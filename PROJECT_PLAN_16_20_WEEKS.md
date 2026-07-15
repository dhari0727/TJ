# JourneyAI — 16–20 Week Project Plan (4-Person Team)

**Project:** JourneyAI — A Hybrid, Explainable, Budget-Conscious Travel Recommendation System
**Course context:** Full Stack Web Development with Machine Learning (capstone)
**Team size:** 4 members
**Duration:** 20 weeks (16-week fast track possible by compressing buffer/polish weeks — see notes)
**Base app:** existing PHP + MySQL travel journal (XAMPP), evolved into JourneyAI.

---

## 1. The Team — Roles & Ownership

**Every member contributes to both the AI/ML core and the website.** The frontend is lightweight
(shared design system + a handful of PHP pages), so there is **no dedicated "frontend-only" role**.
Instead, we split the AI/ML pipeline into four modules — each member owns one ML module **and** the
matching slice of the web app that surfaces it. This way all four can explain the ML in the viva,
and web work is shared evenly. One member additionally coordinates as PM (a hat, not a full-time role).

| # | Member | AI/ML module (owns) | Website module (owns) | Cross-cutting hat |
|---|--------|---------------------|------------------------|-------------------|
| **M1** | **Data & Corpus** | DB schema, synthetic corpus generation, `journals` KB, EDA | Restored pages (view-list/display/update/delete), data seeding into the app | — |
| **M2** | **NLP & Features** | NLP pipeline (TF-IDF, NLTK, sentiment lexicon, destination normalization), `journal_features` | Design system + shared UI (tokens, `ja-head.php`, motion libs), landing/login re-skin | **Project Manager** |
| **M3** | **Recommender** | Hybrid recommender (content + CF + semantic), explainability, blend tuning | Plan-a-Trip page + recommendation cards (the centerpiece UI) | — |
| **M4** | **Cost & Serving** | Cost-prediction model, Flask ML microservice, API contract, model evaluation | Analytics dashboard + data-viz, security remediation, QA | **Docs/Report lead** |

**Principle:** each person is a *full-stack contributor for their vertical* — they build the model,
serve/consume it, and build the page that shows it. Shared concerns (design system, security, QA,
docs) have a named owner but everyone pitches in.

> **16-week compression:** merge Weeks 15–18 (polish/testing/docs run in parallel with late build)
> and drop the dedicated buffer week. The core build (Weeks 1–14) is unchanged.

---

## 2. Functionality & Features

**Core functional features**
1. **User accounts & journals** (existing, hardened) — register, login, record trips via the 4-step
   wizard; view/edit/delete entries (missing pages restored).
2. **Synthetic knowledge base** — ~500 realistic travel journals across ~60 destinations + ~40 users
   + ~4,000 rating interactions, so all models have data to learn from.
3. **NLP information extraction** — from each journal's free text, extract destination, budget
   bucket, trip duration, activities/interests, travel style, and sentiment.
4. **Hybrid recommendation engine** — content-based + collaborative filtering + semantic similarity,
   blended and **budget-aware**, returning a ranked destination list.
5. **Explainable AI** — every recommendation carries a human-readable "why this destination" reason
   plus per-signal factor scores and sample supporting journals.
6. **ML cost prediction** — estimated realistic trip cost (with low/high band and category
   breakdown) for a destination + duration + style + season.
7. **Lesser-known destination discovery** — a "Hidden Gem" boost surfaces authentic under-the-radar
   places, not just popular ones.
8. **Plan-a-Trip experience** — user enters budget, duration, interests, style → animated ranked
   recommendation cards with predicted cost and explanations.
9. **Analytics dashboard** — cost-per-destination, sentiment distribution, personal-spend-vs-corpus,
   popularity — visualized.
10. **Premium animated UI** — cinematic, ocean/aqua aesthetic with smooth scroll, parallax, reveal
    animations, glassmorphism, micro-interactions (inspired by award-winning travel sites).

**Non-functional features**
- Security: password hashing (hash-on-next-login), prepared statements, input validation, XSS-safe
  output, localhost-only ML service.
- Explainability & transparency throughout (no black-box recommendations).
- Accessibility: `prefers-reduced-motion` fallbacks, keyboard-usable forms.
- Reproducibility: seeded corpus, pinned dependencies, documented run order.

**Out of scope (per brief):** ticket/hotel booking, real-time navigation, weather, visa/insurance.

---

## 3. Tools & Technologies

