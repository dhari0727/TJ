"""
JourneyAI — Gemini tool functions module.

The chatbot (Gemini LLM) calls these tools via function-calling. Each tool has:
  (a) a Gemini function-declaration schema (in TOOL_DECLARATIONS), and
  (b) a Python implementation returning a JSON-serializable dict.

Everything here reuses the existing ml.* modules — nothing is reimplemented:
  - ml.geo.places.nearby_places / build_route  (real POIs via OSM/Geoapify)
  - ml.recommender.hybrid.get_recommender + ml.recommender.explain.explain
  - ml.itinerary.generate.generate
  - ml.cost.predict.predict_cost
  - ml.db.fetch_all  (media table, journals view)

Public surface (import this):
    from ml.bot.tools import TOOL_DECLARATIONS, dispatch

Constraints: Python 3.10, standard library + existing ml.* modules only.
"""
import urllib.parse

from ml.geo.places import nearby_places, build_route
from ml.recommender.hybrid import get_recommender
from ml.recommender.explain import explain
from ml.itinerary.generate import generate as generate_itinerary
from ml.cost.predict import predict_cost
from ml.db import fetch_all


# --------------------------------------------------------------------------- #
# helpers
# --------------------------------------------------------------------------- #
def _maps_url(lat, lon):
    """Google Maps search deep-link for a coordinate."""
    return f"https://www.google.com/maps/search/?api=1&query={lat},{lon}"


def _csv(items):
    return ",".join(str(i) for i in (items or []))


def _as_list(x):
    """Coerce Gemini args (which may arrive as a comma string or single value)
    into a clean list of strings."""
    if x is None:
        return []
    if isinstance(x, str):
        return [s.strip() for s in x.split(",") if s.strip()]
    if isinstance(x, (list, tuple)):
        out = []
        for i in x:
            if i is None:
                continue
            out.append(str(i).strip())
        return [i for i in out if i]
    return [str(x)]


def _valid_trip_type(trip_type):
    t = (trip_type or "day").lower()
    return t if t in ("day", "weekend", "short", "long") else "day"


# --------------------------------------------------------------------------- #
# 1. find_nearby_places
# --------------------------------------------------------------------------- #
def find_nearby_places(place, trip_type="day", interests=None, keyword=None):
    """Real places near a location, ranked by fame + proximity.

    keyword optionally filters returned place names (e.g. 'ice cream').
    """
    interests = _as_list(interests)
    trip_type = _valid_trip_type(trip_type)
    res = nearby_places(place, mode=trip_type, interests=interests or None, limit=40)
    if "error" in res:
        return res

    kw = (keyword or "").strip().lower()
    # match loosely: ignore spaces and match any keyword word (so "ice cream"
    # matches "Icecream", "Ice Cream Parlour", "Baskin Robbins" via 'cream', etc.)
    kw_squash = kw.replace(" ", "")
    kw_words = [w for w in kw.split() if len(w) > 2]
    ICE_HINTS = ["cream", "icecream", "baskin", "naturals", "amul", "kulfi", "gelato", "scoop"]
    places = []
    for p in res.get("places", []):
        name = p.get("name", "")
        nl = name.lower()
        nl_squash = nl.replace(" ", "")
        if kw:
            hit = (kw in nl or kw_squash in nl_squash
                   or any(w in nl for w in kw_words)
                   or ("cream" in kw and any(h in nl_squash for h in ICE_HINTS)))
            if not hit:
                continue
        lat, lon = p.get("lat"), p.get("lon")
        places.append({
            "name": name,
            "category": p.get("category", ""),
            "dist_km": p.get("dist_km"),
            "lat": lat,
            "lon": lon,
            "image": p.get("image", ""),
            "maps_url": _maps_url(lat, lon),
        })

    return {
        "places": places,
        "count": len(places),
        "origin": res.get("origin", {}),
        "mode": res.get("mode", trip_type),
        "keyword": kw or None,
    }


# --------------------------------------------------------------------------- #
# 2. recommend_destinations
# --------------------------------------------------------------------------- #
def recommend_destinations(budget=None, days=5, interests=None,
                           travel_style="mid-range", origin_region=None):
    """Personalized destination recommendations with plain-language explanations."""
    interests = _as_list(interests)
    budget = float(budget) if budget not in (None, "") else None
    days = int(days) if days not in (None, "") else 5

    rec = get_recommender()
    recs = rec.recommend(
        budget=budget,
        duration_days=days,
        interests=interests,
        travel_style=travel_style or "mid-range",
        origin_region=origin_region or None,
        top_n=5,
    )

    out = []
    for r in recs:
        er = explain(r, budget=budget, interests=interests)
        out.append({
            "destination": er.get("destination"),
            "predicted_cost": er.get("predicted_cost"),
            "explanation": er.get("explanation", ""),
            "best_season": er.get("best_season", ""),
            "budget_fit": er.get("budget_fit"),
        })
    return {"recommendations": out, "count": len(out)}


