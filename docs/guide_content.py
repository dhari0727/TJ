"""Text, tables and figures of the JourneyAI master guide, one function per section. M is the dict of measured values
collected by build_master_guide.py (live counts, eval output, service examples)."""
from reportlab.platypus import PageBreak, Spacer, KeepTogether
from guide_lib import *  # noqa: F401,F403
import guide_diagrams as G


def nf(n):
    try:
        return f"{int(n):,}"
    except (TypeError, ValueError):
        return str(n)


def sec(num, title, lead):
    return [PageBreak(), Section(num, title, lead), Spacer(1, 4)]


# =====================================================================================================================
def s01(M):
    o = sec(1, "Overview and goals", "What JourneyAI is, what it promises, and the rules every module follows.")
    o += [P("JourneyAI is a trip-planning web application built around a travel journal. People write journals, build storybooks "
            "and keep a private camera roll; the platform turns what is known about places into three practical answers: "
            "<b>where to go</b>, <b>how to do it</b> and <b>what it will cost</b>. It is aimed at travellers in India, with 436 destinations "
            "profiled today (reported by the running ML service)."),
          H2("The three jobs"),
          *table(["Job", "What the user does", "Main modules"],
                 [["Decide where", "States interests, budget, days, month and a starting place; gets ranked, explained destinations.",
                   "Hybrid recommender, similar destinations, destination guide, compare"],
                  ["Plan how", "Gets a day-by-day itinerary, a road route with real stops, a cost band, a packing list; can save for offline.",
                   "Itinerary generator, route builder, cost model, smart plan, chatbot, packing engine"],
                  ["Remember and share", "Writes journals and storybooks, takes Polaroid-style photos, shares publicly if trusted.",
                   "Journal writer, storybooks, camera roll, feed, trust and moderation layer"]],
                 [1.1, 3.2, 2.7]),
          H2("Design principles"),
          *bullets(["<b>Real data only.</b> Destination facts come from Wikivoyage and Wikipedia, prices from published listings and trip reports, "
                    "places from OpenStreetMap. The earlier synthetic corpus was deleted (Section 3).",
                    "<b>Explainable.</b> Every recommendation carries the reasons and the evidence behind it; every cost has a low-high band; "
                    "every authenticity score lists its reasons.",
                    "<b>Fail soft.</b> The PHP site never shows a blank page if the ML service, Gemini or a map server is down; each has a fallback or an honest banner.",
                    "<b>Private by default.</b> New writing is private, photos from the camera are stored in a folder the web server cannot serve, "
                    "and public posting is gated by verification and moderation.",
                    "<b>ML is advisory where it can hurt people.</b> The authenticity score only informs moderators. It never hides or deletes content by itself."]),
          H2("The system at a glance"),
          *table(["Item", "Value", "Source"],
                 [["PHP files in the site root", nf(M["n_php"]), "counted at build time"],
                  ["Python modules under ml/ (excluding the venv)", nf(M["n_py"]), "counted at build time"],
                  ["Flask routes (ML service)", nf(M["n_routes"]), "counted from ml/app.py"],
                  ["MySQL objects", f"{M['n_tables']} tables and {M['n_views']} view", "information_schema, local database"],
                  ["Destinations in the profile artifact", nf(M["n_profiles"]), "GET /health of the running service"],
                  ["Journal rows in the database", nf(M["n_journals"]), "SELECT COUNT(*) FROM db"],
                  ["Automated test checks passing", f"{M['tests_pass']} of {M['tests_total']}", "Section 13"]],
                 [3.4, 1.8, 2.8]),
          callout("Stack: PHP 8 on Apache and MariaDB/MySQL (XAMPP), a Flask service in Python 3.10 using scikit-learn, NumPy and pandas, "
                  "plain HTML, CSS and JavaScript on the front end. There is no front-end build step.", "note")]
    return o


# =====================================================================================================================
def s02(M):
    o = sec(2, "System architecture", "Three processes, one database, a few folders. How a click becomes a ranked answer.")
    o += [P("The browser only ever talks to Apache. PHP pages own sessions, forms, validation and rendering. Anything that needs a trained model or "
            "a map lookup goes through one small PHP client (<font name='Mono'>ml_client.php</font>) to a Python Flask service that listens on "
            "<font name='Mono'>127.0.0.1:5000</font> only. That service loads its models once at start-up and answers JSON."),
          figure(345, G.d_architecture, "Figure 2.1  Components and who talks to whom. Dashed arrows are optional or best-effort; red marks the model path."),
          H2("Why a separate Python service"),
          *bullets(["The model code (scikit-learn, NumPy) lives in Python; PHP stays thin and never loads a pickle.",
                    "Models load <b>once</b> at boot, so a request costs milliseconds of scoring, not seconds of start-up.",
                    "The service binds to loopback and has CORS limited to localhost: only the PHP tier can call it. The base URL is an admin setting "
                    "(<font name='Mono'>ml_service_url</font>) so it can move to another host.",
                    "If it is down, <font name='Mono'>ml_request()</font> returns an <font name='Mono'>__error</font> array and the page shows a banner."]),
          H2("The Flask endpoints"),
          *table(["Route", "Purpose", "Module behind it"],
                 [["GET /health", "Liveness, destination count, cost-model metrics", "app.py"],
                  ["POST /recommend", "Ranked, explained destinations (Section 5)", "recommender/hybrid.py, explain.py"],
                  ["POST /predict-cost", "Cost, low-high band and category split for one place (Section 6)", "cost/predict.py"],
                  ["POST /itinerary", "Day-by-day plan for a destination (Section 8)", "itinerary/generate.py"],
                  ["POST /smart-plan", "One sentence in, a complete trip out (Section 9)", "bot/smartplan.py"],
                  ["POST /chat", "Chatbot turn with tool calls (Section 9)", "bot/chat.py, gemini.py, tools.py"],
                  ["POST /nearby", "Real places near a point, ranked (Section 10)", "geo/places.py"],
                  ["POST /route", "Round-trip or point-to-point route with stops (Section 10)", "geo/places.py"],
                  ["POST /similar", "Destinations like this one (Section 7)", "recommender/similar.py"],
                  ["POST /destination", "Profile for the guide and compare pages; also a name list", "recommender/profile_api.py"],
                  ["POST /authenticity", "Advisory 0-100 journal score (Section 11)", "trust/authenticity.py"],
                  ["GET /analytics/summary", "Aggregates for the insights page", "app.py (SQL)"],
                  ["GET /analytics/personal", "One member's spending summary", "app.py (SQL)"]],
                 [2.0, 4.2, 2.6], mono_cols=(0,)),
          P("Every route clamps its inputs (for example days to 1-60, budget to 1,000-2,000,000, interests intersected with the 20-tag vocabulary) and every "
            "unhandled exception becomes a JSON error with no stack trace.", "body"),
          H2("Where state lives"),
          *table(["Place", "Holds", "Notes"],
                 [["MySQL <font name='Mono'>project</font>", "accounts, journals and the three expense tables, media rows, storybooks, reports, audit log, rate limits, settings",
                   "mysqli, prepared statements for most writes; character set utf8mb4"],
                  ["<font name='Mono'>uploads/</font>", "public media after re-encoding", "its .htaccess turns the PHP engine off and denies script extensions"],
                  ["<font name='Mono'>private/camera/&lt;hash&gt;/</font>", "each member's camera roll", "deny-all .htaccess; read only through camera-img.php after an ownership check; "
                   "folder name is an HMAC of the email, not the email"],
                  ["<font name='Mono'>ml/artifacts/</font>", "pickled TF-IDF vectorizer, destination profiles, cost model; geocache JSON", "regenerable by rebuild_all; geocache lets repeat map queries skip the network"],
                  ["<font name='Mono'>ml/data/</font>", "scraped and derived JSON (catalog, listings, entries, coordinates, images)", "the input of the offline pipeline"],
                  ["<font name='Mono'>config/secret.key</font>", "32 random bytes for AES-256-CBC encryption of secret settings", "created on first use; folder is deny-all"],
                  ["Browser Cache Storage", "static assets (cache-first) and plans the user chose to keep offline", "service worker sw.js; nothing else about a visit is stored"]],
                 [2.2, 3.4, 3.4]),
          H2("Progressive web app and offline"),
          P("<font name='Mono'>sw.js</font> uses two caches. Static files (CSS, JS, fonts, images) are served cache-first because their URLs carry a <font name='Mono'>?v=</font> version. "
            "Pages are network-first: if the network fails, the worker returns a plan the member explicitly saved, or <font name='Mono'>offline.html</font>. "
            "Registration only happens on https or localhost, which is also why the phone camera needs https (Section 14)."),
          H2("Camera roll privacy, in one paragraph"),
          P("A photo taken in the browser is drawn to a canvas with its frame and filter, uploaded, sniffed by magic bytes, re-encoded by GD (which drops EXIF and GPS data), scaled "
            "to 1,600 px and given a 400 px thumbnail. It is stored under a hashed folder that Apache refuses to serve. The only way to read it is "
            "<font name='Mono'>camera-img.php</font>, which looks the photo up by id <i>and</i> owner; someone else's photo returns the same 404 as a missing one. "
            "Choosing a photo for a journal, storybook or post makes a separate, re-encoded public copy; the original stays private."),
          figure(318, G.d_requestflow, "Figure 2.2  One request, end to end: a recommendation search.")]
    return o


