"""
JourneyAI — black-box smoke test against a running site (Apache + MySQL + ML service up).

  ml/venv/Scripts/python.exe tests/smoke.py                       # http://localhost/travel_journel
  BASE_URL=https://example.com/journeyai python tests/smoke.py

Creates a throw-away account, walks the main user journeys, then deletes the account (which removes
everything it created). Exit code 0 = all passed, 1 = at least one failure. Safe to run on a live site:
it only touches its own "smoke-*@test.invalid" user.
"""
import base64
import os
import re
import secrets
import sys

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))
    return bool(cond)


def csrf_from(html):
    m = re.search(r'JA_CSRF=("[a-f0-9]+")', html) or re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1).strip('"') if m else ""


def main():
    anon = requests.Session()
    user = requests.Session()
    email = f"smoke-{secrets.token_hex(4)}@test.invalid"
    pw = "Smoke-Test#2026a"
    pw2 = "Smoke-Test#2026b"

    print("\n== public pages")
    for path in ["index.php", "login.php", "register.php", "forgot-pswd.php", "feed.php", "read-journals.php", "plan-trip.php"]:
        r = anon.get(f"{BASE}/{path}", allow_redirects=False, timeout=60)
        check(f"GET {path} -> 200", r.status_code == 200, r.status_code)
    r = anon.get(f"{BASE}/health.php", timeout=30)
    check("health.php ok (db + recommender up)", r.status_code == 200 and r.json().get("status") == "ok", r.text[:120])
    check("health.php public output is minimal", "details" not in r.json())

    print("\n== protected pages redirect to login")
    for path in ["dashboard.php", "my-entries.php", "share.php", "profile.php", "admin.php", "packing.php", "new-entry.php"]:
        r = anon.get(f"{BASE}/{path}", allow_redirects=False, timeout=30)
        check(f"{path} requires login", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), r.status_code)

    print("\n== internals are not served")
    for path in ["sql/migrations.sql", "ml/app.py", "ml/artifacts/cost_model.pkl", "config/secret.key", "docs/INDIA_ROADMAP.md", ".git/config", "ml/bot/gemini_key.txt", "uploads/", "action.php"]:
        r = anon.get(f"{BASE}/{path}", allow_redirects=False, timeout=30)
        check(f"{path} blocked", r.status_code in (403, 404), r.status_code)
    r = requests.get(f"{BASE}/login.php", timeout=30)   # fresh client: the server sends Set-Cookie only on a new session
    check("security headers present", r.headers.get("X-Content-Type-Options") == "nosniff" and "X-Frame-Options" in r.headers)
    cookie = r.headers.get("Set-Cookie", "")
    check("session cookie is HttpOnly + SameSite", "httponly" in cookie.lower() and "samesite" in cookie.lower(), cookie)
    check("no PHP version header", "X-Powered-By" not in r.headers)

    print("\n== account: register, preferences, plan-trip prefill")
    r = user.post(f"{BASE}/register.php", data={"fname": "Smoke", "lname": "Test", "eml": email, "psw": pw, "sn": "1"}, allow_redirects=False, timeout=60)
    check("register -> dashboard", r.status_code == 302 and "dashboard.php" in r.headers.get("Location", ""), r.status_code)
    r = user.get(f"{BASE}/profile.php", timeout=60)
    tok = csrf_from(r.text)
    check("profile page has a CSRF token", len(tok) >= 16)
    r = user.post(f"{BASE}/profile.php", data={"csrf": tok, "SavePrefs": "1", "home_city": "Udaipur", "party_size": "2", "default_budget": "45000",
                                               "travel_style": "luxury", "interests[]": ["temples", "food"]}, timeout=60)
    check("preferences saved", "Preferences saved" in r.text)
    r = user.get(f"{BASE}/plan-trip.php", timeout=60)
    check("Plan a Trip is pre-filled from preferences", 'value="Udaipur"' in r.text and 'value="45000"' in r.text)
    r = user.post(f"{BASE}/profile.php", data={"SavePrefs": "1"}, timeout=60)
    check("POST without CSRF is rejected", "Session expired" in r.text)

    print("\n== planning")
    r = user.get(f"{BASE}/trip.php?dest=Goa,+India&days=4", timeout=120)
    check("trip page (itinerary tab) renders", r.status_code == 200 and "ja-itin-day" in r.text)
    r = user.get(f"{BASE}/trip.php?place=Anand&mode=day", timeout=120)
    check("trip page (route tab) renders", r.status_code == 200 and "ja-route-stop" in r.text)
    r = user.post(f"{BASE}/smart-plan.php", json={"text": "3 days beaches under 20000"}, timeout=120)
    check("one-box search returns destination matches", r.ok and r.json().get("kind") == "recommend" and r.json().get("recommendations"))
    for old in ["explore.php?q=x", "itinerary.php?dest=Goa", "route.php?place=Anand"]:
        r = user.get(f"{BASE}/{old}", allow_redirects=False, timeout=30)
        check(f"{old.split('?')[0]} redirects", r.status_code == 302)

    print("\n== packing list")
    r = user.post(f"{BASE}/packing.php", data={"csrf": tok, "do": "create", "destination": "Manali, India", "days": "5", "month": "12", "party": "2", "style": "adventure",
                                               "options[]": ["kids", "trekking"], "name": "Smoke list"}, allow_redirects=False, timeout=60)
    loc = r.headers.get("Location", "")
    check("packing list created", r.status_code == 302 and "list=" in loc, r.status_code)
    page = user.get(f"{BASE}/{loc}", timeout=60).text
    items = re.findall(r'data-item="(\d+)"', page)
    check("list has a sensible number of suggested items", 25 <= len(items) <= 80, len(items))
    check("cold-weather items suggested for Manali in December", "Warm jacket" in page and "Thermal" in page)
    ptok = csrf_from(page)
    r = user.post(f"{BASE}/packing-api.php", data={"csrf": ptok, "action": "toggle", "item_id": items[0], "checked": "1"}, timeout=30)
    check("tick an item", r.json().get("packed") == 1, r.text)
    lid = re.search(r"list=(\d+)", loc).group(1)
    r = user.post(f"{BASE}/packing-api.php", data={"csrf": ptok, "action": "add", "list_id": lid, "label": "Yoga mat", "qty": "1", "category": "extras"}, timeout=30)
    check("add a custom item", r.json().get("ok") is True, r.text)
    r = user.post(f"{BASE}/packing-api.php", data={"action": "toggle", "item_id": items[0], "checked": "0"}, timeout=30)
    check("packing API rejects requests without CSRF", "error" in r.json())

    print("\n== community: post, comment, pin, public profile")
    files = {"media[]": ("t.png", PNG, "image/png")}
    r = user.post(f"{BASE}/share.php", data={"csrf": tok, "caption": "Smoke post #smoke", "place": "Goa, India", "audience": "public"}, files=files, timeout=60)
    check("post shared", "post shared" in r.text.lower(), r.text[:100])
    feed = user.get(f"{BASE}/feed.php", timeout=60).text
    m = re.search(r'data-media-id="(\d+)"', feed)
    mid = None
    page = user.get(f"{BASE}/share.php", timeout=60).text
    m = re.search(r'data-pin-kind="media" data-pin-id="(\d+)"', page)
    if check("my post is listed with a pin button", bool(m)):
        mid = m.group(1)
        r = user.post(f"{BASE}/engage.php", data={"csrf": tok, "action": "comment_add", "kind": "media", "id": mid, "body": "Nice one"}, timeout=30)
        check("comment on a post", r.json().get("ok") is True, r.text)
        r = anon.get(f"{BASE}/engage.php", params={"action": "comments", "kind": "media", "id": mid}, timeout=30)
        check("anyone can read comments on a public post", r.json().get("count") == 1, r.text)
        r = anon.post(f"{BASE}/engage.php", data={"action": "comment_add", "kind": "media", "id": mid, "body": "x"}, timeout=30)
        check("anonymous cannot comment", r.status_code == 401)
        r = user.post(f"{BASE}/engage.php", data={"csrf": tok, "action": "pin", "kind": "media", "id": mid, "pin": "1"}, timeout=30)
        check("pin a post", r.json().get("pinned") is True, r.text)
    handle = re.search(r"traveller\.php\?h=([a-z0-9\-]+)", user.get(f"{BASE}/profile.php", timeout=60).text)
    if check("profile shows a public handle", bool(handle)):
        r = anon.get(f"{BASE}/traveller.php?h={handle.group(1)}", timeout=30)
        check("public profile visible to anyone, shows the pinned post", r.status_code == 200 and "Pinned" in r.text)
        check("public profile never leaks the email", email not in r.text and "test.invalid" not in r.text)

    print("\n== journal: create, share, read, privacy")
    r = user.post(f"{BASE}/new-entry.php", data={"title": "Smoke journey", "coun": "India", "city": "Goa", "dv": "2026-01-02", "dr": "2026-01-05", "desc": "A test story.",
                                                 "visibility": "private", "hotel": "2000", "dinner": "900"}, allow_redirects=False, timeout=60)
    eid = re.search(r"id=(\d+)", r.headers.get("Location", ""))
    if check("journal entry created with a real id", bool(eid) and eid.group(1) != "0", r.headers.get("Location")):
        eid = eid.group(1)
        check("private journal hidden from the public", anon.get(f"{BASE}/journal.php?id={eid}", timeout=30).status_code == 404)
        page = user.get(f"{BASE}/my-entries.php", timeout=60).text
        etok = csrf_from(page)
        r = user.post(f"{BASE}/entry-share.php", data={"csrf": etok, "entry_id": eid, "visibility": "link"}, timeout=30).json()
        link = r.get("url", "")
        check("link sharing returns a tokenised URL", "token=" in link, r)
        token = link.split("token=")[-1]
        check("link works with token", anon.get(f"{BASE}/journal.php?id={eid}&token={token}", timeout=30).status_code == 200)
        check("link rejected with wrong token", anon.get(f"{BASE}/journal.php?id={eid}&token=nope", timeout=30).status_code == 404)
        user.post(f"{BASE}/entry-share.php", data={"csrf": etok, "entry_id": eid, "visibility": "public"}, timeout=30)
        check("public journal listed in Read journals", "Smoke journey" in anon.get(f"{BASE}/read-journals.php", timeout=30).text)
        r = user.get(f"{BASE}/delete.php?id={eid}", allow_redirects=False, timeout=30)
        check("delete by GET does nothing", "Smoke journey" in user.get(f"{BASE}/my-entries.php", timeout=60).text)
        user.post(f"{BASE}/delete.php", data={"csrf": etok, "id": eid}, timeout=30)
        check("delete by POST works", "Smoke journey" not in user.get(f"{BASE}/my-entries.php", timeout=60).text)

    print("\n== security: login + password")
    r = anon.post(f"{BASE}/login.php", data={"eml": email, "password": "wrong", "lgn": "1"}, timeout=30)
    check("wrong password is refused", "Incorrect email or password" in r.text)
    ctok = csrf_from(user.get(f"{BASE}/change-pswd.php", timeout=30).text)
    r = user.post(f"{BASE}/change-pswd.php", data={"CUR": "not-it", "PASS": pw2, "Save": "1"}, timeout=30)
    check("password change needs the current password", "current password is not correct" in r.text)
    r = user.post(f"{BASE}/change-pswd.php", data={"CUR": pw, "PASS": pw2, "Save": "1"}, timeout=30)
    check("password changed with the right current password", "updated" in r.text.lower())
    fresh = requests.Session()
    r = fresh.post(f"{BASE}/login.php", data={"eml": email, "password": pw2, "lgn": "1"}, allow_redirects=False, timeout=30)
    check("can log in with the new password", r.status_code == 302 and "dashboard.php" in r.headers.get("Location", ""))
    check("non-admin cannot open the admin panel", fresh.get(f"{BASE}/admin.php", timeout=30).status_code == 403)

    print("\n== cleanup: delete the account")
    tok = csrf_from(fresh.get(f"{BASE}/profile.php", timeout=30).text)
    r = fresh.post(f"{BASE}/profile.php", data={"csrf": tok, "DeleteAccount": "1", "confirm_pw": "wrong"}, timeout=30)
    check("delete account refuses a wrong password", "Wrong password" in r.text)
    r = fresh.post(f"{BASE}/profile.php", data={"csrf": tok, "DeleteAccount": "1", "confirm_pw": pw2}, allow_redirects=False, timeout=60)
    check("account deleted", r.status_code == 302 and "deleted=1" in r.headers.get("Location", ""), r.status_code)
    r = requests.post(f"{BASE}/login.php", data={"eml": email, "password": pw2, "lgn": "1"}, timeout=30)
    check("deleted account can no longer log in", "Incorrect email or password" in r.text)

    failed = [n for n, ok, _ in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
    if failed:
        print("FAILED:\n  - " + "\n  - ".join(failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
