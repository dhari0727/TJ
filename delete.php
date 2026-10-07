<?php
/**
 * JourneyAI — delete one of MY journal entries (POST + CSRF only).
 * Removes the expense rows (keyed by Title+eml), the entry, its photos/videos (rows + files), likes and comments.
 */
session_start();
require 'connection.php';
require_once 'ja-lib.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ja_csrf_ok()) { header('Location: my-entries.php'); exit; }
$id = (int)($_POST['id'] ?? 0);

// look up the entry's Title (join key) — only if owned by this user
$stmt = mysqli_prepare($conn, "SELECT Title FROM db WHERE entry_id = ? AND eml = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'is', $id, $em);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($row) {
    $title = $row['Title'];

    // photos/videos attached to this entry: rows, hashtags, likes, comments and the files themselves
    $s = mysqli_prepare($conn, "SELECT media_id, filepath FROM media WHERE entry_id = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'is', $id, $em); mysqli_stmt_execute($s);
    $media = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
    $root = realpath(__DIR__ . '/uploads');
    foreach ($media as $m) {
        $mid = (int)$m['media_id'];
        foreach (['media_hashtags', 'media_likes', 'media_comments'] as $t) mysqli_query($conn, "DELETE FROM `$t` WHERE media_id = $mid");
        mysqli_query($conn, "DELETE FROM media WHERE media_id = $mid");
        $p = realpath(__DIR__ . '/' . $m['filepath']);
        if ($p && $root && strpos($p, $root) === 0) @unlink($p);
    }
    mysqli_query($conn, "DELETE FROM journal_likes WHERE entry_id = " . (int)$id);
    mysqli_query($conn, "DELETE FROM journal_comments WHERE entry_id = " . (int)$id);

    // expense rows (keyed by Title+eml), then the core row (by id)
    foreach (['db1', 'db2', 'db3'] as $t) {
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
header('Location: my-entries.php');
exit;
