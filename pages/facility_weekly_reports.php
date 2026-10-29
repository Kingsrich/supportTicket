<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = "Weekly Facility Maintenance Report";

$userId = (int) ($_SESSION['user_id'] ?? 0);

$canReport = facilityHasRole(
    $connection,
    $userId,
    [
        'facility_officer',
        'facility_project_manager',
        'facility_admin'
    ]
);

if (!$canReport) {
    header("Location: support_tickets.php?tab=facility");
    exit;
}


function facilityReportFlash($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}


function facilityReportEmails(string $value): array
{
    $parts = preg_split('/[\s,;]+/', trim($value));

    $valid = [];

    foreach ($parts as $email) {
        $email = trim($email);

        if (
            $email !== '' &&
            filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $valid[strtolower($email)] = $email;
        }
    }

    return array_values($valid);
}


function facilityReportDuration($minutes): string
{
    if ($minutes === null || $minutes === '') {
        return '—';
    }

    $minutes = (int) $minutes;

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    return $mins > 0
        ? "{$hours}h {$mins}m"
        : "{$hours}h";
}


/* ============================================================
   GENERATE / REFRESH REPORT
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'generate_report'
) {

    $weekStart = $_POST['week_start'] ?? '';

    if (
        $weekStart === '' ||
        !strtotime($weekStart)
    ) {
        facilityReportFlash(
            'error',
            'Please choose a valid week start date.'
        );

        header("Location: facility_weekly_reports.php");
        exit;
    }

    $weekStart = date(
        'Y-m-d',
        strtotime($weekStart)
    );

    $weekEnd = date(
        'Y-m-d',
        strtotime(
            $weekStart . ' +6 days'
        )
    );

    $recommendations = trim(
        $_POST['recommendations']
        ?? ''
    );

    $wsEsc = mysqli_real_escape_string(
        $connection,
        $weekStart
    );

    $weEsc = mysqli_real_escape_string(
        $connection,
        $weekEnd
    );


    /* --------------------------------------------------------
       CORE METRICS
    --------------------------------------------------------- */

    $raisedRes = mysqli_query(
        $connection,
        "
        SELECT COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE DATE(created_at)
        BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $totalRaised = (int) (
        mysqli_fetch_assoc($raisedRes)['c']
        ?? 0
    );


    $closedRes = mysqli_query(
        $connection,
        "
        SELECT COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE status = 'Closed'
          AND DATE(closed_at)
          BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $totalClosed = (int) (
        mysqli_fetch_assoc($closedRes)['c']
        ?? 0
    );


    $pendingRes = mysqli_query(
        $connection,
        "
        SELECT COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
          AND status NOT IN (
              'Closed',
              'Cancelled',
              'Rejected'
          )
        "
    );

    $totalPending = (int) (
        mysqli_fetch_assoc($pendingRes)['c']
        ?? 0
    );


    $criticalRes = mysqli_query(
        $connection,
        "
        SELECT COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE priority = 'Critical'
          AND DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $totalCritical = (int) (
        mysqli_fetch_assoc($criticalRes)['c']
        ?? 0
    );


    $responseRes = mysqli_query(
        $connection,
        "
        SELECT AVG(
            TIMESTAMPDIFF(
                MINUTE,
                created_at,
                assigned_at
            )
        ) AS mins
        FROM facility_maintenance_requests
        WHERE assigned_at IS NOT NULL
          AND DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $avgResponseMinutes = (float) (
        mysqli_fetch_assoc($responseRes)['mins']
        ?? 0
    );

    $avgResponseHours = round(
        $avgResponseMinutes / 60,
        2
    );


    $resolutionRes = mysqli_query(
        $connection,
        "
        SELECT AVG(
            TIMESTAMPDIFF(
                MINUTE,
                created_at,
                closed_at
            )
        ) AS mins
        FROM facility_maintenance_requests
        WHERE closed_at IS NOT NULL
          AND DATE(closed_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $avgResolutionMinutes = (float) (
        mysqli_fetch_assoc($resolutionRes)['mins']
        ?? 0
    );

    $avgResolutionHours = round(
        $avgResolutionMinutes / 60,
        2
    );


    $downtimeRes = mysqli_query(
        $connection,
        "
        SELECT COALESCE(
            SUM(
                TIMESTAMPDIFF(
                    MINUTE,
                    downtime_started_at,
                    COALESCE(
                        restored_at,
                        NOW()
                    )
                )
            ),
            0
        ) AS mins
        FROM facility_maintenance_requests
        WHERE downtime_started_at IS NOT NULL
          AND DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $downtimeMinutes = (int) (
        mysqli_fetch_assoc($downtimeRes)['mins']
        ?? 0
    );

    $downtimeHours = round(
        $downtimeMinutes / 60,
        2
    );


    $costRes = mysqli_query(
        $connection,
        "
        SELECT COALESCE(
            SUM(c.amount),
            0
        ) AS total
        FROM facility_maintenance_costs c
        INNER JOIN facility_maintenance_requests m
            ON m.id = c.maintenance_request_id
        WHERE DATE(m.created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $totalCost = (float) (
        mysqli_fetch_assoc($costRes)['total']
        ?? 0
    );


    $completionRate =
        $totalRaised > 0
        ? round(
            ($totalClosed / $totalRaised) * 100,
            2
        )
        : 0;


    /* --------------------------------------------------------
       PREVENTIVE MAINTENANCE COMPLIANCE
    --------------------------------------------------------- */

    $pmRes = mysqli_query(
        $connection,
        "
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Completed') AS completed
        FROM facility_pm_runs
        WHERE due_date
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        "
    );

    $pmRow = $pmRes
        ? mysqli_fetch_assoc($pmRes)
        : [];

    $pmTotal = (int) (
        $pmRow['total']
        ?? 0
    );

    $pmCompleted = (int) (
        $pmRow['completed']
        ?? 0
    );

    $pmCompliance =
        $pmTotal > 0
        ? round(
            ($pmCompleted / $pmTotal) * 100,
            2
        )
        : 100;


    /* --------------------------------------------------------
       STATUS BREAKDOWN
    --------------------------------------------------------- */

    $statusBreakdown = [];

    $statusRes = mysqli_query(
        $connection,
        "
        SELECT status, COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY status
        ORDER BY c DESC
        "
    );

    while (
        $statusRes &&
        ($row = mysqli_fetch_assoc($statusRes))
    ) {
        $statusBreakdown[
            $row['status']
        ] = (int) $row['c'];
    }


    /* --------------------------------------------------------
       REQUEST TYPE BREAKDOWN
    --------------------------------------------------------- */

    $typeBreakdown = [];

    $typeRes = mysqli_query(
        $connection,
        "
        SELECT request_type, COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY request_type
        ORDER BY c DESC
        "
    );

    while (
        $typeRes &&
        ($row = mysqli_fetch_assoc($typeRes))
    ) {
        $typeBreakdown[
            $row['request_type']
        ] = (int) $row['c'];
    }


    /* --------------------------------------------------------
       PRIORITY BREAKDOWN
    --------------------------------------------------------- */

    $priorityBreakdown = [];

    foreach (
        ['Low', 'Medium', 'High', 'Critical']
        as $priority
    ) {
        $priorityBreakdown[$priority] = 0;
    }

    $priorityRes = mysqli_query(
        $connection,
        "
        SELECT priority, COUNT(*) AS c
        FROM facility_maintenance_requests
        WHERE DATE(created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY priority
        "
    );

    while (
        $priorityRes &&
        ($row = mysqli_fetch_assoc($priorityRes))
    ) {
        $priorityBreakdown[
            $row['priority']
        ] = (int) $row['c'];
    }


    /* --------------------------------------------------------
       OUTLET COST BREAKDOWN
    --------------------------------------------------------- */

    $outletCostBreakdown = [];

    $outletCostRes = mysqli_query(
        $connection,
        "
        SELECT
            o.warehouse_name,
            COALESCE(SUM(c.amount), 0) AS total_cost
        FROM facility_maintenance_requests m
        INNER JOIN outlets o
            ON o.id = m.outlet_id
        LEFT JOIN facility_maintenance_costs c
            ON c.maintenance_request_id = m.id
        WHERE DATE(m.created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY o.id, o.warehouse_name
        ORDER BY total_cost DESC
        LIMIT 15
        "
    );

    while (
        $outletCostRes &&
        ($row = mysqli_fetch_assoc($outletCostRes))
    ) {
        $outletCostBreakdown[
            $row['warehouse_name']
        ] = (float) $row['total_cost'];
    }


    /* --------------------------------------------------------
       CURRENT OUTSTANDING SNAPSHOT
    --------------------------------------------------------- */

    $outstanding = [];

    $outstandingRes = mysqli_query(
        $connection,
        "
        SELECT
            m.id,
            m.request_number,
            m.subject,
            m.priority,
            m.status,
            o.warehouse_name,
            a.asset_code,
            a.asset_name,
            m.created_at
        FROM facility_maintenance_requests m
        INNER JOIN outlets o
            ON o.id = m.outlet_id
        LEFT JOIN facility_assets a
            ON a.id = m.asset_id
        WHERE m.status NOT IN (
            'Closed',
            'Cancelled',
            'Rejected'
        )
        ORDER BY
            FIELD(
                m.priority,
                'Critical',
                'High',
                'Medium',
                'Low'
            ),
            m.created_at ASC
        LIMIT 25
        "
    );

    while (
        $outstandingRes &&
        ($row = mysqli_fetch_assoc($outstandingRes))
    ) {
        $outstanding[] = $row;
    }


    /* --------------------------------------------------------
       FREQUENTLY FAILING ASSETS - 90 DAY SNAPSHOT
    --------------------------------------------------------- */

    $failingAssets = [];

    $failureRes = mysqli_query(
        $connection,
        "
        SELECT
            a.id,
            a.asset_code,
            a.asset_name,
            o.warehouse_name,
            COUNT(m.id) AS failures
        FROM facility_assets a
        INNER JOIN outlets o
            ON o.id = a.outlet_id
        INNER JOIN facility_maintenance_requests m
            ON m.asset_id = a.id
           AND m.request_type IN (
               'breakdown',
               'corrective'
           )
           AND m.created_at >= DATE_SUB(
               NOW(),
               INTERVAL 90 DAY
           )
        GROUP BY
            a.id,
            a.asset_code,
            a.asset_name,
            o.warehouse_name
        HAVING COUNT(m.id) > 1
        ORDER BY failures DESC
        LIMIT 15
        "
    );

    while (
        $failureRes &&
        ($row = mysqli_fetch_assoc($failureRes))
    ) {
        $failingAssets[] = $row;
    }


    /* --------------------------------------------------------
       TECHNICIAN PERFORMANCE
    --------------------------------------------------------- */

    $technicianPerformance = [];

    $techRes = mysqli_query(
        $connection,
        "
        SELECT
            u.fullname,
            COUNT(DISTINCT m.id) AS assigned,
            SUM(m.status = 'Closed') AS completed,
            COALESCE(
                AVG(
                    CASE
                        WHEN m.closed_at IS NOT NULL
                        THEN TIMESTAMPDIFF(
                            MINUTE,
                            m.created_at,
                            m.closed_at
                        )
                    END
                ),
                0
            ) AS avg_resolution_minutes
        FROM facility_technicians ft
        INNER JOIN users u
            ON u.id = ft.user_id
        LEFT JOIN facility_maintenance_requests m
            ON m.assigned_technician_id = ft.id
           AND DATE(m.created_at)
               BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY ft.id, u.fullname
        HAVING COUNT(DISTINCT m.id) > 0
        ORDER BY completed DESC, assigned DESC
        "
    );

    while (
        $techRes &&
        ($row = mysqli_fetch_assoc($techRes))
    ) {
        $technicianPerformance[] = $row;
    }


    /* --------------------------------------------------------
       VENDOR PERFORMANCE
    --------------------------------------------------------- */

    $vendorPerformance = [];

    $vendorRes = mysqli_query(
        $connection,
        "
        SELECT
            v.vendor_name,
            COUNT(DISTINCT m.id) AS jobs,
            SUM(m.status = 'Closed') AS completed,
            COALESCE(SUM(c.amount), 0) AS total_cost,
            COALESCE(
                AVG(
                    CASE
                        WHEN m.closed_at IS NOT NULL
                        THEN TIMESTAMPDIFF(
                            MINUTE,
                            m.created_at,
                            m.closed_at
                        )
                    END
                ),
                0
            ) AS avg_resolution_minutes
        FROM facility_vendors v
        LEFT JOIN facility_maintenance_requests m
            ON m.assigned_vendor_id = v.id
           AND DATE(m.created_at)
               BETWEEN '{$wsEsc}' AND '{$weEsc}'
        LEFT JOIN facility_maintenance_costs c
            ON c.maintenance_request_id = m.id
           AND c.cost_type = 'Vendor'
        GROUP BY v.id, v.vendor_name
        HAVING COUNT(DISTINCT m.id) > 0
        ORDER BY completed DESC, jobs DESC
        "
    );

    while (
        $vendorRes &&
        ($row = mysqli_fetch_assoc($vendorRes))
    ) {
        $vendorPerformance[] = $row;
    }


    /* --------------------------------------------------------
       WEEKLY MAINTENANCE REGISTER
    --------------------------------------------------------- */

    $maintenanceRegister = [];

    $registerRes = mysqli_query(
        $connection,
        "
        SELECT
            m.id,
            m.request_number,
            m.created_at,
            m.subject,
            m.request_type,
            m.priority,
            m.status,
            m.contact_name,
            o.warehouse_name,
            a.asset_code,
            a.asset_name,
            u.fullname AS technician_name,
            v.vendor_name,
            TIMESTAMPDIFF(
                MINUTE,
                m.created_at,
                m.assigned_at
            ) AS response_minutes,
            TIMESTAMPDIFF(
                MINUTE,
                m.created_at,
                m.closed_at
            ) AS resolution_minutes,
            CASE
                WHEN m.downtime_started_at IS NOT NULL
                THEN TIMESTAMPDIFF(
                    MINUTE,
                    m.downtime_started_at,
                    COALESCE(
                        m.restored_at,
                        NOW()
                    )
                )
                ELSE 0
            END AS downtime_minutes,
            COALESCE(SUM(c.amount), 0) AS total_cost
        FROM facility_maintenance_requests m
        INNER JOIN outlets o
            ON o.id = m.outlet_id
        LEFT JOIN facility_assets a
            ON a.id = m.asset_id
        LEFT JOIN facility_technicians ft
            ON ft.id = m.assigned_technician_id
        LEFT JOIN users u
            ON u.id = ft.user_id
        LEFT JOIN facility_vendors v
            ON v.id = m.assigned_vendor_id
        LEFT JOIN facility_maintenance_costs c
            ON c.maintenance_request_id = m.id
        WHERE DATE(m.created_at)
              BETWEEN '{$wsEsc}' AND '{$weEsc}'
        GROUP BY m.id
        ORDER BY m.created_at ASC
        "
    );

    while (
        $registerRes &&
        ($row = mysqli_fetch_assoc($registerRes))
    ) {
        $maintenanceRegister[] = $row;
    }


    /* --------------------------------------------------------
       EXECUTIVE SUMMARY
    --------------------------------------------------------- */

    $summary =
        "A total of <strong>{$totalRaised}</strong> Facility maintenance request(s) "
        . "were raised during the reporting period. "
        . "<strong>{$totalClosed}</strong> request(s) were closed, representing a "
        . "<strong>{$completionRate}%</strong> completion rate. "
        . "<strong>{$totalPending}</strong> request(s) raised during the week remain open";

    if ($totalCritical > 0) {
        $summary .=
            ", including <strong>{$totalCritical}</strong> Critical request(s)";
    }

    $summary .=
        ". Average first response time was <strong>{$avgResponseHours} hours</strong>, "
        . "average resolution time was <strong>{$avgResolutionHours} hours</strong>, "
        . "recorded downtime was <strong>{$downtimeHours} hours</strong>, "
        . "and total recorded maintenance cost was <strong>₦"
        . number_format($totalCost, 2)
        . "</strong>. Preventive Maintenance compliance for the week was "
        . "<strong>{$pmCompliance}%</strong>.";


    /* --------------------------------------------------------
       SAVE SNAPSHOT
    --------------------------------------------------------- */

    $statusJson = mysqli_real_escape_string(
        $connection,
        json_encode($statusBreakdown)
    );

    $typeJson = mysqli_real_escape_string(
        $connection,
        json_encode($typeBreakdown)
    );

    $priorityJson = mysqli_real_escape_string(
        $connection,
        json_encode($priorityBreakdown)
    );

    $outletCostJson = mysqli_real_escape_string(
        $connection,
        json_encode($outletCostBreakdown)
    );

    $outstandingJson = mysqli_real_escape_string(
        $connection,
        json_encode($outstanding)
    );

    $failureJson = mysqli_real_escape_string(
        $connection,
        json_encode($failingAssets)
    );

    $techJson = mysqli_real_escape_string(
        $connection,
        json_encode($technicianPerformance)
    );

    $vendorJson = mysqli_real_escape_string(
        $connection,
        json_encode($vendorPerformance)
    );

    $registerJson = mysqli_real_escape_string(
        $connection,
        json_encode($maintenanceRegister)
    );

    $recommendationsEsc = mysqli_real_escape_string(
        $connection,
        $recommendations
    );

    $summaryEsc = mysqli_real_escape_string(
        $connection,
        $summary
    );


    $saveSql = "
        INSERT INTO facility_weekly_reports
        (
            week_start,
            week_end,
            generated_by,
            total_raised,
            total_closed,
            total_pending,
            total_critical,
            avg_response_hours,
            avg_resolution_hours,
            downtime_hours,
            total_cost,
            completion_rate,
            pm_compliance,
            status_breakdown,
            request_type_breakdown,
            priority_breakdown,
            outlet_cost_breakdown,
            outstanding_snapshot,
            failing_assets_snapshot,
            technician_performance_snapshot,
            vendor_performance_snapshot,
            maintenance_register_snapshot,
            recommendations,
            summary_text
        )
        VALUES
        (
            '{$wsEsc}',
            '{$weEsc}',
            {$userId},
            {$totalRaised},
            {$totalClosed},
            {$totalPending},
            {$totalCritical},
            {$avgResponseHours},
            {$avgResolutionHours},
            {$downtimeHours},
            {$totalCost},
            {$completionRate},
            {$pmCompliance},
            '{$statusJson}',
            '{$typeJson}',
            '{$priorityJson}',
            '{$outletCostJson}',
            '{$outstandingJson}',
            '{$failureJson}',
            '{$techJson}',
            '{$vendorJson}',
            '{$registerJson}',
            '{$recommendationsEsc}',
            '{$summaryEsc}'
        )
        ON DUPLICATE KEY UPDATE
            week_end = VALUES(week_end),
            generated_by = VALUES(generated_by),
            total_raised = VALUES(total_raised),
            total_closed = VALUES(total_closed),
            total_pending = VALUES(total_pending),
            total_critical = VALUES(total_critical),
            avg_response_hours = VALUES(avg_response_hours),
            avg_resolution_hours = VALUES(avg_resolution_hours),
            downtime_hours = VALUES(downtime_hours),
            total_cost = VALUES(total_cost),
            completion_rate = VALUES(completion_rate),
            pm_compliance = VALUES(pm_compliance),
            status_breakdown = VALUES(status_breakdown),
            request_type_breakdown = VALUES(request_type_breakdown),
            priority_breakdown = VALUES(priority_breakdown),
            outlet_cost_breakdown = VALUES(outlet_cost_breakdown),
            outstanding_snapshot = VALUES(outstanding_snapshot),
            failing_assets_snapshot = VALUES(failing_assets_snapshot),
            technician_performance_snapshot = VALUES(technician_performance_snapshot),
            vendor_performance_snapshot = VALUES(vendor_performance_snapshot),
            maintenance_register_snapshot = VALUES(maintenance_register_snapshot),
            recommendations = VALUES(recommendations),
            summary_text = VALUES(summary_text),
            created_at = NOW()
    ";

    if (!mysqli_query($connection, $saveSql)) {
        facilityReportFlash(
            'error',
            'Unable to save Facility report: ' . mysqli_error($connection)
        );

        header(
            "Location: facility_weekly_reports.php?week={$weekStart}"
        );

        exit;
    }


    facilityReportFlash(
        'success',
        'Facility report generated for week of '
        . date('M d, Y', strtotime($weekStart))
        . '.'
    );

    header(
        "Location: facility_weekly_reports.php?week={$weekStart}"
    );

    exit;
}


/* ============================================================
   EMAIL REPORT AS PDF
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'email_report_pdf'
) {

    $weekStart = $_POST['week_start'] ?? '';
    $recipientInput = trim(
        $_POST['recipient_emails']
        ?? ''
    );

    $recipients = facilityReportEmails(
        $recipientInput
    );

    if (
        $weekStart === '' ||
        !strtotime($weekStart) ||
        empty($recipients)
    ) {
        facilityReportFlash(
            'error',
            'Select a valid week and enter at least one valid recipient email.'
        );

        header(
            "Location: facility_weekly_reports.php?week={$weekStart}"
        );

        exit;
    }

    $wsEsc = mysqli_real_escape_string(
        $connection,
        date('Y-m-d', strtotime($weekStart))
    );

    $res = mysqli_query(
        $connection,
        "
        SELECT
            r.*,
            u.fullname AS generated_by_name
        FROM facility_weekly_reports r
        LEFT JOIN users u
            ON u.id = r.generated_by
        WHERE r.week_start = '{$wsEsc}'
        LIMIT 1
        "
    );

    $reportToSend = $res
        ? mysqli_fetch_assoc($res)
        : null;

    if (!$reportToSend) {
        facilityReportFlash(
            'error',
            'No Facility report found for that week. Generate it first.'
        );

        header(
            "Location: facility_weekly_reports.php?week={$weekStart}"
        );

        exit;
    }


    require_once '../inc/facility_report_pdf.php';
    require_once '../inc/EmailService.php';

    $pdfContent = buildFacilityWeeklyReportPdf(
        $reportToSend
    );

    $filename =
        'facility_weekly_report_'
        . $reportToSend['week_start']
        . '.pdf';

    $subject =
        'Weekly Facility Maintenance Report — '
        . date(
            'M d',
            strtotime($reportToSend['week_start'])
        )
        . ' to '
        . date(
            'M d, Y',
            strtotime($reportToSend['week_end'])
        );

    $body =
        'Please find attached the Weekly Facility Maintenance Report for the period '
        . date(
            'M d',
            strtotime($reportToSend['week_start'])
        )
        . ' – '
        . date(
            'M d, Y',
            strtotime($reportToSend['week_end'])
        )
        . '.';

    $emailService =
        new EmailService($connection);

    $success = [];
    $failed = [];

    foreach ($recipients as $recipientEmail) {

        $ok = $emailService->sendReportPdf($recipientEmail, $recipientEmail, $subject, $body, $pdfContent, $filename, 'facility');

        if ($ok) {
            $success[] = $recipientEmail;
        } else {
            $failed[] = $recipientEmail;
        }
    }

    if (empty($failed)) {
        facilityReportFlash(
            'success',
            'Facility report emailed as PDF to '
            . count($success)
            . ' recipient(s): '
            . implode(', ', $success)
        );
    } else {
        facilityReportFlash(
            'error',
            'Report sent to '
            . count($success)
            . ' recipient(s), but failed for: '
            . implode(', ', $failed)
        );
    }

    header(
        "Location: facility_weekly_reports.php?week={$weekStart}"
    );

    exit;
}


/* ============================================================
   EMAIL REPORT AS HTML MESSAGE
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'email_report_html'
) {

    $weekStart = $_POST['week_start'] ?? '';
    $recipientInput = trim(
        $_POST['recipient_emails']
        ?? ''
    );

    $recipients = facilityReportEmails(
        $recipientInput
    );

    if (
        $weekStart === '' ||
        !strtotime($weekStart) ||
        empty($recipients)
    ) {
        facilityReportFlash(
            'error',
            'Select a valid week and enter at least one valid recipient email.'
        );

        header(
            "Location: facility_weekly_reports.php?week={$weekStart}"
        );

        exit;
    }

    $wsEsc = mysqli_real_escape_string(
        $connection,
        date('Y-m-d', strtotime($weekStart))
    );

    $res = mysqli_query(
        $connection,
        "
        SELECT
            r.*,
            u.fullname AS generated_by_name
        FROM facility_weekly_reports r
        LEFT JOIN users u
            ON u.id = r.generated_by
        WHERE r.week_start = '{$wsEsc}'
        LIMIT 1
        "
    );

    $reportToSend = $res
        ? mysqli_fetch_assoc($res)
        : null;

    if (!$reportToSend) {
        facilityReportFlash(
            'error',
            'No Facility report found for that week. Generate it first.'
        );

        header(
            "Location: facility_weekly_reports.php?week={$weekStart}"
        );

        exit;
    }


    require_once '../inc/facility_report_pdf.php';
    require_once '../inc/EmailService.php';

    $htmlBody = renderFacilityWeeklyReportHtml(
        $reportToSend
    );

    $subject =
        'Weekly Facility Maintenance Report — '
        . date(
            'M d',
            strtotime($reportToSend['week_start'])
        )
        . ' to '
        . date(
            'M d, Y',
            strtotime($reportToSend['week_end'])
        );

    $emailService =
        new EmailService($connection);

    $success = [];
    $failed = [];

    foreach ($recipients as $recipientEmail) {
        $ok = $emailService->sendReportHtml($recipientEmail, $recipientEmail, $subject, $htmlBody, 'facility');
        if ($ok) {
            $success[] = $recipientEmail;
        } else {
            $failed[] = $recipientEmail;
        }
    }

    if (empty($failed)) {
        facilityReportFlash(
            'success',
            'Facility report emailed as a message to '
            . count($success)
            . ' recipient(s): '
            . implode(', ', $success)
        );
    } else {
        facilityReportFlash(
            'error',
            'Report sent to '
            . count($success)
            . ' recipient(s), but failed for: '
            . implode(', ', $failed)
        );
    }

    header(
        "Location: facility_weekly_reports.php?week={$weekStart}"
    );

    exit;
}


/* ============================================================
   LOAD SELECTED REPORT
============================================================ */

$selectedWeek = $_GET['week'] ?? '';
$report = null;

if (
    $selectedWeek !== '' &&
    strtotime($selectedWeek)
) {

    $wsEsc = mysqli_real_escape_string(
        $connection,
        date(
            'Y-m-d',
            strtotime($selectedWeek)
        )
    );

    $res = mysqli_query(
        $connection,
        "
        SELECT
            r.*,
            u.fullname AS generated_by_name
        FROM facility_weekly_reports r
        LEFT JOIN users u
            ON u.id = r.generated_by
        WHERE r.week_start = '{$wsEsc}'
        LIMIT 1
        "
    );

    $report = $res
        ? mysqli_fetch_assoc($res)
        : null;
}


/* ============================================================
   SAVED REPORTS
============================================================ */

$savedReports = [];

$listRes = mysqli_query(
    $connection,
    "
    SELECT
        week_start,
        week_end,
        total_raised,
        completion_rate,
        total_cost
    FROM facility_weekly_reports
    ORDER BY week_start DESC
    LIMIT 26
    "
);

while (
    $listRes &&
    ($row = mysqli_fetch_assoc($listRes))
) {
    $savedReports[] = $row;
}


$defaultWeekStart = date(
    'Y-m-d',
    strtotime('monday this week')
);


include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/facility-weekly-reports.css?v=1.0">

<div class="fwr-page">

    <?php if (!empty($_SESSION['flash_text'])): ?>

    <div class="fwr-alert <?= facilityH($_SESSION['flash_type']) ?>">
        <?= facilityH($_SESSION['flash_text']) ?>
    </div>

    <?php
        unset(
            $_SESSION['flash_text'],
            $_SESSION['flash_type']
        );
        ?>

    <?php endif; ?>


    <!-- ========================================================
         PAGE HEADER
    ========================================================= -->

    <div class="fwr-page-header">

        <div>
            <div class="fwr-eyebrow">
                Facility Management
            </div>

            <!-- <h2>
                Weekly Facility Maintenance Report
            </h2>

            <p>
                Generate, review, print and distribute a management report covering Facility requests, downtime, cost,
                PM compliance, technician and vendor performance.
            </p> -->
        </div>

    </div>


    <!-- ========================================================
         REPORT CONTROLS
    ========================================================= -->

    <section class="fwr-controls">

        <div class="fwr-controls-row">

            <form method="POST" class="fwr-inline-form">

                <input type="hidden" name="action" value="generate_report">

                <div class="fwr-field">
                    <label>Week Starting</label>

                    <input type="date" name="week_start" value="<?= facilityH(
                        $selectedWeek
                        ?: $defaultWeekStart
                    ) ?>" required>
                </div>

                <div class="fwr-field fwr-recommendation-field">
                    <label>Recommendations</label>

                    <input type="text" name="recommendations" value="<?= facilityH(
                        $report['recommendations']
                        ?? ''
                    ) ?>" placeholder="Optional management recommendation...">
                </div>

                <button type="submit" class="fwr-btn fwr-btn-primary">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    Generate / Refresh
                </button>

            </form>


            <?php if (count($savedReports)): ?>

            <form method="GET" class="fwr-saved-form">

                <div class="fwr-field">
                    <label>Saved Reports</label>

                    <select name="week" onchange="this.form.submit()">
                        <option value="">
                            Select a week...
                        </option>

                        <?php foreach ($savedReports as $saved): ?>

                        <option value="<?= facilityH($saved['week_start']) ?>" <?= $selectedWeek === $saved['week_start']
                                      ? 'selected'
                                      : ''
                                      ?>>
                            <?= facilityH(
                                        date(
                                            'M d',
                                            strtotime(
                                                $saved['week_start']
                                            )
                                        )
                                    ) ?>
                            –
                            <?= facilityH(
                                        date(
                                            'M d, Y',
                                            strtotime(
                                                $saved['week_end']
                                            )
                                        )
                                    ) ?>
                            (<?= (int) $saved['total_raised'] ?> Raised)
                        </option>

                        <?php endforeach; ?>

                    </select>
                </div>

            </form>

            <?php endif; ?>

        </div>


        <?php if ($report): ?>

        <div class="fwr-email-row">

            <form method="POST" class="fwr-email-form">

                <input type="hidden" name="week_start" value="<?= facilityH($report['week_start']) ?>">

                <div class="fwr-field fwr-recipient-field">
                    <label>Email Recipients</label>

                    <textarea name="recipient_emails" rows="2"
                        placeholder="manager@company.com, operations@company.com, facility@company.com"
                        required></textarea>

                    <!-- <small>
                            Enter multiple addresses separated by commas, semicolons, spaces or new lines.
                        </small> -->
                </div>

                <div class="fwr-email-actions">

                    <button type="submit" name="action" value="email_report_pdf" class="fwr-btn fwr-btn-success">
                        <i class="fa-solid fa-file-pdf"></i>
                        Email as PDF
                    </button>

                    <button type="submit" name="action" value="email_report_html" class="fwr-btn fwr-btn-info">
                        <i class="fa-solid fa-envelope"></i>
                        Email as Message
                    </button>

                    <button type="button" onclick="window.print()" class="fwr-btn fwr-btn-light">
                        <i class="fa-solid fa-print"></i>
                        Print
                    </button>

                </div>

            </form>

        </div>

        <?php endif; ?>

    </section>


    <!-- ========================================================
         NO REPORT
    ========================================================= -->

    <?php if (!$report): ?>

    <section class="fwr-empty-panel">

        <i class="fa-solid fa-chart-column"></i>

        <strong>
            No Facility report selected
        </strong>

        <span>
            <?= $selectedWeek !== ''
                    ? 'No report has been generated for that week yet.'
                    : 'Choose a week and generate a report, or select a previously saved report.'
                    ?>
        </span>

    </section>


    <?php else: ?>

    <?php
        $statusBreakdown =
            json_decode(
                $report['status_breakdown']
                ?? '',
                true
            ) ?: [];

        $typeBreakdown =
            json_decode(
                $report['request_type_breakdown']
                ?? '',
                true
            ) ?: [];

        $priorityBreakdown =
            json_decode(
                $report['priority_breakdown']
                ?? '',
                true
            ) ?: [];

        $outletCosts =
            json_decode(
                $report['outlet_cost_breakdown']
                ?? '',
                true
            ) ?: [];

        $outstanding =
            json_decode(
                $report['outstanding_snapshot']
                ?? '',
                true
            ) ?: [];

        $failingAssets =
            json_decode(
                $report['failing_assets_snapshot']
                ?? '',
                true
            ) ?: [];

        $technicians =
            json_decode(
                $report['technician_performance_snapshot']
                ?? '',
                true
            ) ?: [];

        $vendors =
            json_decode(
                $report['vendor_performance_snapshot']
                ?? '',
                true
            ) ?: [];

        $maintenanceRegister =
            json_decode(
                $report['maintenance_register_snapshot']
                ?? '',
                true
            ) ?: [];
        ?>


    <!-- ====================================================
             REPORT SHEET 1
        ===================================================== -->

    <article class="fwr-report-sheet">

        <header class="fwr-report-header">

            <div>

                <div class="fwr-report-label">
                    Management Report
                </div>

                <h1>
                    Weekly Facility Maintenance Report
                </h1>

                <p>
                    <?= facilityH(
                            date(
                                'M d',
                                strtotime(
                                    $report['week_start']
                                )
                            )
                        ) ?>
                    –
                    <?= facilityH(
                            date(
                                'M d, Y',
                                strtotime(
                                    $report['week_end']
                                )
                            )
                        ) ?>
                    · Prepared by
                    <?= facilityH(
                            $report['generated_by_name']
                            ?? 'Facility Department'
                        ) ?>
                    · Generated
                    <?= facilityH(
                            date(
                                'M d, Y g:i A',
                                strtotime(
                                    $report['created_at']
                                )
                            )
                        ) ?>
                </p>

            </div>

        </header>


        <section class="fwr-section">

            <div class="fwr-section-title">
                Executive Summary
            </div>

            <div class="fwr-summary">
                <?= strip_tags(
                        $report['summary_text'],
                        '<strong><em><br>'
                    ) ?>
            </div>

        </section>


        <!-- KEY METRICS -->

        <section class="fwr-section">

            <div class="fwr-section-title">
                Key Metrics
            </div>

            <div class="fwr-kpi-grid">

                <div class="fwr-kpi-card">
                    <strong><?= (int) $report['total_raised'] ?></strong>
                    <span>Raised</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= (int) $report['total_closed'] ?></strong>
                    <span>Closed</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= (int) $report['total_pending'] ?></strong>
                    <span>Pending</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= (int) $report['total_critical'] ?></strong>
                    <span>Critical</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= facilityH($report['avg_response_hours']) ?>h</strong>
                    <span>Avg Response</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= facilityH($report['avg_resolution_hours']) ?>h</strong>
                    <span>Avg Resolution</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= facilityH($report['downtime_hours']) ?>h</strong>
                    <span>Downtime</span>
                </div>

                <div class="fwr-kpi-card">
                    <strong><?= facilityH($report['pm_compliance']) ?>%</strong>
                    <span>PM Compliance</span>
                </div>

                <div class="fwr-kpi-card fwr-cost-kpi">
                    <strong>
                        ₦<?= number_format(
                                (float) $report['total_cost'],
                                0
                            ) ?>
                    </strong>
                    <span>Maintenance Cost</span>
                </div>

            </div>

        </section>


        <!-- STATUS -->

        <section class="fwr-section">

            <div class="fwr-section-title">
                Request Status Overview
            </div>

            <div class="fwr-status-grid">

                <?php foreach (
                        $statusBreakdown
                        as $status => $count
                    ): ?>

                <div class="fwr-status-card">

                    <span class="fwr-status-pill">
                        <?= facilityH($status) ?>
                    </span>

                    <strong>
                        <?= (int) $count ?>
                    </strong>

                </div>

                <?php endforeach; ?>

            </div>

        </section>


        <!-- TYPE + PRIORITY -->

        <div class="fwr-two-col">

            <section class="fwr-section">

                <div class="fwr-section-title">
                    By Request Type
                </div>

                <div class="fwr-breakdown-list">

                    <?php
                        $maxType =
                            count($typeBreakdown)
                            ? max($typeBreakdown)
                            : 0;
                        ?>

                    <?php foreach (
                            $typeBreakdown
                            as $type => $count
                        ): ?>

                    <div class="fwr-breakdown-row">

                        <span class="fwr-breakdown-label">
                            <?= facilityH(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $type
                                            )
                                        )
                                    ) ?>
                        </span>

                        <div class="fwr-breakdown-track">
                            <div class="fwr-breakdown-bar" style="width: <?= $maxType > 0
                                        ? round(
                                            ($count / $maxType) * 100
                                        )
                                        : 0
                                        ?>%"></div>
                        </div>

                        <strong>
                            <?= (int) $count ?>
                        </strong>

                    </div>

                    <?php endforeach; ?>

                </div>

            </section>


            <section class="fwr-section">

                <div class="fwr-section-title">
                    By Priority
                </div>

                <div class="fwr-breakdown-list">

                    <?php foreach (
                            $priorityBreakdown
                            as $priority => $count
                        ): ?>

                    <div class="fwr-breakdown-row">

                        <span class="fwr-breakdown-label">
                            <?= facilityH($priority) ?>
                        </span>

                        <div class="fwr-breakdown-track">
                            <div class="fwr-breakdown-bar priority" style="width: <?= $report['total_raised'] > 0
                                        ? round(
                                            ($count / $report['total_raised']) * 100
                                        )
                                        : 0
                                        ?>%"></div>
                        </div>

                        <strong>
                            <?= (int) $count ?>
                        </strong>

                    </div>

                    <?php endforeach; ?>

                </div>

            </section>

        </div>


        <!-- OUTLET COSTS -->

        <section class="fwr-section">

            <div class="fwr-section-title">
                Maintenance Cost by Outlet
            </div>

            <div class="fwr-breakdown-list">

                <?php
                    $maxOutletCost =
                        count($outletCosts)
                        ? max($outletCosts)
                        : 0;
                    ?>

                <?php foreach (
                        $outletCosts
                        as $outlet => $cost
                    ): ?>

                <div class="fwr-breakdown-row">

                    <span class="fwr-breakdown-label">
                        <?= facilityH($outlet) ?>
                    </span>

                    <div class="fwr-breakdown-track">
                        <div class="fwr-breakdown-bar cost" style="width: <?= $maxOutletCost > 0
                                    ? round(
                                        ($cost / $maxOutletCost) * 100
                                    )
                                    : 0
                                    ?>%"></div>
                    </div>

                    <strong>
                        ₦<?= number_format(
                                    (float) $cost,
                                    0
                                ) ?>
                    </strong>

                </div>

                <?php endforeach; ?>

            </div>

        </section>


        <!-- OUTSTANDING -->

        <section class="fwr-section">

            <div class="fwr-section-title">
                Outstanding Maintenance Requests
            </div>

            <div class="fwr-table-wrap">

                <table class="fwr-table">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Request</th>
                            <th>Outlet</th>
                            <th>Asset</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Raised</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($outstanding)): ?>

                        <?php $sn = 1; ?>

                        <?php foreach (
                                    $outstanding
                                    as $item
                                ): ?>

                        <tr>
                            <td><?= $sn++ ?></td>

                            <td>
                                <strong>
                                    <?= facilityH($item['request_number']) ?>
                                </strong>
                                <small>
                                    <?= facilityH($item['subject']) ?>
                                </small>
                            </td>

                            <td>
                                <?= facilityH($item['warehouse_name']) ?>
                            </td>

                            <td>
                                <?= facilityH(
                                                $item['asset_code']
                                                ?: 'General Facility'
                                            ) ?>
                            </td>

                            <td>
                                <?= facilityH($item['priority']) ?>
                            </td>

                            <td>
                                <?= facilityH($item['status']) ?>
                            </td>

                            <td>
                                <?= facilityH(
                                                date(
                                                    'd M',
                                                    strtotime(
                                                        $item['created_at']
                                                    )
                                                )
                                            ) ?>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="7" class="fwr-empty-cell">
                                No outstanding Facility requests.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- FAILING ASSETS -->

        <section class="fwr-section">

            <div class="fwr-section-title">
                Frequently Failing Assets — 90 Day Snapshot
            </div>

            <div class="fwr-table-wrap">

                <table class="fwr-table">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Asset Tag / ID</th>
                            <th>Asset</th>
                            <th>Outlet</th>
                            <th>Failures</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($failingAssets)): ?>

                        <?php $sn = 1; ?>

                        <?php foreach (
                                    $failingAssets
                                    as $asset
                                ): ?>

                        <tr>
                            <td><?= $sn++ ?></td>

                            <td>
                                <strong>
                                    <?= facilityH($asset['asset_code']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= facilityH($asset['asset_name']) ?>
                            </td>

                            <td>
                                <?= facilityH($asset['warehouse_name']) ?>
                            </td>

                            <td>
                                <?= (int) $asset['failures'] ?>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="5" class="fwr-empty-cell">
                                No repeat asset failures in the last 90 days.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <?php if (!empty($report['recommendations'])): ?>

        <section class="fwr-section">

            <div class="fwr-section-title">
                Recommendations
            </div>

            <ul class="fwr-recommendations">

                <?php foreach (
                            preg_split(
                                '/\r\n|\r|\n/',
                                trim(
                                    $report['recommendations']
                                )
                            )
                            as $line
                        ): ?>

                <?php if (trim($line) !== ''): ?>
                <li><?= facilityH($line) ?></li>
                <?php endif; ?>

                <?php endforeach; ?>

            </ul>

        </section>

        <?php endif; ?>


        <footer class="fwr-report-footer">
            <span>Prepared by Facility & Projects Department</span>
            <span>Weekly Facility Maintenance Performance Report</span>
            <span>Confidential – Management Use Only</span>
        </footer>

    </article>


    <!-- ====================================================
             REPORT SHEET 2 - PERFORMANCE
        ===================================================== -->

    <article class="fwr-report-sheet fwr-page-break">

        <header class="fwr-subreport-header">
            <h1>Technician & Vendor Performance</h1>
            <p>
                <?= facilityH(
                        date(
                            'M d',
                            strtotime($report['week_start'])
                        )
                    ) ?>
                –
                <?= facilityH(
                        date(
                            'M d, Y',
                            strtotime($report['week_end'])
                        )
                    ) ?>
            </p>
        </header>


        <section class="fwr-section">

            <div class="fwr-section-title">
                Technician Productivity
            </div>

            <div class="fwr-table-wrap">

                <table class="fwr-table">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Technician</th>
                            <th>Assigned</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                            <th>Avg Resolution</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($technicians)): ?>

                        <?php $sn = 1; ?>

                        <?php foreach (
                                    $technicians
                                    as $tech
                                ): ?>

                        <?php
                                    $assignedJobs =
                                        (int) $tech['assigned'];

                                    $completedJobs =
                                        (int) $tech['completed'];

                                    $rate =
                                        $assignedJobs > 0
                                        ? round(
                                            ($completedJobs / $assignedJobs) * 100,
                                            1
                                        )
                                        : 0;
                                    ?>

                        <tr>
                            <td><?= $sn++ ?></td>
                            <td><?= facilityH($tech['fullname']) ?></td>
                            <td><?= $assignedJobs ?></td>
                            <td><?= $completedJobs ?></td>
                            <td><?= $rate ?>%</td>
                            <td>
                                <?= facilityReportDuration(
                                                $tech['avg_resolution_minutes']
                                                ?? 0
                                            ) ?>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="6" class="fwr-empty-cell">
                                No technician activity recorded for this week.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <section class="fwr-section">

            <div class="fwr-section-title">
                Vendor Performance
            </div>

            <div class="fwr-table-wrap">

                <table class="fwr-table">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Vendor</th>
                            <th>Jobs</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                            <th>Avg Resolution</th>
                            <th>Vendor Cost</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($vendors)): ?>

                        <?php $sn = 1; ?>

                        <?php foreach (
                                    $vendors
                                    as $vendor
                                ): ?>

                        <?php
                                    $vendorJobs =
                                        (int) $vendor['jobs'];

                                    $vendorCompleted =
                                        (int) $vendor['completed'];

                                    $vendorRate =
                                        $vendorJobs > 0
                                        ? round(
                                            ($vendorCompleted / $vendorJobs) * 100,
                                            1
                                        )
                                        : 0;
                                    ?>

                        <tr>
                            <td><?= $sn++ ?></td>
                            <td><?= facilityH($vendor['vendor_name']) ?></td>
                            <td><?= $vendorJobs ?></td>
                            <td><?= $vendorCompleted ?></td>
                            <td><?= $vendorRate ?>%</td>
                            <td>
                                <?= facilityReportDuration(
                                                $vendor['avg_resolution_minutes']
                                                ?? 0
                                            ) ?>
                            </td>
                            <td>
                                ₦<?= number_format(
                                                (float) $vendor['total_cost'],
                                                2
                                            ) ?>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="7" class="fwr-empty-cell">
                                No vendor activity recorded for this week.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </article>


    <!-- ====================================================
             REPORT SHEET 3 - REGISTER
        ===================================================== -->

    <article class="fwr-report-sheet fwr-page-break">

        <header class="fwr-subreport-header">
            <h1>Weekly Maintenance Register</h1>
            <p>
                Complete Facility maintenance requests raised during the reporting period.
            </p>
        </header>


        <div class="fwr-table-wrap">

            <table class="fwr-table fwr-register-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Request</th>
                        <th>Date</th>
                        <th>Outlet</th>
                        <th>Asset</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Assigned</th>
                        <th>Status</th>
                        <th>Response</th>
                        <th>Resolution</th>
                        <th>Downtime</th>
                        <th>Cost</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($maintenanceRegister)): ?>

                    <?php $sn = 1; ?>

                    <?php foreach (
                                $maintenanceRegister
                                as $entry
                            ): ?>

                    <tr>
                        <td><?= $sn++ ?></td>

                        <td>
                            <strong>
                                <?= facilityH($entry['request_number']) ?>
                            </strong>
                        </td>

                        <td>
                            <?= facilityH(
                                            date(
                                                'd M',
                                                strtotime(
                                                    $entry['created_at']
                                                )
                                            )
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityH($entry['warehouse_name']) ?>
                        </td>

                        <td>
                            <?= facilityH(
                                            $entry['asset_code']
                                            ?: 'General'
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityH($entry['subject']) ?>
                        </td>

                        <td>
                            <?= facilityH(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $entry['request_type']
                                                )
                                            )
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityH($entry['priority']) ?>
                        </td>

                        <td>
                            <?= facilityH(
                                            $entry['technician_name']
                                            ?: (
                                                $entry['vendor_name']
                                                ?: 'Unassigned'
                                            )
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityH($entry['status']) ?>
                        </td>

                        <td>
                            <?= facilityReportDuration(
                                            $entry['response_minutes']
                                            ?? null
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityReportDuration(
                                            $entry['resolution_minutes']
                                            ?? null
                                        ) ?>
                        </td>

                        <td>
                            <?= facilityReportDuration(
                                            $entry['downtime_minutes']
                                            ?? 0
                                        ) ?>
                        </td>

                        <td>
                            ₦<?= number_format(
                                            (float) $entry['total_cost'],
                                            2
                                        ) ?>
                        </td>
                    </tr>

                    <?php endforeach; ?>

                    <?php else: ?>

                    <tr>
                        <td colspan="14" class="fwr-empty-cell">
                            No Facility maintenance requests were raised during this week.
                        </td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </article>

    <?php endif; ?>

</div>

<?php include '../inc/footer.php'; ?>