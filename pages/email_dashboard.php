<?php require '../inc/auth.php';
require '../inc/connection.php';
$pageTitle = 'Email Dashboard';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff'))); /* ============================================================ ADMIN ACCESS ONLY ============================================================ */
if ($role !== 'admin') {
    header('Location: support_tickets.php');
    exit;
} /* ============================================================ ESCAPE HELPER ============================================================ */
function emh($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
} /* ============================================================ FLASH ============================================================ */
function emailDashboardFlash(string $type, string $message): void
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $message;
} /* ============================================================ CSRF ============================================================ */
if (empty($_SESSION['email_dashboard_csrf'])) {
    $_SESSION['email_dashboard_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['email_dashboard_csrf']; /* ============================================================ RETRY EMAIL ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        emailDashboardFlash('error', 'Your request expired. Please refresh the page and try again.');
        header('Location: email_dashboard.php');
        exit;
    }
    $action = $_POST['action'] ?? ''; /* -------------------------------------------------------- RETRY -------------------------------------------------------- */
    if ($action === 'retry') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        if ($logId <= 0) {
            emailDashboardFlash('error', 'Invalid email delivery record.');
            header('Location: email_dashboard.php');
            exit;
        }
        require_once '../inc/EmailService.php';
        $emailService = new EmailService($connection);
        $success = $emailService->retryLoggedEmail($logId, $userId);
        if ($success) {
            emailDashboardFlash('success', 'Email retry was sent successfully.');
        } else {
            emailDashboardFlash('error', $emailService->getLastError() ?: 'Email retry failed.');
        }
        header('Location: email_dashboard.php');
        exit;
    }
} /* ============================================================ HELPERS ============================================================ */
function emailDashboardCount(mysqli $connection, string $sql): int
{
    $result = mysqli_query($connection, $sql);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
} /* ============================================================ GLOBAL KPIS ============================================================ */
$totalEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs ");
$sentEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE status = 'sent' ");
$failedEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE status = 'failed' ");
$todayEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE DATE(created_at) = CURDATE() ");
$retryAttempts = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE attempt_type = 'retry' ");
$facilityEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE channel = 'facility' ");
$itEmails = emailDashboardCount($connection, " SELECT COUNT(*) total FROM email_delivery_logs WHERE channel = 'it' ");
$successRate = $totalEmails > 0 ? round(($sentEmails / $totalEmails) * 100, 1) : 100; /* ============================================================ FILTER VALUES ============================================================ */
$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$channelFilter = trim($_GET['channel'] ?? '');
$attemptFilter = trim($_GET['attempt'] ?? '');
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? ''); /* ============================================================ BUILD FILTER ============================================================ */
$where = []; /* SEARCH */
if ($q !== '') {
    $escaped = mysqli_real_escape_string($connection, $q);
    $where[] = " ( l.recipient_email LIKE '%{$escaped}%' OR l.recipient_name LIKE '%{$escaped}%' OR l.sender_email LIKE '%{$escaped}%' OR l.sender_name LIKE '%{$escaped}%' OR l.subject LIKE '%{$escaped}%' OR l.failure_reason LIKE '%{$escaped}%' ) ";
} /* STATUS */
if (in_array($statusFilter, ['sent', 'failed'], true)) {
    $escaped = mysqli_real_escape_string($connection, $statusFilter);
    $where[] = "l.status = '{$escaped}'";
} /* CHANNEL */
if (in_array($channelFilter, ['it', 'facility'], true)) {
    $escaped = mysqli_real_escape_string($connection, $channelFilter);
    $where[] = "l.channel = '{$escaped}'";
} /* ATTEMPT */
if (in_array($attemptFilter, ['initial', 'retry'], true)) {
    $escaped = mysqli_real_escape_string($connection, $attemptFilter);
    $where[] = "l.attempt_type = '{$escaped}'";
} /* FROM */
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $escaped = mysqli_real_escape_string($connection, $fromDate);
    $where[] = "DATE(l.created_at) >= '{$escaped}'";
} /* TO */
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $escaped = mysqli_real_escape_string($connection, $toDate);
    $where[] = "DATE(l.created_at) <= '{$escaped}'";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : ''; /* ============================================================ DELIVERY LOG ============================================================ */
/* PAGINATION */
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$countResult = mysqli_query(
    $connection,
    "SELECT COUNT(*) AS total
     FROM email_delivery_logs l
     {$whereSql}"
);

