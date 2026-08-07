<?php
$ja_title = "Edit Storybook"; $ja_active = "storybooks";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$book_id = (int)($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, "SELECT * FROM storybooks WHERE book_id = ? AND eml = ?");
mysqli_stmt_bind_param($stmt, 'is', $book_id, $em);
mysqli_stmt_execute($stmt);
$book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$book) { header('Location: my-storybooks.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM storybook_pages WHERE book_id = ? ORDER BY sort_order");
mysqli_stmt_bind_param($stmt, 'i', $book_id);
mysqli_stmt_execute($stmt);
$pages = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$emojiSet = [];
$stmt = mysqli_prepare($conn, "SELECT emoji_set FROM storybooks_themes WHERE theme_id = ?");
mysqli_stmt_bind_param($stmt, 's', $book['theme']);
mysqli_stmt_execute($stmt);
$themeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if ($themeRow && $themeRow['emoji_set']) { $emojiSet = json_decode($themeRow['emoji_set'], true); }

$jsonPages = json_encode(array_map(function($p) {
    return [
        'page_id'       => (int)$p['page_id'],
        'sort_order'    => (int)$p['sort_order'],
        'template'      => $p['template'],
        'title'         => $p['title'],
        'body_text'     => $p['body_text'],
        'page_date'     => $p['page_date'],
        'photo_1'       => $p['photo_1'],
        'photo_1_cap'   => $p['photo_1_cap'],
        'photo_2'       => $p['photo_2'],
        'photo_2_cap'   => $p['photo_2_cap'],
        'photo_3'       => $p['photo_3'],
        'photo_3_cap'   => $p['photo_3_cap'],
        'photo_4'       => $p['photo_4'],
        'photo_4_cap'   => $p['photo_4_cap'],
        'decorations'   => $p['decorations'],
        'cost_entry_id' => $p['cost_entry_id'] !== null ? (int)$p['cost_entry_id'] : null,
    ];
}, $pages));
$jsonEmoji = json_encode($emojiSet);
$pageCount = count($pages);
$bookTitle = htmlspecialchars($book['title']);
$theme = htmlspecialchars($book['theme']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $bookTitle ?> — Edit — JourneyAI</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Serif+Display&family=Lora:wght@400;600&family=Caveat:wght@400;600&family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/storybook.css">
</head>
<body>
<div class="sb-shell" data-sb-theme="<?= $theme ?>">

  <div class="sb-toolbar">
    <a href="my-storybooks.php" class="sb-btn sb-btn-ghost sb-btn-sm">Back</a>
    <span class="sb-toolbar-breadcrumb">
      <a href="my-storybooks.php">My Storybooks</a> / <?= $bookTitle ?>
    </span>
    <div class="sb-toolbar-actions">
      <button class="sb-btn sb-btn-sm" id="sbEmojiBtn" type="button">Emojis</button>
      <button class="sb-btn sb-btn-sm" id="sbThemeBtn" type="button">Theme</button>
      <a href="storybook-view.php?id=<?= $book_id ?>" class="sb-btn sb-btn-sm" target="_blank">View</a>
      <a href="storybook-print.php?id=<?= $book_id ?>" class="sb-btn sb-btn-sm" target="_blank">Print</a>
      <button class="sb-btn sb-btn-sm" id="sbShareBtn" type="button">Share</button>
      <button class="sb-btn sb-btn-sm sb-btn-danger" id="sbDeleteBookBtn" type="button">Delete</button>
    </div>
  </div>

  <div class="sb-editor">
    <div class="sb-thumbbar" id="sbThumbbar">
      <div class="sb-thumbbar-head">Pages (<?= $pageCount ?>)</div>
      <label style="display:flex;align-items:center;gap:6px;font-size:.72rem;color:var(--sb-text-dim);padding:0 2px 8px">
        Reading order:
        <select id="sbPageOrderSelect" style="font-size:.72rem;border:1px solid var(--sb-border);border-radius:5px;background:var(--sb-paper);color:var(--sb-text);padding:2px 4px">
          <option value="manual"<?= $book['page_order'] !== 'chronological' ? ' selected' : '' ?>>Manual</option>
          <option value="chronological"<?= $book['page_order'] === 'chronological' ? ' selected' : '' ?>>By date</option>
        </select>
      </label>
      <div id="sbThumbList"></div>
      <button class="sb-add-page-btn" id="sbAddPageBtn" type="button">
        <span class="plus">+</span>
        <span>New Page</span>
      </button>
    </div>
    <div class="sb-canvas" id="sbCanvas"></div>
  </div>

  <div class="sb-modal-overlay" id="sbTplOverlay" style="display:none">
    <div class="sb-modal">
      <div class="sb-modal-title">Choose a template</div>
      <div class="sb-modal-grid">
        <div class="sb-template-card" data-template="cover">
          <div class="sb-template-icon">&#x1F4D6;</div>
          <div class="sb-template-name">Cover</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">Title page with hero image</div>
        </div>
        <div class="sb-template-card" data-template="photo-caption">
          <div class="sb-template-icon">&#x1F5BC;</div>
          <div class="sb-template-name">Photo + Caption</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">One big photo with text</div>
        </div>
        <div class="sb-template-card" data-template="two-photo">
          <div class="sb-template-icon">&#x1F4F7;</div>
          <div class="sb-template-name">Two Photos</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">Side-by-side images</div>
        </div>
        <div class="sb-template-card" data-template="story">
          <div class="sb-template-icon">&#x270D;</div>
          <div class="sb-template-name">Story</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">Full-page text</div>
        </div>
        <div class="sb-template-card" data-template="cost-card">
          <div class="sb-template-icon">&#x1F4B0;</div>
          <div class="sb-template-name">Cost Breakdown</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">Travel expense summary</div>
        </div>
        <div class="sb-template-card" data-template="photos">
          <div class="sb-template-icon">&#x1F39E;</div>
          <div class="sb-template-name">Photo Grid</div>
          <div style="font-size:.7rem;color:var(--sb-text-dim)">2x2 photo mosaic</div>
        </div>
      </div>
    </div>
  </div>

  <div class="sb-modal-overlay" id="sbThemeOverlay" style="display:none">
    <div class="sb-modal">
      <div class="sb-modal-title">Choose a theme</div>
      <div class="sb-theme-grid" id="sbThemeGrid"></div>
    </div>
  </div>

  <div class="sb-emoji-picker" id="sbEmojiPicker" style="display:none"></div>

  <div class="sb-modal-overlay" id="sbShareOverlay" style="display:none">
    <div class="sb-modal" style="max-width:460px">
      <div class="sb-modal-title">Share this storybook</div>
      <div style="display:flex;flex-direction:column;gap:14px">
        <div style="display:flex;gap:18px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;font-size:.88rem">
            <input type="radio" name="sbVisibility" value="private"> Private
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;font-size:.88rem">
            <input type="radio" name="sbVisibility" value="link"> Link-shared
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;font-size:.88rem">
            <input type="radio" name="sbVisibility" value="public"> Public
          </label>
        </div>
        <div id="sbShareLinkWrap" style="display:none;gap:8px;align-items:center">
          <input type="text" id="sbShareLink" readonly style="flex:1;font-size:.82rem;border:1px solid var(--sb-border);border-radius:6px;padding:8px 10px;background:var(--sb-paper);color:var(--sb-text);font-family:var(--sb-font-body)">
          <button class="sb-btn sb-btn-sm sb-btn-primary" id="sbShareCopyBtn" type="button">Copy</button>
        </div>
        <button class="sb-btn sb-btn-sm" id="sbShareCloseBtn" type="button" style="align-self:flex-end">Done</button>
      </div>
    </div>
  </div>

</div><!-- .sb-shell -->
<script>
(function(){
  var API = 'storybook-api.php';
  var BOOK_ID = <?= $book_id ?>;
  var EMOJI_SET = <?= $jsonEmoji ?>;
  var INITIAL_PAGES = <?= $jsonPages ?>;
  var BOOK_VISIBILITY = <?= json_encode($book['visibility']) ?>;
  var BOOK_SHARE_TOKEN = <?= json_encode($book['share_token']) ?>;

  var pages = INITIAL_PAGES.slice();
  var activePageIdx = 0;

  function apiGet(params) {
    var qs = Object.keys(params).map(function(k){ return k+'='+encodeURIComponent(params[k]); }).join('&');
    return fetch(API+'?'+qs).then(function(r){ return r.json(); });
  }
  function apiPost(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    return fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); });
  }
  function apiUpload(file, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fd.append('file', file);
    return fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); });
  }
  function esc(s) {
    if (s == null) return '';
    var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
  }
  function tplClass(tpl) {
    var m = {cover:'sb-tpl-cover','photo-caption':'sb-tpl-photo-caption','two-photo':'sb-tpl-two-photo',story:'sb-tpl-story','cost-card':'sb-tpl-cost-card',photos:'sb-tpl-photo-grid'};
    return m[tpl] || 'sb-tpl-story';
  }
  function debounce(fn, ms) {
    var t; return function(){ clearTimeout(t); var a=arguments,c=this; t=setTimeout(function(){fn.apply(c,a);},ms); };
  }
  var savePage = debounce(function(pageId, fields) {
    fields.page_id = pageId;
    apiPost(Object.assign({action:'update_page'}, fields));
  }, 600);
  function makeSlot(pg, num, label) {
    var pk = 'photo_'+num;
    var slot = document.createElement('div');
    slot.className = 'sb-slot' + (pg[pk] ? ' filled' : '');
    slot.setAttribute('data-slot', num);
    if (pg[pk]) {
      slot.innerHTML = '<img class="sb-slot-img" src="'+esc(pg[pk])+'">';
    } else {
      slot.innerHTML = '<div class="sb-slot-empty-hint">'+esc(label)+'</div>';
    }
    var inp = document.createElement('input');
    inp.type='file'; inp.accept='image/*'; inp.style.display='none';
    slot.appendChild(inp);
    slot.addEventListener('click', function(e){ if(e.target!==inp) inp.click(); });
    inp.addEventListener('change', function(){
      if(!inp.files||!inp.files[0]) return;
      apiUpload(inp.files[0],{action:'upload_photo',page_id:pg.page_id,slot:num}).then(function(res){
        if(res.filepath){ pg[pk]=res.filepath; slot.classList.add('filled'); slot.innerHTML='<img class="sb-slot-img" src="'+esc(res.filepath)+'">'; slot.appendChild(inp); renderThumbs(); }
      });
    });
    return slot;
  }

  function makeEditable(pg, field, cls, placeholder) {
    var el = document.createElement('div');
    el.className = 'sb-editable '+(cls||'');
    el.contentEditable = 'true';
    el.setAttribute('data-placeholder', placeholder||'');
    el.textContent = pg[field]||'';
    el.addEventListener('input', function(){
      pg[field] = el.textContent;
      var upd = {}; upd[field] = el.textContent;
      savePage(pg.page_id, upd);
      renderThumbs();
    });
    return el;
  }

  function renderThumbs() {
    var list = document.getElementById('sbThumbList');
    list.innerHTML = '';
    pages.forEach(function(pg, i){
      var el = document.createElement('div');
      el.className = 'sb-thumb'+(i===activePageIdx?' active':'');
      var pv = pg.photo_1 ? '<img src="'+esc(pg.photo_1)+'" style="width:100%;height:100%;object-fit:cover;display:block">' : '';
      el.innerHTML = pv+'<div class="sb-thumb-num">'+(i+1)+'</div><div class="sb-thumb-label">'+esc(pg.title||pg.template)+'</div><div class="sb-thumb-delete" data-del="'+i+'">&times;</div>';
      el.addEventListener('click', function(e){
        if(e.target.classList.contains('sb-thumb-delete')) return;
        activePageIdx=i; renderThumbs(); renderCanvas();
      });
      list.appendChild(el);
    });
    list.querySelectorAll('.sb-thumb-delete').forEach(function(btn){
      btn.addEventListener('click', function(e){ e.stopPropagation(); deletePage(parseInt(this.getAttribute('data-del'))); });
    });
    document.querySelector('.sb-thumbbar-head').textContent = 'Pages ('+pages.length+')';
  }
  function renderCanvas() {
    var canvas = document.getElementById('sbCanvas');
    if (pages.length===0) {
      canvas.innerHTML = '<div class="sb-my-empty" style="min-height:400px"><div class="sb-my-empty-icon">&#x1F4D6;</div><div class="sb-my-empty-text">No pages yet</div><p style="color:var(--sb-text-dim);margin-bottom:16px">Click "New Page" to start building your storybook.</p></div>';
      return;
    }
    if (activePageIdx>=pages.length) activePageIdx=pages.length-1;
    var pg = pages[activePageIdx];
    var wrap = document.createElement('div'); wrap.className='sb-page-wrap';
    var page = document.createElement('div'); page.className='sb-page '+tplClass(pg.template);
    var inner = document.createElement('div'); inner.className='sb-page-inner';

    switch(pg.template){
      case 'cover':
        inner.appendChild(makeSlot(pg,1,'Tap to add cover image'));
        inner.appendChild(makeEditable(pg,'title','sb-page-title','Book title'));
        inner.appendChild(makeEditable(pg,'page_date','sb-page-subtitle','Date'));
        inner.appendChild(makeEditable(pg,'body_text','sb-hand-note','Subtitle or location'));
        break;
      case 'photo-caption':
        inner.appendChild(makeSlot(pg,1,'Tap to add photo'));
        inner.appendChild(makeEditable(pg,'title','sb-page-title','Caption title'));
        inner.appendChild(makeEditable(pg,'body_text','sb-page-body','Write something about this photo...'));
        break;
      case 'two-photo':
        var row = document.createElement('div'); row.className='sb-photo-row';
        row.appendChild(makeSlot(pg,1,'Photo 1')); row.appendChild(makeSlot(pg,2,'Photo 2'));
        inner.appendChild(row);
        inner.appendChild(makeEditable(pg,'title','sb-page-title','Caption'));
        inner.appendChild(makeEditable(pg,'body_text','sb-page-body','Describe these photos...'));
        break;
      case 'story':
        inner.appendChild(makeEditable(pg,'title','sb-page-title','Chapter title'));
        inner.appendChild(makeEditable(pg,'page_date','sb-page-subtitle','Date'));
        inner.appendChild(makeEditable(pg,'body_text','sb-page-body','Write your story here...'));
        break;
      case 'cost-card':
        var ct = document.createElement('div'); ct.className='sb-cost-title'; ct.textContent=pg.title||'Cost Breakdown';
        inner.appendChild(ct);
        var cb = document.createElement('div'); cb.className='sb-cost-rows';
        cb.innerHTML='<div style="text-align:center;color:var(--sb-text-dim);padding:20px 0;font-size:.85rem">Cost data linked from journal entry</div>';
        inner.appendChild(cb);
        break;
      case 'photos':
        var grid = document.createElement('div'); grid.className='sb-grid-2x2';
        for(var n=1;n<=4;n++) grid.appendChild(makeSlot(pg,n,'Photo '+n));
        inner.appendChild(grid);
        inner.appendChild(makeEditable(pg,'title','sb-page-title','Page title'));
        break;
    }

    page.appendChild(inner); wrap.appendChild(page);
    canvas.innerHTML=''; canvas.appendChild(wrap);
  }
  function addPage(template) {
    apiPost({action:'add_page', book_id:BOOK_ID, template:template}).then(function(res){
      if(res.page_id){
        pages.push({page_id:res.page_id,sort_order:res.sort_order,template:template,title:'',body_text:'',page_date:'',photo_1:null,photo_1_cap:null,photo_2:null,photo_2_cap:null,photo_3:null,photo_3_cap:null,photo_4:null,photo_4_cap:null,decorations:null,cost_entry_id:null});
        activePageIdx=pages.length-1; renderThumbs(); renderCanvas();
      }
    });
  }

  function deletePage(idx) {
    var pg=pages[idx]; if(!pg) return;
    if(!confirm('Delete this page?')) return;
    apiPost({action:'delete_page',page_id:pg.page_id}).then(function(){
      pages.splice(idx,1);
      if(activePageIdx>=pages.length) activePageIdx=Math.max(0,pages.length-1);
      renderThumbs(); renderCanvas();
    });
  }

  function deleteBook() {
    if(!confirm('Delete this entire storybook? This cannot be undone.')) return;
    apiPost({action:'delete_book',book_id:BOOK_ID}).then(function(){ window.location.href='my-storybooks.php'; });
  }
  // Template picker
  var tplOv = document.getElementById('sbTplOverlay');
  document.getElementById('sbAddPageBtn').addEventListener('click', function(){
    tplOv.style.display = tplOv.style.display==='none'?'flex':'none';
  });
  tplOv.addEventListener('click', function(e){ if(e.target===tplOv) tplOv.style.display='none'; });
  tplOv.querySelectorAll('.sb-template-card').forEach(function(card){
    card.addEventListener('click', function(){ tplOv.style.display='none'; addPage(this.getAttribute('data-template')); });
  });

  // Theme picker
  var thOv = document.getElementById('sbThemeOverlay');
  var thGrid = document.getElementById('sbThemeGrid');
  document.getElementById('sbThemeBtn').addEventListener('click', function(){
    thOv.style.display='flex';
    if(thGrid.children.length===0) loadThemes();
  });
  thOv.addEventListener('click', function(e){ if(e.target===thOv) thOv.style.display='none'; });

  function loadThemes() {
    apiGet({action:'get_themes'}).then(function(res){
      if(!res.themes) return;
      res.themes.forEach(function(t){
        var card = document.createElement('div');
        card.className = 'sb-theme-card'+(t.theme_id==='<?= $theme ?>'?' active':'');
        var pal=[]; try{pal=JSON.parse(t.palette||'[]');}catch(e){}
        var sw = pal.slice(0,4).map(function(c){return '<span style="background:'+esc(c)+'"></span>';}).join('');
        card.innerHTML = '<div class="sb-theme-name">'+esc(t.label||t.theme_id)+'</div><div class="sb-theme-swatch">'+sw+'</div>'+(t.description?'<div style="font-size:.72rem;color:var(--sb-text-dim);margin-top:6px">'+esc(t.description)+'</div>':'');
        card.addEventListener('click', function(){
          thGrid.querySelectorAll('.sb-theme-card').forEach(function(c){c.classList.remove('active');});
          card.classList.add('active');
          document.querySelector('.sb-shell').setAttribute('data-sb-theme', t.theme_id);
          apiPost({action:'update_book',book_id:BOOK_ID,theme:t.theme_id});
        });
        thGrid.appendChild(card);
      });
    });
  }
  // Emoji picker
  var emBtn = document.getElementById('sbEmojiBtn');
  var emPk = document.getElementById('sbEmojiPicker');
  emBtn.addEventListener('click', function(e){
    e.stopPropagation();
    if(emPk.style.display==='grid'){ emPk.style.display='none'; return; }
    emPk.innerHTML='';
    var emojis = EMOJI_SET && EMOJI_SET.length ? EMOJI_SET : ['✈️','🌍','📸','🗺️','🎒','🌅','🏖️','⛰️','🚂','🍕','☀️','🌊','🧭','🏛️','🎨','🌺'];
    emojis.forEach(function(em){
      var b = document.createElement('button'); b.className='sb-emoji-btn'; b.type='button'; b.textContent=em;
      b.addEventListener('click', function(){
        var pg = pages[activePageIdx];
        if(!pg) return;
        var cur = pg.body_text||'';
        pg.body_text = cur + em;
        savePage(pg.page_id, {body_text:pg.body_text});
        renderCanvas();
      });
      emPk.appendChild(b);
    });
    emPk.style.display='grid';
    var r = emBtn.getBoundingClientRect();
    emPk.style.top = r.bottom + 4 + 'px';
    emPk.style.right = (window.innerWidth - r.right) + 'px';
  });
  document.addEventListener('click', function(e){ if(!emPk.contains(e.target)&&e.target!==emBtn) emPk.style.display='none'; });

  // Share dialog
  var shOv = document.getElementById('sbShareOverlay');
  function shareLinkFor(vis, token) {
    var origin = window.location.origin+'/travel_journel/';
    if (vis === 'public') return origin+'storybook-view.php?id='+BOOK_ID;
    if (vis === 'link' && token) return origin+'storybook-view.php?token='+token;
    return '';
  }
  function updateShareUI() {
    var wrap = document.getElementById('sbShareLinkWrap');
    var link = shareLinkFor(BOOK_VISIBILITY, BOOK_SHARE_TOKEN);
    document.querySelectorAll('input[name=sbVisibility]').forEach(function(r){ r.checked = (r.value === BOOK_VISIBILITY); });
    if (link) { wrap.style.display='flex'; document.getElementById('sbShareLink').value = link; }
    else { wrap.style.display='none'; }
  }
  document.getElementById('sbShareBtn').addEventListener('click', function(){
    shOv.style.display='flex';
    updateShareUI();
  });
  shOv.addEventListener('click', function(e){ if(e.target===shOv) shOv.style.display='none'; });
  document.getElementById('sbShareCloseBtn').addEventListener('click', function(){ shOv.style.display='none'; });
  document.getElementById('sbShareCopyBtn').addEventListener('click', function(){
    var inp = document.getElementById('sbShareLink'); inp.select();
    document.execCommand('copy');
    this.textContent='Copied!';
    var self=this; setTimeout(function(){self.textContent='Copy';},1500);
  });
  document.querySelectorAll('input[name=sbVisibility]').forEach(function(r){
    r.addEventListener('change', function(){
      apiPost({action:'update_book', book_id:BOOK_ID, visibility:this.value}).then(function(res){
        if (res.error) { alert(res.error); return; }
        BOOK_VISIBILITY = res.visibility;
        BOOK_SHARE_TOKEN = res.share_token;
        updateShareUI();
      });
    });
  });

  // Page order
  var pageOrderSel = document.getElementById('sbPageOrderSelect');
  if (pageOrderSel) {
    pageOrderSel.addEventListener('change', function(){
      apiPost({action:'update_book', book_id:BOOK_ID, page_order:this.value});
    });
  }

  // Delete book
  document.getElementById('sbDeleteBookBtn').addEventListener('click', deleteBook);

  // Initial render
  renderThumbs();
  renderCanvas();
})();
</script>
</body>
</html>
