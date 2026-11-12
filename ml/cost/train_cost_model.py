"""
JourneyAI - honest trip-cost model (bottom-up, real prices only).

Why this replaced the old gradient-boosted model: it was trained on journals.true_total, and for every
imported journal true_total == destinations.base_daily_cost x days (the catalog's tier-based guess). The model
re-learned that guess (R2 0.9999 was a circularity artefact) and travel style changed nothing.

What it does now
  1. Reads ~2,150 REAL price observations (Wikivoyage listings with the article section kept:
     ml/data/cost_obs_tiered.json, built by ml/cost/build_observations.py) and 84 real local-ride fares.
  2. Estimates, per destination, the log price of a night's stay, a meal and an entry ticket with a
     hierarchical shrinkage model (destination -> Wikivoyage area -> region -> India). Tier effects
     (budget / mid / splurge / dorm / do-vs-see) are estimated within destination from the editors'
     own section labels, so no style multiplier is invented. Shrinkage strengths are chosen by
     cross-validation on held-out real listings.
  3. Serves daily cost = stay + food + local transport + fees, with style selecting the tier
     (ml/cost/engine.py), and a Monte-Carlo 80% band from the measured spreads.
  4. Validates on (a) leave-one-destination-out real listings and (b) a small set of real, independently
     reported trip totals (ml/data/real_trip_totals.json), against naive baselines.

Artifact -> ml/artifacts/cost_model.pkl

Run:
    ml/venv/Scripts/python.exe -m ml.cost.build_observations     # only if the raw cache changed
    ml/venv/Scripts/python.exe -m ml.cost.train_cost_model
"""
import json
import math
import os
import pickle
import statistics as st
import sys
from collections import Counter, defaultdict

import numpy as np

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.cost import engine  # noqa: E402
from ml.cost.engine import month_to_season  # noqa: E402,F401  (re-exported for older imports)

DATA = os.path.join(ROOT, "ml", "data")
ARTIFACT_DIR = os.path.join(ROOT, "ml", "artifacts")
OBS_PATH = os.path.join(DATA, "cost_obs_tiered.json")
FARE_PATH = os.path.join(DATA, "cost_obs_transport.json")
TRIPS_PATH = os.path.join(DATA, "real_trip_totals.json")
NODES_PATH = os.path.join(DATA, "india_destinations.json")

REGION_OF_TOP = {
    "Himalayan North": "Himalayas", "Plains (India)": "North India", "Western India": "West India",
    "Central India": "Central India", "Eastern India": "East India",
    "North-Eastern India": "Northeast India", "Southern India": "South India",
    "Delhi": "North India", "Jaipur": "North India", "Varanasi": "North India",
    "Bengaluru": "South India", "Chennai": "South India", "Hyderabad": "South India",
    "Mumbai": "West India", "Kolkata": "East India", "Shimla": "Himalayas",
}
K_GRID = [0.25, 0.5, 1, 2, 4, 8, 16]


# --------------------------------------------------------------------------- data
def rollup(title):
    """'Kolkata/South' -> 'Kolkata' (district pages belong to their city)."""
    return title.split("/")[0].strip()


def load_obs():
    rows = json.load(open(OBS_PATH, encoding="utf-8"))
    for r in rows:
        r["dest"] = rollup(r["destination"])
        r["y"] = math.log(r["inr"])
    return rows


def load_fares():
    return json.load(open(FARE_PATH, encoding="utf-8"))


def db_destinations():
    from ml.db import fetch_all
    return fetch_all("SELECT canonical_name, country, region, base_daily_cost FROM destinations")


def build_paths(titles, db_region):
    """title -> ['R:<region>', area ancestors top-down] (excludes the leaf and 'India')."""
    nodes = {n["title"]: n for n in json.load(open(NODES_PATH, encoding="utf-8"))}
    paths, regions = {}, {}
    for t in titles:
        chain, c, s = [], t, 0
        while c and s < 10:
            chain.append(c)
            c = nodes.get(c, {}).get("parent")
            s += 1
        region = db_region.get(t + ", India")
        if not region:
            for c in chain:
                if c in REGION_OF_TOP:
                    region = REGION_OF_TOP[c]
                    break
        region = region or "North India"
        anc = [c for c in reversed(chain[1:]) if c != "India" and c not in REGION_OF_TOP]
        paths[t] = ["R:" + region] + anc
        regions[t] = region
    return paths, regions


