<?php
$ja_title = "My Routes"; $ja_active = "routes";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];

// view a single saved route?
$viewId = (int)($_GET['view'] ?? 0);
$viewRoute = null;
if ($viewId) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_routes WHERE route_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $viewId, $eml);
    mysqli_stmt_execute($s);
    $viewRoute = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($conn, "SELECT route_id, title, origin, mode, total_km, created_at FROM saved_routes WHERE eml=? ORDER BY route_id DESC");
mysqli_stmt_bind_param($s, 's', $eml);
mysqli_stmt_execute($s);
$routes = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
mysqli_stmt_close($s);
require_once 'ja-icons.php';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('compass',14) ?> Your trips</div>
    <h1>My Routes</h1>
    <p class="sub"><?= count($routes) ?> saved route<?= count($routes)===1?'':'s' ?>.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <?php if ($viewRoute): $data = json_decode($viewRoute['data'], true); ?>
      <a href="my-routes.php" style="color:var(--ja-teal);font-weight:600">← All routes</a>
      <h2 style="margin:12px 0 4px"><?= htmlspecialchars($viewRoute['title']) ?></h2>
      <p class="sub"><?= (int)$viewRoute['total_km'] ?> km · saved <?= htmlspecialchars(date('M j, Y', strtotime($viewRoute['created_at']))) ?></p>
      <div class="ja-route-line" style="margin-top:20px">
        <div class="ja-route-stop origin"><div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
          <div class="ja-route-card"><div class="ja-route-info"><div class="ja-route-name">Start · <?= htmlspecialchars(explode(',',$data['origin']['display'] ?? $viewRoute['origin'])[0]) ?></div></div></div></div>
        <?php foreach (($data['route'] ?? []) as $i=>$st): ?>
          <div class="ja-route-stop"><div class="ja-route-dot"><?= $i+1 ?></div>
            <div class="ja-route-card"><div class="ja-route-info">
              <div class="ja-route-name"><?= htmlspecialchars($st['name']) ?></div>
              <div class="ja-route-sub"><?= isset($st['leg_km'])? $st['leg_km'].' km':'' ?><?= !empty($st['do'])? ' · '.htmlspecialchars($st['do']):'' ?></div>
              <?php if (!empty($st['lat'])): ?><a class="ja-route-map" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?= $st['lat'] ?>,<?= $st['lon'] ?>"><?= ja_icon('map-pin',13) ?> Google Maps</a><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="save-route.php" onsubmit="return confirm('Delete this route?')" style="margin-top:20px">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="route_id" value="<?= (int)$viewRoute['route_id'] ?>">
        <button class="ja-btn ja-btn-ghost" style="color:var(--ja-coral)" onclick="fetch('save-route.php',{method:'POST',body:new URLSearchParams({action:'delete',route_id:<?= (int)$viewRoute['route_id'] ?>})}).then(()=>location.href='my-routes.php');return false;"><?= ja_icon('trash',15) ?> Delete route</button>
      </form>

    <?php elseif (!$routes): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="color:var(--ja-aqua);margin-bottom:12px"><?= ja_icon('compass',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">No saved routes yet.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Build a route</a>
      </div>
    <?php else: ?>
      <div class="ja-grid cols-3">
        <?php foreach ($routes as $rt): ?>
          <div class="ja-card" data-tilt>
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px">
              <h3 style="font-size:1.15rem;margin-bottom:2px"><?= htmlspecialchars($rt['title']) ?></h3>
              <span class="ja-pill near"><?= (int)$rt['total_km'] ?>km</span>
            </div>
            <div style="color:var(--text-mut);font-size:.85rem;margin-bottom:14px">
              from <?= htmlspecialchars(explode(',',$rt['origin'])[0]) ?> · <?= htmlspecialchars($rt['mode']) ?> · <?= htmlspecialchars(date('M j', strtotime($rt['created_at']))) ?>
            </div>
            <div style="display:flex;gap:8px">
              <a href="my-routes.php?view=<?= (int)$rt['route_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem">Open</a>
              <button class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem;color:var(--ja-coral)"
                onclick="if(confirm('Delete?')){fetch('save-route.php',{method:'POST',body:new URLSearchParams({action:'delete',route_id:<?= (int)$rt['route_id'] ?>})}).then(()=>location.reload());}">Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
