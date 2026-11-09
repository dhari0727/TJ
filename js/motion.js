/* JourneyAI motion: scroll progress bar + staggered spring entrances. Pure enhancement: with JS off or
   prefers-reduced-motion, everything simply shows. */
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // scroll progress
  var bar = document.createElement('div'); bar.className = 'wm-progress'; document.body.appendChild(bar);
  function progress() {
    var h = document.documentElement, max = h.scrollHeight - h.clientHeight;
    bar.style.width = (max > 0 ? Math.min(100, h.scrollTop / max * 100) : 0) + '%';
  }
  window.addEventListener('scroll', progress, { passive: true }); progress();
  if (reduce || !('IntersectionObserver' in window)) return;

  var SEL = '.ja-card,.ja-reco,.ja-feed-card,.ja-post,.ja-place-card,.ja-nearby-card,.ja-quick a,.ja-reco-mini,.ja-modcard,.ja-pack-cat,.ja-stat,.ja-itin-day';
  var seen = new WeakSet(), io = new IntersectionObserver(function (entries) {
    var batch = entries.filter(function (e) { return e.isIntersecting; });
    batch.forEach(function (e, i) {
      e.target.style.setProperty('--i', i);
      e.target.classList.remove('wm-pre'); e.target.classList.add('wm-in');
      io.unobserve(e.target);
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

  function scan(root) {
    (root || document).querySelectorAll(SEL).forEach(function (el) {
      if (seen.has(el) || el.classList.contains('reveal')) return;
      seen.add(el);
      var r = el.getBoundingClientRect();
      if (r.top < window.innerHeight * 0.9 && r.bottom > 0) return;     // already on screen: do not hide it
      el.classList.add('wm-pre'); io.observe(el);
    });
  }
  scan();
  // content added later (search results, comments, lightboxes) animates in too
  new MutationObserver(function (m) { m.forEach(function (x) { x.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.matches && n.matches(SEL)) scan(n.parentNode); else scan(n); } }); }); })
    .observe(document.body, { childList: true, subtree: true });
})();
