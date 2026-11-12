/* JourneyAI — discover pages: compare tray and star ratings on destination cards. */
(function () {
  var KEY = 'ja-compare', MAX = 3, picked = [];
  function load() { try { var v = JSON.parse(sessionStorage.getItem(KEY) || '[]'); picked = Array.isArray(v) ? v.slice(0, MAX) : []; } catch (e) { picked = []; } }
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify(picked)); } catch (e) { /* storage blocked: tray still works for this page view */ } }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var tray = null;
  function ensureTray() {
    if (tray) return tray;
    tray = document.createElement('div');
    tray.className = 'dz-tray'; tray.hidden = true;
    tray.setAttribute('role', 'region'); tray.setAttribute('aria-label', 'Destinations to compare');
    document.body.appendChild(tray);
    tray.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-remove]');
      if (rm) { toggle(rm.getAttribute('data-remove')); return; }
      if (e.target.closest('[data-clear]')) { picked = []; save(); sync(); }
    });
    return tray;
  }
  var lastSig = null;
  function render() {
    var t = ensureTray();
    var sig = picked.join('|');
    if (sig === lastSig) return;
    lastSig = sig;
    if (!picked.length) { t.hidden = true; document.body.classList.remove('dz-tray-on'); return; }
    var url = 'compare.php?' + picked.map(function (n) { return 'd[]=' + encodeURIComponent(n); }).join('&');
    var chips = picked.map(function (n) {
      return '<span class="dz-tchip">' + esc(n) + '<button type="button" data-remove="' + esc(n) + '" aria-label="Remove ' + esc(n) + ' from comparison">&times;</button></span>';
    }).join('');
    var ready = picked.length >= 2;
    t.innerHTML = '<div class="dz-tray-in"><div class="dz-tray-l"><span class="dz-tcount">' + picked.length + ' of ' + MAX + ' chosen</span>' + chips + '</div>' +
      '<div class="dz-tray-r"><button type="button" class="dz-textbtn" data-clear>Clear</button>' +
      (ready ? '<a class="ja-btn ja-btn-primary" href="' + url + '">Compare ' + picked.length + ' places</a>' : '<span class="dz-thint">Pick one more to compare</span>') + '</div></div>';
    t.hidden = false; document.body.classList.add('dz-tray-on');
  }
  var mo = null;
  function sync() {
    if (mo) mo.disconnect();
    document.querySelectorAll('[data-compare]').forEach(function (b) {
      var on = picked.indexOf(b.getAttribute('data-compare')) !== -1;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('on', on);
      var s = b.querySelector('span'); if (s) s.textContent = on ? 'Added' : 'Compare';
      b.disabled = !on && picked.length >= MAX;
      b.title = b.disabled ? 'You can compare up to ' + MAX + ' places. Remove one first.' : '';
    });
    render();
    if (mo) mo.observe(document.body, { childList: true, subtree: true });
  }
  function toggle(name) {
    var i = picked.indexOf(name);
    if (i === -1) { if (picked.length >= MAX) return; picked.push(name); } else picked.splice(i, 1);
    save(); sync();
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-compare]'); if (!b) return;
    e.preventDefault(); toggle(b.getAttribute('data-compare'));
  });
  /* the plan page adds result cards after load */
  mo = new MutationObserver(function (list) {
    var fresh = list.some(function (m) {
      return Array.prototype.some.call(m.addedNodes, function (n) {
        return n.nodeType === 1 && !(tray && tray.contains(n)) && (n.matches('[data-compare]') || n.querySelector('[data-compare]'));
      });
    });
    if (fresh) sync();
  });
  load(); sync();

  /* ---- star ratings ---- */
  function paint(w, hover) {
    var val = hover || parseInt(w.getAttribute('data-my-rating') || '0', 10);
    w.querySelectorAll('.ja-star-pick').forEach(function (st) { st.classList.toggle('on', parseInt(st.getAttribute('data-val'), 10) <= val); });
  }
  document.addEventListener('mouseover', function (e) {
    var st = e.target.closest('.ja-star-pick'); if (st) paint(st.parentNode, parseInt(st.getAttribute('data-val'), 10));
  });
  document.addEventListener('mouseout', function (e) { var w = e.target.closest && e.target.closest('.ja-star-widget'); if (w) paint(w); });
  document.addEventListener('click', function (e) {
    var st = e.target.closest('.ja-star-pick'); if (!st) return;
    var w = st.parentNode, val = parseInt(st.getAttribute('data-val'), 10);
    w.setAttribute('data-my-rating', val); paint(w);
    var row = w.closest('.ja-dest-rating'), avgEl = row && row.querySelector('.ja-dest-rating-avg');
    var fd = new FormData(); fd.append('destination', w.getAttribute('data-dest')); fd.append('rating', val);
    fetch('rate-destination.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) throw new Error('rejected');
        if (avgEl && typeof d.avg === 'number') avgEl.innerHTML = '<b>Rated ' + d.avg.toFixed(1) + ' of 5</b> by ' + d.n + ' traveller' + (d.n == 1 ? '' : 's') + '. Thanks for rating.';
      })
      .catch(function () {
        w.setAttribute('data-my-rating', '0'); paint(w);
        if (avgEl) avgEl.textContent = 'Your rating was not saved. Sign in again and retry.';
      });
  });
})();
