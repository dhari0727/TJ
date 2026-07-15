<?php
$ja_title = "Sign up"; $ja_active = "register";
include("connection.php");
session_start();
$err = ""; $err2 = "";
if (isset($_POST["sn"])) {
    $fname = trim($_POST["fname"] ?? '');
    $lname = trim($_POST["lname"] ?? '');
    $eml   = trim($_POST["eml"] ?? '');
    $psw   = $_POST["psw"] ?? '';

    $check = mysqli_prepare($conn, "SELECT eml FROM signup WHERE eml = ? LIMIT 1");
    mysqli_stmt_bind_param($check, 's', $eml);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    $emailExists = mysqli_stmt_num_rows($check) > 0;
    mysqli_stmt_close($check);

    $strong = preg_match('@[A-Z]@',$psw) && preg_match('@[a-z]@',$psw) && preg_match('@[0-9]@',$psw)
           && preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/',$psw) && strlen($psw) >= 8;
    $validate = filter_var($eml, FILTER_VALIDATE_EMAIL);

    if ($emailExists) {
        $err2 = "That email is already registered.";
    } else if (!$strong) {
        $err = "Password needs 8+ characters with an uppercase letter, a number and a symbol.";
    } else if (!$validate) {
        $err2 = "Please enter a valid email address.";
    } else {
        $hash = password_hash($psw, PASSWORD_DEFAULT);
        $sql = mysqli_prepare($conn, "INSERT INTO signup (fname, lname, eml, psw) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($sql, 'ssss', $fname, $lname, $eml, $hash);
        if (mysqli_stmt_execute($sql)) {
            $_SESSION["fname"] = $fname; $_SESSION["lname"] = $lname; $_SESSION['eml'] = $eml;
            header("location: dashboard.php"); exit();
        }
    }
}
require 'ja-images.php';
$img = ja_local_images()['bali'] ?? (ja_local_images()['kerala'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-auth">
  <div class="ja-auth-visual" style="background-image:linear-gradient(160deg,rgba(11,61,79,.35),rgba(11,61,79,.75)),url('<?= htmlspecialchars($img) ?>')">
    <div class="ja-auth-quote">
      <div class="ja-eyebrow" style="color:var(--ja-aqua-2)">✦ Start exploring</div>
      <h2>Travel smarter,<br>from real journeys.</h2>
      <p>Budget-aware, explainable recommendations across 90+ destinations — free to join.</p>
    </div>
  </div>
  <div class="ja-auth-form">
    <div class="ja-auth-box">
      <a class="ja-brand" href="index.php" style="margin-bottom:26px"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI</a>
      <h1>Create account</h1>
      <p class="ja-auth-lead">Join JourneyAI and start planning in minutes.</p>
      <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($err2): ?><div class="ja-err"><?= htmlspecialchars($err2) ?></div><?php endif; ?>
      <form method="post">
        <div class="ja-fieldrow">
          <div class="ja-field"><label>First name</label><input class="ja-input" name="fname" required></div>
          <div class="ja-field"><label>Last name</label><input class="ja-input" name="lname" required></div>
        </div>
        <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="eml" required></div>
        <div class="ja-field"><label>Password</label><input class="ja-input" type="password" name="psw" required placeholder="8+ chars, 1 uppercase, 1 number, 1 symbol"></div>
        <button class="ja-btn ja-btn-primary" type="submit" name="sn" data-magnetic style="width:100%">Create account</button>
      </form>
      <p class="ja-auth-alt">Already have an account? <a href="login.php">Log in</a></p>
    </div>
  </div>
</div>
</body>
</html>
