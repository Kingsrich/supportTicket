<?php require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';
$pageTitle = 'Facility Reports';
$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!facilityHasRole($connection, $uid, ['facility_officer', 'facility_project_manager', 'facility_admin'])) {
    header('Location: support_tickets.php?tab=facility');
    exit;
} /* ============================================================ FILTERS ============================================================ */
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$outletId = (int) ($_GET['outlet'] ?? 0);
$fromEscaped = mysqli_real_escape_string($connection, $from);
$toEscaped = mysqli_real_escape_string($connection, $to);
$where = " DATE(m.created_at) BETWEEN '{$fromEscaped}' AND '{$toEscaped}' ";
if ($outletId > 0) {
    $where .= " AND m.outlet_id = {$outletId}";
} /* ============================================================ REPORT DATA ============================================================ */
$reportSql = " SELECT m.id, m.request_number, m.subject, m.priority, m.status, o.warehouse_name, a.asset_code, a.asset_name, u.fullname technician_name, v.vendor_name, m.created_at, CASE WHEN m.assigned_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.created_at, m.assigned_at ) END response_minutes, CASE WHEN m.closed_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.created_at, m.closed_at ) END resolution_minutes, CASE WHEN m.downtime_started_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.downtime_started_at, COALESCE( m.restored_at, NOW() ) ) ELSE 0 END downtime_minutes, COALESCE( SUM(c.amount), 0 ) total_cost FROM facility_maintenance_requests m INNER JOIN outlets o ON o.id = m.outlet_id LEFT JOIN facility_assets a ON a.id = m.asset_id LEFT JOIN facility_technicians ft ON ft.id = m.assigned_technician_id LEFT JOIN users u ON u.id = ft.user_id LEFT JOIN facility_vendors v ON v.id = m.assigned_vendor_id LEFT JOIN facility_maintenance_costs c ON c.maintenance_request_id = m.id WHERE {$where} GROUP BY m.id ORDER BY m.created_at DESC ";
$rows = mysqli_query($connection, $reportSql); /* ============================================================ CSV EXPORT ============================================================ */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="facility-maintenance-report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Request', 'Subject', 'Outlet', 'Asset', 'Priority', 'Status', 'Technician', 'Vendor', 'Created', 'Response Minutes', 'Resolution Minutes', 'Downtime Minutes', 'Cost']);
    while ($rows && ($row = mysqli_fetch_assoc($rows))) {
        fputcsv($output, [$row['request_number'], $row['subject'], $row['warehouse_name'], $row['asset_code'] ?: 'General', $row['priority'], $row['status'], $row['technician_name'], $row['vendor_name'], $row['created_at'], $row['response_minutes'], $row['resolution_minutes'], $row['downtime_minutes'], $row['total_cost']]);
    }
    fclose($output);
    exit;
} /* ============================================================ SUMMARY METRICS ============================================================ */
$summaryResult = mysqli_query($connection, " SELECT COUNT(*) total_requests, SUM( m.status = 'Closed' ) completed_requests, COALESCE( AVG( CASE WHEN m.assigned_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.created_at, m.assigned_at ) END ), 0 ) avg_response_minutes, COALESCE( AVG( CASE WHEN m.closed_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.created_at, m.closed_at ) END ), 0 ) avg_resolution_minutes, COALESCE( SUM( CASE WHEN m.downtime_started_at IS NOT NULL THEN TIMESTAMPDIFF( MINUTE, m.downtime_started_at, COALESCE( m.restored_at, NOW() ) ) ELSE 0 END ), 0 ) downtime_minutes FROM facility_maintenance_requests m WHERE {$where} ");
$summary = $summaryResult ? mysqli_fetch_assoc($summaryResult) : [];
$totalRequests = (int) ($summary['total_requests'] ?? 0);
$completedRequests = (int) ($summary['completed_requests'] ?? 0);
$completionRate = $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 1) : 0;
$avgResponseHours = round(((float) ($summary['avg_response_minutes'] ?? 0)) / 60, 1);
$avgResolutionHours = round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1);
$totalDowntimeHours = round(((float) ($summary['downtime_minutes'] ?? 0)) / 60, 1); /* ============================================================ TOTAL COST ============================================================ */
$costResult = mysqli_query($connection, " SELECT COALESCE( SUM(c.amount), 0 ) total_cost FROM facility_maintenance_costs c INNER JOIN facility_maintenance_requests m ON m.id = c.maintenance_request_id WHERE {$where} ");
$totalCost = $costResult ? (float) (mysqli_fetch_assoc($costResult)['total_cost'] ?? 0) : 0; /* ============================================================ OUTLETS ============================================================ */
$outlets = mysqli_query($connection, " SELECT id, warehouse_name FROM outlets WHERE status = 'active' ORDER BY warehouse_name "); /* ============================================================ SELECTED OUTLET NAME ============================================================ */
$selectedOutletName = 'All Outlets';
if ($outletId > 0) {
    $selectedOutlet = mysqli_query($connection, " SELECT warehouse_name FROM outlets WHERE id = {$outletId} LIMIT 1 ");
    if ($selectedOutlet && ($selectedOutletRow = mysqli_fetch_assoc($selectedOutlet))) {
        $selectedOutletName = $selectedOutletRow['warehouse_name'];
    }
}
include '../inc/header.php'; ?>
<link rel="stylesheet" href="../assets/css/facility-reports.css?v=1.0">
<div class="fr-page">
    <!-- ======================================================== HEADER ========================================================= -->
    <!-- ========================================================
     HEADER
