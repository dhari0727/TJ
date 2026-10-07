# JourneyAI — Review 2 document

**Project:** JourneyAI — a hybrid, explainable, budget-aware travel recommendation and trip-planning system
**Guide:** Mr. Hitesh Makwana  **Department:** CSE, DEPSTAR

| Member | ID | Module owned |
|---|---|---|
| Margi Maradia | D24DCS161 | Hybrid recommendation system |
| Deeksha Mavadiya | D24DCS162 | Data and corpus (collection, cleaning, dataset) |
| Dharini Thakkar | D24DCS163 | Travel cost prediction |
| Hetavi Shah | D24DCS154 | NLP (feature extraction, destination profiles, query understanding) |

This one file is everything needed for Review 2, in plain language. Part 1 says who prepares what. Parts 2 to 8 explain the project and the numbers. Part 9 lists honest limits. Part 10 is how to re-run things.

---

## 1. Who prepares what (read this first)

Everyone should know Parts 2, 3 and 6 (the story, the data and the evaluation). Then each person prepares their own part.

| Person | Prepare to explain | Key numbers to remember | Code and notebook |
|---|---|---|---|
| **Deeksha (162) Data** | Where every piece of data comes from, how it was collected and cleaned, what is real and what is synthetic, the dataset fields | 1,422 Wikivoyage pages crawled, 16,639 listings, 2,148 real price observations, 578 destinations, 221 real traveller blog reports from 109 destinations | `ml/scrape/` (wikivoyage, build_india_dataset, wikipedia_views, build_highlights, tripoto), `ml/seed/`. Notebooks 01, 02, 03 |
| **Hetavi (154) NLP** | How text becomes numbers: tokens, TF-IDF, sentiment, activity tags, destination profiles, understanding a typed trip request | Sentiment accuracy 90%, destination-name matching 98.5% with typos, trip-query understanding 40 of 40, activity-tag F1 only 0.44 (be honest) | `ml/nlp/`, `ml/bot/smartplan.py`, `ml/eval/nlp_eval.py` (run `python -m ml.eval.nlp_eval`) |
| **Dharini (163) Cost** | How the daily cost is predicted, why the price prior matters, the low to high band, how it was evaluated and what the baselines show | MAE ₹2,698 and R² 0.75 in 5-fold cross-validation, 17% better than "destination average" (40% on unseen places), 80% band covers 77% | `ml/cost/`, `ml/eval/cost_eval.py`, notebook 05 |
| **Margi (161) Recommender** | How a ranked list is made from content, text and collaborative signals plus budget, season, distance and fame, how explanations are written | Weights 38/22/30, top-6 interest match 90%, budget fit 100%, distance fit 100%, 5.1 regions per top-10, AUC 0.57 vs 0.48 random (honest: personalisation signal is modest) | `ml/recommender/`, notebook 04 |

Each person also has a section in Part 7 with a short talk track, a demo step and likely questions with answers.

---

## 2. The project in one minute

JourneyAI is a web app where a traveller types a trip in plain words ("weekend from Ahmedabad, temples and food", "4 days from Nadiad", "Gir") and gets real places, a route with an alternative and a map, a day-by-day itinerary, the estimated cost, and a packing list. Every recommendation says why it was chosen. Travellers can keep a journal, share it, post photos, and read other people's trips.

**What changed since Review 1**

| | Review 1 | Review 2 |
|---|---|---|
| Coverage | 100 Gujarat destinations | 578 destinations: 546 in India across 7 regions, 32 international |
| Data | Synthetic journals only | Real data added: Wikivoyage and Wikipedia listings with prices, OpenStreetMap places, real traveller blog reports. Synthetic journals are still used for training |
| Evaluation | MAE ₹2,434, R² 0.708 | Cross-validation, five baselines, unseen-destination test, interval coverage, blog check, plus NLP and recommender metrics (Part 6) |
| Recommender | Content + semantic + collaborative + proximity | Adds budget, season, fame, and distance from where you start (short trip and long trip now differ) |
| Explanations | One template | Written from each place's real attractions, distance, season and cost per day |
| Features | Recommend, cost | Plan-a-trip box, trip page with itinerary and route and map, packing list builder, AI chat assistant, journal and storybooks, community, admin panel, production hardening |

Note on scale: Review 1 reported 150 synthetic users and 2,600 journals for 100 places. The India rebuild uses 40 synthetic users and 1,500 synthetic journals, plus 1,380 real-sourced entries. It can be scaled with one command (Part 10).

**Response to the Review 1 feedback**

