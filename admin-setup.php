<?php
/**
 * One-time setup: create the FIRST admin. Works only while no admin exists; afterwards it
 * redirects away, and further admins are made from Admin > Users.
 */
$ja_title = "Create admin"; $ja_active = "login";
include("connection.php");
session_start();
require_once __DIR__ . '/ja-lib.php';

$r = mysqli_query($conn, "SELECT COUNT(*) FROM signup WHERE role = 'admin'");
if ((int)mysqli_fetch_row($r)[0] > 0) { header('Location: login.php'); exit; }

$err = '';
if (isset($_POST['create'])) {
    $fname = trim($_POST['fname'] ?? ''); $lname = trim($_POST['lname'] ?? '');
    $eml = trim($_POST['eml'] ?? ''); $psw = $_POST['psw'] ?? '';
    $strong = preg_match('@[A-Z]@', $psw) && preg_match('@[a-z]@', $psw) && preg_match('@[0-9]@', $psw)
           && preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $psw) && strlen($psw) >= 8;
    if (!filter_var($eml, FILTER_VALIDATE_EMAIL)) { $err = 'Enter a valid email.'; }
    elseif (!$strong) { $err = 'Password needs 8+ characters with an uppercase letter, a number and a symbol.'; }
    elseif ($fname === '') { $err = 'Enter your name.'; }
    else {
        $hash = password_hash($psw, PASSWORD_DEFAULT);
        // existing account with this email? promote it (and set the new password); otherwise create it
        $st = mysqli_prepare($conn, "INSERT INTO signup (fname, lname, eml, psw, role, is_active) VALUES (?,?,?,?, 'admin', 1)
                                     ON DUPLICATE KEY UPDATE role = 'admin', is_active = 1, psw = VALUES(psw)");
        mysqli_stmt_bind_param($st, 'ssss', $fname, $lname, $eml, $hash);
        if (mysqli_stmt_execute($st)) {
            session_regenerate_id(true);
            $_SESSION['fname'] = $fname; $_SESSION['lname'] = $lname; $_SESSION['eml'] = $eml;
            header('Location: admin.php'); exit;
        }
        $err = 'Could not create the admin. Check that sql/admin.sql has been run.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth"><div class="ja-auth-form" style="margin:0 auto"><div class="ja-auth-box">
  <h1>Create the admin account</h1>
  <p class="ja-auth-lead">No admin exists yet. This page disappears once you create one.</p>
  <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <div class="ja-fieldrow">
      <div class="ja-field"><label>First name</label><input class="ja-input" name="fname" required value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>"></div>
      <div class="ja-field"><label>Last name</label><input class="ja-input" name="lname" value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>"></div>
    </div>
    <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="eml" required value="<?= htmlspecialchars($_POST['eml'] ?? '') ?>"></div>
    <div class="ja-field"><label>Password</label><input class="ja-input" type="password" name="psw" required placeholder="8+ chars, 1 uppercase, 1 number, 1 symbol"></div>
    <button class="ja-btn ja-btn-primary" type="submit" name="create" style="width:100%">Create admin</button>
  </form>
</div></div></div>
</body>
</html>
