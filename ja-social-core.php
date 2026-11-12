<?php
/**
 * JourneyAI - social posting core: encryption, HTTP, OAuth state, provider framework, the post pipeline.
 *
 * Providers live in social/<id>.php and extend JaSocialProvider (or JaSocialOAuth). Tiers:
 *   1  share links and the Web Share API (no accounts, see ja-social.php and js/social-share.js)
 *   2  Bluesky (handle + app password) and Mastodon (per-instance OAuth, dynamic app registration): work with no approval
 *   3  Facebook Page, Instagram, X, LinkedIn, Pinterest, Threads: need an app registered by the site owner
 *      (admin-settings.php?tab=social). Without credentials they report "Not set up" and nothing is attempted.
 *
 * Rules enforced here, server side: only the owner posts, only text and images they confirmed, only items that are
 * public and approved (checked again at post time by ja_social_item), rate limits, no tokens in logs or errors.
 */
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-trust.php';   // ja_audit()

const JA_SOCIAL_USER_PER_HOUR = 20;
const JA_SOCIAL_PROVIDER_PER_HOUR = 10;
const JA_SOCIAL_IDS = ['bluesky', 'mastodon', 'facebook', 'instagram', 'x', 'linkedin', 'pinterest', 'threads'];

/* ------------------------------------------------------------------ encryption at rest */
function ja_social_key() {
    static $k = null;
    if ($k !== null) return $k;
    $dir = __DIR__ . '/private';
    $file = $dir . '/social.key';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($file) || strlen(trim((string)@file_get_contents($file))) !== 64) {
        @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    $raw = hex2bin(trim((string)@file_get_contents($file)));
    if ($raw === false || strlen($raw) !== 32) throw new RuntimeException('The social encryption key could not be created. Check that the private folder is writable.');
    return $k = $raw;
}
function ja_social_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt((string)$plain, 'aes-256-gcm', ja_social_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ja-social', 16);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function ja_social_decrypt($blob) {
    if (!is_string($blob) || strncmp($blob, 'v1:', 3) !== 0) return '';
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', ja_social_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'ja-social');
    return $pt === false ? '' : $pt;
}

/* ------------------------------------------------------------------ errors and logging (never tokens) */
function ja_social_clean_error($s, $max = 240) {
    $s = strip_tags((string)$s);
    $s = preg_replace('/(Bearer|Basic|OAuth)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [hidden]', $s);
    $s = preg_replace('/(access_token|refresh_token|client_secret|password|code|token)(["\']?\s*[:=]\s*["\']?)[^"\'&\s,}]+/i', '$1$2[hidden]', $s);
    $s = preg_replace('/[A-Za-z0-9_\-\.]{40,}/', '[hidden]', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_substr($s, 0, $max);
}
/** Plain-language reason for an HTTP failure from a platform. */
function ja_social_http_reason($label, $status, $remoteMsg = '') {
    $m = ja_social_clean_error($remoteMsg, 160);
    if ($status === 0) return "Could not reach $label. Check the connection and try again.";
    if ($status === 401) return "$label no longer accepts this sign-in. Reconnect the account in your profile.";
    if ($status === 403) return "$label refused the request" . ($m ? " ($m)" : '') . '. The app may not have permission yet, or the account has a restriction.';
    if ($status === 413) return "$label says the image is too large.";
    if ($status === 422 || $status === 400) return "$label did not accept this post" . ($m ? ": $m" : '.');
    if ($status === 429) return "$label says there were too many requests. Wait a few minutes and try again.";
    if ($status >= 500) return "$label is having trouble right now. Try again later.";
    return "$label returned an error ($status)" . ($m ? ": $m" : '.');
}

/* ------------------------------------------------------------------ URLs and HTTP */
function ja_social_is_local_host($host) { return (bool)preg_match('/^(localhost|127\.0\.0\.1|\[?::1\]?)$/i', (string)$host); }
/** True when this site itself runs on a local development address. */
function ja_social_dev() {
    $h = parse_url(ja_base_url(), PHP_URL_HOST);
    return ja_social_is_local_host($h);
}
/** Is the site address reachable from the internet (https, not local or private)? Instagram and Threads need this. */
function ja_social_base_public() {
    $u = parse_url(ja_base_url());
    if (($u['scheme'] ?? '') !== 'https') return false;
    $h = $u['host'] ?? '';
    if ($h === '' || ja_social_is_local_host($h) || !preg_match('/\./', $h)) return false;
    if (filter_var($h, FILTER_VALIDATE_IP) && !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}
function ja_social_redirect_uri() { return rtrim(ja_base_url(), '/') . '/social-callback.php'; }

/**
 * May the server call this URL? https only, except localhost on a local dev install (used by the test mock).
 * For hosts a user typed (Mastodon instances) the name must not resolve to a private or reserved address.
 * Returns the IP to pin for user-supplied hosts, true/false otherwise.
 */
function ja_social_url_ok($url, $userSupplied = false) {
    $p = parse_url((string)$url);
    if (!$p || empty($p['host']) || empty($p['scheme'])) return false;
    $host = strtolower($p['host']);
    if (!empty($p['user']) || !empty($p['pass'])) return false;
    if (ja_social_is_local_host($host)) return ja_social_dev() && in_array($p['scheme'], ['http', 'https'], true);
    if ($p['scheme'] !== 'https') return false;
    if (!$userSupplied) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else {
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host)) return false;
        $ips = gethostbynamel($host) ?: [];
    }
    if (!$ips) return false;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return $ips[0];
}