# --------------------------------------------------------------------------- #
# 3. plan_itinerary
# --------------------------------------------------------------------------- #
def plan_itinerary(destination, days=5, travel_style="mid-range"):
    """Day-by-day itinerary for a destination (trimmed for chat)."""
    days = int(days) if days not in (None, "") else 5
    it = generate_itinerary(destination, days=days,
                            travel_style=travel_style or "mid-range")
    if "error" in it:
        return it

    plan = []
    for d in it.get("plan", []):
        plan.append({
            "day": d.get("day"),
            "title": d.get("title"),
            "stay": d.get("stay"),
            "est_cost": d.get("est_cost"),
            "timeline": [
                {"time": t.get("time"), "text": t.get("text"), "meal": t.get("meal")}
                for t in d.get("timeline", [])
            ],
        })

    return {
        "destination": it.get("destination"),
        "days": it.get("days"),
        "travel_style": it.get("travel_style"),
        "best_season": it.get("best_season", ""),
        "total_cost": it.get("total_cost"),
        "per_day_cost": it.get("per_day_cost"),
        "highlights": it.get("highlights", [])[:6],
        "plan": plan,
    }


# --------------------------------------------------------------------------- #
# 4. estimate_cost
# --------------------------------------------------------------------------- #
def estimate_cost(destination, days=5, travel_style="mid-range"):
    """Predicted trip cost with a low/high band and category breakdown."""
    days = int(days) if days not in (None, "") else 5
    c = predict_cost(destination, duration_days=days,
                     travel_style=travel_style or "mid-range")
    return {
        "destination": destination,
        "days": days,
        "travel_style": travel_style or "mid-range",
        "predicted_cost": c.get("predicted_cost"),
        "low": c.get("low"),
        "high": c.get("high"),
        "per_day": c.get("per_day"),
        "cost_breakdown": c.get("cost_breakdown", {}),
        "currency": c.get("currency", "INR"),
    }


# --------------------------------------------------------------------------- #
# 5. build_trip_route
# --------------------------------------------------------------------------- #
def build_trip_route(place, trip_type="weekend", interests=None, stops=4):
    """Multi-stop road-trip route: ordered stops with what-to-do, where-to-eat,
    images and map links, plus a deep-link to the app's route page."""
    interests = _as_list(interests)
    trip_type = _valid_trip_type(trip_type)
    stops = int(stops) if stops not in (None, "") else 4

    r = build_route(place, mode=trip_type, interests=interests or None, stops=stops)
    if "error" in r:
        return r

    route = []
    for s in r.get("route", []):
        lat, lon = s.get("lat"), s.get("lon")
        eats = s.get("eats") or ([s["eat"]] if s.get("eat") else [])
        route.append({
            "name": s.get("name"),
            "category": s.get("category", ""),
            "do": s.get("do", ""),
            "eats": [
                {"name": e.get("name"), "cuisine": e.get("cuisine", ""),
                 "dist_km": e.get("dist_km")}
                for e in eats
            ],
            "image": s.get("image", ""),
            "dist_km": s.get("dist_km"),
            "maps_url": _maps_url(lat, lon),
        })

    route_url = (
        "route.php?place=" + urllib.parse.quote(place or "")
        + "&mode=" + urllib.parse.quote(trip_type)
        + "&interests=" + urllib.parse.quote(_csv(interests))
    )

    return {
        "origin": r.get("origin", {}),
        "mode": r.get("mode", trip_type),
        "stops": route,
        "stop_count": len(route),
        "total_km": r.get("total_km"),
        "route_url": route_url,
    }


# --------------------------------------------------------------------------- #
# 6. find_user_media
# --------------------------------------------------------------------------- #
def find_user_media(place, limit=12):
    """Public user-posted photos/videos for a destination (from the media table)."""
    limit = int(limit) if limit not in (None, "") else 12
    rows = fetch_all(
        "SELECT kind, filepath, caption, likes FROM media "
        "WHERE is_public = 1 AND destination LIKE %s "
        "ORDER BY likes DESC, media_id DESC LIMIT %s",
        ("%" + (place or "") + "%", limit),
    )
    media = [{
        "kind": r.get("kind"),
        "filepath": r.get("filepath"),
        "caption": r.get("caption") or "",
        "likes": r.get("likes") or 0,
    } for r in rows]
    return {"media": media, "count": len(media), "place": place}


