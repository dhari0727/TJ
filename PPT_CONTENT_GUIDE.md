# JourneyAI — PPT Content Guide
**Slide-by-slide content only. Build in PowerPoint.**

---

## SLIDE 1: TITLE
**JourneyAI: Hybrid, Explainable, Budget-Conscious Travel Recommender**
*From Travel Journal → Intelligent Travel Planner*

[Name] • Roll [X] • [Course] • [Date] • Guide: [Name]

---

## SLIDE 2: PROBLEM
**Why Travel Planning Fails Today**

| Pain Point | Reality |
|------------|---------|
| No Personalization | Same "Top 10" for everyone |
| Budget Black Box | "₹15k for Goa" — backpacker or luxury? |
| Black Box AI | "We recommend X" — but *why*? |
| Hidden Gems Buried | Popular spots dominate |
| Rigid Itineraries | Copy-paste, no interest/style adaptation |

> **Insight:** Travelers need a system that understands *their* budget, interests, style — and explains its reasoning.

---

## SLIDE 3: SOLUTION
**JourneyAI — Your Personal Travel Intelligence**

**INPUT** → **HYBRID ENGINE** → **OUTPUT**

| Input | Engine | Output |
|-------|--------|--------|
| Budget (₹) | Content (38%) | Ranked destinations + cost bands |
| Duration | Collaborative (30%) | "Why this?" explanations + evidence |
| Interests | Semantic (22%) | Day-by-day itinerary (real places) |
| Travel Style | Budget-fit + Gem boost | Nearby places & routes (OSM) |
| Origin/Month | Proximity + Season | Personal & corpus analytics |

**All offline. No paid APIs. Explainable by design.**

---

## SLIDE 4: LITERATURE REVIEW — POSITIONING
**Where We Fit**

| Approach | Examples | Limitation | Our Advance |
|----------|----------|------------|-------------|
| Collaborative Filtering | Netflix, Amazon | Cold-start; needs dense ratings | Hybrid: works sparse + content fallback |
| Content-Based | Basic travel sites | No discovery | Adds semantic + CF |
| Knowledge-Based | Constraint solvers | Rigid rules | Learns from corpus |
| LLM Planners | ChatGPT plugins | Hallucinations; paid APIs | **Offline, deterministic, cost-grounded** |
| Academic Hybrid | Burke (2002), Jannach (2010) | Often offline eval | **Production Flask API + PHP UI** |

**Key Refs:** Burke 2002 (Hybrid RecSys), Ricci 2015 (Travel RecSys), Jannach 2010 (RecSys Handbook)

---

## SLIDE 5: ARCHITECTURE
**Clean Separation: PHP Web + Python ML**

```
Browser → Apache/PHP (XAMPP :80) ──cURL/JSON──▶ Flask ML (127.0.0.1:5000)
                 │                              │
                 └──────── MySQL `project` ◀─────┘
                      journals, features, interactions, destinations

Models: Trained offline → Pickled → Loaded at Flask boot
External: OpenStreetMap (free, no keys)
```

**Why:** Decoupled, Secure (localhost-only), Scalable, Debuggable

---

## SLIDE 6: COST MODEL — NOTEBOOK 03
**ML-Powered Cost Estimation (R²=0.75)**

### The Insight
> Predict **daily cost** → multiply by days. Removes duration as dominant feature.

### Features
- Destination (93 one-hot) + Region + Travel Style (7) + Season (4) + Duration + Party Size

### Model
```python
# Point estimate
GradientBoostingRegressor(n_estimators=300, max_depth=3, lr=0.05)

# Quantile bands (separate models)
alpha=0.1 (low)  |  alpha=0.9 (high)

# Category ratios (per destination, from corpus)
Goa: Food 30%, Stay 35%, Transport 20%, Shopping 10%, Fees 5%
Gir: Food 20%, Stay 30%, Transport 25%, Shopping 5%, Fees 20%  ← safari permits
```

### Results (20% Holdout)
| Metric | Value |
|--------|-------|
| **R²** | **0.75** |
| **MAE** | **₹7,900** |
| **MAPE** | **~18%** |

