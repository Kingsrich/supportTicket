<?php
require_once '../inc/connection.php';
require_once '../inc/EmailService.php';

header('Content-Type: application/json');

try {
    $emailService = new EmailService($connection);
    $result = $emailService->processQueue(20);

    echo json_encode([
        'success' => true,
        'processed' => $result['processed'],
        'sent' => $result['sent'],
        'failed' => $result['failed']
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}