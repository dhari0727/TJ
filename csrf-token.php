<?php
/** Returns this session's CSRF token to same-origin scripts (browsers block other sites from reading it). */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
echo json_encode(['token' => $_SESSION['csrf']]);
