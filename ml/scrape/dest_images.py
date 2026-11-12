"""
Collect a real Wikipedia lead photo URL for every destination (links only, nothing is downloaded).

    ml/venv/Scripts/python.exe -m ml.scrape.dest_images

Writes ml/data/dest_images.json: {"<canonical_name>": {"src": "<upload.wikimedia.org url>", "page": "<wikipedia url>"}}.
Places with no unambiguous article photo are simply left out; the site then shows a plain gradient rather than a photo of
somewhere else. Photos are hotlinked and credited to Wikipedia (CC-licensed).
"""
import ml.netfix  # noqa: F401  (IPv4)
import json, os, re, sys, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor
from ml.db import fetch_df

OUT = os.path.join(os.path.dirname(__file__), "..", "data", "dest_images.json")
UA = {"User-Agent": "TravelJournalImageBot/1.0 (educational project; low-rate)"}


def summary(title):
    url = "https://en.wikipedia.org/api/rest_v1/page/summary/" + urllib.parse.quote(title.replace(" ", "_"), safe="")
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=15) as r:
            return json.load(r)
    except Exception:
        return None


def lookup(name):
    city = name.split(",")[0].strip()
    for t in ([name.replace(", India", ", India")] if "," in name else []) + [city + ", India", city]:
        d = summary(t)
        if not d or d.get("type") == "disambiguation":
            continue
        th = d.get("thumbnail") or {}
        src = th.get("source")
        if not src:
            continue
        src = re.sub(r"/(\d+)px-", "/960px-", src)  # larger rendition of the same file
        return {"src": src, "page": d.get("content_urls", {}).get("desktop", {}).get("page", "")}
    return None


# ---- second pass: raise coverage for places the exact-title lookup missed (--extend) ----
import time, unicodedata
API = "https://en.wikipedia.org/w/api.php"
WD = "https://www.wikidata.org/w/api.php"
CM = "https://commons.wikimedia.org/w/api.php"
INDIA = re.compile(r"\bIndia(n)?\b|Gujarat|Maharashtra|Karnataka|Kerala|Tamil Nadu|Rajasthan|Uttar Pradesh|Madhya Pradesh|"
                   r"Himachal|Uttarakhand|Punjab|Haryana|Bihar|Jharkhand|West Bengal|Odisha|Assam|Andhra|Telangana|Goa|"
                   r"Chhattisgarh|Sikkim|Meghalaya|Tripura|Manipur|Nagaland|Mizoram|Arunachal|Kashmir|Ladakh|Delhi", re.I)


def _norm(s):
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9 ]+", " ", s).split()


def _get(base, **params):
    time.sleep(0.25)
    url = base + "?" + urllib.parse.urlencode(dict(params, format="json"))
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=20) as r:
            return json.load(r)
    except Exception:
        return None


def _core(name):
    city = name.split(",")[0].strip()
    return re.sub(r"\s*\(.*?\)", "", city).strip()


def _name_ok(core, text):
    """Every word of the place name appears in the title text (so 'Dwarka' never matches 'Dwarka Mor' by accident)."""
    want, have = _norm(core), set(_norm(text))
    return bool(want) and all(w in have for w in want)


def _wiki_try(core, title):
    d = summary(title)
    if not d or d.get("type") == "disambiguation":
        return None
    src = (d.get("thumbnail") or {}).get("source", "")
    if not src or "/wikipedia/en/" in src:  # local (possibly non-free) upload, skip
        return None
    if not _name_ok(core, d.get("title", "")):
        return None
    if not INDIA.search((d.get("description") or "") + " " + (d.get("extract") or "")[:400]):
        return None
    src = re.sub(r"/(\d+)px-", "/960px-", src)
    return {"src": src, "page": d.get("content_urls", {}).get("desktop", {}).get("page", "")}


def _commons_url(fname):
    q = urllib.parse.quote(fname.replace(" ", "_"))
    return {"src": "https://commons.wikimedia.org/wiki/Special:FilePath/" + q + "?width=960",
            "page": "https://commons.wikimedia.org/wiki/File:" + q}


def lookup_more(name):
    core = _core(name)
    st = None
    if "(" in name.split(",")[0]:
        st = re.search(r"\((.*?)\)", name).group(1)
    # 1. alternative Wikipedia titles
    alts = [core + ", " + st if st else None, core + " (India)", core + " (city)", core + " (town)", core + " district"]
    for t in [a for a in alts if a]:
        r = _wiki_try(core, t)
        if r:
            return r
    # 2. Wikipedia search
    j = _get(API, action="query", list="search", srsearch=core + " India", srlimit=5)
    for hit in ((j or {}).get("query", {}).get("search", [])):
        r = _wiki_try(core, hit["title"])
        if r:
            return r
    # 3. Wikidata P18
    j = _get(WD, action="wbsearchentities", search=core, language="en", limit=7, type="item")
    for e in (j or {}).get("search", []):
        if not _name_ok(core, e.get("label", "")) or not INDIA.search(e.get("description", "")):
            continue
        c = _get(WD, action="wbgetclaims", entity=e["id"], property="P18")
        cl = (c or {}).get("claims", {}).get("P18") or []
        if cl:
            return _commons_url(cl[0]["mainsnak"]["datavalue"]["value"])
    # 4. Commons file search (file title must carry the place name; Commons holds only freely licensed files)
    j = _get(CM, action="query", list="search", srnamespace=6, srsearch=core + " India", srlimit=8)
    for hit in (j or {}).get("query", {}).get("search", []):
        t = hit["title"].split(":", 1)[1]
        if re.search(r"\.(jpe?g|png)$", t, re.I) and _name_ok(core, t):
            return _commons_url(t)
    return None


def extend():
    df = fetch_df("SELECT canonical_name FROM destinations")
    names = sorted(set(df["canonical_name"].tolist()))
    with open(OUT, encoding="utf-8") as f:
        out = json.load(f)
    todo = [n for n in names if n not in out and n.endswith(", India")]
    print("before:", len(out), "of", len(names), "| trying", len(todo))
    new = {}
    with ThreadPoolExecutor(max_workers=3) as ex:
        for n, r in zip(todo, ex.map(lookup_more, todo)):
            if r:
                new[n] = r
    out.update(new)
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=0)
    print("added:", len(new), "| after:", len(out), "of", len(names))
    for n, r in new.items():
        print(n, "->", r["page"])


def main():
    if "--extend" in sys.argv:
        return extend()
    df = fetch_df("SELECT canonical_name FROM destinations")
    names = sorted(set(df["canonical_name"].tolist()))
    print(len(names), "destinations")
    with ThreadPoolExecutor(max_workers=8) as ex:
        res = list(ex.map(lookup, names))
    out = {n: r for n, r in zip(names, res) if r}
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=0)
    print("with photo:", len(out), "| without:", len(names) - len(out))


if __name__ == "__main__":
    main()
