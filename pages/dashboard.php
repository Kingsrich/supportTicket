<?php

require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = 'Dashboard';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));
$departmentName = $_SESSION['department_name'] ?? '';

$isAdmin = $role === 'admin';
$isFacilityOfficer = facilityHasRole($connection, $userId, 'facility_officer');
$isProjectManager = facilityHasRole($connection, $userId, 'facility_project_manager');
$canViewManagementDashboard = $isAdmin || $isFacilityOfficer || $isProjectManager;

if (!$canViewManagementDashboard) {
    header('Location: my_dashboard.php');
    exit;
}

$canManageFacility = true;

/* ============================================================
   HELPERS
============================================================ */

function dashboardCount(mysqli $connection, string $sql): int
{
    $result = mysqli_query($connection, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (int) ($row['total'] ?? 0);
}


function dashboardValue(mysqli $connection, string $sql)
{
    $result = mysqli_query($connection, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return $row['value'] ?? 0;
}


/* ============================================================
   IT SUPPORT METRICS
============================================================ */

$totalItTickets =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM tickets
        "
    );

$openItTickets =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM tickets
        WHERE status NOT IN (
            'Closed',
            'Resolved',
            'Cancelled'
        )
        "
    );

$criticalItTickets =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM tickets
        WHERE priority = 'Critical'
          AND status NOT IN (
              'Closed',
              'Resolved',
              'Cancelled'
          )
        "
    );

$itClosedThisMonth =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM tickets
        WHERE status IN (
            'Closed',
            'Resolved'
        )
          AND updated_at >= DATE_FORMAT(
              CURDATE(),
              '%Y-%m-01'
          )
        "
    );


/* ============================================================
   FACILITY METRICS
============================================================ */

$totalFacilityRequests =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_maintenance_requests
        "
    );

$openFacilityRequests =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_maintenance_requests
        WHERE status NOT IN (
            'Closed',
            'Cancelled',
            'Rejected'
        )
        "
    );

$criticalFacilityRequests =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_maintenance_requests
        WHERE priority = 'Critical'
          AND status NOT IN (
              'Closed',
              'Cancelled',
              'Rejected'
          )
        "
    );

$facilityClosedThisMonth =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_maintenance_requests
        WHERE status = 'Closed'
          AND closed_at >= DATE_FORMAT(
              CURDATE(),
              '%Y-%m-01'
          )
        "
    );


/* ============================================================
   ASSET / PM METRICS
============================================================ */

$totalAssets =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_assets
        WHERE operational_status <> 'retired'
        "
    );

$assetsDown =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_assets
        WHERE operational_status = 'down'
        "
    );

$overduePm =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_pm_schedules
        WHERE status = 'active'
          AND next_due_date < CURDATE()
        "
    );

$pmDueSoon =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM facility_pm_schedules
        WHERE status = 'active'
          AND next_due_date
              BETWEEN CURDATE()
              AND DATE_ADD(
                  CURDATE(),
                  INTERVAL 14 DAY
              )
        "
    );


/* ============================================================
   MAINTENANCE COST
============================================================ */

$maintenanceCostMonth =
    (float) dashboardValue(
        $connection,
        "
        SELECT
            COALESCE(
                SUM(c.amount),
                0
            ) AS value
        FROM facility_maintenance_costs c

        INNER JOIN facility_maintenance_requests m
            ON m.id = c.maintenance_request_id

        WHERE m.created_at >= DATE_FORMAT(
            CURDATE(),
            '%Y-%m-01'
        )
        "
    );


/* ============================================================
   USERS / OUTLETS
============================================================ */

$activeUsers =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM users
        WHERE status = 'active'
        "
    );

$activeOutlets =
    dashboardCount(
        $connection,
        "
        SELECT COUNT(*) AS total
        FROM outlets
        WHERE status = 'active'
        "
    );


/* ============================================================
   RECENT IT TICKETS
============================================================ */

$recentItTickets =
    mysqli_query(
        $connection,
        "
        SELECT
            t.id,
            t.ticket_number,
            t.subject,
            t.priority,
            t.status,
            t.created_at,
            u.fullname
        FROM tickets t

        LEFT JOIN users u
            ON u.id = t.user_id

        ORDER BY t.created_at DESC

        LIMIT 6
        "
    );


/* ============================================================
   RECENT FACILITY REQUESTS
============================================================ */

