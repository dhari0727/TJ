<?php
$ja_title = "New Entry"; $ja_active = "new";
session_start();
require 'connection.php';
require 'ja-media.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$saved = false; $err = '';

function num($k){ return (float)($_POST[$k] ?? 0); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $err = 'Please give your trip a title.';
    } else {
        // core (db) — named INSERT
        $s = mysqli_prepare($conn, "INSERT INTO db(Title,Description,Country,City,cd,dv,dr,hn,address,ptv,tv,budget_target,eml) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $cd = date('Y-m-d');
        $budgetTarget = trim($_POST['budget_target'] ?? '');
        $budgetTargetVal = $budgetTarget === '' ? null : (float)$budgetTarget;
        $b = [$title, $_POST['desc']??'', $_POST['coun']??'', $_POST['city']??'', $cd,
              $_POST['dv']??'', $_POST['dr']??'', $_POST['hn']??'', $_POST['ad']??'',
              $_POST['ptv']??'', $_POST['tv']??'', $em];
        mysqli_stmt_bind_param($s,'sssssssssssds',$b[0],$b[1],$b[2],$b[3],$b[4],$b[5],$b[6],$b[7],$b[8],$b[9],$b[10],$budgetTargetVal,$b[11]);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);

        // db1 — food + transport (positional 16)
        $food = num('breakfast')+num('lunch')+num('dinner')+num('snacks')+num('beverages');
        $trans = num('flight')+num('train')+num('taxi')+num('car')+num('bus');
        $s = mysqli_prepare($conn, "INSERT INTO db1 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $d1 = [num('breakfast'),num('lunch'),num('snacks'),num('dinner'),num('beverages'),
               num('car'),num('taxi'),num('train'),num('flight'),0,0,num('bus'),$food,$trans,$title,$em];
        $t1 = str_repeat('d',14).'ss';
        mysqli_stmt_bind_param($s,$t1,$d1[0],$d1[1],$d1[2],$d1[3],$d1[4],$d1[5],$d1[6],$d1[7],$d1[8],$d1[9],$d1[10],$d1[11],$d1[12],$d1[13],$d1[14],$d1[15]);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);

        // db2 — accommodation & misc (positional 11)
        $s = mysqli_prepare($conn, "INSERT INTO db2 VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $d2 = [num('hotel'),0,0,num('guide'),0,0,0,num('insurance'),0,$title,$em];
        $t2 = str_repeat('d',9).'ss';
        mysqli_stmt_bind_param($s,$t2,$d2[0],$d2[1],$d2[2],$d2[3],$d2[4],$d2[5],$d2[6],$d2[7],$d2[8],$d2[9],$d2[10]);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);

        // db3 — fees + shopping + grand (positional 17)
        $fees = num('activities'); $shop = num('shopping'); $misc = num('misc');
        $grand = $food + $trans + $fees + $shop;
        $s = mysqli_prepare($conn, "INSERT INTO db3 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $d3 = [$fees,0,0,0,0,$shop,0,0,0,0,0,$misc,$fees,$shop,$title,$grand,$em];
        $t3 = str_repeat('d',14).'sds';
        mysqli_stmt_bind_param($s,$t3,$d3[0],$d3[1],$d3[2],$d3[3],$d3[4],$d3[5],$d3[6],$d3[7],$d3[8],$d3[9],$d3[10],$d3[11],$d3[12],$d3[13],$d3[14],$d3[15],$d3[16]);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);

        $newId = mysqli_insert_id($conn);

        // handle photo/video uploads (multiple) with per-file captions
        $place = trim(($_POST['city'] ?? '') . ', ' . ($_POST['coun'] ?? ''), ', ');
        // Best-effort browser geolocation, captured client-side (see script below) and
        // submitted as hidden fields. Optional — left blank if the user denied/skipped it.
        $mediaLat = trim($_POST['media_lat'] ?? '');
        $mediaLon = trim($_POST['media_lon'] ?? '');
        if (!empty($_FILES['media']) && is_array($_FILES['media']['name'])) {
            $n = count($_FILES['media']['name']);
            for ($i = 0; $i < $n; $i++) {
                if (($_FILES['media']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $f = [
                    'name' => $_FILES['media']['name'][$i], 'type' => $_FILES['media']['type'][$i],
                    'tmp_name' => $_FILES['media']['tmp_name'][$i], 'error' => $_FILES['media']['error'][$i],
                    'size' => $_FILES['media']['size'][$i],
                ];
                $cap = $_POST['media_caption'][$i] ?? '';
                ja_handle_upload($conn, $f, $em, $newId, $cap, $place, 1, $mediaLat, $mediaLon);
            }
        }
        header('Location: display.php?id='.$newId.'&new=1'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">✦ Record a journey</div>
    <h1>New Journal Entry</h1>
    <p class="sub">Capture your trip — the details help JourneyAI learn and recommend better journeys to you and others.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container" style="max-width:860px">
    <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <form method="post" id="entryForm" enctype="multipart/form-data">

      <!-- step indicators (visual only; single page) -->
      <div class="ja-steps">
        <div class="ja-step active"><span class="num">1</span> Trip details</div>
        <div class="ja-step"><span class="num">2</span> Story & places</div>
        <div class="ja-step"><span class="num">3</span> Budget</div>
      </div>

      <div class="ja-card" style="margin-bottom:22px">
        <h3>Trip details</h3>
        <div class="ja-field"><label>Trip title *</label><input class="ja-input" name="title" placeholder="e.g. Monsoon in Munnar" required></div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Country</label><input class="ja-input" name="coun" placeholder="India"></div>
          <div class="ja-field"><label>City / place</label><input class="ja-input" name="city" placeholder="Munnar"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Date of visit</label><input class="ja-input" type="date" name="dv"></div>
          <div class="ja-field"><label>Date of return</label><input class="ja-input" type="date" name="dr"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Travel mode</label><input class="ja-input" name="tv" placeholder="flight / train / car"></div>
          <div class="ja-field"><label>Hotel / stay</label><input class="ja-input" name="hn" placeholder="where you stayed"></div>
        </div>
      </div>

      <div class="ja-card" style="margin-bottom:22px">
        <h3>Your story & places</h3>
        <div class="ja-field"><label>How was the trip?</label>
          <textarea class="ja-input" name="desc" rows="5" placeholder="Describe the experience, the vibe, what you loved or didn't…"></textarea></div>
        <div class="ja-field"><label>Interesting places you visited</label>
          <textarea class="ja-input" name="ptv" rows="2" placeholder="comma-separated attractions"></textarea></div>
        <div class="ja-field"><label>Address / area</label><input class="ja-input" name="ad" placeholder="neighbourhood or address"></div>
      </div>

      <div class="ja-card" style="margin-bottom:22px">
        <h3>Photos &amp; videos <span style="color:var(--text-mut);font-weight:400;font-size:.9rem">(share your trip — add #hashtags in the caption)</span></h3>
        <div id="mediaDrop" class="ja-media-drop">
          <?= ja_icon('compass',30) ?>
          <p style="margin:10px 0 4px;font-weight:600">Add photos or short videos</p>
          <p style="margin:0;color:var(--text-mut);font-size:.85rem">JPG, PNG, WEBP, MP4, WEBM · up to 40 MB each</p>
          <input type="file" name="media[]" id="mediaInput" accept="image/*,video/*" multiple hidden>
        </div>
        <div id="mediaPreview" class="ja-media-preview"></div>
        <p id="mediaCaptionHint" class="ja-media-cap" style="display:none;margin-top:10px">Add a caption under each photo/video <span style="color:var(--text-mut)">(optional — #hashtags e.g. #beach #goa)</span></p>
        <p id="mediaGeoStatus" style="margin:10px 0 0;color:var(--text-mut);font-size:.8rem"></p>
        <input type="hidden" name="media_lat" id="mediaLat">
        <input type="hidden" name="media_lon" id="mediaLon">
      </div>

      <div class="ja-card" style="margin-bottom:22px">
        <h3>Budget <span style="color:var(--text-mut);font-weight:400;font-size:.9rem">(in ₹, optional — powers cost insights)</span></h3>
        <div class="ja-field"><label>Budget for this trip <span style="color:var(--text-mut);font-weight:400">(target total, optional)</span></label>
          <input class="ja-input" type="number" name="budget_target" min="0" step="0.01" placeholder="e.g. 25000"></div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Flight / air</label><input class="ja-input" type="number" name="flight" min="0" value="0"></div>
          <div class="ja-field"><label>Train</label><input class="ja-input" type="number" name="train" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Taxi / local</label><input class="ja-input" type="number" name="taxi" min="0" value="0"></div>
          <div class="ja-field"><label>Bus / car</label><input class="ja-input" type="number" name="bus" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Accommodation</label><input class="ja-input" type="number" name="hotel" min="0" value="0"></div>
          <div class="ja-field"><label>Food (total)</label><input class="ja-input" type="number" name="dinner" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label>Activities / entry fees</label><input class="ja-input" type="number" name="activities" min="0" value="0"></div>
          <div class="ja-field"><label>Shopping</label><input class="ja-input" type="number" name="shopping" min="0" value="0"></div>
        </div>
        <div class="ja-field"><label>Misc</label><input class="ja-input" type="number" name="misc" min="0" value="0"></div>
      </div>

      <div style="display:flex;gap:12px">
        <button class="ja-btn ja-btn-primary ja-btn-lg" type="submit" data-magnetic>✦ Save journal entry</button>
        <a href="dashboard.php" class="ja-btn ja-btn-ghost ja-btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</main>

<script>
(function(){
  var drop=document.getElementById('mediaDrop'),input=document.getElementById('mediaInput'),prev=document.getElementById('mediaPreview');
  if(!drop)return;
  drop.addEventListener('click',function(){input.click();});
  drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('drag');});
  drop.addEventListener('dragleave',function(){drop.classList.remove('drag');});
  drop.addEventListener('drop',function(e){e.preventDefault();drop.classList.remove('drag');input.files=e.dataTransfer.files;render();});
  input.addEventListener('change',render);
  function render(){
    prev.innerHTML='';
    Array.prototype.forEach.call(input.files,function(f){
      var url=URL.createObjectURL(f),el;
      if(f.type.indexOf('video')===0){el=document.createElement('video');el.src=url;el.muted=true;}
      else{el=document.createElement('img');el.src=url;}
      el.className='ja-media-thumb';prev.appendChild(el);
    });
  }
})();
</script>

<?php include 'ja-footer.php'; ?>
