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

from ml.geo.places import nearby_places, build_route
from ml.cost.predict import predict_cost

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
    """Regex-based intent parse. Returns dict {origin, days, budget, interests, mode}."""
    t = (text or "").lower()

    # origin: after 'from' / 'in' / 'near' / 'around', stop at comma or a
    # stop-word (interest/budget) so we don't capture "ahmedabad, temples".
    origin = None
    m = re.search(r"\b(?:from|near|around|in|at)\s+([a-z][a-z .'-]{1,40}?)(?=\s*(?:,|\bwith\b|\bfor\b|\band\b|\bbudget\b|\bunder\b|\brs\b|₹|\bwant\b|\blike\b|\btemple|\bfood|\bbeach|\bhistor|\bnature|\bgarden|\bmuseum|\bshop|\badventure|\btrek|\bwildlife|\bnight|\brelax|\d|$))", t)
    if m:
        origin = m.group(1).strip(" .,")

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

    # trip mode from days
    if days is None:
        days = 2
    mode = "day" if days <= 1 else ("weekend" if days <= 2 else ("short" if days <= 5 else "long"))
    return {"origin": origin, "days": days, "budget": budget,
            "interests": interests, "mode": mode}


def parse_with_llm(text):
    """Try the LLM for a cleaner parse; fall back to regex."""
    try:
        from ml.bot import gemini
        if not gemini.has_key():
            return parse(text)
        prompt = (
            "Extract travel intent from this request as strict JSON with keys "
            "origin(string or null), days(int), budget(int rupees or null), "
            "interests(array from: temples,food,beach,history,nature,gardens,museums,"
            "shopping,adventure,trekking,wildlife,nightlife,relaxation). "
            "Request: " + text + "\nReturn ONLY the JSON object."
        )
        r = gemini.generate([{"role": "user", "parts": [{"text": prompt}]}])
        txt = gemini.extract_text(r)
        import json
        mt = re.search(r"\{.*\}", txt, re.S)
        if mt:
            d = json.loads(mt.group(0))
            days = int(d.get("days") or 2)
            return {"origin": d.get("origin"), "days": days,
                    "budget": d.get("budget"), "interests": d.get("interests") or [],
                    "mode": "day" if days <= 1 else ("weekend" if days <= 2 else ("short" if days <= 5 else "long"))}
    except Exception:
        pass
    return parse(text)


def plan(text, travel_style="mid-range"):
    """Build a COMPLETE trip from one free-text line."""
    intent = parse_with_llm(text)
    origin = intent["origin"]
    if not origin:
        return {"error": "Tell me where you're starting from — e.g. 'weekend from Ahmedabad, temples & food'.",
                "intent": intent}

    days = intent["days"]
    interests = intent["interests"]
    mode = intent["mode"]

    result = {"intent": intent, "origin_text": origin, "days": days,
              "interests": interests, "budget": intent["budget"]}

    if mode in ("day", "weekend"):
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
        # LONGER trip -> destination recommendations
        from ml.recommender.hybrid import get_recommender
        from ml.recommender.explain import explain
        recs = get_recommender().recommend(
            budget=intent["budget"], duration_days=days, interests=interests,
            travel_style=travel_style, top_n=4)
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
