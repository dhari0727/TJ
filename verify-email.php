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
    $st = mysqli_prepare($conn, "SELECT eml FROM signup WHERE verify_token = ? AND verify_sent_at > (NOW() - INTERVAL 48 HOUR) AND email_verified = 0");
    mysqli_stmt_bind_param($st, 's', $token); mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if ($row) {
        $up = mysqli_prepare($conn, "UPDATE signup SET email_verified = 1, verify_token = NULL WHERE eml = ?");
        mysqli_stmt_bind_param($up, 's', $row['eml']); mysqli_stmt_execute($up); mysqli_stmt_close($up);
        ja_audit($row['eml'], 'email_verified', '');
        $ok = true; $msg = 'Your email is confirmed. You can now share journals and photos with the community.';
    } else {
        $msg = 'This link is not valid any more. Request a new one below.';
    }
}
$me = $_SESSION['eml'] ?? null;
$already = $me ? (int)mysqli_fetch_row(mysqli_query($conn, "SELECT email_verified FROM signup WHERE eml='" . mysqli_real_escape_string($conn, $me) . "'"))[0] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth"><div class="ja-auth-form" style="margin:0 auto"><div class="ja-auth-box">
  <h1>Email verification</h1>
  <?php if ($msg): ?><div class="<?= $ok ? 'ja-ok' : 'ja-err' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($resent): ?>
    <?php if ($resent['ok']): ?><div class="ja-ok">We sent a new link. Check your inbox (and spam).</div>
    <?php elseif ($resent['status'] === 'limited'): ?><div class="ja-err">Too many requests. Try again in an hour.</div>
    <?php elseif ($resent['status'] === 'not_configured' && ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()): ?>
      <div class="ja-ok">Email isn't set up yet. Local test link: <a href="<?= htmlspecialchars($resent['url']) ?>"><?= htmlspecialchars($resent['url']) ?></a></div>
    <?php else: ?><div class="ja-err">We could not send the email right now. Please try later.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($me && !$already && !$ok): ?>
    <p class="ja-auth-lead">Confirm your email to share journals and photos publicly. Until then everything you write stays private.</p>
    <form method="post"><?= ja_csrf_field() ?><button class="ja-btn ja-btn-primary" name="resend" value="1" style="width:100%">Send me a new link</button></form>
  <?php elseif ($me && $already && !$msg): ?><div class="ja-ok">Your email is already verified.</div><?php endif; ?>
  <p class="ja-auth-alt"><a href="<?= $me ? 'dashboard.php' : 'login.php' ?>"><?= $me ? 'Go to Home' : 'Log in' ?></a></p>
</div></div></div>
</body>
</html>
