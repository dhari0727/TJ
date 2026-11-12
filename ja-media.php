<?php
/**
 * JourneyAI — media (photo/video) upload + hashtag helpers.
 */

define('JA_UPLOAD_DIR', __DIR__ . '/uploads');
define('JA_MAX_BYTES', 40 * 1024 * 1024);   // 40 MB per file (covers short videos)
$JA_ALLOWED = [
    'image/jpeg' => ['photo','jpg'], 'image/png' => ['photo','png'],
    'image/webp' => ['photo','webp'], 'image/gif' => ['photo','gif'],
    'video/mp4' => ['video','mp4'], 'video/webm' => ['video','webm'],
    'video/quicktime' => ['video','mov'],
];

/** Extract #hashtags from a caption -> array of lowercase tags (no #). */
function ja_extract_hashtags($text) {
    preg_match_all('/#([A-Za-z0-9_]{2,50})/', (string)$text, $m);
    return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
}

/** Persist tags for a media row (creates tags, bumps uses, links). */
function ja_save_hashtags($conn, $media_id, $tags) {
    foreach ($tags as $t) {
        $s = mysqli_prepare($conn, "INSERT INTO hashtags (tag, uses) VALUES (?,1)
            ON DUPLICATE KEY UPDATE uses = uses + 1");
        mysqli_stmt_bind_param($s, 's', $t); mysqli_stmt_execute($s); mysqli_stmt_close($s);
        $s = mysqli_prepare($conn, "SELECT tag_id FROM hashtags WHERE tag=?");
        mysqli_stmt_bind_param($s, 's', $t); mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
        if ($row) {
            $tid = (int)$row['tag_id'];
            $s = mysqli_prepare($conn, "INSERT IGNORE INTO media_hashtags (media_id, tag_id) VALUES (?,?)");
            mysqli_stmt_bind_param($s, 'ii', $media_id, $tid); mysqli_stmt_execute($s); mysqli_stmt_close($s);
        }
    }
}

const JA_MAX_PIXELS = 25000000;          // 25 megapixels: bigger images are rejected (decompression-bomb guard)
const JA_MAX_SIDE = 2400;                // stored images are scaled so the long side is at most this
const JA_USER_QUOTA = 400 * 1024 * 1024; // total media per account

/** Read the first bytes and decide the REAL type (never trust the file name or the browser's content type). */
function ja_sniff_media($tmp) {
    $h = (string)@file_get_contents($tmp, false, null, 0, 32);
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) return 'png';
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return 'webp';
    if (strncmp($h, 'GIF8', 4) === 0) return 'gif';
    if (substr($h, 4, 4) === 'ftyp') return 'mp4';                 // mp4 / mov
    if (strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0) return 'webm';
    return null;
}

/** Decode with GD and write a clean copy: no EXIF/GPS, no appended data, bounded size. Returns [ok, err]. */
function ja_reencode_image($tmp, $type, $dest) {
    if (!function_exists('imagecreatefromstring')) return [false, 'Image processing is unavailable on this server.'];
    $info = @getimagesize($tmp);
    if (!$info || $info[0] < 16 || $info[1] < 16) return [false, 'That file is not a valid image.'];
    if ($info[0] * $info[1] > JA_MAX_PIXELS) return [false, 'Image is too large (over 25 megapixels).'];
    $img = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$img) return [false, 'That image could not be read.'];
    // honour the camera rotation before the EXIF block is dropped
    if ($type === 'jpg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($tmp);
        $o = (int)($ex['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        elseif ($o === 6) $img = imagerotate($img, -90, 0);
        elseif ($o === 8) $img = imagerotate($img, 90, 0);
    }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, JA_MAX_SIDE / max($w, $h));
    if ($scale < 1) { $img = imagescale($img, (int)round($w * $scale), (int)round($h * $scale)); }
    $ok = false;
    if ($type === 'png' || $type === 'gif') { imagesavealpha($img, true); $ok = imagepng($img, $dest, 6); }
    elseif ($type === 'webp') { $ok = imagewebp($img, $dest, 85); }
    else { $ok = imagejpeg($img, $dest, 86); }
    imagedestroy($img);
    return $ok ? [true, null] : [false, 'Could not save the image.'];
}

/**
 * Handle an uploaded $_FILES entry -> validates, cleans, saves, inserts the media row (+hashtags).
 * Returns [media_id, error]. Skips silently if no file provided.
 * Checks: real type by content, size, dimensions, re-encoding (images), duplicate file across accounts,
 * per-account quota, and the account's trust level decides whether it appears publicly right away.
 */
