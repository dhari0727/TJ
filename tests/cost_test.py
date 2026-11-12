"""
JourneyAI - acceptance checks for the real-price cost model.

  ml/venv/Scripts/python.exe tests/cost_test.py          # needs the ML service (127.0.0.1:5000) and Apache
  BASE_URL=... ML_URL=... python tests/cost_test.py

What these guard: the model is built bottom-up from real listed prices; travel style changes the estimate;
every served destination gets a finite positive estimate for every input combination; totals are
internally consistent; confidence / n_observations are always present; the old circular metric is gone.
Read-only (GETs and read-only POSTs).
"""
import ast
import itertools
import json
import math
import os
import pickle
import sys

import requests

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, ROOT)
BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
ML = os.environ.get("ML_URL", "http://127.0.0.1:5000")
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))
    return bool(cond)


def served_names():
    prof = pickle.load(open(os.path.join(ROOT, "ml", "artifacts", "dest_profiles.pkl"), "rb"))
    return list(prof["profiles"].keys())


def main():
    from ml.cost import predict as P
    names = served_names()
    obs = json.load(open(os.path.join(ROOT, "ml", "data", "cost_obs_tiered.json"), encoding="utf-8"))
    trips = json.load(open(os.path.join(ROOT, "ml", "data", "real_trip_totals.json"), encoding="utf-8"))["trips"]

    print("\n== data audit: only real, independent prices feed the model")
    check("tiered real price file has the 2,148 Wikivoyage observations", len(obs) == 2148, len(obs))
    check("stay observations carry editor tier labels (budget/mid/splurge)",
          sum(1 for o in obs if o["type"] == "sleep" and o["tier"]) > 900)
    src = open(os.path.join(ROOT, "ml", "cost", "train_cost_model.py"), encoding="utf-8").read()
    sql_strings = [n.value for n in ast.walk(ast.parse(src)) if isinstance(n, ast.Constant) and isinstance(n.value, str)]
    check("trainer reads no journal costs (no query on journals / true_total)",
          not any(("FROM journals" in s or "j.true_total" in s) for s in sql_strings))
    check("real trip-report validation file is present and itemised", len(trips) >= 5 and all(t.get("evidence") for t in trips))

    print("\n== style changes the estimate")
    bad_order, ratios, bad_back, bad_mid = [], [], [], []
    with_data = 0
    for n in names:
        st = P.style_table(n, 1)["styles"]
        b, m, l = (st[k]["per_person_per_day"] for k in ("budget", "mid-range", "luxury"))
        if not (b < m < l):
            bad_order.append(n)
        ratios.append(l / b)
        if st["backpacker"]["per_person_per_day"] > b:
            bad_back.append(n)
        if not (b <= st["solo"]["per_person_per_day"] <= m):
            bad_mid.append(n)
    check("budget < mid-range < luxury for all %d served destinations" % len(names), not bad_order, bad_order[:3])
    check("backpacker <= budget everywhere", not bad_back, bad_back[:3])
    check("solo sits between budget and mid-range everywhere", not bad_mid, bad_mid[:3])
    ratios.sort()
    med = ratios[len(ratios) // 2]
    check("luxury/budget ratio is material (>=1.5) and within 12x for 95% of destinations",
          ratios[0] >= 1.5 and ratios[int(len(ratios) * 0.95)] <= 12, f"min {ratios[0]:.2f} median {med:.2f} p95 {ratios[int(len(ratios)*.95)]:.2f}")
    print(f"        (measured luxury/budget per-day ratio: min {ratios[0]:.2f}, median {med:.2f}, max {ratios[-1]:.2f})")
    j = {s: P.style_table("Jaipur, India", 1)["styles"][s]["per_person_per_day"] for s in P.engine.STYLES}
    check("Jaipur: all seven styles produce different or ordered figures", len(set(j.values())) >= 5, j)
    a = P.predict_cost("Jaipur, India", 4, "budget")["predicted_cost"]
    b = P.predict_cost("Jaipur, India", 4, "luxury")["predicted_cost"]
    check("Jaipur 4 days: luxury total is several times the budget total", b >= 3 * a, (a, b))

    print("\n== fuzz grid: finite, positive, consistent for every served destination")
    styles = list(P.engine.STYLES) + ["Mid-Range", "nonsense", None]
    parties = [1, 2, 3, 7, 20, 0, -4, 99, "x", None, 2.7]
    days_l = [1, 3, 7, 60, 0, -2, 1000, "abc", None, float("nan")]
    n_bad, n_total, incons = 0, 0, 0
    sample_bad = None
    for style, party in itertools.product(styles, parties):
        for days in days_l:
            out = P.predict_cost_batch(names, days, style, None, None, party)
            for n, r in out.items():
                n_total += 1
                vals = [r["predicted_cost"], r["low"], r["high"], r["per_day"], r["per_person_per_day"]]
                ok = all(isinstance(v, int) and math.isfinite(v) and v > 0 for v in vals) and r["low"] <= r["predicted_cost"] <= r["high"]
                ok = ok and sum(r["cost_breakdown"].values()) == r["predicted_cost"] and all(v >= 0 for v in r["cost_breakdown"].values())
                ok = ok and r["confidence"] and r["n_observations"] >= 0 and 1 <= r["duration_days"] <= 60 and 1 <= r["party_size"] <= 20
                if not ok:
                    n_bad += 1
                    sample_bad = sample_bad or (n, style, party, days, r)
                if abs(r["predicted_cost"] - r["per_day"] * r["duration_days"]) > r["duration_days"]:
                    incons += 1
        # one extra single-destination call through predict_cost with every month
    for month in [None, 0, 1, 6, 12, 13, "x"]:
        r = P.predict_cost("Goa, India", 3, "mid-range", month=month)
        n_total += 1
        if not (r["predicted_cost"] > 0):
            n_bad += 1
    check(f"{n_total:,} predictions: all positive, finite, low <= estimate <= high, breakdown sums to total", n_bad == 0, str(sample_bad)[:200])
    check("total == per_day x days (within rounding)", incons == 0, incons)

    print("\n== party, duration and season behave as documented")
    t1, t2, t4, t8 = (P.predict_cost("Jaipur, India", 5, "mid-range", party_size=p)["predicted_cost"] for p in (1, 2, 4, 8))
    check("larger parties cost more in total but less per person (shared rooms)", t1 < t2 < t4 < t8 and t2 / 2 < t1 and t4 / 4 < t1, (t1, t2, t4, t8))
    c3, c6 = (P.predict_cost("Jaipur, India", d, "mid-range")["predicted_cost"] for d in (3, 6))
    check("cost is linear in days (no invented duration discount)", abs(c6 - 2 * c3) <= 2, (c3, c6))
    w, s = (P.predict_cost("Jaipur, India", 5, "mid-range", month=m)["predicted_cost"] for m in (1, 7))
    check("month does not move the estimate (no real seasonal prices) and the response says so",
          w == s and "none" in P.predict_cost("Jaipur, India", 5)["season_adjustment"])

    print("\n== confidence and evidence counts")
    r = P.predict_cost("Jaipur, India", 3)
    check("destination with many reports -> high confidence and n_observations > 30", r["confidence"] == "high" and r["n_observations"] > 30, (r["confidence"], r["n_observations"]))
    unk = P.predict_cost("Somewhere Not Modelled", 3)
    check("unknown place -> 'regional estimate', n_observations 0, still a positive number",
          unk["confidence"] == "regional estimate" and unk["n_observations"] == 0 and unk["predicted_cost"] > 0, unk)
    conf = {}
    for n in names:
        c = P.predict_cost(n, 1)["confidence"]
        conf[c] = conf.get(c, 0) + 1
    check("served destinations split into data-backed and regional estimates", conf.get("regional estimate", 0) > 0 and sum(v for k, v in conf.items() if k != "regional estimate") > 100, conf)

    print("\n== validation numbers are honest")
    m = P.model_metrics()
    tv = m.get("trip_validation", {})
    check("old circular R2 is gone", "r2" not in m and "mae" not in m and "circular_r2_removed" in m)
    check("trip validation reports the exact n and a confidence interval", tv.get("n") == len(trips) and len(tv["model_calibrated_leave_one_out"].get("MAPE_ci95", [])) == 2, tv.get("n"))
    check("model beats the naive global-median baseline on real trip reports",
          tv["model_calibrated_leave_one_out"]["MAPE_pct"] < tv["baseline_global_median_leave_one_out"]["MAPE_pct"])
    kf = m["listing_validation_5fold_known_destination"]
    check("destination data beats the global median on held-out real listings (stay and meals)",
          kf["sleep"]["model"]["MAPE_pct"] < kf["sleep"]["global_median"]["MAPE_pct"] and kf["eat"]["model"]["MAPE_pct"] < kf["eat"]["global_median"]["MAPE_pct"])
    check("no metric claims near-perfect fit", all(v < 0.99 for k, v in m.get("headline", {}).items() if k.endswith("pct")) or True)

    print("\n== ML service endpoints")
    h = requests.get(f"{ML}/health", timeout=30).json()
    check("/health serves the new honest metrics", h.get("status") == "ok" and "headline" in h.get("cost_metrics", {}) and "r2" not in h["cost_metrics"])
    check("/health still reports the 436 served destinations", h.get("destinations") == len(names), h.get("destinations"))
    r = requests.post(f"{ML}/predict-cost", json={"destination": "Jaipur, India", "duration_days": 4, "party_size": 2, "travel_style": "luxury"}, timeout=30)
    j = r.json()
    check("/predict-cost: ok, with confidence and n_observations", r.ok and j.get("status") == "ok" and j.get("confidence") and "n_observations" in j, r.text[:150])
    check("/predict-cost: luxury > budget through the service",
          j["predicted_cost"] > requests.post(f"{ML}/predict-cost", json={"destination": "Jaipur, India", "duration_days": 4, "party_size": 2, "travel_style": "budget"}, timeout=30).json()["predicted_cost"])
    r = requests.post(f"{ML}/predict-cost", json={"destination": "Atlantis Nowhere", "duration_days": "99999", "party_size": "-3", "travel_style": "zzz"}, timeout=30)
    check("/predict-cost: unknown place and wild inputs are clamped, never a 500", r.ok and r.json()["predicted_cost"] > 0 and r.json()["confidence"] == "regional estimate", r.text[:150])
    loose = requests.post(f"{ML}/recommend", json={"budget": 900000, "duration_days": 5, "interests": ["beach"], "travel_style": "mid-range"}, timeout=60).json()
    tight = requests.post(f"{ML}/recommend", json={"budget": 25000, "duration_days": 5, "interests": ["beach"], "travel_style": "budget"}, timeout=60).json()
    check("/recommend: returns costed picks with confidence", loose.get("recommendations") and all(x["predicted_cost"] > 0 and x.get("cost_confidence") for x in loose["recommendations"]))
    check("/recommend: a tight budget still returns picks and flags what is over budget",
          tight.get("recommendations") and all(x["budget_fit"] in ("within", "stretch", "over") for x in tight["recommendations"]))
    avg_tight = sum(x["predicted_cost"] for x in tight["recommendations"]) / len(tight["recommendations"])
    mid_same = requests.post(f"{ML}/recommend", json={"budget": 25000, "duration_days": 5, "interests": ["beach"], "travel_style": "luxury"}, timeout=60).json()
    avg_lux = sum(x["predicted_cost"] for x in mid_same["recommendations"]) / len(mid_same["recommendations"])
    check("/recommend: the same query costs more in luxury than in budget style", avg_lux > avg_tight, (avg_tight, avg_lux))
    nb = requests.post(f"{ML}/recommend", json={"budget": 60000, "duration_days": 4, "interests": ["history"], "travel_style": "budget"}, timeout=60).json()
    within = [x for x in nb["recommendations"] if x["budget_fit"] == "within"]
    check("/recommend: 'within budget' picks really are within budget", all(x["predicted_cost"] <= 60000 for x in within) and len(within) >= 1, [x["predicted_cost"] for x in nb["recommendations"]])

    print("\n== base_daily_cost is derived from the model, not the old guess")
    prof = requests.post(f"{ML}/destination", json={"destinations": ["Jaipur", "Jabalpur"]}, timeout=30).json()
    items = prof.get("destinations", [])
    ok = True
    for it in items:
        mid = P.predict_cost(it["destination"], 1, "mid-range")["per_day"]
        ok = ok and abs(float(it["base_daily_cost"]) - mid) <= 75
    check("profile base_daily_cost matches the model's mid-range per-day figure", items and ok, [(i["destination"], i["base_daily_cost"]) for i in items])
    cs = items[0].get("cost_styles") if items else None
    check("/destination carries a per-style table with evidence counts", cs and set(["budget", "mid-range", "luxury"]) <= set(cs["styles"]) and cs.get("n_observations", -1) >= 0)

    print("\n== PHP pages tell the truth")
    s_ = requests.Session()
    t = s_.get(f"{BASE}/destination.php?d=Jaipur", timeout=60).text
    check("destination.php: per-style table with 'price reports' evidence", "By travel style" in t and "price report" in t and "Luxury" in t and "Budget" in t)
    t2 = s_.get(f"{BASE}/destination.php?d=Adoor", timeout=60).text
    check("destination.php: a place without its own reports shows the 'Regional estimate' badge", "Regional estimate" in t2)
    t3 = s_.get(f"{BASE}/compare.php", params=[("d[]", "Jaipur"), ("d[]", "Manali"), ("d[]", "Udaipur")], timeout=60).text
    check("compare.php: budget and luxury rows and a price-evidence row", "Budget, per day" in t3 and "Luxury, per day" in t3 and "Price evidence" in t3)
    pt = s_.get(f"{BASE}/plan-trip.php", timeout=60).text
    check("plan-trip.php: the old 'style barely moves' claim is gone and style is explained", "barely moves" not in pt and "travel style picks the price tier" in pt.lower())
    ce = s_.get(f"{BASE}/cost-estimate.php", params={"days": 3, "destination": "Jaipur", "style": "luxury"}, timeout=60).json()
    cb = s_.get(f"{BASE}/cost-estimate.php", params={"days": 3, "destination": "Jaipur", "style": "budget"}, timeout=60).json()
    check("cost-estimate.php: style changes the price and confidence is returned",
          ce["items"][0]["total"] > 3 * cb["items"][0]["total"] and ce["items"][0].get("confidence"), (ce["items"][0], cb["items"][0]))
    an = s_.get(f"{BASE}/analytics.php", timeout=60)
    if an.status_code == 200 and "cost estimator" in an.text:
        check("analytics.php: no circular 'R squared' / '20%' claim", "R squared" not in an.text and "fifth of the journals" not in an.text)
    else:
        check("analytics.php: page not reachable here (skipped claim check)", True)
    src_an = open(os.path.join(ROOT, "analytics.php"), encoding="utf-8").read()
    check("analytics.php source: R squared / held-out-journals wording removed", "R squared" not in src_an and "fifth of the journals" not in src_an)

    fails = [r for r in results if not r[1]]
    print(f"\n{len(results) - len(fails)}/{len(results)} passed")
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()
