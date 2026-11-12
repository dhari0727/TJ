"""Diagrams for the JourneyAI master guide, drawn with reportlab primitives (vector, no image files)."""
import math
from reportlab.lib import colors
from guide_lib import *  # noqa: F401,F403

W_ = CW
WHITE = colors.white
PALE = colors.HexColor("#FBF6F0")
MEL_BG = colors.HexColor("#FDE8EC")


def d_architecture(c, w, h):
    # lanes
    lane(c, 8, 70, 96, h - 80, "Browser")
    lane(c, 116, 70, 138, h - 80, "Apache + PHP 8")
    lane(c, 266, 70, 128, h - 80, "Flask ML service  127.0.0.1:5000")
    lane(c, 404, 70, 66, h - 80, "Outside", MELON)
    lane(c, 8, 6, w - 16, 58, "Storage", DEEP)
    top = h - 34
    # browser
    box(c, 16, top - 52, 80, 50, "HTML, CSS, JS", "pages, command palette, motion layer", fill=WHITE)
    box(c, 16, top - 112, 80, 50, "Service worker", "caches assets; saved plans offline", fill=WHITE)
    box(c, 16, top - 172, 80, 50, "Camera (getUserMedia)", "Polaroid frames and filters on a canvas", fill=WHITE)
    box(c, 16, top - 232, 80, 46, "Chat widget", "posts to chat.php", fill=WHITE)
    # php
    box(c, 124, top - 52, 122, 50, "Page scripts", "plan-trip, trip, destination, compare, journal, feed, camera, admin", fill=WHITE)
    box(c, 124, top - 112, 122, 50, "ja-lib, ja-trust, ja-media", "sessions, CSRF, rate limits, trust level, uploads", fill=WHITE)
    box(c, 124, top - 172, 122, 50, "ml_client.php", "cURL, JSON, 2 s connect and 8 s total timeout", fill=SAND, stroke=MELON)
    box(c, 124, top - 232, 122, 46, "JSON endpoints", "engage, camera-api, storybook-api, packing-api, suggest, cost-estimate", fill=WHITE)
    # flask
    box(c, 274, top - 52, 112, 50, "ml.app  (13 routes)", "validates and clamps input, JSON errors only", fill=SAND, stroke=MELON)
    box(c, 274, top - 112, 112, 50, "recommender + cost", "hybrid.py, explain.py, similar.py, cost/", fill=WHITE)
    box(c, 274, top - 172, 112, 50, "itinerary + bot", "generate.py, smartplan.py, chat.py, tools.py", fill=WHITE)
    box(c, 274, top - 232, 112, 46, "geo + trust", "places.py, geoapify.py, authenticity.py", fill=WHITE)
    # outside
    box(c, 410, top - 52, 54, 50, "Gemini", "LLM, optional", fill=MEL_BG, stroke=MELON, ssize=6.6)
    box(c, 410, top - 112, 54, 50, "OSM + Geoapify", "places, geocoding", fill=MEL_BG, stroke=MELON, ssize=6.6, tsize=7.6)
    box(c, 410, top - 172, 54, 50, "Wikipedia", "lead photos (hotlinked)", fill=MEL_BG, stroke=MELON, ssize=6.6)
    box(c, 410, top - 232, 54, 46, "SMTP", "mail, if configured", fill=MEL_BG, stroke=MELON, ssize=6.6)
    # storage
    box(c, 16, 12, 100, 36, "MySQL  project", "30+ tables, journals view", fill=WHITE, stroke=DEEP)
    box(c, 124, 12, 100, 36, "uploads/ (public)", "re-encoded media", fill=WHITE, stroke=DEEP)
    box(c, 232, 12, 100, 36, "private/camera/", "deny-all, owner only", fill=WHITE, stroke=DEEP)
    box(c, 340, 12, 124, 36, "ml/artifacts + ml/data", "pickles, geocache, scraped JSON", fill=WHITE, stroke=DEEP)
    # arrows
    arrow(c, 96, top - 27, 124, top - 27)
    arrow(c, 96, top - 142, 124, top - 142)
    arrow(c, 96, top - 209, 124, top - 209)
    arrow(c, 246, top - 147, 274, top - 27, col=MELON)
    arrow(c, 246, top - 147, 274, top - 87, col=MELON)
    arrow(c, 386, top - 27, 410, top - 27, col=MELON, dash=(2, 2))
    arrow(c, 386, top - 142, 410, top - 87, col=MELON, dash=(2, 2))
    arrow(c, 185, top - 232, 185, 48, col=DEEP, dash=(2, 2))
    arrow(c, 330, top - 232, 330, 48, col=DEEP, dash=(2, 2))
    label(c, 260, top - 168, "HTTP", 6.6, MELON, SANS_B, "c"); label(c, 260, top - 176, "JSON", 6.6, MELON, SANS_B, "c")