# --------------------------------------------------------------------------- tier effects
def fixed_effects(rows, levels):
    """Within-destination OLS of log price on level dummies (baseline level omitted). Only destinations that
    show >=2 levels contribute. Returns ({level: (delta, se)}, n_rows_used)."""
    by = defaultdict(list)
    for r in rows:
        by[r["dest"]].append(r)
    X, Y = [], []
    for rs in by.values():
        if len({r["lv"] for r in rs}) < 2:
            continue
        y = np.array([r["y"] for r in rs])
        L = np.array([[1.0 if r["lv"] == l else 0.0 for l in levels] for r in rs])
        X.append(L - L.mean(0))
        Y.append(y - y.mean())
    X, Y = np.vstack(X), np.concatenate(Y)
    b = np.linalg.lstsq(X, Y, rcond=None)[0]
    n_dest = sum(1 for rs in by.values() if len({r["lv"] for r in rs}) >= 2)
    dof = max(1, len(Y) - n_dest - len(levels))
    s2 = float(((Y - X @ b) ** 2).sum() / dof)
    se = np.sqrt(np.diag(s2 * np.linalg.pinv(X.T @ X)))
    return {l: (float(b[i]), float(se[i])) for i, l in enumerate(levels)}, len(Y)


def component_rows(obs):
    sleep, eat, fee = [], [], []
    for r in obs:
        if r["type"] == "sleep":
            lv = "bed" if r["basis"] == "bed" else (r["tier"] or "unl")
            sleep.append(dict(dest=r["dest"], y=r["y"], lv=lv))
        elif r["type"] == "eat":
            eat.append(dict(dest=r["dest"], y=r["y"], lv=r["tier"] or "unl"))
        elif r["type"] in ("see", "do"):
            fee.append(dict(dest=r["dest"], y=r["y"], lv="do" if r["type"] == "do" else "see"))
    for rows in (sleep, eat, fee):         # winsorise at the 1st / 99th percentile (robust to typos)
        lo, hi = np.percentile([r["y"] for r in rows], [1, 99])
        for r in rows:
            r["y"] = float(min(max(r["y"], lo), hi))
    return {"sleep": sleep, "eat": eat, "fee": fee}


# --------------------------------------------------------------------------- hierarchical estimator
class Hier:
    """Empirical-Bayes pooling over destination -> area -> region -> India for ONE component."""

    def __init__(self, rows, paths, deltas, base_level):
        self.paths = paths
        self.items = [(r["dest"], r["y"] - (0.0 if r["lv"] == base_level else deltas[r["lv"]][0]), r["lv"])
                      for r in rows]

    def stats(self, keep=None):
        n, s = defaultdict(float), defaultdict(float)
        for i, (d, y, _) in enumerate(self.items):
            if keep is not None and not keep[i]:
                continue
            n[d] += 1
            s[d] += y
        return n, s

    def node_stats(self, n, s):
        N, S = defaultdict(float), defaultdict(float)
        for d in n:
            for node in self.paths.get(d, ["R:North India"]):
                N[node] += n[d]
                S[node] += s[d]
        return N, S

    def estimate(self, d, n, s, N, S, gN, gS, kp, kl, use_own=True):
        """posterior mean of the destination effect; nodes/global are computed WITHOUT d's own listings."""
        nd, sd = n.get(d, 0.0), s.get(d, 0.0)
        gm = (gS - sd) / max(1.0, gN - nd)
        prior, nearest = gm, "India"
        for node in self.paths.get(d, ["R:North India"]):
            Nn, Sn = N.get(node, 0.0) - nd, S.get(node, 0.0) - sd
            if Nn > 0:
                prior += Nn / (Nn + kp) * (Sn / Nn - prior)
                nearest = node[2:] if node.startswith("R:") else node
        if use_own and nd > 0:
            post = prior + nd / (nd + kl) * (sd / nd - prior)
            return post, 1.0 / (nd + kl), int(nd), nearest
        return prior, 1.0 / kl, 0, nearest

    def cv_tune(self, seed=7, folds=5):
        rng = np.random.default_rng(seed)
        fold = rng.integers(0, folds, len(self.items))
        best = None
        cache = {}
        for f in range(folds):
            keep = fold != f
            n, s = self.stats(keep)
            N, S = self.node_stats(n, s)
            cache[f] = (n, s, N, S, sum(n.values()), sum(s.values()))
        for kp in K_GRID:
            for kl in K_GRID:
                se, cnt = 0.0, 0
                for f in range(folds):
                    n, s, N, S, gN, gS = cache[f]
                    est = {}
                    for i, (d, y, _) in enumerate(self.items):
                        if fold[i] != f:
                            continue
                        if d not in est:
                            est[d] = self.estimate(d, n, s, N, S, gN, gS, kp, kl)[0]
                        se += (y - est[d]) ** 2
                        cnt += 1
                if best is None or se / cnt < best[0]:
                    best = (se / cnt, kp, kl)
        return best[1], best[2], best[0]


