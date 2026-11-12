"""
JourneyAI - chrome / account tests against a running site.

  ml/venv/Scripts/python.exe tests/account_test.py
  BASE_URL=https://example.com/journeyai python tests/account_test.py

Checks the command palette markup and its suggest.php endpoint, the skip link and <main id="main"> landmark,
the no-flash theme script, the 404 page, and (when Playwright is installed) that the palette opens with
Ctrl+K, closes with Esc, and that no page scrolls sideways at phone width. Exit code 0 = all passed.
"""
import json
import os
import re
import sys

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))
    return bool(cond)


def main():
    s = requests.Session()

    print("\n== suggest.php")
    r = s.get(f"{BASE}/suggest.php", params={"q": "Jai"}, timeout=30)
    check("returns JSON", r.headers.get("Content-Type", "").startswith("application/json"), r.headers.get("Content-Type"))
    d = r.json()
    names = [x["name"] for x in d.get("results", [])]
    check("'Jai' finds Jaipur", any(n.startswith("Jaipur") for n in names), names)
    first = (d.get("results") or [{}])[0]
    check("result has name, region and a destination.php?d= link",
          first.get("name") and "region" in first and first.get("url", "").startswith("destination.php?d="), first)
    check("link carries the encoded name", first.get("url") == "destination.php?d=" + requests.utils.quote(first.get("name", ""), safe="").replace("%20", "+"), first.get("url"))
    check("one letter returns nothing", s.get(f"{BASE}/suggest.php", params={"q": "J"}, timeout=30).json()["results"] == [])
    check("LIKE wildcards are escaped", s.get(f"{BASE}/suggest.php", params={"q": "%%"}, timeout=30).json()["results"] == [])
    r = s.get(f"{BASE}/suggest.php", params={"q": "' OR 1=1 -- "}, timeout=30)
    check("quote characters are harmless", r.status_code == 200 and "results" in r.json())

    print("\n== chrome on every page")
    for path in ["index.php", "login.php", "register.php", "forgot-pswd.php", "plan-trip.php", "feed.php", "analytics.php", "nope-not-here.php"]:
        r = s.get(f"{BASE}/{path}", timeout=60)
        h = r.text
        check(f"{path}: command palette markup", 'id="jaPal"' in h and 'role="dialog"' in h and 'role="combobox"' in h)
        check(f"{path}: skip link", 'class="ja-skip"' in h and 'href="#main"' in h)
        check(f"{path}: one <main id=main> landmark", len(re.findall(r'id="main"', h)) == 1)
        check(f"{path}: no-flash theme script in head", h.find("localStorage.getItem('ja-theme')") != -1 and h.find("localStorage.getItem('ja-theme')") < h.find("<link"))
        check(f"{path}: visible Search button", "data-ja-palette-open" in h)
    r = s.get(f"{BASE}/nope-not-here.php", timeout=30)
    check("404 page returns status 404", r.status_code == 404, r.status_code)
    check("404 page explains itself", "find that page" in r.text)

    print("\n== browser checks (Playwright, optional)")
    try:
        from playwright.sync_api import sync_playwright
    except Exception:
        print("  SKIP  Playwright is not installed for this Python")
    else:
        try:
            with sync_playwright() as p:
                b = p.chromium.launch(channel="chrome")
                ctx = b.new_context(viewport={"width": 375, "height": 812}, is_mobile=True, has_touch=True)
                pg = ctx.new_page()
                for path in ["index.php", "login.php", "register.php", "forgot-pswd.php", "analytics.php", "plan-trip.php"]:
                    pg.goto(f"{BASE}/{path}", wait_until="load")
                    over = pg.evaluate("document.documentElement.scrollWidth - innerWidth")
                    check(f"{path}: no horizontal scroll at 375px", over <= 0, over)
                ctx.close()
                ctx = b.new_context(viewport={"width": 1280, "height": 800})
                pg = ctx.new_page()
                pg.goto(f"{BASE}/login.php", wait_until="load")
                pg.keyboard.press("Control+k")
                check("Ctrl+K opens the palette", pg.is_visible("#jaPal"))
                check("focus moves to the search field", pg.evaluate("document.activeElement.id") == "jaPalInput")
                pg.keyboard.type("Jaip")
                pg.wait_for_selector("#jaPalList [role=option]:has-text('Jaipur')", timeout=5000)
                check("typing a place lists it", True)
                pg.keyboard.press("Tab"); pg.keyboard.press("Tab")
                check("Tab stays inside the dialog", pg.evaluate("!!document.activeElement.closest('#jaPal')"))
                pg.keyboard.press("Escape")
                check("Esc closes the palette", not pg.is_visible("#jaPal"))
                pg.keyboard.press("Control+k"); pg.keyboard.type("plan: 3 days in Goa")
                pg.keyboard.press("Enter"); pg.wait_for_url("**/plan-trip.php*", timeout=8000)
                check("plan: sentence goes to the planner", "plan-trip.php" in pg.url and "go=1" in pg.url and "Goa" in pg.url, pg.url)
                b.close()
        except Exception as e:  # a missing browser should not look like a site bug
            check("browser run completed", False, repr(e)[:200])

    bad = [r for r in results if not r[1]]
    print(f"\n{len(results) - len(bad)}/{len(results)} passed")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
