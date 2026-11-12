"""
JourneyAI - bottom-up trip cost engine (pure functions over the trained parameter bundle).

    daily cost of a party = stay + food + local transport + entry fees / activities

Each part is priced from REAL listed prices (Wikivoyage traveller listings, ml/cost/train_cost_model.py)
through a hierarchical (destination -> area -> region -> India) log-space shrinkage estimate. Travel style
does not multiply anything: it SELECTS which real price tier each part is read from (see STYLE_RULES).

What is deliberately NOT priced: getting to the destination (flights / long-distance trains), shopping,
and seasonal surcharges - the real data has no seasonal price signal, so `season`/`month` are accepted
but do not change the estimate (the response says so).
"""
import math

import numpy as np

STYLES = ("backpacker", "budget", "mid-range", "luxury", "family", "solo", "adventure")
CORE_STYLES = ("budget", "mid-range", "luxury")

# Structural assumptions (not estimated from data; see docs/COST_MODEL.md "Assumptions").
STRUCT = {
    "meals_per_day": 3,        # breakfast, lunch, dinner
    "rides_per_day": 4,        # local rides per person per day (two outings, there and back)
    "sights_per_day": 2,       # paid entries per person per day
    "room_capacity": 2,        # a standard Wikivoyage room price is quoted for a double
    "family_room_capacity": 4,
    "auto_capacity": 3,        # auto-rickshaw seats
    "taxi_capacity": 4,
}

# Each style selects a price tier per component. 'meals' = (budget, mid, splurge) meals out of 3.
STYLE_RULES = {
    "backpacker": {"stay": "bed", "meals": (3, 0, 0), "transport": "bus", "fees": "q25"},
    "budget": {"stay": "budget", "meals": (3, 0, 0), "transport": "bus", "fees": "q25"},
    "mid-range": {"stay": "mid", "meals": (0, 3, 0), "transport": "auto", "fees": "q50"},
    "luxury": {"stay": "splurge", "meals": (0, 2, 1), "transport": "taxi", "fees": "q75"},
    "family": {"stay": "mid", "meals": (0, 3, 0), "transport": "auto", "fees": "q50", "family_room": True},
    "solo": {"stay": "budget", "meals": (0, 3, 0), "transport": "auto", "fees": "q50"},
    "adventure": {"stay": "mid", "meals": (0, 3, 0), "transport": "auto", "fees": "adventure"},
}

CATS = ("food", "transport", "accommodation", "shopping", "fees_misc")
N_MC = 2000
_RNG = np.random.default_rng(20260101)
_Z = _RNG.standard_normal((N_MC, 6))      # fixed draws -> deterministic bands


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


def clean_inputs(duration_days, party_size, style):
    def to_int(v, lo, hi, dflt):
        try:
            f = float(v)
            if math.isnan(f) or math.isinf(f):
                return dflt
            return max(lo, min(hi, int(f)))
        except (TypeError, ValueError):
            return dflt
    days = to_int(duration_days, 1, 60, 5)
    party = to_int(party_size, 1, 20, 1)
    style = str(style or "mid-range").strip().lower()
    if style == "mid range" or style == "midrange":
        style = "mid-range"
    if style not in STYLE_RULES:
        style = "mid-range"
    return days, party, style


def dest_params(bundle, destination, region=None):
    """-> (params dict {sleep:(mu,var), eat:..., fee:...}, n_own dict, pooled_from, region_used)."""
    rec = bundle["dest"].get(destination)
    if rec:
        return rec
    reg = region if region in bundle["region_prior"] else None
    prior = bundle["region_prior"][reg] if reg else bundle["india_prior"]
    return {"sleep": (prior["sleep"][0], prior["sleep"][1], 0), "eat": (prior["eat"][0], prior["eat"][1], 0),
            "fee": (prior["fee"][0], prior["fee"][1], 0),
            "area": reg or "India", "region": reg or "India"}


def confidence_of(n_total):
    if n_total <= 0:
        return "regional estimate"
    if n_total < 5:
        return "low"
    if n_total < 15:
        return "medium"
    return "high"


def _stay_level_delta(bundle, level):
    return 0.0 if level == "mid" else bundle["delta"]["sleep"][level][0]


def _stay_level_se(bundle, level):
    return 0.0 if level == "mid" else bundle["delta"]["sleep"][level][1]


