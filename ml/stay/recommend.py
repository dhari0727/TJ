"""
JourneyAI — where to stay, from real data only.  See docs/STAY_RECOMMENDER.md for the full write-up.

    recommend(destination=..., days=3, interests=[...], travel_style="mid-range", party_size=2, stops=[...])

Pipeline
  1. centre      destination -> (lat, lon) (local table, else Nominatim). A few state-level names map to a city.
  2. stops       caller's stops, else the most notable mapped sights near the centre (OSM via ml.geo.places.fetch_pois).
  3. bases       trip length decides how many places to sleep in (0 nights = day trip, 1 night = one base,
                 more = k-means over the stops with k=1..3, split only when it clearly saves travel).
  4. candidates  every mapped place to sleep near each base (ml.geo.places.fetch_stays, OSM tourism=hotel|guest_house|...),
                 enriched with Wikivoyage 'sleep' listings (real price text + source URL) when the names match.
  5. ranking     score = 0.28 proximity + 0.24 type fit + 0.18 style fit + 0.12 notability + 0.10 completeness + 0.08 photo
  6. images      only real open-licensed images: OSM image / wikimedia_commons tags, Wikidata P18, Wikipedia lead image,
                 each verified on Wikimedia Commons (license + credit page). Otherwise null.

Nothing here invents a hotel, price, rating or photo. A price is shown only when Wikivoyage listed one for that
very property; a tier only when OSM stars, a Wikivoyage price or the property type (hostel) supports it.
"""
import json
import math
import os
import re
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from difflib import SequenceMatcher

from ml.geo import places as P
from ml.nlp.lexicon import CANONICAL_ACTIVITIES

DATA = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "data"))
IMG_UA = "TravelJournalImageBot/1.0 (educational project; low-rate)"
VALID_STYLES = {"budget", "mid-range", "luxury", "adventure", "family", "solo", "backpacker"}
EXTRA_INTERESTS = {"family", "romantic", "spiritual", "budget"}

# --------------------------------------------------------------------- vocabulary
# interest -> theme used for type/proximity fit
INTEREST_THEME = {
    "beach": "beach", "diving": "beach",
    "history": "heritage", "architecture": "heritage", "culture": "heritage", "museums": "heritage",
    "temples": "spiritual", "spiritual": "spiritual",
    "wildlife": "nature", "nature": "nature", "backwaters": "nature", "mountains": "nature",
    "snow": "nature", "desert": "nature", "photography": "nature",
    "trekking": "adventure", "adventure": "adventure",
    "food": "city", "nightlife": "city", "shopping": "city",
    "relaxation": "relax", "family": "family", "romantic": "romantic", "budget": "budget",
}
# themes -> the kind of sight they imply when choosing stops
THEME_STOP_CATS = {
    "beach": {"beach"}, "heritage": {"heritage", "museum", "attraction"}, "spiritual": {"religious"},
    "nature": {"nature", "wildlife", "garden"}, "adventure": {"nature", "wildlife"},
    "city": {"attraction", "museum"}, "relax": {"garden", "nature", "beach"}, "family": {"garden", "attraction", "wildlife"},
    "romantic": {"heritage", "garden", "nature"}, "budget": set(),
}

TYPE_LABEL = {
    "hotel": "Hotel", "resort": "Resort", "guest_house": "Guest house", "homestay": "Homestay", "hostel": "Hostel",
    "camp": "Camp", "apartment": "Apartment", "chalet": "Chalet", "motel": "Motel",
    "heritage_hotel": "Heritage stay", "dharamshala": "Dharamshala or ashram", "eco_lodge": "Eco-lodge",
}
# stay type -> how well it suits each theme (0..1). Judgement table, documented in docs/STAY_RECOMMENDER.md.
TYPE_FIT = {
    "beach":     {"resort": .95, "guest_house": .85, "homestay": .85, "hotel": .65, "chalet": .75, "camp": .7, "hostel": .65, "apartment": .65, "motel": .35, "heritage_hotel": .3, "eco_lodge": .6, "dharamshala": .2},
    "heritage":  {"heritage_hotel": 1.0, "guest_house": .7, "homestay": .75, "hotel": .6, "resort": .45, "hostel": .55, "apartment": .4, "motel": .25, "camp": .2, "chalet": .3, "eco_lodge": .3, "dharamshala": .5},
    "spiritual": {"dharamshala": 1.0, "guest_house": .75, "homestay": .7, "hotel": .65, "heritage_hotel": .55, "hostel": .6, "resort": .35, "apartment": .4, "motel": .3, "camp": .25, "chalet": .25, "eco_lodge": .45},
    "nature":    {"eco_lodge": 1.0, "camp": .9, "resort": .8, "chalet": .8, "homestay": .75, "guest_house": .7, "hostel": .55, "hotel": .45, "apartment": .35, "motel": .3, "heritage_hotel": .3, "dharamshala": .3},
    "adventure": {"camp": 1.0, "hostel": .85, "eco_lodge": .85, "chalet": .7, "guest_house": .7, "homestay": .65, "hotel": .45, "resort": .5, "apartment": .35, "motel": .3, "heritage_hotel": .2, "dharamshala": .35},
    "city":      {"hotel": .9, "apartment": .75, "hostel": .7, "heritage_hotel": .65, "guest_house": .6, "motel": .5, "resort": .45, "homestay": .5, "chalet": .2, "camp": .1, "eco_lodge": .15, "dharamshala": .2},
    "relax":     {"resort": 1.0, "eco_lodge": .8, "heritage_hotel": .8, "chalet": .75, "hotel": .6, "homestay": .6, "guest_house": .55, "apartment": .45, "camp": .4, "hostel": .25, "motel": .2, "dharamshala": .3},
    "family":    {"resort": .9, "hotel": .85, "apartment": .85, "heritage_hotel": .6, "guest_house": .6, "homestay": .65, "chalet": .65, "eco_lodge": .5, "motel": .45, "camp": .35, "hostel": .2, "dharamshala": .45},
    "romantic":  {"heritage_hotel": 1.0, "resort": .9, "eco_lodge": .8, "chalet": .8, "homestay": .6, "hotel": .6, "guest_house": .55, "apartment": .4, "camp": .5, "hostel": .1, "motel": .2, "dharamshala": .15},
    "budget":    {"hostel": 1.0, "guest_house": .9, "homestay": .9, "dharamshala": .85, "motel": .6, "camp": .6, "hotel": .5, "apartment": .5, "chalet": .4, "eco_lodge": .35, "resort": .2, "heritage_hotel": .2},
    "group":     {"apartment": .95, "guest_house": .8, "homestay": .8, "resort": .65, "hotel": .55, "chalet": .7, "hostel": .45, "heritage_hotel": .5, "camp": .5, "eco_lodge": .5, "motel": .4, "dharamshala": .5},
}
# travel style -> tier it wants (1 budget, 2 mid-range, 3 upscale, 4 luxury) and the themes it implies
STYLE_TIER = {"budget": 1.0, "backpacker": 1.0, "solo": 1.5, "adventure": 1.5, "mid-range": 2.0, "family": 2.5, "luxury": 3.6}
STYLE_THEME = {"budget": "budget", "backpacker": "budget", "family": "family", "adventure": "adventure", "luxury": "relax"}
TIER_LABEL = {1: "Budget", 2: "Mid-range", 3: "Upscale", 4: "Luxury"}

