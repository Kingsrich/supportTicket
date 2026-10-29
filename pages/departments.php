<?php
require '../inc/auth.php';
require '../inc/connection.php';

$userRole = strtolower(trim($_SESSION['role'] ?? ''));
if ($userRole !== 'admin') {
    $_SESSION['error'] = 'You do not have permission to access that page.';
    header('Location: it_tickets.php');
    exit;
}
mysqli_report(MYSQLI_REPORT_OFF);

$pageTitle = "Departments Management";
$message = "";
$error = "";

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = strtolower($_SESSION['role'] ?? 'staff');
$canManageDepartments = in_array($currentRole, ['admin', 'manager'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManageDepartments) {

        $error = "You are not authorized to manage departments.";

    } else {

        if (isset($_POST['save_department'])) {

            $departmentName = trim($_POST['department_name'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($departmentName === '') {
                $error = "Department name is required.";
            } else {

                $stmt = mysqli_prepare(
                    $connection,
                    "INSERT INTO departments (department_name, status) VALUES (?, ?)"
                );

                mysqli_stmt_bind_param($stmt, "ss", $departmentName, $status);

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Department created successfully.";
                } else {
                    $error = "Unable to create department. It may already exist.";
                }
            }
        }

        if (isset($_POST['update_department'])) {

            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $departmentName = trim($_POST['department_name'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($departmentId <= 0) {
                $error = "Invalid department selected.";
            } elseif ($departmentName === '') {
                $error = "Department name is required.";
            } else {

                $stmt = mysqli_prepare(
                    $connection,
                    "UPDATE departments
                     SET department_name = ?, status = ?
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param($stmt, "ssi", $departmentName, $status, $departmentId);

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Department updated successfully.";
                } else {
                    $error = "Unable to update department.";
                }
            }
        }

        if (isset($_POST['delete_department'])) {

            $departmentId = (int) ($_POST['department_id'] ?? 0);

            if ($departmentId > 0) {

                $check = mysqli_query(
                    $connection,
                    "SELECT COUNT(*) AS total FROM users WHERE department_id = {$departmentId}"
                );

                $row = mysqli_fetch_assoc($check);
                $staffCount = (int) ($row['total'] ?? 0);

                if ($staffCount > 0) {
                    $error = "This department has staff attached. Disable it instead of deleting.";
                } else {
                    mysqli_query($connection, "DELETE FROM departments WHERE id = {$departmentId}");
                    $message = "Department deleted successfully.";
                }
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$where = "";

if (!$canManageDepartments) {
    $where = "WHERE d.id = (
        SELECT department_id FROM users WHERE id = {$currentUserId} LIMIT 1
    )";
}

if ($search !== '') {

    $safeSearch = mysqli_real_escape_string($connection, $search);

    if ($where === '') {
        $where = "WHERE ";
    } else {
        $where .= " AND ";
    }

    $where .= " d.department_name LIKE '%{$safeSearch}%'";
}

$departments = mysqli_query(
    $connection,
    "SELECT
        d.id,
        d.department_name,
        d.status,
        d.created_at,
        COUNT(u.id) AS staff_count
    FROM departments d
    LEFT JOIN users u ON u.department_id = d.id
    {$where}
    GROUP BY d.id
    ORDER BY d.created_at DESC"
);

include '../inc/header.php';
?>

<style>
    .page-wrap {
        width: 100%;
    }

    .panel {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 20px;
        padding: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        margin-bottom: 22px;
    }

    .panel-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .panel h3 {
        color: #1e293b;
        margin-bottom: 14px;
        font-size: 17px;
        font-weight: 700;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }

    .form-control {
        width: 100%;
        height: 40px;
        border: 1px solid #e2e8f0;
        border-radius: 11px;
        padding: 0 13px;
        outline: none;
        font-size: 13px;
        background: #fff;
    }

    .form-control:focus {
        border-color: #db2777;
        box-shadow: 0 0 0 4px rgba(219, 39, 119, 0.10);
    }

    .btn {
        border: none;
        cursor: pointer;
        border-radius: 11px;
        padding: 10px 14px;
        font-weight: 700;
        font-size: 12px;
        text-decoration: none;
        display: inline-flex;
        justify-content: center;
        align-items: center;
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

    .search-row {
        display: flex;
        gap: 10px;
        margin-bottom: 18px;
    }

    .search-row input {
        flex: 1;
    }

    .table-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
    }

    .dept-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 720px;
    }

    .dept-table th {
        text-align: left;
        background: #fff7ed;
        color: #7c2d12;
        padding: 12px 14px;
        font-size: 12px;
        border-bottom: 1px solid #fed7aa;
    }

    .dept-table td {
        padding: 11px 14px;
        font-size: 13px;
        color: #334155;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    .dept-name-btn {
        border: none;
        background: transparent;
        color: #7c2d12;
        font-weight: 600;
        cursor: pointer;
        padding: 0;
        font-size: 13px;
        white-space: nowrap;
    }

    .dept-name-btn:hover {
        color: #db2777;
        text-decoration: underline;
    }

    .badge {
        padding: 5px 10px;
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

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
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
        max-width: 520px;
        max-height: 92vh;
        overflow-y: auto;
        background: #fff;
        border-radius: 24px;
        padding: 24px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 22px;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 17px;
    }

    .modal-close {
        width: 38px;
        height: 38px;
        border: none;
        border-radius: 11px;
        background: #fee2e2;
        color: #991b1b;
        font-size: 18px;
        cursor: pointer;
        font-weight: 600;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    .modal-actions .btn {
        flex: 1;
    }

    .toast-alert {
        position: fixed;
        right: 24px;
        bottom: 24px;
        min-width: 320px;
        max-width: 420px;
        padding: 16px 18px;
        border-radius: 18px;
        color: #fff;
        font-size: 13px;
        font-weight: 500;
        z-index: 99999;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
        animation: slideIn .25s ease, fadeOut .4s ease 4.6s forwards;
    }

    .toast-success {
        background: linear-gradient(135deg, #059669, #10b981);
    }

    .toast-error {
        background: linear-gradient(135deg, #dc2626, #ef4444);
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeOut {
        to {
            opacity: 0;
            transform: translateY(20px);
            visibility: hidden;
        }
    }
</style>

<div class="page-wrap">
    <div class="panel">
        <div class="panel-top">
            <h3 style="margin-bottom:0;">Departments</h3>
            <?php if ($canManageDepartments): ?>
                <button type="button" class="btn btn-primary" onclick="openAddDepartmentModal()">
                    + Add Department
                </button>
            <?php endif; ?>
        </div>
        <form method="GET" class="search-row">
            <input type="text" name="search" class="form-control" placeholder="Search department"
                value="<?= h($search) ?>">
            <button type="submit" class="btn btn-light">
                Search
            </button>
            <a href="departments.php" class="btn btn-light">
                Reset
            </a>
        </form>
        <div class="table-wrap">
            <table class="dept-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Department</th>
                        <th>Staff Count</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($departments && mysqli_num_rows($departments) > 0): ?>
                        <?php $sn = 1; ?>
                        <?php while ($d = mysqli_fetch_assoc($departments)): ?>
                            <?php
                            $status = strtolower($d['status'] ?? 'inactive');
                            $statusClass = 'badge-' . $status;
                            ?>
                            <tr>
                                <td><?= $sn++ ?></td>
                                <td>
                                    <?php if ($canManageDepartments): ?>
                                        <button type="button" class="dept-name-btn" onclick='openDepartmentModal(<?= json_encode([
                                            "id" => (int) $d["id"],
                                            "department_name" => $d["department_name"],
                                            "status" => $d["status"],
                                            "staff_count" => (int) $d["staff_count"]
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <?= h($d['department_name']) ?>
                                        </button>
                                    <?php else: ?>
                                        <?= h($d['department_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((int) $d['staff_count']) ?></td>
                                <td>
                                    <span class="badge <?= h($statusClass) ?>">
                                        <?= h($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h(date('M d, Y', strtotime($d['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">
                                No departments found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="modal-overlay" id="addDepartmentModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Add Department</h3>
            <button type="button" class="modal-close" onclick="closeAddDepartmentModal()">
                ×
            </button>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Department Name</label>
                <input type="text" name="department_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <button type="submit" name="save_department" class="btn btn-primary" style="width:100%;">
                Save Department
            </button>
        </form>
    </div>
</div>
<div class="modal-overlay" id="editDepartmentModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit Department</h3>
            <button type="button" class="modal-close" onclick="closeDepartmentModal()">
                ×
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="department_id" id="edit_department_id">
            <div class="form-group">
                <label>Department Name</label>
                <input type="text" name="department_name" id="edit_department_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="edit_department_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div style="
                background:#fff7ed;
                border:1px solid #fed7aa;
                border-radius:16px;
                padding:14px;
                margin-top:8px;
                font-size:13px;
                color:#7c2d12;
                font-weight:600;
            ">
                Staff in this department:
                <strong id="edit_staff_count">0</strong>
            </div>
            <div class="modal-actions">
                <button type="submit" name="update_department" class="btn btn-primary">
                    Save
                </button>
                <button type="submit" name="delete_department" class="btn btn-danger"
                    onclick="return confirm('Delete this department? This is only allowed if no staff is attached.');">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>
<?php if ($message !== ''): ?>
    <div class="toast-alert toast-success">
        <?= h($message) ?>
    </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="toast-alert toast-error">
        <?= h($error) ?>
    </div>
<?php endif; ?>
<script>
    function openAddDepartmentModal() {
        document.getElementById('addDepartmentModal').classList.add('active');
    }

    function closeAddDepartmentModal() {
        document.getElementById('addDepartmentModal').classList.remove('active');
    }

    function openDepartmentModal(data) {
        document.getElementById('edit_department_id').value = data.id || '';
        document.getElementById('edit_department_name').value = data.department_name || '';
        document.getElementById('edit_department_status').value = data.status || 'active';
        document.getElementById('edit_staff_count').innerText = data.staff_count || 0;

        document.getElementById('editDepartmentModal').classList.add('active');
    }

    function closeDepartmentModal() {
        document.getElementById('editDepartmentModal').classList.remove('active');
    }

    window.addEventListener('click', function (e) {

        const addModal = document.getElementById('addDepartmentModal');
        const editModal = document.getElementById('editDepartmentModal');

        if (e.target === addModal) {
            closeAddDepartmentModal();
        }

        if (e.target === editModal) {
            closeDepartmentModal();
        }

    });
</script>

<?php include '../inc/footer.php'; ?>