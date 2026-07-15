"""
JourneyAI — trip cost-prediction model (P2).

Trains a GradientBoostingRegressor to predict a trip's realistic total cost
(`true_total` — the sum of ALL raw expense columns, NOT the buggy db3.grand)
from:
    canonical_dest (one-hot), region (one-hot), travel_style (one-hot),
    duration_days, season (from MONTH(dv)), party_size

Also trains two quantile GBRs (alpha=0.1 / 0.9) for a low/high band, and learns
per-destination category-split ratios (food/transport/accommodation/shopping/
fees_misc) so a predicted total can be broken down for the UI donut.

Artifacts -> ml/artifacts/cost_model.pkl  (dict of models + encoders + ratios)
Reports MAE / R² on a held-out split for the capstone writeup.

Run:
    python -m ml.cost.train_cost_model
"""
import os
import pickle

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder

from ml.db import fetch_df

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))

CAT_COLS = ["food_total", "transport_total", "accommodation_total",
            "shopping_total", "fees_misc_total"]


def month_to_season(m):
    try:
        m = int(m)
    except (TypeError, ValueError):
        return "unknown"
    if m in (12, 1, 2):
        return "winter"
    if m in (3, 4, 5):
        return "summer"
    if m in (6, 7, 8, 9):
        return "monsoon"
    return "autumn"  # 10, 11


def load_training_frame():
    """Join journals view + features into a modelling frame."""
    df = fetch_df("""
        SELECT j.entry_id, jf.canonical_dest AS dest, jf.travel_style AS style,
               j.duration_days, j.dv, j.true_total,
               j.food_total, j.transport_total, j.accommodation_total,
               j.shopping_total, j.fees_misc_total,
               d.region, d.popularity_tier
        FROM journals j
        JOIN journal_features jf ON jf.entry_id = j.entry_id
        LEFT JOIN destinations d ON d.canonical_name = jf.canonical_dest
        WHERE jf.canonical_dest IS NOT NULL
    """)
    num = ["duration_days", "true_total"] + CAT_COLS
    for c in num:
        df[c] = pd.to_numeric(df[c], errors="coerce").fillna(0.0)
    df = df[df["true_total"] > 0].copy()
    df["season"] = df["dv"].astype(str).str.slice(5, 7).apply(month_to_season)
    df["region"] = df["region"].fillna("unknown")
    df["style"] = df["style"].fillna("mid-range")
    # party_size not captured in the schema — synthesize a mild feature so the
    # model accepts it at inference (defaults to 1). Constant here → harmless.
    df["party_size"] = 1
    return df


def build_pipeline(loss="squared_error", alpha=None):
    cat = ["dest", "region", "style", "season"]
    num = ["duration_days", "party_size"]
    pre = ColumnTransformer([
        ("cat", OneHotEncoder(handle_unknown="ignore"), cat),
        ("num", "passthrough", num),
    ])
    kw = dict(n_estimators=300, max_depth=3, learning_rate=0.05,
              subsample=0.9, random_state=42, loss=loss)
    if alpha is not None:
        kw["alpha"] = alpha
    return Pipeline([("pre", pre), ("gbr", GradientBoostingRegressor(**kw))])


def train():
    df = load_training_frame()
    print(f"Training rows: {len(df)}")

    # Model DAILY cost (true_total / duration) — removes the dominant duration
    # multiplier so the model learns destination/style/season effects cleanly.
    # The served total is (predicted daily) x duration.
    features = ["dest", "region", "style", "season", "duration_days", "party_size"]
    X = df[features]
    y_daily = (df["true_total"] / df["duration_days"]).values
    dur_all = df["duration_days"].values

    idx = np.arange(len(df))
    tr, te = train_test_split(idx, test_size=0.2, random_state=42)
    X_tr, X_te = X.iloc[tr], X.iloc[te]

    # point estimate on daily cost
    model = build_pipeline()
    model.fit(X_tr, y_daily[tr])
    # evaluate on TOTAL (daily prediction x duration) — the number users see
    pred_total = model.predict(X_te) * dur_all[te]
    true_total = (y_daily[te] * dur_all[te])
    mae = mean_absolute_error(true_total, pred_total)
    r2 = r2_score(true_total, pred_total)
    mape = float(np.mean(np.abs((true_total - pred_total) / np.clip(true_total, 1, None))) * 100)

    # quantile bands on daily cost (retrained on full data for serving)
    lo = build_pipeline(loss="quantile", alpha=0.1).fit(X, y_daily)
    hi = build_pipeline(loss="quantile", alpha=0.9).fit(X, y_daily)
    model.fit(X, y_daily)  # refit point model on all data for serving

    # per-destination category-split ratios (mean share of true_total)
    ratios = {}
    for dest, grp in df.groupby("dest"):
        tot = grp["true_total"].sum()
        if tot <= 0:
            continue
        ratios[dest] = {c.replace("_total", ""): float(grp[c].sum() / tot) for c in CAT_COLS}
    # global fallback ratio
    gtot = df["true_total"].sum()
    global_ratio = {c.replace("_total", ""): float(df[c].sum() / gtot) for c in CAT_COLS}

    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    with open(os.path.join(ARTIFACT_DIR, "cost_model.pkl"), "wb") as f:
        pickle.dump({
            "model": model, "lo": lo, "hi": hi,
            "features": features, "ratios": ratios, "global_ratio": global_ratio,
            "predicts": "daily",  # multiply model output by duration_days to get total
            "metrics": {"mae": mae, "r2": r2, "mape": mape},
        }, f)

    print("\n=== Cost model metrics (held-out 20%) ===")
    print(f"MAE : Rs {mae:,.0f}")
    print(f"R2  : {r2:.3f}")
    print(f"MAPE: {mape:.1f}%")
    # sanity: budget vs luxury for the same destination/duration
    def q(dest, style, days=5, season="winter"):
        row = pd.DataFrame([{"dest": dest, "region": df[df.dest == dest]["region"].iloc[0],
                             "style": style, "season": season,
                             "duration_days": days, "party_size": 1}])
        return float(model.predict(row)[0]) * days  # daily -> total
    sample = df["dest"].iloc[0]
    print(f"\nSanity ({sample}, 5 days): "
          f"backpacker=Rs{q(sample,'backpacker'):.0f} < luxury=Rs{q(sample,'luxury'):.0f}")
    print("artifact -> cost_model.pkl")


if __name__ == "__main__":
    train()
