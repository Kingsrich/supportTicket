<?php
require '../inc/EmailService.php';

$mailer = new EmailService(null);

$ok = $mailer->sendTicketCreated(
    'kingsrich2019@gmail.com',   // send it to yourself first, e.g. it@divacakes.com.ng
    'Test User',
    [
        'ticket_number' => 'IT-2026-000001',
        'subject' => 'Test Ticket',
        'category' => 'Network',
        'priority' => 'Low',
        'status' => 'New',
        'description' => 'This is a test email to confirm Microsoft 365 integration works.',
    ]
);

var_dump($ok);