def d_requestflow(c, w, h):
    names = ["Browser", "PHP page", "ml_client.php", "ml.app (Flask)", "Recommender + cost", "MySQL"]
    xs = [48 + i * 76 for i in range(6)]
    top = h - 16
    for x, n in zip(xs, names):
        box(c, x - 34, top - 20, 68, 24, n, fill=DEEP, stroke=DEEP, tcol=CREAM, tsize=7.4)
        c.setStrokeColor(RULE)
        c.setDash(2, 3)
        c.line(x, top - 22, x, 12)
        c.setDash()
    msgs = [
        (0, 1, "1  GET plan-trip.php?budget=30000&interests=beach", MELON),
        (1, 1, "2  session, CSRF, clamp inputs", DIM),
        (1, 2, "3  ml_recommend(params)", DEEP),
        (2, 3, "4  POST /recommend  (JSON)", DEEP),
        (3, 4, "5  recommend(): score every destination", DEEP),
        (4, 5, "6  read interactions for CF matrix (at boot)", DIM),
        (4, 3, "7  explain(): attach reasons and evidence", DEEP),
        (3, 2, "8  200 JSON  (or clean error)", DEEP),
        (2, 1, "9  decoded array, or __error", DEEP),
        (1, 0, "10  HTML cards + cost bands", MELON),
    ]
    y = top - 44
    for a, b, t, col in msgs:
        xa, xb = xs[a], xs[b]
        if a == b:
            c.setStrokeColor(col)
            c.setLineWidth(1)
            c.line(xa, y, xa + 22, y)
            c.line(xa + 22, y, xa + 22, y - 9)
            arrow(c, xa + 22, y - 9, xa + 2, y - 9, col=col)
            label(c, xa + 28, y - 7, t, 7, col)
        else:
            arrow(c, xa, y, xb, y, col=col)
            mid = (xa + xb) / 2
            label(c, min(xa, xb) + 4, y + 3.5, t, 7, col, anchor="l")
        y -= 25
    label(c, w - 8, 6, "A failed or slow ML call becomes a visible 'service offline' banner, never a blank page.", 6.8, MUTE, SANS_I, "r")


def elbow(c, pts, col=DEEP, dash=None):
    """Polyline with an arrow head on the last segment."""
    c.setStrokeColor(col)
    c.setLineWidth(1.1)
    if dash:
        c.setDash(*dash)
    for a, b in zip(pts[:-2], pts[1:-1]):
        c.line(a[0], a[1], b[0], b[1])
    c.setDash()
    arrow(c, pts[-2][0], pts[-2][1], pts[-1][0], pts[-1][1], col=col, dash=dash)


