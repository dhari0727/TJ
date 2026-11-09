<?php
/** POST entry_id, visibility=private|link|public (+csrf) -> JSON {visibility, status, message, url}. Owner only. */
session_start();
require 'connection.php';
require_once 'ja-trust.php';
header('Content-Type: application/json');
$em = $_SESSION['eml'] ?? null;
if (!$em) { echo json_encode(['error' => 'Please log in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ja_csrf_ok()) { echo json_encode(['error' => 'Session expired. Reload the page.']); exit; }
$id = (int)($_POST['entry_id'] ?? 0);
$r = ja_publish_journal($id, $em, $_POST['visibility'] ?? '');
if (!$r['ok']) { echo json_encode(['error' => $r['message'] ?: 'Could not save.']); exit; }
$base = ja_base_url(); $vis = $r['visibility'];
$url = $vis === 'private' ? '' : ($vis === 'public' ? "$base/journal.php?id=$id" : "$base/journal.php?id=$id&token=" . $r['token']);
echo json_encode(['visibility' => $vis, 'status' => $r['status'], 'message' => $r['message'], 'url' => $url]);
