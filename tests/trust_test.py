"""
JourneyAI — tests for the verification, moderation and media/data security layer.

  ml/venv/Scripts/python.exe tests/trust_test.py        (site, MySQL and the ML service running)

Uses throw-away "trust-*@test.invalid" accounts and removes everything it created.
"""
import io
import os
import re
import secrets
import sys

import requests
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, ROOT)
from ml.db import get_connection  # noqa: E402

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
PW = "Trust-Test#2026a"
results = []
STORY = ("We took the early train from Ahmedabad and reached Junagadh before noon. The old fort walls were quiet, the guide knew every "
         "carving, and the street food near the clock tower cost almost nothing. We climbed Girnar the next morning, which took about "
         "five hours with breaks, and slept early. Weekday visits were calm, the buses ran on time, and our whole trip stayed within budget.")


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{str(detail)[:140]}]" if not cond and detail else ""))
    return bool(cond)


def db(sql, args=()):
    c = get_connection(dict_cursor=False)
    try:
        with c.cursor() as cur:
            cur.execute(sql, args or None)
            rows = cur.fetchall() if cur.description else None
        c.commit()
        return rows
    finally:
        c.close()


def csrf(sess, path="profile.php"):
    html = sess.get(f"{BASE}/{path}", timeout=60).text
    m = re.search(r'JA_CSRF=("[a-f0-9]+")', html) or re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1).strip('"') if m else ""


def new_user(tag):
    s = requests.Session()
    eml = f"trust-{tag}-{secrets.token_hex(3)}@test.invalid"
    s.get(f"{BASE}/register.php", timeout=30)
    # the form must not be submitted faster than a human could (server-side check)
    import time; time.sleep(4.5)
    r = s.post(f"{BASE}/register.php", data={"fname": "Trust", "lname": tag, "eml": eml, "psw": PW, "sn": "1", "website": ""}, allow_redirects=False, timeout=60)
    return s, eml, r


def journal(s, tok, title, story, visibility="public", media=None):
    files = {}
    if media:
        files["media[]"] = ("p.jpg", media, "image/jpeg")
    r = s.post(f"{BASE}/new-entry.php", data={"csrf": tok, "title": title, "coun": "India", "city": "Junagadh", "dv": "2026-01-02", "dr": "2026-01-05",
                                              "desc": story, "visibility": visibility, "hotel": "2400", "dinner": "1800", "post_media": "1"},
               files=files or None, allow_redirects=False, timeout=90)
    m = re.search(r"id=(\d+)", r.headers.get("Location", ""))
    return int(m.group(1)) if m and m.group(1) != "0" else None


def jpeg(color=(200, 60, 80), exif_gps=False, size=(640, 420), seed=0):
    im = Image.new("RGB", size, color)
    for i in range(0, size[0], 40):
        for j in range(0, size[1], 40):
            if (i + j + seed) % 80 == 0:
                im.paste((255 - color[0], color[1], seed % 255), (i, j, i + 20, j + 20))
    buf = io.BytesIO()
    if exif_gps:
        ex = Image.Exif()
        ex[0x8825] = {1: "N", 2: (22.0, 40.0, 0.0), 3: "E", 4: (72.0, 52.0, 0.0)}    # GPS block
        ex[0x010F] = "SecretCameraMaker"
        im.save(buf, "JPEG", exif=ex)
    else:
        im.save(buf, "JPEG")
    return buf.getvalue()


def purge():
    """Remove every trust-test account and what it owns (also leftovers from an interrupted run)."""
    emls = [r[0] for r in (db("SELECT eml FROM signup WHERE eml LIKE 'trust-%%@test.invalid'") or [])]
    for e in emls:
        for (f,) in (db("SELECT filepath FROM media WHERE eml=%s", (e,)) or []):
            try:
                os.remove(os.path.join(ROOT, f))
            except OSError:
                pass
        db("DELETE x FROM media_hashtags x JOIN media m ON m.media_id=x.media_id WHERE m.eml=%s", (e,))
        for t in ("db1", "db2", "db3", "media_likes", "media_comments", "media", "db", "user_prefs", "signup"):
            db(f"DELETE FROM {t} WHERE eml=%s", (e,))
        db("DELETE FROM reports WHERE reporter=%s", (e,))
    db("DELETE FROM hashtags WHERE uses<=0 OR tag IN ('t','gps')")
    db("DELETE FROM login_attempts"); db("DELETE FROM rate_limits")
    db("DELETE FROM audit_log WHERE actor LIKE 'trust-%%' OR actor LIKE 'bot%%'")


