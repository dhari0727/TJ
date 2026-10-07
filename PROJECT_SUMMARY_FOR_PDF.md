# JourneyAI (Travel Journal) — Complete Project Summary
**Simple Language Overview for Project Review**

---

## 🎯 What Is This Project?

**JourneyAI** started as a basic **Travel Journal** (PHP + MySQL) where users write trip diaries. We upgraded it into a **Smart Travel Planner** that:
- Recommends destinations based on your budget, interests, and travel style
- Predicts how much a trip will cost (with low/high estimates)
- Creates day-by-day itineraries with real places, meals, and stays
- Shows nearby places and builds routes on a map
- Gives you analytics (your spending vs. others, best value destinations)

**All of this works offline — no paid APIs, no internet required for the AI parts.**

---

## 👥 Team & Roll Number Mapping

| Roll No | Name | Role (AI/ML) | Role (Web/App) |
|---------|------|--------------|----------------|
| **Roll 1** | Member 1 | **Data & Corpus** — Database, synthetic data (1,500 journals), destination catalog | **Journal Pages** — View, Edit, Delete, List entries |
| **Roll 2** | Member 2 | **NLP & Features** — Text processing, sentiment, activity extraction, destination matching | **Design System & Landing** — Animations, theme, login/register pages, Project Manager |
| **Roll 3** | Member 3 | **Recommender** — Hybrid recommendation engine, explanations, "near me", hidden gems | **Plan-a-Trip Page** — Budget slider, interest chips, recommendation cards |
| **Roll 4** | Member 4 | **Cost & Serving** — Cost prediction model, Flask API, itinerary generator, nearby places, routes | **Analytics Dashboard** — Charts, personal insights, security, PHP↔Python integration, Documentation |

> **Rule:** Every member builds **both** the AI model AND the web page that shows it.

---

## 🛠️ Tools & Technologies (Simple List)

### Web Layer (What Users See)
| Tool | Purpose |
|------|---------|
| **PHP 8.0** | Server-side language for all web pages |
| **Apache (XAMPP)** | Local web server |
| **MySQL / MariaDB** | Database (users, journals, expenses, ratings) |
| **HTML5 + CSS3** | Page structure & styling |
| **Vanilla JavaScript + jQuery** | Interactive elements |
| **CSS Custom Properties** | Design tokens (colors, spacing, fonts) |
| **Lenis + GSAP + ScrollTrigger** | Smooth scroll & animations |
| **Chart.js / Chartist** | Charts on analytics page |
| **Leaflet.js** | Interactive maps on route page |

### AI/ML Layer (The Brain)
| Tool | Purpose |
|------|---------|
| **Python 3.10** | Language for all ML code |
| **Flask** | Lightweight API server (runs on `localhost:5000`) |
| **scikit-learn** | ML models (Gradient Boosting, TF-IDF, Cosine Similarity) |
| **pandas + numpy** | Data processing |
| **NLTK** | Text tokenization & lemmatization |
| **pymysql** | Python → MySQL connection |
| **OpenStreetMap (Nominatim + Overpass)** | Free geocoding & place lookup |
| **pickle** | Save/load trained models |

### Development & Collaboration
| Tool | Purpose |
|------|---------|
| **Git + GitHub** | Version control |
| **VS Code** | Code editor |
| **Postman / curl** | API testing |
| **Trello / Notion** | Task board |
| **Figma** | UI design |

---

## ✅ Features Implemented — Categorized by Roll Number

### 🔵 Roll 1 — Data & Corpus + Journal Pages

| Feature | What It Does | Page(s) Integrated |
|---------|--------------|-------------------|
| **Database Schema & Migrations** | Created proper tables: `journals` view, `destinations`, `interactions`, `journal_features` | All pages (backend) |
| **Synthetic Data Generation** | Auto-creates 1,500 realistic trip journals with costs, photos, ratings — so AI has data to learn from | Powers all AI features |
| **Destination Catalog (93 places)** | Curated list with real attractions, INR daily costs, peak seasons, popularity tiers — **8 Gujarat destinations** | Plan-a-Trip, Itinerary, Recommendations |
| **Journal CRUD Pages** | Create, Read, Update, Delete trip entries with prepared statements (secure) | `new-entry.php`, `my-entries.php`, `display.php`, `update.php`, `delete.php`, `view-list.php` |
| **Expense Breakdown** | Split costs into Food, Transport, Accommodation, Shopping, Fees — stored across `db1`–`db3` | Journal entry form, Analytics |

