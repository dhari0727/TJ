"""
JourneyAI - tests for sharing and direct social posting.

  ml/venv/Scripts/python.exe tests/social_test.py        (site and MySQL running)

Bluesky and Mastodon are replaced by a LOCAL MOCK server (a thread in this script), so no real accounts are needed. The site accepts a
localhost server address only when it is itself running on localhost. Facebook, Instagram, X, LinkedIn, Pinterest and Threads
cannot be exercised end to end without the owner's developer apps: only the "Not set up" state, the admin credential form and
the start of the sign-in redirect are checked for those.

Uses throw-away "soc-*@test.invalid" accounts and removes everything it created.
"""
import base64
import hashlib
import io
import json
import os
import re
import secrets
import sys
import threading
import time
import urllib.parse
from email.parser import BytesParser
from email import policy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import requests
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, ROOT)
from ml.db import get_connection  # noqa: E402

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
PW = "Social-Test#2026a"
APP_PW = "abcd-efgh-ijkl-mnop"
results = []
STORY = ("We took the early train from Ahmedabad and reached Junagadh before noon. The old fort walls were quiet, the guide knew every "
         "carving, and the street food near the clock tower cost almost nothing. We climbed Girnar the next morning, which took about "
         "five hours with breaks, and slept early. Weekday visits were calm, the buses ran on time, and our whole trip stayed within budget.")


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{str(detail)[:200]}]" if not cond and detail else ""))
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


# --------------------------------------------------------------------------- the mock Bluesky + Mastodon
class Mock:
    def __init__(self):
        self.calls = []          # (method, path)
        self.blobs = []          # (content_type, bytes)
        self.records = []        # createRecord bodies
        self.mauth = {}          # state -> code_challenge
        self.mmedia = []         # (description, bytes)
        self.mstatuses = []      # parsed form
        self.mtoken_ok = 0
        self.mrevoked = 0
        self.bs_deleted = 0
        self.max_chars = 300
        self.jwt = self._jwt()
        self.port = 0

    @staticmethod
    def _jwt():
        b = lambda d: base64.urlsafe_b64encode(json.dumps(d).encode()).rstrip(b"=").decode()
        return b({"alg": "none"}) + "." + b({"exp": int(time.time()) + 7200, "sub": "did:plc:mock123"}) + "." + secrets.token_hex(8)

    def count(self, path):
        return len([c for c in self.calls if c[1] == path])


MOCK = Mock()


class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass

    def _read(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n) if n else b""

    def _send(self, code, obj=None, headers=None):
        body = json.dumps(obj if obj is not None else {}).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _authed(self):
        return self.headers.get("Authorization", "") in ("Bearer " + MOCK.jwt, "Bearer MASTO_TOKEN_SECRET_1", "Bearer REFRESH_" + MOCK.jwt[-6:])

    def do_GET(self):
        u = urllib.parse.urlparse(self.path); path = u.path; q = urllib.parse.parse_qs(u.query)
        MOCK.calls.append(("GET", path))
        if path == "/oauth/authorize":
            MOCK.mauth[q["state"][0]] = q["code_challenge"][0]
            loc = q["redirect_uri"][0] + "?" + urllib.parse.urlencode({"code": "MOCKCODE", "state": q["state"][0]})
            self.send_response(302); self.send_header("Location", loc); self.send_header("Content-Length", "0"); self.end_headers(); return
        if path == "/api/v1/accounts/verify_credentials":
            return self._send(200, {"id": "42", "username": "tester", "acct": "tester", "display_name": "Tester"}) if self._authed() else self._send(401, {"error": "bad token"})
        if path == "/api/v2/instance":
            return self._send(200, {"configuration": {"statuses": {"max_characters": MOCK.max_chars, "max_media_attachments": 2}}})
        return self._send(404, {"error": "nope"})

    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        body = self._read()
        MOCK.calls.append(("POST", path))
        ct = self.headers.get("Content-Type", "")
        if path == "/xrpc/com.atproto.server.createSession":
            d = json.loads(body or b"{}")
            if d.get("password") == APP_PW and d.get("identifier") == "tester.test":
                return self._send(200, {"accessJwt": MOCK.jwt, "refreshJwt": "REFRESH_" + MOCK.jwt[-6:], "handle": "tester.test", "did": "did:plc:mock123"})
            return self._send(401, {"error": "AuthenticationRequired", "message": "Invalid identifier or password"})
        if path == "/xrpc/com.atproto.server.deleteSession":
            MOCK.bs_deleted += 1; return self._send(200)
        if path == "/xrpc/com.atproto.repo.uploadBlob":
            if not self._authed(): return self._send(401, {"error": "AuthMissing"})
            MOCK.blobs.append((ct, body))
            return self._send(200, {"blob": {"$type": "blob", "ref": {"$link": "bafkreimock%d" % len(MOCK.blobs)}, "mimeType": ct, "size": len(body)}})
        if path == "/xrpc/com.atproto.repo.createRecord":
            if not self._authed(): return self._send(401, {"error": "AuthMissing"})
            d = json.loads(body); MOCK.records.append(d)
            return self._send(200, {"uri": "at://did:plc:mock123/app.bsky.feed.post/3kmock%d" % len(MOCK.records), "cid": "bafy"})
        if path == "/api/v1/apps":
            f = urllib.parse.parse_qs(body.decode())
            return self._send(200, {"client_id": "mock-client", "client_secret": "mock-secret", "redirect_uri": f.get("redirect_uris", [""])[0]})
        if path == "/oauth/token":
            f = {k: v[0] for k, v in urllib.parse.parse_qs(body.decode()).items()}
            ch = None
            for st, c in MOCK.mauth.items():
                ch = c
            ver = f.get("code_verifier", "")
            calc = base64.urlsafe_b64encode(hashlib.sha256(ver.encode()).digest()).rstrip(b"=").decode()
            if f.get("code") != "MOCKCODE" or f.get("client_id") != "mock-client" or f.get("client_secret") != "mock-secret" or calc != ch:
                return self._send(400, {"error": "invalid_grant"})
            MOCK.mtoken_ok += 1
            return self._send(200, {"access_token": "MASTO_TOKEN_SECRET_1", "token_type": "Bearer", "scope": "write:statuses write:media"})
        if path == "/oauth/revoke":
            MOCK.mrevoked += 1; return self._send(200)
        if path == "/api/v2/media":
            if not self._authed(): return self._send(401, {"error": "bad token"})
            msg = BytesParser(policy=policy.default).parsebytes(b"Content-Type: " + ct.encode() + b"\r\n\r\n" + body)
            desc, data = "", b""
            for part in msg.iter_parts():
                name = part.get_param("name", header="content-disposition")
                if name == "description": desc = part.get_content()
                if name == "file": data = part.get_payload(decode=True)
            MOCK.mmedia.append((desc, data))
            return self._send(200, {"id": "m%d" % len(MOCK.mmedia)})
        if path == "/api/v1/statuses":
            if not self._authed(): return self._send(401, {"error": "bad token"})
            f = urllib.parse.parse_qs(body.decode())
            MOCK.mstatuses.append(f)
            return self._send(200, {"id": "s%d" % len(MOCK.mstatuses), "url": "http://127.0.0.1:%d/@tester/s%d" % (MOCK.port, len(MOCK.mstatuses))})
        return self._send(404, {"error": "nope"})


