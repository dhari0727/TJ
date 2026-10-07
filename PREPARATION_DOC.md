# JourneyAI — Complete Preparation Document
**Read this once tonight. Covers everything.**

---

## 🎯 PROJECT ONE-LINER
**JourneyAI** = Hybrid, Explainable, Budget-Conscious Travel Recommender. PHP/MySQL web app + Python/Flask ML microservice. 93 destinations (8 Gujarat), 1,500 synthetic journals, cost model R²=0.75, automated itineraries.

---

## 👥 TEAM & ROLL MAPPING

| Roll | AI/ML Module (Owns) | Web Module (Owns) |
|------|---------------------|-------------------|
| **Roll 1** | Data & Corpus — DB schema, synthetic corpus (1,500 journals), destination catalog (93) | Journal CRUD — new-entry, my-entries, display, update, delete, view-list |
| **Roll 2** | NLP & Features — Activity gazetteer, sentiment lexicon, destination normalization, TF-IDF extraction | Design System & Landing — journeyai.css/js, animations, login/register, PM hat |
| **Roll 3** | Recommender — Hybrid (Content+CF+Semantic), explainability, hidden gems, proximity | Plan-a-Trip Page — budget slider, interest chips, recommendation cards |
| **Roll 4** | Cost & Serving — Cost model (GBR), Flask API (8 endpoints), Itinerary generator, OSM nearby/routes | Analytics Dashboard — charts, personal insights, security, PHP↔Flask integration, docs |

---

## 🛠️ TECH STACK (MEMORIZE)

| Layer | Stack |
|-------|-------|
| **Web** | PHP 8.0, Apache (XAMPP), MySQL/MariaDB, HTML5, CSS3, vanilla JS + jQuery |
| **Design** | CSS Custom Properties, Lenis (smooth scroll), GSAP + ScrollTrigger, Chart.js |
| **ML** | Python 3.10, Flask, scikit-learn (GBR, TF-IDF, Cosine), pandas, NLTK, pymysql |
| **External** | OpenStreetMap Nominatim (geocode) + Overpass (POI) — **free, no keys** |
| **Models** | Trained offline → pickled to `ml/artifacts/` → loaded once at Flask boot |
| **Security** | bcrypt passwords (hash-on-next-login), prepared statements everywhere, Flask localhost-only |

---

## 🏗️ ARCHITECTURE (DRAW THIS FROM MEMORY)

```
Browser → Apache/PHP (:80) ──cURL/JSON──▶ Flask ML (127.0.0.1:5000)
                 │                              │
                 └──────── MySQL `project` ◀─────┘
                      (journals, features, interactions, destinations)
```

**Flask Endpoints (8):** `/health`, `/recommend`, `/predict-cost`, `/itinerary`, `/nearby`, `/route`, `/analytics/summary`, `/analytics/personal`

---

## 📊 KEY METRICS (MEMORIZE THESE NUMBERS)

| Metric | Value |
|--------|-------|
| Destinations in catalog | **93** (8 Gujarat) |
| Synthetic journals | **1,500** |
| Users in corpus | **~40** |
| Rating interactions | **~4,000** |
| Cost Model R² (test) | **0.75** |
| Cost Model MAE | **₹7,900** |
| Cost Model MAPE | **~18%** |
| Recommender latency | **~300ms** (batched) |
| Pages in unified theme | **15+** |
| Flask endpoints | **8** |

---

## 🏷️ GUJARAT DESTINATIONS (KNOW ALL 8)

| Destination | Tier | Best Season | Unique Hook |
|-------------|------|-------------|-------------|
| **Ahmedabad** | Mainstream | Oct–Mar | Heritage walk, Food trail, Stepwells |
| **Kutch** | Lesser-known | Nov–Feb (Rann Utsav) | White Rann, Desert safari, Handicrafts |
| **Dwarka** | Mainstream | Oct–Mar | Dwarkadhish, Bet Dwarka, Nageshwar Jyotirlinga |
| **Somnath** | Mainstream | Oct–Mar | Jyotirlinga, Triveni Sangam, Beach |
| **Gir** | Lesser-known | Dec–Mar | **Asiatic Lion Safari**, Safari permits = high fees |
| **Diu** | Lesser-known | Oct–Mar | Beach, Portuguese fort, Caves |
| **Palitana** | Lesser-known | Oct–Mar | **863 temples on Shatrunjaya Hill** |
| **Statue of Unity** | Mainstream | Oct–Mar | World's tallest statue, Dam, Valley of Flowers |

