<?php
// SECURITY: replace credentials before use. This starter config is generated for local setup only.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'php_security_monitor',
    'db_user' => 'root',
    'db_pass' => '',
    'app_key' => 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-AT-LEAST-32-CHARS',
    'timezone' => 'Africa/Lagos',
    'session_name' => 'PHPSMSESSID',
    'alert_email' => '',
    'mail_from' => '',
    'alert_min_severity' => 'medium',
    'rate_limit_window_seconds' => 60,
    'rate_limit_requests' => 120,
    'alert_cooldown_minutes' => 15,
];
