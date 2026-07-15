"""
JourneyAI — Geoapify Places integration (reliable, key-based).

Geoapify offers a free API key (no billing card, ~3000 req/day) with:
  - Geocoding (place name -> lat/lon)
  - Places API (real POIs by category + radius, with names/details)

Set the key once via env var JOURNEYAI_GEOAPIFY_KEY (or ml/geo/geoapify_key.txt).
If no key is present, callers fall back to the OSM/Overpass path in places.py.

Get a free key: https://myprojects.geoapify.com/  (Sign up -> New project -> API key)
"""
import json
import os
import time
import hashlib
import urllib.parse
import urllib.request
from math import radians, sin, cos, sqrt, atan2

CACHE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts", "geocache"))
UA = "JourneyAI/1.0"
GEO_BASE = "https://api.geoapify.com/v1/geocode/search"
PLACES_BASE = "https://api.geoapify.com/v2/places"

# Geoapify category taxonomy -> our app categories.
# https://apidocs.geoapify.com/docs/places/#categories
GEOAPIFY_CATS = {
    "temples":     "religion.place_of_worship",
    "heritage":    "tourism.sights,heritage",
    "history":     "tourism.sights,heritage.unesco",
    "gardens":     "leisure.park,national_park",
    "nature":      "natural,tourism.attraction.viewpoint",
    "food":        "catering.restaurant,catering.cafe,catering.fast_food,catering.ice_cream",
    "funpark":     "entertainment.water_park,entertainment.theme_park",
    "hotel":       "accommodation.hotel",
    "attractions": "tourism.attraction,entertainment",
    "shopping":    "commercial.shopping_mall,commercial.marketplace",
    "museum":      "entertainment.museum",
}

DEFAULT_CATS = ["temples", "heritage", "gardens", "food", "attractions",
                "nature", "funpark", "hotel", "museum"]


def get_key():
    k = os.environ.get("JOURNEYAI_GEOAPIFY_KEY", "").strip()
    if k:
        return k
    p = os.path.join(os.path.dirname(__file__), "geoapify_key.txt")
    if os.path.exists(p):
        try:
            return open(p, encoding="utf-8").read().strip()
        except Exception:
            return None
    return None


def has_key():
    return bool(get_key())


def _cache_path(key):
    return os.path.join(CACHE_DIR, hashlib.md5(key.encode()).hexdigest() + ".json")


def _cache_get(key, max_age=None):
    p = _cache_path(key)
    if os.path.exists(p):
        if max_age and (time.time() - os.path.getmtime(p)) > max_age:
            return None
        try:
            return json.load(open(p, encoding="utf-8"))
        except Exception:
            return None
    return None


def _cache_put(key, val):
    os.makedirs(CACHE_DIR, exist_ok=True)
    json.dump(val, open(_cache_path(key), "w", encoding="utf-8"))


def _get(url, timeout=15):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


def haversine(la1, lo1, la2, lo2):
    R = 6371.0
    dla, dlo = radians(la2 - la1), radians(lo2 - lo1)
    a = sin(dla / 2) ** 2 + cos(radians(la1)) * cos(radians(la2)) * sin(dlo / 2) ** 2
    return R * 2 * atan2(sqrt(a), sqrt(1 - a))


def geocode(place):
    key = get_key()
    if not key:
        return None
    ck = "gakey-geo:" + place.lower()
    c = _cache_get(ck)
    if c:
        return tuple(c)
    q = urllib.parse.urlencode({"text": place, "limit": 1, "apiKey": key})
    try:
        d = _get(f"{GEO_BASE}?{q}")
    except Exception:
        return None
    feats = d.get("features", [])
    if not feats:
        return None
    p = feats[0]["properties"]
    res = (p["lat"], p["lon"], p.get("formatted", place))
    _cache_put(ck, list(res))
    return res


