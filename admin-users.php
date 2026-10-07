<?php
$ja_title = "Users"; $ja_active = "admin"; $ja_tab = "users";
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/mail.php';
$me = ja_require_admin();
$msg = ''; $err = ''; $devLink = '';

function ja_admin_count() {
    global $conn;
    $r = mysqli_query($conn, "SELECT COUNT(*) FROM signup WHERE role='admin' AND is_active=1");
    return (int)mysqli_fetch_row($r)[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = trim($_POST['eml'] ?? '');
    $act = $_POST['act'] ?? '';
    if (!ja_csrf_ok()) {
        $err = 'Session expired. Reload the page and try again.';
    } elseif ($target === '') {
        $err = 'No user selected.';
    } else {
        $isSelf = strcasecmp($target, $me['eml']) === 0;
        $tq = mysqli_prepare($conn, "SELECT role, is_active FROM signup WHERE eml = ?");
        mysqli_stmt_bind_param($tq, 's', $target);
        mysqli_stmt_execute($tq);
        $t = mysqli_fetch_assoc(mysqli_stmt_get_result($tq));
        mysqli_stmt_close($tq);
        if (!$t) {
            $err = 'User not found.';
        } elseif ($act === 'suspend' || $act === 'activate') {
            if ($isSelf) { $err = "You can't suspend your own account."; }
            elseif ($act === 'suspend' && $t['role'] === 'admin' && ja_admin_count() <= 1) { $err = "Can't suspend the last admin."; }
            else {
                $v = $act === 'activate' ? 1 : 0;
                $u = mysqli_prepare($conn, "UPDATE signup SET is_active = ? WHERE eml = ?");
                mysqli_stmt_bind_param($u, 'is', $v, $target); mysqli_stmt_execute($u); mysqli_stmt_close($u);
                $msg = $v ? "Reactivated $target." : "Suspended $target. They are signed out on their next click.";
            }
        } elseif ($act === 'make_admin' || $act === 'remove_admin') {
            if ($act === 'remove_admin' && ($isSelf || ja_admin_count() <= 1)) { $err = "Can't remove the last admin (or yourself)."; }
            else {
                $role = $act === 'make_admin' ? 'admin' : 'user';
                $u = mysqli_prepare($conn, "UPDATE signup SET role = ? WHERE eml = ?");
                mysqli_stmt_bind_param($u, 'ss', $role, $target); mysqli_stmt_execute($u); mysqli_stmt_close($u);
                $msg = $role === 'admin' ? "$target is now an admin." : "$target is now a regular user.";
            }
        } elseif ($act === 'reset_link') {
            $token = bin2hex(random_bytes(32));
            $exp = date('Y-m-d H:i:s', time() + 3600);
            $u = mysqli_prepare($conn, "UPDATE signup SET reset_token = ?, reset_expires = ? WHERE eml = ?");
            mysqli_stmt_bind_param($u, 'sss', $token, $exp, $target); mysqli_stmt_execute($u); mysqli_stmt_close($u);
            $res = send_reset_email($target, $token);
            if ($res['ok']) { $msg = "Reset link emailed to $target."; }
            else { $msg = "Email not sent ({$res['error']}). Share this one-time link with the user yourself (valid 1 hour):"; $devLink = $res['url']; }
        } elseif ($act === 'delete') {
            if ($isSelf) { $err = "You can't delete your own account here. Use Profile."; }
            elseif ($t['role'] === 'admin' && ja_admin_count() <= 1) { $err = "Can't delete the last admin."; }
            else {
                ja_delete_user_data($target);
                $msg = "Deleted $target and their journals, plans and routes.";
            }
        }
    }
}

$q = trim($_GET['q'] ?? '');
$show = $_GET['show'] ?? 'real';
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 20; $off = ($page - 1) * $per;
$where = ["1=1"]; $params = []; $types = '';
if ($show !== 'all') { $where[] = "eml NOT LIKE '%@seed.journeyai' AND eml NOT LIKE '%@real.journeyai' AND eml NOT LIKE '%@demo.journeyai'"; }
if ($q !== '') { $where[] = "(eml LIKE ? OR fname LIKE ? OR lname LIKE ?)"; $like = "%$q%"; $params = [$like, $like, $like]; $types = 'sss'; }
$w = implode(' AND ', $where);
$cs = mysqli_prepare($conn, "SELECT COUNT(*) FROM signup WHERE $w");
if ($params) mysqli_stmt_bind_param($cs, $types, ...$params);
mysqli_stmt_execute($cs); mysqli_stmt_bind_result($cs, $total); mysqli_stmt_fetch($cs); mysqli_stmt_close($cs);
$ls = mysqli_prepare($conn, "SELECT s.fname, s.lname, s.eml, s.role, s.is_active, s.created_at, s.last_login,
        (SELECT COUNT(*) FROM db d WHERE d.eml = s.eml) AS entries
        FROM signup s WHERE " . str_replace(['eml', 'fname', 'lname'], ['s.eml', 's.fname', 's.lname'], $w) . " ORDER BY s.created_at DESC, s.eml LIMIT $per OFFSET $off");
if ($params) mysqli_stmt_bind_param($ls, $types, ...$params);
mysqli_stmt_execute($ls);
$users = mysqli_fetch_all(mysqli_stmt_get_result($ls), MYSQLI_ASSOC);
mysqli_stmt_close($ls);
$pages = max(1, (int)ceil($total / $per));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<?php include 'ja-admin-tabs.php'; ?>
<h1 style="font-size:clamp(1.8rem,4vw,2.4rem);margin:4px 0 14px">Users <span class="ja-muted" style="font-size:1rem">(<?= number_format($total) ?>)</span></h1>

<?php if ($msg): ?><div class="ja-ok"><?= htmlspecialchars($msg) ?><?php if ($devLink): ?><br><input class="ja-input" readonly value="<?= htmlspecialchars($devLink) ?>" onclick="this.select()" style="margin-top:8px"><?php endif; ?></div><?php endif; ?>
<?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="get" class="ja-filterbar">
  <input class="ja-input" name="q" placeholder="Search name or email" value="<?= htmlspecialchars($q) ?>">
  <select class="ja-select" name="show"><option value="real"<?= $show !== 'all' ? ' selected' : '' ?>>Real users</option><option value="all"<?= $show === 'all' ? ' selected' : '' ?>>Include demo/seed accounts</option></select>
  <button class="ja-btn ja-btn-primary">Search</button>
</form>

<div class="ja-card" style="padding:0;overflow-x:auto">
<table class="ja-table ja-table-wide">
  <thead><tr><th>User</th><th>Status</th><th>Entries</th><th>Joined</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $isSelf = strcasecmp($u['eml'], $me['eml']) === 0; ?>
    <tr>
      <td><strong><?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?></strong><br><span class="ja-muted"><?= htmlspecialchars($u['eml']) ?></span></td>
      <td><?= $u['role'] === 'admin' ? '<span class="ja-pill near">admin</span> ' : '' ?><?= $u['is_active'] ? '<span class="ja-pill near">active</span>' : '<span class="ja-pill season">suspended</span>' ?></td>
      <td><?= (int)$u['entries'] ?></td>
      <td class="ja-muted"><?= htmlspecialchars(substr((string)$u['created_at'], 0, 10) ?: 'n/a') ?></td>
      <td class="ja-muted"><?= htmlspecialchars(substr((string)$u['last_login'], 0, 10) ?: 'never') ?></td>
      <td>
        <form method="post" class="ja-rowact" onsubmit="return this.act.value!=='delete' || confirm('Delete this user and ALL their data? This cannot be undone.')">
          <?= ja_csrf_field() ?><input type="hidden" name="eml" value="<?= htmlspecialchars($u['eml']) ?>">
          <select name="act" class="ja-select" onchange="if(this.value)this.form.requestSubmit()">
            <option value="">Actions…</option>
            <?php if (!$isSelf): ?>
              <option value="<?= $u['is_active'] ? 'suspend' : 'activate' ?>"><?= $u['is_active'] ? 'Suspend' : 'Reactivate' ?></option>
            <?php endif; ?>
            <option value="reset_link">Send password reset</option>
            <?php if (!$isSelf): ?>
              <option value="<?= $u['role'] === 'admin' ? 'remove_admin' : 'make_admin' ?>"><?= $u['role'] === 'admin' ? 'Remove admin' : 'Make admin' ?></option>
              <option value="delete">Delete user…</option>
            <?php endif; ?>
          </select>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="6" class="ja-muted" style="padding:28px;text-align:center">No users match.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="ja-tabs" style="margin-top:16px">
  <?php for ($i = 1; $i <= $pages; $i++): ?><a href="?<?= htmlspecialchars(http_build_query(['q' => $q, 'show' => $show, 'p' => $i])) ?>" class="<?= $i === $page ? 'on' : '' ?>"><?= $i ?></a><?php endfor; ?>
</div>
<?php endif; ?>
<?php include 'ja-footer.php'; ?>
