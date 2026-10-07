<?php
$ja_title = "View Storybook"; $ja_active = "entries";
session_start();
require 'connection.php';

$loggedIn = !empty($_SESSION['eml']);
$em = $loggedIn ? $_SESSION['eml'] : '';

$bookId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$shareToken = $_GET['token'] ?? null;

$book = null;
$isOwner = false;

if ($bookId > 0) {
    if ($loggedIn) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE book_id = ? AND (eml = ? OR visibility = 'public')");
        mysqli_stmt_bind_param($stmt, 'is', $bookId, $em);
        mysqli_stmt_execute($stmt);
        $book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($book && $book['eml'] === $em) $isOwner = true;
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE book_id = ? AND visibility = 'public'");
        mysqli_stmt_bind_param($stmt, 'i', $bookId);
        mysqli_stmt_execute($stmt);
        $book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
} elseif ($shareToken) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE share_token = ? AND visibility IN ('link','public')");
    mysqli_stmt_bind_param($stmt, 's', $shareToken);
    mysqli_stmt_execute($stmt);
    $book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($book) {
        $bookId = (int)$book['book_id'];
        if ($loggedIn && $book['eml'] === $em) $isOwner = true;
    }
}

if (!$book) { header('Location: my-storybooks.php'); exit; }
$bookId = (int)$book['book_id'];

$pageOrderSql = ($book['page_order'] === 'chronological')
    ? "ORDER BY page_date IS NULL, page_date ASC, sort_order ASC"
    : "ORDER BY sort_order ASC";
$stmt = mysqli_prepare($conn, "SELECT * FROM storybook_pages WHERE book_id = ? $pageOrderSql");
mysqli_stmt_bind_param($stmt, 'i', $bookId);
mysqli_stmt_execute($stmt);
$pages = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
$pageCount = count($pages);

$emojiSet = [];
$stmt = mysqli_prepare($conn, "SELECT emoji_set FROM storybooks_themes WHERE theme_id = ?");
mysqli_stmt_bind_param($stmt, 's', $book['theme']);
mysqli_stmt_execute($stmt);
$themeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if ($themeRow && $themeRow['emoji_set']) { $emojiSet = json_decode($themeRow['emoji_set'], true); }

$ownerName = '';
$stmt = mysqli_prepare($conn, "SELECT fname, lname FROM signup WHERE eml = ?");
mysqli_stmt_bind_param($stmt, 's', $book['eml']);
mysqli_stmt_execute($stmt);
$owner = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if ($owner) $ownerName = trim($owner['fname'] . ' ' . $owner['lname']);

$likeCount = 0;
$likedByMe = false;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM storybook_likes WHERE book_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $bookId);
mysqli_stmt_execute($stmt);
$lc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$likeCount = (int)($lc['cnt'] ?? 0);
if ($loggedIn) {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM storybook_likes WHERE book_id = ? AND eml = ?");
    mysqli_stmt_bind_param($stmt, 'is', $bookId, $em);
    mysqli_stmt_execute($stmt);
    $likedByMe = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
}

