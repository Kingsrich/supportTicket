<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';

$pageTitle = "Weekly IT Support Report";

$role = $_SESSION['role'] ?? 'staff';
if (!in_array($role, ['officer', 'admin'])) {
    header("Location: it_tickets.php");
    exit;
}

/* =========================
   HELPERS
========================= */
function rpt_rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    $out = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $out;
}

function rpt_metrics(mysqli $db, string $from, string $to): array
{
    $a = rpt_rows(
        $db,
        "SELECT COUNT(*) AS raised,
                COALESCE(SUM(priority = 'Critical'), 0) AS critical,
                COALESCE(SUM(assigned_to IS NULL), 0) AS unassigned,
                AVG(CASE WHEN assigned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, assigned_at) END) AS avg_assign
         FROM tickets WHERE created_at >= ? AND created_at < ?",
        'ss',
        [$from, $to]
    )[0];

    $b = rpt_rows(
        $db,
        "SELECT COUNT(*) AS resolved,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(resolved_at, closed_at))) AS avg_resolve
         FROM tickets
         WHERE status IN ('Resolved','Closed')
           AND COALESCE(resolved_at, closed_at) >= ? AND COALESCE(resolved_at, closed_at) < ?",
        'ss',
        [$from, $to]
    )[0];

    return [
        'raised' => (int) $a['raised'],
        'critical' => (int) $a['critical'],
        'unassigned' => (int) $a['unassigned'],
        'avg_assign_h' => $a['avg_assign'] !== null ? round($a['avg_assign'] / 60, 1) : null,
        'resolved' => (int) $b['resolved'],
        'avg_resolve_h' => $b['avg_resolve'] !== null ? round($b['avg_resolve'] / 60, 1) : null,
    ];
}

function rpt_delta($cur, $prev, bool $lowerBetter = false): string
{
    if ($cur === null || $prev === null) {
        return '';
    }
    $d = round($cur - $prev, 1);
    if ($d == 0) {
        return '<small class="rpt-delta">same as last week</small>';
    }
    $good = $lowerBetter ? $d < 0 : $d > 0;
    return '<small class="rpt-delta ' . ($good ? 'good' : 'bad') . '">'
        . ($d > 0 ? '▲ ' : '▼ ') . abs($d) . ' vs last week</small>';
}

function rpt_hours($h): string
{
    return $h === null ? '—' : $h . 'h';
}

function rpt_csv_safe($v)
{
    // Stop spreadsheet apps treating text as a formula
    return (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
}

/* =========================
   WEEK (Monday – Sunday)
========================= */
$input = $_GET['week'] ?? '';
$ts = ($input !== '' && strtotime($input)) ? strtotime($input) : time();
$weekStart = date('Y-m-d', strtotime('monday this week', $ts));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));

$from = $weekStart . ' 00:00:00';
$to = date('Y-m-d', strtotime($weekStart . ' +7 days')) . ' 00:00:00';
$prevFrom = $prevWeek . ' 00:00:00';

/* =========================
   DATA
========================= */
$cur = rpt_metrics($connection, $from, $to);
$prev = rpt_metrics($connection, $prevFrom, $from);

$backlogNow = (int) rpt_rows($connection, "SELECT COUNT(*) AS c FROM tickets WHERE status NOT IN ('Resolved','Closed','Cancelled')")[0]['c'];

