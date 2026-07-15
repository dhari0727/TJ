"""
JourneyAI — day-by-day itinerary generator (offline, template + data driven).

Given a destination + trip length + style, builds a structured multi-day plan:
each day has a morning / midday / afternoon / evening timeline of real
activities (drawn from the destination's attractions + activity profile), meal
suggestions, a suggested stay tier, and a per-day cost derived from the trained
cost model. No LLM, no paid API — everything comes from our catalog and models.

Used by the /itinerary Flask endpoint.
"""
import random

from ml.seed.destination_catalog import by_name, get_destinations
from ml.cost.predict import predict_cost, month_to_season

# Activity -> a short set of "doable" slot descriptions (templated, destination-agnostic;
# the real attraction name is slotted in where possible).
ACTIVITY_SLOTS = {
    "beach":       ["Relax on {a}", "Sunbathe and swim at {a}", "Beach walk along {a}", "Sunset at {a}"],
    "trekking":    ["Trek to {a}", "Morning hike around {a}", "Nature walk near {a}"],
    "food":        ["Food trail near {a}", "Try local cuisine around {a}", "Street-food tasting"],
    "nightlife":   ["Evening out near {a}", "Live music and bars", "Nightlife around {a}"],
    "history":     ["Explore {a}", "Guided history walk at {a}", "Visit {a}"],
    "temples":     ["Morning darshan at {a}", "Visit {a}", "Evening aarti near {a}"],
    "museums":     ["Tour {a}", "Visit the galleries at {a}"],
    "shopping":    ["Browse the markets at {a}", "Souvenir shopping near {a}"],
    "wildlife":    ["Wildlife safari at {a}", "Early-morning safari", "Nature reserve visit at {a}"],
    "adventure":   ["Adventure activity at {a}", "Try water/air sports", "Adrenaline session near {a}"],
    "relaxation":  ["Unwind at {a}", "Spa and slow morning", "Leisure time around {a}"],
    "photography": ["Photo walk at {a}", "Golden-hour shoot at {a}", "Scenic viewpoints near {a}"],
    "nature":      ["Explore {a}", "Nature trail at {a}", "Picnic near {a}"],
    "culture":     ["Cultural walk at {a}", "Local village/culture visit", "Experience local traditions at {a}"],
    "backwaters":  ["Houseboat cruise on {a}", "Backwater village tour", "Sunset on the backwaters"],
    "mountains":   ["Mountain viewpoint at {a}", "Scenic drive around {a}", "Cable car / peak visit at {a}"],
    "desert":      ["Desert safari at {a}", "Dune sunset at {a}", "Camel ride near {a}"],
    "snow":        ["Snow activities at {a}", "Skiing / snow point at {a}"],
    "diving":      ["Snorkelling / diving at {a}", "Reef trip near {a}"],
    "architecture":["Admire the architecture of {a}", "Heritage walk at {a}", "Visit {a}"],
}

MEALS = {
    "breakfast": ["Local breakfast at the stay", "Cafe breakfast nearby", "Traditional morning meal"],
    "lunch":     ["Lunch at a popular local spot", "Regional thali / set lunch", "Casual lunch nearby"],
    "dinner":    ["Dinner at a well-rated restaurant", "Local specialities for dinner", "Relaxed dinner near the stay"],
}

STAY_TIER = {
    "budget":     "Comfortable budget guesthouse",
    "backpacker": "Well-rated hostel / homestay",
    "mid-range":  "Boutique mid-range hotel",
    "family":     "Family-friendly hotel with amenities",
    "solo":       "Central, safe boutique stay",
    "adventure":  "Adventure camp / lodge",
    "luxury":     "Premium resort or heritage hotel",
}


def _slot_text(rng, activity, attractions_pool):
    tmpls = ACTIVITY_SLOTS.get(activity, ["Explore {a}"])
    t = rng.choice(tmpls)
    if "{a}" in t:
        a = rng.choice(attractions_pool) if attractions_pool else activity
        return t.format(a=a)
    return t


def _dynamic_destination(place):
    """Build a catalog-like destination dict for ANY place using real nearby
    POIs (Geoapify/OSM) — so itineraries work beyond the 93 curated places."""
    try:
        from ml.geo.places import nearby_places
        near = nearby_places(place, mode="day", interests=None, limit=20)
        if "error" in near or not near.get("places"):
            return None
        pois = near["places"]
        attractions = [p["name"] for p in pois][:10] or [place.split(",")[0]]
        # derive activity tags from the categories present
        catmap = {"religious": "temples", "heritage": "history", "garden": "nature",
                  "nature": "nature", "museum": "museums", "funpark": "adventure",
                  "beach": "beach", "food": "food"}
        acts = []
        for p in pois:
            a = catmap.get(p["category"])
            if a and a not in acts:
                acts.append(a)
        acts = acts or ["nature", "food", "history"]
        city = place.split(",")[0].strip()
        return {
            "name": place, "city": city,
            "country": (place.split(",")[1].strip() if "," in place else "India"),
            "region": "India", "tier": "lesser_known", "base_daily_inr": 2200,
            "activities": acts, "styles": ["mid-range", "budget"],
            "season_peak": [10, 11, 12, 1, 2], "attractions": attractions,
        }
    except Exception:
        return None


