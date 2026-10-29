# PHP Security Monitor (WAMP / PHP + MySQL)

A defensive monitoring dashboard for websites you own or administer. It records requests that pass through the PHP monitoring hook, flags common suspicious patterns, provides a dashboard, CSV reports, email alerts, and configurable IP blocking.

## Requirements
- PHP 8.0+ (tested design target: PHP 8.3)
- MySQL/MariaDB
- Apache/WAMP or a PHP website
- PHP extensions: mysqli, session, filter, openssl (normally enabled)

## Install
1. Copy this folder to your web root, e.g. `C:\wamp64\www\security-monitor`.
2. Create a database, e.g. `php_security_monitor`, in phpMyAdmin.
3. Import `schema.sql`.
4. Copy `config.example.php` to `config.php`, then set database credentials and a long random `app_key`.
5. Visit `http://localhost/security-monitor/setup.php`. Create the first admin account. Then delete `setup.php` or rename it outside the web root.
6. Sign in at `http://localhost/security-monitor/login.php`.
7. In Settings, configure alert email and detection thresholds.
8. Protect the app itself: use HTTPS on live hosting, restrict dashboard access, and keep PHP/MySQL patched.

## Monitor requests on your own PHP site
The dashboard only sees requests that reach its logging hook. To monitor an existing PHP site:
- Copy `monitor.php` and `monitor_lib.php` to a directory readable by the target site.
- In the target site's shared bootstrap/header file, add this early in the request, before output:
  `require_once 'FULL/PATH/TO/security-monitor/monitor.php';`
- Use an absolute filesystem path in production. Do not include it from user-controlled input.
- Add the hook to the common entry point used by all pages. For broader PHP coverage, use Apache `auto_prepend_file` only after testing it on a staging site.

Example Apache `.htaccess` for a specific PHP app (test carefully):
`php_value auto_prepend_file "C:/wamp64/www/security-monitor/monitor.php"`
This works only where Apache allows PHP values in `.htaccess` and the PHP SAPI supports it. On many shared hosts it is disabled. For WAMP, a safer option is configuring `auto_prepend_file` in the relevant Apache/PHP config, then restarting Apache. Confirm the setting with `phpinfo()` and remove any public `phpinfo()` file immediately.

## What it detects
- Common SQL injection-looking strings in query/body data
- Path traversal sequences
- Requests for sensitive filenames (`.env`, `.git`, backups, config files)
- Common scanner/probe paths (phpMyAdmin, wp-admin, etc.)
- Basic high-rate request bursts per IP (using a separate request-hit counter)
- Repeated failed login events, if your app explicitly calls `security_monitor_login_result($success)`

These are heuristic signals, not proof of compromise. False positives are possible.

## Important limits
- This does not replace a WAF, IDS/IPS, antivirus, or hosting provider logs.
- PHP cannot see requests rejected by the firewall, reverse proxy, or Apache before PHP runs.
- The hook may not see static files, blocked requests, or PHP endpoints that do not include the hook.
- Apache access/error logs are required for complete HTTP-level visibility. WAMP commonly stores logs under `C:\wamp64\logs\`; filenames vary. Look for `CustomLog` and `ErrorLog` in Apache configuration.
- Do not trust `X-Forwarded-For` or similar headers unless you explicitly configure a known, trusted reverse proxy. This app records `REMOTE_ADDR` by default to reduce spoofing.
- IP blocking is enforced only for requests that pass through this monitor hook. It cannot block traffic at the firewall/network layer.
- Avoid storing full request bodies or secrets. This monitor stores request path/query with common sensitive parameter values redacted and does not store POST bodies.

## Security recommendations
- Put the monitor on an internal/admin-only path or restrict it by VPN/IP where practical.
- Change default credentials immediately; no default admin password is provided.
- Use least-privilege database credentials.
- Keep `config.php` outside the public web root when possible. If it must stay in this directory, deny web access using Apache rules.
- Back up the database and review alerts before blocking IPs. Do not block shared office/NAT addresses without investigation.
- For production email, configure authenticated SMTP through a trusted mail provider or integrate your existing mail service. PHP `mail()` may not be configured on WAMP.

## Files
- `dashboard.php`: dashboard
- `login.php`, `logout.php`, `setup.php`: authentication/bootstrap
- `settings.php`: alert and detection settings
- `events.php`: searchable event log
- `blocked_ips.php`: IP block management
- `report.php`: CSV export
- `monitor.php`, `monitor_lib.php`: include/auto-prepend hook
- `schema.sql`: database tables
