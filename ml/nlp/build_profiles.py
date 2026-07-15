"""
JourneyAI — per-destination aggregate profiles (P1 → P2 bridge).

Consolidates the per-journal features + TF-IDF into ONE row per canonical
destination — the object the recommender scores a user query against. For each
destination we compute:

    tfidf_mean      mean TF-IDF vector over that destination's journals
    activity_vec    normalized activity histogram (over CANONICAL_ACTIVITIES)
    sentiment_mean  mean sentiment score (destination "quality" signal)
    n_journals      support (how many journals back this profile)
    avg_true_total, avg_daily, avg_duration   cost/duration priors
    popularity_tier, region, base_daily_cost  from the destinations table
    style_mix       distribution of travel styles seen

Artifacts pickled to ml/artifacts/dest_profiles.pkl (a dict) for the Flask
service to load once at boot.

Run:
    python -m ml.nlp.build_profiles
"""
import os
import pickle

import numpy as np
import pandas as pd

from ml.db import fetch_df
from ml.nlp.lexicon import CANONICAL_ACTIVITIES
from ml.seed.destination_catalog import get_destinations

ARTIFACT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "artifacts"))
_CAT_BY_NAME = {d["name"]: d for d in get_destinations()}


def build():
    # per-journal features + costs (join features to the journals view)
    df = fetch_df("""
        SELECT jf.entry_id, jf.canonical_dest, jf.activities, jf.travel_style,
               jf.sentiment_score, jf.budget_bucket,
               j.true_total, j.duration_days
        FROM journal_features jf
        JOIN journals j ON j.entry_id = jf.entry_id
        WHERE jf.canonical_dest IS NOT NULL
    """)
    if df.empty:
        print("No features found — run extract_features first.")
        return

    df["true_total"] = pd.to_numeric(df["true_total"], errors="coerce").fillna(0.0)
    df["duration_days"] = pd.to_numeric(df["duration_days"], errors="coerce").fillna(1).clip(lower=1)
    df["sentiment_score"] = pd.to_numeric(df["sentiment_score"], errors="coerce").fillna(0.0)
    df["daily"] = df["true_total"] / df["duration_days"]

    # destinations reference (tier / region / base cost)
    ref = fetch_df("SELECT canonical_name, region, popularity_tier, base_daily_cost "
                   "FROM destinations")
    ref = ref.set_index("canonical_name").to_dict("index")

    # TF-IDF matrix + aligned entry_ids
    with open(os.path.join(ARTIFACT_DIR, "tfidf_matrix.pkl"), "rb") as f:
        tfidf = pickle.load(f)
    with open(os.path.join(ARTIFACT_DIR, "tfidf_entry_ids.pkl"), "rb") as f:
        tfidf_ids = pickle.load(f)
    id_to_row = {eid: i for i, eid in enumerate(tfidf_ids)}

    import json

    profiles = {}
    for dest, grp in df.groupby("canonical_dest"):
        # activity histogram
        act_counts = np.zeros(len(CANONICAL_ACTIVITIES))
        idx = {a: i for i, a in enumerate(CANONICAL_ACTIVITIES)}
        for acts_json in grp["activities"]:
            for a in (json.loads(acts_json) if isinstance(acts_json, str) else (acts_json or [])):
                if a in idx:
                    act_counts[idx[a]] += 1
        act_vec = act_counts / act_counts.sum() if act_counts.sum() > 0 else act_counts

        # mean TF-IDF over this destination's journals
        rows = [id_to_row[e] for e in grp["entry_id"] if e in id_to_row]
        tfidf_mean = np.asarray(tfidf[rows].mean(axis=0)).ravel() if rows else None

        meta = ref.get(dest, {})
        cat = _CAT_BY_NAME.get(dest, {})
        profiles[dest] = {
            "canonical_dest": dest,
            "n_journals": int(len(grp)),
            "activity_vec": act_vec,
            "tfidf_mean": tfidf_mean,
            "sentiment_mean": float(grp["sentiment_score"].mean()),
            "avg_true_total": float(grp["true_total"].mean()),
            "avg_daily": float(grp["daily"].mean()),
            "avg_duration": float(grp["duration_days"].mean()),
            "popularity_tier": meta.get("popularity_tier", "mainstream"),
            "region": meta.get("region") or cat.get("region"),
            "base_daily_cost": float(meta.get("base_daily_cost") or 0.0),
            "season_peak": cat.get("season_peak", []),
            "city": cat.get("city"),
            "country": cat.get("country"),
            "attractions": cat.get("attractions", []),
            "style_mix": grp["travel_style"].value_counts(normalize=True).to_dict(),
            "top_budget_bucket": grp["budget_bucket"].mode().iat[0] if len(grp) else None,
        }

    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    with open(os.path.join(ARTIFACT_DIR, "dest_profiles.pkl"), "wb") as f:
        pickle.dump({"profiles": profiles,
                     "activities": CANONICAL_ACTIVITIES}, f)

    # summary
    print("=== Destination profiles ===")
    print("destinations profiled:", len(profiles))
    tiers = {}
    for p in profiles.values():
        tiers[p["popularity_tier"]] = tiers.get(p["popularity_tier"], 0) + 1
    print("by tier:", tiers)
    # show a couple
    for name in list(profiles)[:3]:
        p = profiles[name]
        top_acts = [CANONICAL_ACTIVITIES[i] for i in np.argsort(p["activity_vec"])[::-1][:3]
                    if p["activity_vec"][i] > 0]
        print(f"  {name}: n={p['n_journals']}, avg=Rs{p['avg_true_total']:.0f}, "
              f"sent={p['sentiment_mean']:+.2f}, top_acts={top_acts}, tier={p['popularity_tier']}")
    print("artifact -> dest_profiles.pkl")


if __name__ == "__main__":
    build()
