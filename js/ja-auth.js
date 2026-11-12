/* Account pages: show/hide password, the password checklist, and inline field errors.
   Every form still works without this file (the server checks everything again). */
(function () {
  'use strict';
  var SYM = /[!@#$%^&*()\-_=+{};:,<.>]/;
  var ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

  /* show / hide password */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-pw-toggle]'); if (!b) return;
    var inp = document.getElementById(b.getAttribute('data-pw-toggle')); if (!inp) return;
    var show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
    b.setAttribute('aria-pressed', show ? 'true' : 'false'); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    inp.focus();
  });

  /* password checklist + strength */
  function rules(v) { return { len: v.length >= 8, up: /[A-Z]/.test(v), lo: /[a-z]/.test(v), num: /[0-9]/.test(v), sym: SYM.test(v) }; }
  function met(v) { var r = rules(v), n = 0; for (var k in r) if (r[k]) n++; return n; }
  [].forEach.call(document.querySelectorAll('[data-pw-meter]'), function (m) {
    var inp = document.getElementById(m.getAttribute('data-pw-meter')); if (!inp) return;
    var bars = m.querySelectorAll('.acct-bars i'), label = m.querySelector('[data-pw-label]'), items = m.querySelectorAll('.acct-rules li');
    function paint() {
      var v = inp.value, r = rules(v), n = met(v);
      [].forEach.call(items, function (li) {
        var ok = r[li.getAttribute('data-r')]; li.classList.toggle('ok', ok);
        var s = li.querySelector('.ja-sr'); if (!s) { s = document.createElement('span'); s.className = 'ja-sr'; li.appendChild(s); }
        s.textContent = ok ? ' (done)' : ' (not yet)';
      });
      [].forEach.call(bars, function (b, i) { b.className = i < n ? (n === 5 ? 'on full' : 'on') : ''; });
      label.textContent = !v ? 'not started' : n === 5 ? 'meets every requirement' : n >= 3 ? 'getting there' : 'weak';
      m.setAttribute('data-level', !v ? 0 : n);
    }
    inp.addEventListener('input', paint); paint();
  });

  /* inline validation */
  function errBox(inp) { return document.getElementById(inp.id + '-err'); }
  function setErr(inp, msg) {
    var box = errBox(inp), wrap = inp.closest('.acct-field');
    if (!box) return;
    if (msg) { box.innerHTML = ICON + '<span></span>'; box.lastChild.textContent = msg; box.hidden = false; inp.setAttribute('aria-invalid', 'true'); wrap && wrap.classList.add('has-error'); }
    else { box.hidden = true; box.innerHTML = ''; inp.removeAttribute('aria-invalid'); wrap && wrap.classList.remove('has-error'); }
  }
  function check(inp) {
    var rule = inp.getAttribute('data-rule'); if (!rule) return '';
    var name = inp.getAttribute('data-label') || 'this field', v = inp.value.trim();
    if (rule === 'password') {
      if (!inp.value) return 'Enter a ' + name + '.';
      return met(inp.value) === 5 ? '' : 'Not strong enough yet. Add what is missing from the checklist below.';
    }
    if (!v) return 'Enter your ' + name + '.';
    if (rule === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter an email address like name@example.com.';
    return '';
  }
  [].forEach.call(document.querySelectorAll('form[data-acct-validate]'), function (f) {
    f.addEventListener('submit', function (e) {
      var first = null;
      [].forEach.call(f.querySelectorAll('input[data-rule]'), function (inp) {
        var m = check(inp); setErr(inp, m); if (m && !first) first = inp;
      });
      if (first) { e.preventDefault(); first.focus(); }
    });
    [].forEach.call(f.querySelectorAll('input[data-rule]'), function (inp) {
      inp.addEventListener('input', function () { if (inp.getAttribute('aria-invalid') === 'true') setErr(inp, check(inp)); });
      if (inp.type === 'email') inp.addEventListener('blur', function () { if (inp.value.trim()) setErr(inp, check(inp)); });
    });
  });

  /* a server-side error summary gets focus so screen readers announce it */
  var sum = document.getElementById('form-err');
  if (sum) sum.focus({ preventScroll: false });
})();
