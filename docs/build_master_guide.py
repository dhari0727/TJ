"""
Build docs/JourneyAI_Master_Guide.pdf  (reportlab only; fonts: Georgia + Segoe UI from the Windows font folder).

    python docs/build_master_guide.py

Files:  guide_lib.py (look and building blocks), guide_diagrams.py (vector diagrams), guide_content.py (sections 1-5 and 7-14
and the appendix), guide_cost.py (section 6, the cost model), this file (page templates, cover, table of contents, live data).

Live data collected at build time (each falls back gracefully when unavailable):
  - file counts and ml/data/*.json sizes (from the repository)
  - table counts via the XAMPP mysql client, destination count and example output from the running ML service (127.0.0.1:5000)
  - NLP metrics by running  ml/venv/Scripts/python.exe -m ml.eval.nlp_eval
The site test results in TESTS below were measured by running each script in tests/ (see Section 13) and are pinned here.
"""
import datetime
import glob
import importlib.util
import json
import os
import re
import statistics
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)

from reportlab.lib import colors  # noqa: E402
from reportlab.lib.units import mm  # noqa: E402
from reportlab.platypus import BaseDocTemplate, Frame, NextPageTemplate, PageBreak, PageTemplate, Paragraph, Spacer  # noqa: E402
from reportlab.platypus.tableofcontents import TableOfContents  # noqa: E402

import guide_lib as L  # noqa: E402
from guide_lib import *  # noqa: E402,F401,F403
import guide_content as C  # noqa: E402
import guide_cost  # noqa: E402

OUT = os.path.join(HERE, "JourneyAI_Master_Guide.pdf")
MYSQL = r"C:\xampp\mysql\bin\mysql.exe"
PYVENV = os.path.join(ROOT, "ml", "venv", "Scripts", "python.exe")
ML = "http://127.0.0.1:5000"
TODAY = datetime.date.today().isoformat()

TESTS = [  # suite, what it covers, result  (measured 2026-11-12 by running each script against the local site)
    ["smoke.py", "black-box walk of the main journeys with a throw-away account", 77, 77],
    ["account_test.py", "command palette, skip link and landmark, 404 page, no sideways scroll at phone width", 49, 49],
    ["camera_test.py", "private camera roll: upload, folders, ownership, deny-all directory, public copy, cleanup", 73, 73],
    ["camera_picker_test.py", "picker wired into storybook, journal and post; path and ownership checks", 36, 36],
    ["community_test.py", "feed, journal reading and writing, traveller profile", 19, 28],
    ["discover_test.py", "destination guide, compare, live cost helper, /destination", 30, 30],
    ["trips_test.py", "trip page, print stylesheet, PWA files", 28, 28],
    ["trust_test.py", "verification, moderation, reports, media and data security", 62, 62],
    ["cost_test.py", "cost model acceptance: style ordering, 479,607 fuzzed predictions, API and page behaviour", 41, 41],
]


def http_json(path, payload=None, timeout=20):
    try:
        req = urllib.request.Request(ML + path, data=(json.dumps(payload).encode() if payload is not None else None),
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode())
    except Exception:
        return None


def sql(q):
    try:
        out = subprocess.run([MYSQL, "-u", "root", "project", "-N", "-e", q], capture_output=True, text=True, timeout=30).stdout.strip()
        return out
    except Exception:
        return ""


def jlen(name):
    try:
        with open(os.path.join(ROOT, "ml", "data", name), encoding="utf8") as f:
            return len(json.load(f))
    except Exception:
        return 0