def start_mock():
    srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
    MOCK.port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


# --------------------------------------------------------------------------- helpers
def csrf(sess, path="profile.php"):
    html = sess.get(f"{BASE}/{path}", timeout=60).text
    m = re.search(r'JA_CSRF=("[a-f0-9]+")', html) or re.search(r'name="csrf" value="([a-f0-9]+)"', html) or re.search(r'csrf:\s*"([a-f0-9]+)"', html)
    return m.group(1).strip('"') if m else ""


def ftok(sess, path):
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', sess.get(f"{BASE}/{path}", timeout=30).text)
    return m.group(1) if m else ""


def new_user(tag):
    s = requests.Session()
    eml = f"soc-{tag}-{secrets.token_hex(3)}@test.invalid"
    tk = ftok(s, "register.php")
    time.sleep(4.5)
    s.post(f"{BASE}/register.php", data={"csrf": tk, "fname": "Soc", "lname": tag, "eml": eml, "psw": PW, "sn": "1", "website": ""}, allow_redirects=False, timeout=60)
    db("UPDATE signup SET email_verified=1, trust_override='trusted' WHERE eml=%s", (eml,))
    return s, eml


def jpeg(color=(200, 60, 80), size=(640, 420), gps=True):
    im = Image.new("RGB", size, color)
    for i in range(0, size[0], 40):
        for j in range(0, size[1], 40):
            if (i + j) % 80 == 0:
                im.paste((255 - color[0], color[1], 90), (i, j, i + 20, j + 20))
    buf = io.BytesIO()
    if gps:
        ex = Image.Exif(); ex[0x8825] = {1: "N", 2: (22.0, 40.0, 0.0), 3: "E", 4: (72.0, 52.0, 0.0)}; ex[0x010F] = "SecretCameraMaker"
        im.save(buf, "JPEG", exif=ex)
    else:
        im.save(buf, "JPEG")
    return buf.getvalue()


def journal(s, tok, title, visibility="public", media=None):
    files = {"media[]": ("p.jpg", media, "image/jpeg")} if media else None
    r = s.post(f"{BASE}/new-entry.php", data={"csrf": tok, "title": title, "coun": "India", "city": "Junagadh", "dv": "2026-01-02", "dr": "2026-01-05",
                                              "desc": STORY, "visibility": visibility, "hotel": "2400", "dinner": "1800", "post_media": "1"},
               files=files, allow_redirects=False, timeout=90)
    m = re.search(r"id=(\d+)", r.headers.get("Location", ""))
    return int(m.group(1)) if m and m.group(1) != "0" else None


