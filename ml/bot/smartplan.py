"""
JourneyAI — "instant trip" smart planner.

Parses a free-text request like:
  "3 days from Ahmedabad, temples and food, budget 15000"
into structured intent, then assembles a COMPLETE trip in one shot:
  origin, nearby real places (or destination recs), a route, and a cost estimate.

Uses the LLM to parse when available (better), but has a robust regex fallback
so it ALWAYS works even with no Gemini quota.
"""
import re

from ml.geo.places import nearby_places, build_route, build_route_between, geocode as geocode_place
from ml.cost.predict import predict_cost
from ml.nlp.lexicon import CANONICAL_ACTIVITIES

_INTEREST_WORDS = {
    "temple": "temples", "temples": "temples", "spiritual": "temples", "religious": "temples",
    "food": "food", "eat": "food", "cuisine": "food", "restaurant": "food",
    "beach": "beach", "sea": "beach", "coast": "beach",
    "history": "history", "heritage": "history", "fort": "history", "historic": "history",
    "nature": "nature", "waterfall": "nature", "lake": "nature", "scenic": "nature",
    "garden": "gardens", "park": "gardens",
    "museum": "museums", "art": "museums",
    "shopping": "shopping", "market": "shopping", "shop": "shopping",
    "adventure": "adventure", "trek": "trekking", "hiking": "trekking",
    "wildlife": "wildlife", "safari": "wildlife",
    "night": "nightlife", "nightlife": "nightlife", "party": "nightlife",
    "relax": "relaxation", "chill": "relaxation",
}


def parse(text):
    """Regex-based intent parse. Returns dict {origin, destination, days, budget, interests, mode}."""
    t = (text or "").lower()

    # origin extraction. "from X" is the strongest origin signal; try it first,
    # then "near/around/in/at X". Stop at commas/interest/budget words. Handle
    # "near me" specially (caller resolves geolocation).
    origin = None
    destination = None
    _STOP = (r"(?=\s*(?:,|\bwith\b|\bfor\b|\band\b|\bbudget\b|\bunder\b|\brs\b|₹|\bwant\b|\blike\b|"
             r"\btemple|\bfood|\bbeach|\bhistor|\bnature|\bgarden|\bmuseum|\bshop|\badventure|"
             r"\btrek|\bwildlife|\bnight|\brelax|\bplaces?\b|\bvisit\b|\bday\b|\d|$))")
    # "from X to Y" / "X to Y route" — a fixed two-point trip, check BEFORE the
    # plain "from X" pattern so it doesn't swallow "to Y" into the origin.
    m = re.search(r"\bfrom\s+([a-z][a-z .'-]{1,40}?)\s+to\s+([a-z][a-z .'-]{1,40}?)" + _STOP, t)
    if not m:
        m = re.search(r"\b([a-z][a-z .'-]{1,40}?)\s+to\s+([a-z][a-z .'-]{1,40}?)\s+(?:route|trip|road\s*trip)\b", t)
    if m:
        origin = m.group(1).strip(" .,")
        destination = m.group(2).strip(" .,")
    if re.search(r"\bnear\s+me\b", t):
        origin = "near me"    # sentinel — caller swaps in the user's city
    if not origin:
        m = re.search(r"\bfrom\s+([a-z][a-z .'-]{1,40}?)" + _STOP, t)
        if m:
            origin = m.group(1).strip(" .,")
    if not origin:
        m = re.search(r"\b(?:around|in|at)\s+([a-z][a-z .'-]{1,40}?)" + _STOP, t)
        if m:
            origin = m.group(1).strip(" .,")
    if not origin:
        # last: "near X" but NOT "near by / nearby / near me"
        m = re.search(r"\bnear\s+(?!by\b|me\b)([a-z][a-z .'-]{1,40}?)" + _STOP, t)
        if m:
            origin = m.group(1).strip(" .,")
    # reject junk captures
    if origin and origin in ("me", "by", "here", "there", "places", "place"):
        origin = None if origin != "me" else "near me"

    # days
    days = None
    m = re.search(r"(\d+)\s*[- ]?\s*(?:day|days|din)", t)
    if m:
        days = int(m.group(1))
    elif "weekend" in t:
        days = 2
    elif "day trip" in t or "one day" in t or "1 day" in t:
        days = 1

    # budget (15000, 15k, rs 15000, ₹15,000)
    budget = None
    m = re.search(r"(?:budget|under|below|₹|rs\.?|inr)\s*([\d,]+)\s*(k|thousand)?", t)
    if not m:
        m = re.search(r"\b([\d,]{3,})\s*(?:rupees|rs|₹)?\b", t)
    if m:
        num = int(m.group(1).replace(",", ""))
        if m.lastindex and m.group(m.lastindex) in ("k", "thousand"):
            num *= 1000
        elif num < 1000 and ("k" in t):
            num *= 1000
        budget = num

    # interests
    interests = []
    for w, tag in _INTEREST_WORDS.items():
        if re.search(r"\b" + re.escape(w), t) and tag not in interests:
            interests.append(tag)

    # anything else the shared gazetteer knows (snow, mountains, trek, desert, safari, ...)
    from ml.nlp.lexicon import ACTIVITY_GAZETTEER
    for w, tag in ACTIVITY_GAZETTEER.items():
        if tag not in interests and len(w) > 3 and re.search(r"\b" + re.escape(w) + r"\b", t):
            interests.append(tag)

    # trip mode from days
    if days is None:
        days = 2
    mode = "day" if days <= 1 else ("weekend" if days <= 2 else ("short" if days <= 5 else "long"))
    return {"origin": origin, "destination": destination, "days": days, "budget": budget,
            "interests": interests, "mode": mode}


