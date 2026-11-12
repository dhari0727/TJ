"""
JourneyAI "Where to stay" tests (ML /stays endpoint, stays-api.php proxy, trip page section).

  ml/venv/Scripts/python.exe tests/stays_test.py          # ML on 127.0.0.1:5000, site on http://localhost/travel_journel
  ML_BASE=... BASE_URL=... python tests/stays_test.py

Needs Apache + MySQL + the ML service. Real map data is fetched on first use (can take a minute per destination); if
the public map servers are unreachable the data-dependent checks are reported as SKIP, not as failures.
Exit code 0 = no failures.
"""
import math
import os
import re
import sys

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
ML = os.environ.get("ML_BASE", "http://127.0.0.1:5000").rstrip("/")
results = []


def check(name, cond, detail=""):
    results.append(bool(cond))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))


def skip(name, why):
    print(f"  SKIP  {name}   [{why}]")


def _km(a, b):
    p = math.pi / 180
    x = math.sin((b["lat"] - a["lat"]) * p / 2) ** 2 + math.cos(a["lat"] * p) * math.cos(b["lat"] * p) * math.sin((b["lon"] - a["lon"]) * p / 2) ** 2
    return 12742 * math.asin(math.sqrt(x))


def stays(**kw):
    body = {"destination": "Jaipur, India", "days": 3, "interests": ["history"], "travel_style": "mid-range", "party_size": 2}
    body.update(kw)
    r = requests.post(f"{ML}/stays", json=body, timeout=170)
    try:
        return r.status_code, r.json()
    except ValueError:
        return r.status_code, {}


def logic_tests():
    """Pure planning logic on synthetic coordinates (no network): clustering, base choice and night allocation."""
    sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
    from ml.stay.recommend import plan_bases
    print("== base planning logic (synthetic stops, no network)")

    def stops(*pts):
        return [{"name": f"S{i}", "lat": a, "lon": b, "fame": 1} for i, (a, b) in enumerate(pts)]
    near = stops((26.92, 75.82), (26.95, 75.85), (26.90, 75.80), (26.93, 75.78), (26.88, 75.83), (26.94, 75.81))
    # two groups about 110 km apart (Jaipur area and Pushkar area)
    two = stops((26.92, 75.82), (26.95, 75.85), (26.90, 75.80), (26.49, 74.55), (26.50, 74.58), (26.47, 74.52))
    c = (26.9, 75.8)
    for days, st, want_k, label in [(1, near, 1, "1 day"), (2, near, 1, "2 days"), (2, two, 1, "2 days, far groups: still one night one base"),
                                    (3, near, 1, "3 days, close stops"), (3, two, 2, "3 days, groups >40 km apart"), (4, near, 1, "4 days, close stops"),
                                    (7, two, 2, "7 days, groups >40 km apart"), (7, near, 1, "7 days, close stops"), (14, two, 2, "14 days, two groups")]:
        p = plan_bases(st, c, days, "Test")
        check(f"{label}: {want_k} base(s), nights sum to {days - 1}", p["k"] == want_k and sum(b["nights"] for b in p["bases"]) == days - 1,
              f"k={p['k']} nights={[b['nights'] for b in p['bases']]}")
        check(f"{label}: explanation given", bool(p["explain"]))
    p = plan_bases(two, c, 7, "Test")
    check("nights are split in proportion to stops (3+3 stops -> 3 and 3)", sorted(b["nights"] for b in p["bases"]) == [3, 3], [b["nights"] for b in p["bases"]])
    p = plan_bases(two[:4], c, 9, "Test")
    check("every base gets at least one night", all(b["nights"] >= 1 for b in p["bases"]))
    p = plan_bases([], c, 5, "Test")
    check("no stops at all -> one base at the centre, nights still sum", p["k"] == 1 and p["bases"][0]["nights"] == 4)
    p = plan_bases(near[:1], c, 30, "Test")
    check("single stop, 30 days -> one base with 29 nights", p["k"] == 1 and p["bases"][0]["nights"] == 29)


