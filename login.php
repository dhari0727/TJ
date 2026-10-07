<?php
$ja_title = "Login"; $ja_active = "login";
include('connection.php');
session_start();
require_once __DIR__ . '/ja-lib.php';
$evalue = "";
if (!empty($_GET['suspended'])) $evalue = "This account has been suspended. Contact the site admin.";

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
    // throttle: 6 failed attempts per email+IP in 15 minutes
    $ip = ja_client_ip();
    $tq = mysqli_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE eml = ? AND ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    mysqli_stmt_bind_param($tq, 'ss', $email, $ip);
    mysqli_stmt_execute($tq);
    mysqli_stmt_bind_result($tq, $recentFails);
    mysqli_stmt_fetch($tq);
    mysqli_stmt_close($tq);
    $locked = ($recentFails ?? 0) >= 6;

    $stmt = mysqli_prepare($conn, "SELECT fname, lname, eml, psw, role, is_active FROM signup WHERE eml = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $ok = false;
    if ($user && !$locked) {
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

    if ($ok && !(int)$user['is_active']) {
        $ok = false;
        $evalue = "This account has been suspended. Contact the site admin.";
    }
    if ($ok) {
        session_regenerate_id(true);
        $lu = mysqli_prepare($conn, "UPDATE signup SET last_login = NOW() WHERE eml = ?");
        mysqli_stmt_bind_param($lu, 's', $user['eml']);
        mysqli_stmt_execute($lu);
        mysqli_stmt_close($lu);
        $_SESSION['fname'] = $user['fname'];
        $_SESSION['lname'] = $user['lname'];
        $_SESSION['eml']   = $user['eml'];
        header("location:" . ($user['role'] === 'admin' && !empty($_GET['admin']) ? 'admin.php' : 'dashboard.php'));
        exit;
    } else {
        if ($evalue === "") {
            $evalue = $locked ? "Too many attempts. Wait 15 minutes or reset your password." : "Incorrect email or password.";
        }
        if (!$locked && $email !== '') {
            $fa = mysqli_prepare($conn, "INSERT INTO login_attempts (eml, ip) VALUES (?, ?)");
            mysqli_stmt_bind_param($fa, 'ss', $email, $ip);
            mysqli_stmt_execute($fa);
            mysqli_stmt_close($fa);
        }
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
      <p class="ja-auth-alt"><a href="forgot-pswd.php">Forgot your password?</a></p>
      <p class="ja-auth-alt">New here? <a href="register.php">Create an account</a></p>
    </div>
  </div>
</div>
</body>
</html>