def _our_category(props):
    cats = props.get("categories", [])
    if any(c.startswith("religion") for c in cats): return "religious"
    if any("water_park" in c or "theme_park" in c for c in cats): return "funpark"
    if any(c.startswith("accommodation") for c in cats): return "hotel"
    if any(c.startswith("catering") for c in cats): return "food"
    if any(c.startswith("commercial") for c in cats): return "shopping"
    if any("park" in c or "garden" in c for c in cats): return "garden"
    if any("museum" in c for c in cats): return "museum"
    if any(c.startswith("natural") or "viewpoint" in c for c in cats): return "nature"
    if any("heritage" in c or "sights" in c for c in cats): return "heritage"
    return "attraction"


def fetch_places(lat, lon, radius_km, categories):
    key = get_key()
    if not key:
        return []
    cat_str = ",".join(GEOAPIFY_CATS[c] for c in categories if c in GEOAPIFY_CATS)
    ck = f"gakey-poi:{round(lat,3)},{round(lon,3)}:{radius_km}:{cat_str}"
    c = _cache_get(ck, max_age=7 * 86400)
    if c is not None:
        return c
    q = urllib.parse.urlencode({
        "categories": cat_str,
        "filter": f"circle:{lon},{lat},{int(radius_km*1000)}",
        "bias": f"proximity:{lon},{lat}",
        "limit": 200, "apiKey": key,
    })
    try:
        d = _get(f"{PLACES_BASE}?{q}", timeout=20)
    except Exception:
        return []
    out, seen = [], set()
    for f in d.get("features", []):
        p = f["properties"]
        name = p.get("name")
        if not name or name in seen:
            continue
        seen.add(name)
        # fame: Geoapify includes wiki_and_media / rank importance for notable places
        fame = 0.0
        rank = p.get("rank", {})
        if isinstance(rank, dict):
            fame += float(rank.get("importance", 0) or 0) * 6
            if rank.get("popularity"):
                fame += float(rank.get("popularity")) * 0.4
        wiki = (p.get("wiki_and_media") or {})
        if wiki.get("wikidata"): fame += 3
        if wiki.get("wikipedia"): fame += 2
        img = wiki.get("image") or (p.get("datasource", {}).get("raw", {}) or {}).get("image", "")
        out.append({
            "name": name, "lat": p["lat"], "lon": p["lon"],
            "category": _our_category(p),
            "dist_km": round(haversine(lat, lon, p["lat"], p["lon"]), 1),
            "fame": round(fame, 2), "image": img or "",
            "address": p.get("address_line2", ""),
            "cuisine": (p.get("catering", {}) or {}).get("cuisine", ""),
        })
    out.sort(key=lambda x: x["dist_km"])
    _cache_put(ck, out)
    return out


def place_image(name, lat=None, lon=None):
    """Best-effort image URL for a place: Wikipedia thumbnail by name. Cached."""
    ck = "img:" + name.lower()
    c = _cache_get(ck, max_age=30 * 86400)
    if c is not None:
        return c or None
    url = ("https://en.wikipedia.org/w/api.php?action=query&prop=pageimages&format=json"
           "&piprop=thumbnail&pithumbsize=400&titles=" + urllib.parse.quote(name))
    img = ""
    try:
        d = _get(url, timeout=8)
        pages = d.get("query", {}).get("pages", {})
        for _, pg in pages.items():
            th = pg.get("thumbnail", {}).get("source")
            if th:
                img = th
                break
    except Exception:
        img = ""
    _cache_put(ck, img)
    return img or None


if __name__ == "__main__":
    import sys
    if not has_key():
        print("No Geoapify key set. Put it in ml/geo/geoapify_key.txt or "
              "env JOURNEYAI_GEOAPIFY_KEY. Get a free key: https://myprojects.geoapify.com/")
        sys.exit(0)
    g = geocode(sys.argv[1] if len(sys.argv) > 1 else "Nadiad, Gujarat")
    print("geocode:", g[:2] if g else None)
    if g:
        ps = fetch_places(g[0], g[1], 100, DEFAULT_CATS)
        print(f"found {len(ps)} places")
        for p in sorted(ps, key=lambda x: -x["fame"])[:12]:
            line = f"  {p['dist_km']:5.1f}km fame={p['fame']:.1f} {p['category']:9s} {p['name']}"
            print(line.encode("ascii", "replace").decode())