$recentFacilityRequests =
    mysqli_query(
        $connection,
        "
        SELECT
            m.id,
            m.request_number,
            m.subject,
            m.priority,
            m.status,
            m.created_at,
            o.warehouse_name,
            a.asset_code
        FROM facility_maintenance_requests m

        INNER JOIN outlets o
            ON o.id = m.outlet_id

        LEFT JOIN facility_assets a
            ON a.id = m.asset_id

        ORDER BY m.created_at DESC

        LIMIT 6
        "
    );


/* ============================================================
   FACILITY STATUS BREAKDOWN
============================================================ */

$facilityStatus =
    mysqli_query(
        $connection,
        "
        SELECT
            status,
            COUNT(*) AS total
        FROM facility_maintenance_requests
        GROUP BY status
        ORDER BY total DESC
        "
    );


/* ============================================================
   FREQUENTLY FAILING ASSETS
============================================================ */

$failingAssets =
    mysqli_query(
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

        WHERE m.request_type IN (
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

        LIMIT 6
        "
    );


include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/dashboard.css?v=5.0">


<div class="app-dashboard">


    <!-- ========================================================
         PAGE INTRO
    ========================================================= -->

    <div class="app-dashboard-head">

        <div>

            <div class="app-dashboard-eyebrow">
                Operations Overview
            </div>

            <h2>
                Support & Maintenance Dashboard
            </h2>

            <p>
                Monitor IT support, Facility maintenance,
                assets and preventive maintenance from one place.
            </p>

        </div>


        <div class="app-dashboard-actions">

            <a href="support_tickets.php?tab=it&new=1" class="app-dash-btn app-dash-btn-light">
                <i class="fa-solid fa-laptop-medical"></i>
                IT Ticket
            </a>

            <a href="support_tickets.php?tab=facility&new=1" class="app-dash-btn app-dash-btn-primary">
                <i class="fa-solid fa-screwdriver-wrench"></i>
                Maintenance Request
            </a>

        </div>

    </div>


    <!-- ========================================================
         PRIMARY KPI CARDS
    ========================================================= -->

    <div class="app-kpi-grid">

        <a href="support_tickets.php?tab=it" class="app-kpi-card">

            <div class="app-kpi-icon it">
                <i class="fa-solid fa-ticket"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Open IT Tickets
                </span>

                <strong>
                    <?= number_format($openItTickets) ?>
                </strong>

                <small>
                    <?= number_format($totalItTickets) ?>
                    total tickets
                </small>

            </div>

        </a>


        <a href="support_tickets.php?tab=facility" class="app-kpi-card">

            <div class="app-kpi-icon facility">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Open Maintenance
                </span>

                <strong>
                    <?= number_format($openFacilityRequests) ?>
                </strong>

                <small>
                    <?= number_format($totalFacilityRequests) ?>
                    total requests
                </small>

            </div>

        </a>


        <a href="facility_assets.php" class="app-kpi-card">

            <div class="app-kpi-icon assets">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Registered Assets
                </span>

                <strong>
                    <?= number_format($totalAssets) ?>
                </strong>

                <small>
                    <?= number_format($assetsDown) ?>
                    currently down
                </small>

            </div>

        </a>


        <a href="preventive_maintenance.php" class="app-kpi-card">

            <div class="app-kpi-icon pm">
                <i class="fa-solid fa-calendar-check"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Overdue PM
                </span>

                <strong>
                    <?= number_format($overduePm) ?>
                </strong>

                <small>
                    <?= number_format($pmDueSoon) ?>
                    due within 14 days
                </small>

            </div>

        </a>


        <div class="app-kpi-card">

            <div class="app-kpi-icon cost">
                <i class="fa-solid fa-coins"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Maintenance Cost
                </span>

                <strong class="app-money">
                    ₦<?= number_format(
                        $maintenanceCostMonth,
                        0
                    ) ?>
                </strong>

                <small>
                    Current month
                </small>

            </div>

        </div>


        <div class="app-kpi-card">

            <div class="app-kpi-icon outlet">
                <i class="fa-solid fa-store"></i>
            </div>

            <div class="app-kpi-content">

                <span>
                    Active Outlets
                </span>

                <strong>
                    <?= number_format($activeOutlets) ?>
                </strong>

                <small>
                    Across the organization
                </small>

            </div>

        </div>

    </div>


    <!-- ========================================================
         ALERT / PERFORMANCE STRIP
    ========================================================= -->

    <div class="app-summary-grid">

        <div class="app-summary-card danger">

            <span>
                Critical IT Issues
            </span>

            <strong>
                <?= number_format($criticalItTickets) ?>
            </strong>

            <small>
                Open critical IT incidents
            </small>

        </div>


        <div class="app-summary-card danger">

            <span>
                Critical Facility Issues
            </span>

            <strong>
                <?= number_format($criticalFacilityRequests) ?>
            </strong>

            <small>
                Open critical maintenance jobs
            </small>

        </div>


        <div class="app-summary-card success">

            <span>
                IT Closed This Month
            </span>

            <strong>
                <?= number_format($itClosedThisMonth) ?>
            </strong>

            <small>
                Resolved support requests
            </small>

        </div>


        <div class="app-summary-card success">

            <span>
                Facility Closed This Month
            </span>

            <strong>
                <?= number_format($facilityClosedThisMonth) ?>
            </strong>

            <small>
                Completed maintenance jobs
            </small>

        </div>


        <div class="app-summary-card neutral">

            <span>
                Active Users
            </span>

            <strong>
                <?= number_format($activeUsers) ?>
            </strong>

            <small>
                Enabled system users
            </small>

        </div>

    </div>


    <!-- ========================================================
         QUICK ACTIONS
    ========================================================= -->

    <section class="app-dashboard-panel">

        <div class="app-panel-header">

            <div>
                <h3>
                    Quick Actions
                </h3>

                <p>
                    Common support and maintenance operations.
                </p>
            </div>

        </div>


        <div class="app-quick-grid">

            <a href="support_tickets.php?tab=it&new=1" class="app-quick-card">

                <div class="app-quick-icon">
                    <i class="fa-solid fa-laptop-medical"></i>
                </div>

                <div>
                    <strong>
                        Raise IT Ticket
                    </strong>

                    <span>
                        Report systems, devices,
                        network or account problems.
                    </span>
                </div>

            </a>


            <a href="support_tickets.php?tab=facility&new=1" class="app-quick-card">

                <div class="app-quick-icon">
                    <i class="fa-solid fa-wrench"></i>
                </div>

                <div>
                    <strong>
                        Raise Maintenance Request
                    </strong>

                    <span>
                        Report asset breakdowns or
                        general Facility issues.
                    </span>
                </div>

            </a>


            <a href="facility_assets.php" class="app-quick-card">

                <div class="app-quick-icon">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>

                <div>
                    <strong>
                        Asset Register
                    </strong>

                    <span>
                        View asset tags, locations,
                        condition and maintenance history.
                    </span>
                </div>

            </a>


            <a href="preventive_maintenance.php" class="app-quick-card">

                <div class="app-quick-icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>

                <div>
                    <strong>
                        Preventive Maintenance
                    </strong>

                    <span>
                        Review due, upcoming and
                        overdue maintenance schedules.
                    </span>
                </div>

            </a>


            <?php if ($canManageFacility): ?>

                <a href="facility_weekly_reports.php" class="app-quick-card">

                    <div class="app-quick-icon">
                        <i class="fa-solid fa-chart-column"></i>
                    </div>

                    <div>
                        <strong>
                            Facility Reports
                        </strong>

                        <span>
                            Review maintenance performance,
                            cost and downtime.
                        </span>
                    </div>

                </a>

            <?php endif; ?>


            <?php if ($isAdmin): ?>

                <a href="users.php" class="app-quick-card">

                    <div class="app-quick-icon">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>

                    <div>
                        <strong>
                            Manage Users
                        </strong>

                        <span>
                            Maintain staff access,
                            roles and departments.
                        </span>
                    </div>

                </a>

            <?php endif; ?>

        </div>

    </section>


    <!-- ========================================================
         RECENT ACTIVITY
    ========================================================= -->

    <div class="app-two-column">


        <!-- IT -->

        <section class="app-dashboard-panel">

            <div class="app-panel-header">

                <div>
                    <h3>
                        Recent IT Support Tickets
                    </h3>

                    <p>
                        Latest requests submitted to IT.
                    </p>
                </div>

                <a href="support_tickets.php?tab=it" class="app-panel-link">
                    View All
                </a>

            </div>


            <div class="app-table-wrap">

                <table class="app-table">

                    <thead>

                        <tr>
                            <th>Ticket</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Raised</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            $recentItTickets
                            &&
                            mysqli_num_rows(
                                $recentItTickets
                            )
                        ): ?>

                            <?php while (
                                $row =
                                mysqli_fetch_assoc(
                                    $recentItTickets
                                )
                            ): ?>

                                <tr onclick="
                                        location.href =
                                        'ticket_view.php?id=<?= (int) $row['id'] ?>'
                                    ">

                                    <td>

                                        <div class="app-request-cell">

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $row['ticket_number']
                                                ) ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars(
                                                    $row['subject']
                                                ) ?>
                                            </span>

                                        </div>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $row['priority']
                                        ) ?>
                                    </td>

                                    <td>

                                        <span class="app-status">
                                            <?= htmlspecialchars(
                                                $row['status']
                                            ) ?>
                                        </span>

                                    </td>

                                    <td class="app-muted">
                                        <?= date(
                                            'M d',
                                            strtotime(
                                                $row['created_at']
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="4" class="app-empty">
                                    No IT tickets yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- FACILITY -->

        <section class="app-dashboard-panel">

            <div class="app-panel-header">

                <div>
                    <h3>
                        Recent Facility Requests
                    </h3>

                    <p>
                        Latest maintenance activity.
                    </p>
                </div>

                <a href="support_tickets.php?tab=facility" class="app-panel-link">
                    View All
                </a>

            </div>


            <div class="app-table-wrap">

                <table class="app-table">

                    <thead>

                        <tr>
                            <th>Request</th>
                            <th>Outlet</th>
                            <th>Status</th>
                            <th>Raised</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            $recentFacilityRequests
                            &&
                            mysqli_num_rows(
                                $recentFacilityRequests
                            )
                        ): ?>

                            <?php while (
                                $row =
                                mysqli_fetch_assoc(
                                    $recentFacilityRequests
                                )
                            ): ?>

                                <tr onclick="
                                        location.href =
                                        'maintenance_view.php?id=<?= (int) $row['id'] ?>'
                                    ">

                                    <td>

                                        <div class="app-request-cell">

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $row['request_number']
                                                ) ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars(
                                                    $row['subject']
                                                ) ?>
                                            </span>

                                        </div>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $row['warehouse_name']
                                        ) ?>
                                    </td>

                                    <td>

                                        <span class="app-status">
                                            <?= htmlspecialchars(
                                                $row['status']
                                            ) ?>
                                        </span>

                                    </td>

                                    <td class="app-muted">
                                        <?= date(
                                            'M d',
                                            strtotime(
                                                $row['created_at']
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="4" class="app-empty">
                                    No Facility requests yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </div>


    <!-- ========================================================
         FACILITY MANAGEMENT SECTION
    ========================================================= -->

    <?php if ($canManageFacility): ?>

        <div class="app-two-column">


            <!-- STATUS -->

            <section class="app-dashboard-panel">

                <div class="app-panel-header">

                    <div>
                        <h3>
                            Maintenance Status
                        </h3>

                        <p>
                            Current Facility workflow distribution.
                        </p>
                    </div>

                </div>


                <div class="app-status-list">

                    <?php while (
                        $facilityStatus
                        &&
                        ($statusRow =
                            mysqli_fetch_assoc(
                                $facilityStatus
                            ))
                    ): ?>

                        <div class="app-status-row">

                            <span>
                                <?= htmlspecialchars(
                                    $statusRow['status']
                                ) ?>
                            </span>

                            <strong>
                                <?= number_format(
                                    $statusRow['total']
                                ) ?>
                            </strong>

                        </div>

                    <?php endwhile; ?>

                </div>

            </section>


            <!-- FAILING ASSETS -->

            <section class="app-dashboard-panel">

                <div class="app-panel-header">

                    <div>
                        <h3>
                            Frequently Failing Assets
                        </h3>

                        <p>
                            Repeat failures recorded during the last 90 days.
                        </p>
                    </div>

                </div>


                <?php if (
                    $failingAssets
                    &&
                    mysqli_num_rows(
                        $failingAssets
                    )
                ): ?>

                    <div class="app-failure-list">

                        <?php while (
                            $asset =
                            mysqli_fetch_assoc(
                                $failingAssets
                            )
                        ): ?>

                            <a href="facility_asset_view.php?id=<?= (int) $asset['id'] ?>" class="app-failure-row">

                                <div>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $asset['asset_code']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $asset['asset_name']
                                        ) ?>
                                        ·
                                        <?= htmlspecialchars(
                                            $asset['warehouse_name']
                                        ) ?>
                                    </span>

                                </div>

                                <span class="app-failure-count">
                                    <?= (int) $asset['failures'] ?>
                                </span>

                            </a>

                        <?php endwhile; ?>

                    </div>

                <?php else: ?>

                    <div class="app-empty">
                        No repeat asset failures recorded.
                    </div>

                <?php endif; ?>

            </section>

        </div>

    <?php endif; ?>


</div>


<?php include '../inc/footer.php'; ?>