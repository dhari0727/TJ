"""
JourneyAI — Wikivoyage India crawler (Phase 1 + 2 of docs/INDIA_ROADMAP.md).

Crawls the Wikivoyage "India" region tree (states -> regions -> cities/other destinations) via
the public MediaWiki API, then extracts from each destination article:
  - hierarchy, coordinates, Wikidata id
  - structured listings ({{see}}, {{do}}, {{eat}}, {{drink}}, {{sleep}}, {{buy}}) including prices
  - Wikivoyage text is CC BY-SA; we keep source title/url on every record for attribution.

Raw wikitext is cached under ml/data/raw/wikivoyage/ so re-runs are offline and polite.

Usage:
    ml/venv/Scripts/python.exe -m ml.scrape.wikivoyage --crawl        # build destination graph
    ml/venv/Scripts/python.exe -m ml.scrape.wikivoyage --listings     # extract listings
"""
import argparse
import hashlib
import json
import os
import re
import sys
import time

import requests

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DATA = os.path.join(ROOT, "ml", "data")
RAW = os.path.join(DATA, "raw", "wikivoyage")
API = "https://en.wikivoyage.org/w/api.php"
UA = "JourneyAI-research/1.0 (student travel-recommender project; polite crawler)"
SLEEP = 0.4

_session = requests.Session()
_session.headers["User-Agent"] = UA


def _cache_path(title):
    h = hashlib.md5(title.encode("utf8")).hexdigest()[:10]
    safe = re.sub(r"[^A-Za-z0-9_-]", "_", title)[:60]
    return os.path.join(RAW, f"{safe}_{h}.json")


def fetch_wikitext(titles):
    """Return {title: wikitext} for titles (batched, cached). Missing pages map to ''."""
    os.makedirs(RAW, exist_ok=True)
    out, todo = {}, []
    for t in titles:
        p = _cache_path(t)
        if os.path.exists(p):
            with open(p, encoding="utf8") as f:
                out[t] = json.load(f)["wikitext"]
        else:
            todo.append(t)
    for i in range(0, len(todo), 40):
        batch = todo[i:i + 40]
        for attempt in range(4):
            try:
                r = _session.get(API, params={
                    "action": "query", "prop": "revisions", "rvprop": "content", "rvslots": "main",
                    "titles": "|".join(batch), "redirects": 1, "format": "json", "formatversion": 2,
                }, timeout=40)
                r.raise_for_status()
                data = r.json()["query"]
                break
            except Exception as e:  # noqa: BLE001
                time.sleep(2 * (attempt + 1))
                data = None
        if data is None:
            continue
        # map normalized/redirected titles back to what we asked for
        back = {}
        for m in data.get("normalized", []) + data.get("redirects", []):
            back[m["to"]] = back.get(m["from"], m["from"])
        for pg in data.get("pages", []):
            asked = back.get(pg["title"], pg["title"])
            asked = back.get(asked, asked)
            wt = ""
            if not pg.get("missing"):
                wt = pg["revisions"][0]["slots"]["main"]["content"]
            for name in {asked, pg["title"]} & set(batch):
                with open(_cache_path(name), "w", encoding="utf8") as f:
                    json.dump({"title": pg["title"], "wikitext": wt}, f)
                out[name] = wt
        for t in batch:  # anything unresolved -> empty, cached so we don't retry forever
            if t not in out:
                with open(_cache_path(t), "w", encoding="utf8") as f:
                    json.dump({"title": t, "wikitext": ""}, f)
                out[t] = ""
        time.sleep(SLEEP)
    return out


# ---------------------------------------------------------------------------
# wikitext parsing
# ---------------------------------------------------------------------------
def _find_templates(text, names):
    """Yield (name, inner) for top-level {{name|...}} templates, handling nested braces."""
    pat = re.compile(r"\{\{\s*(" + "|".join(names) + r")\s*\|", re.I)
    pos = 0
    while True:
        m = pat.search(text, pos)
        if not m:
            return
        depth, i = 2, m.end()
        while i < len(text) and depth > 0:
            if text.startswith("{{", i):
                depth += 2
                i += 2
            elif text.startswith("}}", i):
                depth -= 2
                i += 2
            else:
                i += 1
        yield m.group(1).lower(), text[m.end():i - 2]
        pos = i


def _split_params(inner):
    """Split a template body on '|' that are not inside [[..]] or {{..}}."""
    parts, buf, d_sq, d_br = [], [], 0, 0
    i = 0
    while i < len(inner):
        two = inner[i:i + 2]
        if two == "[[":
            d_sq += 1; buf.append(two); i += 2; continue
        if two == "]]":
            d_sq -= 1; buf.append(two); i += 2; continue
        if two == "{{":
            d_br += 1; buf.append(two); i += 2; continue
        if two == "}}":
            d_br -= 1; buf.append(two); i += 2; continue
        if inner[i] == "|" and d_sq <= 0 and d_br <= 0:
            parts.append("".join(buf)); buf = []
        else:
            buf.append(inner[i])
        i += 1
    parts.append("".join(buf))
    params = {}
    for p in parts:
        if "=" in p:
            k, v = p.split("=", 1)
            params[k.strip().lower()] = v.strip()
    return params


_LINK = re.compile(r"\[\[(?:[^\]|]*\|)?([^\]]*)\]\]")