/**
 * One HTTP call. $body: string, array (form-encoded) or ['__multipart' => [...]] for file uploads.
 * Returns ['status','body','json','headers','error']. Never follows redirects.
 */
function ja_social_http($method, $url, array $headers = [], $body = null, array $opt = []) {
    $user = !empty($opt['user_supplied']);
    $ok = ja_social_url_ok($url, $user);
    if (!$ok) return ['status' => 0, 'body' => '', 'json' => null, 'headers' => [], 'error' => 'That address is not allowed.'];
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = is_int($k) ? $v : "$k: $v";
    $hdrOut = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $opt['timeout'] ?? 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h,
        CURLOPT_USERAGENT => 'JourneyAI-social/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrOut) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $hdrOut[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    if (is_string($ok) && $ok !== '') {   // pin the address we validated so DNS cannot change between check and use
        $pu = parse_url($url); $port = $pu['port'] ?? 443;
        curl_setopt($ch, CURLOPT_RESOLVE, [$pu['host'] . ':' . $port . ':' . $ok]);
    }
    if (!ini_get('curl.cainfo') && !ini_get('openssl.cafile')) {
        foreach (['C:/xampp/apache/bin/curl-ca-bundle.crt', 'C:/xampp/php/extras/ssl/cacert.pem'] as $ca) if (is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
    }
    if (is_array($body) && isset($body['__multipart'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $body['__multipart']);
    elseif (is_array($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body, '', '&'));
    elseif ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $out === false ? curl_error($ch) : '';
    curl_close($ch);
    $raw = $out === false ? '' : (string)$out;
    $json = json_decode($raw, true);
    return ['status' => $status, 'body' => $raw, 'json' => is_array($json) ? $json : null, 'headers' => $hdrOut, 'error' => ja_social_clean_error($err)];
}
function ja_social_form_headers() { return ['Content-Type' => 'application/x-www-form-urlencoded']; }
function ja_social_json_headers($token = null) { $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json']; if ($token) $h['Authorization'] = 'Bearer ' . $token; return $h; }
function ja_social_remote_msg($r) {
    $j = $r['json'] ?? null;
    if (is_array($j)) {
        foreach (['message', 'error_description', 'detail', 'title'] as $k) if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
        if (!empty($j['error']) && is_string($j['error'])) return $j['error'];
        if (!empty($j['error']['message'])) return (string)$j['error']['message'];
        if (!empty($j['errors'][0]['message'])) return (string)$j['errors'][0]['message'];
    }
    return '';
}

/* ------------------------------------------------------------------ text length */
function ja_social_graphemes($t) {
    if (function_exists('grapheme_strlen')) { $n = grapheme_strlen((string)$t); if ($n !== false && $n !== null) return $n; }
    return preg_match_all('/\X/u', (string)$t) ?: 0;
}
/** mode: graphemes (Bluesky), url23 (Mastodon, X: every link counts as 23), chars. */
function ja_social_count($text, $mode) {
    $text = (string)$text;
    if ($mode === 'url23') {
        $text = preg_replace('~https?://[^\s]+~u', str_repeat('x', 23), $text);
        return ja_social_graphemes($text);
    }
    return $mode === 'graphemes' ? ja_social_graphemes($text) : mb_strlen($text);
}

/* ------------------------------------------------------------------ images (strip EXIF/GPS, fit platform limits) */
/** Resolve a stored uploads/ path to a real file inside uploads/, or null. */
function ja_social_abs_media($rel) {
    $p = ja_safe_media_path($rel);
    if ($p === null || $p === '') return null;
    $abs = realpath(__DIR__ . '/' . $p); $root = realpath(__DIR__ . '/uploads');
    return ($abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}
/** Re-encode as JPEG (drops EXIF/GPS and any metadata), scaled and compressed to fit $maxBytes. Returns [bytes, mime] or null. */
function ja_social_image_bytes($abs, $maxBytes, $maxSide = 2048) {
    if (!function_exists('imagecreatefromstring')) return null;
    $raw = @file_get_contents($abs);
    $im = $raw === false ? false : @imagecreatefromstring($raw);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $s = min(1, $maxSide / max($w, $h));
    if ($s < 1) { $im2 = imagescale($im, max(1, (int)round($w * $s)), max(1, (int)round($h * $s))); if ($im2) { imagedestroy($im); $im = $im2; } }
    $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
    imagedestroy($im);
    for ($q = 88; $q >= 40; $q -= 8) {
        ob_start(); imagejpeg($flat, null, $q); $out = ob_get_clean();
        if (strlen($out) <= $maxBytes) { imagedestroy($flat); return [$out, 'image/jpeg']; }
        $nw = (int)(imagesx($flat) * 0.85); $nh = (int)(imagesy($flat) * 0.85);
        if ($q <= 64 && $nw > 600) { $t = imagescale($flat, $nw, $nh); if ($t) { imagedestroy($flat); $flat = $t; } }
    }
    imagedestroy($flat);
    return null;
}

/* ------------------------------------------------------------------ account storage */
function ja_social_account_public(array $r) {
    $meta = json_decode((string)($r['meta'] ?? ''), true) ?: [];
    return ['id' => (int)$r['account_id'], 'provider' => $r['provider'], 'handle' => $r['handle'], 'name' => $r['display_name'],
            'status' => $r['status'], 'connected' => $r['created_at'], 'meta' => array_intersect_key($meta, ['max_chars' => 1, 'max_media' => 1, 'host' => 1, 'board' => 1])];
}
function ja_social_accounts($eml, $provider = null) {
    $conn = ja_db();
    if ($provider) { $st = mysqli_prepare($conn, "SELECT * FROM social_accounts WHERE eml = ? AND provider = ? ORDER BY account_id"); mysqli_stmt_bind_param($st, 'ss', $eml, $provider); }
    else { $st = mysqli_prepare($conn, "SELECT * FROM social_accounts WHERE eml = ? ORDER BY provider, account_id"); mysqli_stmt_bind_param($st, 's', $eml); }
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC); mysqli_stmt_close($st);
    return $rows;
}
function ja_social_account($eml, $id) {
    $conn = ja_db(); $id = (int)$id;
    $st = mysqli_prepare($conn, "SELECT * FROM social_accounts WHERE account_id = ? AND eml = ?");
    mysqli_stmt_bind_param($st, 'is', $id, $eml); mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    return $r ?: null;
}
function ja_social_account_save($eml, $provider, $remoteId, $handle, $name, $access, $refresh, $expiresTs, $scopes, array $meta = []) {
    $conn = ja_db();
    $enc = ja_social_encrypt($access); $renc = $refresh !== null && $refresh !== '' ? ja_social_encrypt($refresh) : null;
    $exp = $expiresTs ? date('Y-m-d H:i:s', (int)$expiresTs) : null;
    $handle = mb_substr((string)$handle, 0, 190); $name = mb_substr((string)$name, 0, 190); $scopes = mb_substr((string)$scopes, 0, 255);
    $mj = json_encode($meta);
    $st = mysqli_prepare($conn, "INSERT INTO social_accounts (eml, provider, remote_id, handle, display_name, token_enc, refresh_enc, expires_at, scopes, meta, status)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'active') ON DUPLICATE KEY UPDATE handle = VALUES(handle), display_name = VALUES(display_name),
        token_enc = VALUES(token_enc), refresh_enc = VALUES(refresh_enc), expires_at = VALUES(expires_at), scopes = VALUES(scopes), meta = VALUES(meta), status = 'active'");
    mysqli_stmt_bind_param($st, 'ssssssssss', $eml, $provider, $remoteId, $handle, $name, $enc, $renc, $exp, $scopes, $mj);
    $ok = mysqli_stmt_execute($st); mysqli_stmt_close($st);
    return $ok;
}
function ja_social_account_update_tokens($id, $access, $refresh, $expiresTs, $status = 'active') {
    $conn = ja_db();
    $enc = ja_social_encrypt($access); $renc = $refresh !== null && $refresh !== '' ? ja_social_encrypt($refresh) : null;
    $exp = $expiresTs ? date('Y-m-d H:i:s', (int)$expiresTs) : null;
    $st = mysqli_prepare($conn, "UPDATE social_accounts SET token_enc = ?, refresh_enc = COALESCE(?, refresh_enc), expires_at = ?, status = ? WHERE account_id = ?");
    mysqli_stmt_bind_param($st, 'ssssi', $enc, $renc, $exp, $status, $id); mysqli_stmt_execute($st); mysqli_stmt_close($st);
}
function ja_social_account_status($id, $status) {
    $conn = ja_db(); $st = mysqli_prepare($conn, "UPDATE social_accounts SET status = ? WHERE account_id = ?");
    mysqli_stmt_bind_param($st, 'si', $status, $id); mysqli_stmt_execute($st); mysqli_stmt_close($st);
}
function ja_social_tokens(array $a) { return [ja_social_decrypt((string)$a['token_enc']), ja_social_decrypt((string)($a['refresh_enc'] ?? ''))]; }
function ja_social_meta(array $a) { return json_decode((string)($a['meta'] ?? ''), true) ?: []; }

/** Disconnect: ask the platform to revoke (best effort), then delete the tokens and the account row. */
function ja_social_disconnect($eml, $accountId) {
    $a = ja_social_account($eml, $accountId);
    if (!$a) return false;
    $p = ja_social_provider($a['provider']);
    if ($p) { try { $p->revoke($a); } catch (Throwable $e) { /* the local delete below is what matters */ } }
    $conn = ja_db();
    $st = mysqli_prepare($conn, "DELETE FROM social_accounts WHERE account_id = ? AND eml = ?");
    mysqli_stmt_bind_param($st, 'is', $a['account_id'], $eml); mysqli_stmt_execute($st); mysqli_stmt_close($st);
    ja_audit($eml, 'social_disconnect', $a['provider'] . ' ' . $a['handle']);
    return true;
}
/** Used when a user deletes their account: revoke and remove every connection (post history goes with the generic cleanup). */
function ja_social_purge_user($eml) {
    foreach (ja_social_accounts($eml) as $a) ja_social_disconnect($eml, $a['account_id']);
}

/* ------------------------------------------------------------------ settings for owner-registered apps */
/** A setting read straight from the database (the per-request cache in ja_setting is stale right after a write). */
function ja_fresh_setting($key) {
    $conn = ja_db(); $key = (string)$key;
    $st = mysqli_prepare($conn, "SELECT svalue, is_secret FROM settings WHERE skey = ?");
    mysqli_stmt_bind_param($st, 's', $key); mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$r) return '';
    return $r['is_secret'] ? ja_decrypt($r['svalue']) : (string)$r['svalue'];
}
function ja_social_app_creds($provider) {
    // Instagram has its own app ID and secret (Instagram API with Instagram Login), separate from the Facebook Page app
    return [trim((string)ja_setting("social_{$provider}_client_id")), trim((string)ja_setting("social_{$provider}_client_secret"))];
}

/* ------------------------------------------------------------------ provider framework */
abstract class JaSocialProvider {
    public $id = ''; public $label = ''; public $tier = 3;
    /** 'password' (Bluesky), 'instance' (Mastodon) or 'oauth' */
    public $auth = 'oauth';
    public $needsApp = false;
    public $note = '';
    /** Whether the owner configured everything needed. Tier 2 providers are always ready. */
    public function configured() { return true; }
    /** ['chars'=>int,'counter'=>mode,'images'=>int,'image_required'=>bool,'alt'=>bool,'needs_public_url'=>bool,'visibility'=>bool] */
    abstract public function limits(array $account = null);
    public function validate(array $payload, array $account) {
        $l = $this->limits($account);
        $n = ja_social_count($payload['text'], $l['counter']);
        if (trim($payload['text']) === '' && !$payload['images']) return 'Write some text or choose a photo first.';
        if ($n > $l['chars']) return "That is $n characters and {$this->label} allows {$l['chars']}. Shorten the text for {$this->label}.";
        if (count($payload['images']) > $l['images']) return "{$this->label} allows up to {$l['images']} photo" . ($l['images'] == 1 ? '' : 's') . ' in a post.';
        if ($l['image_required'] && !$payload['images']) return "{$this->label} posts need a photo. Choose one.";
        if (!empty($l['needs_public_url']) && $payload['images'] && !ja_social_base_public())
            return "{$this->label} downloads the photo from your website's public address, and this site is not reachable from the internet yet (it needs to be online over https). Use Download image and post it from the {$this->label} app, or try again once the site is live.";
        return null;
    }
    public function connectStart($eml, array $in) { return ['ok' => false, 'error' => 'This service does not use a sign-in redirect.']; }
    public function connectComplete($eml, array $state, array $query) { return ['ok' => false, 'error' => 'Not supported.']; }
    public function connectPassword($eml, array $in) { return ['ok' => false, 'error' => 'Not supported.']; }
    /** Refresh the access token when needed. Returns the (possibly updated) account row or null if it cannot be used. */
    public function ensureFresh(array $account) { return $account; }
    /** @return array ['ok'=>bool,'remote_id'=>string,'remote_url'=>string,'error'=>string] */
    abstract public function post(array $account, array $payload);
    public function revoke(array $account) {}
    /** "Test connection": ['ok'=>bool,'message'=>plain text]. Providers that can check their sign-in override this. */
    public function testConnection(array $account) { return ['ok' => false, 'message' => 'This service has no connection test.']; }
    protected function fail($msg) { return ['ok' => false, 'remote_id' => '', 'remote_url' => '', 'error' => ja_social_clean_error($msg)]; }
    protected function done($id, $url) { return ['ok' => true, 'remote_id' => (string)$id, 'remote_url' => (string)$url, 'error' => '']; }
    /** Prepared image bytes for payload image $i: [bytes, mime] or null. */
    protected function imageBytes(array $img, $maxBytes, $maxSide = 2048) {
        $abs = ja_social_abs_media($img['rel']);
        return $abs ? ja_social_image_bytes($abs, $maxBytes, $maxSide) : null;
    }
    protected function publicImageUrl(array $img) { return rtrim(ja_base_url(), '/') . '/' . ltrim($img['rel'], '/'); }
}

/** OAuth 2 (authorization code, optional PKCE) with the owner's app credentials. Subclasses fill in endpoints and profile(). */
abstract class JaSocialOAuth extends JaSocialProvider {
    public $needsApp = true;
    protected $authorizeUrl = ''; protected $tokenUrl = ''; protected $scope = ''; protected $scopeSep = ' ';
    protected $pkce = false; protected $clientAuth = 'body';
    public function configured() { [$i, $s] = ja_social_app_creds($this->id); return $i !== '' && $s !== ''; }
    protected function creds($state = null) { return ja_social_app_creds($this->id); }
    protected function authParams() { return []; }
    /** @return array list of ['remote_id','handle','name','token'(optional),'meta'(optional)] */
    abstract protected function profiles($token, array $tokenResp, $eml);

    public function connectStart($eml, array $in) {
        if (!$this->configured()) return ['ok' => false, 'error' => $this->label . ' is not set up by the site owner yet.'];
        [$cid] = $this->creds();
        $state = ja_social_oauth_begin($this->id, $eml, $this->pkce, $in);
        $q = ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => $state['redirect'], 'scope' => $this->scope, 'state' => $state['state']] + $this->authParams();
        if ($this->pkce) { $q['code_challenge'] = $state['challenge']; $q['code_challenge_method'] = 'S256'; }
        return ['ok' => true, 'url' => $this->authorizeUrl . (strpos($this->authorizeUrl, '?') === false ? '?' : '&') . http_build_query($q, '', '&', PHP_QUERY_RFC3986)];
    }
    protected function tokenRequest(array $fields, array $st = []) {
        [$cid, $sec] = $this->creds($st);
        $h = ja_social_form_headers(); $h['Accept'] = 'application/json';
        if ($this->clientAuth === 'basic') $h['Authorization'] = 'Basic ' . base64_encode($cid . ':' . $sec);
        else { $fields['client_id'] = $cid; $fields['client_secret'] = $sec; }
        return ja_social_http('POST', $this->tokenUrl, $h, $fields);
    }
    public function connectComplete($eml, array $state, array $query) {
        $code = (string)($query['code'] ?? '');
        if ($code === '') return ['ok' => false, 'error' => 'The ' . $this->label . ' sign-in did not return a code.'];
        $f = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $state['redirect']];
        if ($this->pkce) $f['code_verifier'] = $state['verifier'];
        $r = $this->tokenRequest($f, $state);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['access_token'])) return ['ok' => false, 'error' => ja_social_http_reason($this->label, $r['status'], ja_social_remote_msg($r))];
        $exp = !empty($j['expires_in']) ? time() + (int)$j['expires_in'] : null;
        $profiles = $this->profiles($j['access_token'], $j, $eml);
        if (is_string($profiles)) return ['ok' => false, 'error' => $profiles];
        if (!$profiles) return ['ok' => false, 'error' => 'No account could be found to connect on ' . $this->label . '.'];
        $n = 0;
        foreach ($profiles as $pr) {
            $tok = $pr['token'] ?? $j['access_token'];
            $ok = ja_social_account_save($eml, $this->id, $pr['remote_id'], $pr['handle'], $pr['name'], $tok, $pr['refresh'] ?? ($j['refresh_token'] ?? null), $pr['expires'] ?? $exp, $j['scope'] ?? $this->scope, $pr['meta'] ?? []);
            if ($ok) $n++;
        }
        return ['ok' => $n > 0, 'count' => $n, 'error' => $n ? '' : 'Could not save the account.'];
    }
    public function ensureFresh(array $a) {
        if (!$a['expires_at'] || strtotime($a['expires_at']) > time() + 90) return $a;
        [$acc, $ref] = ja_social_tokens($a);
        if ($ref === '') { ja_social_account_status($a['account_id'], 'expired'); return null; }
        $r = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $ref]);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['access_token'])) { ja_social_account_status($a['account_id'], 'expired'); return null; }
        ja_social_account_update_tokens($a['account_id'], $j['access_token'], $j['refresh_token'] ?? $ref, !empty($j['expires_in']) ? time() + (int)$j['expires_in'] : null);
        return ja_social_account($a['eml'], $a['account_id']);
    }
}

