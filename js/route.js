/* JourneyAI — route page: map, main/alt toggle, modify (remove stop), save. */
(function () {
  var D = window.__routeData;
  if (!D) return;
  var current = "main";
  var map, layer;

  function activeRoute() {
    return current === "alt" && D.alternative ? D.alternative.route : D.route;
  }
  function activeOrigin() { return D.origin; }

  function haversine(a, b, c, d) {
    var R = 6371, dl = (c - a) * Math.PI / 180, dn = (d - b) * Math.PI / 180;
    var x = Math.sin(dl / 2) * Math.sin(dl / 2) + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(dn / 2) * Math.sin(dn / 2);
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }

  function recompute(route) {
    // recompute leg distances + total from origin through the (possibly edited) list
    var o = activeOrigin(), cur = [o.lat, o.lon], total = 0;
    route.forEach(function (s) {
      var leg = Math.round(haversine(cur[0], cur[1], s.lat, s.lon) * 10) / 10;
      s.leg_km = leg; total += leg; cur = [s.lat, s.lon];
    });
    var back = Math.round(haversine(cur[0], cur[1], o.lat, o.lon) * 10) / 10;
    return { total: Math.round((total + back) * 10) / 10, back: back };
  }

  function icon(cat) {
    return { religious: 'star', heritage: 'home', garden: 'sun', nature: 'sun', museum: 'book', funpark: 'compass', hotel: 'home', food: 'heart', shopping: 'wallet', beach: 'sun', attraction: 'map-pin' }[cat] || 'map-pin';
  }
  function svg(n) { // minimal inline icons
    var p = { star: '<polygon points="12 2 15 9 22 9 17 14 18 21 12 17 6 21 7 14 2 9 9 9"/>', home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>', 'map-pin': '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>', compass: '<circle cx="12" cy="12" r="10"/><polygon points="16 8 14 14 8 16 10 10 16 8"/>', trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>', heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>', sun: '<circle cx="12" cy="12" r="5"/>', book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5z"/>', wallet: '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/>' };
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (p[n] || p['map-pin']) + '</svg>';
  }

  function renderStops() {
    var route = activeRoute(), host = document.getElementById('stopsHost');
    host.innerHTML = '';
    route.forEach(function (s, i) {
      var img = s.image
        ? '<div class="ja-route-img" style="background-image:url(\'' + s.image + '\')"></div>'
        : '<div class="ja-route-img noimg">' + svg(icon(s.category)) + '</div>';
      var eats = s.eats || (s.eat ? [s.eat] : []);
      var eat = eats.length ? '<div class="ja-route-eat">' + svg('heart') + ' <span>Eat nearby:</span> ' +
        eats.map(function (e) { return '<a class="ja-eat-chip" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=' + e.lat + ',' + e.lon + '">' + e.name + ' <span style="opacity:.6">' + e.dist_km + 'km</span></a>'; }).join('') + '</div>' : '';
      var el = document.createElement('div');
      el.className = 'ja-route-stop';
      el.innerHTML = '<div class="ja-route-dot">' + (i + 1) + '</div><div class="ja-route-card">' + img +
        '<div class="ja-route-info"><div class="ja-route-head"><div>' +
        '<div class="ja-route-name">' + svg(icon(s.category)) + ' ' + s.name + '</div>' +
        '<div class="ja-route-sub" style="text-transform:capitalize">' + s.category + ' · ' + s.leg_km + ' km away</div></div>' +
        '<button type="button" class="ja-route-remove" data-remove="' + i + '" title="Remove">' + svg('trash') + '</button></div>' +
        '<div class="ja-route-do">' + svg('compass') + ' ' + (s.do || 'Visit this spot') + '</div>' + eat +
        '<a class="ja-route-map" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=' + s.lat + ',' + s.lon + '">' + svg('map-pin') + ' Google Maps</a>' +
        '</div></div>';
      host.appendChild(el);
    });
    var t = recompute(route);
    var km = document.getElementById('rKm'), bk = document.getElementById('backKm'), st = document.getElementById('rStops');
    if (km) km.textContent = Math.round(t.total);
    if (bk) bk.textContent = Math.round(t.back);
    if (st) st.textContent = route.length;
    bindRemove(); drawMap();
  }

  function bindRemove() {
    document.querySelectorAll('[data-remove]').forEach(function (b) {
      b.addEventListener('click', function () {
        var route = activeRoute(), i = +b.getAttribute('data-remove');
        if (route.length <= 1) return;
        route.splice(i, 1);
        renderStops();
      });
    });
  }

  function drawMap() {
    if (typeof L === 'undefined') { setTimeout(drawMap, 250); return; }
    var o = activeOrigin(), route = activeRoute();
    if (!map) {
      map = L.map('map', { scrollWheelZoom: false }).setView([o.lat, o.lon], 9);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 17 }).addTo(map);
    }
    if (layer) map.removeLayer(layer);
    layer = L.layerGroup().addTo(map);
    var pts = [[o.lat, o.lon]];
    L.marker([o.lat, o.lon]).addTo(layer).bindPopup('Start');
    route.forEach(function (s, i) { pts.push([s.lat, s.lon]); L.marker([s.lat, s.lon]).addTo(layer).bindPopup((i + 1) + '. ' + s.name); });
    pts.push([o.lat, o.lon]);
    L.polyline(pts, { color: '#0E7C86', weight: 3, opacity: .8, dashArray: '6 6' }).addTo(layer);
    if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.15));
  }

  // toggle main/alt
  var tog = document.getElementById('routeToggle');
  if (tog) tog.addEventListener('click', function (e) {
    var c = e.target.closest('.ja-chip'); if (!c) return;
    tog.querySelectorAll('.ja-chip').forEach(function (x) { x.classList.remove('on'); });
    c.classList.add('on'); current = c.getAttribute('data-route'); renderStops();
  });

  // save route
  var saveBtn = document.getElementById('saveRouteBtn');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    var route = activeRoute(), t = recompute(route);
    var body = new URLSearchParams();
    body.set('action', 'save');
    body.set('title', (D.origin.display.split(',')[0]) + ' route (' + route.length + ' stops)');
    body.set('origin', D.origin.place || D.origin.display);
    body.set('mode', D.mode);
    body.set('total_km', t.total);
    body.set('data', JSON.stringify({ origin: D.origin, route: route, total_km: t.total, mode_label: D.mode_label }));
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    fetch('save-route.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.disabled = false;
        if (d.ok) { saveBtn.innerHTML = '✓ Saved — view in My Routes'; saveBtn.onclick = function(){ location.href = 'my-routes.php'; }; }
        else saveBtn.innerHTML = (d.error || 'Error');
      })
      .catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Error'; });
  });

  renderStops();
})();