---

## 🧠 COST MODEL — NOTEBOOK 03 (TECHNICAL DEPTH)

### Problem Formulation
- **Target:** `true_total` = food + transport + stay + shopping + fees (from `journals` view)
- **Key Insight:** Predict **daily cost** (`true_total / duration_days`) → multiply by days
- **Why:** Removes duration as dominant multiplier; model learns style/season/dest effects

### Features (6 groups)
1. **Destination** — 93 one-hot
2. **Region** — one-hot (West India, Himalayas, etc.)
3. **Travel Style** — 7 one-hot (budget, mid-range, luxury, adventure, family, solo, backpacker)
4. **Season** — 4 one-hot (Winter, Summer, Monsoon, Autumn) from visit month
5. **Duration** — numeric (1–60)
6. **Party Size** — numeric (default 1, scaling exponent 0.85)

### Model Architecture
```python
# Point estimate
GradientBoostingRegressor(n_estimators=300, max_depth=3, lr=0.05, loss='squared_error')

# Quantile bands (separate models)
alpha=0.1  → lower bound (10th percentile)
alpha=0.9  → upper bound (90th percentile)

# Category ratios (per destination, learned from corpus)
{food: 0.30, transport: 0.20, stay: 0.35, shopping: 0.10, fees: 0.05}  # example: Goa
```

### Training Pipeline
1. Load `journals` + `journal_features` + `destinations` via SQL join
2. Feature engineering: month→season, fill missing, synthesize party_size=1
3. Target = daily cost
4. 80/20 split, random_state=42
5. Train point model on train, evaluate on test (daily_pred × duration)
6. Retrain point + quantile models on **full data** for serving
7. Compute per-destination category ratios (fallback: global)
8. Save `cost_model.pkl` (model, lo, hi, ratios, metrics, predicts="daily")

### Results (20% Holdout)
| Metric | Value | Meaning |
|--------|-------|---------|
| **R²** | **0.75** | 75% variance explained |
| **MAE** | **₹7,900** | Avg absolute error on total trip |
| **MAPE** | **~18%** | Good for heterogeneous travel costs |

### Sanity Check (Runs Every Training)
```python
# Same destination, 5 days
Backpacker Goa → ~₹14,000
Luxury Goa     → ~₹45,000
Assert: Luxury > Backpacker  ✓
```

### Serving Interface
```python
predict_cost(destination, duration_days, travel_style, month, party_size)
→ {
    predicted_cost, low, high, per_day, duration_days,
    cost_breakdown: {food, transport, stay, shopping, fees},
    currency: "INR"
}
```

### Batch Prediction (Critical for Recommender)
`predict_cost_batch(all_93_destinations, ...)` → **one forward pass** (~0.05s vs 1.7s sequential)

### Gujarat Cost Examples (5 days, mid-range, 2 people)
| Destination | Predicted | Low–High | Key Driver |
|-------------|-----------|----------|------------|
| Ahmedabad | ₹10,000 | ₹7,500–13,000 | Food-heavy |
| Kutch | ₹12,000 | ₹9,000–16,000 | Desert camps |
| Dwarka | ₹9,000 | ₹6,800–12,000 | Simple stays |
| Gir | ₹15,000 | ₹11,000–20,000 | Safari permits (fees) |
| Statue of Unity | ₹13,000 | ₹9,500–17,000 | Premium resort |

---

## 🗓️ ITINERARY GENERATOR — NOTEBOOK 06

### Core Idea
**Template + Data driven** — No LLM, no API keys, deterministic, offline.

