<?php
$ja_title = "Login"; $ja_active = "login";
include('connection.php');
session_start();
require_once __DIR__ . '/ja-trust.php';
$evalue = "";
if (!empty($_GET['suspended'])) $evalue = "This account has been suspended. Contact the site admin.";

// logging out is POST-only (logout.php); a GET ?logout=1 is ignored on purpose

if (isset($_POST['lgn'])) {
    ja_csrf_enforce();
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
    if (!ja_rate_limit('login:ip:' . ja_client_ip(), 40, 900)) $locked = true;

    $stmt = mysqli_prepare($conn, "SELECT fname, lname, eml, psw, role, is_active, session_epoch FROM signup WHERE eml = ? LIMIT 1");
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
        ja_audit($user['eml'], 'login', '');
        $lu = mysqli_prepare($conn, "UPDATE signup SET last_login = NOW() WHERE eml = ?");
        mysqli_stmt_bind_param($lu, 's', $user['eml']);
        mysqli_stmt_execute($lu);
        mysqli_stmt_close($lu);
        $_SESSION['fname'] = $user['fname'];
        $_SESSION['lname'] = $user['lname'];
        $_SESSION['eml']   = $user['eml'];
        $_SESSION['ep']    = (int)$user['session_epoch'];
        header("location:" . ($user['role'] === 'admin' && !empty($_GET['admin']) ? 'admin.php' : 'dashboard.php'));
        exit;
    } else {
        if ($evalue === "") {
            $evalue = $locked ? "Too many attempts. Wait 15 minutes or reset your password." : "Incorrect email or password.";
        }
        ja_audit($email, $locked ? 'login_locked' : 'login_failed', '');
        if (!$locked && $email !== '') {
            $fa = mysqli_prepare($conn, "INSERT INTO login_attempts (eml, ip) VALUES (?, ?)");
            mysqli_stmt_bind_param($fa, 'ss', $email, $ip);
            mysqli_stmt_execute($fa);
            mysqli_stmt_close($fa);
        }
    }
}
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css']; $ja_no_shell = true; $ja_no_nav = true;   // standalone page: no top bar or sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct">
<?php ja_auth_open('jaipur', 'Log in', 'Your saved trips and journals are waiting.'); ?>
      <?php if ($evalue): ?><div class="ja-err" role="alert" id="form-err" tabindex="-1"><?= htmlspecialchars($evalue) ?><?php if (stripos($evalue, 'suspended') === false): ?> <a href="forgot-pswd.php">Reset your password</a><?php endif; ?></div><?php endif; ?>
      <form method="post" data-acct-validate novalidate><?= ja_csrf_field() ?>
        <?= ja_field(['id' => 'eml', 'name' => 'eml', 'label' => 'Email', 'type' => 'email', 'value' => $_POST['eml'] ?? '', 'autocomplete' => 'username', 'required' => 1, 'autofocus' => empty($evalue), 'rule' => 'email', 'label_text' => 'email address', 'invalid' => (bool)$evalue, 'also' => $evalue ? 'form-err' : '']) ?>
        <?= ja_field(['id' => 'psw', 'name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => 1, 'label_text' => 'password', 'rule' => 'required', 'aside' => '<a class="acct-aside" href="forgot-pswd.php">Forgot password?</a>', 'invalid' => (bool)$evalue, 'also' => $evalue ? 'form-err' : '']) ?>
        <button class="ja-btn ja-btn-primary acct-submit" type="submit" name="lgn">Log in</button>
      </form>
      <p class="acct-next"><strong>What happens next</strong> You land on Home, with your saved trips and recent journals.</p>
      <p class="acct-alt">New here? <a href="register.php">Create an account</a></p>
<?php ja_auth_close(); ?>
</body>
</html>
