/* JourneyAI motion layer. Calm, tactile, never game-like. Pairs with css/motion.css.

   Everything here is progressive enhancement: reveal states are applied by this script only, so with JS off,
   or with prefers-reduced-motion, every page simply shows. Only transform, opacity, scale, translate and
   text of counters are animated; no layout properties.

   MARKUP API (all optional, most things apply automatically)
     data-mo="rise|fade|scale|draw"   reveal this element when it scrolls into view (default rise).
                                      "none" opts an element out of the automatic reveals.
                                      "draw" strokes the SVG line/path(s) inside (or the element itself).
     data-count="1234"                count this element's number up from zero to the real value. The element's
                                      own text gives the format (prefix, suffix, commas); the final text is
                                      restored exactly. Plain-number figures in .ins-figs and .ja-stat count automatically.
     data-mo-parallax="18"            drift this image/element up to 18px with scroll (hero photos do it automatically).
     data-mo-check                    the first svg inside draws itself as a success check.
     data-mo-skip                     leave an <img> out of the fade-in.
   AUTOMATIC
     page entrance        heading then up to 3 blocks, 60ms apart, 400ms total, first paint only (not on back/forward,
                          and skipped where the View Transitions API already animates the page change).
     scroll reveals       .ja-card .ja-post .tp-day .dz-card .dz-mini .dz-gsec .pc-card .pc-tile .ins-sec ...
     bars and lines       .ins-bars, .ins-cols, .dz-bar, .dz-actbar grow from their baseline.
     photos               fade in on load; slow 1.05 zoom on hover (css); parallax on destination and trip heroes.
     tactile              press scale and ripple, chip spring, like/pin burst, save fill, theme icon turn, toast.
     scroll progress      a thin bar at the top of long pages.
   JS API
     MO.scan(root)        pick up content added later (feed pages, search results); done automatically too.
     MO.count(el, n)      count an element up to n.
     MO.checkSVG()        markup for the animated success check.
     MO.rm()              true when the visitor asked for reduced motion.
   Page changes use @view-transition (see css/motion.css); browsers without it just navigate normally. */