def parse_with_llm(text):
    """Try the LLM for a cleaner parse; fall back to regex."""
    # A bare place name ("Gir", "Dwarka", "Mount Abu") is a PLACE, not an interest: plan that place.
    t = (text or "").strip()
    base0 = parse(t)
    if (re.fullmatch(r"[A-Za-z][A-Za-z .'-]{1,38}", t) and len(t.split()) <= 3 and not base0["origin"]
            and not base0["interests"] and not base0["budget"]
            and t.lower() not in ("hi", "hello", "hey", "help", "thanks", "thank you", "ok", "okay", "yes", "no")):
        return {"origin": t.lower(), "destination": None, "days": 3, "budget": None, "interests": [],
                "mode": "short", "place_only": True}
    try:
        from ml.bot import gemini
        if not gemini.has_key():
            return parse(text)
        prompt = (
            "Extract travel intent from this request as strict JSON with keys "
            "origin(string or null), destination(string or null — ONLY set if the "
            "request names a fixed end point for a route, e.g. 'from Ahmedabad to "
            "Vadodara' or 'Surat to Diu road trip'; leave null for a normal "
            "single-place/nearby request), days(int), budget(int rupees or null), "
            "interests(array using ONLY these words: " + ",".join(CANONICAL_ACTIVITIES) + "). "
            "Request: " + text + "\nReturn ONLY the JSON object."
        )
        r = gemini.generate([{"role": "user", "parts": [{"text": prompt}]}])
        txt = gemini.extract_text(r)
        import json
        mt = re.search(r"\{.*\}", txt, re.S)
        if mt:
            d = json.loads(mt.group(0))
            days = int(d.get("days") or 2)
            base = parse(text)   # rule-based parse is the safety net: keep its interests too
            llm_int = [i for i in (d.get("interests") or []) if i in CANONICAL_ACTIVITIES]
            interests = llm_int + [i for i in base["interests"] if i not in llm_int]
            origin, destination = d.get("origin") or base["origin"], d.get("destination")
            two_point = bool(re.search(r"\bto\b|->|\u2192", text.lower()))
            if not origin and destination and not two_point:
                origin, destination = destination, None      # "weekend in Ahmedabad": one place, not a route
            if destination and origin and destination.strip().lower() == origin.strip().lower():
                destination = None
            if not two_point:
                destination = None                            # a route needs an explicit "A to B"
            return {"origin": origin, "destination": destination, "days": days,
                    "budget": d.get("budget") or base["budget"], "interests": interests,
                    "mode": "day" if days <= 1 else ("weekend" if days <= 2 else ("short" if days <= 5 else "long"))}
    except Exception:
        pass
    return parse(text)


