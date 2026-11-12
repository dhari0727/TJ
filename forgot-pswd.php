<?php
$ja_title = "Forgot password"; $ja_active = "login";
include("connection.php");
require_once __DIR__ . '/ja-trust.php';
require("mail.php");
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$err = ""; $ok = "";

if (isset($_POST['next'])) {
    ja_csrf_enforce();
    $email = trim($_POST['email'] ?? '');
    $generic = "If that email is registered, a reset link has been sent.";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address.";
    } elseif (!ja_rate_limit('forgot:ip:' . ja_client_ip(), 8, 3600) || !ja_rate_limit('forgot:eml:' . strtolower($email), 3, 3600)) {
        $err = "Too many requests. Please wait a while before trying again.";
    } else {
        $check = mysqli_prepare($conn, "SELECT eml FROM signup WHERE eml = ? LIMIT 1");
        mysqli_stmt_bind_param($check, 's', $email);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        $exists = mysqli_stmt_num_rows($check) > 0;
        mysqli_stmt_close($check);

        // Every outcome shows the same message, so the form never reveals whether an address is registered.
        $ok = $generic;
        $devLink = '';
        if ($exists) {
            $token = bin2hex(random_bytes(32));
            $hash = ja_token_hash($token);   // only the hash is stored; the raw token travels in the emailed link
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $up = mysqli_prepare($conn, "UPDATE signup SET reset_token = ?, reset_expires = ? WHERE eml = ?");
            mysqli_stmt_bind_param($up, 'sss', $hash, $expires, $email);
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);

            $res = send_reset_email($email, $token);
            ja_audit($email, 'reset_requested', $res['status'] ?? '');
            if (!$res['ok'] && ($res['status'] ?? '') === 'not_configured' && ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()) $devLink = $res['url'];
        } else {
            usleep(mt_rand(250000, 600000));   // roughly the time a real send takes
            if (ja_setting('dev_show_reset_link') === '1' && ja_is_local_dev()) $devLink = ja_reset_url(bin2hex(random_bytes(32)));   // same shape as a real one, stores nothing
        }
        // local development only: email isn't set up, so show the link here (identical shape for known and unknown addresses)
        if ($devLink !== '') $ok .= " Dev link: " . $devLink;
    }
}
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('goa', 'Reset your password', 'Enter the email you signed up with.'); ?>
      <?php if ($ok): ?>
        <div class="ja-ok" role="status"><?= ja_icon('check', 16) ?> <?= htmlspecialchars($ok) ?></div>
        <p class="acct-note">Check your inbox, and your spam folder if it does not show up within a few minutes. You can ask again after an hour.</p>
      <?php endif; ?>
      <?php if ($err && !$ok): ?><div class="ja-err" role="alert" id="form-err" tabindex="-1"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post" data-acct-validate novalidate><?= ja_csrf_field() ?>
        <?= ja_field(['id' => 'email', 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $_POST['email'] ?? '', 'autocomplete' => 'email', 'required' => 1, 'autofocus' => !$ok, 'rule' => 'email', 'label_text' => 'email address', 'invalid' => (bool)($err && !$ok), 'also' => ($err && !$ok) ? 'form-err' : '']) ?>
        <button class="ja-btn ja-btn-primary acct-submit" type="submit" name="next" value="1">Send reset link</button>
      </form>
      <div class="acct-next">
        <strong>What happens next</strong>
        <ol>
          <li>We email you a link. It works once and expires after 1 hour.</li>
          <li>You choose a new password. Other devices are signed out.</li>
          <li>You log in with it.</li>
        </ol>
        <p>For privacy this page shows the same message whether or not the address has an account.</p>
      </div>
      <p class="acct-alt">Remembered it? <a href="login.php">Log in</a></p>
<?php ja_auth_close(); ?>
</body>
</html>
