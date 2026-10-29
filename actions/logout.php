<?php
session_start();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'] ?: '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
}

session_destroy();
header("Location: ../pages/login.php");
exit;