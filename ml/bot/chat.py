"""
JourneyAI — chatbot orchestrator (Gemini function-calling loop).

Takes a user message (+ short history + optional user email/location context),
lets Gemini decide which JourneyAI tools to call, executes them, feeds results
back, and returns a final natural-language reply plus structured `cards` for
the UI to render (places, recommendations, itinerary, media, booking links).
"""
import json

from ml.bot import gemini
from ml.bot.tools import TOOL_DECLARATIONS, dispatch

SYSTEM = """You are the JourneyAI travel assistant — a friendly, concise expert on trips in India and worldwide.
You help users discover places, plan day trips/weekends/longer trips, estimate budgets, find where to eat,
build routes, see user-posted photos/videos, and get flight/stay booking links.

Rules:
- Use the provided tools to get REAL data (places, costs, itineraries, media, routes). Don't invent place names, distances or costs — call a tool.
- For "near me" / "nearby" / "1 day" style queries, call find_nearby_places (trip_type='day'). Use interests to focus (e.g. ["temples"], ["food"]). For "ice cream" pass interests=["food"] and keyword="ice cream".
- For "where should I go" with a budget/days, call recommend_destinations, then optionally plan_itinerary and estimate_cost for the chosen place.
- When the user asks to eat, include food places. When they ask for photos/videos of a place, call find_user_media.
- For flights/hotels/booking, call booking_links (these are deep links, not live bookings).
- Keep replies short and helpful. Refer to results naturally; the UI will also show cards. Use **bold** for names and [text](url) for links sparingly.
- If a tool returns nothing, say so briefly and suggest an alternative.
"""

MAX_TOOL_ROUNDS = 4


def _cards_from_tool(name, result):
    """Convert a tool result into UI cards the chat panel can render."""
    cards = []
    if not isinstance(result, dict) or result.get("error"):
        return cards
    if name == "find_nearby_places" or name == "build_trip_route":
        places = result.get("places") or []
        if name == "build_trip_route":
            places = result.get("route") or result.get("stops") or []
        for p in places[:8]:
            cards.append({
                "type": "place", "title": p.get("name", ""),
                "subtitle": (str(p.get("dist_km", "")) + " km · " + p.get("category", "")).strip(" ·"),
                "image": p.get("image", ""), "url": p.get("maps_url", ""),
                "meta": p.get("do", ""),
            })
        if name == "build_trip_route" and result.get("route_url"):
            cards.append({"type": "action", "title": "Open full route",
                          "url": result["route_url"], "subtitle": f"{result.get('total_km','')} km"})
    elif name == "recommend_destinations":
        for r in (result.get("recommendations") or result.get("results") or [])[:6]:
            cards.append({
                "type": "reco", "title": r.get("destination", ""),
                "subtitle": ("₹" + format(int(r.get("predicted_cost", 0)), ",")) if r.get("predicted_cost") else "",
                "meta": (r.get("explanation", "") or "")[:140],
                "url": "itinerary.php?dest=" + r.get("destination", "").replace(" ", "%20"),
            })
    elif name == "plan_itinerary":
        for d in (result.get("plan") or [])[:8]:
            items = d.get("timeline") or d.get("items") or []
            sub = " · ".join(t.get("text", "") for t in items[:3]) if items else ""
            cards.append({"type": "day", "title": d.get("title", "Day"), "subtitle": sub,
                          "meta": "₹" + str(d.get("est_cost", "")) if d.get("est_cost") else ""})
    elif name == "find_user_media":
        for m in (result.get("media") or [])[:8]:
            cards.append({"type": "media", "kind": m.get("kind", "photo"),
                          "image": m.get("filepath", ""), "title": m.get("caption", "User post"),
                          "subtitle": ("♥ " + str(m.get("likes", 0)))})
    elif name == "booking_links":
        for label, key in [("Flights", "google_flights"), ("Hotels", "hotels"), ("MakeMyTrip", "makemytrip")]:
            if result.get(key):
                cards.append({"type": "action", "title": label, "url": result[key], "subtitle": "Book / compare"})
    elif name == "estimate_cost":
        cards.append({"type": "cost", "title": result.get("destination", "Estimated cost"),
                      "subtitle": "₹" + format(int(result.get("predicted_cost", 0)), ","),
                      "meta": "range ₹" + format(int(result.get("low", 0)), ",") + "–₹" + format(int(result.get("high", 0)), ",")})
    return cards


