<?php
$ja_title = "Forgot password"; $ja_active = "login";
include("connection.php");
require_once __DIR__ . '/ja-trust.php';
require("mail.php");
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$err = ""; $ok = "";

if (isset($_POST['next'])) {
    ja_csrf_enforce();
    $email = trim($_POST['email'] ?? '');
    $generic = "If that email is registered, a reset link has been sent.";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address.";
    } elseif (!ja_rate_limit('forgot:ip:' . ja_client_ip(), 8, 3600) || !ja_rate_limit('forgot:eml:' . strtolower($email), 3, 3600)) {
        $err = "Too many requests. Please wait a while before trying again.";
    } else {
        $check = mysqli_prepare($conn, "SELECT eml FROM signup WHERE eml = ? LIMIT 1");
        mysqli_stmt_bind_param($check, 's', $email);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        $exists = mysqli_stmt_num_rows($check) > 0;
        mysqli_stmt_close($check);

        // Every outcome shows the same message, so the form never reveals whether an address is registered.
        $ok = $generic;
        $devLink = '';
        if ($exists) {
            $token = bin2hex(random_bytes(32));
            $hash = ja_token_hash($token);   // only the hash is stored; the raw token travels in the emailed link
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $up = mysqli_prepare($conn, "UPDATE signup SET reset_token = ?, reset_expires = ? WHERE eml = ?");
            mysqli_stmt_bind_param($up, 'sss', $hash, $expires, $email);
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);

            $res = send_reset_email($email, $token);
            ja_audit($email, 'reset_requested', $res['status'] ?? '');
            if (!$res['ok'] && ($res['status'] ?? '') === 'not_configured' && ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()) $devLink = $res['url'];
        } else {
            usleep(mt_rand(250000, 600000));   // roughly the time a real send takes
            if (ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()) $devLink = ja_reset_url(bin2hex(random_bytes(32)));   // same shape as a real one, stores nothing
        }
        // local development only: email isn't set up, so show the link here (identical shape for known and unknown addresses)
        if ($devLink !== '') $ok .= " Dev link: " . $devLink;
    }
}
require 'ja-images.php';
$img = ja_local_images()['kerala'] ?? (ja_local_images()['goa'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth">
  <div class="ja-auth-visual" style="background-image:linear-gradient(160deg,rgba(11,61,79,.35),rgba(11,61,79,.75)),url('<?= htmlspecialchars($img) ?>')">
    <div class="ja-auth-quote">
      <div class="ja-eyebrow" style="color:var(--ja-aqua-2)">✦ Locked out?</div>
      <h2>Let's get you<br>back in.</h2>
      <p>Enter your account email and we'll send you a link to set a new password.</p>
    </div>
  </div>
  <div class="ja-auth-form">
    <div class="ja-auth-box">
      <a class="ja-brand" href="index.php" style="margin-bottom:26px"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI</a>
      <h1>Forgot password</h1>
      <p class="ja-auth-lead">We'll email you a link to reset it.</p>
      <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ja-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <form method="post"><?= ja_csrf_field() ?>
        <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="email" required autofocus></div>
        <button class="ja-btn ja-btn-primary" type="submit" name="next" value="1" data-magnetic style="width:100%">Send reset link</button>
      </form>
      <p class="ja-auth-alt">Remembered it? <a href="login.php">Log in</a></p>
    </div>
  </div>
</div>
</body>
</html>
