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

  /* ---- landing page ---- */
  var land = $('[data-cj-landing]'); if (!land) return;

  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: .15 }) : null;
  [].forEach.call(document.querySelectorAll('.cj-rv'), function (el) { io ? io.observe(el) : el.classList.add('in'); });

  /* stars in the night section */
  var night = $('.cj-night');
  if (night) for (var i = 0; i < 60; i++) {
    var s = document.createElement('i'); s.className = 'cj-star'; s.style.left = Math.random() * 100 + '%'; s.style.top = Math.random() * 100 + '%';
    s.style.animationDelay = Math.random() * 3 + 's'; night.appendChild(s);
  }

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
      c.setAttribute('fill', 'var(--bg)'); c.setAttribute('stroke', '#c9bfae'); c.setAttribute('stroke-width', 5); dots.appendChild(c);
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
          el._dot.setAttribute('fill', on ? '#E63457' : 'var(--bg)'); el._dot.setAttribute('stroke', on ? '#fff' : '#c9bfae');
        }
      });
    };
    addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
  }

  /* drifting leaves, three depths, only for people who have not asked for less motion */
  if (!RM) {
    var LV = document.createElement('div'); LV.className = 'cj-leaves'; LV.setAttribute('aria-hidden', 'true'); document.body.appendChild(LV);
    var COL = ['#E63457', '#1E7B4F', '#B6E36B', '#F6B73C', '#1E7B4F'], leaves = [];
    for (var k = 0; k < 4; k++) {
      var near = k % 3, l = document.createElement('div'); l.className = 'cj-leaf';
      l.style.cssText = 'left:' + (Math.random() * 96) + '%;top:' + (Math.random() * 100) + '%;font-size:' + [16, 26, 40][near] + 'px;filter:blur(' + [0, 0, 2][near] + 'px);color:' + COL[k];
      l.innerHTML = '<i style="--d:' + (5 + Math.random() * 5) + 's"><svg viewBox="0 0 24 24" width="1em" height="1em"><path d="M5 19C5 9 11 4 20 4c0 9-5 15-15 15z" fill="currentColor"/><path d="M5 19l8-8" stroke="#ffffff88" fill="none" stroke-width="1.6"/></svg></i>';
      l.dataset.s = [.15, .35, .7][near]; LV.appendChild(l); leaves.push(l);
    }
    var lf = function () { leaves.forEach(function (l) { l.style.translate = '0 ' + (-((scrollY * +l.dataset.s) % (innerHeight + 120))) + 'px'; }); };
    addEventListener('scroll', lf, { passive: true }); lf();
  }

  /* section dots */
  var SECS = [].slice.call(document.querySelectorAll('[data-cj-sec]')), nav = document.createElement('nav');
  nav.className = 'cj-dots'; nav.setAttribute('aria-label', 'Sections');
  SECS.forEach(function (sec) {
    var b = document.createElement('button'); b.type = 'button'; b.title = sec.dataset.cjSec; b.setAttribute('aria-label', sec.dataset.cjSec);
    b.onclick = function () { sec.scrollIntoView({ behavior: RM ? 'auto' : 'smooth' }); }; nav.appendChild(b);
  });
  if (SECS.length) document.body.appendChild(nav);
  var dUpd = function () {
    var k = 0; SECS.forEach(function (s, i) { if (s.getBoundingClientRect().top < innerHeight * .5) k = i; });
    [].forEach.call(nav.children, function (b, i) { b.classList.toggle('on', i === k); });
  };
  addEventListener('scroll', dUpd, { passive: true }); dUpd();
})();
