"""
JourneyAI trips layer tests (trip page, print stylesheet, PWA files).

  ml/venv/Scripts/python.exe tests/trips_test.py        # http://localhost/travel_journel
Needs Apache + MySQL + the ML service up. Public pages only, no account is created.
"""
import json
import os
import re
import sys

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
results = []


def check(name, cond, detail=""):
    results.append(bool(cond))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))


def main():
    s = requests.Session()
    print("== trip page")
    r = s.get(f"{BASE}/trip.php?dest=Jaipur%2C+India&days=3", timeout=120)
    html = r.text
    check("trip.php -> 200", r.status_code == 200, r.status_code)
    check("itinerary days render as collapsible sections", html.count('<details class="tp-day"') >= 3)
    check("cost chart is an accessible SVG with a table fallback", 'role="img"' in html and 'class="tp-costtable"' in html)
    check("cost table total matches itinerary", re.search(r"Total</th><td></td><td>₹[\d,]+", html) is not None)
    check("print stylesheet linked", 'media="print"' in html and "journeyai-print.css" in html)
    check("manifest and theme colour linked from the trip page", 'rel="manifest"' in html and 'content="#123524"' in html)
    check("mobile action bar present (save, share, print, packing)", 'id="tpMobileBar"' in html and "data-tp-print" in html and "packing.php" in html)
    check("loading skeleton is emitted before content", html.find('id="tpLoading"') != -1 and html.find('id="tpLoading"') < html.find('class="tp-head"'))
    check("no browser alert() on the trip page", "alert(" not in html)

    r = s.get(f"{BASE}/trip.php?dest=Zzzzqx&days=3", timeout=60)
    check("unknown destination gives an error state, not a blank page", r.status_code == 200 and "tp-state" in r.text)

    print("== css")
    css = s.get(f"{BASE}/css/pages-trips.css", timeout=30)
    check("pages-trips.css served", css.status_code == 200 and "text/css" in css.headers.get("Content-Type", ""))
    check("@media print rules for one-column plan", "@media print" in css.text and ".tp-layout{display:block}" in css.text)
    check("reduced motion respected", "prefers-reduced-motion" in css.text)

    print("== offline / PWA")
    m = s.get(f"{BASE}/manifest.webmanifest", timeout=30)
    try:
        man = m.json()
    except Exception:
        man = {}
    check("manifest.webmanifest is valid JSON", bool(man), m.text[:80])
    check("manifest name and theme colour", man.get("name") == "JourneyAI" and man.get("theme_color") == "#123524")
    sizes = {i.get("sizes") for i in man.get("icons", [])}
    check("manifest has SVG, 192 and 512 icons", {"any", "192x192", "512x512"} <= sizes, sizes)
    for ic in man.get("icons", []):
        ri = s.get(f"{BASE}/{ic['src']}", timeout=30)
        check(f"icon {ic['src']} served", ri.status_code == 200 and ri.headers.get("Content-Type", "").startswith("image/"), ri.headers.get("Content-Type"))
    sw = s.get(f"{BASE}/sw.js", timeout=30)
    check("sw.js served with a JavaScript content type", sw.status_code == 200 and "javascript" in sw.headers.get("Content-Type", ""), sw.headers.get("Content-Type"))
    check("sw.js precaches offline.html and handles fetch", "offline.html" in sw.text and "addEventListener('fetch'" in sw.text)
    off = s.get(f"{BASE}/offline.html", timeout=30)
    check("offline.html served and explains the connection is offline", off.status_code == 200 and "offline" in off.text.lower() and "Saved on this device" in off.text)
    js = s.get(f"{BASE}/js/pwa.js", timeout=30)
    check("pwa.js only registers on https or localhost", js.status_code == 200 and "localhost" in js.text and "serviceWorker" in js.text)

    print("== protected trip pages")
    for p in ("my-plans.php", "my-routes.php", "packing.php"):
        r = s.get(f"{BASE}/{p}", allow_redirects=False, timeout=30)
        check(f"{p} requires login", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), r.status_code)
    r = s.get(f"{BASE}/shared-plan.php?t=nope", timeout=30)
    check("shared-plan with a bad token shows a clear message", r.status_code == 200 and "does not open a plan" in r.text)

    ok = all(results)
    print(f"\n{sum(results)}/{len(results)} passed")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