def pooled_within_var(items):
    by = defaultdict(list)
    for d, y, _ in items:
        by[d].append(y)
    ss, dof = 0.0, 0
    for ys in by.values():
        if len(ys) >= 2:
            m = sum(ys) / len(ys)
            ss += sum((y - m) ** 2 for y in ys)
            dof += len(ys) - 1
    return ss / max(1, dof)


# --------------------------------------------------------------------------- fares
def fare_table(fares):
    out = {}
    for mode in ("bus", "auto", "taxi"):
        v = np.array([f["inr"] for f in fares if f["mode"] == mode], float)
        lv = np.log(v)
        out[mode] = {"median": float(np.median(v)), "sd_log": float(lv.std(ddof=1)), "n": int(len(v)),
                     "ci": [float(x) for x in bootstrap_ci(v, np.median)]}
    return out


def bootstrap_ci(x, fn, n=2000, seed=3):
    rng = np.random.default_rng(seed)
    x = np.asarray(x, float)
    vals = [fn(rng.choice(x, len(x), replace=True)) for _ in range(n)]
    return np.percentile(vals, [2.5, 97.5])


def family_premium(obs):
    import re
    rat = []
    for r in obs:
        if r["type"] != "sleep":
            continue
        t = r["price_raw"]
        fam = re.search(r"family[^₹]{0,25}(?:₹|Rs\.?)\s*([\d,]+)", t, re.I)
        dbl = re.search(r"(?:double|standard|deluxe|twin|regular)[^₹]{0,40}(?:₹|Rs\.?)\s*([\d,]+)", t, re.I)
        if fam and dbl:
            a, b = float(fam.group(1).replace(",", "")), float(dbl.group(1).replace(",", ""))
            if a > 0 and b > 0:
                rat.append(a / b)
    return float(np.median(rat)), len(rat), [float(x) for x in np.percentile(rat, [25, 75])]


