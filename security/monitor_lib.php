<?php
declare(strict_types=1);

/*
 * Request monitoring library. Include monitor.php from your application's
 * common bootstrap or configure as auto_prepend_file.
 */
function sm_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $p = __DIR__ . '/config.php';
        $cfg = is_file($p) ? require $p : [];
    }
    return $cfg;
}
function sm_db(): ?mysqli {
    static $db = null;
    if ($db instanceof mysqli) return $db;
    $c = sm_config();
    if (!$c || empty($c['db_name'])) return null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
        $db->set_charset('utf8mb4');
        return $db;
    } catch (Throwable $e) {
        error_log('Security monitor unavailable: ' . $e->getMessage());
        return null;
    }
}
function sm_setting(mysqli $db, string $key, string $default): string {
    $s = $db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $s->bind_param('s', $key); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ? (string)$r['setting_value'] : $default;
}
function sm_redact_path(string $uri): string {
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $sensitive = '/pass|password|token|secret|api.?key|authorization|session|csrf|email/i';
    foreach ($query as $k => $v) {
        if (preg_match($sensitive, (string)$k)) $query[$k] = '[REDACTED]';
        elseif (is_array($v)) $query[$k] = '[ARRAY]';
        else $query[$k] = mb_substr((string)$v, 0, 200);
    }
    $q = http_build_query($query);
    return mb_substr($path . ($q !== '' ? '?' . $q : ''), 0, 2048);
}
function sm_detect(string $path, string $ua, string $method): array {
    $signals = [];
    $decoded = strtolower(rawurldecode($path));
    if (preg_match('/(\.\.\/|\.\.\\\\|%2e%2e|\/etc\/passwd|win\.ini)/i', $decoded)) {
        $signals[] = ['path_traversal','high','Path traversal or sensitive system path pattern'];
    }
    if (preg_match('/(\bunion\s+select\b|\bselect\b.+\bfrom\b|sleep\s*\(|benchmark\s*\(|\bor\b\s+[\x27"]?1[\x27"]?\s*=\s*[\x27"]?1|information_schema|load_file\s*\()/i', $decoded)) {
        $signals[] = ['sql_injection_pattern','high','SQL injection-like pattern in URL/query'];
    }
    if (preg_match('/(^|\/)(\.env|\.git|\.svn|composer\.(json|lock)|wp-config\.php|config\.(php|bak)|backup\.(zip|sql)|dump\.sql)(\/|$)/i', $decoded)) {
        $signals[] = ['sensitive_file_probe','high','Request for a sensitive file or backup'];
    }
    if (preg_match('#/(wp-admin|wp-login\.php|phpmyadmin|pma|\.well-known/.*\.php|vendor/phpunit|cgi-bin|actuator/env)(/|$)#i', $decoded)) {
        $signals[] = ['scanner_probe','medium','Common scanner or administration path probe'];
    }
    if (preg_match('/(sqlmap|nikto|nmap|masscan|acunetix|nessus|zgrab)/i', $ua)) {
        $signals[] = ['scanner_user_agent','medium','Known scanner-like user agent string'];
    }
    if (strtoupper($method) === 'TRACE') {
        $signals[] = ['unusual_http_method','medium','TRACE method observed'];
    }
    return $signals;
}
function sm_send_alert(mysqli $db, int $eventId, string $severity, string $type, string $ip, string $path): void {
    $recipient = trim(sm_setting($db, 'alert_email', (string)(sm_config()['alert_email'] ?? '')));
    $from = trim(sm_setting($db, 'mail_from', (string)(sm_config()['mail_from'] ?? '')));
    $min = sm_setting($db, 'alert_min_severity', 'medium');
    $cooldown = max(0, (int)sm_setting($db, 'alert_cooldown_minutes', '15'));
    $rank = ['low'=>1,'medium'=>2,'high'=>3,'critical'=>4];
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || ($rank[$severity] ?? 1) < ($rank[$min] ?? 2)) {
        $st = $db->prepare("INSERT INTO alert_log(event_id,recipient,subject,status,response_text) VALUES(?,?,'Security alert','skipped','Alert recipient unset or severity below threshold')");
        $st->bind_param('is', $eventId, $recipient); $st->execute(); $st->close(); return;
    }
    $s = $db->prepare("SELECT COUNT(*) n FROM alert_log al JOIN security_events se ON se.id=al.event_id WHERE se.ip_address=? AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND al.status='sent'");
    $s->bind_param('si', $ip, $cooldown); $s->execute();
    $n = (int)$s->get_result()->fetch_assoc()['n']; $s->close();
    if ($cooldown > 0 && $n > 0) return;
    $subject = '[Security Monitor] ' . strtoupper($severity) . ': ' . $type;
    $body = "A suspicious request was detected.\n\nTime: " . date('c') . "\nSeverity: $severity\nType: $type\nSource IP: $ip\nPath: $path\nEvent ID: $eventId\n\nReview the security dashboard. This is a heuristic alert, not proof of compromise.";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) $headers .= "From: $from\r\n";
    $ok = @mail($recipient, $subject, $body, $headers);
    $status = $ok ? 'sent' : 'failed';
    $response = $ok ? 'mail() accepted message for delivery' : 'mail() failed; configure SMTP or hosting mail';
    $st = $db->prepare('INSERT INTO alert_log(event_id,recipient,subject,status,response_text) VALUES(?,?,?,?,?)');
    $st->bind_param('issss', $eventId, $recipient, $subject, $status, $response); $st->execute(); $st->close();
}
function sm_record(string $type, string $severity, string $details = ''): ?int {
    $db = sm_db(); if (!$db) return null;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '0.0.0.0';
    $method = substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 12);
    $uri = sm_redact_path((string)($_SERVER['REQUEST_URI'] ?? '/'));
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $status = http_response_code(); $status = ($status >= 100 && $status <= 599) ? $status : null;
    $stmt = $db->prepare('INSERT INTO security_events(ip_address,method,request_path,user_agent,event_type,severity,details,status_code) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssssssi', $ip, $method, $uri, $ua, $type, $severity, $details, $status);
    $stmt->execute(); $id = (int)$db->insert_id; $stmt->close();
    sm_send_alert($db, $id, $severity, $type, $ip, $uri);
    return $id;
}
function sm_enforce_block(): void {
    $db = sm_db(); if (!$db) return;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    $stmt = $db->prepare('SELECT reason FROM blocked_ips WHERE ip_address=? AND enabled=1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
    $stmt->bind_param('s', $ip); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo "Access denied by site security policy.";
        exit;
    }
}
function sm_log_hit(): void {
    $db = sm_db(); if (!$db) return;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    try {
        $stmt = $db->prepare('INSERT INTO request_hits(ip_address) VALUES(?)');
        $stmt->bind_param('s', $ip); $stmt->execute(); $stmt->close();
    } catch (Throwable $e) {
        error_log('Security monitor hit logging failed: ' . $e->getMessage());
    }
}
function sm_check_rate(): void {
    $db = sm_db(); if (!$db) return;
    $window = max(10, min(3600, (int)sm_setting($db, 'rate_limit_window_seconds', '60')));
    $limit = max(10, min(10000, (int)sm_setting($db, 'rate_limit_requests', '120')));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    $stmt = $db->prepare('SELECT COUNT(*) n FROM request_hits WHERE ip_address=? AND hit_time >= DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $stmt->bind_param('si', $ip, $window); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    if ($count >= $limit) {
        sm_record('request_rate_limit','high',"Request count exceeded threshold ($count in {$window}s)");
        $auto = sm_setting($db, 'auto_block_rate_limit', '0') === '1';
        if ($auto) {
            $duration = max(5, min(10080, (int)sm_setting($db, 'auto_block_duration_minutes', '30')));
            $reason = "Automatic block after rate threshold; review before extending.";
            $st = $db->prepare('INSERT INTO blocked_ips(ip_address,reason,expires_at,enabled) VALUES(?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),1) ON DUPLICATE KEY UPDATE reason=VALUES(reason),expires_at=VALUES(expires_at),enabled=1');
            $st->bind_param('ssi', $ip, $reason, $duration); $st->execute(); $st->close();
        }
        http_response_code(429);
        header('Retry-After: ' . $window);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Too many requests. Please try again later.';
        exit;
    }
}
