"""Section 6 of the master guide: the cost model. Written from ml/cost/ and the validation report stored beside the trained artifact
(ml/artifacts/cost_model_report.json), so every number here is read from that file at build time."""
import json
import math
import os
from guide_lib import *  # noqa: F401,F403
from reportlab.platypus import Spacer
from guide_content import sec, nf
import guide_diagrams as G
from reportlab.lib import colors
WHITE = colors.white
MEL_BG = colors.HexColor("#FDE8EC")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPORT = os.path.join(ROOT, "ml", "artifacts", "cost_model_report.json")
DOC = os.path.join(ROOT, "docs", "COST_MODEL.md")


def _rep():
    with open(REPORT, encoding="utf-8") as f:
        return json.load(f)


def collect(M, http_json):
    r = _rep()
    tv = r["trip_validation"]
    sl, ea = r["listing_validation_5fold_known_destination"]["sleep"], r["listing_validation_5fold_known_destination"]["eat"]
    rows = [["Cost model: real-trip error", f"{tv['model_calibrated_leave_one_out']['MAPE_pct']:.1f} % MAPE leave-one-out on {tv['n']} real trip reports (95 % interval "
             f"{tv['model_calibrated_leave_one_out']['MAPE_ci95'][0]:.1f} to {tv['model_calibrated_leave_one_out']['MAPE_ci95'][1]:.1f} %)", "6.7", "artifacts/cost_model_report.json"],
            ["Cost model: held-out listings", f"stays {sl['model']['MAPE_pct']:.1f} % vs {sl['global_median']['MAPE_pct']:.1f} % baseline; meals {ea['model']['MAPE_pct']:.1f} % vs {ea['global_median']['MAPE_pct']:.1f} %", "6.7", "5-fold, destination known"]]
    return {"cost_rows": rows, "cost_eval_summary": f"{tv['model_calibrated_leave_one_out']['MAPE_pct']:.1f} % MAPE on {tv['n']} real trip reports (leave-one-out); "
            f"{sl['model']['MAPE_pct']:.0f} % MAPE on held-out stay prices (baseline {sl['global_median']['MAPE_pct']:.0f} %)", "cost_report": r}


def _fig(M):
    r = M["cost_report"]
    t = r["trained_on"]
    bt = t["by_type"]

    def draw(c, w, h):
        label(c, 10, h - 14, "REAL OBSERVATIONS", 7, GREEN, SANS_B)
        label(c, 138, h - 14, "ESTIMATE PER DESTINATION", 7, GREEN, SANS_B)
        label(c, 318, h - 14, "ASSEMBLE ONE DAY, THEN THE TRIP", 7, GREEN, SANS_B)
        ys = [h - 70, h - 118, h - 166, h - 214]
        items = [("Stays", f"{nf(bt.get('sleep', 0))} listings, {nf(t['tier_labelled_sleep'])} tier-labelled"),
                 ("Meals", f"{nf(bt.get('eat', 0) + bt.get('drink', 0))} listings, {t['tier_labelled_eat']} tier-labelled"),
                 ("Sights and activities", f"{nf(bt.get('see', 0) + bt.get('do', 0))} listings"),
                 ("Local fares", f"{t['local_fares']} real bus, auto, taxi fares")]
        for y, (a, b) in zip(ys, items):
            box(c, 8, y, 112, 42, a, b, fill=WHITE, ssize=6.6)
            arrow(c, 120, y + 21, 138, h - 81, col=GREEN, head=4)
        # shrinkage chain
        box(c, 138, h - 134, 160, 106, "", None, fill=SAND, stroke=MELON)
        chain = ["India (all listings)", "region", "Wikivoyage area", "the destination itself"]
        for i, nm in enumerate(chain):
            yy = h - 52 - i * 22
            box(c, 150, yy - 8, 136, 16, nm, fill=WHITE, stroke=MELON, tsize=7.2, bold=False)
            if i:
                arrow(c, 218, yy + 14, 218, yy + 8.5, col=MELON, head=3.5)
        label(c, 218, h - 20, "pool, partially, from the top down", 6.6, MELON, SANS_I, "c")
        box(c, 138, h - 200, 160, 62, "Tier effects", "budget / mid / splurge, bed, sight vs activity: within-destination regression on the editors' own section labels", fill=MINT, ssize=6.6)
        box(c, 138, h - 258, 160, 52, "Confidence", f"high {r['trained_on']['confidence_counts'].get('high', 0)}, medium {r['trained_on']['confidence_counts'].get('medium', 0)}, low {r['trained_on']['confidence_counts'].get('low', 0)}, regional estimate {r['trained_on']['confidence_counts'].get('regional estimate', 0)} places", fill=WHITE, ssize=6.6)
        box(c, 318, h - 80, 150, 50, "Daily cost of the party", "stay + food + local transport + fees; style picks the tier", fill=SAND, stroke=MELON, ssize=6.6)
        box(c, 318, h - 130, 150, 36, "x price factor", f"{r['trip_validation']['price_factor']:.2f}, set on {r['trip_validation']['n']} real trip reports", fill=WHITE, ssize=6.6)
        box(c, 318, h - 180, 150, 38, "Monte Carlo band", "2,000 fixed draws, 10th to 90th percentile", fill=WHITE, ssize=6.6)
        box(c, 318, h - 232, 150, 36, "x days = trip total", "plus low, high and category split", fill=DEEP, stroke=DEEP, tcol=CREAM, scol=CREAM, ssize=6.6)
        for a, b in ((h - 80, h - 94), (h - 130, h - 142), (h - 180, h - 196)):
            arrow(c, 393, a, 393, b + 0.5, col=DEEP, head=3.5)
        arrow(c, 298, h - 81, 318, h - 55, col=GREEN, head=4)
    return draw


