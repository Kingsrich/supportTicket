<?php

require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = 'My Dashboard';


$userId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );


$fullname =
    $_SESSION['fullname']
    ?? 'User';


/* ============================================================
   HELPERS
============================================================ */

function myDashboardCount(
    mysqli $connection,
    string $sql
): int {

    $result =
        mysqli_query(
            $connection,
            $sql
        );


    if (!$result) {
        return 0;
    }


    $row =
        mysqli_fetch_assoc(
            $result
        );


    return
        (int) (
            $row['total']
            ?? 0
        );
}


/* ============================================================
   FACILITY TECHNICIAN PROFILE
============================================================ */

$technicianProfile = null;


$techResult =
    mysqli_query(
        $connection,
        "
        SELECT
            ft.id,
            ft.specialization

        FROM facility_technicians ft

        WHERE ft.user_id = {$userId}
          AND ft.status = 'active'

        LIMIT 1
        "
    );


if ($techResult) {

    $technicianProfile =
        mysqli_fetch_assoc(
            $techResult
        );
}


/* ============================================================
   MY IT TICKETS
============================================================ */

$myItTotal =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM tickets

        WHERE user_id = {$userId}
        "
    );


$myItOpen =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM tickets

        WHERE user_id = {$userId}

          AND status NOT IN (
              'Closed',
              'Resolved',
              'Cancelled'
          )
        "
    );


$myItClosed =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM tickets

        WHERE user_id = {$userId}

          AND status IN (
              'Closed',
              'Resolved'
          )
        "
    );


/* ============================================================
   MY FACILITY REQUESTS
============================================================ */

$myFacilityTotal =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM facility_maintenance_requests

        WHERE requested_by = {$userId}
        "
    );


$myFacilityOpen =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM facility_maintenance_requests

        WHERE requested_by = {$userId}

          AND status NOT IN (
              'Closed',
              'Cancelled',
              'Rejected'
          )
        "
    );


$myFacilityClosed =
    myDashboardCount(
        $connection,
        "
        SELECT COUNT(*) total

        FROM facility_maintenance_requests

        WHERE requested_by = {$userId}

          AND status = 'Closed'
        "
    );


/* ============================================================
   MY ASSIGNED ENGINEERING JOBS
============================================================ */

$myAssignedJobs = 0;
$myJobsInProgress = 0;
$myCompletedJobs = 0;


if ($technicianProfile) {

    $technicianId =
        (int) $technicianProfile['id'];


    $myAssignedJobs =
        myDashboardCount(
            $connection,
            "
            SELECT COUNT(*) total

            FROM facility_maintenance_requests

            WHERE assigned_technician_id = {$technicianId}

              AND status NOT IN (
                  'Closed',
                  'Cancelled',
                  'Rejected'
              )
            "
        );


    $myJobsInProgress =
        myDashboardCount(
            $connection,
            "
            SELECT COUNT(*) total

            FROM facility_maintenance_requests

            WHERE assigned_technician_id = {$technicianId}

              AND status = 'In Progress'
            "
        );


    $myCompletedJobs =
        myDashboardCount(
            $connection,
            "
            SELECT COUNT(*) total

            FROM facility_maintenance_requests

            WHERE assigned_technician_id = {$technicianId}

              AND status = 'Closed'
            "
        );
}


/* ============================================================
   RECENT MY IT TICKETS
============================================================ */

$recentMyIt =
    mysqli_query(
        $connection,
        "
        SELECT
            id,
            ticket_number,
            subject,
            priority,
            status,
            created_at

        FROM tickets

        WHERE user_id = {$userId}

        ORDER BY created_at DESC

        LIMIT 6
        "
    );


/* ============================================================
   RECENT MY FACILITY REQUESTS
============================================================ */

$recentMyFacility =
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
            o.warehouse_name

        FROM facility_maintenance_requests m

        INNER JOIN outlets o
            ON o.id = m.outlet_id

        WHERE m.requested_by = {$userId}

        ORDER BY m.created_at DESC

        LIMIT 6
        "
    );