| Feedback | What we did | Honest status |
|---|---|---|
| Include real user data from blogs | Built a polite collector for Tripoto traveller trip reports (221 reports, 109 destinations, 200 attributed snippets). Also Holidify pages (196 snippets) | Done, but small. Only 41 cost mentions across 7 destinations were usable (Part 3.5). Blogs are used to check the cost model, not to train it |
| Properly define and describe the dataset | Part 3: sources, sizes, licences, fields, collection steps, cleaning, real vs synthetic | Done |
| Evaluation metrics | Part 6: definitions in simple words plus results for every module, with baselines | Done, including results that are weak |

---

## 3. The dataset

### 3.1 Four kinds of data, in plain words

| Kind | What it is | Size | Real or synthetic |
|---|---|---|---|
| **A. Destination catalog** | The list of places we can recommend, each with region, typical daily cost, best months, main activities, famous attractions, coordinates | 578 destinations (193 hand-curated, rest derived from Wikivoyage), 561 have full profiles | Real facts. Daily cost is an estimate computed from real listed prices |
| **B. Listings and prices** | Real sights, restaurants, hotels with descriptions, coordinates and prices, taken from Wikivoyage | 16,639 listings, 8,104 with coordinates, 11,246 with descriptions, 2,659 with a price, 2,148 turned into numeric prices | Real |
| **C. Traveller blogs** | Real first-person trip reports | 221 Tripoto reports across 109 destinations (average 882 words), 396 attributed snippets (Tripoto 200, Holidify 196) | Real |
| **D. Journals and ratings** | The entries the NLP, cost and recommender models learn from | 2,881 journals: 1,500 synthetic, 1,380 real-sourced (Wikivoyage text with cost from the price estimate), 1 real user. 40 synthetic users, 2,068 ratings | Mostly synthetic, because there is no real user base yet |

### 3.2 Sources, licences and how each was collected

| Source | Used for | How collected | Licence and rules followed |
|---|---|---|---|
| Wikivoyage (MediaWiki API) | Destination graph, listings, prices, descriptions | Breadth-first crawl from "India" (states, regions, cities) with pages cached. 1,422 pages (1,041 cities, 240 regions, 140 other) | CC BY-SA 4.0. Attribution in the site footer |
| Wikipedia pageviews | How well known a place is (60-day average) | One batched API call per 50 places | CC BY-SA. Polite rate limit |
| OpenStreetMap (Nominatim, Overpass) | Geocoding, real nearby places for routes | Live API calls with caching | ODbL. Attribution shown on maps |
| Tripoto trip reports | Real traveller text, costs, activities, sentiment | Sitemap, then pages whose URL names one of our destinations (max 3 per place), 1.5 s delay, robots.txt checked, cached | Only derived facts plus a short attributed snippet are stored, never full text |
| Holidify place pages | Short text snippets, entry fees | Direct page URLs, robots.txt checked | Same rule |
| Hand-curated catalog (team) | 193 destinations with researched costs and attractions, 100 Gujarat towns with hand-checked highlights | Web research by the team | Our own work |
| Synthetic generator (`generate_corpus.py`) | Journals, users, ratings for training | Seeded random generator (same seed gives same data) | n/a |

### 3.3 Data dictionary (the important fields)

**Destination (catalog and `destinations` table):** name ("Goa, India"), country, city, region (one of 7 Indian regions or an international group), tier (mainstream or lesser_known), base_daily_inr (estimated cost per person per day), activities (up to 4 of 20 tags), season_peak (months), attractions (up to 4), description, lat/lon, wiki_views.

**Listing (`india_listings.json`):** destination, type (see, do, eat, drink, sleep, buy), name, description, lat, lon, price (text), hours, address, source_url.

**Cost observation (`india_cost_observations.json`):** destination, type, price_raw, inr (number, a range becomes its midpoint), url.

**Blog cost observation (`blog_cost_obs.json`):** destination, daily_inr, how (per_day or trip_total divided by days), days, per_person flag, url, the sentence it came from.

**Journal (tables `db`, `db1`, `db2`, `db3`, view `journals`):** Title, Description, City, Country, dates, hotel, places visited, travel mode, then costs split into food, transport, accommodation, shopping, fees and misc, with `true_total` and `duration_days` computed in the view. Sharing fields: visibility (private, link, public).

**NLP features (`journal_features`):** canonical destination, activities, sentiment score and label, budget bucket (shoestring to luxury), travel style.

**Ratings (`interactions`):** user, destination, rating 1 to 5, type.

### 3.4 Collection and cleaning steps (Deeksha)

