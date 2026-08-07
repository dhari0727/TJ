<?php
error_reporting(0);
session_start();
require 'connection.php';
require 'ja-media.php';
header('Content-Type: application/json');

$em = $_SESSION['eml'] ?? '';
$loggedIn = $em !== '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Actions below require a logged-in owner; everything else (get_book, get_themes,
// get_comments, get_cost) is readable anonymously and gates access per-book instead,
// so anonymous visitors can view public/link-shared storybooks.
$sb_login_required = ['create_book','update_book','delete_book','add_page','update_page',
    'delete_page','reorder_pages','upload_photo','upload_cover','import_entry',
    'add_comment','toggle_like'];
if (in_array($action, $sb_login_required, true) && !$loggedIn) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required']);
    exit;
}

function sb_json_input($key, $default = null) {
    $v = $_POST[$key] ?? $default;
    if ($v === null || $v === '') return $default;
    if (is_string($v) && (str_starts_with($v, '{') || str_starts_with($v, '['))) {
        $d = json_decode($v, true);
        return ($d !== null) ? $d : $v;
    }
    return $v;
}

function sb_verify_owner($conn, $book_id, $eml) {
    $s = mysqli_prepare($conn, "SELECT eml FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($s, 'i', $book_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return ($row && $row['eml'] === $eml);
}

function sb_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function sb_ok($data) {
    echo json_encode($data);
    exit;
}

function sb_page_owner($conn, $page_id, $eml) {
    $s = mysqli_prepare($conn, "SELECT b.eml FROM storybook_pages p JOIN storybooks b ON b.book_id = p.book_id WHERE p.page_id = ?");
    mysqli_stmt_bind_param($s, 'i', $page_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return ($row && $row['eml'] === $eml);
}

function sb_book_by_page($conn, $page_id) {
    $s = mysqli_prepare($conn, "SELECT book_id FROM storybook_pages WHERE page_id = ?");
    mysqli_stmt_bind_param($s, 'i', $page_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row ? (int)$row['book_id'] : 0;
}

// Visibility gate shared by every anonymous-readable action: owner, or public,
// or a matching link-share token. $book needs at least 'eml','visibility','share_token'.
function sb_can_view_book($book, $em, $token) {
    if (!$book) return false;
    if ($em !== '' && $book['eml'] === $em) return true;
    if ($book['visibility'] === 'public') return true;
    if ($book['visibility'] === 'link' && $token && $token === $book['share_token']) return true;
    return false;
}

switch ($action) {

// ── 1. get_book ──────────────────────────────────────────────────────
case 'get_book':
    $book_id = (int)($_GET['book_id'] ?? 0);
    if ($book_id <= 0) sb_fail('Missing book_id');

    $s = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($s, 'i', $book_id);
    mysqli_stmt_execute($s);
    $book = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$book) sb_fail('Book not found', 404);

    $isOwner = ($book['eml'] === $em);
    $isPublic = ($book['visibility'] === 'public');
    $hasToken = !empty($_GET['token']) && $_GET['token'] === $book['share_token'];
    if (!$isOwner && !$isPublic && !$hasToken) sb_fail('Not authorized', 403);

    $ps = mysqli_prepare($conn, "SELECT * FROM storybook_pages WHERE book_id = ? ORDER BY sort_order ASC");
    mysqli_stmt_bind_param($ps, 'i', $book_id);
    mysqli_stmt_execute($ps);
    $pages = mysqli_fetch_all(mysqli_stmt_get_result($ps), MYSQLI_ASSOC);
    mysqli_stmt_close($ps);

    $ownerName = '';
    $os = mysqli_prepare($conn, "SELECT fname, lname FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($os, 's', $book['eml']);
    mysqli_stmt_execute($os);
    $owner = mysqli_fetch_assoc(mysqli_stmt_get_result($os));
    mysqli_stmt_close($os);
    if ($owner) $ownerName = trim($owner['fname'] . ' ' . $owner['lname']);

    sb_ok([
        'book_id'     => (int)$book['book_id'],
        'title'       => $book['title'],
        'subtitle'    => $book['subtitle'],
        'theme'       => $book['theme'],
        'cover_img'   => $book['cover_img'],
        'visibility'  => $book['visibility'],
        'page_order'  => $book['page_order'],
        'pages'       => array_map(function($p) {
            return [
                'page_id'       => (int)$p['page_id'],
                'sort_order'    => (int)$p['sort_order'],
                'template'      => $p['template'],
                'title'         => $p['title'],
                'body_text'     => $p['body_text'],
                'page_date'     => $p['page_date'],
                'photo_1'       => $p['photo_1'],
                'photo_1_cap'   => $p['photo_1_cap'],
                'photo_2'       => $p['photo_2'],
                'photo_2_cap'   => $p['photo_2_cap'],
                'photo_3'       => $p['photo_3'],
                'photo_3_cap'   => $p['photo_3_cap'],
                'photo_4'       => $p['photo_4'],
                'photo_4_cap'   => $p['photo_4_cap'],
                'decorations'   => $p['decorations'],
                'cost_entry_id' => $p['cost_entry_id'] !== null ? (int)$p['cost_entry_id'] : null,
            ];
        }, $pages),
        'total_pages' => count($pages),
        'owner_name'  => $ownerName,
    ]);
    break;

// ── 2. create_book ───────────────────────────────────────────────────
case 'create_book':
    $title  = trim($_POST['title'] ?? '');
    $theme  = trim($_POST['theme'] ?? 'floral');
    if ($title === '') sb_fail('Title is required');

    $s = mysqli_prepare($conn, "INSERT INTO storybooks (eml, title, theme) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($s, 'sss', $em, $title, $theme);
    mysqli_stmt_execute($s);
    $bookId = mysqli_insert_id($conn);
    mysqli_stmt_close($s);

    sb_ok(['book_id' => (int)$bookId]);
    break;

// ── 3. update_book ───────────────────────────────────────────────────
case 'update_book':
    $book_id = (int)($_POST['book_id'] ?? 0);
    if ($book_id <= 0) sb_fail('Missing book_id');
    if (!sb_verify_owner($conn, $book_id, $em)) sb_fail('Not authorized', 403);

    $fields = [];
    $types  = '';
    $vals   = [];
    foreach (['title', 'subtitle', 'theme', 'visibility', 'page_order', 'cover_img'] as $f) {
        if (isset($_POST[$f])) {
            $fields[] = "$f = ?";
            $types  .= 's';
            $vals[]  = $_POST[$f];
        }
    }

    // Link-shared and public books need a share_token; generate one on first use.
    if (isset($_POST['visibility']) && in_array($_POST['visibility'], ['link', 'public'], true)) {
        $ts = mysqli_prepare($conn, "SELECT share_token FROM storybooks WHERE book_id = ?");
        mysqli_stmt_bind_param($ts, 'i', $book_id);
        mysqli_stmt_execute($ts);
        $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($ts));
        mysqli_stmt_close($ts);
        if (empty($trow['share_token'])) {
            $fields[] = "share_token = ?";
            $types  .= 's';
            $vals[]  = bin2hex(random_bytes(11)); // 22 chars, matches CHAR(22)
        }
    }

    if (empty($fields)) sb_fail('No fields to update');

    $vals[] = $book_id;
    $types .= 'i';
    $s = mysqli_prepare($conn, "UPDATE storybooks SET " . implode(', ', $fields) . " WHERE book_id = ?");
    mysqli_stmt_bind_param($s, $types, ...$vals);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    $rs = mysqli_prepare($conn, "SELECT visibility, share_token FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($rs, 'i', $book_id);
    mysqli_stmt_execute($rs);
    $rrow = mysqli_fetch_assoc(mysqli_stmt_get_result($rs));
    mysqli_stmt_close($rs);

    sb_ok(['ok' => 1, 'visibility' => $rrow['visibility'] ?? null, 'share_token' => $rrow['share_token'] ?? null]);
    break;

// ── 4. delete_book ───────────────────────────────────────────────────
case 'delete_book':
    $book_id = (int)($_POST['book_id'] ?? 0);
    if ($book_id <= 0) sb_fail('Missing book_id');
    if (!sb_verify_owner($conn, $book_id, $em)) sb_fail('Not authorized', 403);

    $s = mysqli_prepare($conn, "DELETE FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($s, 'i', $book_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    sb_ok(['ok' => 1]);
    break;

// ── 5. add_page ──────────────────────────────────────────────────────
case 'add_page':
    $book_id   = (int)($_POST['book_id'] ?? 0);
    $template  = trim($_POST['template'] ?? 'story');
    if ($book_id <= 0) sb_fail('Missing book_id');
    if (!sb_verify_owner($conn, $book_id, $em)) sb_fail('Not authorized', 403);

    $ms = mysqli_prepare($conn, "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM storybook_pages WHERE book_id = ?");
    mysqli_stmt_bind_param($ms, 'i', $book_id);
    mysqli_stmt_execute($ms);
    $mrow = mysqli_fetch_assoc(mysqli_stmt_get_result($ms));
    mysqli_stmt_close($ms);
    $nextOrder = (int)$mrow['next_order'];

    $s = mysqli_prepare($conn, "INSERT INTO storybook_pages (book_id, sort_order, template) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($s, 'iis', $book_id, $nextOrder, $template);
    mysqli_stmt_execute($s);
    $pageId = mysqli_insert_id($conn);
    mysqli_stmt_close($s);

    sb_ok(['page_id' => (int)$pageId, 'sort_order' => $nextOrder]);
    break;

// ── 6. update_page ───────────────────────────────────────────────────
case 'update_page':
    $page_id = (int)($_POST['page_id'] ?? 0);
    if ($page_id <= 0) sb_fail('Missing page_id');
    if (!sb_page_owner($conn, $page_id, $em)) sb_fail('Not authorized', 403);

    $fields = [];
    $types  = '';
    $vals   = [];
    $textFields = ['title', 'body_text', 'page_date', 'cost_entry_id'];
    foreach ($textFields as $f) {
        if (isset($_POST[$f])) {
            $fields[] = "$f = ?";
            $types  .= 's';
            $vals[]  = $_POST[$f] !== '' ? $_POST[$f] : null;
        }
    }
    if (isset($_POST['decorations'])) {
        $fields[] = "decorations = ?";
        $types  .= 's';
        $decVal = $_POST['decorations'];
        $vals[]  = is_string($decVal) ? $decVal : json_encode($decVal);
    }
    foreach (['photo_1','photo_2','photo_3','photo_4'] as $ph) {
        if (isset($_POST[$ph])) {
            $fields[] = "$ph = ?";
            $types  .= 's';
            $vals[]  = $_POST[$ph] !== '' ? $_POST[$ph] : null;
        }
    }
    foreach (['photo_1_cap','photo_2_cap','photo_3_cap','photo_4_cap'] as $pc) {
        if (isset($_POST[$pc])) {
            $fields[] = "$pc = ?";
            $types  .= 's';
            $vals[]  = $_POST[$pc] !== '' ? $_POST[$pc] : null;
        }
    }
    if (empty($fields)) sb_fail('No fields to update');

    $vals[] = $page_id;
    $types .= 'i';
    $s = mysqli_prepare($conn, "UPDATE storybook_pages SET " . implode(', ', $fields) . " WHERE page_id = ?");
    mysqli_stmt_bind_param($s, $types, ...$vals);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    sb_ok(['ok' => 1]);
    break;

// ── 7. delete_page ───────────────────────────────────────────────────
case 'delete_page':
    $page_id = (int)($_POST['page_id'] ?? 0);
    if ($page_id <= 0) sb_fail('Missing page_id');
    if (!sb_page_owner($conn, $page_id, $em)) sb_fail('Not authorized', 403);

    $bookId = sb_book_by_page($conn, $page_id);

    $s = mysqli_prepare($conn, "DELETE FROM storybook_pages WHERE page_id = ?");
    mysqli_stmt_bind_param($s, 'i', $page_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    $rs = mysqli_prepare($conn, "SELECT page_id FROM storybook_pages WHERE book_id = ? ORDER BY sort_order ASC");
    mysqli_stmt_bind_param($rs, 'i', $bookId);
    mysqli_stmt_execute($rs);
    $remaining = mysqli_fetch_all(mysqli_stmt_get_result($rs), MYSQLI_ASSOC);
    mysqli_stmt_close($rs);
    foreach ($remaining as $idx => $r) {
        $us = mysqli_prepare($conn, "UPDATE storybook_pages SET sort_order = ? WHERE page_id = ?");
        $newOrd = $idx;
        mysqli_stmt_bind_param($us, 'ii', $newOrd, $r['page_id']);
        mysqli_stmt_execute($us);
        mysqli_stmt_close($us);
    }

    sb_ok(['ok' => 1]);
    break;

// ── 8. reorder_pages ─────────────────────────────────────────────────
case 'reorder_pages':
    $book_id  = (int)($_POST['book_id'] ?? 0);
    $page_ids = sb_json_input('page_ids');
    if ($book_id <= 0) sb_fail('Missing book_id');
    if (!is_array($page_ids) || empty($page_ids)) sb_fail('Missing page_ids');
    if (!sb_verify_owner($conn, $book_id, $em)) sb_fail('Not authorized', 403);

    foreach ($page_ids as $idx => $pid) {
        $pid = (int)$pid;
        $us = mysqli_prepare($conn, "UPDATE storybook_pages SET sort_order = ? WHERE page_id = ? AND book_id = ?");
        mysqli_stmt_bind_param($us, 'iii', $idx, $pid, $book_id);
        mysqli_stmt_execute($us);
        mysqli_stmt_close($us);
    }

    sb_ok(['ok' => 1]);
    break;

// ── 9. upload_photo ──────────────────────────────────────────────────
case 'upload_photo':
    $page_id = (int)($_POST['page_id'] ?? 0);
    $slot    = (int)($_POST['slot'] ?? 0);
    if ($page_id <= 0) sb_fail('Missing page_id');
    if ($slot < 1 || $slot > 4) sb_fail('Slot must be 1-4');
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) sb_fail('No file uploaded');
    if (!sb_page_owner($conn, $page_id, $em)) sb_fail('Not authorized', 403);

    $bookId = sb_book_by_page($conn, $page_id);
    if (!$bookId) sb_fail('Book not found', 404);

    [$mediaId, $uploadErr] = ja_handle_upload($conn, $_FILES['file'], $em, null, '', null, 1, null, null);
    if ($uploadErr) sb_fail($uploadErr);
    if (!$mediaId) sb_fail('Upload failed');

    $us = mysqli_prepare($conn, "UPDATE media SET book_id = ? WHERE media_id = ?");
    mysqli_stmt_bind_param($us, 'ii', $bookId, $mediaId);
    mysqli_stmt_execute($us);
    mysqli_stmt_close($us);

    $fs = mysqli_prepare($conn, "SELECT filepath FROM media WHERE media_id = ?");
    mysqli_stmt_bind_param($fs, 'i', $mediaId);
    mysqli_stmt_execute($fs);
    $frow = mysqli_fetch_assoc(mysqli_stmt_get_result($fs));
    mysqli_stmt_close($fs);
    $filepath = $frow ? $frow['filepath'] : '';

    $col = "photo_$slot";
    $us2 = mysqli_prepare($conn, "UPDATE storybook_pages SET $col = ? WHERE page_id = ?");
    mysqli_stmt_bind_param($us2, 'si', $filepath, $page_id);
    mysqli_stmt_execute($us2);
    mysqli_stmt_close($us2);

    sb_ok(['filepath' => $filepath, 'media_id' => (int)$mediaId]);
    break;

// ── 10. upload_cover ─────────────────────────────────────────────────
case 'upload_cover':
    $book_id = (int)($_POST['book_id'] ?? 0);
    if ($book_id <= 0) sb_fail('Missing book_id');
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) sb_fail('No file uploaded');
    if (!sb_verify_owner($conn, $book_id, $em)) sb_fail('Not authorized', 403);

    [$mediaId, $uploadErr] = ja_handle_upload($conn, $_FILES['file'], $em, null, '', null, 1, null, null);
    if ($uploadErr) sb_fail($uploadErr);
    if (!$mediaId) sb_fail('Upload failed');

    $us = mysqli_prepare($conn, "UPDATE media SET book_id = ? WHERE media_id = ?");
    mysqli_stmt_bind_param($us, 'ii', $book_id, $mediaId);
    mysqli_stmt_execute($us);
    mysqli_stmt_close($us);

    $fs = mysqli_prepare($conn, "SELECT filepath FROM media WHERE media_id = ?");
    mysqli_stmt_bind_param($fs, 'i', $mediaId);
    mysqli_stmt_execute($fs);
    $frow = mysqli_fetch_assoc(mysqli_stmt_get_result($fs));
    mysqli_stmt_close($fs);
    $filepath = $frow ? $frow['filepath'] : '';

    $us2 = mysqli_prepare($conn, "UPDATE storybooks SET cover_img = ? WHERE book_id = ?");
    mysqli_stmt_bind_param($us2, 'si', $filepath, $book_id);
    mysqli_stmt_execute($us2);
    mysqli_stmt_close($us2);

    sb_ok(['filepath' => $filepath]);
    break;

// ── 11. add_comment ──────────────────────────────────────────────────
case 'add_comment':
    $page_id = (int)($_POST['page_id'] ?? 0);
    $body    = trim($_POST['body'] ?? '');
    if ($page_id <= 0) sb_fail('Missing page_id');
    if ($body === '') sb_fail('Comment body is required');

    $bookId = sb_book_by_page($conn, $page_id);
    if (!$bookId) sb_fail('Page not found', 404);

    $bs = mysqli_prepare($conn, "SELECT visibility FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($bs, 'i', $bookId);
    mysqli_stmt_execute($bs);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bs));
    mysqli_stmt_close($bs);
    if (!$brow || $brow['visibility'] !== 'public') sb_fail('Can only comment on public books', 403);

    $s = mysqli_prepare($conn, "INSERT INTO storybook_comments (page_id, eml, body) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($s, 'iss', $page_id, $em, $body);
    mysqli_stmt_execute($s);
    $commentId = mysqli_insert_id($conn);
    mysqli_stmt_close($s);

    $cs = mysqli_prepare($conn, "SELECT created_at FROM storybook_comments WHERE comment_id = ?");
    mysqli_stmt_bind_param($cs, 'i', $commentId);
    mysqli_stmt_execute($cs);
    $crow = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);

    sb_ok(['comment_id' => (int)$commentId, 'created_at' => $crow ? $crow['created_at'] : null]);
    break;

// ── 12. get_comments ─────────────────────────────────────────────────
case 'get_comments':
    $page_id = (int)($_GET['page_id'] ?? 0);
    if ($page_id <= 0) sb_fail('Missing page_id');

    $bs = mysqli_prepare($conn, "SELECT b.eml, b.visibility, b.share_token
                                  FROM storybook_pages p JOIN storybooks b ON b.book_id = p.book_id
                                  WHERE p.page_id = ?");
    mysqli_stmt_bind_param($bs, 'i', $page_id);
    mysqli_stmt_execute($bs);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bs));
    mysqli_stmt_close($bs);
    if (!sb_can_view_book($brow, $em, $_GET['token'] ?? '')) sb_fail('Not authorized', 403);

    $s = mysqli_prepare($conn,
        "SELECT c.comment_id, c.body, c.created_at, u.fname, u.lname
         FROM storybook_comments c
         JOIN signup u ON u.eml = c.eml
         WHERE c.page_id = ?
         ORDER BY c.created_at ASC");
    mysqli_stmt_bind_param($s, 'i', $page_id);
    mysqli_stmt_execute($s);
    $comments = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
    mysqli_stmt_close($s);

    sb_ok(['comments' => array_map(function($c) {
        return [
            'comment_id' => (int)$c['comment_id'],
            'body'       => $c['body'],
            'created_at' => $c['created_at'],
            'fname'      => $c['fname'],
            'lname'      => $c['lname'],
        ];
    }, $comments)]);
    break;

// ── 13. toggle_like ──────────────────────────────────────────────────
case 'toggle_like':
    $book_id = (int)($_POST['book_id'] ?? 0);
    if ($book_id <= 0) sb_fail('Missing book_id');

    $bs = mysqli_prepare($conn, "SELECT visibility FROM storybooks WHERE book_id = ?");
    mysqli_stmt_bind_param($bs, 'i', $book_id);
    mysqli_stmt_execute($bs);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bs));
    mysqli_stmt_close($bs);
    if (!$brow || $brow['visibility'] !== 'public') sb_fail('Can only like public books', 403);

    $cs = mysqli_prepare($conn, "SELECT 1 FROM storybook_likes WHERE book_id = ? AND eml = ?");
    mysqli_stmt_bind_param($cs, 'is', $book_id, $em);
    mysqli_stmt_execute($cs);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);

    if ($exists) {
        $ds = mysqli_prepare($conn, "DELETE FROM storybook_likes WHERE book_id = ? AND eml = ?");
        mysqli_stmt_bind_param($ds, 'is', $book_id, $em);
        mysqli_stmt_execute($ds);
        mysqli_stmt_close($ds);
        $liked = false;
    } else {
        $is = mysqli_prepare($conn, "INSERT INTO storybook_likes (book_id, eml) VALUES (?, ?)");
        mysqli_stmt_bind_param($is, 'is', $book_id, $em);
        mysqli_stmt_execute($is);
        mysqli_stmt_close($is);
        $liked = true;
    }

    $ls = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM storybook_likes WHERE book_id = ?");
    mysqli_stmt_bind_param($ls, 'i', $book_id);
    mysqli_stmt_execute($ls);
    $lrow = mysqli_fetch_assoc(mysqli_stmt_get_result($ls));
    mysqli_stmt_close($ls);

    sb_ok(['liked' => $liked, 'total_likes' => (int)$lrow['total']]);
    break;

// ── 14. import_entry ─────────────────────────────────────────────────
case 'import_entry':
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if ($entry_id <= 0) sb_fail('Missing entry_id');

    $es = mysqli_prepare($conn, "SELECT * FROM journals WHERE entry_id = ? AND eml = ? LIMIT 1");
    mysqli_stmt_bind_param($es, 'is', $entry_id, $em);
    mysqli_stmt_execute($es);
    $entry = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
    mysqli_stmt_close($es);
    if (!$entry) sb_fail('Entry not found', 404);

    $bookTitle = $entry['Title'];
    $s = mysqli_prepare($conn, "INSERT INTO storybooks (eml, title, theme) VALUES (?, ?, 'minimal')");
    mysqli_stmt_bind_param($s, 'ss', $em, $bookTitle);
    mysqli_stmt_execute($s);
    $newBookId = mysqli_insert_id($conn);
    mysqli_stmt_close($s);

    // page 1: cover
    $coverTitle = $bookTitle;
    $coverBody = trim(($entry['City'] ?? '') . ', ' . ($entry['Country'] ?? ''), ', ');
    if (!empty($entry['dv'])) $coverBody .= "\n" . $entry['dv'];
    if (!empty($entry['dr'])) $coverBody .= ' – ' . $entry['dr'];
    $coverDate = $entry['dv'] ?: null;

    $sp = mysqli_prepare($conn,
        "INSERT INTO storybook_pages (book_id, sort_order, template, title, body_text, page_date)
         VALUES (?, 0, 'cover', ?, ?, ?)");
    mysqli_stmt_bind_param($sp, 'isss', $newBookId, $coverTitle, $coverBody, $coverDate);
    mysqli_stmt_execute($sp);
    mysqli_stmt_close($sp);

    // page 2: story (if description exists)
    $order = 1;
    if (!empty($entry['Description'])) {
        $storyTitle = 'Our Story';
        $storyBody = $entry['Description'];
        $sp2 = mysqli_prepare($conn,
            "INSERT INTO storybook_pages (book_id, sort_order, template, title, body_text, page_date)
             VALUES (?, ?, 'story', ?, ?, ?)");
        mysqli_stmt_bind_param($sp2, 'iisss', $newBookId, $order, $storyTitle, $storyBody, $coverDate);
        mysqli_stmt_execute($sp2);
        mysqli_stmt_close($sp2);
        $order++;
    }

    // photo pages: fetch media for this entry, group into pages of 4
    $ms = mysqli_prepare($conn, "SELECT filepath, caption FROM media WHERE entry_id = ? AND eml = ? ORDER BY media_id ASC");
    mysqli_stmt_bind_param($ms, 'is', $entry_id, $em);
    mysqli_stmt_execute($ms);
    $mediaRows = mysqli_fetch_all(mysqli_stmt_get_result($ms), MYSQLI_ASSOC);
    mysqli_stmt_close($ms);

    $chunks = array_chunk($mediaRows, 4);
    foreach ($chunks as $chunk) {
        $slotData = [];
        foreach ($chunk as $i => $m) {
            $slotData[$i + 1] = [$m['filepath'], $m['caption'] ?? ''];
        }
        $phFields = [];
        $phVals   = [];
        $phTypes  = '';
        foreach ([1,2,3,4] as $n) {
            $phFields[] = "photo_$n";
            $phFields[] = "photo_${n}_cap";
            if (isset($slotData[$n])) {
                $phVals[] = $slotData[$n][0];
                $phTypes .= 's';
                $phVals[] = $slotData[$n][1];
                $phTypes .= 's';
            } else {
                $phVals[] = null;
                $phTypes .= 's';
                $phVals[] = null;
                $phTypes .= 's';
            }
        }
        $cols = implode(', ', $phFields);
        $placeholders = implode(', ', array_fill(0, count($phVals), '?'));
        $sp3 = mysqli_prepare($conn,
            "INSERT INTO storybook_pages (book_id, sort_order, template, title, page_date, $cols)
             VALUES (?, ?, 'photos', 'Photos', ?, $placeholders)");
        $params3 = array_merge([$newBookId, $order, $coverDate], $phVals);
        $types3 = 'iis' . $phTypes;
        mysqli_stmt_bind_param($sp3, $types3, ...$params3);
        mysqli_stmt_execute($sp3);
        mysqli_stmt_close($sp3);
        $order++;
    }

    // cost-card page
    $sp4 = mysqli_prepare($conn,
        "INSERT INTO storybook_pages (book_id, sort_order, template, title, cost_entry_id, page_date)
         VALUES (?, ?, 'cost-card', 'Cost Breakdown', ?, ?)");
    mysqli_stmt_bind_param($sp4, 'iiis', $newBookId, $order, $entry_id, $coverDate);
    mysqli_stmt_execute($sp4);
    mysqli_stmt_close($sp4);

    sb_ok(['book_id' => (int)$newBookId]);
    break;

// ── 15. get_themes ───────────────────────────────────────────────────
case 'get_themes':
    $ts = mysqli_prepare($conn, "SELECT * FROM storybooks_themes ORDER BY theme_id ASC");
    mysqli_stmt_execute($ts);
    $themes = mysqli_fetch_all(mysqli_stmt_get_result($ts), MYSQLI_ASSOC);
    mysqli_stmt_close($ts);

    sb_ok(['themes' => array_map(function($t) {
        return [
            'theme_id'    => $t['theme_id'],
            'label'       => $t['label'],
            'description' => $t['description'],
            'palette'     => $t['palette'],
            'fonts'       => $t['fonts'],
            'emoji_set'   => $t['emoji_set'],
        ];
    }, $themes)]);
    break;

// ── 16. get_cost ─────────────────────────────────────────────────────
// Cost-card pages link to db.entry_id via cost_entry_id. Read the
// aggregated per-category totals from the `journals` view (the same
// source import_entry reads from) rather than guessing at raw db1/db2/db3
// columns by title text.
case 'get_cost':
    $page_id = (int)($_GET['page_id'] ?? 0);
    if ($page_id <= 0) sb_fail('Missing page_id');

    $ps = mysqli_prepare($conn, "SELECT p.cost_entry_id, b.eml, b.visibility, b.share_token
                                  FROM storybook_pages p JOIN storybooks b ON b.book_id = p.book_id
                                  WHERE p.page_id = ?");
    mysqli_stmt_bind_param($ps, 'i', $page_id);
    mysqli_stmt_execute($ps);
    $prow = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$prow) sb_fail('Page not found', 404);
    if (!sb_can_view_book($prow, $em, $_GET['token'] ?? '')) sb_fail('Not authorized', 403);

    if (empty($prow['cost_entry_id'])) sb_ok(['has_data' => false]);

    $cs = mysqli_prepare($conn, "SELECT entry_id, duration_days, food_total, transport_total,
                                         accommodation_total, shopping_total, fees_misc_total, true_total
                                  FROM journals WHERE entry_id = ?");
    mysqli_stmt_bind_param($cs, 'i', $prow['cost_entry_id']);
    mysqli_stmt_execute($cs);
    $crow = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    if (!$crow) sb_ok(['has_data' => false]);

    sb_ok([
        'has_data'             => true,
        'entry_id'             => (int)$crow['entry_id'],
        'duration_days'        => $crow['duration_days'] !== null ? (int)$crow['duration_days'] : null,
        'food_total'           => (float)$crow['food_total'],
        'transport_total'      => (float)$crow['transport_total'],
        'accommodation_total'  => (float)$crow['accommodation_total'],
        'shopping_total'       => (float)$crow['shopping_total'],
        'fees_misc_total'      => (float)$crow['fees_misc_total'],
        'true_total'           => (float)$crow['true_total'],
    ]);
    break;

default:
    sb_fail('Unknown action');
}