$jsonPages = json_encode(array_map(function($p) {
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
}, $pages));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($book['title']) ?> — JourneyAI</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Serif+Display&family=Lora:wght@400;500;600&family=Caveat:wght@400;500;600&family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/storybook.css">
  <style>
    .sb-viewer{display:flex;flex-direction:column;align-items:center;padding:32px 20px 40px;min-height:calc(100vh - 50px)}
    .sb-viewer .sb-page{margin-bottom:0;transition:opacity .25s ease,transform .25s ease}
    .sb-viewer .sb-page.sb-exit-left{opacity:0;transform:translateX(-30px)}
    .sb-viewer .sb-page.sb-exit-right{opacity:0;transform:translateX(30px)}
    .sb-viewer-nav{display:flex;align-items:center;gap:20px;margin-top:24px}
    .sb-viewer-nav .sb-btn{min-width:100px}
    .sb-viewer-page-counter{font-family:var(--sb-font-body);font-size:.85rem;color:var(--sb-text-dim);user-select:none}
    .sb-toolbar-book-owner{font-size:.82rem;color:var(--sb-text-dim);font-weight:400}
    .sb-toolbar-counter{font-size:.82rem;color:var(--sb-text-dim);white-space:nowrap}
    @media(max-width:600px){.sb-toolbar{flex-wrap:wrap;gap:6px;padding:8px 12px}.sb-toolbar-title{font-size:.95rem}.sb-toolbar-actions{gap:4px}.sb-toolbar-actions .sb-btn{padding:5px 8px;font-size:.75rem}.sb-viewer{padding:16px 10px 32px}.sb-viewer-nav{gap:10px}}
    .sb-comments-section{width:100%;max-width:var(--sb-page-w);margin:32px auto 0;padding:20px;background:var(--sb-surface);border:1px solid var(--sb-border);border-radius:10px}
    .sb-comments-section h3{font-family:var(--sb-font-display);font-size:1rem;font-weight:700;margin:0 0 14px}
    .sb-comment{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--sb-border)}
    .sb-comment:last-child{border-bottom:none}
    .sb-comment-avatar{width:32px;height:32px;border-radius:50%;background:var(--sb-slot-bg);color:var(--sb-accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0;font-family:var(--sb-font-body)}
    .sb-comment-body{flex:1}
    .sb-comment-name{font-weight:600;font-size:.82rem;margin-bottom:2px}
    .sb-comment-text{font-size:.88rem;color:var(--sb-text);line-height:1.45}
    .sb-comment-time{font-size:.72rem;color:var(--sb-text-dim);margin-top:3px}
    .sb-comment-form{display:flex;gap:8px;margin-top:14px}
    .sb-comment-form input{flex:1;padding:9px 12px;border:1px solid var(--sb-border);border-radius:8px;background:var(--sb-paper);color:var(--sb-text);font-family:var(--sb-font-body);font-size:.88rem;outline:none;transition:border-color .2s ease}
    .sb-comment-form input:focus{border-color:var(--sb-accent)}
    .sb-like-bar{display:flex;align-items:center;gap:10px;margin-bottom:18px}
    .sb-like-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;font-family:var(--sb-font-body);font-size:.85rem;font-weight:600;border:1px solid var(--sb-border);border-radius:8px;background:var(--sb-surface);color:var(--sb-text);cursor:pointer;transition:all .2s ease}
    .sb-like-btn:hover{border-color:var(--sb-accent)}
    .sb-like-btn.liked{background:var(--sb-accent);color:#fff;border-color:var(--sb-accent)}
    .sb-like-count{font-size:.82rem;color:var(--sb-text-dim)}
    .sb-empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;text-align:center;color:var(--sb-text-dim);min-height:400px}
    .sb-empty-icon{font-size:3rem;margin-bottom:16px;opacity:.4}
    .sb-empty-text{font-size:1.1rem;font-family:var(--sb-font-display);font-weight:700;margin-bottom:8px}
    .sb-slot{cursor:default}.sb-slot:hover{box-shadow:none}
  </style>
