<?php
require '../inc/auth.php';
require '../inc/connection.php';

$pageTitle = "Manager Review";

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'staff';

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function flash($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

$userDeptId = 0;
$res = mysqli_query($connection, "SELECT department_id FROM users WHERE id = {$userId} LIMIT 1");
if ($row = mysqli_fetch_assoc($res)) {
    $userDeptId = (int) $row['department_id'];
}

if ($role === 'staff') {
    include '../inc/header.php';
    echo '<div class="panel"><h3>Access Denied</h3><p>You do not have permission to review reports.</p></div>';
    include '../inc/footer.php';
    exit;
}

/* =========================
   REVIEW ACTION
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $reportId = (int) ($_POST['report_id'] ?? 0);
    $action = $_POST['review_action'] ?? '';
    $comments = trim($_POST['comments'] ?? '');
    $rating = $_POST['rating'] !== '' ? (float) $_POST['rating'] : null;

    if (!in_array($action, ['approved', 'rejected', 'returned'])) {
        flash('error', 'Invalid review action.');
        header("Location: manager_review.php");
        exit;
    }

    $checkSql = "
    SELECT wr.*
    FROM weekly_reports wr
    LEFT JOIN users u ON u.id = wr.user_id
    WHERE wr.id = {$reportId}
";

    if ($role === 'manager') {
        $checkSql .= "
        AND u.line_manager_id = {$userId}
        AND wr.department_id = {$userDeptId}
    ";
    }

    $checkSql .= " LIMIT 1";

    $check = mysqli_query($connection, $checkSql);
    $report = mysqli_fetch_assoc($check);

    if (!$report) {
        flash('error', 'Report not found or not within your department.');
        header("Location: manager_review.php");
        exit;
    }

    if (!in_array($report['status'], ['submitted', 'reviewed'])) {
        flash('error', 'Only submitted reports can be reviewed.');
        header("Location: manager_review.php?view=" . $reportId);
        exit;
    }

    mysqli_begin_transaction($connection);

    try {
        $status = $action;

        $stmt = mysqli_prepare(
            $connection,
            "UPDATE weekly_reports
             SET status = ?,
                 manager_comment = ?,
                 manager_id = ?,
                 reviewed_at = NOW(),
                 approved_at = CASE WHEN ? = 'approved' THEN NOW() ELSE approved_at END
             WHERE id = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "ssisi",
            $status,
            $comments,
            $userId,
            $status,
            $reportId
        );

        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO manager_reviews
             (report_id, manager_id, comments, rating, action_taken)
             VALUES (?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "iisds",
            $reportId,
            $userId,
            $comments,
            $rating,
            $action
        );

        mysqli_stmt_execute($stmt);

        $notifyTitle = "Weekly Report " . ucfirst($action);
        $notifyMsg = "Your weekly report has been {$action} by your manager.";

        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO notifications (user_id, title, message, type)
             VALUES (?, ?, ?, ?)"
        );

        $type = $action === 'approved' ? 'success' : ($action === 'returned' ? 'warning' : 'error');

        mysqli_stmt_bind_param(
            $stmt,
            "isss",
            $report['user_id'],
            $notifyTitle,
            $notifyMsg,
            $type
        );

        mysqli_stmt_execute($stmt);

        mysqli_commit($connection);

        flash('success', 'Report ' . $action . ' successfully.');
        header("Location: manager_review.php");
        exit;

    } catch (Exception $e) {
        mysqli_rollback($connection);
        flash('error', 'Unable to complete review.');
        header("Location: manager_review.php?view=" . $reportId);
        exit;
    }
}

/* =========================
   VIEW REPORT DETAILS
========================= */
$viewId = (int) ($_GET['view'] ?? 0);
$selectedReport = null;
$achievements = [];
$challenges = [];
$objectives = [];
$activities = [];
$attachments = [];
$reviewHistory = [];

if ($viewId > 0) {
    $viewSql = "
        SELECT 
            wr.*,
            u.fullname,
            u.employee_id,
            d.department_name
        FROM weekly_reports wr
        LEFT JOIN users u ON u.id = wr.user_id
        LEFT JOIN departments d ON d.id = wr.department_id
        WHERE wr.id = {$viewId}
    ";

    if ($role === 'manager') {
        $viewSql .= "
        AND u.line_manager_id = {$userId}
        AND wr.department_id = {$userDeptId}
    ";
    }

    $viewSql .= " LIMIT 1";

    $res = mysqli_query($connection, $viewSql);
    $selectedReport = mysqli_fetch_assoc($res);

    if ($selectedReport) {
        $a = mysqli_query($connection, "
            SELECT ra.*, k.kpi_title
            FROM report_achievements ra
            LEFT JOIN kpis k ON k.id = ra.kpi_id
            WHERE ra.report_id = {$viewId}
        ");
        while ($row = mysqli_fetch_assoc($a))
            $achievements[] = $row;

        $c = mysqli_query($connection, "SELECT * FROM report_challenges WHERE report_id = {$viewId}");
        while ($row = mysqli_fetch_assoc($c))
            $challenges[] = $row;

        $o = mysqli_query($connection, "SELECT * FROM report_objectives WHERE report_id = {$viewId}");
        while ($row = mysqli_fetch_assoc($o))
            $objectives[] = $row;

        $act = mysqli_query(
            $connection,
            "SELECT * 
     FROM report_activities 
     WHERE report_id = {$viewId}"
        );

        while ($row = mysqli_fetch_assoc($act)) {
            $activities[] = $row;
        }

        $att = mysqli_query(
            $connection,
            "SELECT *
     FROM attachments
     WHERE report_id = {$viewId}
     ORDER BY created_at DESC"
        );

        while ($row = mysqli_fetch_assoc($att)) {
            $attachments[] = $row;
        }

        $rh = mysqli_query($connection, "
            SELECT mr.*, u.fullname AS manager_name
            FROM manager_reviews mr
            LEFT JOIN users u ON u.id = mr.manager_id
            WHERE mr.report_id = {$viewId}
            ORDER BY mr.reviewed_at DESC
        ");
        while ($row = mysqli_fetch_assoc($rh))
            $reviewHistory[] = $row;
    }
}

/* =========================
   LIST REPORTS
========================= */
$where = "WHERE wr.status IN ('submitted','reviewed','approved','rejected','returned')";

if ($role === 'manager') {
    $where .= "
        AND u.line_manager_id = {$userId}
        AND wr.department_id = {$userDeptId}
    ";
}

$reports = mysqli_query($connection, "
    SELECT 
        wr.*,
        u.fullname,
        u.employee_id,
        d.department_name
    FROM weekly_reports wr
    LEFT JOIN users u ON u.id = wr.user_id
    LEFT JOIN departments d ON d.id = wr.department_id
    {$where}
    ORDER BY 
        FIELD(wr.status, 'submitted', 'reviewed', 'returned', 'rejected', 'approved'),
        wr.created_at DESC
");

include '../inc/header.php';
?>

<style>
    .panel {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 24px;
        padding: 24px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
        margin-bottom: 24px;
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .review-header h2 {
        color: #1e293b;
        margin-bottom: 5px;
    }

    .review-header p {
        color: #64748b;
    }

    .btn {
        border: none;
        cursor: pointer;
        border-radius: 13px;
        padding: 11px 15px;
        font-weight: 600;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #9a3412, #db2777);
        color: #fff;
    }

    .btn-light {
        background: #fff7ed;
        color: #7c2d12;
        border: 1px solid #fed7aa;
    }

    .btn-success {
        background: #dcfce7;
        color: #166534;
    }

    .btn-danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .btn-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .review-table {
        width: 100%;
        min-width: 950px;
        border-collapse: collapse;
    }

    .review-table th {
        text-align: left;
        background: #fff7ed;
        color: #7c2d12;
        padding: 13px;
        font-size: 13px;
        border-bottom: 1px solid #fed7aa;
    }

    .review-table td {
        padding: 13px;
        border-bottom: 1px solid #eef2f7;
        font-size: 14px;
        color: #334155;
        vertical-align: top;
    }

    .status-badge {
        padding: 7px 11px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
        display: inline-flex;
    }

    .status-submitted {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .status-reviewed {
        background: #ede9fe;
        color: #6d28d9;
    }

    .status-approved {
        background: #dcfce7;
        color: #166534;
    }

    .status-rejected {
        background: #fee2e2;
        color: #991b1b;
    }

    .status-returned {
        background: #fef3c7;
        color: #92400e;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .info-box {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 16px;
        padding: 14px;
    }

    .info-box span {
        display: block;
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .info-box strong {
        color: #1e293b;
    }

    .section-title {
        margin: 26px 0 14px;
        color: #431407;
        font-size: 18px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 14px;
    }

    .form-group {
        margin-bottom: 14px;
    }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
    }

    .form-control {
        width: 100%;
        height: 40px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0 12px;
        outline: none;
    }

    textarea.form-control {
        height: auto;
        min-height: 95px;
        padding: 12px;
        resize: vertical;
    }

    .message {
        padding: 14px 16px;
        border-radius: 14px;
        margin-bottom: 18px;
        font-weight: 600;
        font-size: 14px;
    }

    .message.success {
        background: #dcfce7;
        color: #166534;
    }

    .message.error {
        background: #fee2e2;
        color: #991b1b;
    }

    .toast-alert {
        position: fixed;
        bottom: 22px;
        right: 24px;
        z-index: 99999;
        min-width: 280px;
        max-width: 420px;
        padding: 14px 16px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        font-weight: 600;
        font-size: 14px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        animation: toastSlide .25s ease, toastFade 5s forwards;
    }

    .toast-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .toast-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .toast-alert button {
        border: none;
        background: transparent;
        font-size: 20px;
        font-weight: 700;
        cursor: pointer;
        color: inherit;
    }

    @keyframes toastSlide {
        from {
            opacity: 0;
            transform: translateX(18px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes toastFade {

        0%,
        75% {
            opacity: 1;
            transform: translateX(0);
        }

        100% {
            opacity: 0;
            transform: translateX(18px);
            visibility: hidden;
        }
    }

    .row-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    @media(max-width:900px) {
        .panel {
            padding: 18px;
            border-radius: 18px;
        }

        .review-header {
            align-items: stretch;
        }

        .review-header .btn {
            width: 100%;
        }
    }

    .uploaded-files {
        margin-top: 12px;
        display: grid;
        gap: 8px;
    }

    .uploaded-file {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 13px;
    }

    .uploaded-file span {
        color: #334155;
        font-weight: 600;
    }

    .uploaded-file a {
        color: #db2777;
        font-weight: 700;
        text-decoration: none;
    }
</style>

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div id="toastAlert" class="toast-alert toast-<?= h($_SESSION['flash_type']) ?>">
        <span><?= h($_SESSION['flash_text']) ?></span>
        <button type=" button" onclick="closeToast()">×</button>
    </div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="panel" style="margin-bottom: 10px">
    <div>
        <h3>Manager Review & Approval</h3>
        <p>Review submitted weekly reports, add comments, approve, reject or return for correction.</p>
    </div>

    <?php if ($selectedReport): ?>
        <a href="manager_review.php" class="btn btn-light">Back to Reviews</a>
    <?php endif; ?>
</div>

<?php if ($selectedReport): ?>

    <div class="panel">

        <h3 style="margin-bottom:18px;">Report Details</h3>

        <div class="info-grid">
            <div class="info-box">
                <span>Staff</span>
                <strong><?= h($selectedReport['fullname']) ?></strong>
            </div>

            <div class="info-box">
                <span>Employee ID</span>
                <strong><?= h($selectedReport['employee_id']) ?></strong>
            </div>

            <div class="info-box">
                <span>Department</span>
                <strong><?= h($selectedReport['department_name']) ?></strong>
            </div>

            <div class="info-box">
                <span>Week Covered</span>
                <strong>
                    <?= h(date('M d, Y', strtotime($selectedReport['week_start']))) ?>
                    -
                    <?= h(date('M d, Y', strtotime($selectedReport['week_end']))) ?>
                </strong>
            </div>

            <div class="info-box">
                <span>Status</span>
                <strong>
                    <span class="status-badge status-<?= h($selectedReport['status']) ?>">
                        <?= h($selectedReport['status']) ?>
                    </span>
                </strong>
            </div>

            <div class="info-box">
                <span>KPI Score</span>
                <strong><?= number_format((float) $selectedReport['total_kpi_score'], 2) ?>%</strong>
            </div>
        </div>

        <h4 class="section-title">A. Achievements</h4>

        <div class="table-wrap">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>KPI</th>
                        <th>Performance Area</th>
                        <th>Objective</th>
                        <th>Weekly Target</th>
                        <th>Achieved</th>
                        <th>%</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($achievements): ?>
                        <?php $sn = 1;
                        foreach ($achievements as $a): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <?= h($a['kpi_title'] ?? 'N/A') ?>
                                </td>
                                <td><?= h($a['performance_area']) ?></td>
                                <td><?= h($a['objective']) ?></td>
                                <td><?= number_format((float) $a['weekly_objective'], 2) ?></td>
                                <td><?= number_format((float) $a['weekly_achieved'], 2) ?></td>
                                <td><?= number_format((float) $a['weekly_percentage'], 2) ?>%</td>
                                <td><?= h($a['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;">No achievements entered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h4 class="section-title">B. Challenges</h4>

        <div class="table-wrap">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Challenge</th>
                        <th>Recommended Solution</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($challenges): ?>
                        <?php $sn = 1;
                        foreach ($challenges as $c): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <?= h($c['challenge']) ?>
                                </td>
                                <td><?= h($c['recommended_solution']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center;">No challenges entered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h4 class="section-title">C. Next Week Objectives</h4>

        <div class="table-wrap">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Objective</th>
                        <th>Target Date</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($objectives): ?>
                        <?php $sn = 1;
                        foreach ($objectives as $o): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <?= h($o['objective']) ?>
                                </td>
                                <td><?= h($o['target_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center;">No objectives entered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h4 class="section-title">D. Planned Activities</h4>

        <div class="table-wrap">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Activity</th>
                        <th>Activity Date</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($activities): ?>
                        <?php $sn = 1;
                        foreach ($activities as $act): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <?= h($act['activity']) ?>
                                </td>
                                <td><?= h($act['activity_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center;">No activities entered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h4 class="section-title">E. Supporting Documents</h4>

        <?php if (!empty($attachments)): ?>
            <div class="uploaded-files">
                <?php foreach ($attachments as $file): ?>
                    <div class="uploaded-file">
                        <span>
                            <?= h($file['file_name']) ?>
                        </span>

                        <a href="../<?= h($file['file_path']) ?>" target="_blank">
                            View Document
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="color:#94a3b8; font-size:13px;">
                No supporting documents uploaded.
            </div>
        <?php endif; ?>

    </div>

    <?php if ($selectedReport && in_array($selectedReport['status'], ['submitted', 'reviewed'])): ?>

        <div class="panel">
            <h3 style="margin-bottom:18px;">Manager Decision</h3>

            <form method="POST">
                <input type="hidden" name="report_id" value="<?= (int) $selectedReport['id'] ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Rating</label>
                        <input type="number" name="rating" step="0.01" min="0" max="5" class="form-control"
                            placeholder="Example: rate from 1 to 5 with 5 being the best">
                    </div>
                </div>

                <div class="form-group">
                    <label>Manager Comment</label>
                    <textarea name="comments" class="form-control" required placeholder="Enter review comments..."></textarea>
                </div>

                <div class="row-actions">
                    <button type="submit" name="review_action" value="approved" class="btn btn-success">
                        Approve
                    </button>

                    <button type="submit" name="review_action" value="returned" class="btn btn-warning">
                        Return for Correction
                    </button>

                    <button type="submit" name="review_action" value="rejected" class="btn btn-danger">
                        Reject
                    </button>
                </div>
            </form>
        </div>

    <?php endif; ?>

    <?php if (!empty($reviewHistory)): ?>

        <div class="panel">
            <h3 style="margin-bottom:18px;">Review History</h3>

            <div class="table-wrap">
                <table class="review-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Manager</th>
                            <th>Action</th>
                            <th>Rating</th>
                            <th>Comments</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php $sn = 1;
                        foreach ($reviewHistory as $rh): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <?= h($rh['manager_name']) ?>
                                </td>
                                <td><?= h($rh['action_taken']) ?></td>
                                <td><?= $rh['rating'] !== null ? number_format((float) $rh['rating'], 2) : 'N/A' ?></td>
                                <td><?= h($rh['comments']) ?></td>
                                <td><?= h(date('M d, Y h:i A', strtotime($rh['reviewed_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

<?php else: ?>

    <div class="panel">

        <h3 style="margin-bottom:18px;">Reports Awaiting / Reviewed</h3>

        <div class="table-wrap">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Staff</th>
                        <th>Department</th>
                        <th>Week Covered</th>
                        <th>Status</th>
                        <th>KPI Score</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($reports && mysqli_num_rows($reports) > 0): ?>
                        <?php $sn = 1;
                        while ($r = mysqli_fetch_assoc($reports)): ?>
                            <tr>
                                <td><?= $sn++ ?></td>
            <td>
                <strong>
                    <?= h($r['fullname']) ?></strong><br>
                                    <small><?= h($r['employee_id']) ?></small>
                                </td>
                                <td><?= h($r['department_name']) ?></td>
                                <td>
                                    <?= h(date('M d, Y', strtotime($r['week_start']))) ?>
                                    -
                                    <?= h(date('M d, Y', strtotime($r['week_end']))) ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= h($r['status']) ?>">
                                        <?= h($r['status']) ?>
                                    </span>
                                </td>
                                <td><?= number_format((float) $r['total_kpi_score'], 2) ?>%</td>
                                <td>
                                    <?= $r['submission_date'] ? h(date('M d, Y', strtotime($r['submission_date']))) : 'Not submitted' ?>
                                </td>
                                <td>
                                    <a href="manager_review.php?view=<?= (int) $r['id'] ?>" class="btn btn-light">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">
                                No reports available for review.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

<?php endif; ?>

<script>
    function closeToast() {
        const toast = document.getElementById('toastAlert');
        if (toast) {
            toast.style.display = 'none';
        }
    }

    setTimeout(function () {
        closeToast();
    }, 5200);
</script>

<?php include '../inc/footer.php'; ?>