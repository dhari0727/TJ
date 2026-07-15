<?php
/**
 * JourneyAI — floating chat widget partial.
 * Include near the END of a page (before ja-footer.php / </body>), then load the
 * script once:  <script src="js/ja-chat.js" defer></script>
 *
 * ja-chat.js is a SEPARATE file from js/journeyai.js (loaded by ja-head.php).
 * The widget POSTs to chat.php as JSON {message, history} and renders {reply, cards}.
 */
require_once __DIR__ . '/ja-icons.php';
?>
<!-- JourneyAI chat widget -->
<div class="ja-chat" id="jaChat" data-endpoint="chat.php">

  <!-- Floating launcher button -->
  <button class="ja-chat-fab" id="jaChatFab" type="button"
          aria-label="Open JourneyAI Assistant" aria-expanded="false" aria-controls="jaChatPanel">
    <span class="ja-chat-fab-open"><?= ja_icon('sparkle', 26) ?></span>
    <span class="ja-chat-fab-close" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ja-ic">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </span>
  </button>

  <!-- Chat panel -->
  <section class="ja-chat-panel" id="jaChatPanel" role="dialog" aria-modal="false"
           aria-label="JourneyAI Assistant" hidden>
    <header class="ja-chat-head">
      <span class="ja-chat-avatar"><?= ja_icon('compass', 20) ?></span>
      <div class="ja-chat-title">
        <strong>JourneyAI Assistant</strong>
        <small>AI travel companion</small>
      </div>
      <button class="ja-chat-x" id="jaChatClose" type="button" aria-label="Close chat">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ja-ic">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </header>

    <div class="ja-chat-msgs" id="jaChatMsgs" aria-live="polite"></div>

    <form class="ja-chat-input" id="jaChatForm" autocomplete="off">
      <input type="text" id="jaChatText" class="ja-chat-field" name="message"
             placeholder="Ask about places, trips, food…" aria-label="Type your message">
      <button type="submit" class="ja-chat-send" aria-label="Send message">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ja-ic">
          <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </form>
  </section>
</div>
