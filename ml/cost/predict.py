"""
JourneyAI - cost prediction serving wrapper.

Loads ml/artifacts/cost_model.pkl (the bottom-up real-price model trained by ml/cost/train_cost_model.py)
and exposes the same public functions the rest of the app already calls:

    predict_cost(destination, duration_days, travel_style, season, month, party_size) -> dict
    predict_cost_batch(destinations, ...)                                               -> {dest: dict}
    model_metrics()                                                                      -> validation numbers
    style_table(destination, party_size)                                                 -> per-style daily cost

Response fields (superset of the old shape): predicted_cost, low, high, per_day, per_person_per_day,
duration_days, party_size, travel_style, cost_breakdown{food,transport,accommodation,shopping,fees_misc},
currency, confidence ('high' | 'medium' | 'low' | 'regional estimate'), n_observations, n_stay_observations,
estimate_basis, season_adjustment, excludes.
"""
import os
import pickle
import threading

from ml.cost import engine
from ml.cost.engine import month_to_season  # noqa: F401  (re-exported: ml.itinerary.generate imports it from here)

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))
_MODEL_PATH = os.path.join(ARTIFACT_DIR, "cost_model.pkl")

_lock = threading.Lock()
_BUNDLE = None
_DAY_CACHE = {}


def _load():
    global _BUNDLE
    if _BUNDLE is None:
        with _lock:
            if _BUNDLE is None:
                with open(_MODEL_PATH, "rb") as f:
                    _BUNDLE = pickle.load(f)
    return _BUNDLE


def _resolve(destination):
    """Map any spelling the app passes ('Goa', 'Goa, India') to a modelled destination name."""
    b = _load()
    name = str(destination or "").strip()
    if name in b["dest"]:
        return name
    alt = name.split(",")[0].strip() + ", India"
    return alt if alt in b["dest"] else name


def _predict_one(b, name, days, style, party):
    key = (name, style, party)
    day = _DAY_CACHE.get(key)
    if day is None:
        rec = engine.dest_params(b, name)
        day = (rec, engine.price_day(b, rec, style, party))
        if len(_DAY_CACHE) > 20000:
            _DAY_CACHE.clear()
        _DAY_CACHE[key] = day
    return engine.predict_from_day(b, name, day[0], day[1], days, style, party)


def predict_cost_batch(destinations, duration_days=5, travel_style="mid-range",
                       season=None, month=None, party_size=1):
    """Cost for MANY destinations at once. Returns {destination: cost_dict}."""
    b = _load()
    days, party, style = engine.clean_inputs(duration_days, party_size, travel_style)
    out = {}
    for d in destinations:
        out[d] = _predict_one(b, _resolve(d), days, style, party) | {"destination": d}
    return out


def predict_cost(destination, duration_days=5, travel_style="mid-range",
                 season=None, month=None, party_size=1):
    """`season` / `month` are accepted for compatibility but do not move the estimate: the real price data has
    no seasonal signal (the response's season_adjustment says so)."""
    b = _load()
    days, party, style = engine.clean_inputs(duration_days, party_size, travel_style)
    r = _predict_one(b, _resolve(destination), days, style, party)
    r["destination"] = destination
    return r


def style_table(destination, party_size=1):
    b = _load()
    return engine.style_table(b, _resolve(destination), party_size)


def model_metrics():
    m = dict(_load().get("metrics", {}))
    t = m.get("trip_validation", {}) or {}
    # compact headline for /health and the analytics page
    loo = t.get("model_calibrated_leave_one_out", {})
    m["headline"] = {
        "trip_reports_n": t.get("n"),
        "trip_mape_pct": loo.get("MAPE_pct"),
        "trip_mape_ci95": loo.get("MAPE_ci95"),
        "price_reports": (m.get("trained_on") or {}).get("observations"),
        "destinations_with_data": (m.get("trained_on") or {}).get("destinations_with_data"),
    }
    m["trip_validation"] = {k: v for k, v in t.items() if k != "trips"}     # keep /health small
    return m


if __name__ == "__main__":
    for style in ("backpacker", "budget", "mid-range", "luxury"):
        r = predict_cost("Goa, India", duration_days=5, travel_style=style)
        print(f"Goa 5d {style:10s}: Rs{r['predicted_cost']:>7,} "
              f"(band {r['low']:,}-{r['high']:,})  {r['confidence']}  breakdown={r['cost_breakdown']}")
    print("\nmetrics:", model_metrics()["headline"])
