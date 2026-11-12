<?php
/**
 * JourneyAI — recommendation card renderer (shared by plan-trip, recommendations, dashboard).
 * ja_render_cards(array $recs): echoes the animated card grid.
 *
 * Images are bifurcated: a representative stock photo by default, but if a REAL
 * (non-seed) user journal exists for that destination, the card is tagged "User Entry".
 */
require_once __DIR__ . '/ja-icons.php';
require_once __DIR__ . '/ja-images.php';
require_once __DIR__ . '/ja-dest.php';

/** Representative image for a destination (local curated photo, graceful fallback). */
function ja_dest_image($destination) {
    return ja_image_for($destination);
}

/** Does a REAL (non-seed) user journal exist for this destination? (for "User Entry" tag) */
function ja_has_user_entry($destination) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (isset($GLOBALS['conn'])) {
            $res = mysqli_query($GLOBALS['conn'],
                "SELECT DISTINCT canonical_dest FROM journal_features jf
                 JOIN signup s ON s.eml = jf.eml
                 WHERE jf.eml NOT LIKE '%@seed.journeyai' AND jf.eml NOT LIKE '%@demo.journeyai' AND jf.eml NOT LIKE '%@real.journeyai' AND jf.canonical_dest IS NOT NULL");
            if ($res) while ($r = mysqli_fetch_assoc($res)) $cache[$r['canonical_dest']] = true;
        }
    }
    return isset($cache[$destination]);
}

/** Aggregate rating (avg + count) per destination, one query for the whole grid. */
function ja_dest_rating($destination) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (isset($GLOBALS['conn'])) {
            $res = mysqli_query($GLOBALS['conn'],
                "SELECT destination, AVG(rating) avg_r, COUNT(*) n FROM interactions
                 WHERE interaction_type='rating' AND rating IS NOT NULL
                 GROUP BY destination");
            if ($res) while ($r = mysqli_fetch_assoc($res)) {
                $cache[$r['destination']] = ['avg' => (float)$r['avg_r'], 'n' => (int)$r['n']];
            }
        }
    }
    return $cache[$destination] ?? null;
}



