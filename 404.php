<?php
/** Not-found page (wired up with ErrorDocument 404 in .htaccess). Relative links resolve against the site folder via <base>. */
http_response_code(404);
$ja_title = "Page not found"; $ja_active = "";
$ja_css_extra = ['pages-account.css'];
$ja_base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head><base href="<?= htmlspecialchars($ja_base) ?>"><?php include __DIR__ . '/ja-head.php'; ?>
<meta name="robots" content="noindex"></head>
<body class="ja">
<main class="acct-page acct-narrow nf-page">
  <header class="acct-head">
    <h1>We can't find that page</h1>
    <p>The link may be old or mistyped. Search for a place, or head back to somewhere that exists.</p>
  </header>
  <div class="acct-actions nf-actions">
    <button type="button" class="ja-btn ja-btn-primary" data-ja-palette-open><?= ja_icon('search', 16) ?> Search the site</button>
    <a class="ja-btn ja-btn-ghost" href="plan-trip.php">Plan a trip</a>
    <a class="ja-btn ja-btn-ghost" href="<?= !empty($_SESSION['eml']) ? 'dashboard.php' : 'index.php' ?>">Go to Home</a>
  </div>
  <p class="acct-hint nf-tip">Tip: press <kbd class="ja-kbd">Ctrl K</kbd> on any page to search.</p>
</main>
<?php include __DIR__ . '/ja-footer.php'; ?>
</body>
</html>