def main():
    logic_tests()
    print("== /stays by trip length (Jaipur)")
    runs = {}
    for days in (1, 2, 3, 7):
        code, d = stays(days=days)
        runs[days] = (code, d)
        if code == 503:
            skip(f"{days} day(s)", "map data upstream unavailable")
            continue
        check(f"{days}d -> 200 ok", code == 200 and d.get("status") == "ok", f"{code} {d.get('error')}")
    live = {k: v[1] for k, v in runs.items() if v[0] == 200 and v[1].get("status") == "ok" and not v[1].get("empty")}

    for days, d in live.items():
        plan = d["plan"]
        check(f"{days}d: nights == days-1", d["nights"] == days - 1 and plan["nights"] == days - 1)
        check(f"{days}d: base nights sum to {days - 1}", sum(b["nights"] for b in plan["bases"]) == days - 1,
              [b["nights"] for b in plan["bases"]])
        check(f"{days}d: every base has a night when nights>0", days == 1 or all(b["nights"] >= 1 for b in plan["bases"]))
        check(f"{days}d: headline + explanation present", bool(plan["headline"]) and bool(plan["explain"]))
        check(f"{days}d: stays have nights of their base", all(s["nights"] == plan["bases"][s["base"]]["nights"] for s in d["stays"]))
        if days == 1:
            check("1d: says no stay needed and marks options optional", "no stay needed" in plan["headline"].lower() and d.get("optional") is True)
            check("1d: at most 3 'stay over' options", len(d["stays"]) <= 3)
        if days == 2:
            check("2d: exactly one base, one night", len(plan["bases"]) == 1 and plan["bases"][0]["nights"] == 1)
        if days < 4:
            bs = plan["bases"]
            far = len(bs) == 1 or all(_km(a, b) > 40 for i, a in enumerate(bs) for b in bs[i + 1:])
            check(f"{days}d: single base unless bases are >40 km apart", far)

    allstays = [s for d in live.values() for s in d["stays"]]
    if allstays:
        print("== data honesty")
        check("every stay has name, type, tier label, coordinates, map link",
              all(s["name"] and s["type_label"] and s["tier_label"] and isinstance(s["lat"], float) and s["map_url"].startswith("https://") for s in allstays))
        check("distances are finite and non-negative", all(math.isfinite(s["distance_km"]) and s["distance_km"] >= 0 for s in allstays))
        check("scores are finite", all(math.isfinite(s["score"]) for s in allstays))
        imgs = [s["image"] for s in allstays if s["image"]]
        check("every image has a real Wikimedia source, credit page and license",
              all(i["src"].startswith("https://commons.wikimedia.org/wiki/Special:FilePath/") and i["credit_page"].startswith("https://commons.wikimedia.org/wiki/File:")
                  and i["license"] for i in imgs), f"{len(imgs)} images")
        check("images that exist resolve to a real picture",
              all(requests.get(i["src"], timeout=40, stream=True, headers={"User-Agent": "TravelJournalImageBot/1.0 (educational project; low-rate)"}).headers.get("content-type", "").startswith("image/")
                  for i in imgs[:3]) if imgs else True)
        prices = [s["price"] for s in allstays if s["price"]]
        check("every price has a source name and a source URL, and is a positive number",
              all(p["source"] and p["url"].startswith("https://") and p["inr"] > 0 for p in prices), f"{len(prices)} prices")
        check("no stay is a duplicate within a response", all(len({s['id'] for s in d['stays']}) == len(d['stays']) for d in live.values()))
        check("score parts are documented fields", all(set(s["score_parts"]) == {"prox", "type", "style", "fame", "complete", "photo"} for s in allstays))

    print("== ranking responds to style and trip type")
    c1, b = stays(days=3, travel_style="budget", interests=[])
    c2, l = stays(days=3, travel_style="luxury", interests=[])
    if c1 == 200 and c2 == 200 and b.get("stays") and l.get("stays"):
        nb, nl = [s["name"] for s in b["stays"]], [s["name"] for s in l["stays"]]
        check("budget and luxury rank different places", nb != nl, f"{nb[:3]} vs {nl[:3]}")
        tb = [s["tier"] for s in b["stays"] if s["tier"]]
        tl = [s["tier"] for s in l["stays"] if s["tier"]]
        check("budget list is cheaper-tiered than luxury list", (sum(tb) / len(tb) if tb else 0) <= (sum(tl) / len(tl) if tl else 9),
              f"{tb} vs {tl}")
    else:
        skip("style ranking", "map data upstream unavailable")

    print("== errors and fuzz")
    code, d = stays(destination="Zzqxvv Nowherevillex 9981")
    check("unknown destination -> clean error", code in (404, 503) and d.get("status") == "error" and isinstance(d.get("error"), str), f"{code} {d}")
    code, d = stays(destination="")
    check("missing destination -> 400", code == 400 and d.get("status") == "error")
    fuzz = [
        {"destination": "Jaipur, India", "days": "abc"}, {"destination": "Jaipur, India", "days": -5},
        {"destination": "Jaipur, India", "days": 1e99}, {"destination": "Jaipur, India", "interests": {"a": 1}},
        {"destination": "Jaipur, India", "interests": [1, None, ["x"], "<script>"]}, {"destination": "Jaipur, India", "stops": "nope"},
        {"destination": "Jaipur, India", "stops": [{"lat": "x"}, {"lat": 1e9, "lon": 5}, 5, None]},
        {"destination": "J" * 5000}, {"destination": "Jaipur", "party_size": [1]}, {"lat": "x", "lon": 5}, {"lat": 95, "lon": 5},
        {"destination": "Jaipur, India", "travel_style": {"x": 1}}, {"destination": "Jaipur, India", "top_n": "many"},
    ]
    bad = []
    for f in fuzz:
        try:
            r = requests.post(f"{ML}/stays", json=f, timeout=170)
            r.json()
            if r.status_code >= 500 and r.status_code != 503:
                bad.append((f, r.status_code))
        except Exception as e:
            bad.append((f, repr(e)))
    check("fuzzed bodies never crash the service", not bad, bad[:3])
    for raw in ("[1,2]", "null", "not json", ""):
        r = requests.post(f"{ML}/stays", data=raw, headers={"Content-Type": "application/json"}, timeout=30)
        check(f"non-object body {raw!r:8} -> 400", r.status_code == 400, r.status_code)

    print("== PHP proxy and trip page")
    r = requests.get(f"{BASE}/stays-api.php", timeout=30)
    check("stays-api.php without dest -> 400 JSON", r.status_code == 400 and r.json().get("status") == "error")
    r = requests.get(f"{BASE}/stays-api.php", params={"dest": "Jaipur, India", "days": "999", "style": "<b>", "interests": "history,<script>,food", "html": 1}, timeout=170)
    j = r.json()
    check("stays-api.php html=1 returns JSON with rendered html", r.status_code in (200, 502, 503) and "html" in j, r.status_code)
    if j.get("status") == "ok":
        check("proxy clamps days to 30", j.get("days") == 30, j.get("days"))
        check("no raw script tag can reach the rendered html", "<script" not in j["html"].lower())
        check("rendered html has the trip-length header", "st-plan" in j["html"])
        check("every external link opens safely", all("noopener" in a for a in re.findall(r"<a [^>]*target=\"_blank\"[^>]*>", j["html"])))
    r = requests.get(f"{BASE}/trip.php", params={"dest": "Jaipur, India", "days": 2}, timeout=170)
    check("trip.php renders the Where to stay section", r.status_code == 200 and 'id="stays"' in r.text and 'data-days="2"' in r.text and "Where to stay" in r.text, r.status_code)
    check("trip.php stay section loads its script and styles", "js/stays.js" in r.text and "css/pages-stays.css" in r.text)
    r = requests.get(f"{BASE}/trip.php", params={"dest": "Jaipur, India", "days": 2, "interests": 'beach"><script>x'}, timeout=170)
    check("trip.php escapes hostile interests", "<script>x" not in r.text)

    failed = results.count(False)
    print(f"\n{len(results) - failed}/{len(results)} passed")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
