"""
JourneyAI — build the India dataset from the crawled Wikivoyage data (Phase 2).

Inputs  (from ml.scrape.wikivoyage):  ml/data/india_destinations.json, india_listings.json
Outputs (ml/data/):
    india_cost_observations.json  every parseable INR price with destination + listing type
    india_catalog.json            destination dicts (same shape as ml/seed/destination_catalog.D)
    real_entries.json             per-destination real-text entries (Wikivoyage, CC BY-SA) w/ source URL

Everything is derived from real published listings; `base_daily_inr` is an estimate computed from
observed meal / lodging / entry prices (see estimate_daily), falling back to the parent region's
median when a destination has too few observations.

    ml/venv/Scripts/python.exe -m ml.scrape.build_india_dataset
"""
import json
import os
import re
import statistics as st
import sys
from collections import Counter, defaultdict

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.nlp.lexicon import ACTIVITY_GAZETTEER  # noqa: E402
from ml.scrape.wikivoyage import fetch_wikitext, parse_sections, clean  # noqa: E402

DATA = os.path.join(ROOT, "ml", "data")

PRICE = re.compile(r"(?:₹|Rs\.?|INR|Rupees?)\s*([\d][\d,]*(?:\.\d+)?)\s*(?:(?:-|–|—|to)\s*(?:₹|Rs\.?|INR)?\s*([\d][\d,]*(?:\.\d+)?))?", re.I)

REGION_OF_TOP = {
    "Himalayan North": "Himalayas", "Plains (India)": "North India", "Western India": "West India",
    "Central India": "Central India", "Eastern India": "East India",
    "North-Eastern India": "Northeast India", "Southern India": "South India",
    "Delhi": "North India", "Jaipur": "North India", "Varanasi": "North India",
    "Bengaluru": "South India", "Chennai": "South India", "Hyderabad": "South India",
    "Mumbai": "West India", "Kolkata": "East India", "Shimla": "Himalayas",
}
MONTHS = {m: i + 1 for i, m in enumerate(
    ["january", "february", "march", "april", "may", "june", "july", "august", "september",
     "october", "november", "december"])}
SEASON_CTX = re.compile(r"(best time|peak season|high season|tourist season|ideal time|pleasant|cool season|"
                        r"visit between|from (?:october|november|december))", re.I)
STYLE_BY_ACT = {"beach": ["budget", "solo"], "trekking": ["adventure", "backpacker"],
                "temples": ["family", "budget"], "nightlife": ["solo", "mid-range"],
                "wildlife": ["family", "adventure"], "history": ["family", "mid-range"],
                "mountains": ["adventure", "backpacker"], "relaxation": ["mid-range", "family"]}


def parse_prices(text):
    """-> list of INR amounts (range collapsed to its midpoint)."""
    out = []
    for m in PRICE.finditer(text or ""):
        a = float(m.group(1).replace(",", ""))
        b = float(m.group(2).replace(",", "")) if m.group(2) else None
        v = (a + b) / 2 if b and b >= a else a
        if 10 <= v <= 500000:
            out.append(v)
    return out


def estimate_daily(eat, sleep, fees, fallback=None):
    """Per-person daily INR: 3 meals + 1 night (shared by 2 -> ~60%) + 2 entry fees + local transport."""
    if len(eat) + len(sleep) < 3 and fallback:
        return fallback
    meal = st.median(eat) if eat else (fallback or 1800) * 0.12
    night = st.median(sleep) if sleep else (fallback or 1800) * 0.45
    fee = st.median(fees) if fees else 100
    est = 3 * min(meal, 1500) + 0.6 * min(night, 15000) + 2 * min(fee, 1500) + 250
    return int(round(est / 50.0) * 50)


def detect_activities(text, n=5):
    c = Counter()
    low = text.lower()
    for kw, act in ACTIVITY_GAZETTEER.items():
        hits = len(re.findall(r"\b" + re.escape(kw) + r"\b", low))
        if hits:
            c[act] += hits
    return [a for a, _ in c.most_common(n)] or ["culture"]


def detect_season(text, lat):
    peak = Counter()
    for sent in re.split(r"(?<=[.!?])\s+", text):
        if SEASON_CTX.search(sent):
            for mname, mi in MONTHS.items():
                if mname in sent.lower():
                    peak[mi] += 1
    if peak:
        return sorted(m for m, _ in peak.most_common(5))
    return [5, 6, 7, 8, 9, 10] if (lat or 0) > 30 else [10, 11, 12, 1, 2, 3]


