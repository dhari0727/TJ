<?php
/**
 * JourneyAI — health check for uptime monitors / load balancers.
 *   GET /health.php   -> 200 {"status":"ok"} when the database and recommendation service both answer,
 *                        503 {"status":"degraded", ...} otherwise. Public output is deliberately minimal.
 * Signed-in admins also get details (disk, uploads folder, email setup).
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/connection.php';   // exits with 503 by itself if the database is unreachable
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ml_client.php';

$dbOk = (bool)@mysqli_query($conn, 'SELECT 1');
$mlOk = ml_is_up();
$ok = $dbOk && $mlOk;
http_response_code($ok ? 200 : 503);
$out = ['status' => $ok ? 'ok' : 'degraded', 'database' => $dbOk, 'recommender' => $mlOk];

if (ja_is_admin()) {
    $free = @disk_free_space(__DIR__); $total = @disk_total_space(__DIR__);
    $out['details'] = [
        'disk_free_gb' => $free !== false ? round($free / 1073741824, 1) : null,
        'disk_used_pct' => ($free !== false && $total) ? round(100 - $free * 100 / $total) : null,
        'uploads_writable' => is_writable(__DIR__ . '/uploads'),
        'secret_key_present' => is_file(__DIR__ . '/config/secret.key'),
        'email_configured' => function_exists('ja_setting') && ja_setting('smtp_enabled') === '1',
        'php' => PHP_VERSION,
    ];
}
echo json_encode($out);
