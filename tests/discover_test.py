"""
JourneyAI — checks for the discover pages (destination guide, compare, cost helper, ML /destination).

  ml/venv/Scripts/python.exe tests/discover_test.py             # http://localhost/travel_journel
  BASE_URL=... python tests/discover_test.py

Read-only: it only GETs public pages and POSTs read-only queries to the ML service.
"""
import os
import re
import sys

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
ML = os.environ.get("ML_URL", "http://127.0.0.1:5000")
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))
    return bool(cond)


def main():
    s = requests.Session()
    print("\n== ML /destination")
    r = requests.post(f"{ML}/destination", json={"destinations": ["Jaipur", "Manali"]}, timeout=30)
    d = r.json() if r.ok else {}
    items = d.get("destinations", [])
    check("destination: two real profiles come back", r.ok and len(items) == 2, r.text[:120])
    jp = items[0] if items else {}
    check("destination: Jaipur has months, activities and a costed 5-day trip",
          jp.get("season_peak") and jp.get("activities") and jp.get("costs", {}).get("5", {}).get("total", 0) > 1000, str(jp)[:120])
    check("destination: results stay aligned with the request (unknown -> null)",
          requests.post(f"{ML}/destination", json={"destinations": ["Atlantis", "Manali"]}, timeout=30).json().get("results", [1])[0] is None)
    check("destination: unknown place is a clean 404", requests.post(f"{ML}/destination", json={"destination": "Atlantis"}, timeout=30).status_code == 404)
    check("destination: empty request is a 400", requests.post(f"{ML}/destination", json={}, timeout=30).status_code == 400)
    lst = requests.post(f"{ML}/destination", json={"list": True}, timeout=30).json().get("destinations", [])
    check("destination: list mode returns hundreds of real places", len(lst) > 100, len(lst))

    print("\n== destination guide")
    r = s.get(f"{BASE}/destination.php?d=Jaipur", timeout=60)
    t = r.text
    check("destination.php?d=Jaipur -> 200", r.status_code == 200, r.status_code)
    check("guide: names the place and has a best-months chart", "<h1>Jaipur</h1>" in t and "dz-months" in t and "Best months:" in t)
    check("guide: shows a cost per day and a trip-length table", "Typical cost per day" in t and "Likely total" in t)
    check("guide: lists attractions or landmarks", "Amber Fort" in t)
    check("guide: has a Plan a trip button and a compare toggle", "Plan a trip here" in t and "data-compare=" in t)
    check("guide: shows places like this", "Places like Jaipur" in t)
    check("guide: photo is credited when shown", "dz-gphoto" not in t or "Photo from" in t)
    check("guide: lookup is forgiving (jaipur, lowercase)", s.get(f"{BASE}/destination.php?d=jaipur", timeout=60).status_code == 200)
    check("guide: no raw wiki markup leaks into the page", "thumb|" not in t and "&nbsp;" not in t)
    r = s.get(f"{BASE}/destination.php?d=Atlantis", timeout=60)
    check("unknown destination -> 404 with a way forward", r.status_code == 404 and "no guide for" in r.text and 'name="d"' in r.text, r.status_code)
    check("unknown destination: input is escaped", "<script>alert(1)" not in s.get(f"{BASE}/destination.php?d=%3Cscript%3Ealert(1)%3C/script%3E", timeout=60).text)
    r = s.get(f"{BASE}/destination.php", timeout=60)
    check("destination.php with no name -> helpful page, not a crash", r.status_code == 404 and "Pick a destination" in r.text, r.status_code)

    print("\n== compare")
    r = s.get(f"{BASE}/compare.php", params=[("d[]", "Jaipur"), ("d[]", "Manali"), ("d[]", "Udaipur")], timeout=60)
    t = r.text
    check("compare.php with three places -> 200", r.status_code == 200, r.status_code)
    check("compare: all three columns and the cost row", all(x in t for x in ("Jaipur", "Manali", "Udaipur")) and "Cost per day" in t and "Best months" in t)
    check("compare: three month charts", t.count("dz-months-sm") >= 3, t.count("dz-months-sm"))
    r = s.get(f"{BASE}/compare.php?d[]=Jaipur&d[]=Atlantis", timeout=60)
    check("compare: an unknown place is called out, not silently dropped", "No data for Atlantis" in r.text)
    check("compare: more than three places is capped", s.get(f"{BASE}/compare.php?d[]=Jaipur&d[]=Manali&d[]=Udaipur&d[]=Pune", timeout=60).text.count('<th scope="col">') == 3)
    r = s.get(f"{BASE}/compare.php", timeout=60)
    check("compare.php with nothing chosen -> prompt to choose", r.status_code == 200 and "Nothing to compare yet" in r.text)

    print("\n== live cost helper endpoint")
    r = s.get(f"{BASE}/cost-estimate.php", params={"days": 4, "party": 2, "month": 11}, timeout=60)
    j = r.json()
    check("cost-estimate: no place -> three reference places, cheapest to priciest",
          r.ok and j.get("mode") == "range" and len(j["items"]) == 3 and j["items"][0]["total"] < j["items"][2]["total"], r.text[:150])
    r = s.get(f"{BASE}/cost-estimate.php", params={"days": 3, "destination": "Jaipur"}, timeout=60)
    j = r.json()
    check("cost-estimate: one place -> low <= total <= high", r.ok and j["items"][0]["low"] <= j["items"][0]["total"] <= j["items"][0]["high"], r.text[:150])
    a = s.get(f"{BASE}/cost-estimate.php", params={"days": 2, "destination": "Jaipur"}, timeout=60).json()["items"][0]["total"]
    b = s.get(f"{BASE}/cost-estimate.php", params={"days": 6, "destination": "Jaipur"}, timeout=60).json()["items"][0]["total"]
    check("cost-estimate: more days cost more", b > a, (a, b))
    r = s.get(f"{BASE}/cost-estimate.php", params={"destination": "Atlantis"}, timeout=60)
    check("cost-estimate: unknown place -> 404 with a plain message", r.status_code == 404 and "no data" in r.json().get("error", ""))
    r = s.get(f"{BASE}/cost-estimate.php", params={"days": "999", "party": "-5", "style": "x"}, timeout=60)
    check("cost-estimate: wild inputs are clamped, not errors", r.ok and r.json()["days"] == 30 and r.json()["party"] == 1)

    print("\n== plan page")
    r = s.get(f"{BASE}/plan-trip.php", timeout=60)
    check("plan-trip.php has the live cost panel and days field", r.status_code == 200 and 'id="estOut"' in r.text and 'name="days"' in r.text)

    bad = [n for n, ok, _ in results if not ok]
    print(f"\n{len(results) - len(bad)}/{len(results)} passed")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