# =====================================================================================================================
def s03(M):
    o = sec(3, "Data sources and pipeline", "Where every number and sentence comes from, and how it reaches the models.")
    o += [P("The offline pipeline turns public web sources into rows in MySQL, features per journal, one profile per destination and a set of "
            "pickled artifacts. It is run by <font name='Mono'>python -m ml.rebuild_all</font>."),
          figure(278, G.d_pipeline, "Figure 3.1  The data pipeline, left to right. Red boxes are code in ml/; white boxes are data."),
          H2("Sources"),
          *table(["Source", "What is taken", "How", "Volume now"],
                 [["Wikivoyage (CC BY-SA)", "destination tree, coordinates, See / Do / Eat / Sleep listings with prices, intro text",
                   "MediaWiki API; raw wikitext cached on disk; source URL kept on every record", f"{nf(M['n_nodes'])} pages crawled, {nf(M['n_listings'])} listings, {nf(M['n_obs'])} parseable INR prices"],
                  ["Wikipedia", "pageviews (about 60 days), lead photo URL", "REST and query APIs; photos are hotlinked and credited, never downloaded",
                   f"{nf(M['n_prom'])} places scored; {nf(M['n_images'])} photos"],
                  ["Travel blogs and Tripoto", "rupee amounts with context, activity tags, sentiment, a snippet of 450 characters or less with its URL",
                   "robots.txt honoured, 1.5 s between requests, cached; <b>full text is never stored or republished</b>",
                   f"{nf(M['n_tripoto'])} trip reports; {nf(M['n_blog_obs'])} cost observations; {nf(M['n_blog_entries'])} entries"],
                  ["OpenStreetMap (Nominatim, Overpass) and Geoapify", "geocoding and points of interest, live", "queried on demand, results cached; Geoapify only when a key is configured", "on demand"],
                  ["Members' own journals", "text, dates, costs they enter", "the app itself; private until verified and approved", f"{M['n_user_journals']} row(s) today"]],
                 [1.6, 2.5, 2.6, 1.9]),
          H2("From listings to a destination catalog"),
          steps(["Crawl the Wikivoyage India tree (states, regions, cities, other destinations) and extract every <i>see/do/eat/drink/sleep/buy</i> listing.",
                  "Parse prices with a regular expression for rupee signs, 'Rs', 'INR' and ranges (a range collapses to its midpoint). Values outside 10-500,000 are rejected.",
                  "Group observed prices per destination into eat, sleep and fees. Estimate a per-person daily cost (formula below); fall back to the parent region's median when a place has fewer than 3 price observations.",
                  "Detect activities with the shared gazetteer, best months from sentences such as 'best time to visit', and a region from the page hierarchy.",
                  "Drop stub villages: a place is removed when its Wikipedia views are below 30 a day, <i>and</i> it has fewer than 12 listings, <i>and</i> fewer than 3 price observations.",
                  "Tier a place <i>mainstream</i> if it has 150 or more daily views or 15 or more See listings; otherwise <i>lesser known</i>.",
                  "Calibrate: scraped prices skew low and dated, so daily costs are multiplied by the median ratio between the hand-researched catalog and the scraped value on places present in both (clamped to 1.0-2.5)."]),
          formula(["daily = 3 x min(median meal, 1500) + 0.6 x min(median night, 15000)",
                   "        + 2 x min(median entry fee, 1500) + 250",
                   "rounded to the nearest Rs 50;  fallback medians: meal 12 %, night 45 % of the regional value"],
                  "estimate_daily  (ml/scrape/build_india_dataset.py)"),
          P("<b>Update.</b> This catalog price is no longer what the cost model learns from. The cost model now prices stays, meals, rides and fees from the individual observed listings (Section 6), and "
            "<font name='Mono'>ml/cost/refresh_base_costs.py</font> (run by rebuild_all after training) rewrites <font name='Mono'>destinations.base_daily_cost</font> from that model. The estimate above survives as the catalog value and as the source of the generated per-entry figures described below."),
          H2("Loading entries and the real-only policy"),
          P("<font name='Mono'>load_real_entries.py</font> writes the Wikivoyage and blog text into the journal tables, tagged with the domain "
            "<font name='Mono'>@real.journeyai</font> so these rows can be reloaded without touching real members. The text is the source text, with its URL kept in the address column for attribution."),
          callout("An earlier version of the project generated about 500 templated journals and about 4,000 taste-clustered ratings so that the models had something to learn from. "
                  "Because that data was generated to have the structure the models look for (the generator's own docstring says ratings were clustered by taste 'so collaborative filtering finds real neighbour structure'), any metric computed on it would be circular. It was deleted (commit <font name='Mono'>cc9f649</font>: seed accounts, journals, media and ratings removed; "
                  "<font name='Mono'>rebuild_all</font> no longer seeds). The generator survives only behind <font name='Mono'>--with-seed</font>, marked dev only. "
                  "Consequence: there are currently no collaborative-filtering ratings (Section 5).", "note", "WHY SYNTHETIC DATA WAS REMOVED"),
          callout("What is real and what is derived in the loaded entries. <b>Real:</b> the narrative text, its source URL, the observed listing prices (kept separately in "
                  "india_cost_observations.json) and the activities detected from the text. <b>Generated by the loader:</b> the visit dates (a random day in a peak month of 2025), the stay length "
                  "per entry type, and the rupee split across food, transport, stay and shopping, which is the destination's estimated daily cost times the days, split with random "
                  "proportions. These per-entry rupee figures are therefore <b>not</b> traveller receipts and must not be treated as ground truth. Section 6 explains how the cost model handles this.",
                  "caveat", "HONEST DATA NOTE"),
          H2("Feature extraction and rebuild order"),
          P("After loading, <font name='Mono'>extract_features</font> writes one row per journal into <font name='Mono'>journal_features</font> and fits the TF-IDF vectorizer; "
            "<font name='Mono'>build_profiles</font> aggregates those rows into one profile per destination; <font name='Mono'>train_cost_model</font> writes the cost artifact. "
            "Only journals that passed the trust layer enter: approved by moderation, from a verified account (system accounts count), and at most three per member per destination. "
            "Section 4 describes the features; Section 11 the filter."),
          P("The pipeline is deterministic given the cached raw pages: scrapers read from <font name='Mono'>ml/data/raw/</font> before touching the network, random choices use a fixed seed (7), and models use "
            "fixed random states (42 for the cost model, 7 for SVD and IsolationForest).")]
    return o


