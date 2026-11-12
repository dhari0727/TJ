<?php
/**
 * Profile > Connected accounts. Included by profile.php. Needs $emll (the signed-in email).
 * The page script is js/social-profile.js (connect, disconnect through social-api.php).
 */
require_once __DIR__ . '/ja-social.php';
require_once __DIR__ . '/ja-instagram-kit.php';
$socRes = $_SESSION['soc_result'] ?? null; unset($_SESSION['soc_result']);
$socAll = ja_social_providers();
$socAcc = [];
foreach (ja_social_accounts($emll) as $a) $socAcc[$a['provider']][] = $a;
$socHist = [];
$hq = mysqli_prepare(ja_db(), "SELECT sp.provider, sp.kind, sp.status, sp.remote_url, sp.error, sp.created_at, a.handle
    FROM social_posts sp LEFT JOIN social_accounts a ON a.account_id = sp.account_id WHERE sp.eml = ? ORDER BY sp.post_id DESC LIMIT 15");
mysqli_stmt_bind_param($hq, 's', $emll); mysqli_stmt_execute($hq);
$socHist = mysqli_fetch_all(mysqli_stmt_get_result($hq), MYSQLI_ASSOC); mysqli_stmt_close($hq);
$socKinds = ['journal' => 'Journal', 'book' => 'Storybook', 'post' => 'Photo post', 'plan' => 'Trip plan', 'upload' => 'Camera photo'];
$socRow = function ($p) use ($socAcc) {
    $accounts = $socAcc[$p->id] ?? [];
    ob_start(); ?>
    <li class="soc-p" data-provider="<?= htmlspecialchars($p->id) ?>">
      <span class="soc-aico" aria-hidden="true"><?= ja_social_icon($p->id, 22) ?></span>
      <div class="soc-pm"><strong><?= htmlspecialchars($p->label) ?></strong><small><?= htmlspecialchars($p->note) ?></small></div>
      <div class="soc-pa">
        <?php if (!$p->configured()): ?>
          <span class="soc-state">Not set up by the site owner</span>
        <?php elseif ($p->id === 'instagram'): ?>
          <button type="button" class="ja-btn ja-btn-primary soc-igbtn" data-soc-connect="instagram"><?= ja_social_icon('instagram', 18) ?><span><?= $accounts ? 'Log in again with Instagram' : 'Log in with Instagram' ?></span></button>
        <?php elseif ($p->auth === 'oauth'): ?>
          <button type="button" class="ja-btn ja-btn-ghost" data-soc-connect="<?= htmlspecialchars($p->id) ?>"><?= $accounts ? 'Add another' : 'Connect' ?></button>
        <?php elseif ($p->auth === 'instance'): ?>
          <span class="soc-state ok">Ready</span>
        <?php else: ?>
          <span class="soc-state ok">Ready</span>
        <?php endif; ?>
      </div>
      <?php foreach ($accounts as $a): ?>
      <div class="soc-acct">
        <span class="soc-state <?= $a['status'] === 'active' ? 'ok' : 'warn' ?>"><?= $a['status'] === 'active' ? 'Connected' : 'Needs reconnecting' ?></span>
        <strong><?= htmlspecialchars($a['handle'] ?: $a['display_name']) ?></strong>
        <?php if ($p->id === 'instagram' && $a['expires_at']): ?><small class="soc-hint">Sign-in valid until <?= htmlspecialchars(date('j M Y', strtotime($a['expires_at']))) ?></small><?php endif; ?>
        <?php if ($p->id === 'instagram' && $p->configured()): ?><button type="button" class="ja-btn ja-btn-ghost" data-soc-test="<?= (int)$a['account_id'] ?>">Test connection</button><?php endif; ?>
        <?php if ($a['status'] !== 'active' && $p->configured()): ?>
          <?php if ($p->auth === 'oauth' && $p->id !== 'instagram'): ?><button type="button" class="ja-btn ja-btn-ghost" data-soc-connect="<?= htmlspecialchars($p->id) ?>">Reconnect</button><?php endif; ?>
        <?php endif; ?>
        <button type="button" class="ja-btn ja-btn-ghost" data-soc-disconnect="<?= (int)$a['account_id'] ?>" data-label="<?= htmlspecialchars($p->label . ' ' . $a['handle'], ENT_QUOTES) ?>">Disconnect</button>
      </div>
      <?php endforeach; ?>
      <?php if ($p->id === 'instagram'): ?>
        <p class="soc-hint soc-igtxt" style="flex-basis:100%;margin:6px 0 0">You log in on instagram.com itself. This site never sees or stores your Instagram password; it only receives a permission token you can remove at any time. You need a Business or Creator account. <?= igk_public_ok() ? '' : 'Direct posting also needs this site to be online over https, which it is not yet; you can still log in and test the connection.' ?></p>
        <p class="soc-testres" data-soc-testres role="status" aria-live="polite" hidden style="flex-basis:100%;margin:6px 0 0"></p>
      <?php endif; ?>
      <?php if ($p->auth === 'password'): ?>
      <form class="soc-form" data-soc-form="bluesky" autocomplete="off">
        <input class="ja-input" name="handle" placeholder="you.bsky.social" aria-label="Bluesky handle" required>
        <input class="ja-input" name="app_password" type="password" placeholder="App password (xxxx-xxxx-xxxx-xxxx)" aria-label="Bluesky app password" autocomplete="new-password" required>
        <button class="ja-btn ja-btn-primary" type="submit">Connect</button>
        <small class="soc-hint" style="flex-basis:100%">Create an app password at <a href="https://bsky.app/settings/app-passwords" target="_blank" rel="noopener noreferrer">bsky.app/settings/app-passwords</a>. We use it once to sign in and do not keep it. Never type your main password here.</small>
      </form>
      <?php elseif ($p->auth === 'instance'): ?>
      <form class="soc-form" data-soc-form="mastodon">
        <input class="ja-input" name="instance" placeholder="Your server, e.g. mastodon.social" aria-label="Mastodon server" required>
        <button class="ja-btn ja-btn-primary" type="submit">Connect</button>
        <small class="soc-hint" style="flex-basis:100%">You will sign in on your own server and approve posting only. We never see your password.</small>
      </form>
      <?php endif; ?>
    </li>
    <?php return ob_get_clean();
};
?>
  <section class="acct-sec soc-conn" id="connected-accounts" aria-labelledby="s-social">
    <div class="acct-sec-h"><h2 id="s-social">Connected accounts</h2><p>Post journals, storybooks and photos straight to your social accounts. You always see and confirm the exact text and photos first, and nothing is ever posted automatically.</p></div>
    <div class="acct-sec-b">
      <?php if ($socRes): ?><div class="<?= $socRes['ok'] ? 'ja-ok' : 'ja-err' ?> soc-msg" role="<?= $socRes['ok'] ? 'status' : 'alert' ?>"><?= htmlspecialchars($socRes['msg']) ?></div><?php endif; ?>
      <div id="socMsg" class="soc-msg" role="status" aria-live="polite" hidden></div>
      <h3>Instagram</h3>
      <ul class="soc-prov"><?php if (isset($socAll['instagram'])) echo $socRow($socAll['instagram']); ?></ul>
      <p class="acct-hint">No setup needed to share on Instagram from your phone: open a public journal, storybook or photo, press Share, then Instagram. The site makes the images and caption for you.</p>
      <h3>Connect now, no approval needed</h3>
      <ul class="soc-prov"><?php foreach ($socAll as $p) if ($p->tier === 2) echo $socRow($p); ?></ul>
      <h3>Needs the site owner to set up an app</h3>
      <ul class="soc-prov"><?php foreach ($socAll as $p) if ($p->tier === 3 && $p->id !== 'instagram') echo $socRow($p); ?></ul>

      <h3>Post history</h3>
      <?php if (!$socHist): ?><p class="acct-hint">Nothing posted yet. Use the Share button on a public journal, storybook or photo.</p>
      <?php else: ?>
      <div class="soc-hist-wrap"><table class="soc-hist"><thead><tr><th>When</th><th>Where</th><th>What</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($socHist as $r): ?>
          <tr><td><?= htmlspecialchars(date('j M, H:i', strtotime($r['created_at']))) ?></td>
            <td><?= htmlspecialchars(($socAll[$r['provider']]->label ?? $r['provider']) . ($r['handle'] ? ' ' . $r['handle'] : '')) ?></td>
            <td><?= htmlspecialchars($socKinds[$r['kind']] ?? $r['kind']) ?></td>
            <td><?php if ($r['status'] === 'posted'): ?><span class="ok">Posted</span><?= $r['remote_url'] ? ' <a href="' . htmlspecialchars($r['remote_url']) . '" target="_blank" rel="noopener noreferrer">View</a>' : '' ?>
              <?php elseif ($r['status'] === 'failed'): ?><span class="bad">Not posted</span><br><small><?= htmlspecialchars($r['error']) ?></small>
              <?php else: ?>Sending<?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
  </section>
  <script>window.JA_SOC = { api: "social-api.php", loggedIn: true, csrf: <?= json_encode(ja_csrf()) ?> };</script>
  <script src="js/social-profile.js?v=<?= (int)@filemtime(__DIR__ . '/js/social-profile.js') ?>" defer></script>
