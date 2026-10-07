<?php
$ja_title = "Admin"; $ja_active = "admin"; $ja_tab = "overview";
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-mailer.php';
require_once __DIR__ . '/ml_client.php';
ja_require_admin();

function ja_count($sql) {
    global $conn;
    $r = @mysqli_query($conn, $sql);
    $row = $r ? mysqli_fetch_row($r) : null;
    return (int)($row[0] ?? 0);
}
$stats = [
    'Users'            => ja_count("SELECT COUNT(*) FROM signup WHERE eml NOT LIKE '%@seed.journeyai' AND eml NOT LIKE '%@real.journeyai' AND eml NOT LIKE '%@demo.journeyai'"),
    'New this week'    => ja_count("SELECT COUNT(*) FROM signup WHERE created_at > (NOW() - INTERVAL 7 DAY) AND eml NOT LIKE '%@seed.journeyai' AND eml NOT LIKE '%@real.journeyai' AND eml NOT LIKE '%@demo.journeyai'"),
    'Journal entries'  => ja_count("SELECT COUNT(*) FROM db WHERE eml NOT LIKE '%@seed.journeyai' AND eml NOT LIKE '%@real.journeyai' AND eml NOT LIKE '%@demo.journeyai'"),
    'Storybooks'       => ja_count("SELECT COUNT(*) FROM storybooks"),
    'Saved plans'      => ja_count("SELECT COUNT(*) FROM saved_plans"),
    'Saved routes'     => ja_count("SELECT COUNT(*) FROM saved_routes"),
    'Suspended'        => ja_count("SELECT COUNT(*) FROM signup WHERE is_active = 0"),
    'Emails failed (7d)' => ja_count("SELECT COUNT(*) FROM email_log WHERE status <> 'sent' AND created_at > (NOW() - INTERVAL 7 DAY)"),
];
$mlUp = ml_is_up();
$smtpOk = ja_smtp_configured();
$geminiKey = is_file(__DIR__ . '/ml/bot/gemini_key.txt') && trim((string)@file_get_contents(__DIR__ . '/ml/bot/gemini_key.txt')) !== '';

$recent = [];
$r = mysqli_query($conn, "SELECT fname, lname, eml, role, is_active, created_at FROM signup
                          WHERE eml NOT LIKE '%@seed.journeyai' AND eml NOT LIKE '%@real.journeyai' AND eml NOT LIKE '%@demo.journeyai'
                          ORDER BY created_at DESC LIMIT 6");
while ($r && ($row = mysqli_fetch_assoc($r))) $recent[] = $row;
$mails = [];
$r = mysqli_query($conn, "SELECT to_addr, subject, status, error, created_at FROM email_log ORDER BY id DESC LIMIT 6");
while ($r && ($row = mysqli_fetch_assoc($r))) $mails[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<?php include 'ja-admin-tabs.php'; ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 18px">Overview</h1>

<div class="ja-health">
  <div class="ja-hc <?= $mlUp ? 'ok' : 'bad' ?>"><strong>Recommendation service</strong><span><?= $mlUp ? 'Running' : 'Offline. Start ml/start_service.bat' ?></span></div>
  <div class="ja-hc <?= $smtpOk ? 'ok' : 'warn' ?>"><strong>Email (SMTP)</strong><span><?= $smtpOk ? 'Configured' : 'Not set up. Password reset emails cannot be sent' ?></span>
    <?php if (!$smtpOk): ?><a href="admin-settings.php?tab=email">Set up email &rarr;</a><?php endif; ?></div>
  <div class="ja-hc <?= $geminiKey ? 'ok' : 'warn' ?>"><strong>AI chat assistant</strong><span><?= $geminiKey ? 'Gemini key present' : 'No Gemini key. Chat falls back to basic answers' ?></span>
    <?php if (!$geminiKey): ?><a href="admin-settings.php?tab=site">Add key &rarr;</a><?php endif; ?></div>
</div>

<div class="ja-stats-grid">
  <?php foreach ($stats as $label => $n): ?>
    <div class="ja-card ja-stat"><div class="n"><?= number_format($n) ?></div><div class="l"><?= htmlspecialchars($label) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="ja-two-col">
  <div class="ja-card">
    <h3 style="margin-top:0">Newest users <a href="admin-users.php" class="ja-more">See all &rarr;</a></h3>
    <?php if (!$recent): ?><p class="ja-muted">No real users yet.</p><?php endif; ?>
    <table class="ja-table"><tbody>
      <?php foreach ($recent as $u): ?>
        <tr><td><strong><?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?></strong><br><span class="ja-muted"><?= htmlspecialchars($u['eml']) ?></span></td>
            <td><?= $u['role'] === 'admin' ? '<span class="ja-pill near">admin</span>' : '' ?><?= $u['is_active'] ? '' : '<span class="ja-pill season">suspended</span>' ?></td>
            <td class="ja-muted"><?= htmlspecialchars(substr((string)$u['created_at'], 0, 10)) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="ja-card">
    <h3 style="margin-top:0">Recent emails <a href="admin-settings.php?tab=email" class="ja-more">Settings &rarr;</a></h3>
    <?php if (!$mails): ?><p class="ja-muted">Nothing sent yet.</p><?php endif; ?>
    <table class="ja-table"><tbody>
      <?php foreach ($mails as $m): ?>
        <tr><td><?= htmlspecialchars($m['subject']) ?><br><span class="ja-muted"><?= htmlspecialchars($m['to_addr']) ?></span></td>
            <td><span class="ja-pill <?= $m['status'] === 'sent' ? 'near' : 'season' ?>"><?= htmlspecialchars(str_replace('_', ' ', $m['status'])) ?></span></td>
            <td class="ja-muted"><?= htmlspecialchars(substr((string)$m['created_at'], 5, 11)) ?></td></tr>
        <?php if ($m['error']): ?><tr><td colspan="3" class="ja-muted" style="font-size:.78rem"><?= htmlspecialchars($m['error']) ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php include 'ja-footer.php'; ?>
