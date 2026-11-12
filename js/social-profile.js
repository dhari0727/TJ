/* Profile > Connected accounts: connect (Bluesky app password, Mastodon server, owner-registered apps) and disconnect. All through social-api.php. */
(function () {
  'use strict';
  var root = document.getElementById('connected-accounts'); if (!root) return;
  var CFG = window.JA_SOC || {}, msg = document.getElementById('socMsg');
  function say(t, bad) { msg.hidden = false; msg.className = 'soc-msg ' + (bad ? 'ja-err' : 'ja-ok'); msg.setAttribute('role', bad ? 'alert' : 'status'); msg.textContent = t; }
  function post(action, data) {
    var t = window.JA_CSRF || CFG.csrf; var b = new URLSearchParams(data || {}); b.set('action', action);
    return fetch(CFG.api || 'social-api.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': t, 'Accept': 'application/json' }, body: b })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Could not reach the server. Check your connection.' }; });
  }
  root.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-soc-form]'); if (!f) return;
    e.preventDefault(); e.stopPropagation();
    var kind = f.getAttribute('data-soc-form'), btn = f.querySelector('button[type=submit]'), old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Connecting...';
    var p = kind === 'bluesky' ? post('connect_bluesky', { handle: f.handle.value, app_password: f.app_password.value })
                               : post('connect_start', { provider: 'mastodon', instance: f.instance.value });
    p.then(function (d) {
      btn.disabled = false; btn.textContent = old;
      if (!d.ok) { say(d.error || 'Could not connect.', true); return; }
      if (kind === 'bluesky') { f.app_password.value = ''; location.reload(); return; }
      if (d.url) location.href = d.url;
    });
  }, true);
  root.addEventListener('click', function (e) {
    var c = e.target.closest('[data-soc-connect]');
    if (c) {
      c.disabled = true;
      post('connect_start', { provider: c.getAttribute('data-soc-connect') }).then(function (d) {
        c.disabled = false;
        if (!d.ok) { say(d.error || 'Could not connect.', true); return; }
        location.href = d.url;
      });
      return;
    }
    var tb = e.target.closest('[data-soc-test]');
    if (tb) {
      var out = tb.closest('.soc-p').querySelector('[data-soc-testres]'), old = tb.textContent;
      tb.disabled = true; tb.textContent = 'Testing...';
      post('test_connection', { account_id: tb.getAttribute('data-soc-test') }).then(function (d) {
        tb.disabled = false; tb.textContent = old;
        var t = d.message || d.error || 'The test did not return a result.';
        if (out) { out.hidden = false; out.textContent = t; out.className = 'soc-testres ' + (d.ok ? 'ok' : 'bad'); } else say(t, !d.ok);
      });
      return;
    }
    var x = e.target.closest('[data-soc-disconnect]');
    if (x) {
      if (!confirm('Disconnect ' + x.getAttribute('data-label') + '? We delete the saved sign-in and ask the service to revoke it. Your posts stay where they are.')) return;
      x.disabled = true;
      post('disconnect', { account_id: x.getAttribute('data-soc-disconnect') }).then(function (d) {
        if (d.ok) location.reload(); else { x.disabled = false; say(d.error || 'Could not disconnect.', true); }
      });
    }
  });
})();
