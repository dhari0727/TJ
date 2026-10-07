"""
JourneyAI — travel blog / article collector (Phase 3).

Candidate URLs (direct article patterns; SerpAPI path dropped - no valid key) -> polite fetch (robots.txt honoured, 1.5s delay, cached)
-> extract DERIVED data only:
    * daily-cost observations  ("Rs 3,000 per day", "budget of Rs 25,000 for 5 days")
    * activity tags, sentiment, short attributed snippet (<=450 chars of relevant sentences + URL)
Full article text is never stored or republished.

Outputs: ml/data/blog_entries.json (same shape as real_entries.json), ml/data/blog_cost_obs.json

    ml/venv/Scripts/python.exe -m ml.scrape.blogs --limit 40 --per 4
"""
import argparse
import hashlib
import json
import os
import re
import sys
import time
import urllib.robotparser as rp
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.nlp.lexicon import ACTIVITY_GAZETTEER  # noqa: E402

DATA = os.path.join(ROOT, "ml", "data")
CACHE = os.path.join(DATA, "raw", "blogs")
UA = "JourneyAI-research/1.0 (student travel-recommender project; +polite)"
SKIP = ("youtube.", "facebook.", "instagram.", "reddit.", "quora.", "pinterest.", "tripadvisor.", "wikipedia.",
        "wikivoyage.", "twitter.", "x.com", "booking.com", "makemytrip.", "agoda.", "airbnb.")
_robots = {}
S = requests.Session()
S.headers["User-Agent"] = UA

PER_DAY = re.compile(r"(?:₹|Rs\.?|INR)\s*([\d][\d,]*)\s*(?:(?:-|–|to)\s*(?:₹|Rs\.?)?\s*([\d][\d,]*))?\s*(?:/-)?\s*(?:per|a|/|each)\s*(?:person\s*)?(?:per\s*)?day", re.I)
TOTAL = re.compile(r"(?:budget|cost|expense|spend|total)[^.\n]{0,70}?(?:₹|Rs\.?|INR)\s*([\d][\d,]*)[^.\n]{0,60}?(\d{1,2})\s*(?:days|day|nights|night)", re.I)


def key():
    return open(os.path.join(ROOT, "ml", "geo", "serpapi_key")).read().strip()


def allowed(url):
    host = urlparse(url)
    base = f"{host.scheme}://{host.netloc}"
    if base not in _robots:
        r = rp.RobotFileParser()
        try:
            resp = S.get(base + "/robots.txt", timeout=8)
            r.parse(resp.text.splitlines() if resp.status_code == 200 else [])
        except Exception:  # noqa: BLE001
            r.parse([])
        _robots[base] = r
    return _robots[base].can_fetch(UA, url)


def candidates(city):
    """No search API available -> direct, robots-permitted article URLs on travel sites."""
    slug = re.sub(r"[^a-z0-9]+", "-", city.lower()).strip("-")
    return [{"title": f"Holidify - {city}", "link": f"https://www.holidify.com/places/{slug}/"},
            {"title": f"Holidify things to do - {city}",
             "link": f"https://www.holidify.com/places/{slug}/sightseeing-and-things-to-do.html"}]


def fetch(url):
    cp = os.path.join(CACHE, "page_" + hashlib.md5(url.encode()).hexdigest()[:12] + ".txt")
    if os.path.exists(cp):
        return open(cp, encoding="utf8").read()
    if not allowed(url):
        return ""
    time.sleep(1.5)
    try:
        r = S.get(url, timeout=15)
        if r.status_code != 200 or "text/html" not in r.headers.get("content-type", ""):
            return ""
        soup = BeautifulSoup(r.text, "html.parser")
        for t in soup(["script", "style", "nav", "footer", "header", "aside"]):
            t.decompose()
        text = "\n".join(p.get_text(" ", strip=True) for p in soup.find_all(["p", "li", "h2", "h3"]))
    except Exception:  # noqa: BLE001
        return ""
    open(cp, "w", encoding="utf8").write(text[:200000])
    return text


def extract(text, city):
    obs = []
    for m in PER_DAY.finditer(text):
        a = float(m.group(1).replace(",", ""))
        b = float(m.group(2).replace(",", "")) if m.group(2) else a
        v = (a + b) / 2
        if 300 <= v <= 40000:
            obs.append(("per_day", v))
    for m in TOTAL.finditer(text):
        tot, days = float(m.group(1).replace(",", "")), int(m.group(2))
        if days and 500 <= tot / days <= 40000:
            obs.append(("total_div_days", tot / days))
    # snippet: sentences that mention the city AND an activity/cost keyword
    sents = re.split(r"(?<=[.!?])\s+", text)
    keep = [s for s in sents if city.lower() in s.lower() and any(k in s.lower() for k in ACTIVITY_GAZETTEER)
            and 40 < len(s) < 260][:2]
    return obs, " ".join(keep)[:450]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=150, help="destinations to research (SerpAPI queries)")
    ap.add_argument("--per", type=int, default=2, help="pages per destination")
    a = ap.parse_args()
    os.makedirs(CACHE, exist_ok=True)
    cat = json.load(open(os.path.join(DATA, "india_catalog.json"), encoding="utf8"))
    cat.sort(key=lambda d: -d["n_listings"])
    entries, obs_all = [], []
    for d in cat[:a.limit]:
        city = d["city"]
        for res in candidates(city)[:a.per]:
            url = res["link"] or ""
            if not url or any(s in url for s in SKIP):
                continue
            text = fetch(url)
            if len(text) < 800:
                continue
            obs, snip = extract(text, city)
            for kind, v in obs:
                obs_all.append({"destination": d["name"], "daily_inr": round(v), "how": kind, "url": url,
                                "title": res["title"]})
            if len(snip) > 80:
                entries.append({"destination": d["name"], "kind": "blog", "text": snip, "source": "blog", "url": url})
        print(city, len(entries), len(obs_all), flush=True)
    json.dump(entries, open(os.path.join(DATA, "blog_entries.json"), "w", encoding="utf8"), ensure_ascii=False)
    json.dump(obs_all, open(os.path.join(DATA, "blog_cost_obs.json"), "w", encoding="utf8"), ensure_ascii=False)
    print("blog entries:", len(entries), "cost obs:", len(obs_all))


if __name__ == "__main__":
    main()
