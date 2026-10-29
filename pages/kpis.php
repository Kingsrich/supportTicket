<?php
require '../inc/auth.php';
require '../inc/connection.php';

$pageTitle = "KPI Management";

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'staff';

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

/* =========================
   SAVE KPI
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($userRole === 'staff') {
        flash('error', 'You do not have permission to manage KPIs.');
        header("Location: kpis.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_kpi') {

        $kpiId = (int) ($_POST['kpi_id'] ?? 0);
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $role = $_POST['kpi_role'] ?? 'staff';

        if ($userRole === 'manager') {
            $departmentId = $userDeptId;
        }

        if (!in_array($role, ['admin', 'manager', 'staff', 'all'])) {
            $role = 'staff';
        }

        if ($departmentId <= 0) {
            flash('error', 'Please select department.');
            header("Location: kpis.php");
            exit;
        }

        /*
            CREATE MULTIPLE KPIS
        */
        $kpiIds = $_POST['kpi_ids'] ?? [];
        $titles = $_POST['kpi_titles'] ?? [];
        $descriptions = $_POST['descriptions'] ?? [];
        $measurementTypes = $_POST['measurement_types'] ?? [];
        $targetValues = $_POST['target_values'] ?? [];
        $weights = $_POST['weights'] ?? [];
        $statuses = $_POST['statuses'] ?? [];

        $inserted = 0;
        $skipped = 0;

        for ($i = 0; $i < count($titles); $i++) {
            $currentKpiId = (int) ($kpiIds[$i] ?? 0);
            $title = trim($titles[$i] ?? '');
            $description = trim($descriptions[$i] ?? '');
            $measurementType = $measurementTypes[$i] ?? 'number';
            $targetValue = $targetValues[$i] !== '' ? (float) $targetValues[$i] : null;
            $weight = (float) ($weights[$i] ?? 0);
            $status = $statuses[$i] ?? 'active';

            if ($title === '') {
                $skipped++;
                continue;
            }

            if (!in_array($measurementType, ['percentage', 'number', 'currency', 'text'])) {
                $measurementType = 'number';
            }

            if (!in_array($status, ['active', 'inactive'])) {
                $status = 'active';
            }

            if ($currentKpiId > 0) {

                $stmt = mysqli_prepare(
                    $connection,
                    "UPDATE kpis
         SET department_id = ?,
             role = ?,
             kpi_title = ?,
             description = ?,
             measurement_type = ?,
             target_value = ?,
             weight = ?,
             status = ?
         WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "issssddsi",
                    $departmentId,
                    $role,
                    $title,
                    $description,
                    $measurementType,
                    $targetValue,
                    $weight,
                    $status,
                    $currentKpiId
                );

            } else {

                $stmt = mysqli_prepare(
                    $connection,
                    "INSERT INTO kpis
         (department_id, role, kpi_title, description, measurement_type, target_value, weight, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "issssddsi",
                    $departmentId,
                    $role,
                    $title,
                    $description,
                    $measurementType,
                    $targetValue,
                    $weight,
                    $status,
                    $userId
                );
            }

            if (mysqli_stmt_execute($stmt)) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
        if ($inserted > 0) {
            flash('success', "{$inserted} KPI(s) created successfully.");
        } else {
            flash('error', 'No KPI was created. Please enter at least one KPI title.');
        }

        header("Location: kpis.php");
        exit;
    }

    if ($action === 'toggle_kpi') {
        $kpiId = (int) ($_POST['kpi_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'inactive';
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $role = $_POST['kpi_role'] ?? 'staff';

        if ($userRole === 'manager') {
            mysqli_query($connection, "UPDATE kpis SET status='{$newStatus}' WHERE id={$kpiId} AND department_id={$userDeptId}");
        } else {
            mysqli_query($connection, "UPDATE kpis SET status='{$newStatus}' WHERE id={$kpiId}");
        }

        flash('success', 'KPI status updated.');
        header("Location: kpis.php");
        exit;
    }
}

/* =========================
   FILTERS
========================= */
$departments = mysqli_query(
    $connection,
    "SELECT id, department_name FROM departments WHERE status='active' ORDER BY department_name"
);

$selectedDepartment = (int) ($_GET['department_id'] ?? 0);
$selectedRole = $_GET['kpi_role'] ?? '';
$search = trim($_GET['search'] ?? '');

if ($userRole === 'manager') {
    $selectedDepartment = $userDeptId;
}

if ($selectedRole !== '' && !in_array($selectedRole, ['staff', 'manager', 'admin', 'all'])) {
    $selectedRole = '';
}

$where = "WHERE 1=1";

if ($selectedDepartment > 0) {
    $where .= " AND k.department_id = {$selectedDepartment}";
}

if ($selectedRole !== '') {
    $where .= " AND k.role = '" . mysqli_real_escape_string($connection, $selectedRole) . "'";
}

if ($userRole === 'staff') {
    $where .= " AND k.status = 'active'";
    $where .= " AND (k.department_id = {$userDeptId} OR k.department_id IS NULL)";
    $where .= " AND k.role IN ('staff','all')";
}

if ($search !== '') {
    $safeSearch = mysqli_real_escape_string($connection, $search);
    $where .= " AND (k.kpi_title LIKE '%{$safeSearch}%' OR k.description LIKE '%{$safeSearch}%')";
}

$kpis = mysqli_query(
    $connection,
    "SELECT
        k.department_id,
        k.role,
        d.department_name,
        COUNT(k.id) total_kpis,
        SUM(k.weight) total_weight,
        SUM(
            CASE
                WHEN k.status = 'active' THEN 1
                ELSE 0
            END
        ) active_kpis
     FROM kpis k
     LEFT JOIN departments d
        ON d.id = k.department_id
     {$where}
     GROUP BY k.department_id, k.role
     ORDER BY d.department_name ASC, k.role ASC"
);

$totalKpis = mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) total FROM kpis k {$where}"))['total'] ?? 0;
$activeKpis = mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) total FROM kpis k {$where} AND k.status='active'"))['total'] ?? 0;
$totalWeight = mysqli_fetch_assoc(mysqli_query($connection, "SELECT COALESCE(SUM(weight),0) total FROM kpis k {$where}"))['total'] ?? 0;