def plan(text, travel_style="mid-range"):
    """Build a COMPLETE trip from one free-text line."""
    intent = parse_with_llm(text)
    origin = intent["origin"]
    if not origin and (intent["interests"] or intent["budget"]):
        # No starting point, but the user described a trip ("3 days beaches under 20000"):
        # answer with ranked, explained destinations instead of asking again.
        from ml.recommender.hybrid import get_recommender
        from ml.recommender.explain import explain
        recs = get_recommender().recommend(
            budget=intent["budget"], duration_days=intent["days"], interests=intent["interests"],
            travel_style=travel_style, top_n=4)
        recs = [explain(r, budget=intent["budget"], interests=intent["interests"]) for r in recs]
        return {"intent": intent, "origin_text": "", "days": intent["days"], "interests": intent["interests"],
                "budget": intent["budget"], "recommendations": recs, "kind": "recommend", "status": "ok"}
    if not origin or origin == "near me":
        return {"error": "Tell me where you're starting from — e.g. 'weekend from Ahmedabad, temples & food'. "
                         "(Tip: allow location access for 'near me' to work.)",
                "intent": intent, "need_location": (origin == "near me")}

    days = intent["days"]
    interests = intent["interests"]
    mode = intent["mode"]
    fixed_destination = intent.get("destination")

    result = {"intent": intent, "origin_text": origin, "days": days,
              "interests": interests, "budget": intent["budget"]}

    if intent.get("place_only"):
        # one named place: a full itinerary if we know it, otherwise real places around it as a local route
        from ml.itinerary.generate import generate as generate_itinerary
        itin = generate_itinerary(origin, days=days, travel_style=travel_style, party_size=1)
        if "error" not in itin:
            result.update({"itinerary": itin, "kind": "itinerary",
                           "est_cost": itin.get("total_cost", days * 2500)})
            return result
        mode = "weekend"; days = 2; result["days"] = 2

    if fixed_destination:
        # explicit "from X to Y" — a fixed two-point route, not a round trip
        route = build_route_between(origin, fixed_destination, interests=interests,
                                     stops=min(6, max(3, days * 3)))
        if "error" in route:
            return {"error": route["error"], "intent": intent}
        result["route"] = route
        result["origin_geo"] = route.get("origin")
        result["destination_geo"] = route.get("destination")
        result["kind"] = "route_between"
        result["est_cost"] = days * (1500 if travel_style == "budget" else 2500)
    elif mode in ("day", "weekend"):
        # LOCAL trip -> real nearby places + a route
        route = build_route(origin, mode=mode, interests=interests, stops=min(6, max(3, days * 3)))
        if "error" in route:
            near = nearby_places(origin, mode=mode, interests=interests, limit=12)
            result["nearby"] = near.get("places", [])
            result["origin_geo"] = near.get("origin")
            result["kind"] = "nearby"
        else:
            result["route"] = route
            result["origin_geo"] = route.get("origin")
            result["kind"] = "route"
        # rough cost = days * a daily local estimate
        result["est_cost"] = days * (1500 if travel_style == "budget" else 2500)
    else:
        # LONGER trip. If the user named a real, known destination (e.g. "3 days
        # in Dwarka"), build ITS itinerary directly — don't second-guess them
        # with unrelated "best matches" just because the trip is 3+ days.
        # Only fall back to destination recommendations when no place was named
        # or it's not a destination our catalog/itinerary generator recognizes.
        from ml.itinerary.generate import generate as generate_itinerary
        # "3 days FROM Nadiad" means the user starts there and wants somewhere to GO (Junagadh, Dwarka, Gir...),
        # whereas "3 days in Goa" names the destination itself.
        starts_from = bool(origin) and bool(re.search(r"\bfrom\s+" + re.escape(origin.lower()), text.lower()))
        itin = generate_itinerary(origin, days=days, travel_style=travel_style,
                                   party_size=1) if (origin and not starts_from) else {"error": "no origin"}
        if "error" not in itin:
            result["itinerary"] = itin
            result["kind"] = "itinerary"
            result["est_cost"] = itin.get("total_cost", days * (1500 if travel_style == "budget" else 2500))
            return result

        # LONGER trip, unrecognized place -> destination recommendations
        from ml.recommender.hybrid import get_recommender
        from ml.recommender.explain import explain
        origin_latlon = None
        if origin:
            g = geocode_place(origin)
            if g:
                origin_latlon = (g[0], g[1])
        recs = get_recommender().recommend(
            budget=intent["budget"], duration_days=days, interests=interests,
            travel_style=travel_style, top_n=4,
            origin_latlon=origin_latlon, trip_mode=(mode if mode in ("short", "long") else None))
        recs = [explain(r, budget=intent["budget"], interests=interests) for r in recs]
        result["recommendations"] = recs
        result["kind"] = "recommend"

    return result


if __name__ == "__main__":
    import json
    for q in ["weekend from Ahmedabad, temples and food",
              "3 days from Mumbai beaches budget 25000",
              "1 day near Anand ice cream and gardens"]:
        r = plan(q)
        print("\n>>>", q)
        print("  kind:", r.get("kind"), "| intent:", r.get("intent"))
