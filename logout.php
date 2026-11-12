<?php
/** Log out. POST + CSRF only, so a stray link or image tag on another site cannot sign anyone out. */
require_once __DIR__ . '/ja-lib.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Use the Log out button.';
    exit;
}
ja_csrf_enforce();
session_unset();
session_destroy();
header('location:login.php');
exit;
