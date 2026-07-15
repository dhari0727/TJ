<?php
/**
 * JourneyAI — save / delete a route for the logged-in user.
 * POST action=save (title, origin, mode, data JSON) | action=delete (route_id)
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');
if (empty($_SESSION['eml'])) { echo json_encode(['error'=>'Please log in.']); exit; }
$eml = $_SESSION['eml'];
$action = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    $rid = (int)($_POST['route_id'] ?? 0);
    $s = mysqli_prepare($conn, "DELETE FROM saved_routes WHERE route_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $rid, $eml);
    mysqli_stmt_execute($s); mysqli_stmt_close($s);
    echo json_encode(['ok'=>true]); exit;
}

// save
$title  = substr(trim($_POST['title'] ?? 'My route'), 0, 200) ?: 'My route';
$origin = substr(trim($_POST['origin'] ?? ''), 0, 200);
$mode   = substr($_POST['mode'] ?? 'day', 0, 20);
$data   = $_POST['data'] ?? '{}';
$total  = (float)($_POST['total_km'] ?? 0);
// validate JSON
json_decode($data);
if (json_last_error() !== JSON_ERROR_NONE) { echo json_encode(['error'=>'Bad data.']); exit; }

$s = mysqli_prepare($conn,
    "INSERT INTO saved_routes (eml, title, origin, mode, data, total_km) VALUES (?,?,?,?,?,?)");
mysqli_stmt_bind_param($s, 'sssssd', $eml, $title, $origin, $mode, $data, $total);
mysqli_stmt_execute($s);
$rid = mysqli_insert_id($conn);
mysqli_stmt_close($s);
echo json_encode(['ok'=>true, 'route_id'=>$rid]);
