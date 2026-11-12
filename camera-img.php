<?php
/**
 * JourneyAI - serves ONE private camera photo to its owner only.
 *   camera-img.php?id=N&t=thumb|full[&dl=1]
 * The files live in private/camera/ (deny-all); this script is the only way to read them.
 */
session_start();
require_once __DIR__ . '/ja-camera.php';
$eml = $_SESSION['eml'] ?? '';
if ($eml !== '' && !ja_current_user()) $eml = '';   // suspended / deleted accounts see nothing
session_write_close();   // do not hold the session lock while streaming

function ja_cam_deny($code) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo $code === 401 ? 'Log in to see this photo.' : 'Not found.'; exit; }

if ($eml === '') ja_cam_deny(401);
$id = (int)($_GET['id'] ?? 0);
$row = ja_cam_get_photo(ja_db(), $eml, $id);
if (!$row) ja_cam_deny(404);               // someone else's photo looks exactly like a missing one
$thumb = ($_GET['t'] ?? 'full') === 'thumb';
$abs = ja_cam_abs($thumb ? $row['thumbpath'] : $row['filepath']);
if (!$abs) ja_cam_deny(404);

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
if ($thumb) {
    $etag = '"' . md5($row['thumbpath'] . filemtime($abs)) . '"';
    header('Cache-Control: private, max-age=3600');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
} else {
    header('Cache-Control: private, no-store');
}
if (!empty($_GET['dl'])) {
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $row['caption'] !== '' ? $row['caption'] : 'photo');
    $name = trim(substr($name, 0, 40), '-') ?: 'photo';
    header('Content-Disposition: attachment; filename="journeyai-' . $name . '-' . (int)$row['photo_id'] . '.' . $ext . '"');
} else {
    header('Content-Disposition: inline');
}
header('Content-Length: ' . filesize($abs));
readfile($abs);
