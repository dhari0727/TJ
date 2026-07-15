<?php
/**
 * JourneyAI — delete a saved plan (and its packing_items via FK CASCADE).
 * POST {plan_id}
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error'=>'Please log in.']); exit; }

$planId = (int)($_POST['plan_id'] ?? 0);

$stmt = mysqli_prepare($conn, "DELETE FROM saved_plans WHERE plan_id=? AND eml=?");
mysqli_stmt_bind_param($stmt, 'is', $planId, $eml);
mysqli_stmt_execute($stmt);
$ok = mysqli_stmt_affected_rows($stmt) > 0;
mysqli_stmt_close($stmt);

echo json_encode(['ok' => $ok]);