# --------------------------------------------------------------------------- #
# 7. get_my_journal_history
# --------------------------------------------------------------------------- #
def get_my_journal_history(user_eml=None, limit=10):
    """The logged-in user's OWN past trips (from journals + journal_features),
    most recent first. No eml / no rows -> graceful empty result, never an error."""
    limit = int(limit) if limit not in (None, "") else 10
    if not user_eml:
        return {"trips": [], "count": 0,
                "note": "No logged-in user, so no journal history is available."}

    rows = fetch_all(
        "SELECT j.Title, j.Country, j.City, j.duration_days, j.true_total, "
        "       jf.budget_bucket, jf.travel_style, jf.sentiment_label, j.dv "
        "FROM journals j "
        "LEFT JOIN journal_features jf ON jf.entry_id = j.entry_id "
        "WHERE j.eml = %s "
        "ORDER BY j.dv DESC, j.entry_id DESC "
        "LIMIT %s",
        (user_eml, limit),
    )
    trips = [{
        "title": r.get("Title"),
        "country": r.get("Country"),
        "city": r.get("City"),
        "duration_days": r.get("duration_days"),
        "true_total": r.get("true_total"),
        "budget_bucket": r.get("budget_bucket"),
        "travel_style": r.get("travel_style"),
        "sentiment_label": r.get("sentiment_label"),
    } for r in rows]

    if not trips:
        return {"trips": [], "count": 0,
                "note": "This user hasn't logged any trips yet."}
    return {"trips": trips, "count": len(trips)}


# --------------------------------------------------------------------------- #
# 8. booking_links
# --------------------------------------------------------------------------- #
def booking_links(origin=None, destination=None, days=None):
    """Ready-to-use booking deep-links (no API calls)."""
    o = urllib.parse.quote(origin or "")
    d = urllib.parse.quote(destination or "")
    google_flights = (
        "https://www.google.com/travel/flights?q="
        + urllib.parse.quote(f"flights from {origin or ''} to {destination or ''}".strip())
    )
    hotels = "https://www.google.com/travel/hotels/" + d
    makemytrip = "https://www.makemytrip.com/"
    return {
        "origin": origin,
        "destination": destination,
        "days": days,
        "google_flights": google_flights,
        "hotels": hotels,
        "makemytrip": makemytrip,
        "note": ("These are live search deep-links — open them to compare "
                 "real-time flight and hotel prices; JourneyAI does not book directly."),
    }


# --------------------------------------------------------------------------- #
# 9. web_search  (dependency-free; urllib only)
# --------------------------------------------------------------------------- #
def web_search(query, max_results=5):
    """Lightweight web search. Tries DuckDuckGo's HTML endpoint for snippets;
    always returns a Google search deep-link for live browsing."""
    q = (query or "").strip()
    google_url = "https://www.google.com/search?q=" + urllib.parse.quote(q)
    results = []
    if q:
        try:
            results = _duckduckgo(q, max_results=max_results)
        except Exception:
            results = []
    return {
        "query": q,
        "results": results,
        "google_search_url": google_url,
        "note": ("Live browsing isn't available in-app — open the Google search "
                 "link for full, up-to-date results."),
    }


def _duckduckgo(query, max_results=5):
    """Scrape DuckDuckGo's HTML endpoint (no API key) for title/url/snippet.
    Best-effort and regex-based; returns [] on any hiccup."""
    import re
    import html as _html
    import urllib.request

    url = "https://html.duckduckgo.com/html/?q=" + urllib.parse.quote(query)
    req = urllib.request.Request(url, headers={"User-Agent":
                                 "JourneyAI/1.0 (travel assistant; local demo)"})
    with urllib.request.urlopen(req, timeout=15) as r:
        page = r.read().decode("utf-8", "replace")

    results = []
    # result anchors: <a ... class="result__a" href="...">TITLE</a>
    link_re = re.compile(
        r'<a[^>]+class="result__a"[^>]+href="(?P<href>[^"]+)"[^>]*>(?P<title>.*?)</a>',
        re.S)
    snip_re = re.compile(
        r'<a[^>]+class="result__snippet"[^>]*>(?P<snip>.*?)</a>', re.S)
    snippets = [_strip_tags(m.group("snip")) for m in snip_re.finditer(page)]

    for i, m in enumerate(link_re.finditer(page)):
        if len(results) >= max_results:
            break
        href = _unwrap_ddg(m.group("href"))
        title = _strip_tags(m.group("title"))
        snippet = snippets[i] if i < len(snippets) else ""
        if title and href:
            results.append({
                "title": _html.unescape(title),
                "url": href,
                "snippet": _html.unescape(snippet),
            })
    return results