### Activity Slots (21 Types)
```python
ACTIVITY_SLOTS = {
    "desert":    ["Desert safari at {a}", "Dune sunset at {a}", "Camel ride near {a}"],
    "temples":   ["Morning darshan at {a}", "Visit {a}", "Evening aarti near {a}"],
    "wildlife":  ["Early-morning safari at {a}", "Wildlife safari at {a}", ...],
    "beach":     ["Relax on {a}", "Sunbathe at {a}", "Sunset at {a}"],
    "history":   ["Explore {a}", "Guided history walk at {a}", "Visit {a}"],
    "food":      ["Food trail near {a}", "Try local cuisine around {a}", ...],
    # ... 15 more
}
```
- `{a}` = real attraction from catalog (e.g., "White Rann", "Kalo Dungar")

### Time-Aware Rules
- **Morning-preferred:** wildlife, trekking, temples
- **Evening-only:** nightlife
- **Midday/Afternoon:** anything except evening-only

### Dynamic Fallback (Any Place on Earth)
User types "Borsad" (not in catalog)
→ `_dynamic_destination()` calls OSM Overpass
→ Real POIs → map categories to activities
→ Build synthetic catalog entry → generate itinerary

### Output Structure
```json
{
  "destination": "Kutch, India",
  "days": 5,
  "travel_style": "mid-range",
  "total_cost": 24000,
  "cost_breakdown": {...},
  "plan": [
    {"day": 1, "title": "Day 1 — Arrival", "timeline": [
      {"time": "Morning", "text": "Arrive and settle", "meal": "Local breakfast"},
      {"time": "Midday", "text": "Explore White Rann", "meal": "Lunch at local spot"},
      {"time": "Afternoon", "text": "Visit Kalo Dungar"},
      {"time": "Evening", "text": "Desert safari at White Rann", "meal": "Dinner"}
    ], "stay": "Boutique mid-range hotel", "est_cost": 4800},
    ...
  ]
}
```

### Gujarat Example: Kutch 5-Day (Mid-Range)
| Day | Morning | Midday | Afternoon | Evening | Cost/Day |
|-----|---------|--------|-----------|---------|----------|
| 1 | Arrive & settle | White Rann | Kalo Dungar | Desert safari | ₹4,800 |
| 2 | Mata no Madh darshan | Bhuj food trail | Handicraft villages | Mandvi sunset | ₹4,800 |
| 3 | Wild Ass Sanctuary safari | Local cuisine | Chhari Dhandh photography | Rann Utsav cultural | ₹4,800 |
| 4 | Dholavira ruins trek | Ancient reservoirs | Stargazing | Leisure | ₹4,800 |
| 5 | Leisurely morning | Last shopping | — | Depart | ₹4,800 |
| **Total** | | | | | **₹24,000** |

---

## 🔀 RECOMMENDER — HYBRID BLEND

### Three Signals
| Signal | Method | Weight |
|--------|--------|--------|
| **Content** | User interest vector (21-d) cosine vs destination activity histogram | **0.38** |
| **Semantic** | TF-IDF cosine (user text vs destination journal narratives) + sentiment nudge | **0.22** |
| **Collaborative** | Item-item CF (destination×destination cosine from ratings); cold-start → popularity | **0.30** |

### Modifiers (Applied After Blend)
- **Budget Fit:** Soft factor — within=1.0, over=1-(over/budget), never hard-drop
- **Season Fit:** Peak month=1.0, ±1=0.7, else=0.4
- **Hidden Gem Boost:** +0.08 if lesser_known tier AND base score > 0.5
- **Proximity:** Region adjacency (if "near me" selected) — weight 0.10

### Explainability
Every recommendation returns `reason_factors` (per-signal scores) + `explain.py` generates natural language + evidence journal quotes.

---

## 🛡️ SECURITY (BE READY TO EXPLAIN)
- **bcrypt** via `password_hash()` / `password_verify()`
- **Hash-on-next-login:** Legacy plaintext → on successful login, re-hash with bcrypt, store. Next login uses bcrypt only.
- **Prepared statements** across 30+ PHP files (no SQL injection)
- **XSS-safe:** `htmlspecialchars` on all model output rendered to HTML
- **Flask:** Binds `127.0.0.1:5000`, CORS limited to localhost
- **Uploads:** Type/size validation, stored in `uploads/`

---