$countRow = mysqli_fetch_assoc($countResult);
$totalRecords = (int) ($countRow['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$emailLogs = mysqli_query($connection, "
    SELECT
        l.*,
        retry_user.fullname AS retry_user_name,
        (
            SELECT COUNT(*)
            FROM email_delivery_logs successful_retry
            WHERE successful_retry.retried_from_id = l.id
            AND successful_retry.status = 'sent'
        ) AS successful_retry_count,
        (
            SELECT COUNT(*)
            FROM email_delivery_logs all_retry
            WHERE all_retry.retried_from_id = l.id
        ) AS retry_count
    FROM email_delivery_logs l
    LEFT JOIN users retry_user
        ON retry_user.id = l.retry_triggered_by
    {$whereSql}
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");

$startRecord = $totalRecords > 0 ? $offset + 1 : 0;
$endRecord = min($offset + $perPage, $totalRecords);
$failureSummary = mysqli_query($connection, " SELECT failure_reason, COUNT(*) total FROM email_delivery_logs WHERE status = 'failed' AND failure_reason IS NOT NULL AND failure_reason <> '' GROUP BY failure_reason ORDER BY total DESC LIMIT 5 ");
include '../inc/header.php'; ?>
<link rel="stylesheet" href="../assets/css/email-dashboard.css?v=1.0">
<div class="emd-page">
    <!-- ======================================================== HEADER ========================================================= -->
    <div class="emd-page-head">
        <div>
            <div class="emd-eyebrow"> Messaging & Notifications </div>
            <h2> Email Delivery Dashboard </h2>
            <p> Monitor IT and Facility email delivery, failures, Microsoft Graph responses and retry activity. </p>
        </div>
        <div class="emd-head-actions"> <a href="email_settings.php" class="emd-btn emd-btn-light"> <i
                    class="fa-solid fa-gear"></i> Email Settings </a> </div>
    </div>
    <!-- ======================================================== FLASH ========================================================= -->
    <?php if (!empty($_SESSION['flash_text'])): ?>
        <div class="emd-alert <?= emh($_SESSION['flash_type']) ?>"> <?= emh($_SESSION['flash_text']) ?> </div>
        <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?> <?php endif; ?>
    <!-- ======================================================== KPIS ========================================================= -->
    <div class="emd-kpi-grid">
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon total"> <i class="fa-solid fa-envelope"></i> </div>
            <div> <span> Total Attempts </span> <strong> <?= number_format($totalEmails) ?> </strong> <small> All
                    delivery attempts </small> </div>
        </div>
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon sent"> <i class="fa-solid fa-circle-check"></i> </div>
            <div> <span> Successfully Sent </span> <strong> <?= number_format($sentEmails) ?> </strong> <small>
                    Accepted by Microsoft Graph </small> </div>
        </div>
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon failed"> <i class="fa-solid fa-circle-xmark"></i> </div>
            <div> <span> Failed </span> <strong> <?= number_format($failedEmails) ?> </strong> <small> Delivery
                    attempts requiring attention </small> </div>
        </div>
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon rate"> <i class="fa-solid fa-chart-line"></i> </div>
            <div> <span> Success Rate </span> <strong> <?= $successRate ?>% </strong> <small> Overall delivery
                    performance </small> </div>
        </div>
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon retry"> <i class="fa-solid fa-rotate"></i> </div>
            <div> <span> Retry Attempts </span> <strong> <?= number_format($retryAttempts) ?> </strong> <small> Manual
                    resend attempts </small> </div>
        </div>
        <div class="emd-kpi-card">
            <div class="emd-kpi-icon today"> <i class="fa-solid fa-calendar-day"></i> </div>
            <div> <span> Today </span> <strong> <?= number_format($todayEmails) ?> </strong> <small> Attempts made
                    today </small> </div>
        </div>
    </div>
    <?php if ($failureSummary && mysqli_num_rows($failureSummary)): ?>
        <section class="emd-panel emd-failure-panel">
            <div class="emd-panel-head">
                <div>
                    <h3> Most Common Failure Reasons </h3>
                    <p> Frequent Microsoft 365 or delivery problems recorded by the system. </p>
                </div>
            </div>
            <div class="emd-failure-summary"> <?php while ($failure = mysqli_fetch_assoc($failureSummary)): ?>
                    <div class="emd-failure-summary-row">
                        <div> <i class="fa-solid fa-triangle-exclamation"></i> <span> <?= emh($failure['failure_reason']) ?>
                            </span> </div> <strong> <?= number_format($failure['total']) ?> </strong>
                    </div> <?php endwhile; ?>
            </div>
        </section> <?php endif; ?>
    <!-- ======================================================== DELIVERY LOG ========================================================= -->
    <section class="emd-panel">
        <div class="emd-panel-head">
            <div>
                <h3> Email Delivery Log </h3>
                <p> View every sent, failed and retried email delivery attempt. </p>
            </div>
        </div>
        <!-- ==================================================== FILTERS ===================================================== -->
        <form method="GET" class="emd-filters">
            <div class="emd-search"> <i class="fa-solid fa-magnifying-glass"></i> <input type="text" name="q"
                    value="<?= emh($q) ?>" placeholder="Recipient, sender, subject or failure..."> </div> <select
                name="status">
                <option value=""> All Status </option>
                <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>> Sent </option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>> Failed </option>
            </select> <select name="channel">
                <option value=""> All Channels </option>
                <option value="it" <?= $channelFilter === 'it' ? 'selected' : '' ?>> IT Support </option>
                <option value="facility" <?= $channelFilter === 'facility' ? 'selected' : '' ?>> Facility Support
                </option>
            </select> <select name="attempt">
                <option value=""> All Attempts </option>
                <option value="initial" <?= $attemptFilter === 'initial' ? 'selected' : '' ?>> Initial </option>
                <option value="retry" <?= $attemptFilter === 'retry' ? 'selected' : '' ?>> Retry </option>
            </select> <input type="date" name="from" value="<?= emh($fromDate) ?>" title="From date"> <input type="date"
                name="to" value="<?= emh($toDate) ?>" title="To date"> <button type="submit"
                class="emd-btn emd-btn-primary"> <i class="fa-solid fa-filter"></i> Filter </button> <a
                href="email_dashboard.php" class="emd-btn emd-btn-light"> Reset </a>
        </form>
        <!-- ==================================================== TABLE ===================================================== -->
        <div class="emd-table-wrap">
            <table class="emd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th> Channel </th>
                        <th> Recipient </th>
                        <th> Subject </th>
                        <th> Sender </th>
                        <th> Status </th>
                        <th> Delivery Result </th>
                        <th> Attempt </th>
                        <th> Date </th>
                        <th> Action </th>
                    </tr>
                </thead>
                <tbody> <?php if ($emailLogs && mysqli_num_rows($emailLogs) > 0): ?>
                        <?php $sn = $offset + 1;
                        while ($email = mysqli_fetch_assoc($emailLogs)):
                            $successfulRetry = (int) ($email['successful_retry_count'] ?? 0) > 0; ?>
                            <tr>
                                <td class="emd-index"> <?= $sn++ ?> </td> <!-- CHANNEL -->
                                <td> <span class="emd-channel <?= emh($email['channel']) ?>">
                                        <?php if ($email['channel'] === 'facility'): ?> <i
                                                class="fa-solid fa-screwdriver-wrench"></i> Facility <?php else: ?> <i
                                                class="fa-solid fa-laptop"></i> IT <?php endif; ?> </span> </td> <!-- RECIPIENT -->
                                <td>
                                    <div class="emd-person"> <strong> <?= emh($email['recipient_name'] ?: 'Recipient') ?>
                                        </strong> <span> <?= emh($email['recipient_email']) ?> </span> </div>
                                </td> <!-- SUBJECT -->
                                <td>
                                    <div class="emd-subject"> <?= emh($email['subject']) ?>
                                        <?php if ((int) ($email['has_attachments'] ?? 0) === 1): ?> <small> <i
                                                    class="fa-solid fa-paperclip"></i> Attachment included </small> <?php endif; ?>
                                    </div>
                                </td> <!-- SENDER -->
                                <td>
                                    <div class="emd-person"> <strong> <?= emh($email['sender_name'] ?: '—') ?> </strong>
                                        <span> <?= emh($email['sender_email'] ?: '—') ?> </span>
                                    </div>
                                </td> <!-- STATUS -->
                                <td> <?php if ($email['status'] === 'sent'): ?> <span class="emd-status sent"> <i
                                                class="fa-solid fa-check"></i> Sent </span> <?php elseif ($successfulRetry): ?>
                                        <span class="emd-status recovered"> <i class="fa-solid fa-rotate"></i> Retried </span>
                                    <?php else: ?> <span class="emd-status failed"> <i class="fa-solid fa-xmark"></i> Failed
                                        </span> <?php endif; ?>
                                </td> <!-- DELIVERY RESULT -->
                                <td class="emd-result-cell"> <?php if ($email['status'] === 'failed'): ?>
                                        <?php if ($successfulRetry): ?>
                                            <div class="emd-recovered-text"> <i class="fa-solid fa-circle-check"></i> A later retry was
                                                delivered successfully. </div> <small> Original failure:
                                                <?= emh($email['failure_reason'] ?: 'Unknown error') ?> </small> <?php else: ?>
                                            <div class="emd-failure">
                                                <?= emh($email['failure_reason'] ?: 'Unknown delivery failure.') ?>
                                            </div>
                                            <?php if (!empty($email['http_code'])): ?> <small> HTTP
                                                    <?= (int) $email['http_code'] ?> </small> <?php endif; ?>             <?php endif; ?>
                                    <?php else: ?> <span class="emd-success-text"> <i class="fa-solid fa-circle-check"></i>
                                            Microsoft Graph accepted the message. </span>
                                        <?php if (!empty($email['http_code'])): ?> <small> HTTP
                                                <?= (int) $email['http_code'] ?> </small> <?php endif; ?>         <?php endif; ?>
                                </td> <!-- ATTEMPT -->
                                <td> <?php if ($email['attempt_type'] === 'retry'): ?> <span class="emd-attempt retry"> <i
                                                class="fa-solid fa-rotate"></i> Retry </span>
                                        <?php if (!empty($email['retried_from_id'])): ?> <small class="emd-retry-ref">
                                                Original #<?= (int) $email['retried_from_id'] ?> </small> <?php endif; ?>
                                        <?php if (!empty($email['retry_user_name'])): ?> <small class="emd-retry-ref"> By
                                                <?= emh($email['retry_user_name']) ?> </small> <?php endif; ?>         <?php else: ?> <span
                                            class="emd-attempt"> Initial </span>
                                        <?php if ((int) ($email['retry_count'] ?? 0) > 0): ?> <small class="emd-retry-ref">
                                                <?= (int) $email['retry_count'] ?> retry attempt(s) </small> <?php endif; ?>
                                    <?php endif; ?>
                                </td> <!-- DATE -->
                                <td class="emd-date"> <strong>
                                        <?= emh(date('M d, Y', strtotime($email['created_at']))) ?> </strong> <span>
                                        <?= emh(date('h:i A', strtotime($email['created_at']))) ?> </span> </td>
                                <!-- ACTION -->
                                <td> <?php if ($email['status'] === 'failed' && !$successfulRetry): ?>
                                        <form method="POST" class="emd-retry-form"
                                            onsubmit=" return confirm( 'Retry this failed email now?' ); "> <input type="hidden"
                                                name="csrf_token" value="<?= emh($csrfToken) ?>"> <input type="hidden" name="action"
                                                value="retry"> <input type="hidden" name="log_id" value="<?= (int) $email['id'] ?>">
                                            <button type="submit" class="emd-retry-btn"> <i class="fa-solid fa-rotate-right"></i>
                                                Retry </button>
                                        </form>
                                    <?php elseif ($successfulRetry): ?> <span class="emd-completed-retry"> <i
                                                class="fa-solid fa-check"></i> Resolved </span> <?php else: ?> <span
                                            class="emd-no-action"> — </span> <?php endif; ?>
                                </td>
                            </tr> <?php endwhile; ?> <?php else: ?>
                        <tr>
                            <td colspan="10" class="emd-empty"> <i class="fa-regular fa-envelope-open"></i> <strong> No
                                    email records found </strong> <span> Email attempts will appear here once notifications
                                    are sent. </span> </td>
                        </tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $pageParams = $_GET;
        unset($pageParams['page']);

        function emailPageUrl(array $params, int $page): string
        {
            $params['page'] = $page;
            return 'email_dashboard.php?' . http_build_query($params);
        }
        ?>

        <div class="emd-pagination">
            <div class="emd-pagination-info">
                <?= number_format($startRecord) ?>–<?= number_format($endRecord) ?>
                of <?= number_format($totalRecords) ?>
            </div>

            <div class="emd-pagination-actions">
                <?php if ($page > 1): ?>
                    <a href="<?= emh(emailPageUrl($pageParams, $page - 1)) ?>" class="emd-page-btn">
                        <i class="fa-solid fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>

                <span class="emd-page-current">
                    Page <?= $page ?> of <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= emh(emailPageUrl($pageParams, $page + 1)) ?>" class="emd-page-btn">
                        Next <i class="fa-solid fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        /* ===================================================== FADE FLASH MESSAGE ====================================================== */
        const alerts = document.querySelectorAll('.emd-alert');
        alerts.forEach(function (alert) {
            setTimeout(function () {
                alert.classList.add('fade-out');
                setTimeout(function () {
                    alert.remove();
                }, 500);
            }, 4000);
        }); /* ===================================================== PREVENT DOUBLE RETRY ====================================================== */
        document.querySelectorAll('.emd-retry-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                /* * confirm() runs before this because * it is on the form's onsubmit. */
                const button = form.querySelector('.emd-retry-btn');
                setTimeout(function () {
                    if (button) {
                        button.disabled = true;
                        button.innerHTML =
                            '<i class="fa-solid fa-spinner fa-spin"></i> Retrying...';
                    }
                }, 0);
            });
        });
    });
</script>
<?php include '../inc/footer.php'; ?>