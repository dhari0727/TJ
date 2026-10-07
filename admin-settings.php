<?php
$ja_title = "Settings"; $ja_active = "admin";
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-mailer.php';
require_once __DIR__ . '/ml_client.php';
$me = ja_require_admin();
$tab = ($_GET['tab'] ?? 'email') === 'site' ? 'site' : 'email';
$ja_tab = $tab;
$msg = ''; $err = ''; $testResult = null;
$geminiFile = __DIR__ . '/ml/bot/gemini_key.txt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ja_csrf_ok()) {
        $err = 'Session expired. Reload the page and try again.';
    } else {
        $form = $_POST['form'] ?? '';
        if ($form === 'email') {
            $host = trim($_POST['smtp_host'] ?? '');
            $port = (int)($_POST['smtp_port'] ?? 587);
            $enc  = in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
            $from = trim($_POST['smtp_from_email'] ?? '');
            if (!empty($_POST['smtp_enabled']) && ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL))) {
                $err = 'To enable email, enter a host and a valid "from" address.';
            } else {
                ja_setting_set('smtp_enabled', !empty($_POST['smtp_enabled']) ? '1' : '0');
                ja_setting_set('smtp_host', $host);
                ja_setting_set('smtp_port', (string)max(1, min(65535, $port)));
                ja_setting_set('smtp_encryption', $enc);
                ja_setting_set('smtp_user', trim($_POST['smtp_user'] ?? ''));
                if (($_POST['smtp_pass'] ?? '') !== '') ja_setting_set('smtp_pass', $_POST['smtp_pass'], true);  // blank = keep current
                ja_setting_set('smtp_from_email', $from);
                ja_setting_set('smtp_from_name', trim($_POST['smtp_from_name'] ?? '') ?: 'JourneyAI');
                $msg = 'Email settings saved.';
                if (!empty($_POST['send_test'])) {
                    $to = trim($_POST['test_to'] ?? '') ?: $me['eml'];
                    $testResult = ja_send_mail($to, 'JourneyAI test email', '<p>It works. Your SMTP settings are correct.</p>', 'It works. Your SMTP settings are correct.');
                    $testResult['to'] = $to;
                }
            }
        } elseif ($form === 'site') {
            ja_setting_set('site_name', trim($_POST['site_name'] ?? '') ?: 'JourneyAI');
            ja_setting_set('allow_registration', !empty($_POST['allow_registration']) ? '1' : '0');
            ja_setting_set('dev_show_reset_link', !empty($_POST['dev_show_reset_link']) ? '1' : '0');
            $url = trim($_POST['ml_service_url'] ?? '');
            if ($url !== '' && !preg_match('#^https?://[^\s]+$#', $url)) { $err = 'The service URL must start with http:// or https://'; }
            else {
                ja_setting_set('ml_service_url', $url ?: 'http://127.0.0.1:5000');
                $gk = trim($_POST['gemini_key'] ?? '');
                if ($gk !== '') {
                    if (!preg_match('/^[A-Za-z0-9._\-]{20,200}$/', $gk)) { $err = 'That Gemini key looks invalid.'; }
                    else { @file_put_contents($geminiFile, $gk); }
                }
                if (!empty($_POST['remove_gemini'])) { @unlink($geminiFile); }
                if (!$err) $msg = 'Site settings saved.';
            }
        }
    }
    // reload settings after writes
}
// re-read fresh values (the per-request cache in ja_settings_all is stale after a save)
function ja_fresh($k, $d = '') { global $conn; $r = mysqli_prepare($conn, "SELECT svalue, is_secret FROM settings WHERE skey = ?"); mysqli_stmt_bind_param($r, 's', $k); mysqli_stmt_execute($r); $row = mysqli_fetch_assoc(mysqli_stmt_get_result($r)); mysqli_stmt_close($r); if (!$row) return $d; return $row['is_secret'] ? ja_decrypt($row['svalue']) : ($row['svalue'] ?? $d); }
$hasPass = ja_fresh('smtp_pass') !== '';
$hasGemini = is_file($geminiFile) && trim((string)@file_get_contents($geminiFile)) !== '';
$recentMail = [];
$rm = mysqli_query($conn, "SELECT to_addr, subject, status, error, created_at FROM email_log ORDER BY id DESC LIMIT 8");
while ($rm && ($row = mysqli_fetch_assoc($rm))) $recentMail[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<?php include 'ja-admin-tabs.php'; ?>

<?php if ($msg): ?><div class="ja-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($tab === 'email'): ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 6px">Email (SMTP)</h1>
<p class="ja-muted" style="margin-bottom:18px">Used for password reset emails. For Gmail: host <code>smtp.gmail.com</code>, port <code>587</code>, encryption TLS, and use an <em>App password</em> (Google Account &rarr; Security &rarr; App passwords), not your normal password.</p>

<?php if ($testResult): ?>
  <div class="<?= $testResult['ok'] ? 'ja-ok' : 'ja-err' ?>"><?= $testResult['ok'] ? 'Test email sent to ' . htmlspecialchars($testResult['to']) . '. Check the inbox (and spam).' : 'Test failed: ' . htmlspecialchars($testResult['error']) ?></div>
<?php endif; ?>

<form method="post" class="ja-card ja-form-grid">
  <?= ja_csrf_field() ?><input type="hidden" name="form" value="email">
  <label class="ja-switch"><input type="checkbox" name="smtp_enabled" value="1" <?= ja_fresh('smtp_enabled') === '1' ? 'checked' : '' ?>> <span>Send emails from this site</span></label>
  <div class="ja-field"><label>SMTP host</label><input class="ja-input" name="smtp_host" value="<?= htmlspecialchars(ja_fresh('smtp_host', 'smtp.gmail.com')) ?>" placeholder="smtp.gmail.com"></div>
  <div class="ja-field"><label>Port</label><input class="ja-input" type="number" name="smtp_port" value="<?= htmlspecialchars(ja_fresh('smtp_port', '587')) ?>"></div>
  <div class="ja-field"><label>Encryption</label>
    <select class="ja-select" name="smtp_encryption">
      <?php foreach (['tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None (not recommended)'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= ja_fresh('smtp_encryption', 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="ja-field"><label>Username</label><input class="ja-input" name="smtp_user" autocomplete="off" value="<?= htmlspecialchars(ja_fresh('smtp_user')) ?>" placeholder="you@gmail.com"></div>
  <div class="ja-field"><label>Password <?= $hasPass ? '<span class="ja-muted">(saved. Leave blank to keep)</span>' : '' ?></label><input class="ja-input" type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= $hasPass ? '••••••••' : 'App password' ?>"></div>
  <div class="ja-field"><label>"From" address</label><input class="ja-input" type="email" name="smtp_from_email" value="<?= htmlspecialchars(ja_fresh('smtp_from_email')) ?>" placeholder="you@gmail.com"></div>
  <div class="ja-field"><label>"From" name</label><input class="ja-input" name="smtp_from_name" value="<?= htmlspecialchars(ja_fresh('smtp_from_name', 'JourneyAI')) ?>"></div>
  <div class="ja-field"><label>Send a test to</label><input class="ja-input" type="email" name="test_to" value="<?= htmlspecialchars($me['eml']) ?>"></div>
  <div class="ja-form-actions">
    <button class="ja-btn ja-btn-primary" type="submit">Save</button>
    <button class="ja-btn ja-btn-ghost" type="submit" name="send_test" value="1">Save &amp; send test email</button>
  </div>
</form>

<h3 style="margin:28px 0 10px">Recent emails</h3>
<div class="ja-card" style="padding:0;overflow-x:auto"><table class="ja-table ja-table-wide"><tbody>
  <?php foreach ($recentMail as $m): ?>
    <tr><td><?= htmlspecialchars($m['subject']) ?><br><span class="ja-muted"><?= htmlspecialchars($m['to_addr']) ?></span></td>
    <td><span class="ja-pill <?= $m['status'] === 'sent' ? 'near' : 'season' ?>"><?= htmlspecialchars(str_replace('_', ' ', $m['status'])) ?></span><?php if ($m['error']): ?><br><span class="ja-muted" style="font-size:.78rem"><?= htmlspecialchars($m['error']) ?></span><?php endif; ?></td>
    <td class="ja-muted"><?= htmlspecialchars($m['created_at']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$recentMail): ?><tr><td class="ja-muted" style="padding:22px">No emails yet.</td></tr><?php endif; ?>
</tbody></table></div>

<?php else: ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 18px">Site &amp; services</h1>
<form method="post" class="ja-card ja-form-grid">
  <?= ja_csrf_field() ?><input type="hidden" name="form" value="site">
  <div class="ja-field"><label>Site name</label><input class="ja-input" name="site_name" value="<?= htmlspecialchars(ja_fresh('site_name', 'JourneyAI')) ?>"></div>
  <label class="ja-switch"><input type="checkbox" name="allow_registration" value="1" <?= ja_fresh('allow_registration', '1') === '1' ? 'checked' : '' ?>> <span>Allow new sign-ups</span></label>
  <label class="ja-switch"><input type="checkbox" name="dev_show_reset_link" value="1" <?= ja_fresh('dev_show_reset_link') === '1' ? 'checked' : '' ?>> <span>Local testing: show the reset link on screen when email isn't set up (only works from this computer)</span></label>
  <div class="ja-field"><label>Recommendation service URL</label><input class="ja-input" name="ml_service_url" value="<?= htmlspecialchars(ja_fresh('ml_service_url', 'http://127.0.0.1:5000')) ?>"></div>
  <div class="ja-field"><label>Gemini API key (AI chat) <?= $hasGemini ? '<span class="ja-muted">(saved. Paste a new one to replace)</span>' : '' ?></label>
    <input class="ja-input" type="password" name="gemini_key" autocomplete="off" placeholder="<?= $hasGemini ? '••••••••' : 'Paste key from aistudio.google.com/app/apikey' ?>"></div>
  <?php if ($hasGemini): ?><label class="ja-switch"><input type="checkbox" name="remove_gemini" value="1"> <span>Remove the saved Gemini key</span></label><?php endif; ?>
  <div class="ja-form-actions"><button class="ja-btn ja-btn-primary" type="submit">Save</button></div>
</form>
<p class="ja-muted" style="margin-top:12px">The service status and other health checks are on the <a href="admin.php" style="color:var(--ja-teal)">Overview</a> page. Restart the recommendation service after changing the Gemini key.</p>
<?php endif; ?>
<?php include 'ja-footer.php'; ?>