function ja_handle_upload($conn, $file, $eml, $entry_id = null, $caption = '',
                          $destination = null, $is_public = 1, $lat = null, $lon = null) {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];  // nothing uploaded — fine
    }
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed.'];
    if ($file['size'] > JA_MAX_BYTES) return [null, 'File too large (max 40 MB).'];
    if (!is_uploaded_file($file['tmp_name'])) return [null, 'Upload failed.'];

    $type = ja_sniff_media($file['tmp_name']);
    if (!$type) return [null, 'Unsupported file type. Use JPG, PNG, WEBP, MP4 or WEBM.'];
    $kind = in_array($type, ['mp4', 'webm'], true) ? 'video' : 'photo';

    require_once __DIR__ . '/ja-trust.php';
    $trust = ja_trust($eml);
    if ($is_public && !$trust['can_publish']) $is_public = 0;          // unverified / restricted: private only
    if (!ja_rate_limit('upload:' . strtolower($eml), 40, 3600)) return [null, 'You are uploading very fast. Please wait a little.'];

    // duplicate detection by content hash: the same file from another account is a red flag (copied or farmed)
    $sha = hash_file('sha256', $file['tmp_name']);
    $e = mysqli_real_escape_string($conn, $eml);
    $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT SUM(eml='$e'), SUM(eml<>'$e') FROM media WHERE sha256='" . $sha . "'"));
    if ((int)$dup[0] > 0) return [null, 'You already uploaded this file.'];
    if ((int)$dup[1] > 0 && $is_public) return [null, 'This exact file was already uploaded by another account, so it cannot be posted publicly.'];

    $used = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(bytes),0) FROM media WHERE eml='$e'"))[0];
    if ($used + (int)$file['size'] > JA_USER_QUOTA) return [null, 'Storage limit reached (400 MB). Delete some media first.'];

    if (!is_dir(JA_UPLOAD_DIR)) mkdir(JA_UPLOAD_DIR, 0755, true);
    $ext = $type === 'gif' ? 'png' : $type;                             // animated GIFs become still PNGs
    $safe = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = JA_UPLOAD_DIR . '/' . $safe;
    if ($kind === 'photo') {
        [$okImg, $imgErr] = ja_reencode_image($file['tmp_name'], $type, $dest);
        if (!$okImg) return [null, $imgErr];
    } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not save file.'];
    }
    @chmod($dest, 0644);
    $rel = 'uploads/' . $safe;
    $bytes = (int)@filesize($dest);

    // Coordinates are optional/best-effort (browser geolocation). Normalize blanks to NULL
    // and clamp to valid ranges so a bad client value can't corrupt the column.
    $lat = ($lat === null || $lat === '') ? null : max(-90, min(90, (float)$lat));
    $lon = ($lon === null || $lon === '') ? null : max(-180, min(180, (float)$lon));

    $caption = mb_substr((string)$caption, 0, 500);
    $mod = $is_public ? ja_moderation_for($eml) : ['status' => 'approved', 'reason' => null];
    $status = $mod['status'] ?: 'pending'; $reason = $mod['reason'];
    $tags = ja_extract_hashtags($caption);
    $s = mysqli_prepare($conn,
        "INSERT INTO media (entry_id, eml, kind, filepath, caption, destination, lat, lon, is_public, moderation_status, mod_reason, sha256, bytes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($s, 'isssssddisssi', $entry_id, $eml, $kind, $rel, $caption, $destination, $lat, $lon, $is_public, $status, $reason, $sha, $bytes);
    mysqli_stmt_execute($s);
    $mid = mysqli_insert_id($conn);
    mysqli_stmt_close($s);
    if ($tags) ja_save_hashtags($conn, $mid, $tags);
    return [$mid, null];
}

/** Fetch media rows for an entry. */
function ja_entry_media($conn, $entry_id) {
    $s = mysqli_prepare($conn, "SELECT * FROM media WHERE entry_id=? ORDER BY media_id");
    mysqli_stmt_bind_param($s, 'i', $entry_id); mysqli_stmt_execute($s);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
    mysqli_stmt_close($s); return $rows;
}

/** A representative user photo for a destination/place name (for "User Entry" tag). */
function ja_user_photo_for($conn, $place) {
    $key = '%' . mysqli_real_escape_string($conn, explode(',', $place)[0]) . '%';
    $s = mysqli_prepare($conn,
        "SELECT filepath, eml FROM media WHERE kind='photo' AND is_public=1 AND moderation_status='approved'
         AND (destination LIKE ? ) ORDER BY likes DESC, media_id DESC LIMIT 1");
    mysqli_stmt_bind_param($s, 's', $key); mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $row ?: null;
}