========================================================= -->
    <div class="fr-page-header">

        <div>
            <div class="fr-eyebrow">Facility Management</div>
            <h2>Facility Reports</h2>
            <p>
                Review maintenance response time, resolution time,
                downtime, cost and operational performance.
            </p>
        </div>

        <div class="fr-header-actions">
            <a href="facility_weekly_reports.php" class="fr-btn fr-btn-weekly">
                <i class="fa-solid fa-calendar-week"></i>
                Weekly Reports
            </a>
        </div>

    </div>
    <!-- ======================================================== FILTER BAR ========================================================= -->
    <section class="fr-filter-panel">
        <form method="GET" class="fr-filter-form">
            <div class="fr-field"> <label> From Date </label> <input type="date" name="from"
                    value="<?= facilityH($from) ?>"> </div>
            <div class="fr-field"> <label> To Date </label> <input type="date" name="to" value="<?= facilityH($to) ?>">
            </div>
            <div class="fr-field"> <label> Outlet </label> <select name="outlet">
                    <option value="0"> All Outlets </option>
                    <?php while ($outlet = mysqli_fetch_assoc($outlets)): ?>
                    <option value="<?= (int) $outlet['id'] ?>"
                        <?= $outletId === (int) $outlet['id'] ? 'selected' : '' ?>>
                        <?= facilityH($outlet['warehouse_name']) ?>
                    </option> <?php endwhile; ?>
                </select> </div>
            <div class="fr-filter-actions"> <button type="submit" class="fr-btn fr-btn-primary"> <i
                        class="fa-solid fa-filter"></i> Apply Filter </button> <button type="submit" name="export"
                    value="csv" class="fr-btn fr-btn-light"> <i class="fa-solid fa-file-csv"></i> Download CSV </button>
            </div>
        </form>
        <div class="fr-filter-context"> <span> <i class="fa-solid fa-calendar-days"></i>
                <?= facilityH(date('M d, Y', strtotime($from))) ?> —
                <?= facilityH(date('M d, Y', strtotime($to))) ?> </span> <span> <i class="fa-solid fa-location-dot"></i>
                <?= facilityH($selectedOutletName) ?> </span> </div>
    </section>
    <!-- ======================================================== KPI CARDS ========================================================= -->
    <div class="fr-kpi-grid">
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon requests"> <i class="fa-solid fa-ticket"></i> </div>
            <div> <span> Total Requests </span> <strong> <?= $totalRequests ?> </strong> <small> Requests in selected
                    period </small> </div>
        </div>
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon completed"> <i class="fa-solid fa-circle-check"></i> </div>
            <div> <span> Completion Rate </span> <strong> <?= $completionRate ?>% </strong> <small> Closed maintenance
                    requests </small> </div>
        </div>
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon response"> <i class="fa-solid fa-stopwatch"></i> </div>
            <div> <span> Avg. Response </span> <strong> <?= $avgResponseHours ?> <em>hrs</em> </strong> <small> Request
                    to assignment </small> </div>
        </div>
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon resolution"> <i class="fa-solid fa-clock"></i> </div>
            <div> <span> Avg. Resolution </span> <strong> <?= $avgResolutionHours ?> <em>hrs</em> </strong> <small>
                    Request to closure </small> </div>
        </div>
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon downtime"> <i class="fa-solid fa-triangle-exclamation"></i> </div>
            <div> <span> Total Downtime </span> <strong> <?= $totalDowntimeHours ?> <em>hrs</em> </strong> <small>
                    Equipment downtime recorded </small> </div>
        </div>
        <div class="fr-kpi-card">
            <div class="fr-kpi-icon cost"> <i class="fa-solid fa-naira-sign"></i> </div>
            <div> <span> Maintenance Cost </span> <strong class="fr-money"> ₦<?= number_format($totalCost, 0) ?>
                </strong> <small> Total recorded maintenance cost </small> </div>
        </div>
    </div>
    <!-- ======================================================== REPORT TABLE ========================================================= -->
    <section class="fr-panel">
        <div class="fr-panel-header">
            <div>
                <h3> Maintenance Performance Report </h3>
                <p> Detailed maintenance requests for the selected reporting period. </p>
            </div>
        </div>
        <div class="fr-table-wrap">
            <table class="fr-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Outlet / Asset</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Engineer / Vendor</th>
                        <th>Response</th>
                        <th>Resolution</th>
                        <th>Downtime</th>
                        <th class="number">Cost</th>
                    </tr>
                </thead>
                <tbody> <?php if ($rows && mysqli_num_rows($rows) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($rows)): ?>
                    <?php $statusClass = 'status-' . strtolower(str_replace(' ', '-', $row['status']));
                            $priorityClass = 'priority-' . strtolower($row['priority']); ?>
                    <tr onclick=" location.href = 'maintenance_view.php?id=<?= (int) $row['id'] ?>' ">
                        <td>
                            <div class="fr-request"> <strong> <?= facilityH($row['request_number']) ?> </strong>
                                <span> <?= facilityH($row['subject']) ?> </span> <small>
                                    <?= facilityH(date('M d, Y', strtotime($row['created_at']))) ?> </small>
                            </div>
                        </td>
                        <td>
                            <div class="fr-location"> <strong> <?= facilityH($row['warehouse_name']) ?> </strong>
                                <span>
                                    <?= !empty($row['asset_code']) ? facilityH($row['asset_code']) : 'General Facility' ?>
                                </span> <?php if (!empty($row['asset_name'])): ?> <small>
                                    <?= facilityH($row['asset_name']) ?> </small> <?php endif; ?>
                            </div>
                        </td>
                        <td> <span class=" fr-priority <?= facilityH($priorityClass) ?> ">
                                <?= facilityH($row['priority']) ?> </span> </td>
                        <td> <span class=" fr-status <?= facilityH($statusClass) ?> ">
                                <?= facilityH($row['status']) ?> </span> </td>
                        <td>
                            <div class="fr-assignee"> <strong> <?= facilityH($row['technician_name'] ?: '—') ?>
                                </strong> <span> <?= facilityH($row['vendor_name'] ?: '') ?> </span> </div>
                        </td>
                        <td> <?= $row['response_minutes'] !== null ? round($row['response_minutes'] / 60, 1) . ' hrs' : '—' ?>
                        </td>
                        <td> <?= $row['resolution_minutes'] !== null ? round($row['resolution_minutes'] / 60, 1) . ' hrs' : '—' ?>
                        </td>
                        <td> <?= round($row['downtime_minutes'] / 60, 1) ?> hrs </td>
                        <td class="number"> ₦<?= number_format((float) $row['total_cost'], 2) ?> </td>
                    </tr> <?php endwhile; ?> <?php else: ?>
                    <tr>
                        <td colspan="9" class="fr-empty-cell">
                            <div class="fr-empty-state"> <i class="fa-solid fa-chart-column"></i> <strong> No records in
                                    this period </strong> <span> Adjust the date range or outlet filter to view
                                    maintenance report data. </span> </div>
                        </td>
                    </tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div> <?php include '../inc/footer.php'; ?>