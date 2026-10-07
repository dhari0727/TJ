/* Pin / unpin buttons: <button data-pin-kind="media|book|journal" data-pin-id="12" data-pinned="0|1">.
   Needs window.JA_CSRF (set by ja-head.php for signed-in users). Max 3 pins per kind (enforced server-side). */
(function () {
  function label(btn) {
    var on = btn.getAttribute('data-pinned') === '1';
    btn.textContent = on ? 'Unpin' : 'Pin';
    btn.classList.toggle('is-pinned', on);
    var card = btn.closest('[data-pin-card]');
    if (card) card.classList.toggle('pinned', on);
  }
  document.querySelectorAll('[data-pin-kind]').forEach(label);
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pin-kind]');
    if (!btn) return;
    e.preventDefault();
    var want = btn.getAttribute('data-pinned') === '1' ? 0 : 1;
    var fd = new FormData();
    fd.append('action', 'pin'); fd.append('kind', btn.getAttribute('data-pin-kind')); fd.append('id', btn.getAttribute('data-pin-id'));
    fd.append('pin', want); fd.append('csrf', window.JA_CSRF || '');
    btn.disabled = true;
    fetch('engage.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false;
      if (d.error) { alert(d.error); return; }
      btn.setAttribute('data-pinned', d.pinned ? '1' : '0');
      label(btn);
    }).catch(function () { btn.disabled = false; });
  });
})();
