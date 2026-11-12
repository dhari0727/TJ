<?php
/**
 * Where-to-stay proxy for the trip page.
 *   GET|POST stays-api.php?dest=Jaipur,+India&days=3&style=mid-range&interests=history,food&party=2[&html=1]
 *   -> the ML service's /stays answer as JSON (plus "html" with rendered cards when html=1).
 * No login needed. Inputs are clamped and whitelisted (ja_stays_params), rate limited per IP like suggest.php.
 */
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-stays.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST + $_GET : $_GET;
$p = ja_stays_params($in);
$wantHtml = !empty($in['html']);

if ($p['dest'] === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'dest is required']);
    exit;
}
if (!ja_rate_limit('stays:ip:' . ja_client_ip(), 30, 60)) {
    http_response_code(429);
    header('Retry-After: 30');
    $d = ['status' => 'error', 'error' => 'Too many requests. Wait a few seconds and try again.'];
    if ($wantHtml) $d['html'] = ja_stays_html($d);
    echo json_encode($d);
    exit;
}
@set_time_limit(170);
$d = ja_stays_fetch($p);
if (($d['status'] ?? '') === 'ok') {
    header('Cache-Control: private, max-age=300');
} else {
    http_response_code(!empty($d['down']) ? 503 : 502);
}
if ($wantHtml) $d['html'] = ja_stays_html($d);
echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