/** One recommendation card. $r is a /recommend item; $i its position. */
function ja_render_card($r, $i = 0, $signedIn = false) {
    $destRaw = $r['destination'];
    $dest   = htmlspecialchars($destRaw);
    $city   = htmlspecialchars(explode(',', $destRaw)[0]);
    $low    = (int)($r['cost_low'] ?? $r['low'] ?? 0);
    $high   = (int)($r['cost_high'] ?? $r['high'] ?? 0);
    $cost   = (int)($r['predicted_cost'] ?? 0);
    if ($cost <= 0 && $low > 0) $cost = (int)round(($low + max($low, $high)) / 2);   // never show a zero price when we have a range
    $days   = (int)($r['duration_days'] ?? 0);
    $score  = max(0, min(1, (float)($r['score'] ?? 0)));
    $fit    = (string)($r['budget_fit'] ?? 'within');
    $within = ($fit === 'within');
    $gem    = !empty($r['lesser_known']);
    $acts   = array_slice($r['top_activities'] ?? [], 0, 4);
    $expl   = htmlspecialchars($r['explanation'] ?? '');
    $about  = htmlspecialchars($r['description'] ?? '');
    $titles = $r['sample_journal_titles'] ?? [];
    $bd     = $r['cost_breakdown'] ?? [];
    $season = htmlspecialchars($r['best_season'] ?? '');
    $distKm = $r['distance_km'] ?? null; $driveH = $r['drive_hours'] ?? null;
    $distLbl = htmlspecialchars($r['distance_label'] ?? '');
    $img    = ja_dest_image($destRaw);
    $userEntry = ja_has_user_entry($destRaw);
    $rating = ja_dest_rating($destRaw);
    $guide  = htmlspecialchars(ja_dest_url($destRaw));
    $bdTotal = array_sum($bd);
    $segCls = ['food' => 's1', 'stay' => 's2', 'transport' => 's3', 'shopping' => 's4', 'misc' => 's5'];
    ?>
    <article class="dz-card">
      <a class="dz-photo" href="<?= $guide ?>" tabindex="-1" aria-hidden="true">
        <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.classList.add('nophoto');this.remove()"><?php else: ?><span class="dz-nophoto-mark"><?= $city ?></span><?php endif; ?>
        <?php if ($gem): ?><span class="dz-badge"><?= ja_icon('gem', 12) ?> Hidden gem</span><?php endif; ?>
      </a>
      <div class="dz-body">
        <h3 class="dz-name"><a href="<?= $guide ?>"><?= $city ?></a></h3>
        <p class="dz-meta">
          <?php if ($distKm): ?>About <?= (int)$distKm ?> km away<?= $driveH ? ', ' . htmlspecialchars((string)$driveH) . ' h by road' : ', fly or take a train' ?><?php elseif ($distLbl): ?><?= $distLbl ?><?php endif; ?>
          <?php if ($userEntry): ?><?= ($distKm || $distLbl) ? '. ' : '' ?>Travellers have written journals here<?php endif; ?>
        </p>

        <p class="dz-price"><strong><?= ja_inr($cost) ?></strong>
          <span><?= $days ? 'for ' . $days . ' day' . ($days == 1 ? '' : 's') : 'estimated' ?><?= $high > 0 ? ', usually ' . number_format($low) . ' to ' . number_format($high) : '' ?></span></p>
        <p class="dz-fit <?= $within ? 'ok' : 'stretch' ?>"><?= $within ? 'Fits your budget' : 'Stretches your budget' ?></p>

        <?php if ($season): ?><p class="dz-line"><span>Best time</span> <?= $season ?></p><?php endif; ?>
        <?php if ($acts): ?><p class="dz-line"><span>Known for</span> <?= htmlspecialchars(implode(', ', array_map('strtolower', $acts))) ?></p><?php endif; ?>
        <div class="dz-match" title="How well this fits what you asked for"><span class="dz-match-lab">Match</span>
          <div class="dz-meter" role="img" aria-label="Match <?= round($score * 100) ?> out of 100"><i style="width:<?= round($score * 100) ?>%"></i></div>
          <span class="dz-match-n"><?= round($score * 100) ?>%</span></div>

        <?php if ($bdTotal > 0): ?>
        <details class="dz-more">
          <summary>Where the money goes</summary>
          <div class="dz-split" role="img" aria-label="Cost split">
            <?php foreach ($bd as $k => $v): if ($v <= 0) continue; ?><i class="<?= $segCls[$k] ?? 's5' ?>" style="flex:<?= (int)$v ?>"></i><?php endforeach; ?>
          </div>
          <ul class="dz-legend">
            <?php foreach ($bd as $k => $v): if ($v <= 0) continue; ?>
              <li><i class="<?= $segCls[$k] ?? 's5' ?>"></i><?= htmlspecialchars(ucfirst($k)) ?> <b><?= ja_inr($v) ?></b></li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>

        <?php if ($expl): ?>
        <details class="dz-more">
          <summary>Why this destination</summary>
          <p><?= $expl ?></p>
          <?php if ($about): ?><p class="dz-about"><?= $about ?></p><?php endif; ?>
          <?php if ($titles): ?><p class="dz-evidence">From journals: <?= htmlspecialchars(implode('; ', $titles)) ?></p><?php endif; ?>
        </details>
        <?php endif; ?>

        <div class="dz-actions">
          <a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($destRaw) ?>&days=<?= $days ?: 5 ?>">Build itinerary</a>
          <?= ja_compare_btn($destRaw) ?>
          <?php if ($signedIn): ?>
          <form method="post" action="save-plan.php" class="dz-save">
            <input type="hidden" name="destination" value="<?= $dest ?>">
            <input type="hidden" name="predicted_cost" value="<?= $cost ?>">
            <button class="dz-iconbtn" type="submit" aria-label="Save <?= $city ?> to your plans" title="Save to your plans"><?= ja_icon('heart', 18) ?></button>
          </form>
          <?php endif; ?>
        </div>

        <div class="dz-rating ja-dest-rating" data-dest="<?= $dest ?>">
          <span class="ja-dest-rating-avg">
            <?php if ($rating): ?><b>Rated <?= number_format($rating['avg'], 1) ?> of 5</b> by <?= $rating['n'] ?> traveller<?= $rating['n'] == 1 ? '' : 's' ?>
            <?php else: ?>No ratings yet<?php endif; ?>
          </span>
          <?php if ($signedIn): ?>
          <span class="ja-star-widget" role="group" aria-label="Rate <?= $city ?>" data-dest="<?= $dest ?>">
            <?php for ($s = 1; $s <= 5; $s++): ?><button type="button" class="ja-star-pick" data-val="<?= $s ?>" aria-label="<?= $s ?> star<?= $s == 1 ? '' : 's' ?>">★</button><?php endfor; ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}

function ja_render_cards($recs) {
    ja_discover_assets();
    if (empty($recs)) {
        echo '<div class="dz-empty"><h3>No destinations matched yet</h3><p>Try a higher budget, a longer trip, or remove one interest. Fewer filters give the planner more places to rank.</p>'
           . '<a class="ja-btn ja-btn-ghost" href="plan-trip.php">Change your answers</a></div>';
        return;
    }
    $signedIn = !empty($_SESSION['eml']);
    echo '<div class="dz-grid" id="recoGrid">';
    foreach ($recs as $i => $r) ja_render_card($r, $i, $signedIn);
    echo '</div>';
}
