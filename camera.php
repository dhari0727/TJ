<?php
$ja_title = "Camera"; $ja_active = "camera";
session_start();
require_once 'connection.php';
require_once 'ja-camera.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
$folders = ja_cam_folders(ja_db(), $eml);
$usage = ja_cam_usage(ja_db(), $eml);
$cfg = ['maxPhotos' => JA_CAM_MAX_PHOTOS, 'photos' => $usage['photos'], 'maxBytes' => JA_CAM_MAX_BYTES, 'bytes' => $usage['bytes']];
$filters = [
  'original' => 'Original', 'warm' => 'Warm', 'cool' => 'Cool', 'mono' => 'Mono', 'fade' => 'Fade',
  'vivid' => 'Vivid', 'sepia' => 'Sepia', 'film' => 'Film', 'golden' => 'Golden hour',
];
$frames = ['classic' => 'Classic', 'wide' => 'Wide', 'film' => 'Film strip', 'postcard' => 'Postcard', 'none' => 'None'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-camera.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-camera.css') ?>">
<script src="js/camera.js?v=<?= (int)@filemtime(__DIR__ . '/js/camera.js') ?>" defer></script>
</head>
<body class="ja cm-page">
<main class="ja-main">
<div class="ja-container cm-wrap">

  <header class="cm-head">
    <div>
      <h1>Camera</h1>
      <p class="cm-sub">Take a photo, pick a look and a frame, and keep it in your private camera roll.</p>
    </div>
    <a class="ja-btn ja-btn-ghost cm-rolllink" href="camera-roll.php"><?= ja_icon('images', 18) ?><span>Camera roll</span></a>
  </header>

  <div class="cm" id="cm" data-state="starting" data-cfg="<?= htmlspecialchars(json_encode($cfg), ENT_QUOTES) ?>">

    <section class="cm-left" aria-label="Camera">
      <div class="cm-body">
        <div class="cm-stripe" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

        <div class="cm-stage" id="cmStage">
          <!-- live viewfinder, laid out as the chosen frame so what you see is what you save -->
          <div class="cm-card" id="cmCard" data-frame="classic">
            <div class="cm-shot" id="cmShot">
              <video id="cmVideo" playsinline muted autoplay></video>
              <div class="cm-fx" id="cmFx" aria-hidden="true"></div>
              <div class="cm-stamp" aria-hidden="true"></div>
              <div class="cm-flash" id="cmFlash" aria-hidden="true"></div>
              <div class="cm-count" id="cmCount" aria-live="assertive" aria-atomic="true"></div>
              <div class="cm-msg" id="cmMsg" hidden role="alert">
                <?= ja_icon('camera', 34) ?>
                <h2 id="cmMsgTitle">Camera is off</h2>
                <p id="cmMsgBody"></p>
                <div class="cm-msg-actions">
                  <label class="ja-btn ja-btn-primary cm-filebtn" tabindex="0" id="cmMsgPick"><?= ja_icon('images', 18) ?><span>Choose a photo</span><input type="file" accept="image/*" hidden data-cm-file></label>
                  <label class="ja-btn ja-btn-ghost cm-filebtn" tabindex="0" id="cmMsgSnap"><?= ja_icon('camera', 18) ?><span>Use phone camera</span><input type="file" accept="image/*" capture="environment" hidden data-cm-file></label>
                  <button type="button" class="ja-btn ja-btn-ghost" id="cmRetry">Try the camera again</button>
                </div>
              </div>
            </div>
            <div class="cm-cap" id="cmCap" aria-hidden="true"><span class="l1"></span><span class="l2"></span><span class="rt"></span></div>
          </div>

          <!-- the developed print -->
          <div class="cm-out" id="cmOut" hidden>
            <div class="cm-print" id="cmPrint">
              <img id="cmOutImg" alt="Your photo, developing" data-mo-skip>
              <div class="cm-dev" id="cmDev" aria-hidden="true"></div>
            </div>
          </div>
        </div>

        <p class="cm-status" id="cmStatus" role="status" aria-live="polite"></p>

        <!-- live controls -->
        <div class="cm-bar cm-bar-live" id="cmBarLive">
          <label class="cm-round" title="Choose a photo from this device" tabindex="0" id="cmPickBtn">
            <?= ja_icon('images', 22) ?><span class="ja-sr">Choose a photo from this device</span>
            <input type="file" accept="image/*" hidden data-cm-file>
          </label>
          <button type="button" class="cm-shutter" id="cmShutter" aria-label="Take photo"><span></span></button>
          <button type="button" class="cm-round" id="cmFlip" title="Switch camera" aria-label="Switch between front and back camera"><?= ja_icon('flip', 22) ?></button>
        </div>

        <!-- after the shot -->
        <div class="cm-bar cm-bar-result" id="cmBarResult">
          <button type="button" class="ja-btn ja-btn-ghost" id="cmRetake">Retake</button>
          <button type="button" class="ja-btn ja-btn-primary" id="cmSave"><?= ja_icon('check', 18) ?><span>Save photo</span></button>
        </div>
      </div>
      <p class="cm-privacy"><?= ja_icon('lock', 15) ?><span>Only you can see these photos. Your location is never read, and nothing is posted until you pick a photo for a journal or storybook.</span></p>
    </section>

    <aside class="cm-panel" aria-label="Photo settings">
      <section>
        <h2>Look</h2>
        <div class="cm-chips cm-filters" role="radiogroup" aria-label="Filter">
          <?php foreach ($filters as $k => $lbl): ?>
          <button type="button" class="cm-chip" role="radio" aria-checked="<?= $k === 'original' ? 'true' : 'false' ?>" data-filter="<?= $k ?>"><span class="sw" data-sw="<?= $k ?>"></span><span><?= htmlspecialchars($lbl) ?></span></button>
          <?php endforeach; ?>
        </div>
      </section>

      <section>
        <h2>Frame</h2>
        <div class="cm-chips cm-frames" role="radiogroup" aria-label="Frame">
          <?php foreach ($frames as $k => $lbl): ?>
          <button type="button" class="cm-chip" role="radio" aria-checked="<?= $k === 'classic' ? 'true' : 'false' ?>" data-frame="<?= $k ?>"><span class="fi fi-<?= $k ?>"><i></i></span><span><?= htmlspecialchars($lbl) ?></span></button>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="cm-fields">
        <label class="cm-field"><span>Caption</span>
          <input class="ja-input" id="cmCaption" type="text" maxlength="60" autocomplete="off" placeholder="Sunrise at the ghats"></label>
        <label class="cm-field"><span>Place <em>(optional)</em></span>
          <input class="ja-input" id="cmPlace" type="text" maxlength="60" autocomplete="off" placeholder="Varanasi"></label>
        <label class="cm-field"><span>Save to</span>
          <select class="ja-input" id="cmFolder">
            <?php foreach ($folders as $f): ?><option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option><?php endforeach; ?>
          </select></label>
      </section>

      <section>
        <h2>Timer</h2>
        <div class="cm-chips cm-timers" role="radiogroup" aria-label="Self timer">
          <button type="button" class="cm-chip cm-chip-sm" role="radio" aria-checked="true" data-timer="0"><span>Off</span></button>
          <button type="button" class="cm-chip cm-chip-sm" role="radio" aria-checked="false" data-timer="3"><span>3 seconds</span></button>
          <button type="button" class="cm-chip cm-chip-sm" role="radio" aria-checked="false" data-timer="10"><span>10 seconds</span></button>
        </div>
      </section>

      <p class="cm-quota" id="cmQuota"><?= (int)$usage['photos'] ?> of <?= JA_CAM_MAX_PHOTOS ?> photos used, <?= round($usage['bytes'] / 1048576, 1) ?> of <?= JA_CAM_MAX_BYTES >> 20 ?> MB.</p>
    </aside>
  </div>
</div>
</main>
<?php include 'ja-footer.php'; ?>
</body>
</html>