# --------------------------------------------------------------------------- validation
def loo_listing_validation(comps, hier, kp, kl, paths, regions, deltas, sigma_w):
    """Leave-one-destination-out on REAL tier-labelled listings (stay + meals): predict each held-out
    destination's listing price from a model that never saw that destination."""
    res = {}
    for comp, base, lv_list in (("sleep", "mid", ("budget", "mid", "splurge")), ("eat", "mid", ("budget", "mid", "splurge"))):
        h = hier[comp]
        n, s = h.stats()
        N, S = h.node_stats(n, s)
        gN, gS = sum(n.values()), sum(s.values())
        est = {d: h.estimate(d, n, s, N, S, gN, gS, kp[comp], kl[comp], use_own=False)[0] for d in n}
        rows = [r for r in comps[comp] if r["lv"] in lv_list and r["lv"] != "bed"]
        # naive baselines, themselves leave-one-destination-out
        by_lv = defaultdict(list)
        for r in rows:
            by_lv[r["lv"]].append(r)
        out = {"n_listings": len(rows)}
        err = defaultdict(list)
        cover = []
        resid = []
        for r in rows:
            lvl = r["lv"]
            dlt = 0.0 if lvl == "mid" else deltas[comp][lvl][0]
            mu = est[r["dest"]] + dlt
            pred = math.exp(mu)
            act = math.exp(r["y"])
            peers = [math.exp(x["y"]) for x in by_lv[lvl] if x["dest"] != r["dest"]]
            reg_peers = [math.exp(x["y"]) for x in by_lv[lvl]
                         if x["dest"] != r["dest"] and regions.get(x["dest"]) == regions.get(r["dest"])]
            base_g = st.median(peers)
            base_r = st.median(reg_peers) if reg_peers else base_g
            for name, p in (("model", pred), ("global_median", base_g), ("region_median", base_r)):
                err[name].append((abs(p - act), abs(p - act) / act, abs(math.log(p / act))))
            sd = math.sqrt(sigma_w[comp] ** 2 + 1.0 / kl[comp] + deltas[comp].get(lvl, (0, 0))[1] ** 2)
            cover.append(abs(r["y"] - mu) <= 1.2816 * sd)
            resid.append(r["y"] - mu)
        for name, e in err.items():
            a = np.array(e)
            out[name] = {"MAE_inr": float(a[:, 0].mean()), "MAPE_pct": float(a[:, 1].mean() * 100),
                         "median_abs_log_error": float(np.median(a[:, 2]))}
        out["band80_coverage_theoretical_sd"] = float(np.mean(cover))
        out["sd_log_error"] = float(np.std(resid))
        res[comp] = out
    return res


def kfold_listing_validation(comps, hier, kp, kl, deltas, sigma_w, folds=5, seed=21):
    """5-fold on real tier-labelled listings: the held-out listing's destination keeps its OTHER listings, the
    situation of a destination we do have data for. Baseline = median price of that tier over all other
    destinations."""
    res = {}
    rng = np.random.default_rng(seed)
    for comp in ("sleep", "eat"):
        h = hier[comp]
        fold = rng.integers(0, folds, len(h.items))
        err = defaultdict(list)
        cover = []
        resid = []
        raw_rows = comps[comp]
        for f in range(folds):
            keep = fold != f
            n, s = h.stats(keep)
            N, S = h.node_stats(n, s)
            gN, gS = sum(n.values()), sum(s.values())
            est = {}
            for i, (d, y, lv) in enumerate(h.items):
                if fold[i] != f or lv not in ("budget", "mid", "splurge"):
                    continue
                if d not in est:
                    est[d] = h.estimate(d, n, s, N, S, gN, gS, kp[comp], kl[comp])
                mu, var, nown, _ = est[d]
                dlt = 0.0 if lv == "mid" else deltas[comp][lv][0]
                act = math.exp(raw_rows[i]["y"])
                tier_peers = [math.exp(raw_rows[j]["y"]) for j in range(len(raw_rows))
                              if keep[j] and raw_rows[j]["lv"] == lv and raw_rows[j]["dest"] != raw_rows[i]["dest"]]
                for name, p in (("model", math.exp(mu + dlt)), ("global_median", st.median(tier_peers))):
                    err[name].append((abs(p - act), abs(p - act) / act, abs(math.log(p / act))))
                sd = math.sqrt(sigma_w[comp] ** 2 + var * sigma_w[comp] ** 2 + deltas[comp].get(lv, (0, 0))[1] ** 2)
                cover.append(abs(math.log(act) - (mu + dlt)) <= 1.2816 * sd)
                resid.append(math.log(act) - (mu + dlt))
        out = {"n_listings": len(cover)}
        for name, e in err.items():
            a = np.array(e)
            out[name] = {"MAE_inr": float(a[:, 0].mean()), "MAPE_pct": float(a[:, 1].mean() * 100),
                         "median_abs_log_error": float(np.median(a[:, 2]))}
        out["band80_coverage_theoretical_sd"] = float(np.mean(cover))
        out["sd_log_error"] = float(np.std(resid))
        res[comp] = out
    return res


