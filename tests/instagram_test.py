"""
JourneyAI - tests for the Instagram Kit and for direct Instagram posting.

  ml/venv/Scripts/python.exe tests/instagram_test.py        (site and MySQL running)

Part 1 (real, against this site): the Kit's JPEGs (exact sizes, valid JPEG, no EXIF/GPS), the carousel limit, privacy refusals,
caption and hashtag rules, and the short-lived public image link.
Part 2 (against a LOCAL MOCK of Instagram's login and Graph API, a thread in this script): authorize URL, state check, code exchange,
long-lived exchange, refresh, expired and revoked tokens, container creation, status polling, publish, carousel children, errors,
and refusal on a site that is not public https. No real Instagram account is ever contacted. The mock is accepted by the site only
because the site itself runs on localhost; a setting 'social_instagram_test_public' lets the posting code run as if the site were
public (honoured only on localhost).

Uses throw-away "soc-*@test.invalid" accounts and removes everything it created.
"""
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
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import requests
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import social_test as ST  # noqa: E402  (helpers: db, check, new_user, csrf, jpeg, purge)
from social_test import db, check, new_user, csrf, jpeg, results, BASE, ROOT  # noqa: E402

CID, CSEC = "IGAPPID123456", "igsecretXYZ123"


# --------------------------------------------------------------------------- the mock Instagram
class Mock:
    def __init__(self):
        self.calls = []            # (method, path, query dict, form dict, auth header)
        self.fetched = []          # (url, status, content-type, bytes)
        self.valid = set()         # access tokens the mock accepts for /me and graph calls
        self.long = ""
        self.containers = {}       # id -> dict(polls, fail, kind)
        self.published = []
        self.fail_media = None     # (status, body) for the next POST /media
        self.fail_status = False   # containers report ERROR
        self.fail_publish = None
        self.fail_refresh = False
        self.on_media = None       # callback(image_url) run on the first single /media call
        self.port = 0
        self.n = 0

    def paths(self, p):
        return [c for c in self.calls if c[1] == p]


MOCK = Mock()


