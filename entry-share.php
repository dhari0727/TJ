<?php
/** POST entry_id, visibility=private|link|public (+csrf) -> JSON {visibility, url}. Owner only. */
session_start();
require 'connection.php';
require_once 'ja-lib.php';
header('Content-Type: application/json');
$em = $_SESSION['eml'] ?? null;
if (!$em) { echo json_encode(['error' => 'Please log in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ja_csrf_ok()) { echo json_encode(['error' => 'Session expired. Reload the page.']); exit; }
$id = (int)($_POST['entry_id'] ?? 0);
$vis = $_POST['visibility'] ?? '';
if (!in_array($vis, ['private', 'link', 'public'], true)) { echo json_encode(['error' => 'Invalid choice.']); exit; }

$q = mysqli_prepare($conn, "SELECT share_token FROM db WHERE entry_id = ? AND eml = ?");
mysqli_stmt_bind_param($q, 'is', $id, $em); mysqli_stmt_execute($q);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
if (!$row) { echo json_encode(['error' => 'Entry not found.']); exit; }

$token = $row['share_token'];
if (!$token && $vis !== 'private') $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
$u = mysqli_prepare($conn, "UPDATE db SET visibility = ?, share_token = ?, published_at = IF(? = 'public', COALESCE(published_at, NOW()), published_at) WHERE entry_id = ? AND eml = ?");
mysqli_stmt_bind_param($u, 'sssis', $vis, $token, $vis, $id, $em);
$ok = mysqli_stmt_execute($u); mysqli_stmt_close($u);
$base = ja_base_url();
$url = $vis === 'private' ? '' : ($vis === 'public' ? "$base/journal.php?id=$id" : "$base/journal.php?id=$id&token=$token");
echo json_encode($ok ? ['visibility' => $vis, 'url' => $url] : ['error' => 'Could not save.']);
