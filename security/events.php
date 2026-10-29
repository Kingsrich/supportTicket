<?php
require_once __DIR__ . '/inc.php'; require_admin();
$pageTitle='Security Events';
$q=trim((string)($_GET['q']??'')); $severity=(string)($_GET['severity']??''); $type=(string)($_GET['type']??'');
$page=max(1,(int)($_GET['page']??1)); $limit=50; $offset=($page-1)*$limit;
$where=[];$types='';$params=[];
if($q!==''){$where[]='(ip_address LIKE ? OR request_path LIKE ? OR event_type LIKE ? OR details LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like);$types.='ssss';}
if(in_array($severity,['low','medium','high','critical'],true)){$where[]='severity=?';$params[]=$severity;$types.='s';}
if($type!==''){$where[]='event_type=?';$params[]=$type;$types.='s';}
$sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';
$countSql='SELECT COUNT(*) n FROM security_events'.$sqlWhere;$st=$db->prepare($countSql);if($types)$st->bind_param($types,...$params);$st->execute();$total=(int)$st->get_result()->fetch_assoc()['n'];$st->close();
$sql='SELECT * FROM security_events'.$sqlWhere.' ORDER BY id DESC LIMIT ? OFFSET ?';$st=$db->prepare($sql);$types2=$types.'ii';$params2=$params;$params2[]=$limit;$params2[]=$offset;$st->bind_param($types2,...$params2);$st->execute();$rows=$st->get_result();
$typesResult=$db->query('SELECT DISTINCT event_type FROM security_events ORDER BY event_type');
require __DIR__.'/header.php';?>
<h1>Security events</h1><p class="muted"><?=number_format($total)?> matching records</p>
<section class="panel"><form class="formrow" method="get"><div class="field"><label>Search IP, path, type, details</label><input name="q" value="<?=h($q)?>" placeholder="e.g. .env or 192.0.2.1"></div><div class="field"><label>Severity</label><select name="severity"><option value="">All severities</option><?php foreach(['low','medium','high','critical'] as $v):?><option <?= $severity===$v?'selected':''?> value="<?=$v?>"><?=$v?></option><?php endforeach;?></select></div><div class="field"><label>Event type</label><select name="type"><option value="">All event types</option><?php while($tr=$typesResult->fetch_assoc()):?><option value="<?=h($tr['event_type'])?>" <?= $type===$tr['event_type']?'selected':''?>><?=h($tr['event_type'])?></option><?php endwhile;?></select></div><button>Filter</button><a class="btn secondary" href="events.php">Reset</a></form></section>
<section class="panel"><table><thead><tr><th>Time</th><th>IP</th><th>Method</th><th>Type</th><th>Severity</th><th>Path</th><th>Details</th></tr></thead><tbody>
<?php while($r=$rows->fetch_assoc()):?><tr><td><?=h($r['event_time'])?></td><td><?=h($r['ip_address'])?></td><td><?=h($r['method'])?></td><td><?=h($r['event_type'])?></td><td><span class="pill <?=h($r['severity'])?>"><?=h($r['severity'])?></span></td><td><?=h($r['request_path'])?></td><td><?=h($r['details'])?></td></tr><?php endwhile;?>
</tbody></table><div class="pagination"><span>Page <?=number_format($page)?> of <?=number_format(max(1,(int)ceil($total/$limit)))?></span><?php if($page>1):?><a class="btn secondary" href="?<?=h(http_build_query(array_merge($_GET,['page'=>$page-1])))?>">Previous</a><?php endif;?><?php if($offset+$limit<$total):?><a class="btn secondary" href="?<?=h(http_build_query(array_merge($_GET,['page'=>$page+1])))?>">Next</a><?php endif;?></div></section>
<?php require __DIR__.'/footer.php';?>
