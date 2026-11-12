<?php
$ja_title = "Change Password"; $ja_active = "profile";
include("connection.php");
session_start();
if (empty($_SESSION['eml'])) { header("location:login.php"); exit; }
$eml = $_SESSION['eml'];
$err = ''; $ok = '';

if (isset($_POST['Save'])) {
    $p = $_POST['PASS'] ?? '';
    // must prove they know the current password (a stolen/unattended session can't lock the owner out)
    $cq = mysqli_prepare($conn, "SELECT psw FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($cq, 's', $eml); mysqli_stmt_execute($cq);
    $stored = (string)(mysqli_fetch_row(mysqli_stmt_get_result($cq))[0] ?? ''); mysqli_stmt_close($cq);
    $curOk = strlen($stored) >= 60 ? password_verify($_POST['CUR'] ?? '', $stored) : hash_equals($stored, (string)($_POST['CUR'] ?? ''));
    $valid = preg_match('@[A-Z]@',$p) && preg_match('@[a-z]@',$p) && preg_match('@[0-9]@',$p)
          && preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/',$p) && strlen($p) >= 8;
    if (!$curOk) {
        $err = 'Your current password is not correct.';
    } elseif (!$valid) {
        $err = 'Password must be at least 8 characters with an uppercase letter, a number, and a special character.';
    } else {
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE signup SET psw = ?, session_epoch = session_epoch + 1 WHERE eml = ?");
        mysqli_stmt_bind_param($stmt, 'ss', $hash, $eml);
        if (mysqli_stmt_execute($stmt)) { $ok = 'Password updated successfully. Other devices have been signed out.'; $_SESSION['ep'] = (int)($_SESSION['ep'] ?? 0) + 1; }
        mysqli_stmt_close($stmt);
    }
}
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct-page">

<main class="acct-page acct-narrow">
  <header class="acct-head">
    <h1>Change password</h1>
    <p>Choose a new one. Your other devices are signed out when you save it.</p>
  </header>
  <?php if ($err): ?><div class="ja-err" role="alert" id="form-err" tabindex="-1"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="ja-ok" role="status"><?= ja_icon('check',16) ?> <?= htmlspecialchars($ok) ?></div><?php endif; ?>
  <form method="post" class="acct-formbox" data-acct-validate novalidate>
    <?= ja_field(['id' => 'cur', 'name' => 'CUR', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => 1, 'rule' => 'required', 'label_text' => 'current password', 'invalid' => ($err && stripos($err, 'current') !== false), 'also' => ($err && stripos($err, 'current') !== false) ? 'form-err' : '']) ?>
    <?= ja_field(['id' => 'new', 'name' => 'PASS', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => 1, 'rule' => 'password', 'label_text' => 'new password', 'meter' => 1, 'invalid' => ($err && stripos($err, 'current') === false), 'also' => ($err && stripos($err, 'current') === false) ? 'form-err' : '']) ?>
    <div class="acct-actions">
      <button class="ja-btn ja-btn-primary" type="submit" name="Save">Update password</button>
      <a href="profile.php" class="ja-btn ja-btn-ghost">Back to profile</a>
    </div>
  </form>
</main>

<?php include 'ja-footer.php'; ?>
<script src="js/ja-auth.js?v=<?= (int)@filemtime(__DIR__ . '/js/ja-auth.js') ?>" defer></script>
