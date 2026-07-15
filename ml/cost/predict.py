"""
JourneyAI — cost prediction serving wrapper (P2).

Loads ml/artifacts/cost_model.pkl once and exposes predict_cost(), returning a
point estimate, a low/high band, and a per-category breakdown for the UI donut.

The model predicts DAILY cost (see train_cost_model's `predicts` flag); the
served total is (daily prediction) x duration_days.
"""
import os
import pickle
import threading

import pandas as pd

from ml.cost.train_cost_model import month_to_season

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))
_MODEL_PATH = os.path.join(ARTIFACT_DIR, "cost_model.pkl")

_lock = threading.Lock()
_BUNDLE = None

# Region lookup so callers only need to pass a destination name.
_DEST_REGION = None


def _load():
    global _BUNDLE, _DEST_REGION
    if _BUNDLE is None:
        with _lock:
            if _BUNDLE is None:
                with open(_MODEL_PATH, "rb") as f:
                    _BUNDLE = pickle.load(f)
                # region map (lazy import to avoid cycles)
                from ml.db import fetch_all
                _DEST_REGION = {r["canonical_name"]: r["region"]
                                for r in fetch_all("SELECT canonical_name, region FROM destinations")}
    return _BUNDLE


def _row(dest, duration_days, style, season, party_size, region):
    return pd.DataFrame([{
        "dest": dest, "region": region or "unknown", "style": style or "mid-range",
        "season": season, "duration_days": int(duration_days), "party_size": int(party_size),
    }])


def predict_cost(destination, duration_days=5, travel_style="mid-range",
                 season=None, month=None, party_size=1):
    """
    Return a dict:
        { predicted_cost, low, high, per_day, cost_breakdown{...}, currency }
    `season` may be given directly, or derive from `month` (1-12), else 'winter'.
    """
    b = _load()
    if season is None:
        season = month_to_season(month) if month is not None else "winter"
    region = (_DEST_REGION or {}).get(destination)
    duration_days = max(1, int(duration_days))
    party_size = max(1, int(party_size))

    X = _row(destination, duration_days, travel_style, season, party_size, region)

    daily = float(b["model"].predict(X)[0])
    daily_lo = float(b["lo"].predict(X)[0])
    daily_hi = float(b["hi"].predict(X)[0])

    total = daily * duration_days
    # party scaling: accommodation shares less-than-linearly; simple 0.8 exponent
    if party_size > 1:
        scale = party_size ** 0.85
        total *= scale
        daily_lo *= scale
        daily_hi *= scale

    low = max(0.0, daily_lo * duration_days)
    high = max(total, daily_hi * duration_days)

    ratios = b["ratios"].get(destination, b["global_ratio"])
    breakdown = {k: round(total * v) for k, v in ratios.items()}
    # fix rounding drift so parts sum to total
    drift = round(total) - sum(breakdown.values())
    if breakdown:
        big = max(breakdown, key=breakdown.get)
        breakdown[big] += drift

    return {
        "destination": destination,
        "predicted_cost": round(total),
        "low": round(low),
        "high": round(high),
        "per_day": round(total / duration_days),
        "duration_days": duration_days,
        "cost_breakdown": breakdown,
        "currency": "INR",
    }


def model_metrics():
    return _load().get("metrics", {})


if __name__ == "__main__":
    for style in ("backpacker", "mid-range", "luxury"):
        r = predict_cost("Goa, India", duration_days=5, travel_style=style, month=12)
        print(f"Goa 5d {style:10s}: Rs{r['predicted_cost']:>7,} "
              f"(band {r['low']:,}-{r['high']:,})  breakdown={r['cost_breakdown']}")
    print("\nmetrics:", model_metrics())
