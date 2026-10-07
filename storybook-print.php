<?php
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }

$em = $_SESSION['eml'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: my-storybooks.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE book_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$book) { header('Location: my-storybooks.php'); exit; }

$isOwner = ($book['eml'] === $em);
$isPublic = ($book['visibility'] === 'public');
$hasToken = !empty($_GET['token']) && $_GET['token'] === ($book['share_token'] ?? '');
if (!$isOwner && !$isPublic && !$hasToken) { header('Location: my-storybooks.php'); exit; }

$pageOrderSql = ($book['page_order'] === 'chronological')
    ? "ORDER BY page_date IS NULL, page_date ASC, sort_order ASC"
    : "ORDER BY sort_order ASC";
$stmt = mysqli_prepare($conn, "SELECT * FROM storybook_pages WHERE book_id = ? $pageOrderSql");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$pages = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$theme = htmlspecialchars($book['theme']);
$bookTitle = htmlspecialchars($book['title']);

// Cost-card pages link to db.entry_id via cost_entry_id; read the aggregated
// per-category totals from the `journals` view (same source import_entry
// reads from) rather than guessing at raw db1/db2/db3 columns by title text.
$costData = [];
foreach ($pages as $pg) {
    if ($pg['template'] === 'cost-card' && !empty($pg['cost_entry_id'])) {
        $eid = (int)$pg['cost_entry_id'];
        $cs = mysqli_prepare($conn, "SELECT entry_id, duration_days, food_total, transport_total,
                                             accommodation_total, shopping_total, fees_misc_total, true_total
                                      FROM journals WHERE entry_id = ?");
        mysqli_stmt_bind_param($cs, 'i', $eid);
        mysqli_stmt_execute($cs);
        $crow = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
        mysqli_stmt_close($cs);
        if ($crow) { $costData[$pg['page_id']] = $crow; }
    }
}

$tplMap = [
    'cover'         => 'sb-tpl-cover',
    'photo-caption' => 'sb-tpl-photo-caption',
    'two-photo'     => 'sb-tpl-two-photo',
    'story'         => 'sb-tpl-story',
    'cost-card'     => 'sb-tpl-cost-card',
    'photos'        => 'sb-tpl-photo-grid',
];

function sb_esc($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function sb_img($src) {
    if (empty($src)) return '';
    return '<img class="sb-slot-img" src="' . sb_esc($src) . '">';
}
function sb_slot($src, $label = 'Photo') {
    if (!empty($src)) {
        return '<div class="sb-slot filled">' . sb_img($src) . '</div>';
    }
    return '<div class="sb-slot"><div class="sb-slot-empty-hint">' . sb_esc($label) . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $bookTitle ?> — Print — JourneyAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Serif+Display&family=Lora:wght@400;600&family=Caveat:wght@400;600&family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/storybook.css">
<style>
.no-print {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  background: var(--sb-surface);
  border-bottom: 1px solid var(--sb-border);
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  font-family: var(--sb-font-body);
}
.no-print-title {
  font-family: var(--sb-font-display);
  font-weight: 700;
  font-size: 1rem;
  color: var(--sb-text);
}
.no-print-hint {
  font-size: 0.85rem;
  color: var(--sb-text-dim);
}
.no-print-close {
  padding: 6px 14px;
  font-size: 0.82rem;
  font-weight: 600;
  border: 1px solid var(--sb-border);
  border-radius: 6px;
  background: var(--sb-surface);
  color: var(--sb-text);
  cursor: pointer;
  font-family: var(--sb-font-body);
}
.no-print-close:hover {
  background: var(--sb-slot-bg);
  border-color: var(--sb-accent);
}
.no-print-footer {
  text-align: center;
  padding: 30px 20px;
  font-family: var(--sb-font-body);
  font-size: 0.82rem;
  color: var(--sb-text-dim);
  border-top: 1px solid var(--sb-border);
  margin-top: 40px;
}
.sb-canvas-print {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60px 20px 40px;
  gap: 0;
}
.sb-canvas-print .sb-page {
  margin-bottom: 20px;
}
</style>
</head>
<body>
<div class="sb-shell" data-sb-theme="<?= $theme ?>">

<div class="no-print">
  <span class="no-print-title"><?= $bookTitle ?></span>
  <span class="no-print-hint">Press Ctrl+P (or Cmd+P) — choose "Save as PDF" to download it looking exactly like this</span>
  <button class="no-print-close" type="button" onclick="history.back();">Close</button>
</div>

<div class="sb-canvas-print">

<?php foreach ($pages as $pg): ?>
<?php
    $tpl      = $pg['template'] ?? 'story';
    $tplClass = $tplMap[$tpl] ?? 'sb-tpl-story';
    $title    = $pg['title'] ?? '';
    $body     = $pg['body_text'] ?? '';
    $date     = $pg['page_date'] ?? '';
    $photo1   = $pg['photo_1'] ?? '';
    $photo2   = $pg['photo_2'] ?? '';
    $photo3   = $pg['photo_3'] ?? '';
    $photo4   = $pg['photo_4'] ?? '';
    $cap1     = $pg['photo_1_cap'] ?? '';
    $cost     = $costData[$pg['page_id']] ?? null;
?>

<div class="sb-page <?= sb_esc($tplClass) ?>">
<div class="sb-page-inner">

<?php if ($tpl === 'cover'): ?>
  <div class="sb-slot hero filled">
    <?php if (!empty($photo1)): ?>
      <?= sb_img($photo1) ?>
    <?php else: ?>
      <div class="sb-slot-empty-hint">Cover Photo</div>
    <?php endif; ?>
  </div>
  <div class="sb-page-title sb-editable"><?= sb_esc($title) ?></div>
  <div class="sb-page-subtitle sb-editable"><?= sb_esc($date) ?></div>
  <div class="sb-hand-note sb-editable"><?= sb_esc($body) ?></div>

<?php elseif ($tpl === 'photo-caption'): ?>
  <div class="sb-slot main-photo filled">
    <?php if (!empty($photo1)): ?>
      <?= sb_img($photo1) ?>
    <?php else: ?>
      <div class="sb-slot-empty-hint">Photo</div>
    <?php endif; ?>
  </div>
  <div>
    <div class="sb-page-title sb-editable" style="margin-bottom:6px"><?= sb_esc($title) ?></div>
    <div class="sb-page-body sb-editable"><?= sb_esc($body) ?></div>
  </div>

<?php elseif ($tpl === 'two-photo'): ?>
  <div class="sb-photo-row">
    <?= sb_slot($photo1, 'Photo 1') ?>
    <?= sb_slot($photo2, 'Photo 2') ?>
  </div>
  <div>
    <div class="sb-page-title sb-editable" style="margin-bottom:6px"><?= sb_esc($title) ?></div>
    <div class="sb-page-body sb-editable"><?= sb_esc($body) ?></div>
  </div>

<?php elseif ($tpl === 'story'): ?>
  <div class="sb-page-title sb-editable"><?= sb_esc($title) ?></div>
  <div class="sb-page-subtitle sb-editable"><?= sb_esc($date) ?></div>
  <div class="sb-page-body sb-editable"><?= sb_esc($body) ?></div>

<?php elseif ($tpl === 'cost-card'): ?>
  <div class="sb-cost-title sb-editable"><?= sb_esc($title ?: 'Cost Breakdown') ?></div>
  <div class="sb-cost-rows">
    <?php if ($cost): ?>
      <?php
        $labels = ['Food','Transport','Accommodation','Shopping','Fees & Misc'];
        $keys   = ['food_total','transport_total','accommodation_total','shopping_total','fees_misc_total'];
      ?>
      <?php foreach ($keys as $ki => $k): ?>
        <?php $val = (float)($cost[$k] ?? 0); if ($val <= 0) continue; ?>
        <div class="sb-cost-row">
          <span class="sb-cost-label"><?= $labels[$ki] ?></span>
          <span class="sb-cost-value">&#8377;<?= number_format($val, 0) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="sb-cost-row total">
        <span class="sb-cost-label">Total</span>
        <span class="sb-cost-value">&#8377;<?= number_format((float)($cost['true_total'] ?? 0), 0) ?></span>
      </div>
    <?php else: ?>
      <div style="text-align:center;color:var(--sb-text-dim);padding:20px 0;font-size:.85rem">No cost data linked to this page</div>
    <?php endif; ?>
  </div>
  <?php if (!empty($body)): ?>
  <div class="sb-page-body sb-editable" style="font-size:.82rem;color:var(--sb-text-dim)"><?= sb_esc($body) ?></div>
  <?php endif; ?>

<?php elseif ($tpl === 'photos'): ?>
  <div class="sb-grid-2x2">
    <?= sb_slot($photo1, 'Photo 1') ?>
    <?= sb_slot($photo2, 'Photo 2') ?>
    <?= sb_slot($photo3, 'Photo 3') ?>
    <?= sb_slot($photo4, 'Photo 4') ?>
  </div>
  <?php if (!empty($title)): ?>
  <div class="sb-page-title sb-editable" style="font-size:1.2rem"><?= sb_esc($title) ?></div>
  <?php endif; ?>
  <?php if (!empty($cap1)): ?>
  <div class="sb-page-body sb-editable" style="font-size:.82rem;white-space:pre-wrap"><?= sb_esc($cap1) ?></div>
  <?php endif; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php if (empty($pages)): ?>
<div class="sb-my-empty" style="min-height:300px">
  <div class="sb-my-empty-icon">&#x1F4D6;</div>
  <div class="sb-my-empty-text">This storybook has no pages yet.</div>
</div>
<?php endif; ?>

<div class="no-print-footer">
  Printed from JourneyAI
</div>

</div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 800);
};
</script>
</body>
</html>