| Layer | Technology |
|-------|-----------|
| **Web app / server** | PHP 8.0 (XAMPP/Apache), MySQL (`project` DB) |
| **ML microservice** | Python 3.10, Flask + flask-cors, served on `127.0.0.1:5000` |
| **ML / NLP** | scikit-learn (TF-IDF, cosine similarity, NearestNeighbors, GradientBoostingRegressor), NLTK, pandas, numpy, scipy, custom sentiment lexicon |
| **DB access (Python)** | pymysql |
| **Frontend** | HTML5, CSS3 (custom properties/design tokens), vanilla JS, jQuery 3.3.1 |
| **Motion / animation** | Lenis (smooth scroll), GSAP + ScrollTrigger, SplitType, CSS animations, Intersection Observer |
| **Data viz** | Chart.js, Chartist, Peity/Sparkline (restyled to palette) |
| **Fonts / assets** | Self-hosted display + body fonts; local destination imagery (Unsplash/Pexels) |
| **Integration** | PHP cURL → Flask JSON API |
| **Tooling / collab** | Git + GitHub, VS Code, Postman/curl (API testing), Trello/Notion (task board), Figma (UI design) |
| **Docs** | Markdown, capstone report, architecture diagrams |

---

## 4. Phase → Week Mapping (overview)

| Phase | Weeks | Theme |
|-------|-------|-------|
| **P0** Foundation & Data | 1–4 | Setup, DB migration, synthetic corpus, restore missing pages |
| **P1** NLP Knowledge Base | 5–7 | Feature extraction, sentiment, destination normalization |
| **P2** Recommender + Cost API | 8–11 | Hybrid recommender, cost model, Flask service, explainability |
| **P3** Design System & UI | 12–15 | JourneyAI look, animated pages, PHP↔Flask UI, security |
| **P4** Polish, Analytics & Delivery | 16–18 | Motion polish, analytics, re-skin legacy, tuning |
| **Buffer/Docs/Demo** | 19–20 | Testing, report, presentation, demo dry-runs |

---

## 5. Week-by-Week Plan (with per-person work)

Legend: **M1** Data & Corpus · **M2** NLP & Features (+PM) · **M3** Recommender · **M4** Cost & Serving (+Docs)
Everyone does AI/ML **and** website work in their vertical; shared UI/security/QA/docs are pooled.

### PHASE P0 — Foundation & Data (Weeks 1–4)

**Week 1 — Project setup & alignment**
- **M2 (PM):** finalize scope, set up Git repo + branch strategy, task board, weekly cadence, project
  charter; stand up shared dev environment guide (XAMPP + Python 3.10 venv + pymysql).
- **M1:** verify MySQL `project` DB; confirm live table schema against the code's INSERT column
  orders; define destination catalog structure.
- **M3:** research recommender approaches (content/CF/semantic); draft the ranked-list algorithm and
  the `/recommend` request/response shape.
- **M4:** research cost-prediction features + evaluation metrics; collect UI inspiration (Aquora +
  Awwwards) and draft the design-token palette with M2.
- *Deliverable:* repo live, environments working, schema documented, algorithm + API sketch.

**Week 2 — Database design & migration**
- **M1:** write `sql/migrations.sql` — add `entry_id` PK to `db`, create `journals` VIEW (with
  `true_total`, `duration_days`, category subtotals), `interactions`, `destinations`,
  `journal_features` tables; test on a DB copy. Build `ml/seed/destination_catalog.py` (~60
  destinations: base costs, activities, popularity tiers).
- **M4:** `ml/db.py` pymysql connection module; define corpus realism criteria (cost ratios,
  sentiment balance, taste profiles); start data dictionary.
- **M2:** convert palette into `css/journeyai.css` v1 (tokens, self-hosted fonts, base glass/card
  styles) + a static component sample page.
- **M3:** design the recommendation-card layout + Plan-a-Trip form fields (Figma), aligned to the
  API sketch.
- *Deliverable:* migration script tested, destination catalog, design tokens v1.

**Week 3 — Synthetic corpus generation**
- **M1:** build `ml/seed/generate_corpus.py` — templated narratives, correlated costs, seasonal
  dates, users with latent taste profiles, rating interactions (exact positional column orders,
  sentinel `@seed.journeyai` emails); verify positional inserts don't break the legacy wizard.
- **M4:** review generated text/costs for realism; tune templates and adjective pools; validate
  distributions (destinations, budgets, sentiment) — this is the data the cost model will learn from.
- **M2:** extend the design system — hero, buttons, chips, form controls as static HTML components.
- **M3:** prototype the static Plan-a-Trip form + card grid against sample JSON.
- *Deliverable:* 500 journals + interactions in DB; realism validated.

