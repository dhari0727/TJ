<?php
$ja_title = "New Entry"; $ja_active = "entries"; $ja_jtab = "new";
session_start();
require 'connection.php';
require 'ja-media.php';
require_once __DIR__ . '/ja-trust.php';
ja_csrf_enforce();
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$saved = false; $err = '';

function num($k){ return (float)($_POST[$k] ?? 0); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $err = 'Please give your trip a title.';
        if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) { header('Content-Type: application/json'); echo json_encode(['error' => $err]); exit; }
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
        mysqli_stmt_execute($s);
        $newId = mysqli_insert_id($conn);   // must be read NOW: db1/db2/db3 have no auto-increment (it would be 0)
        mysqli_stmt_close($s);

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

        // who can read it is decided AFTER the photos are saved, by the trust layer (ja_publish_journal below)
        $vis = in_array($_POST['visibility'] ?? '', ['private', 'link', 'public'], true) ? $_POST['visibility'] : 'private';
        $postMedia = !empty($_POST['post_media']) ? 1 : 0;   // also show photos/videos in the Community feed

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
                ja_handle_upload($conn, $f, $em, $newId, $cap, $place, $postMedia, $mediaLat, $mediaLon);
            }
        }
        // photos picked from the private camera roll: public copies made by the camera API, re-validated here
        if (!empty($_POST['picked']) && is_array($_POST['picked'])) {
            foreach (array_slice($_POST['picked'], 0, 20) as $i => $pp) {
                if (!is_string($pp)) continue;
                $cap = is_array($_POST['picked_caption'] ?? null) ? (string)($_POST['picked_caption'][$i] ?? '') : '';
                ja_attach_picked($conn, $pp, $em, $newId, $cap, $place, $postMedia, $mediaLat, $mediaLon);
            }
        }
        if ($vis !== 'private') {
            $pr = ja_publish_journal($newId, $em, $vis);
            $_SESSION['ja_flash'] = $pr['ok'] ? ($pr['message'] ?: 'Saved.') : ('Saved privately. ' . $pr['message']);
        }
        if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
            header('Content-Type: application/json'); echo json_encode(['redirect' => 'display.php?id=' . $newId . '&new=1']); exit;
        }
        header('Location: display.php?id='.$newId.'&new=1'); exit;
    }
}
?>
<?php require_once 'ja-community-ui.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>">
<link rel="stylesheet" href="css/pages-camera-picker.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-camera-picker.css') ?>"></head>
<body class="ja">