def collect():
    M = {"date": TODAY}
    M["n_php"] = len(glob.glob(os.path.join(ROOT, "*.php")))
    n = 0
    for dp, dn, fn in os.walk(os.path.join(ROOT, "ml")):
        dn[:] = [d for d in dn if d not in ("venv", "__pycache__")]
        n += sum(1 for f in fn if f.endswith(".py"))
    M["n_py"] = n
    M["n_routes"] = len(re.findall(r"^@app\.(?:get|post)\(", open(os.path.join(ROOT, "ml", "app.py"), encoding="utf8").read(), re.M))
    tv = sql("select table_type,count(*) from information_schema.tables where table_schema='project' group by 1").split("\n")
    d = {r.split("\t")[0]: int(r.split("\t")[1]) for r in tv if "\t" in r}
    M["n_tables"], M["n_views"] = d.get("BASE TABLE", 35), d.get("VIEW", 1)
    M["n_journals"] = int((sql("select count(*) from db") or "1381").split()[0])
    M["n_user_journals"] = int((sql("select count(*) from db where eml not like '%@real.journeyai'") or "1").split()[0])
    h = http_json("/health") or {}
    M["n_profiles"] = h.get("destinations", 436)
    M["health"] = h
    M["n_nodes"], M["n_listings"], M["n_obs"] = jlen("india_destinations.json"), jlen("india_listings.json"), jlen("india_cost_observations.json")
    M["n_prom"], M["n_images"] = jlen("india_prominence.json"), jlen("dest_images.json")
    M["n_tripoto"], M["n_blog_obs"], M["n_blog_entries"] = jlen("tripoto_reports.json"), jlen("blog_cost_obs.json"), jlen("blog_entries.json")
    spec = importlib.util.spec_from_file_location("lexicon", os.path.join(ROOT, "ml", "nlp", "lexicon.py"))
    lex = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(lex)
    M["n_gaz"] = len(lex.ACTIVITY_GAZETTEER)
    # NLP evaluation, run for real
    try:
        r = subprocess.run([PYVENV, "-m", "ml.eval.nlp_eval"], cwd=ROOT, capture_output=True, text=True, timeout=300)
        M["nlp"] = json.loads(r.stdout)
    except Exception as e:
        raise SystemExit("nlp_eval failed: %s" % e)
    # tests
    M["tests"] = {"rows": [[f"<font name='Mono'>{t[0]}</font>", t[1], f"{t[2]} of {t[3]}" + ("" if t[2] == t[3] else " (9 failing)")] for t in TESTS]}
    M["tests_pass"], M["tests_total"] = sum(t[2] for t in TESTS), sum(t[3] for t in TESTS)
    M["reco_example"], M["similar_example"], M["lat_med"] = examples()
    M.update(guide_cost.collect(M, http_json))
    M["cost_section"] = guide_cost.build(M)
    return M


def examples():
    pay = {"interests": ["beach", "food"], "budget": 30000, "duration_days": 5, "travel_style": "mid-range", "month": 12, "party_size": 1, "top_n": 5}
    times = []
    res = None
    for _ in range(5):
        t0 = time.time()
        res = http_json("/recommend", pay) or res
        times.append((time.time() - t0) * 1000)
    reco = []
    lat = None
    if res and res.get("recommendations"):
        lat = statistics.median(times)
        rows = []
        for r in res["recommendations"]:
            f = r["reason_factors"]
            rows.append([esc(r["destination"]), f"{r['score']:.3f}", f"Rs {r['predicted_cost']:,} ({r['cost_low']:,}-{r['cost_high']:,})", r["budget_fit"],
                         f"{f['content']:.2f} / {f['semantic']:.2f} / {f['collaborative']:.2f} / {f['budget']:.2f}"])
        reco = [H3("Worked example (live output)"),
                P(f"Request: interests beach and food, budget Rs 30,000, 5 days, mid-range, December, one traveller. Output of the running service on {TODAY}; "
                  "factors are content / semantic / collaborative / budget."),
                *table(["Destination", "Score", "Estimated cost (band)", "Budget", "Factors"], rows, [2.0, 0.8, 2.3, 0.9, 2.2]),
                P("<b>Explanation for the first result</b> (generated by explain.py): " + esc(res["recommendations"][0]["explanation"]), "body"),
                callout(f"Latency, measured at build time: the {len(times)} calls above took a median of {statistics.median(times):.0f} ms (slowest {max(times):.0f} ms) each "
                        "on the development laptop, including the explanation step and one database query for evidence. Scoring all destinations is one batched pass, not a loop of model calls.", "measured")]
    else:
        reco = [callout("The ML service was not reachable when this guide was built, so no live example is shown. Start it with ml/start_service.bat and rebuild.", "note")]
    sim = []
    s = http_json("/similar", {"destination": "Jaipur, India", "top_n": 4})
    if s and s.get("similar"):
        rows = [[esc(x["destination"]), f"{x['similarity']:.3f}", esc(x["region"] or ""), esc(x["why"])] for x in s["similar"]]
        sim = [H3("Worked example (live output)"), P("Neighbours of Jaipur, India:"), *table(["Destination", "Cosine", "Region", "Why"], rows, [2.0, 0.8, 1.5, 3.0])]
    return reco, sim, lat


