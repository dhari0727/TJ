<?php
/** Day-by-day itinerary body (embedded in trip.php). Expects: $it $dest $days $style $month $party $hero $hasError $ja_saved_flag $STYLES */
require_once __DIR__ . '/ja-trip-parts.php';
$tp_in = !empty($_SESSION['eml']);
?>
<?php if ($hasError || empty($it['plan'])):
  $tp_msg = (string)($it['__error'] ?? '');
  $tp_down = $tp_msg !== '' && (stripos($tp_msg, 'offline') !== false || stripos($tp_msg, 'Invalid response') !== false);
  $tp_place = explode(',', $dest)[0];
?>
  <main class="ja-main"><div class="ja-container tp-wrap">
    <div class="tp-state" role="alert">
      <?php if ($tp_down): ?>
        <h2>The planner is not answering</h2>
        <p>Your trip is fine. We could not reach the service that writes day plans. Try again in a moment.</p>
      <?php else: ?>
        <h2>We have no plan for <?= htmlspecialchars($tp_place) ?> yet</h2>
        <p><?= $tp_msg !== '' ? htmlspecialchars($tp_msg) . '. ' : '' ?>Try a nearby city, or check the spelling.</p>
      <?php endif; ?>
      <div class="tp-state-actions">
        <?php if ($tp_down): ?><a class="ja-btn ja-btn-primary" href="">Try again</a><?php endif; ?>
        <a class="ja-btn <?= $tp_down ? 'ja-btn-ghost' : 'ja-btn-primary' ?>" href="plan-trip.php">Plan a different trip</a>
      </div>
    </div>
  </div></main>
<?php else:
  $offTitle = $it['city'] . ', ' . (int)$it['days'] . ' days';
?>
<header class="tp-head">
  <div class="ja-container tp-head-in">
    <div class="tp-head-text">
      <p class="tp-region"><?= tp_icon('pin', 15) ?> <?= htmlspecialchars($it['region']) ?></p>
      <p class="tp-lede"><?= (int)$it['days'] ?>-day <?= htmlspecialchars($it['travel_style']) ?> plan<?php if (!empty($it['best_season'])): ?>. Best in <?= htmlspecialchars($it['best_season']) ?>.<?php endif; ?></p>
      <dl class="tp-stats">
        <div><dt>Estimated total for <?= (int)$it['party_size'] ?> <?= $it['party_size'] == 1 ? 'traveller' : 'travellers' ?></dt><dd>₹<?= number_format($it['total_cost']) ?></dd></div>
        <div><dt>Per day</dt><dd>₹<?= number_format($it['per_day_cost']) ?></dd></div>
        <div><dt>Highlights</dt><dd><?= count($it['highlights']) ?></dd></div>
      </dl>
      <div class="tp-actions" id="tripActions">
        <?php if ($tp_in): ?>
          <button type="submit" form="tpSaveForm" class="ja-btn ja-btn-primary" id="tpSave"><?= tp_icon('save') ?><span>Save trip</span></button>
        <?php else: ?>
          <a class="ja-btn ja-btn-primary" href="login.php"><?= tp_icon('save') ?><span>Log in to save</span></a>
        <?php endif; ?>
        <button type="button" class="ja-btn ja-btn-ghost" data-tp-share><?= tp_icon('share') ?><span>Share</span></button>
        <button type="button" class="ja-btn ja-btn-ghost" data-tp-print><?= tp_icon('print') ?><span>Print itinerary</span></button>
        <a class="ja-btn ja-btn-ghost" href="packing.php?<?= htmlspecialchars(http_build_query(['dest' => $dest, 'days' => $days])) ?>"><?= tp_icon('bag') ?><span>Packing list</span></a>
      </div>
      <?php if ($ja_saved_flag): ?><p class="tp-saved" role="status"><?= tp_icon('check', 16) ?> Saved to My Trips.</p><?php endif; ?>
    </div>
    <?php if (!empty($hero)): ?>
      <figure class="tp-photo"><img src="<?= htmlspecialchars($hero) ?>" alt="<?= htmlspecialchars($it['city']) ?>" width="560" height="380" loading="eager"></figure>
    <?php endif; ?>
  </div>
