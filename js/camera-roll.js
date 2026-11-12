/* JourneyAI private camera roll: folders, a lazy photo grid, a lightbox with caption editing, multi-select, deletes with undo.
   Talks only to camera-api.php (POST + CSRF). Photos are fetched through camera-img.php, never from a public URL. No libraries. */
(function () {
  'use strict';
  var root = document.getElementById('cr');
  if (!root) return;
  var cfg = {}; try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) {}
  var $ = function (id) { return document.getElementById(id); };
  var PAGE = 60, UNDO_MS = 6000;

  var IC = {
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>', x: '<path d="M18 6L6 18M6 6l12 12"/>', next: '<path d="M9 6l6 6-6 6"/>',
    down: '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 20h16"/>', trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    camera: '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    images: '<rect x="3" y="5" width="14" height="14" rx="2"/><path d="M21 8v11a2 2 0 0 1-2 2H8"/><circle cx="8" cy="10" r="1.5"/><path d="M17 15l-3.5-3.5L5 19"/>',
    folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>', plus: '<path d="M12 5v14M5 12h14"/>'
  };
  function svg(n, s) { return '<svg xmlns="http://www.w3.org/2000/svg" width="' + (s || 18) + '" height="' + (s || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + IC[n] + '</svg>'; }
  function h(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') e.className = attrs[k]; else if (k === 'html') e.innerHTML = attrs[k]; else if (k === 'text') e.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]); else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }

  /* ---------- api ---------- */
  function api(action, data) {
    var fd = new FormData(); fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else if (v != null) fd.append(k, v);
    });
    return fetch('camera-api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent an unexpected reply.' }; }); },
            function () { return { ok: false, error: 'Could not reach the server. Check your connection.' }; });
  }

  /* ---------- state ---------- */
  var S = {
    folders: [], cur: cfg.folder || 0, photos: [], total: 0, selecting: false, sel: {}, hiddenPhotos: {}, hiddenFolders: {},
    hideFolderPhotos: {}, usage: { photos: cfg.photos, bytes: cfg.bytes }, loading: false, pending: null, gen: 0
  };
  var el = {
    folders: $('crFolders'), grid: $('crGrid'), title: $('crTitle'), usage: $('crUsage'), more: $('crMore'), moreBtn: $('crMoreBtn'), empty: $('crEmpty'),
    rename: $('crRename'), delFolder: $('crDelFolder'), select: $('crSelect'), bulk: $('crBulk'), bulkN: $('crBulkN'), bulkFolder: $('crBulkFolder')
  };

  function folderById(id) { for (var i = 0; i < S.folders.length; i++) if (S.folders[i].id === id) return S.folders[i]; return null; }
  function visibleFolders() { return S.folders.filter(function (f) { return !S.hiddenFolders[f.id]; }); }
  function visiblePhotos() {
    return S.photos.filter(function (p) { return !S.hiddenPhotos[p.id] && !(p.folder_id != null && S.hideFolderPhotos[p.folder_id]); });
  }
  function nSel() { return Object.keys(S.sel).length; }

  /* ---------- toast (with Undo) ---------- */
  var toastEl = null, toastT = 0;
  function toast(msg, actionLabel, onAction, ms) {
    if (toastEl) toastEl.remove(); clearTimeout(toastT);
    toastEl = h('div', { class: 'cm-toast', role: 'status' }, [h('span', { text: msg })]);
    if (actionLabel) toastEl.appendChild(h('button', { type: 'button', text: actionLabel, onclick: function () { clearToast(); onAction(); } }));
    document.body.appendChild(toastEl);
    toastT = setTimeout(clearToast, ms || 4500);
  }
  function clearToast() { clearTimeout(toastT); if (toastEl) { toastEl.remove(); toastEl = null; } }

  /* ---------- deferred actions: hide now, send after the undo window ---------- */
  function flushPending() {
    var p = S.pending; if (!p) return Promise.resolve();
    S.pending = null; clearTimeout(p.timer);
    return p.run();
  }
  function queue(label, apply, revert, run) {
    flushPending();
    apply();
    var p = S.pending = { run: run, timer: 0, beacon: null };
    p.timer = setTimeout(function () { if (S.pending === p) { S.pending = null; run(); } }, UNDO_MS);
    toast(label, 'Undo', function () { clearTimeout(p.timer); if (S.pending === p) S.pending = null; revert(); toast('Restored.'); }, UNDO_MS);
    return p;
  }
  window.addEventListener('pagehide', function () {
    var p = S.pending; if (!p || !p.beacon || !navigator.sendBeacon) return;
    var fd = new FormData(); fd.append('csrf', window.JA_CSRF || '');
    Object.keys(p.beacon).forEach(function (k) { var v = p.beacon[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); });
    navigator.sendBeacon('camera-api.php', fd);
  });

  /* ---------- loading ---------- */
  function refreshUsage() {
    var u = S.usage; if (!u) return;
    el.usage.textContent = u.photos + ' of ' + cfg.maxPhotos + ' photos, ' + (u.bytes / 1048576).toFixed(1) + ' of ' + Math.round(cfg.maxBytes / 1048576) + ' MB used.';
  }
  function loadFolders() {
    return api('folders').then(function (d) {
      if (!d.ok) { toast(d.error || 'Could not load your folders.'); return; }
      S.folders = d.folders; if (d.usage) S.usage = d.usage;
      if (S.cur && !folderById(S.cur)) S.cur = 0;
      renderFolders(); renderHead(); refreshUsage();
    });
  }
  function loadPhotos(reset) {
    if (S.loading) return Promise.resolve();
    S.loading = true; var gen = ++S.gen;
    if (reset) { S.photos = []; el.grid.innerHTML = '<div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div><div class="cr-skel"></div>'; el.empty.hidden = true; el.more.hidden = true; }
    return api('photos', { folder_id: S.cur, offset: S.photos.length, limit: PAGE }).then(function (d) {
      S.loading = false;
      if (gen !== S.gen) return;
      if (!d.ok) { el.grid.innerHTML = ''; toast(d.error || 'Could not load your photos.'); return; }
      S.total = d.total; S.photos = S.photos.concat(d.photos);
      renderGrid();
    });
  }

  /* ---------- rendering ---------- */
  function renderFolders() {
    var all = S.folders.reduce(function (n, f) { return n + (S.hiddenFolders[f.id] ? 0 : f.count); }, 0);
    el.folders.innerHTML = '';
    function item(id, name, count, cover) {
      var b = h('button', { type: 'button', class: 'cr-folder', 'aria-current': S.cur === id ? 'true' : false, onclick: function () { selectFolder(id); } }, [
        h('span', { class: 'cv', style: cover ? 'background-image:url(' + cover + ')' : false, html: cover ? '' : svg(id === 0 ? 'images' : 'folder', 20) }),
        h('span', { class: 'nm', text: name }), h('span', { class: 'ct', text: String(count) })
      ]);
      return h('li', {}, [b]);
    }
    var allCover = S.folders.length ? (S.folders.filter(function (f) { return f.cover_thumb_url && !S.hiddenFolders[f.id]; })[0] || {}).cover_thumb_url : null;
    el.folders.appendChild(item(0, 'All photos', all, allCover));
    visibleFolders().forEach(function (f) { el.folders.appendChild(item(f.id, f.name, f.count, f.cover_thumb_url)); });
    el.folders.appendChild(h('li', {}, [h('button', { type: 'button', class: 'cr-newfolder', html: svg('plus', 16) + '<span>New folder</span>', onclick: newFolder })]));
    // bulk-move targets
    el.bulkFolder.innerHTML = '';
    visibleFolders().forEach(function (f) { el.bulkFolder.appendChild(h('option', { value: f.id, text: 'Move to ' + f.name })); });
  }
  function renderHead() {
    var f = S.cur ? folderById(S.cur) : null;
    el.title.innerHTML = '';
    el.title.appendChild(document.createTextNode(f ? f.name : 'All photos'));
    var n = f ? f.count : S.folders.reduce(function (a, x) { return a + x.count; }, 0);
    el.title.appendChild(h('small', { text: n + (n === 1 ? ' photo' : ' photos') }));
    var custom = !!(f && !f.is_default);
    el.rename.hidden = !custom; el.delFolder.hidden = !custom;
  }
  function renderGrid() {
    var list = visiblePhotos();
    el.grid.innerHTML = '';
    root.classList.toggle('cr-selecting', S.selecting);
    list.forEach(function (p, i) {
      var cap = p.caption || ('Photo taken ' + (p.taken_at || '').slice(0, 10));
      var img = h('img', { src: p.thumb_url, alt: cap, loading: 'lazy', decoding: 'async', width: p.tw || false, height: p.th || false, 'data-mo-skip': true });
      var b = h('button', { type: 'button', class: 'cr-tile', 'aria-pressed': S.selecting ? (S.sel[p.id] ? 'true' : 'false') : false, 'aria-label': cap, 'data-id': p.id,
        onclick: function () { if (S.selecting) toggleSel(p.id, b); else openLb(i, b); } }, [img, h('span', { class: 'ck', html: svg('check', 15) })]);
      el.grid.appendChild(b);
    });
    var shown = S.photos.length < S.total;
    el.more.hidden = !shown;
    renderEmpty(list.length);
    updateBulk();
  }
  function renderEmpty(n) {
    if (n || S.loading || S.photos.length < S.total) { el.empty.hidden = true; return; }
    var f = S.cur ? folderById(S.cur) : null;
    el.empty.hidden = false; el.empty.innerHTML = '';
    el.empty.appendChild(h('span', { html: svg(f ? 'folder' : 'camera', 34) }));
    el.empty.appendChild(h('h2', { text: f ? 'This folder is empty' : 'No photos yet' }));
    el.empty.appendChild(h('p', { text: f ? 'Take a new photo and choose this folder when you save it, or move photos here from another folder.'
      : 'Photos you take with the camera show up here. Only you can see them.' }));
    el.empty.appendChild(h('a', { class: 'ja-btn ja-btn-primary', href: 'camera.php', html: svg('camera', 18) + '<span>Take a photo</span>' }));
  }

  /* ---------- selection ---------- */
  function setSelecting(on) {
    S.selecting = on; S.sel = {};
    el.select.setAttribute('aria-pressed', on ? 'true' : 'false');
    el.select.querySelector('span').textContent = on ? 'Cancel select' : 'Select';
    renderGrid();
  }
  function toggleSel(id, btn) {
    if (S.sel[id]) delete S.sel[id]; else S.sel[id] = true;
    btn.setAttribute('aria-pressed', S.sel[id] ? 'true' : 'false'); updateBulk();
  }
  function updateBulk() {
    var n = nSel(); el.bulk.hidden = !(S.selecting && n > 0);
    el.bulkN.textContent = n + ' selected';
  }

  function selectFolder(id) {
    if (S.cur === id) return;
    S.cur = id; S.selecting = false; S.sel = {};
    el.select.setAttribute('aria-pressed', 'false'); el.select.querySelector('span').textContent = 'Select';
    try { history.replaceState(null, '', id ? '?folder=' + id : location.pathname); } catch (e) {}
    renderFolders(); renderHead(); loadPhotos(true);
  }

  /* ---------- modal ---------- */
  var lastFocus = null;
  function modal(build) {
    lastFocus = document.activeElement;
    var scrim = h('div', { class: 'cr-scrim' }), box = h('div', { class: 'cr-modal', role: 'dialog', 'aria-modal': 'true' });
    scrim.appendChild(box); document.body.appendChild(scrim);
    function close() { document.removeEventListener('keydown', key, true); scrim.remove(); if (lastFocus && lastFocus.focus) try { lastFocus.focus(); } catch (e) {} }
    function key(e) {
      if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
      if (e.key === 'Tab') {
        var f = [].slice.call(box.querySelectorAll('button,input,select,a[href]')).filter(function (x) { return !x.disabled; });
        if (!f.length) return; var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
    scrim.addEventListener('mousedown', function (e) { if (e.target === scrim) close(); });
    document.addEventListener('keydown', key, true);
    build(box, close);
    var first = box.querySelector('input,button'); if (first) first.focus();
    return close;
  }
  function nameDialog(title, value, confirmLabel, submit) {
    modal(function (box, close) {
      var inp = h('input', { type: 'text', maxlength: '80', value: value || '', 'aria-label': 'Folder name', autocomplete: 'off' });
      var err = h('p', { class: 'err', role: 'alert' });
      var go = h('button', { type: 'button', class: 'go', text: confirmLabel });
      function doit() {
        var v = inp.value.trim(); if (!v) { err.textContent = 'Give the folder a name.'; inp.focus(); return; }
        go.disabled = true;
        submit(v).then(function (msg) { if (msg) { err.textContent = msg; go.disabled = false; } else close(); });
      }
      go.addEventListener('click', doit);
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doit(); } });
      box.appendChild(h('h2', { text: title })); box.appendChild(inp); box.appendChild(err);
      box.appendChild(h('div', { class: 'btns' }, [h('button', { type: 'button', text: 'Cancel', onclick: close }), go]));
      setTimeout(function () { inp.select(); }, 0);
    });
  }

  /* ---------- folder actions ---------- */
  function newFolder() {
    nameDialog('New folder', '', 'Create folder', function (name) {
      return api('create_folder', { name: name }).then(function (d) {
        if (!d.ok) return d.error;
        return loadFolders().then(function () { selectFolder(d.folder.id); toast('Created ' + d.folder.name + '.'); });
      });
    });
  }
  el.rename.addEventListener('click', function () {
    var f = folderById(S.cur); if (!f) return;
    nameDialog('Rename folder', f.name, 'Save name', function (name) {
      return api('rename_folder', { id: f.id, name: name }).then(function (d) {
        if (!d.ok) return d.error;
        return loadFolders().then(function () { toast('Renamed to ' + d.folder.name + '.'); });
      });
    });
  });
  el.delFolder.addEventListener('click', function () {
    var f = folderById(S.cur); if (!f) return;
    modal(function (box, close) {
      var mv = h('input', { type: 'radio', name: 'dm', value: 'move', checked: true }), dl = h('input', { type: 'radio', name: 'dm', value: 'delete' });
      box.appendChild(h('h2', { text: 'Delete "' + f.name + '"?' }));
      box.appendChild(h('p', { text: f.count ? 'This folder has ' + f.count + (f.count === 1 ? ' photo.' : ' photos.') + ' What should happen to them?' : 'The folder is empty.' }));
      if (f.count) box.appendChild(h('div', { class: 'opts' }, [
        h('label', { class: 'opt' }, [mv, h('span', {}, [h('b', { text: 'Move them to ' + cfg.default }), h('small', { text: 'The photos stay in your camera roll.' })])]),
        h('label', { class: 'opt' }, [dl, h('span', {}, [h('b', { text: 'Delete the photos too' }), h('small', { text: 'You can undo for a few seconds.' })])])
      ]));
      var go = h('button', { type: 'button', class: 'go', text: 'Delete folder' });
      go.addEventListener('click', function () {
        var mode = dl.checked ? 'delete' : 'move'; close(); deleteFolder(f, mode);
      });
      box.appendChild(h('div', { class: 'btns' }, [h('button', { type: 'button', text: 'Cancel', onclick: close }), go]));
    });
  });
  function deleteFolder(f, mode) {
    var wasCur = S.cur;
    queue('Deleted folder ' + f.name + '.', function () {
      S.hiddenFolders[f.id] = true; if (mode === 'delete') S.hideFolderPhotos[f.id] = true;
      if (S.cur === f.id) { S.cur = 0; loadPhotos(true); }
      renderFolders(); renderHead(); renderGrid();
    }, function () {
      delete S.hiddenFolders[f.id]; delete S.hideFolderPhotos[f.id];
      if (wasCur === f.id) { S.cur = f.id; loadPhotos(true); } else renderGrid();
      renderFolders(); renderHead();
    }, function () {
      return api('delete_folder', { id: f.id, mode: mode }).then(function (d) {
        delete S.hiddenFolders[f.id]; delete S.hideFolderPhotos[f.id];
        if (!d.ok) toast(d.error || 'Could not delete the folder.');
        return Promise.all([loadFolders(), loadPhotos(true)]);
      });
    }).beacon = { action: 'delete_folder', id: f.id, mode: mode };
  }

  /* ---------- photo deletes (undo) ---------- */
  function deletePhotos(ids) {
    var label = ids.length === 1 ? 'Deleted 1 photo.' : 'Deleted ' + ids.length + ' photos.';
    var q = queue(label, function () {
      ids.forEach(function (id) { S.hiddenPhotos[id] = true; });
      closeLb(); S.sel = {}; renderGrid();
    }, function () { ids.forEach(function (id) { delete S.hiddenPhotos[id]; }); renderGrid(); }, function () {
      return api('delete', { ids: ids }).then(function (d) {
        ids.forEach(function (id) { delete S.hiddenPhotos[id]; });
        S.photos = S.photos.filter(function (p) { return ids.indexOf(p.id) < 0; });
        if (!d.ok) toast(d.error || 'Could not delete.');
        S.total = Math.max(0, S.total - (d.deleted || 0));
        return loadFolders().then(function () { renderGrid(); });
      });
    });
    q.beacon = { action: 'delete', ids: ids };
  }

  /* ---------- multi-select actions ---------- */
  el.select.addEventListener('click', function () { setSelecting(!S.selecting); });
  $('crBulkCancel').addEventListener('click', function () { setSelecting(false); });
  $('crBulkDel').addEventListener('click', function () { var ids = Object.keys(S.sel).map(Number); if (ids.length) deletePhotos(ids); });
  $('crBulkMove').addEventListener('click', function () {
    var ids = Object.keys(S.sel).map(Number), to = parseInt(el.bulkFolder.value, 10); if (!ids.length || !to) return;
    var f = folderById(to);
    Promise.all(ids.map(function (id) { return api('update', { id: id, folder_id: to }); })).then(function (rs) {
      var bad = rs.filter(function (r) { return !r.ok; })[0];
      if (bad) { toast(bad.error || 'Could not move some photos.'); }
      else toast('Moved ' + ids.length + (ids.length === 1 ? ' photo' : ' photos') + ' to ' + (f ? f.name : 'the folder') + '.');
      setSelecting(false);
      loadFolders().then(function () { loadPhotos(true); });
    });
  });
  el.moreBtn.addEventListener('click', function () { loadPhotos(false); });

  /* ---------- lightbox ---------- */
  var lb = null;
  function openLb(index, opener) {
    closeLb();
    var list = visiblePhotos(); if (!list[index]) return;
    var cur = index, prevFocus = opener || document.activeElement;
    var img = h('img', { alt: '' });
    var prev = h('button', { type: 'button', class: 'cr-lb-nav cr-lb-prev', 'aria-label': 'Previous photo', html: svg('next', 22), onclick: function () { go(-1); } });
    var next = h('button', { type: 'button', class: 'cr-lb-nav cr-lb-next', 'aria-label': 'Next photo', html: svg('next', 22), onclick: function () { go(1); } });
    var close = h('button', { type: 'button', class: 'cr-lb-close', 'aria-label': 'Close photo', html: svg('x', 22), onclick: closeLb });
    var cap = h('input', { type: 'text', maxlength: '200', autocomplete: 'off' }), place = h('input', { type: 'text', maxlength: '120', autocomplete: 'off' });
    var fol = h('select', {});
    var meta = h('p', { class: 'cr-lb-meta' }), state = h('p', { class: 'cr-lb-state', role: 'status' });
    var dl = h('a', { class: 'cr-tool', html: svg('down', 16) + '<span>Download</span>' });
    var del = h('button', { type: 'button', class: 'cr-tool danger', html: svg('trash', 16) + '<span>Delete</span>' });
    var side = h('div', { class: 'cr-lb-side' }, [
      h('label', {}, ['Caption', cap]), h('label', {}, ['Place', place]), h('label', {}, ['Folder', fol]), meta,
      h('div', { class: 'cr-lb-btns' }, [dl, del]), state
    ]);
    var stage = h('div', { class: 'cr-lb-stage' }, [img, prev, next, close]);
    var box = h('div', { class: 'cr-lb', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Photo' }, [stage, side]);
    document.body.appendChild(box); document.body.style.overflow = 'hidden';
    lb = { box: box, prevFocus: prevFocus, key: key };

    function cur_() { return visiblePhotos()[cur]; }
    function show() {
      var list = visiblePhotos(), p = list[cur]; if (!p) { closeLb(); return; }
      img.src = p.thumb_url; img.alt = p.caption || 'Photo';
      var full = new Image(); full.onload = function () { if (cur_() && cur_().id === p.id) img.src = p.full_url; }; full.src = p.full_url;
      cap.value = p.caption || ''; place.value = p.place || '';
      fol.innerHTML = ''; visibleFolders().forEach(function (f) { fol.appendChild(h('option', { value: f.id, text: f.name, selected: f.id === p.folder_id })); });
      meta.textContent = (p.taken_at ? 'Taken ' + p.taken_at.slice(0, 16).replace('T', ' ') : '') + (p.frame && p.frame !== 'none' ? ', ' + p.frame + ' frame' : '');
      dl.href = 'camera-img.php?id=' + p.id + '&t=full&dl=1';
      prev.hidden = cur <= 0; next.hidden = cur >= list.length - 1; state.textContent = '';
    }
    function go(d) { var n = cur + d; if (n < 0 || n >= visiblePhotos().length) return; cur = n; show(); }
    function saveText() {
      var p = cur_(); if (!p) return;
      var c = cap.value.trim(), pl = place.value.trim(); if (c === p.caption && pl === p.place) return;
      state.textContent = 'Saving';
      api('update', { id: p.id, caption: c, place: pl }).then(function (d) {
        if (!d.ok) { state.textContent = d.error || 'Could not save.'; return; }
        p.caption = d.photo.caption; p.place = d.photo.place; state.textContent = 'Saved.';
        var t = el.grid.querySelector('[data-id="' + p.id + '"]'); if (t) { t.setAttribute('aria-label', p.caption || 'Photo'); t.querySelector('img').alt = p.caption || 'Photo'; }
      });
    }
    cap.addEventListener('change', saveText); place.addEventListener('change', saveText);
    [cap, place].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); i.blur(); } }); });
    fol.addEventListener('change', function () {
      var p = cur_(); if (!p) return; var to = parseInt(fol.value, 10);
      state.textContent = 'Moving';
      api('update', { id: p.id, folder_id: to }).then(function (d) {
        if (!d.ok) { state.textContent = d.error || 'Could not move.'; return; }
        p.folder_id = d.photo.folder_id; var f = folderById(to); state.textContent = 'Moved to ' + (f ? f.name : 'folder') + '.';
        loadFolders().then(function () {
          if (S.cur && S.cur !== to) { S.photos = S.photos.filter(function (x) { return x.id !== p.id; }); S.total = Math.max(0, S.total - 1); renderGrid(); if (!visiblePhotos().length) closeLb(); else { cur = Math.min(cur, visiblePhotos().length - 1); show(); } }
        });
      });
    });
    del.addEventListener('click', function () { var p = cur_(); if (p) deletePhotos([p.id]); });
    function key(e) {
      if (e.target && /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName) && e.key !== 'Escape' && e.key !== 'Tab') return;
      if (e.key === 'Escape') { e.preventDefault(); closeLb(); }
      else if (e.key === 'ArrowLeft') go(-1); else if (e.key === 'ArrowRight') go(1);
      else if (e.key === 'Tab') {
        var f = [].slice.call(box.querySelectorAll('button,input,select,a[href]')).filter(function (x) { return !x.disabled && !x.hidden && x.offsetParent !== null; });
        if (!f.length) return; var a = f[0], z = f[f.length - 1];
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
      }
    }
    document.addEventListener('keydown', key);
    show(); close.focus();
  }
  function closeLb() {
    if (!lb) return;
    document.removeEventListener('keydown', lb.key); lb.box.remove(); document.body.style.overflow = '';
    var f = lb.prevFocus; lb = null;
    if (f && document.contains(f) && f.focus) try { f.focus(); } catch (e) {}
  }

  /* ---------- go ---------- */
  refreshUsage();
  loadFolders().then(function () { loadPhotos(true); });
})();