def _strip_tags(s):
    import re
    return re.sub(r"<[^>]+>", "", s or "").strip()


def _unwrap_ddg(href):
    """DDG wraps target URLs in a redirect (…/l/?uddg=<encoded>). Unwrap it."""
    if href.startswith("//"):
        href = "https:" + href
    parsed = urllib.parse.urlparse(href)
    if "uddg" in urllib.parse.parse_qs(parsed.query):
        return urllib.parse.parse_qs(parsed.query)["uddg"][0]
    return href


# --------------------------------------------------------------------------- #
# Gemini function-declaration schemas
# --------------------------------------------------------------------------- #
TOOL_DECLARATIONS = [
    {
        "name": "find_nearby_places",
        "description": (
            "Find REAL places (temples, restaurants, parks, forts, viewpoints, "
            "hotels, malls, etc.) near a location. Use for queries like 'places "
            "near Anand', 'temples around Nadiad', or 'ice cream near me' (pass "
            "interests=['food'] and keyword='ice cream'). Returns ranked places "
            "with distance, coordinates, an image and a Google Maps link."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "place": {"type": "STRING",
                          "description": "Origin location, e.g. 'Nadiad, Gujarat'."},
                "trip_type": {"type": "STRING",
                              "description": "Search radius bucket: 'day' (~120km), "
                                             "'weekend' (~300km), 'short' (~600km), "
                                             "'long' (~3000km). Default 'day'."},
                "interests": {"type": "ARRAY", "items": {"type": "STRING"},
                              "description": "Interest tags to bias results, e.g. "
                                             "['temples'], ['food'], ['nature']."},
                "keyword": {"type": "STRING",
                            "description": "Optional name filter, e.g. 'ice cream' "
                                           "to keep only matching place names."},
            },
            "required": ["place"],
        },
    },
    {
        "name": "recommend_destinations",
        "description": (
            "Recommend travel destinations personalized to a budget, trip length, "
            "interests and travel style, each with a plain-language explanation of "
            "WHY it fits and its best season. Use when the user asks 'where should "
            "I go', 'suggest a trip', or wants destination ideas."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "budget": {"type": "NUMBER",
                           "description": "Total budget in INR (optional)."},
                "days": {"type": "NUMBER", "description": "Trip length in days (default 5)."},
                "interests": {"type": "ARRAY", "items": {"type": "STRING"},
                              "description": "e.g. ['beach','food'] or ['history','temples']."},
                "travel_style": {"type": "STRING",
                                 "description": "'backpacker','budget','mid-range','luxury','family','solo'."},
                "origin_region": {"type": "STRING",
                                  "description": "Traveler's home region for a 'near me' nudge (optional)."},
            },
            "required": [],
        },
    },
    {
        "name": "plan_itinerary",
        "description": (
            "Build a day-by-day itinerary for a specific destination: each day has "
            "a morning/midday/afternoon/evening timeline, meals, a suggested stay, "
            "and per-day cost. Use when the user names a destination and wants a plan."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "destination": {"type": "STRING",
                                "description": "Destination name, e.g. 'Goa, India'."},
                "days": {"type": "NUMBER", "description": "Number of days (default 5)."},
                "travel_style": {"type": "STRING",
                                 "description": "'budget','mid-range','luxury','family','solo','adventure'."},
            },
            "required": ["destination"],
        },
    },
    {
        "name": "estimate_cost",
        "description": (
            "Estimate the cost of a trip to a destination for a given number of "
            "days and travel style. Returns a point estimate, a low/high band and "
            "a category breakdown (stay, food, transport, etc.) in INR."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "destination": {"type": "STRING", "description": "Destination name."},
                "days": {"type": "NUMBER", "description": "Number of days (default 5)."},
                "travel_style": {"type": "STRING",
                                 "description": "'backpacker','budget','mid-range','luxury'."},
            },
            "required": ["destination"],
        },
    },
    {
        "name": "build_trip_route",
        "description": (
            "Build a multi-stop road-trip route from an origin: ordered stops with "
            "what to do, where to eat, images, map links, total distance and a link "
            "to the app's route page. Use for 'plan a road trip', 'a temple route "
            "from X', or 'a weekend route near Y'."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "place": {"type": "STRING", "description": "Starting location."},
                "trip_type": {"type": "STRING",
                              "description": "'day','weekend','short','long' (radius bucket). Default 'weekend'."},
                "interests": {"type": "ARRAY", "items": {"type": "STRING"},
                              "description": "Theme the route, e.g. ['temples'] or ['nature']."},
                "stops": {"type": "NUMBER", "description": "Number of stops (default 4)."},
            },
            "required": ["place"],
        },
    },
    {
        "name": "find_user_media",
        "description": (
            "Find PUBLIC photos and videos posted by JourneyAI users for a "
            "destination. Use when the user wants to 'see photos of', 'show me "
            "pictures of', or browse real user media for a place."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "place": {"type": "STRING", "description": "Destination to search media for."},
            },
            "required": ["place"],
        },
    },
    {
        "name": "get_my_journal_history",
        "description": (
            "Look up the LOGGED-IN USER'S OWN past trips (from their journal "
            "entries), most recent first — title, country/city, duration, actual "
            "amount spent, budget bucket, travel style and sentiment. Call this "
            "when the user references their own past trips ('my Goa trip'), asks "
            "for budget comparisons to a previous trip, asks what they spent, or "
            "wants a new plan similar to / cheaper than something they did before. "
            "Do NOT pass user_eml — it is filled in automatically from the session."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "limit": {"type": "NUMBER",
                          "description": "Max past trips to return (default 10)."},
            },
            "required": [],
        },
    },
    {
        "name": "booking_links",
        "description": (
            "Get ready-to-use deep-links to search flights (Google Flights), hotels "
            "(Google Hotels) and MakeMyTrip for a trip. No live prices — just links "
            "the user can open. Use when the user wants to book or compare prices."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "origin": {"type": "STRING", "description": "Departure city."},
                "destination": {"type": "STRING", "description": "Destination city."},
                "days": {"type": "NUMBER", "description": "Trip length in days (optional)."},
            },
            "required": ["destination"],
        },
    },
    {
        "name": "web_search",
        "description": (
            "Search the web for general/current information not covered by the "
            "other tools (e.g. visa rules, weather, events, opening hours). Returns "
            "best-effort snippets plus a Google search link for live browsing."
        ),
        "parameters": {
            "type": "OBJECT",
            "properties": {
                "query": {"type": "STRING", "description": "Search query."},
            },
            "required": ["query"],
        },
    },
]


