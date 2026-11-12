/* Journal writing: autosaved drafts, live word count and reading time, photo drop with upload progress,
   and a calm preview before saving. Progressive: without JS the form still posts normally. */
(function () {
  'use strict';
  var form = document.getElementById('entryForm'); if (!form) return;
  var CFG = window.PC_WRITE || {};
  var KEY = 'ja-draft:new-entry:' + (CFG.uid || 'me');
  var state = document.getElementById('pwState'), stateTxt = document.getElementById('pwStateTxt');
  var story = form.elements['desc'], counts = document.getElementById('pwCounts');
  function $(id) { return document.getElementById(id); }
  function h(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  /* ---------- words + reading time ---------- */
  function words(t) { var m = t.trim().match(/\S+/g); return m ? m.length : 0; }
  function updateCounts() {
    var t = story.value, w = words(t.replace(/^#{1,3}\s+/gm, '')), min = Math.max(1, Math.ceil(w / 200));
    counts.innerHTML = '';
    var a = h('span'); a.innerHTML = '<b>' + w + '</b> ' + (w === 1 ? 'word' : 'words'); counts.appendChild(a);
    var b = h('span'); b.innerHTML = w ? '<b>' + min + '</b> min read' : 'Reading time appears as you write'; counts.appendChild(b);
    var c = h('span'); c.innerHTML = '<b>' + t.length + '</b> characters'; counts.appendChild(c);
  }
  story.addEventListener('input', updateCounts); updateCounts();

  /* ---------- drafts ---------- */
  var SKIP = { csrf: 1, media_lat: 1, media_lon: 1, 'media[]': 1, 'media_caption[]': 1 };
  function fields() { return Array.prototype.filter.call(form.elements, function (el) { return el.name && !SKIP[el.name] && el.type !== 'file' && el.type !== 'submit' && el.type !== 'button'; }); }
  function stamp() { return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  function setState(cls, text) { state.className = 'pw-state ' + cls; stateTxt.textContent = text; }
  function save() {
    var v = {}; fields().forEach(function (el) { v[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    var empty = !fields().some(function (el) { return el.type !== 'checkbox' && el.type !== 'number' && el.tagName !== 'SELECT' && el.value.trim(); });
    try {
      if (empty) { localStorage.removeItem(KEY); setState('', 'Drafts save as you type'); return; }
      localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), v: v })); setState('saved', 'Draft saved at ' + stamp());
    } catch (e) { setState('', 'Draft could not be saved in this browser'); }
  }
  var timer = null;
  function queue() { setState('dirty', 'Saving draft'); clearTimeout(timer); timer = setTimeout(save, 600); }
  form.addEventListener('input', queue); form.addEventListener('change', queue);
  setState('', 'Drafts save as you type');

  var rest = $('pwRestored');
  try {
    var d = JSON.parse(localStorage.getItem(KEY) || 'null');
    if (d && d.v) {
      fields().forEach(function (el) { if (d.v[el.name] !== undefined) { if (el.type === 'checkbox') el.checked = !!d.v[el.name]; else el.value = d.v[el.name]; } });
      updateCounts();
      $('pwRestoredWhen').textContent = new Date(d.t).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
      rest.hidden = false; setState('saved', 'Draft restored');
    }
  } catch (e) {}
  $('pwDiscard').addEventListener('click', function () {
    try { localStorage.removeItem(KEY); } catch (e) {}
    form.reset(); updateCounts(); rest.hidden = true; setState('', 'Draft discarded'); form.elements['title'].focus();
  });

  /* ---------- photos ---------- */
  var drop = PCDrop.init({ drop: $('mediaDrop'), input: $('mediaInput'), list: $('mediaList'), captions: true });

  var geo = $('mediaGeoStatus');
  if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (pos) {
    form.elements['media_lat'].value = pos.coords.latitude; form.elements['media_lon'].value = pos.coords.longitude;
    if (geo) geo.textContent = 'Location captured, so your photos can be pinned on your map.';
  }, function () {}, { maximumAge: 300000, timeout: 8000 });

  /* ---------- preview ---------- */
  var prev = $('pwPreview'); document.body.appendChild(prev);
  var body = $('pwPrevBody'), opener = null, confirmed = false;
  var VIS = { private: 'Only you can read this', link: 'Anyone with the link can read this', 'public': 'Listed in Read journals for everyone' };
  function fmtDate(v) { if (!v) return ''; var d = new Date(v + 'T00:00:00'); return isNaN(d) ? '' : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
  function buildPreview() {
    var f = form.elements; body.textContent = '';
    var title = h('h2', 'pr-title', f['title'].value.trim() || 'Untitled trip'); body.appendChild(title);
    var meta = h('div', 'pr-meta'), place = [f['city'].value.trim(), f['coun'].value.trim()].filter(Boolean).join(', ');
    if (place) meta.appendChild(h('span', null, place));
    var dv = fmtDate(f['dv'].value), dr = fmtDate(f['dr'].value);
    if (dv) meta.appendChild(h('span', null, dr ? dv + ' to ' + dr : dv));
    var w = words(f['desc'].value.replace(/^#{1,3}\s+/gm, '')); meta.appendChild(h('span', null, Math.max(1, Math.ceil(w / 200)) + ' min read'));
    body.appendChild(meta);
    var files = drop.files();
    if (files.length) {
      var g = h('div', 'pr-gallery ' + (files.length >= 5 ? 'lead' : 'n' + files.length)); g.style.margin = '22px 0 26px';
      files.forEach(function (it) {
        var s = h('div', 'pr-shot'); s.style.cursor = 'default';
        var el = it.file.type.indexOf('video') === 0 ? h('video') : h('img'); el.src = it.url; if (el.tagName === 'VIDEO') el.muted = true; else el.alt = it.cap && it.cap.value ? it.cap.value : '';
        s.appendChild(el); if (it.cap && it.cap.value.trim()) s.appendChild(h('figcaption', null, it.cap.value.trim())); g.appendChild(s);
      });
      body.appendChild(g);
    }
    var st = h('div', 'pr-story'); st.style.marginTop = '22px';
    var text = f['desc'].value.trim();
    if (!text) st.appendChild(h('p', null, 'You have not written the story yet. You can add it later by editing this journal.'));
    text.replace(/\r/g, '').split(/\n{2,}/).forEach(function (block) {
      var buf = [];
      var flush = function () { if (buf.length) { var p = h('p'); buf.forEach(function (l, i) { if (i) p.appendChild(document.createElement('br')); p.appendChild(document.createTextNode(l)); }); st.appendChild(p); buf = []; } };
      block.split('\n').forEach(function (ln) {
        var t = ln.trim(); if (!t) return;
        var m = t.match(/^#{1,3}\s+(.{1,90})$/) || t.match(/^(Day\s+\d+\s*(?:[:\-–.]\s*.{0,70})?)$/i);
        if (m) { flush(); st.appendChild(h('h2', null, m[1].trim())); } else buf.push(t);
      });
      flush();
    });
    body.appendChild(st);
    var places = (f['ptv'].value || '').split(/[,;\n]+/).map(function (x) { return x.trim(); }).filter(Boolean);
    if (places.length) {
      var sec = h('div', 'pr-section'); sec.appendChild(h('h3', null, 'Places mentioned'));
      var row = h('div', 'pr-places'); places.slice(0, 12).forEach(function (p) { row.appendChild(h('span', 'pc-chip', p)); }); sec.appendChild(row); body.appendChild(sec);
    }
    var total = ['flight', 'train', 'taxi', 'bus', 'hotel', 'dinner', 'activities', 'shopping', 'misc'].reduce(function (a, k) { return a + (parseFloat(f[k].value) || 0); }, 0);
    if (total > 0) { var c = h('div', 'pr-section'); c.appendChild(h('h3', null, 'What this trip cost')); c.appendChild(h('p', null, '₹' + Math.round(total).toLocaleString('en-IN') + ' in total.')); body.appendChild(c); }
    var vis = f['visibility'].value;
    $('pwPrevVis').textContent = VIS[vis] || VIS.private;
    $('pwPrevSave').textContent = vis === 'private' ? 'Save journal' : 'Publish journal';
  }
  function openPrev() { buildPreview(); opener = document.activeElement; prev.classList.add('open'); document.body.style.overflow = 'hidden'; $('pwPrevBack').focus(); }
  function closePrev() { prev.classList.remove('open'); document.body.style.overflow = ''; if (opener) opener.focus(); }
  $('pwPrevBack').addEventListener('click', closePrev); $('pwPrevX').addEventListener('click', closePrev);
  prev.addEventListener('click', function (e) { if (e.target === prev) closePrev(); });
  document.addEventListener('keydown', function (e) {
    if (!prev.classList.contains('open')) return;
    if (e.key === 'Escape') { e.preventDefault(); closePrev(); }
    if (e.key === 'Tab') {
      var f = Array.prototype.slice.call(prev.querySelectorAll('button:not([disabled])')), a = f[0], z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    }
  });

  /* ---------- submit: preview first, then upload with progress ---------- */
  var err = $('pwErr');
  function showErr(msg) { err.textContent = msg; err.hidden = !msg; if (msg) err.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  form.addEventListener('submit', function (e) {
    if (!window.FormData || !window.XMLHttpRequest) return;
    e.preventDefault(); showErr('');
    if (!confirmed) { openPrev(); return; }
    confirmed = false;
    var saveBtn = $('pwPrevSave'), back = $('pwPrevBack');
    saveBtn.disabled = true; back.disabled = true; saveBtn.textContent = 'Saving';
    var fd = new FormData(form); fd.set('csrf', CFG.csrf || window.JA_CSRF || '');
    var xhr = new XMLHttpRequest(); xhr.open('POST', form.getAttribute('action') || location.href);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); if (CFG.csrf) xhr.setRequestHeader('X-CSRF-Token', CFG.csrf);
    var hasFiles = drop.files().length > 0;
    if (hasFiles) closePrev();   // let people watch each file upload
    xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) drop.setProgress(ev.loaded / ev.total); };
    function fail(msg) {
      saveBtn.disabled = false; back.disabled = false; saveBtn.textContent = 'Save journal'; drop.reset(); showErr(msg);
    }
    xhr.onerror = function () { fail('The upload could not finish. Check your connection and try again. Your draft is still here.'); };
    xhr.onload = function () {
      var r = null; try { r = JSON.parse(xhr.responseText); } catch (x) {}
      if (xhr.status === 200 && r && r.redirect) { try { localStorage.removeItem(KEY); } catch (x) {} drop.setProgress(1); location.href = r.redirect; return; }
      fail((r && r.error) || 'The journal could not be saved. Your draft is still here, so you can try again.');
    };
    xhr.send(fd);
  });
  $('pwPrevSave').addEventListener('click', function () { confirmed = true; if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true })); });
})();