**Gujarat Destinations in Catalog:** Ahmedabad, Vadodara, Dwarka, Somnath, Kutch, Diu, Palitana, Statue of Unity, Gir

---

### 🟢 Roll 2 — NLP & Features + Design System

| Feature | What It Does | Page(s) Integrated |
|---------|--------------|-------------------|
| **Activity Gazetteer** | 100+ keywords → 21 canonical activities (beach, trekking, food, temples, wildlife, etc.) | Plan-a-Trip interest chips |
| **Sentiment Lexicon** | 250+ positive/negative travel words + negators/intensifiers — computes sentiment score per journal | Journal features, Recommender quality signal |
| **Destination Normalization** | Converts messy user input ("Bombay", "Benares", typos) → canonical names ("Mumbai, India", "Varanasi, India") | Journal entry, Plan-a-Trip |
| **TF-IDF Feature Extraction** | Turns journal text into numerical vectors for similarity matching | Recommender (semantic signal) |
| **Per-Destination Profiles** | Aggregates: average sentiment, activity histogram, cost priors, peak seasons | Recommender, Cost Model |
| **Design System (journeyai.css/js)** | Ocean/teal theme, glassmorphism cards, smooth scroll, reveal animations, dark/light mode | **Every single page** |
| **Landing Page (index.php)** | Cinematic hero carousel, animated stats, destination showcase | `index.php` |
| **Auth Pages Re-skin** | Modern split-screen login/register with validation | `login.php`, `register.php`, `change-pswd.php` |

---

### 🟡 Roll 3 — Recommender + Plan-a-Trip Page

| Feature | What It Does | Page(s) Integrated |
|---------|--------------|-------------------|
| **Content-Based Scoring** | Matches your selected interests (beach, food, history…) against destination activity profiles | Plan-a-Trip results |
| **Collaborative Filtering** | "Users like you liked these places" — from rating interactions table | Plan-a-Trip, Recommendations page |
| **Semantic Similarity** | Compares your interest text against destination journal narratives (TF-IDF cosine) | Plan-a-Trip results |
| **Hybrid Blending** | Weighted mix: 38% Content + 22% Semantic + 30% Collaborative + budget fit + hidden gem boost | Plan-a-Trip, Recommendations |
| **Budget-Aware Filtering** | Predicts cost for ALL 93 destinations in one go (~0.05s), filters/stretches by your budget | Plan-a-Trip form submission |
| **Hidden Gem Discovery** | Boosts lesser-known destinations that score well (Kutch, Palitana, Gir, Spiti, etc.) | Plan-a-Trip cards show "Hidden Gem" ribbon |
| **Proximity / Near Me** | Browser geolocation → region → shows destinations near you | Plan-a-Trip "Use My Location" |
| **Explainable AI** | Every card shows: "Why this?" modal with factor scores + sample journal quotes | Plan-a-Trip cards (`ja-cards.php`) |
| **Plan-a-Trip Page** | Animated form: budget slider, interest chips, trip mode, origin → live recommendation cards | `plan-trip.php` |
| **Recommendations Page** | Personalized picks based on your session history | `recommendations.php` |

---

### 🔴 Roll 4 — Cost Model + Serving + Analytics + Itinerary + Maps