1. **Crawl** Wikivoyage from "India" and read the structured templates (`marker`, `regionlist`, `see/do/eat/drink/sleep/buy`).
2. **Parse prices** from text such as "₹300-500" or "Rs. 1,200" into numbers. A range becomes its midpoint. Values outside ₹10 to ₹500,000 are dropped. 2,148 observations (lodging 1,527, median ₹1,350 a night; meals 234, median ₹170; entry fees 258, median ₹50).
3. **Estimate daily cost per place:** 3 meals + 60% of one room night + 2 entry fees + ₹250 local transport (each part capped to ignore outliers), rounded to ₹50. Places with fewer than 3 price observations (265 of 441) use their region's median.
4. **Filter out stubs:** keep a place if its Wikipedia views are at least 30 a day, or it has at least 12 listings, or at least 3 prices. This removed 109 tiny villages and left 441 scraped destinations.
5. **Tag places:** region from the Wikivoyage hierarchy, tier from fame, up to 4 activity tags from keyword counts, best months from text that mentions seasons (otherwise a latitude rule).
6. **Coordinates:** from Wikivoyage markers, or geocoded once through OpenStreetMap (576 of 578 resolved; one wrong location, "Gir", was corrected by hand).
7. **Local highlights:** 396 towns with 2,559 landmarks and 649 food tips from the listings. The 100 Gujarat towns keep their hand-checked entries.
8. **Collect blogs:** polite Tripoto crawler. From each report keep days, rupee amounts with context, activity tags, a sentiment score and a snippet.
9. **Load everything** into MySQL and run the NLP, profile and model-training steps (`python -m ml.rebuild_all`).

### 3.5 Blog data: what we got, honestly

| | Count |
|---|---|
| Trip reports fetched and analysed | 221 (109 destinations) |
| Reports where the number of days was found | 47 |
| Attributed text snippets | 396 |
| Cost observations (daily cost) | 41, from only 7 destinations (28 from "trip total divided by days", 13 from "per day") |

Most blog posts do not state costs, and the ones that do mix per-person and group prices. So blogs give us real text and a small real cost check, not a large cost dataset. One extraction was plainly wrong (₹36,800 a day for Nubra Valley, a package price); we report results with and without it.

### 3.6 Real versus synthetic: what to say if asked

- The destination facts, listings, prices, coordinates and blog reports are **real, public data**.
- The **journals the models learn from are mostly synthetic**, because the app has no real users yet. Their costs follow the catalog price prior times a travel-style factor, with noise. The 1,380 "real-sourced" journals use real Wikivoyage text, but their cost is our price estimate, not an observed trip.
- Therefore accuracy on synthetic journals shows the models learn the pattern we generated. It does not prove accuracy on real trips. The blog check is the only independent test and it is small (Part 6.2).

**Demo community content (not data, and not used by any model):** so the Community pages are not empty, `python -m ml.seed.demo_content` creates 40 demo accounts (neutral handles, no usable password), 14 Gujarat journals (Dwarka, Somnath, Junagadh, Sasan Gir, Diu, White Rann, Ahmedabad, Nadiad and others), 82 photos and 5 short videos (generated illustrations marked "Demo postcard", not real photographs), 5 storybooks, and the likes and comments between the demo accounts. Every row uses the e-mail domain `@demo.journeyai`; it is excluded from model training and from the admin "real users" counts, and `python -m ml.seed.demo_content --remove` deletes all of it.

### 3.7 Limitations of the data
Prices in Wikivoyage can be old and lean towards budget travel. Lodging dominates the price observations. Blogs rarely state costs. Synthetic taste is simple (each user has a few liked activities). Tiny towns have thin text. The world-class places are over-represented in blogs.

---

## 4. System design (everyone should know this)

```
Browser  ──►  Apache + PHP (the website, MySQL for users and journals)
                 │
                 └──► Python "ML service" on 127.0.0.1:5000 (Flask served by waitress)
                          ├─ recommender, cost model, itinerary, route builder, packing, chat tools
                          └─ reads MySQL, loads trained models (pickle files) once at start
External: OpenStreetMap (places, maps), Gemini (chat, optional), SMTP (password emails)
```

| Layer | Technology |
|---|---|
| ML and NLP | Python 3.10, scikit-learn 1.7, pandas, NumPy, SciPy, NLTK (lemmatizer) |
| Service | Flask 3, waitress (production server), PyMySQL |
| Website | PHP 8, MySQL/MariaDB, JavaScript, Leaflet (maps), Chart.js |
| AI chat | Google Gemini with function calling (model `gemini-3.1-flash-lite`), automatic fallback to our own planner |
| Tools | VS Code, Git/GitHub, Jupyter |

**Example flow:** you type "1 day near Nadiad, temples". PHP sends it to the service. The query is understood (origin Nadiad, 1 day, interest temples). The route builder finds real temples nearby (curated entries like Santram Mandir get priority), orders them into a route, builds an alternative route, and PHP shows the list and an interactive map.

---

## 5. Core modules (technical but simple)

### 5.1 NLP module (Hetavi, 154)

**Job:** turn journal text and typed requests into numbers the other modules can use.

