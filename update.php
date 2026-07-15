<?php
$ja_title = "Edit Entry"; $ja_active = "entries";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = mysqli_prepare($conn,
      "UPDATE db SET Title=?, Description=?, Country=?, City=?, hn=?, address=?, ptv=?, tv=?
       WHERE entry_id=? AND eml=?");
    $vals = [
      trim($_POST['title'] ?? ''), $_POST['desc'] ?? '', $_POST['coun'] ?? '',
      $_POST['city'] ?? '', $_POST['hn'] ?? '', $_POST['ad'] ?? '',
      $_POST['ptv'] ?? '', $_POST['tv'] ?? '',
    ];
    mysqli_stmt_bind_param($stmt, 'ssssssssis', $vals[0],$vals[1],$vals[2],$vals[3],$vals[4],$vals[5],$vals[6],$vals[7], $id, $em);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $saved = true;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM db WHERE entry_id = ? AND eml = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'is', $id, $em);
mysqli_stmt_execute($stmt);
$e = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$e) { header('Location: view-list.php'); exit; }
function v($x){ return htmlspecialchars($x ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<section class="ja-section" style="padding-top:56px">
  <div class="ja-container" style="max-width:760px">
    <a href="display.php?id=<?= $id ?>" style="color:var(--ja-teal);font-weight:600">← Back to entry</a>
    <h1 class="reveal" style="font-size:clamp(2rem,5vw,3rem);margin-top:12px">Edit Entry</h1>
    <?php if ($saved): ?><div class="ja-ok reveal">✓ Changes saved.</div><?php endif; ?>
    <form method="post" class="ja-card reveal">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="ja-field"><label>Title</label><input class="ja-input" name="title" value="<?= v($e['Title']) ?>" required></div>
      <div class="ja-field"><label>Description</label><textarea class="ja-input" name="desc" rows="5"><?= v($e['Description']) ?></textarea></div>
      <div style="display:grid;gap:18px;grid-template-columns:1fr 1fr">
        <div class="ja-field"><label>Country</label><input class="ja-input" name="coun" value="<?= v($e['Country']) ?>"></div>
        <div class="ja-field"><label>City</label><input class="ja-input" name="city" value="<?= v($e['City']) ?>"></div>
      </div>
      <div class="ja-field"><label>Hotel / stay</label><input class="ja-input" name="hn" value="<?= v($e['hn']) ?>"></div>
      <div class="ja-field"><label>Address</label><input class="ja-input" name="ad" value="<?= v($e['address']) ?>"></div>
      <div class="ja-field"><label>Places to visit</label><textarea class="ja-input" name="ptv" rows="2"><?= v($e['ptv']) ?></textarea></div>
      <div class="ja-field"><label>Travel mode</label><input class="ja-input" name="tv" value="<?= v($e['tv']) ?>"></div>
      <button class="ja-btn ja-btn-primary" type="submit" data-magnetic>Save changes</button>
    </form>
  </div>
</section>
<footer class="ja-footer"><div class="ja-container">JourneyAI</div></footer>
</body>
</html>