| Feature | What It Does | Page(s) Integrated |
|---------|--------------|-------------------|
| **Cost Prediction Model** | Gradient Boosting Regressor predicts **daily cost** → multiplies by days. R² = **0.75**, MAE ≈ **₹7,900** | Plan-a-Trip cards, Itinerary page, Cost API |
| **Quantile Bands** | 10th/90th percentile models give **Low / High estimate** (confidence interval) | Everywhere cost shows |
| **Category Breakdown** | Splits total into Food, Transport, Accommodation, Shopping, Fees — per destination ratios | Donut charts on cards & itinerary |
| **Party Size Scaling** | Cost scales with `party_size^0.85` (accommodation shares sub-linearly) | Plan-a-Trip, Itinerary |
| **Flask ML Microservice** | 8 REST endpoints on `127.0.0.1:5000` (health, recommend, predict-cost, itinerary, nearby, route, analytics) | All PHP pages via `ml_client.php` |
| **Day-by-Day Itinerary Generator** | Creates Morning/Midday/Afternoon/Evening timeline with real attractions, meals, stays, per-day cost — **no LLM, fully offline** | `itinerary.php` |
| **Dynamic Destination Support** | Type ANY place (even not in catalog) → fetches real OSM places → builds itinerary on the fly | Itinerary, Nearby, Route |
| **Nearby Places (OSM)** | Nominatim geocode + Overpass API → real POIs around any location, filtered by trip mode (day/weekend/short/long) | `plan-trip.php` (Nearby tab), `itinerary.php` |
| **Multi-Stop Route Builder** | Nearest-neighbour ordering of places → total km + Leaflet map | `route.php` |
| **Analytics Dashboard** | Personal stats (your spend vs community), Practical insights (best value, cheapest months, cost ranges), Corpus insights | `analytics.php` |
| **Security Hardening** | bcrypt passwords (hash-on-next-login), prepared statements everywhere, XSS-safe output, Flask localhost-only | **All pages** |
| **PHP↔Flask Integration** | `ml_client.php` — cURL helper with offline banner, timeout, error handling | Every page calling ML |

---

## 📄 Page-by-Page Feature Map

| Page | Key Features | Roll Owner |
|------|--------------|------------|
| `index.php` | Hero carousel, animated stats, destination showcase, theme toggle | Roll 2 |
| `login.php` / `register.php` / `change-pswd.php` | Secure auth, bcrypt, hash-on-next-login, modern UI | Roll 2 |
| `dashboard.php` | Quick actions, recommended-for-you, recent entries, saved plans | Roll 1 + 3 |
| `plan-trip.php` | **Centerpiece** — Budget slider, interest chips, trip mode, origin → recommendation cards WITH cost, explanations, hidden gem ribbon, "Why this?" modal, Nearby tab (OSM places) | Roll 3 (UI) + Roll 4 (API) |
| `recommendations.php` | Session-history personalized picks, same card system | Roll 3 |
| `itinerary.php` | Day-by-day timeline, cost donut, highlights, attractions, rebuild button, dynamic OSM fallback | Roll 4 |
| `route.php` | Multi-stop route, Leaflet map, total distance, Google Maps links | Roll 4 |
| `analytics.php` | Personal insights, practical insights (best value, cheapest months), corpus charts (Chart.js) | Roll 4 |
| `new-entry.php` | 4-step wizard: Details → Story → Budget → Media/Hashtags (photo/video upload) | Roll 1 |
| `my-entries.php` / `display.php` / `update.php` / `delete.php` / `view-list.php` | Full journal CRUD with prepared statements | Roll 1 |
| `profile.php` | Account management, password change | Roll 2 |

---

## 🗃️ Database Tables (Simple)

| Table | Purpose |
|-------|---------|
| `signup` | Users (email, bcrypt password, name) |
| `db` | Journal core: title, city, country, dates, description |
| `db1`, `db2`, `db3` | Expense breakdown (food, transport, stay, shopping, fees) |
| `journals` (VIEW) | Joined view with `true_total`, `duration_days`, category totals |
| `destinations` | 93 canonical destinations: tier, region, base cost, peak months |
| `interactions` | User ↔ destination ratings/saves (for collaborative filtering) |
| `journal_features` | NLP output per journal: destination, budget bucket, activities, style, sentiment |
| `media`, `hashtags`, `media_hashtags` | Photos/videos + hashtags on entries (social layer) |

---

## 🔌 Flask API Endpoints (What the Python Service Does)

