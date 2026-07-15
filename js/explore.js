/* JourneyAI — Explore: one input -> instant complete trip. */
(function () {
  var form = document.getElementById('smartForm');
  var input = document.getElementById('smartInput');
  var out = document.getElementById('smartResults');
  var geoNote = document.getElementById('geoNote');
  if (!form) return;

  var myLocation = null; // filled if user allows geolocation for "near me"

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function inr(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }
  function maps(lat, lon) { return 'https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lon; }

  var ICONS = { star: 'M12 2l3 7 7 .5-5.5 4.5 2 7L12 17l-6.5 4 2-7L2 9.5 9 9z', home: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', pin: 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z', compass: 'M12 2a10 10 0 100 20 10 10 0 000-20zM16 8l-6 2-2 6 6-2z', heart: 'M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 00-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 000-7.8z' };
  function svg(n) { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' + (ICONS[n] || ICONS.pin) + '"/></svg>'; }

  function loading() {
    out.innerHTML = '<div class="ja-smart-loading">' +
      '<div class="ja-skel" style="height:120px"></div>' +
      '<div class="ja-skel" style="height:120px"></div>' +
      '<div class="ja-skel" style="height:120px"></div></div>';
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function placeCard(p) {
    var img = p.image ? '<div class="ja-pc-img" style="background-image:url(\'' + esc(p.image) + '\')"></div>' : '<div class="ja-pc-img noimg">' + svg('pin') + '</div>';
    return '<a class="ja-place-card" href="' + maps(p.lat, p.lon) + '" target="_blank" rel="noopener">' + img +
      '<div class="ja-pc-body"><div class="ja-pc-name">' + esc(p.name) + '</div>' +
      '<div class="ja-pc-meta">' + esc(p.dist_km) + ' km · ' + esc(p.category) + '</div></div></a>';
  }

  function renderRoute(d) {
    var r = d.route, o = r.origin;
    var stops = r.route.map(function (s, i) {
      var img = s.image ? '<div class="ja-rs-img" style="background-image:url(\'' + esc(s.image) + '\')"></div>' : '';
      var eats = (s.eats || []).slice(0, 2).map(function (e) { return '<span class="ja-eat-chip">' + esc(e.name) + '</span>'; }).join('');
      return '<div class="ja-rs">' + '<div class="ja-rs-num">' + (i + 1) + '</div>' + img +
        '<div class="ja-rs-body"><div class="ja-rs-name">' + esc(s.name) + '</div>' +
        '<div class="ja-rs-do">' + svg('compass') + ' ' + esc(s.do || '') + '</div>' +
        (eats ? '<div class="ja-rs-eat">' + svg('heart') + ' ' + eats + '</div>' : '') +
        '<a class="ja-rs-map" target="_blank" href="' + maps(s.lat, s.lon) + '">Google Maps</a></div></div>';
    }).join('');
    var routeUrl = 'route.php?place=' + encodeURIComponent(d.origin_text) + '&mode=' + esc(d.intent.mode) + '&interests=' + encodeURIComponent((d.interests || []).join(','));
    return section(svg('compass') + ' Your ' + d.days + '-day route from ' + esc(o.display.split(',')[0]),
      r.total_km + ' km · ' + r.stops + ' stops',
      '<div class="ja-route-mini">' + stops + '</div>' +
      '<a class="ja-btn ja-btn-primary" href="' + routeUrl + '">Open full route with map ' + svg('compass') + '</a>');
  }

  function renderNearby(d) {
    var cards = (d.nearby || []).map(placeCard).join('');
    return section(svg('pin') + ' Places near ' + esc(d.origin_text), (d.nearby || []).length + ' real spots',
      '<div class="ja-place-grid">' + cards + '</div>' +
      '<a class="ja-btn ja-btn-primary" href="route.php?place=' + encodeURIComponent(d.origin_text) + '&mode=' + esc(d.intent.mode) + '">Turn into a route ' + svg('compass') + '</a>');
  }

  function renderRecos(d) {
    var cards = (d.recommendations || []).map(function (r) {
      return '<div class="ja-reco-mini">' +
        '<div class="ja-rm-name">' + esc(r.destination) + '</div>' +
        '<div class="ja-rm-cost">' + inr(r.predicted_cost) + '</div>' +
        '<div class="ja-rm-why">' + esc((r.explanation || '').slice(0, 130)) + '</div>' +
        '<a class="ja-btn ja-btn-ghost" href="itinerary.php?dest=' + encodeURIComponent(r.destination) + '&days=' + d.days + '">Build itinerary →</a></div>';
    }).join('');
    return section(svg('star') + ' Best matches for your ' + d.days + '-day trip', (d.budget ? 'under ' + inr(d.budget) : 'ranked & explained'),
      '<div class="ja-reco-mini-grid">' + cards + '</div>');
  }

  function section(title, sub, body) {
    return '<div class="ja-smart-section reveal in"><div class="ja-ss-head"><h2>' + title + '</h2><span>' + esc(sub) + '</span></div>' + body + '</div>';
  }

  function render(d) {
    if (d.error) { out.innerHTML = '<div class="ja-empty">' + esc(d.error) + '</div>'; return; }
    var html = '';
    if (d.kind === 'route' && d.route) html += renderRoute(d);
    else if (d.kind === 'nearby') html += renderNearby(d);
    else if (d.kind === 'recommend') html += renderRecos(d);
    else html += '<div class="ja-empty">Try adding where you\'re starting from.</div>';
    // cost strip
    if (d.est_cost) html += '<div class="ja-cost-strip">' + svg('heart') + ' Rough local budget: <strong>' + inr(d.est_cost) + '</strong> for ' + d.days + ' day(s)</div>';
    out.innerHTML = html;
  }

  function run(text) {
    if (!text.trim()) return;
    loading();
    // if the query mentions "near me", try to resolve location first
    var payload = { text: text, travel_style: 'mid-range' };
    fetch('smart-plan.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () { out.innerHTML = '<div class="ja-empty">Something went wrong. Try again.</div>'; });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value); });
  document.querySelectorAll('[data-ex]').forEach(function (b) {
    b.addEventListener('click', function () { input.value = b.getAttribute('data-ex'); run(input.value); });
  });

  // "near me" -> resolve city via geolocation + reverse geocode, swap into input
  function resolveNearMe(cb) {
    if (!navigator.geolocation) { cb(null); return; }
    geoNote.textContent = 'Finding your location…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude + '&zoom=12')
        .then(function (r) { return r.json(); }).then(function (dd) {
          var a = dd.address || {};
          var city = a.city || a.town || a.village || a.suburb || a.county || a.state || '';
          geoNote.textContent = city ? 'Using your location: ' + city : '';
          cb(city || null);
        }).catch(function () { cb(null); });
    }, function () { geoNote.textContent = ''; cb(null); }, { timeout: 8000, maximumAge: 600000 });
  }

  // intercept "near me" before sending
  var origRun = run;
  run = function (text) {
    if (/near me|nearby|around me/i.test(text)) {
      resolveNearMe(function (city) {
        var t = city ? text.replace(/near me|nearby|around me/i, 'near ' + city) : text;
        origRun(t);
      });
    } else origRun(text);
  };
  form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value); }, true);

  // auto-run if ?q= present
  if (input.value.trim()) run(input.value);
})();
