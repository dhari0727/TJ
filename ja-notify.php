<?php
/**
 * JourneyAI — simple rule-based notifications, generated on the fly (no
 * cron/worker). Call ja_generate_notifications($conn, $eml) once per
 * dashboard load: it runs a couple of cheap queries, inserts any newly
 * "earned" notifications (de-duped by kind+link within the last 30 days),
 * then returns the user's current unread notifications for rendering.
 */

/**
 * Insert a notification for $eml unless an equivalent one (same eml+kind+link)
 * was already created in the last 30 days.
 */
function ja_notify_once($conn, $eml, $kind, $message, $link) {
    $stmt = mysqli_prepare($conn,
        "SELECT notif_id FROM notifications
         WHERE eml=? AND kind=? AND link<=>? AND created_at >= (NOW() - INTERVAL 30 DAY)
         LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'sss', $eml, $kind, $link);
    mysqli_stmt_execute($stmt);
    $dupe = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($dupe) return;

    $stmt = mysqli_prepare($conn,
        "INSERT INTO notifications (eml, kind, message, link) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'ssss', $eml, $kind, $message, $link);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Run the notification rules for $eml, insert any new ones, then return
 * the unread notifications (newest first) for rendering.
 */
function ja_generate_notifications($conn, $eml) {
    // Rule 1: journal entries with zero media -> nudge to add photos.
    $stmt = mysqli_prepare($conn,
        "SELECT db.entry_id, db.Title FROM db
         LEFT JOIN media ON media.entry_id = db.entry_id
         WHERE db.eml=? AND media.media_id IS NULL
         LIMIT 3");
    mysqli_stmt_bind_param($stmt, 's', $eml);
    mysqli_stmt_execute($stmt);
    $noMedia = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    foreach ($noMedia as $row) {
        $link = 'display.php?id=' . (int)$row['entry_id'];
        $title = $row['Title'] !== '' ? $row['Title'] : 'your trip';
        ja_notify_once($conn, $eml, 'add_photos', "Add photos to your trip to {$title}", $link);
    }

    // Rule 2: upcoming trip (dv within the next 14 days).
    $stmt = mysqli_prepare($conn,
        "SELECT entry_id, Title, City, Country, DATEDIFF(dv, CURDATE()) AS days_out
         FROM db
         WHERE eml=? AND dv IS NOT NULL AND dv <> ''
           AND STR_TO_DATE(dv, '%Y-%m-%d') IS NOT NULL
           AND DATEDIFF(dv, CURDATE()) BETWEEN 0 AND 14
         LIMIT 5");
    mysqli_stmt_bind_param($stmt, 's', $eml);
    mysqli_stmt_execute($stmt);
    $upcoming = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    foreach ($upcoming as $row) {
        $place = $row['City'] ?: $row['Title'];
        $days  = (int)$row['days_out'];
        $when  = $days === 0 ? 'today' : ($days === 1 ? 'in 1 day' : "in {$days} days");
        $link  = 'display.php?id=' . (int)$row['entry_id'];
        ja_notify_once($conn, $eml, 'plan_reminder', "Your trip to {$place} is coming up {$when}", $link);
    }

    // Fetch current unread notifications for rendering.
    $stmt = mysqli_prepare($conn,
        "SELECT notif_id, kind, message, link, created_at FROM notifications
         WHERE eml=? AND is_read=0 ORDER BY created_at DESC LIMIT 20");
    mysqli_stmt_bind_param($stmt, 's', $eml);
    mysqli_stmt_execute($stmt);
    $unread = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    return $unread;
}
