"""
JourneyAI private camera roll test (camera-api.php, camera-img.php, private/camera storage).

  python tests/camera_test.py            (Apache + MySQL running; sql/camera.sql applied)

Registers two throw-away "camera-*@test.invalid" accounts, exercises upload, listing, folders, ownership checks,
the deny-all private directory and the public "use" copy, then deletes both accounts and checks their files are gone.
Exit code 0 = all passed.
"""
import hashlib
import hmac
import io
import os
import re
import secrets
import sys
import time

import requests
from PIL import Image

BASE = os.environ.get("BASE_URL", "http://localhost/travel_journel").rstrip("/")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + (f"   [{str(detail)[:160]}]" if detail and not cond else ""))
    return bool(cond)


def csrf_from(html):
    m = re.search(r'JA_CSRF=("[a-f0-9]+")', html) or re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1).strip('"') if m else ""


def jpeg(w=1800, h=1200, exif=False, seed=0):
    im = Image.new("RGB", (w, h))
    px = im.load()
    for x in range(0, w, 6):
        for y in range(0, h, 6):
            c = ((x * 255 // w + seed) % 256, (y * 255 // h) % 256, ((x + y + seed * 7) * 255 // (w + h)) % 256)
            for dx in range(6):
                for dy in range(6):
                    if x + dx < w and y + dy < h:
                        px[x + dx, y + dy] = c
    b = io.BytesIO()
    if exif:
        ex = Image.Exif()
        ex[0x010F] = "SecretCameraMaker"          # Make
        ex[0x0132] = "2026:01:01 10:00:00"
        gps = ex.get_ifd(0x8825)
        gps[1] = "N"; gps[2] = (12.0, 34.0, 56.0); gps[3] = "E"; gps[4] = (77.0, 12.0, 30.0)
        im.save(b, "JPEG", quality=90, exif=ex)
    else:
        im.save(b, "JPEG", quality=90)
    return b.getvalue()


def user_hash(email):
    key = bytes.fromhex(open(os.path.join(ROOT, "config", "secret.key")).read().strip())
    return hmac.new(key, email.lower().encode(), hashlib.sha256).hexdigest()[:24]


def register(sess, email, pw, name):
    tok = csrf_from(sess.get(f"{BASE}/register.php", timeout=30).text)
    time.sleep(4.5)
    sess.post(f"{BASE}/register.php", data={"csrf": tok, "fname": name, "lname": "Cam", "eml": email, "psw": pw, "sn": "1"}, allow_redirects=False, timeout=60)
    return csrf_from(sess.get(f"{BASE}/profile.php", timeout=60).text)


def api(sess, tok, action, files=None, **data):
    d = {"action": action, "csrf": tok}
    for k, v in data.items():
        d[k] = v
    r = sess.post(f"{BASE}/camera-api.php", data=d, files=files, timeout=60)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {"ok": False, "error": r.text[:120]}


def delete_account(sess, email, pw):
    tok = csrf_from(sess.get(f"{BASE}/profile.php", timeout=60).text)
    r = sess.post(f"{BASE}/profile.php", data={"csrf": tok, "DeleteAccount": "1", "confirm_pw": pw}, allow_redirects=False, timeout=60)
    return r.status_code == 302 and "deleted=1" in r.headers.get("Location", "")


def main():
    anon, A, B = requests.Session(), requests.Session(), requests.Session()
    ea = f"camera-{secrets.token_hex(4)}@test.invalid"
    eb = f"camera-{secrets.token_hex(4)}@test.invalid"
    pw = "Camera-Test#2026a"
    pubpath = None
    ok_cleanup = False

    print("\n== signed-out access")
    code, d = api(anon, "x", "folders")
    check("camera-api without login -> 401", code == 401 and d.get("ok") is False, (code, d))
    r = anon.get(f"{BASE}/camera-api.php", timeout=30)
    check("camera-api rejects GET", r.status_code == 405, r.status_code)
    check("camera-img without login -> 401", anon.get(f"{BASE}/camera-img.php?id=1&t=full", timeout=30).status_code == 401)
    for p in ["camera.php", "camera-roll.php"]:
        r = anon.get(f"{BASE}/{p}", allow_redirects=False, timeout=30)
        check(f"{p} requires login", r.status_code == 302 and "login.php" in r.headers.get("Location", ""), r.status_code)

    print("\n== accounts")
    ta = register(A, ea, pw, "Alpha")
    tb = register(B, eb, pw, "Bravo")
    check("both accounts logged in with CSRF tokens", len(ta) >= 16 and len(tb) >= 16)
    ra = A.get(f"{BASE}/camera.php", timeout=60)
    check("camera.php loads for a member", ra.status_code == 200 and 'id="cm"' in ra.text and "js/camera.js" in ra.text)
    check("camera.php has the file-picker fallback", 'capture="environment"' in ra.text and 'accept="image/*"' in ra.text)
    check("permissions policy allows the camera for this site", "camera=(self)" in (ra.headers.get("Permissions-Policy") or ""), ra.headers.get("Permissions-Policy"))
    rr = A.get(f"{BASE}/camera-roll.php", timeout=60)
    check("camera-roll.php loads and says only you can see it", rr.status_code == 200 and "Only you can see these photos" in rr.text)
    check("nav and palette list Camera and Camera roll", 'href="camera.php"' in rr.text and 'href="camera-roll.php"' in rr.text)

    print("\n== api guards")
    code, d = api(A, "", "folders")
    check("POST without a CSRF token -> 403", code == 403 and d.get("ok") is False, (code, d))
    code, d = api(A, ta, "nonsense")
    check("unknown action -> ok:false", d.get("ok") is False and code == 400, (code, d))

    print("\n== folders")
    code, d = api(A, ta, "folders")
    names = [f["name"] for f in d.get("folders", [])]
    check("default 'Camera roll' folder exists on first use", d.get("ok") and names == ["Camera roll"], d)
    default_id = d["folders"][0]["id"]
    code, d = api(A, ta, "create_folder", name="Goa 2026")
    check("create folder", d.get("ok") and d["folder"]["name"] == "Goa 2026", d)
    goa = d["folder"]["id"]
    code, d = api(A, ta, "create_folder", name="Goa 2026")
    check("duplicate folder name refused", d.get("ok") is False, d)
    code, d = api(A, ta, "create_folder", name="   ")
    check("blank folder name refused", d.get("ok") is False, d)
    code, d = api(A, ta, "rename_folder", id=goa, name="Goa trip")
    check("rename folder", d.get("ok") and d["folder"]["name"] == "Goa trip", d)
    code, d = api(A, ta, "rename_folder", id=default_id, name="Other")
    check("default folder cannot be renamed", d.get("ok") is False, d)
    code, d = api(A, ta, "delete_folder", id=default_id, mode="delete")
    check("default folder cannot be deleted", d.get("ok") is False, d)
    code, d = api(B, tb, "rename_folder", id=goa, name="Stolen")
    check("another account cannot rename my folder", d.get("ok") is False and code == 404, (code, d))

    print("\n== upload")
    big = jpeg(2400, 1600, exif=True)
    code, d = api(A, ta, "upload", files={"image": ("shot.jpg", big, "image/jpeg")}, caption="Sunrise <b>at</b> the ghats", place="Varanasi", frame="classic", filter_name="warm")
    check("upload a JPEG", d.get("ok") and d["photo"]["id"] > 0, d)
    p1 = d["photo"]
    check("photo goes to the default folder", p1["folder_id"] == default_id, p1)
    check("urls are camera-img.php urls", p1["thumb_url"].startswith("camera-img.php?id=") and "t=thumb" in p1["thumb_url"] and "t=full" in p1["full_url"], p1)
    check("caption and place are stored", p1["caption"].startswith("Sunrise") and p1["place"] == "Varanasi" and p1["frame"] == "classic")
    code, d = api(A, ta, "upload", files={"image": ("notes.jpg", b"<?php echo 1;?> not an image at all, just text pretending", "image/jpeg")})
    check("a non-image is refused", d.get("ok") is False, d)
    pix = Image.new("RGB", (6000, 5000), (10, 120, 60)); bb = io.BytesIO(); pix.save(bb, "PNG")
    code, d = api(A, ta, "upload", files={"image": ("bomb.png", bb.getvalue(), "image/png")})
    check("an image over 25 megapixels is refused", d.get("ok") is False, d)
    code, d = api(A, ta, "upload", files={"image": ("x.jpg", jpeg(400, 300, seed=3), "image/jpeg")}, folder_id=goa, caption="Beach", frame="film", filter_name="mono")
    check("upload into a chosen folder", d.get("ok") and d["photo"]["folder_id"] == goa, d)
    p2 = d["photo"]
    code, d = api(A, ta, "upload", files={"image": ("x.jpg", jpeg(400, 300, seed=4), "image/jpeg")}, folder_id=999999999)
    check("upload into a folder that is not mine is refused", d.get("ok") is False and code == 404, (code, d))

    print("\n== listing and serving")
    code, d = api(A, ta, "photos")
    ids = [p["id"] for p in d.get("photos", [])]
    check("photos (all) lists both", d.get("ok") and d["total"] == 2 and set(ids) == {p1["id"], p2["id"]}, d)
    code, d = api(A, ta, "photos", folder_id=goa)
    check("photos filtered by folder", d.get("total") == 1 and d["photos"][0]["id"] == p2["id"], d)
    code, d = api(A, ta, "photos", offset=1, limit=1)
    check("offset and limit paginate", len(d.get("photos", [])) == 1 and d["total"] == 2, d)
    code, d = api(A, ta, "folders")
    gf = [f for f in d["folders"] if f["id"] == goa][0]
    check("folder shows its count and a cover thumb", gf["count"] == 1 and (gf["cover_thumb_url"] or "").startswith("camera-img.php"), gf)
    t = A.get(f"{BASE}/{p1['thumb_url']}", timeout=30)
    f = A.get(f"{BASE}/{p1['full_url']}", timeout=30)
    check("owner can fetch the thumbnail (jpeg)", t.status_code == 200 and t.headers["Content-Type"].startswith("image/jpeg"))
    check("owner can fetch the full image (jpeg)", f.status_code == 200 and f.headers["Content-Type"].startswith("image/jpeg"))
    check("full image is Cache-Control private, no-store", "no-store" in f.headers.get("Cache-Control", "") and "private" in f.headers.get("Cache-Control", ""), f.headers.get("Cache-Control"))
    ti, fi = Image.open(io.BytesIO(t.content)), Image.open(io.BytesIO(f.content))
    check("full image is at most 1600px on the long side", max(fi.size) <= 1600, fi.size)
    check("thumbnail is at most 400px", max(ti.size) <= 400, ti.size)
    check("EXIF and GPS were stripped", len(fi.getexif()) == 0 and b"SecretCameraMaker" not in f.content, dict(fi.getexif()))
    dl = A.get(f"{BASE}/{p1['full_url']}&dl=1", timeout=30)
    check("download adds an attachment header", "attachment" in dl.headers.get("Content-Disposition", ""))

    print("\n== privacy")
    for label, url in (("thumb", p1["thumb_url"]), ("full", p1["full_url"])):
        r = B.get(f"{BASE}/{url}", timeout=30)
        check(f"another account cannot fetch my {label}", r.status_code in (403, 404) and not r.headers.get("Content-Type", "").startswith("image/"), r.status_code)
        r = anon.get(f"{BASE}/{url}", timeout=30)
        check(f"signed-out visitor cannot fetch my {label}", r.status_code in (401, 403, 404), r.status_code)
    code, d = api(B, tb, "photos")
    check("another account's list does not contain my photos", d.get("ok") and d["total"] == 0 and not d["photos"], d)
    code, d = api(B, tb, "update", id=p1["id"], caption="hijack")
    check("another account cannot edit my photo", d.get("ok") is False and code == 404, (code, d))
    code, d = api(B, tb, "delete", **{"ids[]": [p1["id"]]})
    check("another account's delete removes nothing", d.get("deleted") == 0 or d.get("ok") is False, d)
    code, d = api(B, tb, "use", id=p1["id"])
    check("another account cannot use my photo", d.get("ok") is False and code == 404, (code, d))
    again = A.get(f"{BASE}/{p1['full_url']}", timeout=30)
    check("my photo survived those attempts", again.status_code == 200)
    h = user_hash(ea)
    pdir = os.path.join(ROOT, "private", "camera", h)
    files = [x for x in os.listdir(pdir) if not x.startswith(".")] if os.path.isdir(pdir) else []
    check("files exist on disk in private/camera/<hash>/ (not the email)", len(files) >= 4 and ea not in pdir, files)
    for url in (f"{BASE}/private/camera/{h}/{files[0]}" if files else f"{BASE}/private/camera/x", f"{BASE}/private/camera/{h}/", f"{BASE}/private/camera/", f"{BASE}/private/"):
        r = A.get(url, timeout=30)
        check(f"direct URL denied: ...{url[-34:]}", r.status_code in (403, 404) and not r.headers.get("Content-Type", "").startswith("image/"), r.status_code)
    check("private dirs carry a deny-all .htaccess", "Require all denied" in open(os.path.join(ROOT, "private", "camera", ".htaccess")).read() and os.path.isfile(os.path.join(pdir, ".htaccess")))

    print("\n== edit and move")
    code, d = api(A, ta, "update", id=p1["id"], caption="Dawn", place="Assi Ghat", folder_id=goa)
    check("update caption, place and folder", d.get("ok") and d["photo"]["caption"] == "Dawn" and d["photo"]["place"] == "Assi Ghat" and d["photo"]["folder_id"] == goa, d)
    code, d = api(A, ta, "update", id=p1["id"], folder_id=999999999)
    check("moving to a folder that is not mine is refused", d.get("ok") is False, d)

    print("\n== use (explicit public copy)")
    code, d = api(A, ta, "use", id=p1["id"])
    pubpath = d.get("path")
    check("use returns a public uploads/ path", d.get("ok") and re.match(r"^uploads/[a-f0-9]+\.(jpg|png|webp)$", pubpath or ""), d)
    if pubpath:
        r = anon.get(f"{BASE}/{pubpath}", timeout=30)
        check("the public copy is served", r.status_code == 200 and r.headers.get("Content-Type", "").startswith("image/"), r.status_code)
        check("the public copy has no EXIF", len(Image.open(io.BytesIO(r.content)).getexif()) == 0)
        check("the public copy is a new file (different name from the private one)", pubpath.split("/")[-1] not in files)
    check("the private original is still private", B.get(f"{BASE}/{p1['full_url']}", timeout=30).status_code in (403, 404))

    print("\n== delete")
    code, d = api(A, ta, "delete_folder", id=goa, mode="move")
    check("delete folder with mode=move moves photos to Camera roll", d.get("ok") and d["moved_photos"] == 2, d)
    code, d = api(A, ta, "photos", folder_id=default_id)
    check("both photos now live in Camera roll", d.get("total") == 2, d)
    code, d = api(A, ta, "create_folder", name="Temp")
    tmp = d["folder"]["id"]
    api(A, ta, "update", id=p2["id"], folder_id=tmp)
    n_before = len([x for x in os.listdir(pdir) if not x.startswith(".")])
    code, d = api(A, ta, "delete_folder", id=tmp, mode="delete")
    check("delete folder with mode=delete removes its photos", d.get("ok") and d["deleted_photos"] == 1, d)
    check("deleting also removes the files from disk", len([x for x in os.listdir(pdir) if not x.startswith(".")]) == n_before - 2)
    check("a deleted photo can no longer be fetched", A.get(f"{BASE}/{p2['full_url']}", timeout=30).status_code == 404)
    code, d = api(A, ta, "delete", **{"ids[]": [p1["id"]]})
    check("delete by ids[]", d.get("ok") and d["deleted"] == 1, d)
    code, d = api(A, ta, "photos")
    check("roll is empty again", d.get("total") == 0, d)

    print("\n== cleanup: delete the accounts")
    code, d = api(A, ta, "upload", files={"image": ("x.jpg", jpeg(300, 200, seed=9), "image/jpeg")})
    check("(re-upload so account deletion has files to remove)", d.get("ok"), d)
    ok_a = delete_account(A, ea, pw)
    ok_b = delete_account(B, eb, pw)
    check("accounts deleted", ok_a and ok_b)
    check("the account's private folder is gone from disk", not os.path.isdir(pdir))
    if pubpath:
        check("the public copy was removed with the account", anon.get(f"{BASE}/{pubpath}", timeout=30).status_code == 404)

    failed = [n for n, ok, _ in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} passed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
