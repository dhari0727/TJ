/* JourneyAI landing page. Only transform and opacity are animated; scroll work is batched into one
   requestAnimationFrame per frame and all layout reads happen before any writes. */
(function () {
  'use strict';
  var D = document, root = D.documentElement;
  if (!D.body.classList.contains('lp')) return;
  root.classList.add('lp-js');

  var RM = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, c) { return (c || D).querySelector(s); };
  var $$ = function (s, c) { return [].slice.call((c || D).querySelectorAll(s)); };
  var clamp = function (v, a, b) { return v < a ? a : v > b ? b : v; };
  var ease = function (t) { return t * t * t * (t * (t * 6 - 15) + 10); };
  var NS = 'http://www.w3.org/2000/svg';

  /* the sticky site nav can change height; keep the pinned sections below it */
  var nav = $('#jaNav');
  var setNav = function () { if (nav) root.style.setProperty('--lp-nav', nav.offsetHeight + 'px'); };
  setNav();

  /* ---------------- scroll scheduler ---------------- */
  var tasks = [], queued = false;
  function onFrame(fn) { tasks.push(fn); }
  function run() { queued = false; for (var i = 0; i < tasks.length; i++) tasks[i](); }
  function ask() { if (!queued) { queued = true; requestAnimationFrame(run); } }
  addEventListener('scroll', ask, { passive: true });
  addEventListener('resize', function () { setNav(); ask(); });

  /* ---------------- hero: crossfade and parallax ---------------- */
  var hero = $('#lpHero');
  if (hero) {
    var slides = $$('.lp-slide', hero), caps = $$('.lp-cap figcaption', hero), ticks = $$('.lp-ticks i', hero), cur = 0, timer = null, SPAN = 7000;
    var show = function (n) {
      [slides, caps, ticks].forEach(function (list) { list.forEach(function (el, i) { el.classList.toggle('on', i === n); if (list === caps) { if (i === n) el.removeAttribute('aria-hidden'); else el.setAttribute('aria-hidden', 'true'); } }); });
      cur = n;
    };
    var heroVisible = true;
    var start = function () { stop(); if (slides.length > 1 && !RM && heroVisible && !D.hidden) timer = setInterval(function () { show((cur + 1) % slides.length); }, SPAN); };
    var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
    if (slides.length > 1 && !RM) {
      start();
      D.addEventListener('visibilitychange', function () { D.hidden ? stop() : start(); });
      if ('IntersectionObserver' in window) new IntersectionObserver(function (es) { heroVisible = es[0].isIntersecting; heroVisible ? (ticks[cur] && (ticks[cur].classList.remove('on'), void ticks[cur].offsetWidth, ticks[cur].classList.add('on')), start()) : stop(); }).observe(hero);
    }
    var media = $('.lp-media', hero);
    if (media && !RM) onFrame(function () {
      var y = window.pageYOffset, h = hero.offsetHeight;
      if (y < h * 1.2) media.style.transform = 'translate3d(0,' + (y * 0.22).toFixed(1) + 'px,0)';
    });
  }

  /* ---------------- planner bar ---------------- */
  var dock = $('#lpDock');
  if (dock) {
    var where = $('#lpWhere'), days = $('#lpDays'), bud = $('#lpBud'), list = $('#lpPlaces');
    $$('.lp-sb', dock).forEach(function (b) {
      b.addEventListener('click', function () {
        var v = parseInt(days.value, 10), d = parseInt(b.getAttribute('data-step'), 10);
        days.value = isNaN(v) ? 3 : clamp(v + d, 1, 14);
      });
    });
    days.addEventListener('change', function () { var v = parseInt(days.value, 10); days.value = isNaN(v) ? '' : clamp(v, 1, 14); });
    bud.addEventListener('input', function () { var d = bud.value.replace(/[^\d]/g, ''); bud.value = d ? Number(d).toLocaleString('en-IN') : ''; });

    // destination suggestions as you type (same endpoint as the search palette)
    var t = null, last = '';
    where.addEventListener('input', function () {
      var q = where.value.trim();
      // only the last place in "A to B" is suggested
      var m = q.split(/\s+to\s+|\s+from\s+/i); q = (m[m.length - 1] || '').trim();
      clearTimeout(t);
      if (q.length < 2 || q === last) return;
      t = setTimeout(function () {
        last = q;
        fetch('suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
          if (!j || !j.results) return;
          var prefix = where.value.trim().slice(0, where.value.trim().length - q.length);
          list.textContent = '';
          j.results.forEach(function (r) { var o = D.createElement('option'); o.value = prefix + r.name.split(',')[0]; list.appendChild(o); });
        }).catch(function () {});
      }, 180);
    });

    // build one plain sentence for the planner; without JS the text field is sent as it is
    var hid = D.createElement('input'); hid.type = 'hidden'; hid.name = 'q'; hid.disabled = true; dock.appendChild(hid);
    var setNames = function (on) { where.name = on ? 'q' : ''; days.disabled = !on; bud.disabled = !on; hid.disabled = on; };
    dock.addEventListener('submit', function () {
      var w = where.value.trim(), n = parseInt(days.value, 10), b = bud.value.replace(/[^\d]/g, ''), s = '';
      if (w && !/\d/.test(w) && !n && !b) { return; }                       // a plain sentence: send it unchanged
      if (n) s = n + (n === 1 ? ' day' : ' days');
      if (w) s = s ? (/\s(to|from)\s/i.test(w) ? w + ', ' + s : s + ' in ' + w) : w;
      if (b) s = (s ? s + ' ' : '') + 'under ' + b;
      if (!s) s = 'plan a trip';
      hid.value = s; setNames(false);
    });
    addEventListener('pageshow', function () { setNames(true); });
  }

  /* ---------------- the journey ---------------- */
  var J = $('#lpJourney');
  if (J && !RM && 'IntersectionObserver' in window) {
    var track = $('.lp-j-track', J), stick = $('.lp-j-stick', J), svg = $('#lpMap'), pin = $('#lpPin'), dust = $('#lpDust'), stepEl = $('#lpStep');
    var segs = $$('.lp-seg', J), cards = $$('.lp-stop', J), dots = $$('.lp-dot', J), n = cards.length;
    var VH = parseFloat(svg.getAttribute('data-h')), lens = segs.map(function (s) { return s.getTotalLength(); });
    segs.forEach(function (s, i) { s.style.strokeDasharray = lens[i]; s.style.strokeDashoffset = lens[i]; });
    J.classList.add('lp-pinned');
    var pinScale = 1, span = 1, active = -1, lastDust = null, offs = lens.slice(), dustIx = 0, pool = [];
    for (var k = 0; k < 10; k++) { var c = D.createElementNS(NS, 'circle'); c.setAttribute('class', 'lp-dust'); c.setAttribute('r', 5); c.setAttribute('opacity', 0); dust.appendChild(c); pool.push(c); }

    var measure = function () {
      var h = svg.getBoundingClientRect().height || 1;
      var u = VH / h;                                  // svg units per css pixel
      svg.style.setProperty('--u', u.toFixed(3));
      pinScale = Math.max(0.5, u * 0.88);
      span = Math.max(1, track.offsetHeight - stick.offsetHeight);
    };
    var spawn = function (x, y) {
      var c = pool[dustIx++ % pool.length], a = (Math.random() - .5) * 26;
      c.setAttribute('cx', x); c.setAttribute('cy', y + 2);
      if (c.animate) c.animate([{ opacity: .55, transform: 'translate(0,0) scale(1)' }, { opacity: 0, transform: 'translate(' + a + 'px,' + (6 + Math.random() * 10) + 'px) scale(.2)' }], { duration: 1000, easing: 'ease-out' });
    };
    var setActive = function (a) {
      if (a === active) return;
      active = a;
      cards.forEach(function (el, i) { el.classList.toggle('is-on', i === a); el.classList.toggle('is-past', i < a); el.classList.toggle('is-next', i > a); });
      dots.forEach(function (el, i) { el.classList.toggle('cur', i === a); });
      if (stepEl) stepEl.textContent = 'Stop ' + (a + 1) + ' of ' + n;
    };
    var render = function () {
      var r = track.getBoundingClientRect();
      var p = clamp(-r.top / span, 0, 1), t = p * (n - 1);
      var seg = Math.min(Math.floor(t), n - 2), local = t - seg;
      if (p >= 1) { seg = n - 2; local = 1; }
      var u = ease(clamp((local - .16) / .68, 0, 1));
      for (var i = 0; i < segs.length; i++) {
        var o = i < seg ? 0 : i > seg ? lens[i] : lens[i] * (1 - u);
        if (Math.abs(o - offs[i]) > .2) { segs[i].style.strokeDashoffset = o; offs[i] = o; }
      }
      var pt = segs[seg].getPointAtLength(u * lens[seg]);
      pin.setAttribute('transform', 'translate(' + pt.x.toFixed(1) + ',' + pt.y.toFixed(1) + ') scale(' + pinScale.toFixed(3) + ')');
      if (!lastDust || Math.hypot(pt.x - lastDust[0], pt.y - lastDust[1]) > 16 * pinScale) { if (lastDust && u > 0 && u < 1) spawn(pt.x, pt.y); lastDust = [pt.x, pt.y]; }
      for (var d = 0; d < n; d++) { var on = t >= d - .02; if (on !== dots[d]._on) { dots[d]._on = on; dots[d].classList.toggle('on', on); } }
      setActive(clamp(Math.round(t), 0, n - 1));
    };
    measure(); render(); setActive(0);
    onFrame(render);
    addEventListener('resize', function () { measure(); render(); });
    if (D.fonts && D.fonts.ready) D.fonts.ready.then(function () { measure(); render(); });
  } else if (J) {
    /* static: the whole route is drawn and every stop is shown */
    $$('.lp-dot', J).forEach(function (d) { d.classList.add('on'); });
    var pn = $('#lpPin'); if (pn) pn.style.display = 'none';
  }

  /* ---------------- one-shot reveals ---------------- */
  var seen = function (els, cb) {
    if (!els.length) return;
    if (RM || !('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('in'); cb && cb(e); }); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); cb && cb(e.target); io.unobserve(e.target); } });
    }, { threshold: .2 });
    els.forEach(function (e) { io.observe(e); });
  };
  seen($$('.lp-tickets'));
  seen($$('.lp-pledges'));
  seen($$('.lp-end'));

  /* numbers count up once, from the real figure; with motion off they simply stay as they are */
  var nf = new Intl.NumberFormat('en-IN');
  var counters = $$('[data-count]');
  if (RM || !('IntersectionObserver' in window)) {
    /* leave the server-rendered figure */
  } else {
    counters.forEach(function (el) { el.textContent = '0'; });
    seen(counters, function (el) {
      var to = parseInt(el.getAttribute('data-count'), 10) || 0, t0 = performance.now(), dur = 1600;
      var tick = function (now) {
        var k = clamp((now - t0) / dur, 0, 1), v = Math.round(to * (1 - Math.pow(1 - k, 4)));
        el.textContent = nf.format(v);
        if (k < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }

  /* ---------------- photo reel: arrows and mouse drag ---------------- */
  var reel = $('#lpReel');
  if (reel) {
    $$('.lp-rb').forEach(function (b) {
      b.addEventListener('click', function () {
        var dir = parseInt(b.getAttribute('data-dir'), 10), w = reel.querySelector('.lp-card');
        reel.scrollBy({ left: dir * ((w ? w.offsetWidth : 280) + 18) * 2, behavior: RM ? 'auto' : 'smooth' });
      });
    });
    var down = false, sx = 0, sl = 0, moved = 0;
    reel.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      down = true; moved = 0; sx = e.clientX; sl = reel.scrollLeft;
    });
    addEventListener('pointermove', function (e) {
      if (!down) return;
      var dx = e.clientX - sx; moved = Math.max(moved, Math.abs(dx));
      if (moved > 5) { reel.classList.add('drag'); reel.scrollLeft = sl - dx; }
    });
    var end = function () { if (!down) return; down = false; setTimeout(function () { reel.classList.remove('drag'); }, 0); };
    addEventListener('pointerup', end); addEventListener('pointercancel', end);
    reel.addEventListener('click', function (e) { if (moved > 5) { e.preventDefault(); e.stopPropagation(); moved = 0; } }, true);
  }

  /* ---------------- tactile: the main button leans toward the pointer ---------------- */
  if (!RM && window.matchMedia && matchMedia('(pointer:fine)').matches) {
    $$('.lp-mag').forEach(function (b) {
      b.addEventListener('pointermove', function (e) {
        var r = b.getBoundingClientRect(), x = (e.clientX - r.left - r.width / 2) / r.width, y = (e.clientY - r.top - r.height / 2) / r.height;
        b.style.transform = 'translate3d(' + (x * 8).toFixed(1) + 'px,' + (y * 6 - 2).toFixed(1) + 'px,0)';
      });
      b.addEventListener('pointerleave', function () { b.style.transform = ''; });
    });
  }

  ask();
})();
