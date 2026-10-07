<?php
/**
 * JourneyAI — packing list actions (JSON). POST only, CSRF-checked, owner-only.
 *   action = toggle | add | delete | qty | uncheck_all | regenerate | rename | delete_list | duplicate
 */
session_start();
require 'connection.php';
require_once 'ja-lib.php';
require_once 'ja-packing-engine.php';
header('Content-Type: application/json');
$em = $_SESSION['eml'] ?? null;
if (!$em) { echo json_encode(['error' => 'Please log in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ja_csrf_ok()) { echo json_encode(['error' => 'Session expired. Reload the page.']); exit; }

function ja_pack_owned_list($conn, $em, $listId) {
    $s = mysqli_prepare($conn, "SELECT * FROM packing_lists WHERE list_id = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'is', $listId, $em); mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $r ?: null;
}
function ja_pack_owned_item($conn, $em, $itemId) {
    $s = mysqli_prepare($conn, "SELECT i.*, l.eml FROM packing_list_items i JOIN packing_lists l ON l.list_id = i.list_id WHERE i.item_id = ? AND l.eml = ?");
    mysqli_stmt_bind_param($s, 'is', $itemId, $em); mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    return $r ?: null;
}
function ja_pack_progress($conn, $listId) {
    $r = mysqli_query($conn, "SELECT COUNT(*) t, COALESCE(SUM(is_checked),0) c FROM packing_list_items WHERE list_id = " . (int)$listId);
    $row = mysqli_fetch_assoc($r);
    return ['total' => (int)$row['t'], 'packed' => (int)$row['c']];
}

$action = $_POST['action'] ?? '';
$listId = (int)($_POST['list_id'] ?? 0);
$itemId = (int)($_POST['item_id'] ?? 0);

if ($action === 'toggle' || $action === 'delete' || $action === 'qty') {
    $it = ja_pack_owned_item($conn, $em, $itemId);
    if (!$it) { echo json_encode(['error' => 'Not found.']); exit; }
    $listId = (int)$it['list_id'];
    if ($action === 'toggle') {
        $v = !empty($_POST['checked']) ? 1 : 0;
        $u = mysqli_prepare($conn, "UPDATE packing_list_items SET is_checked = ? WHERE item_id = ?"); mysqli_stmt_bind_param($u, 'ii', $v, $itemId);
    } elseif ($action === 'qty') {
        $q = max(1, min(99, (int)($_POST['qty'] ?? 1)));
        $u = mysqli_prepare($conn, "UPDATE packing_list_items SET qty = ? WHERE item_id = ?"); mysqli_stmt_bind_param($u, 'ii', $q, $itemId);
    } else {
        $u = mysqli_prepare($conn, "DELETE FROM packing_list_items WHERE item_id = ?"); mysqli_stmt_bind_param($u, 'i', $itemId);
    }
    mysqli_stmt_execute($u); mysqli_stmt_close($u);
    echo json_encode(['ok' => true] + ja_pack_progress($conn, $listId)); exit;
}

$list = ja_pack_owned_list($conn, $em, $listId);
if (!$list) { echo json_encode(['error' => 'List not found.']); exit; }

if ($action === 'add') {
    $label = mb_substr(trim($_POST['label'] ?? ''), 0, 160);
    $cat = array_key_exists($_POST['category'] ?? '', JA_PACK_CATEGORIES) ? $_POST['category'] : 'general';
    $qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));
    if ($label === '') { echo json_encode(['error' => 'Type what to pack.']); exit; }
    $n = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 FROM packing_list_items WHERE list_id = $listId"))[0];
    $s = mysqli_prepare($conn, "INSERT INTO packing_list_items (list_id, category, label, qty, source, sort_order) VALUES (?,?,?,?, 'custom', ?)");
    mysqli_stmt_bind_param($s, 'issii', $listId, $cat, $label, $qty, $n); mysqli_stmt_execute($s);
    $id = mysqli_insert_id($conn); mysqli_stmt_close($s);
    echo json_encode(['ok' => true, 'item' => ['item_id' => $id, 'category' => $cat, 'label' => $label, 'qty' => $qty, 'why' => '', 'source' => 'custom', 'is_checked' => 0]] + ja_pack_progress($conn, $listId)); exit;
}
if ($action === 'uncheck_all') {
    mysqli_query($conn, "UPDATE packing_list_items SET is_checked = 0 WHERE list_id = $listId");
    echo json_encode(['ok' => true] + ja_pack_progress($conn, $listId)); exit;
}
if ($action === 'rename') {
    $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
    if ($name === '') { echo json_encode(['error' => 'Give the list a name.']); exit; }
    $s = mysqli_prepare($conn, "UPDATE packing_lists SET name = ? WHERE list_id = ?"); mysqli_stmt_bind_param($s, 'si', $name, $listId); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    echo json_encode(['ok' => true, 'name' => $name]); exit;
}
if ($action === 'delete_list') {
    $s = mysqli_prepare($conn, "DELETE FROM packing_lists WHERE list_id = ? AND eml = ?"); mysqli_stmt_bind_param($s, 'is', $listId, $em); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'regenerate') {
    // re-run the recommender with the list's saved settings; add only items that are missing (keeps ticks + custom items)
    $region = ''; $intl = false;
    $s = mysqli_prepare($conn, "SELECT region, country FROM destinations WHERE canonical_name = ? LIMIT 1");
    mysqli_stmt_bind_param($s, 's', $list['destination']); mysqli_stmt_execute($s);
    if ($d = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { $region = $d['region']; $intl = $d['country'] !== 'India'; } mysqli_stmt_close($s);
    $rec = ja_packing_recommend(['destination' => $list['destination'], 'days' => $list['days'], 'month' => $list['month'], 'style' => $list['style'],
        'party' => $list['party'], 'options' => array_filter(explode(',', (string)$list['options'])), 'region' => $region, 'international' => $intl]);
    $have = [];
    $r = mysqli_query($conn, "SELECT LOWER(label) FROM packing_list_items WHERE list_id = $listId");
    while ($row = mysqli_fetch_row($r)) $have[$row[0]] = true;
    $n = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 FROM packing_list_items WHERE list_id = $listId"))[0];
    $added = 0;
    $ins = mysqli_prepare($conn, "INSERT INTO packing_list_items (list_id, category, label, qty, why, source, sort_order) VALUES (?,?,?,?,?, 'suggested', ?)");
    foreach ($rec as $it) {
        if (isset($have[strtolower($it['label'])])) continue;
        mysqli_stmt_bind_param($ins, 'issisi', $listId, $it['category'], $it['label'], $it['qty'], $it['why'], $n);
        mysqli_stmt_execute($ins); $n++; $added++;
    }
    mysqli_stmt_close($ins);
    echo json_encode(['ok' => true, 'added' => $added] + ja_pack_progress($conn, $listId)); exit;
}
if ($action === 'duplicate') {
    $name = mb_substr($list['name'] . ' (copy)', 0, 120);
    $s = mysqli_prepare($conn, "INSERT INTO packing_lists (eml, name, destination, days, month, style, party, options) VALUES (?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($s, 'sssiisis', $em, $name, $list['destination'], $list['days'], $list['month'], $list['style'], $list['party'], $list['options']);
    mysqli_stmt_execute($s); $new = mysqli_insert_id($conn); mysqli_stmt_close($s);
    mysqli_query($conn, "INSERT INTO packing_list_items (list_id, category, label, qty, why, source, is_checked, sort_order)
                         SELECT $new, category, label, qty, why, source, 0, sort_order FROM packing_list_items WHERE list_id = $listId");
    echo json_encode(['ok' => true, 'list_id' => $new]); exit;
}
echo json_encode(['error' => 'Unknown action.']);
