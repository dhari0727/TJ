/* JourneyAI Instagram Kit modal. Loaded on demand by js/social-share.js (JaSocial.openKit).
   Makes Instagram-ready JPEGs from the member's own public, approved item (images are drawn by instagram-kit.php), a caption and hashtags
   from the real text, and then: phone = Web Share with the image files; desktop = download (zip for carousels).
   Instagram takes the images only. The caption has to be pasted in the app, and the modal says so.
   Posting straight from the site is offered only when the server reports it is possible. */
(function () {
  'use strict';
  var S = window.JaSocial; if (!S || !S.ui) return;
  var ui = S.ui, h = ui.h, ic = ui.ic, toast = ui.toast;
  var RM = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var EP = (window.JA_SOC && window.JA_SOC.kit) || 'instagram-kit.php';

  /* ---------- a tiny zip writer (store only, no compression, no libraries) ---------- */
  var CRC = (function () { var t = [], c, n, k; for (n = 0; n < 256; n++) { c = n; for (k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
  function crc32(u8) { var c = 0xFFFFFFFF; for (var i = 0; i < u8.length; i++) c = CRC[(c ^ u8[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  function zip(files) { // files: [{name, data:Uint8Array}] -> Blob
    var parts = [], central = [], offset = 0, d = new Date();
    var time = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1), date = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
    files.forEach(function (f) {
      var name = new TextEncoder().encode(f.name), crc = crc32(f.data), sz = f.data.length;
      var lh = new DataView(new ArrayBuffer(30));
      lh.setUint32(0, 0x04034b50, true); lh.setUint16(4, 20, true); lh.setUint16(6, 0x0800, true); lh.setUint16(8, 0, true); lh.setUint16(10, time, true); lh.setUint16(12, date, true);
      lh.setUint32(14, crc, true); lh.setUint32(18, sz, true); lh.setUint32(22, sz, true); lh.setUint16(26, name.length, true); lh.setUint16(28, 0, true);
      parts.push(lh.buffer, name, f.data);
      var ch = new DataView(new ArrayBuffer(46));
      ch.setUint32(0, 0x02014b50, true); ch.setUint16(4, 20, true); ch.setUint16(6, 20, true); ch.setUint16(8, 0x0800, true); ch.setUint16(10, 0, true); ch.setUint16(12, time, true); ch.setUint16(14, date, true);
      ch.setUint32(16, crc, true); ch.setUint32(20, sz, true); ch.setUint32(24, sz, true); ch.setUint16(28, name.length, true); ch.setUint32(42, offset, true);
      central.push(ch.buffer, name);
      offset += 30 + name.length + sz;
    });
    var csize = central.reduce(function (a, b) { return a + (b.byteLength || b.length); }, 0);
    var end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true); end.setUint16(8, files.length, true); end.setUint16(10, files.length, true); end.setUint32(12, csize, true); end.setUint32(16, offset, true);
    return new Blob(parts.concat(central, [end.buffer]), { type: 'application/zip' });
  }

  function slug(s) { return (s || 'journeyai').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'journeyai'; }
  function canShareFiles() {
    try { return !!(navigator.canShare && navigator.canShare({ files: [new File([new Uint8Array(4)], 'a.jpg', { type: 'image/jpeg' })] })); } catch (e) { return false; }
  }
  function isPhone() { return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (window.matchMedia && matchMedia('(pointer:coarse)').matches && matchMedia('(max-width:900px)').matches); }
  function copy(text, msg) { ui.copyText(text, msg); }
  function saveBlob(blob, name) {
    var u = URL.createObjectURL(blob), a = h('a', { href: u, download: name, style: 'display:none' });
    document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); URL.revokeObjectURL(u); }, 1500);
  }

  function open(spec, it) {
    var s = ui.shell('Instagram Kit', it.images && it.images[0] ? it.images[0].url : null);
    s.panel.classList.add('igk-panel');
    s.body.appendChild(h('p', { class: 'soc-muted', text: 'Getting your images ready...' }));
    var q = new URLSearchParams({ action: 'meta', kind: spec.kind, id: spec.id }); if (spec.path) q.set('path', spec.path);
    fetch(EP + '?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Could not reach the server. Check your connection.' }; }).then(function (m) {
      if (!ui.alive()) return;
      s.body.innerHTML = '';
      if (!m.ok) { s.body.appendChild(h('div', { class: 'soc-guide', role: 'alert' }, [h('span', { class: 'soc-guide-i', html: ic('alert', 20) }), h('div', {}, [h('strong', { text: 'Cannot make Instagram images' }), h('p', { text: m.error || 'Something went wrong.' })])])); return; }
      build(s, spec, it, m);
    });
  }

  function build(s, spec, it, m) {
    var body = s.body, st = { fmt: 'feed', tpl: null, n: 0, safe: false, caption: m.caption, alts: {}, busy: 0, urls: {}, blobs: {} };
    var fmtOf = function (id) { return m.formats.filter(function (f) { return f.id === id; })[0]; };
    var cur = function () { return fmtOf(st.fmt); };
    st.tpl = cur().templates[0];

    function url(fmt, tpl, n, dl) {
      var q = new URLSearchParams({ action: 'slide', kind: spec.kind, id: spec.id, format: fmt, template: tpl, n: n }); if (spec.path) q.set('path', spec.path); if (dl) q.set('dl', '1');
      return EP + '?' + q;
    }
    function blobFor(fmt, tpl, n) {
      var k = fmt + '|' + tpl + '|' + n;
      if (!st.blobs[k]) st.blobs[k] = fetch(url(fmt, tpl, n), { credentials: 'same-origin' }).then(function (r) {
        var ct = r.headers.get('Content-Type') || '';
        if (ct.indexOf('image/') !== 0) return r.json().then(function (j) { throw new Error(j.error || 'Could not draw this image.'); }, function () { throw new Error('Could not draw this image.'); });
        return r.blob();
      }).catch(function (e) { delete st.blobs[k]; throw e; });
      return st.blobs[k];
    }
    function keys() { var f = cur(), a = []; if (f.id === 'carousel') for (var i = 0; i < f.count; i++) a.push([f.id, st.tpl, i]); else a.push([f.id, st.tpl, st.n]); return a; }
    function filename(i) { return slug(m.title) + '-' + cur().id + '-' + (i + 1) + '.jpg'; }
    function ready() { return Promise.all(keys().map(function (k) { return blobFor(k[0], k[1], k[2]); })); }

    /* ---- layout ---- */
    var grid = h('div', { class: 'igk-grid' }), left = h('div', { class: 'igk-left' }), right = h('div', { class: 'igk-right' });
    grid.appendChild(left); grid.appendChild(right); body.appendChild(grid);

    var tabs = h('div', { class: 'igk-tabs', role: 'tablist', 'aria-label': 'Instagram format' });
    m.formats.forEach(function (f) {
      var b = h('button', { type: 'button', class: 'igk-tab', role: 'tab', id: 'igkT-' + f.id, 'aria-selected': f.id === st.fmt ? 'true' : 'false', 'data-fmt': f.id, text: f.label + (f.id === 'carousel' ? ' (' + f.count + ')' : '') });
      b.addEventListener('click', function () { setFormat(f.id); });
      b.addEventListener('keydown', function (e) {
        var i = m.formats.indexOf(f), j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : -1;
        if (j < 0 || j >= m.formats.length) return; e.preventDefault(); setFormat(m.formats[j].id); document.getElementById('igkT-' + m.formats[j].id).focus();
      });
      tabs.appendChild(b);
    });
    left.appendChild(tabs);

    var tplBox = h('div', { class: 'igk-tpls', role: 'radiogroup', 'aria-label': 'Design' }); left.appendChild(tplBox);
    var stage = h('div', { class: 'igk-stage' }), frame = h('div', { class: 'igk-frame' }), img = h('img', { class: 'igk-img', alt: '' }), overlay = h('div', { class: 'igk-safe', 'aria-hidden': 'true' }), status = h('p', { class: 'igk-status', role: 'status', 'aria-live': 'polite' });
    frame.appendChild(img); frame.appendChild(overlay); frame.appendChild(status); stage.appendChild(frame); left.appendChild(stage);
    var nav = h('div', { class: 'igk-nav' }); left.appendChild(nav);
    var safeLbl = h('label', { class: 'igk-check' }, [h('input', { type: 'checkbox', id: 'igkSafe' }), ' Show the safe area (keep key text inside the dashed line)']);
    safeLbl.querySelector('input').addEventListener('change', function (e) { st.safe = e.target.checked; paintSafe(); });
    left.appendChild(safeLbl);

    // caption
    right.appendChild(h('label', { class: 'soc-label', for: 'igkCap', text: 'Caption' }));
    var ta = h('textarea', { class: 'soc-ta igk-ta', id: 'igkCap', rows: '9', 'aria-describedby': 'igkCount igkHook' }); ta.value = st.caption;
    var count = h('span', { class: 'soc-count', id: 'igkCount' }), hook = h('p', { class: 'soc-hint igk-hook', id: 'igkHook' });
    var reset = h('button', { type: 'button', class: 'soc-link', text: 'Reset to suggested', onclick: function () { ta.value = st.caption = m.caption; upd(); } });
    right.appendChild(ta); right.appendChild(h('div', { class: 'soc-ctools' }, [count, reset])); right.appendChild(hook);
    var tagLine = h('p', { class: 'soc-hint' });
    tagLine.textContent = 'Hashtags come from your place and tags already used in the community: ' + m.hashtags.map(function (t) { return '#' + t.tag; }).join(' ') + '. Edit them freely.';
    right.appendChild(tagLine);
    function upd() {
      var v = ta.value, n = Array.from(v).length;
      count.textContent = n + ' / ' + m.caption_max; count.className = 'soc-count' + (n > m.caption_max ? ' over' : n > m.caption_max * 0.9 ? ' near' : '');
      var first = Array.from(v).slice(0, m.hook_max).join('').split('\n')[0];
      hook.textContent = 'Instagram shows the first ' + m.hook_max + ' characters before "more": ' + (first || '(empty)') + (Array.from(v).length > m.hook_max ? '...' : '');
      st.caption = v; actions();
    }
    ta.addEventListener('input', upd);

    // alt text
    var altIn = h('input', { type: 'text', class: 'soc-alt', id: 'igkAlt', maxlength: '400', 'aria-label': 'Alt text for this image' });
    altIn.addEventListener('input', function () { st.alts[st.fmt + '|' + st.n] = altIn.value; });
    right.appendChild(h('label', { class: 'soc-label', for: 'igkAlt', text: 'Alt text for this image' }));
    right.appendChild(altIn);
    right.appendChild(h('div', { class: 'soc-row' }, [h('button', { type: 'button', class: 'soc-btn', html: ic('copy', 16) + '<span>Copy alt text</span>', onclick: function () { copy(altIn.value, 'Alt text copied. Paste it under Advanced settings, Write alt text in Instagram.'); } })]));

    // actions
    var act = h('div', { class: 'igk-actions' }); right.appendChild(act);
    var directBox = h('div', { class: 'igk-direct' }); right.appendChild(directBox);

    function altFor(fmt, n) { var k = fmt.id + '|' + n; return st.alts[k] != null ? st.alts[k] : (fmt.alts[n] || ''); }
    function paintSafe() {
      var f = cur(), sf = f.safe; overlay.style.display = st.safe ? 'block' : 'none';
      overlay.style.inset = (sf.t / f.h * 100) + '% ' + (sf.r / f.w * 100) + '% ' + (sf.b / f.h * 100) + '% ' + (sf.l / f.w * 100) + '%';
    }
    function showImage() {
      var f = cur(), k = [f.id, st.tpl, st.n];
      frame.style.aspectRatio = f.w + ' / ' + f.h; frame.setAttribute('data-fmt', f.id); paintSafe();
      status.textContent = RM ? 'Drawing...' : 'Drawing...'; status.hidden = false; img.style.opacity = '.25';
      var mine = k.join('|'); st.showing = mine;
      blobFor(k[0], k[1], k[2]).then(function (b) {
        if (st.showing !== mine) return;
        if (st.urls[mine] == null) st.urls[mine] = URL.createObjectURL(b);
        img.src = st.urls[mine]; img.alt = altFor(f, st.n) || 'Preview'; img.style.opacity = '1'; status.hidden = true;
      }, function (e) { if (st.showing !== mine) return; status.textContent = e.message; img.style.opacity = '.1'; });
      var an = altFor(f, st.n); altIn.value = an;
    }
    function paintTpl() {
      var f = cur(); tplBox.innerHTML = ''; tplBox.hidden = f.templates.length < 2;
      f.templates.forEach(function (t) {
        var b = h('button', { type: 'button', class: 'igk-chip', role: 'radio', 'aria-checked': t === st.tpl ? 'true' : 'false', text: m.template_labels[t] || t });
        b.addEventListener('click', function () { st.tpl = t; st.n = f.id === 'carousel' ? st.n : st.n; paintTpl(); paintNav(); showImage(); actions(); prefetch(); });
        tplBox.appendChild(b);
      });
    }
    function paintNav() {
      var f = cur(); nav.innerHTML = '';
      if (f.id === 'carousel') {
        var prev = h('button', { type: 'button', class: 'soc-btn igk-step', 'aria-label': 'Previous slide', html: '<span aria-hidden="true">&#8249;</span>', disabled: st.n === 0 });
        var next = h('button', { type: 'button', class: 'soc-btn igk-step', 'aria-label': 'Next slide', html: '<span aria-hidden="true">&#8250;</span>', disabled: st.n >= f.count - 1 });
        prev.addEventListener('click', function () { go(st.n - 1); }); next.addEventListener('click', function () { go(st.n + 1); });
        nav.appendChild(prev); nav.appendChild(h('span', { class: 'igk-pos', 'aria-live': 'polite', text: 'Slide ' + (st.n + 1) + ' of ' + f.count })); nav.appendChild(next);
      } else if (f.count > 1) {
        nav.appendChild(h('span', { class: 'igk-pos', text: 'Photo' }));
        var strip = h('div', { class: 'igk-thumbs', role: 'radiogroup', 'aria-label': 'Choose the photo' });
        it.images.slice(0, f.count).forEach(function (im, i) {
          var b = h('button', { type: 'button', class: 'igk-thumb', role: 'radio', 'aria-checked': i === st.n ? 'true' : 'false', 'aria-label': 'Photo ' + (i + 1) }, [h('img', { src: im.url, alt: '' })]);
          b.addEventListener('click', function () { go(i); }); strip.appendChild(b);
        });
        nav.appendChild(strip);
      }
    }
    function go(n) { st.n = Math.max(0, Math.min(cur().count - 1, n)); paintNav(); showImage(); actions(); }
    function setFormat(id) {
      st.fmt = id; st.n = 0; var f = cur(); if (f.templates.indexOf(st.tpl) < 0) st.tpl = f.templates[0];
      [].forEach.call(tabs.children, function (b) { b.setAttribute('aria-selected', b.getAttribute('data-fmt') === id ? 'true' : 'false'); });
      paintTpl(); paintNav(); showImage(); actions(); prefetch(); directPaint();
    }
    function prefetch() { ready().then(function () { st.ready = true; actions(); }, function () {}); st.ready = false; }

    /* ---- the two ways out ---- */
    function actions() {
      var f = cur(), many = f.id === 'carousel' && f.count > 1, phone = isPhone(), share = canShareFiles();
      act.innerHTML = '';
      var tooLong = Array.from(ta.value).length > m.caption_max;
      var copyBtn = h('button', { type: 'button', class: 'soc-btn ' + (phone && share ? 'soc-accent' : ''), html: ic('copy', 16) + '<span>Copy caption</span>', onclick: function () { copy(ta.value, 'Caption copied. Paste it in Instagram.'); } });
      var dlBtn = h('button', { type: 'button', class: 'soc-btn ' + (!(phone && share) ? 'soc-primary' : ''), disabled: !st.ready, html: ic('download', 16) + '<span>' + (many ? 'Download all (zip)' : 'Download image') + '</span>' });
      dlBtn.addEventListener('click', function () {
        ready().then(function (bl) {
          if (bl.length === 1) { saveBlob(bl[0], filename(st.n)); toast('Image downloaded.'); return null; }
          return Promise.all(bl.map(function (b, i) { return b.arrayBuffer().then(function (ab) { return { name: slug(m.title) + '-' + String(i + 1).padStart(2, '0') + '.jpg', data: new Uint8Array(ab) }; }); }))
            .then(function (files) { saveBlob(zip(files), slug(m.title) + '-instagram-carousel.zip'); toast('Zip downloaded. Unzip it and pick the images in order.'); });
        }, function (e) { toast(e.message); });
      });
      var shareBtn = share ? h('button', { type: 'button', class: 'soc-btn ' + (phone ? 'soc-primary' : ''), disabled: !st.ready, html: ic('share', 16) + '<span>' + (phone ? 'Share images to Instagram' : 'Share from this device') + '</span>' }) : null;
      if (shareBtn) shareBtn.addEventListener('click', function () {
        ready().then(function (bl) {
          var files = bl.map(function (b, i) { return new File([b], filename(f.id === 'carousel' ? i : st.n).replace(/\.jpg$/, '') + '.jpg', { type: 'image/jpeg' }); });
          if (!navigator.canShare({ files: files })) { toast('This device cannot share this many images at once. Use Download instead.'); return; }
          if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(function () { toast('Caption copied. Choose Instagram, then paste it.'); }, function () {});
          return navigator.share({ files: files, title: m.title, text: ta.value });
        }).catch(function (e) { if (e && e.name === 'AbortError') return; toast('Sharing was not possible. Use Download instead.'); });
      });
      var order = phone && share ? [copyBtn, shareBtn, dlBtn] : [dlBtn, copyBtn, shareBtn];
      if (phone && share) act.appendChild(h('p', { class: 'igk-step-h', text: '1. Copy the caption  2. Share the images and pick Instagram  3. Paste the caption' }));
      var row = h('div', { class: 'soc-row' }); order.forEach(function (b) { if (b) row.appendChild(b); }); act.appendChild(row);
      var notes = [];
      if (phone && share) notes.push('Instagram takes the images only, not the caption. That is why Copy caption comes first: when Instagram asks for a caption, paste it.');
      else notes.push('Open Instagram on your phone, tap +, and pick ' + (many ? 'these images in order' : 'this image') + '. Instagram does not accept a caption from another app, so copy it here and paste it there.');
      if (f.id === 'story') notes.push('Stories have no caption. Add stickers, and a link sticker if you want one, inside the Instagram app. The layout keeps the title clear of the top and bottom bars.');
      if (many) notes.push('Instagram carousels hold up to 10 images. Pick them in order.');
      if (tooLong) notes.push('The caption is over Instagram\'s 2,200 character limit. Shorten it.');
      notes.forEach(function (t) { act.appendChild(h('p', { class: 'soc-hint', text: t })); });
    }

    /* ---- direct posting (only when the server says it can work) ---- */
    function directPaint() {
      var d = m.direct, f = cur(); directBox.innerHTML = '';
      var title = h('p', { class: 'soc-label', text: 'Post from the site' });
      directBox.appendChild(title);
      directBox.appendChild(h('p', { class: 'soc-hint', text: 'Posting directly from the site needs a public https site and an Instagram business account (set up by the site owner).' }));
      if (d.state !== 'ready') {
        directBox.appendChild(h('p', { class: 'soc-warn soc-hint', text: d.message }));
        if (d.state === 'not_connected' || d.state === 'needs_reconnect') directBox.appendChild(h('a', { class: 'soc-btn', href: 'profile.php#connected-accounts', text: 'Open my profile' }));
        return;
      }
      if (f.id === 'story') { directBox.appendChild(h('p', { class: 'soc-hint', text: 'Stories are shared from your phone. Posting from the site is for Feed, Square and Carousel.' })); return; }
      var go = h('button', { type: 'button', class: 'soc-btn soc-accent', html: ic('send', 16) + '<span>Post to ' + d.handle + '</span>' });
      var confirmBox = h('div', { class: 'igk-confirm', hidden: true }), res = h('div', { class: 'soc-results', 'aria-live': 'polite' });
      go.addEventListener('click', function () {
        var n = f.id === 'carousel' ? f.count : 1;
        confirmBox.hidden = false; confirmBox.innerHTML = '';
        confirmBox.appendChild(h('p', { text: 'This publishes ' + n + ' image' + (n === 1 ? '' : 's') + ' with the caption above, exactly as it is now, to ' + d.handle + '. Nothing is scheduled.' }));
        var yes = h('button', { type: 'button', class: 'soc-btn soc-primary', text: 'Post now' }), no = h('button', { type: 'button', class: 'soc-btn', text: 'Cancel', onclick: function () { confirmBox.hidden = true; } });
        yes.addEventListener('click', function () {
          yes.disabled = true; yes.textContent = 'Posting...'; res.innerHTML = '';
          var alts = []; for (var i = 0; i < n; i++) alts.push(altFor(f, f.id === 'carousel' ? i : st.n));
          var job = { account_id: d.account_id, text: ta.value, images: [], kit: { format: f.id, template: st.tpl, n: st.n }, kit_alts: alts, visibility: 'public' };
          ui.apiPost('post', { kind: spec.kind, id: spec.id, path: spec.path || '', confirmed: '1', req: Math.random().toString(36).slice(2) + Date.now().toString(36), payload: JSON.stringify({ jobs: [job], alts: {} }) }).then(function (r) {
            yes.disabled = false; yes.textContent = 'Post now';
            var x = r.ok && r.results ? r.results[0] : null, ok = x && x.ok;
            var row = h('div', { class: 'soc-res ' + (ok ? 'ok' : 'bad') }, [h('span', { html: ic(ok ? 'check' : 'alert', 18) })]);
            var tx = h('span', { text: ok ? 'Posted to ' + (x.handle || 'Instagram') + '. ' : (x ? x.error : (r.error || 'Could not post.')) });
            if (ok && x.remote_url) tx.appendChild(h('a', { href: x.remote_url, target: '_blank', rel: 'noopener noreferrer', text: 'View the post' }));
            row.appendChild(tx); res.appendChild(row); if (ok) { confirmBox.hidden = true; toast('Posted to Instagram.'); }
          });
        });
        confirmBox.appendChild(h('div', { class: 'soc-row' }, [yes, no]));
      });
      directBox.appendChild(go); directBox.appendChild(confirmBox); directBox.appendChild(res);
    }

    paintTpl(); paintNav(); showImage(); upd(); prefetch(); directPaint();
    // free the preview images when the modal closes
    var obs = new MutationObserver(function () { if (!document.body.contains(s.panel)) { Object.keys(st.urls).forEach(function (k) { URL.revokeObjectURL(st.urls[k]); }); obs.disconnect(); } });
    obs.observe(document.body, { childList: true });
  }

  S.openKit = open;
})();
