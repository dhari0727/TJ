<?php
/**
 * JourneyAI — save a recommended destination as a 'plan' interaction.
 * Writes to the interactions table (prepared statement) then returns to plan-trip.
 * Closing the CF loop: saved plans influence future collaborative recommendations.
 */
session_start();
require 'connection.php';

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['destination'])) {
    $dest = substr(trim($_POST['destination']), 0, 160);
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO interactions (eml, destination, rating, interaction_type) VALUES (?,?,?, 'plan')"
    );
    $rating = 4; // an explicit save signals positive intent
    mysqli_stmt_bind_param($stmt, 'ssi', $eml, $dest, $rating);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['ja_flash'] = "Saved “$dest” to your plans.";
}
header('Location: recommendations.php');
exit;
