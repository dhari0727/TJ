<?php
/**
 * JourneyAI — delete a journal entry (and its expense rows) by entry_id.
 * Ownership enforced via eml; all queries parameterized.
 */
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$id = (int)($_GET['id'] ?? 0);

// look up the entry's Title (join key) — only if owned by this user
$stmt = mysqli_prepare($conn, "SELECT Title FROM db WHERE entry_id = ? AND eml = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'is', $id, $em);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($row) {
    $title = $row['Title'];
    // delete expense rows (keyed by Title+eml), then the core row (by id)
    foreach (['db1','db2','db3'] as $t) {
        $s = mysqli_prepare($conn, "DELETE FROM `$t` WHERE Title = ? AND eml = ?");
        mysqli_stmt_bind_param($s, 'ss', $title, $em);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    $s = mysqli_prepare($conn, "DELETE FROM db WHERE entry_id = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'is', $id, $em);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
}
header('Location: view-list.php');
exit;
