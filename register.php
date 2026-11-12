<?php
$ja_title = "Sign up"; $ja_active = "register";
include("connection.php");
session_start();
require_once __DIR__ . '/ja-trust.php';
$regOpen = ja_setting('allow_registration', '1') === '1';
$err = ""; $err2 = $regOpen ? "" : "New sign-ups are currently closed.";
if (isset($_POST["sn"]) && !$regOpen) { $err2 = "New sign-ups are currently closed."; }
elseif (isset($_POST["sn"])) {
    ja_csrf_enforce();
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

    // bot traps: a hidden field humans never fill, and a form that is submitted faster than a person can type
    $tooFast = (time() - (int)($_SESSION['reg_t'] ?? 0)) < 4;
    if (!empty($_POST['website']) || $tooFast) {
        $err2 = "That looked automated. Please wait a moment and try again.";
        ja_audit($eml, 'register_bot_trap', $tooFast ? 'too fast' : 'honeypot');
    } else if (ja_disposable_email($eml)) {
        $err2 = "Please use a permanent email address (temporary inboxes are not accepted).";
    } else if (ja_common_password($psw)) {
        $err = "That password is too common. Pick something less guessable.";
    } else if (!ja_rate_limit('register:ip:' . ja_client_ip(), 10, 3600)) {
        $err2 = "Too many sign-ups from this network. Please try again later.";
    } else if ($emailExists) {
        // never confirm that an address is taken: same wording as a generic failure, and similar work/time as a real sign-up
        password_hash($psw, PASSWORD_DEFAULT);
        ja_audit($eml, 'register_existing', '');
        $err2 = "We couldn't create an account with those details. If you already have one, log in or reset your password.";
    } else if (!$strong) {
        $err = "Password needs 8+ characters with an uppercase letter, a number and a symbol.";
    } else if (!$validate) {
        $err2 = "Please enter a valid email address.";
    } else {
        $hash = password_hash($psw, PASSWORD_DEFAULT);
        $sql = mysqli_prepare($conn, "INSERT INTO signup (fname, lname, eml, psw) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($sql, 'ssss', $fname, $lname, $eml, $hash);
        if (mysqli_stmt_execute($sql)) {
            ja_ensure_handle($eml);   // public profile handle (never derived from the email)
            $v = ja_send_verification($eml);
            ja_audit($eml, 'register', $v['status']);
            if ($v['status'] === 'not_configured' && ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()) {
                $_SESSION['ja_flash'] = 'Email is not set up yet (admin: Admin > Email). Local test link: ' . $v['url'];
            }
            session_regenerate_id(true);
            $_SESSION["fname"] = $fname; $_SESSION["lname"] = $lname; $_SESSION['eml'] = $eml; $_SESSION['ep'] = 0;
            header("location: dashboard.php"); exit();
        }
    }
}
$_SESSION['reg_t'] = time();   // when the form was shown (for the too-fast check)
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
// which field an error belongs to: password rules and address problems sit under their field, the rest goes in the summary
$errPsw = $err; $errEml = ''; $errTop = '';
if ($err2 !== '') { if (stripos($err2, 'email address') !== false && stripos($err2, "couldn't create") === false) $errEml = $err2; else $errTop = $err2; }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('kerala', 'Create your account', 'Free. Plan trips privately from the first minute.'); ?>
      <?php if ($errTop): ?><div class="ja-err" role="alert" id="form-err" tabindex="-1"><?= htmlspecialchars($errTop) ?></div><?php endif; ?>
      <form method="post" data-acct-validate novalidate><?= ja_csrf_field() ?>
        <div class="ja-fieldrow">
          <?= ja_field(['id' => 'fname', 'name' => 'fname', 'label' => 'First name', 'value' => $_POST['fname'] ?? '', 'autocomplete' => 'given-name', 'required' => 1, 'rule' => 'required', 'label_text' => 'first name', 'maxlength' => 100]) ?>
          <?= ja_field(['id' => 'lname', 'name' => 'lname', 'label' => 'Last name', 'value' => $_POST['lname'] ?? '', 'autocomplete' => 'family-name', 'required' => 1, 'rule' => 'required', 'label_text' => 'last name', 'maxlength' => 100]) ?>
        </div>
        <div style="position:absolute;left:-5000px" aria-hidden="true"><label>Website</label><input name="website" tabindex="-1" autocomplete="off"></div>
        <?= ja_field(['id' => 'eml', 'name' => 'eml', 'label' => 'Email', 'type' => 'email', 'value' => $_POST['eml'] ?? '', 'autocomplete' => 'email', 'required' => 1, 'rule' => 'email', 'label_text' => 'email address', 'error' => $errEml, 'hint' => 'We send one link to confirm it. We never show it publicly.']) ?>
        <?= ja_field(['id' => 'psw', 'name' => 'psw', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => 1, 'rule' => 'password', 'label_text' => 'password', 'error' => $errPsw, 'meter' => 1]) ?>
        <button class="ja-btn ja-btn-primary acct-submit" type="submit" name="sn">Create account</button>
      </form>
      <div class="acct-next">
        <strong>What happens next</strong>
        <ol>
          <li>You land on Home, signed in.</li>
          <li>We email a link to confirm your address.</li>
          <li>Plan and write privately right away. Sharing publicly unlocks once your email is confirmed.</li>
        </ol>
      </div>
      <p class="acct-alt">Already have an account? <a href="login.php">Log in</a></p>
<?php ja_auth_close(); ?>
</body>
</html>
