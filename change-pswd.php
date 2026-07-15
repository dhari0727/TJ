<?php
$ja_title = "Change Password"; $ja_active = "profile";
include("connection.php");
session_start();
if (empty($_SESSION['eml'])) { header("location:login.php"); exit; }
$eml = $_SESSION['eml'];
$err = ''; $ok = '';

if (isset($_POST['Save'])) {
    $p = $_POST['PASS'] ?? '';
    $valid = preg_match('@[A-Z]@',$p) && preg_match('@[a-z]@',$p) && preg_match('@[0-9]@',$p)
          && preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/',$p) && strlen($p) >= 8;
    if (!$valid) {
        $err = 'Password must be at least 8 characters with an uppercase letter, a number, and a special character.';
    } else {
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE signup SET psw = ? WHERE eml = ?");
        mysqli_stmt_bind_param($stmt, 'ss', $hash, $eml);
        if (mysqli_stmt_execute($stmt)) $ok = 'Password updated successfully.';
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">✦ Security</div>
    <h1>Change Password</h1>
    <p class="sub">Choose a strong password to keep your account secure.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container" style="max-width:520px">
    <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ja-ok"><?= ja_icon('check',16) ?> <?= htmlspecialchars($ok) ?></div><?php endif; ?>
    <div class="ja-card">
      <form method="post">
        <div class="ja-field">
          <label>New password</label>
          <input class="ja-input" type="password" name="PASS" required
                 placeholder="8+ chars, 1 uppercase, 1 number, 1 symbol">
        </div>
        <div style="display:flex;gap:10px">
          <button class="ja-btn ja-btn-primary" type="submit" name="Save" data-magnetic>Update password</button>
          <a href="profile.php" class="ja-btn ja-btn-ghost">Back</a>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
