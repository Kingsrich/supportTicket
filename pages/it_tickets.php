<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';

$pageTitle = "IT Support Tickets";

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'staff'; // staff | officer | admin


function responseMessage($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

function getUserDepartmentId($connection, $userId)
{
    $res = mysqli_query($connection, "SELECT department_id FROM users WHERE id = " . (int) $userId . " LIMIT 1");
    $row = mysqli_fetch_assoc($res);
    return (int) ($row['department_id'] ?? 0);
}

$departmentId = getUserDepartmentId($connection, $userId);
$isStaffOnly = $role === 'staff';
$canAssign = in_array($role, ['officer', 'admin']);
if (empty($_SESSION['create_ticket_token'])) {
    $_SESSION['create_ticket_token'] = bin2hex(random_bytes(32));
}

/* =========================
   HANDLE POST ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {

        $submittedToken = $_POST['submission_token'] ?? '';
        $sessionToken = $_SESSION['create_ticket_token'] ?? '';
        if (
            $submittedToken === '' ||
            $sessionToken === '' ||
            !hash_equals($sessionToken, $submittedToken)
        ) {
            responseMessage(
                'error',
                'This ticket has already been submitted or the form has expired.'
            );
            header("Location: it_tickets.php");
            exit;
        }

        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? '';
        $priority = $_POST['priority'] ?? 'Medium';
        $locationOffice = trim($_POST['location_office'] ?? '');
        $contactName = trim($_POST['contact_name'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');

        if (
            $subject === '' || $description === '' || $contactName === '' ||
            !filter_var($contactEmail, FILTER_VALIDATE_EMAIL) ||
            !in_array($category, TICKET_CATEGORIES) || !in_array($priority, TICKET_PRIORITIES)
        ) {
            responseMessage('error', 'Please fill in all required fields correctly, including a valid name and email.');
            header("Location: it_tickets.php?new=1");
            exit;
        }
        // Consume the token so this exact form cannot create another ticket.
        unset($_SESSION['create_ticket_token']);
        $tempNumber = 'TMP-' . uniqid();
        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO tickets (ticket_number, user_id, subject, description, category, priority, department_id, location_office, contact_name, contact_email, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New')"
        );
        mysqli_stmt_bind_param($stmt, "sissssisss", $tempNumber, $userId, $subject, $description, $category, $priority, $departmentId, $locationOffice, $contactName, $contactEmail);
        mysqli_stmt_execute($stmt);
        $ticketId = mysqli_insert_id($connection);

        $ticketNumber = generateTicketNumber($connection, $ticketId);
        $upd = mysqli_prepare($connection, "UPDATE tickets SET ticket_number = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $ticketNumber, $ticketId);
        mysqli_stmt_execute($upd);

        // Attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/tickets/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);

            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];

            foreach ($_FILES['attachments']['name'] as $index => $originalName) {
                if ($_FILES['attachments']['error'][$index] !== UPLOAD_ERR_OK)
                    continue;

                $tmpName = $_FILES['attachments']['tmp_name'][$index];
                $fileType = $_FILES['attachments']['type'][$index];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed))
                    continue;

                $safeName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                $newName = 'ticket_' . $ticketId . '_' . time() . '_' . $index . '_' . $safeName . '.' . $ext;
                $targetPath = $uploadDir . $newName;
                $dbPath = 'uploads/tickets/' . $newName;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $a = mysqli_prepare(
                        $connection,
                        "INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_type, uploaded_by) VALUES (?, ?, ?, ?, ?)"
                    );
                    mysqli_stmt_bind_param($a, "isssi", $ticketId, $originalName, $dbPath, $fileType, $userId);
                    mysqli_stmt_execute($a);
                }
            }
        }

        logActivity($connection, $ticketId, $userId, 'Ticket created');
        notifyUser($connection, $userId, $ticketId, 'created', "Your ticket {$ticketNumber} has been created.", false);

        // =====================================================
        // SEND EMAIL NOTIFICATIONS
        // =====================================================
        require_once '../inc/EmailService.php';
        $emailService = new EmailService($connection);

        // Get full ticket details for email
        $ticketDetailsRes = mysqli_query(
            $connection,
            "SELECT t.* FROM tickets t WHERE t.id = {$ticketId} LIMIT 1"
        );
        $ticketDetails = mysqli_fetch_assoc($ticketDetailsRes);

        // Email to the person who filled in the ticket form (name + email provided on the form)
        if ($ticketDetails && !empty($ticketDetails['contact_email'])) {
            $emailService->sendTicketCreated(
                $ticketDetails['contact_email'],
                $ticketDetails['contact_name'],
                $ticketDetails
            );
        }

        // Email to all admins
        $techRes = mysqli_query($connection, "SELECT id, fullname, email FROM users WHERE role IN ('officer','admin')");
        while ($tech = mysqli_fetch_assoc($techRes)) {
            if ((int) $tech['id'] !== $userId && !empty($tech['email'])) {
                $emailService->sendTicketCreated(
                    $tech['email'],
                    $tech['fullname'],
                    $ticketDetails
                );
            }
        }

        // Alert the configured IT Support address for every new ticket raised
        $settingsRes = mysqli_query($connection, "SELECT it_support_email FROM email_settings ORDER BY id DESC LIMIT 1");
        $settingsRow = $settingsRes ? mysqli_fetch_assoc($settingsRes) : null;
        $itSupportEmail = $settingsRow['it_support_email'] ?? '';

        $alreadyNotified = false;
        if (!empty($itSupportEmail)) {
            $checkRes = mysqli_query(
                $connection,
                "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($connection, $itSupportEmail) . "' AND role IN ('officer','admin') LIMIT 1"
            );
            $alreadyNotified = $checkRes && mysqli_num_rows($checkRes) > 0;
        }

        if (!empty($itSupportEmail) && !$alreadyNotified) {
            $emailService->sendTicketCreated(
                $itSupportEmail,
                'IT Support',
                $ticketDetails
            );
        }

        // Notify all admins that a new ticket needs triage (in-app only — email already sent via sendTicketCreated above)
        $techRes = mysqli_query($connection, "SELECT id FROM users WHERE role IN ('officer','admin')");
        while ($t = mysqli_fetch_assoc($techRes)) {
            if ((int) $t['id'] !== $userId) {
                notifyUser($connection, (int) $t['id'], $ticketId, 'created', "New ticket {$ticketNumber}: {$subject}", false);
            }
        }

        responseMessage('success', "Ticket {$ticketNumber} submitted successfully.");
        header("Location: it_tickets.php");
        exit;
    }

    if ($action === 'cancel_ticket') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $check = mysqli_query($connection, "SELECT * FROM tickets WHERE id = {$ticketId} LIMIT 1");
        $ticket = mysqli_fetch_assoc($check);

        if ($ticket && (int) $ticket['user_id'] === $userId && $ticket['status'] === 'New') {
            $upd = mysqli_prepare($connection, "UPDATE tickets SET status = 'Cancelled', updated_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd, "i", $ticketId);
            mysqli_stmt_execute($upd);
            logActivity($connection, $ticketId, $userId, 'Ticket cancelled by submitter');
            responseMessage('success', 'Ticket cancelled.');
        } else {
            responseMessage('error', 'This ticket can no longer be cancelled.');
        }
        header("Location: it_tickets.php");
        exit;
    }

    if ($action === 'assign_self' || $action === 'assign_officer') {
        if (!$canAssign) {
            responseMessage('error', 'You are not permitted to assign tickets.');
            header("Location: it_tickets.php");
            exit;
        }

        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $officerId = $action === 'assign_self' ? $userId : (int) ($_POST['officer_id'] ?? 0);

        $check = mysqli_query($connection, "SELECT * FROM tickets WHERE id = {$ticketId} LIMIT 1");
        $ticket = mysqli_fetch_assoc($check);

        if ($ticket && $officerId > 0) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tickets
                SET assigned_to = ?,
                    status = IF(status IN ('New','Open'), 'Assigned', status),
                    assigned_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "ii", $officerId, $ticketId);
            mysqli_stmt_execute($stmt);

            logActivity($connection, $ticketId, $userId, 'Ticket assigned');
            notifyUser($connection, $officerId, $ticketId, 'assigned', "Ticket {$ticket['ticket_number']} has been assigned to you.");
            notifyUser($connection, (int) $ticket['user_id'], $ticketId, 'updated', "Your ticket {$ticket['ticket_number']} has been assigned.");

            responseMessage('success', 'Ticket assigned.');
        } else {
            responseMessage('error', 'Unable to assign ticket.');
        }
        header("Location: it_tickets.php");
        exit;
    }
}

/* =========================
   SEARCH & FILTERS
========================= */
$search = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterOfficer = (int) ($_GET['officer'] ?? 0);
$filterDepartment = (int) ($_GET['department'] ?? 0);
$filterDate = $_GET['date'] ?? '';

$where = [];
if ($isStaffOnly) {
    $where[] = "t.user_id = {$userId}";
}
if ($search !== '') {
    $s = mysqli_real_escape_string($connection, $search);
    $where[] = "(t.ticket_number LIKE '%{$s}%' OR t.subject LIKE '%{$s}%' OR u.fullname LIKE '%{$s}%' OR d.department_name LIKE '%{$s}%' OR t.category LIKE '%{$s}%')";
}
if ($filterStatus !== '' && in_array($filterStatus, TICKET_STATUSES)) {
    $s = mysqli_real_escape_string($connection, $filterStatus);
    $where[] = "t.status = '{$s}'";
}
if ($filterPriority !== '' && in_array($filterPriority, TICKET_PRIORITIES)) {
    $s = mysqli_real_escape_string($connection, $filterPriority);
    $where[] = "t.priority = '{$s}'";
}
if ($filterOfficer > 0) {
    $where[] = "t.assigned_to = {$filterOfficer}";
}
if ($filterDepartment > 0) {
    $where[] = "t.department_id = {$filterDepartment}";
}
if ($filterDate !== '') {
    $d = mysqli_real_escape_string($connection, $filterDate);
    $where[] = "DATE(t.created_at) = '{$d}'";
}

$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$tickets = mysqli_query(
    $connection,
    "SELECT t.*,
        u.fullname AS submitter_name,
        d.department_name,
        a.fullname AS officer_name,
        GREATEST(t.created_at, COALESCE(t.updated_at, t.created_at)) AS latest_activity
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN departments d ON d.id = t.department_id
     LEFT JOIN users a ON a.id = t.assigned_to
     {$whereSql}
    ORDER BY latest_activity DESC, t.id DESC"
);

$departments = mysqli_query($connection, "SELECT id, department_name FROM departments ORDER BY department_name ASC");
$officers = mysqli_query($connection, "SELECT id, fullname FROM users WHERE role IN ('officer','admin') ORDER BY fullname ASC");

/* Workload widget: open ticket count per officer */
$workload = [];
if ($canAssign) {
    $wRes = mysqli_query(
        $connection,
        "SELECT u.id, u.fullname, COUNT(t.id) AS open_count
         FROM users u
         LEFT JOIN tickets t ON t.assigned_to = u.id AND t.status NOT IN ('Resolved','Closed','Cancelled')
         WHERE u.role IN ('officer','admin')
         GROUP BY u.id, u.fullname
         ORDER BY open_count DESC"
    );
    while ($w = mysqli_fetch_assoc($wRes))
        $workload[] = $w;
}

$totalOpenTickets = 0;
if ($canAssign) {
    $topen = mysqli_query(
        $connection,
        "SELECT COUNT(*) AS c FROM tickets WHERE status NOT IN ('Resolved','Closed','Cancelled')"
    );
    if ($topen)
        $totalOpenTickets = (int) (mysqli_fetch_assoc($topen)['c'] ?? 0);
}

/* Unread in-app notifications count */
$notifCount = 0;
$nRes = mysqli_query($connection, "SELECT COUNT(*) AS c FROM ticket_notifications WHERE user_id = {$userId} AND is_read = 0");
if ($nRes)
    $notifCount = (int) (mysqli_fetch_assoc($nRes)['c'] ?? 0);

/* =========================
   LOAD USERS FOR NAME AUTOCOMPLETE
========================= */
$usersList = [];
$usersRes = mysqli_query(
    $connection,
    "SELECT fullname, email FROM users WHERE fullname IS NOT NULL AND fullname != '' AND status ='active' ORDER BY fullname ASC"
);
if ($usersRes) {
    while ($u = mysqli_fetch_assoc($usersRes)) {
        $usersList[] = $u;
    }
}

/* =========================
   LOAD OUTLETS FOR AUTOCOMPLETE
========================= */
$outletsList = [];
$outletsRes = mysqli_query(
    $connection,
    "SELECT id, warehouse_name, company
     FROM outlets
     WHERE status = 'active'
     AND warehouse_name IS NOT NULL
     AND warehouse_name != ''
     ORDER BY warehouse_name ASC"
);
if ($outletsRes) {
    while ($o = mysqli_fetch_assoc($outletsRes)) {
        $outletsList[] = $o;
    }
}


include '../inc/header.php';
?>
<link rel="stylesheet" href="../assets/css/tickets.css">

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="message <?= h($_SESSION['flash_type']) ?>"><?= h($_SESSION['flash_text']) ?></div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div id="notifPanel" class="notif-panel hidden">
    <h4>Notifications</h4>
    <div class="notif-list">
        <?php
        $nres = mysqli_query($connection, "SELECT tn.*, t.ticket_number FROM ticket_notifications tn LEFT JOIN tickets t ON t.id = tn.ticket_id WHERE tn.user_id = {$userId} ORDER BY tn.created_at DESC LIMIT 15");
        if ($nres && mysqli_num_rows($nres) > 0):
            while ($n = mysqli_fetch_assoc($nres)):
                ?>
                <a href="ticket_view.php?id=<?= (int) $n['ticket_id'] ?>"
                    class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                    <span class="notif-msg"><?= h($n['message']) ?></span>
                    <span class="notif-time"><?= h(timeAgo($n['created_at'])) ?></span>
                </a>
                <?php
            endwhile;
        else: ?>
            <div class="notif-empty">No notifications yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['new'])): ?>
    <form method="POST" class="panel ticket-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_ticket">
        <input type="hidden" name="submission_token" value="<?= h($_SESSION['create_ticket_token']) ?>">
        <h3>Create Ticket</h3>

        <div class="form-section">
            <div class="form-section-title">1. Contact Details</div>
            <div class="form-grid">

                <div class="form-group autocomplete-wrap">
                    <label>Name <span class="req">*</span></label>
                    <input type="text" name="contact_name" id="contactNameInput" class="form-control"
                        placeholder="Type to search staff..." autocomplete="off" required>
                    <div class="autocomplete-results" id="contactNameResults">
                        <?php foreach ($usersList as $u): ?>
                            <div class="autocomplete-option" data-name="<?= h($u['fullname']) ?>"
                                data-email="<?= h($u['email'] ?? '') ?>" data-search="<?= h(strtolower($u['fullname'])) ?>">
                                <strong><?= h($u['fullname']) ?></strong>
                                <?php if (!empty($u['email'])): ?>
                                    <span class="autocomplete-meta"><?= h($u['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($usersList)): ?>
                            <div class="autocomplete-empty">No staff found.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email address <span class="req">*</span></label>
                    <input type="email" name="contact_email" id="contactEmailInput" class="form-control"
                        placeholder="you@divacakes.com.ng" required>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">2. Request Details</div>
            <div class="form-grid">
                <div class="form-group span-2">
                    <label>Subject / Title <span class="req">*</span></label>
                    <input type="text" name="subject" class="form-control" placeholder="Brief summary of the issue" required
                        maxlength="190">
                </div>
                <div class="form-group">
                    <label>Category <span class="req">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="">Select category</option>
                        <?php foreach (TICKET_CATEGORIES as $c): ?>
                            <option value="<?= h($c) ?>"><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority <span class="req">*</span></label>
                    <select name="priority" class="form-control" required>
                        <?php foreach (TICKET_PRIORITIES as $p): ?>
                            <option value="<?= h($p) ?>" <?= $p === 'Medium' ? 'selected' : '' ?>><?= h($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group autocomplete-wrap">
                    <label>Office</label>
                    <input type="text" name="location_office" id="officeInput" class="form-control"
                        placeholder="Type to search outlet..." autocomplete="off">
                    <div class="autocomplete-results" id="officeResults">
                        <?php foreach ($outletsList as $o): ?>
                            <div class="autocomplete-option" data-id="<?= (int) $o['id'] ?>"
                                data-name="<?= h($o['warehouse_name']) ?>"
                                data-search="<?= h(strtolower($o['warehouse_name'] . ' ' . ($o['company'] ?? ''))) ?>">
                                <strong><?= h($o['warehouse_name']) ?></strong>
                                <?php if (!empty($o['company'])): ?>
                                    <span class="autocomplete-meta"><?= h($o['company']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($outletsList)): ?>
                            <div class="autocomplete-empty">No outlets available. Add outlets first.</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">3. Description</div>
            <div class="form-grid">
                <div class="form-group span-2">
                    <label>Describe the issue <span class="req">*</span></label>
                    <textarea name="description" class="form-control" rows="4"
                        placeholder="What happened, when it started, what you did in an attempt to resolve. etc"
                        required></textarea>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">3. Attachments</div>
            <div class="form-grid">
                <div class="form-group span-2">
                    <label>Upload files (optional)</label>
                    <input type="file" name="attachments[]" class="form-control" multiple
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">
                </div>
            </div>
        </div>

        <div class="row-actions">
            <a href="it_tickets.php" class="btn btn-light">Cancel</a>
            <button type="submit" id="submitTicketBtn" class="btn btn-primary">
                Submit Ticket
            </button>
        </div>
    </form>
<?php endif; ?>

<?php if (!isset($_GET['new'])): ?>
    <div class="panel workload-panel">

        <div class="workload-row">
            <?php if ($canAssign && count($workload)): ?>
                <div class="workload-grid">
                    <div class="workload-card workload-total">
                        <span class="workload-name">All Open Tickets</span>
                        <span class="workload-count"><?= $totalOpenTickets ?> open</span>
                    </div>
                    <?php foreach ($workload as $w): ?>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="topbar-actions">

                <button class="icon-btn" id="notifBell" title="Notifications">
                    🔔
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-dot"><?= $notifCount ?></span>
                    <?php endif; ?>
                </button>

                <a href="it_tickets.php?new=1" class="btn btn-primary">
                    + New Ticket
                </a>

            </div>

        </div>

    </div>
<?php endif; ?>

<?php if (!isset($_GET['new'])): ?>
    <div class="panel tickets-panel">
        <form method="GET" class="filters-bar">
            <input type="text" name="q" class="form-control search-input"
                placeholder="Search by ticket ID, user, subject, department, category..." value="<?= h($search) ?>">

            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach (TICKET_STATUSES as $s): ?>
                    <option value="<?= h($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="priority" class="form-control">
                <option value="">All Priorities</option>
                <?php foreach (TICKET_PRIORITIES as $p): ?>
                    <option value="<?= h($p) ?>" <?= $filterPriority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($canAssign): ?>
                <select name="officer" class="form-control">
                    <option value="0">All</option>
                    <?php mysqli_data_seek($officers, 0);
                    while ($t = mysqli_fetch_assoc($officers)): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $filterOfficer === (int) $t['id'] ? 'selected' : '' ?>>
                            <?= h($t['fullname']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            <?php endif; ?>

            <select name="department" class="form-control">
                <option value="0">All Departments</option>
                <?php mysqli_data_seek($departments, 0);
                while ($d = mysqli_fetch_assoc($departments)): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $filterDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['department_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <input type="date" name="date" class="form-control" value="<?= h($filterDate) ?>">

            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="it_tickets.php" class="btn btn-light">Reset</a>
        </form>

        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ticket ID</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tickets && mysqli_num_rows($tickets) > 0): ?>
                        <?php $sn = 1;
                        while ($t = mysqli_fetch_assoc($tickets)): ?>
                            <tr class="clickable-row" onclick="window.location='ticket_view.php?id=<?= (int) $t['id'] ?>'">
                                <td><?= $sn++ ?></td>
                                <td><strong><?= h($t['ticket_number']) ?></strong></td>
                                <td><?= h($t['department_name'] ?? '—') ?></td>
                                <td><span class="badge <?= statusBadgeClass($t['status']) ?>"><?= h($t['status']) ?></span></td>
                                <td title="<?= h(date('M d, Y h:i A', strtotime($t['latest_activity']))) ?>">
                                    <?= h(timeAgo($t['latest_activity'])) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-row">No tickets found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    document.getElementById('notifBell')?.addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('notifPanel')?.classList.toggle('hidden');
    });

    document.addEventListener('click', function (e) {
        const panel = document.getElementById('notifPanel');
        const bell = document.getElementById('notifBell');
        if (!panel || panel.classList.contains('hidden')) return;
        if (!panel.contains(e.target) && !bell.contains(e.target)) {
            panel.classList.add('hidden');
        }
    });

    /* =========================
       OFFICE AUTOCOMPLETE
    ========================= */
    (function () {
        const input = document.getElementById('officeInput');
        const results = document.getElementById('officeResults');
        const options = results ? results.querySelectorAll('.autocomplete-option') : [];
        let currentHighlight = -1;

        if (!input || !results) return;

        // Use a class-based show/hide system
        function showResults() {
            results.classList.add('active');
        }

        function hideResults() {
            results.classList.remove('active');
            currentHighlight = -1;
            options.forEach(opt => opt.classList.remove('highlighted'));
        }

        function setOptionVisible(option, visible) {
            if (visible) {
                option.classList.remove('hidden-option');
                option.classList.add('visible-option');
            } else {
                option.classList.remove('visible-option');
                option.classList.add('hidden-option');
            }
        }

        function getVisibleOptions() {
            return Array.from(options).filter(o => o.classList.contains('visible-option'));
        }

        function filterOptions(query) {
            const q = query.toLowerCase().trim();
            let visibleCount = 0;

            options.forEach(option => {
                const searchText = option.dataset.search || '';
                if (searchText.includes(q)) {
                    setOptionVisible(option, true);
                    visibleCount++;
                } else {
                    setOptionVisible(option, false);
                }
            });

            if (visibleCount > 0) {
                showResults();
            } else {
                hideResults();
            }

            currentHighlight = -1;
        }

        function highlightNext() {
            const visible = getVisibleOptions();
            if (visible.length === 0) return;

            currentHighlight = (currentHighlight + 1) % visible.length;
            visible.forEach(o => o.classList.remove('highlighted'));
            const target = visible[currentHighlight];
            if (target) {
                target.classList.add('highlighted');
                target.scrollIntoView({
                    block: 'nearest',
                    behavior: 'smooth'
                });
            }
        }

        function highlightPrev() {
            const visible = getVisibleOptions();
            if (visible.length === 0) return;

            currentHighlight = currentHighlight <= 0 ? visible.length - 1 : currentHighlight - 1;
            visible.forEach(o => o.classList.remove('highlighted'));
            const target = visible[currentHighlight];
            if (target) {
                target.classList.add('highlighted');
                target.scrollIntoView({
                    block: 'nearest',
                    behavior: 'smooth'
                });
            }
        }

        function selectHighlighted() {
            const visible = getVisibleOptions();
            if (currentHighlight >= 0 && visible[currentHighlight]) {
                input.value = visible[currentHighlight].dataset.name;
                hideResults();
            }
        }

        // On focus, show all options
        input.addEventListener('focus', function () {
            if (this.value.trim() === '') {
                // Show all
                options.forEach(option => setOptionVisible(option, true));
                showResults();
            } else {
                filterOptions(this.value);
            }
        });

        // On typing, filter
        input.addEventListener('input', function () {
            filterOptions(this.value);
        });

        // Click an option
        options.forEach(option => {
            option.addEventListener('click', function () {
                input.value = this.dataset.name;
                hideResults();
            });
        });

        // Keyboard navigation
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightNext();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightPrev();
            } else if (e.key === 'Enter') {
                if (results.classList.contains('active') && currentHighlight >= 0) {
                    e.preventDefault();
                    selectHighlighted();
                }
            } else if (e.key === 'Escape') {
                hideResults();
            }
        });

        // Click outside to close
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                hideResults();
            }
        });
    })();

    /* =========================
       CONTACT NAME AUTOCOMPLETE
    ========================= */
    (function () {
        const input = document.getElementById('contactNameInput');
        const emailInput = document.getElementById('contactEmailInput');
        const results = document.getElementById('contactNameResults');
        const options = results ? results.querySelectorAll('.autocomplete-option') : [];
        let currentHighlight = -1;

        if (!input || !results) return;

        function showResults() {
            results.classList.add('active');
        }

        function hideResults() {
            results.classList.remove('active');
            currentHighlight = -1;
            options.forEach(opt => opt.classList.remove('highlighted'));
        }

        function setOptionVisible(option, visible) {
            option.classList.toggle('visible-option', visible);
            option.classList.toggle('hidden-option', !visible);
        }

        function getVisibleOptions() {
            return Array.from(options).filter(o => o.classList.contains('visible-option'));
        }

        function filterOptions(query) {
            const q = query.toLowerCase().trim();
            let visibleCount = 0;
            options.forEach(option => {
                const match = (option.dataset.search || '').includes(q);
                setOptionVisible(option, match);
                if (match) visibleCount++;
            });
            visibleCount > 0 ? showResults() : hideResults();
            currentHighlight = -1;
        }

        function selectOption(option) {
            input.value = option.dataset.name;
            if (emailInput && option.dataset.email) {
                emailInput.value = option.dataset.email;
            }
            hideResults();
        }

        input.addEventListener('focus', function () {
            if (this.value.trim() === '') {
                options.forEach(option => setOptionVisible(option, true));
                showResults();
            } else {
                filterOptions(this.value);
            }
        });

        input.addEventListener('input', function () {
            filterOptions(this.value);
        });

        options.forEach(option => {
            option.addEventListener('click', function () {
                selectOption(this);
            });
        });

        input.addEventListener('keydown', function (e) {
            const visible = getVisibleOptions();
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!visible.length) return;
                currentHighlight = (currentHighlight + 1) % visible.length;
                visible.forEach(o => o.classList.remove('highlighted'));
                visible[currentHighlight].classList.add('highlighted');
                visible[currentHighlight].scrollIntoView({
                    block: 'nearest'
                });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!visible.length) return;
                currentHighlight = currentHighlight <= 0 ? visible.length - 1 : currentHighlight - 1;
                visible.forEach(o => o.classList.remove('highlighted'));
                visible[currentHighlight].classList.add('highlighted');
                visible[currentHighlight].scrollIntoView({
                    block: 'nearest'
                });
            } else if (e.key === 'Enter') {
                if (results.classList.contains('active') && currentHighlight >= 0) {
                    e.preventDefault();
                    selectOption(visible[currentHighlight]);
                }
            } else if (e.key === 'Escape') {
                hideResults();
            }
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                hideResults();
            }
        });
    })();

    const ticketForm = document.querySelector('.ticket-form');
    ticketForm?.addEventListener('submit', function (event) {
        const submitButton = document.getElementById('submitTicketBtn');
        if (!submitButton) return;
        // Block any second submission while the first is processing.
        if (ticketForm.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        ticketForm.dataset.submitting = 'true';
        submitButton.disabled = true;
        submitButton.textContent = 'Submitting...';
        submitButton.setAttribute('aria-busy', 'true');
    });
</script>