// Per-officer figures, keyed by user id
$doneAt = "COALESCE(t.resolved_at, t.closed_at)";
$perOfficer = [];
$rows = rpt_rows(
    $connection,
    "SELECT t.assigned_to AS uid,
            COALESCE(SUM(t.created_at >= ? AND t.created_at < ?), 0) AS raised,
            COALESCE(SUM(t.status IN ('Resolved','Closed') AND $doneAt >= ? AND $doneAt < ?), 0) AS resolved,
            COALESCE(SUM(t.status NOT IN ('Resolved','Closed','Cancelled')), 0) AS open_now,
            AVG(CASE WHEN t.status IN ('Resolved','Closed') AND $doneAt >= ? AND $doneAt < ?
                     THEN TIMESTAMPDIFF(MINUTE, t.created_at, $doneAt) END) AS avg_min
     FROM tickets t
     WHERE t.assigned_to IS NOT NULL
     GROUP BY t.assigned_to",
    'ssssss',
    [$from, $to, $from, $to, $from, $to]
);
foreach ($rows as $r) {
    $perOfficer[(int) $r['uid']] = [
        'raised' => (int) $r['raised'],
        'resolved' => (int) $r['resolved'],
        'open_now' => (int) $r['open_now'],
        'avg_h' => $r['avg_min'] !== null ? round($r['avg_min'] / 60, 1) : null,
        'actions' => 0,
        'comments' => 0,
    ];
}
foreach (rpt_rows($connection, "SELECT user_id AS uid, COUNT(*) AS c FROM ticket_activity_log WHERE created_at >= ? AND created_at < ? GROUP BY user_id", 'ss', [$from, $to]) as $r) {
    if (isset($perOfficer[(int) $r['uid']])) {
        $perOfficer[(int) $r['uid']]['actions'] = (int) $r['c'];
    }
}
foreach (rpt_rows($connection, "SELECT user_id AS uid, COUNT(*) AS c FROM ticket_comments WHERE created_at >= ? AND created_at < ? GROUP BY user_id", 'ss', [$from, $to]) as $r) {
    if (isset($perOfficer[(int) $r['uid']])) {
        $perOfficer[(int) $r['uid']]['comments'] = (int) $r['c'];
    }
}

// Officers in the rotation who had no tickets still show up with zeros
$officerNames = [];
$nameRows = rpt_rows($connection, "SELECT u.id, u.fullname FROM users u WHERE u.id IN (SELECT user_id FROM it_officers) OR u.id IN (SELECT DISTINCT assigned_to FROM tickets WHERE assigned_to IS NOT NULL)");
foreach ($nameRows as $r) {
    $officerNames[(int) $r['id']] = $r['fullname'];
}
foreach (array_keys($officerNames) as $uid) {
    $perOfficer[$uid] = $perOfficer[$uid] ?? ['raised' => 0, 'resolved' => 0, 'open_now' => 0, 'avg_h' => null, 'actions' => 0, 'comments' => 0];
}
uasort($perOfficer, fn($a, $b) => $b['resolved'] <=> $a['resolved']);
$maxResolved = max(1, ...array_column($perOfficer ?: [['resolved' => 1]], 'resolved'));

// Tasks completed this week, grouped by officer
$completed = rpt_rows(
    $connection,
    "SELECT t.ticket_number, t.subject, t.priority, t.status, t.category,
            ru.fullname AS requester, a.fullname AS officer,
            $doneAt AS done_at,
            TIMESTAMPDIFF(MINUTE, t.created_at, $doneAt) AS mins
     FROM tickets t
     LEFT JOIN users ru ON ru.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.status IN ('Resolved','Closed') AND $doneAt >= ? AND $doneAt < ?
     ORDER BY a.fullname ASC, done_at ASC",
    'ss',
    [$from, $to]
);

// Breakdowns for tickets raised this week
$byCategory = rpt_rows($connection, "SELECT category AS label, COUNT(*) AS c FROM tickets WHERE created_at >= ? AND created_at < ? GROUP BY category ORDER BY c DESC", 'ss', [$from, $to]);
$byPriority = rpt_rows($connection, "SELECT priority AS label, COUNT(*) AS c FROM tickets WHERE created_at >= ? AND created_at < ? GROUP BY priority ORDER BY FIELD(priority,'Critical','High','Medium','Low')", 'ss', [$from, $to]);

// Backlog right now, oldest critical first
$backlog = rpt_rows(
    $connection,
    "SELECT t.ticket_number, t.subject, t.priority, t.status, a.fullname AS officer,
            DATEDIFF(NOW(), t.created_at) AS age_days
     FROM tickets t
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.status NOT IN ('Resolved','Closed','Cancelled')
     ORDER BY FIELD(t.priority,'Critical','High','Medium','Low'), t.created_at ASC
     LIMIT 30"
);