$editId = (int) ($_GET['edit'] ?? 0);
$editKpi = null;

if ($editId > 0) {
    $editSql = "SELECT * FROM kpis WHERE id={$editId}";

    if ($userRole === 'manager') {
        $editSql .= " AND department_id={$userDeptId}";
    }

    $editSql .= " LIMIT 1";

    $editRes = mysqli_query($connection, $editSql);
    $editKpi = mysqli_fetch_assoc($editRes);
}

$setDepartmentId = (int) ($_GET['set_department_id'] ?? 0);
$setRole = $_GET['set_role'] ?? '';

$setKpis = [];
$setDepartmentName = '';

if ($setDepartmentId > 0 && in_array($setRole, ['staff', 'manager', 'admin', 'all'])) {

    $deptRes = mysqli_query(
        $connection,
        "SELECT department_name FROM departments WHERE id = {$setDepartmentId} LIMIT 1"
    );

    if ($deptRow = mysqli_fetch_assoc($deptRes)) {
        $setDepartmentName = $deptRow['department_name'];
    }

    $setSql = "
    SELECT *
    FROM kpis
    WHERE department_id = {$setDepartmentId}
    AND role = '" . mysqli_real_escape_string($connection, $setRole) . "'
";

    if ($userRole === 'manager') {
        $setSql .= " AND department_id = {$userDeptId}";
    }

    $setSql .= " ORDER BY id ASC";

    $setRes = mysqli_query($connection, $setSql);

    while ($row = mysqli_fetch_assoc($setRes)) {
        $setKpis[] = $row;
    }
}

include '../inc/header.php';
?>

