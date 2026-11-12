<?php
/**
 * JourneyAI — read one shared journal.
 *   journal.php?id=12                 public journals (anyone) or your own
 *   journal.php?id=12&token=XXXX      link-shared journals
 */
$ja_title = "Journal"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-media.php';
require_once 'ja-icons.php';
require_once 'ja-lib.php';
require_once 'ja-community-ui.php';
require_once 'ja-social.php';
$me = $_SESSION['eml'] ?? null;
$id = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$q = mysqli_prepare($conn, "SELECT j.*, s.fname, s.lname, d.visibility, d.share_token, d.moderation_status, d.mod_reason
        FROM journals j JOIN db d ON d.entry_id = j.entry_id LEFT JOIN signup s ON s.eml = j.eml WHERE j.entry_id = ?");
mysqli_stmt_bind_param($q, 'i', $id); mysqli_stmt_execute($q);
$e = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);

$owner = $e && $me && strcasecmp($e['eml'], $me) === 0;
$visible = $e && $e['moderation_status'] === 'approved';   // pending / hidden / rejected journals are visible to their owner only
$allowed = $e && ($owner || ($visible && ($e['visibility'] === 'public' || ($e['visibility'] === 'link' && $token !== '' && hash_equals((string)$e['share_token'], $token)))));
if (!$allowed) { http_response_code(404); }
$media = $allowed ? ja_entry_media($conn, $id) : [];
if ($allowed && !$owner) $media = array_values(array_filter($media, function ($m) use ($e) { return ((int)$m['is_public'] === 1 || $e['visibility'] === 'link') && ($m['moderation_status'] ?? 'approved') === 'approved'; }));
$place = $allowed ? trim(($e['City'] ?? '') . ', ' . ($e['Country'] ?? ''), ', ') : '';
$authorHandle = $allowed ? ja_ensure_handle($e['eml']) : null;
$likes = 0; $iLiked = false;
if ($allowed) {
    $likes = (int)mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM journal_likes WHERE entry_id = ' . $id))[0];
    if ($me) { $lq = mysqli_prepare($conn, 'SELECT 1 FROM journal_likes WHERE entry_id = ? AND eml = ?'); mysqli_stmt_bind_param($lq, 'is', $id, $me); mysqli_stmt_execute($lq); $iLiked = (bool)mysqli_fetch_row(mysqli_stmt_get_result($lq)); mysqli_stmt_close($lq); }
}
$author = $allowed ? trim(($e['fname'] ?? 'A traveller') . ' ' . mb_substr((string)($e['lname'] ?? ''), 0, 1) . '.') : '';

$story = $allowed ? pc_story($e['Description']) : ['html' => '', 'toc' => [], 'words' => 0];
$readMin = pc_read_min($story['words']);
$places = [];
if ($allowed) {
    $country = trim((string)($e['Country'] ?? ''));
    if (!empty($e['City'])) $places[] = trim($e['City'] . ($country !== '' ? ', ' . $country : ''));
    foreach (preg_split('/[,;\n]+/u', (string)($e['ptv'] ?? '')) as $pl) {
        $pl = trim($pl);
        if ($pl === '' || mb_strlen($pl) > 60) continue;
        $full = $pl . ($country !== '' ? ', ' . $country : '');
        if (!in_array($full, $places, true)) $places[] = $full;
    }
    $places = array_slice($places, 0, 12);
}
$photos = array_values(array_filter($media, function ($m) { return $m['kind'] !== 'video'; }));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<?php if ($allowed && $e['visibility'] === 'public' && $e['moderation_status'] === 'approved') { $ogItem = ja_social_item('journal', $id, null); if ($ogItem) ja_social_og(['title' => $ogItem['title'], 'description' => $ogItem['description'], 'image' => $ogItem['og_image'], 'image_alt' => $ogItem['images'][0]['alt'] ?? '', 'url' => 'journal.php?id=' . $id, 'type' => 'article']); } ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>"></head>
<body class="ja">
<?php if (!$allowed): ?>
<main class="ja-main pr-page"><div class="ja-container">
  <div class="pr-unavailable"><?= pc_empty("This journal isn't available", 'It may be private, or the link is wrong. Public journals from other travellers are a good place to start.', ['Read other journals', 'read-journals.php'], 'book') ?></div>
