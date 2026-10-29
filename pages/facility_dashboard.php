<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';
$pageTitle = 'Facility Dashboard';
$uid = (int) ($_SESSION['user_id'] ?? 0);

if (!facilityHasRole($connection, $uid, ['facility_officer','facility_verifier','facility_project_manager','facility_admin'])) {
    header('Location: support_tickets.php?tab=facility');
    exit;
}

function fdScalar(mysqli $connection, string $sql)
{
    $result = mysqli_query($connection, $sql);
    $row = $result ? mysqli_fetch_assoc($result) : [];
    return $row['v'] ?? 0;
}

$outstanding = (int) fdScalar($connection, "SELECT COUNT(*) v FROM facility_maintenance_requests WHERE status NOT IN ('Closed','Cancelled','Rejected')");
$completed = (int) fdScalar($connection, "SELECT COUNT(*) v FROM facility_maintenance_requests WHERE status='Closed' AND closed_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$overdue = (int) fdScalar($connection, "SELECT COUNT(*) v FROM facility_pm_schedules WHERE status='active' AND next_due_date<CURDATE()");
$cost = (float) fdScalar($connection, "SELECT COALESCE(SUM(c.amount),0) v FROM facility_maintenance_costs c JOIN facility_maintenance_requests m ON m.id=c.maintenance_request_id WHERE m.created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$avg = (float) fdScalar($connection, "SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR,created_at,closed_at)),0) v FROM facility_maintenance_requests WHERE closed_at IS NOT NULL AND closed_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)");

$pq = mysqli_query($connection, "SELECT COUNT(*) total,SUM(status='Completed') completed FROM facility_pm_runs WHERE due_date>=DATE_SUB(CURDATE(),INTERVAL 90 DAY)");
$pr = $pq ? mysqli_fetch_assoc($pq) : [];
$pm = (int) ($pr['total'] ?? 0) > 0 ? round(((int) $pr['completed'] / (int) $pr['total']) * 100, 1) : 100;

$statusResult = mysqli_query($connection, "SELECT status,COUNT(*) total FROM facility_maintenance_requests GROUP BY status ORDER BY total DESC");
$statusData = [];
$maxStatus = 1;
while ($statusResult && ($row = mysqli_fetch_assoc($statusResult))) {
    $statusData[] = $row;
    $maxStatus = max($maxStatus, (int) $row['total']);
}

$fail = mysqli_query($connection, "SELECT a.id,a.asset_code,a.asset_name,o.warehouse_name,COUNT(m.id) failures FROM facility_assets a JOIN outlets o ON o.id=a.outlet_id JOIN facility_maintenance_requests m ON m.asset_id=a.id AND m.request_type IN ('breakdown','corrective') AND m.created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY a.id,a.asset_code,a.asset_name,o.warehouse_name ORDER BY failures DESC LIMIT 8");

$ocResult = mysqli_query($connection, "SELECT o.warehouse_name,COALESCE(SUM(c.amount),0) total_cost FROM outlets o LEFT JOIN facility_maintenance_requests m ON m.outlet_id=o.id LEFT JOIN facility_maintenance_costs c ON c.maintenance_request_id=m.id GROUP BY o.id,o.warehouse_name ORDER BY total_cost DESC LIMIT 8");
$ocData = [];
$maxCost = 1;
while ($ocResult && ($row = mysqli_fetch_assoc($ocResult))) {
    $ocData[] = $row;
    $maxCost = max($maxCost, (float) $row['total_cost']);
}

$tech = mysqli_query($connection, "SELECT u.fullname,COUNT(DISTINCT m.id) assigned,SUM(m.status='Closed') completed,COALESCE(AVG(CASE WHEN m.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR,m.created_at,m.closed_at) END),0) avg_hours FROM facility_technicians ft JOIN users u ON u.id=ft.user_id LEFT JOIN facility_maintenance_requests m ON m.assigned_technician_id=ft.id GROUP BY ft.id,u.fullname ORDER BY completed DESC,assigned DESC LIMIT 10");

$recent = mysqli_query($connection, "SELECT m.id,m.request_number,m.subject,m.priority,m.status,m.created_at,o.warehouse_name,a.asset_code,a.asset_name FROM facility_maintenance_requests m JOIN outlets o ON o.id=m.outlet_id LEFT JOIN facility_assets a ON a.id=m.asset_id WHERE m.status NOT IN ('Closed','Cancelled','Rejected') ORDER BY FIELD(m.priority,'Critical','High','Medium','Low'),m.updated_at DESC LIMIT 6");

