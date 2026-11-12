# Cost model: what changed, what data it uses, how it is validated

Code: `ml/cost/` (`build_observations.py`, `train_cost_model.py`, `engine.py`, `predict.py`, `refresh_base_costs.py`).
Tests: `tests/cost_test.py` (41 checks). Stored report: `ml/artifacts/cost_model_report.json`
(`ml.eval.cost_eval` prints it).

## 1. What changed and why

The previous model was a gradient-boosted regressor trained on `journals.true_total`. For every imported journal
`true_total == destinations.base_daily_cost x duration_days` (ratio median 1.000 for every travel style, std 0.11),
because `ml/seed/load_real_entries.py` writes the catalog price x days into the expense columns. The model therefore
re-learned the catalog's own base price: the "R2 0.9999" was a circularity artefact, and travel style had no effect
(Jaipur was about Rs 2,399/day for budget, mid-range and luxury alike). `base_daily_cost` is itself a hand-set tier guess.

The replacement is a bottom-up model built only from real traveller-listed prices:

    daily cost of a party = stay + food + local transport + entry fees / activities

Travel style does not multiply anything. It selects which real price tier each part is read from.
`destinations.base_daily_cost` is now derived from the model (`ml/cost/refresh_base_costs.py`), and nothing shown to
users comes from the circular journals any more (section 6).

## 2. Data audit

Counts below are exact, from the repo and the `project` database.

| Source | Rows | Independent real cost data? | Used |
|---|---|---|---|
| Wikivoyage listings, `ml/data/cost_obs_tiered.json` (re-parsed from the 1,422 cached articles in `ml/data/raw/wikivoyage/`, keeping the article section) | 2,148 priced listings: 1,527 stay, 234 eat, 258 see, 98 do, 31 drink; 338 destinations | Yes: prices travellers/editors reported. 946 stay and 75 eat listings carry the editors' own Budget / Mid-range / Splurge sub-heading | Training |
| Wikivoyage "Get around" fares, `ml/data/cost_obs_transport.json` | 84 flat local-ride fares (34 bus/metro, 38 auto-rickshaw, 12 taxi) in 68 articles | Yes, but thin | Training (India-wide only) |
| Family-room vs double-room price pairs inside listings | 6 | Yes, very thin | Training (one factor) |
| `ml/data/india_cost_observations.json` | 2,148 (same listings, section dropped) | Yes | Superseded by the tiered file (identical counts) |
| `ml/data/blog_cost_obs.json` | 41 regex extractions | Mostly no: component prices (a bike rental, a hotel night, a "total food cost for 15 days") and mis-parsed ranges, many from one Leh article | Not used directly; the whole-trip totals inside the source texts were re-read by hand |
| `ml/data/tripoto_reports.json`, `real_entries.json`, `blog_entries.json` | 221 / 1,194 / 396 | Text and metadata only, no usable cost fields | Not used for costs |
| Journals in MySQL (`journals` view), 1,381 rows | 1,186 `wikivoyage@real.journeyai`, 194 `blog@real.journeyai`, 1 real user | **No for 1,380**: their expense columns are `base_daily_cost x days` (derived). **Yes for 1**: "Monsoon In Matheran" by a real user (Rs 43,000, 3 days) | Excluded from training. The 1 real journal is a validation report |
| Real trip totals, `ml/data/real_trip_totals.json` | 5 (4 Tripoto reports + the Matheran journal), each with a quoted evidence line and the itemised travel-to-destination removed | Yes, independent | Validation, and it calibrates one parameter (section 4.6) |

Nothing synthetic is used anywhere. The previous `@seed.journeyai` corpus is not present in the database.

## 3. Method

All prices are modelled in log space. Observations: `y = log(INR)`, winsorised at the 1st and 99th percentile.

### 3.1 Tier effects (data-derived, not invented)

Within-destination fixed-effects regression of `y` on level dummies (baseline = the mid level), using only destinations that
show at least two levels. Result (log scale vs mid-range, standard error in brackets):

| Component | budget | splurge | unlabelled | dorm bed | do vs see |
|---|---|---|---|---|---|
| Stay (n = 1,046 rows used) | -1.242 (0.056) = x0.29 | +0.900 (0.061) = x2.46 | -0.979 (0.104) | -1.461 (0.133) = x0.23 | |
| Meals (n = 109 rows used) | -0.911 (0.306) = x0.40 | +1.546 (0.348) = x4.69 | -0.151 (0.246) | | |
| Entry fees | | | | | +0.702 (0.209) = x2.0 |

Entry-fee tiers use the real within-destination spread: lower quartile -0.64, upper quartile +0.64 (log). Meal tier effects
rest on only 75 labelled listings and are noisy (standard errors above).

### 3.2 Hierarchical destination estimate

For each component, after removing the tier effect from every listing, a destination's "mid-tier-equivalent" log price is
shrunk toward its parent pool (destination -> Wikivoyage area ancestors -> region -> India), all pools computed
leave-the-destination-out:

    prior_0 = India mean
    prior_j = prior_{j-1} + N_j/(N_j + k_pool) * (mean_j - prior_{j-1})       # each ancestor, top-down
    est_d   = prior + n_d/(n_d + k_leaf) * (mean_d - prior)

