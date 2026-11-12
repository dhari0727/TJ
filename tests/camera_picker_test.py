"""
Camera roll picker integration test (storybook editor, journal writer, community composer).

  ml/venv/Scripts/python.exe tests/camera_picker_test.py

Creates two throw-away "campick-*@test.invalid" users, checks the picker is wired into the pages, that the
server rejects paths outside uploads/ (and files the user does not own) when picked photos are submitted, and
that a real camera-roll copy is accepted. Deletes both accounts. Exit code 0 = all passed.
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
    b = io.BytesIO(); Image.new("RGB", (160, 120), tuple(secrets.randbelow(200) + 30 for _ in range(3))).save(b, "PNG"); return b.getvalue()


def make_user(tag):
    s = requests.Session()
    email = f"campick-{tag}-{secrets.token_hex(3)}@test.invalid"; pw = "Cam-Pick#2026a"
    rtok = csrf_from(s.get(f"{BASE}/register.php", timeout=30).text); time.sleep(4.5)
    s.post(f"{BASE}/register.php", data={"csrf": rtok, "fname": "Cam", "lname": "Pick", "eml": email, "psw": pw, "sn": "1"}, allow_redirects=False, timeout=60)
    from ml.db import get_connection
    c = get_connection(dict_cursor=False)
    with c.cursor() as cur:
        cur.execute("UPDATE signup SET email_verified=1, trust_override='trusted' WHERE eml=%s", (email,))
    c.commit(); c.close()
    return s, email, pw, csrf_from(s.get(f"{BASE}/profile.php", timeout=60).text)


def db(sql, args=()):
    from ml.db import get_connection
    c = get_connection(dict_cursor=False)
    with c.cursor() as cur:
        cur.execute(sql, args); rows = cur.fetchall() if cur.description else []
    c.commit(); c.close(); return rows


def main():
    A, ea, pwa, ta = make_user("a")
    B, eb, pwb, tb = make_user("b")
    check("both users logged in with CSRF tokens", len(ta) >= 16 and len(tb) >= 16)

    def api(sess, tok, **data):
        return sess.post(f"{BASE}/camera-api.php", data=data, headers={"X-CSRF-Token": tok}, timeout=60).json()

    print("\n== picker assets")
    check("camera-picker.js served and exposes jaCameraPicker.open",
          "jaCameraPicker" in A.get(f"{BASE}/js/camera-picker.js", timeout=30).text)
    css = A.get(f"{BASE}/css/pages-camera-picker.css", timeout=30)
    check("picker stylesheet served", css.status_code == 200 and ".cp-dialog" in css.text)
    js = A.get(f"{BASE}/js/camera-picker.js", timeout=30).text
    check("picker note, focus trap, Esc and aria dialog present",
          "Your originals stay private in your camera roll" in js and "aria-modal" in js and "'Escape'" in js and "'Tab'" in js and "Take a new photo" in js)

    print("\n== pages wire the picker in")
    r = A.post(f"{BASE}/storybook-api.php", data={"action": "create_book", "title": "Picker book"}, headers={"X-CSRF-Token": ta}, timeout=30).json()
    book = r.get("book_id"); check("storybook created", bool(book), r)
    page = A.post(f"{BASE}/storybook-api.php", data={"action": "add_page", "book_id": book, "template": "photos"}, headers={"X-CSRF-Token": ta}, timeout=30).json()
    pid = page.get("page_id"); check("storybook page created", bool(pid), page)
    h = A.get(f"{BASE}/storybook-edit.php?id={book}", timeout=60).text
    check("storybook editor has the camera roll button and script", 'id="sbCameraBtn"' in h and "From my camera roll" in h and "js/camera-picker.js" in h and "pages-camera-picker.css" in h)
    check("storybook editor opens the picker with multiple selection", "jaCameraPicker.open({multiple:true" in h)
    h = A.get(f"{BASE}/new-entry.php", timeout=60).text
    check("journal writer has the camera roll button and script", 'id="cameraPick"' in h and "From my camera roll" in h and "js/camera-picker.js" in h and "pages-camera-picker.css" in h)
    h = A.get(f"{BASE}/share.php", timeout=60).text
    check("community composer has the camera roll button and script", 'id="cameraPick"' in h and "js/camera-picker.js" in h)

    print("\n== storybook handler rejects bad paths")
    foreign = None
    up = B.post(f"{BASE}/share.php", data={"csrf": tb, "caption": "b photo", "audience": "private"}, files={"media[]": ("b.png", png(), "image/png")}, timeout=60)
    rows = db("SELECT filepath FROM media WHERE eml=%s ORDER BY media_id DESC LIMIT 1", (eb,))
    if check("second user has an upload to test ownership with", bool(rows), up.text[:80]): foreign = rows[0][0]
    bad = ["../secret.jpg", "uploads/../connection.php", "uploads/../../x.jpg", "http://evil.test/x.jpg", "/etc/passwd", "uploads/x.php",
           "uploads/does-not-exist-0000.jpg", "assets/photo.jpg"]
    if foreign: bad.append(foreign)
    for p in bad:
        res = A.post(f"{BASE}/storybook-api.php", data={"action": "update_page", "page_id": pid, "photo_1": p}, headers={"X-CSRF-Token": ta}, timeout=30).json()
        check(f"storybook rejects {p}", res.get("error") and not res.get("ok"), res)
    row = db("SELECT photo_1 FROM storybook_pages WHERE page_id=%s", (pid,))
    check("rejected paths were not stored", row and not row[0][0], row)

    print("\n== journal handler rejects bad picked paths")
    base = {"csrf": ta, "title": "Picker trip", "coun": "India", "city": "Goa", "visibility": "private"}
    r = A.post(f"{BASE}/new-entry.php", data={**base, "picked[]": bad + ["uploads/../x.jpg"]}, allow_redirects=False, timeout=60)
    eid = (re.search(r"id=(\d+)", r.headers.get("Location", "")) or [0, 0])[1]
    check("journal saved without the bad photos", r.status_code == 302 and eid, r.status_code)
    n = db("SELECT COUNT(*) FROM media WHERE eml=%s AND entry_id=%s", (ea, eid))[0][0]
    check("no media rows were attached from bad paths", n == 0, n)
    n = db("SELECT COUNT(*) FROM media WHERE eml=%s AND filepath=%s", (eb, foreign))[0][0] if foreign else 1
    check("the other user's photo was not touched", n == 1, n)

    print("\n== a real camera-roll copy is accepted")
    up = A.post(f"{BASE}/camera-api.php", data={"action": "upload", "caption": "Picker snap", "place": "Goa"}, files={"image": ("s.png", png(), "image/png")}, headers={"X-CSRF-Token": ta}, timeout=90).json()
    if check("camera roll upload ok", up.get("ok"), up):
        pid_photo = up["photo"]["id"]
        used = api(A, ta, action="use", id=pid_photo)
        path = used.get("path", "")
        check("use returns a public uploads/ path", used.get("ok") and re.match(r"^uploads/[A-Za-z0-9_\-]+\.(jpe?g|png|webp)$", path), used)
        check("the copy is publicly reachable but the roll original is not listed there",
              requests.get(f"{BASE}/{path}", timeout=30).status_code == 200)
        res = A.post(f"{BASE}/storybook-api.php", data={"action": "update_page", "page_id": pid, "photo_1": path, "photo_1_cap": "Picker snap"}, headers={"X-CSRF-Token": ta}, timeout=30).json()
        check("storybook accepts the camera-roll copy", res.get("ok"), res)
        row = db("SELECT photo_1 FROM storybook_pages WHERE page_id=%s", (pid,))
        check("copy stored on the page", row and row[0][0] == path, row)
        # the other user cannot use my copy
        res = B.post(f"{BASE}/storybook-api.php", data={"action": "update_page", "page_id": pid, "photo_1": path}, headers={"X-CSRF-Token": tb}, timeout=30).json()
        check("another user cannot write to my page", not res.get("ok"), res)
        used2 = api(A, ta, action="use", id=pid_photo)
        r = A.post(f"{BASE}/new-entry.php", data={**base, "title": "Picker trip 2", "picked[]": [used2["path"]], "picked_caption[]": ["On the beach #goa"]}, allow_redirects=False, timeout=60)
        eid2 = (re.search(r"id=(\d+)", r.headers.get("Location", "")) or [0, 0])[1]
        n = db("SELECT COUNT(*) FROM media WHERE eml=%s AND entry_id=%s AND filepath=%s AND caption=%s", (ea, eid2, used2["path"], "On the beach #goa"))[0][0]
        check("journal attaches the picked copy with its caption", n == 1, n)
        n = db("SELECT COUNT(*) FROM media WHERE filepath=%s", (used2["path"],))[0][0]
        check("the camera copy was reused, not duplicated", n == 1, n)
        # B cannot attach A's file to B's journal
        r = B.post(f"{BASE}/new-entry.php", data={"csrf": tb, "title": "Steal", "picked[]": [used2["path"]]}, allow_redirects=False, timeout=60)
        eid3 = (re.search(r"id=(\d+)", r.headers.get("Location", "")) or [0, 0])[1]
        n = db("SELECT COUNT(*) FROM media WHERE eml=%s AND filepath=%s", (eb, used2["path"]))[0][0]
        check("another user cannot attach my photo to their journal", n == 0, n)

    print("\n== cleanup")
    for s, pw, who in ((A, pwa, ta), (B, pwb, tb)):
        tok = csrf_from(s.get(f"{BASE}/profile.php", timeout=30).text)
        r = s.post(f"{BASE}/profile.php", data={"csrf": tok, "DeleteAccount": "1", "confirm_pw": pw}, allow_redirects=False, timeout=60)
        check("account deleted", r.status_code == 302, r.status_code)
    left = db("SELECT COUNT(*) FROM media WHERE eml IN (%s,%s)", (ea, eb))[0][0]
    check("no media rows left for the test accounts", left == 0, left)

    failed = [n for n, ok, _ in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
    if failed:
        print("FAILED:\n  - " + "\n  - ".join(failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