def d_pipeline(c, w, h):
    label(c, 10, h - 14, "COLLECT AND DERIVE", 7, GREEN, SANS_B)
    label(c, 10, 84, "LOAD, EXTRACT, TRAIN", 7, GREEN, SANS_B)
    bw, gap = 84, 14
    xs = [8 + i * (bw + gap) for i in range(4)]
    srcs = [("Wikivoyage", "1,422 pages crawled"), ("Wikipedia", "pageviews, lead photos"), ("Blogs + Tripoto", "trip reports with prices"), ("OSM, Geoapify", "live places, cached on demand")]
    y0 = h - 28
    for i, (t, s) in enumerate(srcs):
        box(c, xs[0], y0 - 30 - i * 36, bw, 30, t, s, fill=WHITE, ssize=6.4, tsize=8)
    top, bot = y0 - 30 - 3 * 36, y0
    box(c, xs[1], top, bw, bot - top, "ml/scrape/*", "polite fetch, robots.txt honoured, 1.5 s delay, raw pages cached on disk, derived data only", fill=SAND, stroke=MELON, ssize=6.6)
    for i in range(4):
        arrow(c, xs[0] + bw, y0 - 15 - i * 36, xs[1], y0 - 15 - i * 36, col=GREEN, head=4)
    mid = (top + bot) / 2
    box(c, xs[2], mid + 4, bw, bot - mid - 4, "build_india_dataset", "prices parsed, daily cost estimated, regions, seasons, prominence filter", fill=SAND, stroke=MELON, ssize=6.4)
    box(c, xs[2], top, bw, mid - top - 4, "ml/data/*.json", "catalog, listings, entries", fill=WHITE, ssize=6.4)
    arrow(c, xs[1] + bw, mid + 30, xs[2], mid + 30)
    arrow(c, xs[2] + bw / 2, mid + 4, xs[2] + bw / 2, mid - 0.5, col=DEEP, head=4)
    r2 = 18
    bh = 54
    w2 = 68
    g2 = 8
    x2 = [8 + i * (w2 + g2) for i in range(6)]
    row = [("load_real_entries", "source text kept verbatim; rows tagged @real.journeyai"), ("MySQL", "db, db1-3, destinations, signup"),
           ("extract_features", "only approved items from verified accounts; max 3 per user per place"), ("build_profiles", "one profile per destination"),
           ("train_cost_model", "cost artifact"), ("ml/artifacts/*.pkl", "loaded once when Flask boots")]
    for i, (t, s) in enumerate(row):
        data = t in ("MySQL", "ml/artifacts/*.pkl")
        box(c, x2[i], r2, w2, bh, t, s, fill=WHITE if data else SAND, stroke=DEEP if data else MELON, ssize=5.9, tsize=7.2)
    for i in range(5):
        arrow(c, x2[i] + w2, r2 + bh / 2, x2[i + 1], r2 + bh / 2, head=4)
    jx = xs[2] + bw / 2
    elbow(c, [(jx, top), (jx, top - 14), (x2[0] + w2 / 2, top - 14), (x2[0] + w2 / 2, r2 + bh + 0.5)], col=DEEP)
    label(c, x2[0] + w2 / 2 + 8, top - 10, "entries + catalog", 6.4, DIM, SANS_I)
    label(c, 8, 7, "rebuild_all runs: load_real_entries, extract_features, build_profiles, train_cost_model, in that order.", 6.8, MUTE, SANS_I)


def d_recommender(c, w, h):
    label(c, 10, h - 14, "INPUTS", 7, GREEN, SANS_B)
    ins = ["interests (20 tags)", "budget, days, party", "travel style, month", "origin + trip length", "user email (CF)"]
    y = h - 40
    for t in ins:
        box(c, 8, y, 92, 24, t, fill=WHITE, tsize=7.4)
        y -= 30
    label(c, 122, h - 14, "THREE SCORES  (each scaled to 0..1)", 7, GREEN, SANS_B)
    box(c, 118, h - 60, 128, 44, "Content  0.38", "cosine: interest one-hot vs destination activity mix", fill=MINT, ssize=6.8)
    box(c, 118, h - 112, 128, 44, "Semantic  0.22", "cosine in TF-IDF space, nudged by sentiment (75/25)", fill=MINT, ssize=6.8)
    box(c, 118, h - 164, 128, 44, "Collaborative  0.30", "item-item cosine over ratings; popularity if cold", fill=MINT, ssize=6.8)
    label(c, 266, h - 14, "ADJUST THE SCORE", 7, GREEN, SANS_B)
    box(c, 262, h - 52, 110, 36, "+ prominence 0.12", "log Wikipedia views", fill=SAND, stroke=MELON, tsize=7.6, ssize=6.6)
    box(c, 262, h - 92, 110, 36, "x distance window", "(0.3 + 0.7 fit); trip length", fill=SAND, stroke=MELON, tsize=7.6, ssize=6.6)
    box(c, 262, h - 132, 110, 36, "x budget  (0.5 + 0.5 fit)", "over budget is damped, never dropped", fill=SAND, stroke=MELON, tsize=7.6, ssize=6.6)
    box(c, 262, h - 172, 110, 36, "x season  (0.9 + 0.1 fit)", "+ 0.04 hidden-gem boost", fill=SAND, stroke=MELON, tsize=7.6, ssize=6.6)
    box(c, 388, h - 92, 80, 56, "Rank, keep top N", "in-budget first; backfill so the list is never empty", fill=DEEP, stroke=DEEP, tcol=CREAM, scol=CREAM, ssize=6.6)
    box(c, 388, h - 164, 80, 56, "explain()", "reasons, real attractions, evidence journals", fill=WHITE, stroke=MELON, ssize=6.6)
    for yy in (h - 28, h - 80, h - 132):
        pass
    arrow(c, 100, h - 80, 118, h - 38)
    arrow(c, 100, h - 80, 118, h - 90)
    arrow(c, 100, h - 80, 118, h - 142)
    arrow(c, 246, h - 90, 262, h - 34)
    arrow(c, 246, h - 90, 262, h - 74)
    arrow(c, 246, h - 90, 262, h - 114)
    arrow(c, 246, h - 90, 262, h - 154)
    arrow(c, 372, h - 114, 388, h - 66)
    arrow(c, 428, h - 108, 428, h - 108 - 0.1)
    arrow(c, 428, h - 92, 428, h - 108, col=DEEP)
    label(c, 10, 10, "Weights shown are the constants in ml/recommender/hybrid.py. Proximity (0.10) applies only when an origin region is given.", 6.8, MUTE, SANS_I)


