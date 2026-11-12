<?php
/**
 * JourneyAI - private camera roll JSON API (POST only, login + CSRF + rate limit).
 * Responses: {ok:true, ...} or {ok:false, error:"..."}.
 *
 *   action=folders                                        -> {folders:[{id,name,count,cover_thumb_url}]}
 *   action=photos[&folder_id=N(0=all)&offset&limit]       -> {photos:[{id,caption,place,frame,taken_at,thumb_url,full_url,folder_id}], total}
 *   action=create_folder&name                             -> {folder}
 *   action=rename_folder&id&name                          -> {folder}
 *   action=delete_folder&id&mode=move|delete              -> {deleted_photos, moved_photos}
 *   action=upload (multipart: image, folder_id, caption, place, frame, filter_name) -> {photo}
 *   action=update&id[&caption&place&folder_id]            -> {photo}
 *   action=delete&ids[]=                                  -> {deleted}
 *   action=use&id   copies the framed image to the PUBLIC uploads/ folder -> {path:'uploads/xxxx.jpg'}
 */
session_start();
require_once __DIR__ . '/ja-camera.php';
$conn = ja_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ja_cam_json(['ok' => false, 'error' => 'Use POST.'], 405);
$u = ja_current_user();
if (!$u) ja_cam_json(['ok' => false, 'error' => 'Log in to use your camera roll.'], 401);
$eml = $u['eml'];
$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent))
    ja_cam_json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
session_write_close();
if (!ja_rate_limit('camapi:' . strtolower($eml), 400, 60)) ja_cam_json(['ok' => false, 'error' => 'Too many requests. Wait a moment and try again.'], 429);

$action = (string)($_POST['action'] ?? '');
function ja_cam_fail($msg, $code = 400) { ja_cam_json(['ok' => false, 'error' => $msg], $code); }
function ja_cam_photo_json($conn, $eml, $id) { $r = ja_cam_get_photo($conn, $eml, $id); return $r ? ja_cam_photo_row($r) : null; }
function ja_cam_folder_json($conn, $eml, $id) { foreach (ja_cam_folders($conn, $eml) as $f) if ($f['id'] === (int)$id) return $f; return null; }
function ja_cam_name($v) {
    $n = ja_cam_clean($v, 80);
    if ($n === '') ja_cam_fail('Give the folder a name.');
    return $n;
}

