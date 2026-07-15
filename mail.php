<?php
/**
 * JourneyAI — password reset email helper.
 * Included by forgot-pswd.php; call send_reset_email($email, $token) to
 * deliver a one-time reset link (see reset-password.php for the consumer).
 */
$ja_phpmailer_path = __DIR__ . '/PHPMailer-master/PHPMailerAutoload.php';
if (is_file($ja_phpmailer_path)) {
    require_once $ja_phpmailer_path;
}

function send_reset_email($userEmail, $token) {
    if (!class_exists('PHPMailer')) {
        return false; // PHPMailer vendor library not installed in this checkout
    }
    $mail = new PHPMailer;

    // SMTP credentials come from environment variables — never hardcode secrets.
    // Set JOURNEYAI_SMTP_USER / JOURNEYAI_SMTP_PASS / JOURNEYAI_SMTP_FROM before use.
    $smtpUser = getenv('JOURNEYAI_SMTP_USER') ?: '';
    $smtpPass = getenv('JOURNEYAI_SMTP_PASS') ?: '';
    $smtpFrom = getenv('JOURNEYAI_SMTP_FROM') ?: $smtpUser;

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPSecure = 'tls';
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;

    $mail->setFrom($smtpFrom, 'JourneyAI');
    $mail->addAddress($userEmail);
    $mail->addReplyTo($smtpFrom, 'JourneyAI');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $resetUrl = "$scheme://$host$base/reset-password.php?token=" . urlencode($token);

    $mail->isHTML(true);
    $mail->Subject = 'JourneyAI — reset your password';
    $mail->Body = "We received a request to reset your JourneyAI password.<br><br>"
        . "<a href=\"$resetUrl\">Click here to choose a new password</a><br><br>"
        . "This link expires in 1 hour. If you didn't request this, you can ignore this email.";
    $mail->AltBody = "Reset your JourneyAI password: $resetUrl (expires in 1 hour)";

    return $mail->send();
}
