"""
JourneyAI - evaluation of the trip-cost model.

The model is built from real traveller-listed prices (not from journals), so it is evaluated against real,
held-out prices rather than against journal totals (which, for imported journals, were just the old
catalog price x days - the circularity this replaced).

Three checks, all computed by `ml.cost.train_cost_model` and stored in the artifact:
  1. 5-fold on real tier-labelled listings, destination known     (does destination data help?)
  2. leave-one-destination-out on real listings, destination unseen (the regional-estimate case)
  3. real trip reports (ml/data/real_trip_totals.json), predicted leave-one-report-out, vs naive baselines

    ml/venv/Scripts/python.exe -m ml.eval.cost_eval          # print the stored report
    ml/venv/Scripts/python.exe -m ml.eval.cost_eval --retrain
"""
import json
import os
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)


def main():
    if "--retrain" in sys.argv:
        from ml.cost.train_cost_model import train
        train(verbose=False)
    path = os.path.join(ROOT, "ml", "artifacts", "cost_model_report.json")
    rep = json.load(open(path, encoding="utf-8"))
    print(json.dumps({k: v for k, v in rep.items() if k != "trip_validation"}, indent=1))
    tv = dict(rep["trip_validation"])
    trips = tv.pop("trips", [])
    print(json.dumps(tv, indent=1))
    for t in trips:
        print(f"{t['id']:24s} {t['style_used']:10s} actual {t['actual_pp_day']:7.0f}  predicted(LOO) {t['loo_pred_pp_day']:7.0f}")


if __name__ == "__main__":
    main()
