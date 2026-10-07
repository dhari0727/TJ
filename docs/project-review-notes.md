# JourneyAI — Study Notes for Review

## Introduction (say this at the start)

**Tagline (title slide):**
> JourneyAI — the travel platform that explains itself.

**30-second hook (opening line, read almost verbatim):**
> Ask most travel apps for a recommendation and you get a confident answer with zero reasoning
> behind it — and if you ask what your trip will actually cost, you get a guess, not a number
> you can trust. JourneyAI fixes both problems. It's a travel platform where every recommendation
> comes with a plain-English reason backed by real data, every cost estimate comes from a trained
> model with a measured accuracy — and we built it end-to-end ourselves, from the raw data
> collection to the machine learning to the web app you're about to see.

**Full introduction (60-90 seconds, use if you have the floor for longer):**
> Good [morning/afternoon]. We're presenting JourneyAI, a travel planning platform built around
> one idea: an app should be able to explain itself.
>
> Most travel apps fall into one of two camps. Either they're a black box — you type in a few
> preferences and get a ranked list with no visibility into why — or they're just a digital
> notebook: you type in your trip, and nothing intelligent ever happens with that data. We
> wanted neither. We built a system where the journal data people write actually trains the
> models that power recommendations and cost predictions, and where every single output — a
> recommended destination, a predicted budget — comes with a transparent, traceable reason.
>
> We also made a deliberate choice to go deep instead of wide. Rather than a shallow catalog
> spanning the whole country, JourneyAI's machine learning is built on **103 real Gujarat
> destinations** — researched individually, not scraped — so every number you'll see today is
> grounded in a place that actually exists, with real attractions and realistic costs.
>
> Over the next few minutes we'll walk you through four pieces: how we generated and processed
> the data, how we built an explainable recommendation engine, how we trained and validated a
> cost-prediction model against real baselines, and how it all comes together live in the app.

---

## The numbers (memorize these)

| What | Number |
|---|---|
| Gujarat towns we researched/curated (real landmarks, food) | **100** |
| Destinations in our trainable ML catalog | **103** (the 100 towns → 88 usable entries, + 15 we hand-researched separately, 12 overlap) |
| Synthetic users generated | **150** |
| Synthetic journals generated (training data) | **2,600** |
| Interactions / ratings generated | **~3,560** |
| Cost model accuracy | **MAE ₹2,434 · R² 0.708 · MAPE 22.0%** (beats naive baseline by 15%) |
| Recommender sanity check | **hit-rate@5 ≈ 88-92%** |
| Vadodara curation proof | **4 of 6 real landmarks missed by raw map data, recovered by our curation** |

**If asked "100 or 103, which is it?"** — 100 is the raw research (towns + their landmarks/food).
103 is what we built the ML models on (we turned those towns into full catalog entries with
cost/activity/season data, plus added a few extra hand-picked spots).

---

## Tech stack (one line, likely opening question)

Python (scikit-learn, pandas, NLTK) for the ML notebooks, a Flask microservice to serve the
trained models live, PHP + MySQL for the web app itself.

---

## The one-paragraph pitch

Most travel apps either recommend blindly (no reasoning) or are just a data-entry journal with
no intelligence. We built a system where trip data trains a genuinely explainable AI — every
recommendation says **why**, backed by real evidence, not just a score. Everything is homegrown
(no black-box LLM calls in the core models), and we went deep on one region — **all 103 Gujarat
destinations**, not a handful of famous names.

**What's actually different here:**
- Explainability — every pick comes with a plain-English reason and real evidence, not a score.
- Regional depth — 103 real Gujarat destinations, not a token handful.
- Hybrid signal blend — content + semantic + collaborative, not one single approach.
- The journal data trains the model — usage feeds the intelligence, not a static dataset.

---

## Team roles → notebooks

| Roll No. | Role | Notebook |
|---|---|---|
| D24DCS162 | Data & Corpus | 01 (+ 05, supporting) |
| D24DCS154 | NLP & Features | 02 |
| D24DCS161 | Recommender | 04 |
| D24DCS163 | Cost & Serving | 03 (+ 06, bonus) |

---

## Each notebook, in plain terms

### 01 — Data Generation
- **What it does:** builds our training data. Since no real Gujarat travel dataset exists
  publicly, we generate realistic synthetic journals (fake but structured — costs, activities,
  and sentiment all correlate sensibly, not random).
- **Say:** "150 users, 2,600 journals, across all 103 Gujarat destinations, fully reproducible
  (same seed = same data every time)."