# =====================================================================================================================
def s04(M):
    n = M["nlp"]
    s, a, nm, q = n["sentiment"], n["activity_tags"], n["normalization"], n["query_understanding"]
    o = sec(4, "NLP and feature extraction", "Turning free text into the small set of numbers and tags the recommender can use.")
    o += [P("No transformer is used here, by choice: each step is a transparent rule, lexicon or classical statistic that a reviewer can read in a few lines and that runs in milliseconds."),
          H2("4.1  Tokenizer"),
          P("Lower-case the text, take runs of letters and apostrophes (<font name='Mono'>[a-z']+</font>), and lemmatise each token with NLTK's WordNet lemmatiser "
            "(so 'temples' and 'temple' meet). If the WordNet data is missing the code degrades to plain tokens. The function lives in its own tiny module so the pickled vectorizer can find it again after reloading."),
          H2("4.2  Destination normalisation"),
          P("Journal cities are free text. <font name='Mono'>normalize.py</font> maps (city, country, text) to one canonical catalog name with a fixed cascade, stopping at the first hit:"),
          steps(["exact match on 'City, Country';", "alias table (Bombay to Mumbai, Calcutta to Kolkata, Benares to Varanasi, Pondicherry to Puducherry...);",
                 "exact match on city alone;", "fuzzy match (difflib ratio at least 0.82) on city, then on the full string;",
                 "fallback: scan the free text for any known city name; otherwise return nothing."]),
          H2("4.3  Activity tags"),
          P(f"A hand-curated gazetteer maps {M['n_gaz']} surface words and phrases ('ghat', 'aarti', 'souvenir', 'scuba'...) onto 20 canonical activities: "
            "beach, trekking, food, nightlife, history, temples, museums, shopping, wildlife, adventure, relaxation, photography, nature, culture, backwaters, mountains, desert, snow, diving, architecture. "
            "The same 20-tag vocabulary is used by the catalog, the recommender, the route builder and the chatbot, so an interest typed anywhere means the same thing everywhere."),
          H2("4.4  Sentiment"),
          P("A lexicon method with two refinements. Each positive word counts +1 and each negative word -1 (66 and 55 domain-tuned words). Looking back up to two tokens, a negator "
            "('not', 'never', 'without'...) flips the sign and an intensifier ('very', 'absolutely'...) multiplies the weight by 1.6."),
          formula(["score  = sum(sign x weight over matched words)",
                   "norm   = clip( score / (hits + 1), -1, 1 )",
                   "label  = positive if norm > 0.15,  negative if norm < -0.15,  else neutral"], "sentiment  (ml/nlp/extract_features.py)"),
          H2("4.5  Travel style and budget bucket"),
          P("Budget buckets use quantile edges computed over the corpus's daily spend (20th, 40th, 60th, 80th percentiles), giving "
            "shoestring, budget, mid, premium and luxury. The style classifier is an ordered rule list, first match wins: keyword families "
            "(luxury, backpacker, family, solo, adventure) in the text; then premium or luxury bucket gives luxury; shoestring gives backpacker; "
            "trekking, adventure or mountains tags give adventure; otherwise mid-range."),
          H2("4.6  TF-IDF and destination profiles"),
          P("A <font name='Mono'>TfidfVectorizer</font> over each journal's text uses unigrams and bigrams, minimum document frequency 3, maximum 0.6, at most 4,000 terms, and the lemmatising tokenizer. "
            "Because the vectorizer is fitted once and pickled, nothing is vectorised at request time except the user's short interest string. "
            "<font name='Mono'>build_profiles</font> then reduces each destination to one object: mean TF-IDF vector, normalised activity histogram, mean sentiment, "
            "journal count, cost and duration priors, region, tier, best months and attractions."),
          H2("4.7  Measured quality"),
          P("<font name='Mono'>ml/eval/nlp_eval.py</font> was run at build time for this guide. The sentiment set is 60 sentences written and labelled by the team (20 per class), the "
            "normalisation tests are messy spellings generated from the catalog, and the query set is 40 labelled planner requests."),
          *table(["Test", "Result", "How it was measured"],
                 [["Sentiment, 3 classes", f"accuracy {s['accuracy']*100:.1f} %, macro-F1 {s['macro_f1']:.3f} (majority-class baseline {s['majority_class_baseline_accuracy']*100:.1f} %)",
                   f"{s['sentences']} hand-labelled sentences; positive recall {s['per_class']['positive']['recall']:.2f}, neutral precision {s['per_class']['neutral']['precision']:.2f}, negative recall {s['per_class']['negative']['recall']:.2f}"],
                  ["Activity tags from text", f"precision {a['precision']:.3f}, recall {a['recall']:.3f}, F1 {a['f1']:.3f}",
                   f"top-4 tags from real Wikivoyage text vs the hand-curated tags of {a['destinations compared']} destinations"],
                  ["Name normalisation", "; ".join(f"{r['test']} {r['accuracy (%)']:.1f} %" for r in nm["table"][:5]),
                   f"200 cases per test from the catalog; false-positive rate on junk strings {nm['false_positive_rate (%)']:.1f} %"],
                  ["Alias handling", f"{nm['table'][5]['accuracy (%)']:.1f} % of {nm['table'][5]['cases']} aliases", "Bombay, Madras and similar"],
                  ["Planner query parsing", f"{q['all fields correct (%)']:.0f} % of {q['queries']} queries fully correct", "origin, destination, days, budget, interests"]],
                 [1.6, 3.0, 3.6]),
          callout("Read these numbers with care. The sentiment sentences and the 40 planner queries were written by the same team that wrote the rules, so 100 % on queries shows the parser "
                  "handles the patterns it was built for, not that it generalises. Activity-tag agreement is only about 44 %, which is expected: the curated list names 4 headline activities per place while text mentions many. "
                  "None of these is a held-out benchmark from independent annotators.", "caveat")]
    return o


