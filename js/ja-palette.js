/* JourneyAI command palette: Ctrl/Cmd+K (or "/", or any Search button) opens one dialog that
   - jumps to a page,
   - finds destinations as you type (suggest.php, a prepared LIKE query),
   - runs "plan: <sentence>" through the planner (plan-trip.php?q=...&go=1),
   - runs a few actions (switch theme, ask the assistant).
   Dialog behaviour: focus is trapped, Esc closes, arrow keys move, Enter opens, focus returns to where it was. */
(function () {
  'use strict';
  var box = document.getElementById('jaPal');
  if (!box) return;
  var input = document.getElementById('jaPalInput'), list = document.getElementById('jaPalList'),
      status = document.getElementById('jaPalStatus'), closeBtn = box.querySelector('.ja-pal-x');
  var pages = []; try { pages = JSON.parse(box.getAttribute('data-pages') || '[]'); } catch (e) {}
  var isMac = /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent || '');
  [].forEach.call(document.querySelectorAll('[data-ja-kbd]'), function (k) { k.textContent = isMac ? '⌘ K' : 'Ctrl K'; });

  var ICON = {
    page: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    pin: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    plan: '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
    act: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6.3 6.3l2.8 2.8M14.9 14.9l2.8 2.8M17.7 6.3l-2.8 2.8M9.1 14.9l-2.8 2.8"/>'
  };
  var KIND = { page: 'Page', pin: 'Place', plan: 'Planner', act: 'Action' };

  var items = [], active = 0, opener = null, ctl = null, timer = 0, places = [], placesFor = '', cache = {}, seq = 0;

  function svg(k) { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[k] + '</svg>'; }
  function isOpen() { return !box.hidden; }
  function say(t) { status.textContent = t; }

  function actions() {
    var a = [{ kind: 'act', label: window.jaThemeIsDark && window.jaThemeIsDark() ? 'Switch to light theme' : 'Switch to dark theme', run: function () { window.jaToggleTheme && window.jaToggleTheme(); } }];
    if (document.getElementById('jaChatFab')) a.push({ kind: 'act', label: 'Ask the assistant', run: function () { var f = document.getElementById('jaChatFab'); if (f && !document.getElementById('jaChat').classList.contains('open')) f.click(); else if (f) document.getElementById('jaChatText').focus(); } });
    return a;
  }
  function planItem(text) { return { kind: 'plan', label: 'Plan a trip: ' + text, sub: 'Opens the planner with this sentence', href: 'plan-trip.php?q=' + encodeURIComponent(text) + '&go=1' }; }

  function build() {
    var raw = input.value, q = raw.trim(), ql = q.toLowerCase(), groups = [];
    var pm = /^plan\s*:\s*(.*)$/i.exec(q);
    if (pm) {
      var t = pm[1].trim();
      groups.push({ name: 'Planner', rows: t ? [planItem(t)] : [{ kind: 'plan', label: 'Type your trip after plan:', sub: 'For example: plan: 3 days in Goa under 20k', disabled: true }] });
    } else {
      var pg = pages.filter(function (p) { return !ql || p.label.toLowerCase().indexOf(ql) > -1; }).map(function (p) { return { kind: 'page', label: p.label, sub: '', href: p.href }; });
      var ac = actions().filter(function (a) { return !ql || a.label.toLowerCase().indexOf(ql) > -1 || 'theme dark light'.indexOf(ql) > -1 && /theme/.test(a.label); });
      if (places.length && placesFor === ql) groups.push({ name: 'Places', rows: places.map(function (p) { return { kind: 'pin', label: p.name, sub: p.region, href: p.url }; }) });
      if (pg.length) groups.push({ name: 'Go to', rows: pg });
      if (ac.length) groups.push({ name: 'Actions', rows: ac });
      if (q.length >= 3) groups.push({ name: 'Planner', rows: [planItem(q)] });
    }
    return groups;
  }

  function render(keep) {
    var groups = build(); items = []; list.innerHTML = '';
    groups.forEach(function (g, gi) {
      var li = document.createElement('li'); li.setAttribute('role', 'presentation');
      var h = document.createElement('div'); h.className = 'ja-pal-h'; h.id = 'jaPalG' + gi; h.textContent = g.name;
      var ul = document.createElement('ul'); ul.setAttribute('role', 'group'); ul.setAttribute('aria-labelledby', h.id);
      g.rows.forEach(function (r) {
        var o = document.createElement('li'), idx = items.length; o.id = 'jaPalO' + idx; o.setAttribute('role', 'option'); o.className = 'ja-pal-item' + (r.disabled ? ' off' : '');
        o.setAttribute('aria-selected', 'false'); if (r.disabled) o.setAttribute('aria-disabled', 'true'); o.dataset.i = idx;
        o.innerHTML = '<span class="ja-pal-ic">' + svg(r.kind) + '</span><span class="ja-pal-t"></span><span class="ja-pal-k">' + KIND[r.kind] + '</span>';
        var t = o.querySelector('.ja-pal-t'); t.textContent = r.label;
        if (r.sub) { var s = document.createElement('small'); s.textContent = r.sub; t.appendChild(s); }
        ul.appendChild(o); items.push(r);
      });
      li.appendChild(h); li.appendChild(ul); list.appendChild(li);
    });
    if (!items.length) { var e = document.createElement('li'); e.className = 'ja-pal-empty'; e.setAttribute('role', 'presentation'); e.textContent = 'Nothing matches. Keep typing a place name, or start with plan: to describe a trip.'; list.appendChild(e); }
    if (!keep || active >= items.length) active = 0;
    while (active < items.length && items[active].disabled) active++;
    mark();
    say(items.length ? items.length + (items.length === 1 ? ' result' : ' results') : 'No results');
  }

  function mark() {
    [].forEach.call(list.querySelectorAll('.ja-pal-item'), function (o) { o.setAttribute('aria-selected', +o.dataset.i === active ? 'true' : 'false'); });
    var cur = items.length ? document.getElementById('jaPalO' + active) : null;
    input.setAttribute('aria-activedescendant', cur ? cur.id : '');
    if (cur) cur.scrollIntoView({ block: 'nearest' });
  }
  function move(d) {
    if (!items.length) return; var n = items.length, i = active;
    do { i = (i + d + n) % n; } while (items[i].disabled && i !== active);
    active = i; mark();
  }
  function go(r) {
    if (!r || r.disabled) return;
    close(true);
    if (r.run) r.run(); else if (r.href) window.location.href = r.href;
  }

  function fetchPlaces() {
    var q = input.value.trim(), ql = q.toLowerCase();
    if (ctl) ctl.abort();
    if (q.length < 2 || /^plan\s*:/i.test(q)) { places = []; placesFor = ''; return; }
    if (cache[ql]) { places = cache[ql]; placesFor = ql; render(); return; }
    var my = ++seq; ctl = window.AbortController ? new AbortController() : null;
    fetch('suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
      .then(function (r) { if (r.status === 429) { say('Too many searches. Wait a few seconds.'); return null; } return r.json(); })
      .then(function (d) { if (!d || my !== seq) return; cache[ql] = d.results || []; places = cache[ql]; placesFor = ql; if (input.value.trim().toLowerCase() === ql) render(); })
      .catch(function () {});
  }

  function open() {
    if (isOpen()) return;
    opener = document.activeElement; box.hidden = false; document.documentElement.classList.add('ja-pal-open');
    input.value = ''; places = []; placesFor = ''; active = 0; render(); input.focus();
  }
  function close(skipFocus) {
    if (!isOpen()) return;
    box.hidden = true; document.documentElement.classList.remove('ja-pal-open'); if (ctl) ctl.abort();
    if (!skipFocus && opener && opener.focus) opener.focus();
  }

  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 'k') { e.preventDefault(); isOpen() ? close() : open(); return; }
    if (!isOpen()) {
      var t = e.target, typing = t && (/^(input|textarea|select)$/i.test(t.tagName) || t.isContentEditable);
      if (k === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); open(); }
      return;
    }
    if (k === 'escape') { e.preventDefault(); close(); }
    else if (k === 'arrowdown') { e.preventDefault(); move(1); }
    else if (k === 'arrowup') { e.preventDefault(); move(-1); }
    else if (k === 'home' && e.target === input && !input.value) { e.preventDefault(); active = 0; mark(); }
    else if (k === 'enter' && e.target === input) { e.preventDefault(); go(items[active]); }
    else if (k === 'tab') {   // focus trap: input <-> close button
      var f = [input, closeBtn], i = f.indexOf(document.activeElement);
      e.preventDefault(); f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
    }
  });
  input.addEventListener('input', function () { active = 0; render(); clearTimeout(timer); timer = setTimeout(fetchPlaces, 150); });
  list.addEventListener('click', function (e) { var o = e.target.closest('.ja-pal-item'); if (o) go(items[+o.dataset.i]); });
  list.addEventListener('mousemove', function (e) { var o = e.target.closest('.ja-pal-item'); if (o && +o.dataset.i !== active && !o.classList.contains('off')) { active = +o.dataset.i; mark(); } });
  box.addEventListener('click', function (e) { if (e.target.closest('[data-ja-pal-close]')) close(); });
  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-ja-palette-open]'); if (b) { e.preventDefault(); open(); } });
  window.jaOpenPalette = open;
})();