W = {"prox": .28, "type": .24, "style": .18, "fame": .12, "complete": .10, "photo": .08}
PROX_SCALE_KM = 3.0

# state-level names whose centre is nowhere near the tourist coast -> a city that is, plus a first search radius (km)
ALIASES = {"goa": ("Panaji, India", 30)}

_HERITAGE_KW = re.compile(r"haveli|palace|heritage|mahal|fort\b|castle|kothi|garh\b|bagh\b|baug\b|residency|royal", re.I)
_DHARAM_KW = re.compile(r"dharamsh|dharmsh|ashram|yatri ?niwas|yatri ?sadan|math\b|bhawan\b|seva", re.I)
_ECO_KW = re.compile(r"\beco\b|jungle|forest|wildlife|safari|nature|lodge|glamp|tent|camp|farm|plantation|treehouse|tree house", re.I)
_HOMESTAY_KW = re.compile(r"home ?stay", re.I)


# --------------------------------------------------------------------- small helpers
def _norm(s):
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9 ]+", " ", s)


def _hav(a, b):
    return P.haversine(a[0], a[1], b[0], b[1])


def _proj(lat, lon, lat0):
    return (lon * 111.32 * math.cos(math.radians(lat0)), lat * 110.57)


def _unproj(x, y, lat0):
    return (y / 110.57, x / (111.32 * math.cos(math.radians(lat0))))


def geo_median(pts):
    """Point (lat, lon) minimising the summed distance to pts (Weiszfeld). A single base placed here
    minimises total travel to all stops, which is what 'where should I sleep' asks."""
    if not pts:
        return None
    if len(pts) == 1:
        return tuple(pts[0])
    lat0 = sum(p[0] for p in pts) / len(pts)
    xy = [_proj(p[0], p[1], lat0) for p in pts]
    cx, cy = sum(x for x, _ in xy) / len(xy), sum(y for _, y in xy) / len(xy)
    for _ in range(60):
        nx = ny = dsum = 0.0
        for x, y in xy:
            d = math.hypot(x - cx, y - cy) or 1e-6
            nx += x / d
            ny += y / d
            dsum += 1 / d
        nx, ny = nx / dsum, ny / dsum
        if math.hypot(nx - cx, ny - cy) < 1e-4:
            cx, cy = nx, ny
            break
        cx, cy = nx, ny
    return _unproj(cx, cy, lat0)


def kmeans(pts, k):
    """Plain Lloyd k-means on km-projected coordinates, farthest-point initialisation (deterministic)."""
    lat0 = sum(p[0] for p in pts) / len(pts)
    xy = [_proj(p[0], p[1], lat0) for p in pts]
    cents = [min(xy, key=lambda q: (q[0], q[1]))]
    while len(cents) < k:
        cents.append(max(xy, key=lambda q: min(math.hypot(q[0] - c[0], q[1] - c[1]) for c in cents)))
    assign = [0] * len(xy)
    for _ in range(40):
        new = [min(range(k), key=lambda j: math.hypot(q[0] - cents[j][0], q[1] - cents[j][1])) for q in xy]
        if new == assign and _ > 0:
            break
        assign = new
        for j in range(k):
            m = [xy[i] for i in range(len(xy)) if assign[i] == j]
            if m:
                cents[j] = (sum(a for a, _ in m) / len(m), sum(b for _, b in m) / len(m))
    return assign


def _mean_dist_to_bases(pts, groups):
    tot = 0.0
    for g in groups:
        c = geo_median([pts[i] for i in g])
        tot += sum(_hav(c, pts[i]) for i in g)
    return tot / max(1, len(pts))


# --------------------------------------------------------------------- centre and stops
def resolve_centre(destination, lat=None, lon=None):
    """-> (lat, lon, label, first_radius_km) or None."""
    if lat is not None and lon is not None:
        return lat, lon, destination or "this area", 15
    key = re.sub(r",\s*india$", "", (destination or "").strip().lower())
    radius = 15
    name = destination
    if key in ALIASES:
        name, radius = ALIASES[key]
    geo = P.geocode(name)
    if not geo:
        return None
    return geo[0], geo[1], (destination or name).split(",")[0].strip(), radius