def build(M):
    r = M["cost_report"]
    tv = r["trip_validation"]
    t = r["trained_on"]
    lodo, kf = r["listing_validation_leave_one_destination_out"], r["listing_validation_5fold_known_destination"]
    te = r["tier_effects_log"]
    ex = lambda x: math.exp(x)
    have_doc = os.path.exists(DOC)
    o = sec(6, "Cost model", "Priced bottom-up from real listed prices, with an honest band and a validation that cannot be circular.")
    o += [callout("The cost model was rebuilt shortly before this guide. This section describes the <b>new</b> model, written from the code in ml/cost/ and the validation report "
                  "stored with the trained artifact (ml/artifacts/cost_model_report.json). "
                  + ("The full write-up is in docs/COST_MODEL.md. " if have_doc else "The companion write-up docs/COST_MODEL.md did not exist yet when this guide was built, so nothing here is copied from it. ")
                  + "The running service serves this model.", "note", "STATUS"),
          H2("6.1  Why the first model was discarded"),
          P("The first cost model was a gradient-boosted regressor trained on each journal's total cost. For every imported journal that total was the destination's catalog base price times the number of days "
            "(Section 3), so the model re-learned the catalog's own guess. Its headline R-squared of 0.9999 measured agreement with that guess, not accuracy, and travel style could not matter because it was "
            "never observed (Jaipur cost about Rs 2,399 a day for budget, mid-range and luxury alike; across imported journals the ratio of total to catalog price x days had a median of 1.000 and a standard deviation of 0.11, per docs/COST_MODEL.md). <b>That model and its numbers are not valid evidence of cost accuracy and are not used anywhere in this guide.</b> The report now records this "
            "(<font name='Mono'>circular_r2_removed</font>)."),
          H2("6.2  The new idea in one paragraph"),
          P("Do not predict a total from journals. Price what a traveller actually buys, from real listed prices: a night's stay, three meals, local rides and entry tickets, then add them up for the "
            "party. Each of those four parts is estimated per destination from Wikivoyage listings, borrowing strength from the surrounding region where a place has few listings. "
            "Travel style does not multiply anything; it <i>selects which real price tier</i> each part is read from (a budget room, a mid-range meal)."),
          figure(272, _fig(M), "Figure 6.1  The cost model. Counts are read from the training report."),
          H2("6.3  Data"),
          *table(["Input", "Amount", "Used for"],
                 [["Wikivoyage listings with a parsed rupee price, with the article section kept (budget / mid-range / splurge, sights, activities)", f"{nf(t['observations'])} observations at {t['destinations_with_data']} destinations",
                   "stay, meal and fee prices per destination; tier effects"],
                  ["Real local-ride fares (bus, auto-rickshaw, taxi)", f"{t['local_fares']} fares", "local transport, India-wide medians with bootstrap intervals"],
                  ["Family-room versus double-room pairs in the same listing", f"{t['family_room_pairs']} pairs", "a family-room premium (median ratio)"],
                  ["Real, independently reported trip totals (hand-read from Tripoto trip reports and one member journal)", f"{tv['n']} trips", "<b>validation, and one calibration parameter only</b>; never used to fit prices"]],
                 [3.6, 1.8, 2.3]),
          H2("6.4  Estimation: partial pooling down a hierarchy"),
          P("Listing prices are noisy and unevenly spread: some destinations have dozens, many have none. For each of the three priced components (stay, meal, sight) the model works in log price "
            "and combines a destination's own mean with its surroundings, from the broadest group to the narrowest: all India, then the region, then each Wikivoyage area on the path to the destination, then the "
            "destination. Each step moves the estimate toward the more local mean by a weight that grows with the number of listings there; places with no listings keep the regional or India-wide estimate and are labelled as such."),
          formula(["prior   = mean over India",
                   "for each node on the path (region, then areas, top-down):",
                   "    prior = prior + n_node / (n_node + k_pool) x ( mean_node - prior )",
                   "estimate = prior + n_dest / (n_dest + k_leaf) x ( mean_dest - prior )",
                   "variance of the estimate = sigma_within^2 / (n_dest + k_leaf)"], "empirical-Bayes shrinkage (per component, log price)"),
          P("The node means exclude the destination's own listings, so the estimate for a place never leans on itself. The two strengths <font name='Mono'>k_pool</font> and <font name='Mono'>k_leaf</font> are not set by hand: "
            "they are chosen from the grid 0.25, 0.5, 1, 2, 4, 8, 16 by 5-fold cross-validation, minimising squared error on held-out listings (chosen: stay k_pool 16, k_leaf 2; meals 4, 4; fees 16, 4, as recorded in docs/COST_MODEL.md). Large values pull a small sample hard toward its neighbours; small values trust the data."),
          H2("6.5  Style as price tier, not a multiplier"),
          P("Within each destination the editors file hotels and restaurants under Budget, Mid-range and Splurge headings. A regression of log price on tier dummies <i>within</i> destination (so destination wealth cancels out) "
            "gives the real size of each step. Nothing is assumed."),
          *table(["Component and tier", "Effect on log price (standard error)", "Price multiple vs mid-range"],
                 [["Stay: budget", f"{te['sleep']['budget'][0]:+.2f} ({te['sleep']['budget'][1]:.2f})", f"x {ex(te['sleep']['budget'][0]):.2f}"],
                  ["Stay: splurge", f"{te['sleep']['splurge'][0]:+.2f} ({te['sleep']['splurge'][1]:.2f})", f"x {ex(te['sleep']['splurge'][0]):.2f}"],
                  ["Stay: dorm bed (per person)", f"{te['sleep']['bed'][0]:+.2f} ({te['sleep']['bed'][1]:.2f})", f"x {ex(te['sleep']['bed'][0]):.2f}"],
                  ["Meal: budget", f"{te['eat']['budget'][0]:+.2f} ({te['eat']['budget'][1]:.2f})", f"x {ex(te['eat']['budget'][0]):.2f}"],
                  ["Meal: splurge", f"{te['eat']['splurge'][0]:+.2f} ({te['eat']['splurge'][1]:.2f})", f"x {ex(te['eat']['splurge'][0]):.2f}"],
                  ["Activity versus sight (entry fee)", f"{te['fee']['do'][0]:+.2f} ({te['fee']['do'][1]:.2f})", f"x {ex(te['fee']['do'][0]):.2f}"]],
                 [3.0, 2.6, 2.0]),
          P("Meal and activity effects rest on far fewer labelled listings than stays (see the standard errors), so they are the least certain rows."),
          *table(["Style", "Stay", "Meals (of 3 a day)", "Local transport", "Entry fees"],
                 [["backpacker", "dorm bed per person", "3 budget", "bus", "lower quartile"],
                  ["budget", "budget room", "3 budget", "bus", "lower quartile"],
                  ["mid-range", "mid-range room", "3 mid", "auto-rickshaw", "median"],
                  ["luxury", "splurge room", "2 mid, 1 splurge", "taxi", "upper quartile"],
                  ["family", "mid-range, family room if 3 or more", "3 mid", "auto-rickshaw", "median"],
                  ["solo", "budget room", "3 mid", "auto-rickshaw", "median"],
                  ["adventure", "mid-range room", "3 mid", "auto-rickshaw", "one sight plus one activity"]],
                 [1.2, 2.4, 1.6, 1.4, 1.8]),
          H2("6.6  From prices to a trip total, and the band"),
          formula(["stay      = ceil(party / 2) x room price         (dorm: party x bed price; family room: capacity 4)",
                   "food      = party x ( sum of 3 meal prices from the style's tiers )",
                   "transport = 4 rides x ( party x bus fare | ceil(party/3) x auto fare | ceil(party/4) x taxi fare )",
                   "fees      = party x 2 sights x price at the style's quantile",
                   f"daily     = ( stay + food + transport + fees ) x price_factor          price_factor = {tv['price_factor']:.2f}",
                   "total     = daily x days"], "bottom-up assembly (ml/cost/engine.py)"),
          P(f"<b>Price factor.</b> Wikivoyage prices are dated and leave out incidentals, so listed prices run low. One multiplier, <b>{tv['price_factor']:.2f}</b>, is the geometric-mean ratio of real reported spend "
            f"to the uncalibrated prediction over the {tv['n']} real trip reports (clipped to 1 to 3). It is the only quantity those trips are allowed to set, and its uncertainty feeds the band."),
          P("<b>Band.</b> Each component gets a lognormal spread whose width was measured on held-out listings (wider for an unseen destination than a known one); the components share a correlation found from the data; "
            "the log-standard-deviations are, for known and unseen destinations, stays 0.66 and 0.73, meals 0.71 and 0.82, fees 1.28 and 1.32, and the shared correlation is 0.35 (all from docs/COST_MODEL.md); 2,000 fixed random draws are summed, and the 10th and 90th percentiles become the low and high estimates, recentred on the point estimate. Fixed draws make the band repeatable."),
          callout("Not priced, by design: getting to the destination (flights, long-distance trains), shopping, and seasonal surcharges. The real price data carries no seasonal signal, so the month "
                  "is accepted but changes nothing, and every response says so (<font name='Mono'>season_adjustment</font> and <font name='Mono'>excludes</font>). The API also returns a confidence label from the number of "
                  "listings behind the estimate: none = regional estimate, under 5 = low, under 15 = medium, otherwise high.", "caveat"),
          H2("6.7  Validation"),
          P("Because the model is built from listed prices, it is checked against prices and trips it did not see, with simple baselines beside it. Three layers:"),
          *table(["Check", "Model", "Baseline: median of other places", "Note"],
                 [[f"Stay prices, destination known (5-fold, {nf(kf['sleep']['n_listings'])} tier-labelled listings)", f"MAPE {kf['sleep']['model']['MAPE_pct']:.1f} %, MAE Rs {kf['sleep']['model']['MAE_inr']:,.0f}",
                   f"MAPE {kf['sleep']['global_median']['MAPE_pct']:.1f} %, MAE Rs {kf['sleep']['global_median']['MAE_inr']:,.0f}", "the destination's own data helps"],
                  [f"Meal prices, destination known (5-fold, {kf['eat']['n_listings']} listings)", f"MAPE {kf['eat']['model']['MAPE_pct']:.1f} %, MAE Rs {kf['eat']['model']['MAE_inr']:,.0f}",
                   f"MAPE {kf['eat']['global_median']['MAPE_pct']:.1f} %, MAE Rs {kf['eat']['global_median']['MAE_inr']:,.0f}", "small sample"],
                  [f"Stay prices, destination unseen (leave-one-destination-out)", f"MAPE {lodo['sleep']['model']['MAPE_pct']:.1f} %, median log error {lodo['sleep']['model']['median_abs_log_error']:.2f}",
                   f"MAPE {lodo['sleep']['global_median']['MAPE_pct']:.1f} %, median log error {lodo['sleep']['global_median']['median_abs_log_error']:.2f}", "no real gain by MAPE: an unseen town is roughly a regional guess"],
                  ["Meal prices, destination unseen", f"MAPE {lodo['eat']['model']['MAPE_pct']:.1f} %", f"MAPE {lodo['eat']['global_median']['MAPE_pct']:.1f} %", "75 listings only"]],
                 [2.6, 1.9, 1.9, 1.9]),
          *table(["Real trip reports", "Error per person per day", "Note"],
                 [[f"Model, uncalibrated (n = {tv['n']})", f"MAPE {tv['model_uncalibrated']['MAPE_pct']:.1f} %, MAE Rs {tv['model_uncalibrated']['MAE_inr']:,.0f}", "listed prices alone run low (median prediction/actual "
                   f"{tv['model_uncalibrated']['median_ratio_pred_over_actual']:.2f})"],
                  ["<b>Model, calibrated, leave-one-report-out</b>", f"<b>MAPE {tv['model_calibrated_leave_one_out']['MAPE_pct']:.1f} %</b>, MAE Rs {tv['model_calibrated_leave_one_out']['MAE_inr']:,.0f}; 95 % interval of MAPE "
                   f"{tv['model_calibrated_leave_one_out']['MAPE_ci95'][0]:.1f} to {tv['model_calibrated_leave_one_out']['MAPE_ci95'][1]:.1f} %", "each report predicted with a factor fitted on the other four"],
                  ["Baseline: median of the other reports", f"MAPE {tv['baseline_global_median_leave_one_out']['MAPE_pct']:.1f} %, MAE Rs {tv['baseline_global_median_leave_one_out']['MAE_inr']:,.0f}", "a no-model guess"],
                  [f"Old catalog price (3 reports that have one)", f"MAPE {tv['baseline_old_catalog_price']['MAPE_pct']:.1f} %", f"new model on the same 3: MAPE {tv['model_calibrated_loo_on_same_subset']['MAPE_pct']:.1f} %"],
                  ["80 % band contains the real spend", f"{tv['inside_band80']} of {tv['n']} reports", f"budget-to-luxury envelope contains it for {tv['inside_budget_to_luxury_envelope']} of {tv['n']}"]],
                 [2.6, 3.4, 2.3]),
          callout(f"Measured by ml/cost/train_cost_model.py (stored in cost_model_report.json; ml/eval/cost_eval.py prints it). <b>Read with caution:</b> five trip reports are far too few for a statistical claim, and the "
                  f"calibration factor of {tv['price_factor']:.2f} was set on those same reports (the leave-one-out figure refits it without the held-out report, but it is still tiny). "
                  "The model clearly beats the old circular catalog price and a naive median on these five, and it clearly helps for destinations with listings; for a town with no listings it is a regional estimate with a wide band. "
                  "Luxury has no trip-level validation at all (all five reports are budget or mid-range), and its estimate is about 9 times a budget day (8.3 to 11.2 across places): that is what the real Splurge-versus-Budget price gap gives, and it was not compressed to look more familiar. "
                  "The band on a new place is wide by construction (the standard deviation of the held-out log-price error is about 0.66 to 0.82), which is the honest width.", "measured"),
          H2("6.8  Where the model is used"),
          P("A separate acceptance suite, <font name='Mono'>tests/cost_test.py</font>, checks 41 properties (budget below mid-range below luxury for every served destination, about 480,000 fuzzed inputs all giving finite, ordered, self-consistent results, no effect of month) and passes 41 of 41. "
            "Community cost charts in the analytics endpoints now exclude the imported journals, whose costs are derived, so they stay empty until real travellers log trips."),
          *bullets(["The recommender calls it once for all destinations per request (batched, with a per-process cache of day costs) to decide budget fit (Section 5).",
                    "The itinerary generator and the trip page take the total, band and category split from it (Section 8); the destination guide shows costs for 1, 3, 5 and 7 days.",
                    "<font name='Mono'>ml/cost/refresh_base_costs.py</font> (run by rebuild_all after training) rewrites <font name='Mono'>destinations.base_daily_cost</font> from the model (mid-range, one traveller, per day, rounded to Rs 50; NULL where there is no India price data), so the "
                    "similarity feature (Section 7) and the cost model agree. International catalog rows have no real price data and are served as India-wide regional estimates, flagged."])]
    return o
