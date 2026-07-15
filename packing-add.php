<?php
/**
 * JourneyAI — add a packing item to a plan the session user owns.
 * POST {plan_id, label, category?} -> INSERT with next sort_order.
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error'=>'Please log in.']); exit; }

$planId   = (int)($_POST['plan_id'] ?? 0);
$label    = substr(trim($_POST['label'] ?? ''), 0, 160);
$category = substr(trim($_POST['category'] ?? 'general'), 0, 60) ?: 'general';

if (!$planId || $label === '') { echo json_encode(['error'=>'Item label required.']); exit; }

// Ownership check.
$s = mysqli_prepare($conn, "SELECT plan_id FROM saved_plans WHERE plan_id=? AND eml=?");
mysqli_stmt_bind_param($s, 'is', $planId, $eml);
mysqli_stmt_execute($s);
$owned = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);
if (!$owned) { echo json_encode(['error'=>'Not found.']); exit; }

$s = mysqli_prepare($conn, "SELECT COALESCE(MAX(sort_order),-1)+1 AS n FROM packing_items WHERE plan_id=?");
mysqli_stmt_bind_param($s, 'i', $planId);
mysqli_stmt_execute($s);
$next = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['n'] ?? 0);
mysqli_stmt_close($s);

$s = mysqli_prepare($conn, "INSERT INTO packing_items (plan_id, label, category, sort_order) VALUES (?,?,?,?)");
mysqli_stmt_bind_param($s, 'issi', $planId, $label, $category, $next);
mysqli_stmt_execute($s);
$itemId = mysqli_insert_id($conn);
mysqli_stmt_close($s);

echo json_encode(['ok'=>true, 'item_id'=>$itemId, 'label'=>$label, 'category'=>$category, 'sort_order'=>$next]);