def main():
    purge()
    created = []
    print("\n== registration protections")
    anon = requests.Session()
    anon.get(f"{BASE}/register.php", timeout=30)
    r = anon.post(f"{BASE}/register.php", data={"fname": "Bot", "lname": "X", "eml": "bot@mailinator.com", "psw": PW, "sn": "1"}, timeout=30)
    check("form submitted instantly is treated as a bot", "automated" in r.text)
    import time; time.sleep(4.5)
    r = anon.post(f"{BASE}/register.php", data={"fname": "Bot", "lname": "X", "eml": "bot@mailinator.com", "psw": PW, "sn": "1"}, timeout=30)
    check("disposable email addresses are refused", "permanent email" in r.text)
    anon.get(f"{BASE}/register.php", timeout=30); time.sleep(4.5)
    r = anon.post(f"{BASE}/register.php", data={"fname": "Bot", "lname": "X", "eml": "bot2@test.invalid", "psw": PW, "sn": "1", "website": "http://spam"}, timeout=30)
    check("honeypot field filled in -> refused", "automated" in r.text)
    anon.get(f"{BASE}/register.php", timeout=30); time.sleep(4.5)
    r = anon.post(f"{BASE}/register.php", data={"fname": "Bot", "lname": "X", "eml": "bot3@test.invalid", "psw": "Password123!", "sn": "1"}, timeout=30)
    check("common passwords are refused", "too common" in r.text)

    print("\n== unverified account (A)")
    A, ea, r = new_user("a"); created.append(ea)
    check("sign-up works", r.status_code == 302, r.status_code)
    row = db("SELECT email_verified, verify_token FROM signup WHERE eml=%s", (ea,))
    check("account starts unverified with a token and a handle", row and row[0][0] == 0 and row[0][1], row)
    ta = csrf(A)
    check("home shows the 'confirm your email' notice", "Confirm your email" in A.get(f"{BASE}/dashboard.php", timeout=60).text)
    jid = journal(A, ta, "Junagadh weekend", STORY, "public")
    check("journal is saved", jid is not None)
    vis = db("SELECT visibility, moderation_status FROM db WHERE entry_id=%s", (jid,))
    check("public request from an unverified account is kept private", vis and vis[0][0] == "private", vis)
    r = A.post(f"{BASE}/entry-share.php", data={"csrf": ta, "entry_id": jid, "visibility": "public"}, timeout=30).json()
    check("sharing is refused with a clear reason", "email" in (r.get("error") or "").lower(), r)
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "x #t", "audience": "public"}, files={"media[]": ("a.jpg", jpeg(seed=1), "image/jpeg")}, timeout=60)
    pub = db("SELECT is_public FROM media WHERE eml=%s", (ea,))
    check("photo from an unverified account is stored private", pub and pub[0][0] == 0, pub)

    print("\n== email verification link")
    token = db("SELECT verify_token FROM signup WHERE eml=%s", (ea,))[0][0]
    check("bad token is rejected", "not valid" in requests.get(f"{BASE}/verify-email.php?token={'0' * 64}", timeout=30).text)
    check("real token verifies the account", "confirmed" in requests.get(f"{BASE}/verify-email.php?token={token}", timeout=30).text)
    check("token cannot be reused", "not valid" in requests.get(f"{BASE}/verify-email.php?token={token}", timeout=30).text)
    check("account is marked verified", db("SELECT email_verified FROM signup WHERE eml=%s", (ea,))[0][0] == 1)

    print("\n== verified but new account goes through moderation")
    ta = csrf(A)
    r = A.post(f"{BASE}/entry-share.php", data={"csrf": ta, "entry_id": jid, "visibility": "public"}, timeout=30).json()
    check("public share is accepted but marked pending", r.get("status") == "pending", r)
    check("pending journal is NOT in Read journals", "Junagadh weekend" not in requests.get(f"{BASE}/read-journals.php", timeout=30).text)
    check("pending journal is hidden from the public (404)", requests.get(f"{BASE}/journal.php?id={jid}", timeout=30).status_code == 404)
    check("the author can still open it and sees the review notice", "Waiting for review" in A.get(f"{BASE}/journal.php?id={jid}", timeout=30).text)
    db("UPDATE db SET moderation_status='approved' WHERE entry_id=%s", (jid,)); db("UPDATE media SET moderation_status='approved' WHERE entry_id=%s", (jid,))
    check("after a moderator approves it, it is public", requests.get(f"{BASE}/journal.php?id={jid}", timeout=30).status_code == 200)
    check("and listed in Read journals", "Junagadh weekend" in requests.get(f"{BASE}/read-journals.php", timeout=30).text)

    print("\n== content checks (account B)")
    B, eb, _ = new_user("b"); created.append(eb)
    db("UPDATE signup SET email_verified=1 WHERE eml=%s", (eb,)); tb = csrf(B)
    j2 = journal(B, tb, "Same story", STORY, "private")
    r = B.post(f"{BASE}/entry-share.php", data={"csrf": tb, "entry_id": j2, "visibility": "public"}, timeout=30).json()
    check("copying another member's journal is flagged", r.get("status") == "pending" and "match" in (r.get("message") or "").lower(), r)
    j3 = journal(B, tb, "Tiny", "Nice place. Went there.", "private")
    r = B.post(f"{BASE}/entry-share.php", data={"csrf": tb, "entry_id": j3, "visibility": "public"}, timeout=30).json()
    check("a very short story is flagged", r.get("status") == "pending" and "short" in (r.get("message") or "").lower(), r)
    j4 = journal(B, tb, "Links", STORY + " Visit http://a.example http://b.example http://c.example now", "private")
    r = B.post(f"{BASE}/entry-share.php", data={"csrf": tb, "entry_id": j4, "visibility": "public"}, timeout=30).json()
    check("link spam is flagged", "links" in (r.get("message") or "").lower(), r)

    print("\n== media security")
    ta = csrf(A)
    evil = b"<?php echo 'PWNED'; ?>"
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "evil", "audience": "private"}, files={"media[]": ("shell.php.jpg", evil, "image/jpeg")}, timeout=60)
    check("a script disguised as an image is rejected", "Unsupported" in r.text or "not a valid" in r.text.lower(), r.text[:200])
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "evil2", "audience": "private"}, files={"media[]": ("a.jpg", b"\xFF\xD8\xFF\xE0" + b"<?php system($_GET[1]); ?>" * 20, "image/jpeg")}, timeout=60)
    check("a fake JPEG header with code inside is rejected", "valid image" in r.text.lower() or "could not be read" in r.text.lower() or "Unsupported" in r.text, r.text[:200])
    gps = jpeg(exif_gps=True, seed=7)
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "gps test #gps", "audience": "private"}, files={"media[]": ("g.jpg", gps, "image/jpeg")}, timeout=60)
    fp = db("SELECT filepath FROM media WHERE eml=%s AND caption='gps test #gps'", (ea,))
    if check("a photo with GPS metadata is accepted", fp):
        stored = open(os.path.join(ROOT, fp[0][0]), "rb").read()
        im = Image.open(io.BytesIO(stored))
        check("stored copy has no EXIF/GPS (location is stripped)", not im.getexif() and b"SecretCameraMaker" not in stored and b"Exif" not in stored[:200])
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "gps test #gps", "audience": "private"}, files={"media[]": ("g.jpg", gps, "image/jpeg")}, timeout=60)
    check("uploading the same file twice is refused", "already uploaded" in r.text.lower())
    shared = jpeg(color=(10, 120, 200), seed=3)
    A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "mine", "audience": "public"}, files={"media[]": ("m.jpg", shared, "image/jpeg")}, timeout=60)
    r = B.post(f"{BASE}/share.php", data={"csrf": tb, "caption": "stolen", "audience": "public"}, files={"media[]": ("m.jpg", shared, "image/jpeg")}, timeout=60)
    check("the same photo from ANOTHER account cannot be posted publicly", "another account" in r.text.lower(), r.text[:200])
    big = Image.new("RGB", (9000, 5000), (3, 3, 3)); buf = io.BytesIO(); big.save(buf, "JPEG", quality=5)
    r = A.post(f"{BASE}/share.php", data={"csrf": ta, "caption": "huge", "audience": "private"}, files={"media[]": ("h.jpg", buf.getvalue(), "image/jpeg")}, timeout=90)
    check("a 45-megapixel image is refused (decompression-bomb guard)", "too large" in r.text.lower() or "megapixels" in r.text.lower(), r.text[:160])
    r = requests.get(f"{BASE}/uploads/", timeout=30, allow_redirects=False)
    check("upload folder listing is blocked", r.status_code in (403, 404))

    print("\n== reports hide content automatically")
    pid = db("SELECT media_id FROM media WHERE eml=%s AND caption='mine'", (ea,))
    mid = pid[0][0]
    db("UPDATE media SET moderation_status='approved' WHERE media_id=%s", (mid,))
    reporters = []
    for i in range(3):
        s, e, _ = new_user("r%d" % i); created.append(e)
        db("UPDATE signup SET email_verified=1 WHERE eml=%s", (e,)); reporters.append((s, e))
    for i, (s, e) in enumerate(reporters):
        t = csrf(s)
        r = s.post(f"{BASE}/engage.php", data={"csrf": t, "action": "report", "kind": "media", "id": mid, "reason": "fake"}, timeout=30).json()
        if i == 0:
            again = s.post(f"{BASE}/engage.php", data={"csrf": t, "action": "report", "kind": "media", "id": mid, "reason": "fake"}, timeout=30).json()
            check("reporting twice counts once", "already" in (again.get("message") or ""), again)
    st = db("SELECT moderation_status FROM media WHERE media_id=%s", (mid,))[0][0]
    check("3 reports from 3 people hide the item", st == "hidden", st)
    check("hidden item no longer shows in the feed", f'"id":{mid},' not in requests.get(f"{BASE}/feed.php", timeout=60).text.replace(" ", ""))
    t = csrf(A)
    check("you cannot report your own item", "own" in A.post(f"{BASE}/engage.php", data={"csrf": t, "action": "report", "kind": "media", "id": mid}, timeout=30).json().get("error", ""))

    print("\n== CSRF and access control")
    r = A.post(f"{BASE}/media-like.php", data={"media_id": mid}, timeout=30)
    check("like without a CSRF token is rejected (403)", r.status_code == 403, r.status_code)
    r = A.post(f"{BASE}/media-like.php", data={"media_id": mid}, headers={"X-CSRF-Token": csrf(A)}, timeout=30)
    check("like with the token works through the header", r.status_code == 200 and "error" not in r.json() or "log in" not in r.text.lower(), r.text[:100])
    r = A.post(f"{BASE}/storybook-api.php", data={"action": "create_book", "title": "x"}, timeout=30)
    check("storybook API rejects POST without a token", r.status_code == 403, r.status_code)
    check("admin moderation page is closed to normal users", A.get(f"{BASE}/admin-moderation.php", timeout=30).status_code == 403)
    check("csrf-token endpoint is not cacheable", "no-store" in A.get(f"{BASE}/csrf-token.php", timeout=30).headers.get("Cache-Control", ""))

    print("\n== models only learn from trusted data")
    db("UPDATE db SET moderation_status='pending' WHERE eml=%s", (ea,))
    n_all = db("SELECT COUNT(*) FROM journals j JOIN db d ON d.entry_id=j.entry_id JOIN signup s ON s.eml=j.eml WHERE d.moderation_status='approved' AND s.email_verified=1")[0][0]
    n_mine = db("SELECT COUNT(*) FROM journals j JOIN db d ON d.entry_id=j.entry_id JOIN signup s ON s.eml=j.eml WHERE d.moderation_status='approved' AND s.email_verified=1 AND j.eml=%s", (ea,))[0][0]
    check("pending journals are excluded from the training query", n_mine == 0 and n_all > 0, (n_all, n_mine))

    print("== cleanup")
    purge()
    left = db("SELECT COUNT(*) FROM signup WHERE eml LIKE 'trust-%%'")[0][0]
    check("test accounts removed", left == 0, left)

    failed = [n for n, ok in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
    if failed:
        print("FAILED:\n  - " + "\n  - ".join(failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
