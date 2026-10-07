"""
JourneyAI — real traveller trip reports from Tripoto (user-written blogs/itineraries).

Why: the review feedback asked for real user data from blogs. Tripoto publishes thousands of first-person trip
reports, many with the money spent. We collect politely and keep DERIVED data only:
  * robots.txt is honoured (generic crawler rules allow /trip/ pages), 1.5 s between requests, identified UA,
    every page cached under ml/data/raw/tripoto/ so re-runs are offline
  * from each report we store: URL, title, destination, number of days, rupee amounts mentioned with their
    context (total / per-day), activity tags, a sentiment score and a <=450-character attributed snippet
  * full article text is never stored or republished

Candidates are picked from the sitemap: URLs whose slug names one of our destinations (max 3 per destination so
the sample is spread across India, not just Goa/Manali).

    ml/venv/Scripts/python.exe -m ml.scrape.tripoto --max-pages 350
Outputs (merged into the existing blog files): ml/data/blog_entries.json, ml/data/blog_cost_obs.json
"""
import argparse
import hashlib
import json
import os
import re
import sys
import time
import urllib.robotparser as rp

import requests
from bs4 import BeautifulSoup

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.nlp.lexicon import ACTIVITY_GAZETTEER  # noqa: E402

DATA = os.path.join(ROOT, "ml", "data")
CACHE = os.path.join(DATA, "raw", "tripoto")
UA = "JourneyAI-research/1.0 (student travel-recommender project; polite crawler)"
BASE = "https://www.tripoto.com"
S = requests.Session()
S.headers["User-Agent"] = UA

RUPEE = r"(?:₹|Rs\.?|INR|Rupees?)\s*([\d][\d,]*(?:\.\d+)?)\s*(k|K|thousand|lakh|lac)?"
RUPEE_RE = re.compile(RUPEE, re.I)
TOTAL_CTX = re.compile(r"(total|overall|budget|trip cost|expense|expenses|spent|cost of the trip|all[- ]inclusive|package)", re.I)
PERDAY_CTX = re.compile(r"(per day|a day|/day|daily|each day)", re.I)
PERPERSON_CTX = re.compile(r"(per person|per head|each|pp\b|/person)", re.I)
DAYS_RE = re.compile(r"(\d{1,2})\s*[- ]?\s*(?:days?|d)\b(?:\s*(?:and|&)\s*\d{1,2}\s*nights?)?|(\d{1,2})\s*nights?", re.I)

POS = {"amazing", "beautiful", "wonderful", "loved", "love", "great", "stunning", "perfect", "magical", "serene",
       "peaceful", "breathtaking", "fantastic", "best", "memorable", "delicious", "worth", "lovely", "awesome", "gorgeous"}
NEG = {"crowded", "overpriced", "disappointing", "dirty", "bad", "worst", "terrible", "boring", "expensive", "rude",
       "messy", "tiring", "exhausting", "scam", "waste", "poor", "unsafe", "delayed"}

_robots = None


def allowed(url):
    global _robots
    if _robots is None:
        _robots = rp.RobotFileParser()
        try:
            _robots.parse(S.get(BASE + "/robots.txt", timeout=10).text.splitlines())
        except Exception:  # noqa: BLE001
            _robots.parse([])
    return _robots.can_fetch(UA, url)


def candidates(cities, per_city=3, max_total=350, sitemaps=14):
    """Pick trip-report URLs whose slug names one of our destinations (max per_city each).
    Fast n-gram lookup: a slug like 'weekend-trip-to-mount-abu-123' is split into words and every 1-3 word
    run is looked up in the destination dictionary."""
    key = {re.sub(r"[^a-z0-9]+", "-", c).strip("-"): c for c in cities}
    seen, per, out = set(), {}, []
    pat = re.compile(r"budget|cost|expense|itinerary|cheap|under|inr|rs-|day|night|weekend|trip|diary|guide", re.I)
    for v in range(1, sitemaps + 1):
        try:
            r = S.get(f"{BASE}/sitemap-trip-v{v}.xml", timeout=40)
        except Exception:  # noqa: BLE001
            continue
        if r.status_code != 200:
            continue
        for u in re.findall(r"<loc>(.*?)</loc>", r.text):
            slug = u.rsplit("/", 1)[-1].lower()
            if u in seen or not pat.search(slug):
                continue
            words = [w for w in slug.split("-") if w]
            found = None
            for n in (3, 2, 1):                       # prefer 'mount-abu' over 'abu'
                for i in range(len(words) - n + 1):
                    k = "-".join(words[i:i + n])
                    if k in key:
                        found = key[k]
                        break
                if found:
                    break
            if found and per.get(found, 0) < per_city:
                per[found] = per.get(found, 0) + 1
                seen.add(u)
                out.append((found, u))
        print(f"  sitemap v{v}: {len(out)} candidates so far", flush=True)
        time.sleep(0.5)
        if len(out) >= max_total:
            break
    return out[:max_total]


def fetch(url):
    cp = os.path.join(CACHE, hashlib.md5(url.encode()).hexdigest()[:14] + ".txt")
    if os.path.exists(cp):
        return open(cp, encoding="utf8").read()
    if not allowed(url):
        return ""
    time.sleep(1.5)
    try:
        r = S.get(url, timeout=20)
        if r.status_code != 200 or "text/html" not in r.headers.get("content-type", ""):
            return ""
        soup = BeautifulSoup(r.text, "html.parser")
        for t in soup(["script", "style", "nav", "footer", "header", "aside", "form"]):
            t.decompose()
        title = (soup.find("h1") or soup.find("title"))
        head = title.get_text(" ", strip=True) if title else ""
        body = "\n".join(p.get_text(" ", strip=True) for p in soup.find_all(["p", "li", "h2", "h3"]))
        text = head + "\n" + body
    except Exception:  # noqa: BLE001
        return ""
    open(cp, "w", encoding="utf8").write(text[:150000])
    return text