def api(s, tok, action, **data):
    data["action"] = action
    r = s.post(f"{BASE}/social-api.php", data=data, headers={"X-CSRF-Token": tok, "Accept": "application/json"}, timeout=90)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {"raw": r.text[:300]}


def post_job(s, tok, kind, item_id, jobs, alts=None, confirmed="1", req=None, path=""):
    return api(s, tok, "post", kind=kind, id=item_id, path=path, confirmed=confirmed, req=req or secrets.token_hex(8),
               payload=json.dumps({"jobs": jobs, "alts": alts or {}}))


def purge():
    emls = [r[0] for r in (db("SELECT eml FROM signup WHERE eml LIKE 'soc-%%@test.invalid'") or [])]
    for e in emls:
        for (f,) in (db("SELECT filepath FROM media WHERE eml=%s", (e,)) or []):
            try: os.remove(os.path.join(ROOT, f))
            except OSError: pass
        db("DELETE x FROM media_hashtags x JOIN media m ON m.media_id=x.media_id WHERE m.eml=%s", (e,))
        for t in ("social_posts", "social_accounts", "camera_photos", "camera_folders", "storybooks", "db1", "db2", "db3", "media_likes", "media_comments", "media", "db", "user_prefs", "signup"):
            db(f"DELETE FROM {t} WHERE eml=%s", (e,))
    db("DELETE FROM hashtags WHERE uses<=0")
    db("DELETE FROM settings WHERE skey LIKE 'social_%%'")
    db("DELETE FROM login_attempts"); db("DELETE FROM rate_limits")
    db("DELETE FROM audit_log WHERE actor LIKE 'soc-%%'")