### 02 — NLP Feature Extraction
- **What it does:** turns free-text journal descriptions into structured data — sentiment
  (positive/negative), activities (temples, beach, food...), budget tier, TF-IDF text vectors.
- **Say:** "All rule-based and lexicon-based, not a black-box model — every tag can be traced
  back to a specific word match, so it's fully explainable."

### 03 — Cost Prediction Model
- **What it does:** predicts what a trip will cost, broken into categories (food, transport,
  stay, shopping, fees), using a trained regression model (GradientBoostingRegressor).
- **Why GradientBoosting, not Linear Regression or a neural net:** handles nonlinear
  interactions between destination/season/style without needing huge data, and is much less
  prone to overfitting than a deep model would be on ~2,600 rows.
- **Say:** "MAE ₹2,434, R² 0.708, MAPE 22% on a real held-out test split — genuine
  generalization, not just fitting the training data."
- **Baseline comparison (real number, not just a claim):** a naive "predict each destination's
  average cost" baseline gets MAE ₹2,851 / R² 0.587. Our model cuts error by **15%** over that.
  A plain Linear Regression comes close (MAE ₹2,465, R² 0.717) — worth being honest about if
  asked: it shows the cost relationships here are largely additive, and GradientBoosting's edge
  is modest on this dataset size, but it's still the better choice as more data/features get
  added later.

### 04 — Hybrid Recommender
- **What it does:** recommends destinations by blending 3 signals — what you said you like
  (content), what your interests semantically match (TF-IDF), and what similar travelers liked
  (collaborative filtering) — then explains the pick in plain English.
- **Say:** "For 'temples, history, food' it picks Dwarka. For 'desert, culture' it picks Kutch.
  Consistent, sensible results, not random noise."

### 05 — Geo/Places Data Collection
- **What it does:** the 100-town research dataset. Proves real value with a concrete before/
  after: raw map data (OpenStreetMap/Geoapify) vs. our curated version for Vadodara — **4 of 6
  real landmarks were missing from the raw data and only show up because of our curation.**
- **Say:** "This isn't just supporting data — 88 of our 103 catalog destinations came directly
  from here."

### 06 — Itinerary Generation (bonus, not ML)
- **What it does:** builds a day-by-day plan using the catalog + cost model + geo data. No
  training involved — say this clearly if asked.

---

## Quick Q&A

**Q: How did you collect the data? What APIs?**
- Training data (01-04): synthetic, no API — no public dataset exists for this.
- Real map data (05, Explore/Route pages): OpenStreetMap (free) + Geoapify (free tier).
- The 100-town research: a one-time pass using Gemini + SerpAPI, not a live dependency.

**Q: What preprocessing did you do?**
Tokenize → lemmatize → remove stopwords → sentiment lexicon lookup → activity keyword matching
→ budget bucketing (quantile-based) → TF-IDF vectorization → one-hot encode categories for the
cost model → 80/20 train/test split.

**Q: How many features?**
- Cost model: ~5 inputs (destination, style, season, duration, party size) → ~116 columns after
  encoding.
- NLP: 7 structured fields per journal + a TF-IDF vector (2,000-4,000 dimensions).

**Q: Which website page does each notebook power?**
| Notebook | Page(s) |
|---|---|
| 03 Cost model | Dashboard, Plan a Trip, Recommendations, Itinerary |
| 04 Recommender | Dashboard, Plan a Trip, Recommendations (the cards) |
| 05 Geo/places | Explore / Route builder |
| 06 Itinerary | Itinerary page |
| 02 NLP | Feeds the recommender + Analytics page |
| 01 Data gen | Not a live page — it's the training foundation |

**Q: What's your accuracy?**
Cost model: R² 0.708, and it beats a naive "average cost per destination" baseline by 15% MAE —
real evidence it learned something, not just a number in isolation. Recommender: ~90% hit-rate,
but say clearly this is a sanity check, not a formal accuracy metric. NLP: rule-based, no single
accuracy number — its strength is that everything is explainable, not that it's "trained."

**Q: Is this validated against real prices?**
No — be upfront about this if asked. The cost model is trained on synthetic data, so it
demonstrates the technique correctly, but hasn't been checked against real bookings.

---

## If you only remember 5 things

1. **100 towns researched → 103 destinations trained on.**
2. **Cost model: MAE ₹2,434, R² 0.708 — real held-out accuracy.**
3. **Recommender picks make sense: Dwarka for temples, Kutch for desert — and explains why.**
4. **Vadodara proof: 4 of 6 real landmarks missing from raw map data, recovered by our curation.**
5. **Everything is synthetic training data by design (no dataset exists) — say this proactively,
   don't wait to be asked.**
