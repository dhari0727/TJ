<?php
/**
 * JourneyAI — mark a notification (or all notifications) as read.
 * POST notif_id=<id>  -> marks that one read (ownership-checked via eml).
 * POST all=1          -> marks all of the user's unread notifications read.
 * Responds JSON for AJAX use; falls back to redirecting back if not an AJAX call.
 */
session_start();
require 'connection.php';

$eml = $_SESSION['eml'] ?? null;
if (!$eml) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['all'])) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read=1 WHERE eml=? AND is_read=0");
        mysqli_stmt_bind_param($stmt, 's', $eml);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $ok = true;
    } elseif (!empty($_POST['notif_id'])) {
        $id = (int)$_POST['notif_id'];
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read=1 WHERE notif_id=? AND eml=?");
        mysqli_stmt_bind_param($stmt, 'is', $id, $eml);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_stmt_affected_rows($stmt) >= 0;
        mysqli_stmt_close($stmt);
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

$back = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header('Location: ' . $back);
exit;