</header>

<?php if ($tp_in): ?>
<form method="post" action="save-itinerary.php" id="tpSaveForm" hidden>
  <input type="hidden" name="dest" value="<?= htmlspecialchars($dest) ?>">
  <input type="hidden" name="days" value="<?= (int)$days ?>">
  <input type="hidden" name="style" value="<?= htmlspecialchars($style) ?>">
  <input type="hidden" name="month" value="<?= (int)($month ?? 0) ?>">
  <input type="hidden" name="party" value="<?= (int)$party ?>">
</form>
<?php endif; ?>

<main class="ja-main" id="tripMain">
  <div class="ja-container tp-wrap">
    <div class="tp-layout">
      <div>
        <div class="tp-sectionbar">
          <h2>Day by day</h2>
          <button type="button" class="tp-linkbtn" id="tpToggleDays" data-state="open">Collapse all days</button>
        </div>
        <?= tp_days($it) ?>
      </div>

      <aside class="tp-side">
        <section class="tp-panel">
          <h2>Where the money goes</h2>
          <?= tp_cost_chart($it) ?>
        </section>
        <section class="tp-panel">
          <h2>Highlights</h2>
          <?= tp_highlights($it) ?>
        </section>
        <section class="tp-panel tp-noprint">
          <h2>Change the plan</h2>
          <form method="get" class="tp-rebuild">
            <input type="hidden" name="dest" value="<?= htmlspecialchars($dest) ?>"><input type="hidden" name="tab" value="itinerary">
            <label>Length<select name="days" class="ja-select"><?php for ($i = 2; $i <= 12; $i++): ?><option value="<?= $i ?>" <?= $i == $days ? 'selected' : '' ?>><?= $i ?> days</option><?php endfor; ?></select></label>
            <label>Style<select name="style" class="ja-select"><?php foreach ($STYLES as $s): ?><option value="<?= $s ?>" <?= $s == $style ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></label>
            <button class="ja-btn ja-btn-ghost" type="submit">Rebuild plan</button>
          </form>
        </section>
        <section class="tp-panel tp-noprint" id="tpOffline" hidden>
          <h2>Use it without a connection</h2>
          <p>Keep this plan on this device so you can open it on the road.</p>
          <button type="button" class="ja-btn ja-btn-ghost" id="tpOfflineBtn" data-title="<?= htmlspecialchars($offTitle) ?>" data-cost="<?= (int)$it['total_cost'] ?>" data-days="<?= (int)$it['days'] ?>"><?= tp_icon('download') ?><span>Save for offline</span></button>
          <p class="tp-offstate" id="tpOfflineState" role="status" hidden><?= tp_icon('wifi-off', 15) ?> Available offline</p>
        </section>
      </aside>
    </div>
  </div>
</main>

<!-- stays small and never leaves the page: the offline button stores exactly this -->
<script type="application/json" id="tripData"><?= json_encode($it, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="tp-bar-mobile tp-noprint" id="tpMobileBar" role="toolbar" aria-label="Trip actions">
  <?php if ($tp_in): ?><button type="submit" form="tpSaveForm"><?= tp_icon('save', 20) ?><span>Save</span></button>
  <?php else: ?><a href="login.php"><?= tp_icon('save', 20) ?><span>Save</span></a><?php endif; ?>
  <button type="button" data-tp-share><?= tp_icon('share', 20) ?><span>Share</span></button>
  <button type="button" data-tp-print><?= tp_icon('print', 20) ?><span>Print</span></button>
  <a href="packing.php?<?= htmlspecialchars(http_build_query(['dest' => $dest, 'days' => $days])) ?>"><?= tp_icon('bag', 20) ?><span>Packing</span></a>
</div>
<?php endif; ?>