`k_pool`, `k_leaf` are chosen by 5-fold cross-validation on held-out real listings: stay (16, 2), meals (4, 4), fees (16, 4).
Destinations with no listings get `prior` only and are labelled "regional estimate".

### 3.3 Daily cost and style rules

| Style | Stay | Meals (3/day) | Local rides (4/day) | Entry fees (2/day) | Justification |
|---|---|---|---|---|---|
| backpacker | dorm bed, per person | 3 budget | bus / metro fare | lower quartile | bed level is a measured tier |
| budget | budget room | 3 budget | bus / metro | lower quartile | Budget sub-heading |
| mid-range | mid room | 3 mid | auto-rickshaw | median | baseline |
| luxury | splurge room | 2 mid + 1 splurge | taxi | upper quartile | Splurge sub-heading; one splurge dinner a day is an assumption |
| family | mid room, family room (x1.39, n=6) once the party is 3+, 4 per room | 3 mid | auto | median | family premium measured from 6 listings |
| solo | budget room (pays it alone) | 3 mid | auto | median | assumption: solo travellers choose guesthouses |
| adventure | mid room | 3 mid | auto | one sight + one "do" activity (x2.0) | do-vs-see effect is measured |

Party size: rooms = ceil(party / 2) (a Wikivoyage room price is for a double; family rooms hold 4); food and fees per person;
vehicles by seat capacity (auto 3, taxi 4, bus per person). Duration: linear (nights = days); the data shows no
duration discount, so none is applied. Season/month: accepted but ignored, because the real data has no seasonal price
signal; every response says so (`season_adjustment`).

Local fares are India-wide medians (bus Rs 10, auto Rs 50, taxi Rs 564; bootstrap 95% CI 7-20, 20-70, 150-1,100). That
component is the weakest (n = 34 / 38 / 12) and has no destination effect.

Not priced: getting to the destination, shopping, seasonal surcharges. The `shopping` key stays in `cost_breakdown` at 0.

### 3.4 Current-price factor

Wikivoyage prices are dated and omit incidentals. On the 5 real trip reports the raw model predicts a median 0.55 of the
actual per-person-per-day spend (every report is under-predicted). One multiplicative `price_factor` = geometric mean of
actual/raw = **1.89** (log-sd 0.175) scales every component. It is fitted on the 5 reports, is therefore only as good as
n = 5, and its uncertainty is carried into the band. Re-fit it by adding verified reports to
`ml/data/real_trip_totals.json` and retraining.

### 3.5 Bands

Monte-Carlo (2,000 fixed draws) of lognormal components, with the log-sd of each component set to the measured
held-out listing error (stay 0.66 known destination / 0.73 unseen; meals 0.71 / 0.82; fees 1.28 / 1.32; fares from the
fare spread), a shared "affordability" correlation of 0.35 (the measured correlation between destinations' stay and meal
effects), plus the price-factor uncertainty. Low/high are the 10th/90th percentile, centred on the point estimate.
They are wide on purpose: individual hotel prices vary by a factor of about 2 within a tier.

### 3.6 Confidence

`n_observations` = the destination's own stay + meal + fee listings. `high` >= 15, `medium` 5-14, `low` 1-4,
`regional estimate` = 0 (uses area/region/India pools). Of 618 modelled Indian places: 30 high, 84 medium, 223 low, 284 regional.
International rows in the catalog (32) have no data and are flagged `regional estimate` with India-wide prices; they are not served.

## 4. Validation

### 4.1 Real listings, destination known (5-fold, n = 946 stay / 75 meal tier-labelled listings)

| | MAPE model | MAPE global tier median | MAE model | MAE baseline | median abs log error model / baseline |
|---|---|---|---|---|---|
| Stay | 67% | 76% | Rs 1,404 | Rs 1,656 | 0.43 / 0.49 |
| Meal | 66% | 109% | Rs 217 | Rs 269 | 0.44 / 0.61 |

### 4.2 Real listings, destination never seen (leave-one-destination-out, the regional-estimate case)

| | MAPE model | MAPE global median | MAPE region median | median abs log error (model / global / region) |
|---|---|---|---|---|
| Stay | 78% | 76% | 80% | 0.47 / 0.49 / 0.53 |
| Meal | 74% | 107% | 89% | 0.66 / 0.64 / 0.64 |

For unseen places the model is **not meaningfully better than a global median**: knowing the region/area adds almost nothing
for a single hotel price (between-destination spread is small next to within-destination spread). That is why such places
are labelled "regional estimate" and why `k_pool` is large. Nominal 80% listing bands cover 87-93% (slightly conservative).

### 4.3 Real trip reports (n = 5, per person per day, each predicted with the factor fitted on the other four)

