/* Drag-and-drop photo and video picker. Keeps its own file list (adds, removes, de-duplicates),
   mirrors it into the real <input type=file>, shows thumbnails, optional captions and per-file progress.
   window.PCDrop.init({drop, input, list, captions}) -> {files(), setProgress(fraction), setDone(), reset()} */
(function () {
  'use strict';
  var MAX = 40 * 1024 * 1024;
  function size(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function init(o) {
    var drop = o.drop, input = o.input, list = o.list, items = [];
    function ok(f) {
      if (!/^(image|video)\//.test(f.type)) return 'Only photos and videos can be added.';
      if (f.size > MAX) return 'Larger than 40 MB.';
      return '';
    }
    function sync() {
      try { var dt = new DataTransfer(); items.forEach(function (it) { if (!it.err) dt.items.add(it.file); }); input.files = dt.files; } catch (e) {}
      if (o.onchange) o.onchange(items.filter(function (i) { return !i.err; }));
    }
    function add(fileList) {
      Array.prototype.forEach.call(fileList, function (f) {
        if (items.some(function (i) { return i.file.name === f.name && i.file.size === f.size && i.file.lastModified === f.lastModified; })) return;
        var it = { file: f, err: ok(f), url: URL.createObjectURL(f) };
        var li = document.createElement('li'); li.className = 'pw-file' + (it.err ? ' bad' : '');
        var th = f.type.indexOf('video') === 0 ? document.createElement('video') : document.createElement('img');
        th.className = 'th'; th.src = it.url; if (th.tagName === 'VIDEO') th.muted = true; else th.alt = '';
        var nm = document.createElement('div'); nm.className = 'nm'; nm.textContent = f.name;
        var sm = document.createElement('small'); sm.textContent = size(f.size); nm.appendChild(sm);
        var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rm'; rm.setAttribute('aria-label', 'Remove ' + f.name);
        rm.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        var cap = null;
        if (o.captions && !it.err) {
          cap = document.createElement('input'); cap.type = 'text'; cap.name = 'media_caption[]'; cap.className = 'ja-input cap';
          cap.placeholder = 'Caption, add #hashtags like #beach'; cap.setAttribute('aria-label', 'Caption for ' + f.name); cap.maxLength = 480;
          it.cap = cap;
        }
        var bar = document.createElement('div'); bar.className = 'bar'; bar.innerHTML = '<i></i>';
        var st = document.createElement('div'); st.className = 'st'; st.setAttribute('role', 'status'); st.textContent = it.err;
        li.appendChild(th); li.appendChild(nm); li.appendChild(rm); if (cap) li.appendChild(cap); li.appendChild(bar); li.appendChild(st);
        rm.addEventListener('click', function () {
          items.splice(items.indexOf(it), 1); li.remove(); URL.revokeObjectURL(it.url); sync(); input.focus && drop.focus();
        });
        it.li = li; it.bar = bar.firstChild; it.st = st; items.push(it); list.appendChild(li);
      });
      sync();
    }
    drop.setAttribute('tabindex', '0'); drop.setAttribute('role', 'button');
    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { add(e.dataTransfer.files); });
    input.addEventListener('change', function () {
      // the browser replaced input.files with the new pick; fold it into our list
      var picked = Array.prototype.slice.call(input.files); add(picked);
    });

    var cam = null;
    if (o.camera && window.jaCameraPicker) {
      var cl = document.createElement('ul'); cl.className = 'pw-files'; list.parentNode.insertBefore(cl, list.nextSibling);
      cam = window.jaCameraPicker.attach({ button: o.camera, list: cl, captions: !!o.captions, onchange: o.onchange ? function () { o.onchange(items.filter(function (i) { return !i.err; })); } : null });
    }

    return {
      cameraCount: function () { return cam ? cam.count() : 0; },
      resetCamera: function () { if (cam) cam.reset(); },
      files: function () { return items.filter(function (i) { return !i.err; }); },
      hasErrors: function () { return items.some(function (i) { return i.err; }); },
      /* fraction 0..1 of the whole upload; files are sent in order so each gets its own slice of the bytes */
      setProgress: function (fraction) {
        var good = items.filter(function (i) { return !i.err; }), sum = good.reduce(function (a, i) { return a + i.file.size; }, 0) || 1, acc = 0;
        good.forEach(function (i) {
          var a = acc / sum, b = (acc + i.file.size) / sum; acc += i.file.size;
          var p = Math.max(0, Math.min(1, (fraction - a) / (b - a)));
          i.li.classList.add('up'); i.bar.style.width = Math.round(p * 100) + '%';
          i.st.textContent = p >= 1 ? 'Uploaded' : 'Uploading ' + Math.round(p * 100) + '%';
          i.li.classList.toggle('done', p >= 1);
        });
      },
      reset: function () { items.forEach(function (i) { i.li.classList.remove('up', 'done'); i.st.textContent = i.err; i.bar.style.width = '0'; }); }
    };
  }
  window.PCDrop = { init: init };
})();