def _trip_rows(bundle):
    """Raw (uncalibrated) per-person-per-day predictions for each real trip report."""
    trips = json.load(open(TRIPS_PATH, encoding="utf-8"))["trips"]
    raw_bundle = dict(bundle, price_factor=1.0, price_factor_sd=0.0)
    rows = []
    for t in trips:
        days, party = t["days"], t["party"]
        scope = t.get("in_scope_total") or t["in_scope_total_per_person"] * party
        actual_pp = scope / party / days
        rec = engine.dest_params(bundle, t["destination"])
        # style by a rule fixed before looking at totals: declared style, else the tier nearest the quoted
        # room price (per room per night), else the product default (mid-range)
        if t.get("declared_style"):
            style, how = t["declared_style"], "declared by the author"
        elif t.get("lodging_quote_per_night"):
            q = math.log(t["lodging_quote_per_night"] / math.ceil(party / 2))
            levels = {"budget": engine._stay_level_delta(raw_bundle, "budget"), "mid-range": 0.0,
                      "luxury": engine._stay_level_delta(raw_bundle, "splurge")}
            style = min(levels, key=lambda k: abs(rec["sleep"][0] + levels[k] - q))
            how = "tier nearest to the quoted room price"
        else:
            style, how = "mid-range", "default (no style or lodging quote in the report)"
        d = engine.price_day(raw_bundle, rec, style, party, with_band=False)
        rows.append({"id": t["id"], "destination": t["destination"], "days": days, "party": party,
                     "style_used": style, "style_rule": how, "actual_pp_day": actual_pp,
                     "raw_pred_pp_day": d["total"] / party, "n_obs": int(rec["sleep"][2] + rec["eat"][2] + rec["fee"][2]),
                     "catalog_pp_day": t.get("old_catalog_base_daily_inr")})
    return rows


def calibrate_price_factor(rows):
    """Geometric-mean ratio actual / raw prediction over the real trip reports (clipped to [1, 3]), and the
    prediction sd of one new report: s * sqrt(1 + 1/n)."""
    lr = np.array([math.log(r["actual_pp_day"] / r["raw_pred_pp_day"]) for r in rows])
    f = float(min(3.0, max(1.0, math.exp(lr.mean()))))
    sd = float(lr.std(ddof=1) * math.sqrt(1 + 1 / len(lr))) if len(lr) > 1 else 0.5
    return f, sd


