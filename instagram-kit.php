<?php
/**
 * JourneyAI - Instagram Kit endpoint (login required, owner only, public + approved items only).
 *
 *   GET ?action=meta&kind=&id=[&path=]                          JSON: formats, templates, slide counts, caption, hook, hashtags, alt text, direct-posting state
 *   GET ?action=slide&kind=&id=[&path=]&format=feed|square|story|carousel&template=photo|polaroid|journey|quote&n=0[&dl=1]
 *                                                                 one finished JPEG (1080x1350, 1080x1080 or 1080x1920)
 * Nothing is stored. Images are drawn on request with PHP GD and sent with no metadata.
 */
require_once __DIR__ . '/ja-instagram-kit.php';

$u = ja_current_user();
$eml = $u ? $u['eml'] : null;
$action = (string)($_GET['action'] ?? '');
function igk_json($d, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') igk_json(['ok' => false, 'error' => 'Use GET.'], 405);
if (!$eml) igk_json(['ok' => false, 'error' => 'Log in to make Instagram images.'], 401);
$kind = (string)($_GET['kind'] ?? '');
if (!in_array($kind, ['journal', 'book', 'post', 'plan', 'upload'], true)) igk_json(['ok' => false, 'error' => 'Unknown kind.'], 400);
if (!ja_rate_limit('igkit:' . strtolower($eml), 400, 60)) igk_json(['ok' => false, 'error' => 'Too many requests. Wait a moment.'], 429);
[$item, $err] = igk_item_ok($kind, $_GET['id'] ?? 0, $eml, $_GET['path'] ?? null);
session_write_close();
if (!$item) igk_json(['ok' => false, 'error' => $err], 404);

if ($action === 'meta') {
    try { $m = igk_meta($item); } catch (Throwable $e) { igk_json(['ok' => false, 'error' => 'Could not prepare the Instagram images.'], 500); }
    $m['ok'] = true; $m['kind'] = $kind; $m['id'] = $item['id'];
    $m['direct'] = igk_direct_state($eml);
    $m['public_site'] = igk_public_ok();
    igk_json($m);
}
if ($action === 'slide') {
    $format = (string)($_GET['format'] ?? 'feed');
    if (!isset(IGK_FORMATS[$format])) igk_json(['ok' => false, 'error' => 'Unknown format.'], 400);
    $tpl = (string)($_GET['template'] ?? 'photo');
    try { $bytes = igk_render($item, $format, $tpl, (int)($_GET['n'] ?? 0)); }
    catch (Throwable $e) { igk_json(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not draw this image.'], 500); }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($item['title'])), '-') ?: 'journeyai';
        header('Content-Disposition: attachment; filename="' . substr($slug, 0, 40) . '-' . $format . '-' . (((int)($_GET['n'] ?? 0)) + 1) . '.jpg"');
    }
    echo $bytes; exit;
}
igk_json(['ok' => false, 'error' => 'Unknown action.'], 400);
