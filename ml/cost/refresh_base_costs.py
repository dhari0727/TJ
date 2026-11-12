"""
JourneyAI - refresh destinations.base_daily_cost from the REAL-price cost model.

The old column was a hand-set tier guess (and the imported journals' "costs" were just that guess x days).
It is now derived: one traveller, mid-range style, per day, straight from ml/cost/predict.py, so the
recommender's similarity feature (ml/recommender/similar.py) and the destination list use the same
numbers as every cost estimate on the site. Rows with no India price data (international catalog rows)
are set to NULL rather than keeping an unsupported number.

Also patches base_daily_cost inside ml/artifacts/dest_profiles.pkl in place so the running recommender
sees the new values without a full profile rebuild.

    ml/venv/Scripts/python.exe -m ml.cost.refresh_base_costs [--dry-run]
"""
import argparse
import os
import pickle
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.cost.predict import predict_cost_batch  # noqa: E402
from ml.db import get_connection  # noqa: E402

PROFILES = os.path.join(ROOT, "ml", "artifacts", "dest_profiles.pkl")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    conn = get_connection(dict_cursor=False)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT canonical_name, country FROM destinations")
            rows = cur.fetchall()
        india = [r[0] for r in rows if r[1] == "India"]
        other = [r[0] for r in rows if r[1] != "India"]
        pred = predict_cost_batch(india, duration_days=1, travel_style="mid-range", party_size=1)
        new = {n: int(round(pred[n]["per_day"] / 50.0) * 50) for n in india}
        print(f"India destinations: {len(india)}  international set to NULL: {len(other)}")
        vals = sorted(new.values())
        print(f"new base_daily_cost  min {vals[0]}  median {vals[len(vals) // 2]}  max {vals[-1]}")
        if a.dry_run:
            return
        with conn.cursor() as cur:
            cur.executemany("UPDATE destinations SET base_daily_cost=%s WHERE canonical_name=%s",
                            [(v, k) for k, v in new.items()])
            cur.executemany("UPDATE destinations SET base_daily_cost=NULL WHERE canonical_name=%s", [(n,) for n in other])
        conn.commit()
    finally:
        conn.close()
    if os.path.exists(PROFILES):
        blob = pickle.load(open(PROFILES, "rb"))
        prof = blob["profiles"] if isinstance(blob, dict) and "profiles" in blob else blob
        n = 0
        for name, p in prof.items():
            if name in new:
                p["base_daily_cost"] = float(new[name])
                n += 1
        pickle.dump(blob, open(PROFILES, "wb"))
        print(f"patched {n} profiles in dest_profiles.pkl")


if __name__ == "__main__":
    main()
