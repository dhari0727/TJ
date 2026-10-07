<?php
$ja_title = "New Storybook"; $ja_active = "entries";
session_start();
require 'connection.php';
require 'ja-media.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $err = 'Please give your storybook a title.';
    } else {
        $subtitle  = trim($_POST['subtitle'] ?? '');
        $theme     = trim($_POST['theme'] ?? 'floral');
        $vis       = trim($_POST['visibility'] ?? 'private');

        $s = mysqli_prepare($conn, "INSERT INTO storybooks (eml, title, subtitle, theme, visibility) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, 'sssss', $em, $title, $subtitle, $theme, $vis);
        mysqli_stmt_execute($s);
        $bookId = mysqli_insert_id($conn);
        mysqli_stmt_close($s);

        header('Location: storybook-edit.php?id='.$bookId); exit;
    }
}

$themes = [];
$tr = mysqli_prepare($conn, "SELECT * FROM storybooks_themes ORDER BY theme_id ASC");
mysqli_stmt_execute($tr);
$themes = mysqli_fetch_all(mysqli_stmt_get_result($tr), MYSQLI_ASSOC);
mysqli_stmt_close($tr);

?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<link rel="stylesheet" href="css/storybook.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Serif+Display&family=Lora:wght@400;500;600&family=Caveat:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

<div class="sb-shell" data-sb-theme="floral">
  <div class="ja-pagehead">
    <div class="ja-container">
      <div class="ja-eyebrow">✦ Storybook</div>
      <h1>Create a Storybook Journal</h1>
      <p class="sub">Turn your trip into a beautiful, keepsake journal.</p>
    </div>
  </div>

  <main class="ja-main">
    <div class="ja-container" style="max-width:800px">
      <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post" id="storybookForm">

        <div class="ja-card" style="margin-bottom:22px">
          <h3>Trip details</h3>
          <div class="ja-field"><label>Trip title *</label><input class="ja-input" name="title" placeholder="e.g. Monsoon in Munnar" required></div>
          <div class="ja-field"><label>Subtitle</label><input class="ja-input" name="subtitle" placeholder="A short tagline for your trip"></div>
        </div>

        <div class="ja-card" style="margin-bottom:22px">
          <h3>Choose a theme</h3>
          <div class="sb-theme-grid">
            <?php foreach ($themes as $t): ?>
              <?php
                $slug    = $t['theme_id'];
                $palette = json_decode($t['palette'] ?? '{}', true) ?: [];
                $colors  = array_filter([
                    $palette['--sb-accent']  ?? null,
                    $palette['--sb-accent2'] ?? null,
                    $palette['--sb-bg']      ?? null,
                    $palette['--sb-paper']   ?? null,
                ]);
                if (!$colors) $colors = ['#999','#bbb','#ddd','#eee'];
                $emojiSet = json_decode($t['emoji_set'] ?? '[]', true) ?: [];
                $emojis   = implode('', array_slice($emojiSet, 0, 4)) ?: '📖✨🗺️';
              ?>
              <label class="sb-theme-card<?= $slug==='floral'?' active':'' ?>" data-theme="<?= htmlspecialchars($slug) ?>">
                <input type="radio" name="theme" value="<?= htmlspecialchars($slug) ?>"<?= $slug==='floral'?' checked':'' ?> hidden>
                <div class="sb-theme-name"><?= htmlspecialchars($t['label']) ?></div>
                <?php if (!empty($t['description'])): ?><p style="font-size:.78rem;color:var(--sb-text-dim);margin:0 0 8px"><?= htmlspecialchars($t['description']) ?></p><?php endif; ?>
                <div class="sb-theme-swatch">
                  <?php foreach ($colors as $c): ?><span style="background:<?= htmlspecialchars($c) ?>"></span><?php endforeach; ?>
                </div>
                <div style="margin-top:6px;font-size:.85rem"><?= htmlspecialchars($emojis) ?></div>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="ja-card" style="margin-bottom:22px">
          <h3>Visibility</h3>
          <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:6px">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500">
              <input type="radio" name="visibility" value="private" checked> Private
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500">
              <input type="radio" name="visibility" value="link"> Link-shared
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500">
              <input type="radio" name="visibility" value="public"> Public
            </label>
          </div>
        </div>

        <div style="display:flex;gap:12px">
          <button class="sb-btn sb-btn-primary" type="submit" style="padding:12px 28px;font-size:1rem">Create Storybook</button>
          <a href="my-entries.php" class="sb-btn sb-btn-ghost" style="padding:12px 28px;font-size:1rem">Cancel</a>
        </div>
      </form>
    </div>
  </main>
</div>

<script>
(function(){
  var cards=document.querySelectorAll('.sb-theme-card');
  cards.forEach(function(card){
    card.addEventListener('click',function(){
      cards.forEach(function(c){c.classList.remove('active');});
      card.classList.add('active');
      var radio=card.querySelector('input[type=radio]');
      if(radio)radio.checked=true;
      var shell=document.querySelector('.sb-shell');
      if(shell)shell.setAttribute('data-sb-theme',card.dataset.theme);
    });
  });
  var form=document.getElementById('storybookForm');
  if(form)form.addEventListener('submit',function(e){
    var title=form.querySelector('input[name=title]');
    if(!title||!title.value.trim()){e.preventDefault();title.focus();}
  });
})();
</script>

<?php include 'ja-footer.php'; ?>
