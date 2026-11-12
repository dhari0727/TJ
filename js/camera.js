/* JourneyAI camera: live viewfinder, filters, frames drawn onto a canvas, a polaroid that develops, then Save to the private camera roll.
   Filters are defined once and used twice: as a CSS filter on the live video and on the canvas capture (with a pixel fallback where
   ctx.filter does not exist). Location is never read. No libraries. */
(function () {
  'use strict';
  var root = document.getElementById('cm');
  if (!root) return;
  var $ = function (s) { return root.querySelector(s); };
  var $$ = function (s) { return [].slice.call(root.querySelectorAll(s)); };
  var cfg = {}; try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) {}
  var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LONG = 1600, QUALITY = 0.88;

  var FILTERS = {
    original: { steps: [] },
    warm:   { steps: [['sepia', .22], ['saturate', 1.25], ['hue-rotate', -8], ['brightness', 1.04]] },
    cool:   { steps: [['hue-rotate', 14], ['saturate', 1.08], ['brightness', 1.02], ['contrast', 1.05]] },
    mono:   { steps: [['grayscale', 1], ['contrast', 1.15]] },
    fade:   { steps: [['contrast', .85], ['brightness', 1.1], ['saturate', .8], ['sepia', .1]] },
    vivid:  { steps: [['saturate', 1.55], ['contrast', 1.12]] },
    sepia:  { steps: [['sepia', .9], ['contrast', 1.05]] },
    film:   { steps: [['contrast', 1.1], ['saturate', .9], ['sepia', .12]], fx: 'film' },
    golden: { steps: [['sepia', .32], ['saturate', 1.35], ['brightness', 1.08], ['contrast', 1.05], ['hue-rotate', -12]], fx: 'golden' }
  };
  /* side/top/bottom are fractions of the photo width; ar is the photo's width / height (null = as shot) */
  var FRAMES = {
    classic:  { ar: 1,   side: .055, top: .055, bottom: .25 },
    wide:     { ar: 1.5, side: .04,  top: .04,  bottom: .17 },
    film:     { ar: 1.5, side: .035, top: .09,  bottom: .2 },
    postcard: { ar: 1.5, side: .045, top: .045, bottom: .21 },
    none:     { ar: null, side: 0,   top: 0,    bottom: 0 }
  };
  function cssFilter(name) {
    var st = (FILTERS[name] || FILTERS.original).steps;
    return st.length ? st.map(function (s) { return s[0] + '(' + s[1] + (s[0] === 'hue-rotate' ? 'deg' : '') + ')'; }).join(' ') : 'none';
  }

  var S = {
    filter: 'original', frame: 'classic', timer: 0, facing: 'environment', stream: null, mirror: false,
    snap: null, when: new Date(), blob: null, url: '', counting: 0, saving: false, hadStream: false, devices: 0
  };
  var els = {
    video: $('#cmVideo'), shot: $('#cmShot'), card: $('#cmCard'), stage: $('#cmStage'), fx: $('#cmFx'), flash: $('#cmFlash'), count: $('#cmCount'),
    msg: $('#cmMsg'), msgTitle: $('#cmMsgTitle'), msgBody: $('#cmMsgBody'), cap: $('#cmCap'), out: $('#cmOut'), outImg: $('#cmOutImg'),
    dev: $('#cmDev'), print: $('#cmPrint'), status: $('#cmStatus'), shutter: $('#cmShutter'), flip: $('#cmFlip'), save: $('#cmSave'),
    caption: $('#cmCaption'), place: $('#cmPlace'), folder: $('#cmFolder'), quota: $('#cmQuota'), retry: $('#cmRetry')
  };

  /* ---------- small helpers ---------- */
  var toastEl = null, toastT = 0;
  function toast(msg, linkText, href) {
    if (toastEl) toastEl.remove();
    clearTimeout(toastT);
    toastEl = document.createElement('div'); toastEl.className = 'cm-toast'; toastEl.setAttribute('role', 'status');
    var s = document.createElement('span'); s.textContent = msg; toastEl.appendChild(s);
    if (linkText) { var a = document.createElement('a'); a.href = href; a.textContent = linkText; toastEl.appendChild(a); }
    document.body.appendChild(toastEl);
    toastT = setTimeout(function () { if (toastEl) { toastEl.remove(); toastEl = null; } }, 5500);
  }
  function say(t) { els.status.textContent = t || ''; }
  function setState(s) { root.setAttribute('data-state', s); }
  function dateText(d) { try { return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); } catch (e) { return d.toDateString(); } }
  function secure() { return window.isSecureContext !== false; }
  function canStream() { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && secure(); }

  /* ---------- text on the card (same wording in the live card and on the canvas) ---------- */
  function textSpec(frame, forCanvas) {
    var c = els.caption.value.trim(), p = els.place.value.trim(), d = dateText(S.when);
    if (frame === 'postcard') {
      if (c) return { l1: c, l2: p ? 'Greetings from ' + p + ', ' + d : d };
      return { l1: p ? 'Greetings from ' + p : '', l2: d };
    }
    if (frame === 'film') return { l1: c, l2: p, rt: d };
    if (frame === 'none') return { l1: '', l2: '' };
    return { l1: c, l2: p ? p + ', ' + d : d };
  }

  /* ---------- the live card ---------- */
  function applyFrameToCard() {
    var f = FRAMES[S.frame], ar = f.ar || nativeAr();
    var unit = 100 / (1 + 2 * f.side);
    var st = els.card.style;
    els.card.setAttribute('data-frame', S.frame);
    els.stage.setAttribute('data-frame', S.frame);
    st.setProperty('--ps', (f.side * unit) + '%');
    st.setProperty('--pt', (f.top * unit) + '%');
    st.setProperty('--pb', (f.bottom * unit) + '%');
    st.setProperty('--pbc', (f.bottom * unit) + 'cqw');
    st.setProperty('--ar', String(ar));
    var wr = (1 + 2 * f.side) / (1 / ar + f.top + f.bottom);
    els.stage.style.setProperty('--cw', 'clamp(260px, calc((100dvh - 400px) * ' + wr.toFixed(4) + '), 560px)');
    els.stage.style.setProperty('--wr', wr.toFixed(4));
    updateCardText();
  }
  function nativeAr() {
    var v = els.video, w = v.videoWidth, h = v.videoHeight;
    if (S.snap && root.getAttribute('data-state') !== 'live') { w = S.snap.width; h = S.snap.height; }
    if (!w || !h) return 4 / 3;
    return Math.max(.6, Math.min(1.8, w / h));
  }
  function updateCardText() {
    var t = textSpec(S.frame), c = els.cap;
    c.querySelector('.l1').textContent = t.l1 || '';
    c.querySelector('.l2').textContent = t.l2 || '';
    c.querySelector('.rt').textContent = t.rt || '';
    c.classList.toggle('is-empty', !(t.l1 || t.l2));
  }
  function applyFilterToLive() {
    els.video.style.filter = cssFilter(S.filter);
    els.fx.setAttribute('data-fx', (FILTERS[S.filter] || {}).fx || '');
  }

  /* ---------- camera ---------- */
  function showMsg(title, body, opts) {
    opts = opts || {};
    els.msgTitle.textContent = title; els.msgBody.textContent = body; els.msg.hidden = false;
    els.retry.hidden = !opts.retry;
    root.querySelector('#cmMsgSnap').hidden = !opts.snap;
    setState('nocam');
    say('');
  }
  function stopStream() {
    if (S.stream) { S.stream.getTracks().forEach(function (t) { t.stop(); }); S.stream = null; }
    els.video.srcObject = null;
  }
  function start() {
    if (!canStream()) {
      showMsg(secure() ? 'This browser cannot open the camera' : 'The camera needs a secure page',
        secure() ? 'You can still choose a photo from this device and add a frame to it.'
          : 'Browsers only allow the camera on https pages or on localhost. Open this site over https, or choose a photo instead.',
        { snap: true });
      return;
    }
    stopStream(); els.msg.hidden = true; setState('starting'); say('Opening the camera.');
    var constraints = { audio: false, video: { facingMode: { ideal: S.facing }, width: { ideal: 1920 }, height: { ideal: 1440 } } };
    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      S.stream = stream; S.hadStream = true;
      var tr = stream.getVideoTracks()[0], set = (tr && tr.getSettings && tr.getSettings()) || {};
      S.mirror = set.facingMode ? set.facingMode === 'user' : !('ontouchstart' in window);
      els.shot.classList.toggle('is-mirror', S.mirror);
      els.video.srcObject = stream;
      var go = function () { applyFrameToCard(); setState('live'); say(''); };
      els.video.onloadedmetadata = go;
      var pl = els.video.play(); if (pl && pl.catch) pl.catch(function () {});
      if (navigator.mediaDevices.enumerateDevices) navigator.mediaDevices.enumerateDevices().then(function (d) {
        S.devices = d.filter(function (x) { return x.kind === 'videoinput'; }).length;
        els.flip.hidden = S.devices < 2;
      }).catch(function () {});
    }).catch(function (err) {
      var n = err && err.name;
      if (n === 'NotAllowedError' || n === 'SecurityError' || n === 'PermissionDeniedError')
        showMsg('Camera access is blocked', 'Allow the camera for this site in your browser settings, then try again. Or choose a photo from this device.', { retry: true, snap: true });
      else if (n === 'NotFoundError' || n === 'OverconstrainedError' || n === 'DevicesNotFoundError')
        showMsg('No camera found', 'This device has no camera we can use. You can choose a photo and add a frame to it.', { snap: true });
      else if (n === 'NotReadableError' || n === 'TrackStartError')
        showMsg('The camera is busy', 'Another app or tab is using the camera. Close it and try again.', { retry: true, snap: true });
      else showMsg('The camera did not start', 'Try again, or choose a photo from this device.', { retry: true, snap: true });
    });
  }

  /* ---------- capture ---------- */
  function flash() {
    if (reduced) return;
    els.flash.classList.remove('go'); void els.flash.offsetWidth; els.flash.classList.add('go');
  }
  function grabFrame() {
    var v = els.video, w = v.videoWidth, h = v.videoHeight;
    if (!w || !h) return false;
    var sc = Math.min(1, 2200 / Math.max(w, h)), c = document.createElement('canvas');
    c.width = Math.round(w * sc); c.height = Math.round(h * sc);
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    S.snap = c; S.snapMirror = S.mirror; S.when = new Date();
    return true;
  }
  function shoot() {
    if (!grabFrame()) { say('The camera is not ready yet.'); return; }
    flash();
    stopStream();
    developFirst();
  }
  function onShutter() {
    if (root.getAttribute('data-state') !== 'live') return;
    if (S.counting) { cancelCount(); return; }
    if (!S.timer) { shoot(); return; }
    var n = S.timer; S.counting = 1; root.classList.add('is-counting');
    els.shutter.setAttribute('aria-label', 'Cancel the timer');
    (function tick() {
      if (!S.counting) return;
      els.count.textContent = n; els.count.classList.remove('pop'); void els.count.offsetWidth; els.count.classList.add('pop');
      if (n <= 0) { cancelCount(); shoot(); return; }
      S.counting = setTimeout(function () { n--; if (n <= 0) { els.count.textContent = ''; cancelCount(); shoot(); } else tick(); }, 1000);
    })();
  }
  function cancelCount() {
    if (S.counting && S.counting !== 1) clearTimeout(S.counting);
    S.counting = 0; els.count.textContent = ''; root.classList.remove('is-counting');
    els.shutter.setAttribute('aria-label', 'Take photo');
  }

  /* ---------- drawing the framed picture ---------- */
  function ctxFilterOk() { var c = document.createElement('canvas').getContext('2d'); return typeof c.filter === 'string'; }
  var CTXF = ctxFilterOk();

  function applyPixels(img, steps) {
    var d = img.data, i, n = d.length;
    for (var k = 0; k < steps.length; k++) {
      var f = steps[k][0], a = steps[k][1], m = null;
      if (f === 'brightness') { for (i = 0; i < n; i += 4) { d[i] *= a; d[i + 1] *= a; d[i + 2] *= a; } continue; }
      if (f === 'contrast') { var o = 127.5 - 127.5 * a; for (i = 0; i < n; i += 4) { d[i] = d[i] * a + o; d[i + 1] = d[i + 1] * a + o; d[i + 2] = d[i + 2] * a + o; } continue; }
      if (f === 'grayscale') { var g = 1 - Math.min(1, a); m = [.2126 + .7874 * g, .7152 - .7152 * g, .0722 - .0722 * g, .2126 - .2126 * g, .7152 + .2848 * g, .0722 - .0722 * g, .2126 - .2126 * g, .7152 - .7152 * g, .0722 + .9278 * g]; }
      else if (f === 'sepia') { var s = 1 - Math.min(1, a); m = [.393 + .607 * s, .769 - .769 * s, .189 - .189 * s, .349 - .349 * s, .686 + .314 * s, .168 - .168 * s, .272 - .272 * s, .534 - .534 * s, .131 + .869 * s]; }
      else if (f === 'saturate') { m = [.213 + .787 * a, .715 - .715 * a, .072 - .072 * a, .213 - .213 * a, .715 + .285 * a, .072 - .072 * a, .213 - .213 * a, .715 - .715 * a, .072 + .928 * a]; }
      else if (f === 'hue-rotate') { var r = a * Math.PI / 180, cs = Math.cos(r), sn = Math.sin(r);
        m = [.213 + cs * .787 - sn * .213, .715 - cs * .715 - sn * .715, .072 - cs * .072 + sn * .928, .213 - cs * .213 + sn * .143, .715 + cs * .285 + sn * .140, .072 - cs * .072 - sn * .283, .213 - cs * .213 - sn * .787, .715 - cs * .715 + sn * .715, .072 + cs * .928 + sn * .072]; }
      if (!m) continue;
      for (i = 0; i < n; i += 4) {
        var R = d[i], G = d[i + 1], B = d[i + 2];
        d[i] = m[0] * R + m[1] * G + m[2] * B; d[i + 1] = m[3] * R + m[4] * G + m[5] * B; d[i + 2] = m[6] * R + m[7] * G + m[8] * B;
      }
    }
  }
  var noiseCanvas = null;
  function noise() {
    if (noiseCanvas) return noiseCanvas;
    var c = document.createElement('canvas'); c.width = c.height = 256; var x = c.getContext('2d'), im = x.createImageData(256, 256);
    for (var i = 0; i < im.data.length; i += 4) { var v = 90 + Math.random() * 110; im.data[i] = im.data[i + 1] = im.data[i + 2] = v; im.data[i + 3] = 255; }
    x.putImageData(im, 0, 0); return (noiseCanvas = c);
  }
  function effects(ctx, x, y, w, h, kind) {
    if (!kind) return;
    ctx.save(); ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
    if (kind === 'film') {
      var p = ctx.createPattern(noise(), 'repeat');
      ctx.globalCompositeOperation = 'overlay'; ctx.globalAlpha = .34; ctx.fillStyle = p; ctx.fillRect(x, y, w, h);
      ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1;
      var vg = ctx.createRadialGradient(x + w / 2, y + h / 2, Math.min(w, h) * .38, x + w / 2, y + h / 2, Math.max(w, h) * .75);
      vg.addColorStop(0, 'rgba(0,0,0,0)'); vg.addColorStop(1, 'rgba(0,0,0,.4)');
      ctx.fillStyle = vg; ctx.fillRect(x, y, w, h);
    } else if (kind === 'golden') {
      var lg = ctx.createLinearGradient(x + w, y, x, y + h);
      lg.addColorStop(0, 'rgba(255,170,60,.34)'); lg.addColorStop(.6, 'rgba(255,120,60,.06)'); lg.addColorStop(1, 'rgba(255,120,60,0)');
      ctx.globalCompositeOperation = 'soft-light'; ctx.fillStyle = lg; ctx.fillRect(x, y, w, h);
      ctx.globalCompositeOperation = 'source-over';
    }
    ctx.restore();
  }
  function fitFont(ctx, text, maxW, px, minPx, weight, family) {
    var s = px; ctx.font = weight + ' ' + s + 'px ' + family;
    while (s > minPx && ctx.measureText(text).width > maxW) { s -= 1; ctx.font = weight + ' ' + s + 'px ' + family; }
    if (ctx.measureText(text).width > maxW) { while (text.length > 1 && ctx.measureText(text + '...').width > maxW) text = text.slice(0, -1); text += '...'; }
    return text;
  }
  var HAND = '"Caveat","Segoe Script","Bradley Hand",cursive', SANS = '"Instrument Sans","Segoe UI",system-ui,sans-serif';

  function rrect(ctx, x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function drawText(ctx, g, frame) {
    var t = textSpec(frame, true);
    var areaX = g.px, areaW = g.pw, areaY = g.py + g.ph, areaH = g.bottomP;
    if (frame === 'film') {
      var base = areaY + areaH * .34;
      if (t.l1) { var l1 = fitFont(ctx, t.l1, areaW * .62, areaH * .34, 12, 600, HAND); ctx.fillStyle = '#F2D8A0'; ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'left'; ctx.fillText(l1, areaX, base); }
      if (t.l2) { var l2 = fitFont(ctx, t.l2, areaW * .6, areaH * .16, 10, 600, SANS); ctx.fillStyle = 'rgba(242,216,160,.75)'; ctx.textAlign = 'left'; ctx.fillText(l2, areaX, base + areaH * .25); }
      if (t.rt) { var rt = fitFont(ctx, t.rt, areaW * .34, areaH * .15, 10, 600, SANS); ctx.fillStyle = '#F2A33C'; ctx.textAlign = 'right'; ctx.fillText(rt, areaX + areaW, base); }
      return;
    }
    if (frame === 'none') return;
    var dark = '#1B2A21', soft = '#566B5F';
    var y1 = areaY + areaH * (frame === 'wide' ? .52 : .45), y2 = areaY + areaH * (frame === 'wide' ? .86 : .8);
    ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    if (t.l1) { var a = fitFont(ctx, t.l1, areaW, areaH * (frame === 'wide' ? .42 : .34), 14, 600, HAND); ctx.fillStyle = dark; ctx.fillText(a, areaX, y1); }
    if (t.l2) { var b = fitFont(ctx, t.l2, areaW, areaH * (frame === 'wide' ? .26 : .21), 12, 600, HAND); ctx.fillStyle = soft; ctx.fillText(b, areaX, t.l1 ? y2 : y1); }
  }
  function drawFilmChrome(ctx, g) {
    var hw = g.W * .026, hh = g.topP * .42, pitch = hw * 2.1, y1 = g.topP * .22, y2 = g.H - g.bottomP * .5 + g.bottomP * .12;
    ctx.fillStyle = '#E9E1D0';
    for (var x = pitch * .5; x < g.W - hw; x += pitch) {
      rrect(ctx, x, y1, hw, hh, hw * .22); ctx.fill();
      rrect(ctx, x, g.H - g.topP * .22 - hh, hw, hh, hw * .22); ctx.fill();
    }
    ctx.font = '700 ' + Math.round(g.topP * .32) + 'px ' + SANS; ctx.textBaseline = 'middle';
  }
  function drawStamp(ctx, g) {
    var sw = g.pw * .17, sh = sw * 1.22, x = g.px + g.pw - sw - g.pw * .035, y = g.py + g.pw * .035, r = sw * .045;
    ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.28)'; ctx.shadowBlur = sw * .08; ctx.shadowOffsetY = sw * .02;
    ctx.fillStyle = '#FFFDF7'; ctx.fillRect(x, y, sw, sh);
    ctx.shadowColor = 'transparent';
    var step = sw / 9;
    for (var i = 0; i <= 9; i++) { ctx.beginPath(); ctx.arc(x + i * step, y, r, 0, 7); ctx.arc(x + i * step, y + sh, r, 0, 7); ctx.fill(); }
    for (var j = 0; j <= 11; j++) { ctx.beginPath(); ctx.arc(x, y + j * (sh / 11), r, 0, 7); ctx.arc(x + sw, y + j * (sh / 11), r, 0, 7); ctx.fill(); }
    ctx.restore();
    var ix = x + sw * .1, iy = y + sh * .09, iw = sw * .8, ih = sh * .82;
    ctx.fillStyle = '#123524'; ctx.fillRect(ix, iy, iw, ih);
    ctx.fillStyle = '#E63457'; ctx.beginPath(); ctx.arc(ix + iw / 2, iy + ih * .42, iw * .22, 0, 7); ctx.fill();
    ctx.strokeStyle = '#B6E36B'; ctx.lineWidth = Math.max(1, iw * .04); ctx.beginPath();
    for (var k = 0; k < 8; k++) { var an = k * Math.PI / 4, cx = ix + iw / 2, cy = iy + ih * .42; ctx.moveTo(cx + Math.cos(an) * iw * .3, cy + Math.sin(an) * iw * .3); ctx.lineTo(cx + Math.cos(an) * iw * .4, cy + Math.sin(an) * iw * .4); }
    ctx.stroke();
    ctx.fillStyle = '#FFF6EE'; ctx.font = '700 ' + Math.round(ih * .12) + 'px ' + SANS; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    ctx.fillText('POST', ix + iw / 2, iy + ih * .93);
    // postmark ring with the day
    var pr = sw * .5, pcx = x - pr * 1.15, pcy = y + sh * .55;
    ctx.save(); ctx.translate(pcx, pcy); ctx.rotate(-.2); ctx.strokeStyle = 'rgba(255,246,238,.88)'; ctx.lineWidth = Math.max(1.5, pr * .09);
    ctx.beginPath(); ctx.arc(0, 0, pr, 0, 7); ctx.stroke(); ctx.beginPath(); ctx.arc(0, 0, pr * .74, 0, 7); ctx.stroke();
    ctx.fillStyle = 'rgba(255,246,238,.9)'; ctx.font = '700 ' + Math.round(pr * .34) + 'px ' + SANS; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(String(S.when.getDate()), 0, -pr * .08); ctx.font = '700 ' + Math.round(pr * .24) + 'px ' + SANS;
    ctx.fillText(S.when.toLocaleDateString('en-GB', { month: 'short' }).toUpperCase(), 0, pr * .28);
    ctx.restore();
  }

  function render() {
    var f = FRAMES[S.frame], sw = S.snap.width, sh = S.snap.height;
    var ar = f.ar || Math.max(.6, Math.min(1.8, sw / sh));
    var cw, ch;
    if (sw / sh > ar) { ch = sh; cw = sh * ar; } else { cw = sw; ch = sw / ar; }
    var cx = (sw - cw) / 2, cy = (sh - ch) / 2;
    var pw = Math.min(Math.floor(cw), Math.floor(LONG / Math.max(1 + 2 * f.side, 1 / ar + f.top + f.bottom)));
    pw = Math.max(64, pw);
    var ph = Math.round(pw / ar), sideP = Math.round(f.side * pw), topP = Math.round(f.top * pw), bottomP = Math.round(f.bottom * pw);
    var W = pw + sideP * 2, H = ph + topP + bottomP;
    var c = document.createElement('canvas'); c.width = W; c.height = H; var ctx = c.getContext('2d');
    var g = { W: W, H: H, px: sideP, py: topP, pw: pw, ph: ph, topP: topP, bottomP: bottomP, sideP: sideP };
    var bg = { classic: '#FBFAF6', wide: '#FBFAF6', film: '#131313', postcard: '#F7EBD8', none: '#000' }[S.frame];
    ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
    if (S.frame === 'postcard') { ctx.strokeStyle = 'rgba(27,42,33,.16)'; ctx.lineWidth = Math.max(1, W * .002); ctx.strokeRect(W * .006, W * .006, W - W * .012, H - W * .012); }
    // photo
    var flt = cssFilter(S.filter);
    ctx.save(); ctx.beginPath(); ctx.rect(g.px, g.py, pw, ph); ctx.clip();
    if (CTXF && flt !== 'none') ctx.filter = flt;
    if (S.snapMirror) { ctx.translate(g.px + pw, g.py); ctx.scale(-1, 1); ctx.drawImage(S.snap, cx, cy, cw, ch, 0, 0, pw, ph); }
    else ctx.drawImage(S.snap, cx, cy, cw, ch, g.px, g.py, pw, ph);
    ctx.restore(); ctx.filter = 'none';
    if (!CTXF && flt !== 'none') { var im = ctx.getImageData(g.px, g.py, pw, ph); applyPixels(im, FILTERS[S.filter].steps); ctx.putImageData(im, g.px, g.py); }
    effects(ctx, g.px, g.py, pw, ph, (FILTERS[S.filter] || {}).fx);
    if (S.frame === 'classic' || S.frame === 'wide') { ctx.strokeStyle = 'rgba(0,0,0,.08)'; ctx.lineWidth = 2; ctx.strokeRect(g.px, g.py, pw, ph); }
    if (S.frame === 'film') drawFilmChrome(ctx, g);
    if (S.frame === 'postcard') drawStamp(ctx, g);
    drawText(ctx, g, S.frame);
    g.photoFrac = { x: g.px / W, y: g.py / H, w: pw / W, h: ph / H };
    return { canvas: c, g: g };
  }

  /* ---------- result: develop, edit live, save ---------- */
  var renderTimer = 0;
  function showResult(animate) {
    var r = render();
    r.canvas.toBlob(function (blob) {
      if (!blob) { say('Could not make the picture. Try again.'); return; }
      if (S.url) URL.revokeObjectURL(S.url);
      S.blob = blob; S.url = URL.createObjectURL(blob);
      els.outImg.src = S.url;
      var pf = r.g.photoFrac;
      els.dev.style.cssText = 'left:' + pf.x * 100 + '%;top:' + pf.y * 100 + '%;width:' + pf.w * 100 + '%;height:' + pf.h * 100 + '%';
      els.print.style.setProperty('aspect-ratio', r.g.W + ' / ' + r.g.H);
      els.stage.style.setProperty('--wr', (r.g.W / r.g.H).toFixed(4));
      els.stage.style.setProperty('--cw', 'clamp(260px, calc((100dvh - 400px) * ' + (r.g.W / r.g.H).toFixed(4) + '), 560px)');
      if (animate) {
        els.print.classList.remove('is-developing'); void els.print.offsetWidth;
        if (!reduced) els.print.classList.add('is-developing');
      }
    }, 'image/jpeg', QUALITY);
  }
  function developFirst() {
    setState('result');
    els.card.hidden = true; els.out.hidden = false;
    say('Developing your photo.');
    var doIt = function () { showResult(true); setTimeout(function () { say('Your photo is ready. Save it or retake.'); }, reduced ? 0 : 2600); };
    (document.fonts && document.fonts.load ? document.fonts.load('600 48px Caveat').catch(function () {}) : Promise.resolve()).then(doIt);
  }
  function reRender() {
    if (root.getAttribute('data-state') !== 'result') return;
    clearTimeout(renderTimer);
    renderTimer = setTimeout(function () { showResult(false); }, 120);
  }
  function retake() {
    S.snap = null; els.out.hidden = true; els.card.hidden = false;
    els.print.classList.remove('is-developing'); say('');
    applyFrameToCard();
    if (canStream()) start(); else showMsg('Choose a photo', 'This browser cannot open the camera, so pick a photo from this device.', { snap: true });
  }
  function save() {
    if (S.saving || !S.blob) return;
    S.saving = true; els.save.disabled = true; els.save.querySelector('span').textContent = 'Saving';
    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('image', S.blob, 'photo.jpg');
    fd.append('folder_id', els.folder.value || '0');
    fd.append('caption', els.caption.value.trim());
    fd.append('place', els.place.value.trim());
    fd.append('frame', S.frame);
    fd.append('filter_name', S.filter);
    fetch('camera-api.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent an unexpected reply.' }; }); })
      .then(function (d) {
        S.saving = false; els.save.disabled = false; els.save.querySelector('span').textContent = 'Save photo';
        if (!d.ok) { toast(d.error || 'Could not save the photo.'); say(d.error || 'Could not save the photo.'); return; }
        if (d.usage) { cfg.photos = d.usage.photos; cfg.bytes = d.usage.bytes; quota(); }
        var fname = els.folder.options[els.folder.selectedIndex] ? els.folder.options[els.folder.selectedIndex].text : 'camera roll';
        toast('Saved to ' + fname + '.', 'Open camera roll', 'camera-roll.php' + (els.folder.value ? '?folder=' + encodeURIComponent(els.folder.value) : ''));
        els.caption.value = ''; retake();
      }, function () {
        S.saving = false; els.save.disabled = false; els.save.querySelector('span').textContent = 'Save photo';
        toast('Could not reach the server. Your photo is still here, try Save again.');
      });
  }
  function quota() {
    if (els.quota) els.quota.textContent = cfg.photos + ' of ' + cfg.maxPhotos + ' photos used, ' + (cfg.bytes / 1048576).toFixed(1) + ' of ' + Math.round(cfg.maxBytes / 1048576) + ' MB.';
  }

  /* ---------- picking a file instead ---------- */
  function loadFile(file) {
    if (!file) return;
    if (!/^image\//.test(file.type) && !/\.(jpe?g|png|webp|gif|heic)$/i.test(file.name)) { toast('Choose an image file (JPG, PNG or WEBP).'); return; }
    if (file.size > 25 * 1024 * 1024) { toast('That photo is over 25 MB. Choose a smaller one.'); return; }
    var finish = function (src, w, h) {
      var sc = Math.min(1, 2200 / Math.max(w, h)), c = document.createElement('canvas');
      c.width = Math.round(w * sc); c.height = Math.round(h * sc);
      c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
      S.snap = c; S.snapMirror = false; S.when = new Date();
      stopStream(); els.msg.hidden = true; developFirst();
    };
    if (window.createImageBitmap) {
      createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) { finish(bm, bm.width, bm.height); }, function () { viaImg(); });
    } else viaImg();
    function viaImg() {
      var u = URL.createObjectURL(file), im = new Image();
      im.onload = function () { finish(im, im.naturalWidth, im.naturalHeight); URL.revokeObjectURL(u); };
      im.onerror = function () { URL.revokeObjectURL(u); toast('That image could not be opened. Try another.'); };
      im.src = u;
    }
  }

  /* ---------- wiring ---------- */
  function radio(group, attr, cb) {
    $$(group + ' [' + attr + ']').forEach(function (b) {
      b.addEventListener('click', function () {
        $$(group + ' [' + attr + ']').forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); x.tabIndex = x === b ? 0 : -1; });
        cb(b.getAttribute(attr));
      });
      b.tabIndex = b.getAttribute('aria-checked') === 'true' ? 0 : -1;
      b.addEventListener('keydown', function (e) {
        var k = e.key; if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].indexOf(k) < 0) return;
        var all = $$(group + ' [' + attr + ']'), i = all.indexOf(b), n = all[(i + (k === 'ArrowRight' || k === 'ArrowDown' ? 1 : all.length - 1)) % all.length];
        e.preventDefault(); n.focus(); n.click();
      });
    });
  }
  // filter swatches show their own look
  $$('.sw').forEach(function (s) { s.style.filter = cssFilter(s.getAttribute('data-sw')); });
  radio('.cm-filters', 'data-filter', function (v) { S.filter = v; applyFilterToLive(); reRender(); });
  radio('.cm-frames', 'data-frame', function (v) { S.frame = v; applyFrameToCard(); reRender(); });
  radio('.cm-timers', 'data-timer', function (v) { S.timer = parseInt(v, 10) || 0; });
  ['input', 'change'].forEach(function (ev) {
    els.caption.addEventListener(ev, function () { updateCardText(); reRender(); });
    els.place.addEventListener(ev, function () { updateCardText(); reRender(); });
  });
  els.shutter.addEventListener('click', onShutter);
  els.flip.addEventListener('click', function () { S.facing = S.facing === 'environment' ? 'user' : 'environment'; start(); });
  els.retry.addEventListener('click', start);
  $('#cmRetake').addEventListener('click', retake);
  els.save.addEventListener('click', save);
  $$('[data-cm-file]').forEach(function (inp) {
    inp.addEventListener('change', function () { var f = inp.files && inp.files[0]; inp.value = ''; loadFile(f); });
  });
  $$('label[tabindex]').forEach(function (l) {
    l.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); var i = l.querySelector('input'); if (i) i.click(); } });
  });
  els.video.addEventListener('loadedmetadata', function () { if (FRAMES[S.frame].ar === null) applyFrameToCard(); });

  // release the camera when the tab is hidden or the page is left; take it back when we return
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { if (S.stream) { cancelCount(); stopStream(); S.resume = true; } }
    else if (S.resume && root.getAttribute('data-state') !== 'result') { S.resume = false; start(); }
  });
  window.addEventListener('pagehide', stopStream);

  applyFilterToLive(); applyFrameToCard();
  if (document.fonts && document.fonts.load) document.fonts.load('600 40px Caveat').catch(function () {});
  start();
})();