(function () {
  'use strict';
  if (window.MO) return;                       // ja-head can include this file twice
  var d = document, root = d.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  var rm = function () { return !!mq.matches; };
  var CAN = typeof Element.prototype.animate === 'function';
  var OUT = 'cubic-bezier(.16,1,.3,1)', SPRING = 'cubic-bezier(.34,1.56,.64,1)', IO_EASE = 'cubic-bezier(.65,0,.35,1)';
  var landing = !!d.querySelector('[data-cj-landing]') || (d.body && d.body.classList.contains('cj-home'));

  var qa = function (sel, r) {
    r = r || d; var out = [];
    if (r.nodeType === 1 && r.matches && r.matches(sel)) out.push(r);
    return out.concat([].slice.call(r.querySelectorAll ? r.querySelectorAll(sel) : []));
  };
  var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
  var inView = function (el) { var r = el.getBoundingClientRect(); return r.height + r.width > 0 && r.top < innerHeight * .96 && r.bottom > 0; };

  var API = window.MO = {
    rm: rm,
    scan: function () {},
    count: function () {},
    checkSVG: function () {
      return '<svg class="mo-ck" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<circle cx="12" cy="12" r="9.5" pathLength="1"/><path d="M7.5 12.5l3 3 6-6.5" pathLength="1"/></svg>';
    }
  };

  /* targets that get the scroll reveal automatically */
  var SEL = '.ja-card,.ja-reco,.ja-place-card,.ja-nearby-card,.ja-post,.pc-card,.pc-tile,.dz-card,.dz-mini,.dz-gsec,.tp-day,.ins-sec,.ja-itin-day,.ja-pack-cat,.ja-stat,.ja-modcard,[data-mo]';
  var SKIP = '.reveal,.cj-rv,.stagger,[data-mo="none"],.pc-lb,.ja-pal,.ja-chat';
  var BAR = '.ins-bars .fill,.ins-bars .alt,.ins-cols .fill,.dz-bar,.dz-actbar>i,.mo-bar-auto';
  var COUNT = '[data-count],.ins-figs dd:not(.ins-sub),.ja-stat .n';
  var PAR = '.dz-gphoto img,.tp-photo img,[data-mo-parallax]';

  var seen = new WeakSet(), counters = [], draws = [], parx = [], active = false;

  /* ---------------- reveals ---------------- */
  var io, io2;
  function keyframes(v) {
    if (v === 'fade') return [{ opacity: 0 }, { opacity: 1 }];
    if (v === 'scale') return [{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'none' }];
    return [{ opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'none' }];
  }
  function show(el, i) {
    el.classList.remove('mo-pre');
    var v = el.__mo || 'rise';
    if (v === 'draw') { drawIn(el, i); return; }
    el.animate(keyframes(v), { duration: v === 'fade' ? 500 : 560, easing: OUT, delay: Math.min(i * 60, 300), fill: 'backwards' });
  }
  function shapesOf(el) {
    return el.matches && el.matches('path,line,polyline,polygon,circle,rect,ellipse') ? [el] : [].slice.call(el.querySelectorAll('path,line,polyline,polygon,circle,rect,ellipse'));
  }
  function prepDraw(el) {
    shapesOf(el).forEach(function (s) {
      if (!s.getTotalLength) return;
      var L; try { L = s.getTotalLength(); } catch (e) { return; }
      if (!L) return;
      s.__L = L; s.style.strokeDasharray = L; s.style.strokeDashoffset = L; draws.push(s);
    });
  }
  function drawIn(el, i) {
    shapesOf(el).forEach(function (s, j) {
      if (!s.__L) return;
      var a = s.animate([{ strokeDashoffset: s.__L }, { strokeDashoffset: 0 }], { duration: 900, delay: Math.min(i * 60 + j * 80, 500), easing: IO_EASE, fill: 'backwards' });
      var clear = function () { s.style.strokeDasharray = ''; s.style.strokeDashoffset = ''; };
      a.onfinish = clear; a.oncancel = clear; s.style.strokeDashoffset = 0;
    });
  }
  function prepReveal(el, dynamic, i) {
    if (seen.has(el)) return; seen.add(el);
    if (el.closest(SKIP)) return;
    var explicit = el.getAttribute('data-mo');
    if (!explicit && el.parentElement && el.parentElement.closest(SEL)) return;     // nested cards ride on their parent
    var v = explicit || 'rise';
    el.__mo = v;
    var vis = inView(el);
    if (v === 'draw') { prepDraw(el); io.observe(el); return; }
    if (vis) { if (dynamic) el.animate(keyframes(v), { duration: 480, easing: OUT, delay: Math.min(i * 50, 250), fill: 'backwards' }); return; }
    el.classList.add('mo-pre'); io.observe(el);
  }

  /* bars grow from their baseline, in a small stagger */
  function prepBar(bar, trigger) {
    if (seen.has(bar)) return; seen.add(bar);
    var y = bar.matches('.ins-cols .fill,.dz-bar');
    bar.classList.add(y ? 'mo-bar-y' : 'mo-bar', y ? 'mo-pre-bar-y' : 'mo-pre-bar'); bar.__y = y;
    (trigger.__bars = trigger.__bars || []).push(bar);
    if (!trigger.__obs) { trigger.__obs = 1; io2.observe(trigger); }
  }
  function growBars(trigger) {
    (trigger.__bars || []).forEach(function (b, j) {
      b.classList.remove('mo-pre-bar', 'mo-pre-bar-y');
      b.animate([{ transform: b.__y ? 'scale(1,0)' : 'scale(0,1)' }, { transform: 'scale(1,1)' }], { duration: 760, delay: Math.min(j * 45, 400), easing: OUT, fill: 'backwards' });
    });
  }

  /* numbers count up from zero to the real value, then the original text is put back exactly */
  function prepCount(el) {
    if (seen.has(el)) return; seen.add(el);
    if (el.children.length) return;
    var txt = el.textContent, toks = txt.match(/\d[\d,]*(?:\.\d+)?/g);
    if (!toks || (!el.hasAttribute('data-count') && (toks.length !== 1 || txt.length > 40))) return;
    var tok = toks[0], idx = txt.indexOf(tok);
    var target = el.hasAttribute('data-count') ? parseFloat(el.getAttribute('data-count')) : parseFloat(tok.replace(/,/g, ''));
    if (!isFinite(target) || target <= 0) return;
    var dec = (tok.split('.')[1] || '').length, indian = /^\d{1,2}(,\d{2})+,\d{3}/.test(tok);
    var nf = new Intl.NumberFormat(indian ? 'en-IN' : 'en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec, useGrouping: tok.indexOf(',') > -1 });
    var c = { el: el, text: txt, pre: txt.slice(0, idx), post: txt.slice(idx + tok.length), target: target, nf: nf, dec: dec };
    el.__c = c; counters.push(c);
    el.textContent = c.pre + nf.format(0) + c.post;
    io2.observe(el);
  }
  function runCount(c, to) {
    var t0 = performance.now(), D = 1000, target = to != null ? to : c.target;
    if (rm() || !CAN) { c.el.textContent = c.text; return; }
    (function step(now) {
      var p = clamp((now - t0) / D, 0, 1), e = 1 - Math.pow(1 - p, 3), v = target * e;
      if (!c.dec) v = Math.round(v);
      if (p < 1) { c.el.textContent = c.pre + c.nf.format(v) + c.post; requestAnimationFrame(step); }
      else c.el.textContent = c.text;
    })(t0);
  }
  API.count = function (el, n) {
    var c = el.__c; if (!c) { prepCount(el); c = el.__c; }
    if (c) runCount(c, n);
  };

  /* images fade in once loaded (opacity only, so the layout never moves) */
  function prepImg(img) {
    if (img.__mo || img.complete || img.hasAttribute('data-mo-skip') || img.closest('.ja-nav,.ja-footer,.ja-pal,.pc-lb,.ja-chat,svg')) return;
    img.__mo = 1; img.classList.add('mo-img');
    var fin = function (ok) {
      if (img.__fin) return; img.__fin = 1;
      if (!ok) { img.classList.remove('mo-img'); return; }
      requestAnimationFrame(function () { img.classList.add('mo-img-in'); setTimeout(function () { img.classList.remove('mo-img', 'mo-img-in'); }, 700); });
    };
    img.addEventListener('load', function () { fin(true); }, { once: true });
    img.addEventListener('error', function () { fin(false); }, { once: true });
    setTimeout(function () { fin(false); }, 8000);
  }

  /* hero photo parallax: one rAF-coalesced scroll handler, transform-only */
  var pxRaf = 0;
  function prepPar(el) {
    if (seen.has(el)) return; seen.add(el);
    var box = el.parentElement;
    if (el.tagName === 'IMG' && box && getComputedStyle(box).overflow !== 'hidden') {
      var fr = d.createElement('span'); fr.className = 'mo-frame'; box.insertBefore(fr, el); fr.appendChild(el); box = fr;
    }
    el.classList.add('mo-par');
    parx.push({ el: el, box: box, amp: parseFloat(el.getAttribute('data-mo-parallax')) || 22, vis: false });
    pxObs.observe(box);
    queuePx();
  }
  var pxObs = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { parx.forEach(function (t) { if (t.box === e.target) t.vis = e.isIntersecting; }); }); queuePx();
  }, { rootMargin: '80px' }) : { observe: function () {} };
  function queuePx() { if (!pxRaf) pxRaf = requestAnimationFrame(pxUpdate); }
  function pxUpdate() {
    pxRaf = 0; if (rm()) return;
    var vh = innerHeight;
    parx.forEach(function (t) {
      if (!t.vis) return;
      var r = t.box.getBoundingClientRect(), p = clamp(((r.top + r.height / 2) - vh / 2) / (vh / 2 + r.height / 2), -1, 1);
      var amp = Math.min(t.amp, r.height * .035);
      t.el.style.translate = '0 ' + (-p * amp).toFixed(1) + 'px';
    });
  }

  /* ---------------- scan ---------------- */
  function scan(r, dynamic) {
    if (!active) return; r = r || d;
    var n = 0;
    if (!landing) qa(SEL, r).forEach(function (el) { prepReveal(el, dynamic, n++); });
    qa(BAR, r).forEach(function (b) { prepBar(b, b.closest('li') || b.closest('svg') || b.parentElement); });
    qa(COUNT, r).forEach(prepCount);
    qa('img', r).forEach(prepImg);
    if (!landing) qa(PAR, r).forEach(prepPar);
    qa('.tp-saved svg,[data-mo-check] svg', r).forEach(function (svg) {
      if (svg.__ck) return; svg.__ck = 1;
      var shapes = svg.querySelectorAll('path,circle,polyline,line'), ok = shapes.length > 0;
      [].forEach.call(shapes, function (s) { var cs = getComputedStyle(s); if (cs.fill !== 'none' || cs.stroke === 'none') ok = false; });
      if (!ok) return;
      [].forEach.call(shapes, function (s) { s.setAttribute('pathLength', '1'); });
      svg.classList.add('mo-ck');
    });
  }
  API.scan = function (r) { scan(r, true); };

  /* ---------------- page entrance ---------------- */
  function entrance() {
    if (landing || !CAN) return;
    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    var type = nav ? nav.type : 'navigate';
    if (type === 'back_forward') return;
    var sameOrigin = d.referrer && d.referrer.indexOf(location.origin) === 0;
    if (type === 'navigate' && sameOrigin && 'PageRevealEvent' in window) return;     // the view transition already animates this
    var main = d.getElementById('main'); if (!main) return;
    var usable = function (c) {
      return [].filter.call(c.children, function (e) {
        if (/^(SCRIPT|STYLE|TEMPLATE|LINK)$/.test(e.tagName) || e.hidden || e.matches(SKIP) || e.hasAttribute('data-mo')) return false;
        var cs = getComputedStyle(e), r = e.getBoundingClientRect();
        return cs.display !== 'none' && !/fixed|sticky|absolute/.test(cs.position) && r.height > 0 && r.top < innerHeight * .95;
      });
    };
    var c = main, kids;
    for (var n = 0; n < 3; n++) { kids = usable(c); if (kids.length === 1 && kids[0].children.length > 1) c = kids[0]; else break; }
    kids = usable(c).slice(0, 4);
    kids.forEach(function (el, i) {
      el.animate([{ opacity: 0, transform: 'translateY(12px)' }, { opacity: 1, transform: 'none' }], { duration: 220, delay: i * 60, easing: OUT, fill: 'backwards' });
    });
  }

  /* ---------------- scroll progress ---------------- */
  var bar, pgRaf = 0;
  function progress() {
    pgRaf = 0; if (!bar) return;
    var max = root.scrollHeight - innerHeight;
    bar.classList.toggle('on', max > innerHeight * 1.2);
    bar.style.transform = 'scaleX(' + (max > 0 ? clamp(scrollY / max, 0, 1) : 0).toFixed(4) + ')';
  }
  function queuePg() { if (!pgRaf) pgRaf = requestAnimationFrame(progress); }
  function initProgress() {
    bar = d.createElement('div'); bar.className = 'mo-progress'; bar.setAttribute('aria-hidden', 'true'); d.body.appendChild(bar);
    addEventListener('scroll', function () { queuePg(); queuePx(); }, { passive: true });
    addEventListener('resize', function () { queuePg(); queuePx(); });
    progress();
  }

  /* ---------------- tactile feedback ---------------- */
  var CHIP = '.ja-chip,.pc-chip,.ja-pick,.dz-compare';
  function tactile() {
    d.addEventListener('click', function (e) {
      if (rm() || !CAN) return;
      var t = e.target.closest && e.target.closest(CHIP);
      if (t) { t.classList.remove('mo-spring'); void t.offsetWidth; t.classList.add('mo-spring'); setTimeout(function () { t.classList.remove('mo-spring'); }, 400); }
      var s = e.target.closest && e.target.closest('.dz-save .dz-iconbtn,#tpSave');
      if (s) s.classList.add('mo-saving');
      var b = e.target.closest && e.target.closest('[class*="likebtn"],[data-pin-kind],[data-like]');
      if (b) setTimeout(function () {
        if (!/liked|pinned|\bon\b/.test(b.className) && b.getAttribute('data-pinned') !== '1') return;
        var r = b.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        for (var i = 0; i < 8; i++) {
          var dot = d.createElement('i'); dot.className = 'mo-burst-dot'; dot.style.left = cx + 'px'; dot.style.top = cy + 'px'; d.body.appendChild(dot);
          var a = i / 8 * Math.PI * 2, dist = 20 + (i % 2) * 7;
          dot.animate([{ transform: 'translate(0,0) scale(1)', opacity: 1 }, { transform: 'translate(' + Math.cos(a) * dist + 'px,' + Math.sin(a) * dist + 'px) scale(.2)', opacity: 0 }],
            { duration: 520, easing: OUT, fill: 'forwards' }).onfinish = (function (n) { return function () { n.remove(); }; })(dot);
        }
      }, 90);
    });
    /* theme: let colours cross-fade, then turn the new icon in. Capture phase so it runs before the flip. */
    d.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-ja-theme-toggle]'); if (!t || rm()) return;
      root.classList.add('mo-theming'); clearTimeout(API._th); API._th = setTimeout(function () { root.classList.remove('mo-theming'); }, 520);
      setTimeout(function () {
        qa('.ja-tt-moon,.ja-tt-sun').forEach(function (ic) {
          if (!ic.offsetParent) return;
          var sv = ic.querySelector('svg') || ic;
          sv.animate([{ transform: 'rotate(-80deg) scale(.4)', opacity: 0 }, { transform: 'rotate(0) scale(1)', opacity: 1 }], { duration: 460, easing: SPRING });
        });
      }, 30);
    }, true);
  }

  /* ---------------- boot ---------------- */
  function boot() {
    if (!d.body) return;
    initProgress(); tactile();
    if (rm() || !CAN || !('IntersectionObserver' in window)) return;
    active = true; root.classList.add('mo');
    io = new IntersectionObserver(function (es) {
      es.filter(function (e) { return e.isIntersecting; }).sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top || a.boundingClientRect.left - b.boundingClientRect.left; })
        .forEach(function (e, i) { show(e.target, i); io.unobserve(e.target); });
    }, { rootMargin: '0px 0px -6% 0px', threshold: .06 });
    io2 = new IntersectionObserver(function (es) {
      es.filter(function (e) { return e.isIntersecting; }).forEach(function (e) {
        io2.unobserve(e.target);
        if (e.target.__bars) growBars(e.target);
        if (e.target.__c) runCount(e.target.__c);
      });
    }, { rootMargin: '0px 0px -4% 0px', threshold: .35 });
    scan(d, false);
    entrance();
    new MutationObserver(function (ms) {
      ms.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) scan(n, true); }); });
    }).observe(d.body, { childList: true, subtree: true });
    mq.addEventListener && mq.addEventListener('change', function () { if (rm()) restoreAll(); });
  }

  /* anything still waiting is put back to its final state (printing, reduced motion switched on mid-visit) */
  function restoreAll() {
    qa('.mo-pre,.mo-pre-bar,.mo-pre-bar-y').forEach(function (e) { e.classList.remove('mo-pre', 'mo-pre-bar', 'mo-pre-bar-y'); });
    qa('img.mo-img').forEach(function (e) { e.classList.remove('mo-img', 'mo-img-in'); });
    counters.forEach(function (c) { c.el.textContent = c.text; });
    draws.forEach(function (s) { s.style.strokeDasharray = ''; s.style.strokeDashoffset = ''; });
  }
  addEventListener('beforeprint', restoreAll);

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();
})();
