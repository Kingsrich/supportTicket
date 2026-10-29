<?php require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';
require '../inc/facility_functions.php';
require_once '../inc/EmailService.php';
require_once '../inc/NotificationRouting.php';
require_once '../inc/it_assign.php';


$pageTitle = 'Support Tickets';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$uRes = mysqli_query($connection, "SELECT location_office FROM users WHERE id={$userId}");
$uData = mysqli_fetch_assoc($uRes);
$savedLocation = $uData['location_office'] ?? '';
$role = strtolower($_SESSION['role'] ?? 'staff');
$tab = $_GET['tab'] ?? 'it';
if (!in_array($tab, ['it', 'facility'], true))
    $tab = 'it';
$isNew = isset($_GET['new']);
if (empty($_SESSION['create_ticket_token']))
    $_SESSION['create_ticket_token'] = bin2hex(random_bytes(32));
facilityCsrfToken();
function sFlash($t, $m)
{
    $_SESSION['flash_type'] = $t;
    $_SESSION['flash_text'] = $m;
}
/** * Send a ticket notification to: *  - all configured route "To" recipients *  - all configured route "CC" recipients *  - the requester email * * Existing EmailService::sendNotification() sends one recipient per call, * so configured CC addresses are also delivered as individual notifications. * * Email failure must never prevent a successfully created ticket/request. */

function sendSupportRequestEmails(mysqli $connection, string $routeKey, string $requesterEmail, string $requesterName, string $subject, string $htmlBody, string $channel = 'it'): void
{
    try {
        $route = getEmailNotificationRoute($connection, $routeKey);
        $recipients = [];
        foreach (array_merge($route['to'] ?? [], $route['cc'] ?? []) as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = ['email' => $email, 'name' => 'Support Team'];
            }
        }
        if ($requesterEmail !== '' && filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            $recipients[strtolower($requesterEmail)] = ['email' => $requesterEmail, 'name' => $requesterName !== '' ? $requesterName : 'Requester'];
        }
        if (empty($recipients)) {
            return;
        }
        $emailService = new EmailService($connection);
        foreach ($recipients as $recipient) {
            $sent = $emailService->sendNotification($recipient['email'], $recipient['name'], $subject, $htmlBody, $channel);
            if (!$sent) {
                error_log('Support ticket email failed for ' . $recipient['email'] . ' using route ' . $routeKey);
            }
        }
    } catch (Throwable $e) {
        error_log('Support ticket email exception for route ' . $routeKey . ': ' . $e->getMessage());
    }
}

function supportTicketUrl(string $page, int $id): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');

    return $scheme . '://' . $host . $scriptDir . '/' . $page . '?id=' . $id;
}

