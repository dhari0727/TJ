/* JourneyAI "Calm Journey" behaviour. Everything here is enhancement: with JS off or reduced motion the pages still work.
   - every .ja-btn ripples on press, likes/pins pop, window.cjToast() for quiet confirmations
   - landing only ([data-cj-landing]): scroll route with a travelling pin, drifting leaves, section dots, reveals */
(function () {
  var RM = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var NS = 'http://www.w3.org/2000/svg';

  /* ---- press ripple ---- */
  document.addEventListener('pointerdown', function (e) {
    var b = e.target.closest && e.target.closest('.ja-btn'); if (!b || RM) return;
    var r = b.getBoundingClientRect(), d = Math.max(r.width, r.height), s = document.createElement('span');
    s.className = 'cj-rip'; s.style.cssText = 'width:' + d + 'px;height:' + d + 'px;left:' + (e.clientX - r.left - d / 2) + 'px;top:' + (e.clientY - r.top - d / 2) + 'px';
    b.appendChild(s); setTimeout(function () { s.remove(); }, 650);
  });

  /* ---- toast ---- */
  var tt, toastEl;
  window.cjToast = function (msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'cj-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('on'); clearTimeout(tt); tt = setTimeout(function () { toastEl.classList.remove('on'); }, 2200);
  };

  /* ---- likes and pins: a small pop, and a few hearts rising when something becomes liked ---- */
  var HEART = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 20s-8-5-8-11a4.5 4.5 0 018-2.5A4.5 4.5 0 0120 9c0 6-8 11-8 11z"/></svg>';
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[class*="likebtn"],[data-pin-kind],[data-like]'); if (!b || RM) return;
    b.classList.remove('cj-popping'); void b.offsetWidth; b.classList.add('cj-popping');
    setTimeout(function () {
      if (!/liked|pinned|\bon\b/.test(b.className) && b.getAttribute('data-pinned') !== '1') return;
      var r = b.getBoundingClientRect();
      for (var i = 0; i < 5; i++) {
        var h = document.createElement('div'); h.className = 'cj-heartfly'; h.innerHTML = HEART;
        h.style.left = (r.left + r.width / 2 - 8) + 'px'; h.style.top = (r.top + r.height / 2 - 8) + 'px'; document.body.appendChild(h);
        var dx = (Math.random() - .5) * 70;
        h.animate([{ transform: 'translate(0,0) scale(.6)', opacity: 1 }, { transform: 'translate(' + dx + 'px,-' + (50 + Math.random() * 40) + 'px) scale(1.1)', opacity: 0 }],
          { duration: 900 + Math.random() * 300, easing: 'cubic-bezier(.2,.8,.3,1)' }).onfinish = (function (n) { return function () { n.remove(); }; })(h);
      }
    }, 80);
  });

  /* ---- accessibility: every field gets an accessible name (label[for], aria-label from the nearest label or placeholder) ---- */
  function nameFields(root) {
    var n = 0;
    [].forEach.call((root || document).querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]),select,textarea'), function (el) {
      if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.closest('label')) return;
      if (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '\\"') + '"]')) return;
      var lab = null, prev = el.previousElementSibling;
      if (prev && prev.tagName === 'LABEL' && !prev.htmlFor) lab = prev;
      if (!lab && el.parentElement) { var c = el.parentElement.querySelector(':scope > label:not([for])'); if (c) lab = c; }
      if (lab) { if (!el.id) el.id = 'cj-f' + (++n) + Math.random().toString(36).slice(2, 6); lab.htmlFor = el.id; return; }
      var t = el.getAttribute('placeholder') || el.getAttribute('title') || el.name;
      if (t) el.setAttribute('aria-label', t.replace(/[_-]+/g, ' '));
    });
  }
  nameFields();
  new MutationObserver(function (m) { m.forEach(function (x) { x.addedNodes.forEach(function (nd) { if (nd.nodeType === 1) nameFields(nd); }); }); }).observe(document.body, { childList: true, subtree: true });

  /* ---- theme: saved choice wins, otherwise the system preference (the head script already applied it before first paint) ---- */
  var root = document.documentElement, mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var effective = function () { var t = root.getAttribute('data-theme'); return t === 'dark' || t === 'light' ? t : (mq && mq.matches ? 'dark' : 'light'); };
  var paintTheme = function () {
    var dark = effective() === 'dark', meta = $('meta[name=theme-color]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'theme-color'; document.head.appendChild(meta); }
    meta.content = dark ? '#0E1424' : '#F5F6F2';
    [].forEach.call(document.querySelectorAll('[data-ja-theme-toggle]'), function (b) {
      b.__jaBound = true;   // js/journeyai.js skips buttons already bound, so a click flips the theme once
      b.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme'); b.title = dark ? 'Switch to light theme' : 'Switch to dark theme';
      var l = b.querySelector('[data-ja-theme-label]'); if (l) l.textContent = dark ? 'Light theme' : 'Dark theme';
    });
  };
  window.jaSetTheme = function (t) {
    root.setAttribute('data-theme', t); try { localStorage.setItem('ja-theme', t); } catch (e) {}
    paintTheme(); window.dispatchEvent(new CustomEvent('ja-theme', { detail: t }));
  };
  window.jaToggleTheme = function () { window.jaSetTheme(effective() === 'dark' ? 'light' : 'dark'); };
  window.jaThemeIsDark = function () { return effective() === 'dark'; };
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-ja-theme-toggle]'); if (!b) return;
    e.preventDefault(); window.jaToggleTheme();
  });
  if (mq && mq.addEventListener) mq.addEventListener('change', paintTheme);
  paintTheme();

  /* ---- skip link: move focus to <main> (works even when a <base> tag is present) ---- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-ja-skip]'); if (!a) return;
    var m = document.getElementById('main'); if (!m) return;
    e.preventDefault(); if (!m.hasAttribute('tabindex')) m.setAttribute('tabindex', '-1');
    m.focus({ preventScroll: true }); m.scrollIntoView({ block: 'start' });
  });

  /* ---- top bar menu on phones ---- */
  var nav = $('#jaNav'), menu = $('#jaNavMenu');
  if (nav && menu) {
    var setMenu = function (o) { nav.classList.toggle('open', o); menu.setAttribute('aria-expanded', o ? 'true' : 'false'); menu.setAttribute('aria-label', o ? 'Close menu' : 'Open menu'); };
    menu.addEventListener('click', function () { setMenu(!nav.classList.contains('open')); });
    document.addEventListener('click', function (e) { if (nav.classList.contains('open') && !nav.contains(e.target)) setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('open')) { setMenu(false); menu.focus(); } });
  }

  /* ---- landing page ---- */
  var land = $('[data-cj-landing]'); if (!land) return;

  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: .15 }) : null;
  [].forEach.call(document.querySelectorAll('.cj-rv'), function (el) { io ? io.observe(el) : el.classList.add('in'); });

  /* scroll route: the line draws, the pin travels, each stop opens as the pin reaches it */
  var route = $('#cjRoute');
  if (route) {
    var base = $('#cjBase'), fill = $('#cjFill'), pin = $('#cjPin'), map = $('#cjMap'), dots = $('#cjDots'), L = base.getTotalLength();
    var stops = [].slice.call(map.querySelectorAll('.cj-stop'));
    fill.style.strokeDasharray = L; fill.style.strokeDashoffset = L;
    stops.forEach(function (el) {
      var f = parseFloat(el.dataset.f), p = base.getPointAtLength(f * L), side = p.x > 500 ? -1 : 1;
      el.style.left = (p.x / 10) + '%'; el.style.top = (p.y / 24) + '%';
      el.style.setProperty('--dx', side * (window.innerWidth > 860 ? 190 : 0) + 'px'); el.style.setProperty('--dy', '50px');
      var c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', p.x); c.setAttribute('cy', p.y); c.setAttribute('r', 16);
      c.setAttribute('fill', 'var(--paper, #fff)'); c.setAttribute('stroke', 'currentColor'); c.setAttribute('stroke-opacity', '.4'); c.setAttribute('stroke-width', 5); dots.appendChild(c);
      el._dot = c; el._f = f;
    });
    var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
    var upd = function () {
      var mr = map.getBoundingClientRect(), p = RM ? 1 : clamp((innerHeight * .55 - mr.top) / mr.height, 0, 1), pt = base.getPointAtLength(p * L);
      pin.setAttribute('transform', 'translate(' + pt.x + ',' + pt.y + ')'); fill.style.strokeDashoffset = L * (1 - p);
      stops.forEach(function (el) {
        var on = p >= el._f - .015;
        if (on !== el.classList.contains('on')) {
          el.classList.toggle('on', on);
          el._dot.setAttribute('fill', on ? '#F4A300' : 'var(--paper, #fff)'); el._dot.setAttribute('stroke', on ? '#14213D' : 'currentColor');
        }
      });
    };
    addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
  }

})();