<style>
    .kpi-page-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .kpi-page-head h2 {
        font-size: 21px;
        color: #1e293b;
        margin-bottom: 3px;
    }

    .kpi-page-head p {
        font-size: 13px;
        color: #64748b;
    }

    .btn {
        border: none;
        cursor: pointer;
        border-radius: 10px;
        padding: 9px 13px;
        font-weight: 600;
        font-size: 12px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
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

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }

    .summary-card {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 16px;
        padding: 15px;
        box-shadow: 0 5px 16px rgba(15, 23, 42, .04);
    }

    .summary-label {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .summary-value {
        font-size: 24px;
        font-weight: 700;
        color: #431407;
    }

    .panel {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 18px;
        padding: 17px;
        box-shadow: 0 5px 16px rgba(15, 23, 42, .04);
        margin-bottom: 14px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: 1.3fr 1fr 1fr auto;
        gap: 10px;
        align-items: end;
    }

    .form-group {
        margin-bottom: 0;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }

    .form-control {
        width: 100%;
        height: 36px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0 10px;
        outline: none;
        font-size: 13px;
    }

    textarea.form-control {
        height: auto;
        min-height: 74px;
        padding: 10px;
        resize: vertical;
    }

    .form-control:focus {
        border-color: #db2777;
        box-shadow: 0 0 0 3px rgba(219, 39, 119, .10);
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .kpi-table {
        width: 100%;
        min-width: 880px;
        border-collapse: collapse;
    }

    .kpi-table th {
        text-align: left;
        background: #fff7ed;
        color: #7c2d12;
        padding: 10px 11px;
        font-size: 12px;
        border-bottom: 1px solid #fed7aa;
    }

    .kpi-table td {
        padding: 10px 11px;
        border-bottom: 1px solid #eef2f7;
        font-size: 13px;
        color: #334155;
        vertical-align: top;
    }

    .clickable-row,
    .clickable-row td {
        cursor: pointer !important;
    }

    .clickable-row:hover {
        background: #fff7ed;
    }

    .kpi-title-link,
    .kpi-title-link:link,
    .kpi-title-link:visited,
    .kpi-title-link:hover,
    .kpi-title-link:active {
        color: #431407;
        font-weight: 600;
        text-decoration: none !important;
        cursor: pointer !important;
    }

    .kpi-title-link:hover {
        color: #db2777;
    }

    .badge {
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        text-transform: capitalize;
        display: inline-flex;
    }

    .badge-active {
        background: #dcfce7;
        color: #166534;
    }

    .badge-inactive {
        background: #f1f5f9;
        color: #475569;
    }

    .badge-role {
        background: #ede9fe;
        color: #6d28d9;
    }

    .badge-type {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .55);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-box {
        width: 100%;
        max-width: 1100px;
        /* height: 102vh; */
        max-height: 142vh;
        overflow-y: auto;
        background: #fff;
        border-radius: 22px;
        padding: 22px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, .25);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .modal-header h3 {
        font-size: 18px;
        color: #1e293b;
    }

    .modal-close {
        width: 38px;
        height: 38px;
        border: none;
        border-radius: 10px;
        background: #fee2e2;
        color: #991b1b;
        font-size: 20px;
        font-weight: 700;
        cursor: pointer;
    }

    .modal-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .toast-alert {
        position: fixed;
        top: 22px;
        right: 24px;
        z-index: 99999;
        min-width: 280px;
        max-width: 420px;
        padding: 13px 15px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        font-weight: 600;
        font-size: 13px;
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

    .weight-ok {
        color: #166534;
    }

    .weight-bad {
        color: #991b1b;
    }

    @media(max-width:900px) {

        .filter-grid,
        .modal-grid {
            grid-template-columns: 1fr;
        }

        .kpi-page-head .btn,
        .filter-grid .btn {
            width: 100%;
        }

        .modal-box {
            max-width: 96%;
            padding: 18px;
        }
    }

    .kpi-list-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin: 16px 0 10px;
    }

    .kpi-list-head h4 {
        font-size: 15px;
        color: #431407;
    }

    .kpi-entry-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
    }

    .kpi-entry-table th {
        background: #fff7ed;
        color: #7c2d12;
        padding: 8px;
        font-size: 12px;
        text-align: left;
        border-bottom: 1px solid #fed7aa;
    }

    .kpi-entry-table td {
        padding: 7px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
        min-width: auto;
    }

    .kpi-entry-table td:nth-child(2) {
        min-width: 16%;
    }

    .kpi-entry-table td:nth-child(3) {
        min-width: 20%;
    }

    .kpi-entry-table th:first-child,
    .kpi-entry-table td:first-child {
        width: 42px;
        min-width: 42px;
        max-width: 42px;
        text-align: center;
        padding-left: 2px;
        padding-right: 2px;
    }

    .kpi-entry-table th,
    .kpi-entry-table td {
        padding: 5px;
    }

    .kpi-entry-table .form-control {
        height: 34px;
        font-size: 12px;
        border-radius: 9px;
    }

    .remove-row-btn {
        width: 30px;
        height: 30px;
        border: none;
        border-radius: 9px;
        background: #fee2e2;
        color: #991b1b;
        cursor: pointer;
        font-size: 18px;
        font-weight: 900;
    }

    .save-kpi-btn {
        width: 17%;
        margin-top: 14px;
        margin-left: auto;
        display: flex;
        justify-content: center;
    }

    @media(max-width:900px) {
        .save-kpi-btn {
            width: 100%;
        }
    }
</style>

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div id="toastAlert" class="toast-alert toast-<?= h($_SESSION['flash_type']) ?>">
        <span><?= h($_SESSION['flash_text']) ?></span>
        <button type="button" onclick="closeToast()">×</button>
    </div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="kpi-page-head">
    <div>
        <!-- <h2>KPI Setup</h2> -->
        <!-- <p>Select a department and role, then create KPIs for that specific group.</p> -->
    </div>

    <?php if ($userRole !== 'staff'): ?>
        <button class="btn btn-primary" onclick="openKpiModal()">
            <i class="fa-solid fa-plus"></i> Create New KPI
        </button>
    <?php endif; ?>
</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-label">KPIs in This Set</div>
        <div class="summary-value"><?= number_format((float) $totalKpis) ?></div>
    </div>

    <div class="summary-card">
        <div class="summary-label">Active</div>
        <div class="summary-value"><?= number_format((float) $activeKpis) ?></div>
    </div>

    <div class="summary-card">
        <div class="summary-label">Total Weight</div>
        <div class="summary-value <?= ((float) $totalWeight == 100.00) ? 'weight-ok' : 'weight-bad' ?>">
            <?= number_format((float) $totalWeight, 2) ?>%
        </div>
    </div>
</div>

<div class="panel">
    <form method="GET" class="filter-grid">

        <div class="form-group">
            <label>Department</label>

            <?php if ($userRole === 'manager'): ?>
                <input type="hidden" name="department_id" value="<?= (int) $userDeptId ?>">
                <input type="text" class="form-control" readonly value="Your Department">
            <?php else: ?>
                <select name="department_id" class="form-control" required>
                    <option value="0">Select Department</option>
                    <?php mysqli_data_seek($departments, 0);
                    while ($d = mysqli_fetch_assoc($departments)): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $selectedDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['department_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Role</label>
            <select name="kpi_role" class="form-control">
                <option value="">All Roles</option>
                <option value="staff" <?= $selectedRole === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="manager" <?= $selectedRole === 'manager' ? 'selected' : '' ?>>Manager</option>
                <option value="admin" <?= $selectedRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="all" <?= $selectedRole === 'all' ? 'selected' : '' ?>>All Roles</option>
            </select>
        </div>

        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" value="<?= h($search) ?>" placeholder="Search KPI">
        </div>

        <button class="btn btn-light">
            <i class="fa-solid fa-filter"></i> Apply
        </button>

    </form>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="kpi-table">
            <thead>
                <tr>
                    <th style="width:55px;">S/N</th>
                    <th>Department</th>
                    <th>Role</th>
                    <th>Total KPIs</th>
                    <th>Active KPIs</th>
                    <th>Total Weight</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($kpis && mysqli_num_rows($kpis) > 0): ?>
                    <?php $sn = 1;
                    while ($k = mysqli_fetch_assoc($kpis)): ?>

                        <?php
                        $deptId = (int) $k['department_id'];
                        $roleName = $k['role'];

                        $isComplete = ((float) $k['total_weight'] == 100);
                        ?>

                        <tr class="clickable-row"
                            onclick="window.location='kpis.php?set_department_id=<?= $deptId ?>&set_role=<?= urlencode($roleName) ?>&department_id=<?= $deptId ?>&kpi_role=<?= urlencode($roleName) ?>'">
                            <td><?= $sn++ ?></td>

                            <td>
                                <strong>
                                    <?= h($k['department_name'] ?? 'General') ?>
                                </strong>
                            </td>

                            <td>
                                <span class="badge badge-role">
                                    <?= h($roleName) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((float) $k['total_kpis']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $k['active_kpis']) ?>
                            </td>

                            <td>
                                <strong class="<?= $isComplete ? 'weight-ok' : 'weight-bad' ?>">
                                    <?= number_format((float) $k['total_weight'], 2) ?>%
                                </strong>
                            </td>

                            <td>
                                <?php if ($k['active_kpis'] > 0): ?>
                                    <span class="badge badge-active">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:24px;color:#94a3b8;">
                            No KPIs found for this department and role.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay <?= ($editKpi || $setDepartmentId > 0) ? 'active' : '' ?>" id="kpiModal">
    <div class="modal-box">

        <div class="modal-header">
            <h3>
                <?php if ($setDepartmentId > 0): ?>
                    Edit KPI Set - <?= h($setDepartmentName) ?> / <?= h(ucfirst($setRole)) ?>
                <?php else: ?>
                    Create New KPI Set
                <?php endif; ?>
            </h3> <button type="button" class="modal-close" onclick="closeKpiModal()">×</button>
        </div>

        <form method="POST">

            <input type="hidden" name="action" value="save_kpi">
            <input type="hidden" name="kpi_id" value="0">

            <div class="modal-grid">

                <div class="form-group">
                    <label>Department</label>

                    <?php if ($userRole === 'manager'): ?>
                        <input type="hidden" name="department_id" value="<?= (int) $userDeptId ?>">
                        <input type="text" class="form-control" readonly value="Your Department">
                    <?php else: ?>
                        <select name="department_id" class="form-control" required>
                            <option value="">Select Department</option>

                            <?php mysqli_data_seek($departments, 0);
                            while ($d = mysqli_fetch_assoc($departments)): ?>
                                <?php
                                $modalDepartment = $setDepartmentId > 0 ? $setDepartmentId : $selectedDepartment;
                                ?>
                                <option value="<?= (int) $d['id'] ?>" <?= (int) $modalDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['department_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Role</label>

                    <?php
                    $modalRole = $setRole !== '' ? $setRole : $selectedRole;
                    ?>

                    <select name="kpi_role" class="form-control">
                        <option value="staff" <?= $modalRole === 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="manager" <?= $modalRole === 'manager' ? 'selected' : '' ?>>Manager</option>
                        <option value="admin" <?= $modalRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="all" <?= $modalRole === 'all' ? 'selected' : '' ?>>All Roles</option>
                    </select>
                </div>

            </div>

            <div class="kpi-list-head">
                <h4>KPI List</h4>

                <button type="button" class="btn btn-light" onclick="addKpiRow()">
                    + Add KPI Row
                </button>
            </div>

            <div class="table-wrap">
                <table class="kpi-entry-table" id="kpiEntryTable">
                    <thead>
                        <tr>
                            <th style="width:45px;">#</th>
                            <th>KPI Title</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Target</th>
                            <th>Weight</th>
                            <th>Status</th>
                            <th style="width:50px;"></th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php
                        $rows = count($setKpis) > 0 ? $setKpis : [[]];
                        $sn = 1;
                        ?>

                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="row-no"><?= $sn++ ?></td>

                                <td>
                                    <input type="hidden" name="kpi_ids[]" value="<?= (int) ($row['id'] ?? 0) ?>">
                                    <input type="text" name="kpi_titles[]" class="form-control" required
                                        value="<?= h($row['kpi_title'] ?? '') ?>">
                                </td>

                                <td>
                                    <input type="text" name="descriptions[]" class="form-control"
                                        value="<?= h($row['description'] ?? '') ?>">
                                </td>

                                <td>
                                    <?php $type = $row['measurement_type'] ?? 'number'; ?>
                                    <select name="measurement_types[]" class="form-control">
                                        <option value="number" <?= $type === 'number' ? 'selected' : '' ?>>Number</option>
                                        <option value="percentage" <?= $type === 'percentage' ? 'selected' : '' ?>>
                                            Percentage</option>
                                        <option value="currency" <?= $type === 'currency' ? 'selected' : '' ?>>Currency
                                        </option>
                                        <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>Text</option>
                                    </select>
                                </td>

                                <td>
                                    <input type="number" step="0.01" name="target_values[]" class="form-control"
                                        value="<?= h($row['target_value'] ?? '') ?>">
                                </td>

                                <td>
                                    <input type="number" step="0.01" name="weights[]" class="form-control"
                                        value="<?= h($row['weight'] ?? '') ?>">
                                </td>

                                <td>
                                    <?php $status = $row['status'] ?? 'active'; ?>
                                    <select name="statuses[]" class="form-control">
                                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive
                                        </option>
                                    </select>
                                </td>

                                <td>
                                    <button type="button" class="remove-row-btn" onclick="removeKpiRow(this)">×</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary save-kpi-btn">
                Save KPI Set
            </button>

        </form>

    </div>
</div>

<script>
    function openKpiModal() {
        document.getElementById('kpiModal').classList.add('active');
    }

    function closeKpiModal() {
        document.getElementById('kpiModal').classList.remove('active');

        const params = new URLSearchParams(window.location.search);
        const dept = params.get('department_id') || '';
        const role = params.get('kpi_role') || 'staff';

        window.location.href = 'kpis.php';
    }

    function closeToast() {
        const toast = document.getElementById('toastAlert');
        if (toast) {
            toast.style.display = 'none';
        }
    }

    setTimeout(function () {
        closeToast();
    }, 5200);

    window.addEventListener('click', function (e) {
        const modal = document.getElementById('kpiModal');

        if (e.target === modal) {
            closeKpiModal();
        }
    });

    function refreshKpiRowNumbers() {
        document.querySelectorAll('#kpiEntryTable tbody tr').forEach(function (row, index) {
            const cell = row.querySelector('.row-no');
            if (cell) {
                cell.textContent = index + 1;
            }
        });
    }

    function addKpiRow() {
        const tbody = document.querySelector('#kpiEntryTable tbody');
        const firstRow = tbody.querySelector('tr');
        const clone = firstRow.cloneNode(true);

        clone.querySelectorAll('input').forEach(function (input) {
            input.value = '';
        });

        clone.querySelectorAll('select').forEach(function (select) {
            select.selectedIndex = 0;
        });

        tbody.appendChild(clone);
        refreshKpiRowNumbers();
    }

    function removeKpiRow(btn) {
        const tbody = document.querySelector('#kpiEntryTable tbody');

        if (tbody.querySelectorAll('tr').length <= 1) {
            return;
        }

        btn.closest('tr').remove();
        refreshKpiRowNumbers();
    }
</script>


<?php include '../inc/footer.php'; ?>