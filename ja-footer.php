<?php
/**
 * JourneyAI — shared footer + document close.
 * Closes the sidebar shell (logged-in) OR renders the full footer (top-nav).
 * Relies on $ja_use_sidebar set by ja-head.php.
 */
if (!empty($ja_use_sidebar)): ?>
  </div><!-- .ja-app-content -->
</div><!-- .ja-app-main -->
<?php include __DIR__ . '/ja-chat.php'; ?>
<script src="js/ja-chat.js" defer></script>
<script>
(function(){
  var t=document.getElementById('jaSideToggle'),s=document.getElementById('jaSidebar');
  if(t&&s)t.addEventListener('click',function(){s.classList.toggle('open');});
  document.addEventListener('click',function(e){
    if(s&&s.classList.contains('open')&&!s.contains(e.target)&&e.target!==t&&!t.contains(e.target))s.classList.remove('open');
  });
})();
</script>
</body>
</html>
<?php else: ?>
<footer class="ja-footer">
  <div class="ja-container ja-footer-inner">
    <div class="ja-footer-brand">
      <span class="ja-brand"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI</span>
      <p>Hybrid, explainable, budget-aware travel recommendations built on real travel journals.</p>
    </div>
    <div class="ja-footer-cols">
      <div>
        <h4>Discover</h4>
        <a href="plan-trip.php">Plan a Trip</a>
        <a href="feed.php">Feed</a>
        <a href="analytics.php">Analytics</a>
      </div>
      <div>
        <h4>Get started</h4>
        <a href="register.php">Sign up</a>
        <a href="login.php">Login</a>
      </div>
      <div>
        <h4>About</h4>
        <a href="index.php">Home</a>
        <span class="ja-muted">© <?= date('Y') ?> JourneyAI</span>
      </div>
    </div>
  </div>
</footer>
<?php include __DIR__ . '/ja-chat.php'; ?>
<script src="js/ja-chat.js" defer></script>
</body>
</html>
<?php endif; ?>
