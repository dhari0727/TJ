<?php
/** Confirms an email address from the link we send. verify-email.php?token=...   Also: ?resend=1 (signed-in users). */
$ja_title = "Verify email"; $ja_active = "login";
session_start();
require 'connection.php';
require_once 'ja-trust.php';
$msg = ''; $ok = false; $resent = null;

if (isset($_POST['resend']) && !empty($_SESSION['eml']) && ja_csrf_ok()) {
    $resent = ja_send_verification($_SESSION['eml']);
}
$token = trim($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokHash = ja_token_hash($token);   // only the sha256 of the emailed token is stored
    $st = mysqli_prepare($conn, "SELECT eml, verify_token FROM signup WHERE verify_token = ? AND verify_sent_at > (NOW() - INTERVAL 48 HOUR) AND email_verified = 0");
    mysqli_stmt_bind_param($st, 's', $tokHash); mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    $claimed = false;
    if ($row && hash_equals((string)$row['verify_token'], $tokHash)) {
        $up = mysqli_prepare($conn, "UPDATE signup SET email_verified = 1, verify_token = NULL WHERE eml = ? AND verify_token = ?");
        mysqli_stmt_bind_param($up, 'ss', $row['eml'], $tokHash); mysqli_stmt_execute($up);
        $claimed = mysqli_stmt_affected_rows($up) === 1; mysqli_stmt_close($up);
    }
    if ($claimed) {
        ja_audit($row['eml'], 'email_verified', '');
        $ok = true; $msg = 'Your email is confirmed. You can now share journals and photos with the community.';
    } else {
        $msg = 'This link is not valid any more. Request a new one below.';
    }
}
$me = $_SESSION['eml'] ?? null;
$already = $me ? (int)mysqli_fetch_row(mysqli_query($conn, "SELECT email_verified FROM signup WHERE eml='" . mysqli_real_escape_string($conn, $me) . "'"))[0] : 0;
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('taj-mahal', 'Confirm your email', ''); ?>
  <?php if ($msg): ?><div class="<?= $ok ? 'ja-ok' : 'ja-err' ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($resent): ?>
    <?php if ($resent['ok']): ?><div class="ja-ok" role="status">We sent a new link. Check your inbox and your spam folder.</div>
    <?php elseif ($resent['status'] === 'limited'): ?><div class="ja-err" role="alert">Too many requests. Try again in an hour.</div>
    <?php elseif ($resent['status'] === 'not_configured' && ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()): ?>
      <div class="ja-ok" role="status">Email isn't set up yet. Local test link: <a href="<?= htmlspecialchars($resent['url']) ?>"><?= htmlspecialchars($resent['url']) ?></a></div>
    <?php else: ?><div class="ja-err" role="alert">We could not send the email right now. Please try later.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($me && !$already && !$ok): ?>
    <p class="acct-lead">Confirm your email to share journals and photos publicly. Until then everything you write stays private.</p>
    <form method="post"><?= ja_csrf_field() ?><button class="ja-btn ja-btn-primary acct-submit" name="resend" value="1">Email me a new link</button></form>
    <p class="acct-next"><strong>What happens next</strong> Open the link in the email. It works for 48 hours and confirms this address straight away.</p>
  <?php elseif ($me && $already && !$msg): ?><div class="ja-ok" role="status">Your email is already confirmed.</div>
  <?php elseif (!$me && !$ok && !$msg): ?><p class="acct-lead">Open the link we emailed you to confirm your address, or log in to ask for a new one.</p><?php endif; ?>
  <p class="acct-alt"><a href="<?= $me ? 'dashboard.php' : 'login.php' ?>"><?= $me ? 'Go to Home' : 'Log in' ?></a></p>
<?php ja_auth_close(); ?>
</body>
</html>
