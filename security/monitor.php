<?php
declare(strict_types=1);

require_once __DIR__ . '/monitor_lib.php';

/* Monitor and enforce only for requests that include/run this hook. */
sm_enforce_block();
sm_log_hit();
sm_check_rate();

$uri = sm_redact_path((string)($_SERVER['REQUEST_URI'] ?? '/'));
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
foreach (sm_detect($uri, $ua, $method) as [$type, $severity, $details]) {
    sm_record($type, $severity, $details);
}

/* Optional integration point for your login handler:
 * call security_monitor_login_result(false) after a failed login
 * and security_monitor_login_result(true) after a successful login.
 */
if (!function_exists('security_monitor_login_result')) {
    function security_monitor_login_result(bool $success): void {
        sm_record($success ? 'login_success' : 'login_failure', $success ? 'low' : 'medium',
            $success ? 'Application reported successful login' : 'Application reported failed login');
    }
}
