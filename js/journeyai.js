/* ============================================================================
   JourneyAI — motion & interaction layer.
   Progressive enhancement: uses Lenis + GSAP if present (loaded via CDN in
   ja-head.php), but every effect has a vanilla fallback so the page works
   even if those libraries fail to load. Respects prefers-reduced-motion.
   ============================================================================ */
(function () {
  "use strict";
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // overlay nav: solidify once scrolled past the hero fold
  (function () {
    var nav = document.getElementById("jaNav");
    if (!nav || !nav.classList.contains("overlay")) return;
    function onScroll() { nav.classList.toggle("scrolled", window.scrollY > 60); }
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  })();

  document.addEventListener("DOMContentLoaded", function () {
    initTheme();
    // Enable the reveal system only now that JS is confirmed alive.
    // (CSS keeps content visible by default; this opts into animate-in.)
    if (!reduce) document.documentElement.classList.add("ja-animate");
    if (reduce) { revealAllNow(); return; }
    initSmoothScroll();
    initReveals();
    initSplitText();
    initMagnetic();
    initCursorGlow();
    initTilt();
    initParallax();
    initCountUps();
    initScoreBars();
    // safety net: never leave content stuck invisible
    setTimeout(function () {
      document.querySelectorAll(".reveal:not(.in), .stagger:not(.in)").forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < window.innerHeight + 200) el.classList.add("in");
      });
    }, 2500);
  });

  /* ---- theme toggle (persisted) ---- */
  function initTheme() {
    var root = document.documentElement;
    var saved = localStorage.getItem("ja-theme");
    if (saved) root.setAttribute("data-theme", saved);

    function effective() {
      var t = root.getAttribute("data-theme");
      if (t === "dark" || t === "light") return t;
      return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    }
    document.querySelectorAll("[data-ja-theme-toggle]").forEach(function (btn) {
      if (btn.__jaBound) return;      // guard against double-binding
      btn.__jaBound = true;
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var next = effective() === "dark" ? "light" : "dark";
        root.setAttribute("data-theme", next);
        localStorage.setItem("ja-theme", next);
      });
    });
  }

  /* ---- Lenis smooth scroll (if available) ---- */
  function initSmoothScroll() {
    if (typeof window.Lenis === "undefined") return;
    try {
      var lenis = new window.Lenis({ duration: 1.1, smoothWheel: true });
      function raf(t) { lenis.raf(t); requestAnimationFrame(raf); }
      requestAnimationFrame(raf);
      window.__lenis = lenis;
      if (window.gsap && window.ScrollTrigger) {
        lenis.on("scroll", window.ScrollTrigger.update);
      }
    } catch (e) { /* ignore */ }
  }

  /* ---- scroll reveals via IntersectionObserver (works without GSAP) ---- */
  function initReveals() {
    var els = document.querySelectorAll(".reveal, .stagger");
    if (!("IntersectionObserver" in window)) { els.forEach(function (e) { e.classList.add("in"); }); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          var el = en.target;
          if (el.classList.contains("stagger")) {
            Array.prototype.forEach.call(el.children, function (c, i) {
              c.style.transitionDelay = (i * 0.08) + "s";
            });
          }
          el.classList.add("in");
          io.unobserve(el);
        }
      });
    }, { threshold: 0.12 });
    els.forEach(function (e) { io.observe(e); });
  }

  function revealAllNow() {
    document.querySelectorAll(".reveal,.stagger").forEach(function (e) { e.classList.add("in"); });
    initCountUps(true); initScoreBars(true); initTheme();
  }

  /* ---- split-text headline reveal ---- */
  function initSplitText() {
    document.querySelectorAll("[data-split]").forEach(function (el) {
      var words = el.textContent.trim().split(/\s+/);
      el.textContent = "";
      el.setAttribute("aria-label", words.join(" "));
      words.forEach(function (w, i) {
        var span = document.createElement("span");
        span.textContent = w;
        span.style.display = "inline-block";
        span.style.opacity = "0";
        span.style.transform = "translateY(40px) rotate(4deg)";
        span.style.transition = "opacity .7s cubic-bezier(.22,.61,.36,1) " + (i * 0.07) + "s, transform .7s cubic-bezier(.22,.61,.36,1) " + (i * 0.07) + "s";
        el.appendChild(span);
        el.appendChild(document.createTextNode(" "));
      });
      requestAnimationFrame(function () {
        Array.prototype.forEach.call(el.querySelectorAll("span"), function (s) {
          s.style.opacity = "1"; s.style.transform = "none";
        });
      });
    });
  }

  /* ---- magnetic buttons ---- */
  function initMagnetic() {
    document.querySelectorAll("[data-magnetic]").forEach(function (btn) {
      btn.addEventListener("mousemove", function (e) {
        var r = btn.getBoundingClientRect();
        var x = e.clientX - r.left - r.width / 2;
        var y = e.clientY - r.top - r.height / 2;
        btn.style.transform = "translate(" + x * 0.25 + "px," + y * 0.35 + "px)";
      });
      btn.addEventListener("mouseleave", function () { btn.style.transform = ""; });
    });
  }

  /* ---- cursor glow ---- */
  function initCursorGlow() {
    if (window.matchMedia("(pointer:coarse)").matches) return;
    var glow = document.createElement("div");
    glow.style.cssText = "position:fixed;top:0;left:0;width:340px;height:340px;border-radius:50%;pointer-events:none;z-index:9999;transform:translate(-50%,-50%);background:radial-gradient(circle,rgba(49,198,212,.14),transparent 65%);transition:opacity .3s;mix-blend-mode:screen";
    document.body.appendChild(glow);
    var tx = 0, ty = 0, cx = 0, cy = 0;
    document.addEventListener("mousemove", function (e) { tx = e.clientX; ty = e.clientY; });
    (function loop() { cx += (tx - cx) * 0.12; cy += (ty - cy) * 0.12; glow.style.left = cx + "px"; glow.style.top = cy + "px"; requestAnimationFrame(loop); })();
  }

  /* ---- 3D tilt on hover ---- */
  function initTilt() {
    document.querySelectorAll("[data-tilt]").forEach(function (card) {
      card.addEventListener("mousemove", function (e) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width - 0.5;
        var py = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = "perspective(900px) rotateY(" + px * 7 + "deg) rotateX(" + (-py * 7) + "deg) translateY(-6px)";
      });
      card.addEventListener("mouseleave", function () { card.style.transform = ""; });
    });
  }

  /* ---- hero parallax ---- */
  function initParallax() {
    var layers = document.querySelectorAll("[data-parallax]");
    if (!layers.length) return;
    window.addEventListener("scroll", function () {
      var y = window.scrollY;
      layers.forEach(function (l) {
        var speed = parseFloat(l.getAttribute("data-parallax")) || 0.3;
        l.style.transform = "translateY(" + y * speed + "px) scale(1.1)";
      });
    }, { passive: true });
  }

  /* ---- count-up numbers ---- */
  function initCountUps(now) {
    var els = document.querySelectorAll("[data-countup]");
    function run(el) {
      var target = parseFloat(el.getAttribute("data-countup"));
      var prefix = el.getAttribute("data-prefix") || "";
      var dur = 1200, start = null;
      function step(t) {
        if (!start) start = t;
        var p = Math.min((t - start) / dur, 1);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = prefix + Math.round(target * eased).toLocaleString("en-IN");
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }
    if (now || !("IntersectionObserver" in window)) { els.forEach(run); return; }
    var io = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { run(en.target); io.unobserve(en.target); } });
    }, { threshold: 0.5 });
    els.forEach(function (e) { io.observe(e); });
  }

  /* ---- animated score bars ---- */
  function initScoreBars(now) {
    var bars = document.querySelectorAll(".ja-score-fill[data-score]");
    function run(b) { b.style.width = (parseFloat(b.getAttribute("data-score")) * 100) + "%"; }
    if (now || !("IntersectionObserver" in window)) { setTimeout(function () { bars.forEach(run); }, 100); return; }
    var io = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { run(en.target); io.unobserve(en.target); } });
    }, { threshold: 0.4 });
    bars.forEach(function (b) { io.observe(b); });
  }

  /* ---- expose helper to (re)init dynamically-added cards ---- */
  window.jaInit = function () {
    initReveals(); initTilt(); initCountUps(); initScoreBars(); initMagnetic();
  };
})();
