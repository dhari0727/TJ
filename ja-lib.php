<?php
/**
 * JourneyAI — shared helpers: DB-backed settings (secrets encrypted), auth guards, CSRF.
 *
 *   require_once 'ja-lib.php';
 *   ja_setting('site_name');                 // read
 *   ja_setting_set('smtp_host', 'x', false); // write (3rd arg true => encrypt)
 *   $u = ja_require_login();                 // redirects to login.php if not signed in
 *   $u = ja_require_admin();                 // 403 page unless role = admin
 */
if (session_status() === PHP_SESSION_NONE) session_start();
/** The shared DB connection (global $conn), opened on first use even if connection.php was already included elsewhere. */
function ja_db() {
    global $conn;
    if (!($conn instanceof mysqli)) { include __DIR__ . '/connection.php'; }
    return $conn;
}
ja_db();

/* ---------- encryption for secrets stored in `settings` ---------- */
function ja_secret_key() {
    $dir = __DIR__ . '/config';
    $file = $dir . '/secret.key';
    if (!is_file($file)) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($file, bin2hex(random_bytes(32)));
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return hex2bin(trim((string)@file_get_contents($file))) ?: str_repeat("\0", 32);
}
function ja_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', ja_secret_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}
function ja_decrypt($blob) {
    if ($blob === '' || $blob === null) return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', ja_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}

/* ---------- settings ---------- */
function ja_settings_all() {
    global $conn; ja_db();
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $r = @mysqli_query($conn, "SELECT skey, svalue, is_secret FROM settings");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $cache[$row['skey']] = $row['is_secret'] ? ja_decrypt($row['svalue']) : $row['svalue'];
    }
    return $cache;
}
function ja_setting($key, $default = '') {
    $all = ja_settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
}
function ja_setting_set($key, $value, $secret = false) {
    global $conn; ja_db();
    $store = $secret ? ja_encrypt((string)$value) : (string)$value;
    $sec = $secret ? 1 : 0;
    $st = mysqli_prepare($conn, "INSERT INTO settings (skey, svalue, is_secret) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), is_secret = VALUES(is_secret)");
    mysqli_stmt_bind_param($st, 'ssi', $key, $store, $sec);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    return $ok;
}

/* ---------- current user + guards ---------- */
function ja_current_user() {
    global $conn; ja_db();
    if (empty($_SESSION['eml'])) return null;
    static $u = false;
    if ($u !== false) return $u;
    $st = mysqli_prepare($conn, "SELECT fname, lname, eml, role, is_active FROM signup WHERE eml = ? LIMIT 1");
    mysqli_stmt_bind_param($st, 's', $_SESSION['eml']);
    mysqli_stmt_execute($st);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
    mysqli_stmt_close($st);
    if ($u && !(int)$u['is_active']) {   // suspended while signed in -> sign out
        session_unset(); session_destroy();
        header('Location: login.php?suspended=1'); exit;
    }
    return $u;
}
function ja_is_admin() {
    $u = ja_current_user();
    return $u && $u['role'] === 'admin';
}
function ja_require_login() {
    $u = ja_current_user();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}
function ja_require_admin() {
    $u = ja_require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Not allowed</title><body style="font-family:sans-serif;padding:60px;text-align:center"><h1>Admins only</h1><p><a href="dashboard.php">Back to Home</a></p></body>';
        exit;
    }
    return $u;
}

/* ---------- CSRF for state-changing admin/account forms ---------- */
function ja_csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function ja_csrf_field() { return '<input type="hidden" name="csrf" value="' . htmlspecialchars(ja_csrf()) . '">'; }
function ja_csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/* ---------- per-user data helpers (used by Profile and Admin) ---------- */
/** Every real table that stores rows by user email (except signup). Discovered, not hard-coded, so new features are covered. */
function ja_eml_tables() {
    global $conn; ja_db();
    static $t = null;
    if ($t !== null) return $t;
    $t = [];
    $r = mysqli_query($conn, "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'eml' AND t.TABLE_TYPE = 'BASE TABLE' AND c.TABLE_NAME <> 'signup'");
    while ($r && ($row = mysqli_fetch_row($r))) $t[] = $row[0];
    return $t;
}

/** Change a user's email everywhere (all tables). Returns true on success. */
function ja_rename_user($old, $new) {
    global $conn; ja_db();
    mysqli_begin_transaction($conn);
    try {
        foreach (array_merge(['signup'], ja_eml_tables()) as $tbl) {
            if ($tbl === 'login_attempts') continue;
            $st = mysqli_prepare($conn, "UPDATE `$tbl` SET eml = ? WHERE eml = ?");
            mysqli_stmt_bind_param($st, 'ss', $new, $old);
            if (!mysqli_stmt_execute($st)) throw new Exception(mysqli_error($conn));
            mysqli_stmt_close($st);
        }
        mysqli_commit($conn);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return false;
    }
}