# =====================================================================================================================
def s05(M):
    o = sec(5, "Recommender", "A hybrid, explainable ranking: three signals blended, then adjusted for budget, season and distance.")
    o += [P("<font name='Mono'>ml/recommender/hybrid.py</font> scores every destination for one request and returns the top N with an explanation. "
            "Each of the three base signals is min-max scaled to 0..1 across destinations, so they can be mixed."),
          figure(210, G.d_recommender, "Figure 5.1  Recommender flow. Constants are the ones in hybrid.py."),
          H2("5.1  The three base signals"),
          *table(["Signal", "Weight", "Computation", "When it is neutral"],
                 [["Content", "0.38", "Interest one-hot over the 20 activities; cosine with each destination's activity histogram", "no interests: 0.5 for everyone"],
                  ["Semantic", "0.22", "Interest words through the fitted TF-IDF vectorizer; cosine with each destination's mean TF-IDF; then 0.75 x similarity + 0.25 x mean sentiment, re-scaled", "no interests: only the sentiment part"],
                  ["Collaborative", "0.30", "Item-item cosine over a user x destination rating matrix (average of 1-5 stars; a saved plan counts as 4). "
                   "Seed ratings are the user's ratings minus 2.5, floored at 0; score = similarity x seeds", "unknown user, or no rating above 2.5: global popularity = average rating x log(1 + number of ratings)"]],
                 [1.1, 0.7, 4.2, 2.4]),
          formula(["base  = 0.38 x content + 0.22 x semantic + 0.30 x collaborative",
                   "score = base + 0.12 x prominence"], "step 1"),
          callout("The collaborative matrix is read from the <font name='Mono'>interactions</font> table when the service starts, and that table is empty today because the synthetic ratings were deleted "
                  "and no member has rated yet. With no rows the collaborative score is the same constant for every destination, so it changes no ranking. It begins to matter once members rate or save places "
                  "and the service is restarted. Journal likes and pins do not feed it.", "caveat", "CURRENT STATE"),
          H2("5.2  Prominence"),
          P("A real-world attention prior stops famous places being buried: <font name='Mono'>log(1 + Wikipedia daily views) / log(1 + the maximum)</font>, between 0 and 1. "
            "Destinations without scraped views fall back to 0.75 (mainstream) or 0.35 (lesser known). Weight 0.12."),
          H2("5.3  Distance-aware ranking"),
          P("When the request has an origin and a trip length (day, weekend, short, long), the origin is geocoded and the road distance is estimated as 1.3 times the straight-line (haversine) distance. "
            "Each trip length has a comfortable window and a typical distance; inside the window the fit is between 0.8 and 1.0 (a soft bell on log distance), outside it decays exponentially."),
          formula(["window (km):  day 0-90,  weekend 60-380,  short 180-950,  long 700-4500;  typical: 30, 170, 420, 1600",
                   "inside window:  fit = 0.8 + 0.2 x exp( -0.5 z^2 ),   z = ln( km / typical ) / 0.75",
                   "outside:        fit = 0.8 x exp( -gap / scale ),     scale = max(120, 0.35 x window width)",
                   "score = score x ( 0.3 + 0.7 x fit );   short and long trips also x ( 0.55 + 0.45 x prominence )",
                   "unknown coordinates: score x 0.6"], "step 2"),
          figure(188, G.d_window, "Figure 5.2  The distance-fit curves for the four trip lengths, plotted from the same formula as hybrid._window_fit. The small steps at the window edges are in the code: the inside fit at an edge is a little above the 0.8 that the outside formula starts from."),
          P("A coarser region-adjacency model (<font name='Mono'>proximity.py</font>, distance 0 to 3 between seven Indian regions, 2.5 for neighbouring countries, 3 for long-haul) "
            "remains for the older 'near me' selector; it blends in with weight 0.10 only when an origin region is given."),
          H2("5.4  Budget, season, discovery"),
          formula(["budget fit = 1 if predicted <= budget, else max(0, 1 - (predicted - budget) / budget)",
                   "score = score x ( 0.5 + 0.5 x budget fit )          never a hard drop",
                   "season fit = 1.0 if the month is a peak month, 0.7 if within one month, else 0.4",
                   "score = score x ( 0.9 + 0.1 x season fit )",
                   "score += 0.04  if lesser-known and base > 0.5      hidden-gem boost"], "step 3"),
          P("Predicted cost for all destinations comes from one batched call to the cost model (Section 6). A destination is flagged <i>within</i>, <i>stretch</i> or <i>over</i> "
            "(over means more than 1.25 x budget). The final list prefers in-budget places; if fewer than N fit, it is topped up with the nearest-cost over-budget ones so the user never sees an empty page."),
          H2("5.5  Cold start"),
          *bullets(["<b>New user, no ratings:</b> collaborative falls back to popularity (or to zeros if nobody has rated), content and semantic carry the ranking.",
                    "<b>No interests typed:</b> content is a flat 0.5 and the ranking is driven by prominence, budget, season and distance.",
                    "<b>New destination with no journals:</b> it still has the catalog activity tags, text and prices from Wikivoyage, which is why the profile is built from the catalog as well as journals."]),
          H2("5.6  Explanations"),
          P("<font name='Mono'>explain.py</font> writes a short paragraph for each result, strictly from facts the system holds: the interests that matched the destination's top activities, up to three real attractions, "
            "distance and drive time if known, best months and whether the chosen month suits them, cost per day against the budget, and whether the collaborative or lesser-known signals mattered. "
            "It also returns <i>evidence</i>: the dominant factors and up to two positive journals about the place. There is no generative model in this step."),
          *M["reco_example"]]
    return o


# =====================================================================================================================
def s06(M):
    return M["cost_section"]


# =====================================================================================================================
def s07(M):
    o = sec(7, "Similar destinations", "If you liked X, try Y: latent semantic analysis over what travellers and Wikivoyage wrote.")
    o += [P("Plain keyword overlap misses that 'shrine' and 'temple', or 'coast' and 'beach', mean the same thing. <font name='Mono'>similar.py</font> therefore "
            "describes each destination with one dense vector and compares vectors with cosine similarity."),
          figure(212, G.d_lsa, "Figure 7.1  How a destination embedding is built and used."),
          steps(["Stack every destination's mean TF-IDF vector and reduce it with truncated SVD to k = min(48, ...) latent axes (random state 7), then L2-normalise. This is latent semantic analysis: words that co-occur load on the same axis.",
                 "Append the 20-tag activity histogram (weight 0.7), the log of the typical daily cost scaled to 0..1 (weight 0.35) and a one-hot region (weight 0.25); the text block has weight 1.0.",
                 "L2-normalise the whole vector. Similarity of two destinations is then a dot product (cosine).",
                 "Find the destination by name or city, rank all others by dot product (the cosine of the normalised vectors), optionally keep only the same region or only other regions.",
                 "Explain each neighbour: shared activities (each above 0.10 in both profiles), 'same region', and 'similar daily cost' when the two costs are within 25 %."]),
          P("The weights are fixed by judgement, not tuned against a labelled benchmark: the text carries the most meaning, what you do there comes next, price and region only break ties. "
            "The result feeds the 'If you like this, try' block on the trip page and the compare tray. There is no ground-truth set of 'similar places' in this project, so similarity quality has been checked only by reading examples, not by a metric."),
          *M["similar_example"]]
    return o


# =====================================================================================================================
def s08(M):
    o = sec(8, "Itinerary generator", "A template-and-data planner: no language model, deterministic given its seed.")
    o += [P("<font name='Mono'>ml/itinerary/generate.py</font> builds a day-by-day plan for one destination, trip length (1-21 days), style, month and party size. "
            "It composes the plan from the destination's real attractions and activity tags using fixed sentence templates per activity."),
          H2("How a day is composed"),
          steps(["Resolve the destination: exact catalog name, then the city part. For a place outside the catalog, build a temporary destination from live nearby points of interest (Section 10), deriving activity tags from the categories found.",
                 "Ask the cost model for the trip total and divide by days to get a per-day cost; the same band and category split are returned.",
                 "Shuffle the attraction list with a seeded random generator and use it as a pool that recycles when empty.",
                 "For each day pick three activities with timing rules: nightlife is evening-only; wildlife, trekking and temples are preferred in the morning; the afternoon never repeats the morning or midday choice.",
                 "Fill templates such as 'Morning darshan at {attraction}' or 'Golden-hour shoot at {attraction}'. Day 1 morning is 'Arrive and settle in'; the last day is a leisurely morning and 'Depart'.",
                 "De-duplicate: a slot text may not repeat within a day (up to 15 re-draws). Tiny places with one attraction and one tag fall back to generic slots such as 'Browse a local market'.",
                 "Attach meals (three alternatives each for breakfast, lunch, dinner) and a stay tier from the travel style ('Boutique mid-range hotel', 'Adventure camp / lodge'...)."]),
          H2("Season and cost"),
          P("The month is mapped to a season (December-February winter, March-May summer, June-September monsoon, October-November autumn) for the cost model; the best months shown come from the destination's detected peak months. "
            "Every day carries the same <i>estimated cost</i> (total / days); the plan does not price individual activities."),
          callout("The default seed is derived from the destination name with Python's built-in <font name='Mono'>hash()</font>, which is randomised per process. The same request can therefore produce a "
                  "different (equally valid) arrangement after the service restarts, unless a seed is passed. Plans a member saves are stored, so they do not change.", "caveat"),
          callout("The generator does not know opening hours, travel times between attractions or ticket prices. It proposes a sensible shape for each day from real place names; the route builder (Section 10) is what adds distances.", "note"),
          H2("Other rule-based planners"),
          P("<b>Packing list</b> (<font name='Mono'>ja-packing-engine.php</font>) is a rule engine in PHP: climate flags from region and month, activities from the chips the member picks, "
            "quantities from days and party size, and a short 'why' beside every item. <b>Notifications</b> on the dashboard are generated on page load from a few cheap queries and de-duplicated over 30 days; there is no background worker.")]
    return o