switch ($action) {

case 'folders':
    ja_cam_json(['ok' => true, 'folders' => ja_cam_folders($conn, $eml), 'usage' => ja_cam_usage($conn, $eml) + ['max_photos' => JA_CAM_MAX_PHOTOS, 'max_bytes' => JA_CAM_MAX_BYTES]]);

case 'photos':
    ja_cam_default_folder($conn, $eml);
    $fid = (int)($_POST['folder_id'] ?? 0);
    $off = max(0, (int)($_POST['offset'] ?? 0));
    $lim = max(1, min(200, (int)($_POST['limit'] ?? 60)));
    if ($fid > 0) {
        if (!ja_cam_folder_owned($conn, $eml, $fid)) ja_cam_fail('That folder was not found.', 404);
        $c = mysqli_prepare($conn, "SELECT COUNT(*) FROM camera_photos WHERE eml=? AND folder_id=?");
        mysqli_stmt_bind_param($c, 'si', $eml, $fid);
        $q = mysqli_prepare($conn, "SELECT * FROM camera_photos WHERE eml=? AND folder_id=? ORDER BY taken_at DESC, photo_id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($q, 'siii', $eml, $fid, $lim, $off);
    } else {
        $c = mysqli_prepare($conn, "SELECT COUNT(*) FROM camera_photos WHERE eml=?");
        mysqli_stmt_bind_param($c, 's', $eml);
        $q = mysqli_prepare($conn, "SELECT * FROM camera_photos WHERE eml=? ORDER BY taken_at DESC, photo_id DESC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($q, 'sii', $eml, $lim, $off);
    }
    mysqli_stmt_execute($c); $total = (int)mysqli_fetch_row(mysqli_stmt_get_result($c))[0]; mysqli_stmt_close($c);
    mysqli_stmt_execute($q); $rows = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC); mysqli_stmt_close($q);
    ja_cam_json(['ok' => true, 'photos' => array_map('ja_cam_photo_row', $rows), 'total' => $total]);

case 'create_folder':
    $name = ja_cam_name($_POST['name'] ?? '');
    if (count(ja_cam_folders($conn, $eml)) >= JA_CAM_MAX_FOLDERS) ja_cam_fail('You can have up to ' . JA_CAM_MAX_FOLDERS . ' folders.');
    $s = mysqli_prepare($conn, "INSERT INTO camera_folders (eml, name, created_at) VALUES (?,?,NOW())");
    mysqli_stmt_bind_param($s, 'ss', $eml, $name);
    if (!@mysqli_stmt_execute($s)) ja_cam_fail('You already have a folder called "' . $name . '".');
    $id = mysqli_insert_id($conn); mysqli_stmt_close($s);
    ja_cam_json(['ok' => true, 'folder' => ja_cam_folder_json($conn, $eml, $id)]);

case 'rename_folder':
    $f = ja_cam_folder_owned($conn, $eml, $_POST['id'] ?? 0);
    if (!$f) ja_cam_fail('That folder was not found.', 404);
    if ($f['name'] === JA_CAM_DEFAULT) ja_cam_fail('The ' . JA_CAM_DEFAULT . ' folder cannot be renamed.');
    $name = ja_cam_name($_POST['name'] ?? '');
    if (strcasecmp($name, JA_CAM_DEFAULT) === 0) ja_cam_fail('That name is reserved for the default folder.');
    $s = mysqli_prepare($conn, "UPDATE camera_folders SET name=? WHERE folder_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'sis', $name, $f['folder_id'], $eml);
    if (!@mysqli_stmt_execute($s)) ja_cam_fail('You already have a folder called "' . $name . '".');
    mysqli_stmt_close($s);
    ja_cam_json(['ok' => true, 'folder' => ja_cam_folder_json($conn, $eml, $f['folder_id'])]);

case 'delete_folder':
    $f = ja_cam_folder_owned($conn, $eml, $_POST['id'] ?? 0);
    if (!$f) ja_cam_fail('That folder was not found.', 404);
    if ($f['name'] === JA_CAM_DEFAULT) ja_cam_fail('The ' . JA_CAM_DEFAULT . ' folder cannot be deleted.');
    $mode = ($_POST['mode'] ?? 'move') === 'delete' ? 'delete' : 'move';
    $fid = (int)$f['folder_id']; $moved = 0; $deleted = 0;
    if ($mode === 'delete') {
        $s = mysqli_prepare($conn, "SELECT photo_id FROM camera_photos WHERE eml=? AND folder_id=?");
        mysqli_stmt_bind_param($s, 'si', $eml, $fid); mysqli_stmt_execute($s);
        $ids = array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'photo_id'); mysqli_stmt_close($s);
        $deleted = ja_cam_delete_photos($conn, $eml, $ids);
    } else {
        $def = ja_cam_default_folder($conn, $eml);
        $s = mysqli_prepare($conn, "UPDATE camera_photos SET folder_id=? WHERE eml=? AND folder_id=?");
        mysqli_stmt_bind_param($s, 'isi', $def, $eml, $fid); mysqli_stmt_execute($s);
        $moved = max(0, mysqli_stmt_affected_rows($s)); mysqli_stmt_close($s);
    }
    $s = mysqli_prepare($conn, "DELETE FROM camera_folders WHERE folder_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $fid, $eml); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    ja_cam_json(['ok' => true, 'deleted_photos' => $deleted, 'moved_photos' => $moved]);

case 'upload':
    if (!ja_rate_limit('camup:' . strtolower($eml), 80, 3600)) ja_cam_fail('You are saving photos very fast. Please wait a little.', 429);
    $use = ja_cam_usage($conn, $eml);
    if ($use['photos'] >= JA_CAM_MAX_PHOTOS) ja_cam_fail('Your camera roll is full (' . JA_CAM_MAX_PHOTOS . ' photos). Delete some photos to make room.', 409);
    if ($use['bytes'] >= JA_CAM_MAX_BYTES) ja_cam_fail('Your camera roll has used its ' . (JA_CAM_MAX_BYTES >> 20) . ' MB of space. Delete some photos to make room.', 409);
    $fid = (int)($_POST['folder_id'] ?? 0);
    if ($fid > 0) { if (!ja_cam_folder_owned($conn, $eml, $fid)) ja_cam_fail('That folder was not found.', 404); }
    else $fid = ja_cam_default_folder($conn, $eml);
    $frame = in_array($_POST['frame'] ?? '', JA_CAM_FRAMES, true) ? $_POST['frame'] : 'none';
    $filt = in_array($_POST['filter_name'] ?? '', JA_CAM_FILTERS, true) ? $_POST['filter_name'] : 'original';
    $cap = ja_cam_clean($_POST['caption'] ?? '', 200); $place = ja_cam_clean($_POST['place'] ?? '', 120);
    [$rel, $trel, $bytes, $err] = ja_cam_store_upload($eml, $_FILES['image'] ?? null);
    if ($err) ja_cam_fail($err);
    if ($use['bytes'] + $bytes > JA_CAM_MAX_BYTES) {
        foreach ([$rel, $trel] as $r) { $p = ja_cam_abs($r); if ($p) @unlink($p); }
        ja_cam_fail('That photo would pass your ' . (JA_CAM_MAX_BYTES >> 20) . ' MB limit. Delete some photos to make room.', 409);
    }
    $s = mysqli_prepare($conn, "INSERT INTO camera_photos (eml, folder_id, filepath, thumbpath, caption, place, frame, filter_name, taken_at, bytes) VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
    mysqli_stmt_bind_param($s, 'sissssssi', $eml, $fid, $rel, $trel, $cap, $place, $frame, $filt, $bytes);
    if (!mysqli_stmt_execute($s)) { foreach ([$rel, $trel] as $r) { $p = ja_cam_abs($r); if ($p) @unlink($p); } ja_cam_fail('Could not save the photo.', 500); }
    $id = mysqli_insert_id($conn); mysqli_stmt_close($s);
    ja_cam_json(['ok' => true, 'photo' => ja_cam_photo_json($conn, $eml, $id), 'usage' => ja_cam_usage($conn, $eml) + ['max_photos' => JA_CAM_MAX_PHOTOS, 'max_bytes' => JA_CAM_MAX_BYTES]]);

case 'update':
    $row = ja_cam_get_photo($conn, $eml, $_POST['id'] ?? 0);
    if (!$row) ja_cam_fail('That photo was not found.', 404);
    $cap = array_key_exists('caption', $_POST) ? ja_cam_clean($_POST['caption'], 200) : $row['caption'];
    $place = array_key_exists('place', $_POST) ? ja_cam_clean($_POST['place'], 120) : $row['place'];
    $fid = $row['folder_id'] === null ? null : (int)$row['folder_id'];
    if (array_key_exists('folder_id', $_POST)) {
        $want = (int)$_POST['folder_id'];
        if ($want <= 0) $fid = ja_cam_default_folder($conn, $eml);
        elseif (ja_cam_folder_owned($conn, $eml, $want)) $fid = $want;
        else ja_cam_fail('That folder was not found.', 404);
    }
    $s = mysqli_prepare($conn, "UPDATE camera_photos SET caption=?, place=?, folder_id=? WHERE photo_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'ssiis', $cap, $place, $fid, $row['photo_id'], $eml); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    ja_cam_json(['ok' => true, 'photo' => ja_cam_photo_json($conn, $eml, $row['photo_id'])]);

case 'delete':
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0))), 0, 300);
    if (!$ids) ja_cam_fail('Choose at least one photo.');
    ja_cam_json(['ok' => true, 'deleted' => ja_cam_delete_photos($conn, $eml, $ids)]);

case 'use':
    if (!ja_rate_limit('camuse:' . strtolower($eml), 120, 3600)) ja_cam_fail('You are using photos very fast. Please wait a little.', 429);
    $row = ja_cam_get_photo($conn, $eml, $_POST['id'] ?? 0);
    if (!$row) ja_cam_fail('That photo was not found.', 404);
    [$path, $err] = ja_cam_publish_copy($conn, $eml, $row);
    if ($err) ja_cam_fail($err, 500);
    ja_cam_json(['ok' => true, 'path' => $path]);

default:
    ja_cam_fail('Unknown action.');
}
