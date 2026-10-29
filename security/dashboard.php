<?php
require_once __DIR__ . '/inc.php'; require_admin();
$pageTitle = 'Security Dashboard';
$today = (int)$db->query("SELECT COUNT(*) n FROM security_events WHERE event_time >= CURDATE()")->fetch_assoc()['n'];
$high = (int)$db->query("SELECT COUNT(*) n FROM security_events WHERE event_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity IN ('high','critical')")->fetch_assoc()['n'];
$ips = (int)$db->query("SELECT COUNT(DISTINCT ip_address) n FROM security_events WHERE event_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['n'];
$blocked = (int)$db->query("SELECT COUNT(*) n FROM blocked_ips WHERE enabled=1 AND (expires_at IS NULL OR expires_at>NOW())")->fetch_assoc()['n'];
$events = $db->query('SELECT id,event_time,ip_address,event_type,severity,request_path,details FROM security_events ORDER BY id DESC LIMIT 15');
$daily = $db->query("SELECT DATE(event_time) d,COUNT(*) n FROM security_events WHERE event_time>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(event_time) ORDER BY d")->fetch_all(MYSQLI_ASSOC);
$map=[]; foreach($daily as $r)$map[$r['d']]=(int)$r['n']; $max=max([1,...array_values($map)]);
require __DIR__ . '/header.php';
?>
<h1>Security dashboard</h1><p class="muted">Monitoring activity recorded by the PHP hook. Last updated <?=h(date('d M Y H:i:s'))?></p>
<div class="cards">
<div class="card"><div class="muted">Events today</div><div class="metric"><?=number_format($today)?></div></div>
<div class="card"><div class="muted">High / critical (24h)</div><div class="metric"><?=number_format($high)?></div></div>
<div class="card"><div class="muted">Unique IPs (24h)</div><div class="metric"><?=number_format($ips)?></div></div>
<div class="card"><div class="muted">Active IP blocks</div><div class="metric"><?=number_format($blocked)?></div></div>
</div>
<section class="panel"><h2>Events over the last 7 days</h2><div class="chart">
<?php for($i=6;$i>=0;$i--): $d=date('Y-m-d',strtotime("-$i days"));$n=$map[$d]??0;?>
<div class="barcol"><span><?=number_format($n)?></span><div class="bar" style="height:<?=max(2,(int)(100*$n/$max))?>%"></div><span><?=h(date('D',strtotime($d)))?></span></div>
<?php endfor;?></div></section>
<section class="panel"><div class="actions"><h2 style="margin-right:auto">Recent security events</h2><a class="btn secondary" href="events.php">View all events</a><a class="btn" href="report.php">Export CSV</a></div>
<table><thead><tr><th>Time</th><th>IP</th><th>Type</th><th>Severity</th><th>Request</th><th>Details</th></tr></thead><tbody>
<?php while($r=$events->fetch_assoc()):?><tr><td><?=h($r['event_time'])?></td><td><?=h($r['ip_address'])?></td><td><?=h($r['event_type'])?></td><td><span class="pill <?=h($r['severity'])?>"><?=h($r['severity'])?></span></td><td><?=h($r['request_path'])?></td><td><?=h($r['details'])?></td></tr><?php endwhile;?>
</tbody></table></section>
<?php require __DIR__ . '/footer.php'; ?>
