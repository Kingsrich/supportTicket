<?php

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.gc_maxlifetime', 604800);

    session_set_cookie_params([
        'lifetime' => 604800,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}