def d_trust(c, w, h):
    y1 = h - 62
    box(c, 8, y1, 80, 40, "Member submits", "journal, photo, storybook", fill=DEEP, stroke=DEEP, tcol=CREAM, scol=CREAM)
    box(c, 108, y1, 88, 40, "Email verified?", "no: stays private", fill=MEL_BG, stroke=MELON)
    box(c, 216, y1, 100, 40, "Content checks", "length, links, repeats, copies, cost, dates", fill=SAND, stroke=MELON, ssize=6.6)
    box(c, 336, y1, 132, 40, "Trust level", "trusted: approved instantly; verified: pending queue", fill=MINT, ssize=6.6)
    arrow(c, 88, y1 + 20, 108, y1 + 20)
    arrow(c, 196, y1 + 20, 216, y1 + 20)
    arrow(c, 316, y1 + 20, 336, y1 + 20)
    y2 = h - 142
    box(c, 8, y2, 100, 40, "Rate limits", "5 public items a day; upload and comment caps", fill=WHITE)
    box(c, 128, y2, 100, 40, "Moderator queue", "approve / reject, reason logged", fill=SAND, stroke=MELON)
    box(c, 248, y2, 100, 40, "Public", "approved items only", fill=MINT)
    box(c, 368, y2, 100, 40, "Reports", "3 distinct, verified, aged reporters hide it", fill=MEL_BG, stroke=MELON, ssize=6.6)
    arrow(c, 402, y1, 402, y2 + 40 + 0.1 if False else y1 - 6, col=DEEP)
    arrow(c, 402, y1 - 6, 178, y2 + 40, col=DEEP)
    arrow(c, 228, y2 + 20, 248, y2 + 20)
    arrow(c, 368, y2 + 20, 348, y2 + 20, col=MELON)
    arrow(c, 108, y2 + 20, 128, y2 + 20, col=DIM, dash=(2, 2))
    box(c, 8, 12, 212, 50, "Authenticity score (advisory)", "TF-IDF near-duplicate, repetition, link and phone stuffing, specificity, IsolationForest. Shown to moderators. Never hides anything on its own.", fill=WHITE, stroke=GREEN, ssize=6.8)
    box(c, 240, 12, 228, 50, "Models only see approved, verified data", "journal_features is built from approved items by verified accounts; one account counts at most 3 journals per destination.", fill=WHITE, stroke=GREEN, ssize=6.8)
    arrow(c, 298, y2, 298, 62, col=DEEP, dash=(2, 2))


