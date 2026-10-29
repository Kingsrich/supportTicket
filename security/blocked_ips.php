<?php
require_once __DIR__ . '/inc.php'; require_admin();
$pageTitle='Blocked IPs';$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$action=(string)($_POST['action']??'');
 if($action==='add'){
  $ip=trim((string)($_POST['ip_address']??''));$reason=trim((string)($_POST['reason']??''));$mins=trim((string)($_POST['duration']??''));
  if(!valid_ip($ip))$error='Enter a valid IPv4 or IPv6 address.';
  else{$expires=null;if($mins!==''){ $m=max(5,min(525600,(int)$mins));$expires=date('Y-m-d H:i:s',time()+$m*60);}
   if($expires===null){$s=$db->prepare('INSERT INTO blocked_ips(ip_address,reason,expires_at,enabled,created_by) VALUES(?,?,NULL,1,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),expires_at=NULL,enabled=1');$s->bind_param('ssi',$ip,$reason,$_SESSION['admin_id']);}
   else{$s=$db->prepare('INSERT INTO blocked_ips(ip_address,reason,expires_at,enabled,created_by) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),expires_at=VALUES(expires_at),enabled=1');$s->bind_param('sssi',$ip,$reason,$expires,$_SESSION['admin_id']);}
   $s->execute();$s->close();$msg='IP block saved. It only applies to requests that reach the PHP monitoring hook.';
  }
 }elseif($action==='toggle'){$id=(int)($_POST['id']??0);$s=$db->prepare('UPDATE blocked_ips SET enabled=1-enabled WHERE id=?');$s->bind_param('i',$id);$s->execute();$s->close();$msg='Block status updated.';}
 elseif($action==='delete'){$id=(int)($_POST['id']??0);$s=$db->prepare('DELETE FROM blocked_ips WHERE id=?');$s->bind_param('i',$id);$s->execute();$s->close();$msg='IP entry deleted.';}
}
$rows=$db->query('SELECT b.*,a.username FROM blocked_ips b LEFT JOIN admins a ON a.id=b.created_by ORDER BY b.id DESC');
require __DIR__.'/header.php';?>
<h1>IP block management</h1><p class="muted">Use carefully. A block can affect multiple people behind one shared public IP.</p><?php if($msg):?><p class="success"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<section class="panel"><h2>Add an IP block</h2><form method="post" class="formrow"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add"><div class="field"><label>IPv4 / IPv6 address</label><input name="ip_address" required placeholder="203.0.113.10"></div><div class="field"><label>Reason</label><input name="reason" maxlength="500" placeholder="Reason for blocking"></div><div class="field"><label>Duration in minutes (blank = permanent)</label><input type="number" name="duration" min="5" max="525600" placeholder="e.g. 60"></div><button>Add block</button></form></section>
<section class="panel"><table><thead><tr><th>IP</th><th>Reason</th><th>Created</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php while($r=$rows->fetch_assoc()):?><tr><td><?=h($r['ip_address'])?></td><td><?=h($r['reason'])?></td><td><?=h($r['created_at'])?></td><td><?=h($r['expires_at']??'Never')?></td><td><?=((int)$r['enabled']===1 && (!$r['expires_at']||strtotime($r['expires_at'])>time()))?'Active':'Inactive/expired'?></td><td><div class="actions"><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="toggle"><button class="secondary">Toggle</button></form><form method="post" data-confirm="Delete this IP block entry?"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="delete"><button class="danger">Delete</button></form></div></td></tr><?php endwhile;?></tbody></table></section>
<?php require __DIR__.'/footer.php';?>
