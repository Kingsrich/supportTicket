<?php
require_once __DIR__ . '/inc.php';
require_admin();
$pageTitle = $pageTitle ?? 'Security Monitor';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($pageTitle) ?></title><link rel="stylesheet" href="style.css"></head><body>
<header class="top"><strong>🛡 PHP Security Monitor</strong><nav><a href="dashboard.php">Dashboard</a><a href="events.php">Events</a><a href="blocked_ips.php">Blocked IPs</a><a href="settings.php">Settings</a><a href="report.php">CSV Report</a><a href="logout.php">Sign out</a></nav></header><main class="wrap">
