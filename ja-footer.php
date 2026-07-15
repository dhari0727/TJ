<?php
/**
 * JourneyAI — shared footer + document close.
 * Include at the end of every page (closes <body></html>).
 */
?>
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
        <a href="analytics.php">Analytics</a>
        <?php if (!empty($_SESSION['eml'])): ?><a href="recommendations.php">For You</a><?php endif; ?>
      </div>
      <div>
        <h4>Journal</h4>
        <?php if (!empty($_SESSION['eml'])): ?>
          <a href="new-entry.php">New Entry</a>
          <a href="my-entries.php">My Entries</a>
          <a href="profile.php">Profile</a>
        <?php else: ?>
          <a href="login.php">Login</a>
          <a href="register.php">Sign up</a>
        <?php endif; ?>
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