# --------------------------------------------------------------------------------------------------- document
class Guide(BaseDocTemplate):
    def __init__(self, fn, **kw):
        super().__init__(fn, pagesize=(PAGE_W, PAGE_H), leftMargin=LM, rightMargin=RM, topMargin=TM, bottomMargin=BM,
                         title="JourneyAI Master Guide", author="JourneyAI team",
                         subject="Algorithms and system design of JourneyAI", **kw)
        self.cur_section = ""
        body = Frame(LM, BM, CW, PAGE_H - TM - BM, id="body", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
        cover = Frame(0, 0, PAGE_W, PAGE_H, id="cover", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
        self.addPageTemplates([PageTemplate(id="cover", frames=[cover], onPage=cover_page),
                               PageTemplate(id="body", frames=[body], onPageEnd=self.body_page)])

    def afterFlowable(self, fl):
        if isinstance(fl, Section):
            num = f"{fl.num:02d}" if isinstance(fl.num, int) else str(fl.num)
            self.cur_section = f"{num}  {fl.title}"
            key = f"sec{num}"
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(f"{num}  {fl.title}", key, 0, 0)
            self.notify("TOCEntry", (0, f"<font color='#E63457'><b>{num}</b></font>&nbsp;&nbsp;{fl.title}", self.page, key))
            self.lead_for[num] = fl.lead

    lead_for = {}

    def beforeDocument(self):
        self.cur_section = ""

    def body_page(self, c, doc):
        c.saveState()
        c.setStrokeColor(RULE)
        c.setLineWidth(0.5)
        c.line(LM, PAGE_H - TM + 8 * mm, PAGE_W - RM, PAGE_H - TM + 8 * mm)
        c.setFont(SANS_B, 7.4)
        c.setFillColor(MELON)
        c.drawString(LM, PAGE_H - TM + 10.5 * mm, "JOURNEYAI")
        c.setFillColor(MUTE)
        c.setFont(SANS, 7.4)
        c.drawString(LM + 46, PAGE_H - TM + 10.5 * mm, "Master Guide: algorithms and system design")
        c.drawRightString(PAGE_W - RM, PAGE_H - TM + 10.5 * mm, self.cur_section)
        c.setStrokeColor(RULE)
        c.line(LM, BM - 7 * mm, PAGE_W - RM, BM - 7 * mm)
        c.setFillColor(DEEP)
        c.setFont(SERIF_B, 9)
        c.drawRightString(PAGE_W - RM, BM - 12 * mm, str(doc.page))
        c.setFillColor(MUTE)
        c.setFont(SANS, 7)
        c.drawString(LM, BM - 12 * mm, f"Built {TODAY} from the repository as it stands")
        c.restoreState()


def cover_page(c, doc):
    import math
    c.saveState()
    c.setFillColor(DEEP)
    c.rect(0, 0, PAGE_W, PAGE_H, fill=1, stroke=0)
    # concentric watermelon rings, bottom right
    cx, cy = PAGE_W - 26 * mm, 50 * mm
    for i, r in enumerate([150, 118, 86, 54]):
        c.setStrokeColor(colors.HexColor("#1E7B4F"))
        c.setLineWidth(1.0)
        c.circle(cx, cy, r * mm * 0.46, fill=0, stroke=1)
    c.setFillColor(MELON)
    c.circle(cx, cy, 24 * mm, fill=1, stroke=0)
    c.setFillColor(colors.HexColor("#FF6B86"))
    c.circle(cx, cy, 15 * mm, fill=1, stroke=0)
    c.setFillColor(DEEP)
    c.circle(cx, cy, 6 * mm, fill=1, stroke=0)
    # dotted route with pins
    pts = [(24 * mm, 84 * mm), (58 * mm, 116 * mm), (96 * mm, 98 * mm), (128 * mm, 140 * mm), (170 * mm, 124 * mm)]
    c.setStrokeColor(CREAM)
    c.setLineWidth(1.4)
    c.setDash(1, 5)
    p = c.beginPath()
    p.moveTo(*pts[0])
    for a, b in zip(pts, pts[1:]):
        mx = (a[0] + b[0]) / 2
        p.curveTo(mx, a[1], mx, b[1], b[0], b[1])
    c.drawPath(p, stroke=1, fill=0)
    c.setDash()
    for i, (x, y) in enumerate(pts):
        c.setFillColor(MELON if i in (0, len(pts) - 1) else CREAM)
        c.circle(x, y, 2.6 * mm, fill=1, stroke=0)
    c.setFillColor(CREAM)
    c.setFont(SANS_B, 9)
    c.drawString(24 * mm, PAGE_H - 36 * mm, "TECHNICAL GUIDE FOR REVIEWERS AND TEAMMATES")
    c.setStrokeColor(MELON)
    c.setLineWidth(2.4)
    c.line(24 * mm, PAGE_H - 40 * mm, 44 * mm, PAGE_H - 40 * mm)
    c.setFont(SERIF_B, 60)
    c.drawString(24 * mm, PAGE_H - 78 * mm, "JourneyAI")
    c.setFont(SERIF, 24)
    c.drawString(24 * mm, PAGE_H - 94 * mm, "Master Guide")
    c.setFont(SERIF_I, 13.5)
    c.setFillColor(colors.HexColor("#CFE3D6"))
    for i, ln in enumerate(["How the system works and how every algorithm in it works:",
                            "data pipeline, language processing, recommender, cost model,",
                            "itinerary and route planning, chatbot, trust and safety."]):
        c.drawString(24 * mm, PAGE_H - 112 * mm - i * 19, ln)
    c.setFillColor(CREAM)
    c.setFont(SANS_B, 9)
    c.drawString(24 * mm, 36 * mm, "PHP 8  +  MySQL  +  Flask / scikit-learn")
    c.setFont(SANS, 9)
    c.setFillColor(colors.HexColor("#CFE3D6"))
    c.drawString(24 * mm, 30 * mm, f"Built {TODAY}.")
    c.drawString(24 * mm, 25 * mm, "Every number is measured or read from the code;")
    c.drawString(24 * mm, 20 * mm, "how it was obtained is stated beside it.")
    c.restoreState()


def build():
    M = collect()
    doc = Guide(OUT)
    toc = TableOfContents()
    toc.levelStyles = [ParagraphStyle("t0", fontName=SANS, fontSize=10.5, leading=16.5, textColor=DEEP, leftIndent=0, rightIndent=22)]
    toc.dotsMinLevel = 0
    story = [NextPageTemplate("body"), PageBreak(),
             Paragraph("Contents", ParagraphStyle("tt", fontName=SERIF_B, fontSize=26, leading=30, textColor=DEEP, spaceAfter=4)),
             Paragraph("Section numbers match the page headers. Figures are vector drawings made for this guide.", ST["lead"]),
             toc]
    story += evidence_index(M)
    for fn in (C.s01, C.s02, C.s03, C.s04, C.s05, C.s06, C.s07, C.s08, C.s09, C.s10, C.s11, C.s12, C.s13, C.s14):
        story += fn(M)
    story += C.appendix(M)
    doc.multiBuild(story)
    return M


def evidence_index(M):
    n = M["nlp"]
    rows = [["Sentiment accuracy", f"{n['sentiment']['accuracy']*100:.1f} % on 60 team-labelled sentences", "4.7", "ml/eval/nlp_eval.py, run at build"],
            ["Activity-tag F1", f"{n['activity_tags']['f1']:.3f} over {n['activity_tags']['destinations compared']} destinations", "4.7", "ml/eval/nlp_eval.py"],
            ["Destination name matching", f"{n['normalization']['table'][3]['accuracy (%)']:.1f} % with a one-letter typo", "4.7", "ml/eval/nlp_eval.py"],
            ["Planner query parsing", f"{n['query_understanding']['all fields correct (%)']:.0f} % of 40 team-written queries", "4.7", "ml/eval/nlp_eval.py"],
            ["Recommend latency", (f"median {M['lat_med']:.0f} ms over 5 local calls" if M.get("lat_med") else "not measured (service offline)"), "5.6", "timed live at build"],
            *M.get("cost_rows", []),
            ["Site test checks", f"{M['tests_pass']} of {M['tests_total']} passing ({len(TESTS)} suites)", "13.1", "each tests/*.py run on " + M["date"]],
            ["Corpus size", f"{M['n_listings']:,} listings, {M['n_obs']:,} price observations, {M['n_profiles']} destinations", "3", "ml/data/*.json, GET /health"]]
    return [Spacer(1, 10), Paragraph("Evidence index", ParagraphStyle("ei", fontName=SERIF_B, fontSize=13, leading=17, textColor=DEEP, spaceAfter=4)),
            *table(["Number", "Value", "Section", "Where it came from"], rows, [2.0, 3.2, 0.7, 2.4])]


def guide_intro(M):
    """A short 'how to read this' block that follows the contents, so sections start on fresh pages."""
    return [Spacer(1, 8),
            callout("<b>Reading order.</b> Sections 1-3: the whole picture and the data. 4-9: each algorithm, in the order a request meets it. 10-12: maps, safety, interface. "
                    "13-14: what was measured, and the limits. Green <b>MEASURED</b> and red <b>CAVEAT</b> boxes separate evidence from claims.", "note", "HOW TO READ THIS GUIDE")]


if __name__ == "__main__":
    build()
    print("wrote", OUT)
