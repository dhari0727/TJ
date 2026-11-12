/* JourneyAI offline support: registers the service worker (https or localhost only) and lets a plan be saved for offline.
   Saved pages live in Cache Storage "ja-offline-pages"; offline-index.json in the same cache lists them for offline.html. */
(function () {
  'use strict';
  var PAGES = 'ja-offline-pages';
  var secure = location.protocol === 'https:' || /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
  var hasCaches = 'caches' in window;
  var INDEX = new URL('offline-index.json', document.baseURI).href;

  function norm(href) {
    var u = new URL(href || location.href, document.baseURI);
    u.searchParams.delete('saved'); u.hash = '';
    return u.href;
  }
  function readIndex(c) { return c.match(INDEX).then(function (r) { return r ? r.json() : []; }).catch(function () { return []; }); }
  function writeIndex(c, list) {
    return c.put(INDEX, new Response(JSON.stringify(list), { headers: { 'Content-Type': 'application/json' } }));
  }
  function toast(m) { if (window.JATrips) window.JATrips.toast(m); }

  if (secure && 'serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {}); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    /* mark saved-plan cards that are stored on this device */
    if (hasCaches && secure) {
      var flags = document.querySelectorAll('[data-offline-url]');
      if (flags.length) caches.open(PAGES).then(readIndex).then(function (list) {
        var have = {}; list.forEach(function (p) { have[p.url] = 1; });
        flags.forEach(function (el) {
          if (have[norm(el.getAttribute('data-offline-url'))]) { var f = el.querySelector('[data-offline-flag]'); if (f) f.classList.add('on'); }
        });
      });
    }

    var panel = document.getElementById('tpOffline'), btn = document.getElementById('tpOfflineBtn'), state = document.getElementById('tpOfflineState');
    if (!panel || !btn) return;
    if (!hasCaches || !secure || !('serviceWorker' in navigator)) return;   // not supported here: the panel stays hidden
    panel.hidden = false;
    var url = norm(), label = btn.querySelector('span');

    function show(saved) {
      state.hidden = !saved;
      label.textContent = saved ? 'Remove offline copy' : 'Save for offline';
      btn.dataset.saved = saved ? '1' : '';
    }
    caches.open(PAGES).then(function (c) { return c.match(url); }).then(function (hit) { show(!!hit); });

    btn.addEventListener('click', function () {
      btn.disabled = true;
      caches.open(PAGES).then(function (c) {
        if (btn.dataset.saved) {
          return c.delete(url).then(function () { return readIndex(c); }).then(function (list) {
            return writeIndex(c, list.filter(function (p) { return p.url !== url; }));
          }).then(function () { show(false); toast('Removed from this device.'); });
        }
        label.textContent = 'Saving';
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (res) {
          if (!res.ok) throw new Error('bad');
          var data = document.getElementById('tripData');
          return c.put(url, res.clone()).then(function () {
            if (data) return c.put(new URL('offline-data/' + encodeURIComponent(btn.dataset.title || 'trip') + '.json', document.baseURI).href,
              new Response(data.textContent, { headers: { 'Content-Type': 'application/json' } }));
          }).then(function () { return readIndex(c); }).then(function (list) {
            list = list.filter(function (p) { return p.url !== url; });
            list.push({ url: url, title: btn.dataset.title || document.title, cost: Number(btn.dataset.cost) || 0, days: Number(btn.dataset.days) || 0, saved: Date.now() });
            return writeIndex(c, list);
          });
        }).then(function () { show(true); toast('Saved. This plan will open without a connection.'); });
      }).catch(function () { show(!!btn.dataset.saved); toast('Could not save for offline. Check your connection and try again.'); })
        .then(function () { btn.disabled = false; });
    });
  });

  window.addEventListener('offline', function () { toast('You are offline. Plans saved for offline still open.'); });
})();
