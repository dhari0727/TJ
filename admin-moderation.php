<?php
$ja_css_extra = ['pages-account.css'];
$ja_title = "Moderation"; $ja_active = "admin"; $ja_tab = "moderation";
require_once __DIR__ . '/ja-trust.php';
require_once __DIR__ . '/ml_client.php';
$me = ja_require_admin();
$view = in_array($_GET['v'] ?? '', ['queue', 'reports', 'audit'], true) ? $_GET['v'] : 'queue';
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ja_csrf_ok()) { $err = 'Session expired. Reload the page and try again.'; }
    else {
        $kind = $_POST['kind'] ?? ''; $id = (int)($_POST['id'] ?? 0); $act = $_POST['act'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if ($act === 'approve') { ja_set_moderation($kind, $id, 'approved', '', $me['eml']); $msg = 'Approved.'; }
        elseif ($act === 'reject') { ja_set_moderation($kind, $id, 'rejected', $reason ?: 'Does not meet the community guidelines', $me['eml']); $msg = 'Rejected. The author can see the reason.'; }
        elseif ($act === 'hide') { ja_set_moderation($kind, $id, 'hidden', $reason ?: 'Hidden by a moderator', $me['eml']); $msg = 'Hidden.'; }
        elseif ($act === 'dismiss' || $act === 'uphold') {
            $new = $act === 'uphold' ? 'upheld' : 'dismissed';
            $s = mysqli_prepare($conn, "UPDATE reports SET status = ? WHERE kind = ? AND item_id = ? AND status = 'open'");
            mysqli_stmt_bind_param($s, 'ssi', $new, $kind, $id); mysqli_stmt_execute($s); mysqli_stmt_close($s);
            if ($act === 'uphold') ja_set_moderation($kind, $id, 'rejected', 'Removed after community reports', $me['eml']);
            else ja_set_moderation($kind, $id, 'approved', '', $me['eml']);
            $msg = $act === 'uphold' ? 'Reports upheld, item removed.' : 'Reports dismissed, item restored.';
        }
    }
}