## 🚀 DEMO FLOW (5 MINUTES)
1. **Landing** → Hero carousel, smooth scroll
2. **Plan-a-Trip** → Budget ₹15k, 5 days, Beach+Food → Diu/Kutch cards with cost donuts, "Hidden Gem" ribbon, click "Why this?"
3. **Itinerary** → Kutch, 5 days → Timeline with White Rann, Kalo Dungar, meals, stays, cost donut
4. **Route** → "Bhuj" → Leaflet map with multi-stop route
5. **Analytics** → Personal spend vs community, best value destinations
6. **Journal Entry** → Create with photo + hashtags

---

## 💬 TOP 12 Q&A (MEMORIZE ANSWERS)

| # | Question | Short Answer |
|---|----------|--------------|
| 1 | Why Gradient Boosting not NN/RF? | GBM excels on tabular mixed features, fast on 1.5k rows, native quantile loss, feature importance |
| 2 | Cold-start for new users? | Content + Semantic work from stated interests; CF falls back to popularity |
| 3 | R²=0.75 — what about 25%? | Inherent travel variance (transport mode, booking time). **Low/high bands capture uncertainty explicitly** |
| 4 | Why synthetic data? | Capstone constraint. But corpus has correlated costs, seasonal patterns, latent user tastes — *controlled generator* |
| 5 | Itinerary without LLM? | Template + real attractions + time-aware slots = deterministic, fast, zero hallucination |
| 6 | OSM Overpass down? | Catalog covers 93 major spots. Dynamic fallback only for off-catalog. Production: cache + keyed provider |
| 7 | Flask bottleneck? | Models load **once at boot**. Batched inference (93 dests in 0.05s). Stateless, horizontally scalable |
| 8 | Hash-on-next-login? | Legacy verify → bcrypt hash → store. Zero-downtime, no user friction |
| 9 | Why PHP + Flask not one stack? | Base app was PHP. **Polyglot**: PHP for server-rendered pages + auth, Python for ML. Clean separation |
| 10 | Production risk? | OSM reliability → cache + fallback. Synthetic bias → collect real interactions, retrain monthly |
| 11 | Recommendation evaluation? | Offline: test query checklist. Online: clicks/saves/ratings → precision@k, diversity, gem exposure |
| 12 | International destinations? | Catalog has 20+ int'l. Cost model less accurate (less synthetic data) but structure generalizes. OSM works globally |

---

## 🎤 TECHNICAL VOCABULARY (USE THESE)
- **Quantile Regression** → cost bands
- **Item-Item Collaborative Filtering** → CF signal
- **TF-IDF Cosine Similarity** → semantic signal
- **Cold-Start Problem** → new user handling
- **Daily Cost Target Engineering** → cost model design
- **Synthetic Corpus Bootstrapping** → data generation
- **Hash-on-Next-Login Migration** → security
- **Batched Inference** → latency optimization
- **Deterministic Template Generation** → itinerary (vs LLM)
- **Polyglot Architecture** → PHP + Flask

---

## ✅ PRE-DEMO CHECKLIST (RUN 30 MIN BEFORE)
```
[ ] XAMPP Apache + MySQL running
[ ] ML service: ml/venv/Scripts/python.exe -m ml.app  (check http://127.0.0.1:5000/health)
[ ] Browser: http://localhost/travel_journel/index.php
[ ] Test: Plan-a-Trip → Budget 15000, 5 days, Beach+Food → Diu/Kutch appear
[ ] Test: Itinerary → Kutch, 5 days → timeline renders
[ ] Test: Route → Bhuj → map loads
[ ] Test: Analytics → charts render
[ ] Backup demo video ready (phone recording)
[ ] Reset script handy: ml/venv/Scripts/python.exe -m ml.rebuild_all --seed 42 --n 1500
```

---

## 📁 REFERENCE FILES IN REPO
- `PREPARATION_DOC.md` ← **This file**
- `PPT_CONTENT_GUIDE.md` ← Slide content only
- `TRANSITION_SCRIPTS.md` ← Speaking transitions
- `JourneyAI_Project_Summary.pdf` ← Print & carry
- `IMPLEMENTATION.md` ← Architecture/API reference