/* =========================
   CSV EXPORT
========================= */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="it_weekly_report_' . $weekStart . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
    $put = function (array $r) use ($out) {
        fputcsv($out, array_map('rpt_csv_safe', $r), ',', '"', '');
    };

    $put(['Weekly IT Support Report', $weekStart . ' to ' . $weekEnd]);
    $put([]);
    $put(['SUMMARY']);
    $put(['Raised', $cur['raised']]);
    $put(['Resolved', $cur['resolved']]);
    $put(['Critical raised', $cur['critical']]);
    $put(['Unassigned (raised this week)', $cur['unassigned']]);
    $put(['Avg hours to assign', $cur['avg_assign_h'] ?? '']);
    $put(['Avg hours to resolve', $cur['avg_resolve_h'] ?? '']);
    $put(['Open backlog now', $backlogNow]);
    $put([]);
    $put(['BY OFFICER']);
    $put(['Officer', 'Assigned this week', 'Resolved this week', 'Open now', 'Avg hours to resolve', 'Actions logged', 'Comments']);
    foreach ($perOfficer as $uid => $p) {
        $put([$officerNames[$uid] ?? ('User #' . $uid), $p['raised'], $p['resolved'], $p['open_now'], $p['avg_h'] ?? '', $p['actions'], $p['comments']]);
    }
    $put([]);
    $put(['TASKS COMPLETED']);
    $put(['Ticket', 'Subject', 'Category', 'Priority', 'Requester', 'Done by', 'Completed at', 'Minutes to resolve']);
    foreach ($completed as $c) {
        $put([$c['ticket_number'], $c['subject'], $c['category'], $c['priority'], $c['requester'] ?? '', $c['officer'] ?? 'Unassigned', $c['done_at'], $c['mins']]);
    }
    $put([]);
    $put(['OUTSTANDING BACKLOG']);
    $put(['Ticket', 'Subject', 'Priority', 'Status', 'Assigned to', 'Age (days)']);
    foreach ($backlog as $b) {
        $put([$b['ticket_number'], $b['subject'], $b['priority'], $b['status'], $b['officer'] ?? 'Unassigned', $b['age_days']]);
    }
    fclose($out);
    exit;
}

$netChange = $cur['raised'] - $cur['resolved'];
$weekLabel = date('M d', strtotime($weekStart)) . ' – ' . date('M d, Y', strtotime($weekEnd));
$isCurrentWeek = $weekStart === date('Y-m-d', strtotime('monday this week'));

include '../inc/header.php';
?>
<link rel="stylesheet" href="../assets/css/reports.css">
<link rel="stylesheet" href="../assets/css/it-reports.css?v=1.0">