# =====================================================================================================================
def s09(M):
    o = sec(9, "Smart plan and chatbot", "A one-sentence planner that always works, and an LLM assistant that is allowed to fail.")
    o += [H2("9.1  Smart plan: one sentence in, a trip out"),
          P("<font name='Mono'>smartplan.py</font> takes text such as 'weekend from Ahmedabad, temples and food under 15k' and returns a complete result. Parsing is a regular-expression "
            "parser, so it works with no network; if a Gemini key exists, the model is asked for strict JSON as a cleaner parse, but the rule-based parse is always kept as a safety net and its interests are merged in."),
          *table(["Field", "How it is found"],
                 [["Origin and destination", "'from X to Y' or 'X to Y route' (two-point trip); 'from X'; 'around/in/at X'; 'near X'; 'near me' is a sentinel the caller must resolve (the browser's location). A bare place name is treated as a place to plan."],
                  ["Days", "'3 days', 'weekend' = 2, 'day trip' or '1 day' = 1; default 2. Mode: day (1), weekend (2), short (3-5), long (6+)."],
                  ["Budget", "after 'budget/under/rs/inr', or a bare number of 3+ digits; 'k' and 'thousand' multiply by 1,000."],
                  ["Interests", "a small synonym table plus every gazetteer word longer than 3 letters, mapped to the 20 tags."]],
                 [1.6, 5.6]),
          P("<b>What it builds depends on the parse.</b> A fixed 'A to B' gives a point-to-point route; a one-or-two day trip gives a round-trip route of real nearby stops; a longer trip to a known place "
            "gives that place's itinerary; 'from X' with 3+ days, or interests with no origin, gives ranked destination recommendations with the distance window of Section 5. "
            "The cost shown for local and point-to-point trips comes from the cost model for the named place (India-wide prices if it has no data of its own) and carries a confidence label; the earlier hand-set daily figure is gone."),
          H2("9.2  Chatbot"),
          figure(206, G.d_chat, "Figure 9.1  One chat turn, and the fallback that keeps it useful when the LLM is unavailable."),
          P("<font name='Mono'>chat.py</font> sends the system rules, the last 8 turns, the new message and the tool schemas to Gemini. The model decides which of nine tools to call; each is a thin wrapper "
            "over the same code the website uses:"),
          *table(["Tool", "Does"],
                 [["find_nearby_places", "real places near a location, optionally with a keyword such as 'ice cream'"],
                  ["recommend_destinations", "the hybrid recommender with explanations"],
                  ["plan_itinerary / estimate_cost", "itinerary and cost band for a destination"],
                  ["build_trip_route", "multi-stop route with where to eat"],
                  ["find_user_media", "public member photos and videos for a place"],
                  ["get_my_journal_history", "the signed-in member's own past trips"],
                  ["booking_links", "deep links to flight and hotel searches (not live bookings)"],
                  ["web_search", "short DuckDuckGo HTML snippets, best effort"]],
                 [2.2, 5.0], mono_cols=(0,)),
          H2("Deadlines, fallbacks and safety"),
          *bullets(["<b>Deadlines.</b> One total budget (45 s by default; 10 s when used only to parse a plan) is shared across every model and retry, not applied per request. PHP waits at most 25 s.",
                    "<b>Model chain.</b> A configured primary model, then other free-tier Gemini models; a 429 or 5xx is retried once after 2 s and then the next model is tried; a 404 skips a retired model.",
                    "<b>Tool loop cap.</b> At most 4 rounds of tool calls, then the model is asked for a final summary. Model parts are echoed back verbatim because newer Gemini models require their thought signatures.",
                    "<b>Fallback.</b> Any LLM failure returns an answer from the rule-based planner or a direct nearby search; only if that finds nothing does the user see a clear 'try Plan a Trip' message.",
                    "<b>Identity.</b> The email passed to the history tool comes from the PHP session. The model can never choose whose trips to read.",
                    "<b>Input caps.</b> Message 2,000 characters, the last 10 history turns accepted (8 sent to the model), tool results are dictionaries; unknown tools and bad arguments become an error object, never an exception.",
                    "<b>Grounding.</b> The system prompt tells the model not to invent places, distances or costs and to call a tool instead. This reduces but cannot eliminate wrong statements: the reply text is the model's."])]
    return o


# =====================================================================================================================
def s10(M):
    o = sec(10, "Geo and routing", "Finding real places, ranking them by fame and distance, and ordering stops into a route.")
    o += [P("<font name='Mono'>ml/geo/places.py</font> is the map layer used by the nearby search, the route builder, the chatbot and the distance-aware recommender."),
          H2("Geocoding"),
          steps(["Cache hit for the lower-cased query (JSON files named by an MD5 of the key under ml/artifacts/geocache/).",
                 "Coordinates we already hold for every served destination (instant, no network).",
                 "A recent miss (under one hour) returns nothing without asking again.",
                 "Nominatim restricted to India; if empty, Nominatim worldwide. Successes and misses are cached. (For nearby searches, Geoapify's geocoder and places API are used instead when a key is configured; the Overpass path is the fallback.)"]),
          H2("Points of interest and the Overpass race"),
          P("Overpass queries use the OSM tags for 13 category groups (temples, history, gardens, nature, wildlife, museums, beach, food, fun, hotels, attractions, shopping, towns), grouped four at a time so each request stays light "
            "and run in parallel. The public Overpass servers often rate-limit or time out, so each query is posted to <b>all three mirrors at once</b> and the first valid JSON answer wins; a mirror that failed is skipped for 5 minutes. "
            "Results are cached for 7 days, and an empty answer is never cached because it usually means the upstream was down."),
          H2("Fame and ranking"),
          formula(["fame  = 3.0 if wikidata + 2.0 if wikipedia + 1.5 if heritage + 0.5 x stars + 0.5 if tourism=attraction",
                   "score = 1.2 x fame + 2.0 x ( 1 - min(distance / max distance, 1) )"], "nearby_places scoring"),
          P("A famous place farther away therefore beats an unnamed nearby one. Curated local landmarks (hand-researched for Gujarat, derived from Wikivoyage listings for the rest of India) are injected or boosted, "
            "because OpenStreetMap under-tags some genuinely well-known places. If interests are given, about 70 % of the slots are filled from matching categories and the rest from a round-robin of other categories for variety; with no interests the list is a balanced round-robin."),
          *table(["Trip mode", "Search radius", "Days"], [["day", "120 km", "1"], ["weekend", "300 km", "2"], ["short", "600 km", "4"], ["long", "3,000 km", "8"]], [1.5, 1.5, 1.0]),
          H2("Round-trip routes"),
          P("Choose stops (interest-focused if the member picked interests, otherwise varied by category, at most n/2+1 per category), then order them with a <b>greedy nearest-neighbour</b> walk from the origin, "
            "and add the return leg. A second, different set of stops is returned as an alternative route, and each stop gets its three nearest food places and, if missing, a Wikipedia thumbnail. "
            "Nearest-neighbour is a heuristic: it is fast and good for 3-8 stops but not guaranteed shortest. Distances are straight-line haversine, not road distance."),
          H2("Point-to-point routes and corridor scoring"),
          P("For 'from A to B', candidates are gathered around both ends and around the midpoint (three points along the line for trips above 200 km). Each candidate is projected onto the straight line from A to B "
            "with an equirectangular approximation (accurate enough at regional scale)."),
          figure(176, G.d_corridor, "Figure 10.1  Corridor scoring: progress along the line versus sideways detour."),
          P("The journey is divided into n equal segments by <font name='Mono'>along_km</font>, the best candidate (lowest <font name='Mono'>1.5 x perp_km - fame</font>) fills each segment first so stops spread over the whole trip instead of clustering at the famous end cities; "
            "empty segments are topped up from the next-best candidates, and the chosen stops are sorted by progress."),
          callout("OpenStreetMap data is crowd-sourced and uneven: small towns can have few tagged places, which shows up as thin routes (Section 14). The tool shows names, categories and straight-line legs; it is not a navigation product.", "caveat")]
    return o


