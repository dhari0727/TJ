"""
Collect a real Wikipedia lead photo URL for every destination (links only, nothing is downloaded).

    ml/venv/Scripts/python.exe -m ml.scrape.dest_images

Writes ml/data/dest_images.json: {"<canonical_name>": {"src": "<upload.wikimedia.org url>", "page": "<wikipedia url>"}}.
Places with no unambiguous article photo are simply left out; the site then shows a plain gradient rather than a photo of
somewhere else. Photos are hotlinked and credited to Wikipedia (CC-licensed).
"""
import json, os, re, sys, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor
from ml.db import fetch_df

OUT = os.path.join(os.path.dirname(__file__), "..", "data", "dest_images.json")
UA = {"User-Agent": "JourneyAI-student-project/1.0 (travel journal; contact: dharinithakkar4@gmail.com)"}


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


def main():
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