function renderTicketListPanel(string $tab, $list, string $q, string $status, string $priorityFilter, string $fromDate, string $toDate, string $outletFilter, array $outlets): string
{
    $ticketCount = $list ? mysqli_num_rows($list) : 0;
    $returnParams = [
        'tab' => $tab
    ];

    if ($q !== '') {
        $returnParams['q'] = $q;
    }

    if ($status !== '') {
        $returnParams['status'] = $status;
    }

    if ($priorityFilter !== '') {
        $returnParams['priority'] = $priorityFilter;
    }

    if ($outletFilter !== '') {
        $returnParams['outlet'] = $outletFilter;
    }

    if ($fromDate !== '') {
        $returnParams['from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $returnParams['to_date'] = $toDate;
    }

    $returnUrl = 'support_tickets.php?' . http_build_query($returnParams);

    ob_start();
?>
    <section class="st-list-panel" id="ticketListPanel">
        <form method="GET" class="st-filter-bar" id="ticketFilters">
            <input type="hidden" name="tab" value="<?= facilityH($tab) ?>">

            <input type="text" name="q" class="st-filter-search" value="<?= facilityH($q) ?>"
                placeholder="Search tickets..." autocomplete="off">

            <select name="status" class="st-filter-select">
                <option value="">All Statuses</option>
                <?php $statuses = $tab === 'it' ? TICKET_STATUSES : FACILITY_STATUSES; ?>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= facilityH($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                        <?= facilityH($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="priority" class="st-filter-select">
                <option value="">All Priorities</option>
                <?php $priorities = $tab === 'it' ? TICKET_PRIORITIES : FACILITY_PRIORITIES; ?>
                <?php foreach ($priorities as $p): ?>
                    <option value="<?= facilityH($p) ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>>
                        <?= facilityH($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="outlet" class="st-filter-select">
                <option value="">All Outlets</option>

                <?php foreach ($outlets as $o): ?>
                    <?php $outletName = trim($o['warehouse_name'] ?? ''); ?>

                    <?php if ($outletName !== ''): ?>
                        <option value="<?= facilityH($outletName) ?>" <?= $outletFilter === $outletName ? 'selected' : '' ?>>
                            <?= facilityH($outletName) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <div class="st-date-inline">
                <span>From</span>
                <input type="date" name="from_date" value="<?= facilityH($fromDate) ?>">
            </div>

            <div class="st-date-inline">
                <span>To</span>
                <input type="date" name="to_date" value="<?= facilityH($toDate) ?>">
            </div>

        </form>

        <div class="st-list-summary">
            <span>
                <?= $tab === 'it' ? 'IT Tickets' : 'Maintenance Requests' ?>
            </span>

            <strong>
                <?= number_format($ticketCount) ?>
            </strong>
        </div>

        <div class="st-table-wrap">
            <table class="st-table">
                <thead>
                    <?php if ($tab === 'it'): ?>
                        <tr>
                            <th>#</th>
                            <th>Ticket</th>
                            <th>Outlet</th> <!-- ADDED THIS -->
                            <th>Department</th>
                            <th>Status</th>
                            <th>Modified</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>#</th>
                            <th>Request</th>
                            <th>Outlet</th>
                            <th>Asset</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Modified</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if ($list && mysqli_num_rows($list) > 0): ?>
                        <?php $sn = 1; ?>
                        <?php while ($r = mysqli_fetch_assoc($list)): ?>
                            <?php if ($tab === 'it'): ?>
                                <?php $itStatusClass = 'status-' . strtolower(str_replace(' ', '-', $r['status'])); ?>
                                <tr class="st-ticket-row"
                                    data-href="ticket_view.php?id=<?= (int) $r['id'] ?>&return=<?= rawurlencode($returnUrl) ?>">
                                    <td class="st-sn"><?= $sn++ ?></td>
                                    <td>
                                        <div class="st-request-main">
                                            <strong><?= facilityH($r['ticket_number']) ?></strong>
                                            <span><?= facilityH($r['subject'] ?? '') ?></span>
                                        </div>
                                    </td>
                                    <td><?= facilityH($r['location_office'] ?? '—') ?></td> <!-- ADDED THIS -->
                                    <td><?= facilityH($r['department_name'] ?? '—') ?></td>
                                    <td><span class="st-status <?= facilityH($itStatusClass) ?>"><?= facilityH($r['status']) ?></span>
                                    </td>
                                    <td class="st-muted"><?= facilityH(timeAgo($r['latest_activity'])) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $facilityStatusClass = 'status-' . strtolower(str_replace(' ', '-', $r['status']));
                                $priorityClass = 'priority-' . strtolower($r['priority']);
                                ?>
                                <tr class="st-ticket-row"
                                    data-href="maintenance_view.php?id=<?= (int) $r['id'] ?>&return=<?= rawurlencode($returnUrl) ?>">
                                    <td class="st-sn"><?= $sn++ ?></td>
                                    <td>
                                        <div class="st-request-main">
                                            <strong><?= facilityH($r['request_number']) ?></strong>
                                            <span><?= facilityH($r['subject']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= facilityH($r['warehouse_name']) ?></td>
                                    <td>
                                        <?php if (!empty($r['asset_code'])): ?>
                                            <div class="st-asset">
                                                <strong><?= facilityH($r['asset_code']) ?></strong>
                                                <span><?= facilityH($r['asset_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="st-muted">General Facility</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span
                                            class="st-priority <?= facilityH($priorityClass) ?>"><?= facilityH($r['priority']) ?></span>
                                    </td>
                                    <td><span
                                            class="st-status <?= facilityH($facilityStatusClass) ?>"><?= facilityH($r['status']) ?></span>
                                    </td>
                                    <td class="st-muted"><?= facilityH(timeAgo($r['latest_activity'])) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $tab === 'it' ? 6 : 7 ?>" class="st-empty">
                                <!-- UPDATED 5 to 6 -->
                                <div class="st-empty-state">
                                    <i class="fa-solid fa-inbox"></i>
                                    <strong>No requests found</strong>
                                    <span>Try adjusting your search or filters.</span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    return ob_get_clean();
}

$dep = 0;
$dr = mysqli_query($connection, "SELECT department_id, location_office FROM users WHERE id={$userId} LIMIT 1");
if ($dr && ($x = mysqli_fetch_assoc($dr))) {
    $dep = (int) ($x['department_id'] ?? 0);
    $userLocation = mysqli_real_escape_string($connection, $x['location_office'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_it_ticket') {
    $token = $_POST['submission_token'] ?? '';
    $known = $_SESSION['create_ticket_token'] ?? '';
    if (!$token || !$known || !hash_equals($known, $token)) {
        sFlash('error', 'This request has already been submitted or the form expired.');
        header('Location:support_tickets.php?tab=it');
        exit;
    }
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? '';
    $priority = $_POST['priority'] ?? 'Medium';
    $office = trim($_POST['location_office'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $name = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    if (
        $subject === '' ||
        $description === '' ||
        $company === '' ||
        $office === '' ||
        $name === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !in_array($category, TICKET_CATEGORIES, true) ||
        !in_array($priority, TICKET_PRIORITIES, true)
    ) {
        sFlash(
            'error',
            'Please complete all required IT fields, including Company and Office / Outlet.'
        );

        header('Location:support_tickets.php?tab=it&new=1');
        exit;
    }
    unset($_SESSION['create_ticket_token']);
    $tmp = 'TMP-' . uniqid();
    $s = mysqli_prepare($connection, "INSERT INTO tickets(ticket_number,user_id,subject,description,category,priority,department_id,location_office,contact_name,contact_email,status) VALUES (?,?,?,?,?,?,?,?,?,?,'New')");
    mysqli_stmt_bind_param($s, 'sissssisss', $tmp, $userId, $subject, $description, $category, $priority, $dep, $office, $name, $email);
    mysqli_stmt_execute($s);
    $id = mysqli_insert_id($connection);

    // Automatically save the selected outlet to the user's profile
    $updateUserLoc = mysqli_prepare($connection, "UPDATE users SET location_office = ? WHERE id = ?");
    mysqli_stmt_bind_param($updateUserLoc, "si", $office, $userId);
    mysqli_stmt_execute($updateUserLoc);

    $num = generateTicketNumber($connection, $id);
    $u = mysqli_prepare($connection, "UPDATE tickets SET ticket_number=? WHERE id=?");
    mysqli_stmt_bind_param($u, 'si', $num, $id);
    mysqli_stmt_execute($u);
    if (!empty($_FILES['attachments']['name'][0])) {
        $dir = __DIR__ . '/../uploads/tickets/';
        if (!is_dir($dir))
            mkdir($dir, 0755, true);
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];
        foreach ($_FILES['attachments']['name'] as $i => $original) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK)
                continue;
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true))
                continue;
            $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original, PATHINFO_FILENAME));
            $new = "ticket_{$id}_" . time() . "_{$i}_{$safe}.{$ext}";
            if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dir . $new)) {
                $path = 'uploads/tickets/' . $new;
                $type = $_FILES['attachments']['type'][$i] ?? '';
                $a = mysqli_prepare($connection, "INSERT INTO ticket_attachments(ticket_id,file_name,file_path,file_type,uploaded_by) VALUES (?,?,?,?,?)");
                mysqli_stmt_bind_param($a, 'isssi', $id, $original, $path, $type, $userId);
                mysqli_stmt_execute($a);
            }
        }
    }
    logActivity($connection, $id, $userId, 'Ticket created');
    notifyUser($connection, $userId, $id, 'created', "Your ticket {$num} has been created.", false); /* --------------------------------------------------------- EMAIL NOTIFICATION — IT TICKET CREATED ---------------------------------------------------------- */
    // Round-robin: pick the next IT officer (null if nobody is available)
    $assignedOfficerId = autoAssignTicket($connection, $id, $userId);
    $assignedOfficerName = '';
    if ($assignedOfficerId) {
        $oq = mysqli_query($connection, "SELECT fullname, email FROM users WHERE id = " . (int) $assignedOfficerId . " LIMIT 1");
        $officerRow = $oq ? mysqli_fetch_assoc($oq) : null;
        $assignedOfficerName = $officerRow['fullname'] ?? '';
    }

    $ticketUrl = supportTicketUrl('ticket_view.php', $id);
    $itEmailSubject = "New IT Support Ticket {$num} — {$subject}";
    $itEmailBody = " <div style='font-family:Arial,sans-serif;color:#1f2937;line-height:1.6'> <h2 style='margin:0 0 12px;color:#7c2d12'> New IT Support Ticket </h2> <p> An IT support ticket has been raised. </p> <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;max-width:650px'> <tr> <td style='font-weight:bold;width:160px'>Ticket</td> <td>" . htmlspecialchars($num) . "</td> </tr> <tr> <td style='font-weight:bold'>User</td> <td>" . htmlspecialchars($name) . "</td> </tr> <tr> <td style='font-weight:bold'>Email</td> <td>" . htmlspecialchars($email) . "</td> </tr> <tr> <td style='font-weight:bold'>Category</td> <td>" . htmlspecialchars($category) . "</td> </tr>
    <tr> <td style='font-weight:bold'>Priority</td>
    <tr><td style='font-weight:bold'>Assigned to</td><td>" . htmlspecialchars($assignedOfficerName ?: 'Awaiting assignment') . "</td></tr>
    <td>" . htmlspecialchars($priority) . "</td> </tr>
    <tr> <td style='font-weight:bold'>Company</td>
    <td>" . htmlspecialchars($company ?: '—') . "</td>
    </tr><tr> <td style='font-weight:bold'>Office / Outlet</td> <td>" . htmlspecialchars($office ?: '—') . "</td> </tr> <tr> <td style='font-weight:bold'>Subject</td> <td>" . htmlspecialchars($subject) . "</td> </tr> </table> <p style='margin-top:16px'> <strong>Description</strong><br> " . nl2br(htmlspecialchars($description)) . " </p>
    <p style='margin-top:22px'>
    <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES) . "'
       style='display:inline-block;
              background:#7c2d12;
              color:#ffffff;
              text-decoration:none;
              padding:11px 18px;
              border-radius:6px;
              font-weight:bold;
              font-size:14px;'>
        View IT Ticket
    </a>
</p>
    <p style='margin-top:18px;color:#64748b;font-size:13px'> This message was generated automatically by the Support Ticket System. </p> </div> ";
    sendSupportRequestEmails($connection, 'it_ticket_created', $email, $name, $itEmailSubject, $itEmailBody, 'it');
    if (!empty($officerRow['email']) && filter_var($officerRow['email'], FILTER_VALIDATE_EMAIL)) {
        try {
            (new EmailService($connection))->sendNotification(
                $officerRow['email'],
                $officerRow['fullname'],
                "Ticket {$num} assigned to you — {$subject}",
                $itEmailBody,
                'it'
            );
        } catch (Throwable $e) {
            error_log('Officer assignment email failed: ' . $e->getMessage());
        }
    }
    sFlash('success', "IT ticket {$num} submitted successfully.");
    header('Location:support_tickets.php?tab=it');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_facility_request') {
    if (!facilityValidateCsrf()) {
        sFlash('error', 'Invalid or expired request.');
        header('Location:support_tickets.php?tab=facility&new=1');
        exit;
    }
    $type = $_POST['request_type'] ?? 'breakdown';
    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $company = trim($_POST['company'] ?? '');
    $name = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $cat = (int) ($_POST['maintenance_category_id'] ?? 0);
    $priority = $_POST['priority'] ?? 'Medium';
    $operational = $_POST['equipment_operational'] ?? '';
    $issue = trim($_POST['issue_started_at'] ?? '');
    if (!in_array($type, ['breakdown', 'corrective', 'preventive', 'inspection', 'general_facility'], true) || $outletId <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $description === '' || !in_array($priority, FACILITY_PRIORITIES, true)) {
        sFlash('error', 'Please complete all required Facility fields.');
        header('Location:support_tickets.php?tab=facility&new=1');
        exit;
    }
    if ($assetId > 0) {
        $vr = mysqli_query($connection, "SELECT id FROM facility_assets WHERE id={$assetId} AND outlet_id={$outletId} AND operational_status<>'retired' LIMIT 1");
        if (!$vr || mysqli_num_rows($vr) === 0) {
            sFlash('error', 'Selected asset does not belong to this outlet.');
            header('Location:support_tickets.php?tab=facility&new=1');
            exit;
        }
    }
    $tmp = 'TMP-' . uniqid();
    $assetParam = $assetId ?: null;
    $catParam = $cat ?: null;
    $opParam = $operational === '' ? null : (int) $operational;
    $issueDb = $issue ? date('Y-m-d H:i:s', strtotime($issue)) : null;
    mysqli_begin_transaction($connection);
    try {
        $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_requests(request_number,request_type,asset_id,outlet_id,requested_by,contact_name,contact_email,subject,description,maintenance_category_id,priority,equipment_operational,issue_started_at,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'New')");
        mysqli_stmt_bind_param($s, 'ssiiissssisis', $tmp, $type, $assetParam, $outletId, $userId, $name, $email, $subject, $description, $catParam, $priority, $opParam, $issueDb);
        if (!mysqli_stmt_execute($s))
            throw new RuntimeException(mysqli_stmt_error($s));
        $id = mysqli_insert_id($connection);
        $num = facilityRequestNumber($id);
        $u = mysqli_prepare($connection, "UPDATE facility_maintenance_requests SET request_number=? WHERE id=?");
        mysqli_stmt_bind_param($u, 'si', $num, $id);
        mysqli_stmt_execute($u);
        if (!empty($_FILES['attachments']['name'][0])) {
            $dir = __DIR__ . '/../uploads/maintenance/';
            if (!is_dir($dir))
                mkdir($dir, 0755, true);
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];
            foreach ($_FILES['attachments']['name'] as $i => $original) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK)
                    continue;
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true))
                    continue;
                $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original, PATHINFO_FILENAME));
                $new = "maintenance_{$id}_" . time() . "_{$i}_{$safe}.{$ext}";
                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dir . $new)) {
                    $path = 'uploads/maintenance/' . $new;
                    $ft = $_FILES['attachments']['type'][$i] ?? '';
                    $a = mysqli_prepare($connection, "INSERT INTO facility_maintenance_attachments(maintenance_request_id,attachment_type,file_name,file_path,file_type,uploaded_by) VALUES (?,'Request Evidence',?,?,?,?)");
                    mysqli_stmt_bind_param($a, 'isssi', $id, $original, $path, $ft, $userId);
                    mysqli_stmt_execute($a);
                }
            }
        }
        if ($assetId > 0 && $opParam === 0) {
            $down = $issueDb ?: date('Y-m-d H:i:s');
            $d = mysqli_prepare($connection, "UPDATE facility_maintenance_requests SET downtime_started_at=? WHERE id=?");
            mysqli_stmt_bind_param($d, 'si', $down, $id);
            mysqli_stmt_execute($d);
            mysqli_query($connection, "UPDATE facility_assets SET operational_status='down' WHERE id={$assetId}");
        }
        facilityLog($connection, $id, $userId, 'Maintenance request created');
        facilityNotify($connection, $userId, $id, 'created', "Your maintenance request {$num} has been created.", $assetId ?: null);
        $rs = mysqli_query($connection, "SELECT DISTINCT fur.user_id FROM facility_user_roles fur JOIN users u ON u.id = fur.user_id AND u.status = 'active' WHERE fur.role_key IN ( 'facility_officer', 'facility_project_manager', 'facility_admin' )");
        while ($rs && ($r = mysqli_fetch_assoc($rs))) {
            if ((int) $r['user_id'] !== $userId) {
                facilityNotify($connection, (int) $r['user_id'], $id, 'created', "New maintenance request {$num}: {$subject}", $assetId ?: null);
            }
        } /* ----------------------------------------------------- EMAIL NOTIFICATION — FACILITY REQUEST CREATED ------------------------------------------------------ */
        $assetEmailText = 'General Facility';
        if ($assetId > 0) {
            $assetEmailResult = mysqli_query($connection, " SELECT asset_code, asset_name FROM facility_assets WHERE id = {$assetId} LIMIT 1 ");
            if ($assetEmailResult && ($assetEmailRow = mysqli_fetch_assoc($assetEmailResult))) {
                $assetEmailText = trim(($assetEmailRow['asset_code'] ?? '') . ' — ' . ($assetEmailRow['asset_name'] ?? ''));
            }
        }
        $outletEmailName = '';
        $outletEmailResult = mysqli_query($connection, " SELECT warehouse_name FROM outlets WHERE id = {$outletId} LIMIT 1 ");
        if ($outletEmailResult && ($outletEmailRow = mysqli_fetch_assoc($outletEmailResult))) {
            $outletEmailName = $outletEmailRow['warehouse_name'] ?? '';
        }
        $ticketUrl = supportTicketUrl('maintenance_view.php', $id);
        $facilityEmailSubject = "New Facility Maintenance Request {$num} — {$subject}";
        $facilityEmailBody = " <div style='font-family:Arial,sans-serif;color:#1f2937;line-height:1.6'> <h2 style='margin:0 0 12px;color:#7c2d12'> New Facility Maintenance Request </h2> <p> A Facility Maintenance request has been raised. </p> <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;max-width:650px'> <tr> <td style='font-weight:bold;width:160px'>Request</td> <td>" . htmlspecialchars($num) . "</td> </tr> <tr> <td style='font-weight:bold'>User</td> <td>" . htmlspecialchars($name) . "</td> </tr> <tr> <td style='font-weight:bold'>Email</td> <td>" . htmlspecialchars($email) . "</td> </tr> <tr> <td style='font-weight:bold'>Request Type</td> <td>" . htmlspecialchars(ucwords(str_replace('_', ' ', $type))) . "</td> </tr>
        <tr> <td style='font-weight:bold'>Priority</td>
        <td>" . htmlspecialchars($priority) . "</td> </tr>
        <tr>
        <td style='font-weight:bold'>Company</td>
        <td>" . htmlspecialchars($company ?: '—') . "</td>
        </tr>
        <tr> <td style='font-weight:bold'>Outlet</td>
        <td>" . htmlspecialchars($outletEmailName ?: '—') . "</td> </tr>
        <tr> <td style='font-weight:bold'>Asset</td>
        <td>" . htmlspecialchars($assetEmailText) . "</td> </tr>
        <tr> <td style='font-weight:bold'>Subject</td>
        <td>" . htmlspecialchars($subject) . "</td> </tr> </table> <p style='margin-top:16px'> <strong>Problem Description</strong><br> " . nl2br(htmlspecialchars($description)) . " </p>
        <p style='margin-top:22px'>
    <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES) . "'
       style='display:inline-block;
              background:#7c2d12;
              color:#ffffff;
              text-decoration:none;
              padding:11px 18px;
              border-radius:6px;
              font-weight:bold;
              font-size:14px;'>
        View Maintenance Request
    </a>