</head>
<body>
<div class="sb-shell" data-sb-theme="<?= htmlspecialchars($book['theme']) ?>">
  <div class="sb-toolbar">
    <?php if ($isOwner): ?>
      <a href="my-storybooks.php" class="sb-btn sb-btn-ghost sb-btn-sm">Back</a>
    <?php else: ?>
      <a href="feed.php" class="sb-btn sb-btn-ghost sb-btn-sm">Feed</a>
    <?php endif; ?>
    <span class="sb-toolbar-title"><?= htmlspecialchars($book['title']) ?></span>
    <?php if ($ownerName): ?>
      <span class="sb-toolbar-book-owner">by <?= htmlspecialchars($ownerName) ?></span>
    <?php endif; ?>
    <div class="sb-toolbar-actions">
      <span class="sb-toolbar-counter" id="sbPageCounter">Page 1 of <?= $pageCount ?></span>
      <?php if ($isOwner): ?>
        <a href="storybook-edit.php?id=<?= $bookId ?>" class="sb-btn sb-btn-sm">Edit</a>
        <button class="sb-btn sb-btn-sm" id="sbPrintBtn" type="button">Print</button>
        <button class="sb-btn sb-btn-sm" id="sbShareBtn" type="button">Share</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="sb-viewer" id="sbViewer">
    <?php if ($pageCount === 0): ?>
      <div class="sb-empty-state">
        <div class="sb-empty-icon">&#x1F4D6;</div>
        <div class="sb-empty-text">No pages yet</div>
        <?php if ($isOwner): ?>
          <p style="color:var(--sb-text-dim);margin-bottom:16px">Add pages to your storybook.</p>
          <a href="storybook-edit.php?id=<?= $bookId ?>" class="sb-btn sb-btn-primary">Edit Storybook</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div id="sbPageContainer"></div>
      <div class="sb-viewer-nav">
        <button class="sb-btn" id="sbPrevBtn" type="button">&larr; Prev</button>
        <span class="sb-viewer-page-counter" id="sbViewerCounter">Page 1 of <?= $pageCount ?></span>
        <span class="sb-rollup" id="sbRollup" style="display:none"></span>
        <button class="sb-btn" id="sbNextBtn" type="button">Next &rarr;</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($book['visibility'] === 'public'): ?>
    <div style="max-width:var(--sb-page-w);margin:0 auto;padding:0 20px">
      <div class="sb-like-bar">
        <button class="sb-btn sb-like-btn <?= $likedByMe ? 'liked' : '' ?>" id="sbLikeBtn" type="button">
          <span id="sbLikeIcon"><?= $likedByMe ? '&#x2764;&#xFE0F;' : '&#x1F44D;' ?></span>
          <span id="sbLikeText"><?= $likedByMe ? 'Liked' : 'Like' ?></span>
        </button>
        <span class="sb-like-count" id="sbLikeCount"><?= $likeCount ?> like<?= $likeCount !== 1 ? 's' : '' ?></span>
      </div>
    </div>

    <div class="sb-comments-section" id="sbCommentsSection">
      <h3>Comments</h3>
      <div id="sbCommentsList"></div>
      <?php if ($loggedIn): ?>
        <form class="sb-comment-form" id="sbCommentForm">
          <input type="text" id="sbCommentInput" placeholder="Write a comment..." autocomplete="off">
          <button class="sb-btn sb-btn-primary sb-btn-sm" type="submit">Post</button>
        </form>
      <?php else: ?>
        <p style="font-size:.85rem;color:var(--sb-text-dim);margin-top:12px">
          <a href="login.php" style="color:var(--sb-accent);font-weight:600">Log in</a> to leave a comment.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($isOwner): ?>
    <div class="sb-modal-overlay" id="sbShareOverlay" style="display:none">
      <div class="sb-modal" style="max-width:460px">
        <div class="sb-modal-title">Share this storybook</div>
        <div style="display:flex;flex-direction:column;gap:14px">
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="sbShareLink" readonly style="flex:1;font-size:.82rem;border:1px solid var(--sb-border);border-radius:6px;padding:8px 10px;background:var(--sb-paper);color:var(--sb-text);font-family:var(--sb-font-body)">
            <button class="sb-btn sb-btn-sm sb-btn-primary" id="sbShareCopyBtn" type="button">Copy</button>
          </div>
          <button class="sb-btn sb-btn-sm" id="sbShareCloseBtn" type="button" style="align-self:flex-end">Done</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div><!-- .sb-shell -->
