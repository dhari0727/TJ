<?php
/**
 * JourneyAI — generate (or return existing) share token for a saved plan.
 * POST {plan_id} -> {ok, token, url}
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error'=>'Please log in.']); exit; }

$planId = (int)($_POST['plan_id'] ?? 0);

$s = mysqli_prepare($conn, "SELECT plan_id, share_token FROM saved_plans WHERE plan_id=? AND eml=?");
mysqli_stmt_bind_param($s, 'is', $planId, $eml);
mysqli_stmt_execute($s);
$plan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

if (!$plan) { echo json_encode(['error'=>'Not found.']); exit; }

$token = $plan['share_token'];
if (!$token) {
    $token = bin2hex(random_bytes(11)); // 22 chars, matches CHAR(22)
    $u = mysqli_prepare($conn, "UPDATE saved_plans SET share_token=? WHERE plan_id=?");
    mysqli_stmt_bind_param($u, 'si', $token, $planId);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
$url = rtrim($base, '/') . '/shared-plan.php?t=' . urlencode($token);

echo json_encode(['ok'=>true, 'token'=>$token, 'url'=>$url]);