</p>
        <p style='margin-top:18px;color:#64748b;font-size:13px'> This message was generated automatically by the Support Ticket System. </p> </div> ";
        sendSupportRequestEmails($connection, 'facility_ticket_created', $email, $name, $facilityEmailSubject, $facilityEmailBody, 'facility');
        mysqli_commit($connection);
        sFlash('success', "Facility request {$num} submitted successfully.");
        header('Location:support_tickets.php?tab=facility');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($connection);
        sFlash('error', 'Unable to create maintenance request: ' . $e->getMessage());
        header('Location:support_tickets.php?tab=facility&new=1');
        exit;
    }
}
$users = [];
$ur = mysqli_query($connection, "SELECT fullname,email FROM users WHERE status='active' ORDER BY fullname");
while ($ur && ($x = mysqli_fetch_assoc($ur)))
    $users[] = $x;
$outlets = [];
$or = mysqli_query($connection, "SELECT id,warehouse_name,company,email FROM outlets WHERE status='active' ORDER BY warehouse_name");
while ($or && ($x = mysqli_fetch_assoc($or)))
    $outlets[] = $x;
$fc = mysqli_query($connection, "SELECT id,category_name FROM facility_maintenance_categories WHERE status='active' ORDER BY category_name");
$fa = mysqli_query($connection, "SELECT id,asset_code,asset_name,outlet_id FROM facility_assets WHERE operational_status<>'retired' ORDER BY asset_name");
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$priorityFilter = trim($_GET['priority'] ?? '');
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$outletFilter = trim($_GET['outlet'] ?? '');
if ($tab === 'it') {
    $w = [];
    if ($role === 'staff') {
        if (!empty($userLocation)) {
            // Show all tickets for their outlet
            $w[] = "t.location_office='{$userLocation}'";
        } else {
            // No outlet assigned? Only show their own tickets
            $w[] = "t.user_id={$userId}";
        }
    }

    if ($q !== '') {
        $e = mysqli_real_escape_string($connection, $q);
        $w[] = "(t.ticket_number LIKE '%{$e}%' OR t.subject LIKE '%{$e}%' OR u.fullname LIKE '%{$e}%')";
    }
    if ($status !== '' && in_array($status, TICKET_STATUSES, true)) {
        $e = mysqli_real_escape_string($connection, $status);
        $w[] = "t.status='{$e}'";
    }
    if ($priorityFilter !== '' && in_array($priorityFilter, TICKET_PRIORITIES, true)) {
        $e = mysqli_real_escape_string($connection, $priorityFilter);
        $w[] = "t.priority='{$e}'";
    }
    if (
        $fromDate !== '' &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)
    ) {
        $fromEscaped = mysqli_real_escape_string($connection, $fromDate);

        $w[] = "DATE(t.created_at) >= '{$fromEscaped}'";
    }

    if (
        $toDate !== '' &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)
    ) {
        $toEscaped = mysqli_real_escape_string($connection, $toDate);
        $w[] =
            "DATE(t.created_at) <= '{$toEscaped}'";
    }
    if ($outletFilter !== '') {
        $e = mysqli_real_escape_string($connection, $outletFilter);
        $w[] = "t.location_office='{$e}'";
    }
    $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $list = mysqli_query($connection, "SELECT t.*,d.department_name,GREATEST(t.created_at,COALESCE(t.updated_at,t.created_at)) latest_activity FROM tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN departments d ON d.id=t.department_id {$ws} ORDER BY latest_activity DESC,t.id DESC");
} else {
    $w = [];
    $priv = facilityCanManage($connection, $userId) || facilityHasRole($connection, $userId, 'facility_technician');
    if (!$priv)
        $w[] = "m.requested_by={$userId}";
    if ($q !== '') {
        $e = mysqli_real_escape_string($connection, $q);
        $w[] = "(m.request_number LIKE '%{$e}%' OR m.subject LIKE '%{$e}%' OR o.warehouse_name LIKE '%{$e}%' OR a.asset_name LIKE '%{$e}%')";
    }
    if ($status !== '' && in_array($status, FACILITY_STATUSES, true)) {
        $e = mysqli_real_escape_string($connection, $status);
        $w[] = "m.status='{$e}'";
    }
    if ($priorityFilter !== '' && in_array($priorityFilter, FACILITY_PRIORITIES, true)) {
        $e = mysqli_real_escape_string($connection, $priorityFilter);
        $w[] = "m.priority='{$e}'";
    }
    if (
        $fromDate !== '' &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)
    ) {
        $fromEscaped = mysqli_real_escape_string($connection, $fromDate);
        $w[] =
            "DATE(m.created_at) >= '{$fromEscaped}'";
    }


    if (
        $toDate !== '' &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)
    ) {
        $toEscaped = mysqli_real_escape_string($connection, $toDate);
        $w[] =
            "DATE(m.created_at) <= '{$toEscaped}'";
    }
    if ($outletFilter !== '') {
        $e = mysqli_real_escape_string($connection, $outletFilter);
        $w[] = "o.warehouse_name='{$e}'";
    }
    $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $list = mysqli_query($connection, "SELECT m.*,o.warehouse_name,a.asset_code,a.asset_name,GREATEST(m.created_at,COALESCE(m.updated_at,m.created_at)) latest_activity FROM facility_maintenance_requests m JOIN outlets o ON o.id=m.outlet_id LEFT JOIN facility_assets a ON a.id=m.asset_id {$ws} ORDER BY latest_activity DESC,m.id DESC");
}

