<?php
/**
 * JourneyAI - the only public door for images Instagram has to download (for direct posting from a public https site).
 *   ig-media.php?t=<token>
 * The token is random (256 bits), tied to one image a member explicitly queued by confirming an Instagram post, and valid for
 * 15 minutes. Anything else (wrong, unknown, expired token) gets the same plain 404. No listing, no other files.
 */
require_once __DIR__ . '/ja-instagram-kit.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function ig_media_404() { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo 'Not found'; exit; }
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($m !== 'GET' && $m !== 'HEAD') { http_response_code(405); header('Allow: GET, HEAD'); exit; }
if (!ja_rate_limit('igmedia:' . ja_client_ip(), 300, 60)) { http_response_code(429); exit; }
$row = igk_queue_get($_GET['t'] ?? '');
if (!$row) ig_media_404();
$file = igk_queue_dir() . '/' . basename($row['path']);
if (!is_file($file)) ig_media_404();
$conn = ja_db(); $id = (int)$row['id'];
mysqli_query($conn, "UPDATE ig_media_queue SET hits = hits + 1 WHERE id = $id");
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=0, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
if ($m === 'GET') readfile($file);