# =====================================================================================================================
def s11(M):
    o = sec(11, "Trust and safety", "Layers that make abuse slow, visible and reversible; none is trusted alone.")
    o += [figure(246, G.d_trust, "Figure 11.1  What happens to something a member wants to publish."),
          H2("11.1  Trust levels and moderation"),
          *table(["Level", "Rule", "Effect"],
                 [["none / new", "unknown account, or email not verified", "can write privately; cannot publish anything"],
                  ["verified", "email verified, but under 3 days old or fewer than 2 approved public items", "can publish; items wait as <i>pending</i> for a moderator; 5 public journals or storybooks a day"],
                  ["trusted", "verified, account at least 3 days old, at least 2 approved public items, no upheld reports; or admin / override / system account", "published instantly as <i>approved</i>"],
                  ["restricted", "override, or 2 or more upheld reports in 90 days", "publishing switched off"]],
                 [1.1, 3.6, 3.0]),
          P("Public journals first pass content checks; any flag holds the item as pending with the reasons: fewer than 40 words, more than two links, vocabulary diversity below 0.35, runs of 10 repeated characters, mostly capitals, "
            "an exact copy of another member's text (MD5 of normalised words) or a near copy (5-word shingles, Jaccard similarity of 0.6 or more against the latest 300 public journals), an implausible cost per day (under Rs 150 or over Rs 60,000), "
            "or impossible dates (return before visit, more than 30 days in the future, longer than 120 days). Editing an already-shared journal re-runs the checks."),
          H2("11.2  Reports"),
          P("Anyone signed in can report a journal, photo, storybook or comment with a reason (fake, spam, unsafe, copyright, other). A reporter can report an item once; the count of distinct reporters is "
            "restricted to accounts that are email-verified and at least three days old, so a fresh sock-puppet cannot hide someone's work. At 3 distinct qualifying reporters the item is set to <i>hidden</i> automatically "
            "until a moderator looks. Members cannot report their own items and are limited to 20 reports an hour. Moderation actions are written to an audit log."),
          H2("11.3  Authenticity score (advisory)"),
          P("<font name='Mono'>trust/authenticity.py</font> returns a 0-100 score with reasons. It starts at 100 and subtracts for each signal; the figures below are the code's."),
          *table(["Signal", "Penalty"],
                 [["fewer than 25 words / 25 to 59 words", "-25 / -8"],
                  ["type-token ratio under 0.35 (repetitive); one word above 18 % of the text; fewer than 70 % unique sentences", "-18, -10, -18"],
                  ["over 35 % capitals; links (each); a phone number; promotional words above 4 % of the text", "-10; -15 each (max -30); -20; -20"],
                  ["near-duplicate: highest TF-IDF cosine against journals by <i>other</i> authors, 0.75 or more / 0.90 or more", "-25 / -45"],
                  ["IsolationForest (150 trees, contamination 0.05) over ten text features flags the entry as unusual, and nothing else has been flagged", "-6"],
                  ["concrete numbers or a long entry (over 120 words)", "+3"]],
                 [5.2, 2.0]),
          P("Levels: 70 or more 'likely genuine', 45-69 'needs a look', below 45 'likely low quality or copied'. The forest learns what is normal for this site from the corpus itself (no labels). "
            "The score is shown to moderators in the queue and is never used to hide or delete content."),
          H2("11.4  Accounts, sessions and requests"),
          callout("Limits that remain: the CSP still allows inline scripts; a few queries use <font name='Mono'>mysqli_real_escape_string</font> on values already validated rather than prepared statements; "
                  "uploaded videos are stored as received (MP4 and WebM metadata is not stripped); there is no two-factor authentication; and the 'trusted' rule can be reached by two real approved posts, so moderation, not the score, is the real gate.", "caveat"),
          *table(["Control", "How it works"],
                 [["Passwords", "<font name='Mono'>password_hash</font> (bcrypt via PASSWORD_DEFAULT); a legacy plaintext row is accepted once and upgraded to a hash on login. Common passwords and disposable email domains are checked at sign-up."],
                  ["Login and registration", "6 recent failures lock an email and IP pair, 40 attempts per 15 minutes per IP; sign-up has a hidden bot field and minimum fill time; session id regenerated on login."],
                  ["Session epoch", "<font name='Mono'>signup.session_epoch</font> increases on a password change or reset; any session carrying an older value is signed out, so a stolen session dies when the password changes."],
                  ["One-time tokens", "password-reset and email-verify tokens are random, emailed raw and stored only as a SHA-256 hash; the forgot-password page gives the same answer whether or not the address exists."],
                  ["CSRF", "per-session random token compared with <font name='Mono'>hash_equals</font>, sent as a form field or an X-CSRF-Token header on every state-changing POST; logout is POST-only."],
                  ["Rate limits", "a sliding-window table (<font name='Mono'>rate_limits</font>): for example 120 searches a minute per IP, 40 uploads an hour, 80 camera uploads an hour, 5 public posts a day for non-trusted accounts, 3 reset emails an hour per address."],
                  ["Transport and headers", "Content-Security-Policy, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy (camera allowed for the site only), Cross-Origin-Opener-Policy; HttpOnly, SameSite=Lax, strict-mode session cookies; HSTS and Secure cookies when served over https. SMTP and other secret settings are AES-256-CBC encrypted in the settings table, with the key in a deny-all folder."],
                  ["Media pipeline", "type read from the first bytes, not the file name; over 25 megapixels refused; GD re-encodes (EXIF and GPS dropped, rotation kept), long side 2,400 px; "
                   "SHA-256 duplicate check across accounts; 40 MB per file, 400 MB per account; random names; no code can run in uploads/."]],
                 [1.5, 5.7])]
    return o


# =====================================================================================================================
def s12(M):
    o = sec(12, "Front end and UX system", "One brand layer, one motion layer, and pages that still work with scripts off.")
    o += [H2("12.1  Brand tokens"),
          P("Colour, type and radius are CSS custom properties in <font name='Mono'>css/journeyai-brand.css</font>, with a dark theme that swaps the same tokens. The palette is the one this guide uses."),
          Diagram(60, lambda c, w, h: _swatches(c, w, h)),
          P("Headings use Fraunces (an optical-size serif), body text Instrument Sans, figures Bricolage Grotesque, handwritten accents Caveat; each has a system fallback. "
            "This PDF substitutes Georgia and Segoe UI because the web fonts are not installed on the build machine. The accent fill is chosen so white text on it passes a 4.5:1 contrast ratio, and a separate darker accent is used as text.", "small"),
          H2("12.2  Motion system"),
          P("<font name='Mono'>js/motion.js</font> and <font name='Mono'>css/motion.css</font> form a progressive enhancement: reveal states are added by script, so with JavaScript off every page simply shows. "
            "Only transform, opacity and counter text animate; layout properties never do. Everything is disabled under <font name='Mono'>prefers-reduced-motion</font> and when printing."),
          *bullets(["<b>Markup API:</b> <font name='Mono'>data-mo</font> (rise, fade, scale, draw), <font name='Mono'>data-count</font> counts a number up to its real value, <font name='Mono'>data-mo-parallax</font> drifts an image.",
                    "<b>Automatic:</b> first-paint entrance of the heading and up to three blocks, scroll reveals on cards, bars that grow from their baseline, image fade-in, press-scale and ripple, a thin scroll-progress bar.",
                    "<b>Page changes:</b> the View Transitions API cross-fades between same-origin pages (130 ms out, 340 ms in); browsers without it navigate normally."]),
          H2("12.3  Landing page"),
          P("The home page is a pinned, scroll-driven journey. <font name='Mono'>landing.js</font> uses a single scroll scheduler: scroll events only request one animation frame; inside it all layout reads happen before any writes, "
            "so there is no layout thrashing. It handles a hero cross-fade with parallax, the planner bar, a pinned section in which an SVG route is drawn segment by segment (stroke-dashoffset) and a pin travels along it, one stop card at a time, as the visitor scrolls, one-shot reveals, a draggable photo reel, and a button that leans toward the pointer. "
            "The numbers on the page (places, journals) are read from the database, and the sample itinerary and destination facts come from the live ML service and are cached for a day; if the service is down the page renders without them."),
          H2("12.4  Command palette"),
          P("Ctrl or Cmd + K (or '/', or any Search button) opens one dialog that jumps to a page, finds destinations as you type (a rate-limited, prepared <font name='Mono'>LIKE</font> query in suggest.php, 120 a minute per IP), "
            "runs <font name='Mono'>plan: &lt;sentence&gt;</font> through the smart planner, or performs actions such as switching theme. It traps focus, closes on Esc, supports arrow keys and returns focus to where it was."),
          H2("12.5  Destination guide, compare and live cost helper"),
          *bullets(["<b>Destination guide</b> (<font name='Mono'>destination.php</font>): real facts only. Description cleaned of wiki residue, up to eight landmarks, activity shares, best months, cost for 1, 3, 5 and 7 days from the cost model, and a source link.",
                    "<b>Compare</b> (<font name='Mono'>compare.php</font>): two or three destinations side by side from the same profile data; the tray is kept in sessionStorage and works if storage is blocked.",
                    "<b>Live cost helper:</b> <font name='Mono'>cost-estimate.php</font> is a read-only JSON endpoint behind the planner; it clamps inputs and returns a cost for a named place or three reference places (lower-cost, typical, pricier)."]),
          H2("12.6  Offline, print and accessibility"),
          *bullets(["Saving a trip for offline stores that page in Cache Storage; <font name='Mono'>offline.html</font> lists saved plans when the network is gone.",
                    "A print stylesheet gives trip pages and storybooks a clean paper layout; storybooks have a dedicated print view.",
                    "Skip link and a main landmark on pages, a no-flash theme script, labelled dialogs, and an automated check that no page scrolls sideways at phone width (Section 13)."])]
    return o


