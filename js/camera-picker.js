/* Camera roll picker: an accessible modal that lists the signed-in user's private camera roll
   (folders as tabs, lazy photo grid) and hands back public copies for stories and journals.

   window.jaCameraPicker.open({ multiple: true|false, onPick: function (items) {}, onClose: function () {} })
   items = [{ path, thumb_url, caption, place, id }]  (path is the public uploads/... copy)

   API contract (POST camera-api.php, CSRF header added by js/csrf.js):
     action=folders                         -> { ok, folders:[{id,name,count,cover_thumb_url}] }
     action=photos&folder_id&offset&limit   -> { ok, photos:[{id,caption,place,frame,taken_at,thumb_url,full_url,folder_id}], total }
     action=use&id                          -> { ok, path:'uploads/xxxx.jpg' }   (an explicit copy; the original stays private) */
(function () {
  'use strict';
  var API = 'camera-api.php', PAGE = 48, ROLL = 'camera-roll.php', CAMERA = 'camera.php';
  var NOTE = 'Photos you pick are copied into this story or journal. Your originals stay private in your camera roll.';
  var root = null, state = null;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function icon(d, size) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 18); s.setAttribute('height', size || 18);
    s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p); return s;
  }
  var ICON_X = 'M6 6l12 12M18 6L6 18', ICON_CHECK = 'M5 12.5l4.5 4.5L19 7.5',
      ICON_CAM = 'M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zM12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z';

  function call(params) {
    var fd = new FormData();
    Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { throw new Error('The camera roll sent an unexpected reply.'); });
    }).then(function (d) {
      if (!d || d.ok === false || d.error) throw new Error((d && d.error) || 'The camera roll could not be reached.');
      return d;
    });
  }

  function focusables() {
    return Array.prototype.filter.call(root.querySelectorAll('button:not([disabled]), a[href], [tabindex="0"]'), function (n) { return n.offsetParent !== null; });
  }
  function onKey(e) {
    if (!root) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    var f = focusables(); if (!f.length) return;
    var a = f[0], z = f[f.length - 1];
    if (!root.contains(document.activeElement)) { e.preventDefault(); a.focus(); }
    else if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
    else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
  }

  function close(skipCallback) {
    if (!root) return;
    var st = state;
    document.removeEventListener('keydown', onKey, true);
    root.remove(); root = null; state = null;
    document.documentElement.classList.remove('cp-lock');
    if (st && st.opener && st.opener.focus) { try { st.opener.focus(); } catch (e) {} }
    if (!skipCallback && st && st.opts.onClose) st.opts.onClose();
  }

  function open(opts) {
    if (root) return;
    opts = opts || {};
    var multiple = opts.multiple !== false;
    state = { opts: opts, multiple: multiple, opener: document.activeElement, folder: 0, offset: 0, total: 0, sel: [], busy: false, folders: [] };

    root = el('div', 'cp-overlay');
    var dlg = el('div', 'cp-dialog'); dlg.setAttribute('role', 'dialog'); dlg.setAttribute('aria-modal', 'true'); dlg.setAttribute('aria-labelledby', 'cpTitle'); dlg.setAttribute('aria-describedby', 'cpNote');
    var head = el('div', 'cp-head');
    var title = el('h2', 'cp-title', multiple ? 'Choose photos from your camera roll' : 'Choose a photo from your camera roll'); title.id = 'cpTitle';
    var x = el('button', 'cp-x'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.appendChild(icon(ICON_X, 18));
    head.appendChild(title); head.appendChild(x);
    var note = el('p', 'cp-note', NOTE); note.id = 'cpNote';
    var tabs = el('div', 'cp-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Camera roll folders');
    var body = el('div', 'cp-body');
    var status = el('div', 'cp-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    var grid = el('div', 'cp-grid'); grid.setAttribute('role', 'group'); grid.setAttribute('aria-label', 'Photos');
    var more = el('button', 'cp-more', 'Show more photos'); more.type = 'button'; more.hidden = true;
    body.appendChild(status); body.appendChild(grid); body.appendChild(more);
    var foot = el('div', 'cp-foot');
    var take = el('a', 'cp-take'); take.href = CAMERA; take.target = '_blank'; take.rel = 'noopener';
    take.appendChild(icon(ICON_CAM, 16)); take.appendChild(el('span', null, 'Take a new photo'));
    var count = el('span', 'cp-count'); count.setAttribute('aria-live', 'polite');
    var cancel = el('button', 'cp-btn cp-ghost', 'Cancel'); cancel.type = 'button';
    var go = el('button', 'cp-btn cp-primary', multiple ? 'Use photos' : 'Use photo'); go.type = 'button'; go.disabled = true;
    var acts = el('div', 'cp-acts'); acts.appendChild(count); acts.appendChild(cancel); acts.appendChild(go);
    foot.appendChild(take); foot.appendChild(acts);
    dlg.appendChild(head); dlg.appendChild(note); dlg.appendChild(tabs); dlg.appendChild(body); dlg.appendChild(foot);
    root.appendChild(dlg); document.body.appendChild(root);
    document.documentElement.classList.add('cp-lock');
    document.addEventListener('keydown', onKey, true);

    function selIndex(id) { for (var i = 0; i < state.sel.length; i++) if (String(state.sel[i].id) === String(id)) return i; return -1; }
    function refreshFoot() {
      var n = state.sel.length;
      count.textContent = n ? n + (n === 1 ? ' photo selected' : ' photos selected') : '';
      go.disabled = n === 0 || state.busy;
      go.textContent = state.busy ? 'Copying' : (multiple ? (n ? 'Use ' + n + (n === 1 ? ' photo' : ' photos') : 'Use photos') : 'Use photo');
    }
    function setStatus(msg, kind, retry) {
      status.textContent = ''; status.className = 'cp-status' + (kind ? ' ' + kind : ''); status.hidden = !msg && !retry;
      if (msg) status.appendChild(el('p', null, msg));
      if (retry) { var b = el('button', 'cp-btn cp-ghost', retry.label); b.type = 'button'; b.addEventListener('click', retry.fn); status.appendChild(b); }
    }
    function emptyState() {
      status.textContent = ''; status.className = 'cp-status empty'; status.hidden = false;
      status.appendChild(icon(ICON_CAM, 34));
      status.appendChild(el('p', 'cp-empty-h', state.folder ? 'This folder has no photos yet' : 'Your camera roll is empty'));
      status.appendChild(el('p', null, state.folder ? 'Move photos here from your camera roll, or take a new one.' : 'Take a photo with the polaroid camera and it will appear here.'));
      var a = el('a', 'cp-btn cp-primary', 'Open the camera'); a.href = CAMERA; a.target = '_blank'; a.rel = 'noopener'; status.appendChild(a);
    }

    function makeTile(p) {
      var b = el('button', 'cp-tile'); b.type = 'button'; b.setAttribute('data-id', p.id);
      var alt = (p.caption || p.place || 'Photo');
      b.setAttribute('aria-label', alt + (p.taken_at ? ', taken ' + p.taken_at : ''));
      var img = el('img'); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.src = p.thumb_url;
      b.appendChild(img);
      var tick = el('span', 'cp-tick'); tick.appendChild(icon(ICON_CHECK, 14)); b.appendChild(tick);
      if (p.caption || p.place) b.appendChild(el('span', 'cp-cap', p.caption || p.place));
      function paint() { var on = selIndex(p.id) >= 0; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
      paint();
      b.addEventListener('click', function () {
        if (state.busy) return;
        var i = selIndex(p.id);
        if (!multiple) { state.sel = i >= 0 ? [] : [p]; grid.querySelectorAll('.cp-tile').forEach(function (t) { t.classList.remove('on'); t.setAttribute('aria-pressed', 'false'); }); }
        else if (i >= 0) state.sel.splice(i, 1); else state.sel.push(p);
        paint(); refreshFoot(); setStatus('');
      });
      return b;
    }

    function loadPhotos(reset) {
      if (reset) { state.offset = 0; grid.textContent = ''; more.hidden = true; }
      var token = state.token = (state.token || 0) + 1;
      if (reset) setStatus('Loading photos', 'loading');
      more.disabled = true;
      call({ action: 'photos', folder_id: state.folder, offset: state.offset, limit: PAGE }).then(function (d) {
        if (!root || token !== state.token) return;
        var list = d.photos || []; state.total = +d.total || list.length;
        list.forEach(function (p) { grid.appendChild(makeTile(p)); });
        state.offset += list.length;
        more.hidden = !(list.length && state.offset < state.total); more.disabled = false;
        if (!grid.children.length) emptyState(); else setStatus('');
      }).catch(function (err) {
        if (!root || token !== state.token) return;
        more.disabled = false;
        setStatus(err.message || 'Photos could not be loaded.', 'error', { label: 'Try again', fn: function () { loadPhotos(reset); } });
      });
    }
    more.addEventListener('click', function () { loadPhotos(false); });

    function renderTabs() {
      tabs.textContent = '';
      var all = 0; state.folders.forEach(function (f) { all += +f.count || 0; });
      var defs = [{ id: 0, name: 'All photos', count: all }].concat(state.folders);
      defs.forEach(function (f) {
        var t = el('button', 'cp-tab'); t.type = 'button'; t.setAttribute('role', 'tab');
        var on = f.id === state.folder; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
        if (on) t.classList.add('on');
        t.appendChild(el('span', null, f.name)); t.appendChild(el('small', null, String(f.count != null ? f.count : 0)));
        t.addEventListener('click', function () { if (f.id === state.folder) return; state.folder = f.id; renderTabs(); loadPhotos(true); });
        t.addEventListener('keydown', function (e) {
          if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
          var all = Array.prototype.slice.call(tabs.children), i = all.indexOf(t), n = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
          e.preventDefault(); n.click(); var nt = tabs.children[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length]; if (nt) nt.focus();
        });
        tabs.appendChild(t);
      });
    }

    function boot() {
      setStatus('Opening your camera roll', 'loading');
      call({ action: 'folders' }).then(function (d) {
        if (!root) return;
        state.folders = d.folders || [];
        var any = state.folders.some(function (f) { return +f.count > 0; });
        renderTabs();
        if (!any) { tabs.hidden = true; emptyState(); return; }
        loadPhotos(true);
      }).catch(function (err) {
        if (!root) return;
        tabs.hidden = true;
        setStatus(err.message || 'The camera roll could not be reached.', 'error', { label: 'Try again', fn: function () { tabs.hidden = false; boot(); } });
      });
    }

    function pick() {
      if (!state.sel.length || state.busy) return;
      state.busy = true; refreshFoot(); setStatus('Copying ' + state.sel.length + (state.sel.length === 1 ? ' photo' : ' photos') + '. Your originals stay private.', 'loading');
      var queue = state.sel.slice(), done = [], failed = [], firstErr = '';
      function one(p) {
        return call({ action: 'use', id: p.id }).then(function (d) {
          if (!d.path) throw new Error('No file came back.');
          done.push({ path: d.path, thumb_url: p.thumb_url, caption: p.caption || '', place: p.place || '', id: p.id });
        }).catch(function (e) { failed.push(p); firstErr = firstErr || e.message; });
      }
      function worker() { var p = queue.shift(); return p ? one(p).then(worker) : Promise.resolve(); }
      Promise.all([worker(), worker(), worker()]).then(function () {
        if (!root) return;
        state.busy = false;
        var order = {}; state.sel.forEach(function (p, i) { order[p.id] = i; });
        done.sort(function (a, b) { return order[a.id] - order[b.id]; });
        if (!failed.length) { var cb = state.opts.onPick; close(true); if (cb) cb(done); return; }
        state.sel = failed; grid.querySelectorAll('.cp-tile').forEach(function (t) { var on = selIndex(t.getAttribute('data-id')) >= 0; t.classList.toggle('on', on); t.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        refreshFoot();
        if (done.length && state.opts.onPick) state.opts.onPick(done);
        setStatus((done.length ? done.length + ' added. ' : '') + failed.length + (failed.length === 1 ? ' photo' : ' photos') + ' could not be copied' + (firstErr ? ': ' + firstErr : '.') + ' Try again.', 'error');
      });
    }

    x.addEventListener('click', function () { close(); });
    cancel.addEventListener('click', function () { close(); });
    root.addEventListener('mousedown', function (e) { if (e.target === root) close(); });
    go.addEventListener('click', pick);
    refreshFoot(); boot();
    setTimeout(function () { if (x) x.focus(); }, 30);
  }

  /* Wires a button to the picker and renders picked photos as removable rows with hidden inputs, so the form
     posts them as picked[] (plus picked_caption[] when captions are wanted). The server re-validates each path.
       jaCameraPicker.attach({ button, list, multiple, captions, onchange }) -> { count(), reset() } */
  function attach(o) {
    var items = [];
    function sync() { if (o.onchange) o.onchange(items.slice()); }
    function addItem(it) {
      if (items.some(function (x) { return x.path === it.path; })) return;
      var li = el('li', 'pw-file cp-picked');
      var th = el('img', 'th'); th.alt = ''; th.src = it.thumb_url || it.path;
      var nm = el('div', 'nm', it.caption || it.place || 'Camera roll photo'); nm.appendChild(el('small', null, 'From your camera roll'));
      var rm = el('button', 'rm'); rm.type = 'button'; rm.setAttribute('aria-label', 'Remove photo from camera roll'); rm.appendChild(icon(ICON_X, 16));
      var hid = el('input'); hid.type = 'hidden'; hid.name = 'picked[]'; hid.value = it.path;
      li.appendChild(th); li.appendChild(nm); li.appendChild(rm); li.appendChild(hid);
      if (o.captions) {
        var cap = el('input', 'ja-input cap'); cap.type = 'text'; cap.name = 'picked_caption[]'; cap.maxLength = 480;
        cap.placeholder = 'Caption, add #hashtags like #beach'; cap.value = it.caption || ''; cap.setAttribute('aria-label', 'Caption for camera roll photo');
        li.appendChild(cap);
      }
      rm.addEventListener('click', function () { items.splice(items.indexOf(it), 1); li.remove(); sync(); o.button.focus(); });
      it.li = li; items.push(it); o.list.appendChild(li);
    }
    o.button.addEventListener('click', function () {
      open({ multiple: o.multiple !== false, onPick: function (picked) { picked.forEach(addItem); sync(); } });
    });
    return { count: function () { return items.length; }, reset: function () { items.forEach(function (i) { i.li.remove(); }); items = []; sync(); } };
  }

  window.jaCameraPicker = { open: open, close: close, attach: attach };
})();