def generate(destination, days=5, travel_style="mid-range", month=None,
             party_size=1, seed=None):
    d = by_name(destination)
    if d is None:
        # fuzzy: match by city
        for x in get_destinations():
            if x["city"].lower() == str(destination).split(",")[0].strip().lower():
                d = x; break
    if d is None:
        # Not in our catalog — build one on the fly from REAL nearby places
        # (works for any place: Matheran, Lonavala, etc.).
        d = _dynamic_destination(destination)
    if d is None:
        return {"error": f"Unknown destination: {destination}"}

    days = max(1, min(21, int(days)))
    rng = random.Random(seed if seed is not None else hash(d["name"]) & 0xffff)

    cost = predict_cost(d["name"], duration_days=days, travel_style=travel_style,
                        month=month, party_size=party_size)
    per_day_cost = round(cost["predicted_cost"] / days)

    activities = d["activities"][:]
    attractions = d["attractions"][:]
    rng.shuffle(attractions)
    att_pool = attractions[:]

    def take_attraction():
        nonlocal att_pool
        if not att_pool:
            att_pool = attractions[:]  # recycle
        return att_pool.pop() if att_pool else None

    # activities that only make sense at certain times of day
    EVENING_ONLY = {"nightlife"}
    MORNING_PREF = {"wildlife", "trekking", "temples"}

    def pick_for(slot, exclude=()):
        pool = [a for a in activities if a not in exclude]
        if slot == "morning":
            pool = [a for a in pool if a not in EVENING_ONLY] or pool
            pref = [a for a in pool if a in MORNING_PREF]
            return rng.choice(pref or pool)
        if slot == "evening":
            pref = [a for a in pool if a in EVENING_ONLY]
            return rng.choice(pref or pool)
        # midday/afternoon: anything except evening-only
        day_pool = [a for a in pool if a not in EVENING_ONLY] or pool
        return rng.choice(day_pool)

    plan_days = []
    for day in range(1, days + 1):
        a1 = pick_for("morning")
        a2 = pick_for("midday", exclude=(a1,))
        a3 = pick_for("afternoon", exclude=(a1, a2))

        morning_att = take_attraction()
        afternoon_att = take_attraction()

        if day == 1:
            morning = "Arrive and settle into your stay"
        elif day == days and days > 1:
            morning = "Leisurely morning, last-minute exploring"
        else:
            morning = _slot_text(rng, a1, [morning_att] if morning_att else attractions)

        timeline = [
            {"time": "Morning",   "icon": "sun",     "text": morning,
             "meal": rng.choice(MEALS["breakfast"])},
            {"time": "Midday",    "icon": "map-pin", "text": _slot_text(rng, a2, [afternoon_att] if afternoon_att else attractions),
             "meal": rng.choice(MEALS["lunch"])},
            {"time": "Afternoon", "icon": "compass", "text": _slot_text(rng, a3, attractions)},
            {"time": "Evening",   "icon": "star",    "text": (
                "Depart" if (day == days and days > 1) else _slot_text(rng, pick_for("evening"), attractions)),
             "meal": (None if (day == days and days > 1) else rng.choice(MEALS["dinner"]))},
        ]
        plan_days.append({
            "day": day,
            "title": f"Day {day}" + (" — Arrival" if day == 1 else (" — Departure" if day == days and days > 1 else "")),
            "timeline": timeline,
            "stay": STAY_TIER.get(travel_style, STAY_TIER["mid-range"]),
            "est_cost": per_day_cost,
        })

    mn = ['', 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
    best_season = ", ".join(mn[m] for m in sorted(d.get("season_peak", []))[:4])

    return {
        "destination": d["name"],
        "city": d["city"],
        "country": d["country"],
        "region": d["region"],
        "days": days,
        "travel_style": travel_style,
        "season": month_to_season(month) if month else None,
        "best_season": best_season,
        "party_size": party_size,
        "total_cost": cost["predicted_cost"],
        "cost_low": cost["low"],
        "cost_high": cost["high"],
        "cost_breakdown": cost["cost_breakdown"],
        "per_day_cost": per_day_cost,
        "highlights": d["attractions"],
        "activities": d["activities"][:6],
        "plan": plan_days,
    }


if __name__ == "__main__":
    import json
    it = generate("Goa, India", days=4, travel_style="mid-range", month=12)
    print(f"{it['destination']} — {it['days']} days — Rs{it['total_cost']:,} (Rs{it['per_day_cost']}/day)")
    for d in it["plan"]:
        print(f"\n{d['title']}  (~Rs{d['est_cost']})  stay: {d['stay']}")
        for t in d["timeline"]:
            meal = f"  |  {t['meal']}" if t.get("meal") else ""
            print(f"  {t['time']:10s} {t['text']}{meal}")
