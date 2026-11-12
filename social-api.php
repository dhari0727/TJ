<?php
/**
 * JourneyAI - sharing and social posting API (JSON).
 *
 *   GET  ?action=describe&kind=journal|book|post|plan|upload&id=N[&path=uploads/x.jpg]
 *          what the share sheet needs (links, caption, images, whether this person can post directly)
 *   POST action=targets                                  connected accounts + services and their limits   (login)
 *   POST action=post  kind,id[,path],confirmed=1,req,payload=JSON{jobs:[{account_id,text,images:[i],visibility}],alts:{i:text}}   (login, owner)
 *   POST action=connect_bluesky  handle, app_password
 *   POST action=connect_start    provider[, instance]    -> {url} to send the browser to
 *   POST action=disconnect       account_id
 *   POST action=test_connection  account_id      -> {ok, message}: asks the service who this sign-in belongs to
 *   POST action=history
 * Every POST needs the CSRF token. Nothing is posted unless confirmed=1 AND the item is public and approved right now.
 */
require_once __DIR__ . '/ja-social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ja_soc_out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function ja_soc_fail($msg, $code = 400, array $more = []) { ja_soc_out(['ok' => false, 'error' => $msg] + $more, $code); }

$u = ja_current_user();
$eml = $u ? $u['eml'] : null;
$action = (string)($_REQUEST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($action !== 'describe') ja_soc_fail('Use POST.', 405);
    $kind = (string)($_GET['kind'] ?? '');
    if (!in_array($kind, ['journal', 'book', 'post', 'plan', 'upload'], true)) ja_soc_fail('Unknown kind.');
    if (!ja_rate_limit('socdesc:' . ($eml ? strtolower($eml) : ja_client_ip()), 240, 60)) ja_soc_fail('Too many requests. Wait a moment.', 429);
    session_write_close();
    $d = ja_social_describe($kind, $_GET['id'] ?? 0, $eml, $_GET['path'] ?? null);
    ja_soc_out($d, $d['ok'] ? 200 : 404);
}

ja_csrf_enforce();
if (!$eml) ja_soc_fail('Log in to connect accounts and post.', 401);
if (!ja_rate_limit('socapi:' . strtolower($eml), 200, 60)) ja_soc_fail('Too many requests. Wait a moment.', 429);

function ja_soc_provider_info($p) {
    return ['id' => $p->id, 'label' => $p->label, 'tier' => $p->tier, 'auth' => $p->auth, 'configured' => $p->configured(), 'note' => $p->note, 'limits' => $p->limits()];
}