def d_er(c, w, h):
    def tbl(x, y, wd, name, cols, hl=MINT):
        rowh = 9.4
        hh = 15 + rowh * len(cols)
        c.setFillColor(WHITE)
        c.setStrokeColor(GREEN)
        c.setLineWidth(0.9)
        c.roundRect(x, y - hh, wd, hh, 3, fill=1, stroke=1)
        c.setFillColor(DEEP)
        c.roundRect(x, y - 14, wd, 14, 3, fill=1, stroke=0)
        c.rect(x, y - 14, wd, 6, fill=1, stroke=0)
        label(c, x + 5, y - 10.5, name, 7.6, CREAM, SANS_B)
        yy = y - 22
        for cl in cols:
            label(c, x + 5, yy, cl, 6.5, INK, MONO)
            yy -= rowh
        return (x, y, wd, hh)

    s = tbl(180, h - 12, 104, "signup", ["eml  (identity key)", "role, is_active", "email_verified", "trust_override", "session_epoch", "psw  (hash), handle"])
    d = tbl(8, h - 12, 110, "db  (journal)", ["entry_id", "eml, Title, Description", "City, Country, dv, dr", "visibility, share_token", "moderation_status", "text_hash"])
    d1 = tbl(8, h - 118, 110, "db1, db2, db3", ["Title + eml  (costs)", "food, transport", "accommodation", "shopping, fees"])
    jf = tbl(8, h - 190, 110, "journal_features", ["entry_id", "canonical_dest", "activities, style", "sentiment, budget_bucket"])
    m = tbl(330, h - 12, 138, "media", ["media_id, eml", "filepath, sha256", "destination, lat, lon", "is_public, likes", "moderation_status"])
    sb = tbl(330, h - 100, 138, "storybooks / pages", ["book_id, eml, theme", "visibility, share_token", "moderation_status", "storybook_pages.book_id"])
    cp = tbl(330, h - 170, 138, "camera_photos / folders", ["photo_id, eml, folder_id", "filepath  (private/camera)", "frame, filter_name"])
    ds = tbl(180, h - 130, 104, "destinations", ["canonical_name", "region, tier", "base_daily_cost, lat, lon"])
    it = tbl(180, h - 190, 104, "interactions", ["eml, destination", "rating, interaction_type"])
    rp = tbl(180, h - 238, 104, "reports", ["reporter, kind, item_id", "reason, status"])
    sp = tbl(8, h - 252, 110, "saved_plans / routes", ["eml, destination", "itinerary, share_token"])
    ot = tbl(330, h - 226, 138, "others", ["audit_log, rate_limits", "notifications, email_log", "settings, packing_lists", "hashtags, likes, comments"])
    # relations (all keyed on eml unless stated)
    arrow(c, 180, h - 40, 118, h - 40, col=GREEN, head=4)
    arrow(c, 284, h - 40, 330, h - 40, col=GREEN, head=4)
    arrow(c, 284, h - 58, 330, h - 128, col=GREEN, head=4)
    arrow(c, 180, h - 70, 118, h - 140, col=GREEN, head=4)
    arrow(c, 63, h - 90, 63, h - 118, col=MELON, head=4)
    arrow(c, 63, h - 163, 63, h - 190, col=MELON, head=4)
    arrow(c, 232, h - 130, 232, h - 100 if False else h - 100, col=GREEN, head=4)
    arrow(c, 284, h - 82, 330, h - 190, col=GREEN, head=4)
    arrow(c, 232, h - 164, 232, h - 190, col=GREEN, head=4)
    arrow(c, 180, h - 215, 118, h - 252 + 0, col=GREEN, head=4, dash=(2, 2))
    label(c, 66, h - 108, "Title + eml", 6.4, MELON, SANS_B)
    label(c, 66, h - 180, "entry_id", 6.4, MELON, SANS_B)
    label(c, 8, 8, "Green arrows: owned by an account via eml. Red: joined on entry or Title. Only the main tables are drawn; the database has 36 tables and 1 view (journals).", 6.6, MUTE, SANS_I)


