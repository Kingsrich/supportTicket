<?php
require_once __DIR__ . '/inc.php'; require_admin();
$from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));$format=(string)($_GET['format']??'');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');
if($to<$from){$tmp=$from;$from=$to;$to=$tmp;}
$s=$db->prepare('SELECT id,event_time,ip_address,method,request_path,user_agent,event_type,severity,details,status_code FROM security_events WHERE event_time>=? AND event_time < DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC');
$s->bind_param('ss',$from,$to);$s->execute();$rows=$s->get_result();
if($format==='csv'){
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="security-report-'.$from.'-to-'.$to.'.csv"');
 $out=fopen('php://output','w');fputcsv($out,['ID','Time','IP','Method','Path','User Agent','Event Type','Severity','Details','HTTP Status']);
 while($r=$rows->fetch_assoc())fputcsv($out,$r);fclose($out);exit;
}
$pageTitle='Security Reports';require __DIR__.'/header.php';?>
<h1>Security reports</h1><p class="muted">Export filtered event data for review. CSV may contain IP addresses and URL paths; store it securely.</p><section class="panel"><form method="get" class="formrow"><div class="field"><label>From date</label><input type="date" name="from" value="<?=h($from)?>" required></div><div class="field"><label>To date</label><input type="date" name="to" value="<?=h($to)?>" required></div><button>Preview</button><button name="format" value="csv">Download CSV</button></form></section>
<section class="panel"><h2>Summary</h2><?php $s2=$db->prepare("SELECT COUNT(*) total,COUNT(DISTINCT ip_address) ips,SUM(severity IN ('high','critical')) high_count FROM security_events WHERE event_time>=? AND event_time<DATE_ADD(?,INTERVAL 1 DAY)");$s2->bind_param('ss',$from,$to);$s2->execute();$sum=$s2->get_result()->fetch_assoc();?><div class="cards"><div class="card">Total events<div class="metric"><?=number_format((int)$sum['total'])?></div></div><div class="card">Unique IPs<div class="metric"><?=number_format((int)$sum['ips'])?></div></div><div class="card">High / critical<div class="metric"><?=number_format((int)$sum['high_count'])?></div></div></div></section>
<section class="panel"><h2>Recent matching events (up to 200)</h2><table><thead><tr><th>Time</th><th>IP</th><th>Type</th><th>Severity</th><th>Path</th></tr></thead><tbody><?php $s3=$db->prepare('SELECT event_time,ip_address,event_type,severity,request_path FROM security_events WHERE event_time>=? AND event_time<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC LIMIT 200');$s3->bind_param('ss',$from,$to);$s3->execute();$rs=$s3->get_result();while($r=$rs->fetch_assoc()):?><tr><td><?=h($r['event_time'])?></td><td><?=h($r['ip_address'])?></td><td><?=h($r['event_type'])?></td><td><span class="pill <?=h($r['severity'])?>"><?=h($r['severity'])?></span></td><td><?=h($r['request_path'])?></td></tr><?php endwhile;?></tbody></table></section>
<?php require __DIR__.'/footer.php';?>