def price_day(bundle, rec, style, party, with_band=True):
    """Daily cost of the whole party in INR split by component, plus an 80% band."""
    rule = STYLE_RULES[style]
    S = bundle["struct"]
    mu_s, v_s, _ = rec["sleep"]
    mu_e, v_e, _ = rec["eat"]
    mu_f, v_f, _ = rec["fee"]
    sw, ew, fw = bundle["sigma_w"]["sleep"], bundle["sigma_w"]["eat"], bundle["sigma_w"]["fee"]

    # ---- stay
    lvl = rule["stay"]
    unit = math.exp(mu_s + _stay_level_delta(bundle, lvl))
    if lvl == "bed":
        stay = party * unit                                   # a dorm bed per person
    else:
        cap = S["room_capacity"]
        if rule.get("family_room") and party >= 3:
            cap = S["family_room_capacity"]
            unit *= bundle["family_premium"]
        stay = math.ceil(party / cap) * unit
    s_stay = bundle["pred_sd"]["sleep"]["known" if rec["sleep"][2] > 0 else "unseen"]

    # ---- food (3 meals a day, each read from the tier given by the style)
    nb, nm, ns = rule["meals"]
    de = bundle["delta"]["eat"]
    meal = (nb * math.exp(mu_e + de["budget"][0]) + nm * math.exp(mu_e) + ns * math.exp(mu_e + de["splurge"][0]))
    food = party * meal
    s_food = bundle["pred_sd"]["eat"]["known" if rec["eat"][2] > 0 else "unseen"]

    # ---- local transport (India-wide real fares; vehicles by seat capacity)
    mode = rule["transport"]
    fare = bundle["fares"][mode]["median"]
    if mode == "bus":
        trans = S["rides_per_day"] * party * fare
    else:
        cap = S["auto_capacity"] if mode == "auto" else S["taxi_capacity"]
        trans = S["rides_per_day"] * math.ceil(party / cap) * fare
    s_trans = bundle["fares"][mode]["sd_log"]

    # ---- entry fees / activities
    fo = bundle["fee_off"]
    if rule["fees"] == "adventure":
        fee = math.exp(mu_f) + math.exp(mu_f + bundle["delta"]["fee"]["do"][0])   # one sight + one activity
    else:
        fee = S["sights_per_day"] * math.exp(mu_f + fo[rule["fees"]])
    fees = party * fee
    s_fee = bundle["pred_sd"]["fee"]["known" if rec["fee"][2] > 0 else "unseen"]

    # Current-price adjustment: Wikivoyage listing prices are dated and omit incidentals; the factor is
    # calibrated on real trip reports (train_cost_model.calibrate_price_factor) and scales every component.
    f = bundle.get("price_factor", 1.0)
    stay, food, trans, fees = stay * f, food * f, trans * f, fees * f
    comp = {"accommodation": stay, "food": food, "transport": trans, "fees_misc": fees}
    total = sum(comp.values())
    out = {"components": comp, "total": total}
    if with_band:
        rho = bundle["rho"]
        zc = _Z[:, 4:5]
        zi = _Z[:, :4]
        z = math.sqrt(rho) * zc + math.sqrt(1 - rho) * zi
        sd = np.array([s_stay, s_food, s_trans, s_fee])
        base = np.array([stay, food, trans, fees])
        # lognormal draw centred on the median point (shared 'affordability' factor via rho)
        sim = (base[None, :] * np.exp(z * sd[None, :])).sum(axis=1)
        sim = sim * np.exp(_Z[:, 5] * bundle.get("price_factor_sd", 0.0))     # uncertainty of the calibrated factor
        sim = sim * (total / float(np.median(sim)))        # centre the band on the point estimate (its median)
        out["low"] = float(np.quantile(sim, 0.10))
        out["high"] = float(np.quantile(sim, 0.90))
    return out


def predict(bundle, destination, duration_days=5, travel_style="mid-range", season=None, month=None,
            party_size=1, region=None):
    days, party, style = clean_inputs(duration_days, party_size, travel_style)
    rec = dest_params(bundle, destination, region)
    d = price_day(bundle, rec, style, party)
    return predict_from_day(bundle, destination, rec, d, days, style, party)


def predict_from_day(bundle, destination, rec, d, days, style, party):
    total = d["total"] * days
    low = max(0.0, min(d["low"] * days, total))
    high = max(d["high"] * days, total)
    comp = {k: v * days for k, v in d["components"].items()}
    breakdown = {k: int(round(comp.get(k, 0.0))) for k in CATS}
    drift = int(round(total)) - sum(breakdown.values())
    if drift:
        breakdown[max(("accommodation", "food", "transport", "fees_misc"), key=lambda k: breakdown[k])] += drift
    n_own = rec["sleep"][2] + rec["eat"][2] + rec["fee"][2]
    conf = confidence_of(n_own)
    area = rec.get("area")
    if rec.get("is_international"):
        conf = "regional estimate"
    return {
        "destination": destination,
        "predicted_cost": int(round(total)),
        "low": int(round(low)),
        "high": int(round(high)),
        "per_day": int(round(total / days)),
        "per_person_per_day": int(round(total / days / party)),
        "duration_days": days,
        "party_size": party,
        "travel_style": style,
        "cost_breakdown": breakdown,
        "currency": "INR",
        "confidence": conf,
        "n_observations": int(n_own),
        "n_stay_observations": int(rec["sleep"][2]),
        "estimate_basis": ("destination price reports" if n_own > 0 else
                           ("pooled from " + str(area) if area and area != "India" else "India-wide price reports")),
        "season_adjustment": "none: the real price data has no seasonal signal",
        "excludes": "travel to the destination, shopping, and seasonal surcharges",
    }


def style_table(bundle, destination, party_size=1, region=None):
    """Per-person daily cost for each core style, with 80% band and evidence counts (for destination pages)."""
    rec = dest_params(bundle, destination, region)
    n_own = rec["sleep"][2] + rec["eat"][2] + rec["fee"][2]
    out = {"styles": {}, "n_observations": int(n_own), "n_stay_observations": int(rec["sleep"][2]),
           "confidence": confidence_of(n_own)}
    for s in STYLES:
        d = price_day(bundle, rec, s, max(1, party_size))
        p = max(1, party_size)
        out["styles"][s] = {"per_person_per_day": int(round(d["total"] / p)),
                            "low": int(round(d["low"] / p)), "high": int(round(d["high"] / p))}
    return out
