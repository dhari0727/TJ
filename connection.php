<?php
/**
 * JourneyAI — database connection. Sets the global $conn (mysqli).
 *
 * Settings, in priority order:
 *   1. environment variables  JOURNEYAI_DB_HOST / _PORT / _USER / _PASS / _NAME   (Apache SetEnv or system env)
 *   2. config/app.local.php   returning ['host'=>..,'port'=>..,'user'=>..,'pass'=>..,'db'=>..]   (git-ignored)
 *   3. defaults for a local XAMPP install (root, no password, database "project")
 * In production create a least-privilege MySQL user (see sql/create-db-user.sql) and use 1 or 2.
 */
$conn = (function () {
    $cfg = ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => '', 'db' => 'project'];
    $local = __DIR__ . '/config/app.local.php';
    if (is_file($local)) {
        $o = include $local;
        if (is_array($o)) $cfg = array_merge($cfg, array_intersect_key($o, $cfg));
    }
    foreach (['host' => 'JOURNEYAI_DB_HOST', 'port' => 'JOURNEYAI_DB_PORT', 'user' => 'JOURNEYAI_DB_USER', 'pass' => 'JOURNEYAI_DB_PASS', 'db' => 'JOURNEYAI_DB_NAME'] as $k => $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') $cfg[$k] = $v;
    }
    mysqli_report(MYSQLI_REPORT_OFF);   // keep the same behaviour on PHP 8.0 and 8.1+
    $c = @mysqli_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], (int)$cfg['port']);
    if (!$c) {
        error_log('JourneyAI: database connection failed: ' . mysqli_connect_error());
        if (!headers_sent()) { http_response_code(503); header('Retry-After: 30'); }
        echo 'The service is temporarily unavailable. Please try again in a minute.';
        exit;
    }
    mysqli_set_charset($c, 'utf8mb4');
    return $c;
})();
