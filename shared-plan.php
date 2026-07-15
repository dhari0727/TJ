<?php
/**
 * JourneyAI — public, read-only view of a saved plan via its share token.
 * No login/session check required. Looks up saved_plans by share_token.
 */
$ja_title = "Shared Plan"; $ja_active = "";
require_once 'ja-icons.php';
require 'connection.php';

$MONTHS = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

$token = trim($_GET['t'] ?? '');
$plan = null;
$packing = [];
if ($token) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_plans WHERE share_token=?");
    mysqli_stmt_bind_param($s, 's', $token);
    mysqli_stmt_execute($s);
    $plan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if ($plan) {
        $s = mysqli_prepare($conn, "SELECT * FROM packing_items WHERE plan_id=? ORDER BY sort_order ASC, item_id ASC");
        mysqli_stmt_bind_param($s, 'i', $plan['plan_id']);
        mysqli_stmt_execute($s);
        $packing = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
        mysqli_stmt_close($s);
    }
}
$it = $plan ? json_decode($plan['itinerary'], true) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css"></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('sparkle',14) ?> Shared itinerary</div>
    <h1><?= $plan ? htmlspecialchars($plan['destination']) : 'Shared Plan' ?></h1>
    <?php if ($plan): ?>
      <p class="sub"><?= (int)$plan['days'] ?>-day <?= htmlspecialchars($plan['travel_style']) ?>
        <?php if ($plan['month']): ?>· <?= $MONTHS[(int)$plan['month']] ?><?php endif; ?>
        · <?= (int)$plan['party_size'] ?> traveller<?= $plan['party_size']==1?'':'s' ?></p>
    <?php endif; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <?php if (!$plan || !$it || empty($it['plan'])): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="color:var(--ja-coral);margin-bottom:12px"><?= ja_icon('compass',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">This link is invalid or has expired.</p>
        <a href="index.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Go to JourneyAI</a>
      </div>
    <?php else: ?>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:8px" class="no-print">
        <button class="ja-btn ja-btn-ghost" onclick="window.print()"><?= ja_icon('book',15) ?> Print / Save as PDF</button>
      </div>

      <div class="ja-itin-layout">
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

          <?php if ($packing): ?>
          <div class="ja-card ja-pack-list" style="margin-top:20px">
            <h3><?= ja_icon('wallet',16) ?> Packing checklist</h3>
            <ul style="list-style:none;padding:0;margin:12px 0 0">
              <?php foreach ($packing as $p): ?>
                <li style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--brd)">
                  <input type="checkbox" disabled <?= $p['is_checked'] ? 'checked' : '' ?> style="width:17px;height:17px;flex:none">
                  <span style="flex:1;font-size:.9rem;<?= $p['is_checked'] ? 'text-decoration:line-through;color:var(--text-mut)' : '' ?>"><?= htmlspecialchars($p['label']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
        </aside>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
