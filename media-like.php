<?php
/**
 * JourneyAI — toggle a like on a media post for the logged-in user.
 * POST media_id -> toggles media_likes row, keeps media.likes counter in sync.
 * Returns JSON: {ok:true, liked:bool, likes:int} | {error:"..."}
 */
error_reporting(0);
session_start();
require 'connection.php';
header('Content-Type: application/json');

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { echo json_encode(['error' => 'Please log in.']); exit; }

$mediaId = (int)($_POST['media_id'] ?? 0);
if ($mediaId <= 0) { echo json_encode(['error' => 'Invalid media.']); exit; }

// does the media post exist?
$s = mysqli_prepare($conn, "SELECT media_id, likes FROM media WHERE media_id=?");
mysqli_stmt_bind_param($s, 'i', $mediaId);
mysqli_stmt_execute($s);
$media = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);
if (!$media) { echo json_encode(['error' => 'Post not found.']); exit; }

// already liked?
$s = mysqli_prepare($conn, "SELECT 1 FROM media_likes WHERE media_id=? AND eml=?");
mysqli_stmt_bind_param($s, 'is', $mediaId, $eml);
mysqli_stmt_execute($s);
$already = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

mysqli_begin_transaction($conn);
try {
    if ($already) {
        $s = mysqli_prepare($conn, "DELETE FROM media_likes WHERE media_id=? AND eml=?");
        mysqli_stmt_bind_param($s, 'is', $mediaId, $eml);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $s = mysqli_prepare($conn, "UPDATE media SET likes = GREATEST(0, likes - 1) WHERE media_id=?");
        mysqli_stmt_bind_param($s, 'i', $mediaId);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $liked = false;
    } else {
        $s = mysqli_prepare($conn, "INSERT INTO media_likes (media_id, eml) VALUES (?,?)");
        mysqli_stmt_bind_param($s, 'is', $mediaId, $eml);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $s = mysqli_prepare($conn, "UPDATE media SET likes = likes + 1 WHERE media_id=?");
        mysqli_stmt_bind_param($s, 'i', $mediaId);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $liked = true;
    }
    mysqli_commit($conn);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['error' => 'Could not update like.']); exit;
}

$s = mysqli_prepare($conn, "SELECT likes FROM media WHERE media_id=?");
mysqli_stmt_bind_param($s, 'i', $mediaId);
mysqli_stmt_execute($s);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

echo json_encode(['ok' => true, 'liked' => $liked, 'likes' => (int)($row['likes'] ?? 0)]);
