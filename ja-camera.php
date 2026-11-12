<?php
/**
 * JourneyAI - private camera roll helpers (shared by camera.php, camera-roll.php, camera-api.php, camera-img.php).
 * Photos are stored OUTSIDE any public path (private/camera/<hash-of-eml>/, deny-all) and are only ever
 * served by camera-img.php after the session owner is checked.
 */
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-media.php';

const JA_CAM_MAX_PHOTOS = 300;
const JA_CAM_MAX_BYTES = 150 * 1024 * 1024;   // per account
const JA_CAM_MAX_UPLOAD = 12 * 1024 * 1024;   // per photo, as received
const JA_CAM_MAX_SIDE = 1600;
const JA_CAM_THUMB = 400;
const JA_CAM_MAX_FOLDERS = 40;
const JA_CAM_DEFAULT = 'Camera roll';
const JA_CAM_FRAMES = ['classic', 'wide', 'film', 'postcard', 'none'];
const JA_CAM_FILTERS = ['original', 'warm', 'cool', 'mono', 'fade', 'vivid', 'sepia', 'film', 'golden'];

function ja_cam_root() { return __DIR__ . '/private/camera'; }

/** Per-account directory name: a hash, never the email. */
function ja_cam_user_hash($eml) { return substr(hash_hmac('sha256', strtolower((string)$eml), ja_secret_key()), 0, 24); }

/** Make sure private/ and private/camera/ exist and refuse every direct request. */
function ja_cam_ensure_root() {
    $priv = __DIR__ . '/private'; $root = ja_cam_root();
    if (!is_dir($priv)) @mkdir($priv, 0755, true);
    if (!is_file($priv . '/.htaccess')) @file_put_contents($priv . '/.htaccess', "Require all denied\n");
    if (!is_dir($root)) @mkdir($root, 0755, true);
    if (!is_file($root . '/.htaccess')) @file_put_contents($root . '/.htaccess', "Require all denied\n");
}

