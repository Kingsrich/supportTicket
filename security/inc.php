<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.example.php to config.php and configure it.');
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name'] ?? 'PHPSMSESSID');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true, 'secure' => $secure, 'samesite' => 'Strict', 'path' => '/'
    ]);
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(
        (string)$config['db_host'],
        (string)$config['db_user'],
        (string)$config['db_pass'],
        (string)$config['db_name']
    );
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    error_log('PHP Security Monitor database connection failed: ' . $e->getMessage());
    exit('Database connection error.');
}

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_check(): void {
    $given = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $given)) {
        http_response_code(403); exit('Invalid security token. Reload and try again.');
    }
}
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php'); exit;
    }
}
function get_setting(mysqli $db, string $key, string $default = ''): string {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $stmt->bind_param('s', $key); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ? (string)$row['setting_value'] : $default;
}
function set_setting(mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value); $stmt->execute(); $stmt->close();
}
function valid_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}
function severity_rank(string $s): int {
    return ['low'=>1,'medium'=>2,'high'=>3,'critical'=>4][$s] ?? 1;
}