1. **Destination matching** (`normalize.py`): maps messy "City, Country" text to one catalog place. Order: exact match, alias list (Bombay to Mumbai), city-only, fuzzy match (difflib, cutoff 0.82), then a scan of the text for a known city.
2. **Tokenising** (`tokenizer.py`): lowercase words, NLTK lemmatiser (so "temples" and "temple" are one word).
3. **Activity tags** (`lexicon.py`): a gazetteer of 153 keywords mapped to 20 tags (beach, trekking, food, nightlife, history, temples, museums, shopping, wildlife, adventure, relaxation, photography, nature, culture, backwaters, mountains, desert, snow, diving, architecture).
4. **Sentiment:** word lists (66 positive, 55 negative). A negator (14 words, e.g. "not") within the two previous words flips the sign. An intensifier (12 words, e.g. "very") multiplies by 1.6. Score = sum divided by (hits + 1), clipped to -1..1. Label: above 0.15 positive, below -0.15 negative, else neutral.
5. **Budget bucket and travel style:** daily spend is cut into five buckets by quantiles; style (luxury, backpacker, family, solo, adventure, mid-range) comes from keywords then spend and activities.
6. **TF-IDF vectors:** unigrams and bigrams, 4,000 features, a word must appear in at least 3 journals and at most 60% of them. This is the text fingerprint of each journal.
7. **Destination profile** (`build_profiles.py`): one row per destination (561): average TF-IDF vector, activity histogram over the 20 tags, average sentiment, cost priors, tier, region, season, attractions, description.
8. **Query understanding** (`smartplan.py`): rules pull out origin ("from X", "near X", "in X"), number of days, budget, interests, and "A to B" routes. Gemini can improve the parse, but the rules are kept as a safety net and their answer is merged. A bare place name ("Gir") is treated as a place to plan.

Output tables: `journal_features` (2,881 rows) and `dest_profiles.pkl` (561 profiles).

### 5.2 Cost prediction (Dharini, 163)

**Job:** estimate the cost of a trip, with a low to high range and a breakdown.

- **Algorithm:** GradientBoostingRegressor (scikit-learn): 300 small trees, depth 3, learning rate 0.05, 90% subsampling, fixed random seed 42. It predicts **daily** cost per person, then multiplies by days (and by party size to the power 0.85 for groups).
- **Inputs:** destination (one-hot), region, travel style, season (from month), days, party size, and **base_daily** (the catalog price estimate as a number). The last one lets the model handle places it has not seen.
- **Range:** two more models (10th and 90th percentile, "quantile regression") give the low and high.
- **Breakdown:** each destination keeps the average share of food, transport, stay, shopping and fees, used to split the total (the donut chart).
- **Training data:** 2,881 journals over 561 destinations. Held-out 20% at the last rebuild: MAE ₹2,736, R² 0.813, MAPE 16.0%.
- **Served by:** `/predict-cost`, and in one batch call for every recommendation card (fast).

**What drives it (permutation importance, extra error when shuffled):** base_daily ₹591 a day, travel style ₹78, trip length ₹29, season ₹5, region ₹3. In plain words: the price prior carries most of the signal; style and season adjust it.

### 5.3 Recommendation system (Margi, 161)

**Job:** rank destinations for what the user wants and explain the choice.

Score for each destination, all parts scaled to 0..1:

```
base  = 0.38 × content  +  0.22 × semantic  +  0.30 × collaborative
score = base + 0.12 × fame
score × (0.5 + 0.5 × budget fit) × (0.9 + 0.1 × season fit)
+ 0.04 bonus for a strong lesser-known match        (hidden gem)
optional: 10% region closeness; for trips with a start point, distance fit (below)
```

| Part | Plain meaning | Method |
|---|---|---|
| Content | Does the place offer what I picked? | Cosine similarity between my interest vector and the place's activity histogram (20 tags) |
| Semantic | Do the travel stories sound like what I want? | Cosine similarity of TF-IDF text vectors, 75%, plus 25% sentiment quality |
| Collaborative | Did travellers like me like it? | Item-item cosine similarity on the rating matrix. New users fall back to popularity |
| Budget fit | Can I afford it? | 1 if predicted cost is within budget, falling as it goes over. Never a hard drop |
| Season fit | Is it a good month? | Compares travel month with best months |
| Fame | Do people actually go there? | Log of Wikipedia daily views (curated places use their tier) |
| Distance fit | Is it the right distance for this trip length? | Road km is about 1.3 times the straight line. Windows: day 0 to 90 km, weekend 60 to 380, short (3 to 5 days) 180 to 950, long 700 to 4,500, with a preferred distance inside each (30, 170, 420, 1,600 km) |

Short and long trips now differ. From Nadiad a short trip gives Dwarka, Udaipur and Mithapur (about 280 to 530 km). A long trip gives Mysore, Hampi, Goa and Jim Corbett (1,000 to 1,600 km).

