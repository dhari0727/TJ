/* JourneyAI trip pages: toast, share, print, collapsible days, delete with undo. No libraries, no alert(). */
(function () {
  'use strict';
  var toastEl = null, toastTimer = null;

  function toast(msg, actionLabel, onAction, ms) {
    if (toastEl) { toastEl.remove(); clearTimeout(toastTimer); }
    toastEl = document.createElement('div');
    toastEl.className = 'tp-toast';
    toastEl.setAttribute('role', 'status');
    var s = document.createElement('span'); s.textContent = msg; toastEl.appendChild(s);
    if (actionLabel) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = actionLabel;
      b.addEventListener('click', function () { clear(); if (onAction) onAction(); });
      toastEl.appendChild(b);
    }
    document.body.appendChild(toastEl);
    toastTimer = setTimeout(clear, ms || 4000);
    function clear() { clearTimeout(toastTimer); if (toastEl) { toastEl.remove(); toastEl = null; } }
  }
  window.JATrips = { toast: toast };

  /* ---- share the current page ---- */
  function share() {
    var url = location.href.replace(/[?&]saved=1/, ''), title = document.title;
    if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () { toast('Link copied. Anyone with it can open this plan.'); },
        function () { toast('Could not copy the link. Copy it from the address bar.'); });
    } else toast('Copy the link from the address bar to share this plan.');
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tp-share]'); if (t) { e.preventDefault(); share(); return; }
    t = e.target.closest('[data-tp-print]'); if (t) { e.preventDefault(); window.print(); return; }
  });

  /* ---- print: open every day first, then put them back ---- */
  var wasClosed = [];
  window.addEventListener('beforeprint', function () {
    wasClosed = [];
    document.querySelectorAll('details.tp-day').forEach(function (d) { if (!d.open) { wasClosed.push(d); d.open = true; } });
  });
  window.addEventListener('afterprint', function () { wasClosed.forEach(function (d) { d.open = false; }); wasClosed = []; });

  /* ---- collapse / expand all days ---- */
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('tpToggleDays');
    if (b) b.addEventListener('click', function () {
      var open = b.dataset.state !== 'open';
      document.querySelectorAll('details.tp-day').forEach(function (d) { d.open = open; });
      b.dataset.state = open ? 'open' : 'closed';
      b.textContent = open ? 'Collapse all days' : 'Expand all days';
    });
  });

  /* ---- delete with undo: the row hides at once, the request goes out after the undo window ---- */
  var pending = [];
  function send(p) {
    if (p.sent) return; p.sent = true;
    var body = new URLSearchParams(p.body);
    return fetch(p.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-del]'); if (!btn) return;
    e.preventDefault();
    var card = btn.closest('.tp-plan'), name = btn.dataset.name || 'item';
    var p = { sent: false, url: btn.dataset.url, body: JSON.parse(btn.dataset.body || '{}') };
    if (card) card.classList.add('is-gone');
    btn.disabled = true;
    pending.push(p);
    p.timer = setTimeout(function () {
      var r = send(p);
      var done = function () {
        if (btn.dataset.redirect) { location.href = btn.dataset.redirect; return; }
        if (!document.querySelector('.tp-plan:not(.is-gone)')) location.reload();
      };
      if (r) r.then(done, done); else done();
    }, 6000);
    toast('Deleted ' + name + '.', 'Undo', function () {
      clearTimeout(p.timer); p.sent = true;
      if (card) card.classList.remove('is-gone');
      btn.disabled = false;
      toast('Restored ' + name + '.');
    }, 6000);
  });
  window.addEventListener('pagehide', function () { pending.forEach(function (p) { if (!p.sent) { clearTimeout(p.timer); send(p); } }); });

  /* ---- my plans: share link ---- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('#jaShareBtn'); if (!b) return;
    fetch('plan-share.php', { method: 'POST', body: new URLSearchParams({ plan_id: b.dataset.planId }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { toast(d.error || 'Could not create a share link. Try again.'); return; }
        var box = document.getElementById('jaShareBox'), inp = document.getElementById('jaShareUrl');
        box.hidden = false; inp.value = d.url; inp.focus(); inp.select();
      }, function () { toast('Could not reach the server. Check your connection.'); });
  });
  document.addEventListener('click', function (e) {
    var c = e.target.closest('#jaShareCopy'); if (!c) return;
    var inp = document.getElementById('jaShareUrl'); inp.select();
    if (navigator.clipboard) navigator.clipboard.writeText(inp.value).then(function () { toast('Link copied.'); });
  });
})();
