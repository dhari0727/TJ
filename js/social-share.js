/* JourneyAI share sheet and post composer.
   Any element with data-soc-open data-kind data-id [data-path] opens the sheet. window.JaSocial.open({kind,id,path}) does the same.
   Sheet: Web Share (with the photo where the phone supports it), share links, copy link/caption, download image.
   Composer (owner, public + approved items only): pick connected accounts, edit the text per platform with a live counter, add alt text,
   see a preview, and press "Post now". Nothing is sent until that press; the server re-checks everything. */
(function () {
  'use strict';
  var CFG = window.JA_SOC || {}, API = CFG.api || 'social-api.php';
  var ICONS = window.JA_SOC_ICONS || {};
  var RM = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var layer = null, prevFocus = null, keyFn = null;

  function ic(name, s) {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + (s || 18) + '" height="' + (s || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
  }
  function h(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (k === 'class') e.className = v; else if (k === 'html') e.innerHTML = v; else if (k === 'text') e.textContent = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v); else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function toast(m) { if (window.cjToast) window.cjToast(m); }
  function token() {
    if (CFG.csrf || window.JA_CSRF) return Promise.resolve(CFG.csrf || window.JA_CSRF);
    return fetch('csrf-token.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { CFG.csrf = d.token; return d.token; });
  }
  function apiGet(q) {
    return fetch(API + '?' + new URLSearchParams(q), { credentials: 'same-origin' }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected reply.' }; }); });
  }
  function apiPost(action, data) {
    return token().then(function (t) {
      var b = new URLSearchParams(data || {}); b.set('action', action);
      return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': t, 'Accept': 'application/json' }, body: b });
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; }); },
            function () { return { ok: false, error: 'Could not reach the server. Check your connection.' }; });
  }

  /* ---------- text length, mirrors the server ---------- */
  function graphemes(t) {
    if (window.Intl && Intl.Segmenter) { var n = 0; var it = new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(t)[Symbol.iterator](); while (!it.next().done) n++; return n; }
    return Array.from(t).length;
  }
  function count(text, mode) {
    if (mode === 'url23') text = text.replace(/https?:\/\/\S+/g, new Array(24).join('x'));
    return mode === 'chars' ? Array.from(text).length : graphemes(text);
  }
  /** Shorten the sentence part so the whole text fits, keeping the link and hashtags. */
  function fit(text, lim) {
    if (count(text, lim.counter) <= lim.chars) return text;
    var m = text.match(/^([\s\S]*?)((?:\n\n)?https?:\/\/\S+)?((?:\n\n)?(?:#\S+\s*)+)?$/);
    var body = m ? m[1] : text, link = (m && m[2]) || '', tags = (m && m[3]) || '';
    var rest = function () { return link + tags; };
    for (var tries = 0; tries < 2; tries++) {
      var b = body;
      while (b.length > 0 && count(b + '...' + rest(), lim.counter) > lim.chars) b = b.slice(0, Math.max(0, b.length - Math.max(1, Math.ceil((count(b + '...' + rest(), lim.counter) - lim.chars) / 2))));
      if (count(b.replace(/\s+$/, '') + '...' + rest(), lim.counter) <= lim.chars) return b.replace(/\s+$/, '') + (b.length < body.length ? '...' : '') + rest();
      tags = ''; // still too long: drop the hashtags first
    }
    return text;
  }

  /* ---------- overlay shell ---------- */
  function close() {
    if (!layer) return;
    document.removeEventListener('keydown', keyFn); layer.remove(); layer = null; document.body.style.overflow = '';
    if (prevFocus && prevFocus.focus) try { prevFocus.focus(); } catch (e) {}
  }
  function shell(title, thumb) {
    close();
    prevFocus = document.activeElement;
    var closeBtn = h('button', { type: 'button', class: 'soc-x', 'aria-label': 'Close', html: ic('close', 20), onclick: close });
    var head = h('div', { class: 'soc-head' }, [thumb ? h('img', { class: 'soc-thumb', src: thumb, alt: '' }) : null, h('h2', { class: 'soc-title', id: 'socTitle', text: title }), closeBtn]);
    var body = h('div', { class: 'soc-body' });
    var panel = h('div', { class: 'soc-panel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'socTitle', tabindex: '-1' }, [head, body]);
    layer = h('div', { class: 'soc-layer' + (RM ? ' soc-rm' : ''), onmousedown: function (e) { if (e.target === layer) close(); } }, [panel]);
    document.body.appendChild(layer); document.body.style.overflow = 'hidden';
    keyFn = function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = [].slice.call(panel.querySelectorAll('button,a[href],input,textarea,select')).filter(function (x) { return !x.disabled && x.offsetParent !== null; });
      if (!f.length) return; var a = f[0], z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    };
    document.addEventListener('keydown', keyFn);
    setTimeout(function () { closeBtn.focus(); }, 30);
    return { panel: panel, body: body, title: head.querySelector('.soc-title'), closeBtn: closeBtn };
  }

  function copyText(text, msg) {
    var done = function () { toast(msg); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text, done); }); }
    else legacyCopy(text, done);
  }
  function legacyCopy(text, cb) {
    var t = h('textarea', { 'aria-hidden': 'true', style: 'position:fixed;opacity:0;top:0' }); t.value = text; document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); cb(); } catch (e) { toast('Could not copy. Select the text and copy it by hand.'); }
    t.remove();
  }
  function fileName(item) { var u = (item.images[0] && item.images[0].rel) || 'photo.jpg'; return u.split('/').pop(); }

  /* ---------- the sheet ---------- */
  function openSheet(spec) {
    var q = { action: 'describe', kind: spec.kind, id: spec.id }; if (spec.path) q.path = spec.path;
    var s = shell('Share', null);
    s.body.appendChild(h('p', { class: 'soc-muted', text: 'Loading...' }));
    apiGet(q).then(function (d) {
      if (!layer) return;
      if (!d.ok) { s.title.textContent = 'Cannot share this'; s.body.innerHTML = ''; s.body.appendChild(guide(d.error || 'This cannot be shared.', null, spec)); return; }
      render(s, d, spec);
    });
  }
  function guide(msg, item, spec) {
    var box = h('div', { class: 'soc-guide', role: 'status' }, [h('span', { class: 'soc-guide-i', html: ic('alert', 20) }), h('div', {}, [h('strong', { text: 'Make it public first' }), h('p', { text: msg })])]);
    if (item && item.needs_link && item.kind === 'plan') {
      var b = h('button', { type: 'button', class: 'soc-btn soc-primary', text: 'Create share link' });
      b.addEventListener('click', function () {
        if (!confirm('Anyone with the link will be able to view this plan without logging in. Create the link?')) return;
        b.disabled = true;
        apiPost2('plan-share.php', { plan_id: item.id }).then(function (r) { if (r && r.ok) openSheet(spec); else { b.disabled = false; toast((r && r.error) || 'Could not create the link.'); } });
      });
      box.lastChild.appendChild(b);
    }
    return box;
  }
  function apiPost2(url, data) {
    return token().then(function (t) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': t }, body: new URLSearchParams(data) }); }).then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  function render(s, d, spec) {
    var it = d.item, body = s.body; body.innerHTML = '';
    s.title.textContent = it.title || 'Share';
    if (it.images && it.images[0]) { var th = s.panel.querySelector('.soc-thumb'); if (!th) { th = h('img', { class: 'soc-thumb', alt: '' }); s.panel.querySelector('.soc-head').insertBefore(th, s.title); } th.src = it.images[0].url; }
    if (!it.shareable) { body.appendChild(guide(it.reason || 'This is not public yet.', it, spec)); return; }

    if (navigator.share) {
      body.appendChild(h('button', { type: 'button', class: 'soc-btn soc-primary soc-wide', html: ic('share', 18) + '<span>Share from this device</span>', onclick: function () { nativeShare(it); } }));
    }
    body.appendChild(h('p', { class: 'soc-label', text: 'Send to' }));
    var grid = h('div', { class: 'soc-grid' });
    (d.links || []).forEach(function (l) {
      grid.appendChild(h('a', { class: 'soc-tile', href: l.url, target: l.id === 'email' ? '_self' : '_blank', rel: 'noopener noreferrer', html: ic(l.id, 22) + '<span>' + l.label + '</span>' }));
    });
    body.appendChild(grid);
    var row = h('div', { class: 'soc-row' });
    row.appendChild(h('button', { type: 'button', class: 'soc-btn', html: ic('link', 16) + '<span>Copy link</span>', onclick: function () { copyText(it.url, 'Link copied.'); } }));
    row.appendChild(h('button', { type: 'button', class: 'soc-btn', html: ic('copy', 16) + '<span>Copy caption</span>', onclick: function () { copyText(it.caption, 'Caption copied with hashtags.'); } }));
    if (it.images && it.images.length) row.appendChild(h('a', { class: 'soc-btn', href: it.images[0].url, download: fileName(it), html: ic('download', 16) + '<span>Download image</span>' }));
    body.appendChild(row);
    if (d.logged_in && it.owner) {
      var ig = h('button', { type: 'button', class: 'soc-btn soc-ig soc-wide', html: ic('instagram', 18) + '<span>Make Instagram images and caption</span>', onclick: function () { openKit(spec, it); } });
      body.appendChild(ig);
      body.appendChild(h('p', { class: 'soc-hint', text: 'Instagram does not take links from other sites. The Instagram Kit makes feed, square, story and carousel images plus a caption you can post from your phone or download.' }));
    } else if (it.images && it.images.length) body.appendChild(h('p', { class: 'soc-hint', text: 'Instagram does not take links from other sites. Download the image, copy the caption, and post from the Instagram app.' }));
    if (!it.public_site) body.appendChild(h('p', { class: 'soc-hint soc-warn', text: 'This site is running on your own computer, so these links only open here. They work for everyone once the site is online.' }));

    if (d.logged_in && it.owner) {
      var sec = h('div', { class: 'soc-post' });
      sec.appendChild(h('p', { class: 'soc-label', text: 'Post from here' }));
      sec.appendChild(h('p', { class: 'soc-muted', text: 'Post straight to Bluesky, Mastodon and other accounts you have connected. You will see the exact text first.' }));
      sec.appendChild(h('button', { type: 'button', class: 'soc-btn soc-accent', html: ic('send', 16) + '<span>Post to my accounts</span>', onclick: function () { openComposer(d, spec); } }));
      body.appendChild(sec);
    }
  }

  /* ---------- Instagram Kit (loaded on first use) ---------- */
  var kitLoading = null;
  function openKit(spec, it) {
    if (window.JaSocial.openKit) { window.JaSocial.openKit(spec, it); return; }
    if (!kitLoading) kitLoading = new Promise(function (ok, bad) {
      var sc = document.createElement('script'); sc.src = CFG.kitJs || 'js/instagram-kit.js'; sc.onload = ok; sc.onerror = bad; document.head.appendChild(sc);
    });
    kitLoading.then(function () { if (window.JaSocial.openKit) window.JaSocial.openKit(spec, it); }, function () { kitLoading = null; toast('Could not load the Instagram Kit. Check your connection and try again.'); });
  }

  function nativeShare(it) {
    var data = { title: it.title, text: it.caption };
    var done = function () {};
    var fail = function (e) { if (e && e.name === 'AbortError') return; toast('Sharing was not possible. Use one of the links instead.'); };
    if (it.images && it.images[0] && navigator.canShare) {
      fetch(it.images[0].url).then(function (r) { return r.blob(); }).then(function (b) {
        var f = new File([b], fileName(it), { type: b.type || 'image/jpeg' });
        if (navigator.canShare({ files: [f] })) return navigator.share({ files: [f], title: it.title, text: it.caption }).then(done, fail);
        data.url = it.url; return navigator.share(data).then(done, fail);
      }).catch(function () { data.url = it.url; navigator.share(data).then(done, fail); });
    } else { data.url = it.url; if (!it.link_is_image) data.text = it.text || it.title; navigator.share(data).then(done, fail); }
  }

  /* ---------- the composer ---------- */
  function openComposer(d, spec) {
    var it = d.item;
    var s = shell('Post to your accounts', it.images && it.images[0] ? it.images[0].url : null);
    s.body.appendChild(h('p', { class: 'soc-muted', text: 'Loading your accounts...' }));
    apiPost('targets', {}).then(function (t) {
      if (!layer) return;
      s.body.innerHTML = '';
      if (!t.ok) { s.body.appendChild(h('p', { class: 'soc-err', text: t.error || 'Could not load your accounts.' })); return; }
      if (!t.accounts.length) {
        s.body.appendChild(h('p', { text: 'You have not connected an account yet.' }));
        s.body.appendChild(h('p', { class: 'soc-muted', text: 'Bluesky and Mastodon connect in a minute and need no approval.' }));
        s.body.appendChild(h('a', { class: 'soc-btn soc-primary', href: 'profile.php#connected-accounts', text: 'Connect an account' }));
        return;
      }
      composer(s, d, t.accounts, spec);
    });
  }

  function composer(s, d, accounts, spec) {
    var it = d.item, body = s.body;
    var sel = {}, texts = {}, vis = {}, imgOn = {}, alts = {}, busy = false, finished = false;
    it.images.forEach(function (im, i) { imgOn[i] = i < 4; alts[i] = im.alt || ''; });

    body.appendChild(h('button', { type: 'button', class: 'soc-btn soc-ig', html: ic('instagram', 16) + '<span>Make Instagram images and caption</span>', onclick: function () { openKit(spec, it); } }));
    var accBox = h('fieldset', { class: 'soc-acc' }, [h('legend', { text: 'Post to' })]);
    accounts.forEach(function (a) {
      var ready = a.ready, why = a.status !== 'active' ? 'Needs reconnecting in your profile' : (!a.ready ? 'Not set up by the site owner' : '');
      var cb = h('input', { type: 'checkbox', id: 'socA' + a.id, disabled: !ready });
      cb.addEventListener('change', function () { sel[a.id] = cb.checked; if (cb.checked && texts[a.id] == null) texts[a.id] = fit(it.caption, a.limits); refresh(true); });
      accBox.appendChild(h('label', { class: 'soc-account' + (ready ? '' : ' off'), for: 'socA' + a.id }, [cb, h('span', { class: 'soc-aico', html: ic(a.provider, 20) }),
        h('span', { class: 'soc-atxt' }, [h('strong', { text: a.handle || a.name }), h('small', { text: a.label + (why ? ', ' + why : '') })])]));
    });
    body.appendChild(accBox);

    var photoBox = h('div', { class: 'soc-photos' });
    if (it.images.length) {
      photoBox.appendChild(h('p', { class: 'soc-label', text: 'Photos' }));
      var pl = h('div', { class: 'soc-plist' });
      it.images.forEach(function (im, i) {
        var chk = h('input', { type: 'checkbox', id: 'socP' + i, checked: i < 4 });
        chk.addEventListener('change', function () { imgOn[i] = chk.checked; refresh(); });
        var alt = h('input', { type: 'text', class: 'soc-alt', maxlength: '400', value: alts[i], placeholder: 'Describe the photo for people who cannot see it', 'aria-label': 'Photo description ' + (i + 1) });
        alt.addEventListener('input', function () { alts[i] = alt.value; refresh(); });
        pl.appendChild(h('div', { class: 'soc-pitem' }, [h('label', { class: 'soc-pcheck', for: 'socP' + i }, [chk, h('img', { src: im.url, alt: '' })]), alt]));
      });
      photoBox.appendChild(pl);
    }
    body.appendChild(photoBox);

    var cards = h('div', { class: 'soc-cards' }); body.appendChild(cards);
    var preview = h('div', { class: 'soc-preview', 'aria-label': 'Preview' }); body.appendChild(preview);
    var summary = h('p', { class: 'soc-summary', role: 'status', 'aria-live': 'polite' }); body.appendChild(summary);
    var resBox = h('div', { class: 'soc-results', 'aria-live': 'polite' }); body.appendChild(resBox);
    var go = h('button', { type: 'button', class: 'soc-btn soc-primary soc-wide', html: ic('send', 16) + '<span>Post now</span>' });
    body.appendChild(go);

    function chosenImages() { var o = []; it.images.forEach(function (im, i) { if (imgOn[i]) o.push(i); }); return o; }
    function problems() {
      var out = [], imgs = chosenImages();
      accounts.forEach(function (a) {
        if (!sel[a.id]) return; var L = a.limits, n = count(texts[a.id] || '', L.counter);
        if (!(texts[a.id] || '').trim() && !imgs.length) out.push('Write something or choose a photo for ' + a.label + '.');
        if (n > L.chars) out.push(a.label + ' is ' + (n - L.chars) + ' characters over its limit of ' + L.chars + '.');
        if (imgs.length > L.images) out.push(a.label + ' takes up to ' + L.images + ' photo' + (L.images === 1 ? '' : 's') + '.');
        if (L.image_required && !imgs.length) out.push(a.label + ' posts need a photo.');
        if (L.needs_public_url && imgs.length && !it.public_site) out.push(a.label + ' downloads photos from your public website, which this site is not yet. Use Download image instead.');
      });
      return out;
    }
    function altNudge() {
      var need = accounts.some(function (a) { return sel[a.id] && a.limits.alt; });
      return need && chosenImages().some(function (i) { return !(alts[i] || '').trim(); });
    }
    function refresh(rebuild) {
      var picked = accounts.filter(function (a) { return sel[a.id]; });
      if (rebuild !== false) {
        cards.innerHTML = '';
        picked.forEach(function (a) {
          var ta = h('textarea', { class: 'soc-ta', rows: '5', id: 'socT' + a.id, 'aria-label': 'Text for ' + a.label }); ta.value = texts[a.id] || '';
          var ctr = h('span', { class: 'soc-count', id: 'socC' + a.id });
          var tools = h('div', { class: 'soc-ctools' }, [ctr, h('button', { type: 'button', class: 'soc-link', text: 'Shorten to fit', onclick: function () { texts[a.id] = fit(texts[a.id] || '', a.limits); ta.value = texts[a.id]; refresh(false); } })]);
          ta.addEventListener('input', function () { texts[a.id] = ta.value; refresh(false); });
          var kids = [h('div', { class: 'soc-chead' }, [h('span', { html: ic(a.provider, 16) }), h('strong', { text: a.label + ' ' + (a.handle || '') })]), ta, tools];
          if (a.limits.visibility) {
            var v = h('select', { class: 'soc-sel', id: 'socV' + a.id, 'aria-label': 'Who can see it on ' + a.label }, [h('option', { value: 'public', text: 'Public' }), h('option', { value: 'unlisted', text: 'Unlisted (not in public timelines)' })]);
            v.value = vis[a.id] || 'public'; v.addEventListener('change', function () { vis[a.id] = v.value; });
            kids.push(h('label', { class: 'soc-vis' }, ['Visible to ', v]));
          }
          cards.appendChild(h('section', { class: 'soc-card' }, kids));
        });
      }
      picked.forEach(function (a) {
        var c = document.getElementById('socC' + a.id); if (!c) return;
        var n = count(texts[a.id] || '', a.limits.counter); c.textContent = n + ' / ' + a.limits.chars; c.className = 'soc-count' + (n > a.limits.chars ? ' over' : n > a.limits.chars * 0.9 ? ' near' : '');
      });
      // preview of the first chosen account
      preview.innerHTML = '';
      if (picked.length) {
        var a0 = picked[0], imgs = chosenImages(), t = texts[a0.id] || '';
        var pv = h('div', { class: 'soc-pv' }, [h('div', { class: 'soc-pvh' }, [h('span', { class: 'soc-pva', html: ic(a0.provider, 16) }), h('span', { text: a0.handle || a0.label })]), h('p', { class: 'soc-pvt', text: t })]);
        if (imgs.length) { var g = h('div', { class: 'soc-pvi n' + Math.min(4, imgs.length) }); imgs.slice(0, 4).forEach(function (i) { g.appendChild(h('img', { src: it.images[i].url, alt: alts[i] || '' })); }); pv.appendChild(g); }
        preview.appendChild(h('p', { class: 'soc-label', text: 'Preview (' + a0.label + ')' })); preview.appendChild(pv);
      }
      var pr = problems(), nudge = altNudge();
      if (!picked.length) summary.textContent = 'Choose at least one account.';
      else if (pr.length) summary.textContent = pr[0];
      else summary.textContent = 'This posts the text' + (chosenImages().length ? ' and ' + chosenImages().length + ' photo' + (chosenImages().length === 1 ? '' : 's') : '') + ' exactly as shown to ' + picked.map(function (a) { return (a.handle || a.label) + ' (' + a.label + ')'; }).join(', ') + '. Nothing else is sent, and nothing is scheduled.' + (nudge ? ' Tip: add a photo description so everyone can follow along.' : '');
      summary.className = 'soc-summary' + (pr.length ? ' bad' : '');
      go.disabled = finished ? false : (busy || !picked.length || pr.length > 0);
    }

    go.addEventListener('click', function () {
      if (finished) { close(); return; }
      if (busy) return; var picked = accounts.filter(function (a) { return sel[a.id]; }); if (!picked.length || problems().length) return;
      busy = true; go.disabled = true; go.querySelector('span').textContent = 'Posting...'; resBox.innerHTML = '';
      var imgs = chosenImages(), altMap = {}; imgs.forEach(function (i) { altMap[i] = alts[i] || ''; });
      var jobs = picked.map(function (a) { return { account_id: a.id, text: texts[a.id] || '', images: imgs, visibility: vis[a.id] || 'public' }; });
      var req = Math.random().toString(36).slice(2) + Date.now().toString(36);
      apiPost('post', { kind: it.kind, id: it.id, path: spec.path || '', confirmed: '1', req: req, payload: JSON.stringify({ jobs: jobs, alts: altMap }) }).then(function (r) {
        busy = false; go.querySelector('span').textContent = 'Post now';
        if (!r.ok) { resBox.appendChild(h('div', { class: 'soc-res bad' }, [h('span', { html: ic('alert', 18) }), h('span', { text: r.error || 'Could not post.' })])); refresh(false); return; }
        var failed = [];
        r.results.forEach(function (x) {
          var row = h('div', { class: 'soc-res ' + (x.ok ? 'ok' : 'bad') }, [h('span', { html: ic(x.ok ? 'check' : 'alert', 18) })]);
          var tx = h('span', {});
          tx.appendChild(document.createTextNode(x.ok ? 'Posted to ' + (x.handle || x.provider) + '. ' : (x.handle || x.provider) + ': ' + x.error + ' '));
          if (x.ok && x.remote_url) tx.appendChild(h('a', { href: x.remote_url, target: '_blank', rel: 'noopener noreferrer', text: 'View the post' }));
          row.appendChild(tx); resBox.appendChild(row);
          if (x.ok) { sel[x.account_id] = false; var cb = document.getElementById('socA' + x.account_id); if (cb) { cb.checked = false; cb.disabled = true; } } else failed.push(x);
        });
        if (r.posted) toast('Posted to ' + r.posted + (r.posted === 1 ? ' account.' : ' accounts.'));
        refresh(true);
        if (!failed.length) { finished = true; go.querySelector('span').textContent = 'Done'; go.disabled = false; summary.textContent = ''; }
      });
    });
    refresh(true);
  }

  /* ---------- wiring ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-soc-open]'); if (!b) return;
    e.preventDefault();
    openSheet({ kind: b.getAttribute('data-kind'), id: b.getAttribute('data-id'), path: b.getAttribute('data-path') || '' });
  });
  window.JaSocial = {
    open: function (spec) { openSheet(spec); },
    openUpload: function (path) { openSheet({ kind: 'upload', id: 0, path: path }); },
    close: close,
    ui: { shell: shell, h: h, ic: ic, toast: toast, copyText: copyText, apiPost: apiPost, alive: function () { return !!layer; } }
  };
})();
