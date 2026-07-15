/* JourneyAI — My Entries map: pins for photos with known lat/lon. */
(function () {
  var D = window.__entriesMapData;
  if (!D || !D.length) return;

  function drawMap() {
    if (typeof L === 'undefined') { setTimeout(drawMap, 250); return; }
    var el = document.getElementById('entriesMap');
    if (!el) return;

    var map = L.map('entriesMap', { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 17 }).addTo(map);

    var pts = [];
    D.forEach(function (p) {
      if (typeof p.lat !== 'number' || typeof p.lon !== 'number' || isNaN(p.lat) || isNaN(p.lon)) return;
      pts.push([p.lat, p.lon]);
      var popup = '<div style="min-width:160px">' +
        (p.img ? '<img src="' + p.img + '" style="width:100%;height:90px;object-fit:cover;border-radius:8px;margin-bottom:6px">' : '') +
        '<div style="font-weight:600">' + (p.title || 'Trip') + '</div>' +
        (p.city ? '<div style="color:#667;font-size:.82rem;margin-bottom:6px">' + p.city + '</div>' : '') +
        '<a href="display.php?id=' + p.entry_id + '" style="color:#0E7C86;font-weight:600;font-size:.85rem">View entry →</a>' +
        '</div>';
      L.marker([p.lat, p.lon]).addTo(map).bindPopup(popup);
    });

    if (pts.length) {
      if (pts.length === 1) map.setView(pts[0], 11);
      else map.fitBounds(L.latLngBounds(pts).pad(0.2));
    } else {
      map.setView([20, 0], 2);
    }
  }

  drawMap();
})();
