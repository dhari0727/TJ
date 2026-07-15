"""
JourneyAI — Flask ML microservice (P2).

Serves the trained recommender + cost model to the PHP app over local JSON.
Binds to 127.0.0.1:5000 ONLY (never network-exposed). CORS limited to localhost.

Endpoints:
    GET  /health              -> {status, models_loaded, ...}
    POST /recommend           -> ranked, explained destination recommendations
    POST /predict-cost        -> cost estimate + band + breakdown for one destination
    GET  /analytics/summary   -> aggregates for the analytics dashboard

Run:
    python -m ml.app
    (or: ml/venv/Scripts/python.exe -m ml.app)

All models are loaded ONCE at boot (pickles + a live CF matrix from the DB).
Input is validated and clamped; DB reads are parameterized.
"""
from flask import Flask, request, jsonify
from flask_cors import CORS

from ml.recommender.hybrid import get_recommender
from ml.recommender.explain import explain
from ml.cost.predict import predict_cost, model_metrics
from ml.db import fetch_all
from ml.nlp.lexicon import CANONICAL_ACTIVITIES

app = Flask(__name__)
CORS(app, resources={r"/*": {"origins": ["http://localhost", "http://127.0.0.1"]}})

VALID_STYLES = {"budget", "mid-range", "luxury", "adventure", "family", "solo", "backpacker"}
_RECO = None


def _reco():
    global _RECO
    if _RECO is None:
        _RECO = get_recommender()
    return _RECO


def _clamp_int(v, lo, hi, default):
    try:
        return max(lo, min(hi, int(v)))
    except (TypeError, ValueError):
        return default


# --------------------------------------------------------------------- health
@app.get("/health")
def health():
    try:
        r = _reco()
        return jsonify({
            "status": "ok",
            "models_loaded": True,
            "destinations": len(r.dest_names),
            "cost_metrics": model_metrics(),
        })
    except Exception as e:  # pragma: no cover
        return jsonify({"status": "error", "models_loaded": False, "error": str(e)}), 500


# ------------------------------------------------------------------ recommend
@app.post("/recommend")
def recommend():
    data = request.get_json(silent=True) or {}

    # validate + clamp inputs
    budget = data.get("budget")
    try:
        budget = float(budget) if budget not in (None, "", 0, "0") else None
        if budget is not None:
            budget = max(1000.0, min(2_000_000.0, budget))
    except (TypeError, ValueError):
        budget = None

    duration = _clamp_int(data.get("duration_days"), 1, 60, 5)
    party = _clamp_int(data.get("party_size"), 1, 20, 1)
    top_n = _clamp_int(data.get("top_n"), 1, 20, 6)
    month = data.get("month")
    month = _clamp_int(month, 1, 12, None) if month not in (None, "") else None

    style = str(data.get("travel_style", "mid-range")).lower()
    if style not in VALID_STYLES:
        style = "mid-range"

    # interests intersected against the allowed vocabulary
    raw_interests = data.get("interests") or []
    if isinstance(raw_interests, str):
        raw_interests = [s.strip() for s in raw_interests.split(",")]
    interests = [i.lower() for i in raw_interests if i.lower() in CANONICAL_ACTIVITIES]

    eml = data.get("eml")
    if eml is not None:
        eml = str(eml)[:190]

    from ml.recommender.proximity import ORIGIN_REGIONS
    origin_region = data.get("origin_region")
    if origin_region not in ORIGIN_REGIONS:
        origin_region = None

    recs = _reco().recommend(
        eml=eml, budget=budget, duration_days=duration, interests=interests,
        travel_style=style, month=month, party_size=party, top_n=top_n,
        origin_region=origin_region)
    recs = [explain(r, budget=budget, interests=interests) for r in recs]

    return jsonify({
        "status": "ok",
        "query": {"budget": budget, "duration_days": duration, "interests": interests,
                  "travel_style": style, "party_size": party, "month": month},
        "count": len(recs),
        "recommendations": recs,
    })


# --------------------------------------------------------------- predict-cost
@app.post("/predict-cost")
def predict_cost_endpoint():
    data = request.get_json(silent=True) or {}
    dest = data.get("destination")
    if not dest:
        return jsonify({"status": "error", "error": "destination required"}), 400
    duration = _clamp_int(data.get("duration_days"), 1, 60, 5)
    party = _clamp_int(data.get("party_size"), 1, 20, 1)
    month = data.get("month")
    month = _clamp_int(month, 1, 12, None) if month not in (None, "") else None
    style = str(data.get("travel_style", "mid-range")).lower()
    if style not in VALID_STYLES:
        style = "mid-range"
    result = predict_cost(str(dest), duration_days=duration, travel_style=style,
                          month=month, party_size=party)
    result["status"] = "ok"
    return jsonify(result)


