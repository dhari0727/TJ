# JourneyAI — Implementation Document

**JourneyAI** is a hybrid, explainable, budget-aware travel recommendation system built on a
PHP + MySQL web app (XAMPP) with a Python/Flask machine-learning microservice. It evolved from a
basic PHP "travel journal" into a full AI travel planner.

---

## 1. What JourneyAI Does

| Capability | Description |
|-----------|-------------|
| **Hybrid recommender** | Blends content-based + collaborative filtering + semantic similarity to recommend destinations. |
| **Explainable AI** | Every recommendation states *why* (matched interests, similar travellers, budget fit) with evidence journals. |
| **Cost prediction** | ML model predicts realistic trip cost with a low/high band and category breakdown. |
| **Near me / proximity** | Browser geolocation → suggests places near the user; region-aware ranking. |
| **Real nearby places** | For day trips/weekends: live OpenStreetMap lookup of real places around any typed location. |
| **Day-by-day itinerary** | Generates a morning→evening plan (attractions, meals, stays, per-day cost) for any destination. |
| **Multi-stop route builder** | Orders nearby places into an efficient route with a live map + total distance. |
| **Analytics** | Personal insights (your spend vs community) + practical insights (best value, cheapest months, cost ranges). |
| **Travel journal** | Users record trips (with photos/videos + #hashtags); powers the recommender. |

---

## 2. Architecture

```
Browser ──▶ Apache/PHP (XAMPP, :80) ──cURL/JSON──▶ Flask ML service (127.0.0.1:5000, Python 3.10)
                 │                                        │
                 └──────── MySQL `project` ◀── pymysql ───┘
                          (models trained offline → pickled → loaded at boot)
   External (client-side / server-side): OpenStreetMap Nominatim + Overpass (nearby places, geocode)
```

- **PHP layer** — the web app: pages, auth, journal, and all UI. Calls the ML service via `ml_client.php`.
- **Flask ML service** (`ml/app.py`) — serves recommendations, cost, itinerary, nearby, route, analytics.
- **MySQL `project`** — users, journals, expenses, interactions, NLP features, media.
- **Models** trained offline, pickled to `ml/artifacts/`, loaded once at Flask boot.

---

## 3. Machine Learning (Python 3.10, `ml/`)

Run order: **migrate → seed → extract → build profiles → train cost model → serve.**
One command: `ml/venv/Scripts/python.exe -m ml.rebuild_all --seed 42 --n 1500`

| Module | Purpose |
|--------|---------|
| `ml/seed/destination_catalog.py` | ~93 curated destinations (India-heavy) with real attractions, INR costs, seasons, popularity tier. |
| `ml/seed/generate_corpus.py` | Generates ~1,500 synthetic journals + ~40 users + rating interactions (templated NLG, correlated costs). |
| `ml/nlp/lexicon.py` | Activity gazetteer + custom travel sentiment lexicon. |
| `ml/nlp/normalize.py` | Free-text City/Country → canonical destination (aliases + fuzzy match). |
| `ml/nlp/extract_features.py` | TF-IDF + NLTK + sentiment → per-journal features (`journal_features`); pickles vectorizer/matrix. |
| `ml/nlp/build_profiles.py` | Aggregates per-destination profiles (mean TF-IDF, activity histogram, sentiment, cost priors, season). |
| `ml/cost/train_cost_model.py` | GradientBoostingRegressor on **daily** cost → total; quantile bands; category ratios. **R² ≈ 0.75, MAE ≈ ₹7,900.** |
| `ml/cost/predict.py` | Cost serving wrapper (point + low/high + breakdown). |
| `ml/recommender/hybrid.py` | Content + item-item CF + semantic blend; budget-fit; hidden-gem discovery boost; proximity + season. |
| `ml/recommender/explain.py` | Natural-language "why this" + evidence journals. |
| `ml/recommender/proximity.py` | Region adjacency → "near me" proximity score + distance label. |
| `ml/itinerary/generate.py` | Day-by-day itinerary (time-appropriate slots, meals, stays, per-day cost). |
| `ml/geo/places.py` | OpenStreetMap Nominatim geocode + Overpass POI fetch → real nearby places; nearest-neighbour route builder. |
| `ml/app.py` | Flask service (endpoints below), binds `127.0.0.1:5000`, CORS localhost-only. |

### Flask API endpoints
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | liveness + model metrics |
| POST | `/recommend` | ranked, explained destination recommendations |
| POST | `/predict-cost` | cost estimate + band + breakdown |
| POST | `/itinerary` | day-by-day plan for a destination |
| POST | `/nearby` | real nearby places (OSM) for a typed location + trip mode |
| POST | `/route` | multi-stop route (ordered stops + total km) |
| GET | `/analytics/summary` | corpus + practical insights (best value, cheapest months, cost ranges) |
| GET | `/analytics/personal?eml=` | a user's personal spend/trip insights |

---

## 4. Database (MySQL `project`)

| Table | Role |
|-------|------|
| `signup` | users (passwords now **bcrypt-hashed**) |
| `db` | journal entry core (+ `entry_id` PK added) |
| `db1`,`db2`,`db3` | expense breakdown (food/transport/accommodation/fees/shopping) |
| `journals` (VIEW) | normalized join of db+db1+db2+db3 with `true_total`, `duration_days`, per-category totals |
| `destinations` | canonical destination reference (tier, region, base cost) |
| `interactions` | user↔destination ratings/saves → collaborative filtering |
| `journal_features` | NLP-derived features per journal (dest, budget, activities, style, sentiment) |
| `media`, `hashtags`, `media_hashtags`, `media_likes`, `media_comments` | photos/videos (journal-linked or standalone posts) + hashtags, likes, comments |
| `settings`, `email_log`, `login_attempts`, `user_prefs` | admin-editable settings (secrets encrypted), mail history, login throttling, per-user preferences |

Migrations (run in this order, all safe to re-run): `sql/migrations.sql` (core), `sql/media.sql`, `sql/features.sql`,
`sql/storybook.sql` + `sql/storybook-themes.sql`, `sql/admin.sql` (roles, settings, mail log, login throttle, user prefs),
`sql/community.sql` (journal sharing, post comments), `sql/engage.sql` (packing lists, pinning, handles, journal likes/comments),
`sql/prod.sql` (rate limits, indexes), `sql/create-db-user.sql` (least-privilege DB user).

---

## 5. Web App (PHP pages)

**Design system:** one consistent editorial/cinematic theme (`css/journeyai.css`, `css/journeyai-community.css`,
`js/journeyai.js`), ocean/teal palette, SVG icons (`ja-icons.php`), shared head/footer (`ja-head.php`,
`ja-footer.php`). Light + dark themes.

**One menu, one place for each job.** The sidebar lives in a single file, [ja-nav.php](ja-nav.php):

| Menu | Page | What it is for |
|------|------|----------------|
| Home | `dashboard.php` | Search box, picks for you (`recommendations.php`), recent entries, saved plans. |
| Plan a Trip | `plan-trip.php` → `trip.php` | The ONLY search. Type a trip in plain words (or open "more control"). Results open in `trip.php`, one page with tabs **Day by day & cost** and **Route & map**. `explore.php`, `itinerary.php`, `route.php` just redirect here. |
| My Trips | `my-plans.php`, `my-routes.php` | Everything you saved from planning (tabs: plans, routes). |
| My Journal | `my-entries.php`, `my-storybooks.php`, `new-entry.php` | Tabs: entries, storybooks, new entry. Each entry can be shared (private / link / public) and posted to the feed. |
| Community | `feed.php`, `share.php`, `read-journals.php` | Tabs: **Feed** (Instagram-style posts), **Share a post** (upload photos/videos with #tags, manage your posts), **Read journals** (public journals and storybooks). Reader: `journal.php`. |
| Insights | `analytics.php` | Personal + community cost insights. |

Also: `packing.php` (packing list builder, under My Trips), `traveller.php?h=handle` (public profile with pinned items), `engage.php` (comments, journal likes, pinning API), `health.php` (monitoring).
| Admin | `admin*.php` | Only shown to admins (see below). |

Account: `login.php`, `register.php`, `forgot-pswd.php` + `reset-password.php`, `profile.php` (details, **travel
preferences** that pre-fill Plan a Trip and Home, data export, account deletion), `change-pswd.php`
(asks for the current password). The floating chat assistant (`chat.php` → Flask `/chat`) is on every page;
it uses Gemini when a key is set and falls back to the built-in planner on any failure.

### Admin panel
First admin: open `admin-setup.php` once (it only works while no admin exists). After that:
- `admin.php` — health (recommendation service, email, AI key), counts, newest users, recent emails.
- `admin-users.php` — search, suspend/reactivate, make/remove admin, send a password-reset link, delete user.
- `admin-settings.php?tab=email` — **SMTP settings + "send test email"** (password stored encrypted; built-in
  SMTP client in `ja-mailer.php`, no PHPMailer needed). Gmail: smtp.gmail.com, 587, TLS, an App password.
- `admin-settings.php?tab=site` — site name, open/close registration, recommendation-service URL, Gemini key.
Shared code: `ja-lib.php` (settings, encryption, auth guards, CSRF, per-user data helpers), `ja-mailer.php`, `mail.php`.

**Shared includes:** `ml_client.php` (Flask cURL client), `ja-cards.php` (recommendation cards),
`ja-images.php` (destination photos), `ja-media.php` (upload + hashtags).

---

## 6. Security

- Passwords **bcrypt-hashed** (`password_hash`), with **hash-on-next-login** migration for legacy rows.
- **Prepared statements** across all data-touching pages (SQL-injection fixed; verified).
- Fixed a critical passwordless-session auth-bypass bug in the original login.
- Input validation/clamping on all new endpoints; `htmlspecialchars` on rendered model output.
- Flask bound to `127.0.0.1` only; CORS limited to localhost; upload type/size checks.

---

## 7. How to Run (development)

**Production deployment, backups, monitoring and the go-live checklist: see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).** Run `ops\check.ps1` before every release (syntax checks + the 70-check `tests/smoke.py`).

```bash
# 1. Start XAMPP (Apache + MySQL)

# 2. Database
mysql -u root project < sql/migrations.sql
mysql -u root project < sql/media.sql

# 3. ML environment (once)
py -3.10 -m venv ml/venv
ml/venv/Scripts/python.exe -m pip install -r ml/requirements.txt

# 4. Build models + seed corpus
ml/venv/Scripts/python.exe -m ml.rebuild_all --seed 42 --n 1500

# 5. Start the ML service   (or double-click ml/start_service.bat)
ml/venv/Scripts/python.exe -m ml.app

# 6. Open the app
http://localhost/travel_journel/index.php
```

---

## 8. Status & Roadmap

**India coverage:** 561 destinations profiled from curated + Wikivoyage/Wikipedia data (`docs/INDIA_ROADMAP.md`); notebooks in `ml/notebooks/`.

**Done:** full ML pipeline, all pages unified in the JourneyAI theme, recommender + cost +
itinerary + nearby + route + analytics, security remediation, logo/branding, media upload
(photos/videos + hashtags on entries).

**In progress / next:** switch geo lookups to a reliable keyed provider (Geoapify) for stronger
place coverage + photos; "User Entry" photos on place cards; category filters on nearby results;
social browse feed + reels + trending hashtags. See `IDEAS_BACKLOG.md`.