def chat(message, history=None, user_eml=None, user_location=None):
    """Run one chat turn. Returns {reply, cards}."""
    if not gemini.has_key():
        return {"reply": "The assistant isn't configured yet — a Gemini API key is needed. "
                         "You can still use Plan a Trip and the route builder.", "cards": []}

    # build Gemini `contents` from history + this message
    contents = []
    for h in (history or [])[-8:]:
        role = "user" if h.get("role") == "user" else "model"
        contents.append({"role": role, "parts": [{"text": h.get("text", "")}]})
    ctx = message
    if user_location:
        ctx += f"\n\n(User's location: {user_location})"
    contents.append({"role": "user", "parts": [{"text": ctx}]})

    all_cards = []
    try:
        for _ in range(MAX_TOOL_ROUNDS):
            resp = gemini.generate(contents, tools=TOOL_DECLARATIONS, system=SYSTEM)
            calls = gemini.extract_calls(resp)
            if not calls:
                reply = gemini.extract_text(resp) or "Here's what I found."
                return {"reply": reply, "cards": all_cards}
            # execute tool calls, append model turn + function responses
            model_parts = []
            for c in calls:
                model_parts.append({"functionCall": {"name": c["name"], "args": c["args"]}})
            contents.append({"role": "model", "parts": model_parts})
            resp_parts = []
            for c in calls:
                if user_eml and c["name"] == "find_user_media" and "eml" not in c["args"]:
                    pass
                result = dispatch(c["name"], c["args"])
                all_cards.extend(_cards_from_tool(c["name"], result))
                resp_parts.append({"functionResponse": {
                    "name": c["name"],
                    "response": {"result": result},
                }})
            contents.append({"role": "user", "parts": resp_parts})
        # ran out of rounds — ask for a final summary
        resp = gemini.generate(contents, system=SYSTEM)
        return {"reply": gemini.extract_text(resp) or "Here's what I found.", "cards": all_cards}
    except Exception as e:
        msg = str(e)
        if "429" in msg or "quota" in msg.lower():
            # Free-tier quota hit — fall back to a direct tool call so the user
            # still gets useful results without the LLM.
            fb = _fallback(message, user_eml)
            if fb:
                return fb
            return {"reply": "The AI assistant's free daily quota is used up (resets at midnight "
                             "Pacific). Meanwhile, try Plan a Trip or the route builder!", "cards": all_cards}
        return {"reply": "Sorry, I hit an error answering that. Try rephrasing?", "cards": all_cards}


def _fallback(message, user_eml=None):
    """Best-effort answer WITHOUT the LLM (used when Gemini quota is exhausted).
    Simple intent detection -> direct tool call."""
    m = message.lower()
    import re
    # extract a place after 'near' / 'in' / 'at'
    place = None
    mt = re.search(r"\b(?:near|in|at|around)\s+([a-zA-Z .,'-]{2,60})", message)
    if mt:
        place = mt.group(1).strip(" .,")
    try:
        if place and any(w in m for w in ["place", "visit", "nearby", "near me", "temple", "food",
                                          "eat", "ice cream", "day trip", "1 day", "weekend"]):
            interests = []
            if "temple" in m: interests = ["temples"]
            elif any(w in m for w in ["eat", "food", "restaurant", "ice cream"]): interests = ["food"]
            kw = "ice cream" if "ice cream" in m else None
            res = dispatch("find_nearby_places", {"place": place, "trip_type": "day",
                                                  "interests": interests, "keyword": kw})
            if res.get("places"):
                cards = _cards_from_tool("find_nearby_places", res)
                names = ", ".join(p["name"] for p in res["places"][:4])
                return {"reply": f"Here are some spots near {place}: {names}. "
                                 f"(AI assistant is on cooldown — showing direct results.)",
                        "cards": cards}
    except Exception:
        pass
    return None


if __name__ == "__main__":
    for q in ["Places to visit near Nadiad, I have 1 day",
              "ice cream places near Anand"]:
        print("\n>>>", q)
        r = chat(q)
        print("BOT:", r["reply"][:200].encode("ascii", "replace").decode())
        print("cards:", len(r["cards"]))