| Endpoint | Method | Input | Output |
|----------|--------|-------|--------|
| `/health` | GET | — | Model status, destination count, cost metrics |
| `/recommend` | POST | budget, duration, interests, style, month, party, origin | Ranked destinations + cost + explanations |
| `/predict-cost` | POST | destination, duration, style, month, party | Point cost + low/high + category breakdown |
| `/itinerary` | POST | destination, days, style, month, party | Full day-by-day plan with timeline, meals, stays |
| `/nearby` | POST | place, mode, interests | Real OSM places around location |
| `/route` | POST | place, destination (optional), interests, stops | Ordered stops + total km + map data |
| `/analytics/summary` | GET | — | Corpus stats, best value, cheapest months, cost ranges |
| `/analytics/personal` | GET | eml | Your trips, spend by category, vs community avg |

---

## 🎓 Gujarat-Specific Highlights

| Destination | Type | Best Season | Key Attractions | Est. 5-Day Mid-Range Cost |
|-------------|------|-------------|-----------------|---------------------------|
| **Ahmedabad** | Heritage/City | Oct–Mar | Sabarmati Ashram, Adalaj Stepwell, Jama Masjid | ~₹10,000 |
| **Kutch** | Desert/Culture | Nov–Feb (Rann Utsav) | White Rann, Kalo Dungar, Handicraft Villages | ~₹12,000 |
| **Dwarka** | Spiritual | Oct–Mar | Dwarkadhish Temple, Bet Dwarka, Nageshwar Jyotirlinga | ~₹9,000 |
| **Somnath** | Spiritual/Coastal | Oct–Mar | Somnath Temple, Triveni Sangam, Beach | ~₹9,500 |
| **Gir** | Wildlife | Dec–Mar | Asiatic Lion Safari, Devaliya Park | ~₹15,000 |
| **Diu** | Beach/Relax | Oct–Mar | Nagoa Beach, Diu Fort, Naida Caves | ~₹9,500 |
| **Palitana** | Spiritual/Trekking | Oct–Mar | Shatrunjaya Hill (863 temples), Jain Temples | ~₹7,500 |
| **Statue of Unity** | Monument/Nature | Oct–Mar | Statue, Sardar Sarovar Dam, Valley of Flowers | ~₹13,000 |

---

## 🏗️ How to Run (One-Command Setup)

```bash
# 1. Start XAMPP (Apache + MySQL)

# 2. Database
mysql -u root project < sql/migrations.sql
mysql -u root project < sql/media.sql

# 3. Python Environment (once)
py -3.10 -m venv ml/venv
ml/venv/Scripts/python.exe -m pip install -r ml/requirements.txt

# 4. Build Everything (migrate → seed → extract → train → serve)
ml/venv/Scripts/python.exe -m ml.rebuild_all --seed 42 --n 1500

# 5. Start ML Service
ml/venv/Scripts/python.exe -m ml.app
# OR double-click: ml/start_service.bat

# 6. Open App
http://localhost/travel_journel/index.php
```

---

## 📊 Key Metrics for Review

| Metric | Value |
|--------|-------|
| **Destinations in Catalog** | 93 (8 Gujarat) |
| **Synthetic Journals** | 1,500 |
| **Users in Corpus** | ~40 |
| **Rating Interactions** | ~4,000 |
| **Cost Model R²** | 0.75 |
| **Cost Model MAE** | ₹7,900 |
| **Recommendation Latency** | ~300ms (batched) |
| **Pages in Unified Theme** | 15+ |
| **Flask Endpoints** | 8 |
| **Security** | bcrypt + prepared statements + XSS-safe |

---

## 🎬 What to Demo Tomorrow

1. **Landing Page** — Cinematic carousel, smooth scroll
2. **Plan-a-Trip** — Set budget ₹15,000, 5 days, interests: beach + food → see Gujarat picks (Diu, Kutch) with cost bands, "Hidden Gem" ribbon, click "Why this?"
3. **Itinerary** — Pick "Kutch, India", 5 days → see day-by-day plan with White Rann, Kalo Dungar, meals, stays, cost donut
4. **Route** — Type "Bhuj" → see multi-stop route on map
5. **Analytics** — Personal spend vs community, best value destinations
6. **Journal Entry** — Create new trip with photo upload + hashtags

---

**All set!** This document covers tools, tech, features, roll-wise ownership, page integration, and Gujarat specifics — in simple language.