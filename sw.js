/* JourneyAI service worker.
   - CSS, JS, fonts and images: cache first (file URLs carry a ?v= version, so stale copies are not a problem).
   - Pages: network first. If the network is gone, serve a plan the user chose to save for offline, else offline.html.
   - Only pages the user explicitly saved are ever stored (cache "ja-offline-pages"); nothing else about a visit is kept. */
var STATIC = 'ja-static-v1';
var PAGES = 'ja-offline-pages';
var PRECACHE = ['offline.html', 'css/pages-trips.css', 'css/journeyai-brand.css', 'js/trips-ui.js', 'js/pwa.js', 'images/journeyai-logo.svg'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(STATIC).then(function (c) {
    return Promise.all(PRECACHE.map(function (u) { return c.add(u).catch(function () {}); }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('ja-static-') === 0 && k !== STATIC; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function isStatic(req, url) {
  if (/fonts\.(googleapis|gstatic)\.com$/.test(url.hostname)) return true;
  if (url.origin !== self.location.origin) return false;
  return ['style', 'script', 'font', 'image'].indexOf(req.destination) !== -1 || /\.(css|js|woff2?|svg|png|jpe?g|webp|gif|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () {
      return caches.open(PAGES).then(function (c) { return c.match(req.url); }).then(function (hit) {
        return hit || caches.open(STATIC).then(function (c) { return c.match('offline.html'); }).then(function (o) {
          return o || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } });
        });
      });
    }));
    return;
  }

  if (isStatic(req, url)) {
    e.respondWith(caches.open(STATIC).then(function (c) {
      return c.match(req).then(function (hit) {
        if (hit) return hit;
        return fetch(req).then(function (res) {
          if (res && (res.ok || res.type === 'opaque')) c.put(req, res.clone());
          return res;
        });
      });
    }));
  }
});