/** Delete a user and everything they own (rows + uploaded files). */
function ja_delete_user_data($eml) {
    global $conn; ja_db();
    $st = mysqli_prepare($conn, "SELECT filepath FROM media WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $files = array_column(mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC), 'filepath');
    mysqli_stmt_close($st);
    // child rows keyed by something other than eml
    @mysqli_query($conn, "DELETE mh FROM media_hashtags mh JOIN media m ON m.media_id = mh.media_id WHERE m.eml = '" . mysqli_real_escape_string($conn, $eml) . "'");
    @mysqli_query($conn, "DELETE pi FROM packing_items pi JOIN saved_plans sp ON sp.plan_id = pi.plan_id WHERE sp.eml = '" . mysqli_real_escape_string($conn, $eml) . "'");
    foreach (ja_eml_tables() as $tbl) {
        $st = mysqli_prepare($conn, "DELETE FROM `$tbl` WHERE eml = ?");
        mysqli_stmt_bind_param($st, 's', $eml); @mysqli_stmt_execute($st); mysqli_stmt_close($st);
    }
    $st = mysqli_prepare($conn, "DELETE FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st); mysqli_stmt_close($st);
    $root = realpath(__DIR__ . '/uploads');
    foreach ($files as $f) {
        $p = realpath(__DIR__ . '/' . $f);
        if ($p && $root && strpos($p, $root) === 0) @unlink($p);
    }
}

/** Saved travel preferences with safe defaults. */
function ja_user_prefs($eml) {
    global $conn; ja_db();
    $d = ['home_city' => '', 'default_budget' => 30000, 'travel_style' => 'mid-range', 'party_size' => 1, 'interests' => [], 'notify_email' => 1];
    if (!$eml) return $d;
    $st = mysqli_prepare($conn, "SELECT * FROM user_prefs WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$r) return $d;
    return [
        'home_city' => (string)$r['home_city'],
        'default_budget' => $r['default_budget'] ? (int)$r['default_budget'] : $d['default_budget'],
        'travel_style' => $r['travel_style'] ?: $d['travel_style'],
        'party_size' => $r['party_size'] ? (int)$r['party_size'] : 1,
        'interests' => array_values(array_filter(explode(',', (string)$r['interests']))),
        'notify_email' => (int)$r['notify_email'],
    ];
}

/**
 * Sliding-window rate limit. Returns true if this hit is allowed (and records it), false if over the limit.
 *   if (!ja_rate_limit('forgot:ip:' . ja_client_ip(), 5, 3600)) { ...too many... }
 */
function ja_rate_limit($bucket, $max, $windowSeconds) {
    global $conn; ja_db();
    $bucket = mb_substr($bucket, 0, 120);
    $st = mysqli_prepare($conn, "SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)");
    mysqli_stmt_bind_param($st, 'si', $bucket, $windowSeconds); mysqli_stmt_execute($st);
    $n = (int)(mysqli_fetch_row(mysqli_stmt_get_result($st))[0] ?? 0); mysqli_stmt_close($st);
    if ($n >= $max) return false;
    $in = mysqli_prepare($conn, "INSERT INTO rate_limits (bucket) VALUES (?)");
    mysqli_stmt_bind_param($in, 's', $bucket); mysqli_stmt_execute($in); mysqli_stmt_close($in);
    if (mt_rand(1, 50) === 1) @mysqli_query($conn, "DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)");   // tidy up now and then
    return true;
}

/** A user's public profile handle (created on first need). Never derived from the email. */
function ja_ensure_handle($eml) {
    global $conn; ja_db();
    $st = mysqli_prepare($conn, "SELECT fname, handle FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$r) return null;
    if (!empty($r['handle'])) return $r['handle'];
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$r['fname']);
    $base = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$ascii)), 0, 20) ?: 'traveller';
    for ($i = 0; $i < 10; $i++) {
        $h = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $up = mysqli_prepare($conn, "UPDATE signup SET handle = ? WHERE eml = ? AND handle IS NULL");
        mysqli_stmt_bind_param($up, 'ss', $h, $eml);
        if (@mysqli_stmt_execute($up) && mysqli_stmt_affected_rows($up) === 1) { mysqli_stmt_close($up); return $h; }
        mysqli_stmt_close($up);
        $chk = mysqli_prepare($conn, "SELECT handle FROM signup WHERE eml = ?");
        mysqli_stmt_bind_param($chk, 's', $eml); mysqli_stmt_execute($chk);
        $cur = mysqli_fetch_row(mysqli_stmt_get_result($chk))[0] ?? null; mysqli_stmt_close($chk);
        if ($cur) return $cur;
    }
    return null;
}

function ja_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function ja_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
}