<div class="panel report-controls no-print">
    <div class="report-controls-row">
        <div class="inline-form">
            <a class="btn btn-light small-btn" href="?week=<?= h($prevWeek) ?>">&larr; Previous</a>
            <span class="rpt-week"><?= h($weekLabel) ?></span>
            <?php if (!$isCurrentWeek): ?>
                <a class="btn btn-light small-btn" href="?week=<?= h($nextWeek) ?>">Next &rarr;</a>
                <a class="btn btn-light small-btn" href="?">This week</a>
            <?php endif; ?>
            <form method="GET" class="inline-form">
                <input type="date" name="week" class="form-control" value="<?= h($weekStart) ?>" onchange="this.form.submit()">
            </form>
        </div>
        <div class="inline-form">
            <a class="btn btn-success small-btn" href="?week=<?= h($weekStart) ?>&export=csv">Export CSV (Excel)</a>
            <button onclick="window.print()" class="btn btn-light small-btn">Print / Save as PDF</button>
            <?php if ($role === 'admin'): ?>
                <a class="btn btn-primary small-btn" href="it_rotation.php">Auto-assign rotation</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel report-sheet">
    <div class="report-header">
        <div>
            <h2 class="page-heading">Weekly IT Support Report</h2>
            <p class="page-subheading"><?= h($weekLabel) ?> &middot; Prepared by ICT Department</p>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Week at a glance</div>
        <div class="kpi-grid rpt-kpi-grid">
            <div class="kpi-card">
                <h3><?= $cur['raised'] ?></h3><span>Raised</span>
                <?= rpt_delta($cur['raised'], $prev['raised']) ?>
            </div>
            <div class="kpi-card">
                <h3><?= $cur['resolved'] ?></h3><span>Resolved</span>
                <?= rpt_delta($cur['resolved'], $prev['resolved']) ?>
            </div>
            <div class="kpi-card">
                <h3><?= $netChange > 0 ? '+' : '' ?><?= $netChange ?></h3><span>Net backlog change</span>
                <small class="rpt-delta"><?= $netChange > 0 ? 'backlog grew' : ($netChange < 0 ? 'backlog shrank' : 'flat') ?></small>
            </div>
            <div class="kpi-card">
                <h3><?= $backlogNow ?></h3><span>Open now</span>
                <small class="rpt-delta">live count</small>
            </div>
            <div class="kpi-card">
                <h3><?= $cur['critical'] ?></h3><span>Critical raised</span>
                <?= rpt_delta($cur['critical'], $prev['critical'], true) ?>
            </div>
            <div class="kpi-card">
                <h3><?= rpt_hours($cur['avg_assign_h']) ?></h3><span>Avg time to assign</span>
                <?= rpt_delta($cur['avg_assign_h'], $prev['avg_assign_h'], true) ?>
            </div>
            <div class="kpi-card">
                <h3><?= rpt_hours($cur['avg_resolve_h']) ?></h3><span>Avg time to resolve</span>
                <?= rpt_delta($cur['avg_resolve_h'], $prev['avg_resolve_h'], true) ?>
            </div>
        </div>
        <?php if ($cur['unassigned'] > 0): ?>
            <p class="rpt-note"><?= $cur['unassigned'] ?> ticket(s) raised this week have no officer yet.</p>
        <?php endif; ?>
    </div>

    <div class="form-section">
        <div class="form-section-title">Who did what</div>
        <div class="table-wrap">
            <table class="report-print-table">
                <thead>
                    <tr>
                        <th>Officer</th>
                        <th class="rpt-num">Assigned</th>
                        <th class="rpt-num">Resolved</th>
                        <th class="rpt-num">Open now</th>
                        <th class="rpt-num">Avg resolve</th>
                        <th class="rpt-num">Actions</th>
                        <th class="rpt-num">Comments</th>
                        <th style="width:18%">Share of resolved</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($perOfficer): ?>
                        <?php foreach ($perOfficer as $uid => $p): ?>
                            <tr>
                                <td><strong><?= h($officerNames[$uid] ?? ('User #' . $uid)) ?></strong></td>
                                <td class="rpt-num"><?= $p['raised'] ?></td>
                                <td class="rpt-num"><?= $p['resolved'] ?></td>
                                <td class="rpt-num"><?= $p['open_now'] ?></td>
                                <td class="rpt-num"><?= rpt_hours($p['avg_h']) ?></td>
                                <td class="rpt-num"><?= $p['actions'] ?></td>
                                <td class="rpt-num"><?= $p['comments'] ?></td>
                                <td>
                                    <?php if ($cur['resolved'] > 0): ?>
                                        <div class="breakdown-bar-track" title="<?= round($p['resolved'] / max(1, $cur['resolved']) * 100) ?>% of this week's resolved tickets"><div class="breakdown-bar" style="width: <?= round($p['resolved'] / $maxResolved * 100) ?>%"></div></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="empty-row">No officers found. Run the migration to set up the officer list.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="rpt-note">“Assigned” counts tickets raised this week that belong to the officer; “Resolved” counts tickets they closed out this week, whenever they were raised. Actions and comments come from the ticket activity log.</p>
    </div>

    <div class="form-section">
        <div class="form-section-title">Tasks completed this week</div>
        <div class="table-wrap">
            <table class="report-print-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Requester</th>
                        <th>Priority</th>
                        <th>Completed</th>
                        <th class="rpt-num">Time to resolve</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($completed): ?>
                        <?php $lastOfficer = null; ?>
                        <?php foreach ($completed as $c): ?>
                            <?php $who = $c['officer'] ?? 'Unassigned'; ?>
                            <?php if ($who !== $lastOfficer): ?>
                                <tr class="rpt-officer-group"><td colspan="6"><?= h($who) ?></td></tr>
                                <?php $lastOfficer = $who; ?>
                            <?php endif; ?>
                            <tr>
                                <td><strong><?= h($c['ticket_number']) ?></strong></td>
                                <td><?= h($c['subject']) ?></td>
                                <td><?= h($c['requester'] ?? '—') ?></td>
                                <td><span class="badge <?= priorityBadgeClass($c['priority']) ?>"><?= h($c['priority']) ?></span></td>
                                <td><?= h(date('D d M, g:i A', strtotime($c['done_at']))) ?></td>
                                <td class="rpt-num"><?= h(formatDuration($c['mins'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty-row">No tickets were resolved this week.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="report-row">
        <div class="form-section">
            <div class="form-section-title">Raised by category</div>
            <div class="breakdown-list">
                <?php if ($byCategory): ?>
                    <?php $maxCat = max(array_column($byCategory, 'c')); ?>
                    <?php foreach ($byCategory as $r): ?>
                        <div class="breakdown-row">
                            <span class="breakdown-label"><?= h($r['label']) ?></span>
                            <div class="breakdown-bar-track"><div class="breakdown-bar" style="width: <?= round($r['c'] / $maxCat * 100) ?>%"></div></div>
                            <span class="breakdown-count"><?= (int) $r['c'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-row">No tickets raised this week.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-section">
            <div class="form-section-title">Raised by priority</div>
            <div class="breakdown-list">
                <?php if ($byPriority): ?>
                    <?php foreach ($byPriority as $r): ?>
                        <div class="breakdown-row">
                            <span class="badge <?= priorityBadgeClass($r['label']) ?>"><?= h($r['label']) ?></span>
                            <div class="breakdown-bar-track"><div class="breakdown-bar" style="width: <?= $cur['raised'] > 0 ? round($r['c'] / $cur['raised'] * 100) : 0 ?>%"></div></div>
                            <span class="breakdown-count"><?= (int) $r['c'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-row">No tickets raised this week.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Outstanding tickets (live)</div>
        <div class="table-wrap">
            <table class="report-print-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Assigned to</th>
                        <th class="rpt-num">Age</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($backlog): ?>
                        <?php foreach ($backlog as $b): ?>
                            <tr>
                                <td><strong><?= h($b['ticket_number']) ?></strong></td>
                                <td><?= h($b['subject']) ?></td>
                                <td><span class="badge <?= priorityBadgeClass($b['priority']) ?>"><?= h($b['priority']) ?></span></td>
                                <td><span class="badge <?= statusBadgeClass($b['status']) ?>"><?= h($b['status']) ?></span></td>
                                <td><?= h($b['officer'] ?? 'Unassigned') ?></td>
                                <td class="rpt-num"><?= (int) $b['age_days'] ?>d</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty-row">No outstanding tickets. The backlog is clear.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($backlogNow > count($backlog)): ?>
            <p class="rpt-note">Showing the 30 most urgent of <?= $backlogNow ?> open tickets.</p>
        <?php endif; ?>
    </div>

    <div class="report-footer">
        <span>Prepared by ICT Department</span>
        <span>Weekly IT Support Performance Report</span>
        <span>Confidential – Management Use Only</span>
    </div>
</div>

<?php include '../inc/footer.php'; ?>