</div></main>
<?php else: ?>
<div class="pr-progress" aria-hidden="true"><i id="prBar"></i></div>
<main class="ja-main pr-page" id="prRoot" data-id="<?= (int)$id ?>" data-token="<?= htmlspecialchars($token) ?>" data-me="<?= $me ? '1' : '0' ?>"><div class="ja-container">

  <header class="pr-top">
    <a class="pr-back" href="read-journals.php"><?= pc_icon('left', 16) ?> All journals</a>
    <?php if ($owner && $e['moderation_status'] !== 'approved'): ?>
      <div class="pr-note"><strong><?= ['pending' => 'Waiting for review', 'hidden' => 'Hidden after reports', 'rejected' => 'Not approved'][$e['moderation_status']] ?? 'Under review' ?>.</strong>
        Only you can see this right now.<?= $e['mod_reason'] ? ' ' . htmlspecialchars($e['mod_reason']) : '' ?></div>
    <?php endif; ?>
    <?php if ($owner && $e['visibility'] === 'private'): ?><div class="pr-note">Only you can see this. <a class="pc-link" href="my-entries.php">Share it from My journal</a>.</div><?php endif; ?>
    <h1 class="pr-title"><?= htmlspecialchars(ja_title($e['Title'])) ?></h1>
    <div class="pr-meta">
      <?php if ($place): ?><span><?= ja_icon('map-pin', 16) ?> <?= htmlspecialchars($place) ?></span><?php endif; ?>
      <?php if ((int)$e['duration_days'] > 0): ?><span><?= ja_icon('calendar', 16) ?> <?= (int)$e['duration_days'] ?> days<?= $e['dv'] ? ', ' . htmlspecialchars(date('M Y', strtotime($e['dv']))) : '' ?></span><?php endif; ?>
      <span id="prReadTime"><?= ja_icon('clock', 16) ?> <?= $readMin ?> min read</span>
      <span><?= ja_icon('user', 16) ?> <?php if ($authorHandle): ?><a href="traveller.php?h=<?= urlencode($authorHandle) ?>"><?= htmlspecialchars($author) ?></a><?php else: ?><?= htmlspecialchars($author) ?><?php endif; ?></span>
    </div>
  </header>

  <?php if ($media): ?>
  <div class="pr-gallery <?= count($media) >= 5 ? 'lead' : 'n' . count($media) ?>" id="prGallery">
    <?php $pi = 0; foreach ($media as $m): ?>
      <?php if ($m['kind'] === 'video'): ?>
        <figure class="pr-shot vid"><video src="<?= htmlspecialchars($m['filepath']) ?>" controls preload="metadata"></video><?php if ($m['caption']): ?><figcaption><?= htmlspecialchars($m['caption']) ?></figcaption><?php endif; ?></figure>
      <?php else: $alt = trim((string)$m['caption']) !== '' ? $m['caption'] : 'Photo ' . ($pi + 1) . ' from ' . ja_title($e['Title']); ?>
        <button type="button" class="pr-shot" data-lb="<?= $pi ?>" data-src="<?= htmlspecialchars($m['filepath']) ?>" data-cap="<?= htmlspecialchars((string)$m['caption']) ?>" aria-label="Open photo <?= $pi + 1 ?> of <?= count($photos) ?>">
          <img src="<?= htmlspecialchars($m['filepath']) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy" decoding="async">
          <?php if ($m['caption']): ?><figcaption><?= htmlspecialchars($m['caption']) ?></figcaption><?php endif; ?>
        </button>
      <?php $pi++; endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pr-layout<?= count($story['toc']) >= 2 ? ' has-toc' : '' ?>">
    <?php if (count($story['toc']) >= 2): ?>
    <nav class="pr-toc" aria-label="In this journal"><details id="prToc"><summary>In this journal</summary>
      <ol><?php foreach ($story['toc'] as [$tid, $tl]): ?><li><a href="#<?= $tid ?>"><?= htmlspecialchars($tl) ?></a></li><?php endforeach; ?></ol></details></nav>
    <?php endif; ?>

    <article>
      <div class="pr-story" id="prStory">
        <?= $story['html'] !== '' ? $story['html'] : '<p>No story written yet.</p>' ?>
      </div>
      <?php if (!empty($e['hn'])): ?><dl class="pr-facts"><div><dt>Stayed at</dt><dd><?= htmlspecialchars($e['hn']) ?></dd></div></dl><?php endif; ?>

      <?php if ($places): ?>
      <section class="pr-section" aria-labelledby="prPlacesH">
        <h3 id="prPlacesH">Places mentioned</h3>
        <div class="pr-places"><?php foreach ($places as $pl): ?><a href="trip.php?dest=<?= urlencode($pl) ?>&amp;days=<?= max(1, min(7, (int)$e['duration_days'])) ?>"><?= ja_icon('map-pin', 15) ?> <?= htmlspecialchars(explode(',', $pl)[0]) ?></a><?php endforeach; ?></div>
      </section>
      <?php endif; ?>

      <?php $total = (float)$e['true_total']; if ($total > 0): ?>
      <section class="pr-section" aria-labelledby="prCostH">
        <h3 id="prCostH">What this trip cost</h3>
        <div class="pr-cost">
          <div class="big">&#8377;<?= number_format($total) ?> <small>total, about &#8377;<?= number_format($total / max(1, (int)$e['duration_days'])) ?> a day</small></div>
          <div class="pr-bars">
          <?php foreach (['Food' => 'food_total', 'Transport' => 'transport_total', 'Stay' => 'accommodation_total', 'Shopping' => 'shopping_total', 'Fees and misc' => 'fees_misc_total'] as $lbl => $k):
              $v = (float)$e[$k]; if ($v <= 0) continue; $pct = round($v / $total * 100); ?>
            <div class="pr-bar"><span><?= $lbl ?></span><div><i style="width:<?= $pct ?>%"></i></div><span>&#8377;<?= number_format($v) ?></span></div>
          <?php endforeach; ?></div>
        </div>
      </section>
      <?php endif; ?>

      <section class="pr-section pr-engage" aria-labelledby="prTalkH">
        <h3 id="prTalkH">Comments</h3>
        <div class="pc-actions" style="margin-bottom:12px">
          <button type="button" class="pc-act" id="prLike" aria-pressed="<?= $iLiked ? 'true' : 'false' ?>" aria-label="Like this journal"><?= ja_icon('heart', 17) ?><span class="n" id="prLikeN"><?= (int)$likes ?></span></button>
        </div>
        <div class="pc-cmts" style="border-top:0;padding-top:0">
          <div class="pc-cmts-list" id="prCmtList" style="max-height:none"></div>
          <?php if ($me): ?><form class="pc-cmts-form" id="prCmtForm"><label class="pc-sr" for="prCmtInput">Write a comment</label><input id="prCmtInput" type="text" maxlength="600" placeholder="Write a comment" autocomplete="off"><button type="submit">Post</button></form>
          <?php else: ?><a href="login.php" class="pc-link">Log in to like and comment</a><?php endif; ?>
        </div>
        <?php if ($me && !$owner): ?>
        <details class="ja-report"><summary>Report this journal</summary>
          <form id="prReport"><select name="reason" aria-label="Reason"><option value="fake">Looks fake or made up</option><option value="spam">Spam or advertising</option><option value="unsafe">Unsafe or offensive</option><option value="copyright">Copied from somewhere else</option><option value="other">Something else</option></select>
          <input name="detail" maxlength="300" placeholder="Optional detail" aria-label="Detail"><button type="submit">Send report</button><span id="prReportMsg" class="pc-muted" role="status"></span></form></details>
        <?php endif; ?>
      </section>

      <div class="pr-cta">
        <a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($place) ?>&amp;days=<?= max(1, (int)$e['duration_days']) ?>"><?= ja_icon('compass', 16) ?> Plan a similar trip to <?= htmlspecialchars(explode(',', $place)[0]) ?></a>
        <?= ja_social_button('journal', $id) ?>
      </div>
    </article>
  </div>
</div></main>

<div class="pc-lb" id="prLb" role="dialog" aria-modal="true" aria-label="Photo viewer">
  <button type="button" class="pc-lb-close" data-close aria-label="Close viewer"><?= pc_icon('close', 20) ?></button>
  <button type="button" class="pc-lb-nav prev" data-nav="-1" aria-label="Previous photo"><?= pc_icon('left', 22) ?></button>
  <figure class="pc-lb-stage"><img id="prLbImg" alt=""><figcaption id="prLbCap"></figcaption></figure>
  <button type="button" class="pc-lb-nav next" data-nav="1" aria-label="Next photo"><?= pc_icon('right', 22) ?></button>
  <div class="pc-lb-count" id="prLbCount" aria-live="polite"></div>
</div>
<script>window.PC_READ = { csrf: <?= json_encode(ja_csrf()) ?> };</script>
<script src="js/reader.js?v=<?= (int)@filemtime(__DIR__ . '/js/reader.js') ?>" defer></script>
<?php endif; ?>
<?php include 'ja-footer.php'; ?>
