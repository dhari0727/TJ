<?php
/**
 * JourneyAI — password reset email.
 * Uses the built-in SMTP client (ja-mailer.php); the SMTP account is configured by an admin
 * under Admin > Email settings. No PHPMailer or environment variables needed.
 */
require_once __DIR__ . '/ja-mailer.php';

/** Build the reset URL for a token (same folder as the current script). */
function ja_reset_url($token) {
    return ja_base_url() . '/reset-password.php?token=' . urlencode($token);
}

/**
 * Send the reset email. Returns the ja_send_mail() result array
 * ['ok','status','error'] plus 'url' (the reset link) for admin/dev display.
 */
function send_reset_email($userEmail, $token) {
    $url = ja_reset_url($token);
    $site = ja_setting('site_name', 'JourneyAI');
    $html = "<p>We received a request to reset your $site password.</p>"
          . "<p><a href=\"" . htmlspecialchars($url) . "\">Choose a new password</a></p>"
          . "<p>This link expires in 1 hour. If you didn't ask for this, you can ignore this email.</p>";
    $res = ja_send_mail($userEmail, "$site: reset your password", $html,
        "Reset your $site password: $url (expires in 1 hour)");
    $res['url'] = $url;
    return $res;
}