def d_corridor(c, w, h):
    ox, oy, dx, dy = 40, 40, w - 40, h - 70
    c.setStrokeColor(GREEN)
    c.setLineWidth(2)
    c.line(ox, oy, dx, dy)
    c.setFillColor(DEEP)
    c.circle(ox, oy, 5, fill=1, stroke=0)
    label(c, ox + 8, oy - 12, "origin O", 8, DEEP, SANS_B)
    c.circle(dx, dy, 5, fill=1, stroke=0)
    label(c, dx - 8, dy + 8, "destination D", 8, DEEP, SANS_B, "r")
    px, py = 215, 58
    vx, vy = dx - ox, dy - oy
    t = ((px - ox) * vx + (py - oy) * vy) / (vx * vx + vy * vy)
    qx, qy = ox + t * vx, oy + t * vy
    c.setStrokeColor(MELON)
    c.setLineWidth(1)
    c.setDash(3, 2)
    c.line(px, py, qx, qy)
    c.setDash()
    c.setFillColor(MELON)
    c.circle(px, py, 4.5, fill=1, stroke=0)
    label(c, px + 9, py - 12, "candidate place P", 8, MELON, SANS_B)
    c.setFillColor(GREEN)
    c.circle(qx, qy, 3, fill=1, stroke=0)
    label(c, qx - 8, qy + 10, "projection of P on the line", 7, GREEN, SANS_B, "r")
    label(c, (px + qx) / 2 - 6, (py + qy) / 2 - 2, "perp_km", 7.2, MELON, SANS_B, "r")
    label(c, (px + qx) / 2 - 6, (py + qy) / 2 - 11, "(sideways detour)", 6.6, MELON, SANS, "r")
    arrow(c, ox, oy - 22, qx, oy - 22, col=DEEP, head=4)
    label(c, (ox + qx) / 2, oy - 34, "along_km = t x direct_km  (progress toward D)", 7.2, DEEP, SANS_B, "c")
    label(c, w - 12, 62, "keep P if  -10 <= along_km <= direct_km + 10", 7.6, DEEP, SANS_B, "r")
    label(c, w - 12, 50, "and  perp_km <= max(15, 0.25 x direct_km)", 7.6, DEEP, SANS_B, "r")
    label(c, w - 12, 34, "Split the route into n equal segments by along_km; the best", 7, DIM, SANS, "r")
    label(c, w - 12, 24, "candidate per segment (lowest 1.5 x perp_km - fame) fills each", 7, DIM, SANS, "r")
    label(c, w - 12, 14, "slot; stops are then sorted by along_km.", 7, DIM, SANS, "r")


def d_window(c, w, h):
    # soft distance window fit for the four trip modes (formula copied from hybrid._window_fit)
    wins = {"day": (0, 90, 30.0), "weekend": (60, 380, 170.0), "short": (180, 950, 420.0), "long": (700, 4500, 1600.0)}
    cols = {"day": MELON, "weekend": AMBER, "short": GREEN, "long": DEEP}
    L, B, Wd, Ht = 46, 28, w - 70, h - 54

    def fit(km, mode):
        lo, hi, ideal = wins[mode]
        if lo <= km <= hi:
            z = math.log(max(km, 5.0) / ideal) / 0.75
            return 0.8 + 0.2 * math.exp(-0.5 * z * z)
        gap = (lo - km) if km < lo else (km - hi)
        scale = max(120.0, (hi - lo) * 0.35)
        return 0.8 * math.exp(-gap / scale)

    xmin, xmax = math.log10(10), math.log10(6000)
    c.setStrokeColor(RULE)
    c.setLineWidth(0.5)
    for v in (0, 0.25, 0.5, 0.75, 1.0):
        y = B + Ht * v
        c.line(L, y, L + Wd, y)
        label(c, L - 5, y - 2.5, f"{v:.2f}", 6.6, MUTE, anchor="r")
    for km in (10, 30, 100, 300, 1000, 3000):
        x = L + Wd * (math.log10(km) - xmin) / (xmax - xmin)
        c.line(x, B, x, B - 3)
        label(c, x, B - 12, f"{km}", 6.6, MUTE, anchor="c")
    label(c, L + Wd / 2, 6, "estimated road distance (km, log scale)", 7, DIM, anchor="c")
    ly = h - 14
    for i, m in enumerate(wins):
        c.setStrokeColor(cols[m])
        c.setLineWidth(1.8)
        pts = []
        for s in range(0, 241):
            lk = xmin + (xmax - xmin) * s / 240
            km = 10 ** lk
            pts.append((L + Wd * s / 240, B + Ht * fit(km, m)))
        p = c.beginPath()
        p.moveTo(*pts[0])
        for pt in pts[1:]:
            p.lineTo(*pt)
        c.drawPath(p, stroke=1, fill=0)
        c.setFillColor(cols[m])
        c.rect(L + 6 + i * 92, ly - 1, 10, 4, fill=1, stroke=0)
        label(c, L + 20 + i * 92, ly - 2, f"{m} ({wins[m][0]}-{wins[m][1]} km)", 7, INK)


