<?php
require_once __DIR__ . '/inc.php';
if (!empty($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $s = $db->prepare('SELECT id,username,password_hash FROM admins WHERE username=? LIMIT 1');
    $s->bind_param('s', $username); $s->execute(); $admin = $s->get_result()->fetch_assoc(); $s->close();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id']; $_SESSION['admin_username'] = $admin['username'];
        $u = $db->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?'); $u->bind_param('i', $_SESSION['admin_id']); $u->execute(); $u->close();
        header('Location: dashboard.php'); exit;
    }
    $error = 'Invalid username or password.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title><link rel="stylesheet" href="style.css"></head><body><main class="auth"><div class="panel"><h1>🛡 Security Monitor</h1><p class="muted">Administrator sign-in</p><?php if(isset($_GET['setup'])):?><p class="success">Administrator created. Delete setup.php from the server now.</p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><div class="field"><label>Username</label><input name="username" required autocomplete="username"></div><br><div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div><button>Sign in</button></form></div></main></body></html>