def to_inr(num, suffix):
    v = float(num.replace(",", ""))
    s = (suffix or "").lower()
    if s in ("k", "thousand"):
        v *= 1000
    elif s in ("lakh", "lac"):
        v *= 100000
    return v


def analyse(text, city):
    title = text.split("\n", 1)[0][:160]
    days = None
    m = DAYS_RE.search(title) or DAYS_RE.search(text[:1500])
    if m:
        days = int(m.group(1) or m.group(2))
        if not (1 <= days <= 30):
            days = None
    obs = []
    for sent in re.split(r"(?<=[.!?\n])\s+", text):
        for m in RUPEE_RE.finditer(sent):
            v = to_inr(m.group(1), m.group(2))
            if not (200 <= v <= 500000):
                continue
            kind = None
            if PERDAY_CTX.search(sent) and 300 <= v <= 40000:
                kind = "per_day"
            elif TOTAL_CTX.search(sent) and days and 1500 <= v <= 400000:
                kind = "trip_total"
            if kind:
                per_person = bool(PERPERSON_CTX.search(sent))
                obs.append({"kind": kind, "inr": round(v), "per_person": per_person, "sentence": sent.strip()[:160]})
    low = text.lower()
    acts = {}
    for kw, act in ACTIVITY_GAZETTEER.items():
        if len(kw) > 3 and re.search(r"\b" + re.escape(kw) + r"\b", low):
            acts[act] = acts.get(act, 0) + 1
    words = re.findall(r"[a-z']+", low)
    pos = sum(1 for w in words if w in POS)
    neg = sum(1 for w in words if w in NEG)
    sent_score = round((pos - neg) / max(1, pos + neg), 2)
    city_l = city.lower()
    sents = [s for s in re.split(r"(?<=[.!?])\s+", text) if city_l in s.lower() and 50 < len(s) < 260]
    snippet = " ".join(sents[:2])[:450]
    return {"title": title, "days": days, "obs": obs, "activities": sorted(acts, key=acts.get, reverse=True)[:5],
            "sentiment": sent_score, "snippet": snippet, "n_words": len(words)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=350)
    ap.add_argument("--per-city", type=int, default=3)
    a = ap.parse_args()
    os.makedirs(CACHE, exist_ok=True)
    cat = json.load(open(os.path.join(DATA, "india_catalog.json"), encoding="utf8"))
    by_city = {}
    for d in cat:
        by_city.setdefault(d["city"].lower(), d["name"])
    # also the curated destinations (they are not all in india_catalog.json)
    from ml.seed.destination_catalog import get_destinations
    for d in get_destinations():
        if d["country"] == "India":
            by_city.setdefault(d["city"].lower(), d["name"])
    cities = sorted(by_city)
    print(f"{len(cities)} Indian destinations to match against trip-report URLs")
    cands = candidates(cities, a.per_city, a.max_pages)
    print(f"{len(cands)} candidate reports")

    entries, cost_obs, reports = [], [], []
    for i, (city, url) in enumerate(cands, 1):
        text = fetch(url)
        if len(text) < 600:
            continue
        info = analyse(text, city)
        dest = by_city[city]
        reports.append({"destination": dest, "url": url, "title": info["title"], "days": info["days"],
                        "n_cost_mentions": len(info["obs"]), "sentiment": info["sentiment"],
                        "activities": info["activities"], "n_words": info["n_words"]})
        if len(info["snippet"]) > 80:
            entries.append({"destination": dest, "kind": "blog", "text": info["snippet"], "source": "tripoto", "url": url})
        for o in info["obs"]:
            if o["kind"] == "per_day":
                daily = o["inr"]
            else:
                daily = o["inr"] / max(1, info["days"] or 1)
                if info["days"] is None:
                    continue
            if 300 <= daily <= 40000:
                cost_obs.append({"destination": dest, "daily_inr": round(daily), "how": o["kind"], "days": info["days"],
                                 "per_person": o["per_person"], "url": url, "title": info["title"], "context": o["sentence"]})
        if i % 25 == 0:
            print(f"  {i}/{len(cands)} pages | reports kept {len(reports)} | cost observations {len(cost_obs)}", flush=True)

    # merge with the earlier (Holidify) blog files, replacing any previous Tripoto rows
    ep, cp = os.path.join(DATA, "blog_entries.json"), os.path.join(DATA, "blog_cost_obs.json")
    old_e = [e for e in (json.load(open(ep, encoding="utf8")) if os.path.exists(ep) else []) if e.get("source") != "tripoto"]
    old_c = [c for c in (json.load(open(cp, encoding="utf8")) if os.path.exists(cp) else []) if "tripoto.com" not in c.get("url", "")]
    json.dump(old_e + entries, open(ep, "w", encoding="utf8"), ensure_ascii=False)
    json.dump(old_c + cost_obs, open(cp, "w", encoding="utf8"), ensure_ascii=False)
    json.dump(reports, open(os.path.join(DATA, "tripoto_reports.json"), "w", encoding="utf8"), ensure_ascii=False)
    print(f"done: {len(reports)} reports, {len(entries)} snippets, {len(cost_obs)} cost observations "
          f"across {len({r['destination'] for r in reports})} destinations")


if __name__ == "__main__":
    main()