if (($_GET['partial'] ?? '') === 'list') {
    echo renderTicketListPanel($tab, $list, $q, $status, $priorityFilter, $fromDate, $toDate, $outletFilter, $outlets);
    exit;
}

include '../inc/header.php'; ?>
<link rel="stylesheet" href="../assets/css/support-tickets.css?v=1.0">
<style>
    #ticketListPanel {
        transition: opacity .15s ease
    }

    .st-ticket-row {
        cursor: pointer
    }
</style> <?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="st-alert <?= facilityH($_SESSION['flash_type']) ?>"> <?= facilityH($_SESSION['flash_text']) ?> </div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?> <?php endif; ?>
<div class="st-page">
    <!-- ======================================================= HEADER ======================================================== -->
    <div class="st-page-header">
        <div>
            <!-- <h2>Support Tickets</h2>
            <p> Raise and track IT support or Facility Maintenance requests from one place. </p> -->
        </div> <?php if (!$isNew): ?> <a href="support_tickets.php?tab=<?= facilityH($tab) ?>&new=1"
                class="st-btn st-btn-primary"> <i class="fa-solid fa-plus"></i>
                <?= $tab === 'it' ? 'New IT Ticket' : 'New Maintenance Request' ?> </a> <?php endif; ?>
    </div>
    <!-- ======================================================= MODULE SWITCHER ======================================================== -->
    <div class="st-module-switcher"> <a href="support_tickets.php?tab=it"
            class="st-module-card <?= $tab === 'it' ? 'active' : '' ?>">
            <div class="st-module-icon it"> <i class="fa-solid fa-laptop-medical"></i> </div>
            <div> <strong>IT Support</strong> <span> Systems, devices, accounts, network and application issues. </span>
            </div> <i class="fa-solid fa-chevron-right st-module-arrow"></i>
        </a> <a href="support_tickets.php?tab=facility"
            class="st-module-card <?= $tab === 'facility' ? 'active' : '' ?>">
            <div class="st-module-icon facility"> <i class="fa-solid fa-screwdriver-wrench"></i> </div>
            <div> <strong>Facility Maintenance</strong> <span> Equipment faults, repairs, PM and general facility
                    issues. </span> </div> <i class="fa-solid fa-chevron-right st-module-arrow"></i>
        </a> </div>
    <!-- ======================================================= NEW IT TICKET ======================================================== -->
    <?php if ($isNew && $tab === 'it'): ?>
        <form method="POST" enctype="multipart/form-data" class="st-form-card" id="supportForm"> <input type="hidden"
                name="action" value="create_it_ticket"> <input type="hidden" name="submission_token"
                value="<?= facilityH($_SESSION['create_ticket_token']) ?>">
            <div class="st-form-header">
                <div class="st-form-icon it"> <i class="fa-solid fa-laptop-medical"></i> </div>
                <div>
                    <h3>New IT Support Ticket</h3>
                    <p> Provide enough information to help the IT team diagnose the issue quickly. </p>
                </div>
            </div>
            <section class="st-form-section">
                <div class="st-section-title"> <span>1</span>
                    <div>
                        <h4>User Information</h4>
                        <p>Your contact details for this request.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field"> <label> Name <span>*</span> </label> <input list="staffNames" name="contact_name"
                            required placeholder="Enter name"> </div>
                    <div class="st-field">
                        <label>Company<span>*</span></label>
                        <select id="itCompany" name="company" required>
                            <option value="">Select company</option>
                            <?php
                            $companyList = [];
                            foreach ($outlets as $o) {
                                $companyName = trim($o['company'] ?? '');
                                if ($companyName !== '')
                                    $companyList[$companyName] = $companyName;
                            }
                            ksort($companyList);
                            foreach ($companyList as $companyName):
                            ?>
                                <option value="<?= facilityH($companyName) ?>">
                                    <?= facilityH($companyName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="st-field">
                        <label>Office / Outlet <span>*</span></label>
                        <select name="location_office" id="itOutlet" required>
                            <option value="">Select outlet</option>
                            <?php foreach ($outlets as $o): ?>
                                <option value="<?= facilityH($o['warehouse_name']) ?>"
                                    <?= ($savedLocation === $o['warehouse_name']) ? 'selected' : '' ?>
                                    data-company="<?= facilityH($o['company'] ?? '') ?>"
                                    data-email="<?= facilityH($o['email'] ?? '') ?>">
                                    <?= facilityH($o['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="st-field"> <label> Email <span>*</span> </label>
                        <input type="email" name="contact_email" id="itContactEmail" required
                            placeholder="name@company.com">
                    </div>
                </div>
            </section>
            <section class="st-form-section">
                <div class="st-section-title"> <span>2</span>
                    <div>
                        <h4>Request Details</h4>
                        <p>Classify the IT issue and where it occurred.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field st-span-2"> <label> Subject <span>*</span> </label> <input name="subject" required
                            maxlength="190" placeholder="Brief summary of the issue"> </div>
                    <div class="st-field"> <label> Category <span>*</span> </label> <select name="category" required>
                            <option value=""> Select category </option> <?php foreach (TICKET_CATEGORIES as $c): ?>
                                <option> <?= facilityH($c) ?> </option> <?php endforeach; ?>
                        </select> </div>
                    <div class="st-field"> <label> Priority <span>*</span> </label> <select name="priority">
                            <?php foreach (TICKET_PRIORITIES as $p): ?>
                                <option <?= $p === 'Medium' ? 'selected' : '' ?>> <?= facilityH($p) ?> </option>
                            <?php endforeach; ?>
                        </select> </div>

                </div>
            </section>
            <section class="st-form-section">
                <div class="st-section-title"> <span>3</span>
                    <div>
                        <h4>Description & Evidence</h4>
                        <p>Explain the issue and attach any useful screenshots or files.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field st-span-2"> <label> Description <span>*</span> </label> <textarea
                            name="description" rows="4" required
                            placeholder="Describe what happened, when it started, and any steps already taken..."></textarea>
                    </div>
                    <div class="st-field st-span-2"> <label> Attachments </label> <input type="file" name="attachments[]"
                            multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt"> <small> Optional: PDF, Office
                            documents, images or text files. </small> </div>
                </div>
            </section>
            <div class="st-form-actions"> <a href="support_tickets.php?tab=it" class="st-btn st-btn-light"> Cancel </a>
                <button type="submit" class="st-btn st-btn-primary submit-support-btn"> <i
                        class="fa-solid fa-paper-plane"></i> Submit IT Ticket </button>
            </div>
        </form>
        <!-- ======================================================= NEW FACILITY REQUEST ======================================================== -->
    <?php elseif ($isNew && $tab === 'facility'): ?>
        <form method="POST" enctype="multipart/form-data" class="st-form-card" id="supportForm"> <input type="hidden"
                name="action" value="create_facility_request"> <input type="hidden" name="csrf_token"
                value="<?= facilityH(facilityCsrfToken()) ?>">
            <div class="st-form-header">
                <div class="st-form-icon facility"> <i class="fa-solid fa-screwdriver-wrench"></i> </div>
                <div>
                    <h3>New Facility Maintenance Request</h3>
                    <p> Report equipment breakdowns, corrective work, inspections or general facility issues. </p>
                </div>
            </div>
            <section class="st-form-section">
                <div class="st-section-title"> <span>1</span>
                    <div>
                        <h4>User Information</h4>
                        <p>Your contact details for this maintenance request.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field"> <label> Name <span>*</span> </label> <input list="staffNames" name="contact_name"
                            required placeholder="Enter requester name"> </div>
                    <div class="st-field"> <label> Email <span>*</span> </label>
                        <input type="email" name="contact_email" id="facilityContactEmail" required
                            placeholder="name@company.com">
                    </div>
                </div>
            </section>
            <section class="st-form-section">
                <div class="st-section-title"> <span>2</span>
                    <div>
                        <h4>Location & Asset</h4>
                        <p>Select the outlet and asset affected by the issue.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field">
                        <label>Company <span>*</span></label>
                        <select id="facilityCompany" name="company" required>
                            <option value="">Select company</option>
                            <?php
                            $companyList = [];
                            foreach ($outlets as $o) {
                                $companyName = trim($o['company'] ?? '');
                                if ($companyName !== '')
                                    $companyList[$companyName] = $companyName;
                            }
                            ksort($companyList);
                            foreach ($companyList as $companyName):
                            ?>
                                <option value="<?= facilityH($companyName) ?>"><?= facilityH($companyName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="st-field">
                        <label>Outlet <span>*</span></label>
                        <select name="outlet_id" id="facilityOutlet" required>
                            <option value="">Select outlet</option>
                            <?php foreach ($outlets as $o): ?>
                                <option value="<?= (int) $o['id'] ?>" data-company="<?= facilityH($o['company'] ?? '') ?>"
                                    data-email="<?= facilityH($o['email'] ?? '') ?>">
                                    <?= facilityH($o['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="st-field"> <label> Registered Asset </label> <select name="asset_id" id="facilityAsset">
                            <option value="0"> No asset / General facility issue </option>
                            <?php while ($fa && ($a = mysqli_fetch_assoc($fa))): ?>
                                <option value="<?= (int) $a['id'] ?>" data-outlet="<?= (int) $a['outlet_id'] ?>">
                                    <?= facilityH($a['asset_code'] . ' — ' . $a['asset_name']) ?>
                                </option> <?php endwhile; ?>
                        </select>
                        <!-- <small> Assets are filtered automatically by the selected outlet. </small> -->
                    </div>
                </div>
            </section>
            <section class="st-form-section">
                <div class="st-section-title"> <span>3</span>
                    <div>
                        <h4>Maintenance Details</h4>
                        <p>Classify the issue and indicate its operational impact.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field"> <label> Request Type <span>*</span> </label> <select name="request_type">
                            <option value="breakdown"> Breakdown </option>
                            <option value="corrective"> Corrective Maintenance </option>
                            <option value="preventive"> Preventive Maintenance </option>
                            <option value="inspection"> Inspection </option>
                            <option value="general_facility"> General Facility Issue </option>
                        </select> </div>
                    <div class="st-field"> <label> Category </label> <select name="maintenance_category_id">
                            <option value="0"> Select category </option>
                            <?php while ($fc && ($c = mysqli_fetch_assoc($fc))): ?>
                                <option value="<?= (int) $c['id'] ?>"> <?= facilityH($c['category_name']) ?> </option>
                            <?php endwhile; ?>
                        </select> </div>
                    <div class="st-field st-span-2"> <label> Subject <span>*</span> </label> <input name="subject" required
                            maxlength="190" placeholder="Brief description of the maintenance issue"> </div>
                    <div class="st-field"> <label> Priority <span>*</span> </label> <select name="priority">
                            <?php foreach (FACILITY_PRIORITIES as $p): ?>
                                <option <?= $p === 'Medium' ? 'selected' : '' ?>> <?= facilityH($p) ?> </option>
                            <?php endforeach; ?>
                        </select> </div>
                    <div class="st-field"> <label> Is equipment operational? </label> <select name="equipment_operational">
                            <option value=""> Unknown / N/A </option>
                            <option value="1"> Yes </option>
                            <option value="0"> No — equipment is down </option>
                        </select> </div>
                    <div class="st-field st-span-2"> <label> Issue Started </label> <input type="datetime-local"
                            name="issue_started_at"> </div>
                </div>
            </section>
            <section class="st-form-section">
                <div class="st-section-title"> <span>4</span>
                    <div>
                        <h4>Problem Description & Evidence</h4>
                        <p>Describe the problem, symptoms and operational impact.</p>
                    </div>
                </div>
                <div class="st-form-grid">
                    <div class="st-field st-span-2"> <label> Problem Description <span>*</span> </label> <textarea
                            name="description" rows="4" required
                            placeholder="Describe the fault, symptoms, impact on operations and any action already taken..."></textarea>
                    </div>
                    <div class="st-field st-span-2"> <label> Supporting Evidence </label> <input type="file"
                            name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt"> <small>
                            Attach photos, screenshots, quotations or supporting documents. </small> </div>
                </div>
            </section>
            <div class="st-form-actions"> <a href="support_tickets.php?tab=facility" class="st-btn st-btn-light"> Cancel
                </a> <button type="submit" class="st-btn st-btn-primary submit-support-btn"> <i
                        class="fa-solid fa-paper-plane"></i> Submit Maintenance Request </button> </div>
        </form>
        <!-- ======================================================= REQUEST LIST ======================================================== -->
    <?php else: ?>
        <div id="ticketListContainer">
            <?= renderTicketListPanel($tab, $list, $q, $status, $priorityFilter, $fromDate, $toDate, $outletFilter, $outlets) ?>
        </div>
    <?php endif; ?>
</div> <datalist id="staffNames"> <?php foreach ($users as $u): ?>
        <option value="<?= facilityH($u['fullname']) ?>"> <?= facilityH($u['email']) ?> </option> <?php endforeach; ?>
</datalist>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        let currentTab = <?= json_encode($tab) ?>;
        const isNewPage = <?= $isNew ? 'true' : 'false' ?>;
        const listContainer = document.getElementById('ticketListContainer');

        /* =========================================================
           COMPANY -> OUTLET -> EMAIL / ASSET
        ========================================================= */
        const itCompany = document.getElementById('itCompany');
        const itOutlet = document.getElementById('itOutlet');
        const itEmail = document.getElementById('itContactEmail');

        function filterItOutlets(clear = true) { // Add 'clear = true' here
            if (!itCompany || !itOutlet) return;
            const selectedCompany = itCompany.value;

            if (clear) {
                itOutlet.value = ''; // Only clear if we explicitly say to
            }

            [...itOutlet.options].forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                const allowed = selectedCompany !== '' && option.dataset.company === selectedCompany;
                option.hidden = !allowed;
                option.disabled = !allowed;
            });

            if (itEmail) itEmail.value = '';
        }


        function fillItEmail() {
            if (!itOutlet || !itEmail) return;
            const option = itOutlet.options[itOutlet.selectedIndex];
            itEmail.value = option?.dataset.email || '';
        }

        itCompany?.addEventListener('change', () => filterItOutlets(true));
        itOutlet?.addEventListener('change', fillItEmail);
        if (itCompany && itOutlet) filterItOutlets(false);

        const company = document.getElementById('facilityCompany');
        const outlet = document.getElementById('facilityOutlet');
        const asset = document.getElementById('facilityAsset');
        const facilityEmail = document.getElementById('facilityContactEmail');

        function filterOutlets() {
            if (!company || !outlet) return;
            const selectedCompany = company.value;
            outlet.value = '';

            [...outlet.options].forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                const allowed = selectedCompany !== '' && option.dataset.company === selectedCompany;
                option.hidden = !allowed;
                option.disabled = !allowed;
            });

            if (facilityEmail) facilityEmail.value = '';

            if (asset) {
                asset.value = '0';
                filterAssets();
            }
        }

        function filterAssets() {
            if (!outlet || !asset) return;
            const outletId = outlet.value;
            asset.value = '0';

            [...asset.options].forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                const allowed = outletId !== '' && option.dataset.outlet === outletId;
                option.hidden = !allowed;
                option.disabled = !allowed;
            });
        }

        function fillFacilityEmail() {
            if (!outlet || !facilityEmail) return;
            const option = outlet.options[outlet.selectedIndex];
            facilityEmail.value = option?.dataset.email || '';
        }

        company?.addEventListener('change', filterOutlets);
        outlet?.addEventListener('change', function() {
            filterAssets();
            fillFacilityEmail();
        });
        if (company && outlet) filterOutlets();

        /* =========================================================
           AJAX TICKET LIST
           Search/filter/tab switching refreshes only the list panel.
        ========================================================= */
        let searchTimer = null;
        let listController = null;

        function updateListLoading(loading) {
            const panel = document.getElementById('ticketListPanel');
            if (!panel) return;
            panel.style.opacity = loading ? '0.55' : '1';
            panel.style.pointerEvents = loading ? 'none' : '';
        }

        function updateBrowserUrl(params) {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('tab', currentTab);

            Object.entries(params).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    url.searchParams.set(key, value);
                }
            });

            history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
        }

        async function refreshTicketList(params = {}, pushUrl = true) {
            if (!listContainer) return;

            if (listController) listController.abort();
            listController = new AbortController();

            const query = new URLSearchParams();
            query.set('partial', 'list');
            query.set('tab', currentTab);

            Object.entries(params).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    query.set(key, value);
                }
            });

            updateListLoading(true);

            try {
                const response = await fetch('support_tickets.php?' + query.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store',
                    signal: listController.signal
                });

                if (!response.ok) throw new Error('Unable to refresh tickets.');

                listContainer.innerHTML = await response.text();
                bindTicketListEvents();

                if (pushUrl) updateBrowserUrl(params);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error(error);
                }
            } finally {
                updateListLoading(false);
            }
        }

        function currentFilterValues() {
            const form = document.getElementById('ticketFilters');
            if (!form) return {};
            const data = new FormData(form);

            return {
                q: (data.get('q') || '').trim(),
                status: data.get('status') || '',
                priority: data.get('priority') || '',
                outlet: data.get('outlet') || '',
                from_date: data.get('from_date') || '',
                to_date: data.get('to_date') || ''
            };
        }

        function bindTicketListEvents() {
            const form = document.getElementById('ticketFilters');
            if (!form) return;

            form.querySelectorAll('select, input[type="date"]').forEach(control => {
                control.addEventListener('change', function() {
                    refreshTicketList(currentFilterValues());
                });
            });

            const search = form.querySelector('input[name="q"]');
            search?.addEventListener('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() {
                    refreshTicketList(currentFilterValues());
                }, 350);
            });


            listContainer.querySelectorAll('.st-ticket-row').forEach(row => {
                row.addEventListener('click', function() {
                    if (row.dataset.href) window.location.href = row.dataset.href;
                });
            });
        }

        if (!isNewPage && listContainer) {
            bindTicketListEvents();

            document.querySelectorAll('.st-module-switcher a').forEach(link => {
                link.addEventListener('click', function(event) {
                    const url = new URL(link.href, window.location.href);
                    const nextTab = url.searchParams.get('tab');

                    if (nextTab !== 'it' && nextTab !== 'facility') return;

                    event.preventDefault();
                    currentTab = nextTab;

                    document.querySelectorAll('.st-module-switcher a').forEach(card => {
                        card.classList.toggle('active', card === link);
                    });

                    const newButton = document.querySelector('.st-page-header > a.st-btn-primary');
                    if (newButton) {
                        newButton.href = 'support_tickets.php?tab=' + currentTab + '&new=1';
                        newButton.innerHTML = '<i class="fa-solid fa-plus"></i> ' +
                            (currentTab === 'it' ? 'New IT Ticket' : 'New Maintenance Request');
                    }

                    refreshTicketList({});
                });
            });
        }

        /* =========================================================
           PREVENT DOUBLE FORM SUBMISSION
        ========================================================= */
        const form = document.getElementById('supportForm');
        const button = form?.querySelector('.submit-support-btn');

        form?.addEventListener('submit', function(event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = '1';

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            }
        });

        /* =========================================================
           AUTO HIDE FLASH ALERT
        ========================================================= */
        document.querySelectorAll('.st-alert').forEach(function(alert) {
            setTimeout(function() {
                alert.classList.add('fade-out');
                setTimeout(function() {
                    alert.remove();
                }, 600);
            }, 4000);
        });
    });
</script>
<?php include '../inc/footer.php'; ?>