switch ($action) {

case 'targets':
    $accounts = [];
    foreach (ja_social_accounts($eml) as $a) {
        $p = ja_social_provider($a['provider']);
        if (!$p) continue;
        $accounts[] = ja_social_account_public($a) + ['label' => $p->label, 'limits' => $p->limits($a), 'ready' => $p->configured() && $a['status'] === 'active'];
    }
    ja_soc_out(['ok' => true, 'accounts' => $accounts, 'providers' => array_values(array_map('ja_soc_provider_info', ja_social_providers()))]);

case 'connect_bluesky':
case 'connect_start':
    if (!ja_rate_limit('socconn:' . strtolower($eml), 12, 600)) ja_soc_fail('Too many connection attempts. Wait a few minutes.', 429);
    $pid = $action === 'connect_bluesky' ? 'bluesky' : (string)($_POST['provider'] ?? '');
    $p = ja_social_provider($pid);
    if (!$p) ja_soc_fail('Unknown service.');
    if (!$p->configured()) ja_soc_fail($p->label . ' is not set up by the site owner yet.', 409);
    try {
        if ($action === 'connect_bluesky') {
            $r = $p->connectPassword($eml, ['handle' => $_POST['handle'] ?? '', 'app_password' => $_POST['app_password'] ?? '']);
            if (!empty($r['ok'])) ja_audit($eml, 'social_connect', 'bluesky');
        } else {
            if ($p->auth === 'password') ja_soc_fail('Use the sign-in form for ' . $p->label . '.');
            $r = $p->connectStart($eml, ['instance' => $_POST['instance'] ?? '']);
        }
    } catch (Throwable $e) {
        ja_soc_fail('Could not start the connection. Try again.', 500);
    }
    if (empty($r['ok'])) ja_soc_fail($r['error'] ?? 'Could not connect.', 400);
    ja_soc_out(['ok' => true] + array_intersect_key($r, ['url' => 1]));

case 'disconnect':
    $ok = ja_social_disconnect($eml, (int)($_POST['account_id'] ?? 0));
    ja_soc_out(['ok' => $ok, 'error' => $ok ? '' : 'That account was not found.'], $ok ? 200 : 404);

case 'test_connection':
    if (!ja_rate_limit('soctest:' . strtolower($eml), 20, 600)) ja_soc_fail('Too many tests. Wait a few minutes.', 429);
    $a = ja_social_account($eml, (int)($_POST['account_id'] ?? 0));
    if (!$a) ja_soc_fail('That account was not found.', 404);
    $p = ja_social_provider($a['provider']);
    if (!$p || !$p->configured()) ja_soc_fail('This service is not set up by the site owner.', 409);
    session_write_close();
    try { $t = $p->testConnection($a); } catch (Throwable $e) { $t = ['ok' => false, 'message' => 'The test could not run. Try again.']; }
    ja_soc_out(['ok' => !empty($t['ok']), 'message' => (string)($t['message'] ?? '')]);

case 'history':
    $conn = ja_db();
    $st = mysqli_prepare($conn, "SELECT sp.post_id, sp.provider, sp.kind, sp.status, sp.remote_url, sp.error, sp.created_at, a.handle
        FROM social_posts sp LEFT JOIN social_accounts a ON a.account_id = sp.account_id WHERE sp.eml = ? ORDER BY sp.post_id DESC LIMIT 25");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC); mysqli_stmt_close($st);
    ja_soc_out(['ok' => true, 'posts' => $rows]);

case 'post':
    if (($_POST['confirmed'] ?? '') !== '1') ja_soc_fail('Check the text and photos, then press Post now to confirm.', 400);
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['journal', 'book', 'post', 'plan', 'upload'], true)) ja_soc_fail('Unknown kind.');
    // re-check on the server, right now: not only in the page that was open
    $item = ja_social_item($kind, $_POST['id'] ?? 0, $eml, $_POST['path'] ?? null);
    if (!$item || !$item['owner']) ja_soc_fail('You can only post your own items.', 404);
    if (!$item['shareable']) ja_soc_fail($item['reason'] !== '' ? $item['reason'] : 'Make this public first.', 409, ['needs_public' => true]);
    $payload = json_decode((string)($_POST['payload'] ?? ''), true);
    $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
    $alts = is_array($payload['alts'] ?? null) ? $payload['alts'] : [];
    if (!$jobs) ja_soc_fail('Choose at least one account to post to.');
    if (count($jobs) > 8) ja_soc_fail('You can post to up to 8 accounts at once.');
    $req = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['req'] ?? ''));
    if (strlen($req) < 8) ja_soc_fail('Reload the page and try again.');
    $clean = []; $seen = [];
    foreach ($jobs as $j) {
        $aid = (int)($j['account_id'] ?? 0);
        if ($aid <= 0 || isset($seen[$aid])) ja_soc_fail('The account list is not valid.');
        $seen[$aid] = 1;
        if (!ja_social_account($eml, $aid)) ja_soc_fail('One of the accounts is not connected any more.', 404);
        $text = str_replace("\r\n", "\n", (string)($j['text'] ?? ''));
        if (mb_strlen($text) > 5000) ja_soc_fail('That text is far too long.');
        $clean[] = ['account_id' => $aid, 'text' => $text, 'image_idx' => array_values(array_unique(array_map('intval', (array)($j['images'] ?? [])))),
                    'alts' => $alts, 'kit' => is_array($j['kit'] ?? null) ? array_intersect_key($j['kit'], ['format' => 1, 'template' => 1, 'n' => 1]) : null,
                    'kit_alts' => array_slice(array_map('strval', array_values((array)($j['kit_alts'] ?? []))), 0, 10), 'visibility' => in_array($j['visibility'] ?? '', ['public', 'unlisted'], true) ? $j['visibility'] : 'public',
                    'req_key' => substr($req, 0, 24) . $aid];
    }
    session_write_close();
    $results = [];
    foreach ($clean as $job) $results[] = ja_social_post_one($eml, $item, $job);
    ja_soc_out(['ok' => true, 'results' => $results, 'posted' => count(array_filter($results, function ($r) { return $r['ok']; }))]);

default:
    ja_soc_fail('Unknown action.');
}
