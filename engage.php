<?php
/**
 * JourneyAI — comments, journal likes and pinning (JSON).
 *
 *   GET  ?action=comments&kind=media|journal&id=N[&token=T]        list comments (anyone who can view the item)
 *   POST action=comment_add    kind id body [token]                add a comment (login + csrf)
 *   POST action=comment_delete kind comment_id                     author, or the owner of the item
 *   POST action=like           kind=journal id [token]             toggle a like on a journal
 *   POST action=pin            kind=media|book|journal id pin=1|0  owner only, max 3 pinned per kind
 * (Posts are liked via media-like.php, storybooks via storybook-api.php; both already existed.)
 */
session_start();
require 'connection.php';
require_once 'ja-lib.php';
header('Content-Type: application/json');
$em = $_SESSION['eml'] ?? null;
$action = $_REQUEST['action'] ?? '';
$kind = $_REQUEST['kind'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);
$token = (string)($_REQUEST['token'] ?? '');
const MAX_PINNED = 3;

function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function one($conn, $sql, $types, ...$args) {
    $s = mysqli_prepare($conn, $sql);
    if ($types !== '') mysqli_stmt_bind_param($s, $types, ...$args);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $r ?: null;
}

/** Load the item + its owner and decide whether the current viewer may see it. */
function item_for($conn, $kind, $id, $em, $token) {
    if ($kind === 'media') {
        $r = one($conn, "SELECT eml, is_public FROM media WHERE media_id = ?", 'i', $id);
        if (!$r) return null;
        $r['can_view'] = (int)$r['is_public'] === 1 || ($em && strcasecmp($r['eml'], $em) === 0);
    } elseif ($kind === 'journal') {
        $r = one($conn, "SELECT eml, visibility, share_token FROM db WHERE entry_id = ?", 'i', $id);
        if (!$r) return null;
        $owner = $em && strcasecmp($r['eml'], $em) === 0;
        $r['can_view'] = $owner || $r['visibility'] === 'public' || ($r['visibility'] === 'link' && $token !== '' && hash_equals((string)$r['share_token'], $token));
    } elseif ($kind === 'book') {
        $r = one($conn, "SELECT eml, visibility, share_token FROM storybooks WHERE book_id = ?", 'i', $id);
        if (!$r) return null;
        $owner = $em && strcasecmp($r['eml'], $em) === 0;
        $r['can_view'] = $owner || $r['visibility'] === 'public';
    } else return null;
    $r['owner'] = $em && strcasecmp($r['eml'], $em) === 0;
    return $r;
}
function comment_table($kind) { return $kind === 'media' ? ['media_comments', 'media_id'] : ['journal_comments', 'entry_id']; }
function display_name($conn, $eml) {
    $r = one($conn, "SELECT fname, lname, handle FROM signup WHERE eml = ?", 's', $eml);
    if (!$r) return ['name' => 'A traveller', 'handle' => null];
    return ['name' => trim($r['fname'] . ' ' . mb_substr((string)$r['lname'], 0, 1) . '.'), 'handle' => $r['handle']];
}

if ($action === 'comments') {
    if (!in_array($kind, ['media', 'journal'], true)) fail('Bad kind.');
    $item = item_for($conn, $kind, $id, $em, $token);
    if (!$item || !$item['can_view']) fail('Not found.', 404);
    [$tbl, $col] = comment_table($kind);
    $s = mysqli_prepare($conn, "SELECT comment_id, eml, body, created_at FROM `$tbl` WHERE `$col` = ? ORDER BY comment_id ASC LIMIT 200");
    mysqli_stmt_bind_param($s, 'i', $id); mysqli_stmt_execute($s);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
    $out = [];
    foreach ($rows as $r) {
        $n = display_name($conn, $r['eml']);
        $out[] = ['id' => (int)$r['comment_id'], 'name' => $n['name'], 'handle' => $n['handle'], 'body' => $r['body'], 'created' => $r['created_at'],
                  'mine' => $em && strcasecmp($r['eml'], $em) === 0, 'can_delete' => ($em && strcasecmp($r['eml'], $em) === 0) || $item['owner']];
    }
    echo json_encode(['comments' => $out, 'count' => count($out)]); exit;
}

// everything below changes data: POST + login + csrf
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
if (!$em) fail('Please log in.', 401);
if (!ja_csrf_ok()) fail('Session expired. Reload the page.', 403);

