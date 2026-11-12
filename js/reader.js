/* Journal reading page: progress bar, contents highlight, photo lightbox, likes and comments. */
(function () {
  'use strict';
  var root = document.getElementById('prRoot'); if (!root) return;
  var CSRF = (window.PC_READ || {}).csrf, ID = root.dataset.id, TOKEN = root.dataset.token, ME = root.dataset.me === '1';
  function h(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  /* reading progress, measured over the story text */
  var bar = document.getElementById('prBar'); document.body.appendChild(bar.parentNode);
  var story = document.getElementById('prStory'), ticking = false;
  function progress() {
    ticking = false;
    var r = story.getBoundingClientRect(), total = r.height - window.innerHeight * 0.5;
    var done = total <= 0 ? 1 : Math.min(1, Math.max(0, (-r.top + window.innerHeight * 0.3) / total));
    bar.style.transform = 'scaleX(' + done.toFixed(3) + ')';
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(progress); } }, { passive: true });
  window.addEventListener('resize', progress); progress();

  /* contents: open on wide screens, highlight the section being read */
  var toc = document.getElementById('prToc');
  if (toc) {
    var wide = window.matchMedia('(min-width:1000px)');
    var sync = function () { toc.open = wide.matches; }; sync();
    if (wide.addEventListener) wide.addEventListener('change', sync);
    var links = {}; toc.querySelectorAll('a').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (en) {
        en.forEach(function (x) { if (x.isIntersecting) { Object.keys(links).forEach(function (k) { links[k].classList.remove('on'); }); links[x.target.id].classList.add('on'); } });
      }, { rootMargin: '-10% 0px -75% 0px' });
      story.querySelectorAll('h2[id]').forEach(function (hd) { io.observe(hd); });
    }
  }

  /* lightbox */
  var lb = document.getElementById('prLb'); document.body.appendChild(lb);
  var shots = Array.prototype.slice.call(document.querySelectorAll('[data-lb]')), cur = 0, opener = null;
  function show() {
    var s = shots[cur];
    document.getElementById('prLbImg').src = s.dataset.src;
    document.getElementById('prLbImg').alt = s.dataset.cap || s.querySelector('img').alt;
    var cap = document.getElementById('prLbCap'); cap.textContent = s.dataset.cap || ''; cap.hidden = !s.dataset.cap;
    document.getElementById('prLbCount').textContent = (cur + 1) + ' of ' + shots.length;
    lb.classList.toggle('single', shots.length < 2);
  }
  function open(i, from) { cur = i; opener = from; show(); lb.classList.add('open'); document.body.style.overflow = 'hidden'; lb.querySelector('.pc-lb-close').focus(); }
  function close() { lb.classList.remove('open'); document.body.style.overflow = ''; if (opener) opener.focus(); }
  function nav(d) { cur = (cur + d + shots.length) % shots.length; show(); }
  shots.forEach(function (s, i) { s.addEventListener('click', function () { open(i, s); }); });
  lb.addEventListener('click', function (e) {
    if (e.target === lb || e.target.closest('[data-close]')) return close();
    var n = e.target.closest('[data-nav]'); if (n) nav(parseInt(n.dataset.nav, 10));
  });
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('open')) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowRight') nav(1);
    else if (e.key === 'ArrowLeft') nav(-1);
    else if (e.key === 'Tab') {
      var f = Array.prototype.slice.call(lb.querySelectorAll('button')), first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* engagement */
  function post(data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('csrf', CSRF); fd.append('kind', 'journal'); fd.append('id', ID); if (TOKEN) fd.append('token', TOKEN);
    return fetch('engage.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); });
  }
  var list = document.getElementById('prCmtList');
  function load() {
    fetch('engage.php?action=comments&kind=journal&id=' + encodeURIComponent(ID) + (TOKEN ? '&token=' + encodeURIComponent(TOKEN) : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        list.textContent = '';
        if (!d.comments || !d.comments.length) { list.appendChild(h('span', 'pc-muted', 'No comments yet. Be the first to say something.')); return; }
        d.comments.forEach(function (c) {
          var row = h('div', 'pc-cmt'); row.dataset.cid = c.id;
          var b = h('b'); if (c.handle) { var a = h('a', null, c.name); a.href = 'traveller.php?h=' + encodeURIComponent(c.handle); b.appendChild(a); } else b.textContent = c.name;
          row.appendChild(b); row.appendChild(document.createTextNode(' ' + c.body));
          if (c.can_delete) { var x = h('button', null, '×'); x.type = 'button'; x.dataset.del = '1'; x.setAttribute('aria-label', 'Delete comment'); row.appendChild(x); }
          list.appendChild(row);
        });
      });
  }
  load();
  list.addEventListener('click', function (e) { if (e.target.dataset.del) post({ action: 'comment_delete', comment_id: e.target.closest('.pc-cmt').dataset.cid }).then(function (d) { if (d.ok) load(); }); });
  var like = document.getElementById('prLike'), likeN = document.getElementById('prLikeN'), busy = false;
  like.addEventListener('click', function () {
    if (!ME) { location.href = 'login.php'; return; }
    if (busy) return; busy = true;
    var was = like.getAttribute('aria-pressed') === 'true', n = parseInt(likeN.textContent, 10) || 0;
    like.setAttribute('aria-pressed', was ? 'false' : 'true'); likeN.textContent = Math.max(0, n + (was ? -1 : 1));
    if (!was) { like.classList.remove('pop'); void like.offsetWidth; like.classList.add('pop'); }
    post({ action: 'like' }).then(function (d) {
      if (d.error) { like.setAttribute('aria-pressed', was ? 'true' : 'false'); likeN.textContent = n; return; }
      like.setAttribute('aria-pressed', d.liked ? 'true' : 'false'); likeN.textContent = d.likes;
    }).catch(function () { like.setAttribute('aria-pressed', was ? 'true' : 'false'); likeN.textContent = n; }).then(function () { busy = false; });
  });
  var f = document.getElementById('prCmtForm');
  if (f) f.addEventListener('submit', function (e) {
    e.preventDefault(); var i = document.getElementById('prCmtInput'); if (!i.value.trim()) return;
    post({ action: 'comment_add', body: i.value }).then(function (d) { if (d.error) { list.appendChild(h('span', 'pc-muted', d.error)); return; } i.value = ''; load(); });
  });
  var rp = document.getElementById('prReport');
  if (rp) rp.addEventListener('submit', function (e) {
    e.preventDefault();
    post({ action: 'report', reason: rp.reason.value, detail: rp.detail.value }).then(function (d) { document.getElementById('prReportMsg').textContent = d.message || d.error || ''; });
  });
})();
