<?php
/**
 * Destination suggestions for the command palette.
 *   GET suggest.php?q=jai   ->   {"q":"jai","results":[{"name":"Jaipur, India","region":"North India","url":"destination.php?d=Jaipur%2C+India"}, ...]}
 * Prepared LIKE query on `destinations`, rate limited per IP, no session needed.
 */
require_once __DIR__ . '/ja-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr(preg_replace('/\s+/', ' ', $q), 0, 60);
if (mb_strlen($q) < 2) { echo json_encode(['q' => $q, 'results' => []]); exit; }

if (!ja_rate_limit('suggest:ip:' . ja_client_ip(), 120, 60)) {
    http_response_code(429);
    header('Retry-After: 30');
    echo json_encode(['q' => $q, 'results' => [], 'error' => 'Too many searches. Wait a few seconds.']);
    exit;
}

$esc = addcslashes($q, '%_\\');
$starts = $esc . '%';
$contains = '%' . $esc . '%';
$conn = ja_db();
$st = mysqli_prepare($conn, "SELECT canonical_name, region FROM destinations
    WHERE canonical_name LIKE ? OR city LIKE ?
    ORDER BY (canonical_name LIKE ?) DESC, (popularity_tier = 'mainstream') DESC, CHAR_LENGTH(canonical_name), canonical_name
    LIMIT 8");
mysqli_stmt_bind_param($st, 'sss', $contains, $contains, $starts);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$out = [];
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $out[] = ['name' => $r['canonical_name'], 'region' => (string)$r['region'],
              'url' => 'destination.php?d=' . urlencode($r['canonical_name'])];
}
mysqli_stmt_close($st);
echo json_encode(['q' => $q, 'results' => $out], JSON_UNESCAPED_UNICODE);