function ja_social_provider($id) {
    static $cache = [];
    if (!in_array($id, JA_SOCIAL_IDS, true)) return null;
    if (isset($cache[$id])) return $cache[$id];
    $file = __DIR__ . '/social/' . $id . '.php';
    if (!is_file($file)) return null;
    require_once $file;
    $cls = 'JaSocial_' . $id;
    return $cache[$id] = new $cls();
}
function ja_social_providers() {
    $o = [];
    foreach (JA_SOCIAL_IDS as $id) { $p = ja_social_provider($id); if ($p) $o[$id] = $p; }
    return $o;
}

/* ------------------------------------------------------------------ OAuth state (random state + PKCE verifier in the session) */
function ja_social_b64url($b) { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function ja_social_oauth_begin($provider, $eml, $pkce, array $extra = []) {
    $state = ja_social_b64url(random_bytes(24));
    $verifier = ja_social_b64url(random_bytes(48));
    $redirect = ja_social_redirect_uri();
    if (empty($_SESSION['soc_oauth']) || !is_array($_SESSION['soc_oauth'])) $_SESSION['soc_oauth'] = [];
    foreach ($_SESSION['soc_oauth'] as $k => $v) if (($v['t'] ?? 0) < time() - 900) unset($_SESSION['soc_oauth'][$k]);
    if (count($_SESSION['soc_oauth']) > 10) array_shift($_SESSION['soc_oauth']);
    $_SESSION['soc_oauth'][$state] = ['p' => $provider, 'eml' => $eml, 'verifier' => $verifier, 'redirect' => $redirect, 't' => time(), 'x' => $extra];
    return ['state' => $state, 'verifier' => $verifier, 'challenge' => ja_social_b64url(hash('sha256', $verifier, true)), 'redirect' => $redirect];
}
/** Validate a callback's state. Returns [entry, null] or [null, plain error]. The entry is single use. */
function ja_social_oauth_take($state, $eml) {
    $state = (string)$state;
    $e = $_SESSION['soc_oauth'][$state] ?? null;
    unset($_SESSION['soc_oauth'][$state]);
    if ($state === '' || !is_array($e)) return [null, 'This sign-in link is not valid or was already used. Start the connection again from your profile.'];
    if (($e['t'] ?? 0) < time() - 600) return [null, 'The sign-in took too long. Start the connection again.'];
    if (!$eml || strcasecmp($e['eml'], $eml) !== 0) return [null, 'This sign-in belongs to a different account. Start again from your profile.'];
    if (!hash_equals($e['redirect'], ja_social_redirect_uri())) return [null, 'The return address did not match. Start the connection again.'];
    return [$e, null];
}

/* ------------------------------------------------------------------ the post pipeline */
/**
 * Post one confirmed job to one account. $item comes from ja_social_item() (already checked public + approved).
 * $job: account_id, text, image_idx[], alts[idx=>text], visibility, req_key.
 * Returns ['account_id','provider','handle','ok','remote_url','error','post_id'].
 */
function ja_social_post_one($eml, array $item, array $job) {
    $conn = ja_db();
    $res = ['account_id' => (int)$job['account_id'], 'provider' => '', 'handle' => '', 'ok' => false, 'remote_url' => '', 'error' => '', 'post_id' => 0];
    $a = ja_social_account($eml, $job['account_id']);
    if (!$a) { $res['error'] = 'That account is not connected any more.'; return $res; }
    $res['provider'] = $a['provider']; $res['handle'] = $a['handle'];
    $p = ja_social_provider($a['provider']);
    if (!$p || !$p->configured()) { $res['error'] = ($p ? $p->label : 'This service') . ' is not set up by the site owner.'; return $res; }
    if ($a['status'] !== 'active') { $res['error'] = 'This account needs to be reconnected in your profile.'; return $res; }

    $images = [];
    foreach ((array)$job['image_idx'] as $i) {
        $i = (int)$i;
        if (!isset($item['images'][$i])) { $res['error'] = 'One of the chosen photos is not available.'; return $res; }
        $images[] = ['rel' => $item['images'][$i]['rel'], 'alt' => mb_substr(trim((string)($job['alts'][$i] ?? '')), 0, 1000)];
    }
    // Instagram Kit: the images are drawn by the site (cards, polaroids, carousels) instead of picked from the item's photos
    $kit = null;
    if (!empty($job['kit']) && $a['provider'] === 'instagram') {
        require_once __DIR__ . '/ja-instagram-kit.php';
        $k = $job['kit'];
        $fmt = (string)($k['format'] ?? ''); $tpl = (string)($k['template'] ?? 'photo'); $kn = (int)($k['n'] ?? 0);
        if (!in_array($fmt, ['feed', 'square', 'carousel'], true)) { $res['error'] = 'Only Feed, Square and Carousel images can be posted from the site. Stories are shared from your phone.'; return $res; }
        $specs = igk_slides($item, $fmt, $tpl);
        if (!$specs) { $res['error'] = 'There is nothing to post for that format.'; return $res; }
        $kit = ['format' => $fmt, 'template' => $tpl, 'n' => $kn];
        $images = [];
        $count = $fmt === 'carousel' ? count($specs) : 1;
        for ($i = 0; $i < $count; $i++) $images[] = ['rel' => 'kit:' . $i, 'alt' => mb_substr(trim((string)($job['kit_alts'][$i] ?? '')), 0, 1000)];
    }
    $payload = ['text' => (string)$job['text'], 'images' => $images, 'url' => $item['url'], 'visibility' => $job['visibility'] ?? 'public', 'title' => $item['title'], 'kit' => $kit, 'item' => $item];
    if ($err = $p->validate($payload, $a)) { $res['error'] = $err; return $res; }

    // duplicate click protection: the same request key for the same account returns the earlier result
    $rk = mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($job['req_key'] ?? '')), 0, 40) ?: null;
    $kind = $item['kind']; $iid = (string)$item['id'];
    $st = mysqli_prepare($conn, "INSERT INTO social_posts (eml, account_id, provider, kind, item_id, status, req_key) VALUES (?,?,?,?,?, 'queued', ?)");
    $aid = (int)$a['account_id'];
    mysqli_stmt_bind_param($st, 'sissss', $eml, $aid, $a['provider'], $kind, $iid, $rk);
    if (!@mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);
        $q = mysqli_prepare($conn, "SELECT post_id, status, remote_url, error FROM social_posts WHERE account_id = ? AND req_key = ?");
        mysqli_stmt_bind_param($q, 'is', $aid, $rk); mysqli_stmt_execute($q);
        $old = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
        $res['ok'] = $old && $old['status'] === 'posted'; $res['remote_url'] = $old['remote_url'] ?? '';
        $res['error'] = $res['ok'] ? '' : ((($old['status'] ?? '') === 'queued') ? 'This post is already being sent.' : ($old['error'] ?? 'Already tried.'));
        $res['post_id'] = (int)($old['post_id'] ?? 0); $res['duplicate'] = true;
        return $res;
    }
    $postId = mysqli_insert_id($conn); mysqli_stmt_close($st); $res['post_id'] = $postId;

    $finish = function ($ok, $remoteId, $url, $err) use ($conn, $postId, &$res, $eml, $a, $kind, $iid) {
        $status = $ok ? 'posted' : 'failed'; $err = mb_substr(ja_social_clean_error($err), 0, 255);
        $remoteId = mb_substr((string)$remoteId, 0, 255); $url = mb_substr((string)$url, 0, 500);
        $u = mysqli_prepare($conn, "UPDATE social_posts SET status = ?, remote_id = ?, remote_url = ?, error = ? WHERE post_id = ?");
        mysqli_stmt_bind_param($u, 'ssssi', $status, $remoteId, $url, $err, $postId); mysqli_stmt_execute($u); mysqli_stmt_close($u);
        ja_audit($eml, 'social_post', $a['provider'] . " $kind#$iid $status" . ($ok ? '' : ': ' . mb_substr($err, 0, 120)));
        $res['ok'] = $ok; $res['remote_url'] = $url; $res['error'] = $ok ? '' : $err;
        return $res;
    };
    if (!ja_rate_limit('socpost:u:' . strtolower($eml), JA_SOCIAL_USER_PER_HOUR, 3600))
        return $finish(false, '', '', 'You have reached ' . JA_SOCIAL_USER_PER_HOUR . ' posts this hour. Wait a while before posting again.');
    if (!ja_rate_limit('socpost:p:' . $a['provider'] . ':' . strtolower($eml), JA_SOCIAL_PROVIDER_PER_HOUR, 3600))
        return $finish(false, '', '', 'You have reached ' . JA_SOCIAL_PROVIDER_PER_HOUR . ' posts an hour to ' . $p->label . '. Wait a while before posting again.');
    try {
        $fresh = $p->ensureFresh($a);
        if (!$fresh) return $finish(false, '', '', $p->label . ' sign-in expired. Reconnect the account in your profile.');
        $r = $p->post($fresh, $payload);
    } catch (Throwable $e) {
        return $finish(false, '', '', 'Something went wrong while posting to ' . $p->label . '.');
    }
    return $finish(!empty($r['ok']), $r['remote_id'] ?? '', $r['remote_url'] ?? '', $r['error'] ?? 'Unknown error.');
}
