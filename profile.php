<?php
$ja_title = "Profile"; $ja_active = "profile";
include('connection.php');
session_start();
if (empty($_SESSION['eml'])) { header("location:login.php"); exit; }
$emll  = $_SESSION['eml'];
$flash = '';

if (isset($_POST['Save'])) {
    $fn  = trim($_POST['fname'] ?? '');
    $ln  = trim($_POST['lname'] ?? '');
    $eml = trim($_POST['email'] ?? '');
    $run = function($sql, $types, ...$vals) use ($conn) {
        $st = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($st, $types, ...$vals);
        $ok = mysqli_stmt_execute($st); mysqli_stmt_close($st); return $ok;
    };
    $ok = $run("UPDATE signup SET fname=?, lname=?, eml=? WHERE eml=?", 'ssss', $fn, $ln, $eml, $emll);
    $run("UPDATE db  SET eml=? WHERE eml=?", 'ss', $eml, $emll);
    $run("UPDATE db1 SET eml=? WHERE eml=?", 'ss', $eml, $emll);
    $run("UPDATE db2 SET eml=? WHERE eml=?", 'ss', $eml, $emll);
    $run("UPDATE db3 SET eml=? WHERE eml=?", 'ss', $eml, $emll);
    if ($ok) {
        $_SESSION['fname']=$fn; $_SESSION['lname']=$ln; $_SESSION['eml']=$eml; $emll=$eml;
        $flash = 'Profile updated.';
    }
}

$q = mysqli_prepare($conn, "SELECT * FROM signup WHERE eml = ? LIMIT 1");
mysqli_stmt_bind_param($q, 's', $emll); mysqli_stmt_execute($q);
$res = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);

// account stats
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
    <p class="sub">Manage your account details and travel journal.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container" style="max-width:900px">
    <?php if ($flash): ?><div class="ja-ok"><?= ja_icon('check',16) ?> <?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <div style="display:grid;gap:24px;grid-template-columns:1.5fr 1fr;align-items:start">
      <div class="ja-card">
        <h3>Personal details</h3>
        <form method="post">
          <div class="ja-fieldrow">
            <div class="ja-field"><label>First name</label><input class="ja-input" name="fname" value="<?= v($res['fname']??'') ?>"></div>
            <div class="ja-field"><label>Last name</label><input class="ja-input" name="lname" value="<?= v($res['lname']??'') ?>"></div>
          </div>
          <div class="ja-field"><label>Email</label><input class="ja-input" type="email" name="email" value="<?= v($res['eml']??'') ?>"></div>
          <button class="ja-btn ja-btn-primary" type="submit" name="Save" data-magnetic>Save changes</button>
        </form>
      </div>

      <aside>
        <div class="ja-card">
          <h3 style="font-size:1.15rem">Your journal</h3>
          <div style="display:flex;gap:20px;margin-top:8px">
            <div><div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.9rem"><?= (int)($stats['n']??0) ?></div><div class="l">Entries</div></div></div>
            <div><div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.9rem">₹<?= number_format((float)($stats['spent']??0)/1000,0) ?>k</div><div class="l">Total logged</div></div></div>
          </div>
          <a href="my-entries.php" class="ja-btn ja-btn-ghost" style="width:100%;margin-top:16px;padding:10px"><?= ja_icon('book',16) ?> View entries</a>
        </div>
        <div class="ja-card" style="margin-top:20px">
          <h3 style="font-size:1.15rem">Security</h3>
          <p style="color:var(--text-mut);font-size:.9rem;margin:6px 0 14px">Your password is securely hashed.</p>
          <a href="change-pswd.php" class="ja-btn ja-btn-ghost" style="width:100%;padding:10px"><?= ja_icon('user',16) ?> Change password</a>
          <a href="login.php?logout=1" class="ja-btn ja-btn-ghost" style="width:100%;padding:10px;margin-top:10px;color:var(--ja-coral)"><?= ja_icon('logout',16) ?> Log out</a>
        </div>
      </aside>
    </div>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
