"""
JourneyAI — versatile "nearby places" via OpenStreetMap (free, no API key).

Works for ANY location the user types (Anand, Ahmedabad, anywhere):
  1. geocode(place)      -> (lat, lon, display_name)   via Nominatim
  2. nearby(lat,lon,...) -> real POIs by category+radius via Overpass
  3. nearby_places(place, mode) -> ranked day-trip/weekend/etc. suggestions

Responses are cached to disk (ml/artifacts/geocache) so repeat lookups are
instant and we stay polite to the free public endpoints.
"""
import json
import os
import time
import hashlib
import urllib.parse
import urllib.request
from math import radians, sin, cos, sqrt, atan2

CACHE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts", "geocache"))
UA = "JourneyAI/1.0 (travel capstone; local demo)"
NOMINATIM = "https://nominatim.openstreetmap.org/search"
# Multiple Overpass mirrors — the public endpoints rate-limit / 504 often, so we
# try them in order until one returns valid JSON. This fixes intermittently
# missing places (e.g. Santram Mandir not showing up).
OVERPASS_MIRRORS = [
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
]

# trip modes -> search radius (km) and how many days
TRIP_MODES = {
    "day":     {"label": "Day trip",   "radius_km": 120, "days": 1},
    "weekend": {"label": "Weekend",    "radius_km": 300, "days": 2},
    "short":   {"label": "Short trip", "radius_km": 600, "days": 4},
    "long":    {"label": "Long trip",  "radius_km": 3000, "days": 8},
}

# OSM tag groups -> categories. Broad coverage so famous spots (Sun Temple,
# water parks, hotels, waterfalls, forts) all surface. Nodes AND ways (some big
# sites like temples/forts/parks are mapped as ways/areas).
CATEGORY_TAGS = {
    "temples":     ['nwr["amenity"="place_of_worship"]["name"]'],
    "history":     ['nwr["historic"]["name"]'],
    "gardens":     ['nwr["leisure"="park"]["name"]', 'nwr["leisure"="garden"]["name"]'],
    "nature":      ['nwr["tourism"="viewpoint"]["name"]', 'nwr["natural"~"peak|water|beach"]["name"]',
                    'nwr["waterway"="waterfall"]["name"]'],
    "wildlife":    ['nwr["leisure"="nature_reserve"]["name"]', 'nwr["tourism"="zoo"]["name"]',
                    'nwr["boundary"="national_park"]["name"]'],
    "museums":     ['nwr["tourism"="museum"]["name"]', 'nwr["tourism"="gallery"]["name"]'],
    "beach":       ['nwr["natural"="beach"]["name"]'],
    "food":        ['nwr["amenity"~"restaurant|cafe|fast_food|ice_cream"]["name"]'],
    "fun":         ['nwr["leisure"="water_park"]["name"]', 'nwr["tourism"="theme_park"]["name"]',
                    'nwr["leisure"="amusement_arcade"]["name"]'],
    "hotels":      ['nwr["tourism"="hotel"]["name"]', 'nwr["tourism"="resort"]["name"]'],
    "attractions": ['nwr["tourism"="attraction"]["name"]', 'nwr["tourism"="artwork"]["name"]',
                    'nwr["man_made"="tower"]["tourism"]["name"]'],
    "shopping":    ['nwr["shop"="mall"]["name"]', 'nwr["amenity"="marketplace"]["name"]'],
    "towns":       ['node["place"~"city|town"]["name"]'],
}

# map our app interest vocabulary onto the OSM category groups above
INTEREST_TO_CATS = {
    "temples": ["temples"], "history": ["history"], "architecture": ["history"],
    "nature": ["nature", "gardens"], "mountains": ["nature"], "trekking": ["nature"],
    "wildlife": ["wildlife"], "museums": ["museums"], "beach": ["beach"],
    "relaxation": ["gardens", "nature", "hotels"], "photography": ["nature", "history", "attractions"],
    "culture": ["history", "temples", "museums"], "food": ["food"], "shopping": ["shopping"],
    "adventure": ["nature", "fun", "attractions"], "nightlife": ["food", "fun"],
}