# --------------------------------------------------------------- nearby (OSM)
@app.post("/nearby")
def nearby():
    data = request.get_json(silent=True) or {}
    place = str(data.get("place", "")).strip()
    if not place:
        return jsonify({"status": "error", "error": "place required"}), 400
    from ml.geo.places import nearby_places, TRIP_MODES
    mode = data.get("mode", "day")
    if mode not in TRIP_MODES:
        mode = "day"
    raw_interests = data.get("interests") or []
    if isinstance(raw_interests, str):
        raw_interests = [s.strip() for s in raw_interests.split(",")]
    interests = [i.lower() for i in raw_interests if i.lower() in CANONICAL_ACTIVITIES]
    limit = _clamp_int(data.get("limit"), 1, 30, 12)
    result = nearby_places(place, mode=mode, interests=interests, limit=limit)
    if "error" in result:
        return jsonify({"status": "error", "error": result["error"]}), 404
    result["status"] = "ok"
    return jsonify(result)


# --------------------------------------------------------------- route builder
@app.post("/route")
def route():
    data = request.get_json(silent=True) or {}
    place = str(data.get("place", "")).strip()
    if not place:
        return jsonify({"status": "error", "error": "place required"}), 400
    from ml.geo.places import build_route, TRIP_MODES
    mode = data.get("mode", "weekend")
    if mode not in TRIP_MODES:
        mode = "weekend"
    raw_interests = data.get("interests") or []
    if isinstance(raw_interests, str):
        raw_interests = [s.strip() for s in raw_interests.split(",")]
    interests = [i.lower() for i in raw_interests if i.lower() in CANONICAL_ACTIVITIES]
    stops = _clamp_int(data.get("stops"), 2, 8, 4)
    result = build_route(place, mode=mode, interests=interests, stops=stops)
    if "error" in result:
        return jsonify({"status": "error", "error": result["error"]}), 404
    result["status"] = "ok"
    return jsonify(result)


# --------------------------------------------------------------- itinerary
@app.post("/itinerary")
def itinerary():
    data = request.get_json(silent=True) or {}
    dest = data.get("destination")
    if not dest:
        return jsonify({"status": "error", "error": "destination required"}), 400
    days = _clamp_int(data.get("days"), 1, 21, 5)
    party = _clamp_int(data.get("party_size"), 1, 20, 1)
    month = data.get("month")
    month = _clamp_int(month, 1, 12, None) if month not in (None, "") else None
    style = str(data.get("travel_style", "mid-range")).lower()
    if style not in VALID_STYLES:
        style = "mid-range"
    from ml.itinerary.generate import generate
    result = generate(str(dest), days=days, travel_style=style, month=month, party_size=party)
    if "error" in result:
        return jsonify({"status": "error", "error": result["error"]}), 404
    result["status"] = "ok"
    return jsonify(result)


