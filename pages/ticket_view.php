<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'staff';
$uRes = mysqli_query($connection, "SELECT location_office FROM users WHERE id={$userId}");
$uData = mysqli_fetch_assoc($uRes);
$userLocation = $uData['location_office'] ?? '';
$canAssign = in_array($role, ['officer', 'admin']);
$canSeeInternal = in_array($role, ['officer', 'admin']);

function responseMessage($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

$ticketId = (int) ($_GET['id'] ?? 0);
$check = mysqli_query($connection, "SELECT * FROM tickets WHERE id = {$ticketId} LIMIT 1");
$ticket = mysqli_fetch_assoc($check);

if (!$ticket) {
    header("Location: support_tickets.php");
    exit;
}

// Security: If the user is staff, they can only view if they created the ticket
// OR if they are from the same outlet as the ticket.
if ($role === 'staff') {
    if ((int)$ticket['user_id'] !== $userId && $ticket['location_office'] !== $userLocation) {
        header("Location: support_tickets.php");
        exit;
    }
}


$isTicketLocked = in_array($ticket['status'], ['Resolved', 'Closed'], true);

mysqli_query(
    $connection,
    "UPDATE ticket_notifications SET is_read = 1 WHERE ticket_id = {$ticketId} AND user_id = {$userId} AND is_read = 0"
);
/* =========================
   POST ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (
        $isTicketLocked &&
        !in_array($action, ['user_close_ticket'], true)
    ) {
        responseMessage(
            'error',
            'This ticket has already been resolved and can no longer be edited.'
        );

        header("Location: ticket_view.php?id={$ticketId}");
        exit;
    }

    if ($action === 'add_comment') {
        $commentText = trim($_POST['comment'] ?? '');
        $isInternal = ($_POST['is_internal'] ?? '0') === '1' && $canSeeInternal ? 1 : 0;

        if ($commentText === '') {
            responseMessage('error', 'Comment cannot be empty.');
            header("Location: ticket_view.php?id={$ticketId}");
            exit;
        }

        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal) VALUES (?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, "iisi", $ticketId, $userId, $commentText, $isInternal);
        mysqli_stmt_execute($stmt);
        $commentId = mysqli_insert_id($connection);

        // Reply attachments
        if (!empty($_FILES['reply_files']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/comments/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];

            foreach ($_FILES['reply_files']['name'] as $index => $originalName) {
                if ($_FILES['reply_files']['error'][$index] !== UPLOAD_ERR_OK)
                    continue;
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed))
                    continue;

                $safeName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                $newName = 'comment_' . $commentId . '_' . time() . '_' . $index . '_' . $safeName . '.' . $ext;
                $targetPath = $uploadDir . $newName;
                $dbPath = 'uploads/comments/' . $newName;

                if (move_uploaded_file($_FILES['reply_files']['tmp_name'][$index], $targetPath)) {
                    $a = mysqli_prepare(
                        $connection,
                        "INSERT INTO ticket_comment_attachments (comment_id, file_name, file_path) VALUES (?, ?, ?)"
                    );
                    mysqli_stmt_bind_param($a, "iss", $commentId, $originalName, $dbPath);
                    mysqli_stmt_execute($a);
                }
            }
        }

        // Mentions
        $mentionedIds = extractMentions($connection, $commentText);
        foreach ($mentionedIds as $mid) {
            $m = mysqli_prepare($connection, "INSERT INTO ticket_mentions (comment_id, ticket_id, mentioned_user_id) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($m, "iii", $commentId, $ticketId, $mid);
            mysqli_stmt_execute($m);
            if ($mid !== $userId) {
                notifyUser($connection, $mid, $ticketId, 'comment', "You were mentioned on ticket {$ticket['ticket_number']}.");
            }
        }

        // Notify: public replies notify the ticket owner (if someone else wrote it) + assigned tech
        if (!$isInternal) {
            $notifiedSomeone = false;

            if ((int) $ticket['user_id'] !== $userId) {
                notifyUser($connection, (int) $ticket['user_id'], $ticketId, 'comment', "New reply on ticket {$ticket['ticket_number']}.");
                $notifiedSomeone = true;
            }

            if (!empty($ticket['assigned_to']) && (int) $ticket['assigned_to'] !== $userId) {
                notifyUser($connection, (int) $ticket['assigned_to'], $ticketId, 'comment', "New reply on ticket {$ticket['ticket_number']}.");
                $notifiedSomeone = true;
            }

            // Fallback: unassigned ticket + owner is the one replying — alert all officers/admins
            if (!$notifiedSomeone) {
                $techRes = mysqli_query($connection, "SELECT id FROM users WHERE role IN ('officer','admin')");
                while ($t = mysqli_fetch_assoc($techRes)) {
                    if ((int) $t['id'] !== $userId) {
                        notifyUser($connection, (int) $t['id'], $ticketId, 'comment', "New reply on ticket {$ticket['ticket_number']}.");
                    }
                }
            }
        }

        logActivity($connection, $ticketId, $userId, $isInternal ? 'Internal note added' : 'Public reply added');
        header("Location: ticket_view.php?id={$ticketId}");
        exit;
    }

    if ($action === 'update_status' && $canAssign) {
        $newStatus = $_POST['status'] ?? '';

        if ($newStatus === 'Closed') {
            responseMessage('error', "Cannot close a ticket, select Resolved if issue is resolved.");
            header("Location: ticket_view.php?id={$ticketId}");
            exit;
        }

        if (in_array($newStatus, TICKET_STATUSES)) {
            $resolvedAt = in_array($newStatus, ['Resolved']) ? date('Y-m-d H:i:s') : $ticket['resolved_at'];
            $closedAt = $ticket['closed_at']; // Closed can only be set via user_close_ticket, never here

            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tickets SET status = ?, resolved_at = ?, closed_at = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "sssi", $newStatus, $resolvedAt, $closedAt, $ticketId);
            mysqli_stmt_execute($stmt);

            logActivity($connection, $ticketId, $userId, "Status changed to {$newStatus}");
            notifyUser($connection, (int) $ticket['user_id'], $ticketId, strtolower($newStatus), "Your ticket {$ticket['ticket_number']} is now '{$newStatus}'.");

            responseMessage('success', "Status updated to {$newStatus}.");
        }
        header("Location: ticket_view.php?id={$ticketId}");
        exit;
    }

    if (($action === 'assign_self' || $action === 'reassign') && $canAssign) {
        $officerId = $action === 'assign_self' ? $userId : (int) ($_POST['officer_id'] ?? 0);

        if ($officerId > 0) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tickets SET assigned_to = ?, status = IF(status IN ('New','Open'), 'Assigned', status), assigned_at = NOW() WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "ii", $officerId, $ticketId);
            mysqli_stmt_execute($stmt);

            logActivity($connection, $ticketId, $userId, $action === 'assign_self' ? 'Assigned to self' : 'Reassigned');
            notifyUser($connection, $officerId, $ticketId, 'assigned', "Ticket {$ticket['ticket_number']} has been assigned to you.");

            responseMessage('success', 'Ticket assignment updated.');
        }
        header("Location: ticket_view.php?id={$ticketId}");
        exit;
    }

    if ($action === 'add_part' && $canAssign) {
        $part = trim($_POST['part_name'] ?? '');
        $qty = max(1, (int) ($_POST['quantity'] ?? 1));
        $cost = max(0, (float) ($_POST['unit_cost'] ?? 0));
        $status = $_POST['part_status'] ?? 'required';
        $notes = trim($_POST['notes'] ?? '');
        if ($part !== '') {
            $s = mysqli_prepare($connection, "INSERT INTO ticket_parts(ticket_id,part_name,quantity,unit_cost,status,notes,created_by) VALUES(?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($s, 'isidssi', $ticketId, $part, $qty, $cost, $status, $notes, $userId);
            mysqli_stmt_execute($s);
            logActivity($connection, $ticketId, $userId, "Part/material added: {$part}");
            responseMessage('success', 'Part/material recorded.');
        }
        header("Location:ticket_view.php?id={$ticketId}");
        exit;
    }

    if ($action === 'add_vendor_cost' && $canAssign) {
        $vendor = trim($_POST['vendor_name'] ?? '');
        $work = trim($_POST['work_description'] ?? '');
        $amount = max(0, (float) ($_POST['amount'] ?? 0));
        $ref = trim($_POST['invoice_reference'] ?? '');
        if ($vendor !== '' && $amount > 0) {
            $s = mysqli_prepare($connection, "INSERT INTO ticket_vendor_costs(ticket_id,vendor_name,work_description,amount,invoice_reference,created_by) VALUES(?,?,?,?,?,?)");
            mysqli_stmt_bind_param($s, 'issdsi', $ticketId, $vendor, $work, $amount, $ref, $userId);
            mysqli_stmt_execute($s);
            logActivity($connection, $ticketId, $userId, "External vendor cost recorded: {$vendor}");
            responseMessage('success', 'Vendor cost recorded.');
        }
        header("Location:ticket_view.php?id={$ticketId}");
        exit;
    }



    if ($action === 'user_close_ticket') {
        if (!$canAssign && (int) $ticket['user_id'] === $userId && $ticket['status'] === 'Resolved') {
            $stmt = mysqli_prepare($connection, "UPDATE tickets SET status = 'Closed', closed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $ticketId);
            mysqli_stmt_execute($stmt);

            logActivity($connection, $ticketId, $userId, 'Ticket closed by user confirmation');

            if (!empty($ticket['assigned_to'])) {
                notifyUser($connection, (int) $ticket['assigned_to'], $ticketId, 'closed', "Ticket {$ticket['ticket_number']} was confirmed closed by the user.");
            }

            responseMessage('success', 'Ticket closed. Thanks for confirming!');
        } else {
            responseMessage('error', 'This ticket cannot be closed right now.');
        }
        header("Location: ticket_view.php?id={$ticketId}");
        exit;
    }
}

/* Re-fetch ticket in case of updates */
$check = mysqli_query($connection, "SELECT t.*, u.fullname AS submitter_name, u.email AS submitter_email, d.department_name, a.fullname AS officer_name,
o.company AS company_name
FROM tickets t
LEFT JOIN users u ON u.id = t.user_id
LEFT JOIN departments d ON d.id = t.department_id
LEFT JOIN users a ON a.id = t.assigned_to
LEFT JOIN outlets o ON o.warehouse_name = t.location_office
WHERE t.id = {$ticketId} LIMIT 1");
$ticket = mysqli_fetch_assoc($check);

$attachments = [];
$attRes = mysqli_query($connection, "SELECT * FROM ticket_attachments WHERE ticket_id = {$ticketId} ORDER BY created_at ASC");
while ($r = mysqli_fetch_assoc($attRes))
    $attachments[] = $r;

$commentWhere = $canSeeInternal ? "" : "AND c.is_internal = 0";
$comments = mysqli_query(
    $connection,
    "SELECT c.*, u.fullname
     FROM ticket_comments c
     LEFT JOIN users u ON u.id = c.user_id
     WHERE c.ticket_id = {$ticketId} {$commentWhere}
     ORDER BY c.created_at ASC"
);

$activity = mysqli_query($connection, "SELECT al.*, u.fullname FROM ticket_activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.ticket_id = {$ticketId} ORDER BY al.created_at ASC");
$parts = mysqli_query($connection, "SELECT * FROM ticket_parts WHERE ticket_id={$ticketId} ORDER BY created_at DESC");
$vendorCosts = mysqli_query($connection, "SELECT * FROM ticket_vendor_costs WHERE ticket_id={$ticketId} ORDER BY created_at DESC");

$pr = mysqli_query($connection, "SELECT COALESCE(SUM(quantity*unit_cost),0) total FROM ticket_parts WHERE ticket_id={$ticketId}");
$partsTotal = (float) (mysqli_fetch_assoc($pr)['total'] ?? 0);

$vr = mysqli_query($connection, "SELECT COALESCE(SUM(amount),0) total FROM ticket_vendor_costs WHERE ticket_id={$ticketId}");
$vendorTotal = (float) (mysqli_fetch_assoc($vr)['total'] ?? 0);

$ticketCost = $partsTotal + $vendorTotal;
$downStart = strtotime($ticket['created_at']);

$downEnd = !empty($ticket['resolved_at'])
    ? strtotime($ticket['resolved_at'])
    : time();

$downtimeSeconds = max(0, $downEnd - $downStart);

$downtimeDays = floor($downtimeSeconds / 86400);
$downtimeHours = floor(($downtimeSeconds % 86400) / 3600);
$downtimeMinutes = floor(($downtimeSeconds % 3600) / 60);

if ($downtimeDays > 0) {
    $totalDowntime = $downtimeDays . 'd ' . $downtimeHours . 'h';
} elseif ($downtimeHours > 0) {
    $totalDowntime = $downtimeHours . 'h ' . $downtimeMinutes . 'm';
} else {
    $totalDowntime = $downtimeMinutes . 'm';
}
$requiredRes = mysqli_query($connection, "SELECT COALESCE(SUM(quantity),0) total FROM ticket_parts WHERE ticket_id={$ticketId} AND status='required'");
$partsRequired = (int) (mysqli_fetch_assoc($requiredRes)['total'] ?? 0);

$usedRes = mysqli_query($connection, "SELECT COALESCE(SUM(quantity),0) total FROM ticket_parts WHERE ticket_id={$ticketId} AND status='used'");
$partsUsed = (int) (mysqli_fetch_assoc($usedRes)['total'] ?? 0);

$vendorCountRes = mysqli_query($connection, "SELECT COUNT(*) total FROM ticket_vendor_costs WHERE ticket_id={$ticketId}");
$vendorCount = (int) (mysqli_fetch_assoc($vendorCountRes)['total'] ?? 0);

$commentCountRes = mysqli_query($connection, "SELECT COUNT(*) total FROM ticket_comments WHERE ticket_id={$ticketId}");
$commentCount = (int) (mysqli_fetch_assoc($commentCountRes)['total'] ?? 0);

$officers = mysqli_query($connection, "SELECT id, fullname FROM users WHERE role IN ('officer','admin') ORDER BY fullname ASC");

include '../inc/header.php';
?>
<link rel="stylesheet" href="../assets/css/tickets.css">

<?php if (!empty($_SESSION['flash_text'])): ?>
<div class="message <?= h($_SESSION['flash_type']) ?>"><?= h($_SESSION['flash_text']) ?></div>
<?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<a href="support_tickets.php" class="back-link">&larr; Back to tickets</a>

<div class="ticket-header panel">
    <div class="ticket-header-main">
        <span class="ticket-number"><?= h($ticket['ticket_number']) ?></span>
        <h2><?= h($ticket['subject']) ?></h2>
        <div class="ticket-meta">
            <span class="badge <?= priorityBadgeClass($ticket['priority']) ?>"><?= h($ticket['priority']) ?></span>
            <span class="badge <?= statusBadgeClass($ticket['status']) ?>"><?= h($ticket['status']) ?></span>
            <span class="meta-item"><?= h($ticket['category']) ?></span>
            <span class="meta-item"><?= h($ticket['department_name'] ?? 'No department') ?></span>
            <?php if (!empty($ticket['company_name'])): ?>
            <span class="meta-item">Company: <?= h($ticket['company_name']) ?></span>
            <?php endif; ?>
            <?php if (!empty($ticket['location_office'])): ?>
            <span class="meta-item">📍 <?= h($ticket['location_office']) ?></span>
            <?php endif; ?>
        </div>
        <p class="ticket-submitted-by">Submitted by <strong><?= h($ticket['submitter_name']) ?></strong> on
            <?= h(date('M d, Y g:i A', strtotime($ticket['created_at']))) ?>
        </p>
    </div>

    <?php if ($canAssign && !$isTicketLocked): ?>
    <div class="ticket-header-actions">
        <form method="POST" class="inline-form">
            <select name="status" class="form-control" onchange="this.form.submit();">
                <?php foreach (TICKET_STATUSES as $s): ?>
                <option value="<?= h($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="update_status">
        </form>

        <form method="POST" class="inline-form">
            <select name="officer_id" class="form-control">
                <option value="0">Assign IT Officer...</option>
                <?php while ($t = mysqli_fetch_assoc($officers)): ?>
                <option value="<?= (int) $t['id'] ?>"
                    <?= (int) $ticket['assigned_to'] === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['fullname']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <button type="submit" name="action" value="reassign"
                class="btn btn-light small-btn"><?= empty($ticket['assigned_to']) ? 'Assign' : 'Reassign' ?></button>
        </form>

        <?php if (empty($ticket['assigned_to']) || (int) $ticket['assigned_to'] !== $userId): ?>
        <form method="POST" class="inline-form">
            <button type="submit" name="action" value="assign_self" class="btn btn-success small-btn">Assign to
                me</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!$canAssign && (int) $ticket['user_id'] === $userId): ?>
    <div class="ticket-header-actions">
        <form method="POST" class="inline-form close-toggle-form">
            <label class="toggle-switch">
                <input type="checkbox" onchange="this.form.submit()"
                    <?= $ticket['status'] === 'Closed' ? 'checked' : '' ?>
                    <?= $ticket['status'] !== 'Resolved' ? 'disabled' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
            <span class="toggle-label">
                <?php if ($ticket['status'] === 'Closed'): ?>
                Ticket closed
                <?php elseif ($ticket['status'] === 'Resolved'): ?>
                Confirm & close ticket
                <?php else: ?>
                Close once Resolved
                <?php endif; ?>
            </span>
            <input type="hidden" name="action" value="user_close_ticket">
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($canAssign): ?>
<div class="it-ticket-kpis">

    <div class="it-ticket-kpi">
        <span>Parts Required</span>
        <strong><?= number_format($partsRequired) ?></strong>
        <small>Awaiting purchase</small>
    </div>

    <div class="it-ticket-kpi">
        <span>Parts Used</span>
        <strong><?= number_format($partsUsed) ?></strong>
        <small>Used for this ticket</small>
    </div>

    <div class="it-ticket-kpi">
        <span>Parts Cost</span>
        <strong>₦<?= number_format($partsTotal, 2) ?></strong>
        <small>Purchased / used items</small>
    </div>

    <div class="it-ticket-kpi">
        <span>Vendor Cost</span>
        <strong>₦<?= number_format($vendorTotal, 2) ?></strong>
        <small><?= number_format($vendorCount) ?> vendor record(s)</small>
    </div>

    <div class="it-ticket-kpi">
        <span>Total Ticket Cost</span>
        <strong>₦<?= number_format($ticketCost, 2) ?></strong>
        <small>Parts + vendor</small>
    </div>

    <div class="it-ticket-kpi">
        <span>Total Downtime</span>
        <strong><?= h($totalDowntime) ?></strong>
        <small><?= !empty($ticket['resolved_at']) ? 'Until resolution' : 'Currently ongoing' ?></small>
    </div>

</div>
<?php endif; ?>

<div class="ticket-body-grid">
    <div class="ticket-main-col">
        <div class="panel">
            <h3 class="section-title-sm">Description</h3>
            <p class="ticket-description"><?= nl2br(h($ticket['description'])) ?></p>

            <?php if (count($attachments)): ?>
            <div class="uploaded-files">
                <?php foreach ($attachments as $file): ?>
                <div class="uploaded-file">
                    <span>📎 <?= h($file['file_name']) ?></span>
                    <a href="../<?= h($file['file_path']) ?>" target="_blank">View</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($canAssign && !$isTicketLocked): ?>
        <div class="ticket-cost-grid">
            <div class="panel ticket-cost-panel">
                <h3 class="section-title-sm">Parts / Materials</h3>
                <form method="POST" class="form-grid">
                    <input name="part_name" class="form-control" placeholder="Part / material" required>
                    <input type="number" name="quantity" min="1" value="1" class="form-control">
                    <input type="number" name="unit_cost" min="0" step="0.01" class="form-control"
                        placeholder="Unit cost">
                    <select name="part_status" class="form-control">
                        <option value="required">Required</option>
                        <option value="purchased">Purchased</option>
                        <option value="used">Used</option>
                    </select>
                    <input name="notes" class="form-control span-2" placeholder="Notes">
                    <button name="action" value="add_part" class="btn btn-primary">Add Part</button>
                </form>

                <?php while ($p = $parts ? mysqli_fetch_assoc($parts) : null): ?>
                <div class="detail-row">
                    <span><?= h($p['part_name']) ?> ×
                        <?= (int) $p['quantity'] ?><small><?= h(ucfirst($p['status'])) ?></small></span>
                    <strong>₦<?= number_format($p['quantity'] * $p['unit_cost'], 2) ?></strong>
                </div>
                <?php endwhile; ?>
            </div>

            <div class="panel ticket-cost-panel">
                <h3 class="section-title-sm">External Vendor Cost</h3>
                <form method="POST" class="form-grid">
                    <input name="vendor_name" class="form-control" placeholder="Vendor name" required>
                    <input type="number" name="amount" min="0" step="0.01" class="form-control" placeholder="Amount"
                        required>
                    <input name="invoice_reference" class="form-control" placeholder="Invoice / reference">
                    <textarea name="work_description" class="form-control span-2" rows="2"
                        placeholder="Work performed"></textarea>
                    <button name="action" value="add_vendor_cost" class="btn btn-primary">Add Vendor Cost</button>
                </form>

                <?php while ($v = $vendorCosts ? mysqli_fetch_assoc($vendorCosts) : null): ?>
                <div class="detail-row">
                    <span><strong><?= h($v['vendor_name']) ?></strong><small><?= h($v['work_description'] ?: 'External service') ?></small></span>
                    <strong>₦<?= number_format($v['amount'], 2) ?></strong>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h3 class="section-title-sm">Conversation</h3>

            <div class="conversation">
                <?php if ($comments && mysqli_num_rows($comments) > 0): ?>
                <?php while ($c = mysqli_fetch_assoc($comments)): ?>
                <div class="comment-bubble <?= $c['is_internal'] ? 'internal-note' : '' ?>">
                    <div class="comment-head">
                        <strong><?= h($c['fullname']) ?></strong>
                        <?php if ($c['is_internal']): ?><span class="badge status-in-progress">Internal
                            Note</span><?php endif; ?>
                        <span class="comment-time"><?= h(timeAgo($c['created_at'])) ?></span>
                    </div>
                    <div class="comment-text"><?= nl2br(h($c['comment'])) ?></div>
                    <?php
                            $cAtt = mysqli_query($connection, "SELECT * FROM ticket_comment_attachments WHERE comment_id = {$c['id']}");
                            if ($cAtt && mysqli_num_rows($cAtt) > 0):
                            ?>
                    <div class="uploaded-files">
                        <?php while ($ca = mysqli_fetch_assoc($cAtt)): ?>
                        <div class="uploaded-file">
                            <span>📎 <?= h($ca['file_name']) ?></span>
                            <a href="../<?= h($ca['file_path']) ?>" target="_blank">View</a>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="empty-row">No replies yet.</div>
                <?php endif; ?>
            </div>

            <?php if (!$isTicketLocked): ?>

            <form method="POST" class="reply-form" enctype="multipart/form-data">

                <textarea name="comment" class="form-control" rows="3"
                    placeholder="Write a reply... use @name to mention someone" required></textarea>

                <div class="reply-form-footer">

                    <input type="file" name="reply_files[]" multiple class="form-control-file">

                    <?php if ($canSeeInternal): ?>
                    <label class="internal-toggle">
                        <input type="checkbox" name="is_internal" value="1">
                        IT Office
                    </label>
                    <?php endif; ?>

                    <button type="submit" name="action" value="add_comment" class="btn btn-primary">
                        Send Reply
                    </button>

                </div>

            </form>

            <?php else: ?>

            <div class="ticket-locked-message">
                <strong>Ticket resolved</strong>
                <span>This ticket is read-only and can no longer be edited.</span>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <div class="ticket-side-col">
        <div class="panel">
            <h3 class="section-title-sm">Details</h3>
            <div class="detail-row"><span>Ticket ID</span><strong><?= h($ticket['ticket_number']) ?></strong></div>
            <div class="detail-row">
                <span>Company</span>
                <strong><?= h($ticket['company_name'] ?? '-') ?></strong>
            </div>
            <div class="detail-row"><span>Status</span><strong><?= h($ticket['status']) ?></strong></div>
            <div class="detail-row"><span>Priority</span><strong><?= h($ticket['priority']) ?></strong></div>
            <div class="detail-row"><span>Category</span><strong><?= h($ticket['category']) ?></strong></div>
            <div class="detail-row">
                <span>IT Officer</span><strong><?= h($ticket['officer_name'] ?? 'Unassigned') ?></strong>
            </div>
            <div class="detail-row"><span>Date
                    Created</span><strong><?= h(date('M d, Y', strtotime($ticket['created_at']))) ?></strong></div>
            <?php if (!empty($ticket['resolved_at'])): ?>
            <div class="detail-row">
                <span>Resolved</span><strong><?= h(date('M d, Y', strtotime($ticket['resolved_at']))) ?></strong>
            </div>
            <?php endif; ?>
            <div class="ticket-cost-summary">
                <div class="detail-row"><span>Parts
                        Cost</span><strong>₦<?= number_format($partsTotal, 2) ?></strong>
                </div>
                <div class="detail-row"><span>Vendor
                        Cost</span><strong>₦<?= number_format($vendorTotal, 2) ?></strong>
                </div>
                <div class="detail-row ticket-cost-total"><span>Total Ticket
                        Cost</span><strong>₦<?= number_format($ticketCost, 2) ?></strong></div>
            </div>


        </div>

        <div class="panel">
            <h3 class="section-title-sm">Activity Log</h3>
            <div class="activity-log">
                <?php if ($activity && mysqli_num_rows($activity) > 0): ?>
                <?php while ($a = mysqli_fetch_assoc($activity)): ?>
                <div class="activity-item">
                    <span class="activity-dot"></span>
                    <div>
                        <div class="activity-text"><?= h($a['action']) ?> <span class="muted-sub">by
                                <?= h($a['fullname']) ?></span></div>
                        <div class="activity-time"><?= h(timeAgo($a['created_at'])) ?></div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="empty-row">No activity yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../inc/footer.php'; ?>