<?php
return [

    // ========================================
    // EMAIL SYSTEM MODE
    // ========================================
    'mode' => 'graph', // 'graph' or 'smtp'

    // ========================================
    // SENDER INFORMATION
    // ========================================
    'from_email' => 'noreply@divacakes.com',
    'from_name' => 'Diva Cakes Support',

    // ========================================
    // MICROSOFT 365 GRAPH API
    // ========================================
    'graph' => [
        'tenant_id' => 'YOUR_TENANT_ID',
        'client_id' => 'YOUR_CLIENT_ID',
        'client_secret' => 'YOUR_CLIENT_SECRET',
        'redirect_uri' => 'https://yourdomain.com/oauth_callback.php',
        'sender_user' => 'noreply@divacakes.com', // Mailbox that sends
        'auth_url' => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/authorize',
        'token_url' => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token',
        'graph_endpoint' => 'https://graph.microsoft.com/v1.0',
        'scope' => 'https://graph.microsoft.com/.default offline_access',
    ],

    // ========================================
    // SMTP FALLBACK (for when Graph fails)
    // ========================================
    'smtp' => [
        'host' => 'smtp.office365.com',
        'port' => 587,
        'username' => 'noreply@divacakes.com',
        'password' => 'YOUR-APP-PASSWORD-HERE',
        'encryption' => 'tls', // 'tls' or 'ssl'
        'auth' => true,
    ],

    // ========================================
    // GENERAL SETTINGS
    // ========================================
    'enable_notifications' => true,
    'log_emails' => true,
    'log_path' => __DIR__ . '/../logs/email_log.txt',
    'max_retries' => 3,
    'retry_delay' => 2, // seconds
];