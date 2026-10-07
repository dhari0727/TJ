"""
JourneyAI — India-wide "local highlights" (same schema as the hand-curated Gujarat ml/geo/local_highlights.py).

Gujarat was hand-researched town-by-town with web search. For the rest of India we derive the same
structure from real Wikivoyage See/Do/Eat listings (CC BY-SA; source URL kept per town):
    {key: {character, landmarks:[{name,category,note,lat,lon}], food:[{dish,where,note}], source_url}}
Written to ml/data/india_highlights.json; ml/geo/local_highlights.py falls back to it for any town
not in its curated TOWNS (curated always wins).

    ml/venv/Scripts/python.exe -m ml.scrape.build_highlights
"""
import json
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DATA = os.path.join(ROOT, "ml", "data")

CATS = [
    ("religious", r"temple|mandir|mosque|masjid|church|gurdwara|gurudwara|dargah|shrine|monastery|gompa|cathedral|ashram|math\b|basilica|synagogue|jain|pilgrim|ghat"),
    ("museum", r"museum|gallery|planetarium|science centre|science center"),
    ("beach", r"beach|shore|coast"),
    ("wildlife", r"national park|sanctuary|wildlife|zoo|tiger|safari|reserve|bird"),
    ("garden", r"garden|park\b|botanical|gardens"),
    ("shopping", r"market|bazaar|bazar|mall|emporium|haat|shopping|chowk"),
    ("heritage", r"fort|palace|tomb|monument|haveli|gate|qila|mahal|stepwell|baoli|cave|ruins|memorial|minar|pol\b|heritage|bridge|dam|lighthouse|cantonment"),
    ("nature", r"lake|waterfall|falls|hill|peak|valley|river|island|view ?point|sunset|sunrise|canyon|glacier|pass\b|forest|backwater|cascade|point\b|trek"),
]
GENERIC = re.compile(r"^(bus|railway|train|airport|taxi|auto|hospital|atm|bank|police|post office|tourist (office|information)|hotel|lodge|guest ?house|hostel|resort)\b", re.I)
SPECIALTY = re.compile(r"(?:famous for|known for|specialit(?:y|ies)(?: is| are| include)?|try (?:the )?|serves|must[- ]try|don't miss|best (?:known )?for)\s+(?:the |its |their )?([A-Za-z][A-Za-z' \-]{3,45}?)(?:[.,;:)]| and | with | at | in | from |$)", re.I)


def classify(name, desc):
    """Name wins (a 'Fort' is heritage even if its blurb mentions a garden); description is the fallback."""
    for text in (name.lower(), desc.lower()[:140]):
        for cat, pat in CATS:
            if re.search(pat, text):
                return cat
    return "attraction"


def first_sentence(s, n=170):
    s = re.sub(r"\s+", " ", s or "").strip()
    for m in re.finditer(r"[.!?](\s|$)", s):
        head = s[: m.end()].rstrip()
        last = re.findall(r"([A-Za-z]+)[.!?]$", head)
        if len(head) >= 25 and not (last and len(last[0]) <= 2):  # skip 'c.', 'St.', 'Mt.' abbreviations
            s = head
            break
    return s if len(s) <= n else s[: n - 1].rstrip() + "…"


def main():
    cat = json.load(open(os.path.join(DATA, "india_catalog.json"), encoding="utf8"))
    listings = json.load(open(os.path.join(DATA, "india_listings.json"), encoding="utf8"))
    by = {}
    for l in listings:
        by.setdefault(l["destination"], []).append(l)
    towns, n_land, n_food = {}, 0, 0
    for d in cat:
        t = d["city"]
        ls = by.get(t, [])
        cand = []
        for l in ls:
            if l["type"] not in ("see", "do") or GENERIC.match(l["name"]) or len(l["name"]) > 70:
                continue
            desc = l["description"]
            if len(desc) < 25 and not l["wikidata"]:
                continue
            score = (3 if l["wikidata"] else 0) + (2 if len(desc) >= 60 else 0) + (1 if l["lat"] else 0) + (1 if l["type"] == "see" else 0)
            cand.append((score, l))
        cand.sort(key=lambda x: -x[0])
        lms, seen = [], set()
        for _, l in cand:
            k = l["name"].lower()
            if k in seen:
                continue
            seen.add(k)
            lm = {"name": l["name"], "category": classify(l["name"], l["description"]),
                  "note": first_sentence(l["description"]) or f"Listed sight in {t}."}
            if l["lat"] is not None and l["lon"] is not None:
                lm["lat"], lm["lon"] = l["lat"], l["lon"]
            lms.append(lm)
            if len(lms) >= 8:
                break
        foods, seenf = [], set()
        for l in ls:
            if l["type"] != "eat" or GENERIC.match(l["name"]) or len(l["description"]) < 30:
                continue
            m = SPECIALTY.search(l["description"])
            dish = (m.group(1).strip().rstrip(".,") if m else l["name"])
            if dish.lower() in seenf:
                continue
            seenf.add(dish.lower())
            foods.append({"dish": dish[:60], "where": l["name"] if m else (l["address"] or f"around {t}")[:80],
                          "note": first_sentence(l["description"], 150)})
            if len(foods) >= 5:
                break
        if len(lms) < 2:
            continue
        towns[t.lower()] = {"character": first_sentence(d.get("description", ""), 220) or f"{t}, {d['region']}.",
                            "landmarks": lms, "food": foods, "region": d["region"],
                            "source_url": d["source_url"], "source": "wikivoyage"}
        n_land += len(lms)
        n_food += len(foods)
    json.dump(towns, open(os.path.join(DATA, "india_highlights.json"), "w", encoding="utf8"), ensure_ascii=False)
    print(f"towns: {len(towns)}  landmarks: {n_land}  food: {n_food}")


if __name__ == "__main__":
    sys.path.insert(0, ROOT)
    main()
