<?php
$ja_css_extra = ['pages-account.css'];
$ja_title = "Settings"; $ja_active = "admin";
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-mailer.php';
require_once __DIR__ . '/ml_client.php';
require_once __DIR__ . '/ja-social-core.php';
$me = ja_require_admin();
$tab = in_array($_GET['tab'] ?? 'email', ['site', 'social'], true) ? $_GET['tab'] : 'email';
$SOCIAL_APPS = ['instagram' => 'Instagram (Instagram API with Instagram Login)', 'facebook' => 'Facebook Page (Meta app)', 'x' => 'X', 'linkedin' => 'LinkedIn', 'pinterest' => 'Pinterest', 'threads' => 'Threads (separate Meta app)'];
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
        } elseif ($form === 'social') {
            $bad = '';
            foreach (array_keys($SOCIAL_APPS) as $pk) {
                if (!empty($_POST['remove_' . $pk])) { ja_setting_set("social_{$pk}_client_id", '', true); ja_setting_set("social_{$pk}_client_secret", '', true); continue; }
                $cid = trim($_POST['id_' . $pk] ?? ''); $sec = trim($_POST['secret_' . $pk] ?? '');
                foreach ([$cid, $sec] as $v) if ($v !== '' && !preg_match('/^[A-Za-z0-9._\-~+\/=:]{6,300}$/', $v)) $bad = 'One of the values looks invalid. Paste the ID and secret exactly as the platform shows them.';
                if ($bad) break;
                if ($cid !== '') ja_setting_set("social_{$pk}_client_id", $cid, true);   // blank = keep current
                if ($sec !== '') ja_setting_set("social_{$pk}_client_secret", $sec, true);
            }
            if ($bad) $err = $bad; else $msg = 'Social app settings saved. Nothing is shown back; a row says "Set" once both values are stored.';
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

<?php elseif ($tab === 'social'): ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 6px">Social apps</h1>
<p class="ja-muted" style="margin-bottom:6px">Bluesky and Mastodon work for members with no setup here. The services below need an app that <em>you</em> register with them. Until both values are saved, members see "Not set up by the site owner" and nothing is attempted. Step by step instructions are in <code>docs/SOCIAL_SETUP.md</code>.</p>
<p class="ja-muted" style="margin-bottom:18px">Redirect address to register with every service: <code><?= htmlspecialchars(ja_social_redirect_uri()) ?></code></p>
<?php if (!ja_social_base_public()): ?><div class="ja-warn" style="margin-bottom:16px"><strong>This site is not on a public https address.</strong> Instagram and Threads cannot post photos from here (they download the photo from your website). The Instagram Kit still works: members can download the images or share them from their phone, and some services only accept https redirects. Everything works for testing on localhost where the service allows it. Set the public address in settings (<code>site_url</code>) when the site goes live.</div><?php endif; ?>
<section class="ja-card" style="margin-bottom:16px" aria-labelledby="igGuide">
  <h2 id="igGuide" style="font-size:1.15rem;margin:0 0 8px">Instagram: what you need to do</h2>
  <p class="ja-muted" style="margin:0 0 10px">Members can already make Instagram-ready images and a caption (the Instagram button in the share sheet) and post them from their phone. <strong>No setup is needed for that.</strong> Posting straight from this site needs the steps below, and a site that is online over https.</p>
  <ol style="margin:0 0 10px 1.2em;padding:0;line-height:1.6">
    <li>The Instagram account must be a <strong>Business or Creator</strong> account (Instagram app, Settings, Account type and tools). No Facebook Page is needed with this login.</li>
    <li>At developers.facebook.com create an app of type <strong>Business</strong>, then add the product <strong>Instagram</strong> and open <strong>API setup with Instagram login</strong>.</li>
    <li>Copy the <strong>Instagram app ID</strong> and <strong>Instagram app secret</strong> shown there (they are not the same as the Facebook app ID and secret) into the Instagram row below.</li>
    <li>Under <strong>Set up Instagram business login</strong> add this redirect address: <code><?= htmlspecialchars(ja_social_redirect_uri()) ?></code>. Instagram needs https for live use. Whether it accepts a <code>http://localhost</code> address for testing is unverified here.</li>
    <li>Permissions requested: <code>instagram_business_basic</code> and <code>instagram_business_content_publish</code>.</li>
    <li><strong>Development mode needs no App Review for your own account:</strong> in App roles, Roles, add the Instagram account as an <strong>Instagram Tester</strong> and accept the invite in Instagram (Settings, Website permissions, Apps and websites, Tester invites). Every other member needs App Review and Business Verification before they can log in.</li>
    <li>Set the <code>site_url</code> setting to your public https address. Instagram downloads each image from your site, so a site on localhost cannot publish.</li>
  </ol>
  <p class="ja-muted" style="margin:0">The site stores only the sign-in token (encrypted), never an Instagram password. Photos and photo carousels can be posted; Reels and Stories are not published from here. The site stops at 25 posts per account in 24 hours.</p>
</section>
<form method="post" class="ja-card ja-form-grid">
  <?= ja_csrf_field() ?><input type="hidden" name="form" value="social">
  <?php foreach ($SOCIAL_APPS as $pk => $plabel): $isSet = ja_fresh("social_{$pk}_client_id") !== '' && ja_fresh("social_{$pk}_client_secret") !== ''; ?>
  <fieldset style="border:0;padding:0;margin:0;grid-column:1/-1;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
    <legend style="font-weight:600;margin-bottom:6px"><?= htmlspecialchars($plabel) ?> <span class="ja-pill <?= $isSet ? 'near' : 'season' ?>"><?= $isSet ? 'Set' : 'Not set up' ?></span></legend>
    <div class="ja-field"><label for="id_<?= $pk ?>">Client ID / App ID</label><input class="ja-input" id="id_<?= $pk ?>" type="password" name="id_<?= $pk ?>" autocomplete="off" placeholder="<?= $isSet ? '(saved, leave blank to keep)' : 'Paste the ID' ?>"></div>
    <div class="ja-field"><label for="secret_<?= $pk ?>">Client secret / App secret</label><input class="ja-input" id="secret_<?= $pk ?>" type="password" name="secret_<?= $pk ?>" autocomplete="new-password" placeholder="<?= $isSet ? '(saved, leave blank to keep)' : 'Paste the secret' ?>"></div>
    <?php if ($isSet): ?><label class="ja-switch" style="grid-column:1/-1"><input type="checkbox" name="remove_<?= $pk ?>" value="1"> <span>Remove the saved <?= htmlspecialchars($plabel) ?> credentials</span></label><?php endif; ?>
  </fieldset>
  <?php endforeach; ?>
  <div class="ja-form-actions"><button class="ja-btn ja-btn-primary" type="submit">Save</button></div>
</form>
<p class="ja-muted" style="margin-top:12px">Secrets are stored encrypted and are never shown again. Member sign-in tokens are encrypted separately with the key in <code>private/social.key</code>. Back that file up with your database: without it, members would have to reconnect.</p>

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
