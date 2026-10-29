<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? 'staff'));
$canAssign = in_array($role, ['officer', 'admin'], true);

if (!$canAssign) {
    header("Location: it_tickets.php");
    exit;
}

$reportMode = $_GET['mode'] ?? $_POST['report_mode'] ?? 'weekly';
$reportMode = in_array($reportMode, ['weekly', 'monthly'], true) ? $reportMode : 'weekly';
$pageTitle = $reportMode === 'monthly' ? 'Monthly IT Support Report' : 'Weekly IT Support Report';
$reportScope = $_GET['scope'] ?? $_POST['report_scope'] ?? 'all';
$reportScope = in_array($reportScope, ['mine', 'all'], true) ? $reportScope : 'all';

$scopeUserId = $reportScope === 'mine' ? $userId : 0;
$ticketScopeSql = $reportScope === 'mine' ? " AND t.assigned_to={$userId} " : '';
$simpleScopeSql = $reportScope === 'mine' ? " AND assigned_to={$userId} " : '';

function responseMessage($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

/* =========================================================
   LOGGED-IN USER = EMAIL SENDER
========================================================= */
$senderRes = mysqli_query($connection, "SELECT fullname,email FROM users WHERE id={$userId} LIMIT 1");
$senderUser = $senderRes ? mysqli_fetch_assoc($senderRes) : [];
$senderEmail = trim($senderUser['email'] ?? '');
$senderName = trim($senderUser['fullname'] ?? 'ICT Department');

/* =========================================================
   SAVE PENDING TICKET REASONS / NEXT ACTIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pending_reasons') {
    $weekStart = $_POST['week_start'] ?? '';
    if (!$weekStart || !strtotime($weekStart)) {
        responseMessage('error', 'Invalid reporting week.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}");
        exit;
    }

    $weekStart = date('Y-m-d', strtotime($weekStart));
    $reasons = (array) ($_POST['pending_reason'] ?? []);
    $actions = (array) ($_POST['next_action'] ?? []);
    $dates = (array) ($_POST['target_date'] ?? []);

    foreach ($reasons as $ticketId => $reason) {
        $ticketId = (int) $ticketId;
        $reason = trim($reason);
        $nextAction = trim($actions[$ticketId] ?? '');
        $targetDate = trim($dates[$ticketId] ?? '');
        $targetDate = $targetDate !== '' ? $targetDate : null;

        if ($ticketId <= 0 || $reason === '' || $nextAction === '')
            continue;

        $stmt = mysqli_prepare($connection, "
            INSERT INTO it_ticket_pending_reasons
            (ticket_id,report_week_start,reason,next_action,target_date,updated_by)
            VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            reason=VALUES(reason),
            next_action=VALUES(next_action),
            target_date=VALUES(target_date),
            updated_by=VALUES(updated_by),
            updated_at=NOW()
        ");
        mysqli_stmt_bind_param($stmt, 'issssi', $ticketId, $weekStart, $reason, $nextAction, $targetDate, $userId);
        mysqli_stmt_execute($stmt);
    }

    /* Refresh saved report targets immediately */
    $weekEsc = mysqli_real_escape_string($connection, $weekStart);

    $targetRes = mysqli_query($connection, "
    SELECT
    t.id AS ticket_id,
    t.ticket_number,
    t.subject,
    t.status,
    o.company AS company,
    o.warehouse_name AS outlet,
    p.reason,
    p.next_action,
    p.target_date
FROM tickets t
LEFT JOIN outlets o ON o.warehouse_name=t.location_office
LEFT JOIN it_ticket_pending_reasons p
    ON p.ticket_id=t.id
    AND p.report_week_start='{$weekEsc}'
WHERE t.status NOT IN('Resolved','Closed','Cancelled')
{$ticketScopeSql}
ORDER BY t.created_at ASC
");

    $nextWeekTargets = [];

    while ($targetRes && ($row = mysqli_fetch_assoc($targetRes))) {
        $nextWeekTargets[] = $row;
    }

    $targetsJson = mysqli_real_escape_string(
        $connection,
        json_encode($nextWeekTargets)
    );

    mysqli_query(
        $connection,
        "UPDATE it_ticket_weekly_reports
     SET next_week_targets='{$targetsJson}'
     WHERE week_start='{$weekEsc}'
     AND report_scope='{$reportScope}'
     AND scope_user_id={$scopeUserId}"
    );

    responseMessage('success', 'Outstanding ticket reasons and next-week targets saved.');
    header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
    exit;
}

/* =========================================================
   GENERATE / REFRESH WEEKLY REPORT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_report') {
    $weekStart = $_POST['week_start'] ?? '';

    if ($weekStart === '' || !strtotime($weekStart)) {
        responseMessage('error', 'Please choose a valid week start date.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}");
        exit;
    }

    $weekStart = date('Y-m-d', strtotime($weekStart));
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $recommendations = trim($_POST['recommendations'] ?? '');

    $wsEsc = mysqli_real_escape_string($connection, $weekStart);
    $weEsc = mysqli_real_escape_string($connection, $weekEnd);

    /* Raised */
    $r = mysqli_query($connection, "
    SELECT COUNT(*) c
    FROM tickets
    WHERE DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
    {$simpleScopeSql}
");
    $totalRaised = (int) (mysqli_fetch_assoc($r)['c'] ?? 0);

    /* Resolved / Closed */
    $r = mysqli_query($connection, "
        SELECT COUNT(*) c FROM tickets
        WHERE status IN('Resolved','Closed')
        AND(
            DATE(resolved_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
            OR DATE(closed_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        )
        {$simpleScopeSql}
    ");
    $totalResolved = (int) (mysqli_fetch_assoc($r)['c'] ?? 0);

    /* Pending */
    $r = mysqli_query($connection, "
        SELECT COUNT(*) c FROM tickets
        WHERE DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        AND status NOT IN('Resolved','Closed','Cancelled')
        {$simpleScopeSql}
    ");
    $totalPending = (int) (mysqli_fetch_assoc($r)['c'] ?? 0);

    /* Critical */
    $r = mysqli_query($connection, "
        SELECT COUNT(*) c FROM tickets
        WHERE priority='Critical'
        AND DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        {$simpleScopeSql}
    ");
    $totalCritical = (int) (mysqli_fetch_assoc($r)['c'] ?? 0);

    /* Avg response */
    $r = mysqli_query($connection, "
        SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,assigned_at)) mins
        FROM tickets
        WHERE assigned_at IS NOT NULL
        AND DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        {$simpleScopeSql}
    ");
    $avgMinutes = (float) (mysqli_fetch_assoc($r)['mins'] ?? 0);
    $avgResponseHours = round($avgMinutes / 60, 2);

    $slaPercent = $totalRaised > 0 ? round(($totalResolved / $totalRaised) * 100, 2) : 0;

    /* Status breakdown */
    $statusBreakdown = [];
    foreach (TICKET_STATUSES as $s)
        $statusBreakdown[$s] = 0;

    $sRes = mysqli_query($connection, "
        SELECT status,COUNT(*) c
        FROM tickets
        WHERE DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        {$simpleScopeSql}
        GROUP BY status
    ");
    while ($row = mysqli_fetch_assoc($sRes)) {
        $statusBreakdown[$row['status']] = (int) $row['c'];
    }

    unset(
        $statusBreakdown['Assigned'],
        $statusBreakdown['Waiting for User'],
        $statusBreakdown['Pending Vendor']
    );

    /* Category */
    $categoryBreakdown = [];
    $cRes = mysqli_query($connection, "
        SELECT category,COUNT(*) c
        FROM tickets
        WHERE DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        {$simpleScopeSql}
        GROUP BY category
        ORDER BY c DESC
    ");
    while ($row = mysqli_fetch_assoc($cRes)) {
        $categoryBreakdown[$row['category']] = (int) $row['c'];
    }

    /* Priority */
    $priorityBreakdown = [];
    foreach (TICKET_PRIORITIES as $p)
        $priorityBreakdown[$p] = 0;

    $pRes = mysqli_query($connection, "
        SELECT priority,COUNT(*) c
        FROM tickets
        WHERE DATE(created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'
        {$simpleScopeSql}
        GROUP BY priority
    ");
    while ($row = mysqli_fetch_assoc($pRes)) {
        $priorityBreakdown[$row['priority']] = (int) $row['c'];
    }

    /* Outstanding ticket snapshot */
    $outstanding = [];
    $oRes = mysqli_query($connection, "
    SELECT
    t.id,
    t.ticket_number,
    t.subject,
    t.description,
    t.priority,
    t.status,
    t.location_office,
    u.fullname AS submitter_name,
        o.company AS company_name,
        o.warehouse_name AS outlet_name,
        p.reason,
        p.next_action,
        p.target_date
    FROM tickets t
    LEFT JOIN users u ON u.id=t.user_id
    LEFT JOIN outlets o ON o.warehouse_name=t.location_office
    LEFT JOIN it_ticket_pending_reasons p
        ON p.ticket_id=t.id
        AND p.report_week_start='{$wsEsc}'
    WHERE t.status NOT IN('Resolved','Closed','Cancelled')
    {$ticketScopeSql}
    ORDER BY t.created_at ASC
    LIMIT 50
");

    while ($row = mysqli_fetch_assoc($oRes)) {
        $outstanding[] = $row;
    }

    /* Next-week targets automatically derived from pending tickets */
    $nextWeekTargets = [];
    foreach ($outstanding as $item) {
        $nextWeekTargets[] = [
            'ticket_id' => $item['id'],
            'ticket_number' => $item['ticket_number'],
            'subject' => $item['subject'],
            'company' => $item['company_name'] ?? '',
            'outlet' => $item['outlet_name'] ?? $item['location_office'] ?? '',
            'reason' => $item['reason'] ?? '',
            'next_action' => $item['next_action'] ?? '',
            'target_date' => $item['target_date'] ?? '',
            'status' => $item['status'] ?? ''
        ];
    }

    /* Weekly register with ticket costs */
    $ticketRegister = [];

    $trRes = mysqli_query($connection, "
    SELECT
        t.id,
        t.ticket_number,
        t.created_at,
        t.location_office,
        t.subject,
        t.description,
        t.priority,
        t.status,

        u.fullname AS submitter_name,

        COALESCE(o.company, '') AS company_name,
        COALESCE(o.warehouse_name, t.location_office) AS outlet_name,

        TIMESTAMPDIFF(
            MINUTE,
            t.created_at,
            COALESCE(t.resolved_at, t.closed_at)
        ) AS resolution_minutes,

        COALESCE(pc.parts_cost, 0) AS parts_cost,
        COALESCE(vc.vendor_cost, 0) AS vendor_cost,

        COALESCE(pc.parts_cost, 0)
        + COALESCE(vc.vendor_cost, 0) AS total_cost

    FROM tickets t

    LEFT JOIN users u
        ON u.id = t.user_id

    LEFT JOIN outlets o
        ON o.warehouse_name = t.location_office

    LEFT JOIN (
        SELECT
            ticket_id,
            SUM(quantity * unit_cost) AS parts_cost
        FROM ticket_parts
        GROUP BY ticket_id
    ) pc ON pc.ticket_id = t.id

    LEFT JOIN (
        SELECT
            ticket_id,
            SUM(amount) AS vendor_cost
        FROM ticket_vendor_costs
        GROUP BY ticket_id
    ) vc ON vc.ticket_id = t.id

    WHERE DATE(t.created_at) BETWEEN '{$wsEsc}' AND '{$weEsc}'

    AND t.status <> 'Cancelled'

    {$ticketScopeSql}

    ORDER BY t.created_at ASC
");

    while ($row = mysqli_fetch_assoc($trRes)) {
        $ticketRegister[] = $row;
    }

    $totalPartsCost = 0;
    $totalVendorCost = 0;
    foreach ($ticketRegister as $tr) {
        $totalPartsCost += (float) $tr['parts_cost'];
        $totalVendorCost += (float) $tr['vendor_cost'];
    }
    $totalSupportCost = $totalPartsCost + $totalVendorCost;

    $summary = "A total of <strong>{$totalRaised}</strong> IT support requests were submitted during the reporting period. "
        . "{$totalResolved} tickets were resolved, giving a completion rate of {$slaPercent}%. "
        . "{$totalPending} tickets remain open"
        . ($totalCritical > 0 ? ", including {$totalCritical} marked Critical." : ".");

    $statusJson = mysqli_real_escape_string($connection, json_encode($statusBreakdown));
    $categoryJson = mysqli_real_escape_string($connection, json_encode($categoryBreakdown));
    $priorityJson = mysqli_real_escape_string($connection, json_encode($priorityBreakdown));
    $outstandingJson = mysqli_real_escape_string($connection, json_encode($outstanding));
    $targetsJson = mysqli_real_escape_string($connection, json_encode($nextWeekTargets));
    $registerJson = mysqli_real_escape_string($connection, json_encode($ticketRegister));
    $recommendationsEsc = mysqli_real_escape_string($connection, $recommendations);
    $summaryEsc = mysqli_real_escape_string($connection, $summary);

    mysqli_query($connection, "
        INSERT INTO it_ticket_weekly_reports(
            week_start,
            week_end,
            generated_by,
            report_scope,
            scope_user_id,
            total_raised,
            total_resolved,
            total_pending,
            total_critical,
            total_parts_cost,
            total_vendor_cost,
            total_support_cost,
            avg_response_hours,
            sla_percent,
            status_breakdown,
            category_breakdown,
            priority_breakdown,
            outstanding_snapshot,
            next_week_targets,
            ticket_register_snapshot,
            recommendations,
            summary_text
        )
        VALUES(
            '{$wsEsc}',
            '{$weEsc}',
            {$userId},
            '{$reportScope}',
            {$scopeUserId},
            {$totalRaised},
            {$totalResolved},
            {$totalPending},
            {$totalCritical},
            {$totalPartsCost},
            {$totalVendorCost},
            {$totalSupportCost},
            {$avgResponseHours},
            {$slaPercent},
            '{$statusJson}',
            '{$categoryJson}',
            '{$priorityJson}',
            '{$outstandingJson}',
            '{$targetsJson}',
            '{$registerJson}',
            '{$recommendationsEsc}',
            '{$summaryEsc}'
        )
        ON DUPLICATE KEY UPDATE
            week_end=VALUES(week_end),
            generated_by=VALUES(generated_by),
            report_scope=VALUES(report_scope),
            scope_user_id=VALUES(scope_user_id),
            total_raised=VALUES(total_raised),
            total_resolved=VALUES(total_resolved),
            total_pending=VALUES(total_pending),
            total_critical=VALUES(total_critical),
            total_parts_cost=VALUES(total_parts_cost),
            total_vendor_cost=VALUES(total_vendor_cost),
            total_support_cost=VALUES(total_support_cost),
            avg_response_hours=VALUES(avg_response_hours),
            sla_percent=VALUES(sla_percent),
            status_breakdown=VALUES(status_breakdown),
            category_breakdown=VALUES(category_breakdown),
            priority_breakdown=VALUES(priority_breakdown),
            outstanding_snapshot=VALUES(outstanding_snapshot),
            next_week_targets=VALUES(next_week_targets),
            ticket_register_snapshot=VALUES(ticket_register_snapshot),
            recommendations=VALUES(recommendations),
            summary_text=VALUES(summary_text),
            created_at=NOW()
    ");

    responseMessage('success', 'Weekly IT report generated for ' . date('M d, Y', strtotime($weekStart)) . '.');
    header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
    exit;
}

/* =========================================================
   HELPER: CHECK PENDING TICKETS BEFORE EMAIL
========================================================= */
function missingPendingReasons($connection, $weekStart, $reportScope, $userId)
{
    $weekEsc = mysqli_real_escape_string($connection, $weekStart);
    $scopeSql = $reportScope === 'mine' ? " AND t.assigned_to=" . (int) $userId : '';

    $r = mysqli_query($connection, "
        SELECT COUNT(*) c
        FROM tickets t
        LEFT JOIN it_ticket_pending_reasons p
            ON p.ticket_id=t.id
            AND p.report_week_start='{$weekEsc}'
        WHERE t.status NOT IN('Resolved','Closed','Cancelled')
        {$scopeSql}
        AND(
            p.id IS NULL
            OR TRIM(COALESCE(p.reason,''))=''
            OR TRIM(COALESCE(p.next_action,''))=''
        )
    ");

    return (int) (mysqli_fetch_assoc($r)['c'] ?? 0);
}

/* =========================================================
   EMAIL WEEKLY REPORT AS PDF
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_report_pdf') {
    $weekStart = $_POST['week_start'] ?? '';
    $recipientEmail = trim($_POST['recipient_email'] ?? '');

    if ($weekStart === '' || !strtotime($weekStart) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Please select a valid week and recipient email.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Your logged-in user account does not have a valid email address.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    $weekStart = date('Y-m-d', strtotime($weekStart));
    $missing = missingPendingReasons($connection, $weekStart, $reportScope, $userId);

    if ($missing > 0) {
        responseMessage('error', "{$missing} unresolved ticket(s) still need a reason and next-week action before this report can be sent.");
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    $wsEsc = mysqli_real_escape_string($connection, $weekStart);

    $res = mysqli_query($connection, "
        SELECT r.*,u.fullname generated_by_name
        FROM it_ticket_weekly_reports r
        LEFT JOIN users u ON u.id=r.generated_by
        WHERE r.week_start='{$wsEsc}'
        AND r.report_scope='{$reportScope}'
        AND r.scope_user_id={$scopeUserId}
        LIMIT 1
    ");

    $reportToSend = mysqli_fetch_assoc($res);

    if (!$reportToSend) {
        responseMessage('error', 'No report found. Generate the report first.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    require_once '../inc/report_pdf.php';
    require_once '../inc/EmailService.php';

    $pdfContent = buildWeeklyReportPdf($reportToSend);
    $filename = 'it_report_' . $reportToSend['week_start'] . '.pdf';

    $emailService = new EmailService($connection);

    $ok = $emailService->sendReportPdf(
        $recipientEmail,
        $recipientEmail,
        'Weekly IT Support Report — ' . date('M d', strtotime($reportToSend['week_start'])) . ' to ' . date('M d, Y', strtotime($reportToSend['week_end'])),
        'Please find attached the Weekly IT Support Report for ' . date('M d', strtotime($reportToSend['week_start'])) . ' – ' . date('M d, Y', strtotime($reportToSend['week_end'])) . '.',
        $pdfContent,
        $filename,
        'it',
        $senderEmail,
        $senderName
    );

    responseMessage(
        $ok ? 'success' : 'error',
        $ok ? "Report emailed to {$recipientEmail} from {$senderEmail}." : 'Failed to send report email. Check the email dashboard/error log.'
    );

    header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
    exit;
}

/* =========================================================
   EMAIL WEEKLY REPORT AS HTML
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_report_html') {
    $weekStart = $_POST['week_start'] ?? '';
    $recipientEmail = trim($_POST['recipient_email'] ?? '');

    if ($weekStart === '' || !strtotime($weekStart) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Please select a valid week and recipient email.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Your logged-in user account does not have a valid email address.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    $weekStart = date('Y-m-d', strtotime($weekStart));
    $missing = missingPendingReasons($connection, $weekStart, $reportScope, $userId);

    if ($missing > 0) {
        responseMessage('error', "{$missing} unresolved ticket(s) still need a reason and next-week action before this report can be sent.");
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    $wsEsc = mysqli_real_escape_string($connection, $weekStart);

    $res = mysqli_query($connection, "
        SELECT r.*,u.fullname generated_by_name
        FROM it_ticket_weekly_reports r
        LEFT JOIN users u ON u.id=r.generated_by
        WHERE r.week_start='{$wsEsc}'
        AND r.report_scope='{$reportScope}'
        AND r.scope_user_id={$scopeUserId}
        LIMIT 1
    ");

    $reportToSend = mysqli_fetch_assoc($res);

    if (!$reportToSend) {
        responseMessage('error', 'No report found. Generate it first.');
        header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
        exit;
    }

    require_once '../inc/report_pdf.php';
    require_once '../inc/EmailService.php';

    $htmlBody = renderWeeklyReportHtml($reportToSend);
    $emailService = new EmailService($connection);

    $ok = $emailService->sendReportHtml(
        $recipientEmail,
        $recipientEmail,
        'Weekly IT Support Report — ' . date('M d', strtotime($reportToSend['week_start'])) . ' to ' . date('M d, Y', strtotime($reportToSend['week_end'])),
        $htmlBody,
        'it',
        $senderEmail,
        $senderName
    );

    responseMessage(
        $ok ? 'success' : 'error',
        $ok ? "Report emailed to {$recipientEmail} from {$senderEmail}." : 'Failed to send report email.'
    );

    header("Location: it_reports.php?mode=weekly&scope={$reportScope}&week={$weekStart}");
    exit;
}

/* =========================================================
   LOAD WEEKLY REPORT
========================================================= */
$selectedWeek = $_GET['week'] ?? '';
$report = null;

if ($selectedWeek !== '' && strtotime($selectedWeek)) {
    $selectedWeek = date('Y-m-d', strtotime($selectedWeek));
    $wsEsc = mysqli_real_escape_string($connection, $selectedWeek);

    $res = mysqli_query($connection, "
        SELECT r.*,u.fullname generated_by_name
        FROM it_ticket_weekly_reports r
        LEFT JOIN users u ON u.id=r.generated_by
        WHERE r.week_start='{$wsEsc}'
        AND r.report_scope='{$reportScope}'
        AND r.scope_user_id={$scopeUserId}
        LIMIT 1
    ");

    $report = mysqli_fetch_assoc($res);
}

/* Saved reports */
$savedReports = [];
$listRes = mysqli_query($connection, "
    SELECT week_start,week_end,total_raised,sla_percent
    FROM it_ticket_weekly_reports
    WHERE report_scope='{$reportScope}'
    AND scope_user_id={$scopeUserId}
    ORDER BY week_start DESC
    LIMIT 52
");

while ($row = mysqli_fetch_assoc($listRes)) {
    $savedReports[] = $row;
}

$defaultWeekStart = date('Y-m-d', strtotime('monday this week'));
$reasonWeek = $selectedWeek ?: $defaultWeekStart;
$reasonWeekEsc = mysqli_real_escape_string($connection, $reasonWeek);

/* Outstanding tickets requiring explanation */
$pendingTickets = mysqli_query($connection, "
    SELECT
        t.id,
        t.ticket_number,
        t.subject,
        t.description,
        t.location_office,
        t.priority,
        t.status,
        t.created_at,
        u.fullname AS submitter_name,
        COALESCE(o.warehouse_name,t.location_office) AS outlet_name,
        o.company AS company_name,
        p.reason,
        p.next_action,
        p.target_date
    FROM tickets t
    LEFT JOIN users u ON u.id=t.user_id
    LEFT JOIN outlets o ON o.warehouse_name=t.location_office
    LEFT JOIN it_ticket_pending_reasons p
        ON p.ticket_id=t.id
        AND p.report_week_start='{$reasonWeekEsc}'
    WHERE t.status NOT IN('Resolved','Closed','Cancelled')
    {$ticketScopeSql}
    ORDER BY FIELD(t.priority,'Critical','High','Medium','Low'),t.created_at ASC
");

$pendingTicketRows = [];
while ($pendingTickets && ($pendingRow = mysqli_fetch_assoc($pendingTickets))) {
    $pendingTicketRows[] = $pendingRow;
}

/* =========================================================
   MONTHLY REPORT
========================================================= */
$selectedMonth = $_GET['month'] ?? date('Y-m');
$monthlyReports = [];
$monthlyTotals = [
    'raised' => 0,
    'resolved' => 0,
    'pending' => 0,
    'critical' => 0,
    'parts_cost' => 0,
    'vendor_cost' => 0,
    'support_cost' => 0
];
$monthlyCompletion = 0;
$monthlyAvgResponse = 0;

if ($reportMode === 'monthly') {
    if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
        $selectedMonth = date('Y-m');
    }

    $monthStart = date('Y-m-01', strtotime($selectedMonth . '-01'));
    $monthEnd = date('Y-m-t', strtotime($selectedMonth . '-01'));

    $ms = mysqli_real_escape_string($connection, $monthStart);
    $me = mysqli_real_escape_string($connection, $monthEnd);

    $monthlyRes = mysqli_query($connection, "
        SELECT r.*,u.fullname generated_by_name
        FROM it_ticket_weekly_reports r
        LEFT JOIN users u ON u.id=r.generated_by
        WHERE r.week_start BETWEEN '{$ms}' AND '{$me}'
        AND r.report_scope='{$reportScope}'
        AND r.scope_user_id={$scopeUserId}
        ORDER BY r.week_start ASC
    ");

    $responseTotal = 0;
    $responseCount = 0;

    while ($r = mysqli_fetch_assoc($monthlyRes)) {
        $monthlyReports[] = $r;
        $monthlyTotals['raised'] += (int) $r['total_raised'];
        $monthlyTotals['resolved'] += (int) $r['total_resolved'];
        $monthlyTotals['critical'] += (int) $r['total_critical'];
        $monthlyTotals['parts_cost'] += (float) $r['total_parts_cost'];
        $monthlyTotals['vendor_cost'] += (float) $r['total_vendor_cost'];
        $monthlyTotals['support_cost'] += (float) $r['total_support_cost'];

        /* Latest weekly pending figure represents latest known backlog */
        $monthlyTotals['pending'] = (int) $r['total_pending'];

        $responseTotal += (float) $r['avg_response_hours'];
        $responseCount++;
    }

    $monthlyCompletion = $monthlyTotals['raised'] > 0
        ? round(($monthlyTotals['resolved'] / $monthlyTotals['raised']) * 100, 1)
        : 0;

    $monthlyAvgResponse = $responseCount > 0
        ? round($responseTotal / $responseCount, 2)
        : 0;
}

/* =========================================================
   EMAIL MONTHLY REPORT AS HTML
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_monthly_html') {
    $month = $_POST['month'] ?? date('Y-m');
    $recipientEmail = trim($_POST['recipient_email'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}$/', $month) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Enter a valid month and recipient email.');
        header("Location: it_reports.php?mode=monthly&scope={$reportScope}&month={$month}");
        exit;
    }

    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        responseMessage('error', 'Your logged-in account does not have a valid sender email.');
        header("Location: it_reports.php?mode=monthly&scope={$reportScope}&month={$month}");
        exit;
    }

    $monthStart = date('Y-m-01', strtotime($month . '-01'));
    $monthEnd = date('Y-m-t', strtotime($month . '-01'));
    $ms = mysqli_real_escape_string($connection, $monthStart);
    $me = mysqli_real_escape_string($connection, $monthEnd);

    $weeks = [];
    $r = mysqli_query($connection, "
        SELECT *
        FROM it_ticket_weekly_reports
        WHERE week_start BETWEEN '{$ms}' AND '{$me}'
        AND report_scope='{$reportScope}'
        AND scope_user_id={$scopeUserId}
        ORDER BY week_start
    ");

    while ($row = mysqli_fetch_assoc($r))
        $weeks[] = $row;

    if (!$weeks) {
        responseMessage('error', 'No weekly reports exist for that month.');
        header("Location: it_reports.php?mode=monthly&scope={$reportScope}&month={$month}");
        exit;
    }

    $raised = $resolved = $critical = 0;
    $pending = 0;

    foreach ($weeks as $w) {
        $raised += (int) $w['total_raised'];
        $resolved += (int) $w['total_resolved'];
        $critical += (int) $w['total_critical'];
        $pending = (int) $w['total_pending'];
    }

    $completion = $raised > 0 ? round(($resolved / $raised) * 100, 1) : 0;

    $html = "<h2>Monthly IT Support Report — " . htmlspecialchars(date('F Y', strtotime($month . '-01'))) . "</h2>";
    $html .= "<p>Prepared by " . htmlspecialchars($senderName) . "</p>";
    $html .= "<table cellpadding='8' cellspacing='0' style='border-collapse:collapse;width:100%;border:1px solid #ddd'>";
    $html .= "<tr><th>Raised</th><th>Resolved</th><th>Pending</th><th>Critical</th><th>Completion</th></tr>";
    $html .= "<tr><td>{$raised}</td><td>{$resolved}</td><td>{$pending}</td><td>{$critical}</td><td>{$completion}%</td></tr>";
    $html .= "</table><br>";
    $html .= "<h3>Weekly Breakdown</h3>";
    $html .= "<table cellpadding='8' cellspacing='0' style='border-collapse:collapse;width:100%;border:1px solid #ddd'>";
    $html .= "<tr><th>Week</th><th>Raised</th><th>Resolved</th><th>Pending</th><th>Critical</th><th>Completion Rate</th></tr>";

    foreach ($weeks as $w) {
        $html .= "<tr>";
        $html .= "<td>" . htmlspecialchars(date('M d', strtotime($w['week_start'])) . ' - ' . date('M d', strtotime($w['week_end']))) . "</td>";
        $html .= "<td>" . (int) $w['total_raised'] . "</td>";
        $html .= "<td>" . (int) $w['total_resolved'] . "</td>";
        $html .= "<td>" . (int) $w['total_pending'] . "</td>";
        $html .= "<td>" . (int) $w['total_critical'] . "</td>";
        $html .= "<td>" . htmlspecialchars($w['sla_percent']) . "%</td>";
        $html .= "</tr>";
    }

    $html .= "</table>";

    require_once '../inc/EmailService.php';
    $emailService = new EmailService($connection);

    $ok = $emailService->sendReportHtml(
        $recipientEmail,
        $recipientEmail,
        'Monthly IT Support Report — ' . date('F Y', strtotime($month . '-01')),
        $html,
        'it',
        $senderEmail,
        $senderName
    );

    responseMessage(
        $ok ? 'success' : 'error',
        $ok ? "Monthly report emailed to {$recipientEmail} from {$senderEmail}." : 'Monthly report email failed.'
    );

    header("Location: it_reports.php?mode=monthly&scope={$reportScope}&month={$month}");
    exit;
}

include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/reports.css">
<style>
    /* Professional report additions */
    .report-section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 14px
    }

    .report-section-head h3 {
        margin: 0 0 4px;
        font-size: 18px
    }

    .report-section-head p {
        margin: 0;
        color: #64748b;
        font-size: 13px
    }

    .report-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-weight: 700
    }

    .ticket-issue {
        max-width: 420px;
        white-space: normal;
        line-height: 1.45;
        color: #475569
    }

    .ticket-subject {
        min-width: 190px;
        font-weight: 600
    }

    .ticket-meta {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: 11px
    }

    .action-btn {
        white-space: nowrap
    }

    .pending-state {
        font-size: 12px;
        color: #64748b;
        margin-top: 5px
    }

    .pending-state.complete {
        color: #15803d
    }

    .report-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(15, 23, 42, .58);
        padding: 14px;
        overflow: auto
    }

    .report-modal.open {
        display: flex;
        align-items: center;
        justify-content: center
    }

    .report-modal-card {
        width: min(560px, 100%);
        max-height: 94vh;
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
        overflow: hidden
    }

    .report-modal-head {
        display: flex;
        justify-content: space-between;
        gap: 20px;
        padding: 14px 18px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc
    }

    .report-modal-head h3 {
        margin: 0 0 3px;
        font-size: 17px
    }

    .report-modal-head p {
        margin: 0;
        color: #64748b;
        font-size: 13px
    }

    .modal-close {
        border: 0;
        background: #e2e8f0;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        font-size: 19px;
        cursor: pointer
    }

    .report-modal-body {
        padding: 16px 18px;
        max-height: calc(90vh - 76px);
        overflow-y: auto
    }

    .modal-ticket-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc
    }

    .modal-ticket-summary div {
        font-size: 12px;
        color: #64748b
    }

    .modal-ticket-summary strong {
        display: block;
        margin-top: 3px;
        color: #0f172a;
        font-size: 13px
    }

    .modal-field {
        margin-bottom: 11px;
        font-size: 13px
    }

    .modal-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: 700;
        color: #334155;
        font-size: 13px
    }

    .modal-field textarea,
    .modal-field input {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 8px 10px;
        font: inherit;
        background: #fff
    }

    .modal-field textarea {
        min-height: 68px;
        max-height: 120px;
        resize: vertical
    }

    .modal-help {
        font-size: 10px;
        color: #94a3b8;
        margin-top: 3px;
        line-height: 1.3
    }

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding-top: 2px
    }

    .all-ticket-table td {
        vertical-align: top
    }

    .all-ticket-table th {
        white-space: nowrap
    }

    @media(max-width:800px) {
        .modal-ticket-summary {
            grid-template-columns: 1fr
        }

        .report-section-head {
            flex-direction: column
        }

        .all-ticket-table {
            min-width: 1100px
        }
    }

    @media print {

        .no-print,
        .report-modal {
            display: none !important
        }

        .report-sheet {
            box-shadow: none
        }

        .page-break {
            page-break-before: always
        }
    }
</style>

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="message <?= h($_SESSION['flash_type']) ?>"><?= h($_SESSION['flash_text']) ?></div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="report-topbar">
    <div>
        <h2 class="page-heading">IT Support Reports</h2>
        <p class="page-subheading">Weekly operational reporting and monthly management consolidation.</p>
    </div>
    <div class="report-sender">
        <span>Sending as</span>
        <strong><?= h($senderName) ?></strong>
        <small><?= h($senderEmail ?: 'No email configured') ?></small>
    </div>
</div>

<div class="report-mode-switch">
    <a href="it_reports.php?mode=weekly&scope=<?= h($reportScope) ?>"
        class="<?= $reportMode === 'weekly' ? 'active' : '' ?>">
        Weekly Report
    </a>

    <a href="it_reports.php?mode=monthly&scope=<?= h($reportScope) ?>"
        class="<?= $reportMode === 'monthly' ? 'active' : '' ?>">
        Monthly Report
    </a>
</div>
<div class="report-scope-switch">
    <a href="it_reports.php?mode=<?= h($reportMode) ?>&scope=mine"
        class="<?= $reportScope === 'mine' ? 'active' : '' ?>">
        My Assigned Tickets
    </a>

    <a href="it_reports.php?mode=<?= h($reportMode) ?>&scope=all" class="<?= $reportScope === 'all' ? 'active' : '' ?>">
        All IT Tickets
    </a>
</div>

<?php if ($reportMode === 'weekly'): ?>

    <div class="panel report-controls">
        <div class="report-controls-row">
            <form method="POST" class="inline-form report-generate-form">
                <input type="hidden" name="action" value="generate_report">
                <input type="hidden" name="report_mode" value="weekly">
                <input type="hidden" name="report_scope" value="<?= h($reportScope) ?>">
                <label class="toggle-label">Week Starting</label>
                <input type="date" name="week_start" class="form-control"
                    value="<?= h($selectedWeek ?: $defaultWeekStart) ?>">
                <button type="submit" class="btn btn-primary small-btn">Generate / Refresh</button>
            </form>

            <?php if ($report): ?>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="week_start" value="<?= h($report['week_start']) ?>">
                    <input type="hidden" name="report_scope" value="<?= h($reportScope) ?>">
                    <input type="email" name="recipient_email" class="form-control" placeholder="Recipient email" required>
                    <button type="submit" name="action" value="email_report_pdf" class="btn btn-success small-btn">Email
                        PDF</button>
                    <button type="submit" name="action" value="email_report_html" class="btn btn-info small-btn">Email
                        Message</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="report-controls-row">
            <?php if (count($savedReports)): ?>
                <form method="GET" class="inline-form">
                    <input type="hidden" name="mode" value="weekly">
                    <input type="hidden" name="scope" value="<?= h($reportScope) ?>">
                    <label class="toggle-label">Saved Reports</label>
                    <select name="week" class="form-control" onchange="this.form.submit()">
                        <option value="">Select week...</option>
                        <?php foreach ($savedReports as $r): ?>
                            <option value="<?= h($r['week_start']) ?>" <?= $selectedWeek === $r['week_start'] ? 'selected' : '' ?>>
                                <?= h(date('M d', strtotime($r['week_start']))) ?> -
                                <?= h(date('M d, Y', strtotime($r['week_end']))) ?>
                                (<?= (int) $r['total_raised'] ?> Raised)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <?php if ($report): ?>
                <button onclick="window.print()" class="btn btn-light small-btn">🖨 Print</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$report): ?>
        <div class="panel empty-row">
            <?= $selectedWeek !== '' ? 'No report has been generated for that week yet.' : 'Choose a week and generate a report.' ?>
        </div>
    <?php else: ?>

        <?php
        $statusBreakdown = json_decode($report['status_breakdown'] ?? '', true) ?: [];
        $categoryBreakdown = json_decode($report['category_breakdown'] ?? '', true) ?: [];
        $priorityBreakdown = json_decode($report['priority_breakdown'] ?? '', true) ?: [];
        $outstanding = json_decode($report['outstanding_snapshot'] ?? '', true) ?: [];
        $nextTargets = json_decode($report['next_week_targets'] ?? '', true) ?: [];

        /*
         * Pending Tasks must reflect the CURRENT ticket status.
         * A ticket that has since been Resolved, Closed or Cancelled
         * must no longer appear as a pending task.
         */
        $filteredTargets = [];

        foreach ($nextTargets as $target) {

            $ticketId = (int) ($target['ticket_id'] ?? 0);

            if ($ticketId <= 0) {
                continue;
            }

            $statusRes = mysqli_query(
                $connection,
                "SELECT status
         FROM tickets
         WHERE id = {$ticketId}
         LIMIT 1"
            );

            $liveTicket = $statusRes
                ? mysqli_fetch_assoc($statusRes)
                : null;

            // Ticket no longer exists
            if (!$liveTicket) {
                continue;
            }

            $liveStatus = trim((string) ($liveTicket['status'] ?? ''));

            // Only genuinely outstanding tickets belong in Pending Tasks
            if (
                in_array(
                    strtolower($liveStatus),
                    ['resolved', 'closed', 'cancelled'],
                    true
                )
            ) {
                continue;
            }

            // Keep snapshot status synchronized for display purposes
            $target['status'] = $liveStatus;

            $filteredTargets[] = $target;
        }

        $nextTargets = $filteredTargets;
        ?>

        <div class="panel report-sheet">
            <div class="report-header">
                <div>
                    <h2 class="page-heading">Weekly IT Support Report</h2>
                    <p class="page-subheading">
                        <?= h(date('M d', strtotime($report['week_start']))) ?> –
                        <?= h(date('M d, Y', strtotime($report['week_end']))) ?>
                        · Prepared by <?= h($report['generated_by_name'] ?? $senderName) ?>
                        · Generated <?= h(date('M d, Y g:i A', strtotime($report['created_at']))) ?>
                    </p>
                </div>
            </div>

            <?php $ticketRegister = json_decode($report['ticket_register_snapshot'] ?? '', true) ?: []; ?>

            <div class="form-section report-primary-section">
                <div class="report-section-head">
                    <div>
                        <h3>All Tickets</h3>
                        <p>Complete ticket register for the reporting week.</p>
                    </div>
                    <span class="report-count"><?= count($ticketRegister) ?></span>
                </div>
                <div class="table-wrap">
                    <table class="report-print-table all-ticket-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ticket ID</th>
                                <th>Outlet</th>
                                <th>Staff Who Raised It</th>
                                <th>Subject</th>
                                <th>Issue</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Resolution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($ticketRegister):
                                $sn = 1; ?>
                                <?php foreach ($ticketRegister as $tr): ?>
                                    <tr>
                                        <td><?= $sn++ ?></td>
                                        <td><a href="ticket_view.php?id=<?= (int) $tr['id'] ?>"
                                                target="_blank"><strong><?= h($tr['ticket_number']) ?></strong></a><span
                                                class="ticket-meta"><?= h(date('d M Y', strtotime($tr['created_at']))) ?></span></td>
                                        <td><?= h($tr['outlet_name'] ?? $tr['location_office'] ?? '—') ?></td>
                                        <td><?= h($tr['submitter_name'] ?? '—') ?></td>
                                        <td class="ticket-subject"><?= h($tr['subject'] ?? '—') ?></td>
                                        <td class="ticket-issue"><?= nl2br(h($tr['description'] ?? '—')) ?></td>
                                        <td><span
                                                class="badge <?= priorityBadgeClass($tr['priority']) ?>"><?= h($tr['priority']) ?></span>
                                        </td>
                                        <td><span class="badge <?= statusBadgeClass($tr['status']) ?>"><?= h($tr['status']) ?></span>
                                        </td>
                                        <td><?= in_array($tr['status'], ['Resolved', 'Closed'], true) ? h(formatDuration($tr['resolution_minutes'] ?? 0)) : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-row">All tickets raised during this reporting
                                        week.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-section no-print">
                <div class="report-section-head">
                    <div>
                        <h3>Outstanding Tickets</h3>
                        <p>Open tickets requiring management follow-up. Add or update the reason, next action and target date
                            from the action button.</p>
                    </div>
                    <span class="report-count"><?= count($pendingTicketRows) ?></span>
                </div>
                <div class="table-wrap">
                    <table class="report-print-table">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Outlet</th>
                                <th>Staff</th>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Follow-up</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pendingTicketRows): ?>
                                <?php foreach ($pendingTicketRows as $p):
                                    $hasPlan = trim((string) ($p['reason'] ?? '')) !== '' && trim((string) ($p['next_action'] ?? '')) !== ''; ?>
                                    <tr>
                                        <td><strong><?= h($p['ticket_number']) ?></strong><span
                                                class="ticket-meta"><?= h(date('d M Y', strtotime($p['created_at']))) ?></span></td>
                                        <td><?= h($p['outlet_name'] ?? $p['location_office'] ?? '—') ?></td>
                                        <td><?= h($p['submitter_name'] ?? '—') ?></td>
                                        <td><?= h($p['subject']) ?></td>
                                        <td><span
                                                class="badge <?= priorityBadgeClass($p['priority']) ?>"><?= h($p['priority']) ?></span>
                                        </td>
                                        <td><span class="badge <?= statusBadgeClass($p['status']) ?>"><?= h($p['status']) ?></span></td>
                                        <td>
                                            <div class="pending-state <?= $hasPlan ? 'complete' : '' ?>">
                                                <?= $hasPlan ? '✓ Follow-up plan recorded' : 'Action required' ?>
                                            </div>
                                            <?php if (!empty($p['target_date'])): ?><span class="ticket-meta">Target:
                                                    <?= h(date('d M Y', strtotime($p['target_date']))) ?></span><?php endif; ?>
                                        </td>
                                        <td><button type="button"
                                                class="btn <?= $hasPlan ? 'btn-light' : 'btn-primary' ?> small-btn action-btn"
                                                onclick='openPendingModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><?= $hasPlan ? 'Edit Follow-up' : '+ Add Follow-up' ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-row">No outstanding tickets — clean backlog.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Executive Summary</div>
                <p class="report-summary"><?= strip_tags($report['summary_text'], '<strong><em><br>') ?></p>
            </div>

            <div class="form-section">
                <div class="form-section-title">Key Metrics</div>
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <h3><?= (int) $report['total_raised'] ?></h3><span>Raised</span>
                    </div>
                    <div class="kpi-card">
                        <h3><?= (int) $report['total_resolved'] ?></h3><span>Resolved</span>
                    </div>
                    <div class="kpi-card">
                        <h3><?= (int) $report['total_pending'] ?></h3><span>Pending</span>
                    </div>
                    <div class="kpi-card">
                        <h3><?= (int) $report['total_critical'] ?></h3><span>Critical</span>
                    </div>
                    <div class="kpi-card">
                        <h3><?= h($report['avg_response_hours']) ?>h</h3><span>Avg Response</span>
                    </div>
                    <div class="kpi-card">
                        <h3><?= h($report['sla_percent']) ?>%</h3><span>Completion</span>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Ticket Status Overview</div>
                <div class="status-overview-grid">
                    <?php foreach ($statusBreakdown as $status => $count): ?>
                        <div class="status-overview-card">
                            <span class="badge <?= statusBadgeClass($status) ?>"><?= h($status) ?></span>
                            <strong><?= (int) $count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="report-row">
                <div class="form-section">
                    <div class="form-section-title">By Category</div>
                    <div class="breakdown-list">
                        <?php if ($categoryBreakdown): ?>
                            <?php $maxCat = max($categoryBreakdown); ?>
                            <?php foreach ($categoryBreakdown as $cat => $count): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-label"><?= h($cat) ?></span>
                                    <div class="breakdown-bar-track">
                                        <div class="breakdown-bar"
                                            style="width:<?= $maxCat > 0 ? round(($count / $maxCat) * 100) : 0 ?>%"></div>
                                    </div>
                                    <span class="breakdown-count"><?= (int) $count ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-row">No tickets this week.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">By Priority</div>
                    <div class="breakdown-list">
                        <?php foreach ($priorityBreakdown as $pr => $count): ?>
                            <div class="breakdown-row">
                                <span class="badge <?= priorityBadgeClass($pr) ?>"><?= h($pr) ?></span>
                                <div class="breakdown-bar-track">
                                    <div class="breakdown-bar"
                                        style="width:<?= $report['total_raised'] > 0 ? round(($count / $report['total_raised']) * 100) : 0 ?>%">
                                    </div>
                                </div>
                                <span class="breakdown-count"><?= (int) $count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Pending Tasks</div>

                <div class="next-target-grid">
                    <?php if ($nextTargets): ?>
                        <?php foreach ($nextTargets as $target): ?>
                            <div class="next-target-card">
                                <div class="next-target-row">
                                    <strong>Ticket number:</strong>
                                    <span><?= h($target['ticket_number'] ?? '—') ?></span>
                                </div>

                                <div class="next-target-row">
                                    <strong>Subject:</strong>
                                    <span><?= h($target['subject'] ?? '—') ?></span>
                                </div>
                                <div class="next-target-row">
                                    <strong>Company:</strong>
                                    <span><?= h($target['company'] ?? '—') ?></span>
                                </div>

                                <div class="next-target-row">
                                    <strong>Outlet:</strong>
                                    <span><?= h($target['outlet'] ?? '—') ?></span>
                                </div>

                                <div class="next-target-row">
                                    <strong>Next Action:</strong>
                                    <span><?= h($target['next_action'] ?? 'Not defined') ?></span>
                                </div>

                                <div class="next-target-row">
                                    <strong>Reason pending:</strong>
                                    <span><?= h($target['reason'] ?? 'Not provided') ?></span>
                                </div>

                                <div class="next-target-row">
                                    <strong>Target Date:</strong>
                                    <span>
                                        <?= !empty($target['target_date'])
                                            ? h(date('M d, Y', strtotime($target['target_date'])))
                                            : '—' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-row">No pending tasks for the next reporting period.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($report['recommendations'])): ?>
                <div class="form-section">
                    <div class="form-section-title">Recommendations</div>
                    <ul class="report-recommendations">
                        <?php foreach (preg_split('/\r\n|\r|\n/', trim($report['recommendations'])) as $line): ?>
                            <?php if (trim($line) !== ''): ?>
                                <li><?= h($line) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="report-footer">
                <span>Prepared by <?= h($senderName) ?></span>
                <span>Weekly IT Support Performance Report</span>
                <span>Confidential – Management Use Only</span>
            </div>
        </div>


    <?php endif; ?>

<?php endif; ?>



<div id="pendingFollowupModal" class="report-modal no-print" aria-hidden="true">
    <div class="report-modal-card" role="dialog" aria-modal="true" aria-labelledby="pendingModalTitle">
        <div class="report-modal-head">
            <div>
                <h3 id="pendingModalTitle">Outstanding Ticket Follow-up</h3>
                <p>Document why the ticket remains unresolved and the committed action for the next reporting period.
                </p>
            </div><button type="button" class="modal-close" onclick="closePendingModal()"
                aria-label="Close">&times;</button>
        </div>
        <form method="POST" id="pendingFollowupForm">
            <input type="hidden" name="action" value="save_pending_reasons">
            <input type="hidden" name="week_start" value="<?= h($reasonWeek) ?>">
            <input type="hidden" name="report_scope" value="<?= h($reportScope) ?>">
            <div class="report-modal-body">
                <div class="modal-ticket-summary">
                    <div>Ticket<strong id="modalTicketNo">—</strong></div>
                    <div>Outlet<strong id="modalOutlet">—</strong></div>
                    <div>Raised By<strong id="modalStaff">—</strong></div>
                    <div>Status / Priority<strong id="modalStatus">—</strong></div>
                </div>
                <div class="modal-field"><label>Subject / Issue</label>
                    <div id="modalIssue"
                        style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;line-height:1.35;font-size:12px;max-height:70px;overflow:auto">
                    </div>
                </div>
                <div class="modal-field"><label for="modalReason">Reason Not Resolved <span
                            style="color:#dc2626">*</span></label><textarea id="modalReason" required
                        placeholder="State the specific blocker, dependency, approval, technical limitation or other reason preventing resolution."></textarea>
                    <div class="modal-help">Be specific enough for management to understand the blocker without
                        reopening the ticket.</div>
                </div>
                <div class="modal-field"><label for="modalAction">Next Week's Action <span
                            style="color:#dc2626">*</span></label><textarea id="modalAction" required
                        placeholder="State the exact action, owner/follow-up step, vendor visit, approval, replacement or technical work planned."></textarea>
                </div>
                <div class="modal-field"><label for="modalTarget">Target Resolution Date</label><input type="date"
                        id="modalTarget">
                    <div class="modal-help">Use the realistic date by which the next action or resolution is expected.
                    </div>
                </div>
                <div class="modal-actions"><button type="button" class="btn btn-light"
                        onclick="closePendingModal()">Cancel</button><button type="submit" class="btn btn-primary">Save
                        Follow-up Plan</button></div>
            </div>
        </form>
    </div>
</div>
<script>
    function openPendingModal(ticket) {
        const modal = document.getElementById('pendingFollowupModal'),
            form = document.getElementById('pendingFollowupForm');
        document.getElementById('modalTicketNo').textContent = ticket.ticket_number || '—';
        document.getElementById('modalOutlet').textContent = ticket.outlet_name || ticket.location_office || '—';
        document.getElementById('modalStaff').textContent = ticket.submitter_name || '—';
        document.getElementById('modalStatus').textContent = (ticket.status || '—') + ' / ' + (ticket.priority || '—');
        document.getElementById('modalIssue').textContent = (ticket.subject || '—') + (ticket.description ? ' — ' + ticket
            .description : '');
        const reason = document.getElementById('modalReason'),
            action = document.getElementById('modalAction'),
            target = document.getElementById('modalTarget');
        reason.name = 'pending_reason[' + ticket.id + ']';
        action.name = 'next_action[' + ticket.id + ']';
        target.name = 'target_date[' + ticket.id + ']';
        reason.value = ticket.reason || '';
        action.value = ticket.next_action || '';
        target.value = ticket.target_date || '';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => reason.focus(), 50);
    }

    function closePendingModal() {
        const modal = document.getElementById('pendingFollowupModal');
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    document.getElementById('pendingFollowupModal')?.addEventListener('click', e => {
        if (e.target.id === 'pendingFollowupModal') closePendingModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closePendingModal();
    });
</script>

<?php if ($reportMode === 'monthly'): ?>

    <div class="panel report-controls">
        <div class="report-controls-row">
            <form method="GET" class="inline-form">
                <input type="hidden" name="mode" value="monthly">
                <input type="hidden" name="scope" value="<?= h($reportScope) ?>">
                <label class="toggle-label">Report Month</label>
                <input type="month" name="month" class="form-control" value="<?= h($selectedMonth) ?>">
                <button class="btn btn-primary small-btn">Load Month</button>
            </form>

            <?php if ($monthlyReports): ?>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="report_mode" value="monthly">
                    <input type="hidden" name="report_scope" value="<?= h($reportScope) ?>">
                    <input type="hidden" name="month" value="<?= h($selectedMonth) ?>">
                    <input type="email" name="recipient_email" class="form-control" placeholder="Recipient email" required>
                    <button name="action" value="email_monthly_html" class="btn btn-success small-btn">
                        Email Monthly Report
                    </button>
                </form>

                <button onclick="window.print()" class="btn btn-light small-btn">🖨 Print</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$monthlyReports): ?>

        <div class="panel empty-row">
            No weekly reports have been generated for <?= h(date('F Y', strtotime($selectedMonth . '-01'))) ?>.
        </div>

    <?php else: ?>

        <div class="panel report-sheet monthly-report-sheet">
            <div class="report-header">
                <div>
                    <h2 class="page-heading">Monthly IT Support Report</h2>
                    <p class="page-subheading">
                        <?= h(date('F Y', strtotime($selectedMonth . '-01'))) ?>
                        · Compiled from <?= count($monthlyReports) ?> weekly report(s)
                        · Prepared by <?= h($senderName) ?>
                    </p>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Monthly Management Summary</div>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <h3><?= number_format($monthlyTotals['raised']) ?></h3>
                        <span>Total Raised</span>
                    </div>

                    <div class="kpi-card">
                        <h3><?= number_format($monthlyTotals['resolved']) ?></h3>
                        <span>Resolved</span>
                    </div>

                    <div class="kpi-card">
                        <h3><?= number_format($monthlyTotals['pending']) ?></h3>
                        <span>Current Pending</span>
                    </div>

                    <div class="kpi-card">
                        <h3><?= number_format($monthlyTotals['critical']) ?></h3>
                        <span>Critical</span>
                    </div>

                    <div class="kpi-card">
                        <h3><?= h($monthlyCompletion) ?>%</h3>
                        <span>Completion</span>
                    </div>

                    <div class="kpi-card">
                        <h3><?= h($monthlyAvgResponse) ?>h</h3>
                        <span>Avg Response</span>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Weekly Performance</div>

                <div class="table-wrap">
                    <table class="report-print-table">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Raised</th>
                                <th>Resolved</th>
                                <th>Pending</th>
                                <th>Critical</th>
                                <th>Avg Response</th>
                                <th>Completion</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($monthlyReports as $r): ?>
                                <tr>
                                    <td>
                                        <?= h(date('M d', strtotime($r['week_start']))) ?> -
                                        <?= h(date('M d', strtotime($r['week_end']))) ?>
                                    </td>
                                    <td><?= (int) $r['total_raised'] ?></td>
                                    <td><?= (int) $r['total_resolved'] ?></td>
                                    <td><?= (int) $r['total_pending'] ?></td>
                                    <td><?= (int) $r['total_critical'] ?></td>
                                    <td><?= h($r['avg_response_hours']) ?>h</td>
                                    <td><?= h($r['sla_percent']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Weekly Executive Summaries</div>

                <div class="monthly-week-grid">
                    <?php foreach ($monthlyReports as $r): ?>
                        <div class="monthly-week-card">
                            <div class="monthly-week-head">
                                <strong>
                                    <?= h(date('M d', strtotime($r['week_start']))) ?> -
                                    <?= h(date('M d', strtotime($r['week_end']))) ?>
                                </strong>
                                <span><?= h($r['sla_percent']) ?>%</span>
                            </div>

                            <p><?= strip_tags($r['summary_text'], '<strong><em><br>') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Pending Tasks</div>

                <?php
                $monthlyTargets = [];
                $monthlyTargets = [];

                foreach ($monthlyReports as $weekly) {

                    $targets = json_decode(
                        $weekly['next_week_targets'] ?? '',
                        true
                    ) ?: [];

                    foreach ($targets as $target) {

                        $ticketId = (int) ($target['ticket_id'] ?? 0);

                        if ($ticketId <= 0) {
                            continue;
                        }

                        $statusRes = mysqli_query(
                            $connection,
                            "SELECT status
             FROM tickets
             WHERE id = {$ticketId}
             LIMIT 1"
                        );

                        $liveTicket = $statusRes
                            ? mysqli_fetch_assoc($statusRes)
                            : null;

                        if (!$liveTicket) {
                            continue;
                        }

                        $liveStatus = trim(
                            (string) ($liveTicket['status'] ?? '')
                        );

                        if (
                            in_array(
                                strtolower($liveStatus),
                                ['resolved', 'closed', 'cancelled'],
                                true
                            )
                        ) {
                            continue;
                        }

                        $target['status'] = $liveStatus;

                        $monthlyTargets[] = $target;
                    }
                }
                ?>

                <div class="next-target-grid">
                    <?php if ($monthlyTargets): ?>
                        <?php foreach ($monthlyTargets as $target): ?>
                            <div class="next-target-card">
                                <strong><?= h($target['ticket_number'] ?? '') ?></strong>
                                <span><?= h($target['subject'] ?? '') ?></span>
                                <p><?= h($target['next_action'] ?? 'Follow up and resolve.') ?></p>
                                <?php if (!empty($target['target_date'])): ?>
                                    <small>Target: <?= h(date('M d, Y', strtotime($target['target_date']))) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-row">No pending tasks recorded for this month.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="report-footer">
                <span>Prepared by <?= h($senderName) ?></span>
                <span>Monthly IT Support Management Report</span>
                <span>Confidential – Management Use Only</span>
            </div>
        </div>

    <?php endif; ?>
<?php endif; ?>

<?php include '../inc/footer.php'; ?>