**Week 4 — Restore missing pages & data-app integration**
- **M1:** port `view-list.php`, `display.php`, `update.php`, `delete.php` from `p1/` — rewritten with
  **prepared statements**; formalize the cross-table join; EDA on the seeded corpus (cost/destination
  coverage) for the report.
- **M2:** apply JourneyAI tokens to the restored list/display pages (first re-skin); shared
  `ja-head.php` partial.
- **M4:** write P0 test cases; verify `true_total ≠ grand`; QA the legacy wizard still works.
- **M3:** finalize the recommender interface spec (what each card needs) so P2 has a target.
- *Deliverable (P0 milestone):* app is whole (browse 500 journals), data ready for ML. **Demo #1.**

### PHASE P1 — NLP Knowledge Base (Weeks 5–7)

**Week 5 — NLP foundations** *(M2 owns NLP this phase)*
- **M2:** build `ml/nlp/lexicon.py` (activity gazetteer + ~300-word sentiment lexicon) and
  `ml/nlp/normalize.py` (City/Country → canonical via alias dict + difflib).
- **M1:** design the `journal_features` write path/batching; ensure the NLP job reads the `journals`
  view efficiently (owner of the KB it writes into).
- **M4:** curate/validate the activity + sentiment lexicons against sample journals; label a small
  gold set for evaluation.
- **M3:** spec how tags/sentiment/activities appear on recommendation cards (feeds the UI).
- *Deliverable:* lexicons + normalizer, gold evaluation set.

**Week 6 — Feature extraction pipeline**
- **M2:** build `ml/nlp/extract_features.py` — TF-IDF (english stopwords, 1–2 grams) + NLTK
  tokenize/lemmatize + keyword/activity extraction + budget-bucket + travel-style rules + sentiment;
  write `journal_features`; pickle vectorizer + matrix under `ml/artifacts/`.
- **M1:** run the pipeline over the full corpus; performance-tune the DB reads/writes.
- **M4:** evaluate extraction vs the gold set (activity precision, sentiment agreement); record
  metrics for the report.
- **M3:** prototype a "journal insights" preview using extracted features (design validation).
- *Deliverable:* populated `journal_features`, extraction metrics.

**Week 7 — KB consolidation**
- **M2:** aggregate per-destination profiles (mean TF-IDF, interest one-hot, sentiment quality) — the
  inputs the recommender consumes; freeze the KB artifact format.
- **M1:** add a rebuild script chaining migrate→seed→extract; version the artifacts.
- **M4:** QA pass on P1; spot-check positive vs negative journals; update data dictionary + report.
- **M3:** finalize card/badge/ribbon/loader components needed for P3.
- *Deliverable (P1 milestone):* structured knowledge base ready. **Demo #2 (show extracted intel).**

### PHASE P2 — Recommender + Cost API (Weeks 8–11)

**Week 8 — Cost prediction model** *(M4 owns cost + serving)*
- **M4:** build `ml/cost/train_cost_model.py` — GradientBoostingRegressor on {destination/region,
  duration, style, season, party_size} → `true_total`; quantile GBRs for low/high bands; learn
  category-breakdown ratios. `ml/cost/predict.py` serving wrapper; report MAE/R².
- **M3:** build content-based scorer (query vector vs per-destination profile cosine) in
  `ml/recommender/hybrid.py` (recommender owner starts here).
- **M2:** provide per-destination NLP profiles the scorers consume; support field alignment.
- **M1:** validate cost inputs against corpus ranges; sanity-check (luxury > budget) with M4.
- *Deliverable:* trained, evaluated cost model + first content scorer.

**Week 9 — Semantic + collaborative recommender**
- **M3:** add semantic scorer (journal-level TF-IDF cosine + sentiment weight) and item-item CF from
  `interactions` (destination×destination cosine; cold-start fallback) to `hybrid.py`.
- **M4:** scaffold `ml/app.py` Flask service with `/health`; wrap the cost model for serving.
- **M2:** define request/response schemas jointly; supply KB artifacts to the recommender.
- **M1:** create test queries (beach+budget, culture+luxury…) + expected-behavior checklist.
- *Deliverable:* all three recommender signals working offline.

**Week 10 — Blending + explainability + endpoints**
- **M3:** blend signals with budget-fit factor + discovery boost; build `ml/recommender/explain.py`
  (reason_factors + templated natural-language reasons + sample journals).
- **M4:** implement `/recommend` and `/predict-cost` endpoints; input validation; parameterized DB
  reads; bind to 127.0.0.1.
- **M1:** verify explanations factually match scores; refine reason templates against real data.
- **M2:** wire the static form to the API via a test harness; render raw JSON.
- *Deliverable:* full hybrid recommender served over HTTP.

