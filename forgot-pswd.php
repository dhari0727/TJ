<?php
$ja_title = "Forgot password"; $ja_active = "login";
include("connection.php");
require("mail.php");
session_start();
$err = ""; $ok = "";

if (isset($_POST['next'])) {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address.";
    } else {
        $check = mysqli_prepare($conn, "SELECT eml FROM signup WHERE eml = ? LIMIT 1");
        mysqli_stmt_bind_param($check, 's', $email);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        $exists = mysqli_stmt_num_rows($check) > 0;
        mysqli_stmt_close($check);

        if ($exists) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $up = mysqli_prepare($conn, "UPDATE signup SET reset_token = ?, reset_expires = ? WHERE eml = ?");
            mysqli_stmt_bind_param($up, 'sss', $token, $expires, $email);
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);

            if (send_reset_email($email, $token)) {
                $ok = "If that email is registered, a reset link has been sent.";
            } else {
                $err = "Unable to send the reset email right now. Please try again later.";
            }
        } else {
            // don't reveal whether the address is registered
            $ok = "If that email is registered, a reset link has been sent.";
        }
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
      <form method="post">
        <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="email" required autofocus></div>
        <button class="ja-btn ja-btn-primary" type="submit" name="next" value="1" data-magnetic style="width:100%">Send reset link</button>
      </form>
      <p class="ja-auth-alt">Remembered it? <a href="login.php">Log in</a></p>
    </div>
  </div>
</div>
</body>
</html>