def _swatches(c, w, h):
    sw = [("#123524", "deep green"), ("#1E7B4F", "green"), ("#E63457", "watermelon"), ("#FFF6EE", "cream"), ("#FFE9DC", "sand"), ("#B6E36B", "lime")]
    cw = (w - 20) / len(sw)
    for i, (hx, nm) in enumerate(sw):
        x = 10 + i * cw
        c.setFillColor(colors.HexColor(hx))
        c.setStrokeColor(RULE)
        c.roundRect(x, 24, cw - 8, 28, 4, fill=1, stroke=1)
        label(c, x, 12, nm, 7.4, INK, SANS_B)
        label(c, x, 3, hx, 6.6, MUTE, MONO)


# =====================================================================================================================
def s13(M):
    t = M["tests"]
    o = sec(13, "Testing and evaluation", "What was measured, how, and what was not validated.")
    o += [P("There is no unit-test framework. Nine standalone Python scripts in <font name='Mono'>tests/</font> exercise the running site over HTTP (Apache, MySQL and the ML service must be up); "
            "each creates throw-away <font name='Mono'>@test.invalid</font> accounts, then deletes them and everything they made. Two evaluation scripts in <font name='Mono'>ml/eval/</font> measure the models."),
          H2("13.1  Site test suites"),
          *table(["Suite", "Covers", "Result"],
                 [[r[0], r[1], r[2]] for r in t["rows"]] + [["<b>Total</b>", "", f"<b>{M['tests_pass']} of {M['tests_total']}</b>"]],
                 [1.6, 4.6, 1.2], mono_cols=(0,)),
          callout(f"Measured by running every script in tests/ against the local site on {M['date']}. Eight suites passed fully. <b>community_test.py passed 19 of 28 checks:</b> its nine failures are the checks named "
                  "writer has drag-and-drop photo area, writer has live counts, post shared, feed shows the post as a masonry card, like and comment buttons share one actions row, user text is escaped, load-more returns JSON cards, "
                  "writer saves over XHR, and profile shows a public handle. The last redesign of those pages and "
                  "the moderation queue (a new member's post is held as pending, so it does not appear on the feed or load-more JSON) are the likely cause, but this was not investigated, so treat the suite as stale rather than the pages as broken, until checked.", "measured"),
          H2("13.2  Model evaluation"),
          *table(["What", "Measured", "Method"],
                 [["Sentiment", f"{M['nlp']['sentiment']['accuracy']*100:.1f} % accuracy, macro-F1 {M['nlp']['sentiment']['macro_f1']:.3f}", "60 team-labelled sentences (Section 4.7)"],
                  ["Activity tags", f"F1 {M['nlp']['activity_tags']['f1']:.3f}", "text-derived vs hand-curated tags"],
                  ["Destination matching", f"{M['nlp']['normalization']['table'][3]['accuracy (%)']:.1f} % with a one-letter typo; {M['nlp']['normalization']['false_positive_rate (%)']:.1f} % false positives on junk", "200 generated cases per variant"],
                  ["Planner parsing", f"{M['nlp']['query_understanding']['all fields correct (%)']:.0f} % of 40 queries", "team-written queries"],
                  ["Cost model", M["cost_eval_summary"], "see Section 6"]],
                 [1.5, 3.2, 2.6]),
          Diagram(118, lambda c, w, h: G.d_bars(c, w, h, [("Sentiment accuracy", M['nlp']['sentiment']['accuracy'] * 100), ("Sentiment baseline (majority)", 33.3, MUTE),
                                                          ("Name match, one-letter typo", M['nlp']['normalization']['table'][3]['accuracy (%)']),
                                                          ("Planner queries fully correct", M['nlp']['query_understanding']['all fields correct (%)']),
                                                          ("Activity tags (F1 x 100)", M['nlp']['activity_tags']['f1'] * 100)])),
          P("Figure 13.1  Headline NLP measurements. Bars are green at 90 or above, amber at 60 or above, red below; grey is a baseline. See the caveat in Section 4.7.", "cap"),
          H2("13.3  What was not validated"),
          *bullets(["<b>Recommender ranking quality.</b> There is no held-out set of user preferences, so no precision@k, NDCG or A/B result exists. Ranking was checked by reading outputs on sample queries.",
                    "<b>Collaborative filtering.</b> It has no ratings to learn from yet, so it is untested on real behaviour.",
                    "<b>Similar destinations and the authenticity score</b> have no labelled benchmark; the authenticity score has not been calibrated against real spam.",
                    "<b>Itinerary quality.</b> It is a template planner; it has not been compared with human plans or tested for feasibility (opening hours, transfer times).",
                    "<b>Chatbot accuracy.</b> It depends on a third-party model; there is no scripted answer-quality test, only the fallbacks being exercised by hand.",
                    "<b>Load.</b> No load or soak test has been run; the service uses waitress with 8 threads when installed.",
                    "<b>Browsers.</b> Layout was checked by an automated phone-width test and by eye; there is no cross-browser matrix."])]
    return o


# =====================================================================================================================
def s14(M):
    o = sec(14, "Limitations and honest caveats", "What a reviewer should know before trusting a number, and where the project goes next.")
    o += [H2("14.1  Data"),
          *bullets(["<b>Wiki-derived knowledge.</b> Descriptions, attractions, seasons and prices come from Wikivoyage and Wikipedia. They are volunteer-written, may be dated, and Wikivoyage prices skew low (hence the calibration in Section 3).",
                    "<b>Thin data for small towns.</b> Places with few listings have few prices and few OpenStreetMap points, so their profiles, costs and routes are less reliable; the prominence filter drops the thinnest stubs entirely.",
                    "<b>The journal corpus is source text, not diaries.</b> 1,380 of the 1,381 journal rows are Wikivoyage or blog extracts. They teach vocabulary and activity mix, not what individual travellers spent. Dates and per-entry rupee splits on them are generated (Section 3).",
                    "<b>Sentiment of encyclopaedic text</b> is mostly neutral by nature, so destination sentiment is a weak signal; in the recommender it carries only 25 % of 22 % of the base weight."]),
          H2("14.2  Models"),
          *bullets(["The cost model is validated on 5 real trip reports and on held-out listings only (Section 6.7); it leaves out travel to the destination, shopping and seasons, and its band for a place with no listings is wide.",
                    "Weights in the recommender and the similarity embedding are set by judgement and have not been tuned against user outcomes.",
                    "The collaborative signal is inactive until members rate places; a new rating only counts after the service restarts.",
                    "The itinerary generator has no notion of time and distance between its suggestions."]),
          H2("14.3  Platform"),
          *bullets(["<b>Phone camera needs https.</b> Browsers expose <font name='Mono'>getUserMedia</font> and service workers only on https or localhost; on plain http over a LAN address the camera page falls back to file upload.",
                    "<b>No two-factor authentication</b> and <b>no SMTP configured by default</b>: verification and reset emails need an admin to enter SMTP details (a small built-in SMTP client is used, not PHPMailer); in this install the SMTP user, password and from-address are empty, so mail is not delivered, and a development setting can show the link on screen instead.",
                    "<b>Single server.</b> Sessions, rate limits and caches are local; scaling out would need shared session storage and a shared cache.",
                    "<b>Third-party dependence.</b> Gemini, Overpass, Nominatim and Geoapify are free-tier services with quotas; each has a fallback or a cache but results can be slower or thinner when they are down.",
                    "<b>CLAUDE.md is out of date.</b> The repository's CLAUDE.md still describes plaintext passwords and a procedural-only layout; the current code hashes passwords and uses the ja-* shared layer described here."]),
          H2("14.4  Future work"),
          *table(["Idea", "Why"],
                 [["Collect real ratings and run an offline ranking evaluation (leave-one-out, precision@k)", "turns the recommender from plausible to measured"],
                  ["Gather genuine traveller receipts with consent (budget logs) as cost ground truth", "the one input the cost model cannot get from listings"],
                  ["Road distances and opening hours (a routing engine, OSM opening_hours)", "makes routes and itineraries feasible, not only plausible"],
                  ["Two-factor authentication and a stricter CSP without inline scripts", "closes the largest remaining security gaps"],
                  ["Strip metadata from uploaded video", "completes the privacy story that images already have"],
                  ["Shared cache and session store", "needed before running more than one web server"],
                  ["Independent annotation of sentiment and activity tags", "replaces team-written test sets with a defensible benchmark"]],
                 [4.4, 2.8])]
    return o