if ($action === 'comment_add') {
    if (!in_array($kind, ['media', 'journal'], true)) fail('Bad kind.');
    $item = item_for($conn, $kind, $id, $em, $token);
    if (!$item || !$item['can_view']) fail('Not found.', 404);
    $body = trim(preg_replace('/\s+/u', ' ', (string)($_POST['body'] ?? '')));
    if ($body === '' || mb_strlen($body) > 600) fail('Write 1 to 600 characters.');
    // basic flood control: 10 comments per minute per user
    $recent = 0;
    foreach (['media_comments', 'journal_comments'] as $t) { $r = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM `$t` WHERE eml = '" . mysqli_real_escape_string($conn, $em) . "' AND created_at > (NOW() - INTERVAL 1 MINUTE)")); $recent += (int)$r[0]; }
    if ($recent >= 10) fail('Slow down a little.', 429);
    [$tbl, $col] = comment_table($kind);
    $s = mysqli_prepare($conn, "INSERT INTO `$tbl` (`$col`, eml, body) VALUES (?,?,?)");
    mysqli_stmt_bind_param($s, 'iss', $id, $em, $body); mysqli_stmt_execute($s);
    $cid = mysqli_insert_id($conn); mysqli_stmt_close($s);
    $n = display_name($conn, $em);
    echo json_encode(['ok' => true, 'comment' => ['id' => $cid, 'name' => $n['name'], 'handle' => $n['handle'], 'body' => $body, 'created' => date('Y-m-d H:i:s'), 'mine' => true, 'can_delete' => true]]); exit;
}

if ($action === 'comment_delete') {
    if (!in_array($kind, ['media', 'journal'], true)) fail('Bad kind.');
    [$tbl, $col] = comment_table($kind);
    $cid = (int)($_POST['comment_id'] ?? 0);
    $c = one($conn, "SELECT `$col` AS item_id, eml FROM `$tbl` WHERE comment_id = ?", 'i', $cid);
    if (!$c) fail('Not found.', 404);
    $item = item_for($conn, $kind, (int)$c['item_id'], $em, '');
    if (!(strcasecmp($c['eml'], $em) === 0 || ($item && $item['owner']))) fail('Not allowed.', 403);
    $s = mysqli_prepare($conn, "DELETE FROM `$tbl` WHERE comment_id = ?"); mysqli_stmt_bind_param($s, 'i', $cid); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'like') {
    if ($kind !== 'journal') fail('Bad kind.');
    $item = item_for($conn, 'journal', $id, $em, $token);
    if (!$item || !$item['can_view']) fail('Not found.', 404);
    $had = one($conn, "SELECT 1 x FROM journal_likes WHERE entry_id = ? AND eml = ?", 'is', $id, $em);
    $s = mysqli_prepare($conn, $had ? "DELETE FROM journal_likes WHERE entry_id = ? AND eml = ?" : "INSERT INTO journal_likes (entry_id, eml) VALUES (?,?)");
    mysqli_stmt_bind_param($s, 'is', $id, $em); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    $n = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM journal_likes WHERE entry_id = " . $id))[0];
    echo json_encode(['ok' => true, 'liked' => !$had, 'likes' => $n]); exit;
}

if ($action === 'pin') {
    $map = ['media' => ['media', 'media_id'], 'book' => ['storybooks', 'book_id'], 'journal' => ['db', 'entry_id']];
    if (!isset($map[$kind])) fail('Bad kind.');
    [$tbl, $col] = $map[$kind];
    $row = one($conn, "SELECT eml, is_pinned FROM `$tbl` WHERE `$col` = ?", 'i', $id);
    if (!$row || strcasecmp($row['eml'], $em) !== 0) fail('Not found.', 404);
    $pin = !empty($_POST['pin']) ? 1 : 0;
    if ($pin) {
        $n = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM `$tbl` WHERE eml = '" . mysqli_real_escape_string($conn, $em) . "' AND is_pinned = 1"))[0];
        if (!$row['is_pinned'] && $n >= MAX_PINNED) fail('You can pin up to ' . MAX_PINNED . '. Unpin one first.');
    }
    $s = mysqli_prepare($conn, "UPDATE `$tbl` SET is_pinned = ? WHERE `$col` = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'iis', $pin, $id, $em); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    echo json_encode(['ok' => true, 'pinned' => (bool)$pin]); exit;
}
fail('Unknown action.');