<div class="ja-pagehead pc-head">
  <div class="ja-container">
    <h1>Write a journal</h1>
    <p class="sub">Tell the story of a trip. The details you add help JourneyAI suggest better journeys to you and to others.</p>
    <?php include 'ja-journal-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <div class="pw-form">
    <?php if ($err): ?><div class="pw-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <div class="pw-err" id="pwErr" role="alert" hidden></div>
    <div class="pw-restored" id="pwRestored" hidden>
      <span>Restored your draft from <strong id="pwRestoredWhen"></strong>. Photos are not kept in drafts, so add them again.</span>
      <button type="button" id="pwDiscard">Discard draft</button>
    </div>

    <form method="post" id="entryForm" enctype="multipart/form-data">
      <ol class="pw-steps" aria-label="Sections">
        <li><a href="#pwDetails"><b>1</b> Trip details</a></li>
        <li><a href="#pwStory"><b>2</b> Story and photos</a></li>
        <li><a href="#pwBudget"><b>3</b> Budget</a></li>
        <li><a href="#pwShare"><b>4</b> Sharing</a></li>
      </ol>

      <section class="pw-card" id="pwDetails">
        <h3>Trip details</h3>
        <p class="hint">Where you went and when. Only the title is required.</p>
        <div class="ja-field"><label for="f-title">Trip title</label><input class="ja-input" id="f-title" name="title" placeholder="Monsoon in Munnar" required maxlength="150"></div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-coun">Country</label><input class="ja-input" id="f-coun" name="coun" placeholder="India"></div>
          <div class="ja-field"><label for="f-city">City or place</label><input class="ja-input" id="f-city" name="city" placeholder="Munnar"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-dv">Date of visit</label><input class="ja-input" id="f-dv" type="date" name="dv"></div>
          <div class="ja-field"><label for="f-dr">Date of return</label><input class="ja-input" id="f-dr" type="date" name="dr"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-tv">Travel mode</label><input class="ja-input" id="f-tv" name="tv" placeholder="Flight, train or car"></div>
          <div class="ja-field"><label for="f-hn">Where you stayed</label><input class="ja-input" id="f-hn" name="hn" placeholder="Hotel or homestay"></div>
        </div>
      </section>

      <section class="pw-card pw-story" id="pwStory">
        <h3>Your story</h3>
        <p class="hint">Write it the way you would tell a friend. Start a line with <code>##</code> or <code>Day 2:</code> to make a section heading; long journals then get a contents list.</p>
        <div class="ja-field"><label for="f-desc">How was the trip?</label>
          <textarea class="ja-input" id="f-desc" name="desc" rows="9" placeholder="What did you do, what did it cost, and what would you do differently?"></textarea>
          <div class="pw-counts" id="pwCounts" aria-live="polite"></div></div>
        <div class="ja-field"><label for="f-ptv">Places you visited</label>
          <textarea class="ja-input" id="f-ptv" name="ptv" rows="2" placeholder="Separate with commas: Hawa Mahal, Amber Fort"></textarea>
          <p class="hint" style="margin:6px 0 0">Readers can open each place as a trip plan.</p></div>
        <div class="ja-field"><label for="f-ad">Address or area</label><input class="ja-input" id="f-ad" name="ad" placeholder="Neighbourhood or street"></div>

        <h3 style="margin-top:26px">Photos and videos</h3>
        <p class="hint">Drop files here or choose them. Add a caption to each, with #hashtags if you want them in the Community feed.</p>
        <div id="mediaDrop" class="pw-drop" aria-label="Add photos or videos">
          <?= pc_icon('upload', 30) ?>
          <strong>Drag photos and videos here, or choose files</strong>
          <span>JPG, PNG, WEBP, MP4 or WEBM, up to 40 MB each</span>
          <input type="file" name="media[]" id="mediaInput" accept="image/*,video/*" multiple hidden>
        </div>
        <div class="pw-camera-row"><button type="button" class="ja-btn ja-btn-ghost" id="cameraPick"><?= ja_icon('camera', 18) ?> From my camera roll</button>
          <span class="hint">Polaroids you took in JourneyAI. Your originals stay private.</span></div>
        <ul id="mediaList" class="pw-files"></ul>
        <p id="mediaGeoStatus" class="pw-geo"></p>
        <input type="hidden" name="media_lat"><input type="hidden" name="media_lon">
      </section>

      <section class="pw-card" id="pwBudget">
        <h3>Budget</h3>
        <p class="hint">In rupees. Optional, but it powers the cost breakdown readers see.</p>
        <div class="ja-field"><label for="f-bt">Target total for this trip</label>
          <input class="ja-input" id="f-bt" type="number" name="budget_target" min="0" step="0.01" placeholder="25000"></div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-flight">Flight</label><input class="ja-input" id="f-flight" type="number" name="flight" min="0" value="0"></div>
          <div class="ja-field"><label for="f-train">Train</label><input class="ja-input" id="f-train" type="number" name="train" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-taxi">Taxi and local</label><input class="ja-input" id="f-taxi" type="number" name="taxi" min="0" value="0"></div>
          <div class="ja-field"><label for="f-bus">Bus and car</label><input class="ja-input" id="f-bus" type="number" name="bus" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-hotel">Stay</label><input class="ja-input" id="f-hotel" type="number" name="hotel" min="0" value="0"></div>
          <div class="ja-field"><label for="f-dinner">Food, total</label><input class="ja-input" id="f-dinner" type="number" name="dinner" min="0" value="0"></div>
        </div>
        <div class="ja-fieldrow">
          <div class="ja-field"><label for="f-act">Activities and entry fees</label><input class="ja-input" id="f-act" type="number" name="activities" min="0" value="0"></div>
          <div class="ja-field"><label for="f-shop">Shopping</label><input class="ja-input" id="f-shop" type="number" name="shopping" min="0" value="0"></div>
        </div>
        <div class="ja-field"><label for="f-misc">Anything else</label><input class="ja-input" id="f-misc" type="number" name="misc" min="0" value="0"></div>
      </section>

      <section class="pw-card" id="pwShare">
        <h3>Sharing</h3>
        <p class="hint">You can change this any time from My journal.</p>
        <div class="ja-field"><label for="f-vis">Who can read this journal?</label>
          <select class="ja-select" id="f-vis" name="visibility">
            <option value="private">Only me</option>
            <option value="link">Anyone with the link</option>
            <option value="public">Everyone, listed in Read journals</option>
          </select></div>
        <label class="ja-switch"><input type="checkbox" name="post_media" value="1"> <span>Also post my photos and videos to the Community feed</span></label>
      </section>

      <div class="pw-foot">
        <button class="ja-btn ja-btn-primary" type="submit">Preview journal</button>
        <a href="my-entries.php" class="ja-btn ja-btn-ghost">Cancel</a>
        <span class="pw-state" id="pwState" role="status"><i></i><span id="pwStateTxt">Drafts save as you type</span></span>
      </div>
    </form>
    </div>
  </div>
</main>

<div class="pw-prev" id="pwPreview" role="dialog" aria-modal="true" aria-labelledby="pwPrevT">
  <div class="pw-sheet">
    <div class="pw-sheet-head"><strong id="pwPrevT">Preview</strong><span class="pc-muted">This is how readers will see it.</span>
      <button type="button" class="pc-act" id="pwPrevX" aria-label="Close preview"><?= pc_icon('close', 16) ?></button></div>
    <div class="pw-sheet-body" id="pwPrevBody"></div>
    <div class="pw-sheet-foot">
      <p id="pwPrevVis"></p>
      <button type="button" class="ja-btn ja-btn-ghost" id="pwPrevBack">Keep editing</button>
      <button type="button" class="ja-btn ja-btn-primary" id="pwPrevSave">Save journal</button>
    </div>
  </div>
</div>

<script>window.PC_WRITE = { csrf: <?= json_encode(ja_csrf()) ?>, uid: <?= json_encode(substr(sha1(strtolower($em)), 0, 10)) ?> };</script>
<script src="js/camera-picker.js?v=<?= (int)@filemtime(__DIR__ . '/js/camera-picker.js') ?>"></script>
<script src="js/media-drop.js?v=<?= (int)@filemtime(__DIR__ . '/js/media-drop.js') ?>"></script>
<script src="js/journal-writer.js?v=<?= (int)@filemtime(__DIR__ . '/js/journal-writer.js') ?>"></script>

<?php include 'ja-footer.php'; ?>