### Sanity Check (Auto-run)
`Luxury Goa (5d) > Backpacker Goa (5d)` ✓

### Serving
`predict_cost(dest, days, style, month, party)` → {predicted, low, high, breakdown, per_day}

**Batch:** 93 destinations in **one forward pass** (~0.05s)

### Gujarat Examples (5d, mid-range, 2 pax)
| Dest | Predicted | Band | Driver |
|------|-----------|------|--------|
| Ahmedabad | ₹10k | ₹7.5k–13k | Food-heavy |
| Kutch | ₹12k | ₹9k–16k | Desert camps |
| Gir | ₹15k | ₹11k–20k | Safari fees |

---

## SLIDE 7: COST MODEL — INNOVATIONS
**What Makes It Different**

| Innovation | Impact |
|------------|--------|
| Daily cost target | Clean signal for style/season/dest |
| Quantile regression | Real low/high bands (not ±%) |
| Per-dest category ratios | Donuts match reality (Gir fees=20%) |
| Party scaling (^0.85) | Accommodation shares sub-linearly |
| Batched inference | 93 dests in 0.05s → real-time recommender |
| Season awareness | Month→Season→Cost adjustment |
| Explainable breakdown | Every prediction = point + band + 5-category donut |

---

## SLIDE 8: ITINERARY GENERATOR — NOTEBOOK 06
**Automated Day-by-Day Plans — No LLM**

### Core: Template + Real Attractions + Time-Aware Slots
```python
ACTIVITY_SLOTS = {
    "desert": ["Desert safari at {a}", "Dune sunset at {a}", "Camel ride near {a}"],
    "temples": ["Morning darshan at {a}", "Visit {a}", "Evening aarti near {a}"],
    "wildlife": ["Early-morning safari at {a}", ...],
    # 21 activity types
}
```
- `{a}` = real attraction from catalog (White Rann, Kalo Dungar, etc.)

### Time Rules
- **Morning:** wildlife, trekking, temples
- **Evening:** nightlife
- **Midday/Afternoon:** flexible

### Dynamic Fallback (Any Place)
User types "Borsad" → OSM Overpass → POIs → map to activities → synthetic catalog entry → itinerary

### Output
Structured timeline per day: Morning/Midday/Afternoon/Evening + meals + stay tier + per-day cost

### Kutch 5-Day Example (Mid-Range, ₹24k total)
| Day | Morning | Midday | Afternoon | Evening |
|-----|---------|--------|-----------|---------|
| 1 | Arrive | White Rann | Kalo Dungar | Desert safari |
| 2 | Mata no Madh | Bhuj food trail | Handicrafts | Mandvi sunset |
| 3 | Wild Ass Safari | Local cuisine | Photography | Rann Utsav |
| 4 | Dholavira trek | Reservoirs | Stargazing | Leisure |
| 5 | Leisure | Shopping | — | Depart |

---

## SLIDE 9: GUJARAT FOCUS
**8 Destinations, Deeply Modeled**

| Dest | Tier | Season | Unique Hook |
|------|------|--------|-------------|
| Ahmedabad | Mainstream | Oct–Mar | Heritage, Food, Stepwells |
| Kutch | Lesser-known | Nov–Feb | **Rann Utsav**, White Rann |
| Dwarka | Mainstream | Oct–Mar | Jyotirlinga, Bet Dwarka |
| Somnath | Mainstream | Oct–Mar | Jyotirlinga, Triveni Sangam |
| **Gir** | Lesser-known | Dec–Mar | **Asiatic Lion Safari** |
| Diu | Lesser-known | Oct–Mar | Beach, Portuguese Fort |
| **Palitana** | Lesser-known | Oct–Mar | **863 Temples** on Shatrunjaya |
| Statue of Unity | Mainstream | Oct–Mar | World's Tallest Statue |

> Most apps: "Ahmedabad + maybe Kutch." We model **9 distinct experiences** with real attractions, seasonal costs, activity profiles.

---

