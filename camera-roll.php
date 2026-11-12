<?php
$ja_title = "Camera roll"; $ja_active = "roll";
session_start();
require_once 'connection.php';
require_once 'ja-camera.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
$usage = ja_cam_usage(ja_db(), $eml);
$cfg = [
  'maxPhotos' => JA_CAM_MAX_PHOTOS, 'maxBytes' => JA_CAM_MAX_BYTES, 'photos' => $usage['photos'], 'bytes' => $usage['bytes'],
  'default' => JA_CAM_DEFAULT, 'folder' => (int)($_GET['folder'] ?? 0),
];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-camera.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-camera.css') ?>">
<?php require_once __DIR__ . '/ja-social.php'; echo ja_social_assets(); ?>
<script src="js/camera-roll.js?v=<?= (int)@filemtime(__DIR__ . '/js/camera-roll.js') ?>" defer></script>
</head>
<body class="ja cr-page">
<main class="ja-main">
<div class="ja-container cr-wrap">

  <header class="cr-head">
    <div>
      <h1>Camera roll</h1>
      <p class="cr-private"><?= ja_icon('lock', 17) ?><span>Only you can see these photos. They are not public and have no shareable link.</span></p>
    </div>
    <div class="cr-headbtns">
      <a class="ja-btn ja-btn-primary" href="camera.php"><?= ja_icon('camera', 18) ?><span>Open camera</span></a>
    </div>
  </header>

  <div class="cr" id="cr" data-cfg="<?= htmlspecialchars(json_encode($cfg), ENT_QUOTES) ?>">
    <nav aria-label="Folders">
      <ul class="cr-folders" id="crFolders"></ul>
    </nav>

    <section class="cr-main" aria-live="polite">
      <div class="cr-bar">
        <h2 class="cr-title" id="crTitle">All photos</h2>
        <div class="cr-tools">
          <button type="button" class="cr-tool" id="crRename" hidden><?= ja_icon('edit', 16) ?><span>Rename</span></button>
          <button type="button" class="cr-tool" id="crDelFolder" hidden><?= ja_icon('trash', 16) ?><span>Delete folder</span></button>
          <button type="button" class="cr-tool" id="crSelect" aria-pressed="false"><?= ja_icon('check', 16) ?><span>Select</span></button>
        </div>
      </div>
      <p class="cr-usage" id="crUsage"></p>
      <div class="cr-grid" id="crGrid"><div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div></div>
      <div class="cr-more" id="crMore" hidden><button type="button" class="ja-btn ja-btn-ghost" id="crMoreBtn">Show more photos</button></div>
      <div class="cr-empty" id="crEmpty" hidden></div>
    </section>
  </div>

  <div class="cr-bulk" id="crBulk" hidden role="toolbar" aria-label="Selected photos">
    <strong id="crBulkN">0 selected</strong>
    <select id="crBulkFolder" aria-label="Move selected photos to"></select>
    <button type="button" id="crBulkMove">Move</button>
    <button type="button" class="danger" id="crBulkDel">Delete</button>
    <button type="button" id="crBulkCancel">Done</button>
  </div>
</div>
</main>
<?php include 'ja-footer.php'; ?>
</body>
</html>
