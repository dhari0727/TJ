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
    ja_csrf_enforce();
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
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('taj-mahal', 'Create the admin account', 'No admin exists yet. This page disappears once you create one.'); ?>
  <?php if ($err): ?><div class="ja-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post" data-acct-validate novalidate>
    <div class="ja-fieldrow">
      <?= ja_field(['id' => 'fname', 'name' => 'fname', 'label' => 'First name', 'value' => $_POST['fname'] ?? '', 'required' => 1, 'rule' => 'required', 'label_text' => 'first name']) ?>
      <?= ja_field(['id' => 'lname', 'name' => 'lname', 'label' => 'Last name', 'value' => $_POST['lname'] ?? '']) ?>
    </div>
    <?= ja_field(['id' => 'eml', 'name' => 'eml', 'label' => 'Email', 'type' => 'email', 'value' => $_POST['eml'] ?? '', 'required' => 1, 'rule' => 'email', 'label_text' => 'email address']) ?>
    <?= ja_field(['id' => 'psw', 'name' => 'psw', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => 1, 'rule' => 'password', 'label_text' => 'password', 'meter' => 1]) ?>
    <button class="ja-btn ja-btn-primary acct-submit" type="submit" name="create">Create admin</button>
  </form>
<?php ja_auth_close(); ?>
</body>
</html>
