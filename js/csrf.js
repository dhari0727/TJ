/* Adds the CSRF token to every same-origin POST: fetch() calls get an X-CSRF-Token header, <form method=post> gets a hidden field. */
(function () {
  var tok = window.JA_CSRF || null, pending = null;
  function getToken() {
    if (tok) return Promise.resolve(tok);
    if (!pending) pending = fetch('csrf-token.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (d) { tok = d.token || ''; return tok; }).catch(function () { return ''; });
    return pending;
  }
  var nativeFetch = window.fetch;
  window.fetch = function (input, init) {
    init = init || {};
    var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
    var url = typeof input === 'string' ? input : ((input && input.url) || '');
    var sameOrigin = !/^https?:/i.test(url) || url.indexOf(location.origin) === 0;
    if (method !== 'POST' || !sameOrigin || url.indexOf('csrf-token.php') !== -1) return nativeFetch(input, init);
    return getToken().then(function (t) {
      var h = new Headers(init.headers || (input && input.headers) || {});
      if (t) h.set('X-CSRF-Token', t);
      init.headers = h;
      return nativeFetch(input, init);
    });
  };
  function addField(form, t) {
    var i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf'; i.value = t; form.appendChild(i);
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || String(f.method).toLowerCase() !== 'post' || f.querySelector('input[name=csrf]')) return;
    if (f.action && /^https?:/i.test(f.action) && f.action.indexOf(location.origin) !== 0) return;
    if (tok) { addField(f, tok); return; }
    e.preventDefault();
    getToken().then(function (t) { addField(f, t); HTMLFormElement.prototype.submit.call(f); });
  }, true);
})();
