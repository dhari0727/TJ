"""
JourneyAI - build the REAL, tier-labelled price observations the cost model trains on.

Source: the cached Wikivoyage wikitext in ml/data/raw/wikivoyage/ (1,422 India articles, written by
travellers who list the actual price they paid or saw posted). The older flat file
ml/data/india_cost_observations.json dropped the article section a listing sat in; this parser keeps it.
Wikivoyage's own convention puts accommodation (and some eateries) under editor-assigned
"Budget", "Mid-range" and "Splurge" sub-headings - that is the only tier information in the data, and the
cost model uses it directly (no invented tier multipliers).

Per listing we keep:
    destination   article title ("Jaipur"; district pages "Kolkata/South" roll up to "Kolkata")
    type          sleep | eat | drink | see | do
    tier          budget | mid | splurge | None   (from the sub-heading, Sleep and Eat sections only)
    inr           the representative INR amount (see price_value)
    basis         'room' | 'bed' (dorm / per-bed / per-person lodging) | 'item'
    name, price_raw, url   provenance

Run:   ml/venv/Scripts/python.exe -m ml.cost.build_observations
Output: ml/data/cost_obs_tiered.json
"""
import glob
import json
import os
import re
import statistics as st
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.scrape.wikivoyage import _find_templates, _split_params, clean  # noqa: E402

RAW = os.path.join(ROOT, "ml", "data", "raw", "wikivoyage")
OUT = os.path.join(ROOT, "ml", "data", "cost_obs_tiered.json")

PRICE = re.compile(r"(?:₹|Rs\.?|INR|Rupees?)\s*([\d][\d,]*(?:\.\d+)?)\s*(?:(?:-|–|—|&ndash;|to)\s*"
                   r"(?:₹|Rs\.?|INR)?\s*([\d][\d,]*(?:\.\d+)?))?", re.I)
HEAD = re.compile(r"^(={2,4})\s*(.*?)\s*\1\s*$", re.M)
TIER_OF = {"budget": "budget", "cheap": "budget", "mid-range": "mid", "midrange": "mid", "mid range": "mid",
           "splurge": "splurge", "luxury": "splurge", "top end": "splurge", "upscale": "splurge"}
SECTION_TYPES = {"see": "see", "do": "do", "eat": "eat", "drink": "drink", "sleep": "sleep",
                 "eat and drink": None, "see and do": None}
BED_WORDS = re.compile(r"\b(dorm|dormitory|per bed|bunk|per person|per head|/person|a bed)\b", re.I)


def amounts(text):
    out = []
    for m in PRICE.finditer(text or ""):
        a = float(m.group(1).replace(",", ""))
        b = float(m.group(2).replace(",", "")) if m.group(2) else None
        v = (a + b) / 2 if b and b >= a else a
        if 10 <= v <= 500000:
            out.append((v, m.start(), m.end()))
    return out


def price_value(kind, text):
    """Representative INR amount of a listing's price text. sleep/eat/drink: median of the amounts quoted
    (range -> midpoint). see/do: the price an INDIAN visitor pays - the amount stated next to 'Indian(s)'
    if there is one, else the first amount (Wikivoyage lists the domestic rate first)."""
    am = amounts(text)
    if not am:
        return None
    if kind in ("sleep", "eat", "drink"):
        return float(st.median(v for v, _, _ in am))
    low = text.lower()
    for v, s, e in am:
        if re.search(r"indian|domestic|resident", low[e:e + 40]) and "foreign" not in low[e:e + 12]:
            return float(v)
    return float(am[0][0])


def tier_of(h3):
    return TIER_OF.get((h3 or "").strip().lower())


