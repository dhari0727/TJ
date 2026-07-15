<?php
$ja_title = "Login"; $ja_active = "login";
include('connection.php');
session_start();
$evalue = "";

// logout (linked from the JourneyAI navbar as login.php?logout=1)
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("location:login.php");
    exit;
}

if (isset($_POST['lgn'])) {
    $email    = trim($_POST['eml'] ?? '');
    $password = $_POST['password'] ?? '';

    // fetch the user by email with a prepared statement (no SQL injection)
    $stmt = mysqli_prepare($conn, "SELECT fname, lname, eml, psw FROM signup WHERE eml = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $ok = false;
    if ($user) {
        $stored = $user['psw'];
        $isHashed = (strlen($stored) >= 60 && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')));
        if ($isHashed) {
            $ok = password_verify($password, $stored);
        } else {
            // legacy plaintext row — accept if it matches, then upgrade to a hash
            if (hash_equals($stored, $password)) {
                $ok = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $up = mysqli_prepare($conn, "UPDATE signup SET psw = ? WHERE eml = ?");
                mysqli_stmt_bind_param($up, 'ss', $newHash, $email);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
            }
        }
    }

    if ($ok) {
        $_SESSION['fname'] = $user['fname'];
        $_SESSION['lname'] = $user['lname'];
        $_SESSION['eml']   = $user['eml'];
        header("location:dashboard.php");
        exit;
    } else {
        $evalue = "Incorrect email or password.";
    }
}
require 'ja-images.php';
$img = ja_local_images()['maldives'] ?? (ja_local_images()['goa'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth">
  <div class="ja-auth-visual" style="background-image:linear-gradient(160deg,rgba(11,61,79,.35),rgba(11,61,79,.75)),url('<?= htmlspecialchars($img) ?>')">
    <div class="ja-auth-quote">
      <div class="ja-eyebrow" style="color:var(--ja-aqua-2)">✦ Welcome back</div>
      <h2>Your next journey<br>is waiting.</h2>
      <p>Pick up where you left off — your saved plans and journals are right here.</p>
    </div>
  </div>
  <div class="ja-auth-form">
    <div class="ja-auth-box">
      <a class="ja-brand" href="index.php" style="margin-bottom:26px"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI</a>
      <h1>Log in</h1>
      <p class="ja-auth-lead">Welcome back. Enter your details to continue.</p>
      <?php if ($evalue): ?><div class="ja-err"><?= htmlspecialchars($evalue) ?></div><?php endif; ?>
      <form method="post">
        <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="eml" required autofocus></div>
        <div class="ja-field"><label>Password</label><input class="ja-input" type="password" name="password" required></div>
        <button class="ja-btn ja-btn-primary" type="submit" name="lgn" data-magnetic style="width:100%">Log in</button>
      </form>
      <p class="ja-auth-alt">New here? <a href="register.php">Create an account</a></p>
    </div>
  </div>
</div>
</body>
</html>
