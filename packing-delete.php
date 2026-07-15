<?php
/**
 * JourneyAI — delete a packing item. Ownership verified via JOIN.
 * POST {item_id}
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error'=>'Please log in.']); exit; }

$itemId = (int)($_POST['item_id'] ?? 0);

$stmt = mysqli_prepare($conn,
    "DELETE pi FROM packing_items pi
     JOIN saved_plans sp ON sp.plan_id = pi.plan_id
     WHERE pi.item_id = ? AND sp.eml = ?");
mysqli_stmt_bind_param($stmt, 'is', $itemId, $eml);
mysqli_stmt_execute($stmt);
$ok = mysqli_stmt_affected_rows($stmt) > 0;
mysqli_stmt_close($stmt);

echo json_encode(['ok' => $ok]);