def parse_article(title, wikitext, url):
    # split into (h2, h3, chunk)
    marks = [(m.start(), m.end(), len(m.group(1)), m.group(2)) for m in HEAD.finditer(wikitext)]
    chunks, h2, h3, last = [], "", "", 0
    cur_start = 0
    for s, e, lvl, name in marks:
        chunks.append((h2, h3, wikitext[cur_start:s]))
        if lvl == 2:
            h2, h3 = name.lower(), ""
        else:
            h3 = name.lower()
        cur_start = e
    chunks.append((h2, h3, wikitext[cur_start:]))
    rows = []
    for h2n, h3n, chunk in chunks:
        sec_kind = SECTION_TYPES.get(h2n)
        for name, inner in _find_templates(chunk, ["see", "do", "eat", "drink", "sleep", "listing"]):
            p = _split_params(inner)
            nm = clean(p.get("name", ""))
            if not nm:
                continue
            kind = p.get("type", name).lower() if name == "listing" else name
            if kind not in ("see", "do", "eat", "drink", "sleep"):
                continue
            raw = clean(p.get("price", ""))
            val = price_value(kind, raw)
            if val is None:
                continue
            tier = tier_of(h3n) if (kind in ("sleep", "eat") and h2n in ("sleep", "eat", "eat and drink")) else None
            basis = "item"
            if kind == "sleep":
                basis = "bed" if BED_WORDS.search(raw) and not re.search(r"\b(double|room|twin|suite)\b", raw, re.I) else "room"
                if re.search(r"\b(dorm|dormitory|bunk)\b", raw, re.I) and not re.search(r"\b(double|twin|suite)\b", raw, re.I):
                    basis = "bed"
            rows.append({"destination": title, "type": kind, "tier": tier, "inr": round(val, 1), "basis": basis,
                         "name": nm[:80], "price_raw": raw[:300], "url": url})
    return rows


MODES = [("taxi", r"\b(taxi|taxis|cab|cabs|ola|uber)\b"),
         ("auto", r"\b(auto|autos|auto-?rickshaws?|autorickshaws?|autorikshaws?|rickshaws?|tuk)\b"),
         ("bus", r"\b(bus|buses|metro|tram|local train|ferry|ferries|share[d]? jeep|shared)\b")]
# keep only single flat fares: drop per-km / per-hour / rentals / airport transfers / deposits / itemised lists
FARE_EXCLUDE = re.compile(r"(per km|/km|a km|every km|each km|per hour|an hour|per day|daily|/day|airport|railway station to|"
                          r"deposit|advance|rent|hire|guide|package|full day|whole day|night|minimum|waiting|=|\*)", re.I)
OUT_T = os.path.join(ROOT, "ml", "data", "cost_obs_transport.json")


def parse_fares(title, wikitext, url):
    """One flat local-ride fare per sentence of the 'Get around' section (mode = taxi / auto-rickshaw / bus-metro)."""
    m = re.search(r"^==\s*Get around\s*==\s*$(.*?)(?=^==[^=])", wikitext, re.M | re.S)
    if not m:
        return []
    out = []
    for s in re.split(r"(?<=[.!?])\s+", clean(m.group(1))):
        if not PRICE.search(s) or FARE_EXCLUDE.search(s) or len(s) > 260:
            continue
        low = s.lower()
        mode = next((k for k, p in MODES if re.search(p, low)), None)
        am = [v for v, _, _ in amounts(s)] or [float(x.group(1).replace(",", "")) for x in PRICE.finditer(s)]
        if mode and am:
            out.append({"destination": title, "mode": mode, "inr": float(st.median(am)), "sentence": s[:240], "url": url})
    return out


def main():
    rows = []
    fares = []
    n_art = 0
    for f in sorted(glob.glob(os.path.join(RAW, "*.json"))):
        d = json.load(open(f, encoding="utf-8"))
        title, wt = d.get("title", ""), d.get("wikitext", "")
        n_art += 1
        url = "https://en.wikivoyage.org/wiki/" + title.replace(" ", "_")
        rows += parse_article(title, wt, url)
        fares += parse_fares(title, wt, url)
    json.dump(rows, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
    json.dump(fares, open(OUT_T, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
    from collections import Counter
    print(f"local-ride fares={len(fares)} {dict(Counter(f['mode'] for f in fares))} -> {OUT_T}")
    print(f"articles={n_art} observations={len(rows)} -> {OUT}")
    print("by type:", dict(Counter(r["type"] for r in rows)))
    print("tier-labelled:", dict(Counter((r["type"], r["tier"]) for r in rows if r["tier"])))
    print("sleep basis:", dict(Counter(r["basis"] for r in rows if r["type"] == "sleep")))


if __name__ == "__main__":
    main()