def derive_stops(centre, radius_km, themes, days):
    """The notable mapped sights the trip would visit: fame first, then fit to the trip's themes, then nearness."""
    wanted = set()
    for t in themes:
        wanted |= THEME_STOP_CATS.get(t, set())
    try:
        pois, _ok = P.fetch_sights(centre[0], centre[1], radius_km)
    except Exception:
        pois = []
    pool = list(pois)

    def rank(p):
        return p.get("fame", 0) * 1.2 + (1.5 if p["category"] in wanted else 0) - 0.04 * p["dist_km"]

    pool.sort(key=rank, reverse=True)
    n = max(3, min(12, days * 2))
    return [{"name": p["name"], "lat": p["lat"], "lon": p["lon"], "category": p["category"], "fame": p.get("fame", 0)}
            for p in pool[:n]], pois


# --------------------------------------------------------------------- bases (trip-length intelligence)
def _label_for_stops(stops):
    top = max(stops, key=lambda s: s.get("fame", 0)) if stops else None
    return top["name"] if top else None


def plan_bases(stops, centre, days, destination):
    """Decide where to sleep. Returns dict(kind, nights, bases[], k, explain, headline, summary)."""
    nights = days - 1
    cpt = (centre[0], centre[1])
    pts = [(s["lat"], s["lon"]) for s in stops]
    if not pts:
        pts = [cpt]
        stops = []
    explain = ""
    groups = [list(range(len(pts)))]
    base_pts_median = geo_median(pts)

    if nights >= 2 and len(pts) >= 2:
        diam = max(_hav(a, b) for a in pts for b in pts)
        mean1 = _mean_dist_to_bases(pts, groups)
        if diam <= 15:
            explain = f"All {len(pts)} stops lie within {diam:.0f} km of each other, so one base avoids packing and unpacking."
        else:
            kmax = 2 if nights < 4 else min(3, nights // 2)
            best_groups, best_cost = groups, mean1
            for k in range(2, kmax + 1):
                if k > len(pts):
                    break
                a = kmeans(pts, k)
                g = [[i for i in range(len(pts)) if a[i] == j] for j in range(k)]
                g = [x for x in g if x]
                if len(g) < k:
                    continue
                cents = [geo_median([pts[i] for i in x]) for x in g]
                sep = min(_hav(cents[i], cents[j]) for i in range(k) for j in range(i + 1, k))
                cost = _mean_dist_to_bases(pts, g)
                if nights < 4:
                    ok = sep > 40
                    why_not = f"the groups of stops are only {sep:.0f} km apart and a trip under 4 nights stays in one place unless they are over 40 km apart"
                else:
                    small = min(len(x) for x in g)
                    ok = sep >= 15 and cost <= best_cost * 0.70 and (small >= 2 or len(pts) <= 4)
                    why_not = f"splitting into {k} bases saves under 30% of daily travel ({best_cost:.0f} km to {cost:.0f} km)"
                if ok:
                    best_groups, best_cost = g, cost
                    explain = (f"The stops fall into {k} groups at least {sep:.0f} km apart; sleeping in {k} places cuts the average "
                               f"distance from bed to stop from {mean1:.0f} km to {cost:.0f} km.")
                elif not explain:
                    explain = f"One base is enough: {why_not}."
            groups = best_groups
            if len(groups) == 1 and not explain:
                explain = "One base is enough: the stops are close enough to reach from a single place."
    elif nights >= 2 and not stops:
        explain = f"No mapped sights could be loaded for {destination}, so the base is the centre of {destination}."
    elif nights >= 2:
        explain = "Only one mapped stop, so one base."
    elif nights == 1:
        explain = ("One night means one base: it is placed at the point that minimises the total distance to your day-1 and "
                   "day-2 stops, so you never switch hotels.")
    else:
        explain = "A one-day plan ends where it started, so no overnight base is needed."

    k = len(groups)
    bases = []
    for g in groups:
        gp = [pts[i] for i in g]
        c = geo_median(gp) or cpt
        bases.append({"lat": round(c[0], 5), "lon": round(c[1], 5), "stops": [stops[i]["name"] for i in g] if stops else [],
                      "_stops": [stops[i] for i in g] if stops else [], "weight": len(g)})
    # visit order: start with the base nearest the centre (arrival), then nearest neighbour
    order, cur, left = [], cpt, bases[:]
    while left:
        left.sort(key=lambda b: _hav(cur, (b["lat"], b["lon"])))
        b = left.pop(0)
        order.append(b)
        cur = (b["lat"], b["lon"])
    bases = order
    # nights: 1 each, the rest by largest remainder of stop counts
    alloc = [0] * k
    if nights > 0:
        alloc = [1] * k if nights >= k else [1] + [0] * (k - 1)
        rest = nights - sum(alloc)
        tw = sum(b["weight"] for b in bases) or 1
        quota = [rest * b["weight"] / tw for b in bases]
        add = [int(q) for q in quota]
        left_n = rest - sum(add)
        for i in sorted(range(k), key=lambda i: quota[i] - add[i], reverse=True)[:left_n]:
            add[i] += 1
        alloc = [a + b for a, b in zip(alloc, add)]
        if nights < k:  # cannot happen with the rules above, but never output a base with no night
            bases, alloc = bases[:1], [nights]
    for i, b in enumerate(bases):
        b["id"] = i
        b["nights"] = alloc[i]
    return {"nights": nights, "k": len(bases), "bases": bases, "explain": explain}


# --------------------------------------------------------------------- Wikivoyage listings
_WV = None
_GENERIC = {"hotel", "the", "guest", "house", "guesthouse", "resort", "inn", "lodge", "hostel", "and", "residency", "homestay", "home", "stay", "by", "at", "of"}


_COMMON = {"palace", "royal", "heritage", "grand", "city", "new", "old", "taj", "park", "garden", "fort", "club", "palace", "mahal", "haveli", "international", "plaza", "tower", "towers", "view", "villa", "regency", "paradise", "pride", "comfort", "star", "green", "blue", "golden", "gold", "silver"}


def _wikivoyage():
    global _WV
    if _WV is None:
        _WV = {}
        try:
            with open(os.path.join(DATA, "india_cost_observations.json"), encoding="utf-8") as f:
                for o in json.load(f):
                    if o.get("type") == "sleep" and o.get("name") and o.get("inr"):
                        _WV.setdefault(o["destination"].split("/")[0].strip().lower(), []).append(o)
        except Exception:
            pass
    return _WV


def _wv_all_prices():
    return sorted(o["inr"] for v in _wikivoyage().values() for o in v)


def _quantile_tier(price, prices):
    if len(prices) < 6:
        return None
    rank = sum(1 for p in prices if p <= price) / len(prices)
    return 1 if rank <= .34 else 2 if rank <= .67 else 3 if rank <= .92 else 4


def _same_name(a, b):
    ta = [t for t in _norm(a).split() if t not in _GENERIC]
    tb = [t for t in _norm(b).split() if t not in _GENERIC]
    if not ta or not tb:
        return False
    sa, sb = set(ta), set(tb)
    if sa == sb:
        return True
    if len(sa & sb) / len(sa | sb) >= .67 and len(sa & sb) >= 1:
        return True
    return SequenceMatcher(None, " ".join(ta), " ".join(tb)).ratio() >= .88


def attach_wikivoyage(cands, names):
    lists = []
    for n in names:
        lists += _wikivoyage().get(n.lower(), [])
    if not lists:
        return {"listings": 0, "matched": 0, "dist_prices": 0}
    local_prices = sorted(o["inr"] for o in lists)
    prices = local_prices if len(local_prices) >= 6 else _wv_all_prices()
    basis = "this destination" if len(local_prices) >= 6 else "all India listings"
    matched = 0
    for c in cands:
        for o in lists:
            if _same_name(c["name"], o["name"]):
                c["price"] = {"inr": round(float(o["inr"])), "raw": (o.get("price_raw") or "")[:240], "source": "Wikivoyage",
                              "url": o.get("url") or ""}
                c["wv_tier"] = _quantile_tier(o["inr"], prices)
                c["wv_basis"] = basis
                matched += 1
                break
    return {"listings": len(lists), "matched": matched, "dist_prices": len(prices)}


# --------------------------------------------------------------------- candidate model
def classify(t):
    tour, name = t.get("tourism", "hotel"), t.get("name", "")
    if tour == "camp_site":
        base = "camp"
    elif tour == "hostel":
        base = "hostel"
    elif tour == "guest_house":
        base = "homestay" if (t.get("guest_house") == "homestay" or _HOMESTAY_KW.search(name)) else "guest_house"
    elif tour in ("apartment", "chalet", "motel", "resort"):
        base = tour
    else:
        base = "hotel"
    heritage = bool(t.get("heritage") or t.get("historic") or _HERITAGE_KW.search(name))
    if _DHARAM_KW.search(name) and base in ("guest_house", "hostel", "hotel"):
        return "dharamshala", heritage
    if heritage and base in ("hotel", "resort", "guest_house"):
        return "heritage_hotel", True
    if _ECO_KW.search(name) and base in ("hotel", "resort", "camp", "guest_house", "chalet", "homestay"):
        return ("camp" if base == "camp" else "eco_lodge"), heritage
    return base, heritage


def _stars(t):
    m = re.match(r"\s*([1-5](?:\.\d)?)", str(t.get("stars", "")))
    return float(m.group(1)) if m else None


def build_candidate(t):
    typ, heritage = classify(t)
    st = _stars(t)
    c = {"name": t["name"], "lat": t["lat"], "lon": t["lon"], "type": typ, "type_label": TYPE_LABEL[typ], "heritage": heritage,
         "stars": st, "osm": t.get("osm"), "tags": t,
         "website": t.get("website") or t.get("contact:website") or "", "phone": t.get("phone") or t.get("contact:phone") or "",
         "suburb": t.get("addr:suburb") or t.get("addr:city") or ""}
    fame = 0.0
    fame += 3.0 if t.get("wikidata") else 0
    fame += 2.0 if t.get("wikipedia") else 0
    fame += 1.5 if t.get("heritage") else 0
    fame += (st or 0) * 0.5
    c["fame_raw"] = fame
    return c


def finalize_tier(c):
    """Real tier only: OSM stars, else Wikivoyage price quantile, else hostel type; otherwise unknown."""
    if c["stars"]:
        c["tier"] = 1 if c["stars"] <= 2 else 2 if c["stars"] < 4 else 3 if c["stars"] < 5 else 4
        c["tier_basis"] = f"{c['stars']:g} stars on OpenStreetMap"
    elif c.get("wv_tier"):
        c["tier"] = c["wv_tier"]
        c["tier_basis"] = f"Wikivoyage price against {c['wv_basis']}"
    elif c["type"] == "hostel":
        c["tier"] = 1
        c["tier_basis"] = "hostel"
    else:
        c["tier"] = None
        c["tier_basis"] = ""
    c["tier_label"] = (TIER_LABEL[c["tier"]] + " (" + c["tier_basis"] + ")") if c["tier"] else "Tier not mapped"


# --------------------------------------------------------------------- scoring
def _nearest(c, pts):
    return min((_hav((c["lat"], c["lon"]), p) for p in pts), default=None)


def type_fit(c, themes, signals):
    parts, reasons = [], []
    for th in themes:
        w = TYPE_FIT[th].get(c["type"], .4)
        sig = None
        if th == "beach" and signals.get("beach"):
            d = _nearest(c, signals["beach"])
            sig = 1.0 if d <= 1 else max(0.0, 1 - (d - 1) / 5)
            if d <= 2:
                reasons.append(f"Beach {d:.1f} km away" if d >= .1 else "Right on a mapped beach")
        elif th == "spiritual" and signals.get("religious"):
            d = _nearest(c, signals["religious"])
            sig = 1.0 if d <= .5 else max(0.0, 1 - (d - .5) / 2.5)
            if d <= 1:
                reasons.append(f"Place of worship {d * 1000:.0f} m away" if d < 1 else "Place of worship about 1 km away")
        elif th == "heritage" and signals.get("heritage"):
            d = _nearest(c, signals["heritage"])
            sig = 1.0 if d <= 1 else max(0.0, 1 - (d - 1) / 4)
            if d <= 1.5:
                reasons.append(f"Heritage site {d:.1f} km away")
        elif th == "nature" and signals.get("wildlife"):
            d = _nearest(c, signals["wildlife"])
            sig = 1.0 if d <= 3 else max(0.0, 1 - (d - 3) / 15)
            if d <= 5:
                reasons.append(f"Reserve or nature area {d:.1f} km away")
        parts.append(w if sig is None else .55 * w + .45 * sig)
        if th in ("heritage", "spiritual", "nature", "beach", "adventure", "budget", "family", "romantic", "relax", "city"):
            if w >= .85:
                reasons.append({"beach": f"{c['type_label']} suits a beach trip", "heritage": "Heritage-style stay for a history trip",
                                "spiritual": "Built for pilgrims and quiet stays", "nature": f"{c['type_label']} suits a nature trip",
                                "adventure": f"{c['type_label']} suits an adventure trip", "budget": f"{c['type_label']} suits a tight budget",
                                "family": f"{c['type_label']} suits family stays", "romantic": f"{c['type_label']} suits a romantic trip",
                                "relax": f"{c['type_label']} suits a slow, restful trip", "city": f"{c['type_label']} suits a city break"}.get(th, ""))
    return (sum(parts) / len(parts) if parts else .5), [r for r in reasons if r]


def style_fit(c, style):
    if not c["tier"]:
        return .4, None
    target = STYLE_TIER.get(style, 2.0)
    f = max(0.0, 1 - abs(c["tier"] - target) / 3)
    return f, f"{TIER_LABEL[c['tier']]} tier fits a {style} trip" if abs(c["tier"] - target) <= .75 else None


def completeness(c):
    n = sum(bool(x) for x in (c["website"], c["phone"], c["tier"], c["suburb"] or c["tags"].get("addr:street"), c.get("price")))
    return n / 5


def prescore(c, style, themes, signals, base_xy):
    d = _hav((c["lat"], c["lon"]), base_xy)
    prox = 1 / (1 + d / PROX_SCALE_KM)
    tf, treasons = type_fit(c, themes, signals)
    sf, sreason = style_fit(c, style)
    fame = min(1.0, (c["fame_raw"] + (1.0 if c.get("price") else 0)) / 6)
    comp = completeness(c)
    c["dist_base"] = d
    c["_reasons_type"] = treasons
    c["_reason_style"] = sreason
    c["parts"] = {"prox": prox, "type": tf, "style": sf, "fame": fame, "complete": comp}
    c["pre"] = sum(W[k] * c["parts"][k] for k in c["parts"])
    return c["pre"]


def final_score(c):
    c["parts"]["photo"] = 1.0 if c.get("image") else 0.0
    c["score"] = sum(W[k] * c["parts"][k] for k in W)


# --------------------------------------------------------------------- images (Wikimedia only)
def _api(url, timeout=12):
    """Polite GET of a Wikimedia API: generic bot UA, low rate, honours Retry-After on 429."""
    last = None
    for attempt in range(3):
        time.sleep(0.25 if attempt == 0 else 0)
        req = urllib.request.Request(url, headers={"User-Agent": IMG_UA})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            last = e
            if e.code in (429, 503):
                try:
                    wait = min(6.0, float(e.headers.get("Retry-After", 2)))
                except (TypeError, ValueError):
                    wait = 2.0
                time.sleep(wait)
                continue
            raise
    raise last


_FILE_RE = re.compile(r"(?:commons\.wikimedia\.org/wiki/|commons\.wikimedia\.org/wiki/Special:FilePath/)(File:[^?#]+)", re.I)


def _commons_file_from_tags(t):
    wc = (t.get("wikimedia_commons") or "").strip()
    if wc.lower().startswith("file:"):
        return urllib.parse.unquote(wc[5:]).replace("_", " ")
    im = (t.get("image") or "").strip()
    m = _FILE_RE.search(im)
    if m:
        return urllib.parse.unquote(m.group(1)[5:]).replace("_", " ")
    m = re.match(r"https?://upload\.wikimedia\.org/wikipedia/commons/(?:thumb/)?[0-9a-f]/[0-9a-f]{2}/([^/]+)", im)
    if m:
        return urllib.parse.unquote(m.group(1)).replace("_", " ")
    return None


def _cached(key, ttl):
    v = P._cache_get(key, max_age=ttl)
    return v


def _wikidata_files(qids):
    """qid -> commons file name or None (P18). Only successful lookups are cached (negatives for 3 days)."""
    out, todo = {}, []
    for q in qids:
        v = _cached("wdimg:" + q, 30 * 86400 if True else 0)
        if v is not None:
            if v.get("file") or (time.time() - v.get("t", 0)) < 3 * 86400:
                out[q] = v.get("file")
                continue
        todo.append(q)
    for i in range(0, len(todo), 40):
        chunk = todo[i:i + 40]
        try:
            d = _api("https://www.wikidata.org/w/api.php?" + urllib.parse.urlencode(
                {"action": "wbgetentities", "ids": "|".join(chunk), "props": "claims", "format": "json"}))
        except Exception:
            continue
        for q in chunk:
            ent = (d.get("entities") or {}).get(q) or {}
            f = None
            for cl in (ent.get("claims") or {}).get("P18", []):
                v = ((cl.get("mainsnak") or {}).get("datavalue") or {}).get("value")
                if isinstance(v, str):
                    f = v
                    break
            out[q] = f
            P._cache_put("wdimg:" + q, {"file": f, "t": time.time()})
    return out


def _wikipedia_files(tags):
    """'en:Title' -> commons-style file name of the lead image, or None."""
    out, by_lang = {}, {}
    for tg in tags:
        m = re.match(r"([a-z\-]{2,8}):(.+)", tg)
        if m:
            by_lang.setdefault(m.group(1), []).append(m.group(2))
    for lang, titles in by_lang.items():
        for i in range(0, len(titles), 40):
            chunk = titles[i:i + 40]
            try:
                d = _api(f"https://{lang}.wikipedia.org/w/api.php?" + urllib.parse.urlencode(
                    {"action": "query", "prop": "pageimages", "piprop": "name", "redirects": 1, "titles": "|".join(chunk), "format": "json"}))
            except Exception:
                continue
            q = d.get("query") or {}
            back = {}
            for n in (q.get("normalized") or []) + (q.get("redirects") or []):
                back[n["to"]] = n["from"]
            for pg in (q.get("pages") or {}).values():
                t = pg.get("title")
                orig = back.get(t, t)
                orig = back.get(orig, orig)
                if pg.get("pageimage"):
                    out[f"{lang}:{orig}"] = pg["pageimage"].replace("_", " ")
                    out[f"{lang}:{t}"] = pg["pageimage"].replace("_", " ")
    return out


def _wikipedia_geo_titles(cands_idx):
    """Many notable hotels have an English Wikipedia article that OSM does not link. Look for one within 1 km whose
    title matches the hotel name; returns {candidate index: 'en:Title'}. Cached per OSM object."""
    out = {}

    def one(item):
        i, c = item
        key = "wpgeo:" + str(c.get("osm"))
        v = _cached(key, 30 * 86400)
        if v is not None and (v.get("title") or time.time() - v.get("t", 0) < 7 * 86400):
            return i, v.get("title")
        try:
            d = _api("https://en.wikipedia.org/w/api.php?" + urllib.parse.urlencode(
                {"action": "query", "list": "geosearch", "gscoord": f"{c['lat']}|{c['lon']}", "gsradius": 1000, "gslimit": 12,
                 "format": "json"}))
        except Exception:
            return i, None
        title = None
        for r in (d.get("query") or {}).get("geosearch", []):
            t = r.get("title", "")
            bare = t.split(",")[0]
            if _same_name(c["name"], bare):
                title = t
                break
            # very close (<= 200 m) and sharing a distinctive word, e.g. "Taj Rambagh Palace" and "Rambagh Palace, Jaipur"
            if r.get("dist", 9999) <= 200:
                shared = (set(_norm(c["name"]).split()) & set(_norm(bare).split())) - _GENERIC - _COMMON
                if any(len(w) >= 4 for w in shared):
                    title = t
                    break
        P._cache_put(key, {"title": title, "t": time.time()})
        return i, title

    with ThreadPoolExecutor(max_workers=1) as ex:
        for i, t in ex.map(one, cands_idx):
            if t:
                out[i] = "en:" + t
    return out


def _commons_info(files):
    """file name -> {license, author} when the file is on Commons with a license, else None."""
    out, todo = {}, []
    for f in files:
        v = _cached("cmfile:" + f, 90 * 86400)
        if v is not None:
            out[f] = v.get("info")
        else:
            todo.append(f)
    for i in range(0, len(todo), 30):
        chunk = todo[i:i + 30]
        try:
            d = _api("https://commons.wikimedia.org/w/api.php?" + urllib.parse.urlencode(
                {"action": "query", "titles": "|".join("File:" + f for f in chunk), "prop": "imageinfo",
                 "iiprop": "extmetadata|mime", "format": "json", "redirects": 1}))
        except Exception:
            continue
        q = d.get("query") or {}
        back = {}
        for n in (q.get("normalized") or []) + (q.get("redirects") or []):
            back[n["to"]] = n["from"]
        got = {}
        for pg in (q.get("pages") or {}).values():
            title = pg.get("title", "")
            ii = (pg.get("imageinfo") or [None])[0]
            info = None
            if ii and str(ii.get("mime", "")).startswith("image/") and ii.get("mime") not in ("image/svg+xml", "image/gif", "image/tiff"):
                md = ii.get("extmetadata") or {}
                lic = (md.get("LicenseShortName") or {}).get("value")
                if lic and "non-free" not in lic.lower() and "fair use" not in lic.lower():
                    au = re.sub(r"<[^>]+>", "", (md.get("Artist") or {}).get("value") or "").strip()[:80]
                    info = {"license": lic[:40], "author": au or None}
            got[title[5:] if title.startswith("File:") else title] = info
            orig = back.get(title, title)
            got[orig[5:] if orig.startswith("File:") else orig] = info
        for f in chunk:
            key = f if f in got else f.replace("_", " ")
            if key in got or f in got:
                out[f] = got.get(f, got.get(key))
                P._cache_put("cmfile:" + f, {"info": out[f], "t": time.time()})
    return out


def resolve_images(cands):
    """Attach c['image'] = {src, credit_page, license, author, via} to candidates with a verifiable open image."""
    want = {}                                  # candidate index -> (file, via)
    for i, c in enumerate(cands):
        f = _commons_file_from_tags(c["tags"])
        if f:
            want[i] = (f, "OpenStreetMap tag")
    need_wd = {i: c["tags"]["wikidata"] for i, c in enumerate(cands) if i not in want and re.fullmatch(r"Q\d+", c["tags"].get("wikidata", ""))}
    if need_wd:
        wd = _wikidata_files(sorted(set(need_wd.values())))
        for i, q in need_wd.items():
            if wd.get(q):
                want[i] = (wd[q], "Wikidata")
    need_wp = {i: c["tags"]["wikipedia"] for i, c in enumerate(cands) if i not in want and c["tags"].get("wikipedia")}
    if need_wp:
        wp = _wikipedia_files(sorted(set(need_wp.values())))
        for i, tg in need_wp.items():
            if wp.get(tg):
                want[i] = (wp[tg], "Wikipedia")
    geo_idx = sorted([(i, c) for i, c in enumerate(cands) if i not in want], key=lambda x: -x[1].get("pre", 0))[:24]
    if geo_idx:
        titles = _wikipedia_geo_titles(geo_idx)
        if titles:
            wp = _wikipedia_files(sorted(set(titles.values())))
            for i, tg in titles.items():
                if wp.get(tg):
                    want[i] = (wp[tg], "Wikipedia")
    if not want:
        return 0
    info = _commons_info(sorted({f for f, _ in want.values()}))
    n = 0
    for i, (f, via) in want.items():
        meta = info.get(f)
        if not meta:
            continue
        name = f.replace(" ", "_")
        cands[i]["image"] = {
            "src": "https://commons.wikimedia.org/wiki/Special:FilePath/" + urllib.parse.quote(name) + "?width=800",
            "credit_page": "https://commons.wikimedia.org/wiki/File:" + urllib.parse.quote(name),
            "license": meta["license"], "author": meta.get("author"), "via": via}
        n += 1
    return n


# --------------------------------------------------------------------- orchestration
def _clean_interests(raw, style):
    out = []
    for i in raw or []:
        if isinstance(i, str):
            i = i.strip().lower()
            if (i in CANONICAL_ACTIVITIES or i in EXTRA_INTERESTS) and i not in out:
                out.append(i)
    themes = []
    for i in out:
        th = INTEREST_THEME.get(i)
        if th and th not in themes:
            themes.append(th)
    st = STYLE_THEME.get(style)
    if st and st not in themes:
        themes.append(st)
    return out, themes[:4]


def _headline(plan, dest):
    n, bases = plan["nights"], plan["bases"]
    if n == 0:
        return "Day trip: no stay needed"
    if n == 1:
        return "One night: stay here"
    if len(bases) == 1:
        return f"{n} nights: one base for the whole trip"
    parts = [f"{b['label']} for {b['nights']}" for b in bases]
    return f"{n} nights: base in " + ", ".join(parts[:-1]) + (" and " if len(parts) > 1 else "") + parts[-1]


def recommend(destination="", days=3, interests=None, travel_style="mid-range", party_size=1, stops=None,
              lat=None, lon=None, top_n=6):
    t0 = time.time()
    style = travel_style if travel_style in VALID_STYLES else "mid-range"
    days = max(1, min(30, int(days)))
    party_size = max(1, min(20, int(party_size)))
    top_n = max(1, min(12, int(top_n)))
    interests, themes = _clean_interests(interests, style)
    if party_size >= 5 and "group" not in themes:
        themes.append("group")

    centre = resolve_centre(destination, lat, lon)
    if not centre:
        return {"status": "error", "error": f"Could not find '{destination}' on the map. Try the city name with its state or country."}
    clat, clon, label, radius = centre

    # stops
    given = []
    for s in stops or []:
        try:
            given.append({"name": str(s.get("name", "Stop"))[:80], "lat": float(s["lat"]), "lon": float(s["lon"]), "fame": 0})
        except Exception:
            continue
    given = [g for g in given if -90 <= g["lat"] <= 90 and -180 <= g["lon"] <= 180][:20]
    pois, stops_source = [], "given"
    if given:
        stop_list = given
    else:
        stop_list, pois = derive_stops((clat, clon), max(radius, 25) if days >= 4 else radius, themes, days)
        stops_source = "OpenStreetMap sights near " + label if stop_list else "none"

    plan = plan_bases(stop_list, (clat, clon), days, label)
    notes = []
    if stops_source == "none":
        notes.append("No mapped sights were available, so the base is placed at the centre of " + label + ".")

    # candidates around every base
    found, seen, ok_any = [], set(), False
    for b in plan["bases"]:
        for r in (8, 16):
            rows, ok = P.fetch_stays(b["lat"], b["lon"], r)
            ok_any = ok_any or ok
            if len(rows) >= 12 or not ok:
                break
        for t in rows:
            if t["osm"] not in seen:
                seen.add(t["osm"])
                found.append(t)
    if not found:
        if not ok_any:
            return {"status": "error", "error": "Map data for stays is not reachable right now. Try again in a minute.", "retryable": True}
        return {"status": "ok", "destination": label, "days": days, "nights": plan["nights"], "empty": True,
                "message": f"No mapped places to stay near {label} yet.", "plan": _public_plan(plan, label, days, stops_source),
                "stays": [], "coverage": {"candidates": 0, "with_photo": 0, "with_price": 0}, "interests": interests, "style": style}

    cands = [build_candidate(t) for t in found]
    wv = attach_wikivoyage(cands, {label, destination.split(",")[0].strip()} | ({"goa"} if label.lower() == "goa" else set()))
    for c in cands:
        finalize_tier(c)

    # base labels: commonest suburb among nearby stays, else the most notable stop
    for b in plan["bases"]:
        near = [c["suburb"] for c in cands if c["suburb"] and _hav((c["lat"], c["lon"]), (b["lat"], b["lon"])) <= 2.5]
        top = Counter(near).most_common(1)
        b["label"] = top[0][0] if top and top[0][1] >= 2 else ((_label_for_stops(b["_stops"]) + " area") if b["_stops"] else label)

    signals = {"beach": [(p["lat"], p["lon"]) for p in pois if p["category"] == "beach"],
               "religious": [(p["lat"], p["lon"]) for p in pois if p["category"] == "religious"],
               "heritage": [(p["lat"], p["lon"]) for p in pois if p["category"] == "heritage"],
               "wildlife": [(p["lat"], p["lon"]) for p in pois if p["category"] == "wildlife"]}
    if given:  # signals still wanted for fit even with caller stops
        try:
            ps, _ok = P.fetch_sights(clat, clon, radius)
            for key, cat in (("beach", "beach"), ("religious", "religious"), ("heritage", "heritage"), ("wildlife", "wildlife")):
                signals[key] = [(p["lat"], p["lon"]) for p in ps if p["category"] == cat]
        except Exception:
            pass

    # assign each candidate to its nearest base and pre-score
    for c in cands:
        b = min(plan["bases"], key=lambda b: _hav((c["lat"], c["lon"]), (b["lat"], b["lon"])))
        c["base"] = b["id"]
        prescore(c, style, themes, signals, (b["lat"], b["lon"]))

    # photos for the best pre-scored few per base, then final ranking
    k = len(plan["bases"])
    pool = []
    for b in plan["bases"]:
        pool += sorted([c for c in cands if c["base"] == b["id"]], key=lambda c: -c["pre"])[:max(10, 18 // k)]
    # notable ones (they are the only ones that can have an image) always get looked up
    pool += [c for c in cands if (c["tags"].get("image") or c["tags"].get("wikimedia_commons") or c["tags"].get("wikidata")
                                  or c["tags"].get("wikipedia")) and c not in pool][:30]
    try:
        resolve_images(pool)
    except Exception:
        notes.append("Photo lookup was unavailable, so some places may be shown without a picture.")
    for c in cands:
        final_score(c)

    n_total = top_n if plan["nights"] > 0 else min(top_n, 3)
    per = max(2, math.ceil(n_total / k))
    picked = []
    for b in plan["bases"]:
        bucket = sorted([c for c in cands if c["base"] == b["id"]], key=lambda c: -c["score"])
        chosen, types = [], Counter()
        while bucket and len(chosen) < per:
            bucket.sort(key=lambda c: -(c["score"] - .04 * types[c["type"]]))   # soft variety: -0.04 per repeat of a type
            c = bucket.pop(0)
            types[c["type"]] += 1
            chosen.append(c)
        picked += chosen
    picked.sort(key=lambda c: (c["base"], -c["score"]))
    picked = picked[:max(n_total, k * 2)] if k > 1 else picked[:n_total]

    stays = [_public_stay(c, plan, style, i, label) for i, c in enumerate(picked)]
    plan_out = _public_plan(plan, label, days, stops_source)
    return {
        "status": "ok", "destination": label, "centre": {"lat": clat, "lon": clon}, "days": days, "nights": plan["nights"],
        "style": style, "interests": interests, "party_size": party_size, "plan": plan_out, "stays": stays,
        "optional": plan["nights"] == 0,
        "coverage": {"candidates": len(cands), "with_photo": sum(1 for s in stays if s["image"]),
                     "with_price": sum(1 for s in stays if s["price"]), "wikivoyage_listings": wv["listings"],
                     "wikivoyage_matched": wv["matched"]},
        "notes": notes, "took_s": round(time.time() - t0, 1),
        "sources": ["OpenStreetMap (Overpass)", "Wikivoyage (price text)", "Wikidata, Wikipedia and Wikimedia Commons (photos)"],
    }


def _public_plan(plan, label, days, stops_source):
    n = plan["nights"]
    kind = "day" if n == 0 else "one_night" if n == 1 else "single_base" if len(plan["bases"]) == 1 else "multi_base"
    if kind == "day":
        summary = ("A one-day plan ends back where it started, so there is nothing to book. If you decide to stay over, these are the "
                   "closest mapped options to your stops.")
    else:
        summary = plan["explain"]
    p = {"kind": kind, "nights": n, "days": days, "k": len(plan["bases"]), "explain": plan["explain"], "summary": summary,
         "stops_source": stops_source,
         "bases": [{"id": b["id"], "label": b.get("label") or label, "lat": b["lat"], "lon": b["lon"], "nights": b["nights"],
                    "stops": b["stops"]} for b in plan["bases"]]}
    p["headline"] = _headline({"nights": n, "bases": [{"label": b.get("label") or label, "nights": b["nights"]} for b in plan["bases"]]}, label)
    return p


def _public_stay(c, plan, style, rank, label_default="the centre"):
    b = plan["bases"][c["base"]]
    why = list(c["_reasons_type"][:2])
    if c["_reason_style"]:
        why.append(c["_reason_style"])
    if c["stars"]:
        why.append(f"{c['stars']:g} stars mapped on OpenStreetMap")
    if c.get("price"):
        why.append("Price listed on Wikivoyage")
    elif c["tags"].get("wikipedia") or c["tags"].get("wikidata"):
        why.append("Well documented on Wikipedia or Wikidata")
    if c["heritage"] and c["type"] != "heritage_hotel":
        why.append("Heritage-tagged building")
    seen, uniq = set(), []
    for w in why:
        if w not in seen:
            seen.add(w)
            uniq.append(w)
    return {
        "id": c["osm"], "rank": rank + 1, "base": c["base"], "name": c["name"], "type": c["type"], "type_label": c["type_label"],
        "tier": c["tier"], "tier_label": c["tier_label"], "tier_basis": c["tier_basis"], "base_label": b.get("label") or label_default, "stars": c["stars"], "lat": round(c["lat"], 5), "lon": round(c["lon"], 5),
        "distance_km": round(c["dist_base"], 1), "nights": 0 if plan["nights"] == 0 else b["nights"],
        "price": c.get("price"), "why": uniq[:5], "score": round(c["score"], 3),
        "score_parts": {k: round(v, 2) for k, v in c["parts"].items()},
        "image": c.get("image"), "website": c["website"], "phone": c["phone"],
        "map_url": f"https://www.openstreetmap.org/{c['osm']}" if c.get("osm") else f"https://www.openstreetmap.org/?mlat={c['lat']}&mlon={c['lon']}#map=17/{c['lat']}/{c['lon']}",
        "address": ", ".join(x for x in (c["tags"].get("addr:street"), c["suburb"]) if x),
    }


if __name__ == "__main__":
    import sys
    r = recommend(sys.argv[1] if len(sys.argv) > 1 else "Jaipur, India", days=int(sys.argv[2]) if len(sys.argv) > 2 else 3,
                  interests=["history"], travel_style="mid-range")
    print(json.dumps(r, indent=1, ensure_ascii=False)[:4000])