def main():
    nodes = json.load(open(os.path.join(DATA, "india_destinations.json"), encoding="utf8"))
    listings = json.load(open(os.path.join(DATA, "india_listings.json"), encoding="utf8"))
    by_title = {n["title"]: n for n in nodes}

    def top_region(title):
        seen = 0
        while title and seen < 10:
            if title in REGION_OF_TOP:
                return REGION_OF_TOP[title]
            title = by_title.get(title, {}).get("parent")
            seen += 1
        return "North India"

    by_dest = defaultdict(list)
    for l in listings:
        by_dest[l["destination"]].append(l)

    # --- cost observations -------------------------------------------------
    obs = []
    per = defaultdict(lambda: {"eat": [], "sleep": [], "fees": []})
    for d, ls in by_dest.items():
        for l in ls:
            amts = parse_prices(l["price"])
            if not amts:
                continue
            kind = {"eat": "eat", "drink": "eat", "sleep": "sleep", "see": "fees", "do": "fees"}.get(l["type"])
            if not kind:
                continue
            v = st.median(amts)
            per[d][kind].append(v)
            obs.append({"destination": d, "type": l["type"], "name": l["name"], "price_raw": l["price"],
                        "inr": round(v, 1), "source": "wikivoyage", "url": l["source_url"]})
    json.dump(obs, open(os.path.join(DATA, "india_cost_observations.json"), "w", encoding="utf8"),
              ensure_ascii=False)

    region_pool = defaultdict(lambda: {"eat": [], "sleep": [], "fees": []})
    for d, p in per.items():
        r = top_region(d)
        for k in p:
            region_pool[r][k].extend(p[k])
    region_daily = {r: estimate_daily(p["eat"], p["sleep"], p["fees"]) for r, p in region_pool.items()}

    # --- catalog + real entries -------------------------------------------
    cand = [n for n in nodes if n["kind"] in ("city", "other") and "/" not in n["title"]
            and len(by_dest.get(n["title"], [])) >= 5]
    texts = fetch_wikitext([n["title"] for n in cand])
    pp = os.path.join(DATA, "india_prominence.json")
    prom = json.load(open(pp, encoding="utf8")) if os.path.exists(pp) else {}
    catalog, entries = [], []
    for n in cand:
        t = n["title"]
        ls = by_dest[t]
        views = prom.get(t, 0.0)
        n_obs = sum(len(v) for v in per[t].values())
        # prominence filter: drop Wikivoyage stub villages nobody travels to (no attention, thin content)
        if prom and views < 30 and len(ls) < 12 and n_obs < 3:
            continue
        region = top_region(t)
        secs = parse_sections(texts.get(t, ""))
        intro = clean(re.sub(r"^.*?(?=\n[A-Za-z'\[])", "", texts.get(t, "").split("\n==")[0], flags=re.S))[:600]
        body = " ".join(clean(secs.get(k, ""))[:900] for k in ("Understand", "See", "Do", "Eat", "Get in", "Stay safe"))
        listing_txt = " ".join(f"{l['name']}. {l['description']}" for l in ls if l["type"] in ("see", "do", "eat"))
        corpus = f"{intro} {body} {listing_txt}"
        acts = detect_activities(corpus)
        sees = [l["name"] for l in ls if l["type"] == "see"][:4] or [l["name"] for l in ls][:4]
        daily = estimate_daily(per[t]["eat"], per[t]["sleep"], per[t]["fees"], fallback=region_daily.get(region, 2200))
        n_see = sum(1 for l in ls if l["type"] == "see")
        tier = "mainstream" if (views >= 150 or n_see >= 15) else "lesser_known"
        styles = sorted({s for a in acts for s in STYLE_BY_ACT.get(a, [])}) or ["mid-range", "family"]
        name = f"{t}, India"
        catalog.append({
            "name": name, "country": "India", "city": t, "region": region, "tier": tier,
            "base_daily_inr": daily, "activities": acts[:4], "styles": styles[:3],
            "season_peak": detect_season(corpus, n["lat"]), "attractions": sees,
            "description": intro[:400], "lat": n["lat"], "lon": n["lon"],
            "n_listings": len(ls), "n_price_obs": n_obs, "wiki_views": views,
            "source": "wikivoyage", "source_url": "https://en.wikivoyage.org/wiki/" + t.replace(" ", "_"),
        })
        # real entries: one per narrative block, so TF-IDF has genuine varied text to learn from
        blocks = [("overview", f"{intro} {clean(secs.get('Understand', ''))[:900]}"),
                  ("sights", " ".join(f"{l['name']}: {l['description']}" for l in ls if l["type"] == "see")[:1400]),
                  ("food", " ".join(f"{l['name']}: {l['description']}" for l in ls if l["type"] in ("eat", "drink"))[:1200]),
                  ("activities", " ".join(f"{l['name']}: {l['description']}" for l in ls if l["type"] == "do")[:1200])]
        for label, txt in blocks:
            if len(txt.split()) >= 25:
                entries.append({"destination": name, "kind": label, "text": txt,
                                "source": "wikivoyage", "url": catalog[-1]["source_url"]})
    # Calibrate: Wikivoyage prices skew budget/dated. Scale derived daily costs so that, on the
    # destinations ALSO in our hand-researched catalog, they match it (median ratio). Curated wins
    # on overlap anyway; this only corrects the destinations that exist solely in the scraped set.
    from ml.seed.destination_catalog import DESTINATIONS
    cur = {d["city"].lower(): d["base_daily_inr"] for d in DESTINATIONS if d["country"] == "India"}
    ratios = [cur[c["city"].lower()] / c["base_daily_inr"] for c in catalog if c["city"].lower() in cur and c["base_daily_inr"]]
    k = st.median(ratios) if len(ratios) >= 8 else 1.0
    k = min(max(k, 1.0), 2.5)
    for c in catalog:
        c["base_daily_inr_raw"] = c["base_daily_inr"]
        c["base_daily_inr"] = int(round(c["base_daily_inr"] * k / 50.0) * 50)
    print(f"calibration: {len(ratios)} overlapping destinations, scale x{k:.2f}")
    json.dump(catalog, open(os.path.join(DATA, "india_catalog.json"), "w", encoding="utf8"), ensure_ascii=False, indent=1)
    json.dump(entries, open(os.path.join(DATA, "real_entries.json"), "w", encoding="utf8"), ensure_ascii=False)
    print(f"cost observations: {len(obs)}; destinations w/ >=5 listings: {len(catalog)}; real entries: {len(entries)}")
    print("region daily medians:", region_daily)
    print("tiers:", Counter(c["tier"] for c in catalog), "regions:", Counter(c["region"] for c in catalog))


if __name__ == "__main__":
    main()
