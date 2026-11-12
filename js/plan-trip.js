/* JourneyAI — plan-trip page: form controls, live cost helper, loading state. */
(function () {
  function $(id) { return document.getElementById(id); }
  var b = $('budget'), lbl = $('budgetLabel');
  if (!b) return;

  function inr(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* budget slider: label and filled track */
  function updBudget() {
    var pct = (b.value - b.min) / (b.max - b.min) * 100;
    b.style.background = 'linear-gradient(90deg,var(--gold) 0%,var(--gold) ' + pct + '%,var(--bg-2) ' + pct + '%,var(--bg-2) 100%)';
    lbl.textContent = inr(b.value);
  }
  b.addEventListener('input', function () { updBudget(); scheduleEstimate(); });
  updBudget();

  /* interests: toggle buttons -> hidden inputs */
  var chips = $('chips'), box = $('interestInputs');
  function syncInterests() {
    box.innerHTML = '';
    chips.querySelectorAll('.ja-chip.on').forEach(function (c) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = 'interests[]'; i.value = c.dataset.val; box.appendChild(i);
    });
  }
  chips.addEventListener('click', function (e) {
    var c = e.target.closest('.ja-chip'); if (!c) return;
    c.classList.toggle('on'); c.setAttribute('aria-pressed', c.classList.contains('on'));
    syncInterests();
  });
  syncInterests();

  /* trip length: one choice; it also sets a sensible day count that stays editable */
  var modeChips = $('modeChips'), modeInput = $('modeInput'), days = $('days');
  modeChips.addEventListener('click', function (e) {
    var c = e.target.closest('.ja-chip'); if (!c) return;
    modeChips.querySelectorAll('.ja-chip').forEach(function (x) { x.classList.remove('on'); x.setAttribute('aria-pressed', 'false'); });
    c.classList.add('on'); c.setAttribute('aria-pressed', 'true');
    modeInput.value = c.dataset.mode; days.value = c.dataset.days; scheduleEstimate();
  });

  /* ---- live cost helper ---- */
  var out = $('estOut'), place = $('estPlace'), timer = null, seq = 0, lastQuery = '';
  var form = $('planForm');
  function field(n) { return form.elements[n]; }
  function queryString() {
    var p = new URLSearchParams();
    p.set('days', Math.max(1, Math.min(30, parseInt(days.value, 10) || 1)));
    p.set('party', Math.max(1, Math.min(20, parseInt(field('party').value, 10) || 1)));
    p.set('month', field('month').value || 0);
    p.set('style', field('style').value);
    if (place.value.trim()) p.set('destination', place.value.trim());
    return p.toString();
  }
  function skeleton() {
    out.innerHTML = '<div class="dz-est-skel" aria-hidden="true"><i></i><i></i><i></i></div><span class="dz-sr">Estimating the cost</span>';
    out.setAttribute('aria-busy', 'true');
  }
  function budgetLine(total) {
    var bud = parseInt(b.value, 10) || 0;
    if (!total) return '';
    if (bud >= total) return '<p class="dz-est-fit ok">Your ' + inr(bud) + ' budget covers this with about ' + inr(bud - total) + ' to spare.</p>';
    return '<p class="dz-est-fit stretch">This is about ' + inr(total - bud) + ' over your ' + inr(bud) + ' budget. Try fewer days or a lower-cost place.</p>';
  }
  function show(d, qs) {
    out.removeAttribute('aria-busy');
    var who = (parseInt(field('party').value, 10) || 1);
    var meta = d.days + ' day' + (d.days == 1 ? '' : 's') + ', ' + who + ' traveller' + (who == 1 ? '' : 's');
    var h = '';
    if (d.mode === 'place') {
      var e = d.items[0];
      h = '<p class="dz-est-main"><strong>' + inr(e.low) + ' to ' + inr(e.high) + '</strong><span>' + esc(e.name.split(',')[0]) + ', ' + esc(meta) + '</span></p>' +
          '<p class="dz-est-sub">Most likely ' + inr(e.total) + ', about ' + inr(e.per_day) + ' a day.</p>' + budgetLine(e.total);
    } else {
      h = '<p class="dz-est-cap">' + esc(meta) + '</p><ul class="dz-est-list">' + d.items.map(function (e) {
        return '<li><span>' + esc(e.label) + '<em>' + esc(e.name.split(',')[0]) + '</em></span><b>' + inr(e.low) + ' to ' + inr(e.high) + '</b></li>';
      }).join('') + '</ul>' + budgetLine(d.items[1] && d.items[1].total);
    }
    out.innerHTML = h;
  }
  function fail(msg) {
    out.removeAttribute('aria-busy');
    out.innerHTML = '<div class="dz-est-err" role="alert"><p>' + esc(msg) + '</p><button type="button" class="dz-textbtn" id="estRetry">Try again</button></div>';
    var r = $('estRetry'); if (r) r.addEventListener('click', runEstimate);
  }
  function runEstimate() {
    var qs = queryString(), my = ++seq; lastQuery = qs;
    skeleton();
    fetch('cost-estimate.php?' + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (my !== seq) return;   // a newer request is in flight
        if (!res.ok || res.j.status !== 'ok') return fail(res.j.error || 'We could not get an estimate. Try again in a moment.');
        show(res.j, qs);
      })
      .catch(function () { if (my === seq) fail('We could not reach the cost model. Check your connection and try again. Your plan search still works.'); });
  }
  function scheduleEstimate() { clearTimeout(timer); if (out) timer = setTimeout(runEstimate, 450); }
  ['days', 'party'].forEach(function (n) { field(n).addEventListener('input', scheduleEstimate); });
  ['month', 'style'].forEach(function (n) { field(n).addEventListener('change', scheduleEstimate); });
  if (place) { place.addEventListener('input', scheduleEstimate); place.addEventListener('change', scheduleEstimate); }
  /* first estimate once the panel is visible */
  var adv = $('adv'), first = false;
  function maybeFirst() { if (!first && adv.open) { first = true; runEstimate(); } }
  adv.addEventListener('toggle', maybeFirst); maybeFirst();

  /* ---- submit: show placeholders where results will appear ---- */
  form.addEventListener('submit', function () {
    var btn = $('planSubmit'); if (btn) { btn.textContent = 'Finding destinations...'; btn.disabled = true; }
    var res = $('planResults'), tpl = $('skelTpl');
    if (res && tpl) { res.innerHTML = '<p class="dz-hint" role="status">Ranking places for your budget, month and interests. This takes a few seconds.</p>'; res.appendChild(tpl.content.cloneNode(true)); }
  });

  /* ---- "Use my location": geolocation -> reverse geocode -> origin text ---- */
  var geoBtn = $('nearMeBtn'), geoStatus = $('geoStatus'), origin = $('origin');
  function detect() {
    if (!navigator.geolocation) { geoStatus.textContent = 'Your browser can not share a location. Type your city instead.'; return; }
    geoStatus.textContent = 'Finding your location...';
    navigator.geolocation.getCurrentPosition(function (pos) {
      fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude + '&zoom=12')
        .then(function (r) { return r.json(); }).then(function (d) {
          var a = d.address || {};
          var city = a.city || a.town || a.village || a.suburb || a.county || a.state_district || a.state || '';
          if (city) { origin.value = city; geoStatus.textContent = 'Starting from ' + city + '. Day trips and weekends will list real places nearby.'; }
          else geoStatus.textContent = 'We could not name your location. Type your city instead.';
        }).catch(function () { geoStatus.textContent = 'The place lookup failed. Type your city instead.'; });
    }, function (err) {
      geoStatus.textContent = err.code === 1 ? 'Location access is blocked in your browser. Type your city instead.' : 'Your location is not available right now. Type your city instead.';
    }, { timeout: 9000, maximumAge: 600000 });
  }
  if (geoBtn) geoBtn.addEventListener('click', detect);
})();
