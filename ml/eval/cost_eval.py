"""
JourneyAI — evaluation of the trip-cost model (member D24DCS163).

Questions answered, in plain words:
  1. How far off is the predicted trip cost on journals the model has NOT seen?   (5-fold cross-validation)
  2. Is it better than simple guesses?                                           (3 baselines)
  3. Does it work for a destination it has NEVER seen? (needed to scale to all India) (leave-destination-out)
  4. Is the "low-high" band honest?  (an 80% band should contain ~80% of true costs)  (interval coverage)
  5. Does it agree with what real travellers wrote in blogs?                      (external validation)
  6. Which inputs matter most?                                                    (permutation importance)

    ml/venv/Scripts/python.exe -m ml.eval.cost_eval
"""
import json
import os
import sys

import numpy as np
import pandas as pd
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import GroupKFold, KFold

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.cost.train_cost_model import build_pipeline, month_to_season  # noqa: E402
from ml.db import fetch_df  # noqa: E402

DATA = os.path.join(ROOT, "ml", "data")
FEATURES = ["dest", "region", "style", "season", "duration_days", "party_size", "base_daily"]


def load_frame():
    df = fetch_df("""
        SELECT j.entry_id, j.eml, jf.canonical_dest AS dest, jf.travel_style AS style, j.duration_days, j.dv,
               j.true_total, d.region, d.base_daily_cost AS base_daily
        FROM journals j
        JOIN journal_features jf ON jf.entry_id = j.entry_id
        LEFT JOIN destinations d ON d.canonical_name = jf.canonical_dest
        WHERE jf.canonical_dest IS NOT NULL""")
    for c in ("duration_days", "true_total", "base_daily"):
        df[c] = pd.to_numeric(df[c], errors="coerce").fillna(0.0)
    df = df[(df["true_total"] > 0) & (df["duration_days"] > 0)].copy()
    df["season"] = df["dv"].astype(str).str.slice(5, 7).apply(month_to_season)
    df["region"] = df["region"].fillna("unknown")
    df["style"] = df["style"].fillna("mid-range")
    df["party_size"] = 1
    df["daily"] = df["true_total"] / df["duration_days"]
    df["source"] = np.where(df["eml"].str.endswith("@seed.journeyai"), "synthetic journals",
                            np.where(df["eml"].str.endswith("@real.journeyai"), "real-sourced (Wikivoyage prices)", "real users"))
    return df.reset_index(drop=True)


def metrics(true_total, pred_total):
    true_total, pred_total = np.asarray(true_total, float), np.asarray(pred_total, float)
    return {"MAE (Rs)": float(mean_absolute_error(true_total, pred_total)),
            "RMSE (Rs)": float(np.sqrt(mean_squared_error(true_total, pred_total))),
            "MAPE (%)": float(np.mean(np.abs(true_total - pred_total) / np.clip(true_total, 1, None)) * 100),
            "R2": float(r2_score(true_total, pred_total))}


def _baselines(train, test):
    g = train["daily"].mean()
    dest = train.groupby("dest")["daily"].mean()
    dest_style = train.groupby(["dest", "style"])["daily"].mean()
    region = train.groupby("region")["daily"].mean()
    out = {"global average": np.full(len(test), g)}
    out["destination average"] = test["dest"].map(dest).fillna(test["region"].map(region)).fillna(g).values
    ds = [dest_style.get((d, s), dest.get(d, np.nan)) for d, s in zip(test["dest"], test["style"])]
    out["destination + style average"] = pd.Series(ds, index=test.index).fillna(test["region"].map(region)).fillna(g).values
    out["region average"] = test["region"].map(region).fillna(g).values
    out["catalog price prior only"] = test["base_daily"].replace(0, np.nan).fillna(g).values
    return out


def cross_validate(df, k=5, seed=42, group=False):
    """5-fold CV. group=True keeps every destination entirely inside ONE fold, so the model is tested on
    destinations it has never seen (the situation for a brand-new place)."""
    splitter = GroupKFold(n_splits=k) if group else KFold(n_splits=k, shuffle=True, random_state=seed)
    split_iter = splitter.split(df, groups=df["dest"]) if group else splitter.split(df)
    rows, oof = [], pd.Series(np.nan, index=df.index)
    for fold, (tr, te) in enumerate(split_iter, 1):
        train, test = df.iloc[tr], df.iloc[te]
        model = build_pipeline().fit(train[FEATURES], train["daily"])
        pred = model.predict(test[FEATURES])
        oof.iloc[te] = pred
        dur = test["duration_days"].values
        res = {"GradientBoosting (ours)": pred * dur}
        for name, p in _baselines(train, test).items():
            res[name] = p * dur
        for name, p in res.items():
            m = metrics(test["true_total"], p); m.update({"model": name, "fold": fold}); rows.append(m)
    tab = pd.DataFrame(rows).groupby("model")[["MAE (Rs)", "RMSE (Rs)", "MAPE (%)", "R2"]].agg(["mean", "std"])
    tab.columns = [f"{a} {b}" for a, b in tab.columns]
    order = ["GradientBoosting (ours)", "destination + style average", "destination average", "region average",
             "catalog price prior only", "global average"]
    return tab.loc[[o for o in order if o in tab.index]], oof


