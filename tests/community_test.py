"""
JourneyAI community pages test (feed, journal reading, journal writing, traveller profile).

  ml/venv/Scripts/python.exe tests/community_test.py

Creates a throw-away "community-*@test.invalid" user, exercises the pages, then deletes the account.
Exit code 0 = all passed.
"""
import io
import os
import re
import secrets
import sys
import time

import requests

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))
    return bool(cond)


def csrf_from(html):
    m = re.search(r'JA_CSRF=("[a-f0-9]+")', html) or re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1).strip('"') if m else ""


def png():
    from PIL import Image
    b = io.BytesIO(); Image.new("RGB", (120, 90), tuple(secrets.randbelow(200) + 30 for _ in range(3))).save(b, "PNG"); return b.getvalue()


def main():
    anon, user = requests.Session(), requests.Session()
    email = f"community-{secrets.token_hex(4)}@test.invalid"; pw = "Comm-Test#2026a"

    print("\n== pages respond")
    for path in ["feed.php", "feed.php?tag=nosuchtagxyz", "read-journals.php", "read-journals.php?q=zzzzqq", "storybook-discover.php"]:
        r = anon.get(f"{BASE}/{path}", timeout=60)
        check(f"GET {path} -> 200", r.status_code == 200, r.status_code)
    r = anon.get(f"{BASE}/feed.php?tag=nosuchtagxyz", timeout=60)
    check("empty feed filter shows an empty state with a next step", "pc-empty" in r.text and "Nothing here yet" in r.text)
    r = anon.get(f"{BASE}/read-journals.php?q=zzzzqq", timeout=60)
    check("empty journal search shows an empty state", "pc-empty" in r.text)
    for path in ["new-entry.php", "my-entries.php", "share.php", "my-storybooks.php", "dashboard.php"]:
        r = anon.get(f"{BASE}/{path}", allow_redirects=False, timeout=30)
        check(f"{path} still requires login", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), r.status_code)

    print("\n== account")
    rtok = csrf_from(user.get(f"{BASE}/register.php", timeout=30).text); time.sleep(4.5)
    user.post(f"{BASE}/register.php", data={"csrf": rtok, "fname": "Comm", "lname": "Test", "eml": email, "psw": pw, "sn": "1"}, allow_redirects=False, timeout=60)
    from ml.db import get_connection
    c = get_connection(dict_cursor=False)
    with c.cursor() as cur:
        cur.execute("UPDATE signup SET email_verified=1, trust_override='trusted' WHERE eml=%s", (email,))
    c.commit(); c.close()
    tok = csrf_from(user.get(f"{BASE}/profile.php", timeout=60).text)
    check("logged in with a CSRF token", len(tok) >= 16)

    print("\n== writing page")
    page = user.get(f"{BASE}/new-entry.php", timeout=60).text
    check("writer has drag-and-drop photo area", 'id="mediaDrop"' in page and "media-drop.js" in page)
    check("writer has live counts, draft status and preview", 'id="pwCounts"' in page and 'id="pwState"' in page and 'id="pwPreview"' in page)

    print("\n== feed")
    xss = '<script>alert(1)</script> #community'
    r = user.post(f"{BASE}/share.php", data={"csrf": tok, "caption": xss, "place": "Goa, India", "audience": "public"}, files={"media[]": ("t.png", png(), "image/png")}, timeout=60)
    check("post shared", "post shared" in r.text.lower(), r.text[:80])
    feed = anon.get(f"{BASE}/feed.php?tag=community", timeout=60).text
    check("feed shows the post as a masonry card", 'class="pc-masonry"' in feed and 'class="pc-card"' in feed)
    check("like and comment buttons share one actions row", bool(re.search(r'class="pc-actions">\s*<button[^>]*data-like.*?</button>\s*<button[^>]*data-comment', feed, re.S)))
    check("user text is escaped (no raw script tag)", "<script>alert(1)</script>" not in feed and "&lt;script&gt;alert(1)&lt;/script&gt;" in feed)
    check("viewer markup present (Esc and arrow keys handled in feed.js)", 'id="pcLb"' in feed and 'role="dialog"' in feed)
    check("hashtag chip filter keeps state in the URL", 'href="feed.php?tag=' in feed and 'aria-current="true"' in feed)
    pj = anon.get(f"{BASE}/feed.php?tag=community&partial=1&page=1", timeout=60)
    d = pj.json() if pj.ok else {}
    check("load-more endpoint returns JSON cards", d.get("count", 0) >= 1 and 'pc-card' in d.get("html", ""), pj.text[:100])
    check("load-more past the end returns no cards and more=false", anon.get(f"{BASE}/feed.php?tag=community&partial=1&page=9", timeout=30).json().get("more") is False)
    js = anon.get(f"{BASE}/js/feed.js", timeout=30).text
    check("feed.js handles Esc, arrows and optimistic likes", "'Escape'" in js and "ArrowRight" in js and "setLike(id, !was" in js)

    print("\n== journal: write, read")
    story = ("We took the early train into Jaipur and dropped our bags before nine. " * 8 + "\n\n## Day 1: the old city\n" + "Hawa Mahal first, before the buses. " * 12 +
             "\n\n## Day 2: Amber Fort\n" + "We climbed instead of riding the elephant. " * 12 + f"\n\n<b>raw</b> {secrets.token_hex(4)}")
    r = user.post(f"{BASE}/new-entry.php", data={"csrf": tok, "title": "Community journey <i>x</i>", "coun": "India", "city": "Jaipur", "dv": "2026-02-02", "dr": "2026-02-05",
                                                 "desc": story, "ptv": "Hawa Mahal, Amber Fort", "visibility": "private", "hotel": "3000", "dinner": "1500"},
                  headers={"X-Requested-With": "XMLHttpRequest"}, timeout=60)
    data = r.json() if r.headers.get("Content-Type", "").startswith("application/json") else {}
    eid = re.search(r"id=(\d+)", data.get("redirect", ""))
    check("writer saves over XHR and returns a redirect", bool(eid) and eid.group(1) != "0", r.text[:120])
    if eid:
        eid = eid.group(1)
        user.post(f"{BASE}/share.php?entry={eid}", data={"csrf": tok, "caption": "From the journal", "audience": "public", "entry_id": eid}, files={"media[]": ("a.png", png(), "image/png")}, timeout=60)
        for i in range(2):
            user.post(f"{BASE}/share.php?entry={eid}", data={"csrf": tok, "caption": f"More {i}", "audience": "public", "entry_id": eid}, files={"media[]": (f"b{i}.png", png(), "image/png")}, timeout=60)
        j = user.get(f"{BASE}/journal.php?id={eid}", timeout=60)
        h = j.text
        check("journal page 200 for its owner", j.status_code == 200, j.status_code)
        check("journal shows reading time", re.search(r"\d+ min read", h) is not None)
        check("journal has a reading-progress bar", 'id="prBar"' in h and "reader.js" in h)
        check("journal has a contents list for a sectioned story", 'class="pr-toc"' in h and 'href="#s1"' in h and 'href="#s2"' in h and 'id="s2"' in h)
        check("journal has a photo lightbox (dialog, nav, close)", 'id="prLb"' in h and 'data-close' in h and 'data-nav="1"' in h and h.count("data-lb=") == 3)
        check("journal lists places mentioned linking to trip.php", "Places mentioned" in h and "trip.php?dest=Hawa+Mahal" in h)
        check("journal text is escaped", "<b>raw</b>" not in h and "<i>x</i>" not in h and "&lt;b&gt;raw&lt;/b&gt;" in h)
        rj = anon.get(f"{BASE}/js/reader.js", timeout=30).text
        check("reader.js handles Esc and arrow keys", "'Escape'" in rj and "ArrowLeft" in rj)
        check("private journal still hidden from the public", anon.get(f"{BASE}/journal.php?id={eid}", timeout=30).status_code == 404)

    print("\n== traveller profile")
    handle = re.search(r"traveller\.php\?h=([a-z0-9\-]+)", user.get(f"{BASE}/profile.php", timeout=60).text)
    if check("profile shows a public handle", bool(handle)):
        r = anon.get(f"{BASE}/traveller.php?h={handle.group(1)}", timeout=30)
        check("traveller page 200 and no email leak", r.status_code == 200 and email not in r.text)
    check("unknown traveller is a 404 with a way back", anon.get(f"{BASE}/traveller.php?h=nobody-here", timeout=30).status_code == 404)

    print("\n== cleanup")
    tok = csrf_from(user.get(f"{BASE}/profile.php", timeout=30).text)
    r = user.post(f"{BASE}/profile.php", data={"csrf": tok, "DeleteAccount": "1", "confirm_pw": pw}, allow_redirects=False, timeout=60)
    check("account deleted", r.status_code == 302, r.status_code)

    failed = [n for n, ok, _ in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
    if failed:
        print("FAILED:\n  - " + "\n  - ".join(failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