function ja_cam_user_dir($eml, $create = true) {
    ja_cam_ensure_root();
    $d = ja_cam_root() . '/' . ja_cam_user_hash($eml);
    if ($create && !is_dir($d)) {
        @mkdir($d, 0755, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Absolute path for a stored relative path, only if it really sits inside private/camera/. */
function ja_cam_abs($rel) {
    $p = realpath(__DIR__ . '/' . $rel); $root = realpath(ja_cam_root());
    if (!$p || !$root || strpos($p, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($p)) return null;
    return $p;
}

function ja_cam_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ja_cam_clean($s, $max) {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s);
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_substr($s, 0, $max);
}

/** The default folder, created on first use. Returns folder_id. */
function ja_cam_default_folder($conn, $eml) {
    $name = JA_CAM_DEFAULT;
    $s = mysqli_prepare($conn, "INSERT IGNORE INTO camera_folders (eml, name, created_at) VALUES (?,?,NOW())");
    mysqli_stmt_bind_param($s, 'ss', $eml, $name); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    $s = mysqli_prepare($conn, "SELECT folder_id FROM camera_folders WHERE eml=? AND name=?");
    mysqli_stmt_bind_param($s, 'ss', $eml, $name); mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return (int)$r[0];
}

function ja_cam_folder_owned($conn, $eml, $id) {
    $id = (int)$id; if ($id <= 0) return null;
    $s = mysqli_prepare($conn, "SELECT folder_id, name FROM camera_folders WHERE folder_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $id, $eml); mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $r ?: null;
}

function ja_cam_img_url($id, $kind) { return 'camera-img.php?id=' . (int)$id . '&t=' . ($kind === 'thumb' ? 'thumb' : 'full'); }

function ja_cam_photo_row($r) {
    $dim = ($tp = ja_cam_abs($r['thumbpath'])) ? @getimagesize($tp) : null;
    return [
        'tw' => $dim ? (int)$dim[0] : 0, 'th' => $dim ? (int)$dim[1] : 0,
        'id' => (int)$r['photo_id'],
        'caption' => (string)$r['caption'],
        'place' => (string)$r['place'],
        'frame' => (string)$r['frame'],
        'filter_name' => (string)$r['filter_name'],
        'taken_at' => (string)$r['taken_at'],
        'thumb_url' => ja_cam_img_url($r['photo_id'], 'thumb'),
        'full_url' => ja_cam_img_url($r['photo_id'], 'full'),
        'folder_id' => $r['folder_id'] === null ? null : (int)$r['folder_id'],
    ];
}

function ja_cam_folders($conn, $eml) {
    ja_cam_default_folder($conn, $eml);
    $s = mysqli_prepare($conn,
        "SELECT f.folder_id, f.name,
                (SELECT COUNT(*) FROM camera_photos p WHERE p.eml=f.eml AND p.folder_id=f.folder_id) AS n,
                (SELECT p.photo_id FROM camera_photos p WHERE p.eml=f.eml AND p.folder_id=f.folder_id ORDER BY p.taken_at DESC, p.photo_id DESC LIMIT 1) AS cover
         FROM camera_folders f WHERE f.eml=? ORDER BY (f.name='" . JA_CAM_DEFAULT . "') DESC, f.name");
    mysqli_stmt_bind_param($s, 's', $eml); mysqli_stmt_execute($s);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
    $out = [];
    foreach ($rows as $r) $out[] = ['id' => (int)$r['folder_id'], 'name' => $r['name'], 'count' => (int)$r['n'],
        'cover_thumb_url' => $r['cover'] ? ja_cam_img_url($r['cover'], 'thumb') : null,
        'is_default' => $r['name'] === JA_CAM_DEFAULT];
    return $out;
}

function ja_cam_usage($conn, $eml) {
    $s = mysqli_prepare($conn, "SELECT COUNT(*), COALESCE(SUM(bytes),0) FROM camera_photos WHERE eml=?");
    mysqli_stmt_bind_param($s, 's', $eml); mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return ['photos' => (int)$r[0], 'bytes' => (int)$r[1]];
}

function ja_cam_get_photo($conn, $eml, $id) {
    $id = (int)$id; if ($id <= 0) return null;
    $s = mysqli_prepare($conn, "SELECT * FROM camera_photos WHERE photo_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $id, $eml); mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $r ?: null;
}

/** Make a JPEG thumbnail (long side JA_CAM_THUMB). Returns bool. */
function ja_cam_make_thumb($srcAbs, $destAbs) {
    $img = @imagecreatefromstring((string)file_get_contents($srcAbs));
    if (!$img) return false;
    $w = imagesx($img); $h = imagesy($img);
    $sc = min(1, JA_CAM_THUMB / max($w, $h));
    $t = imagecreatetruecolor(max(1, (int)round($w * $sc)), max(1, (int)round($h * $sc)));
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $img, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
    $ok = imagejpeg($t, $destAbs, 80);
    imagedestroy($img); imagedestroy($t);
    return (bool)$ok;
}

/**
 * Validate + clean an uploaded image with the shared media pipeline (magic bytes, pixel limit, GD re-encode that drops EXIF/GPS),
 * clamp to 1600px, store under the user's private dir and create the thumbnail.
 * Returns [photoPathRel, thumbPathRel, bytes, error].
 */
function ja_cam_store_upload($eml, $file) {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        return [null, null, 0, ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) ? 'That photo is too large (max 12 MB).' : 'No photo was received. Try again.'];
    }
    if ($file['size'] > JA_CAM_MAX_UPLOAD) return [null, null, 0, 'That photo is too large (max 12 MB).'];
    if (!is_uploaded_file($file['tmp_name'])) return [null, null, 0, 'Upload failed.'];
    $type = ja_sniff_media($file['tmp_name']);
    if (!in_array($type, ['jpg', 'png', 'webp', 'gif'], true)) return [null, null, 0, 'Unsupported file type. Use a JPG, PNG or WEBP photo.'];
    $dir = ja_cam_user_dir($eml);
    $ext = $type === 'jpg' ? 'jpg' : ($type === 'webp' ? 'webp' : 'png');   // gif becomes still png
    $base = bin2hex(random_bytes(14));
    $dest = $dir . '/' . $base . '.' . $ext;
    [$ok, $err] = ja_reencode_image($file['tmp_name'], $type, $dest);
    if (!$ok) { @unlink($dest); return [null, null, 0, $err ?: 'That image could not be saved.']; }
    // clamp to 1600 px (the shared pipeline allows 2400)
    $info = @getimagesize($dest);
    if ($info && max($info[0], $info[1]) > JA_CAM_MAX_SIDE) {
        $im = @imagecreatefromstring((string)file_get_contents($dest));
        if ($im) {
            $sc = JA_CAM_MAX_SIDE / max($info[0], $info[1]);
            $im2 = imagescale($im, (int)round($info[0] * $sc), (int)round($info[1] * $sc));
            if ($ext === 'png') imagepng($im2, $dest, 6); elseif ($ext === 'webp') imagewebp($im2, $dest, 85); else imagejpeg($im2, $dest, 88);
            imagedestroy($im); imagedestroy($im2);
        }
    }
    $thumb = $dir . '/' . $base . '_t.jpg';
    if (!ja_cam_make_thumb($dest, $thumb)) { @unlink($dest); return [null, null, 0, 'Could not make a thumbnail.']; }
    @chmod($dest, 0644); @chmod($thumb, 0644);
    $h = ja_cam_user_hash($eml);
    return ['private/camera/' . $h . '/' . basename($dest), 'private/camera/' . $h . '/' . basename($thumb), (int)@filesize($dest), null];
}

function ja_cam_unlink_photo($row) {
    foreach (['filepath', 'thumbpath'] as $k) { $p = ja_cam_abs($row[$k] ?? ''); if ($p) @unlink($p); }
}

/** Delete photos (rows + files) by id for this owner. Returns number removed. */
function ja_cam_delete_photos($conn, $eml, array $ids) {
    $n = 0;
    foreach ($ids as $id) {
        $row = ja_cam_get_photo($conn, $eml, $id);
        if (!$row) continue;
        $s = mysqli_prepare($conn, "DELETE FROM camera_photos WHERE photo_id=? AND eml=?");
        mysqli_stmt_bind_param($s, 'is', $row['photo_id'], $eml); mysqli_stmt_execute($s);
        $n += max(0, mysqli_stmt_affected_rows($s)); mysqli_stmt_close($s);
        ja_cam_unlink_photo($row);
    }
    return $n;
}

/** Remove every camera file of an account (used when the account is deleted). */
function ja_cam_purge_user_files($eml) {
    $d = ja_cam_user_dir($eml, false);
    if (!is_dir($d)) return;
    foreach (glob($d . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
    @unlink($d . '/.htaccess'); @rmdir($d);
}

/** Copy a private photo into the PUBLIC uploads folder through the media pipeline. Returns [path, error]. */
function ja_cam_publish_copy($conn, $eml, $row) {
    $src = ja_cam_abs($row['filepath']);
    if (!$src) return [null, 'That photo file is missing.'];
    $type = ja_sniff_media($src);
    if (!in_array($type, ['jpg', 'png', 'webp'], true)) return [null, 'Unsupported photo type.'];
    if (!is_dir(JA_UPLOAD_DIR)) @mkdir(JA_UPLOAD_DIR, 0755, true);
    $safe = bin2hex(random_bytes(12)) . '.' . $type;
    $dest = JA_UPLOAD_DIR . '/' . $safe;
    [$ok, $err] = ja_reencode_image($src, $type, $dest);
    if (!$ok) { @unlink($dest); return [null, $err ?: 'Could not copy the photo.']; }
    @chmod($dest, 0644);
    $rel = 'uploads/' . $safe; $bytes = (int)@filesize($dest); $sha = hash_file('sha256', $dest);
    $cap = (string)$row['caption']; $dst = $row['place'] !== '' ? (string)$row['place'] : null;
    // tracked as a private-by-default media row so deleting the account cleans the copy up
    $s = mysqli_prepare($conn, "INSERT INTO media (eml, kind, filepath, caption, destination, is_public, moderation_status, sha256, bytes) VALUES (?, 'photo', ?, ?, ?, 0, 'approved', ?, ?)");
    mysqli_stmt_bind_param($s, 'sssssi', $eml, $rel, $cap, $dst, $sha, $bytes);
    mysqli_stmt_execute($s); mysqli_stmt_close($s);
    return [$rel, null];
}