**Explanations** (`explain.py`) use real facts, for example: "Dwarka fits your interest in temples and food, with [real attractions]. About 530 km away (9.6 h by road); best in Jan to Mar, which suits your travel month. Estimated Rs 9,200 for 4 days (about Rs 2,300 a day), within your budget."

### 5.4 New features since Review 1 (all members should be able to name these)

| Feature | What it does | How it works |
|---|---|---|
| **Plan a Trip (one box)** | One search for everything | Query understanding, then route, destination list or itinerary depending on the request |
| **Trip page** | Tabs: Day by day and cost, Route and map | Itinerary generator (time slots, meals, stay, per-day cost from the cost model, works for any place using real nearby places). Route builder (nearest-neighbour ordering, a recommended and an alternative route, eat-nearby per stop, Leaflet map with pinned stops, save, print) |
| **Real local places** | Santram Mandir, Mai Mandir style results | OpenStreetMap places boosted by hand-checked highlights (Gujarat, 100 towns) and Wikivoyage highlights (396 other towns) |
| **Packing list builder** | A checklist that fits the trip | Rule engine: climate from region and month, quantities from trip length, activities, kids and elders, international items. Every item has a "why". Tick, add, edit quantity, print, duplicate |
| **AI chat assistant** | Ask in plain language | Gemini picks tools (find places, recommend, itinerary, cost, route, packing, journal history). Falls back to our planner if the model is unavailable |
| **Journal and storybooks** | Record trips with fields and photos or videos | Sharing is private, link or public. Pin up to 3 items. Storybooks have themes, page templates, print to PDF |
| **Community** | Feed, share a post, read journals, traveller profile | Posts with hashtags, likes, comments, pinning; public journals and storybooks; profile never shows the email |
| **Accounts and admin** | Sign-up, login, password reset by email, preferences, admin panel | SMTP built in and set from the admin panel, roles, suspend users, mail log, settings, health check |
| **Production readiness** | Safe to deploy | Hidden errors, secure cookies, blocked internals, upload sandbox, CSRF tokens, rate limits, backups, 70-check smoke test (`docs/DEPLOYMENT.md`) |

---

## 6. Evaluation

### 6.1 Metrics in simple words

| Term | Plain meaning |
|---|---|
| **MAE** (mean absolute error) | On average, how many rupees the prediction is off. Lower is better |
| **RMSE** | Like MAE but punishes big mistakes more |
| **MAPE** | Average error as a percentage of the true cost |
| **R²** | How much of the ups and downs of cost the model explains. 1 is perfect, 0 is no better than guessing the average |
| **Baseline** | A simple guess to beat (for example "use the average cost of that destination") |
| **Cross-validation (5-fold)** | Split the data into 5 parts, train on 4, test on the 5th, repeat. Gives an average and a spread |
| **Unseen-destination test** | Like cross-validation, but a destination is never in both training and testing, so it tests brand-new places |
| **Interval coverage** | If we promise an 80% range, about 80% of true costs should fall inside |
| **Precision, recall, F1** | Of the tags we found, how many were right (precision). Of the right tags, how many we found (recall). F1 combines them |
| **Hit rate@10** | Did the held-out liked place appear in the top 10? |
| **AUC** | Chance that a liked place is ranked above a random unliked place. 0.5 is random, 1.0 is perfect |
| **NDCG, MRR** | Ranking scores that reward putting the right place near the top |
| **Coverage** | Share of the catalog that ever gets recommended |

### 6.2 Cost model (Dharini)

**5-fold cross-validation, 2,881 journals (mean over folds):**

| Model | MAE (₹) | RMSE (₹) | MAPE | R² |
|---|---|---|---|---|
| **GradientBoosting (ours)** | **2,698** | 6,732 | 16.2% | 0.75 |
| Destination + style average | 3,332 | 8,262 | 20.1% | 0.62 |
| Destination average | 3,267 | 7,445 | 21.0% | 0.69 |
| Region average | 4,028 | 8,158 | 30.2% | 0.63 |
| Catalog price prior only (base_daily × days) | 2,491 | 6,101 | 14.0% | 0.80 |
| Global average | 5,407 | 10,861 | 45.8% | 0.36 |

**Brand-new destinations (the whole destination held out):**

