<?php
/**
 * JourneyAI — Packing list builder.
 *   packing.php            your lists + "build a new list"
 *   packing.php?list=ID    one checklist (tick, add, edit quantities, print)
 *   packing.php?plan=ID    open (or create) the list for a saved plan
 *   packing.php?dest=..&days=..   pre-fills the builder (e.g. from a trip page)
 */
$ja_title = "Packing lists"; $ja_active = "plans"; $ja_tab = "packing";
session_start();
require 'connection.php';
require_once 'ja-lib.php';
require_once 'ja-packing-engine.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$STYLES = ['budget', 'mid-range', 'luxury', 'adventure', 'family', 'solo', 'backpacker'];
$MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$err = '';

/** Create a list + fill it from the recommender. Returns the new list_id. */
function ja_pack_create($conn, $em, $name, $dest, $days, $month, $style, $party, $opts, $planId = null) {
    $region = ''; $intl = false;
    if ($dest !== '') {
        $s = mysqli_prepare($conn, "SELECT region, country FROM destinations WHERE canonical_name = ? OR city = ? LIMIT 1");
        $city = trim(explode(',', $dest)[0]);
        mysqli_stmt_bind_param($s, 'ss', $dest, $city); mysqli_stmt_execute($s);
        if ($d = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { $region = $d['region']; $intl = $d['country'] !== 'India'; }
        mysqli_stmt_close($s);
    }
    $optStr = implode(',', $opts);
    $s = mysqli_prepare($conn, "INSERT INTO packing_lists (eml, plan_id, name, destination, days, month, style, party, options) VALUES (?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($s, 'sissiisis', $em, $planId, $name, $dest, $days, $month, $style, $party, $optStr);
    mysqli_stmt_execute($s); $id = mysqli_insert_id($conn); mysqli_stmt_close($s);
    $rec = ja_packing_recommend(['destination' => $dest, 'days' => $days, 'month' => $month, 'style' => $style, 'party' => $party,
        'options' => $opts, 'region' => $region, 'international' => $intl]);
    $ins = mysqli_prepare($conn, "INSERT INTO packing_list_items (list_id, category, label, qty, why, source, sort_order) VALUES (?,?,?,?,?, 'suggested', ?)");
    foreach ($rec as $i => $it) { mysqli_stmt_bind_param($ins, 'issisi', $id, $it['category'], $it['label'], $it['qty'], $it['why'], $i); mysqli_stmt_execute($ins); }
    mysqli_stmt_close($ins);
    return $id;
}

/* ---- open the list that belongs to a saved plan (create it from the plan on first use) ---- */
if (isset($_GET['plan'])) {
    $pid = (int)$_GET['plan'];
    $s = mysqli_prepare($conn, "SELECT list_id FROM packing_lists WHERE plan_id = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'is', $pid, $em); mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    if (!$row) {
        $s = mysqli_prepare($conn, "SELECT destination, days, travel_style, month, party_size FROM saved_plans WHERE plan_id = ? AND eml = ?");
        mysqli_stmt_bind_param($s, 'is', $pid, $em); mysqli_stmt_execute($s);
        $p = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
        if ($p) {
            $lid = ja_pack_create($conn, $em, 'Packing for ' . explode(',', $p['destination'])[0], $p['destination'], (int)$p['days'], (int)$p['month'] ?: null, $p['travel_style'] ?: 'mid-range', (int)$p['party_size'] ?: 1, [], $pid);
            // keep anything already ticked/added on the old per-plan checklist
            $old = mysqli_query($conn, "SELECT label, category, is_checked FROM packing_items WHERE plan_id = $pid");
            $ins = mysqli_prepare($conn, "INSERT INTO packing_list_items (list_id, category, label, source, is_checked, sort_order) SELECT ?, ?, ?, 'custom', ?, 900 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM packing_list_items WHERE list_id = ? AND LOWER(label) = LOWER(?))");
            while ($old && ($o = mysqli_fetch_assoc($old))) {
                $cat = array_key_exists($o['category'], JA_PACK_CATEGORIES) ? $o['category'] : 'general';
                $chk = (int)$o['is_checked'];
                mysqli_stmt_bind_param($ins, 'issiis', $lid, $cat, $o['label'], $chk, $lid, $o['label']); mysqli_stmt_execute($ins);
            }
            mysqli_stmt_close($ins);
            header('Location: packing.php?list=' . $lid); exit;
        }
        header('Location: packing.php'); exit;
    }
    header('Location: packing.php?list=' . (int)$row['list_id']); exit;
}

/* ---- create from the builder form ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'create') {
    if (!ja_csrf_ok()) { $err = 'Session expired. Reload and try again.'; }
    elseif (!ja_rate_limit('packing:' . strtolower($em), 30, 3600)) { $err = 'That is a lot of lists. Please try again later.'; }
    else {
        $dest = mb_substr(trim($_POST['destination'] ?? ''), 0, 160);
        $days = max(1, min(60, (int)($_POST['days'] ?? 4)));
        $month = (int)($_POST['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
        $style = in_array($_POST['style'] ?? '', $STYLES, true) ? $_POST['style'] : 'mid-range';
        $party = max(1, min(30, (int)($_POST['party'] ?? 1)));
        $opts = array_values(array_intersect((array)($_POST['options'] ?? []), array_keys(JA_PACK_OPTIONS)));
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120) ?: ('Packing for ' . ($dest !== '' ? explode(',', $dest)[0] : 'my trip'));
        $lid = ja_pack_create($conn, $em, $name, $dest, $days, $month, $style, $party, $opts);
        header('Location: packing.php?list=' . $lid); exit;
    }
}

$listId = (int)($_GET['list'] ?? 0);
$list = null; $items = [];
if ($listId) {
    $s = mysqli_prepare($conn, "SELECT * FROM packing_lists WHERE list_id = ? AND eml = ?");
    mysqli_stmt_bind_param($s, 'is', $listId, $em); mysqli_stmt_execute($s);
    $list = mysqli_fetch_assoc(mysqli_stmt_get_result($s)); mysqli_stmt_close($s);
    if (!$list) { header('Location: packing.php'); exit; }
    $s = mysqli_prepare($conn, "SELECT * FROM packing_list_items WHERE list_id = ? ORDER BY sort_order, item_id");
    mysqli_stmt_bind_param($s, 'i', $listId); mysqli_stmt_execute($s);
    $items = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
}

$lists = [];
if (!$list) {
    $s = mysqli_prepare($conn, "SELECT l.*, (SELECT COUNT(*) FROM packing_list_items i WHERE i.list_id = l.list_id) total,
        (SELECT COALESCE(SUM(is_checked),0) FROM packing_list_items i WHERE i.list_id = l.list_id) packed
        FROM packing_lists l WHERE l.eml = ? ORDER BY l.created_at DESC");
    mysqli_stmt_bind_param($s, 's', $em); mysqli_stmt_execute($s);
    $lists = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
}
$dests = [];
$r = mysqli_query($conn, "SELECT canonical_name FROM destinations ORDER BY canonical_name");
while ($r && ($row = mysqli_fetch_row($r))) $dests[] = $row[0];
$prefs = ja_user_prefs($em);
$preDest = trim($_GET['dest'] ?? ''); $preDays = max(1, min(60, (int)($_GET['days'] ?? 4)));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css"></head>
<body class="ja">
<div class="ja-pagehead"><div class="ja-container">
  <div class="ja-eyebrow"><?= ja_icon('check',14) ?> Never forget the charger</div>
  <h1>My Trips</h1>
  <?php include 'ja-trips-tabs.php'; ?>
</div></div>

<main class="ja-main"><div class="ja-container" style="max-width:980px">
<?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($list): /* ================= one checklist ================= */
  $cats = [];
  foreach ($items as $it) $cats[$it['category']][] = $it;
  $order = array_keys(JA_PACK_CATEGORIES);
  uksort($cats, function ($a, $b) use ($order) { return array_search($a, $order) <=> array_search($b, $order); });
  $packed = count(array_filter($items, function ($i) { return $i['is_checked']; }));
?>
  <a href="packing.php" class="ja-muted" style="font-size:.85rem">&larr; All packing lists</a>
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:8px 0 14px">
    <div>
      <h2 id="listName" style="margin:0;font-size:1.7rem"><?= htmlspecialchars($list['name']) ?></h2>
      <div class="ja-muted" style="font-size:.88rem"><?= htmlspecialchars($list['destination'] ?: 'Any destination') ?> &middot; <?= (int)$list['days'] ?> days<?= $list['month'] ? ' &middot; ' . $MONTHS[(int)$list['month']] : '' ?> &middot; <?= htmlspecialchars($list['style']) ?> &middot; <?= (int)$list['party'] ?> traveller<?= $list['party'] > 1 ? 's' : '' ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap" class="no-print">
      <button class="ja-btn ja-btn-ghost" id="btnRegen" title="Add any newly recommended items. Your ticks and extra items are kept">Refresh suggestions</button>
      <button class="ja-btn ja-btn-ghost" id="btnUncheck">Untick all</button>
      <button class="ja-btn ja-btn-ghost" onclick="window.print()">Print</button>
      <button class="ja-btn ja-btn-ghost" id="btnDup">Duplicate</button>
      <button class="ja-btn ja-btn-ghost" id="btnDel" style="color:var(--ja-coral)">Delete</button>
    </div>
  </div>

  <div class="ja-card" style="position:sticky;top:8px;z-index:5;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;font-weight:600"><span id="progText"><?= $packed ?> of <?= count($items) ?> packed</span><span id="progPct"><?= count($items) ? round($packed * 100 / count($items)) : 0 ?>%</span></div>
    <div class="ja-progress-bar"><span id="progFill" style="width:<?= count($items) ? round($packed * 100 / count($items)) : 0 ?>%"></span></div>
  </div>

  <form id="addForm" class="ja-card no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
    <input class="ja-input" name="label" placeholder="Add your own item (e.g. yoga mat)" style="flex:2;min-width:200px" maxlength="160" required>
    <input class="ja-input" name="qty" type="number" min="1" max="99" value="1" style="width:80px">
    <select class="ja-select" name="category" style="flex:1;min-width:150px"><?php foreach (JA_PACK_CATEGORIES as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'extras' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    <button class="ja-btn ja-btn-primary" type="submit">Add</button>
  </form>

  <div id="cats">
  <?php foreach ($cats as $ck => $rows): $done = count(array_filter($rows, function ($i) { return $i['is_checked']; })); ?>
    <section class="ja-pack-cat" data-cat="<?= htmlspecialchars($ck) ?>">
      <h3><?= htmlspecialchars(JA_PACK_CATEGORIES[$ck] ?? 'Other') ?> <span class="ja-muted cnt"><?= $done ?>/<?= count($rows) ?></span></h3>
      <ul class="ja-pack-list">
      <?php foreach ($rows as $it): ?>
        <li data-item="<?= (int)$it['item_id'] ?>" class="<?= $it['is_checked'] ? 'done' : '' ?>">
          <label><input type="checkbox" <?= $it['is_checked'] ? 'checked' : '' ?>> <span class="lbl"><?= htmlspecialchars($it['label']) ?></span></label>
          <input class="qty no-print" type="number" min="1" max="99" value="<?= (int)$it['qty'] ?>" title="Quantity">
          <span class="qty-print"><?= (int)$it['qty'] > 1 ? '&times;' . (int)$it['qty'] : '' ?></span>
          <?php if ($it['why']): ?><span class="why no-print" title="<?= htmlspecialchars($it['why']) ?>">why?</span><?php endif; ?>
          <button class="del no-print" title="Remove" type="button">&times;</button>
        </li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
  </div>
  <script>
  (function(){
    var LIST = <?= $listId ?>, CSRF = <?= json_encode(ja_csrf()) ?>;
    var CATS = <?= json_encode(JA_PACK_CATEGORIES) ?>;
    function api(data){ var fd=new FormData(); Object.keys(data).forEach(function(k){fd.append(k,data[k]);}); fd.append('csrf',CSRF); return fetch('packing-api.php',{method:'POST',body:fd}).then(function(r){return r.json();}); }
    function prog(p){ if(p.total===undefined)return; var pct=p.total?Math.round(p.packed*100/p.total):0; document.getElementById('progText').textContent=p.packed+' of '+p.total+' packed'; document.getElementById('progPct').textContent=pct+'%'; document.getElementById('progFill').style.width=pct+'%'; }
    function catCount(sec){ var li=sec.querySelectorAll('li'); var d=sec.querySelectorAll('li.done').length; sec.querySelector('.cnt').textContent=d+'/'+li.length; if(!li.length) sec.remove(); }
    document.getElementById('cats').addEventListener('change',function(e){
      var li=e.target.closest('li'); if(!li||e.target.type!=='checkbox')return;
      li.classList.toggle('done',e.target.checked);
      api({action:'toggle',item_id:li.dataset.item,checked:e.target.checked?1:0}).then(prog); catCount(li.closest('.ja-pack-cat'));
    });
    document.getElementById('cats').addEventListener('click',function(e){
      if(!e.target.classList.contains('del'))return; var li=e.target.closest('li'), sec=li.closest('.ja-pack-cat');
      api({action:'delete',item_id:li.dataset.item}).then(function(r){ if(r.ok){ li.remove(); prog(r); catCount(sec);} });
    });
    document.getElementById('cats').addEventListener('input',function(e){
      if(!e.target.classList.contains('qty'))return; var li=e.target.closest('li'); clearTimeout(li._t);
      li._t=setTimeout(function(){ api({action:'qty',item_id:li.dataset.item,qty:e.target.value}); },400);
    });
    document.getElementById('addForm').addEventListener('submit',function(e){
      e.preventDefault(); var f=e.target; api({action:'add',list_id:LIST,label:f.label.value,qty:f.qty.value,category:f.category.value}).then(function(r){
        if(r.error){alert(r.error);return;} var it=r.item, sec=document.querySelector('.ja-pack-cat[data-cat="'+it.category+'"]');
        if(!sec){ sec=document.createElement('section'); sec.className='ja-pack-cat'; sec.dataset.cat=it.category; sec.innerHTML='<h3></h3><ul class="ja-pack-list"></ul>'; sec.querySelector('h3').innerHTML=(CATS[it.category]||'Other')+' <span class="ja-muted cnt">0/0</span>'; document.getElementById('cats').appendChild(sec); }
        var li=document.createElement('li'); li.dataset.item=it.item_id; li.innerHTML='<label><input type="checkbox"> <span class="lbl"></span></label><input class="qty no-print" type="number" min="1" max="99" value="'+it.qty+'"><span class="qty-print"></span><button class="del no-print" type="button">&times;</button>'; li.querySelector('.lbl').textContent=it.label;
        sec.querySelector('ul').appendChild(li); catCount(sec); prog(r); f.label.value=''; f.label.focus();
      });
    });
    document.getElementById('btnRegen').onclick=function(){ api({action:'regenerate',list_id:LIST}).then(function(r){ if(r.ok){ alert(r.added? r.added+' new suggestion'+(r.added>1?'s':'')+' added.' : 'Nothing new to suggest. Your list is up to date.'); if(r.added) location.reload(); } }); };
    document.getElementById('btnUncheck').onclick=function(){ api({action:'uncheck_all',list_id:LIST}).then(function(){ location.reload(); }); };
    document.getElementById('btnDup').onclick=function(){ api({action:'duplicate',list_id:LIST}).then(function(r){ if(r.list_id) location.href='packing.php?list='+r.list_id; }); };
    document.getElementById('btnDel').onclick=function(){ if(confirm('Delete this packing list?')) api({action:'delete_list',list_id:LIST}).then(function(){ location.href='packing.php'; }); };
    document.getElementById('listName').addEventListener('dblclick',function(){ var n=prompt('Rename list',this.textContent); if(n) api({action:'rename',list_id:LIST,name:n}).then(function(r){ if(r.ok) document.getElementById('listName').textContent=r.name; }); });
  })();
  </script>

<?php else: /* ================= overview + builder ================= */ ?>
  <div class="ja-two-col" style="align-items:start">
    <div class="ja-card">
      <h3 style="margin-top:0">Build a packing list</h3>
      <p class="ja-muted" style="margin:0 0 12px;font-size:.9rem">Tell us about the trip. You get a list sized to your days, the season at the destination and what you'll be doing, with a reason for each item.</p>
      <form method="post">
        <?= ja_csrf_field() ?><input type="hidden" name="do" value="create">
        <div class="ja-field"><label>Destination</label><input class="ja-input" name="destination" list="destList" value="<?= htmlspecialchars($preDest) ?>" placeholder="e.g. Manali, India (optional)" autocomplete="off">
          <datalist id="destList"><?php foreach ($dests as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?php endforeach; ?></datalist></div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Days</label><input class="ja-input" type="number" name="days" min="1" max="60" value="<?= $preDays ?>"></div>
          <div class="ja-field"><label>Month</label><select class="ja-select" name="month"><option value="0">Any</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= $MONTHS[$m] ?></option><?php endfor; ?></select></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Travellers</label><input class="ja-input" type="number" name="party" min="1" max="30" value="<?= (int)$prefs['party_size'] ?>"></div>
          <div class="ja-field"><label>Style</label><select class="ja-select" name="style"><?php foreach ($STYLES as $s): ?><option value="<?= $s ?>" <?= $prefs['travel_style'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="ja-field"><label>What will you do? <span class="ja-muted">(we also guess from the destination)</span></label>
          <div class="ja-pickchips"><?php foreach (JA_PACK_OPTIONS as $k => $v): ?><label class="ja-pick"><input type="checkbox" name="options[]" value="<?= $k ?>"><span><?= htmlspecialchars($v) ?></span></label><?php endforeach; ?></div></div>
        <div class="ja-field"><label>List name <span class="ja-muted">(optional)</span></label><input class="ja-input" name="name" maxlength="120"></div>
        <button class="ja-btn ja-btn-primary ja-btn-lg" type="submit">Build my list</button>
      </form>
    </div>
    <div>
      <h3 style="margin-top:0">Your lists (<?= count($lists) ?>)</h3>
      <?php if (!$lists): ?><div class="ja-empty" style="padding:30px"><p style="margin:0">No lists yet. Build your first one.</p></div><?php endif; ?>
      <?php foreach ($lists as $l): $pct = $l['total'] ? round($l['packed'] * 100 / $l['total']) : 0; ?>
        <a class="ja-card" href="packing.php?list=<?= (int)$l['list_id'] ?>" style="display:block;text-decoration:none;color:inherit;margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;gap:10px"><strong><?= htmlspecialchars($l['name']) ?></strong><span class="ja-pill <?= $pct == 100 ? 'near' : 'season' ?>"><?= $pct ?>%</span></div>
          <div class="ja-muted" style="font-size:.84rem"><?= htmlspecialchars($l['destination'] ?: 'Any destination') ?> &middot; <?= (int)$l['days'] ?> days &middot; <?= (int)$l['packed'] ?>/<?= (int)$l['total'] ?> packed</div>
          <div class="ja-progress-bar" style="margin-top:8px"><span style="width:<?= $pct ?>%"></span></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div></main>
<?php include 'ja-footer.php'; ?>
