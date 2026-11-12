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

/* ---- security headers (sent once per request; harmless on JSON endpoints) ---- */
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://nominatim.openstreetmap.org https://cdn.jsdelivr.net; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    header('Permissions-Policy: camera=(self), microphone=(), payment=(), usb=()');   // camera: the in-site Camera page
    header('Cross-Origin-Opener-Policy: same-origin');
}

/**
 * A password change/reset bumps signup.session_epoch; any session carrying an older epoch is signed out.
 * Sessions created before this feature carry no epoch and count as 0, which matches until a first change.
 */
if (!function_exists('ja_enforce_session_epoch')) {
function ja_enforce_session_epoch($c = null) {
    if ($c === null) $c = $GLOBALS['conn'] ?? null;
    if (!$c) return;
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['eml']) || !empty($GLOBALS['ja_epoch_checked'])) return;
    $GLOBALS['ja_epoch_checked'] = true;
    $q = mysqli_prepare($c, "SELECT session_epoch FROM signup WHERE eml = ?");
    if (!$q) return;
    mysqli_stmt_bind_param($q, 's', $_SESSION['eml']); mysqli_stmt_execute($q);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
    if ($r === null || (int)$r[0] !== (int)($_SESSION['ep'] ?? 0)) {
        $_SESSION = []; session_destroy();
        if (!headers_sent()) { header('Location: login.php?expired=1'); }
        exit;
    }
}
}
ja_enforce_session_epoch($conn);
