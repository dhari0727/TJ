"""
JourneyAI — coordinates for every destination, so trips can be ranked by distance from where the user starts.

Scraped destinations already carry lat/lon (Wikivoyage). Hand-curated ones are geocoded once through
Nominatim (politely, ~1 request/second, cached under ml/artifacts/geocache). Output: ml/data/dest_coords.json
    {"Goa, India": [15.49, 73.83], ...}

    ml/venv/Scripts/python.exe -m ml.geo.build_dest_coords
"""
import json
import os
import sys
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, ROOT)
from ml.geo.places import geocode  # noqa: E402
from ml.seed.destination_catalog import get_destinations  # noqa: E402

DATA = os.path.join(ROOT, "ml", "data")


def main():
    scraped = {}
    p = os.path.join(DATA, "india_catalog.json")
    if os.path.exists(p):
        for d in json.load(open(p, encoding="utf8")):
            if d.get("lat") is not None and d.get("lon") is not None:
                scraped[d["name"]] = [d["lat"], d["lon"]]
    out_path = os.path.join(DATA, "dest_coords.json")
    out = json.load(open(out_path, encoding="utf8")) if os.path.exists(out_path) else {}
    todo = [d for d in get_destinations() if d["name"] not in out]
    print(f"{len(out)} already known, {len(todo)} to resolve")
    miss = []
    for i, d in enumerate(todo, 1):
        if d["name"] in scraped:
            out[d["name"]] = scraped[d["name"]]
            continue
        g = geocode(f"{d['city']}, {d['country']}")
        if g:
            out[d["name"]] = [round(g[0], 5), round(g[1], 5)]
        else:
            miss.append(d["name"])
        time.sleep(1.1)          # Nominatim usage policy
        if i % 20 == 0:
            json.dump(out, open(out_path, "w", encoding="utf8"), ensure_ascii=False)
            print(f"  {i}/{len(todo)}", flush=True)
    json.dump(out, open(out_path, "w", encoding="utf8"), ensure_ascii=False)
    print(f"done: {len(out)} destinations with coordinates; unresolved: {miss}")


if __name__ == "__main__":
    main()
