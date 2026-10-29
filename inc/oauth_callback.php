<?php
/**
 * OAuth2 callback for initial Microsoft Graph authorization
 * Visit this URL once to authorize the app
 */
require '../inc/email_config.php';
require '../inc/connection.php';

$config = require '../inc/email_config.php';

if (!isset($_GET['code'])) {
    $authUrl = str_replace('{tenant_id}', $config['graph']['tenant_id'], $config['graph']['auth_url']);
    $authUrl .= '?' . http_build_query([
        'client_id' => $config['graph']['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['graph']['redirect_uri'],
        'response_mode' => 'query',
        'scope' => 'offline_access https://graph.microsoft.com/Mail.Send',
        'state' => bin2hex(random_bytes(16)),
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// Exchange code for tokens
$tokenUrl = str_replace('{tenant_id}', $config['graph']['tenant_id'], $config['graph']['token_url']);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $config['graph']['client_id'],
        'client_secret' => $config['graph']['client_secret'],
        'code' => $_GET['code'],
        'redirect_uri' => $config['graph']['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!empty($data['refresh_token'])) {
    $dir = __DIR__ . '/../cache';
    if (!is_dir($dir))
        mkdir($dir, 0755, true);
    file_put_contents($dir . '/refresh_token.txt', $data['refresh_token']);

    echo "<h2>✓ Authorization Successful!</h2>";
    echo "<p>Refresh token has been stored. You can now close this page.</p>";
} else {
    echo "<h2>✗ Authorization Failed</h2>";
    echo "<pre>" . print_r($data, true) . "</pre>";
}