class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass

    def _send(self, code, obj=None, headers=None):
        body = json.dumps(obj if obj is not None else {}).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _auth_ok(self):
        a = self.headers.get("Authorization", "")
        return a.startswith("Bearer ") and a[7:] in MOCK.valid

    def _graph_err(self, code=400, msg="Bad", gcode=100):
        return self._send(code, {"error": {"message": msg, "type": "OAuthException", "code": gcode}})

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = {k: v[0] for k, v in urllib.parse.parse_qs(u.query).items()}
        MOCK.calls.append(("GET", u.path, q, {}, self.headers.get("Authorization", "")))
        if u.path == "/oauth/authorize":
            if q.get("response_type") != "code" or not q.get("state"):
                return self._send(400, {"error": "bad request"})
            code = os.environ.get("IG_MOCK_CODE", "IGCODE")
            loc = q["redirect_uri"] + "?" + urllib.parse.urlencode({"code": MOCK.next_code, "state": MOCK.next_state or q["state"]})
            self.send_response(302); self.send_header("Location", loc); self.send_header("Content-Length", "0"); self.end_headers(); return
        if u.path == "/access_token":
            if q.get("grant_type") != "ig_exchange_token" or q.get("client_secret") != CSEC:
                return self._graph_err(400, "Invalid exchange", 190)
            tok = q.get("access_token", "")
            if tok == "SHORT_TOK_1": MOCK.long = "LONG_TOK_1_" + secrets.token_hex(4)
            elif tok == "SHORT_PERSONAL": MOCK.long = "LONG_PERSONAL"
            else: return self._graph_err(400, "Invalid token", 190)
            MOCK.valid.add(MOCK.long)
            return self._send(200, {"access_token": MOCK.long, "token_type": "bearer", "expires_in": 5184000})
        if u.path == "/refresh_access_token":
            if MOCK.fail_refresh:
                return self._graph_err(400, "Session has expired", 190)
            if q.get("grant_type") != "ig_refresh_token" or q.get("access_token") not in MOCK.valid:
                return self._graph_err(400, "Invalid token", 190)
            MOCK.long = "LONG_TOK_2_" + secrets.token_hex(4); MOCK.valid.add(MOCK.long)
            return self._send(200, {"access_token": MOCK.long, "token_type": "bearer", "expires_in": 5184000})
        if u.path == "/me":
            if not self._auth_ok(): return self._graph_err(401, "Invalid OAuth access token", 190)
            tok = self.headers["Authorization"][7:]
            return self._send(200, {"user_id": "17841400000000001", "id": "999", "username": "mock_ig", "account_type": "BUSINESS" if "PERSONAL" not in tok else "PERSONAL"})
        m = re.match(r"^/v23\.0/([^/]+)$", u.path)
        if m:
            if not self._auth_ok(): return self._graph_err(401, "Invalid OAuth access token", 190)
            cid = m.group(1)
            if "permalink" in q.get("fields", ""):
                return self._send(200, {"permalink": "https://www.instagram.com/p/MOCK%s/" % cid})
            c = MOCK.containers.get(cid)
            if not c: return self._graph_err(400, "Unknown container", 100)
            c["polls"] += 1
            if MOCK.fail_status: return self._send(200, {"status_code": "ERROR", "status": "Error: Media upload has failed with error code 2207003"})
            return self._send(200, {"status_code": "IN_PROGRESS" if c["polls"] == 1 else "FINISHED"})
        return self._send(404, {"error": "nope"})

    def do_POST(self):
        u = urllib.parse.urlparse(self.path)
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n).decode() if n else ""
        f = {k: v[0] for k, v in urllib.parse.parse_qs(body).items()}
        MOCK.calls.append(("POST", u.path, {}, f, self.headers.get("Authorization", "")))
        if u.path == "/oauth/access_token":
            if f.get("client_id") != CID or f.get("client_secret") != CSEC or f.get("grant_type") != "authorization_code" or not f.get("redirect_uri", "").endswith("/social-callback.php"):
                return self._send(400, {"error_type": "OAuthException", "code": 400, "error_message": "Invalid client or redirect"})
            code = f.get("code")
            perms = "instagram_business_basic,instagram_business_content_publish"
            if code == "IGCODE": tok = "SHORT_TOK_1"
            elif code == "PERSONALCODE": tok = "SHORT_PERSONAL"
            elif code == "NOPERMCODE": tok = "SHORT_TOK_1"; perms = "instagram_business_basic"
            else: return self._send(400, {"error_type": "OAuthException", "code": 400, "error_message": "Invalid authorization code"})
            return self._send(200, {"data": [{"access_token": tok, "user_id": "17841400000000001", "permissions": perms}]})
        m = re.match(r"^/v23\.0/([^/]+)/(media|media_publish)$", u.path)
        if m:
            if not self._auth_ok(): return self._graph_err(401, "Invalid OAuth access token", 190)
            if m.group(2) == "media":
                if MOCK.fail_media:
                    st, b = MOCK.fail_media; MOCK.fail_media = None
                    return self._send(st, b)
                if f.get("media_type") == "CAROUSEL":
                    kids = f.get("children", "").split(",")
                    if not (2 <= len(kids) <= 10) or any(k not in MOCK.containers or MOCK.containers[k]["kind"] != "child" for k in kids):
                        return self._graph_err(400, "Invalid carousel children", 100)
                    MOCK.n += 1; cid = "car%d" % MOCK.n
                    MOCK.containers[cid] = {"polls": 0, "kind": "carousel", "caption": f.get("caption", ""), "kids": kids}
                    return self._send(200, {"id": cid})
                url = f.get("image_url", "")
                if not url: return self._graph_err(400, "image_url required", 100)
                if MOCK.on_media and f.get("is_carousel_item") != "true":
                    cb, MOCK.on_media = MOCK.on_media, None
                    cb(url)
                try:
                    r = requests.get(url, timeout=30)
                    MOCK.fetched.append((url, r.status_code, r.headers.get("Content-Type", ""), r.content))
                    if r.status_code != 200: return self._graph_err(400, "Only photo or video can be accepted as media type.", 9004)
                except Exception:
                    return self._graph_err(400, "Could not fetch the image", 9004)
                MOCK.n += 1; cid = "med%d" % MOCK.n
                MOCK.containers[cid] = {"polls": 0, "kind": "child" if f.get("is_carousel_item") == "true" else "single", "caption": f.get("caption", ""), "alt": f.get("alt_text", "")}
                return self._send(200, {"id": cid})
            c = MOCK.containers.get(f.get("creation_id", ""))
            if MOCK.fail_publish:
                st, b = MOCK.fail_publish; MOCK.fail_publish = None
                return self._send(st, b)
            if not c or c["polls"] < 2 or c["kind"] == "child": return self._graph_err(400, "Media ID is not available", 9007)
            MOCK.n += 1; mid = "pub%d" % MOCK.n
            MOCK.published.append((mid, f["creation_id"]))
            return self._send(200, {"id": mid})
        return self._send(404, {"error": "nope"})


MOCK.next_code = "IGCODE"
MOCK.next_state = None


def start_mock():
    srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
    MOCK.port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


# --------------------------------------------------------------------------- helpers
def api(s, tok, action, **data):
    data["action"] = action
    r = s.post(f"{BASE}/social-api.php", data=data, headers={"X-CSRF-Token": tok, "Accept": "application/json"}, timeout=120)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {"raw": r.text[:300]}


def kit(s, **q):
    return s.get(f"{BASE}/instagram-kit.php", params=q, timeout=120)


def photo_journal(s, tok, title, city, n_photos, desc, visibility="public"):
    files = [("media[]", (f"p{i}.jpg", jpeg(color=(40 + i * 15 % 200, 120, 80 + i * 9 % 150), size=(900, 700), gps=True), "image/jpeg")) for i in range(n_photos)]
    r = s.post(f"{BASE}/new-entry.php", data={"csrf": tok, "title": title, "coun": "India", "city": city, "dv": "2026-01-02", "dr": "2026-01-05", "desc": desc,
                                              "visibility": visibility, "hotel": "2400", "dinner": "1800", "post_media": "1", "ptv": "old fort, clock tower"},
               files=files, allow_redirects=False, timeout=180)
    m = re.search(r"id=(\d+)", r.headers.get("Location", ""))
    jid = int(m.group(1)) if m and m.group(1) != "0" else None
    if jid:
        if visibility == "public":
            db("UPDATE db SET moderation_status='approved', visibility='public' WHERE entry_id=%s", (jid,))
            db("UPDATE media SET moderation_status='approved', is_public=1 WHERE entry_id=%s", (jid,))
    return jid


