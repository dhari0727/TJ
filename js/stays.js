/* JourneyAI — "Where to stay" on the trip page.
   Reads the trip's days / style / interests from #stays data attributes, asks stays-api.php for server-rendered
   cards, and refetches when the trip type or style is changed. Loads when the section nears the viewport. */
(function () {
  var sec = document.getElementById('stays');
  if (!sec) return;
  var body = sec.querySelector('.st-body');
  var styleSel = sec.querySelector('[data-st-style]');
  var chips = [].slice.call(sec.querySelectorAll('.st-chip'));
  var api = sec.getAttribute('data-st-api') || 'stays-api.php';
  var ctrl = null, timer = null, started = false;

  function selected() {
    // chips the page started with may include interests that have no chip (e.g. culture); keep those
    var extra = (sec.getAttribute('data-interests') || '').split(',').filter(function (x) {
      return x && !chips.some(function (c) { return c.getAttribute('data-int') === x; }) ;
    });
    var on = chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; })
      .map(function (c) { return c.getAttribute('data-int'); });
    return on.concat(extra).slice(0, 6);
  }

  function query() {
    var q = new URLSearchParams({
      dest: sec.getAttribute('data-dest') || '', days: sec.getAttribute('data-days') || '3',
      style: styleSel ? styleSel.value : (sec.getAttribute('data-style') || 'mid-range'),
      interests: selected().join(','), party: sec.getAttribute('data-party') || '1', html: '1'
    });
    return q.toString();
  }

  function skeleton() {
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = '<div class="st-skel" role="status"><p>Finding places to stay. The first lookup for a destination can take up to a minute.</p>' +
      '<div class="st-skel-grid"><i></i><i></i><i></i></div></div>';
  }

  function show(html) {
    body.innerHTML = html;
    body.setAttribute('aria-busy', 'false');
    var r = body.querySelector('[data-st-retry]');
    if (r) r.addEventListener('click', load);
  }

  var seq = 0;
  function load() {
    var qs = query(), key = 'ja-stays:' + qs, mine = ++seq;
    if (ctrl) ctrl.abort();
    try { var hit = sessionStorage.getItem(key); if (hit) { show(hit); return; } } catch (e) {}
    ctrl = window.AbortController ? new AbortController() : null;
    var my = ctrl;
    skeleton();
    var tm = setTimeout(function () { if (my) my.abort(); }, 160000);
    function fail(msg) {
      show('<div class="st-state" role="alert"><h3>We could not load places to stay</h3><p>' + msg +
        '</p><button type="button" class="ja-btn ja-btn-ghost" data-st-retry>Try again</button></div>');
    }
    fetch(api + '?' + qs, { headers: { Accept: 'application/json' }, signal: my ? my.signal : undefined })
      .then(function (r) { return r.json().catch(function () { return { status: 'error', error: 'The server sent something unexpected.' }; }); })
      .then(function (j) {
        clearTimeout(tm);
        if (mine !== seq) return;
        if (j.html) show(j.html); else fail(j.error ? String(j.error).replace(/[<>&]/g, '') : 'Try again in a moment.');
        if (j.status === 'ok' && j.html) { try { sessionStorage.setItem(key, j.html); } catch (e) {} }
      })
      .catch(function (e) {
        clearTimeout(tm);
        if (mine !== seq) return;      // a newer request replaced this one and will paint
        fail(e && e.name === 'AbortError' ? 'The lookup took too long. Try again; the second attempt is usually much faster.' : 'Check your connection and try again.');
      });
  }

  function later() { clearTimeout(timer); timer = setTimeout(function () { timer = null; load(); }, 350); }

  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      later();
    });
  });
  if (styleSel) styleSel.addEventListener('change', later);

  function start() { if (started) return; started = true; load(); }
  if ('IntersectionObserver' in window && location.hash !== '#stays') {
    var io = new IntersectionObserver(function (es) {
      if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(); }
    }, { rootMargin: '700px 0px' });
    io.observe(sec);
  } else {
    start();
  }
})();