def trip_validation(bundle):
    rows = _trip_rows(bundle)
    n = len(rows)
    act = np.array([r["actual_pp_day"] for r in rows])
    raw = np.array([r["raw_pred_pp_day"] for r in rows])
    final_f, final_sd = calibrate_price_factor(rows)
    # honest out-of-sample score: each report is predicted with a factor calibrated on the OTHER reports
    loo_pred = []
    for i in range(n):
        f, _ = calibrate_price_factor([r for j, r in enumerate(rows) if j != i])
        loo_pred.append(raw[i] * f)
    loo_pred = np.array(loo_pred)
    glob = np.array([float(np.median(np.delete(act, i))) for i in range(n)])     # naive baseline, leave-one-out

    def score(pred, a=act):
        pred, a = np.asarray(pred, float), np.asarray(a, float)
        return {"MAE_inr": float(np.mean(np.abs(pred - a))), "MAPE_pct": float(np.mean(np.abs(pred - a) / a) * 100),
                "median_ratio_pred_over_actual": float(np.median(pred / a))}

    out = {"n": n, "unit": "INR per person per day (author's total minus itemised travel-to-destination)",
           "price_factor": final_f, "price_factor_sd_log": final_sd,
           "model_uncalibrated": score(raw),
           "model_calibrated_leave_one_out": score(loo_pred),
           "baseline_global_median_leave_one_out": score(glob)}
    reg_pred = []
    for i, r in enumerate(rows):
        peers = [rows[j]["actual_pp_day"] for j in range(n) if j != i and rows[j]["destination"] == r["destination"]]
        reg_pred.append(float(np.median(peers)) if peers else np.nan)
    out["baseline_region_median"] = "not computable: no two reports share a destination or region with n=5"
    cat = [(r["catalog_pp_day"], r["actual_pp_day"], loo_pred[i]) for i, r in enumerate(rows) if r["catalog_pp_day"]]
    if cat:
        p = np.array([c[0] for c in cat], float)
        a = np.array([c[1] for c in cat], float)
        m = np.array([c[2] for c in cat], float)
        out["baseline_old_catalog_price"] = dict(score(p, a), n=len(cat))
        out["model_calibrated_loo_on_same_subset"] = dict(score(m, a), n=len(cat))
    rng = np.random.default_rng(11)
    ape = np.abs(loo_pred - act) / act * 100
    boots = [rng.choice(ape, n, replace=True).mean() for _ in range(4000)]
    out["model_calibrated_leave_one_out"]["MAPE_ci95"] = [float(x) for x in np.percentile(boots, [2.5, 97.5])]
    served = dict(bundle, price_factor=final_f, price_factor_sd=final_sd)
    detail = []
    for i, r in enumerate(rows):
        rec = engine.dest_params(served, r["destination"])
        d = engine.price_day(served, rec, r["style_used"], r["party"])
        bud = engine.price_day(served, rec, "budget", r["party"], with_band=False)["total"] / r["party"]
        lux = engine.price_day(served, rec, "luxury", r["party"], with_band=False)["total"] / r["party"]
        detail.append(dict(r, loo_pred_pp_day=float(loo_pred[i]), served_pred_pp_day=d["total"] / r["party"],
                           low_pp_day=d["low"] / r["party"], high_pp_day=d["high"] / r["party"],
                           budget_pp_day=bud, luxury_pp_day=lux, global_median_pp_day=float(glob[i])))
    out["trips"] = detail
    out["inside_band80"] = int(sum(1 for r in detail if r["low_pp_day"] <= r["actual_pp_day"] <= r["high_pp_day"]))
    out["inside_budget_to_luxury_envelope"] = int(sum(1 for r in detail if r["budget_pp_day"] <= r["actual_pp_day"] <= r["luxury_pp_day"]))
    out["note"] = (f"n={n} real trip reports: far too few for statistical claims (95% interval on MAPE shown). "
                   "They calibrate ONE parameter (price_factor, the dated-listing / unlisted-extras uplift); "
                   "the error above is leave-one-report-out, i.e. each report predicted with a factor fitted on the others.")
    return out, final_f, final_sd


