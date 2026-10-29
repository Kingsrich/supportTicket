<?php
require '../inc/auth.php';
require '../inc/connection.php';

$pageTitle = "Weekly Reports";

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'staff';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function responseMessage($type, $text) {
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

function getUserDepartmentId($connection, $userId) {
    $res = mysqli_query($connection, "SELECT department_id FROM users WHERE id = {$userId} LIMIT 1");
    $row = mysqli_fetch_assoc($res);
    return (int)($row['department_id'] ?? 0);
}

$departmentId = getUserDepartmentId($connection, $userId);

/* =========================
   SAVE / SUBMIT REPORT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'save_report' || $action === 'submit_report') {

        $reportId = (int)($_POST['report_id'] ?? 0);
        $weekStart = $_POST['week_start'] ?? '';
        $weekEnd = $_POST['week_end'] ?? '';
        $periodCovered = trim($_POST['period_covered'] ?? '');
        $newStatus = $action === 'submit_report' ? 'submitted' : 'draft';
        $submissionDate = $action === 'submit_report' ? date('Y-m-d H:i:s') : null;

        if ($weekStart === '' || $weekEnd === '') {
            responseMessage('error', 'Please select week start and week end.');
            header("Location: weekly_reports.php");
            exit;
        }

        if ($reportId > 0) {
            $check = mysqli_query($connection, "SELECT * FROM weekly_reports WHERE id = {$reportId} LIMIT 1");
            $existing = mysqli_fetch_assoc($check);

            if (!$existing || ($role === 'staff' && (int)$existing['user_id'] !== $userId)) {
                responseMessage('error', 'Invalid report selected.');
                header("Location: weekly_reports.php");
                exit;
            }

            if (!in_array($existing['status'], ['draft', 'rejected'])) {
                responseMessage('error', 'This report can no longer be edited.');
                header("Location: weekly_reports.php");
                exit;
            }

            $stmt = mysqli_prepare(
                $connection,
                "UPDATE weekly_reports 
                 SET week_start=?, week_end=?, period_covered=?, status=?, submission_date=?, updated_at=NOW()
                 WHERE id=?"
            );
            mysqli_stmt_bind_param($stmt, "sssssi", $weekStart, $weekEnd, $periodCovered, $newStatus, $submissionDate, $reportId);
            mysqli_stmt_execute($stmt);

        } else {
            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO weekly_reports 
                (user_id, department_id, week_start, week_end, period_covered, status, submission_date)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iisssss", $userId, $departmentId, $weekStart, $weekEnd, $periodCovered, $newStatus, $submissionDate);
            mysqli_stmt_execute($stmt);
            $reportId = mysqli_insert_id($connection);
        }

        mysqli_query($connection, "DELETE FROM report_achievements WHERE report_id = {$reportId}");
        mysqli_query($connection, "DELETE FROM report_challenges WHERE report_id = {$reportId}");
        mysqli_query($connection, "DELETE FROM report_objectives WHERE report_id = {$reportId}");
        mysqli_query($connection, "DELETE FROM report_activities WHERE report_id = {$reportId}");

        $totalScore = 0;

        $kpiIds = $_POST['kpi_id'] ?? [];
        $areas = $_POST['performance_area'] ?? [];
        $objectives = $_POST['objective'] ?? [];
        $weeklyObjectives = $_POST['weekly_objective'] ?? [];
        $weeklyAchieved = $_POST['weekly_achieved'] ?? [];
        $mtdObjectives = [];
        $mtdAchieved = [];
        $remarks = $_POST['remarks'] ?? [];

        for ($i = 0; $i < count($areas); $i++) {
            $kpiId = (int)($kpiIds[$i] ?? 0);
            $area = trim($areas[$i] ?? '');
            $objective = trim($objectives[$i] ?? '');
            $wObj = (float)($weeklyObjectives[$i] ?? 0);
            $wAch = (float)($weeklyAchieved[$i] ?? 0);
            $mObj = 0;
            $mAch = 0;
            $remark = trim($remarks[$i] ?? '');

            if ($area === '' && $objective === '') continue;

            $wPercent = $wObj > 0 ? min(($wAch / $wObj) * 100, 100) : 0;
            $mPercent = 0;
            $totalScore += $wPercent;

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO report_achievements
                (report_id, kpi_id, performance_area, objective, weekly_objective, weekly_achieved, weekly_percentage, mtd_objective, mtd_achieved, mtd_percentage, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "iissdddddds",
                $reportId,
                $kpiId,
                $area,
                $objective,
                $wObj,
                $wAch,
                $wPercent,
                $mObj,
                $mAch,
                $mPercent,
                $remark
            );

            mysqli_stmt_execute($stmt);
        }

        $averageScore = count(array_filter($areas)) > 0 ? $totalScore / count(array_filter($areas)) : 0;

        mysqli_query(
            $connection,
            "UPDATE weekly_reports SET total_kpi_score = " . (float)$averageScore . " WHERE id = {$reportId}"
        );

        if (!empty($_FILES['report_files']['name'][0])) {

    $uploadDir = __DIR__ . '/../uploads/reports/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($_FILES['report_files']['name'] as $index => $originalName) {

        if ($_FILES['report_files']['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName = $_FILES['report_files']['tmp_name'][$index];
        $fileSize = (int) $_FILES['report_files']['size'][$index];
        $fileType = $_FILES['report_files']['type'][$index];

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed)) {
            continue;
        }

        $safeName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $newName = 'report_' . $reportId . '_' . time() . '_' . $index . '_' . $safeName . '.' . $ext;

        $targetPath = $uploadDir . $newName;
        $dbPath = 'uploads/reports/' . $newName;

        if (move_uploaded_file($tmpName, $targetPath)) {

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO attachments 
                 (report_id, file_name, file_path, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "isssi",
                $reportId,
                $originalName,
                $dbPath,
                $fileType,
                $userId
            );

            mysqli_stmt_execute($stmt);
        }
    }
}

        $challenges = $_POST['challenge'] ?? [];
        $solutions = $_POST['recommended_solution'] ?? [];

        for ($i = 0; $i < count($challenges); $i++) {
            $challenge = trim($challenges[$i] ?? '');
            $solution = trim($solutions[$i] ?? '');

            if ($challenge === '') continue;

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO report_challenges (report_id, challenge, recommended_solution)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iss", $reportId, $challenge, $solution);
            mysqli_stmt_execute($stmt);
        }

        $nextObjectives = $_POST['next_objective'] ?? [];
        $targetDates = $_POST['target_date'] ?? [];

        for ($i = 0; $i < count($nextObjectives); $i++) {
            $objective = trim($nextObjectives[$i] ?? '');
            $targetDate = $targetDates[$i] ?? null;

            if ($objective === '') continue;

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO report_objectives (report_id, objective, target_date)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iss", $reportId, $objective, $targetDate);
            mysqli_stmt_execute($stmt);
        }

        $activities = $_POST['activity'] ?? [];
        $activityDates = $_POST['activity_date'] ?? [];

        for ($i = 0; $i < count($activities); $i++) {
            $activity = trim($activities[$i] ?? '');
            $activityDate = $activityDates[$i] ?? null;

            if ($activity === '') continue;

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO report_activities (report_id, activity, activity_date)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iss", $reportId, $activity, $activityDate);
            mysqli_stmt_execute($stmt);
        }

        responseMessage('success', $action === 'submit_report' ? 'Weekly report submitted successfully.' : 'Weekly report saved as draft.');
        header("Location: weekly_reports.php");
        exit;
    }

    if ($action === 'delete_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);

        $check = mysqli_query($connection, "SELECT * FROM weekly_reports WHERE id = {$reportId} LIMIT 1");
        $report = mysqli_fetch_assoc($check);

        if ($report && $report['status'] === 'draft' && (int)$report['user_id'] === $userId) {
            mysqli_query($connection, "DELETE FROM report_achievements WHERE report_id = {$reportId}");
            mysqli_query($connection, "DELETE FROM report_challenges WHERE report_id = {$reportId}");
            mysqli_query($connection, "DELETE FROM report_objectives WHERE report_id = {$reportId}");
            mysqli_query($connection, "DELETE FROM report_activities WHERE report_id = {$reportId}");
            mysqli_query($connection, "DELETE FROM weekly_reports WHERE id = {$reportId}");

            responseMessage('success', 'Draft report deleted.');
        } else {
            responseMessage('error', 'Only your draft reports can be deleted.');
        }

        header("Location: weekly_reports.php");
        exit;
    }
}

/* =========================
   LOAD REPORT FOR EDIT
========================= */
$editId = (int)($_GET['edit'] ?? 0);
$editing = false;
$report = null;
$achievements = [];
$challenges = [];
$objectives = [];
$activities = [];
$attachments = [];

if ($editId > 0) {
    $res = mysqli_query($connection, "SELECT * FROM weekly_reports WHERE id = {$editId} LIMIT 1");
    $report = mysqli_fetch_assoc($res);

    if ($report && ($role !== 'staff' || (int)$report['user_id'] === $userId)) {
        $editing = true;
                $attRes = mysqli_query(
            $connection,
            "SELECT *
             FROM attachments
             WHERE report_id = {$editId}
             ORDER BY created_at DESC"
        );

        while ($r = mysqli_fetch_assoc($attRes)) {
            $attachments[] = $r;
        }

        $aRes = mysqli_query($connection, "SELECT * FROM report_achievements WHERE report_id = {$editId}");
        while ($r = mysqli_fetch_assoc($aRes)) $achievements[] = $r;

        $cRes = mysqli_query($connection, "SELECT * FROM report_challenges WHERE report_id = {$editId}");
        while ($r = mysqli_fetch_assoc($cRes)) $challenges[] = $r;

        $oRes = mysqli_query($connection, "SELECT * FROM report_objectives WHERE report_id = {$editId}");
        while ($r = mysqli_fetch_assoc($oRes)) $objectives[] = $r;

        $actRes = mysqli_query($connection, "SELECT * FROM report_activities WHERE report_id = {$editId}");
        while ($r = mysqli_fetch_assoc($actRes)) $activities[] = $r;
    }
}

$readonly = $editing && !in_array($report['status'], ['draft', 'rejected']);

/* =========================
   DATA
========================= */
$assignedKpis = mysqli_query(
    $connection,
    "SELECT *
     FROM kpis
     WHERE status = 'active'
     AND department_id = {$departmentId}
     AND role IN ('" . mysqli_real_escape_string($connection, $role) . "', 'all')
     ORDER BY kpi_title ASC"
);

$where = "";
if ($role === 'staff') {
    $where = "WHERE wr.user_id = {$userId}";
} elseif ($role === 'manager') {
    $where = "WHERE wr.department_id = {$departmentId}";
}

$reports = mysqli_query(
    $connection,
    "SELECT 
        wr.*,
        u.fullname,
        d.department_name
     FROM weekly_reports wr
     LEFT JOIN users u ON u.id = wr.user_id
     LEFT JOIN departments d ON d.id = wr.department_id
     {$where}
     ORDER BY wr.created_at DESC"
);

include '../inc/header.php';
?>

<style>
.report-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.btn {
    border: none;
    cursor: pointer;
    border-radius: 13px;
    padding: 12px 16px;
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

.btn-danger {
    background: #fee2e2;
    color: #991b1b;
}

.btn-success {
    background: #dcfce7;
    color: #166534;
}

.panel {
    background: #fff;
    border: 1px solid #eef2f7;
    border-radius: 24px;
    padding: 24px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    margin-bottom: 24px;
}

.panel h3 {
    color: #1e293b;
    margin-bottom: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}

.form-control {
    width: 100%;
    height: 46px;
    border: 1px solid #e2e8f0;
    border-radius: 13px;
    padding: 0 13px;
    font-size: 14px;
    outline: none;
}

textarea.form-control {
    min-height: 45px;
    padding: 12px 13px;
    resize: vertical;
}

.form-control:focus {
    border-color: #db2777;
    box-shadow: 0 0 0 4px rgba(219, 39, 119, .10);
}

.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

.report-table th {
    text-align: left;
    background: #fff7ed;
    color: #7c2d12;
    padding: 13px;
    font-size: 13px;
    border-bottom: 1px solid #fed7aa;
}

.report-table td {
    padding: 12px;
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

.status-draft {
    background: #f1f5f9;
    color: #475569;
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

.message {
    padding: 14px 16px;
    border-radius: 14px;
    margin-bottom: 18px;
    font-weight: 500;
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

.section-title {
    margin: 28px 0 14px;
    font-size: 18px;
    color: #431407;
}

.row-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.small-btn {
    padding: 8px 10px;
    font-size: 12px;
}

@media(max-width:900px) {
    .panel {
        padding: 18px;
        border-radius: 18px;
    }

    .report-actions {
        align-items: stretch;
    }

    .report-actions .btn {
        width: 100%;
    }

    .report-table {
        min-width: 980px;
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
<div class="message <?= h($_SESSION['flash_type']) ?>">
    <?= h($_SESSION['flash_text']) ?>
</div>
<?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>



<?php if (isset($_GET['new']) || $editing): ?>

<form method="POST" class="panel" enctype="multipart/form-data">

    <input type="hidden" name="report_id" value="<?= $editing ? (int)$report['id'] : 0 ?>">

    <h3><?= $editing ? 'Edit Weekly Report' : 'Create Weekly Report' ?></h3>

    <?php if ($readonly): ?>
    <div class="message error">This report has already been submitted and is now read-only.</div>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group">
            <label>Week Start</label>
            <input type="date" name="week_start" class="form-control" required
                value="<?= h($report['week_start'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
        </div>

        <div class="form-group">
            <label>Week End</label>
            <input type="date" name="week_end" class="form-control" required value="<?= h($report['week_end'] ?? '') ?>"
                <?= $readonly ? 'readonly' : '' ?>>
        </div>

        <div class="form-group">
            <label>Period Covered</label>
            <input type="text" name="period_covered" class="form-control" placeholder="Example: May Week 2"
                value="<?= h($report['period_covered'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
        </div>
    </div>

    <h4 class="section-title">A. Achievements for the Week</h4>

    <div class="table-wrap">
        <table class="report-table" id="achievementTable">
            <thead>
                <tr>
                    <th>KPI</th>
                    <th>Objective</th>
                    <th>Weekly Target</th>
                    <th>Achieved</th>
                    <th>%</th>
                    <th>Remarks</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $achievementRows = [];

                if (count($achievements)) {
                    $achievementRows = $achievements;
                } else {
                    while ($k = mysqli_fetch_assoc($assignedKpis)) {
                        $achievementRows[] = [
                            'kpi_id' => $k['id'],
                            'performance_area' => $k['kpi_title'],
                            'objective' => $k['description'],
                            'weekly_objective' => $k['target_value'],
                            'weekly_achieved' => '',
                            'weekly_percentage' => '',
                            'mtd_objective' => '',
                            'mtd_achieved' => '',
                            'mtd_percentage' => '',
                            'remarks' => ''
                        ];
                    }
                }

                foreach ($achievementRows as $a):
                ?>
                <tr>
                    <td>
                        <input type="hidden" name="kpi_id[]" value="<?= (int)($a['kpi_id'] ?? 0) ?>">
                        <input type="hidden" name="performance_area[]" value="<?= h($a['performance_area'] ?? '') ?>">

                        <strong><?= h($a['performance_area'] ?? '') ?></strong>
                    </td>

                    <td>
                        <input name="objective[]" class="form-control" value="<?= h($a['objective'] ?? '') ?>"
                            <?= $readonly ? 'readonly' : '' ?>>
                    </td>

                    <td>
                        <input type="number" step="0.01" name="weekly_objective[]" class="form-control calc-target"
                            value="<?= h($a['weekly_objective'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
                    </td>

                    <td>
                        <input type="number" step="0.01" name="weekly_achieved[]" class="form-control calc-achieved"
                            value="<?= h($a['weekly_achieved'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
                    </td>

                    <td>
                        <input name="weekly_percentage[]" class="form-control calc-percent" readonly
                            value="<?= h($a['weekly_percentage'] ?? '') ?>">
                    </td>

                    <td>
                        <input name="remarks[]" class="form-control" value="<?= h($a['remarks'] ?? '') ?>"
                            <?= $readonly ? 'readonly' : '' ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h4 class="section-title">B. Challenges and Recommended Solutions</h4>

    <div id="challengeRows">
        <?php $challengeRows = count($challenges) ? $challenges : [[]]; foreach ($challengeRows as $c): ?>
        <div class="form-grid challenge-row">
            <div class="form-group">
                <label>Challenge</label>
                <textarea name="challenge[]" class="form-control"
                    <?= $readonly ? 'readonly' : '' ?>><?= h($c['challenge'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Recommended Solution</label>
                <textarea name="recommended_solution[]" class="form-control"
                    <?= $readonly ? 'readonly' : '' ?>><?= h($c['recommended_solution'] ?? '') ?></textarea>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$readonly): ?>
    <button type="button" class="btn btn-light" onclick="addChallengeRow()">+ Add Challenge</button>
    <?php endif; ?>

    <h4 class="section-title">C. Objectives for Next Week</h4>

    <div id="objectiveRows">
        <?php $objectiveRows = count($objectives) ? $objectives : [[]]; foreach ($objectiveRows as $o): ?>
        <div class="form-grid objective-row">
            <div class="form-group">
                <label>Objective</label>
                <input name="next_objective[]" class="form-control" value="<?= h($o['objective'] ?? '') ?>"
                    <?= $readonly ? 'readonly' : '' ?>>
            </div>
            <div class="form-group">
                <label>Target Date</label>
                <input type="date" name="target_date[]" class="form-control" value="<?= h($o['target_date'] ?? '') ?>"
                    <?= $readonly ? 'readonly' : '' ?>>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$readonly): ?>
    <button type="button" class="btn btn-light" onclick="addObjectiveRow()">+ Add Objective</button>
    <?php endif; ?>

    <h4 class="section-title">D. Planned Activities for the Week</h4>

    <div id="activityRows">
        <?php $activityRows = count($activities) ? $activities : [[]]; foreach ($activityRows as $act): ?>
        <div class="form-grid activity-row">
            <div class="form-group">
                <label>Activity</label>
                <input name="activity[]" class="form-control" value="<?= h($act['activity'] ?? '') ?>"
                    <?= $readonly ? 'readonly' : '' ?>>
            </div>
            <div class="form-group">
                <label>Activity Date</label>
                <input type="date" name="activity_date[]" class="form-control"
                    value="<?= h($act['activity_date'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$readonly): ?>
    <button type="button" class="btn btn-light" onclick="addActivityRow()">+ Add Activity</button>
    <?php endif; ?>

    <h4 class="section-title">E. Supporting Documents</h4>

    <div class="form-group">
        <label>Upload Documents</label>
        <input type="file" name="report_files[]" class="form-control" multiple
            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" <?= $readonly ? 'disabled' : '' ?>>
    </div>
    <?php if (!empty($attachments)): ?>
    <div class="uploaded-files">
        <?php foreach ($attachments as $file): ?>
        <div class="uploaded-file">
            <span>
                <?= h($file['file_name']) ?>
            </span>

            <a href="../<?= h($file['file_path']) ?>" target="_blank">
                View
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="row-actions" style="margin-top:28px;">
        <a href="weekly_reports.php" class="btn btn-light">Back</a>

        <?php if (!$readonly): ?>
        <button type="submit" name="action" value="save_report" class="btn btn-light">Save Draft</button>
        <button type="submit" name="action" value="submit_report" class="btn btn-primary">Submit Report</button>
        <?php endif; ?>
    </div>

</form>

<?php endif; ?>

<?php if (!isset($_GET['new']) && !$editing): ?>
<div class="panel">

    <div class="report-actions">
        <div>
            <h3 style="color:#1e293b;margin-bottom:5px;">Staff Report List</h3>
        </div>

        <a href="weekly_reports.php?new=1" class="btn btn-primary">+ New Weekly Report</a>
    </div>


    <!-- <h3>Report List</h3> -->

    <div class="table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Department</th>
                    <th>Week Covered</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th>KPI Score</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($reports && mysqli_num_rows($reports) > 0): ?>
                <?php while ($r = mysqli_fetch_assoc($reports)): ?>
                <?php $status = strtolower($r['status'] ?? 'draft'); ?>
                <tr>
                    <td><?= h($r['fullname']) ?></td>
                    <td><?= h($r['department_name']) ?></td>
                    <td><?= h(date('M d, Y', strtotime($r['week_start']))) ?> -
                        <?= h(date('M d, Y', strtotime($r['week_end']))) ?></td>
                    <td><?= h($r['period_covered']) ?></td>
                    <td><span class="status-badge status-<?= h($status) ?>"><?= h($status) ?></span></td>
                    <td><?= number_format((float)$r['total_kpi_score'], 2) ?>%</td>
                    <td><?= $r['submission_date'] ? h(date('M d, Y', strtotime($r['submission_date']))) : 'Not submitted' ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a href="weekly_reports.php?edit=<?= (int)$r['id'] ?>" class="btn btn-light small-btn">
                                Open
                            </a>

                            <?php if ($status === 'draft' && (int)$r['user_id'] === $userId): ?>
                            <form method="POST" onsubmit="return confirm('Delete this draft report?');">
                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" name="action" value="delete_report"
                                    class="btn btn-danger small-btn">
                                    Delete
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">
                        No weekly reports found.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
<?php endif; ?>
<script>
function removeRow(btn) {
    const tr = btn.closest('tr');
    if (tr) tr.remove();
}

function calculateRow(row) {
    const target = parseFloat(row.querySelector('.calc-target')?.value || 0);
    const achieved = parseFloat(row.querySelector('.calc-achieved')?.value || 0);
    const percent = row.querySelector('.calc-percent');

    if (percent) {
        percent.value = target > 0 ? ((achieved / target) * 100).toFixed(2) : "0.00";
    }


}

document.addEventListener('input', function(e) {
    if (
        e.target.classList.contains('calc-target') ||
        e.target.classList.contains('calc-achieved')
    ) {
        calculateRow(e.target.closest('tr'));
    }
});


function addChallengeRow() {
    document.getElementById('challengeRows').insertAdjacentHTML('beforeend', `
        <div class="form-grid challenge-row">
            <div class="form-group">
                <label>Challenge</label>
                <textarea name="challenge[]" class="form-control"></textarea>
            </div>
            <div class="form-group">
                <label>Recommended Solution</label>
                <textarea name="recommended_solution[]" class="form-control"></textarea>
            </div>
        </div>
    `);
}

function addObjectiveRow() {
    document.getElementById('objectiveRows').insertAdjacentHTML('beforeend', `
        <div class="form-grid objective-row">
            <div class="form-group">
                <label>Objective</label>
                <input name="next_objective[]" class="form-control">
            </div>
            <div class="form-group">
                <label>Target Date</label>
                <input type="date" name="target_date[]" class="form-control">
            </div>
        </div>
    `);
}

function addActivityRow() {
    document.getElementById('activityRows').insertAdjacentHTML('beforeend', `
        <div class="form-grid activity-row">
            <div class="form-group">
                <label>Activity</label>
                <input name="activity[]" class="form-control">
            </div>
            <div class="form-group">
                <label>Activity Date</label>
                <input type="date" name="activity_date[]" class="form-control">
            </div>
        </div>
    `);
}
</script>

<?php include '../inc/footer.php'; ?>