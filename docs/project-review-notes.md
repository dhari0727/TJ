# JourneyAI — Project Review Prep

## Role → Notebook mapping

| Roll No. | Role | Notebook(s) |
|---|---|---|
| **D24DCS162** | Data & Corpus | **01 — Synthetic Data Generation** (+ **05 — Geo/Places Data Collection**, supporting) |
| **D24DCS154** | NLP & Features | **02 — NLP Feature Extraction & Destination Profiles** |
| **D24DCS161** | Recommender | **04 — Hybrid Recommender** |
| **D24DCS163** | Cost & Serving | **03 — Cost Prediction Model** (+ **06 — Itinerary Generation**, bonus/serving-layer demo) |

Notebooks 05 and 06 weren't named in the original 4-way split — I assigned them by nearest fit
(05 is data curation, same spirit as 162's corpus work; 06 is built directly on 163's cost model
and would be demoed through their Flask microservice). Reassign if your team already has a
different understanding.

---

## Overall concept — the pitch

**Problem:** generic travel apps are either a black-box recommender with no reasoning, or a
plain data-entry journal with no intelligence behind it.

**JourneyAI's answer:** a travel journal that *feeds* its own AI. As people write trips
(costs, narratives, ratings), that data trains a genuinely explainable recommender and a cost
predictor — every recommendation says **why**, not just a score. All homegrown NLP, no black-box
API calls for the core intelligence.

**Say this in the opening slide:** *"Most travel apps either recommend blindly or just store
data. We built a system where the journal data trains the AI, and the AI can always explain
itself — in plain language, backed by real evidence."*

**Differentiators to hit:**
- End-to-end pipeline you built yourselves: raw text → structured features → trained models →
  explainable recommendations — no third-party ML API for the core intelligence.
- Every recommendation includes a plain-English reason traceable to real data (matched
  interests, similar travelers' ratings, real journal excerpts).
- Cost prediction gives a low/high **band**, not a single guess, plus a category breakdown.
- Fully reproducible: 6 real notebooks, real metrics, real trained artifacts — not slideware.

---

## Per-notebook talking points

### 01 — Synthetic Data Generation (D24DCS162)
- **Why synthetic:** no public dataset of India-focused travel journals + costs + ratings
  exists. Built a generator with *real structure*, not random noise.
- **What to show:** the 93-destination catalog (research-backed cost priors), how synthetic
  users get a latent "taste vector" that biases which destinations they write about, template
  narratives with sentiment-varied vocabulary (so the NLP model has real signal to find), and
  Dirichlet-distributed cost splits for realistic budget breakdowns. Deterministic seeding =
  reproducible.
- **Numbers to cite:** 93 destinations · 1,501 journals (1,500 synthetic + 1 real) · 41 users ·
  2,088 interactions.
- **One-liner:** *"We didn't fake random numbers — user taste correlates with destination
  choice, sentiment correlates with cost satisfaction, so every downstream model has genuine
  signal, not noise."*

### 02 — NLP Feature Extraction & Destination Profiles (D24DCS154)
- **Problem:** journal text is unstructured; need sentiment, activities, budget tier, travel
  style as structured features.
- **What to show:** hand-built sentiment lexicon with negation ("not amazing") and intensifier
  ("absolutely stunning") handling; a 100+ keyword activity gazetteer; TF-IDF vectorization;
  fuzzy destination-name normalization ("Bombay" → "Mumbai"); rule-based travel-style classifier.
- **Why it matters (be ready for this question):** *"Why not just use a transformer/LLM for
  sentiment?"* → Explainability: every tag traces to a specific rule or word match, no black box,
  zero inference cost, fully auditable.
- **Numbers to cite:** TF-IDF matrix ≈ 1,500 × 4,000 vocab · 93 destination profiles built.

### 03 — Cost Prediction Model (D24DCS163)
- **Problem:** users want a realistic, broken-down budget estimate before committing.
- **What to show:** GradientBoostingRegressor predicting **daily** cost (explain: removes trip
  length as a confound so the model learns destination/style/season effects cleanly), two extra
  quantile regressors for a low/high confidence band, per-destination category-split ratios for
  the UI donut chart.
- **Numbers to cite — know these cold:** **MAE ₹7,921 · R² 0.749 · MAPE 32.7%** on a held-out
  20% split. *"R²=0.75 means the model explains 75% of the variance in trip cost from just
  destination, style, season and duration — strong given the dataset spans backpacker to luxury
  travel."*
- **Also mention:** the trained model is served live via a Flask microservice (`ml/app.py`) to
  the PHP app, with a batched-prediction endpoint (~17x faster than per-destination calls) so the
  recommender can score all 93 destinations in one pass.

### 04 — Hybrid Recommender (D24DCS161)
- **Problem:** single-signal recommenders either ignore stated interests (pure collaborative) or
  ignore what similar travelers loved (pure content-based).
- **What to show:** the 3-signal blend — content (interest-activity cosine), semantic (TF-IDF
  cosine over narrative text), collaborative (item-item CF from real ratings) — plus a discovery
  boost for well-scoring "hidden gem" destinations. **The centerpiece: the explainability layer**
  turning a numeric blend into "We recommend X because it matches your interest in Y, travelers
  with similar taste rated it highly, and it fits your ₹Z budget" — backed by real sample journal
  titles, not a made-up sentence.
- **Numbers to cite:** hit-rate@5 ≈ 92% sanity check against users' real 5-star history (explain
  it's a sanity check, not a rigorous train/test split, if pressed).
- **Best demo move:** run 2-3 live queries and read out the generated explanations — this is the
  most visually/verbally impressive part of the whole system.

### 05 — Geo/Places Data Collection (D24DCS162, supporting, mention only if time allows)
- Not a trained model — real POI data (free OpenStreetMap, optional Geoapify) with a disk cache,
  plus a 100-town hand-curated "local highlights" dataset filling gaps OSM misses for smaller
  Indian towns.
- **One-liner:** *"This is what lets recommendations and itineraries work for any place someone
  types, not just our 93 curated destinations."*
- **Numbers:** 15,845+ cached POIs · 100 curated towns.

### 06 — Itinerary Generation (D24DCS163, bonus, mention only if time allows)
- **Explicitly not ML** — composes the cost model + catalog + geo lookup into a day-by-day plan.
  Say this clearly so it doesn't get challenged as if it were a trained model.
- **One-liner:** *"This shows how the trained pieces come together into a real user-facing
  feature — no LLM, no paid API, fully deterministic."*

---

## Suggested slide flow (8-10 min review)

1. Title + problem statement
2. System architecture (PHP app ↔ Flask ML microservice ↔ MySQL) — one diagram
3. Data: synthetic corpus (162)
4. NLP pipeline (154)
5. Cost model + metrics (163)
6. Recommender + live explainability demo (161) — spend the most time here, it's the standout
7. App screenshots: storybook journal, recommendation cards, cost breakdown donut
8. Results summary table (all key metrics in one place)
9. Roadmap / future work

I can also generate this as a ready-to-present HTML slide deck (or a proper .pptx) if that's
faster than building it by hand tonight — just say the word.
