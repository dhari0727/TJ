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

/**
 * Handle an uploaded $_FILES entry -> saves file, inserts media row (+hashtags).
 * Returns [media_id, error]. Skips silently if no file provided.
 */
function ja_handle_upload($conn, $file, $eml, $entry_id = null, $caption = '',
                          $destination = null, $is_public = 1, $lat = null, $lon = null) {
    global $JA_ALLOWED;
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];  // nothing uploaded — fine
    }
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed.'];
    if ($file['size'] > JA_MAX_BYTES) return [null, 'File too large (max 40 MB).'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($JA_ALLOWED[$mime])) return [null, 'Unsupported file type.'];
    [$kind, $ext] = $JA_ALLOWED[$mime];

    if (!is_dir(JA_UPLOAD_DIR)) mkdir(JA_UPLOAD_DIR, 0755, true);
    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = JA_UPLOAD_DIR . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return [null, 'Could not save file.'];
    $rel = 'uploads/' . $safe;

    // Coordinates are optional/best-effort (browser geolocation). Normalize blanks to NULL
    // and clamp to valid ranges so a bad client value can't corrupt the column.
    $lat = ($lat === null || $lat === '') ? null : max(-90, min(90, (float)$lat));
    $lon = ($lon === null || $lon === '') ? null : max(-180, min(180, (float)$lon));

    $tags = ja_extract_hashtags($caption);
    $s = mysqli_prepare($conn,
        "INSERT INTO media (entry_id, eml, kind, filepath, caption, destination, lat, lon, is_public)
         VALUES (?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($s, 'isssssddi', $entry_id, $eml, $kind, $rel, $caption, $destination, $lat, $lon, $is_public);
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
        "SELECT filepath, eml FROM media WHERE kind='photo' AND is_public=1
         AND (destination LIKE ? ) ORDER BY likes DESC, media_id DESC LIMIT 1");
    mysqli_stmt_bind_param($s, 's', $key); mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $row ?: null;
}
