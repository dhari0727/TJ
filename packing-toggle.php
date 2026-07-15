<?php
/**
 * JourneyAI — toggle a packing item's checked state.
 * POST {item_id, checked} -> UPDATE packing_items SET is_checked=?
 * Ownership verified via JOIN to saved_plans on the session user's eml.
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error'=>'Please log in.']); exit; }

$itemId  = (int)($_POST['item_id'] ?? 0);
$checked = !empty($_POST['checked']) && $_POST['checked'] !== '0' ? 1 : 0;

$stmt = mysqli_prepare($conn,
    "UPDATE packing_items pi
     JOIN saved_plans sp ON sp.plan_id = pi.plan_id
     SET pi.is_checked = ?
     WHERE pi.item_id = ? AND sp.eml = ?");
mysqli_stmt_bind_param($stmt, 'iis', $checked, $itemId, $eml);
mysqli_stmt_execute($stmt);
$ok = mysqli_stmt_affected_rows($stmt) > 0;
mysqli_stmt_close($stmt);

echo json_encode(['ok' => $ok]);
