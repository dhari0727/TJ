<?php
$ja_title = "Profile"; $ja_active = "profile";
include('connection.php');
session_start();
require_once __DIR__ . '/ja-lib.php';
if (empty($_SESSION['eml'])) { header("location:login.php"); exit; }
$emll  = $_SESSION['eml'];
$flash = ''; $err = '';
$INTERESTS = ['beach','trekking','food','nightlife','history','temples','museums','shopping','wildlife','adventure',
              'relaxation','photography','nature','culture','mountains','desert','snow','diving','architecture'];
$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];

/* ---- download my data (JSON) ---- */
if (isset($_GET['export'])) {
    $out = ['exported_at' => date('c'), 'account' => null, 'preferences' => ja_user_prefs($emll)];
    $q = mysqli_prepare($conn, "SELECT fname, lname, eml, created_at FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($q, 's', $emll); mysqli_stmt_execute($q);
    $out['account'] = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
    foreach (['db' => 'journal_entries', 'media' => 'media', 'saved_plans' => 'saved_plans', 'saved_routes' => 'saved_routes', 'interactions' => 'ratings_and_activity', 'storybooks' => 'storybooks'] as $tbl => $key) {
        $q = mysqli_prepare($conn, "SELECT * FROM `$tbl` WHERE eml = ?");
        mysqli_stmt_bind_param($q, 's', $emll); mysqli_stmt_execute($q);
        $out[$key] = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC); mysqli_stmt_close($q);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="journeyai-my-data.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !ja_csrf_ok()) {
    $err = 'Session expired. Reload the page and try again.';
} elseif (isset($_POST['Save'])) {
    $fn  = trim($_POST['fname'] ?? '');
    $ln  = trim($_POST['lname'] ?? '');
    $eml = trim($_POST['email'] ?? '');
    if ($fn === '') { $err = 'Enter your first name.'; }
    elseif (!filter_var($eml, FILTER_VALIDATE_EMAIL)) { $err = 'Enter a valid email address.'; }
    else {
        $ok = true;
        if (strcasecmp($eml, $emll) !== 0) {
            $c = mysqli_prepare($conn, "SELECT 1 FROM signup WHERE eml = ?");
            mysqli_stmt_bind_param($c, 's', $eml); mysqli_stmt_execute($c);
            $taken = (bool)mysqli_fetch_row(mysqli_stmt_get_result($c)); mysqli_stmt_close($c);
            if ($taken) { $err = 'That email is already used by another account.'; $ok = false; }
            elseif (ja_rename_user($emll, $eml)) { $_SESSION['eml'] = $eml; $emll = $eml; }
            else { $err = 'Could not change the email. Nothing was changed.'; $ok = false; }
        }
        if ($ok) {
            $bio = mb_substr(trim($_POST['bio'] ?? ''), 0, 240);
            $u = mysqli_prepare($conn, "UPDATE signup SET fname = ?, lname = ?, bio = ? WHERE eml = ?");
            mysqli_stmt_bind_param($u, 'ssss', $fn, $ln, $bio, $emll); mysqli_stmt_execute($u); mysqli_stmt_close($u);
            $_SESSION['fname'] = $fn; $_SESSION['lname'] = $ln;
            $flash = 'Profile updated.';
        }
    }
} elseif (isset($_POST['SavePrefs'])) {
    $style = in_array($_POST['travel_style'] ?? '', $STYLES, true) ? $_POST['travel_style'] : 'mid-range';
    $ints = implode(',', array_values(array_intersect((array)($_POST['interests'] ?? []), $INTERESTS)));
    $budget = max(1000, min(2000000, (int)($_POST['default_budget'] ?? 30000)));
    $party = max(1, min(20, (int)($_POST['party_size'] ?? 1)));
    $home = mb_substr(trim($_POST['home_city'] ?? ''), 0, 120);
    $notify = !empty($_POST['notify_email']) ? 1 : 0;
    $st = mysqli_prepare($conn, "INSERT INTO user_prefs (eml, home_city, default_budget, travel_style, party_size, interests, notify_email)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE home_city = VALUES(home_city), default_budget = VALUES(default_budget),
        travel_style = VALUES(travel_style), party_size = VALUES(party_size), interests = VALUES(interests), notify_email = VALUES(notify_email)");
    mysqli_stmt_bind_param($st, 'ssisisi', $emll, $home, $budget, $style, $party, $ints, $notify);
    mysqli_stmt_execute($st); mysqli_stmt_close($st);
    $flash = 'Preferences saved. Plan a Trip and your recommendations now start from these.';
} elseif (isset($_POST['DeleteAccount'])) {
    $cq = mysqli_prepare($conn, "SELECT psw, role FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($cq, 's', $emll); mysqli_stmt_execute($cq);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($cq)); mysqli_stmt_close($cq);
    $stored = (string)($row['psw'] ?? '');
    $pwOk = strlen($stored) >= 60 ? password_verify($_POST['confirm_pw'] ?? '', $stored) : hash_equals($stored, (string)($_POST['confirm_pw'] ?? ''));
    $lastAdmin = false;
    if (($row['role'] ?? '') === 'admin') {
        $lastAdmin = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM signup WHERE role='admin' AND is_active=1"))[0] <= 1;
    }
    if (!$pwOk) { $err = 'Wrong password. Your account was not deleted.'; }
    elseif ($lastAdmin) { $err = "You're the only admin. Make someone else an admin first (Admin > Users)."; }
    else { ja_delete_user_data($emll); session_unset(); session_destroy(); header('Location: index.php?deleted=1'); exit; }
}

$q = mysqli_prepare($conn, "SELECT * FROM signup WHERE eml = ? LIMIT 1");
mysqli_stmt_bind_param($q, 's', $emll); mysqli_stmt_execute($q);
$res = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
$prefs = ja_user_prefs($emll);

$q = mysqli_prepare($conn, "SELECT COUNT(*) n, COALESCE(SUM(true_total),0) spent FROM journals WHERE eml=?");
mysqli_stmt_bind_param($q,'s',$emll); mysqli_stmt_execute($q);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
function v($x){ return htmlspecialchars($x ?? ''); }
require_once __DIR__ . '/ja-auth.php';
$ja_css_extra = ['pages-account.css'];
$myHandle = ja_ensure_handle($emll);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct-page">

<main class="acct-page">
  <header class="acct-head">
    <h1>Your profile</h1>
    <p>Your details, travel preferences and data.</p>
    <dl class="acct-figs">
      <div><dt>Journal entries</dt><dd><?= (int)($stats['n']??0) ?></dd></div>
      <div><dt>Total logged</dt><dd>&#8377;<?= number_format((float)($stats['spent']??0)) ?></dd></div>
      <div><a class="acct-link" href="my-entries.php">Open My journal</a></div>
    </dl>
  </header>

  <?php if ($flash): ?><div class="ja-ok" role="status"><?= ja_icon('check',16) ?> <?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ja-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <section class="acct-sec" aria-labelledby="s-details">
    <div class="acct-sec-h"><h2 id="s-details">Personal details</h2><p>Your name appears on journals you share. Your email is never shown publicly.</p></div>
    <form method="post" class="acct-sec-b" data-acct-validate novalidate>
      <?= ja_csrf_field() ?>
      <div class="ja-fieldrow">
        <?= ja_field(['id' => 'fname', 'name' => 'fname', 'label' => 'First name', 'value' => $res['fname'] ?? '', 'autocomplete' => 'given-name', 'required' => 1, 'rule' => 'required', 'label_text' => 'first name']) ?>
        <?= ja_field(['id' => 'lname', 'name' => 'lname', 'label' => 'Last name', 'value' => $res['lname'] ?? '', 'autocomplete' => 'family-name']) ?>
      </div>
      <?= ja_field(['id' => 'email', 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $res['eml'] ?? '', 'autocomplete' => 'email', 'required' => 1, 'rule' => 'email', 'label_text' => 'email address']) ?>
      <?= ja_field(['id' => 'bio', 'name' => 'bio', 'label' => 'Short bio', 'value' => $res['bio'] ?? '', 'maxlength' => 240, 'placeholder' => 'e.g. Weekend wanderer. Chasing sunsets and street food.', 'hint' => 'Shown on your public profile. Up to 240 characters.']) ?>
      <?php if ($myHandle): ?><p class="acct-hint acct-handle">Your public profile: <a href="traveller.php?h=<?= urlencode($myHandle) ?>">traveller.php?h=<?= htmlspecialchars($myHandle) ?></a></p><?php endif; ?>
      <button class="ja-btn ja-btn-primary" type="submit" name="Save">Save changes</button>
    </form>
  </section>

  <section class="acct-sec" aria-labelledby="s-prefs">
    <div class="acct-sec-h"><h2 id="s-prefs">Travel preferences</h2><p>Plan a trip starts from these, and your picks on Home follow them.</p></div>
    <form method="post" class="acct-sec-b">
      <?= ja_csrf_field() ?>
      <div class="ja-fieldrow">
        <div class="ja-field acct-field"><label for="p-home">Home city</label><input class="ja-input" id="p-home" name="home_city" value="<?= v($prefs['home_city']) ?>" placeholder="e.g. Ahmedabad" autocomplete="address-level2"></div>
        <div class="ja-field acct-field"><label for="p-party">Usual travellers</label><input class="ja-input" id="p-party" type="number" name="party_size" min="1" max="20" value="<?= (int)$prefs['party_size'] ?>"></div>
      </div>
      <div class="ja-fieldrow">
        <div class="ja-field acct-field"><label for="p-budget">Typical budget (&#8377;)</label><input class="ja-input" id="p-budget" type="number" name="default_budget" min="1000" step="1000" value="<?= (int)$prefs['default_budget'] ?>"></div>
        <div class="ja-field acct-field"><label for="p-style">Travel style</label>
          <select class="ja-select" id="p-style" name="travel_style"><?php foreach ($STYLES as $s): ?><option value="<?= $s ?>" <?= $prefs['travel_style'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      </div>
      <fieldset class="acct-interests">
        <legend>What you love</legend>
        <div class="ja-pickchips">
          <?php foreach ($INTERESTS as $it): ?>
            <label class="ja-pick"><input type="checkbox" name="interests[]" value="<?= $it ?>" <?= in_array($it, $prefs['interests'], true) ? 'checked' : '' ?>><span><?= $it ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <label class="acct-check"><input type="checkbox" name="notify_email" value="1" <?= $prefs['notify_email'] ? 'checked' : '' ?>> <span>Email me about account activity</span></label>
      <button class="ja-btn ja-btn-primary" type="submit" name="SavePrefs">Save preferences</button>
    </form>
  </section>

  <section class="acct-sec" aria-labelledby="s-sec">
    <div class="acct-sec-h"><h2 id="s-sec">Security</h2><p>Your password is stored hashed, never as plain text.</p></div>
    <div class="acct-sec-b acct-actions">
      <a href="change-pswd.php" class="ja-btn ja-btn-ghost"><?= ja_icon('user',16) ?> Change password</a>
      <?= ja_logout_form('class="ja-btn ja-btn-ghost"', ja_icon('logout',16) . ' Log out') ?>
    </div>
  </section>

  <section class="acct-sec" aria-labelledby="s-data">
    <div class="acct-sec-h"><h2 id="s-data">Your data</h2><p>Take a copy of everything you have saved, or remove your account.</p></div>
    <div class="acct-sec-b">
      <p class="acct-actions"><a href="profile.php?export=1" class="ja-btn ja-btn-ghost">Download my data (JSON)</a></p>
      <details class="acct-danger">
        <summary>Delete my account</summary>
        <form method="post" onsubmit="return confirm('Permanently delete your account and everything in it?')">
          <?= ja_csrf_field() ?>
          <p>This removes your journals, photos, plans and posts. It can't be undone.</p>
          <div class="ja-field acct-field"><label for="del-pw">Your password</label><input class="ja-input" id="del-pw" type="password" name="confirm_pw" autocomplete="current-password" required></div>
          <button class="ja-btn acct-danger-btn" type="submit" name="DeleteAccount">Delete everything</button>
        </form>
      </details>
    </div>
  </section>
</main>

<?php include 'ja-footer.php'; ?>
<script src="js/ja-auth.js?v=<?= (int)@filemtime(__DIR__ . '/js/ja-auth.js') ?>" defer></script>