def main():
    purge()
    srv = start_mock()
    MOCK_URL = f"http://127.0.0.1:{MOCK.port}"
    db("INSERT INTO settings (skey, svalue, is_secret) VALUES ('social_bluesky_base', %s, 0) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", (MOCK_URL,))

    print("\n== set up: a trusted member with a public journal, a private one and a pending one")
    A, ea = new_user("a")
    ta = csrf(A)
    check("member has a CSRF token", len(ta) > 10)
    j_pub = journal(A, ta, "Junagadh public weekend", "public", jpeg(gps=True))
    j_priv = journal(A, ta, "Junagadh private notes", "private")
    j_pend = journal(A, ta, "Junagadh pending piece", "public")
    check("three journals created", all([j_pub, j_priv, j_pend]), (j_pub, j_priv, j_pend))
    db("UPDATE db SET moderation_status='approved', visibility='public' WHERE entry_id=%s", (j_pub,))
    db("UPDATE media SET moderation_status='approved', is_public=1 WHERE entry_id=%s", (j_pub,))
    db("UPDATE db SET moderation_status='pending', visibility='public' WHERE entry_id=%s", (j_pend,))
    anon = requests.Session()

    print("\n== share links and link previews")
    d = anon.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "journal", "id": j_pub}, timeout=60).json()
    check("public journal is shareable by anyone", d.get("ok") and d["item"]["shareable"], d)
    jurl = d["item"]["url"]
    check("share URL is the absolute public journal URL", jurl.startswith("http") and jurl.endswith(f"journal.php?id={j_pub}"), jurl)
    links = {l["id"]: l["url"] for l in d["links"]}
    check("links for WhatsApp, Facebook, X, LinkedIn, Pinterest, Telegram, Reddit, Bluesky, email", {"whatsapp", "facebook", "x", "linkedin", "pinterest", "telegram", "reddit", "bluesky", "email"} <= set(links), list(links))
    enc = urllib.parse.quote(jurl, safe="")
    check("each link carries the public URL, URL-encoded", all(enc in u or urllib.parse.quote(jurl) in u or jurl.replace(":", "%3A") in u for k, u in links.items() if k not in ("whatsapp", "email", "bluesky")), links)
    check("WhatsApp, Bluesky and email links include the URL in the text", all(urllib.parse.quote(jurl, safe="") in links[k] or urllib.parse.quote(jurl) in links[k] for k in ("whatsapp", "bluesky", "email")))
    check("links use the expected services", links["whatsapp"].startswith("https://wa.me/?text=") and links["facebook"].startswith("https://www.facebook.com/sharer/sharer.php?u=")
          and links["x"].startswith("https://twitter.com/intent/tweet?") and links["linkedin"].startswith("https://www.linkedin.com/sharing/share-offsite/?url=")
          and links["pinterest"].startswith("https://pinterest.com/pin/create/button/?url=") and links["telegram"].startswith("https://t.me/share/url?")
          and links["reddit"].startswith("https://www.reddit.com/submit?") and links["email"].startswith("mailto:?subject="), links)
    check("Pinterest link has the real photo as media", "media=" in links["pinterest"] and "uploads%2F" in links["pinterest"], links["pinterest"])
    check("no link is a script or data URL", not any(re.match(r"^(javascript|data):", u, re.I) for u in links.values()))
    d2 = anon.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "journal", "id": j_priv}, timeout=60)
    check("a private journal is not described to strangers (404)", d2.status_code == 404 and "Junagadh private" not in d2.text, d2.text[:100])
    d3 = anon.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "journal", "id": j_pend}, timeout=60)
    check("a pending journal is not described to strangers (404)", d3.status_code == 404)
    d4 = A.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "journal", "id": j_priv}, timeout=60).json()
    check("the owner of a private journal is told to make it public first", d4["ok"] and not d4["item"]["shareable"] and "public first" in d4["item"]["reason"] and not d4["links"], d4)
    d5 = A.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "journal", "id": j_pend}, timeout=60).json()
    check("the owner of a pending journal sees the review message and no links", d5["ok"] and not d5["item"]["shareable"] and "review" in d5["item"]["reason"] and not d5["links"], d5)
    html = anon.get(f"{BASE}/journal.php?id={j_pub}", timeout=60).text
    og = dict(re.findall(r'<meta property="(og:[a-z:]+)" content="([^"]*)"', html))
    check("journal page has Open Graph title/description/image/url", og.get("og:title") and og.get("og:description") and og.get("og:image", "").startswith("http") and og.get("og:url") == jurl, og)
    check("og:image is a real uploaded photo", "/uploads/" in og.get("og:image", ""), og.get("og:image"))
    check("journal page has Twitter Card tags", 'name="twitter:card" content="summary_large_image"' in html and 'name="twitter:title"' in html)
    check("the page has a Share button", "data-soc-open" in html and 'data-kind="journal"' in html)
    html_priv = A.get(f"{BASE}/journal.php?id={j_priv}", timeout=60).text
    check("a private journal's page has no Open Graph tags", 'property="og:title"' not in html_priv)
    bid = None
    db("INSERT INTO storybooks (eml, title, subtitle, theme, cover_img, visibility, moderation_status) SELECT %s, 'Soc book', 'Notes from a quiet week', 'floral', filepath, 'public', 'approved' FROM media WHERE entry_id=%s LIMIT 1", (ea, j_pub))
    bid = db("SELECT book_id FROM storybooks WHERE eml=%s", (ea,))[0][0]
    bh = anon.get(f"{BASE}/storybook-view.php?id={bid}", timeout=60).text
    check("storybook page has Open Graph tags and its cover", 'property="og:title" content="Soc book"' in bh and 'property="og:image"' in bh and 'name="twitter:card"' in bh)

    print("\n== nothing happens without login, a token, or confirmation")
    r = requests.post(f"{BASE}/social-api.php", data={"action": "post", "kind": "journal", "id": j_pub, "confirmed": "1"}, timeout=30)
    check("anonymous post is refused", r.status_code in (401, 403), r.status_code)
    r = A.post(f"{BASE}/social-api.php", data={"action": "targets"}, timeout=30)
    check("a POST without the CSRF token is refused", r.status_code == 403, r.status_code)

    print("\n== Bluesky (mock): connect")
    st, d = api(A, ta, "connect_bluesky", handle="tester.test", app_password="wxyz-wxyz-wxyz-wxyz")
    check("a wrong app password is refused with a clear message", st == 400 and "app password" in d.get("error", ""), d)
    st, d = api(A, ta, "connect_bluesky", handle="tester.test", app_password="not a password")
    check("a malformed app password is refused before any request", st == 400, d)
    st, d = api(A, ta, "connect_bluesky", handle="tester.test", app_password=APP_PW)
    check("the right app password connects", st == 200 and d.get("ok"), d)
    row = db("SELECT account_id, handle, token_enc, refresh_enc, scopes, meta FROM social_accounts WHERE eml=%s AND provider='bluesky'", (ea,))
    check("one Bluesky account is stored", row and len(row) == 1 and row[0][1] == "@tester.test", row)
    aid = row[0][0]
    check("tokens are encrypted (v1: AES-GCM), not plain text", row[0][2].startswith("v1:") and MOCK.jwt not in row[0][2] and "REFRESH_" not in row[0][3] and row[0][3].startswith("v1:"), row[0][2][:30])
    dump = json.dumps(db("SELECT * FROM social_accounts WHERE eml=%s", (ea,)), default=str)
    check("the app password is not stored anywhere", APP_PW not in dump and MOCK.jwt not in dump)
    key_path = os.path.join(ROOT, "private", "social.key")
    check("the key file exists outside the web (private/, deny-all)", os.path.isfile(key_path) and os.path.isfile(os.path.join(ROOT, "private", ".htaccess")))
    check("private/social.key is not downloadable", requests.get(f"{BASE}/private/social.key", timeout=30).status_code in (403, 404))
    ig = open(os.path.join(ROOT, ".gitignore"), encoding="utf-8").read()
    check("the key file is in .gitignore", "private/social.key" in ig)
    st, d = api(A, ta, "targets")
    acc = d["accounts"][0]
    check("targets list the account with limits and no token", acc["provider"] == "bluesky" and acc["limits"]["chars"] == 300 and acc["limits"]["images"] == 4 and "token" not in json.dumps(d).lower(), d)
    check("services needing owner apps are listed as not set up", {p["id"]: p["configured"] for p in d["providers"]}.get("facebook") is False, d["providers"])

    print("\n== posting rules")
    job = lambda text, **k: [dict({"account_id": aid, "text": text, "images": []}, **k)]
    n0 = MOCK.count("/xrpc/com.atproto.repo.createRecord")
    st, d = post_job(A, ta, "journal", j_pub, job("hello"), confirmed="0")
    check("without confirmation nothing is posted", st == 400 and MOCK.count("/xrpc/com.atproto.repo.createRecord") == n0, d)
    st, d = post_job(A, ta, "journal", j_priv, job("hello"))
    check("a private journal cannot be posted (409, make it public first)", st == 409 and "public" in d.get("error", "").lower(), d)
    st, d = post_job(A, ta, "journal", j_pend, job("hello"))
    check("a pending journal cannot be posted (409)", st == 409 and "review" in d.get("error", "").lower(), d)
    check("nothing reached the mock for those", MOCK.count("/xrpc/com.atproto.repo.createRecord") == n0)
    B, eb = new_user("b")
    tb = csrf(B)
    st, d = post_job(B, tb, "journal", j_pub, job("hello"))
    check("someone else cannot post my journal (404)", st == 404, d)
    st, d = api(B, tb, "disconnect", account_id=aid)
    check("someone else cannot disconnect my account", st == 404 and db("SELECT COUNT(*) FROM social_accounts WHERE account_id=%s", (aid,))[0][0] == 1, d)
    st, d = post_job(B, tb, "journal", j_pub, [{"account_id": aid, "text": "hello", "images": []}])
    check("someone else cannot use my connected account", st in (404, 409), d)
    st, d = post_job(A, ta, "journal", j_pub, job("é" * 301))
    r0 = d["results"][0] if d.get("results") else {}
    check("over 300 characters is refused for Bluesky with the count", st == 200 and not r0.get("ok") and "301" in r0.get("error", "") and "300" in r0.get("error", ""), d)
    check("nothing was sent for the over-long text", MOCK.count("/xrpc/com.atproto.repo.createRecord") == n0)
    st, d = post_job(A, ta, "journal", j_pub, job("x", images=[7]))
    check("a photo index that does not exist is refused", not d["results"][0]["ok"], d)
    st, d = post_job(A, ta, "journal", j_pub, [{"account_id": aid, "text": "hi", "images": [0, 1, 2, 3, 4]}])
    check("more than 4 photos is refused for Bluesky", not d["results"][0]["ok"], d)

    print("\n== a real post to the mock Bluesky")
    text = "Junagadh in January was lovely: the fort walls, the street food. See " + jurl + " #travel " + "é" * 20
    req = secrets.token_hex(8)
    st, d = post_job(A, ta, "journal", j_pub, job(text, images=[0]), alts={"0": "A red tiled photo of the fort"}, req=req)
    r0 = d["results"][0] if d.get("results") else {}
    check("post succeeds and returns a link to the live post", st == 200 and r0.get("ok") and r0["remote_url"].startswith("https://bsky.app/profile/tester.test/post/"), d)
    rec = MOCK.records[-1]["record"] if MOCK.records else {}
    check("record has the exact text the member confirmed", rec.get("text") == text, rec.get("text"))
    check("record is an app.bsky.feed.post with a createdAt", rec.get("$type") == "app.bsky.feed.post" and rec.get("createdAt", "").endswith("Z"))
    feats = [f["features"][0]["$type"] for f in rec.get("facets", [])]
    check("the link and the hashtag became facets", "app.bsky.richtext.facet#link" in feats and "app.bsky.richtext.facet#tag" in feats, rec.get("facets"))
    lf = [f for f in rec["facets"] if f["features"][0]["$type"] == "app.bsky.richtext.facet#link"][0]
    check("link facet byte range matches the URL in the UTF-8 text", text.encode()[lf["index"]["byteStart"]:lf["index"]["byteEnd"]].decode() == jurl, lf)
    emb = rec.get("embed", {})
    check("image embed carries the alt text", emb.get("$type") == "app.bsky.embed.images" and emb["images"][0]["alt"] == "A red tiled photo of the fort", emb)
    ctype, blob = MOCK.blobs[-1]
    im = Image.open(io.BytesIO(blob))
    check("uploaded blob is a JPEG under Bluesky's 1 MB limit", ctype == "image/jpeg" and len(blob) < 1000000 and im.format == "JPEG", (ctype, len(blob)))
    check("the uploaded photo has no EXIF/GPS/camera data", not dict(im.getexif()) and b"Exif" not in blob[:200] and b"SecretCameraMaker" not in blob)
    prow = db("SELECT status, remote_url, error, kind, item_id FROM social_posts WHERE eml=%s ORDER BY post_id DESC LIMIT 1", (ea,))[0]
    check("post history row says posted, with the URL", prow[0] == "posted" and prow[1].startswith("https://bsky.app/") and prow[3] == "journal" and prow[4] == str(j_pub), prow)
    aud = db("SELECT action, detail FROM audit_log WHERE actor=%s AND action='social_post' ORDER BY id DESC LIMIT 1", (ea,))
    check("an audit row was written (without tokens)", aud and "bluesky" in aud[0][1] and MOCK.jwt not in aud[0][1], aud)
    nrec = len(MOCK.records)
    st, d2 = post_job(A, ta, "journal", j_pub, job(text, images=[0]), alts={"0": "x"}, req=req)
    check("repeating the same request does not post twice", len(MOCK.records) == nrec and d2["results"][0].get("duplicate") and d2["results"][0]["ok"], d2)
    resp_all = json.dumps(d) + json.dumps(d2)
    check("responses never contain tokens", MOCK.jwt not in resp_all and "REFRESH_" not in resp_all)

    print("\n== session refresh (expired access token)")
    db("UPDATE social_accounts SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE account_id=%s", (aid,))
    MOCK.jwt = Mock._jwt()                       # the old access token is no longer valid on the server
    calls0 = MOCK.count("/xrpc/com.atproto.server.refreshSession")
    # the mock's refresh endpoint hands out the new token
    orig = H.do_POST
    def do_post(self):
        if urllib.parse.urlparse(self.path).path == "/xrpc/com.atproto.server.refreshSession":
            MOCK.calls.append(("POST", "/xrpc/com.atproto.server.refreshSession"))
            return self._send(200, {"accessJwt": MOCK.jwt, "refreshJwt": "REFRESH_" + MOCK.jwt[-6:], "handle": "tester.test", "did": "did:plc:mock123"})
        return orig(self)
    H.do_POST = do_post
    st, d = post_job(A, ta, "journal", j_pub, job("after refresh"))
    check("an expired token is refreshed automatically and the post goes through", d["results"][0]["ok"] and MOCK.count("/xrpc/com.atproto.server.refreshSession") == calls0 + 1, d)

    print("\n== rate limits")
    ok_count = 0; limited = None
    for i in range(14):
        st, d = post_job(A, ta, "journal", j_pub, job(f"rate test {i}"))
        r0 = d["results"][0]
        if r0["ok"]: ok_count += 1
        else: limited = r0["error"]; break
    check("the per-provider hourly limit (10) stops further posts with a plain message", limited and "posts an hour" in limited and ok_count <= 8, (ok_count, limited))
    db("DELETE FROM rate_limits WHERE bucket LIKE 'socpost:%%'")

    print("\n== Mastodon (mock): OAuth with state and PKCE")
    MOCK.max_chars = 300
    st, d = api(A, ta, "connect_start", provider="mastodon", instance="not a server!!")
    check("a bad server name is refused", st == 400, d)
    st, d = api(A, ta, "connect_start", provider="mastodon", instance="https://10.0.0.5")
    check("a private-network server is refused (SSRF guard)", st == 400, d)
    st, d = api(A, ta, "connect_start", provider="mastodon", instance=MOCK_URL)
    check("sign-in URL is built for the server with state, PKCE S256 and the strict redirect URI", st == 200 and d.get("ok"), d)
    q = urllib.parse.parse_qs(urllib.parse.urlparse(d["url"]).query)
    check("URL has response_type=code, state, code_challenge_method=S256, scope and redirect", q.get("response_type") == ["code"] and len(q["state"][0]) >= 30
          and q["code_challenge_method"] == ["S256"] and q["scope"] == ["write:statuses write:media"] and q["redirect_uri"] == [f"{BASE}/social-callback.php"], q)
    # a forged callback: wrong state
    r = A.get(f"{BASE}/social-callback.php", params={"code": "MOCKCODE", "state": "forged-" + secrets.token_hex(8)}, allow_redirects=False, timeout=60)
    check("callback with a wrong state is rejected and goes back to the profile", r.status_code == 302 and "profile.php" in r.headers.get("Location", ""), r.status_code)
    pg = A.get(f"{BASE}/profile.php", timeout=60).text
    check("the profile shows the plain-language error", "not valid or was already used" in pg)
    check("no Mastodon account was created by the forged callback", db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s AND provider='mastodon'", (ea,))[0][0] == 0)
    check("the token endpoint was never called for the forged callback", MOCK.mtoken_ok == 0)
    # the genuine flow: the 'browser' visits the mock authorize URL and is redirected back
    ar = requests.get(d["url"], allow_redirects=False, timeout=30)
    cb = ar.headers["Location"]
    cq = urllib.parse.parse_qs(urllib.parse.urlparse(cb).query)
    check("the server redirects back with the same state", cq["state"] == q["state"] and cb.startswith(f"{BASE}/social-callback.php"), cb)
    other = requests.Session()
    r = other.get(cb, allow_redirects=False, timeout=60)
    check("the callback needs the member's own session (logged-out is sent to log in)", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), r.headers.get("Location"))
    r = A.get(cb, allow_redirects=False, timeout=60)
    check("genuine callback connects the account", r.status_code == 302 and MOCK.mtoken_ok == 1, (r.status_code, MOCK.mtoken_ok))
    mrow = db("SELECT handle, token_enc, meta FROM social_accounts WHERE eml=%s AND provider='mastodon'", (ea,))
    check("Mastodon account stored with the instance limit read from /api/v2/instance", mrow and mrow[0][0].startswith("@tester@") and json.loads(mrow[0][2])["max_chars"] == 300 and json.loads(mrow[0][2])["max_media"] == 2, mrow)
    check("Mastodon token is encrypted at rest", mrow[0][1].startswith("v1:") and "MASTO_TOKEN_SECRET_1" not in mrow[0][1])
    r = A.get(cb, allow_redirects=False, timeout=60)
    check("the callback link cannot be replayed", MOCK.mtoken_ok == 1)
    st, d = api(A, ta, "targets")
    macc = [a for a in d["accounts"] if a["provider"] == "mastodon"][0]
    check("targets reports the live limit (300 chars, 2 photos, visibility choice)", macc["limits"]["chars"] == 300 and macc["limits"]["images"] == 2 and macc["limits"]["visibility"], macc)
    st, d = post_job(A, ta, "journal", j_pub, [{"account_id": macc["id"], "text": "m" * 301, "images": []}])
    check("over the instance limit is refused", not d["results"][0]["ok"] and "301" in d["results"][0]["error"], d)
    st, d = post_job(A, ta, "journal", j_pub, [{"account_id": macc["id"], "text": "Short note " + jurl, "images": [0], "visibility": "unlisted"}], alts={"0": "Fort wall in the morning"})
    r0 = d["results"][0]
    check("Mastodon post succeeds and links to the live post", r0["ok"] and "/@tester/s" in r0["remote_url"], d)
    ms = MOCK.mstatuses[-1]
    check("status has the exact text, unlisted visibility and the media id", ms["status"] == ["Short note " + jurl] and ms["visibility"] == ["unlisted"] and ms.get("media_ids[]") == ["m1"], ms)
    check("media upload carried the description and an EXIF-free JPEG", MOCK.mmedia[-1][0] == "Fort wall in the morning" and b"SecretCameraMaker" not in MOCK.mmedia[-1][1] and Image.open(io.BytesIO(MOCK.mmedia[-1][1])).format == "JPEG")

    print("\n== owner-app services (Tier 3)")
    st, d = api(A, ta, "connect_start", provider="facebook")
    check("Facebook says 'not set up' and starts nothing", st == 409 and "not set up" in d.get("error", ""), d)
    pg = A.get(f"{BASE}/profile.php", timeout=60).text
    check("profile shows Connected accounts with 'Not set up by the site owner'", 'id="connected-accounts"' in pg and "Not set up by the site owner" in pg and "@tester.test" in pg)
    check("profile history lists the posts", "Post history" in pg and "Posted" in pg)
    # admin saves credentials, then the OAuth start works without any network call
    db("UPDATE signup SET role='admin' WHERE eml=%s", (eb,))
    adm = csrf(B, "admin-settings.php?tab=social")
    page = B.get(f"{BASE}/admin-settings.php?tab=social", timeout=60).text
    check("admin has a Social apps tab with the redirect address", "Social apps" in page and f"{BASE}/social-callback.php" in page)
    B.post(f"{BASE}/admin-settings.php?tab=social", data={"csrf": adm, "form": "social", "id_facebook": "TESTAPPID12345", "secret_facebook": "TESTSECRETabcdef9876"}, timeout=60)
    page = B.get(f"{BASE}/admin-settings.php?tab=social", timeout=60).text
    check("saved credentials are never echoed back (only 'Set')", "TESTAPPID12345" not in page and "TESTSECRETabcdef9876" not in page and ">Set<" in page)
    raw = db("SELECT svalue, is_secret FROM settings WHERE skey='social_facebook_client_secret'")
    check("the secret is stored encrypted", raw and raw[0][1] == 1 and "TESTSECRET" not in raw[0][0], raw)
    st, d = api(A, ta, "connect_start", provider="facebook")
    fq = urllib.parse.urlparse(d.get("url", ""))
    fqq = urllib.parse.parse_qs(fq.query)
    check("with credentials, Facebook sign-in starts at facebook.com with client id, state and the exact redirect", st == 200 and fq.netloc == "www.facebook.com" and fqq["client_id"] == ["TESTAPPID12345"]
          and fqq["redirect_uri"] == [f"{BASE}/social-callback.php"] and len(fqq["state"][0]) >= 30 and "pages_manage_posts" in fqq["scope"][0], d)
    st, d = api(A, ta, "connect_start", provider="x")
    check("X is still 'not set up' (credentials are per service)", st == 409, d)
    st, d = api(B, tb, "connect_start", provider="mastodon", instance="http://127.0.0.1:1")
    check("a refused connection to a server gives a plain error, not a stack trace", st == 400 and "Traceback" not in json.dumps(d) and "curl" not in json.dumps(d).lower(), d)

    print("\n== camera roll photo (public copy) can be described and posted")
    tok_cam = ta
    up = A.post(f"{BASE}/camera-api.php", data={"action": "upload", "caption": "Camera test", "place": "Goa"}, files={"image": ("c.jpg", jpeg(gps=True, color=(30, 120, 180)), "image/jpeg")},
                headers={"X-CSRF-Token": tok_cam}, timeout=90).json()
    pid = up.get("photo", {}).get("id")
    check("a photo is saved to the private camera roll", bool(pid), up)
    d = A.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "upload", "path": "uploads/nope.jpg"}, timeout=30)
    check("an arbitrary path is not described", d.status_code == 404)
    cp = A.post(f"{BASE}/camera-api.php", data={"action": "use", "id": pid}, headers={"X-CSRF-Token": tok_cam}, timeout=90).json()
    check("the camera roll 'use' action makes a public copy", cp.get("ok") and cp["path"].startswith("uploads/"), cp)
    d = A.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "upload", "path": cp["path"]}, timeout=30).json()
    check("the copy is shareable by its owner, with its photo", d["ok"] and d["item"]["shareable"] and len(d["item"]["images"]) == 1 and d["can_post"], d)
    dnone = anon.get(f"{BASE}/social-api.php", params={"action": "describe", "kind": "upload", "path": cp["path"]}, timeout=30)
    check("strangers cannot describe someone's camera copy", dnone.status_code == 404)
    st, d = post_job(A, ta, "upload", 0, job("From my camera roll", images=[0]), alts={"0": "A blue test frame"}, path=cp["path"])
    check("posting the camera photo works (copy made with explicit confirmation)", d["results"][0]["ok"], d)
    check("the camera photo sent was EXIF-free", b"SecretCameraMaker" not in MOCK.blobs[-1][1])
    check("the private original is untouched in the roll", db("SELECT COUNT(*) FROM camera_photos WHERE photo_id=%s", (pid,))[0][0] == 1)

    print("\n== disconnect revokes and deletes the tokens")
    bs_before, rv_before = MOCK.bs_deleted, MOCK.mrevoked
    st, d = api(A, ta, "disconnect", account_id=aid)
    check("Bluesky disconnect ok, session deleted at the server, row gone", d.get("ok") and MOCK.bs_deleted == bs_before + 1 and db("SELECT COUNT(*) FROM social_accounts WHERE account_id=%s", (aid,))[0][0] == 0, d)
    st, d = api(A, ta, "disconnect", account_id=macc["id"])
    check("Mastodon disconnect revokes the token and deletes the row", d.get("ok") and MOCK.mrevoked == rv_before + 1 and db("SELECT COUNT(*) FROM social_accounts WHERE account_id=%s", (macc["id"],))[0][0] == 0, d)
    check("no encrypted token remains for the member", db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ea,))[0][0] == 0)
    st, d = post_job(A, ta, "journal", j_pub, job("after disconnect"))
    check("posting to a disconnected account is refused", st == 404, d)

    print("\n== deleting an account removes tokens and post rows")
    C, ec = new_user("c"); tc = csrf(C)
    st, d = api(C, tc, "connect_bluesky", handle="tester.test", app_password=APP_PW)
    check("third member connects", d.get("ok"), d)
    jc = journal(C, tc, "Junagadh third", "public")
    db("UPDATE db SET moderation_status='approved' WHERE entry_id=%s", (jc,))
    cid = db("SELECT account_id FROM social_accounts WHERE eml=%s", (ec,))[0][0]
    st, d = post_job(C, tc, "journal", jc, [{"account_id": cid, "text": "third", "images": []}])
    check("they post once", d["results"][0]["ok"], d)
    check("rows exist before deletion", db("SELECT COUNT(*) FROM social_posts WHERE eml=%s", (ec,))[0][0] == 1)
    bs_before = MOCK.bs_deleted
    C.post(f"{BASE}/profile.php", data={"csrf": tc, "DeleteAccount": "1", "confirm_pw": PW}, allow_redirects=False, timeout=90)
    check("account is gone", db("SELECT COUNT(*) FROM signup WHERE eml=%s", (ec,))[0][0] == 0)
    check("social accounts and post rows are gone", db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ec,))[0][0] == 0 and db("SELECT COUNT(*) FROM social_posts WHERE eml=%s", (ec,))[0][0] == 0)
    check("the session was revoked at the service on deletion", MOCK.bs_deleted == bs_before + 1)

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
                pg.goto(f"{BASE}/journal.php?id={j_pub}", wait_until="load")
                pg.click("[data-soc-open]")
                pg.wait_for_selector(".soc-panel")
                check("share sheet opens with links on a phone and does not scroll sideways", pg.locator(".soc-tile").count() >= 8 and pg.evaluate("document.documentElement.scrollWidth - innerWidth") <= 0)
                pg.keyboard.press("Escape")
                check("Escape closes the sheet", pg.locator(".soc-panel").count() == 0)
                ctx.close(); b.close()
        except Exception as e:
            print("  SKIP  browser check could not run:", str(e)[:120])

    srv.shutdown()
    purge()
    bad = [n for n, ok in results if not ok]
    print(f"\n{len(results) - len(bad)}/{len(results)} passed")
    if bad:
        print("FAILED:"); [print("  -", n) for n in bad]
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