def setting(k, v, secret=0):
    db("INSERT INTO settings (skey, svalue, is_secret) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", (k, v, secret))


def cleanup():
    ST.purge()
    db("DELETE FROM ig_media_queue")
    qd = os.path.join(ROOT, "private", "ig-queue")
    if os.path.isdir(qd):
        for f in os.listdir(qd):
            try: os.remove(os.path.join(qd, f))
            except OSError: pass
    db("DELETE FROM hashtags WHERE tag='girnar'")
    db("DELETE FROM rate_limits")


def check_jpeg(name, data, size):
    try:
        im = Image.open(io.BytesIO(data)); im.load()
    except Exception as e:
        return check(name, False, e)
    q0 = (im.quantization or {}).get(0, [99])[0]
    ok = im.format == "JPEG" and im.size == size and im.mode == "RGB" and not dict(im.getexif()) and b"Exif" not in data[:64] and b"SecretCameraMaker" not in data \
        and "icc_profile" not in im.info and q0 <= 4
    return check(name, ok, (im.format, im.size, im.mode, dict(im.getexif()), q0))


def main():
    cleanup()
    srv = start_mock()
    MOCK_URL = f"http://127.0.0.1:{MOCK.port}"
    anon = requests.Session()

    print("\n== set up: a trusted member, a public journal with photos, a 12-photo journal, a private one, a pending one")
    A, ea = new_user("igk"); ta = csrf(A)
    B, eb = new_user("igkb"); tb = csrf(B)
    j_pub = photo_journal(A, ta, "Junagadh weekend by train", "Junagadh", 4, ST.STORY)
    j_big = photo_journal(A, ta, "Junagadh twelve frames", "Junagadh", 12, ST.STORY)
    j_priv = photo_journal(A, ta, "Junagadh private notes", "Junagadh", 2, ST.STORY, "private")
    j_pend = photo_journal(A, ta, "Junagadh pending piece", "Junagadh", 2, ST.STORY)
    db("UPDATE db SET moderation_status='pending' WHERE entry_id=%s", (j_pend,))
    db("UPDATE media SET moderation_status='pending' WHERE entry_id=%s", (j_pend,))
    check("journals created", all([j_pub, j_big, j_priv, j_pend]), (j_pub, j_big, j_priv, j_pend))
    nmedia = db("SELECT COUNT(*) FROM media WHERE entry_id=%s AND is_public=1 AND moderation_status='approved'", (j_pub,))[0][0]
    check("the public journal has its photos", nmedia >= 3, nmedia)
    db("INSERT INTO hashtags (tag, uses) VALUES ('girnar', 5) ON DUPLICATE KEY UPDATE uses=uses")

    print("\n== the Kit: meta, sizes, JPEG, no EXIF")
    r = kit(A, action="meta", kind="journal", id=j_pub); m = r.json()
    check("meta works for the owner of a public approved journal", r.status_code == 200 and m.get("ok"), r.text[:200])
    fm = {f["id"]: f for f in m["formats"]}
    check("four formats with the right sizes", [(k, fm[k]["w"], fm[k]["h"]) for k in ("feed", "square", "story", "carousel")] == [("feed", 1080, 1350), ("square", 1080, 1080), ("story", 1080, 1920), ("carousel", 1080, 1350)])
    sizes = {"feed": (1080, 1350), "square": (1080, 1080), "story": (1080, 1920), "carousel": (1080, 1350)}
    for fid, f in fm.items():
        for t in f["templates"]:
            n = f["count"] if fid == "carousel" else 1
            for i in range(min(n, 3)):
                rr = kit(A, action="slide", kind="journal", id=j_pub, format=fid, template=t, n=i)
                check_jpeg(f"{fid}/{t}/{i} is a {sizes[fid][0]}x{sizes[fid][1]} sRGB JPEG, quality 90, no EXIF or GPS",
                           rr.content if rr.headers.get("Content-Type", "").startswith("image/jpeg") else b"", sizes[fid])
    rr = kit(A, action="slide", kind="journal", id=j_pub, format="feed", template="journey")
    check("the journey card (place, days, cost from real data) renders", rr.status_code == 200 and rr.headers["Content-Type"] == "image/jpeg")
    rr = kit(A, action="slide", kind="journal", id=j_pub, format="story", template="quote")
    check("the quote card renders from the journal's own text", rr.status_code == 200 and rr.headers["Content-Type"] == "image/jpeg")
    check("the response is not cacheable by others", "no-store" in kit(A, action="slide", kind="journal", id=j_pub, format="feed", template="photo").headers.get("Cache-Control", ""))

    print("\n== carousel: cover, photos, closing slide, never more than 10")
    car = fm["carousel"]
    check("carousel for 4 photos has cover + photos + closing slide, at most 10", 3 <= car["count"] <= 10, car["count"])
    mb = kit(A, action="meta", kind="journal", id=j_big).json()
    cb = {f["id"]: f for f in mb["formats"]}["carousel"]
    check("a 12-photo journal is capped at 10 slides", cb["count"] == 10, cb["count"])
    rr = kit(A, action="slide", kind="journal", id=j_big, format="carousel", template="photo", n=99)
    check("an out-of-range slide number is clamped, not an error", rr.status_code == 200)
    check("every slide has alt text", len(cb["alts"]) == 10 and all(len(a) > 5 for a in cb["alts"]))

    print("\n== privacy: private, pending, other people's and anonymous are refused")
    check("private journal refused with a plain reason", kit(A, action="meta", kind="journal", id=j_priv).status_code == 404 and "public" in kit(A, action="meta", kind="journal", id=j_priv).json()["error"].lower())
    rp = kit(A, action="slide", kind="journal", id=j_priv, format="feed", template="photo")
    check("no image is drawn for a private journal", rp.status_code == 404 and not rp.headers.get("Content-Type", "").startswith("image/"))
    check("pending journal refused", kit(A, action="meta", kind="journal", id=j_pend).status_code == 404 and "review" in kit(A, action="meta", kind="journal", id=j_pend).json()["error"].lower())
    check("another member cannot use someone's public journal", kit(B, action="meta", kind="journal", id=j_pub).status_code == 404)
    check("anonymous gets 401", kit(anon, action="meta", kind="journal", id=j_pub).status_code == 401)
    check("a camera path that was never made public is refused", kit(A, action="meta", kind="upload", id=0, path="uploads/0993caffb8c0188a.jpg").status_code == 404)
    check("unknown kind refused", kit(A, action="meta", kind="user", id=1).status_code == 400)

    print("\n== caption, hook, hashtags: real data only")
    cap = m["caption"]
    check("caption is at most 2200 characters", len(cap) <= 2200, len(cap))
    check("the first line (the hook) fits Instagram's 125 characters", len(cap.split("\n")[0]) <= 125, cap.split("\n")[0])
    check("the hook is a real sentence from the journal", m["hook"].rstrip(".") in ST.STORY or ST.STORY.startswith(m["hook"].rstrip(".")), m["hook"])
    tags = [t["tag"] for t in m["hashtags"]]
    check("3 to 5 hashtags", 3 <= len(tags) <= 5, tags)
    check("hashtags come from the real destination and text", "Junagadh" in tags and "India" in tags and "Girnar" in tags, tags)
    check("the hashtags are in the caption and nothing else is invented", all(f"#{t}" in cap for t in tags) and cap.count("#") == len(tags))
    longj = photo_journal(A, ta, "Junagadh long first sentence", "Junagadh", 1, "x" * 0 + ("We walked through the old city of Junagadh early in the morning and kept going until the light changed over the hills " * 4) + ". Then we slept.")
    ml = kit(A, action="meta", kind="journal", id=longj).json()
    check("a very long first sentence is trimmed to the hook limit", ml["ok"] and len(ml["caption"].split("\n")[0]) <= 125 and len(ml["caption"]) <= 2200, ml.get("caption", "")[:200])
    check("an item with no text still gets a caption from its title", len(kit(A, action="meta", kind="journal", id=j_pub).json()["caption"]) > 10)

    print("\n== camera roll photos: only after the explicit public copy")
    up = A.post(f"{BASE}/camera-api.php", data={"action": "upload", "caption": "Dawn over Girnar", "place": "Junagadh"}, files={"image": ("c.jpg", jpeg(gps=True, color=(30, 120, 180)), "image/jpeg")},
                headers={"X-CSRF-Token": ta}, timeout=90).json()
    pid = up.get("photo", {}).get("id")
    priv = db("SELECT filepath FROM camera_photos WHERE photo_id=%s", (pid,))
    check("the private camera original cannot be used by the Kit", bool(priv) and kit(A, action="meta", kind="upload", id=0, path=priv[0][0]).status_code == 404)
    cp = A.post(f"{BASE}/camera-api.php", data={"action": "use", "id": pid}, headers={"X-CSRF-Token": ta}, timeout=90).json()
    mu = kit(A, action="meta", kind="upload", id=0, path=cp["path"]).json()
    check("after the public copy is made, the Kit works on the copy", mu.get("ok") and mu["photos"] == 1 and "Dawn over Girnar" in mu["caption"], mu)
    ru = kit(A, action="slide", kind="upload", id=0, path=cp["path"], format="feed", template="polaroid")
    check_jpeg("camera copy polaroid is a 1080x1350 JPEG without EXIF", ru.content, (1080, 1350))
    check("another member cannot use the copy", kit(B, action="meta", kind="upload", id=0, path=cp["path"]).status_code == 404)

    print("\n== the public image link: only queued images, expires, no listing")
    for bad in ("", "abc", "../uploads/0993caffb8c0188a.jpg", secrets.token_urlsafe(32)[:43], "A" * 43):
        rr = anon.get(f"{BASE}/ig-media.php", params={"t": bad}, timeout=30)
        check(f"ig-media refuses token {bad[:12]!r}", rr.status_code == 404 and not rr.headers.get("Content-Type", "").startswith("image/"))
    check("ig-media does not accept POST", anon.post(f"{BASE}/ig-media.php", data={"t": "x"}, timeout=30).status_code == 405)
    check("the queue folder is not served by the web server", anon.get(f"{BASE}/private/ig-queue/", timeout=30).status_code in (401, 403, 404) and anon.get(f"{BASE}/private/social.key", timeout=30).status_code in (401, 403, 404))

    print("\n== Instagram login against the mock (no real account)")
    st, d = api(A, ta, "connect_start", provider="instagram")
    check("Instagram says 'not set up' until the owner saves the app", st == 409 and "not set up" in d.get("error", ""), d)
    pg = A.get(f"{BASE}/profile.php", timeout=60).text
    li = re.search(r'<li class="soc-p" data-provider="instagram">(.*?)</li>', pg, re.S)
    check("profile shows Instagram first with 'Not set up by the site owner'", bool(li) and "Not set up by the site owner" in li.group(1))
    # the owner saves the Instagram app; the mock's addresses are test-only settings
    db("UPDATE signup SET role='admin' WHERE eml=%s", (eb,))
    adm = csrf(B, "admin-settings.php?tab=social")
    page = B.get(f"{BASE}/admin-settings.php?tab=social", timeout=60).text
    check("admin guidance: Business/Creator, Instagram Tester, no App Review for own account, permissions, public https, localhost unverified",
          all(x in page for x in ["Business or Creator", "Instagram Tester", "instagram_business_content_publish", "instagram_business_basic", "public https", "unverified", "API setup with Instagram login"]))
    B.post(f"{BASE}/admin-settings.php?tab=social", data={"csrf": adm, "form": "social", "id_instagram": CID, "secret_instagram": CSEC}, timeout=60)
    page = B.get(f"{BASE}/admin-settings.php?tab=social", timeout=60).text
    check("the Instagram secret is never echoed back", CSEC not in page and CID not in page)
    for k, v in [("social_instagram_auth_base", MOCK_URL), ("social_instagram_api_base", MOCK_URL), ("social_instagram_graph_base", MOCK_URL), ("social_instagram_poll_ms", "30")]:
        setting(k, v)
    pg = A.get(f"{BASE}/profile.php", timeout=60).text
    li = re.search(r'<li class="soc-p" data-provider="instagram">(.*?)</li>', pg, re.S).group(1)
    check("profile now shows a prominent 'Log in with Instagram' button", "Log in with Instagram" in li and 'data-soc-connect="instagram"' in li)
    check("there is no password field anywhere in the Instagram flow", 'type="password"' not in li and 'name="password"' not in li and "app_password" not in li and "never sees or stores your Instagram password" in li)

    st, d = api(A, ta, "connect_start", provider="instagram")
    au = urllib.parse.urlparse(d.get("url", "")); aq = urllib.parse.parse_qs(au.query)
    check("authorize URL carries client_id, exact redirect, response_type=code, both scopes and a one-time state",
          st == 200 and au.path == "/oauth/authorize" and aq["client_id"] == [CID] and aq["redirect_uri"] == [f"{BASE}/social-callback.php"] and aq["response_type"] == ["code"]
          and aq["scope"] == ["instagram_business_basic,instagram_business_content_publish"] and len(aq["state"][0]) >= 30, d)
    check("the authorize request asks for no password or username", not any(k in aq for k in ("password", "username", "login", "user")))
    state1 = aq["state"][0]

    # 1) a forged state is refused and nothing is connected
    MOCK.next_state = "forged-state-value"; MOCK.next_code = "IGCODE"
    rr = A.get(d["url"], allow_redirects=True, timeout=60)
    check("a forged or unknown state is refused with a plain message", "not valid or was already used" in rr.text, re.sub(r"\s+", " ", rr.text)[:100])
    check("no account and no token exchange happened", db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s AND provider='instagram'", (ea,))[0][0] == 0 and not MOCK.paths("/oauth/access_token"))
    MOCK.next_state = None
    # 2) a bad code gives a plain error
    st, d2 = api(A, ta, "connect_start", provider="instagram"); MOCK.next_code = "BADCODE"
    rr = A.get(d2["url"], allow_redirects=True, timeout=60)
    check("an invalid code gives a plain error and saves nothing", "Invalid authorization code" in rr.text and db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ea,))[0][0] == 0)
    # 3) a personal account is explained
    st, d2 = api(A, ta, "connect_start", provider="instagram"); MOCK.next_code = "PERSONALCODE"
    rr = A.get(d2["url"], allow_redirects=True, timeout=60)
    check("a personal Instagram account is refused with the fix", "personal account" in rr.text and "Business or Creator" in rr.text and db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ea,))[0][0] == 0)
    # 4) declined posting permission
    st, d2 = api(A, ta, "connect_start", provider="instagram"); MOCK.next_code = "NOPERMCODE"
    rr = A.get(d2["url"], allow_redirects=True, timeout=60)
    check("missing publish permission is refused with the fix", "permission was not granted" in rr.text and db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ea,))[0][0] == 0)
    # 5) the real success path
    calls_before = len(MOCK.calls)
    st, d2 = api(A, ta, "connect_start", provider="instagram"); MOCK.next_code = "IGCODE"
    rr = A.get(d2["url"], allow_redirects=True, timeout=60)
    row = db("SELECT account_id, handle, token_enc, refresh_enc, expires_at, status, scopes FROM social_accounts WHERE eml=%s AND provider='instagram'", (ea,))
    check("login succeeds: connected as @mock_ig", bool(row) and row[0][1] == "@mock_ig" and "Connected to Instagram" in rr.text, rr.text[:100])
    new = MOCK.calls[calls_before:]
    tok_post = [c for c in new if c[1] == "/oauth/access_token" and c[0] == "POST"]
    check("code exchange: POST with client_id, client_secret, grant_type, exact redirect_uri and the code",
          len(tok_post) == 1 and tok_post[0][3].get("grant_type") == "authorization_code" and tok_post[0][3].get("code") == "IGCODE" and tok_post[0][3].get("redirect_uri") == f"{BASE}/social-callback.php", tok_post)
    ex = [c for c in new if c[1] == "/access_token"]
    check("long-lived exchange: ig_exchange_token with the short-lived token", len(ex) == 1 and ex[0][2].get("grant_type") == "ig_exchange_token" and ex[0][2].get("access_token") == "SHORT_TOK_1")
    me = [c for c in new if c[1] == "/me"]
    check("profile read with the long-lived token (fields user_id,username,account_type)", len(me) == 1 and me[0][4] == "Bearer " + MOCK.long and "account_type" in me[0][2].get("fields", ""))
    aid = row[0][0]
    check("the token is stored encrypted and expires in about 60 days", row[0][2].startswith("v1:") and MOCK.long not in row[0][2] and "SHORT_TOK" not in row[0][2]
          and 59 * 86400 < (row[0][4].timestamp() - time.time()) < 61 * 86400 and row[0][3] is None and row[0][5] == "active", row)
    prof = A.get(f"{BASE}/profile.php", timeout=60).text
    check("profile shows the account, the expiry and Test connection", "@mock_ig" in prof and "Sign-in valid until" in prof and "data-soc-test" in prof and MOCK.long not in prof)
    st, d3 = api(A, ta, "test_connection", account_id=aid)
    check("Test connection reports who Instagram says the sign-in is", d3.get("ok") and "@mock_ig" in d3["message"] and "Business" in d3["message"] and "not online over https" in d3["message"], d3)
    st, d3 = api(B, tb, "test_connection", account_id=aid)
    check("another member cannot test someone else's account", st == 404, d3)

    print("\n== direct posting refuses on a site that is not public https")
    n_media = len(MOCK.paths("/v23.0/17841400000000001/media"))
    jobs = [{"account_id": aid, "text": "Hello from the test", "images": [0]}]
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs, alts={"0": "alt"})
    check("posting from localhost is refused with the plain explanation", st == 200 and d["results"][0]["error"] and "online over https" in d["results"][0]["error"], d)
    check("and nothing was sent to Instagram", len(MOCK.paths("/v23.0/17841400000000001/media")) == n_media)
    ks = kit(A, action="meta", kind="journal", id=j_pub).json()
    check("the Kit modal explains direct posting needs a public https site and a business account", ks["direct"]["state"] == "not_public" and "online over https" in ks["direct"]["message"], ks["direct"])

    setting("social_instagram_test_public", "1")      # test only: lets the posting code run as if the site were public
    ks = kit(A, action="meta", kind="journal", id=j_pub).json()
    check("with a public site the Kit offers direct posting to the connected account", ks["direct"]["state"] == "ready" and ks["direct"]["handle"] == "@mock_ig", ks["direct"])

    print("\n== single photo: container, status polling, publish, image link")
    seen = {}

    def on_media(url):
        tok = url.split("t=")[1]
        r1 = requests.get(url, timeout=30); seen["ok"] = (r1.status_code, r1.headers.get("Content-Type"), r1.headers.get("Content-Length"), r1.headers.get("X-Robots-Tag"), len(r1.content))
        seen["head"] = requests.head(url, timeout=30).status_code
        seen["wrong"] = requests.get(url[:-1] + ("A" if url[-1] != "A" else "B"), timeout=30).status_code
        seen["row"] = db("SELECT COUNT(*), MAX(hits) FROM ig_media_queue WHERE token_hash=%s", (hashlib.sha256(tok.encode()).hexdigest(),))[0]
        db("UPDATE ig_media_queue SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash=%s", (hashlib.sha256(tok.encode()).hexdigest(),))
        seen["expired"] = requests.get(url, timeout=30).status_code
        db("UPDATE ig_media_queue SET expires_at = NOW() + INTERVAL 15 MINUTE WHERE token_hash=%s", (hashlib.sha256(tok.encode()).hexdigest(),))
        seen["url"] = url
    MOCK.on_media = on_media
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs, alts={"0": "Fort wall at dawn"})
    r0 = d["results"][0]
    check("the post succeeds and returns the Instagram permalink", r0["ok"] and r0["remote_url"].startswith("https://www.instagram.com/p/MOCK"), d)
    media_calls = [c for c in MOCK.paths("/v23.0/17841400000000001/media")][n_media:]
    f0 = media_calls[0][3]
    check("container created with image_url, caption and alt_text", f0["image_url"].startswith(f"{BASE}/ig-media.php?t=") and f0["caption"] == "Hello from the test" and f0["alt_text"] == "Fort wall at dawn", f0)
    check("calls carry the Bearer token", all(c[4] == "Bearer " + MOCK.long for c in media_calls))
    check("the status was polled until FINISHED, then media_publish was called once", MOCK.containers["med1"]["polls"] >= 2 and len(MOCK.paths("/v23.0/17841400000000001/media_publish")) == 1 and len(MOCK.published) == 1)
    fetched = [x for x in MOCK.fetched if x[0] == seen.get("url")]
    check("Instagram's fetch got a JPEG with correct Content-Type, no EXIF", fetched and fetched[0][1] == 200 and fetched[0][2] == "image/jpeg" and b"SecretCameraMaker" not in fetched[0][3] and Image.open(io.BytesIO(fetched[0][3])).format == "JPEG" and not dict(Image.open(io.BytesIO(fetched[0][3])).getexif()))
    check("the link serves image/jpeg with length and noindex while valid", seen["ok"][0] == 200 and seen["ok"][1] == "image/jpeg" and int(seen["ok"][2]) == seen["ok"][4] and "noindex" in (seen["ok"][3] or ""), seen.get("ok"))
    check("HEAD works, a wrong token gets 404", seen["head"] == 200 and seen["wrong"] == 404, seen)
    check("an expired token gets 404 (same as an unknown one)", seen["expired"] == 404, seen)
    check("after the post the link is gone: row deleted and 404", db("SELECT COUNT(*) FROM ig_media_queue WHERE eml=%s", (ea,))[0][0] == 0 and requests.get(seen["url"], timeout=30).status_code == 404)
    check("no staged files are left behind", not os.path.isdir(os.path.join(ROOT, "private", "ig-queue")) or not os.listdir(os.path.join(ROOT, "private", "ig-queue")))
    check("the post is in the history", db("SELECT status FROM social_posts WHERE eml=%s AND provider='instagram' ORDER BY post_id DESC LIMIT 1", (ea,))[0][0] == "posted")

    print("\n== Kit carousel posted through the pipeline (children, CAROUSEL container, 10 max)")
    MOCK.calls_start = len(MOCK.calls); fetched_before = len(MOCK.fetched)
    job = [{"account_id": aid, "text": "A carousel", "images": [], "kit": {"format": "carousel", "template": "photo", "n": 0}}]
    st, d = ST.post_job(A, ta, "journal", j_big, job)
    r1 = d["results"][0]
    check("carousel posted", r1["ok"], d)
    new = MOCK.calls[MOCK.calls_start:]
    kids = [c for c in new if c[1].endswith("/media") and c[3].get("is_carousel_item") == "true"]
    cont = [c for c in new if c[1].endswith("/media") and c[3].get("media_type") == "CAROUSEL"]
    check("one child container per slide, at most 10", 2 <= len(kids) <= 10 and len(kids) == 10, len(kids))
    check("the CAROUSEL container lists the children and carries the caption", len(cont) == 1 and len(cont[0][3]["children"].split(",")) == len(kids) and cont[0][3]["caption"] == "A carousel")
    imgs = MOCK.fetched[fetched_before:]
    check("every slide Instagram fetched is a 1080x1350 JPEG with no EXIF", len(imgs) == len(kids) and all(Image.open(io.BytesIO(i[3])).size == (1080, 1350) and not dict(Image.open(io.BytesIO(i[3])).getexif()) for i in imgs))
    st, d = ST.post_job(A, ta, "journal", j_big, [{"account_id": aid, "text": "story", "images": [], "kit": {"format": "story", "template": "photo", "n": 0}}])
    check("Stories are not posted from the site", "Stories" in d["results"][0]["error"], d)
    st, d = ST.post_job(A, ta, "journal", j_priv, [{"account_id": aid, "text": "x", "images": [0]}])
    check("a private journal still cannot be posted (server re-checks)", st == 409, (st, d))
    st, d = ST.post_job(A, ta, "journal", j_pub, [{"account_id": aid, "text": "x" * 2300, "images": [0]}])
    check("over-long caption is refused before anything is sent", "2200" in d["results"][0]["error"], d)
    st, d = ST.post_job(A, ta, "journal", j_pub, [{"account_id": aid, "text": " ".join("#t%d" % i for i in range(31)), "images": [0]}])
    check("more than 30 hashtags refused", "30 hashtags" in d["results"][0]["error"], d)

    print("\n== errors from Instagram are explained plainly")
    db("DELETE FROM rate_limits")
    MOCK.fail_media = (400, {"error": {"message": "Only photo or video can be accepted as media type.", "type": "OAuthException", "code": 9004}})
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a refused container gives a plain error", not d["results"][0]["ok"] and "refused" in d["results"][0]["error"] and "access_token" not in d["results"][0]["error"], d)
    MOCK.fail_status = True
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a container that ends in ERROR says Instagram could not process the image", not d["results"][0]["ok"] and "could not process" in d["results"][0]["error"], d)
    MOCK.fail_status = False
    MOCK.fail_publish = (400, {"error": {"message": "Rate limit", "type": "OAuthException", "code": 4}})
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a rate limit from Instagram is explained", "too many requests" in d["results"][0]["error"], d)
    check("failed posts leave no queued images", db("SELECT COUNT(*) FROM ig_media_queue WHERE eml=%s", (ea,))[0][0] == 0)
    db("DELETE FROM rate_limits")
    for _ in range(25): db("INSERT INTO rate_limits (bucket) VALUES (%s)", (f"igpub:{aid}",))
    n_before = len(MOCK.paths("/v23.0/17841400000000001/media"))
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("the 25 posts per 24 hours cap is enforced locally", "25 posts" in d["results"][0]["error"] and len(MOCK.paths("/v23.0/17841400000000001/media")) == n_before, d)
    db("DELETE FROM rate_limits")

    print("\n== token life: refresh, expiry, revoked")
    old_long = MOCK.long
    db("UPDATE social_accounts SET expires_at = NOW() + INTERVAL 40 DAY WHERE account_id=%s", (aid,))
    n_ref = len(MOCK.paths("/refresh_access_token"))
    ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a token with 40 days left is not refreshed", len(MOCK.paths("/refresh_access_token")) == n_ref)
    db("UPDATE social_accounts SET expires_at = NOW() + INTERVAL 5 DAY WHERE account_id=%s", (aid,))
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    ref = MOCK.paths("/refresh_access_token")[n_ref:]
    newrow = db("SELECT token_enc, expires_at FROM social_accounts WHERE account_id=%s", (aid,))[0]
    check("a token within 10 days of expiry is refreshed (ig_refresh_token with the old token)", len(ref) == 1 and ref[0][2].get("grant_type") == "ig_refresh_token" and ref[0][2].get("access_token") == old_long, ref)
    check("the new token and a new 60 day expiry are saved, and the post used the new token", 59 * 86400 < newrow[1].timestamp() - time.time() < 61 * 86400 and MOCK.paths("/v23.0/17841400000000001/media")[-1][4] == "Bearer " + MOCK.long and d["results"][0]["ok"], d)
    db("UPDATE social_accounts SET expires_at = NOW() + INTERVAL 59 DAY + INTERVAL 23 HOUR WHERE account_id=%s", (aid,))
    n_ref = len(MOCK.paths("/refresh_access_token")); ST.post_job(A, ta, "journal", j_pub, jobs)
    check("no refresh is attempted for a token younger than 24 hours", len(MOCK.paths("/refresh_access_token")) == n_ref)
    db("UPDATE social_accounts SET expires_at = NOW() + INTERVAL 3 DAY WHERE account_id=%s", (aid,))
    MOCK.fail_refresh = True
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a refresh Instagram rejects marks the account 'expired' and asks to log in again", not d["results"][0]["ok"] and "expired" in d["results"][0]["error"] and db("SELECT status FROM social_accounts WHERE account_id=%s", (aid,))[0][0] == "expired", d)
    MOCK.fail_refresh = False
    db("UPDATE social_accounts SET status='active', expires_at = NOW() - INTERVAL 1 DAY WHERE account_id=%s", (aid,))
    n_media = len(MOCK.paths("/v23.0/17841400000000001/media"))
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("an expired token is not used: account marked expired, nothing sent", not d["results"][0]["ok"] and "expired" in d["results"][0]["error"] and len(MOCK.paths("/v23.0/17841400000000001/media")) == n_media and db("SELECT status FROM social_accounts WHERE account_id=%s", (aid,))[0][0] == "expired")
    st, d = api(A, ta, "test_connection", account_id=aid)
    check("Test connection on an expired sign-in says to log in again", not d["ok"] and "Log in with Instagram again" in d["message"], d)
    prof = A.get(f"{BASE}/profile.php", timeout=60).text
    check("profile shows 'Needs reconnecting' and the login button", "Needs reconnecting" in prof and "Log in again with Instagram" in prof)
    db("UPDATE social_accounts SET status='active', expires_at = NOW() + INTERVAL 30 DAY WHERE account_id=%s", (aid,))
    MOCK.valid.discard(MOCK.long)       # the member removed the app in Instagram: the token is revoked
    st, d = ST.post_job(A, ta, "journal", j_pub, jobs)
    check("a revoked token (Instagram answers 190) marks the account expired with a plain message", not d["results"][0]["ok"] and "Log in with Instagram again" in d["results"][0]["error"] and db("SELECT status FROM social_accounts WHERE account_id=%s", (aid,))[0][0] == "expired", d)

    print("\n== disconnect deletes the token")
    st, d = api(A, ta, "disconnect", account_id=aid)
    check("disconnect removes the Instagram account row and token", d.get("ok") and db("SELECT COUNT(*) FROM social_accounts WHERE eml=%s", (ea,))[0][0] == 0, d)

    srv.shutdown()
    cleanup()
    bad = [n for n, ok in results if not ok]
    print(f"\n{len(results) - len(bad)}/{len(results)} passed")
    if bad:
        print("FAILED:"); [print("  -", n) for n in bad]
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
