<?php
require_once __DIR__ . '/inc.php';
$count = (int)$db->query('SELECT COUNT(*) n FROM admins')->fetch_assoc()['n'];
if ($count > 0) { http_response_code(403); exit('An administrator already exists. Delete setup.php now.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) $error = 'Username must be 3-80 characters using letters, numbers, dot, underscore or hyphen.';
    elseif (strlen($password) < 14) $error = 'Use a password of at least 14 characters.';
    else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $s = $db->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)');
        $s->bind_param('ss', $username, $hash); $s->execute(); $s->close();
        header('Location: login.php?setup=1'); exit;
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Initial Setup</title><link rel="stylesheet" href="style.css"></head><body><main class="auth"><div class="panel"><h1>Create administrator</h1><p class="muted">This page works only while no administrator exists.</p><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><div class="field"><label>Username</label><input name="username" required minlength="3" maxlength="80" autocomplete="username"></div><br><div class="field"><label>Password (14+ characters)</label><input type="password" name="password" required minlength="14" autocomplete="new-password"></div><button>Create administrator</button></form></div></main></body></html>
