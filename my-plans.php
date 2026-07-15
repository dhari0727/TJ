<?php
$ja_title = "My Plans"; $ja_active = "plans";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
require_once 'ja-icons.php';

$MONTHS = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

// view a single saved plan?
$viewId = (int)($_GET['view'] ?? 0);
$viewPlan = null;
$packing = [];
if ($viewId) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_plans WHERE plan_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $viewId, $eml);
    mysqli_stmt_execute($s);
    $viewPlan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if ($viewPlan) {
        $s = mysqli_prepare($conn, "SELECT * FROM packing_items WHERE plan_id=? ORDER BY sort_order ASC, item_id ASC");
        mysqli_stmt_bind_param($s, 'i', $viewId);
        mysqli_stmt_execute($s);
        $packing = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
        mysqli_stmt_close($s);
    }
}

$s = mysqli_prepare($conn, "SELECT plan_id, destination, days, travel_style, month, party_size, share_token, created_at FROM saved_plans WHERE eml=? ORDER BY plan_id DESC");
mysqli_stmt_bind_param($s, 's', $eml);
mysqli_stmt_execute($s);
$plans = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
mysqli_stmt_close($s);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css"></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('compass',14) ?> Your trips</div>
    <h1>My Plans</h1>
    <p class="sub"><?= count($plans) ?> saved plan<?= count($plans)===1?'':'s' ?>.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <?php if ($viewId && !$viewPlan): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <p style="font-size:1.1rem;color:var(--text-dim)">Plan not found.</p>
        <a href="my-plans.php" class="ja-btn ja-btn-primary" style="margin-top:14px">← All plans</a>
      </div>

    <?php elseif ($viewPlan): $it = json_decode($viewPlan['itinerary'], true); ?>
      <a href="my-plans.php" style="color:var(--ja-teal);font-weight:600">← All plans</a>

      <?php if (!empty($_GET['saved'])): ?>
        <div class="ja-card" style="margin:16px 0;border-color:var(--ja-teal);color:var(--ja-teal);padding:14px 20px;font-weight:600">
          <?= ja_icon('check',15) ?> Plan saved with a starter packing checklist.
        </div>
      <?php endif; ?>

      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-top:12px">
        <div>
          <h2 style="margin-bottom:4px"><?= htmlspecialchars($viewPlan['destination']) ?></h2>
          <p class="sub"><?= (int)$viewPlan['days'] ?>-day <?= htmlspecialchars($viewPlan['travel_style']) ?>
            <?php if ($viewPlan['month']): ?>· <?= $MONTHS[(int)$viewPlan['month']] ?><?php endif; ?>
            · <?= (int)$viewPlan['party_size'] ?> traveller<?= $viewPlan['party_size']==1?'':'s' ?>
            · saved <?= htmlspecialchars(date('M j, Y', strtotime($viewPlan['created_at']))) ?></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="ja-btn ja-btn-ghost" id="jaShareBtn" data-plan-id="<?= (int)$viewPlan['plan_id'] ?>"><?= ja_icon('sparkle',15) ?> Share</button>
          <button class="ja-btn ja-btn-ghost" onclick="window.print()"><?= ja_icon('book',15) ?> Print / Save as PDF</button>
          <button class="ja-btn ja-btn-ghost" style="color:var(--ja-coral)"
            onclick="if(confirm('Delete this plan?')){fetch('save-plan-delete.php',{method:'POST',body:new URLSearchParams({plan_id:<?= (int)$viewPlan['plan_id'] ?>})}).then(()=>location.href='my-plans.php');}">
            <?= ja_icon('trash',15) ?> Delete</button>
        </div>
      </div>

      <div id="jaShareBox" style="display:none;margin-top:14px" class="ja-card">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="text" class="ja-input" id="jaShareUrl" readonly style="flex:1;min-width:220px">
          <button class="ja-btn ja-btn-primary no-print" type="button" id="jaShareCopy">Copy link</button>
        </div>
        <p style="color:var(--text-mut);font-size:.85rem;margin-top:8px">Anyone with this link can view this plan — no login required.</p>
      </div>

      <?php if (!$it || (empty($it['plan']))): ?>
        <div class="ja-empty" style="margin-top:20px">This plan's itinerary snapshot is unavailable.</div>
      <?php else: ?>
      <div class="ja-itin-layout" style="margin-top:24px">
        <div class="ja-itin-days">
          <?php foreach ($it['plan'] as $d): ?>
            <section class="ja-itin-day reveal">
              <div class="ja-itin-daynum"><?= (int)$d['day'] ?></div>
              <div class="ja-itin-daybody">
                <div class="ja-itin-dayhead">
                  <h2><?= htmlspecialchars($d['title']) ?></h2>
                  <span class="ja-pill season"><?= ja_icon('wallet',12) ?> ~₹<?= number_format($d['est_cost']) ?></span>
                </div>
                <div class="ja-timeline">
                  <?php foreach ($d['timeline'] as $t): ?>
                    <div class="ja-tl-item">
                      <div class="ja-tl-dot"><?= ja_icon($t['icon'] ?? 'clock',14) ?></div>
                      <div class="ja-tl-content">
                        <div class="ja-tl-time"><?= htmlspecialchars($t['time']) ?></div>
                        <div class="ja-tl-text"><?= htmlspecialchars($t['text']) ?></div>
                        <?php if (!empty($t['meal'])): ?><div class="ja-tl-meal"><?= ja_icon('sparkle',11) ?> <?= htmlspecialchars($t['meal']) ?></div><?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="ja-itin-stay"><?= ja_icon('home',15) ?> Suggested stay: <strong><?= htmlspecialchars($d['stay']) ?></strong></div>
              </div>
            </section>
          <?php endforeach; ?>
        </div>

        <aside class="ja-itin-side">
          <?php if (!empty($it['cost_breakdown'])): ?>
          <div class="ja-card">
            <h3>Cost breakdown</h3>
            <div style="margin-top:10px;font-size:.9rem;color:var(--text-dim)">
              Range ₹<?= number_format($it['cost_low'] ?? 0) ?> – ₹<?= number_format($it['cost_high'] ?? 0) ?>
            </div>
            <ul style="list-style:none;padding:0;margin:12px 0 0">
              <?php $LBL=['food'=>'Food','transport'=>'Transport','accommodation'=>'Stay','shopping'=>'Shopping','fees_misc'=>'Fees & misc'];
              foreach ($it['cost_breakdown'] as $k=>$v): ?>
                <li style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--brd);font-size:.88rem">
                  <span><?= htmlspecialchars($LBL[$k] ?? $k) ?></span><strong>₹<?= number_format($v) ?></strong></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <?php if (!empty($it['highlights'])): ?>
          <div class="ja-card" style="margin-top:20px">
            <h3>Highlights</h3>
            <ul style="list-style:none;padding:0;margin:6px 0 0">
              <?php foreach ($it['highlights'] as $h): ?>
                <li style="padding:8px 0;border-bottom:1px solid var(--brd);display:flex;gap:9px;align-items:center;font-size:.92rem">
                  <span style="color:var(--ja-teal)"><?= ja_icon('map-pin',14) ?></span><?= htmlspecialchars($h) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <!-- Packing checklist -->
          <div class="ja-card ja-pack-list" style="margin-top:20px" id="jaPackCard" data-plan-id="<?= (int)$viewPlan['plan_id'] ?>">
            <h3><?= ja_icon('wallet',16) ?> Packing checklist</h3>
            <ul style="list-style:none;padding:0;margin:12px 0 0" id="jaPackList">
              <?php foreach ($packing as $p): ?>
                <li data-item-id="<?= (int)$p['item_id'] ?>" style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--brd)">
                  <input type="checkbox" class="ja-pack-check" <?= $p['is_checked'] ? 'checked' : '' ?> style="width:17px;height:17px;flex:none">
                  <span class="ja-pack-label" style="flex:1;font-size:.9rem;<?= $p['is_checked'] ? 'text-decoration:line-through;color:var(--text-mut)' : '' ?>"><?= htmlspecialchars($p['label']) ?></span>
                  <button type="button" class="ja-pack-del no-print" title="Remove" style="background:none;border:none;color:var(--text-mut);cursor:pointer;padding:2px 6px"><?= ja_icon('trash',14) ?></button>
                </li>
              <?php endforeach; ?>
            </ul>
            <form id="jaPackAddForm" class="no-print" style="display:flex;gap:8px;margin-top:14px">
              <input type="text" id="jaPackAddInput" class="ja-input" placeholder="Add an item…" style="flex:1;padding:10px 12px">
              <button class="ja-btn ja-btn-primary" style="padding:10px 16px" type="submit">Add</button>
            </form>
          </div>
        </aside>
      </div>
      <?php endif; ?>

    <?php elseif (!$plans): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="color:var(--ja-aqua);margin-bottom:12px"><?= ja_icon('compass',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">No saved plans yet.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Plan a trip</a>
      </div>
    <?php else: ?>
      <div class="ja-grid cols-3">
        <?php foreach ($plans as $pl): ?>
          <div class="ja-card" data-tilt>
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px">
              <h3 style="font-size:1.15rem;margin-bottom:2px"><?= htmlspecialchars($pl['destination']) ?></h3>
              <span class="ja-pill near"><?= (int)$pl['days'] ?>d</span>
            </div>
            <div style="color:var(--text-mut);font-size:.85rem;margin-bottom:14px">
              <?= htmlspecialchars(ucfirst($pl['travel_style'])) ?><?= $pl['month'] ? ' · '.$MONTHS[(int)$pl['month']] : '' ?>
              · <?= htmlspecialchars(date('M j', strtotime($pl['created_at']))) ?>
              <?php if ($pl['share_token']): ?> · <span style="color:var(--ja-teal)"><?= ja_icon('sparkle',11) ?> shared</span><?php endif; ?>
            </div>
            <div style="display:flex;gap:8px">
              <a href="my-plans.php?view=<?= (int)$pl['plan_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem">Open</a>
              <button class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem;color:var(--ja-coral)"
                onclick="if(confirm('Delete?')){fetch('save-plan-delete.php',{method:'POST',body:new URLSearchParams({plan_id:<?= (int)$pl['plan_id'] ?>})}).then(()=>location.reload());}">Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Share button
  var shareBtn = document.getElementById('jaShareBtn');
  if (shareBtn) {
    shareBtn.addEventListener('click', function () {
      fetch('plan-share.php', { method: 'POST', body: new URLSearchParams({ plan_id: shareBtn.dataset.planId }) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { alert(data.error || 'Could not create share link.'); return; }
          document.getElementById('jaShareBox').style.display = 'block';
          document.getElementById('jaShareUrl').value = data.url;
          document.getElementById('jaShareUrl').select();
        });
    });
  }
  var copyBtn = document.getElementById('jaShareCopy');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var input = document.getElementById('jaShareUrl');
      input.select();
      navigator.clipboard && navigator.clipboard.writeText(input.value);
      copyBtn.textContent = 'Copied!';
      setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 1500);
    });
  }

  // Packing checklist
  var packCard = document.getElementById('jaPackCard');
  if (packCard) {
    var planId = packCard.dataset.planId;
    var list = document.getElementById('jaPackList');

    list.addEventListener('change', function (e) {
      if (!e.target.classList.contains('ja-pack-check')) return;
      var li = e.target.closest('li');
      var itemId = li.dataset.itemId;
      var checked = e.target.checked;
      var label = li.querySelector('.ja-pack-label');
      label.style.textDecoration = checked ? 'line-through' : 'none';
      label.style.color = checked ? 'var(--text-mut)' : '';
      fetch('packing-toggle.php', { method: 'POST', body: new URLSearchParams({ item_id: itemId, checked: checked ? 1 : 0 }) });
    });

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.ja-pack-del');
      if (!btn) return;
      var li = btn.closest('li');
      var itemId = li.dataset.itemId;
      fetch('packing-delete.php', { method: 'POST', body: new URLSearchParams({ item_id: itemId }) })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (data.ok) li.remove(); });
    });

    var addForm = document.getElementById('jaPackAddForm');
    addForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = document.getElementById('jaPackAddInput');
      var label = input.value.trim();
      if (!label) return;
      fetch('packing-add.php', { method: 'POST', body: new URLSearchParams({ plan_id: planId, label: label }) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { alert(data.error || 'Could not add item.'); return; }
          var li = document.createElement('li');
          li.dataset.itemId = data.item_id;
          li.style.cssText = 'display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--brd)';
          li.innerHTML = '<input type="checkbox" class="ja-pack-check" style="width:17px;height:17px;flex:none">' +
            '<span class="ja-pack-label" style="flex:1;font-size:.9rem"></span>' +
            '<button type="button" class="ja-pack-del no-print" title="Remove" style="background:none;border:none;color:var(--text-mut);cursor:pointer;padding:2px 6px">'
            + '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>';
          li.querySelector('.ja-pack-label').textContent = data.label;
          list.appendChild(li);
          input.value = '';
        });
    });
  }
});
</script>

<?php include 'ja-footer.php'; ?>
</body>
</html>
