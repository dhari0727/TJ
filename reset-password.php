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
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('kerala', 'Choose a new password', $valid ? 'For ' . $row['eml'] . '. Pick one you have not used before.' : ''); ?>
      <?php if ($err && !$valid && !$ok): ?><div class="ja-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($ok): ?>
        <div class="ja-ok" role="status"><?= ja_icon('check', 16) ?> <?= htmlspecialchars($ok) ?></div>
        <p><a class="ja-btn ja-btn-primary acct-submit" href="login.php">Log in</a></p>
      <?php elseif ($valid): ?>
        <form method="post" data-acct-validate novalidate><?= ja_csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <?= ja_field(['id' => 'psw', 'name' => 'psw', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => 1, 'autofocus' => 1, 'rule' => 'password', 'label_text' => 'new password', 'error' => $err, 'meter' => 1]) ?>
          <button class="ja-btn ja-btn-primary acct-submit" type="submit" name="next" value="1">Save new password</button>
        </form>
        <p class="acct-next"><strong>What happens next</strong> Your other devices are signed out, then you log in with the new password.</p>
      <?php else: ?>
        <p class="acct-note">This reset link is invalid or has expired. Links work once and last 1 hour.</p>
        <p><a class="ja-btn ja-btn-primary acct-submit" href="forgot-pswd.php">Request a new link</a></p>
      <?php endif; ?>
      <p class="acct-alt">Remembered it? <a href="login.php">Log in</a></p>
<?php ja_auth_close(); ?>
</body>
</html>
