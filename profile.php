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
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">✦ Account</div>
    <h1>Your Profile</h1>
    <p class="sub">Your details, travel preferences and data.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container" style="max-width:980px">
    <?php if ($flash): ?><div class="ja-ok"><?= ja_icon('check',16) ?> <?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div style="display:grid;gap:24px;grid-template-columns:1.5fr 1fr;align-items:start" class="ja-profile-grid">
      <div>
        <div class="ja-card">
          <h3>Personal details</h3>
          <form method="post">
            <?= ja_csrf_field() ?>
            <div class="ja-fieldrow">
              <div class="ja-field"><label>First name</label><input class="ja-input" name="fname" value="<?= v($res['fname']??'') ?>" required></div>
              <div class="ja-field"><label>Last name</label><input class="ja-input" name="lname" value="<?= v($res['lname']??'') ?>"></div>
            </div>
            <div class="ja-field"><label>Email <span class="ja-muted">(never shown publicly)</span></label><input class="ja-input" type="email" name="email" value="<?= v($res['eml']??'') ?>" required></div>
            <div class="ja-field"><label>Short bio <span class="ja-muted">(shown on your public profile)</span></label><input class="ja-input" name="bio" maxlength="240" value="<?= v($res['bio']??'') ?>" placeholder="e.g. Weekend wanderer. Chasing sunsets and street food."></div>
            <?php $myHandle = ja_ensure_handle($emll); if ($myHandle): ?><p class="ja-muted" style="margin:0 0 12px;font-size:.88rem">Your public profile: <a href="traveller.php?h=<?= urlencode($myHandle) ?>" style="color:var(--ja-teal)">traveller.php?h=<?= htmlspecialchars($myHandle) ?></a></p><?php endif; ?>
            <button class="ja-btn ja-btn-primary" type="submit" name="Save" data-magnetic>Save changes</button>
          </form>
        </div>

        <div class="ja-card" style="margin-top:20px">
          <h3>Travel preferences</h3>
          <p class="ja-muted" style="margin:0 0 12px;font-size:.9rem">Used to pre-fill Plan a Trip and personalise your recommendations on Home.</p>
          <form method="post">
            <?= ja_csrf_field() ?>
            <div class="ja-fieldrow">
              <div class="ja-field"><label>Home city</label><input class="ja-input" name="home_city" value="<?= v($prefs['home_city']) ?>" placeholder="e.g. Ahmedabad"></div>
              <div class="ja-field"><label>Usual travellers</label><input class="ja-input" type="number" name="party_size" min="1" max="20" value="<?= (int)$prefs['party_size'] ?>"></div>
            </div>
            <div class="ja-fieldrow">
              <div class="ja-field"><label>Typical budget (&#8377;)</label><input class="ja-input" type="number" name="default_budget" min="1000" step="1000" value="<?= (int)$prefs['default_budget'] ?>"></div>
              <div class="ja-field"><label>Travel style</label>
                <select class="ja-select" name="travel_style"><?php foreach ($STYLES as $s): ?><option value="<?= $s ?>" <?= $prefs['travel_style'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="ja-field"><label>What you love</label>
              <div class="ja-pickchips">
                <?php foreach ($INTERESTS as $it): ?>
                  <label class="ja-pick"><input type="checkbox" name="interests[]" value="<?= $it ?>" <?= in_array($it, $prefs['interests'], true) ? 'checked' : '' ?>><span><?= $it ?></span></label>
                <?php endforeach; ?>
              </div></div>
            <label class="ja-switch" style="margin:10px 0 14px"><input type="checkbox" name="notify_email" value="1" <?= $prefs['notify_email'] ? 'checked' : '' ?>> <span>Email me about account activity</span></label>
            <button class="ja-btn ja-btn-primary" type="submit" name="SavePrefs" data-magnetic>Save preferences</button>
          </form>
        </div>
      </div>

      <aside>
        <div class="ja-card">
          <h3 style="font-size:1.15rem">Your journal</h3>
          <div style="display:flex;gap:20px;margin-top:8px">
            <div><div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.9rem"><?= (int)($stats['n']??0) ?></div><div class="l">Entries</div></div></div>
            <div><div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.9rem">&#8377;<?= number_format((float)($stats['spent']??0)/1000,0) ?>k</div><div class="l">Total logged</div></div></div>
          </div>
          <a href="my-entries.php" class="ja-btn ja-btn-ghost" style="width:100%;margin-top:16px;padding:10px"><?= ja_icon('book',16) ?> Open My Journal</a>
        </div>
        <div class="ja-card" style="margin-top:20px">
          <h3 style="font-size:1.15rem">Security</h3>
          <p style="color:var(--text-mut);font-size:.9rem;margin:6px 0 14px">Your password is securely hashed.</p>
          <a href="change-pswd.php" class="ja-btn ja-btn-ghost" style="width:100%;padding:10px"><?= ja_icon('user',16) ?> Change password</a>
          <a href="login.php?logout=1" class="ja-btn ja-btn-ghost" style="width:100%;padding:10px;margin-top:10px;color:var(--ja-coral)"><?= ja_icon('logout',16) ?> Log out</a>
        </div>
        <div class="ja-card" style="margin-top:20px">
          <h3 style="font-size:1.15rem">Your data</h3>
          <a href="profile.php?export=1" class="ja-btn ja-btn-ghost" style="width:100%;padding:10px;margin-top:8px">Download my data (JSON)</a>
          <details style="margin-top:14px">
            <summary style="cursor:pointer;color:var(--ja-coral);font-weight:600">Delete my account</summary>
            <form method="post" style="margin-top:10px" onsubmit="return confirm('Permanently delete your account and everything in it?')">
              <?= ja_csrf_field() ?>
              <p class="ja-muted" style="font-size:.85rem;margin:0 0 8px">This removes your journals, photos, plans and posts. It can't be undone.</p>
              <input class="ja-input" type="password" name="confirm_pw" placeholder="Enter your password to confirm" required>
              <button class="ja-btn ja-btn-ghost" style="width:100%;margin-top:8px;color:var(--ja-coral)" type="submit" name="DeleteAccount">Delete everything</button>
            </form>
          </details>
        </div>
      </aside>
    </div>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