<script>
(function(){
  var BOOK_ID = <?= $bookId ?>;
  var IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;
  var IS_PUBLIC = <?= $book['visibility'] === 'public' ? 'true' : 'false' ?>;
  var LOGGED_IN = <?= $loggedIn ? 'true' : 'false' ?>;
  var BOOK_VISIBILITY = <?= json_encode($book['visibility']) ?>;
  var SHARE_TOKEN = <?= json_encode($book['share_token']) ?>;
  var pages = <?= $jsonPages ?>;
  var currentIdx = 0;

  function esc(s) {
    if (s == null) return '';
    var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
  }

  function renderDecos(page) {
    if (!page.decorations) return '';
    var decos = page.decorations;
    if (typeof decos === 'string') { try { decos = JSON.parse(decos); } catch(e) { return ''; } }
    if (!Array.isArray(decos) || decos.length === 0) return '';
    var h = '';
    decos.forEach(function(d) {
      h += '<div class="sb-deco" style="top:' + esc(d.top||'10%') + ';left:' + esc(d.left||'10%') + ';position:absolute;cursor:default">' + esc(d.emoji||'') + '</div>';
    });
    return h;
  }

  function photoSlot(page, num, cls) {
    var pk = 'photo_' + num;
    var filepath = page[pk] || '';
    var divCls = 'sb-slot' + (cls ? ' ' + cls : '') + (filepath ? ' filled' : '');
    if (filepath) {
      return '<div class="' + divCls + '"><img class="sb-slot-img" src="' + esc(filepath) + '"></div>';
    }
    return '<div class="' + divCls + '"><span class="sb-slot-empty-hint">No photo</span></div>';
  }

  function renderCover(page) {
    var h = '<div class="sb-page-inner sb-tpl-cover">';
    h += photoSlot(page, 1, 'hero');
    if (page.title) h += '<div class="sb-page-title">' + esc(page.title) + '</div>';
    if (page.page_date) h += '<div class="sb-page-subtitle">' + esc(page.page_date) + '</div>';
    if (page.body_text) h += '<div class="sb-hand-note">' + esc(page.body_text) + '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderPhotoCaption(page) {
    var h = '<div class="sb-page-inner sb-tpl-photo-caption">';
    h += photoSlot(page, 1, 'main-photo');
    h += '<div>';
    if (page.title) h += '<div class="sb-page-title">' + esc(page.title) + '</div>';
    if (page.photo_1_cap) h += '<div class="sb-page-subtitle">' + esc(page.photo_1_cap) + '</div>';
    else if (page.body_text) h += '<div class="sb-page-subtitle">' + esc(page.body_text) + '</div>';
    h += '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderTwoPhoto(page) {
    var h = '<div class="sb-page-inner sb-tpl-two-photo">';
    h += '<div class="sb-photo-row">';
    h += photoSlot(page, 1);
    h += photoSlot(page, 2);
    h += '</div>';
    h += '<div>';
    if (page.title) h += '<div class="sb-page-title">' + esc(page.title) + '</div>';
    if (page.body_text) h += '<div class="sb-page-subtitle">' + esc(page.body_text) + '</div>';
    h += '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderStory(page) {
    var h = '<div class="sb-page-inner sb-tpl-story">';
    if (page.title) h += '<div class="sb-page-title">' + esc(page.title) + '</div>';
    if (page.page_date) h += '<div class="sb-page-subtitle">' + esc(page.page_date) + '</div>';
    if (page.body_text) h += '<div class="sb-page-body">' + esc(page.body_text) + '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderCostCard(page) {
    var h = '<div class="sb-page-inner sb-tpl-cost-card">';
    h += '<div class="sb-cost-title">' + esc(page.title || 'Cost Breakdown') + '</div>';
    h += '<div class="sb-cost-rows" id="sbCostDisplay">';
    if (page.cost_entry_id) {
      h += '<div style="text-align:center;color:var(--sb-text-dim);padding:20px">Loading costs...</div>';
    } else {
      h += '<div style="text-align:center;padding:30px 10px;color:var(--sb-text-dim)"><div style="font-size:2rem;margin-bottom:10px">&#x1F4CB;</div><div>No cost data linked</div></div>';
    }
    h += '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderPhotoGrid(page) {
    var h = '<div class="sb-page-inner sb-tpl-photo-grid">';
    h += '<div class="sb-grid-2x2">';
    for (var n = 1; n <= 4; n++) h += photoSlot(page, n);
    h += '</div>';
    if (page.title) h += '<div class="sb-page-title">' + esc(page.title) + '</div>';
    h += renderDecos(page);
    h += '</div>';
    return h;
  }

  function renderPage(page) {
    var map = {
      'cover': renderCover,
      'photo-caption': renderPhotoCaption,
      'two-photo': renderTwoPhoto,
      'story': renderStory,
      'cost-card': renderCostCard,
      'photos': renderPhotoGrid
    };
    var fn = map[page.template] || renderStory;
    return '<div class="sb-page">' + fn(page) + '</div>';
  }

  function loadCosts(pageId) {
    var el = document.getElementById('sbCostDisplay');
    if (!el) return;
    var url = 'storybook-api.php?action=get_cost&page_id=' + pageId;
    if (SHARE_TOKEN) url += '&token=' + encodeURIComponent(SHARE_TOKEN);
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || data.error || !data.has_data) { el.innerHTML = '<div style="text-align:center;padding:20px;color:var(--sb-text-dim)">No costs recorded</div>'; return; }
        var rows = [];
        var fields = [['Food',data.food_total],['Transport',data.transport_total],['Accommodation',data.accommodation_total],['Shopping',data.shopping_total],['Fees & Misc',data.fees_misc_total]];
        fields.forEach(function(f){ var v=parseFloat(f[1])||0; if(v>0) rows.push('<div class="sb-cost-row"><span class="sb-cost-label">'+esc(f[0])+'</span><span class="sb-cost-value">\u20B9'+v.toLocaleString('en-IN')+'</span></div>'); });
        var tot = parseFloat(data.true_total)||0;
        if (tot > 0) rows.push('<div class="sb-cost-row total"><span class="sb-cost-label">Total</span><span class="sb-cost-value">\u20B9'+tot.toLocaleString('en-IN')+'</span></div>');
        if (rows.length === 0) rows.push('<div style="text-align:center;padding:20px;color:var(--sb-text-dim)">No costs recorded</div>');
        el.innerHTML = rows.join('');
      })
      .catch(function(){ el.innerHTML = '<div style="text-align:center;padding:20px;color:var(--sb-text-dim)">Could not load costs</div>'; });
  }
  // running "spent so far" rollup across cost-card pages, shown while flipping through the book
  var costTotals = {};   // page_id -> rupee total
  function loadAllCosts() {
    pages.forEach(function(pg){
      if (pg.template !== 'cost-card' || !pg.cost_entry_id) return;
      var url = 'storybook-api.php?action=get_cost&page_id=' + pg.page_id + (SHARE_TOKEN ? '&token=' + encodeURIComponent(SHARE_TOKEN) : '');
      fetch(url).then(function(r){ return r.json(); }).then(function(d){
        if (d && d.has_data) { costTotals[pg.page_id] = parseFloat(d.true_total) || 0; updateRollup(); }
      }).catch(function(){});
    });
  }
  function updateRollup() {
    var el = document.getElementById('sbRollup');
    if (!el) return;
    var sum = 0, any = false;
    for (var i = 0; i <= currentIdx; i++) {
      var t = costTotals[pages[i] && pages[i].page_id];
      if (t) { sum += t; any = true; }
    }
    el.textContent = any ? 'Spent so far: ₹' + Math.round(sum).toLocaleString('en-IN') : '';
    el.style.display = any ? '' : 'none';
  }
  function showPage(idx) {
    var container = document.getElementById('sbPageContainer');
    if (!container || pages.length === 0) return;
    if (idx < 0) idx = 0;
    if (idx >= pages.length) idx = pages.length - 1;
    currentIdx = idx;
    var pg = pages[idx];
    container.innerHTML = renderPage(pg);
    document.getElementById('sbViewerCounter').textContent = 'Page ' + (idx + 1) + ' of ' + pages.length;
    document.getElementById('sbPageCounter').textContent = 'Page ' + (idx + 1) + ' of ' + pages.length;
    document.getElementById('sbPrevBtn').disabled = (idx === 0);
    document.getElementById('sbNextBtn').disabled = (idx === pages.length - 1);
    document.getElementById('sbPrevBtn').style.opacity = idx === 0 ? '0.4' : '1';
    document.getElementById('sbNextBtn').style.opacity = idx === pages.length - 1 ? '0.4' : '1';
    if (pg.template === 'cost-card' && pg.cost_entry_id) {
      setTimeout(function(){ loadCosts(pg.page_id); }, 0);
    }
    updateRollup();
  }

  function prevPage() {
    if (currentIdx > 0) showPage(currentIdx - 1);
  }

  function nextPage() {
    if (currentIdx < pages.length - 1) showPage(currentIdx + 1);
  }

  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); prevPage(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); nextPage(); }
  });

  var prevBtn = document.getElementById('sbPrevBtn');
  var nextBtn = document.getElementById('sbNextBtn');
  if (prevBtn) prevBtn.addEventListener('click', prevPage);
  if (nextBtn) nextBtn.addEventListener('click', nextPage);

  var printBtn = document.getElementById('sbPrintBtn');
  if (printBtn) printBtn.addEventListener('click', function(){ window.print(); });

  var shareBtn = document.getElementById('sbShareBtn');
  var shareOv = document.getElementById('sbShareOverlay');
  if (shareBtn && shareOv) {
    shareBtn.addEventListener('click', function() {
      shareOv.style.display = 'flex';
      var origin = window.location.origin + '/travel_journel/';
      var link = (BOOK_VISIBILITY === 'link' && SHARE_TOKEN)
        ? origin + 'storybook-view.php?token=' + SHARE_TOKEN
        : origin + 'storybook-view.php?id=' + BOOK_ID;
      document.getElementById('sbShareLink').value = link;
    });
    var shareClose = document.getElementById('sbShareCloseBtn');
    if (shareClose) shareClose.addEventListener('click', function(){ shareOv.style.display = 'none'; });
    shareOv.addEventListener('click', function(e){ if (e.target === shareOv) shareOv.style.display = 'none'; });
    var copyBtn = document.getElementById('sbShareCopyBtn');
    if (copyBtn) copyBtn.addEventListener('click', function(){
      var inp = document.getElementById('sbShareLink'); inp.select();
      document.execCommand('copy');
      this.textContent = 'Copied!'; var self = this;
      setTimeout(function(){ self.textContent = 'Copy'; }, 1500);
    });
  }

  if (IS_PUBLIC && LOGGED_IN) {
    var likeBtn = document.getElementById('sbLikeBtn');
    if (likeBtn) {
      likeBtn.addEventListener('click', function() {
        var fd = new FormData();
        fd.append('book_id', BOOK_ID);
        fetch('storybook-api.php?action=toggle_like', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (res.error) { alert(res.error); return; }
            var icon = document.getElementById('sbLikeIcon');
            var text = document.getElementById('sbLikeText');
            var count = document.getElementById('sbLikeCount');
            if (res.liked) { likeBtn.classList.add('liked'); icon.innerHTML = '&#x2764;&#xFE0F;'; text.textContent = 'Liked'; }
            else { likeBtn.classList.remove('liked'); icon.innerHTML = '&#x1F44D;'; text.textContent = 'Like'; }
            count.textContent = res.total_likes + ' like' + (res.total_likes !== 1 ? 's' : '');
          });
      });
    }

    var commentForm = document.getElementById('sbCommentForm');
    if (commentForm) {
      commentForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var input = document.getElementById('sbCommentInput');
        var body = input.value.trim();
        if (!body || pages.length === 0) return;
        var currentPage = pages[currentIdx];
        var fd = new FormData();
        fd.append('page_id', currentPage.page_id);
        fd.append('body', body);
        fetch('storybook-api.php?action=add_comment', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (res.error) { alert(res.error); return; }
            input.value = '';
            loadComments(currentPage.page_id);
          });
      });
    }
  }

  function loadComments(pageId) {
    var list = document.getElementById('sbCommentsList');
    if (!list) return;
    var url = 'storybook-api.php?action=get_comments&page_id=' + pageId;
    if (SHARE_TOKEN) url += '&token=' + encodeURIComponent(SHARE_TOKEN);
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.comments || res.comments.length === 0) {
          list.innerHTML = '<p style="font-size:.85rem;color:var(--sb-text-dim);padding:10px 0">No comments yet. Be the first!</p>';
          return;
        }
        var h = '';
        res.comments.forEach(function(c) {
          var initials = ((c.fname||'')[0]||'') + ((c.lname||'')[0]||'');
          var timeStr = '';
          if (c.created_at) {
            try { var d = new Date(c.created_at); timeStr = d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}); } catch(e) { timeStr = c.created_at; }
          }
          h += '<div class="sb-comment">';
          h += '<div class="sb-comment-avatar">' + esc(initials.toUpperCase()) + '</div>';
          h += '<div class="sb-comment-body">';
          h += '<div class="sb-comment-name">' + esc(c.fname) + ' ' + esc(c.lname) + '</div>';
          h += '<div class="sb-comment-text">' + esc(c.body) + '</div>';
          if (timeStr) h += '<div class="sb-comment-time">' + esc(timeStr) + '</div>';
          h += '</div></div>';
        });
        list.innerHTML = h;
      })
      .catch(function(){ list.innerHTML = '<p style="font-size:.85rem;color:var(--sb-text-dim);padding:10px 0">Could not load comments.</p>'; });
  }

  if (pages.length > 0) {
    showPage(0);
    loadAllCosts();
    if (IS_PUBLIC && pages.length > 0) {
      loadComments(pages[0].page_id);
    }
  }
})();
</script>
</body>
</html>