# well-rounded default so routes always include VARIETY: temples, food, gardens,
# heritage, attractions, water-parks/fun, hotels, nature.
DEFAULT_CATS = ["temples", "history", "gardens", "food", "attractions",
                "nature", "fun", "hotels", "museums"]


def _cache_path(key):
    h = hashlib.md5(key.encode()).hexdigest()
    return os.path.join(CACHE_DIR, h + ".json")


def _cache_get(key, max_age=None):
    p = _cache_path(key)
    if os.path.exists(p):
        if max_age and (time.time() - os.path.getmtime(p)) > max_age:
            return None
        try:
            with open(p, encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            return None
    return None


def _cache_put(key, val):
    os.makedirs(CACHE_DIR, exist_ok=True)
    with open(_cache_path(key), "w", encoding="utf-8") as f:
        json.dump(val, f)


def _http_get(url, timeout=20):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8")


def _overpass(query, timeout=30):
    """POST an Overpass query, trying mirrors until one returns valid JSON."""
    body = urllib.parse.urlencode({"data": query}).encode()
    for url in OVERPASS_MIRRORS:
        try:
            req = urllib.request.Request(url, data=body, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                txt = r.read().decode("utf-8")
            if txt.lstrip().startswith("{"):
                return json.loads(txt)
        except Exception:
            continue
    return None


def haversine(la1, lo1, la2, lo2):
    R = 6371.0
    dla, dlo = radians(la2 - la1), radians(lo2 - lo1)
    a = sin(dla / 2) ** 2 + cos(radians(la1)) * cos(radians(la2)) * sin(dlo / 2) ** 2
    return R * 2 * atan2(sqrt(a), sqrt(1 - a))


def geocode(place):
    """Resolve a free-text place to (lat, lon, display_name) or None."""
    place = (place or "").strip()
    if not place:
        return None
    ck = "geo:" + place.lower()
    cached = _cache_get(ck)
    if cached:
        return tuple(cached)
    q = urllib.parse.urlencode({"q": place, "format": "json", "limit": 1,
                                "countrycodes": "in", "addressdetails": 0})
    try:
        data = json.loads(_http_get(f"{NOMINATIM}?{q}"))
    except Exception:
        return None
    if not data:
        # retry without country restriction (international)
        q = urllib.parse.urlencode({"q": place, "format": "json", "limit": 1})
        try:
            data = json.loads(_http_get(f"{NOMINATIM}?{q}"))
        except Exception:
            return None
    if not data:
        return None
    res = (float(data[0]["lat"]), float(data[0]["lon"]), data[0].get("display_name", place))
    _cache_put(ck, list(res))
    return res


def fetch_pois(lat, lon, radius_km, categories):
    """Query Overpass for POIs in the given categories within radius. Returns list of dicts."""
    ck = f"poi:{round(lat,3)},{round(lon,3)}:{radius_km}:{'|'.join(sorted(categories))}"
    cached = _cache_get(ck, max_age=7 * 86400)
    if cached is not None:
        return cached
    r = int(radius_km * 1000)
    # Split into a few lighter queries (a huge single nwr query over a wide radius
    # gets rejected by the public Overpass). Each sub-query stays small; results
    # merge. `out center tags` gives geometry centers + all tags (wikidata/image).
    def build(cat_list):
        parts = []
        for cat in cat_list:
            for tag in CATEGORY_TAGS.get(cat, []):
                parts.append(f'{tag}(around:{r},{lat},{lon});')
        return f"[out:json][timeout:35];({''.join(parts)});out center tags 150;" if parts else None

    # group categories so each request is light
    cat_list = list(categories)
    batches = [cat_list[i:i + 4] for i in range(0, len(cat_list), 4)]
    elements = []
    for b in batches:
        q = build(b)
        if not q:
            continue
        data = _overpass(q, timeout=40)
        if data:
            elements.extend(data.get("elements", []))
    out = []
    seen = set()
    for e in elements:
        t = e.get("tags", {})
        name = t.get("name")
        if not name or name in seen:
            continue
        la = e.get("lat") or (e.get("center") or {}).get("lat")
        lo = e.get("lon") or (e.get("center") or {}).get("lon")
        if la is None or lo is None:
            continue
        # classify (order matters)
        cat = "attraction"
        if t.get("amenity") == "place_of_worship": cat = "religious"
        elif t.get("leisure") == "water_park" or t.get("tourism") == "theme_park" or t.get("leisure") == "amusement_arcade": cat = "funpark"
        elif t.get("tourism") in ("hotel", "resort"): cat = "hotel"
        elif t.get("amenity") in ("restaurant", "cafe", "fast_food", "ice_cream"): cat = "food"
        elif t.get("shop") == "mall" or t.get("amenity") == "marketplace": cat = "shopping"
        elif t.get("leisure") in ("park", "garden"): cat = "garden"
        elif t.get("waterway") == "waterfall": cat = "nature"
        elif t.get("historic"): cat = "heritage"
        elif t.get("natural") == "beach": cat = "beach"
        elif t.get("natural") or t.get("tourism") == "viewpoint": cat = "nature"
        elif t.get("leisure") == "nature_reserve" or t.get("tourism") == "zoo" or t.get("boundary") == "national_park": cat = "wildlife"
        elif t.get("tourism") in ("museum", "gallery"): cat = "museum"
        elif t.get("place"): cat = "town"
        # FAME score: wikidata/wikipedia presence => notable; stars/importance add
        fame = 0.0
        if t.get("wikidata"): fame += 3.0
        if t.get("wikipedia"): fame += 2.0
        if t.get("heritage"): fame += 1.5
        if t.get("stars"):
            try: fame += float(t["stars"]) * 0.5
            except ValueError: pass
        if t.get("tourism") == "attraction": fame += 0.5
        # image (wikimedia/website) for showing photos
        img = t.get("image") or ""
        wd = t.get("wikidata") or ""
        wp = t.get("wikipedia") or ""
        seen.add(name)
        out.append({
            "name": name, "lat": la, "lon": lo, "category": cat,
            "dist_km": round(haversine(lat, lon, la, lo), 1),
            "fame": round(fame, 1), "image": img, "wikidata": wd, "wikipedia": wp,
            "cuisine": t.get("cuisine", ""),
        })
    out.sort(key=lambda x: x["dist_km"])
    _cache_put(ck, out)
    return out


def _score(p, max_dist):
    """Rank score: fame matters a lot; proximity still counts. Famous distant
    places (Sun Temple, big water parks) beat unnamed nearby ones."""
    prox = 1.0 - min(p["dist_km"] / max_dist, 1.0)     # 1 near -> 0 far
    return p["fame"] * 1.2 + prox * 2.0


def nearby_places(place, mode="day", interests=None, limit=40, category=None):
    """
    Given an origin + trip mode + interests, return real nearby suggestions
    ranked by FAME + proximity (so famous spots surface), balanced across
    categories. `category` optionally filters to one type.
    """
    geo = geocode(place)
    if not geo:
        return {"error": f"Couldn't locate '{place}'. Try adding the state/country."}
    lat, lon, display = geo
    m = TRIP_MODES.get(mode, TRIP_MODES["day"])
    interests = [i.lower() for i in (interests or [])]

    cats = set(DEFAULT_CATS)
    for it in interests:
        cats.update(INTEREST_TO_CATS.get(it, []))

    # Prefer Geoapify (reliable + fame + photos) when a key is configured;
    # otherwise fall back to the OSM/Overpass path.
    try:
        from ml.geo import geoapify
        if geoapify.has_key():
            ga_geo = geoapify.geocode(place)
            if ga_geo:
                lat, lon, display = ga_geo
            ga_cats = list(cats) + [c for c in geoapify.DEFAULT_CATS if c not in cats]
            pois = geoapify.fetch_places(lat, lon, m["radius_km"], ga_cats)
        else:
            pois = fetch_pois(lat, lon, m["radius_km"], cats)
    except Exception:
        pois = fetch_pois(lat, lon, m["radius_km"], cats)

    for p in pois:
        p.setdefault("fame", 0.0); p.setdefault("image", "")
    pois = [p for p in pois if p["dist_km"] > 0.6]     # skip the origin itself
    if category:
        pois = [p for p in pois if p["category"] == category]

    max_dist = max((p["dist_km"] for p in pois), default=1) or 1
    for p in pois:
        p["score"] = round(_score(p, max_dist), 2)

    # counts per category (for filter chips in the UI)
    cat_counts = {}
    for p in pois:
        cat_counts[p["category"]] = cat_counts.get(p["category"], 0) + 1

    if category:
        # single-category view: just rank by score, return more
        ranked = sorted(pois, key=lambda x: x["score"], reverse=True)[:limit]
    else:
        # BALANCED: round-robin across categories, but by SCORE within each so
        # famous spots lead. Guarantees variety AND surfaces the notable places.
        by_cat = {}
        for p in pois:
            by_cat.setdefault(p["category"], []).append(p)
        for c in by_cat:
            by_cat[c].sort(key=lambda x: x["score"], reverse=True)
        cat_order = sorted(by_cat, key=lambda c: by_cat[c][0]["score"], reverse=True)
        ranked, idx = [], 0
        while len(ranked) < limit and any(by_cat.values()):
            c = cat_order[idx % len(cat_order)]
            if by_cat[c]:
                ranked.append(by_cat[c].pop(0))
            idx += 1
            if idx > len(cat_order) * limit:
                break

    return {
        "origin": {"place": place, "display": display, "lat": lat, "lon": lon},
        "mode": mode, "mode_label": m["label"], "radius_km": m["radius_km"], "days": m["days"],
        "interests": interests, "category": category,
        "category_counts": cat_counts,
        "count": len(ranked),
        "places": ranked,
    }


def build_route(place, mode="weekend", interests=None, stops=4):
    """
    Multi-destination route: pick top nearby places and order them into an
    efficient sequence (greedy nearest-neighbour) starting from the origin.
    Returns the ordered stops with leg distances and a total.
    """
    near = nearby_places(place, mode=mode, interests=interests, limit=40)
    if "error" in near:
        return near
    origin = near["origin"]
    pool = near["places"]
    if not pool:
        return {"error": f"No routable places found near '{place}'. Try a wider trip mode."}

    # spread selection: don't cluster — pick diverse distances/categories
    chosen = []
    used_cats = {}
    for p in pool:
        if len(chosen) >= stops:
            break
        c = p["category"]
        if used_cats.get(c, 0) >= max(1, stops // 2 + 1):
            continue          # avoid too many of one category
        used_cats[c] = used_cats.get(c, 0) + 1
        chosen.append(p)
    if len(chosen) < stops:   # backfill if category limits were too strict
        for p in pool:
            if p not in chosen and len(chosen) < stops:
                chosen.append(p)

    # greedy nearest-neighbour ordering from the origin
    route = []
    cur = (origin["lat"], origin["lon"])
    remaining = chosen[:]
    total = 0.0
    while remaining:
        remaining.sort(key=lambda p: haversine(cur[0], cur[1], p["lat"], p["lon"]))
        nxt = remaining.pop(0)
        leg = round(haversine(cur[0], cur[1], nxt["lat"], nxt["lon"]), 1)
        total += leg
        nxt = dict(nxt); nxt["leg_km"] = leg
        route.append(nxt)
        cur = (nxt["lat"], nxt["lon"])
    # return leg back to origin
    back = round(haversine(cur[0], cur[1], origin["lat"], origin["lon"]), 1)
    total += back

    return {
        "origin": origin, "mode": mode, "mode_label": near["mode_label"], "days": near["days"],
        "interests": near["interests"], "stops": len(route),
        "route": route, "return_km": back, "total_km": round(total, 1),
    }


if __name__ == "__main__":
    import sys
    res = nearby_places(sys.argv[1] if len(sys.argv) > 1 else "Anand, Gujarat",
                        mode="day", interests=["temples", "history"])
    if "error" in res:
        print(res["error"])
    else:
        print(f"Near {res['origin']['display'][:40]} ({res['mode_label']}, <{res['radius_km']}km):")
        for p in res["places"]:
            print(f"  {p['dist_km']:5.1f}km  {p['category']:10s} {p['name']}")
