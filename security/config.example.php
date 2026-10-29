<?php
declare(strict_types=1);

/*
 * Copy to config.php and update these values.
 * Use a unique, long random app_key (at least 32 random characters).
 */
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'php_security_monitor',
    'db_user' => 'security_monitor_user',
    'db_pass' => 'CHANGE_THIS_DATABASE_PASSWORD',
    'app_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET_VALUE',
    'timezone' => 'Africa/Lagos',
    'session_name' => 'PHPSMSESSID',
    // Leave blank to disable email alerts until configured.
    'alert_email' => '',
    'mail_from' => '',
    'alert_min_severity' => 'medium',
    'rate_limit_window_seconds' => 60,
    'rate_limit_requests' => 120,
    'alert_cooldown_minutes' => 15,
];