# --------------------------------------------------------------------------- main
def train(verbose=True):
    obs = load_obs()
    fares = load_fares()
    dbd = db_destinations()
    db_region = {r["canonical_name"]: r["region"] for r in dbd}
    db_base = {r["canonical_name"]: float(r["base_daily_cost"] or 0) for r in dbd}
    india_db = [r for r in dbd if r["country"] == "India"]

    titles = {r["dest"] for r in obs} | {r["canonical_name"].replace(", India", "") for r in india_db}
    paths, regions = build_paths(titles, db_region)
    comps = component_rows(obs)

    # ---- tier effects from the editors' own section labels
    deltas = {}
    deltas["sleep"], n_s = fixed_effects(comps["sleep"], ["budget", "splurge", "unl", "bed"])
    deltas["eat"], n_e = fixed_effects(comps["eat"], ["budget", "splurge", "unl"])
    deltas["fee"], n_f = fixed_effects(comps["fee"], ["do"])
    base = {"sleep": "mid", "eat": "mid", "fee": "see"}
    hier = {c: Hier(comps[c], paths, deltas[c], base[c]) for c in comps}
    sigma_w, kp, kl, cv_mse = {}, {}, {}, {}
    for c in comps:
        sigma_w[c] = math.sqrt(pooled_within_var(hier[c].items))
        kp[c], kl[c], cv_mse[c] = hier[c].cv_tune()
        if verbose:
            print(f"[{c}] listings={len(comps[c])} sigma_within={sigma_w[c]:.2f} k_pool={kp[c]} k_leaf={kl[c]} cv_mse={cv_mse[c]:.3f}")

    # ---- fee tier offsets (budget = lower quartile, luxury = upper quartile of the real within-destination spread)
    by = defaultdict(list)
    for d, y, lv in hier["fee"].items:
        if lv == "see":
            by[d].append(y)
    resid = [y - sum(v) / len(v) for v in by.values() if len(v) >= 3 for y in v]
    fee_off = {"q25": float(np.percentile(resid, 25)), "q50": 0.0, "q75": float(np.percentile(resid, 75))}

    # ---- posterior table for every destination
    dest_tbl, est_vec = {}, defaultdict(dict)
    stats = {}
    for c in comps:
        n, s = hier[c].stats()
        N, S = hier[c].node_stats(n, s)
        stats[c] = (n, s, N, S, sum(n.values()), sum(s.values()))
    international = {r["canonical_name"] for r in dbd if r["country"] != "India"}
    for t in sorted(titles):
        rec = {}
        for c in comps:
            n, s, N, S, gN, gS = stats[c]
            mu, var, nown, near = hier[c].estimate(t, n, s, N, S, gN, gS, kp[c], kl[c])
            rec[c] = (float(mu), float(var * sigma_w[c] ** 2), int(nown))
            if c == "sleep":
                rec["area"] = near
            est_vec[t][c] = (mu, nown)
        rec["region"] = regions[t]
        dest_tbl[t + ", India"] = rec
    # region / India priors for destinations we have never heard of
    region_prior, india_prior = {}, {}
    for c in comps:
        n, s, N, S, gN, gS = stats[c]
        india_prior[c] = (gS / gN, sigma_w[c] ** 2 / kl[c] + sigma_w[c] ** 2)
    for reg in {v for v in regions.values()}:
        region_prior[reg] = {}
        for c in comps:
            n, s, N, S, gN, gS = stats[c]
            Nn, Sn = N.get("R:" + reg, 0.0), S.get("R:" + reg, 0.0)
            mu = gS / gN
            if Nn > 0:
                mu += Nn / (Nn + kp[c]) * (Sn / Nn - mu)
            region_prior[reg][c] = (float(mu), float(sigma_w[c] ** 2 / kl[c]))
    for r in dbd:                              # international catalog rows: no real data -> India-wide, flagged
        if r["canonical_name"] in international:
            dest_tbl[r["canonical_name"]] = {c: (india_prior[c][0], india_prior[c][1], 0) for c in comps}
            dest_tbl[r["canonical_name"]].update({"area": "India", "region": r["region"], "is_international": True})

    # correlation of destination effects across components (drives how band components move together)
    pairs = []
    ts = [t for t in titles if all(est_vec[t][c][1] >= 3 for c in ("sleep", "eat"))]
    if len(ts) >= 8:
        a = np.array([est_vec[t]["sleep"][0] for t in ts])
        b = np.array([est_vec[t]["eat"][0] for t in ts])
        pairs.append(float(np.corrcoef(a, b)[0, 1]))
    rho = float(min(0.9, max(0.0, np.mean(pairs)))) if pairs else 0.3

    fam_p, fam_n, fam_iqr = family_premium(obs)
    bundle = {
        "kind": "bottom-up real-price model", "version": 2,
        "dest": dest_tbl, "region_prior": region_prior, "india_prior": india_prior,
        "delta": deltas, "sigma_w": sigma_w, "fee_off": fee_off, "fares": fare_table(fares),
        "family_premium": fam_p, "family_premium_n": fam_n, "family_premium_iqr": fam_iqr,
        "rho": rho, "struct": engine.STRUCT, "k_pool": kp, "k_leaf": kl,
        "predicts": "daily",
    }

    # ---- validation
    lodo = loo_listing_validation(comps, hier, kp, kl, paths, regions, deltas, sigma_w)
    kfold = kfold_listing_validation(comps, hier, kp, kl, deltas, sigma_w)
    # predictive spread of one price, measured on held-out real listings (log scale): destination known / unseen
    fee_h = hier["fee"]
    nf, sf = fee_h.stats()
    Nf, Sf = fee_h.node_stats(nf, sf)
    fe_res = [y - fee_h.estimate(d, nf, sf, Nf, Sf, sum(nf.values()), sum(sf.values()), kp["fee"], kl["fee"], use_own=False)[0]
              for d, y, _ in fee_h.items]
    bundle["pred_sd"] = {"sleep": {"known": kfold["sleep"]["sd_log_error"], "unseen": lodo["sleep"]["sd_log_error"]},
                         "eat": {"known": kfold["eat"]["sd_log_error"], "unseen": lodo["eat"]["sd_log_error"]},
                         "fee": {"known": math.sqrt(cv_mse["fee"]), "unseen": float(np.std(fe_res))}}
    trips, pf, pf_sd = trip_validation(bundle)
    bundle["price_factor"], bundle["price_factor_sd"] = pf, pf_sd
    conf = Counter()
    served_like = [k for k, v in dest_tbl.items() if not v.get("is_international")]
    for k in served_like:
        v = dest_tbl[k]
        conf[engine.confidence_of(v["sleep"][2] + v["eat"][2] + v["fee"][2])] += 1
    counts = Counter(r["type"] for r in obs)
    audit = {
        "observations": len(obs), "by_type": dict(counts),
        "tier_labelled_sleep": sum(1 for r in comps["sleep"] if r["lv"] in ("budget", "mid", "splurge")),
        "tier_labelled_eat": sum(1 for r in comps["eat"] if r["lv"] in ("budget", "mid", "splurge")),
        "local_fares": len(fares), "destinations_with_data": len({r["dest"] for r in obs}),
        "confidence_counts": dict(conf), "family_room_pairs": fam_n,
    }
    bundle["metrics"] = {
        "model": "bottom-up real-price model (stay + food + local transport + fees)",
        "trained_on": audit,
        "tier_effects_log": {c: {k: [round(v[0], 3), round(v[1], 3)] for k, v in deltas[c].items()} for c in deltas},
        "listing_validation_leave_one_destination_out": lodo,
        "listing_validation_5fold_known_destination": kfold,
        "trip_validation": trips,
        "circular_r2_removed": "The previous R2 0.9999 measured agreement with the catalog's own base price, not accuracy.",
    }
    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    with open(os.path.join(ARTIFACT_DIR, "cost_model.pkl"), "wb") as f:
        pickle.dump(bundle, f)
    with open(os.path.join(ARTIFACT_DIR, "cost_model_report.json"), "w", encoding="utf-8") as f:
        json.dump(bundle["metrics"], f, indent=1, ensure_ascii=False, default=float)
    if verbose:
        print("\n=== tier effects (log scale, vs mid-range) ===")
        for c in deltas:
            print(c, {k: (round(v[0], 2), round(v[1], 2)) for k, v in deltas[c].items()})
        print("fee offsets", {k: round(v, 2) for k, v in fee_off.items()}, "rho", round(rho, 2),
              "family premium", round(fam_p, 2), f"(n={fam_n})")
        print("fares", {k: (round(v['median']), v['n']) for k, v in bundle["fares"].items()})
        print("=== leave-one-destination-out (unseen destination) ===")
        print(json.dumps(lodo, indent=1))
        print("=== 5-fold (destination known) ===")
        print(json.dumps(kfold, indent=1))
        print("=== real trip totals ===")
        for r in trips["trips"]:
            print(f"{r['id']:24s} {r['style_used']:10s} actual {r['actual_pp_day']:7.0f} raw {r['raw_pred_pp_day']:6.0f} "
                  f"loo {r['loo_pred_pp_day']:6.0f} served {r['served_pred_pp_day']:6.0f} "
                  f"[{r['low_pp_day']:.0f}-{r['high_pp_day']:.0f}] global-median {r['global_median_pp_day']:.0f} catalog {r['catalog_pp_day']}")
        print({k: v for k, v in trips.items() if k != "trips"})
        print("confidence:", dict(conf))
    return bundle


if __name__ == "__main__":
    train()