# --------------------------------------------------------------------------- #
# dispatch
# --------------------------------------------------------------------------- #
_TOOLS = {
    "find_nearby_places": find_nearby_places,
    "recommend_destinations": recommend_destinations,
    "plan_itinerary": plan_itinerary,
    "estimate_cost": estimate_cost,
    "build_trip_route": build_trip_route,
    "find_user_media": find_user_media,
    "get_my_journal_history": get_my_journal_history,
    "booking_links": booking_links,
    "web_search": web_search,
}


def dispatch(name, args):
    """Run the tool `name` with keyword `args` (a dict). Always returns a dict;
    on any failure returns {'error': '...'} instead of raising."""
    fn = _TOOLS.get(name)
    if fn is None:
        return {"error": f"Unknown tool: {name}"}
    if not isinstance(args, dict):
        return {"error": f"Tool args for '{name}' must be an object."}
    try:
        result = fn(**args)
        if not isinstance(result, dict):
            return {"result": result}
        return result
    except TypeError as e:
        return {"error": f"Bad arguments for '{name}': {e}"}
    except Exception as e:
        return {"error": f"Tool '{name}' failed: {e}"}


if __name__ == "__main__":
    out = dispatch("find_nearby_places",
                   {"place": "Nadiad, Gujarat", "trip_type": "day",
                    "interests": ["temples"]})
    if "error" in out:
        print("ERROR:", out["error"])
    else:
        display = out["origin"].get("display", "Nadiad")[:40]
        print(f"find_nearby_places -> {out['count']} places near {display}"
              .encode("ascii", "replace").decode("ascii"))
        for p in out["places"][:5]:
            line = f"  {p['dist_km']:>5}km  {p['category']:10s} {p['name']}"
            print(line.encode("ascii", "replace").decode("ascii"))
