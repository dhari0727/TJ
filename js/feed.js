/* Community feed: infinite scroll with skeletons, optimistic likes, post viewer with comments. */
(function () {
  'use strict';
  var CFG = window.PC_FEED || {};
  var grid = document.getElementById('pcGrid');
  var root = document.getElementById('pcFeedRoot');
  if (!grid || !root) return;
  var skels = document.getElementById('pcSkels'), more = document.getElementById('pcMore'),
      moreBtn = document.getElementById('pcMoreBtn'), moreMsg = document.getElementById('pcMoreMsg'),
      endEl = document.getElementById('pcEnd');
  var lb = document.getElementById('pcLb');
  document.body.appendChild(lb);   // fixed overlays must live outside the animated page wrapper

  function h(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function csrfHeaders(extra) { var o = extra || {}; if (CFG.csrf) o['X-CSRF-Token'] = CFG.csrf; return o; }
  function initials(label) {
    label = (label || '').trim(); if (!label) return '?';
    var p = label.split(/\s+/);
    return (p.length > 1 ? p[0][0] + p[p.length - 1][0] : label.substring(0, 2)).toUpperCase();
  }

  /* ---------- load more ---------- */
  var page = parseInt(root.dataset.page, 10) || 1, hasMore = root.dataset.more === '1', busy = false;
  function nextUrl() {
    var q = new URLSearchParams();
    if (root.dataset.tag) q.set('tag', root.dataset.tag);
    if (root.dataset.dest) q.set('dest', root.dataset.dest);
    q.set('page', page + 1); q.set('partial', '1');
    return 'feed.php?' + q.toString();
  }
  function loadMore() {
    if (busy || !hasMore) return;
    busy = true; moreMsg.textContent = ''; moreBtn.setAttribute('aria-busy', 'true');
    skels.hidden = false;
    fetch(nextUrl(), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (d) {
      var t = document.createElement('div'); t.innerHTML = d.html;
      while (t.firstChild) grid.appendChild(t.firstChild);
      page = d.page; hasMore = !!d.more;
      var q = new URLSearchParams(location.search); q.set('page', page);
      try { history.replaceState(null, '', '?' + q.toString()); } catch (e) {}
      moreBtn.href = '?' + (function () { var u = new URLSearchParams(location.search); u.set('page', page + 1); return u.toString(); })();
      more.hidden = !hasMore; endEl.hidden = hasMore;
    }).catch(function () {
      moreMsg.textContent = 'Could not load more posts. Check your connection and try again.';
    }).then(function () { busy = false; skels.hidden = true; moreBtn.removeAttribute('aria-busy'); });
  }
  if (moreBtn) moreBtn.addEventListener('click', function (e) { e.preventDefault(); loadMore(); });
  if ('IntersectionObserver' in window && more) {
    new IntersectionObserver(function (en) { if (en[0].isIntersecting && hasMore && !more.hidden) loadMore(); }, { rootMargin: '500px 0px' }).observe(more);
  }

  /* ---------- likes (optimistic) ---------- */
  var inflight = {};
  function setLike(id, liked, n) {
    document.querySelectorAll('[data-like][data-media-id="' + id + '"]').forEach(function (b) {
      b.setAttribute('aria-pressed', liked ? 'true' : 'false');
      var c = b.querySelector('.n'); if (c) c.textContent = n;
    });
    var card = postById(id); if (card) { card.liked = liked; card.likes = n; }
  }
  function toggleLike(btn) {
    if (!CFG.loggedIn) { location.href = 'login.php'; return; }
    var id = btn.dataset.mediaId; if (inflight[id]) return; inflight[id] = true;
    var was = btn.getAttribute('aria-pressed') === 'true', cur = parseInt(btn.querySelector('.n').textContent, 10) || 0;
    setLike(id, !was, Math.max(0, cur + (was ? -1 : 1)));
    if (!was) { btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop'); }
    fetch('media-like.php', { method: 'POST', credentials: 'same-origin', headers: csrfHeaders({ 'Content-Type': 'application/x-www-form-urlencoded' }),
      body: 'media_id=' + encodeURIComponent(id) }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { setLike(id, was, cur); if (/log in/i.test(d.error)) location.href = 'login.php'; return; }
      setLike(id, !!d.liked, d.likes);
    }).catch(function () { setLike(id, was, cur); }).then(function () { inflight[id] = false; });
  }

  /* ---------- viewer ---------- */
  var idx = 0, opener = null, cards = [];
  function readCards() {
    cards = Array.prototype.slice.call(grid.querySelectorAll('.pc-card[data-post]'));
    return cards;
  }
  function dataOf(card) {
    if (!card._pc) { try { card._pc = JSON.parse(card.getAttribute('data-post')); } catch (e) { card._pc = {}; } }
    return card._pc;
  }
  function postById(id) {
    for (var i = 0; i < cards.length; i++) { var p = dataOf(cards[i]); if (String(p.id) === String(id)) return p; }
    return null;
  }
  function render() {
    var p = dataOf(cards[idx]); if (!p) return;
    var stage = document.getElementById('pcLbStage'); stage.textContent = '';
    var el;
    if (p.kind === 'video') { el = document.createElement('video'); el.controls = true; el.autoplay = true; el.loop = true; el.playsInline = true; el.src = p.src; }
    else { el = document.createElement('img'); el.src = p.src; el.alt = p.caption ? String(p.caption).slice(0, 140) : 'Photo shared by ' + p.owner; }
    stage.appendChild(el);
    document.getElementById('pcLbAv').textContent = initials(p.owner);
    document.getElementById('pcLbOwner').textContent = p.owner;
    document.getElementById('pcLbDate').textContent = p.created ? new Date(p.created.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
    var cap = document.getElementById('pcLbCap'); cap.textContent = p.caption || ''; cap.hidden = !p.caption;
    var tags = document.getElementById('pcLbTags'); tags.textContent = '';
    (p.tags || []).forEach(function (t) { var a = h('a', null, '#' + t); a.href = 'feed.php?tag=' + encodeURIComponent(t); tags.appendChild(a); });
    var like = document.getElementById('pcLbLike'); like.dataset.mediaId = p.id;
    like.setAttribute('aria-pressed', p.liked ? 'true' : 'false'); like.querySelector('.n').textContent = p.likes;
    document.getElementById('pcLbCount').textContent = (idx + 1) + ' of ' + cards.length;
    var rep = document.getElementById('pcLbReportMsg'); if (rep) rep.textContent = '';
    loadComments(p.id);
  }
  function open(i, focusCmt, from) {
    readCards(); if (!cards.length) return;
    idx = i; opener = from || document.activeElement; render();
    lb.classList.add('open'); document.body.style.overflow = 'hidden';
    var target = focusCmt && document.getElementById('pcCmtInput') ? document.getElementById('pcCmtInput') : lb.querySelector('.pc-lb-close');
    target.focus();
  }
  function close() {
    lb.classList.remove('open'); document.body.style.overflow = '';
    var v = lb.querySelector('video'); if (v) v.pause();
    if (opener && opener.focus) opener.focus();
  }
  function nav(d) { idx = (idx + d + cards.length) % cards.length; render(); }
  lb.addEventListener('click', function (e) {
    if (e.target === lb) return close();
    var c = e.target.closest('[data-close]'); if (c) return close();
    var n = e.target.closest('[data-nav]'); if (n) nav(parseInt(n.dataset.nav, 10));
  });
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('open')) return;
    var typing = /input|textarea|select/i.test((e.target.tagName || ''));
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (!typing && e.key === 'ArrowRight') nav(1);
    else if (!typing && e.key === 'ArrowLeft') nav(-1);
    else if (e.key === 'Tab') {
      var f = Array.prototype.slice.call(lb.querySelectorAll('button,a[href],input,select,video[controls]')).filter(function (x) { return x.offsetParent !== null && !x.disabled; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* ---------- comments ---------- */
  var list = document.getElementById('pcCmtList');
  function setCount(id, n) {
    document.querySelectorAll('[data-cmt-for="' + id + '"]').forEach(function (el) { el.textContent = n; });
    var p = postById(id); if (p) p.comments = n;
  }
  function cmtNode(c) {
    var row = h('div', 'pc-cmt'); row.dataset.cid = c.id;
    var b = h('b'); if (c.handle) { var a = h('a', null, c.name); a.href = 'traveller.php?h=' + encodeURIComponent(c.handle); b.appendChild(a); } else b.textContent = c.name;
    row.appendChild(b); row.appendChild(document.createTextNode(' ' + c.body));
    if (c.can_delete) { var d = h('button', null, '×'); d.type = 'button'; d.setAttribute('aria-label', 'Delete comment'); d.dataset.del = '1'; row.appendChild(d); }
    return row;
  }
  function loadComments(id) {
    list.textContent = ''; list.appendChild(h('span', 'pc-muted', 'Loading comments'));
    fetch('engage.php?action=comments&kind=media&id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (String(dataOf(cards[idx]).id) !== String(id)) return;
      list.textContent = '';
      if (d.comments && d.comments.length) d.comments.forEach(function (c) { list.appendChild(cmtNode(c)); });
      else list.appendChild(h('span', 'pc-muted', 'No comments yet. Say something about this place.'));
      setCount(id, d.count || 0);
    }).catch(function () { list.textContent = ''; });
  }
  function post(data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); fd.append('csrf', CFG.csrf);
    return fetch('engage.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: csrfHeaders() }).then(function (r) { return r.json(); });
  }
  var form = document.getElementById('pcCmtForm');
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('pcCmtInput'), id = dataOf(cards[idx]).id;
    if (!input.value.trim()) return;
    post({ action: 'comment_add', kind: 'media', id: id, body: input.value }).then(function (d) {
      if (d.error) { list.appendChild(h('span', 'pc-muted', d.error)); return; }
      input.value = ''; loadComments(id);
    });
  });
  list.addEventListener('click', function (e) {
    if (!e.target.dataset.del) return;
    var row = e.target.closest('.pc-cmt'), id = dataOf(cards[idx]).id;
    post({ action: 'comment_delete', kind: 'media', comment_id: row.dataset.cid }).then(function (d) { if (d.ok) loadComments(id); });
  });
  var rep = document.getElementById('pcLbReport');
  if (rep) rep.addEventListener('change', function () {
    if (!rep.value) return;
    post({ action: 'report', kind: 'media', id: dataOf(cards[idx]).id, reason: rep.value }).then(function (d) {
      document.getElementById('pcLbReportMsg').textContent = d.message || d.error || ''; rep.value = '';
    });
  });

  /* ---------- grid events ---------- */
  grid.addEventListener('click', function (e) {
    var like = e.target.closest('[data-like]'); if (like) { toggleLike(like); return; }
    var card = e.target.closest('.pc-card'); if (!card) return;
    readCards(); var i = cards.indexOf(card);
    if (e.target.closest('[data-comment]')) { open(i, true, e.target.closest('[data-comment]')); return; }
    var m = e.target.closest('[data-open]'); if (m) open(i, false, m);
  });
  document.getElementById('pcLbLike').addEventListener('click', function () { toggleLike(this); });
  // videos preview on hover for mouse users
  grid.addEventListener('mouseover', function (e) { var v = e.target.closest('.pc-media') && e.target.closest('.pc-media').querySelector('video'); if (v) v.play().catch(function () {}); });
  grid.addEventListener('mouseout', function (e) { var v = e.target.closest('.pc-media') && e.target.closest('.pc-media').querySelector('video'); if (v) { v.pause(); v.currentTime = 0; } });
})();