def clean(s):
    s = re.sub(r"\{\{[^{}]*\}\}", "", s or "")
    s = _LINK.sub(r"\1", s)
    s = re.sub(r"\[\[File:[^\]]*\]\]", "", s)
    s = re.sub(r"'''?", "", s)
    s = re.sub(r"<[^>]+>", "", s)
    return re.sub(r"\s+", " ", s).strip()


def link_target(s):
    m = re.search(r"\[\[([^\]|#]+)", s or "")
    return m.group(1).strip() if m else clean(s)


def _num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def parse_children(wikitext):
    """Child destinations: markers (cities/other destinations) + regionlist regions."""
    kids = []
    for _, inner in _find_templates(wikitext, ["marker"]):
        p = _split_params(inner)
        title = link_target(p.get("name", ""))
        typ = p.get("type", "").lower()
        if not title or typ not in {"city", "other", "region", "go", "see", "do", "vicinity"}:
            continue
        if typ in {"go", "see", "do", "vicinity"}:  # listings-ish markers, not destinations
            continue
        kids.append({"title": title, "kind": typ, "lat": _num(p.get("lat")), "lon": _num(p.get("long")),
                     "wikidata": p.get("wikidata", ""), "desc": clean(p.get("description", ""))})
    for _, inner in _find_templates(wikitext, ["regionlist"]):
        p = _split_params(inner)
        for k, v in p.items():
            m = re.fullmatch(r"region(\d+)name", k)
            if m:
                title = link_target(p.get(f"region{m.group(1)}items", "")) or link_target(v)
                # region name is usually the article title itself
                title = link_target(v) or title
                kids.append({"title": link_target(v), "kind": "region", "lat": None, "lon": None,
                             "wikidata": "", "desc": clean(p.get(f"region{m.group(1)}description", ""))})
    # plain bullet links inside "Cities"/"Other destinations" sections (older style pages)
    return kids


def parse_banner_coords(wikitext):
    m = re.search(r"\{\{\s*geo\s*\|\s*([\d.\-]+)\s*\|\s*([\d.\-]+)", wikitext, re.I)
    return (float(m.group(1)), float(m.group(2))) if m else (None, None)


def parse_listings(wikitext):
    kinds = ["see", "do", "eat", "drink", "sleep", "buy", "listing"]
    rows = []
    for name, inner in _find_templates(wikitext, kinds):
        p = _split_params(inner)
        nm = clean(p.get("name", ""))
        if not nm:
            continue
        rows.append({
            "type": p.get("type", name).lower() if name == "listing" else name,
            "name": nm,
            "alt": clean(p.get("alt", "")),
            "lat": _num(p.get("lat")), "lon": _num(p.get("long")),
            "price": clean(p.get("price", "")),
            "hours": clean(p.get("hours", "")),
            "address": clean(p.get("address", "")),
            "url": p.get("url", ""),
            "wikidata": p.get("wikidata", ""),
            "description": clean(p.get("content", "") or p.get("description", "")),
        })
    return rows


def parse_sections(wikitext):
    """Split article into {heading: text} (level-2 headings), cleaned."""
    parts = re.split(r"^==\s*([^=].*?)\s*==\s*$", wikitext, flags=re.M)
    secs = {}
    for i in range(1, len(parts) - 1, 2):
        secs[parts[i].strip()] = parts[i + 1]
    return secs


# ---------------------------------------------------------------------------
# crawl
# ---------------------------------------------------------------------------
def crawl(root="India", max_nodes=6000):
    nodes = {root: {"title": root, "parent": None, "kind": "country", "depth": 0, "lat": None,
                    "lon": None, "wikidata": "", "desc": ""}}
    frontier = [root]
    while frontier and len(nodes) < max_nodes:
        texts = fetch_wikitext(frontier)
        nxt = []
        for t in frontier:
            wt = texts.get(t, "")
            node = nodes[t]
            if node["lat"] is None:
                node["lat"], node["lon"] = parse_banner_coords(wt)
            for k in parse_children(wt):
                ct = k["title"]
                if ct in nodes or ct == t:
                    continue
                nodes[ct] = {**k, "parent": t, "depth": node["depth"] + 1}
                nxt.append(ct)
        print(f"depth done: frontier={len(frontier)} new={len(nxt)} total={len(nodes)}", flush=True)
        frontier = nxt
    return nodes


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--crawl", action="store_true")
    ap.add_argument("--listings", action="store_true")
    ap.add_argument("--root", default="India")
    a = ap.parse_args()
    os.makedirs(DATA, exist_ok=True)
    gpath = os.path.join(DATA, "india_destinations.json")
    if a.crawl:
        nodes = crawl(a.root)
        with open(gpath, "w", encoding="utf8") as f:
            json.dump(list(nodes.values()), f, ensure_ascii=False, indent=1)
        print("wrote", gpath, len(nodes))
    if a.listings:
        nodes = json.load(open(gpath, encoding="utf8"))
        texts = fetch_wikitext([n["title"] for n in nodes])
        rows = []
        for n in nodes:
            for r in parse_listings(texts.get(n["title"], "")):
                r["destination"] = n["title"]
                r["source"] = "wikivoyage"
                r["source_url"] = "https://en.wikivoyage.org/wiki/" + n["title"].replace(" ", "_")
                rows.append(r)
        lp = os.path.join(DATA, "india_listings.json")
        with open(lp, "w", encoding="utf8") as f:
            json.dump(rows, f, ensure_ascii=False)
        print("wrote", lp, len(rows))


if __name__ == "__main__":
    sys.path.insert(0, ROOT)
    main()