| Model | MAE (₹) | MAPE | R² |
|---|---|---|---|
| **GradientBoosting (ours)** | **2,548** | 15.6% | 0.79 |
| Destination, destination+style and region average (a new place has nothing to look up, so all three fall back to its region's average) | 4,255 | 31.0% | 0.48 |
| Catalog price prior only | 2,491 | 14.0% | 0.79 |

**Reading this honestly:**
- Ours is about 17% better than "destination average" (MAE 2,698 vs 3,267) on known places and about 40% better on brand-new places (2,548 vs 4,255). That is the claim to make, and it matches Review 1's "beats the naive baseline".
- A baseline that uses only the catalog price prior is **as good as or slightly better than** our model. So most of the skill comes from the price prior (built from real Wikivoyage prices and our research). The model adds adjustments for style, season and trip length. Say this before they ask.
- Synthetic journals only: MAE ₹4,724, R² 0.69. The "real-sourced" rows score R² 0.95, but that is circular because their cost is our price estimate, so do not present it as accuracy.
- **Range quality:** the 10th to 90th percentile band should hold 80% of true costs and holds 76.9% (average width ₹7,665 for a whole trip). Slightly too narrow.
- **Check against real blogs (small, noisy):** 7 destinations, 41 mentions. Excluding one clearly wrong extraction (Nubra Valley ₹36,800 a day), the model is off by about ₹1,170 a day on average (6 destinations). It overestimates Manali (₹2,221 vs ₹938 in blogs), Goa (₹2,303 vs ₹400, one mention) and underestimates Leh (₹1,119 vs ₹3,625). Blog writers are often backpackers, and remote high places cost more than our price prior says. Treat this as preliminary evidence, not proof.

### 6.3 NLP module (Hetavi)

| Test | Data | Result |
|---|---|---|
| **Sentiment** | 60 sentences we wrote and labelled (20 positive, 20 neutral, 20 negative) | Accuracy **90%**, macro F1 **0.90** (majority guess would be 33%). Positive: 18 of 20 right. Neutral: 20 of 20. Negative: 16 of 20. All 6 mistakes were predicted "neutral" (the lexicon does not know words such as "bland", "cheating", "spoiled", "dangerous", "fascinating") |
| **Activity tags from real text** | Tags found in real Wikivoyage text for 54 destinations vs the hand-curated tags of the same places | Precision 0.44, recall 0.44, **F1 0.44**. Good for history (F1 about 0.74), temples (0.74), beach (0.83), mountains (0.63). Weak for relaxation, architecture, shopping, photography, culture (the words that signal them are rare in guidebook text). Over-tags "food" and "museums" |
| **Destination name matching** | 200 destinations with exact, upper/lower case, extra spaces, one-letter typos, no country | 100% exact, case, spaces and city-only; **98.5% with a one-letter typo**. 6 of 7 known aliases (Bombay, Madras, Benares...). 1 of 20 junk names ("Springfield", "Hogwarts"...) matched wrongly (5%) |
| **Understanding a trip query** | 40 labelled requests (origin, days, budget, interests, "A to B") | **40 of 40** fully correct, 100% on every field (rule-based parser) |

Limits to say out loud: the sentiment test set is small and written by us; the activity-tag score shows the tagger is a keyword approach with clear weak spots; the query set resembles the examples we built the parser on.

### 6.4 Recommender (Margi)

**A. Quality checks on the live system (what the user sees):**

| Check | Result |
|---|---|
| Interest match: share of top-6 results whose own tags include the asked interest (all 20 interests) | **90.0%** |
| Same check against hand-curated labels (62 curated results) | **86.8%** |
| Budget fit: results costing at most 1.25 × the budget (budgets ₹10k to ₹80k) | **100%** |
| Distance fit: results inside the distance window for weekend, short and long trips from 5 cities (Nadiad, Delhi, Mumbai, Bengaluru, Kolkata) | **100%** for each |
| Coverage: share of the catalog that appears across 200 random queries | **48.5%** |
| Diversity: distinct regions in a top-10 | **5.1** |
| Share of lesser-known places in a top-10 | **50.8%** |

**B. Does personalisation work? Leave-one-out test.** For each of 40 users, hide one place they rated 4 or 5, rebuild every signal without it, rank all unseen places, and check where the hidden one lands (5 random repeats, catalog of 561):

| Method | Hit rate@10 | AUC | NDCG@10 | MRR |
|---|---|---|---|---|
| Random | 2.5% | 0.48 | 0.010 | 0.012 |
| Popularity | 3.0% | 0.52 | 0.020 | 0.024 |
| Content only | 2.5% | 0.55 | 0.008 | 0.012 |
| Collaborative only | 1.0% | 0.50 | 0.004 | 0.010 |
| **Hybrid (ours)** | 2.0% | **0.57** | 0.007 | 0.013 |

**Reading this honestly:** finding one specific hidden place out of 561 is a very hard test. Hit rate, NDCG and MRR are at chance level for every method. The AUC shows the hybrid is the best of the five (0.57 vs 0.48 random) but the personalisation signal is **weak**. The reason is the data: only 40 synthetic users with about 50 ratings each spread across 561 places. We do not claim strong collaborative accuracy. The strong, useful parts are the explainable constraints: interests, budget, distance and season. Real user ratings are the real fix.

### 6.5 Itinerary, route and packing (no accuracy numbers)
These are rule-based builders with real data, so they are described, not scored. Checks done: route and itinerary pages render for any typed place; 40 of 40 queries parse; packing list gives 25 to 80 suitable items and cold-weather items for Manali in December; all covered by the 70-check smoke test.

---

## 7. Per-member talk track, demo and likely questions

### Deeksha (162): Data
**Say:** "No ready dataset exists for Indian trip costs and local places, so we built one from public sources: Wikivoyage listings with real prices, Wikipedia fame, OpenStreetMap places, and real traveller blogs. Journals for training are still mostly synthetic because we have no users yet."
**Demo:** show `ml/data/india_listings.json` rows, the price parser result, the catalog entry for one town, a Tripoto snippet with its URL.
**Questions**
1. *Why synthetic journals?* There is no public dataset of Indian trip journals with costs. We generate them from taste profiles and the real price prior, and we say so openly.
2. *Is scraping allowed?* We follow robots.txt, identify ourselves, wait 1.5 s between pages, cache, and store only derived facts plus a short attributed snippet. Wikivoyage and Wikipedia are open licences; the footer carries attribution.
3. *How big is the real blog data?* 221 reports, but only 41 cost mentions from 7 destinations. Blogs rarely state costs.
4. *How do you know the prices are correct?* We use medians of many listings and cap outliers, and compare with our curated estimates. Prices can be dated and lean budget; we state that.
5. *How is a destination's daily cost computed?* Three meals, 60% of a room night, two entry fees, ₹250 local transport, rounded to ₹50; fall back to the region median when fewer than 3 prices.
6. *What did you remove?* 109 tiny places with no attention and thin content.

### Hetavi (154): NLP
**Say:** "NLP turns text into numbers: tokens, TF-IDF vectors, a transparent sentiment score, activity tags, and a profile for every destination. It also understands what the user types."
**Demo:** run `python -m ml.eval.nlp_eval`; type "weekend from Udaipur, temples and food" and "Gir" in Plan a Trip.
**Questions**
1. *Why a lexicon, not BERT?* Explainable, no GPU, fast, and good enough on our short texts (90% on our test). Limits: misses words it does not know.
2. *What is TF-IDF?* A word's weight is high if it is frequent in this text but rare across all texts, so distinctive words count most.
3. *How is negation handled?* A negator within two words flips the sign; "very" multiplies by 1.6.
4. *Why is activity-tag F1 only 0.44?* Guidebook text rarely says "photography" or "relaxation"; the tagger over-tags food. Improvement: a trained classifier on labelled text.
5. *How do you match "Bombay"?* Alias list, then fuzzy match with a 0.82 similarity cutoff.
6. *Where is the LLM used?* Only to improve query parsing and chat. The rule parser always runs as a safety net.

### Dharini (163): Cost prediction
**Say:** "We predict daily cost with gradient boosting, then scale by trip length. It beats the destination-average baseline by 17% on known places and 40% on brand-new places, and gives a low to high range and a category breakdown."
**Demo:** an itinerary page cost donut, the cost-model table in notebook 05, `python -m ml.eval.cost_eval`.
**Questions**
1. *Why gradient boosting?* Handles mixed categorical and numeric inputs, captures interactions (style × season), small data, no scaling needed.
2. *Why predict daily cost?* Removes the dominant effect of trip length so the model learns place, style and season effects.
3. *A price-prior baseline matches you. Why use ML?* Correct to notice. The prior supplies the level; the model adds style, season and duration effects and gives intervals. On real data with real ratings the model has more to learn.
4. *Is 90% training accuracy real?* The "real-sourced" 0.95 R² is circular and we do not present it. We report cross-validation on all rows and the unseen-destination test.
5. *How do you know the range is reliable?* An 80% range holds 76.9% of true costs, slightly narrow.
6. *Does it match real travellers?* Preliminary: 7 destinations from blogs, off by about ₹1,170 a day, worse for Leh. We need more real cost data.
7. *Why is party size 0.85 power?* Costs rise with group size but not linearly (shared rooms and cabs).

### Margi (161): Recommender
**Say:** "We combine content, text and collaborative signals, then adjust for budget, season, fame and distance from where you start. Every card explains why."
**Demo:** Plan a Trip: "3 days beaches under 20000"; then a short trip and a long trip from Nadiad and show the different lists; open one explanation.
**Questions**
1. *Why a hybrid?* Content works for new users and places, collaborative learns taste, text adds nuance; together they cover each other's weaknesses.
2. *How are weights chosen?* 38/22/30 plus extras were tuned by hand on sample queries. Improvement: learn weights from real feedback.
3. *Your hit rate is at chance level.* Yes, on 40 synthetic users and 561 places a single hidden place is nearly impossible to find. AUC 0.57 vs 0.48 shows a modest gain. We rely on checks that matter to the user: interest match 90%, budget fit 100%, distance fit 100%.
4. *How do you handle new users?* Content and text still work; collaborative falls back to popularity.
5. *How is "near" decided?* Straight-line distance times 1.3 approximates road km, compared with a window and preferred distance for the trip length.
6. *What stops it recommending obscure towns?* A fame prior from Wikipedia views; for short and long trips fame multiplies the score.
7. *How are explanations produced?* Built from real attractions, distance, season and cost for that place (not generated text).

---

## 8. Five-minute demo script (anyone can run it)

1. **Home** shows personal picks and a search box.
2. **Plan a Trip:** "1 day near Nadiad, temples" gives Santram Mandir and Mai Mandir, a route, an alternative route toggle and a map with pins.
3. **Short vs long:** open "More control", pick Short trip from Nadiad (Dwarka, Udaipur...), then Long trip (Mysore, Hampi, Goa...). Each card shows distance and why.
4. **Trip page:** Day by day and cost (donut and range), Route and map tab, **Packing list** button.
5. **Packing:** Manali, December, kids and trekking; show a "why?" and tick items.
6. **Journal:** New entry with photos and costs; share as link; **Community** feed, comment, pin.
7. **Chat:** "How much for 5 days in Kerala?" and "Places to visit near me".
8. **Admin:** health, users, email settings.

---

## 9. Limitations and future work (say these before reviewers do)

- The journals used for training are mostly synthetic; real users are needed. Costs, taste and sentiment learned from them describe our generator.
- Blog costs are scarce and noisy (41 mentions, 7 destinations); the external cost check is preliminary.
- The cost model's skill mostly comes from the catalog price prior; its range is slightly too narrow (76.9% vs 80%).
- Collaborative and personalised ranking is weak on 40 users (AUC 0.57). More real ratings are the fix.
- The activity tagger is keyword based (F1 0.44); sentiment misses unseen words.
- Wikivoyage prices can be dated and lean towards budget travel; high, remote places such as Leh are under-estimated.
- Single-server deployment; no email verification or two-factor login; no strict Content-Security-Policy yet.
- Future: collect real cost-bearing journals from users, train a text classifier for activities, learn recommender weights from clicks, ratio-based cost model, more blog sources with permission.

---

## 10. How to run and reproduce

```
# start everything (Windows / XAMPP): Apache + MySQL from the XAMPP panel, then
ml\start_service.bat                                   # ML service on 127.0.0.1:5000
http://localhost/travel_journel/                       # the website

# rebuild data and models
ml\venv\Scripts\python.exe -m ml.scrape.wikivoyage --crawl --listings
ml\venv\Scripts\python.exe -m ml.scrape.wikipedia_views
ml\venv\Scripts\python.exe -m ml.scrape.build_india_dataset
ml\venv\Scripts\python.exe -m ml.scrape.build_highlights
ml\venv\Scripts\python.exe -m ml.scrape.tripoto --max-pages 350        # real traveller blogs (about 30 min, polite)
ml\venv\Scripts\python.exe -m ml.rebuild_all --n 1500                  # builds synthetic data, NLP features, profiles, cost model
# to match Review 1's scale: ml.rebuild_all --n 2600 (journals); users: ml.seed.generate_corpus --users 150 --n 2600 --truncate, then ml.rebuild_all --no-seed
# evaluation
ml\venv\Scripts\python.exe -m ml.eval.cost_eval
ml\venv\Scripts\python.exe -m ml.eval.nlp_eval
powershell -File ops\check.ps1                                         # syntax + 70-check end-to-end test
```

**Notebooks (`ml/notebooks/`):** 01 Wikivoyage crawl, 02 listings and real costs, 03 blog corpus (Deeksha); 04 recommender and evaluation (Margi); 05 cost model training (Dharini). There is no separate NLP notebook; Hetavi shows `ml.eval.nlp_eval` and the code in `ml/nlp/`. Notebooks are rebuilt by `python ml/notebooks/_build_notebooks.py`.

**Key files:** `ml/seed/destination_catalog.py` (catalog), `ml/nlp/` (NLP), `ml/cost/` (cost model), `ml/recommender/` (hybrid, explain, proximity), `ml/itinerary/generate.py`, `ml/geo/places.py` (routes), `ml/bot/` (chat and query understanding), `ja-packing-engine.php` (packing), `docs/DEPLOYMENT.md`, `docs/INDIA_ROADMAP.md`.

**Glossary of project words:** *catalog* = the list of destinations; *profile* = the numeric summary of one destination; *tier* = mainstream or lesser-known; *prior* = a sensible starting guess (here, the typical daily cost); *hidden gem* = a lesser-known place that matches strongly; *synthetic* = generated by our program, not written by a real traveller.
