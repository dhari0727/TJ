<?php
/**
 * JourneyAI — AJAX endpoint: submit/update a user's 1-5 star rating for a destination.
 * Keeps one rating row per (eml, destination): checks for an existing 'rating' row
 * first and UPDATEs it, else INSERTs. Returns the fresh aggregate so the card can
 * update its displayed average without a page reload.
 */
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$dest   = isset($_POST['destination']) ? substr(trim($_POST['destination']), 0, 160) : '';
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;

if ($dest === '' || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

// One rating per user per destination: update if it exists, else insert.
$stmt = mysqli_prepare($conn,
    "SELECT id FROM interactions WHERE eml=? AND destination=? AND interaction_type='rating' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ss', $eml, $dest);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($existing) {
    $stmt = mysqli_prepare($conn, "UPDATE interactions SET rating=?, created_at=NOW() WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $rating, $existing['id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO interactions (eml, destination, rating, interaction_type) VALUES (?,?,?, 'rating')");
    mysqli_stmt_bind_param($stmt, 'ssi', $eml, $dest, $rating);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Fresh aggregate for optimistic UI update.
$stmt = mysqli_prepare($conn,
    "SELECT AVG(rating) avg_r, COUNT(*) n FROM interactions WHERE destination=? AND interaction_type='rating' AND rating IS NOT NULL");
mysqli_stmt_bind_param($stmt, 's', $dest);
mysqli_stmt_execute($stmt);
$agg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

echo json_encode([
    'ok'  => true,
    'avg' => $agg && $agg['avg_r'] !== null ? (float)$agg['avg_r'] : $rating,
    'n'   => $agg ? (int)$agg['n'] : 1,
]);
