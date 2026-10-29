<?php
/**
 * Shared helpers for the IT Support Ticket module.
 * Include after auth.php + connection.php are already loaded.
 */

const TICKET_CATEGORIES = [
    'Hardware',
    'Software',
    'Network',
    'Internet',
    'Printer',
    'Email',
    'Password Reset',
    'Account Access',
    'Security',
    'CCTV',
    'Phone',
    'Server',
    'Website',
    'ERP/System',
    'Other'
];

const TICKET_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

const TICKET_STATUSES = [
    'New',
    'Open',
    'Assigned',
    'In Progress',
    'Waiting for User',
    'Pending Vendor',
    'Resolved',
    'Closed',
    'Cancelled'
];

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function slug($v)
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $v));
}

/** Generate a human-friendly, unique ticket number like IT-2026-000123 */
function generateTicketNumber($connection, $newTicketId)
{
    return 'IT-' . date('Y') . '-' . str_pad($newTicketId, 6, '0', STR_PAD_LEFT);
}

/** Insert an in-app notification, and best-effort email. SMS is a stub — plug in a gateway later. */
function notifyUser($connection, $userId, $ticketId, $type, $message, $sendEmail = true)
{
    $stmt = mysqli_prepare(
        $connection,
        "INSERT INTO ticket_notifications (user_id, ticket_id, type, message) VALUES (?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iiss", $userId, $ticketId, $type, $message);
    mysqli_stmt_execute($stmt);

    // Best-effort email delivery via Microsoft 365 (swallow failures so ticket flow never breaks on mail issues)
    if ($sendEmail) {
        $res = mysqli_query($connection, "SELECT email, fullname FROM users WHERE id = " . (int) $userId . " LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!empty($row['email'])) {
            require_once __DIR__ . '/EmailService.php';
            $emailService = new EmailService($connection);

            $ticketRes = mysqli_query($connection, "SELECT ticket_number FROM tickets WHERE id = " . (int) $ticketId . " LIMIT 1");
            $ticketRow = $ticketRes ? mysqli_fetch_assoc($ticketRes) : null;
            $ticketNumber = $ticketRow['ticket_number'] ?? '';

            $subject = $ticketNumber ? "Ticket {$ticketNumber} Update" : "IT Ticket Update";
            $emailService->sendNotification($row['email'], $row['fullname'] ?? '', $subject, nl2br(htmlspecialchars($message)));
        }
    }

    // SMS delivery (optional) — wire up a provider (e.g. Twilio) here if needed.
    // sendSms($row['phone'] ?? null, $message);
}

function logActivity($connection, $ticketId, $userId, $action)
{
    $stmt = mysqli_prepare(
        $connection,
        "INSERT INTO ticket_activity_log (ticket_id, user_id, action) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iis", $ticketId, $userId, $action);
    mysqli_stmt_execute($stmt);
}

/** Parse @mentions in a comment body against fullname/username; returns array of user ids found. */
function extractMentions($connection, $commentText)
{
    if (!preg_match_all('/@([A-Za-z0-9_\.]+)/', $commentText, $matches)) {
        return [];
    }
    $found = [];
    foreach (array_unique($matches[1]) as $name) {
        $safe = mysqli_real_escape_string($connection, $name);
        $res = mysqli_query($connection, "SELECT id FROM users WHERE fullname LIKE '{$safe}%' OR email LIKE '{$safe}@%' LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $found[] = (int) $row['id'];
        }
    }
    return $found;
}

function priorityBadgeClass($priority)
{
    return 'priority-' . slug($priority);
}

function statusBadgeClass($status)
{
    return 'status-' . slug($status);
}

function timeAgo($datetime)
{
    if (empty($datetime))
        return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)
        return 'just now';
    if ($diff < 3600)
        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)
        return floor($diff / 86400) . 'd ago';
    return date('M d, Y', strtotime($datetime));
}

/** Format a minute count as "20m", "3h 15m", or "2d 4h" for report tables. */
function formatDuration($minutes)
{
    $minutes = (int) $minutes;
    if ($minutes <= 0)
        return '—';
    if ($minutes < 60)
        return "{$minutes}m";
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
    $d = intdiv($minutes, 1440);
    $h = intdiv($minutes % 1440, 60);
    return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
}