# ----------------------------------------------------------- analytics summary
@app.get("/analytics/summary")
def analytics_summary():
    cost_by_dest = fetch_all("""
        SELECT jf.canonical_dest AS destination, COUNT(*) AS n,
               ROUND(AVG(j.true_total)) AS avg_cost,
               ROUND(AVG(j.duration_days),1) AS avg_days,
               ROUND(AVG(jf.sentiment_score),3) AS avg_sentiment,
               d.popularity_tier AS tier
        FROM journal_features jf
        JOIN journals j ON j.entry_id = jf.entry_id
        LEFT JOIN destinations d ON d.canonical_name = jf.canonical_dest
        WHERE jf.canonical_dest IS NOT NULL
        GROUP BY jf.canonical_dest, d.popularity_tier
        ORDER BY n DESC
    """)
    sentiment_dist = fetch_all(
        "SELECT sentiment_label AS label, COUNT(*) AS n FROM journal_features "
        "GROUP BY sentiment_label")
    style_dist = fetch_all(
        "SELECT travel_style AS style, COUNT(*) AS n FROM journal_features "
        "GROUP BY travel_style ORDER BY n DESC")
    popular = fetch_all("""
        SELECT destination, COUNT(*) AS ratings, ROUND(AVG(rating),2) AS avg_rating
        FROM interactions WHERE rating IS NOT NULL
        GROUP BY destination ORDER BY avg_rating DESC, ratings DESC LIMIT 10
    """)
    totals = fetch_all("""
        SELECT COUNT(*) AS journals, COUNT(DISTINCT canonical_dest) AS destinations,
               COUNT(DISTINCT eml) AS travelers
        FROM journal_features
    """)
    # PRACTICAL: best value = high sentiment per rupee (experience per rupee spent)
    best_value = fetch_all("""
        SELECT jf.canonical_dest AS destination, COUNT(*) AS n,
               ROUND(AVG(j.true_total)) AS avg_cost,
               ROUND(AVG(jf.sentiment_score),3) AS sentiment,
               ROUND(AVG(jf.sentiment_score) / (AVG(j.true_total)/10000), 3) AS value_score
        FROM journal_features jf JOIN journals j ON j.entry_id=jf.entry_id
        WHERE jf.canonical_dest IS NOT NULL AND j.true_total > 0
        GROUP BY jf.canonical_dest HAVING n >= 3
        ORDER BY value_score DESC LIMIT 8
    """)
    # PRACTICAL: cheapest months to travel (avg daily spend by visit month)
    cheapest_months = fetch_all("""
        SELECT MONTH(j.dv) AS month, COUNT(*) AS n,
               ROUND(AVG(j.true_total / GREATEST(j.duration_days,1))) AS avg_daily
        FROM journals j WHERE j.dv IS NOT NULL AND j.dv <> '' AND j.true_total > 0
        GROUP BY MONTH(j.dv) HAVING month BETWEEN 1 AND 12 ORDER BY month
    """)
    # PRACTICAL: budget vs luxury daily cost range per popular destination
    cost_ranges = fetch_all("""
        SELECT jf.canonical_dest AS destination,
               ROUND(MIN(j.true_total/GREATEST(j.duration_days,1))) AS min_daily,
               ROUND(MAX(j.true_total/GREATEST(j.duration_days,1))) AS max_daily,
               COUNT(*) AS n
        FROM journal_features jf JOIN journals j ON j.entry_id=jf.entry_id
        WHERE jf.canonical_dest IS NOT NULL AND j.true_total > 0
        GROUP BY jf.canonical_dest HAVING n >= 5 ORDER BY n DESC LIMIT 10
    """)
    return jsonify({
        "status": "ok",
        "totals": totals[0] if totals else {},
        "cost_by_destination": cost_by_dest,
        "sentiment_distribution": sentiment_dist,
        "style_distribution": style_dist,
        "popular_destinations": popular,
        "best_value": best_value,
        "cheapest_months": cheapest_months,
        "cost_ranges": cost_ranges,
        "cost_metrics": model_metrics(),
    })


# ---------------------------------------------------- personal analytics
@app.get("/analytics/personal")
def analytics_personal():
    eml = request.args.get("eml", "")
    if not eml:
        return jsonify({"status": "error", "error": "eml required"}), 400
    stats = fetch_all("""
        SELECT COUNT(*) AS trips, COALESCE(ROUND(SUM(true_total)),0) AS total_spent,
               COALESCE(ROUND(AVG(true_total)),0) AS avg_trip,
               COALESCE(ROUND(AVG(duration_days),1),0) AS avg_days
        FROM journals WHERE eml=%s
    """, (eml,))
    by_cat = fetch_all("""
        SELECT COALESCE(ROUND(SUM(food_total)),0) food, COALESCE(ROUND(SUM(transport_total)),0) transport,
               COALESCE(ROUND(SUM(accommodation_total)),0) accommodation,
               COALESCE(ROUND(SUM(shopping_total)),0) shopping, COALESCE(ROUND(SUM(fees_misc_total)),0) fees_misc
        FROM journals WHERE eml=%s
    """, (eml,))
    trips = fetch_all("""
        SELECT Title, City, true_total, duration_days FROM journals
        WHERE eml=%s ORDER BY true_total DESC
    """, (eml,))
    community_avg = fetch_all("SELECT ROUND(AVG(true_total)) a FROM journals WHERE true_total>0")
    return jsonify({
        "status": "ok",
        "stats": stats[0] if stats else {},
        "by_category": by_cat[0] if by_cat else {},
        "trips": trips,
        "community_avg_trip": (community_avg[0]["a"] if community_avg else 0),
    })


if __name__ == "__main__":
    print("Loading JourneyAI models...")
    _reco()  # warm the models at boot
    print("Models loaded. Serving on http://127.0.0.1:5000")
    app.run(host="127.0.0.1", port=5000, debug=False)
