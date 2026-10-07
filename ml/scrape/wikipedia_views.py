"""
JourneyAI — destination prominence from Wikipedia pageviews (last ~60 days, daily average).

Used to (a) drop micro-villages that only exist as Wikivoyage stubs and (b) set the mainstream /
lesser_known tier from real attention instead of listing counts.  Output: ml/data/india_prominence.json
    ml/venv/Scripts/python.exe -m ml.scrape.wikipedia_views
"""
import json
import os
import sys
import time

import requests

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DATA = os.path.join(ROOT, "ml", "data")
API = "https://en.wikipedia.org/w/api.php"
S = requests.Session()
S.headers["User-Agent"] = "JourneyAI-research/1.0 (student travel-recommender project; polite crawler)"


def main():
    cat = json.load(open(os.path.join(DATA, "india_catalog.json"), encoding="utf8"))
    titles = [d["city"] for d in cat]
    out = {}
    for i in range(0, len(titles), 50):
        batch = titles[i:i + 50]
        q = {}
        for attempt in range(8):
            r = S.get(API, params={"action": "query", "prop": "pageviews", "titles": "|".join(batch),
                                   "redirects": 1, "format": "json", "formatversion": 2, "pvipdays": 60}, timeout=40)
            if r.status_code == 200:
                q = r.json().get("query", {})
                break
            time.sleep(10 * (attempt + 1))  # 429 -> back off
        back = {}
        for m in q.get("normalized", []) + q.get("redirects", []):
            back[m["to"]] = back.get(m["from"], m["from"])
        for pg in q.get("pages", []):
            pv = [v for v in (pg.get("pageviews") or {}).values() if v is not None]
            avg = sum(pv) / len(pv) if pv else 0.0
            asked = back.get(pg["title"], pg["title"])
            out[asked] = max(out.get(asked, 0), round(avg, 1))
            out[pg["title"]] = max(out.get(pg["title"], 0), round(avg, 1))
        time.sleep(2)
    res = {t: out.get(t, 0.0) for t in titles}
    json.dump(res, open(os.path.join(DATA, "india_prominence.json"), "w", encoding="utf8"), ensure_ascii=False)
    vals = sorted(res.values())
    print("n", len(vals), "median", vals[len(vals) // 2], "p25", vals[len(vals) // 4], "p75", vals[3 * len(vals) // 4], "zero:", sum(v == 0 for v in vals))


if __name__ == "__main__":
    main()