**Week 11 — API hardening & integration contract**
- **M4:** `/analytics/summary` endpoint; error handling; cache heavy artifacts at boot; finalize the
  PHP↔Flask JSON contract; build `ml_client.php` (cURL helper + offline banner).
- **M3:** tune blend weights + discovery boost on the test queries; lock default hyperparameters.
- **M1:** end-to-end API test collection (Postman/curl); log accuracy/latency for the report.
- **M2:** integrate `ml_client.php` into a first real page; confirm PHP→Flask round-trip in browser.
- *Deliverable (P2 milestone):* stable ML API. **Demo #3 (curl → recommendations + cost).**

### PHASE P3 — Design System & UI (Weeks 12–15)

> **Note:** the design system is lightweight, so P3 web work is split by vertical — each member
> builds the page that surfaces *their* ML module. M2 owns the shared design system + landing;
> everyone reuses it.

**Week 12 — Design system finalization + landing**
- **M2:** finalize `css/journeyai.css`; vendor motion libs (Lenis, GSAP+ScrollTrigger, SplitType);
  build `js/journeyai.js` (smooth scroll, reveals, split-text, magnetic buttons, cursor glow);
  cinematic `index.php` landing + shared `ja-head.php`. Re-skin login/register.
- **M3:** build the static Plan-a-Trip page + card components on the finalized design system.
- **M4:** wire the analytics data path (`/analytics/summary`) end-to-end into a stub page.
- **M1:** UX review of landing + navigation; gather/optimize destination imagery into `images/dest/`.
- *Deliverable:* stunning animated landing live; design system shared by all.