## SLIDE 10: RECOMMENDER — HYBRID BLEND
**Three Signals → One Ranked List**

```
FINAL = 0.38×Content + 0.22×Semantic + 0.30×CF
        × Budget_Fit × Season_Fit
        + Hidden_Gem_Boost + Proximity
```

| Signal | Method |
|--------|--------|
| **Content** | Interest vector (21-d) cosine vs dest activity histogram |
| **Semantic** | TF-IDF cosine (user text vs journal narratives) + sentiment nudge |
| **Collaborative** | Item-item CF (dest×dest cosine from ratings); cold-start → popularity |

**Explainability:** Every card shows factor scores + "Why this?" modal with evidence journals.

---

## SLIDE 11: RESULTS SUMMARY
**By the Numbers**

| Component | Metric | Value |
|-----------|--------|-------|
| Catalog | Destinations | 93 (8 Gujarat) |
| Corpus | Journals / Users / Ratings | 1,500 / ~40 / ~4,000 |
| Cost Model | R² / MAE / MAPE | 0.75 / ₹7,900 / 18% |
| Recommender | Latency (batched) | ~300ms |
| API | Endpoints | 8 |
| Web | Themed pages | 15+ |
| Security | Passwords / SQL / XSS | bcrypt / Prepared / Escaped |

---

## SLIDE 12: DEMO FLOW (5 MIN)
1. **Landing** — Cinematic carousel
2. **Plan-a-Trip** — ₹15k, 5d, Beach+Food → Diu/Kutch cards, cost donuts, "Why this?"
3. **Itinerary** — Kutch, 5d → Timeline with White Rann, meals, stays, cost donut
4. **Route** — "Bhuj" → Leaflet map multi-stop
5. **Analytics** — Personal vs community, best value
6. **Journal Entry** — Photo + hashtags

---

## SLIDE 13: CHALLENGES SOLVED
| Challenge | Solution |
|-----------|----------|
| No real data | Synthetic corpus: correlated costs, seasons, latent tastes |
| Cold-start | Content+Semantic work without history; CF→popularity |
| Duration dominance | Predict daily cost → ×days |
| PHP↔Flask latency | Batched cost prediction (93 in 0.05s) |
| Off-catalog places | OSM Overpass dynamic fallback |
| Legacy security | Hash-on-next-login + prepared statements |
| Animation perf | Lenis+GSAP + reduced-motion fallbacks |

---

## SLIDE 14: FUTURE — SOCIAL FEED
| Phase | Feature |
|-------|---------|
| Next | Photo/video upload → media table |
| Next | Reels-style posts + place tags |
| Next | Hashtags → trending → tag pages |
| Next | Browse feed (grid + reel viewer) |
| Next | "User Entry" photos on place cards |
| Later | Geoapify keyed API for better POIs |

---

## SLIDE 15: THANK YOU
**JourneyAI — Intelligent Travel Planning for India**

[GitHub] • [Demo Video] • [Email] • Questions?

---

## 🎨 VISUALS TO ADD (Create in draw.io / Screenshot)

| Slide | Visual |
|-------|--------|
| 5 | Architecture diagram (Browser→PHP→Flask→MySQL) |
| 6 | Cost pipeline: Features → Daily GBR → ×Days → Total + Bands + Ratios |
| 8 | Itinerary flow: Dest → Catalog/OSM → Activities → Time Slots → Template → Timeline |
| 9 | Gujarat map with 8 pins (tier-colored) |
| 10 | Recommender blend: 3 bars (38/22/30) + modifiers |
| 12 | Screenshots: Plan-a-Trip form, Cards with "Why this?", Itinerary timeline, Route map, Analytics charts |

---

## 🎨 COLOR PALETTE (from journeyai.css)
- **Primary Teal:** `#0d9488`
- **Deep Teal:** `#0f766e`
- **Accent Gold:** `#f59e0b`
- **Dark BG:** `#0f172a`
- **Card BG:** `rgba(255,255,255,0.05)` (glassmorphism)
- **Text:** `#e2e8f0` (light) / `#1e293b` (dark)