function rows_q($sql) { global $conn; $r = mysqli_query($conn, $sql); return $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : []; }
$pendJ = rows_q("SELECT d.entry_id id, d.Title, d.City, d.eml, d.mod_reason, d.published_at, LEFT(d.Description, 260) txt, CONCAT_WS(' ', d.Title, d.Description) full FROM db d WHERE d.moderation_status='pending' ORDER BY d.entry_id DESC LIMIT 50");
$pendM = rows_q("SELECT m.media_id id, m.kind, m.filepath, m.caption, m.eml, m.mod_reason, m.created_at FROM media m WHERE m.moderation_status='pending' AND m.entry_id IS NULL ORDER BY m.media_id DESC LIMIT 60");
$pendB = rows_q("SELECT b.book_id id, b.title, b.eml, b.mod_reason FROM storybooks b WHERE b.moderation_status='pending' ORDER BY b.book_id DESC LIMIT 30");
$nPending = count($pendJ) + count($pendM) + count($pendB);
$reps = rows_q("SELECT r.kind, r.item_id, COUNT(*) n, GROUP_CONCAT(DISTINCT r.reason) reasons, MAX(r.detail) detail, MAX(r.created_at) last_at
                FROM reports r WHERE r.status='open' GROUP BY r.kind, r.item_id ORDER BY n DESC, last_at DESC LIMIT 60");
$audit = $view === 'audit' ? rows_q("SELECT actor, action, detail, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 150") : [];
function author_label($eml) { return htmlspecialchars(substr($eml, 0, strpos($eml, '@') ?: strlen($eml))) . '@…'; }
function mod_form($kind, $id, $acts) {
    $h = '<form method="post" class="ja-modact">' . ja_csrf_field() . '<input type="hidden" name="kind" value="' . htmlspecialchars($kind) . '"><input type="hidden" name="id" value="' . (int)$id . '">';
    $h .= '<input name="reason" placeholder="Reason (shown to the author)" maxlength="240">';
    foreach ($acts as $a => $label) $h .= '<button name="act" value="' . $a . '" class="ja-btn ja-btn-ghost">' . $label . '</button>';
    return $h . '</form>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<?php include 'ja-admin-tabs.php'; ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 6px">Moderation</h1>
<p class="ja-muted" style="margin:0 0 16px">New members' public posts wait here. Items reported by 3 different people are hidden automatically and appear under Reports.</p>
<div class="ja-tabs">
  <a href="?v=queue" class="<?= $view === 'queue' ? 'on' : '' ?>">Waiting for review (<?= $nPending ?>)</a>
  <a href="?v=reports" class="<?= $view === 'reports' ? 'on' : '' ?>">Reports (<?= count($reps) ?>)</a>
  <a href="?v=audit" class="<?= $view === 'audit' ? 'on' : '' ?>">Activity log</a>
</div>
<?php if ($msg): ?><div class="ja-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($view === 'queue'): ?>
  <?php if (!$nPending): ?><div class="ja-empty" style="padding:40px"><p style="margin:0">Nothing waiting. All caught up.</p></div><?php endif; ?>
  <?php foreach ($pendJ as $j): ?>
    <div class="ja-card ja-modcard"><div><span class="ja-vis pending">Journal</span> <strong><?= htmlspecialchars($j['Title']) ?></strong> <span class="ja-muted">in <?= htmlspecialchars($j['City']) ?> by <?= author_label($j['eml']) ?></span></div>
      <?php if ($j['mod_reason']): ?><div class="ja-warn" style="margin:8px 0"><?= htmlspecialchars($j['mod_reason']) ?></div><?php endif; ?>
      <?php $au = ml_authenticity($j['full'] ?? $j['txt'], $j['eml'], (int)$j['id']); if (empty($au['__error']) && isset($au['score'])): $sc = max(0, min(100, (int)$au['score'])); ?>
        <div class="adm-auth" role="group" aria-label="Authenticity check">
          <div class="adm-auth-top"><strong><?= $sc ?></strong><span>out of 100</span><em class="adm-lvl lvl-<?= htmlspecialchars(preg_replace('/[^a-z]/', '', strtolower($au['level']))) ?>"><?= htmlspecialchars($au['level']) ?></em></div>
          <div class="adm-meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $sc ?>" aria-label="Authenticity score"><i style="width:<?= $sc ?>%"></i></div>
          <?php if (!empty($au['reasons'])): ?><ul class="adm-reasons"><?php foreach ($au['reasons'] as $rs): ?><li><?= htmlspecialchars($rs) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <p class="adm-adv">Advisory only. It never rejects anything by itself.</p>
        </div>
      <?php endif; ?>
      <p class="ja-muted" style="margin:6px 0"><?= htmlspecialchars($j['txt']) ?>…</p>
      <a href="journal.php?id=<?= (int)$j['id'] ?>" target="_blank" class="ja-more" style="float:none">Open full journal</a>
      <?= mod_form('journal', $j['id'], ['approve' => 'Approve', 'reject' => 'Reject']) ?></div>
  <?php endforeach; ?>
  <?php if ($pendM): ?><div class="ja-post-grid" style="margin-top:14px">
    <?php foreach ($pendM as $m): ?><div class="ja-post"><div class="ja-post-media"><?php if ($m['kind'] === 'video'): ?><video src="<?= htmlspecialchars($m['filepath']) ?>" controls preload="metadata"></video><?php else: ?><img src="<?= htmlspecialchars($m['filepath']) ?>" alt=""><?php endif; ?></div>
      <div class="ja-post-body"><div class="ja-post-cap"><?= htmlspecialchars($m['caption'] ?: 'No caption') ?></div><div class="ja-muted" style="font-size:.76rem">by <?= author_label($m['eml']) ?></div>
      <?= mod_form('media', $m['id'], ['approve' => 'Approve', 'reject' => 'Reject']) ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <?php foreach ($pendB as $b): ?>
    <div class="ja-card ja-modcard"><div><span class="ja-vis pending">Storybook</span> <strong><?= htmlspecialchars($b['title']) ?></strong> <span class="ja-muted">by <?= author_label($b['eml']) ?></span></div>
      <a href="storybook-view.php?id=<?= (int)$b['id'] ?>" target="_blank" class="ja-more" style="float:none">Open</a>
      <?= mod_form('book', $b['id'], ['approve' => 'Approve', 'reject' => 'Reject']) ?></div>
  <?php endforeach; ?>

<?php elseif ($view === 'reports'): ?>
  <?php if (!$reps): ?><div class="ja-empty" style="padding:40px"><p style="margin:0">No open reports.</p></div><?php endif; ?>
  <?php foreach ($reps as $r): $link = $r['kind'] === 'journal' ? 'journal.php?id=' . (int)$r['item_id'] : ($r['kind'] === 'book' ? 'storybook-view.php?id=' . (int)$r['item_id'] : 'feed.php'); ?>
    <div class="ja-card ja-modcard"><div><span class="ja-vis hidden"><?= (int)$r['n'] ?> report<?= $r['n'] > 1 ? 's' : '' ?></span> <strong><?= htmlspecialchars($r['kind']) ?> #<?= (int)$r['item_id'] ?></strong>
      <span class="ja-muted">reasons: <?= htmlspecialchars($r['reasons']) ?></span></div>
      <?php if ($r['detail']): ?><p class="ja-muted" style="margin:6px 0">&ldquo;<?= htmlspecialchars($r['detail']) ?>&rdquo;</p><?php endif; ?>
      <a href="<?= $link ?>" target="_blank" class="ja-more" style="float:none">Open item</a>
      <?= mod_form($r['kind'], $r['item_id'], ['uphold' => 'Remove it', 'dismiss' => 'Dismiss reports']) ?></div>
  <?php endforeach; ?>

<?php else: ?>
  <div class="ja-card" style="padding:0;overflow-x:auto"><table class="ja-table ja-table-wide"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($audit as $a): ?><tr><td class="ja-muted"><?= htmlspecialchars($a['created_at']) ?></td><td><?= $a['actor'] ? author_label($a['actor']) : 'system' ?></td><td><?= htmlspecialchars($a['action']) ?></td><td class="ja-muted"><?= htmlspecialchars($a['detail']) ?></td><td class="ja-muted"><?= htmlspecialchars($a['ip']) ?></td></tr><?php endforeach; ?>
  <?php if (!$audit): ?><tr><td colspan="5" class="ja-muted" style="padding:24px">No activity yet.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif; ?>
<?php include 'ja-footer.php'; ?>