def by_source(df, oof):
    rows = []
    for src, g in df.groupby("source"):
        m = metrics(g["true_total"], oof.loc[g.index] * g["duration_days"]); m["rows"] = len(g); m["source"] = src
        rows.append(m)
    return pd.DataFrame(rows).set_index("source")


def interval_coverage(df, seed=42, lo_q=0.1, hi_q=0.9):
    rng = np.random.RandomState(seed)
    idx = rng.permutation(len(df)); cut = int(len(df) * 0.8)
    train, test = df.iloc[idx[:cut]], df.iloc[idx[cut:]]
    lo = build_pipeline(loss="quantile", alpha=lo_q).fit(train[FEATURES], train["daily"])
    hi = build_pipeline(loss="quantile", alpha=hi_q).fit(train[FEATURES], train["daily"])
    plo, phi = lo.predict(test[FEATURES]), hi.predict(test[FEATURES])
    inside = (test["daily"].values >= plo) & (test["daily"].values <= phi)
    width = float(np.mean((phi - plo) * test["duration_days"].values))
    return {"target coverage (%)": (hi_q - lo_q) * 100, "actual coverage (%)": float(inside.mean() * 100),
            "average band width (Rs, whole trip)": width, "test rows": int(len(test))}


def external_validation(blog_path=None):
    """Compare model predictions with daily costs REAL travellers wrote in blog trip reports."""
    from ml.cost.predict import predict_cost_batch
    blog_path = blog_path or os.path.join(DATA, "blog_cost_obs.json")
    if not os.path.exists(blog_path):
        return None
    obs = pd.DataFrame(json.load(open(blog_path, encoding="utf8")))
    if obs.empty:
        return None
    per = obs.groupby("destination")["daily_inr"].agg(["median", "count"]).rename(columns={"median": "blog_daily", "count": "n_mentions"})
    dests = list(per.index)
    pred = predict_cost_batch(dests, duration_days=4, travel_style="mid-range", month=None)
    per["model_daily"] = [pred[d]["per_day"] for d in dests]
    per = per.dropna()
    err = per["model_daily"] - per["blog_daily"]
    from scipy.stats import spearmanr
    rho = spearmanr(per["blog_daily"], per["model_daily"])[0] if len(per) > 2 else float("nan")
    summary = {"destinations compared": int(len(per)), "blog mentions used": int(per["n_mentions"].sum()),
               "MAE (Rs per day)": float(err.abs().mean()),
               "median abs. error (Rs per day)": float(err.abs().median()),
               "MAPE (%)": float((err.abs() / per["blog_daily"]).mean() * 100),
               "bias (Rs per day, model - blog)": float(err.mean()),
               "rank correlation (Spearman)": float(rho)}
    return summary, per.sort_values("n_mentions", ascending=False)


def permutation_importance(df, seed=42, repeats=5):
    rng = np.random.RandomState(seed)
    idx = rng.permutation(len(df)); cut = int(len(df) * 0.8)
    train, test = df.iloc[idx[:cut]], df.iloc[idx[cut:]]
    model = build_pipeline().fit(train[FEATURES], train["daily"])
    base = mean_absolute_error(test["daily"], model.predict(test[FEATURES]))
    out = {}
    for f in FEATURES:
        inc = []
        for _ in range(repeats):
            t = test.copy(); t[f] = rng.permutation(t[f].values)
            inc.append(mean_absolute_error(t["daily"], model.predict(t[FEATURES])) - base)
        out[f] = float(np.mean(inc))
    return pd.Series(out).sort_values(ascending=False)


def run_all():
    df = load_frame()
    cv, oof = cross_validate(df)
    gcv, _ = cross_validate(df, group=True)
    report = {
        "rows": int(len(df)), "destinations": int(df["dest"].nunique()),
        "rows_by_source": df["source"].value_counts().to_dict(),
        "cv_seen_destinations": cv.round(3).reset_index().to_dict("records"),
        "cv_unseen_destinations": gcv.round(3).reset_index().to_dict("records"),
        "by_source": by_source(df, oof).round(3).reset_index().to_dict("records"),
        "interval": interval_coverage(df),
        "importance_mae_increase_rs_per_day": permutation_importance(df).round(2).to_dict(),
    }
    ext = external_validation()
    if ext:
        report["external_blog_validation"] = ext[0]
    return report


if __name__ == "__main__":
    print(json.dumps(run_all(), indent=1, default=float))
