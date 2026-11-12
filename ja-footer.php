<?php
/**
 * JourneyAI — shared footer + document close.
 * Closes the sidebar shell (logged-in) OR renders the full footer (top-nav).
 * Relies on $ja_use_sidebar set by ja-head.php.
 */
$ja_credit_html = 'Destination data from <a href="https://en.wikivoyage.org" target="_blank" rel="noopener">Wikivoyage</a> and <a href="https://en.wikipedia.org" target="_blank" rel="noopener">Wikipedia</a> (CC BY-SA 4.0) and <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors (ODbL). Costs are estimates, not quotes.';
if (!empty($ja_use_sidebar)): ?>
    <div class="ja-credits"><?= $ja_credit_html ?></div>
  </div><!-- .ja-app-content -->
</div><!-- .ja-app-main -->
<?php include __DIR__ . '/ja-palette.php'; ?>
<?php include __DIR__ . '/ja-chat.php'; ?>
<script src="js/ja-chat.js?v=<?= (int)@filemtime(__DIR__ . '/js/ja-chat.js') ?>" defer></script>
<script>
(function(){
  var t=document.getElementById('jaSideToggle'),s=document.getElementById('jaSidebar');
  function set(o){s.classList.toggle('open',o);t.setAttribute('aria-expanded',o?'true':'false');t.setAttribute('aria-label',o?'Close menu':'Open menu');}
  if(t&&s)t.addEventListener('click',function(){set(!s.classList.contains('open'));});
  document.addEventListener('click',function(e){
    if(s&&s.classList.contains('open')&&!s.contains(e.target)&&e.target!==t&&!t.contains(e.target))set(false);
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&s&&s.classList.contains('open')){set(false);t.focus();}});
})();
</script>
</body>
</html>
<?php else: ?>
<footer class="ja-footer">
  <div class="ja-container ja-footer-inner">
    <div class="ja-footer-brand">
      <a class="ja-brand" href="index.php"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span></a>
      <p>Plan a trip in plain words. Costs and routes come from real travel journals, and every pick says why.</p>
    </div>
    <div class="ja-footer-cols">
      <nav aria-label="Explore">
        <h2>Explore</h2>
        <a href="plan-trip.php">Plan a trip</a>
        <a href="feed.php">Community</a>
        <a href="analytics.php">Insights</a>
      </nav>
      <nav aria-label="Account">
        <h2>Account</h2>
        <?php if (!empty($_SESSION['eml'])): ?>
          <a href="dashboard.php">Home</a>
          <a href="profile.php">Your profile</a>
        <?php else: ?>
          <a href="register.php">Sign up</a>
          <a href="login.php">Log in</a>
        <?php endif; ?>
      </nav>
      <nav aria-label="Site">
        <h2>Site</h2>
        <a href="index.php">Home</a>
        <button type="button" class="ja-footer-link" data-ja-palette-open>Search the site</button>
        <span class="ja-footer-note">&copy; <?= date('Y') ?> JourneyAI</span>
      </nav>
    </div>
    <div class="ja-credits"><?= $ja_credit_html ?></div>
  </div>
</footer>
<?php include __DIR__ . '/ja-palette.php'; ?>
<?php include __DIR__ . '/ja-chat.php'; ?>
<script src="js/ja-chat.js?v=<?= (int)@filemtime(__DIR__ . '/js/ja-chat.js') ?>" defer></script>
</body>
</html>
<?php endif; ?>