/* ============================================================
   RECENT ASSIGNED TECHNICIAN JOBS
============================================================ */

$recentAssignedJobs = null;


if ($technicianProfile) {

    $technicianId =
        (int) $technicianProfile['id'];


    $recentAssignedJobs =
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

            WHERE m.assigned_technician_id
                = {$technicianId}

            ORDER BY
                FIELD(
                    m.status,
                    'In Progress',
                    'Assigned',
                    'Verification Failed',
                    'Awaiting Verification',
                    'Awaiting Approval',
                    'Closed'
                ),

                m.created_at DESC

            LIMIT 8
            "
        );
}


include '../inc/header.php';

?>

<link rel="stylesheet" href="../assets/css/my-dashboard.css?v=1.0">


<div class="myd-page">


    <!-- ========================================================
         HEADER
    ========================================================= -->

    <div class="myd-header">

        <div>

            <div class="myd-eyebrow">
                My Support Centre
            </div>

            <h2>
                Welcome,
                <?= htmlspecialchars($fullname) ?>
            </h2>

            <p>
                Track your IT Support tickets,
                Facility maintenance requests
                and assigned maintenance work.
            </p>

        </div>


        <div class="myd-header-actions">

            <a href="support_tickets.php?tab=it&new=1" class="myd-btn myd-btn-light">
                <i class="fa-solid fa-laptop-medical"></i>

                IT Support
            </a>


            <a href="support_tickets.php?tab=facility&new=1" class="myd-btn myd-btn-primary">
                <i class="fa-solid fa-screwdriver-wrench"></i>

                Facility Request
            </a>

        </div>

    </div>


    <!-- ========================================================
         PERSONAL KPI CARDS
    ========================================================= -->

    <div class="myd-kpi-grid">


        <a href="support_tickets.php?tab=it" class="myd-kpi-card">

            <div class="myd-kpi-icon it">
                <i class="fa-solid fa-ticket"></i>
            </div>

            <div>

                <span>
                    My IT Tickets
                </span>

                <strong>
                    <?= number_format(
                        $myItTotal
                    ) ?>
                </strong>

                <small>
                    <?= number_format(
                        $myItOpen
                    ) ?>
                    currently open
                </small>

            </div>

        </a>


        <a href="support_tickets.php?tab=facility" class="myd-kpi-card">

            <div class="myd-kpi-icon facility">
                <i class="fa-solid fa-wrench"></i>
            </div>

            <div>

                <span>
                    My Facility Requests
                </span>

                <strong>
                    <?= number_format(
                        $myFacilityTotal
                    ) ?>
                </strong>

                <small>
                    <?= number_format(
                        $myFacilityOpen
                    ) ?>
                    currently open
                </small>

            </div>

        </a>


        <div class="myd-kpi-card">

            <div class="myd-kpi-icon complete">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <div>

                <span>
                    IT Resolved
                </span>

                <strong>
                    <?= number_format(
                        $myItClosed
                    ) ?>
                </strong>

                <small>
                    Completed IT requests
                </small>

            </div>

        </div>


        <div class="myd-kpi-card">

            <div class="myd-kpi-icon complete">
                <i class="fa-solid fa-check-double"></i>
            </div>

            <div>

                <span>
                    Facility Closed
                </span>

                <strong>
                    <?= number_format(
                        $myFacilityClosed
                    ) ?>
                </strong>

                <small>
                    Completed maintenance requests
                </small>

            </div>

        </div>


        <?php if ($technicianProfile): ?>

        <a href="support_tickets.php?tab=facility" class="myd-kpi-card">

            <div class="myd-kpi-icon engineer">
                <i class="fa-solid fa-user-gear"></i>
            </div>

            <div>

                <span>
                    Assigned Jobs
                </span>

                <strong>
                    <?= number_format(
                            $myAssignedJobs
                        ) ?>
                </strong>

                <small>
                    <?= number_format(
                            $myJobsInProgress
                        ) ?>
                    in progress
                </small>

            </div>

        </a>


        <div class="myd-kpi-card">

            <div class="myd-kpi-icon engineer">
                <i class="fa-solid fa-screwdriver"></i>
            </div>

            <div>

                <span>
                    Jobs Completed
                </span>

                <strong>
                    <?= number_format(
                            $myCompletedJobs
                        ) ?>
                </strong>

                <small>
                    Technician history
                </small>

            </div>

        </div>

        <?php endif; ?>


    </div>


    <!-- ========================================================
         QUICK ACTIONS
    ========================================================= -->

    <section class="myd-panel">

        <div class="myd-panel-head">

            <div>
                <h3>
                    Quick Actions
                </h3>

                <p>
                    Common support activities.
                </p>
            </div>

        </div>


        <div class="myd-quick-grid">


            <a href="support_tickets.php?tab=it&new=1" class="myd-quick-card">

                <i class="fa-solid fa-laptop-medical"></i>

                <div>
                    <strong>
                        Raise IT Support Ticket
                    </strong>

                    <span>
                        Report systems, network,
                        device or account problems.
                    </span>
                </div>

            </a>


            <a href="support_tickets.php?tab=facility&new=1" class="myd-quick-card">

                <i class="fa-solid fa-screwdriver-wrench"></i>

                <div>
                    <strong>
                        Raise Facility Request
                    </strong>

                    <span>
                        Report maintenance or
                        equipment problems.
                    </span>
                </div>

            </a>


            <a href="support_tickets.php?tab=it" class="myd-quick-card">

                <i class="fa-solid fa-clock-rotate-left"></i>

                <div>
                    <strong>
                        Track My Tickets
                    </strong>

                    <span>
                        Review your support
                        request history.
                    </span>
                </div>

            </a>


            <a href="support_tickets.php?tab=facility" class="myd-quick-card">

                <i class="fa-solid fa-list-check"></i>

                <div>
                    <strong>
                        Track Maintenance
                    </strong>

                    <span>
                        Follow the status of your
                        Facility requests.
                    </span>
                </div>

            </a>


        </div>

    </section>


    <!-- ========================================================
         MY RECENT REQUESTS
    ========================================================= -->

    <div class="myd-two-col">


        <!-- IT -->

        <section class="myd-panel">

            <div class="myd-panel-head">

                <div>

                    <h3>
                        My Recent IT Tickets
                    </h3>

                    <p>
                        Your latest IT Support activity.
                    </p>

                </div>


                <a href="support_tickets.php?tab=it" class="myd-panel-link">
                    View All
                </a>

            </div>


            <div class="myd-table-wrap">

                <table class="myd-table">

                    <thead>

                        <tr>
                            <th>Ticket</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                        $recentMyIt
                        &&
                        mysqli_num_rows(
                            $recentMyIt
                        )
                    ): ?>

                        <?php while (
                            $ticket =
                                mysqli_fetch_assoc(
                                    $recentMyIt
                                )
                        ): ?>

                        <tr onclick="
                                    location.href =
                                    'ticket_view.php?id=<?= (int) $ticket['id'] ?>'
                                ">

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                            $ticket[
                                                'ticket_number'
                                            ]
                                        ) ?>
                                </strong>

                                <span>
                                    <?= htmlspecialchars(
                                            $ticket[
                                                'subject'
                                            ]
                                        ) ?>
                                </span>

                            </td>


                            <td>

                                <span class="myd-status">
                                    <?= htmlspecialchars(
                                            $ticket[
                                                'status'
                                            ]
                                        ) ?>
                                </span>

                            </td>


                            <td>
                                <?= date(
                                        'M d',
                                        strtotime(
                                            $ticket[
                                                'created_at'
                                            ]
                                        )
                                    ) ?>
                            </td>

                        </tr>

                        <?php endwhile; ?>

                        <?php else: ?>

                        <tr>

                            <td colspan="3" class="myd-empty">
                                You have not raised an
                                IT Support ticket yet.
                            </td>

                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- FACILITY -->

        <section class="myd-panel">

            <div class="myd-panel-head">

                <div>

                    <h3>
                        My Recent Facility Requests
                    </h3>

                    <p>
                        Your latest maintenance requests.
                    </p>

                </div>


                <a href="support_tickets.php?tab=facility" class="myd-panel-link">
                    View All
                </a>

            </div>


            <div class="myd-table-wrap">

                <table class="myd-table">

                    <thead>

                        <tr>
                            <th>Request</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                        $recentMyFacility
                        &&
                        mysqli_num_rows(
                            $recentMyFacility
                        )
                    ): ?>

                        <?php while (
                            $request =
                                mysqli_fetch_assoc(
                                    $recentMyFacility
                                )
                        ): ?>

                        <tr onclick="
                                    location.href =
                                    'maintenance_view.php?id=<?= (int) $request['id'] ?>'
                                ">

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                            $request[
                                                'request_number'
                                            ]
                                        ) ?>
                                </strong>

                                <span>
                                    <?= htmlspecialchars(
                                            $request[
                                                'subject'
                                            ]
                                        ) ?>
                                </span>

                            </td>


                            <td>

                                <span class="myd-status">
                                    <?= htmlspecialchars(
                                            $request[
                                                'status'
                                            ]
                                        ) ?>
                                </span>

                            </td>


                            <td>
                                <?= date(
                                        'M d',
                                        strtotime(
                                            $request[
                                                'created_at'
                                            ]
                                        )
                                    ) ?>
                            </td>

                        </tr>

                        <?php endwhile; ?>

                        <?php else: ?>

                        <tr>

                            <td colspan="3" class="myd-empty">
                                You have not raised a
                                Facility request yet.
                            </td>

                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


    </div>


    <!-- ========================================================
         TECHNICIAN ASSIGNMENTS
    ========================================================= -->

    <?php if ($technicianProfile): ?>

    <section class="myd-panel">

        <div class="myd-panel-head">

            <div>

                <h3>
                    My Assigned Maintenance Jobs
                </h3>

                <p>
                    Facility jobs currently assigned
                    to your engineer profile.
                </p>

            </div>

        </div>


        <div class="myd-table-wrap">

            <table class="myd-table myd-job-table">

                <thead>

                    <tr>
                        <th>Request</th>
                        <th>Outlet</th>
                        <th>Asset</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if (
                        $recentAssignedJobs
                        &&
                        mysqli_num_rows(
                            $recentAssignedJobs
                        )
                    ): ?>

                    <?php while (
                            $job =
                                mysqli_fetch_assoc(
                                    $recentAssignedJobs
                                )
                        ): ?>

                    <tr onclick="
                                    location.href =
                                    'maintenance_view.php?id=<?= (int) $job['id'] ?>'
                                ">

                        <td>

                            <strong>
                                <?= htmlspecialchars(
                                            $job[
                                                'request_number'
                                            ]
                                        ) ?>
                            </strong>

                            <span>
                                <?= htmlspecialchars(
                                            $job[
                                                'subject'
                                            ]
                                        ) ?>
                            </span>

                        </td>


                        <td>
                            <?= htmlspecialchars(
                                        $job[
                                            'warehouse_name'
                                        ]
                                    ) ?>
                        </td>


                        <td>
                            <?= htmlspecialchars(
                                        $job[
                                            'asset_code'
                                        ]
                                        ?: 'General Facility'
                                    ) ?>
                        </td>


                        <td>
                            <?= htmlspecialchars(
                                        $job[
                                            'priority'
                                        ]
                                    ) ?>
                        </td>


                        <td>

                            <span class="myd-status">

                                <?= htmlspecialchars(
                                            $job[
                                                'status'
                                            ]
                                        ) ?>

                            </span>

                        </td>


                        <td>
                            <?= date(
                                        'M d',
                                        strtotime(
                                            $job[
                                                'created_at'
                                            ]
                                        )
                                    ) ?>
                        </td>

                    </tr>

                    <?php endwhile; ?>

                    <?php else: ?>

                    <tr>

                        <td colspan="6" class="myd-empty">
                            No maintenance jobs are
                            currently assigned to you.
                        </td>

                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <?php endif; ?>


</div>


<?php include '../inc/footer.php'; ?>