include '../inc/header.php';
?>
<link rel="stylesheet" href="../assets/css/facility-dashboard.css?v=1.0">

<div class="fd-page">
    <div class="fd-page-header">
        <div>
            <div class="fd-eyebrow">Facility Management</div>
            <h2>Facility Dashboard</h2>
            <p>Monitor maintenance operations, asset reliability, preventive maintenance, costs and Officer's performance.</p>
        </div>
        <div class="fd-header-actions">
            <a href="facility_reports.php" class="fd-btn fd-btn-light"><i class="fa-solid fa-chart-column"></i> Reports</a>
            <a href="support_tickets.php?tab=facility&new=1" class="fd-btn fd-btn-primary"><i class="fa-solid fa-plus"></i> Maintenance Request</a>
        </div>
    </div>

    <div class="fd-kpi-grid">
        <a href="support_tickets.php?tab=facility" class="fd-kpi-card"><div class="fd-kpi-icon requests"><i class="fa-solid fa-screwdriver-wrench"></i></div><div class="fd-kpi-content"><span>Outstanding Requests</span><strong><?= $outstanding ?></strong><small>Currently open maintenance jobs</small></div></a>
        <a href="support_tickets.php?tab=facility&status=Closed" class="fd-kpi-card"><div class="fd-kpi-icon completed"><i class="fa-solid fa-circle-check"></i></div><div class="fd-kpi-content"><span>Completed This Month</span><strong><?= $completed ?></strong><small>Approved and closed requests</small></div></a>
        <a href="preventive_maintenance.php" class="fd-kpi-card"><div class="fd-kpi-icon overdue"><i class="fa-solid fa-calendar-xmark"></i></div><div class="fd-kpi-content"><span>Overdue PM</span><strong><?= $overdue ?></strong><small>Preventive maintenance overdue</small></div></a>
        <a href="preventive_maintenance.php" class="fd-kpi-card"><div class="fd-kpi-icon compliance"><i class="fa-solid fa-calendar-check"></i></div><div class="fd-kpi-content"><span>PM Compliance</span><strong><?= $pm ?>%</strong><small>Last 90 days</small></div></a>
        <a href="facility_reports.php" class="fd-kpi-card"><div class="fd-kpi-icon cost"><i class="fa-solid fa-naira-sign"></i></div><div class="fd-kpi-content"><span>Maintenance Cost</span><strong class="fd-money">₦<?= number_format($cost,0) ?></strong><small>Current month</small></div></a>
        <a href="facility_reports.php" class="fd-kpi-card"><div class="fd-kpi-icon resolution"><i class="fa-solid fa-clock"></i></div><div class="fd-kpi-content"><span>Avg. Resolution</span><strong><?= round($avg,1) ?><em> hrs</em></strong><small>Last 90 days</small></div></a>
    </div>

    <section class="fd-panel fd-recent-panel">
        <div class="fd-panel-header"><div><h3>Active Maintenance Requests</h3><p>Priority open jobs requiring attention.</p></div><a href="support_tickets.php?tab=facility" class="fd-text-link">View all <i class="fa-solid fa-arrow-right"></i></a></div>
        <div class="fd-request-list">
        <?php if ($recent && mysqli_num_rows($recent)): while ($r = mysqli_fetch_assoc($recent)):
            $priorityClass = strtolower($r['priority']);
            $statusClass = strtolower(str_replace(' ','-',$r['status'])); ?>
            <a href="maintenance_view.php?id=<?= (int)$r['id'] ?>" class="fd-request-row">
                <div class="fd-request-id"><strong><?= facilityH($r['request_number']) ?></strong><span><?= facilityH(date('M d',strtotime($r['created_at']))) ?></span></div>
                <div class="fd-request-main"><strong><?= facilityH($r['subject']) ?></strong><span><i class="fa-solid fa-location-dot"></i><?= facilityH($r['warehouse_name']) ?><?php if (!empty($r['asset_code'])): ?><span class="fd-dot">•</span><?= facilityH($r['asset_code']) ?><?= !empty($r['asset_name']) ? ' — '.facilityH($r['asset_name']) : '' ?><?php endif; ?></span></div>
                <div class="fd-request-meta"><span class="fd-priority <?= facilityH($priorityClass) ?>"><?= facilityH($r['priority']) ?></span><span class="fd-status <?= facilityH($statusClass) ?>"><?= facilityH($r['status']) ?></span></div>
                <div class="fd-request-arrow"><i class="fa-solid fa-chevron-right"></i></div>
            </a>
        <?php endwhile; else: ?>
            <div class="fd-empty"><i class="fa-solid fa-circle-check"></i><strong>No active maintenance requests</strong><span>All current requests are completed or closed.</span></div>
        <?php endif; ?>
        </div>
    </section>

    <div class="fd-insight-grid">
        <section class="fd-panel">
            <div class="fd-panel-header"><div><h3>Requests by Status</h3><p>Current maintenance workload.</p></div></div>
            <div class="fd-status-list">
            <?php if ($statusData): foreach ($statusData as $r): $count=(int)$r['total']; $width=($count/$maxStatus)*100; $class=strtolower(str_replace(' ','-',$r['status'])); ?>
                <div class="fd-status-item"><div class="fd-status-top"><span><i class="fd-status-dot <?= facilityH($class) ?>"></i><?= facilityH($r['status']) ?></span><strong><?= $count ?></strong></div><div class="fd-progress-track"><div class="fd-progress-fill <?= facilityH($class) ?>" style="width:<?= round($width,1) ?>%"></div></div></div>
            <?php endforeach; else: ?><div class="fd-empty small">No maintenance requests yet.</div><?php endif; ?>
            </div>
        </section>

        <section class="fd-panel">
            <div class="fd-panel-header"><div><h3>Maintenance Cost by Outlet</h3><p>Highest maintenance expenditure.</p></div></div>
            <div class="fd-cost-list">
            <?php if ($ocData): foreach ($ocData as $r): $value=(float)$r['total_cost']; $width=$maxCost>0?($value/$maxCost)*100:0; ?>
                <div class="fd-cost-item"><div class="fd-cost-top"><span><?= facilityH($r['warehouse_name']) ?></span><strong>₦<?= number_format($value,0) ?></strong></div><div class="fd-progress-track"><div class="fd-progress-fill cost" style="width:<?= round($width,1) ?>%"></div></div></div>
            <?php endforeach; else: ?><div class="fd-empty small">No maintenance cost data yet.</div><?php endif; ?>
            </div>
        </section>

        <section class="fd-panel">
            <div class="fd-panel-header"><div><h3>Frequently Failing Equipment</h3><p>Breakdown and corrective jobs in 90 days.</p></div></div>
            <div class="fd-failure-list">
            <?php if ($fail && mysqli_num_rows($fail)): $rank=1; while ($r=mysqli_fetch_assoc($fail)): ?>
                <a href="facility_asset_view.php?id=<?= (int)$r['id'] ?>" class="fd-failure-item"><span class="fd-rank"><?= $rank++ ?></span><div class="fd-failure-main"><strong><?= facilityH($r['asset_code']) ?></strong><span><?= facilityH($r['asset_name']) ?></span><small><?= facilityH($r['warehouse_name']) ?></small></div><div class="fd-failure-count"><strong><?= (int)$r['failures'] ?></strong><span>failures</span></div></a>
            <?php endwhile; else: ?><div class="fd-empty small"><i class="fa-solid fa-circle-check"></i><span>No repeat failures in the last 90 days.</span></div><?php endif; ?>
            </div>
        </section>
    </div>

    <section class="fd-panel">
        <div class="fd-panel-header"><div><h3>Facility Support Productivity</h3><p>Assignment volume, completion rate and average resolution time.</p></div></div>
        <div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Officer</th><th class="number">Assigned</th><th class="number">Completed</th><th>Completion Rate</th><th class="number">Avg. Resolution</th></tr></thead><tbody>
        <?php if ($tech && mysqli_num_rows($tech)): while ($r=mysqli_fetch_assoc($tech)): $assigned=(int)$r['assigned']; $closed=(int)$r['completed']; $rate=$assigned?round(($closed/$assigned)*100,1):0; $initial=strtoupper(substr(trim($r['fullname']),0,1)); ?>
            <tr><td><div class="fd-tech"><div class="fd-tech-avatar"><?= facilityH($initial) ?></div><strong><?= facilityH($r['fullname']) ?></strong></div></td><td class="number"><?= $assigned ?></td><td class="number"><?= $closed ?></td><td><div class="fd-rate"><div class="fd-rate-track"><span style="width:<?= min(100,$rate) ?>%"></span></div><strong><?= $rate ?>%</strong></div></td><td class="number"><?= round((float)$r['avg_hours'],1) ?> hrs</td></tr>
        <?php endwhile; else: ?><tr><td colspan="5" class="fd-table-empty">No officer productivity data yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>

<?php include '../inc/footer.php'; ?>
