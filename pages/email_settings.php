<?php require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';
$pageTitle = "Email Setup";
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));
if ($role !== 'admin') {
    header("Location: support_tickets.php");
    exit;
}
function responseMessage($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}
/** * Convert comma / semicolon / newline separated addresses * into a clean comma-separated string. */
function normalizeEmailList(string $value): string
{
    $parts = preg_split('/[\s,;]+/', trim($value));
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
    return implode(',', $parts);
}
/** * Validate a comma / semicolon / newline separated email list. * Blank is allowed unless $required = true. */
function validateEmailList(string $value, bool $required = false): bool
{
    $normalized = normalizeEmailList($value);
    if ($normalized === '') {
        return !$required;
    }
    foreach (explode(',', $normalized) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
    }
    return true;
}
/* ============================================================ LOAD CURRENT MICROSOFT 365 SETTINGS ============================================================ */
$current = null;
$res = mysqli_query($connection, "SELECT * FROM email_settings ORDER BY id DESC LIMIT 1");
if ($res) {
    $current = mysqli_fetch_assoc($res);
}
/* ============================================================ LOAD NOTIFICATION ROUTES ============================================================ */
$routes = ['it_ticket_created' => ['to_emails' => '', 'cc_emails' => ''], 'facility_ticket_created' => ['to_emails' => '', 'cc_emails' => '']];
$routeResult = mysqli_query($connection, " SELECT route_key, to_emails, cc_emails FROM email_notification_routes WHERE route_key IN ( 'it_ticket_created', 'facility_ticket_created' ) ");
while ($routeResult && ($route = mysqli_fetch_assoc($routeResult))) {
    $routes[$route['route_key']] = ['to_emails' => $route['to_emails'] ?? '', 'cc_emails' => $route['cc_emails'] ?? ''];
}
/* ============================================================ POST ACTIONS ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_settings') {
        $tenantId = trim($_POST['tenant_id'] ?? '');
        $clientId = trim($_POST['client_id'] ?? '');
        $clientSecretInput = trim($_POST['client_secret'] ?? '');

        $itSenderEmail = trim($_POST['it_sender_email'] ?? '');
        $itSenderName = trim($_POST['it_sender_name'] ?? 'Diva Cakes IT Support');
        $itPrimaryMailbox = trim($_POST['it_primary_mailbox'] ?? '');

        $facilitySenderEmail = trim($_POST['facility_sender_email'] ?? '');
        $facilitySenderName = trim($_POST['facility_sender_name'] ?? 'Diva Cakes Facility & Projects');
        $facilityPrimaryMailbox = trim($_POST['facility_primary_mailbox'] ?? '');

        $hrSenderEmail = trim($_POST['hr_sender_email'] ?? '');
        $hrSenderName = trim($_POST['hr_sender_name'] ?? 'Human Resources - Diva Cakes');
        $hrPrimaryMailbox = trim($_POST['hr_primary_mailbox'] ?? '');

        // Global fallback mailbox column — defaults to IT's primary mailbox.
        $primaryMailbox = $itPrimaryMailbox;

        $secretExpiresAt = trim($_POST['secret_expires_at'] ?? '');
        $itTo = normalizeEmailList($_POST['it_to_emails'] ?? '');
        $itCc = normalizeEmailList($_POST['it_cc_emails'] ?? '');
        $facilityTo = normalizeEmailList($_POST['facility_to_emails'] ?? '');
        $facilityCc = normalizeEmailList($_POST['facility_cc_emails'] ?? '');

        if (
            $tenantId === '' || $clientId === '' ||
            $itSenderEmail === '' || $facilitySenderEmail === '' || $hrSenderEmail === '' ||
            $itPrimaryMailbox === '' || $facilityPrimaryMailbox === '' || $hrPrimaryMailbox === '' ||
            !filter_var($itSenderEmail, FILTER_VALIDATE_EMAIL) ||
            !filter_var($facilitySenderEmail, FILTER_VALIDATE_EMAIL) ||
            !filter_var($hrSenderEmail, FILTER_VALIDATE_EMAIL) ||
            !filter_var($itPrimaryMailbox, FILTER_VALIDATE_EMAIL) ||
            !filter_var($facilityPrimaryMailbox, FILTER_VALIDATE_EMAIL) ||
            !filter_var($hrPrimaryMailbox, FILTER_VALIDATE_EMAIL)
        ) {
            responseMessage('error', 'Please fill in Tenant ID, Client ID, and a valid Sender Email + Primary Mailbox for IT Support, Facility, and HR.');
            header("Location: email_settings.php");
            exit;
        }

        if (!validateEmailList($itTo, true)) {
            responseMessage('error', 'IT Support "To" recipients contain an invalid email address.');
            header("Location: email_settings.php");
            exit;
        }
        if (!validateEmailList($itCc)) {
            responseMessage('error', 'IT Support CC recipients contain an invalid email address.');
            header("Location: email_settings.php");
            exit;
        }
        if (!validateEmailList($facilityTo, true)) {
            responseMessage('error', 'Facility Maintenance "To" recipients contain an invalid email address.');
            header("Location: email_settings.php");
            exit;
        }
        if (!validateEmailList($facilityCc)) {
            responseMessage('error', 'Facility Maintenance CC recipients contain an invalid email address.');
            header("Location: email_settings.php");
            exit;
        }

        $clientSecret = $clientSecretInput !== '' ? $clientSecretInput : ($current['client_secret'] ?? '');
        if ($clientSecret === '') {
            responseMessage('error', 'Client Secret is required the first time you set this up.');
            header("Location: email_settings.php");
            exit;
        }

        // These flags are no longer surfaced in the UI — always stored as 1
        // since every sender now always uses its own primary mailbox.
        $itIsAlias = 1;
        $facilityIsAlias = 1;
        $hrIsAlias = 1;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $expiresParam = $secretExpiresAt !== '' ? $secretExpiresAt : null;
        mysqli_begin_transaction($connection);
        try {
            $stmt = mysqli_prepare($connection, " INSERT INTO email_settings ( tenant_id, client_id, client_secret, primary_mailbox, it_sender_email, it_sender_name, it_is_alias, it_primary_mailbox, facility_sender_email, facility_sender_name, facility_is_alias, facility_primary_mailbox, hr_sender_email, hr_sender_name, hr_is_alias, hr_primary_mailbox, secret_expires_at, updated_by ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ");
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssisssisssissi",
                $tenantId,
                $clientId,
                $clientSecret,
                $primaryMailbox,
                $itSenderEmail,
                $itSenderName,
                $itIsAlias,
                $itPrimaryMailbox,
                $facilitySenderEmail,
                $facilitySenderName,
                $facilityIsAlias,
                $facilityPrimaryMailbox,
                $hrSenderEmail,
                $hrSenderName,
                $hrIsAlias,
                $hrPrimaryMailbox,
                $expiresParam,
                $userId
            );
            if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));

            $itRoute = 'it_ticket_created';
            $stmtIt = mysqli_prepare($connection, " INSERT INTO email_notification_routes ( route_key, to_emails, cc_emails, updated_by ) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE to_emails = VALUES(to_emails), cc_emails = VALUES(cc_emails), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP ");
            mysqli_stmt_bind_param($stmtIt, "sssi", $itRoute, $itTo, $itCc, $userId);
            if (!mysqli_stmt_execute($stmtIt)) throw new Exception(mysqli_stmt_error($stmtIt));

            $facilityRoute = 'facility_ticket_created';
            $stmtFacility = mysqli_prepare($connection, " INSERT INTO email_notification_routes ( route_key, to_emails, cc_emails, updated_by ) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE to_emails = VALUES(to_emails), cc_emails = VALUES(cc_emails), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP ");
            mysqli_stmt_bind_param($stmtFacility, "sssi", $facilityRoute, $facilityTo, $facilityCc, $userId);
            if (!mysqli_stmt_execute($stmtFacility)) throw new Exception(mysqli_stmt_error($stmtFacility));

            mysqli_commit($connection);
            $cacheFile = sys_get_temp_dir() . '/ms365_token_cache.json';
            if (file_exists($cacheFile)) @unlink($cacheFile);
            responseMessage('success', 'Email credentials and ticket notification routing saved successfully.');
        } catch (Throwable $e) {
            mysqli_rollback($connection);
            responseMessage('error', 'Unable to save email settings: ' . $e->getMessage());
        }
        header("Location: email_settings.php");
        exit;
    }

    if ($action === 'send_test') {
        $testTo = trim($_POST['test_email'] ?? '');
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            responseMessage('error', 'Enter a valid email address to send the test to.');
            header("Location: email_settings.php");
            exit;
        }
        require_once '../inc/EmailService.php';
        $emailService = new EmailService($connection);
        $ok = $emailService->sendNotification($testTo, 'Test Recipient', 'Support Desk — Test Email', 'This is a test email from the Support Ticket system. If you received this, your Microsoft 365 email setup is working correctly.');
        responseMessage($ok ? 'success' : 'error', $ok ? "Test email sent to {$testTo}." : 'Test email failed to send. Check your credentials and PHP error log.');
        header("Location: email_settings.php");
        exit;
    }
}
include '../inc/header.php'; ?>

<link rel="stylesheet" href="../assets/css/email-settings.css?v=1.0">
<div class="es-page">
    <div class="es-page-header">
        <div class="es-header-content">
            <div class="es-eyebrow">Administration</div>
            <h2>Email & Notification Setup</h2>
            <p>
                Configure Microsoft 365 email delivery and decide who receives
                or is copied on IT Support and Facility Maintenance tickets.
            </p>
        </div>
        <a href="email_dashboard.php" class="es-dashboard-btn">
            <i class="fa-solid fa-chart-line"></i>
            Email Dashboard
        </a>
    </div>
    <?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="es-alert <?= h($_SESSION['flash_type']) ?>"> <?= h($_SESSION['flash_text']) ?> </div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?> <?php endif; ?>
    <form method="POST" class="es-form"> <input type="hidden" name="action" value="save_settings">
        <!-- =================================================== MICROSOFT 365 CREDENTIALS ==================================================== -->
        <section class="es-card">
            <div class="es-card-header">
                <div class="es-step"> 1 </div>
                <div>
                    <h3> Microsoft 365 Credentials </h3>
                    <p> Shared app registration used to authenticate all outgoing mail. </p>
                </div>
            </div>
            <div class="es-grid">
                <div class="es-field"> <label> Tenant ID <span>*</span> </label> <input type="text" name="tenant_id"
                        value="<?= h($current['tenant_id'] ?? '') ?>" required> </div>
                <div class="es-field"> <label> Client (Application) ID <span>*</span> </label> <input type="text"
                        name="client_id" value="<?= h($current['client_id'] ?? '') ?>" required> </div>

                <div class="es-field es-span-2"> <label> Client Secret
                        <?= empty($current['client_secret']) ? '<span>*</span>' : '' ?> </label> <input type="password"
                        name="client_secret" autocomplete="new-password"
                        placeholder="<?= !empty($current['client_secret']) ? 'Leave blank to keep current secret' : 'Paste your client secret value' ?>">
                    <?php if (!empty($current['client_secret'])): ?> <small> A secret is already stored. Leave this
                        field blank unless you are replacing it. </small> <?php endif; ?> </div>
                <div class="es-field"> <label> Secret Expiry Date </label> <input type="date" name="secret_expires_at"
                        value="<?= h($current['secret_expires_at'] ?? '') ?>"> </div>
            </div>
        </section>

        <!-- =================================================== SENDER IDENTITIES ==================================================== -->
        <section class="es-card">
            <div class="es-card-header">
                <div class="es-step"> 2 </div>
                <div>
                    <h3> Sender Identities </h3>
                    <p> Every sender needs a Microsoft 365 mailbox it actually sends through — the "Primary Mailbox." If
                        the sender address isn't a real mailbox on its own (e.g. it's an alias), enter the real mailbox
                        here. Otherwise, just repeat the same address in both fields. </p>
                </div>
            </div>
            <div class="es-grid">
                <!-- IT -->
                <div class="es-field"> <label> IT Support Sender Email <span>*</span> </label> <input type="email"
                        name="it_sender_email" value="<?= h($current['it_sender_email'] ?? 'it@divacakes.com.ng') ?>"
                        required> </div>
                <div class="es-field"> <label> IT Support Primary Mailbox <span>*</span> </label> <input type="email"
                        name="it_primary_mailbox"
                        value="<?= h($current['it_primary_mailbox'] ?? $current['it_sender_email'] ?? 'it@divacakes.com.ng') ?>"
                        placeholder="it@divacakes.com.ng" required> </div>
                <div class="es-field"> <label> IT Support Sender Name </label> <input type="text" name="it_sender_name"
                        value="<?= h($current['it_sender_name'] ?? 'Diva Cakes IT Support') ?>"> </div>
                <div></div>

                <!-- FACILITY -->
                <div class="es-field"> <label> Facility Sender Email <span>*</span> </label> <input type="email"
                        name="facility_sender_email"
                        value="<?= h($current['facility_sender_email'] ?? 'facility@divacakes.com.ng') ?>" required>
                </div>
                <div class="es-field"> <label> Facility Primary Mailbox <span>*</span> </label> <input type="email"
                        name="facility_primary_mailbox"
                        value="<?= h($current['facility_primary_mailbox'] ?? $current['facility_sender_email'] ?? 'facility@divacakes.com.ng') ?>"
                        placeholder="facility@divacakes.com.ng" required> </div>
                <div class="es-field"> <label> Facility Sender Name </label> <input type="text"
                        name="facility_sender_name"
                        value="<?= h($current['facility_sender_name'] ?? 'Diva Cakes Facility & Projects') ?>"> </div>
                <div></div>

                <!-- HR -->
                <div class="es-field"> <label> HR Sender Email <span>*</span> </label> <input type="email"
                        name="hr_sender_email"
                        value="<?= h($current['hr_sender_email'] ?? 'hradminmgr@divacakes.com.ng') ?>" required> </div>
                <div class="es-field"> <label> HR Primary Mailbox <span>*</span> </label> <input type="email"
                        name="hr_primary_mailbox"
                        value="<?= h($current['hr_primary_mailbox'] ?? $current['hr_sender_email'] ?? 'hradminmgr@divacakes.com.ng') ?>"
                        placeholder="hradminmgr@divacakes.com.ng" required> </div>
                <div class="es-field"> <label> HR Sender Name </label> <input type="text" name="hr_sender_name"
                        value="<?= h($current['hr_sender_name'] ?? 'Human Resources - Diva Cakes') ?>"> </div>
                <div></div>
            </div>
        </section>

        <!-- =================================================== IT SUPPORT ROUTING ==================================================== -->
        <section class="es-card es-route-card">
            <div class="es-route-heading">
                <div class="es-route-icon it"> <i class="fa-solid fa-laptop-medical"></i> </div>
                <div>
                    <h3> IT Support Ticket Notifications </h3>
                    <p> These recipients are notified whenever a new IT Support ticket is raised. </p>
                </div>
            </div>
            <div class="es-grid">
                <div class="es-field es-span-2"> <label> Send To <span>*</span> </label> <textarea name="it_to_emails"
                        rows="2" required
                        placeholder="it@divacakes.com.ng, itmanager@divacakes.com.ng"><?= h($routes['it_ticket_created']['to_emails']) ?></textarea>
                    <small> Main recipients. Separate multiple email addresses with commas, semicolons, spaces, or new
                        lines. </small>
                </div>
                <div class="es-field es-span-2"> <label> Copy (CC) </label> <textarea name="it_cc_emails" rows="2"
                        placeholder="operations@divacakes.com.ng, manager@divacakes.com.ng"><?= h($routes['it_ticket_created']['cc_emails']) ?></textarea>
                    <small> Optional recipients who should be copied on every newly raised IT ticket. </small>
                </div>
            </div>
        </section>

        <!-- =================================================== FACILITY ROUTING ==================================================== -->
        <section class="es-card es-route-card">
            <div class="es-route-heading">
                <div class="es-route-icon facility"> <i class="fa-solid fa-screwdriver-wrench"></i> </div>
                <div>
                    <h3> Facility Maintenance Notifications </h3>
                    <p> These recipients are notified whenever a new Facility Maintenance request is raised. </p>
                </div>
            </div>
            <div class="es-grid">
                <div class="es-field es-span-2"> <label> Send To <span>*</span> </label> <textarea
                        name="facility_to_emails" rows="2" required
                        placeholder="facility@divacakes.com.ng, facilitymanager@divacakes.com.ng"><?= h($routes['facility_ticket_created']['to_emails']) ?></textarea>
                    <small> Main recipients for all newly raised Facility Maintenance requests. </small>
                </div>
                <div class="es-field es-span-2"> <label> Copy (CC) </label> <textarea name="facility_cc_emails" rows="2"
                        placeholder="projects@divacakes.com.ng, operations@divacakes.com.ng"><?= h($routes['facility_ticket_created']['cc_emails']) ?></textarea>
                    <small> Optional recipients who should be copied on each Facility Maintenance request. </small>
                </div>
            </div>
        </section>

        <!-- =================================================== EXPIRY WARNING ==================================================== -->
        <?php if (!empty($current['secret_expires_at']) && strtotime($current['secret_expires_at']) < strtotime('+30 days')): ?>
        <div class="es-alert error"> <strong> Microsoft 365 client secret expiring soon. </strong> The current secret
            expires on <?= h(date('M d, Y', strtotime($current['secret_expires_at']))) ?>. Create a replacement in
            Microsoft Entra ID before then. </div> <?php endif; ?>
        <div class="es-actions"> <button type="submit" class="es-btn es-btn-primary"> <i
                    class="fa-solid fa-floppy-disk"></i> Save Email Settings </button> </div>
    </form>

    <!-- ======================================================= TEST EMAIL ======================================================== -->
    <section class="es-card">
        <div class="es-card-header">
            <div class="es-step"> 3 </div>
            <div>
                <h3> Test Email Delivery </h3>
                <p> Confirm that the Microsoft 365 connection can send successfully. </p>
            </div>
        </div>
        <form method="POST" class="es-test-form"> <input type="hidden" name="action" value="send_test">
            <div class="es-field"> <label> Test Recipient </label> <input type="email" name="test_email"
                    placeholder="you@divacakes.com.ng" required> </div> <button type="submit"
                class="es-btn es-btn-light"> <i class="fa-solid fa-paper-plane"></i> Send Test Email </button>
        </form>
    </section>

    <!-- ======================================================= ROUTING EXPLANATION ======================================================== -->
    <section class="es-info-card">
        <div class="es-info-icon"> <i class="fa-solid fa-circle-info"></i> </div>
        <div> <strong> How the routing works </strong>
            <p> When the user submits an <b>IT Support</b> ticket, the system reads the <code>it_ticket_created</code>
                route. When the user submits a <b>Facility Maintenance</b> ticket, the system reads the
                <code>facility_ticket_created</code> route. The configured main recipients receive the message and the
                configured CC recipients are copied. Each department's <b>Primary Mailbox</b> is the actual mailbox
                Microsoft Graph authenticates against when sending — the message will still appear "From" the Sender
                Email above it.
            </p>
        </div>
    </section>
</div>

<?php include '../inc/footer.php'; ?>