**Week 13 — Plan-a-Trip page (the centerpiece)** *(M3 — recommender's own UI)*
- **M3:** build animated `plan-trip.php` — budget slider with live fill, springy interest chips,
  cascade-in recommendation cards with tilt-on-hover, count-up costs, slide-open "Why this?",
  per-card animated donut, Hidden-Gem shimmer ribbon, skeleton loaders; wire form → `/recommend`.
- **M4:** server-side input validation/clamping for the form; "Save plan" writes an interaction
  (prepared statement).
- **M1:** verify recommendations render sensibly on real data; adjust corpus/scoring edge cases.
- **M2:** motion/interaction support + `prefers-reduced-motion` fallbacks for the cards.
- *Deliverable:* Plan-a-Trip fully working and beautiful.

**Week 14 — Recommendations page + security remediation**
- **M4:** password hashing with **hash-on-next-login** in `login.php`/`register.php`/`change-pswd.php`;
  convert remaining legacy queries (`action.php`, `profile.php`, `pc.php`, `mail.php`) to prepared
  statements; fix profile.php to key on `eml`; security test (injection/XSS).
- **M3:** build `recommendations.php` (session-history picks, same card system); implement the
  personalized no-form recommendation path with M1.
- **M1:** add the user-history query feeding personalized recommendations (prepared statement).
- **M2:** re-skin any remaining auth/nav surfaces; sidebar links across pages.
- *Deliverable:* recommendations page + hardened, secure app.

**Week 15 — Integration & stabilization**
- **All:** full integration pass — every page uses the design system + live data; fix cross-page
  bugs; graceful offline banner when Flask is down. Each member stabilizes their own vertical.
- **M2:** micro-interaction polish; motion consistency pass.
- **M4:** regression test the whole app; update docs.
- *Deliverable (P3 milestone):* integrated, secure, beautiful app. **Demo #4 (browser end-to-end).**

### PHASE P4 — Polish, Analytics & Delivery (Weeks 16–18)

**Week 16 — Analytics dashboard** *(M4 — cost/serving vertical's UI)*
- **M4:** build `analytics.php` — scroll-choreographed self-drawing charts, count-up stat tiles,
  palette-matched Chart.js/Chartist over the `journals` view + `/analytics/summary`; finalize the
  aggregates and query performance.
- **M1:** supply model-quality visuals (CF neighbor examples, cost-model accuracy) + corpus stats.
- **M3:** richer explanation templates; final recommender hyperparameter tuning; hidden-gem quality.
- **M2:** chart restyling to the palette + reveal animations; verify against raw SQL.
- *Deliverable:* animated analytics dashboard.

**Week 17 — Motion & explainability polish + legacy re-skin**
- **M2:** re-skin the legacy wizard pages (`dashboard.php`–`dashboard3.php`, `profile.php`) with
  shared tokens; final animation polish across the app; accessibility audit.
- **M3:** recommender edge-case hardening; explanation quality pass on real queries.
- **M4:** performance profiling of the ML service; caching improvements; cost-model final metrics.
- **M1:** data/corpus polish; final EDA figures for the report.
- *Deliverable:* one cohesive, polished product.

**Week 18 — Performance, tuning & feature freeze**
- **All:** fix remaining bugs; **feature freeze** at end of week; each owns final metrics for their
  module (M1 corpus/EDA, M2 NLP extraction, M3 recommender, M4 cost + latency).
- **M4:** one-command run script (migrate→seed→extract→train→serve) + `ml/README.md`.
- **M2/M3:** final visual QA, cross-browser check, screenshot/video capture.
- *Deliverable (P4 milestone):* production-quality demo build. **Demo #5 (full dress rehearsal).**

### DELIVERY — Docs, Report & Presentation (Weeks 19–20)

**Week 19 — Documentation & report**
- **M4 (docs lead):** assemble capstone report — abstract, problem, literature/existing-systems,
  methodology, architecture diagrams, results/metrics, screenshots, limitations, future work; write
  the cost-model + serving/architecture + security sections.
- **M1:** data & corpus + EDA write-up. **M2:** NLP methodology + design-system write-up.
  **M3:** recommender + explainability + Plan-a-Trip write-up.
- *Deliverable:* complete report + user manual + README.

**Week 20 — Presentation, demo & submission**
- **All:** build slide deck; rehearse a scripted end-to-end demo (with a data reset script for a
  clean run); prepare Q&A; record a backup demo video; final submission.
- *Deliverable:* final presentation, live demo, submitted project. **Final Demo.**

---

## 6. Contribution Matrix (who does what, at a glance)

Roles by vertical: **M1** Data & Corpus · **M2** NLP & Features (+PM) · **M3** Recommender ·
**M4** Cost & Serving (+Docs). **Every member does AI/ML *and* website work.**

| Area | M1 | M2 | M3 | M4 |
|------|:--:|:--:|:--:|:--:|
| **— AI/ML —** | | | | |
| DB schema & migrations | ★ | | | ○ |
| Synthetic corpus generation | ★ | ○ | | ○ |
| NLP feature extraction & sentiment | ○ | ★ | | |
| Destination normalization | ○ | ★ | | |
| Hybrid recommender (content+CF+semantic) | ○ | ○ | ★ | |
| Explainability | | | ★ | ○ |
| Cost prediction model | ○ | | | ★ |
| Flask ML microservice & API | | ○ | ○ | ★ |
| Model evaluation & metrics | ○ | ○ | ○ | ★ |
| **— Website —** | | | | |
| Design system & motion libs | | ★ | ○ | |
| Landing / login re-skin | | ★ | | |
| Restored pages (view/display/update/delete) | ★ | | | |
| Plan-a-Trip + recommendation cards | | ○ | ★ | ○ |
| Analytics dashboard & data-viz | ○ | ○ | | ★ |
| PHP↔Flask integration | | ○ | ○ | ★ |
| Security remediation | ○ | | | ★ |
| **— Shared —** | | | | |
| Project management | | ★ | | |
| Testing & QA | ○ | ○ | ○ | ★ |
| Documentation & report | ○ | ○ | ○ | ★ |
| Demo & presentation | ○ | ○ | ○ | ○ |

★ = owner/lead · ○ = contributor/support

**Balance:** every member owns **one AI/ML module + one website module** and contributes across
others — so all four can speak to the ML in the viva, and web work is shared, not siloed on one
person. M2 carries the PM hat and the shared design system; M4 carries docs and serving. Weekly
demos keep integration continuous rather than a risky big-bang at the end.

---

## 7. Milestones & Risk Notes

**Milestones (demo gates):**
- Wk 4 — Data foundation ready · Wk 7 — Knowledge base · Wk 11 — ML API · Wk 15 — Integrated app ·
  Wk 18 — Polished build · Wk 20 — Final delivery.

**Key risks & mitigations:**
- *Empty real data* → synthetic corpus (already planned) gives every model signal.
- *Python 3.14 wheel issues* → ML service pinned to **Python 3.10** (stable, libs pre-installed).
- *ML service down during demo* → graceful offline banner + backup demo video + data-reset script.
- *Scope creep on animation* → Three.js/WebGL explicitly out; Lenis + GSAP + CSS is enough.
- *Integration surprises* → weekly demo gates force continuous integration.

**16-week fast track:** run Weeks 16–18 polish concurrently with Weeks 13–15 build, and fold docs
(Wk 19) into the final two build weeks — delivering in 16 weeks with tighter parallelism.
