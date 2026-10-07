<?php
/**
 * JourneyAI — tiny SMTP client (no PHPMailer needed). Settings come from the admin panel
 * (Admin > Email settings), stored in the `settings` table.
 *
 *   $r = ja_send_mail('to@x.com', 'Subject', '<p>HTML body</p>', 'plain text body');
 *   // $r = ['ok'=>bool, 'status'=>'sent'|'failed'|'not_configured', 'error'=>string]
 *
 * Supports: implicit SSL (port 465), STARTTLS (587), or none; AUTH LOGIN / PLAIN.
 * Every attempt is written to `email_log`.
 */
require_once __DIR__ . '/ja-lib.php';

function ja_smtp_configured() {
    return ja_setting('smtp_enabled') === '1' && ja_setting('smtp_host') !== '' && ja_setting('smtp_from_email') !== '';
}

function ja_mail_log($to, $subject, $status, $error = '') {
    global $conn; ja_db();
    $st = mysqli_prepare($conn, "INSERT INTO email_log (to_addr, subject, status, error) VALUES (?,?,?,?)");
    $err = mb_substr($error, 0, 480);
    mysqli_stmt_bind_param($st, 'ssss', $to, $subject, $status, $err);
    @mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

function ja_send_mail($to, $subject, $html, $alt = '') {
    if (!ja_smtp_configured()) {
        ja_mail_log($to, $subject, 'not_configured', 'SMTP is not configured (Admin > Email settings).');
        return ['ok' => false, 'status' => 'not_configured', 'error' => 'Email is not set up yet.'];
    }
    $host = ja_setting('smtp_host');
    $port = (int)ja_setting('smtp_port', '587');
    $enc  = ja_setting('smtp_encryption', 'tls');        // tls = STARTTLS, ssl = implicit, none
    $user = ja_setting('smtp_user');
    $pass = ja_setting('smtp_pass');
    $from = ja_setting('smtp_from_email');
    $fromName = ja_setting('smtp_from_name', 'JourneyAI');

    try {
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new Exception("Cannot connect to $host:$port ($errstr)");
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function ($c, $expect) use ($fp, $read) {
            if ($c !== null) fwrite($fp, $c . "\r\n");
            $resp = $read();
            if (strpos($resp, (string)$expect) !== 0) throw new Exception(trim($resp) ?: 'No response from SMTP server');
            return $resp;
        };

        $cmd(null, 220);
        $hello = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $cmd("EHLO $hello", 250);
        if ($enc === 'tls') {
            $cmd('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('TLS handshake failed');
            $cmd("EHLO $hello", 250);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode($user), 334);
            $cmd(base64_encode($pass), 235);
        }
        $cmd("MAIL FROM:<$from>", 250);
        $cmd("RCPT TO:<$to>", 250);
        $cmd('DATA', 354);

        $boundary = 'ja_' . bin2hex(random_bytes(8));
        $alt = $alt !== '' ? $alt : trim(html_entity_decode(strip_tags($html)));
        $enc64 = function ($s) { return 'Subject: =?UTF-8?B?' . base64_encode($s) . '?='; };
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
            "To: <$to>",
            $enc64($subject),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $hello . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($alt))
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$boundary--\r\n";
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg);          // dot-stuffing
        fwrite($fp, $msg . "\r\n.\r\n");
        $resp = $read();
        if (strpos($resp, '250') !== 0) throw new Exception(trim($resp));
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        ja_mail_log($to, $subject, 'sent');
        return ['ok' => true, 'status' => 'sent', 'error' => ''];
    } catch (Throwable $e) {
        if (!empty($fp) && is_resource($fp)) @fclose($fp);
        ja_mail_log($to, $subject, 'failed', $e->getMessage());
        return ['ok' => false, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}
