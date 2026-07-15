/* ============================================================================
   JourneyAI — floating chat widget logic (vanilla, dependency-free).
   Separate from js/journeyai.js. Include with: <script src="js/ja-chat.js" defer>
   POSTs {message, history} to chat.php and renders {reply, cards}.
   ============================================================================ */
(function () {
  "use strict";

  var root = document.getElementById("jaChat");
  if (!root) return;

  var fab      = document.getElementById("jaChatFab");
  var panel    = document.getElementById("jaChatPanel");
  var closeBtn = document.getElementById("jaChatClose");
  var msgsEl   = document.getElementById("jaChatMsgs");
  var form     = document.getElementById("jaChatForm");
  var input    = document.getElementById("jaChatText");
  var sendBtn  = form ? form.querySelector(".ja-chat-send") : null;
  var endpoint = root.getAttribute("data-endpoint") || "chat.php";

  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var K_OPEN = "jaChat.open";
  var K_HIST = "jaChat.history";

  // history is the wire format we send: [{role:'user'|'bot', text}]
  var history = loadHistory();
  var busy = false;

  var SUGGESTIONS = [
    "Places to visit near me",
    "I have 1 day, where to go?",
    "Ice cream places nearby",
    "Plan a 3-day trip on a budget"
  ];

  /* ---------- storage ---------- */
  function loadHistory() {
    try { return JSON.parse(sessionStorage.getItem(K_HIST)) || []; }
    catch (e) { return []; }
  }
  function saveHistory() {
    try { sessionStorage.setItem(K_HIST, JSON.stringify(history)); } catch (e) {}
  }
  function saveOpen(v) {
    try { sessionStorage.setItem(K_OPEN, v ? "1" : "0"); } catch (e) {}
  }

  /* ---------- open / close ---------- */
  function open() {
    root.classList.add("open");
    panel.hidden = false;
    fab.setAttribute("aria-expanded", "true");
    saveOpen(true);
    scrollBottom();
    setTimeout(function () { if (input) input.focus(); }, reduce ? 0 : 260);
  }
  function close() {
    root.classList.remove("open");
    fab.setAttribute("aria-expanded", "false");
    saveOpen(false);
    // keep it in the DOM but hide after the transition so it's not focusable
    setTimeout(function () { if (!root.classList.contains("open")) panel.hidden = true; }, reduce ? 0 : 300);
  }
  function toggle() { root.classList.contains("open") ? close() : open(); }

  /* ---------- safe text -> html ---------- */
  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  // Escape first, THEN apply a tiny markdown subset on the escaped string.
  function renderMarkdown(text) {
    var html = escapeHtml(text);
    // links [label](url) — only http(s)/relative, no javascript:
    html = html.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
      if (/^\s*javascript:/i.test(url)) return label;
      var safe = url.replace(/"/g, "&quot;");
      return '<a href="' + safe + '" target="_blank" rel="noopener noreferrer">' + label + "</a>";
    });
    // bold **text**
    html = html.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
    // newlines
    html = html.replace(/\n/g, "<br>");
    return html;
  }

  function scrollBottom() {
    if (msgsEl) msgsEl.scrollTop = msgsEl.scrollHeight;
  }

  var AV_BOT = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>';
  var AV_USER = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

  /* ---------- render a message ---------- */
  function addMessage(role, text, cards) {
    var wrap = document.createElement("div");
    wrap.className = "ja-msg ja-msg-" + (role === "user" ? "user" : "bot");

    var av = document.createElement("div");
    av.className = "ja-msg-av";
    av.innerHTML = role === "user" ? AV_USER : AV_BOT;

    var col = document.createElement("div");

    var bubble = document.createElement("div");
    bubble.className = "ja-bubble";
    bubble.innerHTML = renderMarkdown(text);
    col.appendChild(bubble);

    if (cards && cards.length) col.appendChild(buildCards(cards));

    wrap.appendChild(av);
    wrap.appendChild(col);
    msgsEl.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  /* ---------- structured cards ---------- */
  function buildCards(cards) {
    var box = document.createElement("div");
    box.className = "ja-chat-cards";
    cards.forEach(function (c) {
      if (!c || typeof c !== "object") return;
      var isLink = c.url && !/^\s*javascript:/i.test(c.url);
      var el = document.createElement(isLink ? "a" : "div");
      el.className = "ja-cc";
      if (isLink) {
        el.href = c.url;
        el.target = "_blank";
        el.rel = "noopener noreferrer";
      }

      // media (image or video)
      if (c.image) {
        if (c.kind === "video") {
          var v = document.createElement("video");
          v.src = c.image;
          v.controls = true;
          v.preload = "metadata";
          v.className = "ja-cc-media";
          el.appendChild(v);
        } else {
          var img = document.createElement("img");
          img.src = c.image;
          img.alt = c.title || "";
          img.loading = "lazy";
          img.className = "ja-cc-media";
          el.appendChild(img);
        }
      }

      var body = document.createElement("div");
      body.className = "ja-cc-body";

      if (c.title) {
        var t = document.createElement("div");
        t.className = "ja-cc-title";
        t.textContent = c.title;
        body.appendChild(t);
      }
      if (c.subtitle) {
        var s = document.createElement("div");
        s.className = "ja-cc-sub";
        s.textContent = c.subtitle;
        body.appendChild(s);
      }
      // meta: distance for places, or any short label
      var meta = c.meta || c.dist;
      if (meta) {
        var m = document.createElement("span");
        m.className = "ja-cc-meta";
        m.textContent = meta;
        body.appendChild(m);
      }

      el.appendChild(body);
      box.appendChild(el);
    });
    return box;
  }

  /* ---------- typing indicator ---------- */
  function showTyping() {
    var wrap = document.createElement("div");
    wrap.className = "ja-msg ja-msg-bot";
    wrap.setAttribute("data-typing", "1");
    wrap.innerHTML =
      '<div class="ja-msg-av">' + AV_BOT + '</div>' +
      '<div class="ja-bubble ja-typing"><span></span><span></span><span></span></div>';
    msgsEl.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  /* ---------- greeting + chips (not persisted as history) ---------- */
  function renderGreeting() {
    addMessage("bot",
      "Hi! I'm your **JourneyAI** assistant. Ask me about places to visit, day trips, food spots, or planning a budget-friendly journey.");
    var wrap = document.createElement("div");
    wrap.className = "ja-msg ja-msg-bot";
    var col = document.createElement("div");
    var chips = document.createElement("div");
    chips.className = "ja-chat-chips";
    SUGGESTIONS.forEach(function (label) {
      var chip = document.createElement("button");
      chip.type = "button";
      chip.className = "ja-chat-chip";
      chip.textContent = label;
      chip.addEventListener("click", function () {
        if (busy) return;
        send(label);
      });
      chips.appendChild(chip);
    });
    col.appendChild(chips);
    // spacer avatar to align with bot column
    var av = document.createElement("div");
    av.className = "ja-msg-av";
    av.style.visibility = "hidden";
    wrap.appendChild(av);
    wrap.appendChild(col);
    msgsEl.appendChild(wrap);
    scrollBottom();
  }

  /* ---------- restore prior conversation ---------- */
  function renderHistory() {
    msgsEl.innerHTML = "";
    if (!history.length) { renderGreeting(); return; }
    history.forEach(function (m) {
      addMessage(m.role, m.text, m.cards);
    });
  }

  /* ---------- send ---------- */
  function send(text) {
    text = (text || "").trim();
    if (!text || busy) return;
    busy = true;
    if (sendBtn) sendBtn.disabled = true;
    input.value = "";

    // user bubble immediately
    addMessage("user", text);
    history.push({ role: "user", text: text });
    saveHistory();

    var typing = showTyping();

    var priorHistory = history.slice(0, -1); // everything before this message

    fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ message: text, history: priorHistory })
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
        var reply = (data && data.reply) ? data.reply : "Sorry, I didn't catch that. Could you rephrase?";
        var cards = (data && Array.isArray(data.cards)) ? data.cards : null;
        addMessage("bot", reply, cards);
        history.push({ role: "bot", text: reply, cards: cards || undefined });
        saveHistory();
      })
      .catch(function () {
        if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
        addMessage("bot",
          "I'm having trouble reaching the server right now. Please check your connection and try again in a moment.");
      })
      .then(function () {
        busy = false;
        if (sendBtn) sendBtn.disabled = false;
        if (input) input.focus();
      });
  }

  /* ---------- wire up ---------- */
  fab.addEventListener("click", toggle);
  if (closeBtn) closeBtn.addEventListener("click", close);
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      send(input.value);
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && root.classList.contains("open")) close();
  });

  renderHistory();

  // restore open state within the session
  try {
    if (sessionStorage.getItem(K_OPEN) === "1") open();
  } catch (e) {}
})();