def d_chat(c, w, h):
    box(c, 8, h - 60, 70, 44, "User message", "chat.php adds session email", fill=DEEP, stroke=DEEP, tcol=CREAM, scol=CREAM, ssize=6.6)
    box(c, 100, h - 60, 100, 44, "chat()", "builds contents: last 8 turns + message (+ location)", fill=SAND, stroke=MELON, ssize=6.6)
    box(c, 222, h - 60, 100, 44, "Gemini generateContent", "system rules + 9 tool schemas", fill=MEL_BG, stroke=MELON, ssize=6.6)
    box(c, 344, h - 60, 124, 44, "Function calls?", "yes: run tools, feed results back (max 4 rounds)", fill=MINT, ssize=6.6)
    arrow(c, 78, h - 38, 100, h - 38)
    arrow(c, 200, h - 38, 222, h - 38)
    arrow(c, 322, h - 38, 344, h - 38)
    box(c, 344, h - 124, 124, 44, "dispatch(name, args)", "9 tools; always returns a dict; errors become {error}", fill=WHITE, ssize=6.6)
    arrow(c, 406, h - 60, 406, h - 80)
    box(c, 222, h - 124, 100, 44, "Tool results", "go back to Gemini as functionResponse", fill=WHITE, ssize=6.6)
    arrow(c, 344, h - 102, 322, h - 102)
    arrow(c, 272, h - 80, 272, h - 60, col=MELON)
    box(c, 8, h - 124, 190, 44, "Final text + UI cards", "places, route, itinerary, cost, media, past trips", fill=MINT, ssize=7)
    arrow(c, 222, h - 70, 198, h - 100, col=DEEP, dash=(2, 2))
    box(c, 8, 10, 460, 48, "Fallback: any Gemini failure (quota, retired model, network, 45 s total deadline) -> rule-based answer", "regex intent -> smartplan.plan() or find_nearby_places. get_my_journal_history always receives the SESSION email, never one chosen by the model.", fill=WHITE, stroke=MELON, ssize=7)


def d_lsa(c, w, h):
    box(c, 8, h - 56, 96, 42, "mean TF-IDF vector", "per destination (up to 4,000 terms)", fill=WHITE, ssize=6.6)
    box(c, 124, h - 56, 90, 42, "TruncatedSVD", "k = min(48, ...) latent axes", fill=SAND, stroke=MELON, ssize=6.6)
    box(c, 8, h - 104, 96, 38, "activity mix", "20-tag histogram", fill=WHITE, ssize=6.6)
    box(c, 8, h - 148, 96, 38, "log daily cost", "min-max scaled", fill=WHITE, ssize=6.6)
    box(c, 8, h - 192, 96, 38, "region", "one-hot", fill=WHITE, ssize=6.6)
    arrow(c, 104, h - 35, 124, h - 35)
    box(c, 244, h - 120, 100, 92, "Weighted concat", "text x 1.0, activity x 0.7, cost x 0.35, region x 0.25, then L2 normalise", fill=MINT, ssize=6.8)
    arrow(c, 214, h - 35, 244, h - 60)
    arrow(c, 104, h - 85, 244, h - 85)
    arrow(c, 104, h - 129, 244, h - 105)
    arrow(c, 104, h - 173, 244, h - 120)
    box(c, 372, h - 100, 96, 52, "cosine nearest", "emb @ emb[i]; top N; optional same-region filter", fill=DEEP, stroke=DEEP, tcol=CREAM, scol=CREAM, ssize=6.6)
    arrow(c, 344, h - 74, 372, h - 74)
    box(c, 244, h - 192, 224, 40, "why: shared activities (each > 0.10), same region, daily cost within 25 %", "a plain sentence, built only from fields in the profiles", fill=WHITE, stroke=GREEN, ssize=6.6, tsize=7.2)
    arrow(c, 420, h - 100, 420, h - 152)


def d_bars(c, w, h, items, vmax=100, unit="%"):
    """Horizontal bar chart: items = [(label, value)]"""
    L = 190
    n = len(items)
    rh = (h - 16) / n
    for i, it in enumerate(items):
        lab, v = it[0], it[1]
        fixed = it[2] if len(it) > 2 else None
        y = h - 8 - (i + 1) * rh + 3
        label(c, L - 6, y + rh / 2 - 4, lab, 7.4, INK, anchor="r")
        c.setFillColor(colors.HexColor("#EFE6DB"))
        c.rect(L, y + 3, w - L - 50, rh - 9, fill=1, stroke=0)
        c.setFillColor(fixed or (GREEN if v >= 90 else (AMBER if v >= 60 else MELON)))
        c.rect(L, y + 3, (w - L - 50) * v / vmax, rh - 9, fill=1, stroke=0)
        label(c, L + (w - L - 50) * v / vmax + 4, y + rh / 2 - 4, f"{v:g}{unit}", 7.4, DEEP, SANS_B)