# =====================================================================================================================
def appendix(M):
    o = [PageBreak(), Section("A", "Appendix", "Reference tables: endpoints, tables, files, glossary."), Spacer(1, 4)]
    o += [H2("A.1  ML service endpoints and main request fields"),
          *table(["Endpoint", "Request fields (JSON)", "Key response fields"],
                 [["POST /recommend", "eml, budget, duration_days, party_size, top_n, month, travel_style, interests[], origin, trip_mode, origin_region",
                   "recommendations[]: destination, score, predicted_cost, cost_low, cost_high, cost_breakdown, budget_fit, reason_factors, explanation, evidence"],
                  ["POST /predict-cost", "destination, duration_days, party_size, month, travel_style", "predicted_cost, low, high, per_day, cost_breakdown"],
                  ["POST /itinerary", "destination, days (1-21), travel_style, month, party_size", "plan[] with timeline, stay, est_cost; total_cost, cost band"],
                  ["POST /smart-plan", "text (500 chars), travel_style", "intent, kind (route, route_between, nearby, itinerary, recommend), est_cost"],
                  ["POST /chat", "message, history[], eml, location", "reply, cards[]"],
                  ["POST /nearby", "place, mode, interests[], limit (1-30)", "origin, places[] with category, dist_km, fame, score"],
                  ["POST /route", "place, [destination], mode, interests[], stops (2-8)", "route[], total_km, alternative, food_options"],
                  ["POST /similar", "destination, top_n (1-12), same_region", "similar[]: destination, similarity, why"],
                  ["POST /destination", "destination | destinations[] (max 3) | list:true, month", "profile: description, landmarks, activities, costs for 1/3/5/7 days"],
                  ["POST /authenticity", "text, eml, entry_id", "score, level, reasons[], nearest_duplicate, features"],
                  ["GET /health, /analytics/summary, /analytics/personal?eml=", "-", "status and model info; aggregates"]],
                 [1.7, 3.0, 3.5], mono_cols=(0,)),
          H2("A.2  PHP-side JSON endpoints"),
          *table(["File", "Purpose"],
                 [["chat.php, smart-plan.php", "browser to ML proxies (CSRF enforced, session identity attached)"],
                  ["cost-estimate.php, suggest.php", "read-only helpers for the live cost box and command palette"],
                  ["engage.php", "journal likes, comments, pins, reports (auto-hide rule lives here)"],
                  ["camera-api.php, camera-img.php", "private camera roll actions; owner-only image streaming"],
                  ["storybook-api.php, packing-api.php", "storybook editor and packing list actions"],
                  ["entry-share.php, plan-share.php", "visibility and share tokens for journals and saved plans"],
                  ["rate-destination.php, save-plan.php", "write the ratings and saved plans that feed collaborative filtering"],
                  ["csrf-token.php, health.php, notif-read.php, media-like.php", "token for same-origin scripts, uptime check, notification and like toggles"]],
                 [3.0, 4.2], mono_cols=(0,)),
          H2("A.3  Database tables (35 tables and 1 view)"),
          *table(["Group", "Tables"],
                 [["Accounts and trust", "signup, login_attempts, rate_limits, audit_log, email_log, settings, user_prefs, reports, notifications"],
                  ["Journals (legacy wizard keeps three expense tables keyed by Title + eml)", "db, db1, db2, db3, journal_likes, journal_comments, <i>journals (view that adds duration and totals)</i>"],
                  ["Media and community", "media, media_likes, media_comments, media_hashtags, hashtags"],
                  ["Storybooks", "storybooks, storybook_pages, storybooks_themes, storybook_likes, storybook_comments"],
                  ["Camera roll", "camera_folders, camera_photos"],
                  ["Planning", "destinations, interactions, saved_plans, saved_routes, packing_lists, packing_list_items, packing_items"],
                  ["ML output", "journal_features"]],
                 [2.6, 4.6]),
          H2("A.4  File map"),
          *table(["Path", "What is there"],
                 [["*.php (root)", "pages and JSON endpoints; <font name='Mono'>ja-*.php</font> are the shared layer (head, nav, auth, trust, media, camera, cards, mail, packing)"],
                  ["ml/app.py, ml_client.php", "the Flask service and the PHP client for it"],
                  ["ml/scrape/, ml/data/", "crawlers and derived JSON (catalog, listings, entries, coordinates, highlights, images)"],
                  ["ml/nlp/", "tokenizer, normaliser, lexicon, feature extraction, profile builder"],
                  ["ml/recommender/, ml/cost/, ml/itinerary/", "recommender, similar, explanations, profile API; cost model; itinerary generator"],
                  ["ml/bot/, ml/geo/, ml/trust/", "Gemini client, tools, chat and smart plan; places, routing, Geoapify, local highlights; authenticity"],
                  ["ml/eval/, tests/", "model evaluation scripts; site test suites"],
                  ["sql/", "migrations for each feature (trust, camera, media, storybook, session...)"],
                  ["css/, js/", "brand, motion, page and landing styles and scripts; vendor theme files"],
                  ["sw.js, manifest.webmanifest, offline.html", "service worker, install manifest, offline page"],
                  ["ops/", "check.ps1 (pre-deploy: php -l, compile, JS syntax, smoke test) and backup.ps1"],
                  ["docs/", "this guide (build_master_guide.py, guide_*.py) and project notes"]],
                 [2.6, 4.6], mono_cols=(0,)),
          H2("A.5  Glossary"),
          *table(["Term", "Meaning"],
                 [["TF-IDF", "term frequency x inverse document frequency: a word weighs more when frequent in a document and rare overall."],
                  ["Cosine similarity", "angle-based similarity of two vectors, 1 = same direction, 0 = unrelated."],
                  ["LSA / truncated SVD", "latent semantic analysis: compress TF-IDF vectors to a few axes so related words land together."],
                  ["Item-item collaborative filtering", "recommend what is rated like the things a user already liked, using co-rating patterns."],
                  ["Cold start", "no history for the user or item yet; the system falls back to content and popularity."],
                  ["Haversine", "great-circle distance between two latitude-longitude points."],
                  ["Overpass / Nominatim", "OpenStreetMap query service for points of interest / geocoder for place names."],
                  ["IsolationForest", "an unsupervised outlier detector: odd points are isolated by few random splits."],
                  ["Jaccard / shingles", "overlap of two sets / the set of consecutive 5-word chunks of a text; used for near-duplicate detection."],
                  ["CSRF", "cross-site request forgery: another site making your browser submit a form; stopped by a secret per-session token."],
                  ["CSP", "Content-Security-Policy: a header telling the browser which sources of script and media to trust."],
                  ["Session epoch", "a counter that invalidates all older sessions when a password changes."],
                  ["PWA / service worker", "a web app that can be installed and works offline through a background script that caches files."],
                  ["Quantile band", "a low-high range from the 10th and 90th percentile predictions, meant to hold about 80 % of outcomes."]],
                 [2.0, 5.2])]
    return o
