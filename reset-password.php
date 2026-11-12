<?php
require_once __DIR__ . '/ja-trust.php';
$ja_title = "Reset password"; $ja_active = "login";
include("connection.php");
session_start();
$err = ""; $ok = "";
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$row = null;
if ($token !== '') {
    $tokHash = ja_token_hash($token);   // the DB only holds the sha256 of the emailed token
    $stmt = mysqli_prepare($conn, "SELECT eml, reset_expires, reset_token FROM signup WHERE reset_token = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $tokHash);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}
$valid = $row && hash_equals((string)$row['reset_token'], ja_token_hash($token)) && strtotime($row['reset_expires']) >= time();

if ($valid && isset($_POST['next'])) {
    ja_csrf_enforce();
    $psw = $_POST['psw'] ?? '';
    $strong = preg_match('@[A-Z]@',$psw) && preg_match('@[a-z]@',$psw) && preg_match('@[0-9]@',$psw)
           && preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/',$psw) && strlen($psw) >= 8 && !ja_common_password($psw);

    if (!$strong) {
        $err = "Password needs 8+ characters with an uppercase letter, a number and a symbol.";
    } else {
        $hash = password_hash($psw, PASSWORD_DEFAULT);
        $up = mysqli_prepare($conn, "UPDATE signup SET psw = ?, reset_token = NULL, reset_expires = NULL, session_epoch = session_epoch + 1 WHERE eml = ? AND reset_token = ?");
        mysqli_stmt_bind_param($up, 'sss', $hash, $row['eml'], $tokHash);
        mysqli_stmt_execute($up);
        $claimed = mysqli_stmt_affected_rows($up) === 1;   // a second use of the same link changes nothing
        mysqli_stmt_close($up);
        if (!$claimed) { $err = "This reset link was already used."; $valid = false; } else
        $ok = "Password updated. You can log in now.";
        $valid = false; // token is spent; hide the form
    }
}
require 'ja-images.php';
$img = ja_local_images()['bali'] ?? (ja_local_images()['goa'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth">
  <div class="ja-auth-visual" style="background-image:linear-gradient(160deg,rgba(11,61,79,.35),rgba(11,61,79,.75)),url('<?= htmlspecialchars($img) ?>')">
    <div class="ja-auth-quote">
      <div class="ja-eyebrow" style="color:var(--ja-aqua-2)">✦ Almost there</div>
      <h2>Choose a new<br>password.</h2>
      <p>Pick something strong you haven't used before.</p>
    </div>
  </div>
  <div class="ja-auth-form">
    <div class="ja-auth-box">
      <a class="ja-brand" href="index.php" style="margin-bottom:26px"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI</a>
      <h1>Reset password</h1>
      <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ja-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <?php if ($valid): ?>
        <p class="ja-auth-lead">Enter a new password for <?= htmlspecialchars($row['eml']) ?>.</p>
        <form method="post"><?= ja_csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="ja-field"><label>New password</label><input class="ja-input" type="password" name="psw" required placeholder="8+ chars, 1 uppercase, 1 number, 1 symbol"></div>
          <button class="ja-btn ja-btn-primary" type="submit" name="next" value="1" data-magnetic style="width:100%">Set new password</button>
        </form>
      <?php elseif (!$ok): ?>
        <p class="ja-auth-lead">This reset link is invalid or has expired.</p>
        <p class="ja-auth-alt"><a href="forgot-pswd.php">Request a new reset link</a></p>
      <?php endif; ?>
      <p class="ja-auth-alt">Remembered it? <a href="login.php">Log in</a></p>
    </div>
  </div>
</div>
</body>
</html>