| Report | Style used | Actual | Raw model | Leave-one-out | Served 80% band |
|---|---|---|---|---|---|
| Khajuraho bike trip (2 people, 3 days) | budget (room quote Rs 999) | 1,007 | 553 | 1,055 | 549-2,914 |
| Kanpur weekend (4 people, 2 days) | budget (quote) | 1,000 | 528 | 996 | 519-2,876 |
| Manali low-budget vlog (6, 4 days) | budget (declared) | 1,250 | 736 | 1,427 | 734-3,798 |
| Leh MakeMyTrip package (2, 7 nights) | mid-range (default) | 4,396 | 1,777 | 3,137 | 1,752-8,845 |
| Matheran, real user journal (1, 3 days) | mid-range (quote Rs 2,833) | 5,500 | 3,315 | 6,465 | 2,969-17,677 |

| | MAE | MAPE |
|---|---|---|
| Raw model (no factor) | Rs 1,249 | 46.5% |
| **Calibrated, leave-one-report-out** | **Rs 491** | **13.1% (95% bootstrap interval 4.7-21.7%)** |
| Naive: median of the other reports' spend | Rs 2,546 | 126.5% |
| Old catalog price (3 reports that had one) | Rs 1,164 | 107.4% (model on same 3: 6.4%) |
| Region-median baseline | not computable: no two reports share a region |

**n = 5 is far too small for statistical claims.** The interval is shown to make that visible; it is also optimistic because
the one fitted factor was tuned on the same five reports (leave-one-out removes only part of that). The style for each report
was fixed by a rule before totals were examined: author-declared style, else the tier nearest the quoted room price, else
mid-range. All 5 actual figures fall inside the served 80% band; 3 of 5 fall between the budget and luxury estimates.

### 4.4 Acceptance checks (`tests/cost_test.py`, 41 checks, all pass)

budget < mid-range < luxury for all 436 served destinations; backpacker <= budget; solo between budget and mid-range;
479,607 fuzz predictions (every style incl. junk, party sizes -4..99 and non-numbers, days 0..1000/NaN/text, 436 destinations)
are positive, finite, low <= estimate <= high, breakdown sums to total, total = per_day x days; party scaling and linearity in
days; month has no effect; confidence/`n_observations` present; `/predict-cost`, `/recommend` (budget ranking and fit flags)
and the PHP pages behave and no longer make the old claims.

**Deviation from the suggested acceptance range:** the luxury/budget per-day ratio is **min 8.3, median 9.4, max 11.2**,
not 1.5-6. This is what the real tiers give: Splurge stays are x8.5 the Budget stays (x2.46 / x0.29) and a luxury day adds a
taxi and a splurge dinner. The test therefore requires >= 1.5 and p95 <= 12. Compressing it would need a hand-picked
definition of "luxury" (e.g. an upper quartile instead of the Splurge sub-heading); it was not done to hit a target.

## 5. Limitations

- Only 5 real trip reports (all budget or mid-range) validate totals; the current-price factor rests on them. Luxury has no
  trip-level validation at all.
- Wikivoyage prices are dated and uneven in coverage: 284 of the 618 modelled places have no listing of their own.
- Local transport is India-wide (84 fares), not per destination. Taxi (n = 12) is very uncertain and drives luxury.
- Meal tier effects come from 75 labelled listings; family-room premium from 6.
- Structural assumptions not estimated from data: 3 meals, 4 rides, 2 paid sights per day, 2 per room, one splurge dinner for
  luxury, solo = budget stay. Changing rides/day by one moves mid-range daily cost by about Rs 95 (one auto fare x 1.89) (`engine.STRUCT`).
- A solo traveller pays a whole room, so default one-traveller figures are higher than per-person figures for a couple.
- No seasonality, no duration discount, no shopping, no travel to the destination, India only.
- The log-normal band reflects listing price variation, not model error on a particular trip.

## 6. What no longer shows circular prices

- `/health` and `analytics.php`: the "R squared / measured on a fifth of the journals" block is replaced by the numbers above.
- `/analytics/summary` and `/analytics/personal`: the community cost charts, cheapest months, best value, cost ranges and community
  average now exclude `@real.journeyai` and `@seed.journeyai` journals (their costs are derived), so they stay empty until real
  travellers log trips.
- `destinations.base_daily_cost` (used by the recommender's similarity feature, the destination list and the index page) is
  refreshed from the model: one traveller, mid-range, per day, rounded to Rs 50 (546 India rows: min 3,050, median 6,750,
  max 17,450). 32 international rows with no data are set to NULL. `dest_profiles.pkl` is patched in place.
  `ml/rebuild_all.py` runs the refresh after training.
- `ml/bot/smartplan.py` no longer falls back to a hand-set Rs 1,500/2,500 per day.
- The profiles' `avg_true_total` / `avg_daily` (derived from the imported journals) are still computed by
  `ml/nlp/build_profiles.py` but are not displayed anywhere.
- `ml/notebooks/05_cost_model_training.ipynb` still contains the old executed outputs; regenerate it from
  `ml/notebooks/_build_notebooks.py` when needed.

## 7. Rebuild

    ml/venv/Scripts/python.exe -m ml.cost.build_observations   # only if ml/data/raw/wikivoyage changed
    ml/venv/Scripts/python.exe -m ml.cost.train_cost_model
    ml/venv/Scripts/python.exe -m ml.cost.refresh_base_costs
    # restart the ML service, then: ml/venv/Scripts/python.exe tests/cost_test.py
