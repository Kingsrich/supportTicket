<?php
require '../inc/auth.php';
require '../inc/connection.php';
mysqli_report(MYSQLI_REPORT_OFF);

$pageTitle = "User Management";
$message = "";
$error = "";

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = strtolower($_SESSION['role'] ?? 'staff');
$canManageUsers = in_array($currentRole, ['admin', 'manager'], true);

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function sFlash($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}
function timeAgo($timestamp)
{
    if (!$timestamp) return '—';
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 1) return 'just now';
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return round($diff / 60) . ' minutes ago';
    if ($diff < 86400) return round($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return round($diff / 86400) . ' days ago';
    if ($diff < 31536000) return round($diff / 2592000) . ' months ago';

    return date('M d, Y', $time);
}


function logAudit($connection, $adminId, $targetId, $action, $details)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = mysqli_prepare($connection, "INSERT INTO user_audit_log (admin_id, target_user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iisss", $adminId, $targetId, $action, $details, $ip);
    mysqli_stmt_execute($stmt);
}


function getOrCreateDepartment($connection, $name)
{
    $name = trim($name);
    if ($name === '')
        return null;

    $stmt = mysqli_prepare($connection, "SELECT id FROM departments WHERE department_name = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($res)) {
        return (int) $row['id'];
    }

    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 10));

    $stmt = mysqli_prepare($connection, "INSERT INTO departments (department_name, department_code, status) VALUES (?, ?, 'active')");
    mysqli_stmt_bind_param($stmt, "ss", $name, $code);
    mysqli_stmt_execute($stmt);

    return mysqli_insert_id($connection);
}

function getOrCreateDesignation($connection, $name)
{
    $name = trim($name);
    if ($name === '')
        return null;

    $stmt = mysqli_prepare($connection, "SELECT id FROM designations WHERE designation_name = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($res)) {
        return (int) $row['id'];
    }

    $stmt = mysqli_prepare($connection, "INSERT INTO designations (designation_name) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);

    return mysqli_insert_id($connection);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManageUsers) {
        $error = "You are not authorized to manage users.";
    } else {

        if (isset($_POST['save_user'])) {

            $fullname = trim($_POST['fullname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $employeeId = trim($_POST['employee_id'] ?? '');
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $designationId = (int) ($_POST['designation_id'] ?? 0);
            $lineManagerId = !empty($_POST['line_manager_id'])
                ? (int) $_POST['line_manager_id']
                : null;
            $role = $_POST['role'] ?? 'staff';
            $status = $_POST['status'] ?? 'active';
            $requiresWeeklyReport = isset($_POST['requires_weekly_report']) ? 1 : 0;

            if ($fullname === '' || $email === '') {
                $error = "Full name and email are required.";
            } else {

                /*
                    Temporary default password.
                    You can change this later from settings.
                    Every user must change password after first login.
                */
                $defaultPassword = "Welcome123@";
                $customPassword = trim($_POST['password'] ?? '');
                $hashedPassword = ($customPassword !== '')
                    ? password_hash($customPassword, PASSWORD_DEFAULT)
                    : password_hash("Welcome123@", PASSWORD_DEFAULT);

                $stmt = mysqli_prepare($connection, "INSERT INTO users (employee_id, fullname, email, phone, company, password, department_id, designation_id, line_manager_id, role, status, requires_weekly_report, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssssiiissi",
                    $employeeId,
                    $fullname,
                    $email,
                    $phone,
                    $company,
                    $hashedPassword,
                    $departmentId,
                    $designationId,
                    $lineManagerId,
                    $role,
                    $status,
                    $requiresWeeklyReport
                );

                if (mysqli_stmt_execute($stmt)) {
                    logAudit($connection, $currentUserId, mysqli_insert_id($connection), 'USER_CREATE', "Created user $fullname");
                    sFlash('success', "User created successfully, default password is Welcome123@");
                    header("Location: users.php");
                    exit;
                } else {

                    $dbError = mysqli_error($connection);

                    if (str_contains(strtolower($dbError), 'employee_id')) {

                        $error = "A user with this Employee ID already exists.";
                    } elseif (str_contains(strtolower($dbError), 'email')) {

                        $error = "A user with this email address already exists.";
                    } else {

                        $error = "Unable to create user. Please try again.";
                    }
                }
            }
        }
    }

    if (isset($_POST['bulk_upload'])) {

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please upload a valid CSV file.";
        } else {

            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");

            if ($handle === false) {
                $error = "Unable to read uploaded file.";
            } else {

                $rowCount = 0;
                $inserted = 0;
                $skipped = 0;

                $defaultPassword = "Welcome123@";
                $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

                $headers = fgetcsv($handle, 10000, ",");

                if (!$headers) {
                    $error = "CSV file is empty or invalid.";
                } else {

                    $headerMap = [];

                    foreach ($headers as $index => $headerName) {
                        $cleanHeader = strtolower(trim($headerName));
                        $cleanHeader = str_replace(" ", "_", $cleanHeader);
                        $headerMap[$cleanHeader] = $index;
                    }

                    $requiredColumns = [
                        "employee_id",
                        "fullname",
                        "email",
                        "phone",
                        "company",
                        "department",
                        "designation",
                        "line_manager_email",
                        "role",
                        "status",
                        "requires_weekly_report"
                    ];

                    foreach ($requiredColumns as $col) {
                        if (!array_key_exists($col, $headerMap)) {
                            $error = "Missing required CSV column: {$col}";
                            break;
                        }
                    }

                    if ($error === "") {

                        while (($data = fgetcsv($handle, 10000, ",")) !== false) {

                            $rowCount++;

                            $employeeId = trim($data[$headerMap["employee_id"]] ?? "");
                            $fullname = trim($data[$headerMap["fullname"]] ?? "");
                            $email = trim($data[$headerMap["email"]] ?? "");
                            $phone = trim($data[$headerMap["phone"]] ?? "");
                            $company = trim($data[$headerMap["company"]] ?? "");
                            $requiresWeeklyReportRaw = strtolower(trim($data[$headerMap["requires_weekly_report"]] ?? "0"));
                            $requiresWeeklyReport = in_array($requiresWeeklyReportRaw, ["1", "yes", "true", "required"], true) ? 1 : 0;
                            $departmentName = trim($data[$headerMap["department"]] ?? "");
                            $designationName = trim($data[$headerMap["designation"]] ?? "");
                            $lineManagerEmail = trim($data[$headerMap["line_manager_email"]] ?? "");
                            $role = strtolower(trim($data[$headerMap["role"]] ?? "staff"));
                            $status = strtolower(trim($data[$headerMap["status"]] ?? "active"));

                            if ($fullname === "" || $email === "") {
                                $skipped++;
                                continue;
                            }

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $skipped++;
                                continue;
                            }

                            if (!in_array($role, ["admin", "manager", "staff"])) {
                                $role = "staff";
                            }

                            if (!in_array($status, ["active", "inactive", "suspended"])) {
                                $status = "active";
                            }

                            $departmentId = getOrCreateDepartment($connection, $departmentName);
                            $designationId = getOrCreateDesignation($connection, $designationName);

                            $lineManagerId = null;

                            if ($lineManagerEmail !== "") {
                                $lineManagerEmail = strtolower(trim($lineManagerEmail));

                                $stmtMgr = mysqli_prepare(
                                    $connection,
                                    "SELECT id
     FROM users
     WHERE LOWER(TRIM(email)) = ?
     LIMIT 1"
                                );

                                mysqli_stmt_bind_param($stmtMgr, "s", $lineManagerEmail);
                                mysqli_stmt_execute($stmtMgr);

                                $mgrRes = mysqli_stmt_get_result($stmtMgr);
                                if ($mgr = mysqli_fetch_assoc($mgrRes)) {
                                    $lineManagerId = (int) $mgr['id'];
                                }
                            }

                            $stmt = mysqli_prepare(
                                $connection,
                                "INSERT INTO users (
        employee_id,
        fullname,
        email,
        phone,
        company,
        password,
        department_id,
        designation_id,
        line_manager_id,
        role,
        status,
        requires_weekly_report,
        must_change_password
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
                            );

                            mysqli_stmt_bind_param(
                                $stmt,
                                "ssssssiiissi",
                                $employeeId,
                                $fullname,
                                $email,
                                $phone,
                                $company,
                                $hashedPassword,
                                $departmentId,
                                $designationId,
                                $lineManagerId,
                                $role,
                                $status,
                                $requiresWeeklyReport
                            );

                            if (mysqli_stmt_execute($stmt)) {
                                $inserted++;
                            } else {
                                $skipped++;
                            }
                        }

                        sFlash('success', "Bulk upload completed. Inserted: {$inserted}. Skipped: {$skipped}.");
                        header("Location: users.php");
                        exit;
                    }
                }

                fclose($handle);
            }
        }
    }

    if (isset($_POST['toggle_status'])) {
        if (!$canManageUsers) {
            $error = "You are not authorized to change user status.";
        } else {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? 'inactive';

            if ($userId > 0) {
                $stmt = mysqli_prepare($connection, "UPDATE users SET status = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "si", $newStatus, $userId);
                mysqli_stmt_execute($stmt);
                logAudit($connection, $currentUserId, $userId, 'STATUS_CHANGE', "Changed status to $newStatus");
                sFlash('success', "User status updated.");
                header("Location: users.php");
                exit;
            }
        }
    }

    if (isset($_POST['reset_password'])) {

        if (!$canManageUsers) {
            $error = "You are not authorized to reset passwords.";
        } else {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $defaultPassword = "Welcome123@";
            $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

            if ($userId > 0) {
                $stmt = mysqli_prepare(
                    $connection,
                    "UPDATE users
                 SET password = ?, must_change_password = 1, password_changed_at = NULL
                 WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt, "si", $hashedPassword, $userId);
                mysqli_stmt_execute($stmt);
                logAudit($connection, $currentUserId, $userId, 'Password Reset', "Reset password to default");
                sFlash('success', "Password reset successfully. Default password is Welcome123@");
                header("Location: users.php");
                exit;
            }
        }
    }

    if (isset($_POST['update_user'])) {

        $userId = (int) ($_POST['user_id'] ?? 0);

        if (!$canManageUsers) {
            // Staff can only edit their own fullname, phone, and email
            if ($userId !== $currentUserId) {
                $error = "You are not authorized to edit this user.";
            } else {
                $fullname = trim($_POST['fullname'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');

                if ($fullname === '' || $email === '') {
                    $error = "Full name and email are required.";
                } else {
                    $stmt = mysqli_prepare(
                        $connection,
                        "UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, "sssi", $fullname, $email, $phone, $userId);

                    if (mysqli_stmt_execute($stmt)) {
                        // --- START CUSTOM PASSWORD UPDATE ---
                        $newPassword = trim($_POST['password'] ?? '');
                        if ($newPassword !== '') {
                            $hashedNewPwd = password_hash($newPassword, PASSWORD_DEFAULT);
                            $pwdStmt = mysqli_prepare($connection, "UPDATE users SET password = ? WHERE id = ?");
                            mysqli_stmt_bind_param($pwdStmt, "si", $hashedNewPwd, $userId);
                            mysqli_stmt_execute($pwdStmt);
                            logAudit($connection, $currentUserId, $userId, 'PWD_UPDATE', "Password updated by admin");
                        }
                        // --- END CUSTOM PASSWORD UPDATE ---

                        logAudit($connection, $currentUserId, $userId, 'User Update', "Admin updated user profile details");
                        sFlash('success', "User updated successfully.");
                        header("Location: users.php");
                        exit;
                    }
                }
            }
        } else {
            // Existing full-update logic for admin/manager stays exactly as-is
            $fullname = trim($_POST['fullname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $employeeId = trim($_POST['employee_id'] ?? '');
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $designationId = (int) ($_POST['designation_id'] ?? 0);
            $lineManagerId = !empty($_POST['line_manager_id'])
                ? (int) $_POST['line_manager_id']
                : null;
            $role = $_POST['role'] ?? 'staff';
            $status = $_POST['status'] ?? 'active';
            $requiresWeeklyReport = isset($_POST['requires_weekly_report']) ? 1 : 0;

            if ($userId <= 0 || $fullname === '' || $email === '') {
                $error = "Full name and email are required.";
            } elseif ($departmentId <= 0) {
                $error = "Please select a valid department.";
            } else {
                $stmt = mysqli_prepare(
                    $connection,
                    "UPDATE users
        SET employee_id = ?, fullname = ?, email = ?, phone = ?, company = ?, department_id = ?, designation_id = ?, line_manager_id = ?, role = ?, status = ?, requires_weekly_report = ?
        WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "sssssiiissii",
                    $employeeId,
                    $fullname,
                    $email,
                    $phone,
                    $company,
                    $departmentId,
                    $designationId,
                    $lineManagerId,
                    $role,
                    $status,
                    $requiresWeeklyReport,
                    $userId
                );

                if (mysqli_stmt_execute($stmt)) {
                    logAudit($connection, $currentUserId, $userId, 'User Update', "Admin updated user profile details");
                    sFlash('success', "User updated successfully.");
                    header("Location: users.php");
                    exit;
                } else {

                    $error = "Unable to update user.";
                }
            }
        }
    }

    if (isset($_POST['delete_user'])) {

        if (!$canManageUsers) {
            $error = "You are not authorized to delete users.";
        } else {
            $userId = (int) ($_POST['user_id'] ?? 0);

            if ($userId > 0) {
                $check = mysqli_query(
                    $connection,
                    "SELECT COUNT(*) AS total FROM weekly_reports WHERE user_id = {$userId}"
                );
                $row = mysqli_fetch_assoc($check);
                $hasReports = (int) ($row['total'] ?? 0);

                if ($hasReports > 0) {
                    $error = "This user already has reports. Disable the user instead of deleting.";
                } else {
                    mysqli_query($connection, "DELETE FROM users WHERE id = {$userId}");
                    logAudit($connection, $currentUserId, $userId, 'USER_DELETE', "Permanently deleted user");
                    sFlash('success', "User deleted successfully.");
                    header("Location: users.php");
                    exit;
                }
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$filterCompany = trim($_GET['company'] ?? '');
$filterDepartment = (int) ($_GET['department_id'] ?? 0);
$filterDesignation = (int) ($_GET['designation_id'] ?? 0);

$whereParts = [];

if (!$canManageUsers) {
    $whereParts[] = "u.id = {$currentUserId}";
}

if ($search !== '') {
    $safeSearch = mysqli_real_escape_string($connection, $search);
    $whereParts[] = "(
        u.fullname LIKE '%{$safeSearch}%'
        OR u.email LIKE '%{$safeSearch}%'
        OR u.employee_id LIKE '%{$safeSearch}%'
        OR d.department_name LIKE '%{$safeSearch}%'
        OR g.designation_name LIKE '%{$safeSearch}%'
    )";
}

if ($filterCompany !== '') {
    $safeCompany = mysqli_real_escape_string($connection, $filterCompany);
    $whereParts[] = "u.company = '{$safeCompany}'";
}

if ($filterDepartment > 0) {
    $whereParts[] = "u.department_id = {$filterDepartment}";
}

if ($filterDesignation > 0) {
    $whereParts[] = "u.designation_id = {$filterDesignation}";
}

$where = "";

if (!empty($whereParts)) {
    $where = "WHERE " . implode(" AND ", $whereParts);
}

$users = mysqli_query(
    $connection,
    "SELECT
        u.*,
        d.department_name,
        g.designation_name,
        lm.fullname AS line_manager_name,
        GREATEST(u.created_at, COALESCE(u.updated_at, u.created_at)) as last_activity,
        COALESCE(r.total_reports, 0) AS total_reports,
        COALESCE(r.pending_reports, 0) AS pending_reports,
        COALESCE(r.avg_kpi_score, 0) AS avg_kpi_score
    FROM users u
    LEFT JOIN departments d ON d.id=u.department_id
    LEFT JOIN designations g ON g.id=u.designation_id
    LEFT JOIN users lm ON lm.id=u.line_manager_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS total_reports,
        SUM(CASE WHEN status IN ('draft', 'submitted', 'reviewed') THEN 1 ELSE 0 END) AS pending_reports,
        AVG(total_kpi_score) AS avg_kpi_score
        FROM weekly_reports GROUP BY user_id
    ) r ON r.user_id = u.id
    {$where}
    ORDER BY last_activity DESC"
);


$departments = mysqli_query($connection, "SELECT id, department_name FROM departments WHERE status = 'active' ORDER BY department_name");
$designations = mysqli_query($connection, "SELECT id, designation_name FROM designations ORDER BY designation_name");

$managers = mysqli_query(
    $connection,
    "SELECT
        u.id,
        u.fullname,
        u.employee_id,
        u.role,
        d.department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'active'
     ORDER BY u.fullname"
);


/* =========================================================
   PAGE MODE: LIST / ADD / EDIT
========================================================= */
$viewMode = 'list';
$editUser = null;

if (!$canManageUsers) {
    $viewMode = 'edit';
    $editId = $currentUserId;
} elseif (($_GET['action'] ?? '') === 'add') {
    $viewMode = 'add';
} elseif (!empty($_GET['edit'])) {
    $viewMode = 'edit';
    $editId = (int) $_GET['edit'];
}

if ($viewMode === 'edit') {
    $editId = (int) ($editId ?? 0);
    if ($editId > 0) {
        if (!$canManageUsers && $editId !== $currentUserId) {
            $editId = $currentUserId;
        }

        $editRes = mysqli_query(
            $connection,
            "SELECT
                u.*,
                d.department_name,
                g.designation_name,
                lm.fullname AS line_manager_name,
                COALESCE(r.total_reports,0) AS total_reports,
                COALESCE(r.pending_reports,0) AS pending_reports,
                COALESCE(r.avg_kpi_score,0) AS avg_kpi_score
             FROM users u
             LEFT JOIN departments d ON d.id=u.department_id
             LEFT JOIN designations g ON g.id=u.designation_id
             LEFT JOIN users lm ON lm.id=u.line_manager_id
             LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS total_reports,
                    SUM(CASE WHEN status IN ('draft','submitted','reviewed') THEN 1 ELSE 0 END) AS pending_reports,
                    AVG(total_kpi_score) AS avg_kpi_score
                FROM weekly_reports
                GROUP BY user_id
             ) r ON r.user_id=u.id
             WHERE u.id={$editId}
             LIMIT 1"
        );

        if ($editRes) {
            $editUser = mysqli_fetch_assoc($editRes);
        }

        if (!$editUser) {
            $error = "User not found.";
            $viewMode = 'list';
        }
    } else {
        $viewMode = 'list';
    }
}

if ($viewMode === 'edit' && isset($editId)) {
    // Only get logs for the user being edited
    $auditLogs = mysqli_query($connection, "SELECT a.*, u.fullname as admin_name FROM user_audit_log a JOIN users u ON a.admin_id = u.id WHERE a.target_user_id = " . (int)$editId . " ORDER BY a.created_at DESC LIMIT 50");
} else {
    // Get general logs for the list view
    $auditLogs = mysqli_query($connection, "SELECT a.*, u.fullname as admin_name FROM user_audit_log a JOIN users u ON a.admin_id = u.id ORDER BY a.created_at DESC LIMIT 50");
}

include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/users.css?v=1">

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="users-toast users-toast-<?= $_SESSION['flash_type'] ?>" id="usersToast">
        <?= h($_SESSION['flash_text']) ?>
    </div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>


<div class="users-page">

    <?php if ($viewMode === 'list'): ?>
        <div class="users-page-head">
            <div>
                <div class="users-eyebrow">Administration</div>
                <h1>User Management</h1>
                <p>Manage employees, reporting access, account roles and employment status.</p>
            </div>

            <?php if ($canManageUsers): ?>
                <div class="users-page-actions">
                    <?php if ($currentRole === 'admin'): ?>
                        <a href="sync_erpnext_users.php" class="users-btn users-btn-light" onclick="showSyncProgress()">
                            <i class="fa-solid fa-rotate"></i> Sync ERPNext
                        </a>
                    <?php endif; ?>

                    <a href="users.php?action=add" class="users-btn users-btn-primary">
                        <i class="fa-solid fa-user-plus"></i> Add User
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="users-card">
            <div class="users-card-head">
                <div>
                    <h2>Staff Directory</h2>
                    <span><?= $users ? number_format(mysqli_num_rows($users)) : 0 ?> user(s)</span>
                </div>
            </div>

            <form method="GET" class="users-filters">
                <div class="users-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" placeholder="Search name, email or employee ID"
                        value="<?= h($search) ?>">
                </div>

                <select name="company">
                    <option value="">All Companies</option>
                    <option value="Diva Cakes" <?= $filterCompany === 'Diva Cakes' ? 'selected' : '' ?>>Diva Cakes</option>
                    <option value="CRAVE CITY MEGA LIMITED"
                        <?= $filterCompany === 'CRAVE CITY MEGA LIMITED' ? 'selected' : '' ?>>Crave City Mega Limited
                    </option>
                </select>

                <select name="department_id">
                    <option value="0">All Departments</option>
                    <?php mysqli_data_seek($departments, 0); ?>
                    <?php while ($d = mysqli_fetch_assoc($departments)): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $filterDepartment === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= h($d['department_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="designation_id">
                    <option value="0">All Designations</option>
                    <?php mysqli_data_seek($designations, 0); ?>
                    <?php while ($g = mysqli_fetch_assoc($designations)): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= $filterDesignation === (int)$g['id'] ? 'selected' : '' ?>>
                            <?= h($g['designation_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <button type="submit" class="users-btn users-btn-light">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>

                <a href="users.php" class="users-btn users-btn-ghost">Reset</a>
            </form>

            <div class="users-table-wrap">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th class="users-sn">S/N</th>
                            <th>Employee</th>
                            <th>Company</th>
                            <!-- <th>Email</th> -->
                            <th>Department</th>
                            <!-- <th>Designation</th> -->
                            <th>Modified</th>
                            <!-- <th>Status</th> -->
                            <!-- <th></th> -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users && mysqli_num_rows($users) > 0): ?>
                            <?php $sn = 1; ?>
                            <?php while ($u = mysqli_fetch_assoc($users)): ?>
                                <?php $status = strtolower($u['status'] ?? 'inactive'); ?>
                                <tr class="users-clickable-row"
                                    onclick="window.location.href='users.php?edit=<?= (int)$u['id'] ?>'">
                                    <td class="users-sn"><?= $sn++ ?></td>
                                    <td>
                                        <div class="users-person">
                                            <div class="users-avatar"><?= h(strtoupper(substr(trim($u['fullname']), 0, 1))) ?></div>
                                            <div>
                                                <strong><?= h($u['fullname']) ?></strong>
                                                <small><?= h($u['employee_id'] ?: 'No employee ID') ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= h($u['company'] ?? 'N/A') ?></td>
                                    <!-- <td><?= h($u['email']) ?></td> -->
                                    <td><?= h($u['department_name'] ?? 'N/A') ?></td>
                                    <!-- <td><?= h($u['designation_name'] ?? 'N/A') ?></td> -->
                                    <td style="color: var(--u-muted); font-size: 10px;"><?= h(timeAgo($u['last_activity'])) ?></td>
                                    <!-- <td><span class="users-badge users-role"><?= h(ucfirst($u['role'])) ?></span></td> -->
                                    <!-- <td><span class="users-badge users-status-<?= h($status) ?>"><?= h(ucfirst($status)) ?></span> -->
                                    </td>

                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="users-empty">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($viewMode === 'add' && $canManageUsers): ?>

        <div class="users-page-head">
            <div>
                <div class="users-eyebrow">User Management</div>
                <h1>Create User</h1>
                <p>Create a new employee account and assign organisational access.</p>
            </div>
            <a href="users.php" class="users-btn users-btn-light">
                <i class="fa-solid fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <div class="users-form-card">
            <div class="users-section-head">
                <div>
                    <h2>Account Information</h2>
                    <p>Basic identity and company information.</p>
                </div>
            </div>

            <form method="POST">
                <div class="users-form-grid">
                    <div class="users-field">
                        <label>Employee ID</label>
                        <input type="text" name="employee_id" placeholder="EMP001">
                    </div>

                    <div class="users-field">
                        <label>Full Name <span>*</span></label>
                        <input type="text" name="fullname" required>
                    </div>

                    <div class="users-field">
                        <label>Email Address <span>*</span></label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="users-field">
                        <label>Phone</label>
                        <input type="text" name="phone">
                    </div>
                    <div class="users-field">
                        <label>Password (Optional)</label>
                        <input type="password" name="password" placeholder="Leave blank for default">
                    </div>
                    <div class="users-field">
                        <label>Change Password</label>
                        <input type="password" name="password" placeholder="Leave blank to keep current">
                    </div>

                    <div class="users-field">
                        <label>Company</label>
                        <input type="text" name="company" placeholder="Company">
                    </div>

                    <div class="users-field">
                        <label>Department</label>
                        <select name="department_id">
                            <option value="0">Select Department</option>
                            <?php mysqli_data_seek($departments, 0); ?>
                            <?php while ($d = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= h($d['department_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Designation</label>
                        <select name="designation_id">
                            <option value="0">Select Designation</option>
                            <?php mysqli_data_seek($designations, 0); ?>
                            <?php while ($g = mysqli_fetch_assoc($designations)): ?>
                                <option value="<?= (int)$g['id'] ?>"><?= h($g['designation_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Line Manager / Reports To</label>
                        <select name="line_manager_id">
                            <option value="">Select Line Manager</option>
                            <?php mysqli_data_seek($managers, 0); ?>
                            <?php while ($m = mysqli_fetch_assoc($managers)): ?>
                                <option value="<?= (int)$m['id'] ?>"><?= h($m['fullname']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="users-section-divider"></div>

                <div class="users-section-head">
                    <div>
                        <h2>Access & Reporting</h2>
                        <p>Define the user's system role and reporting responsibilities.</p>
                    </div>
                </div>

                <div class="users-form-grid users-form-grid-3">
                    <div class="users-field">
                        <label>Role</label>
                        <select name="role">
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <label class="users-check-card">
                        <input type="checkbox" name="requires_weekly_report" value="1">
                        <span>
                            <strong>Requires Weekly Report</strong>
                            <small>Include this employee in the weekly reporting workflow.</small>
                        </span>
                    </label>
                </div>

                <div class="users-form-actions">
                    <button type="submit" name="save_user" class="users-btn users-btn-primary">
                        <i class="fa-solid fa-user-plus"></i> Create User
                    </button>
                    <a href="users.php" class="users-btn users-btn-light">Cancel</a>
                </div>

                <div class="users-info-box">
                    <i class="fa-solid fa-key"></i>
                    <div>
                        <strong>Initial password</strong>
                        <span>New users receive <b>Welcome123@</b> and must change it on first login.</span>
                    </div>
                </div>
            </form>
        </div>

        <div class="users-form-card users-bulk-card">
            <div class="users-section-head users-section-head-inline">
                <div>
                    <h2>Bulk User Import</h2>
                    <p>Upload multiple employee accounts using the standard CSV template.</p>
                </div>
                <a href="download_user_template.php" class="users-btn users-btn-light">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <form method="POST" enctype="multipart/form-data" class="users-upload-row">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" name="bulk_upload" class="users-btn users-btn-primary">
                    <i class="fa-solid fa-upload"></i> Upload CSV
                </button>
            </form>
        </div>

    <?php elseif ($viewMode === 'edit' && $editUser): ?>
        <div class="users-page-head">
            <div>
                <div class="users-eyebrow"><?= $canManageUsers ? 'User Management' : 'My Account' ?></div>
                <h1><?= h($editUser['fullname']) ?></h1>
                <p>
                    <?= h($editUser['employee_id'] ?? '') ?>
                    <?php if (!empty($editUser['department_name'])): ?> ·
                        <?= h($editUser['department_name']) ?><?php endif; ?>
                        <?php if (!empty($editUser['designation_name'])): ?> ·
                            <?= h($editUser['designation_name']) ?><?php endif; ?>
                </p>
            </div>
            <?php if ($canManageUsers): ?>
                <a href="users.php" class="users-btn users-btn-light">
                    <i class="fa-solid fa-arrow-left"></i> Back to Users
                </a>
            <?php endif; ?>
        </div>

        <div class="users-summary-grid">
            <div class="users-summary-card">
                <span>Reports Submitted</span>
                <strong><?= number_format((int)$editUser['total_reports']) ?></strong>
            </div>
            <div class="users-summary-card">
                <span>Pending Reports</span>
                <strong><?= number_format((int)$editUser['pending_reports']) ?></strong>
            </div>
            <div class="users-summary-card">
                <span>Average KPI Score</span>
                <strong><?= number_format((float)$editUser['avg_kpi_score'], 2) ?>%</strong>
            </div>
            <div class="users-summary-card">
                <span>Account Status</span>
                <strong class="users-summary-status"><?= h(ucfirst($editUser['status'])) ?></strong>
            </div>
        </div>

        <div class="users-form-card">
            <div class="users-section-head">
                <div>
                    <h2>Profile & Employment</h2>
                    <p>Update identity, organisational assignment and contact information.</p>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">

                <div class="users-form-grid">
                    <div class="users-field">
                        <label>Employee ID</label>
                        <input type="text" name="employee_id" value="<?= h($editUser['employee_id']) ?>"
                            <?= $canManageUsers ? '' : 'readonly' ?>>
                    </div>

                    <div class="users-field">
                        <label>Full Name <span>*</span></label>
                        <input type="text" name="fullname" value="<?= h($editUser['fullname']) ?>" required>
                    </div>

                    <div class="users-field">
                        <label>Email Address <span>*</span></label>
                        <input type="email" name="email" value="<?= h($editUser['email']) ?>" required>
                    </div>

                    <div class="users-field">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?= h($editUser['phone']) ?>">
                    </div>

                    <div class="users-field">
                        <label>Company</label>
                        <input type="text" name="company" value="<?= h($editUser['company'] ?? '') ?>"
                            <?= $canManageUsers ? '' : 'readonly' ?>>
                    </div>

                    <div class="users-field">
                        <label>Department</label>
                        <select name="department_id" <?= $canManageUsers ? '' : 'disabled' ?>>
                            <option value="0">Select Department</option>
                            <?php mysqli_data_seek($departments, 0); ?>
                            <?php while ($d = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?= (int)$d['id'] ?>"
                                    <?= (int)$editUser['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['department_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Designation</label>
                        <select name="designation_id" <?= $canManageUsers ? '' : 'disabled' ?>>
                            <option value="0">Select Designation</option>
                            <?php mysqli_data_seek($designations, 0); ?>
                            <?php while ($g = mysqli_fetch_assoc($designations)): ?>
                                <option value="<?= (int)$g['id'] ?>"
                                    <?= (int)$editUser['designation_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                    <?= h($g['designation_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Line Manager / Reports To</label>
                        <select name="line_manager_id" <?= $canManageUsers ? '' : 'disabled' ?>>
                            <option value="">Select Line Manager</option>
                            <?php mysqli_data_seek($managers, 0); ?>
                            <?php while ($m = mysqli_fetch_assoc($managers)): ?>
                                <option value="<?= (int)$m['id'] ?>"
                                    <?= (int)($editUser['line_manager_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                    <?= h($m['fullname']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="users-section-divider"></div>

                <div class="users-section-head">
                    <div>
                        <h2>Access & Reporting</h2>
                        <p>Manage account access and weekly reporting responsibility.</p>
                    </div>
                </div>

                <div class="users-form-grid users-form-grid-3">
                    <div class="users-field">
                        <label>Role</label>
                        <select name="role" <?= $canManageUsers ? '' : 'disabled' ?>>
                            <option value="staff" <?= $editUser['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="manager" <?= $editUser['role'] === 'manager' ? 'selected' : '' ?>>Manager
                            </option>
                            <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div class="users-field">
                        <label>Status</label>
                        <select name="status" <?= $canManageUsers ? '' : 'disabled' ?>>
                            <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $editUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive
                            </option>
                            <option value="suspended" <?= $editUser['status'] === 'suspended' ? 'selected' : '' ?>>Suspended
                            </option>
                        </select>
                    </div>

                    <label class="users-check-card">
                        <input type="checkbox" name="requires_weekly_report" value="1"
                            <?= (int)($editUser['requires_weekly_report'] ?? 0) === 1 ? 'checked' : '' ?>
                            <?= $canManageUsers ? '' : 'disabled' ?>>
                        <span>
                            <strong>Requires Weekly Report</strong>
                            <small>Employee participates in weekly reporting.</small>
                        </span>
                    </label>
                </div>

                <div class="users-form-actions">
                    <button type="submit" name="update_user" class="users-btn users-btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $canManageUsers ? 'Save Changes' : 'Save Profile' ?>
                    </button>

                    <?php if ($canManageUsers): ?>
                        <button type="submit" name="reset_password" class="users-btn users-btn-light"
                            onclick="return confirm('Reset this user password to Welcome123@?');">
                            <i class="fa-solid fa-key"></i> Reset Password
                        </button>

                        <button type="submit" name="delete_user" class="users-btn users-btn-danger"
                            onclick="return confirm('Delete this user? This is only allowed if the user has no reports.');">
                            <i class="fa-solid fa-trash"></i> Delete User
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php if ($canManageUsers): ?>
            <div class="audit-section">
                <div class="audit-title">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Activity History for <?= h($editUser['fullname']) ?>
                </div>
                <div class="audit-timeline">
                    <?php if ($auditLogs && mysqli_num_rows($auditLogs) > 0): ?>
                        <?php while ($log = mysqli_fetch_assoc($auditLogs)): ?>
                            <div class="audit-item">
                                <div class="audit-meta">
                                    <span><span class="audit-admin"><?= h($log['admin_name']) ?></span> performed <span
                                            class="audit-action-badge badge-<?= h($log['action']) ?>"><?= h($log['action']) ?></span></span>
                                    <span><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div class="audit-details">
                                    <?= h($log['details']) ?>
                                    <small style="display:block; color:#94a3b8; margin-top:5px;">IP:
                                        <?= h($log['ip_address']) ?></small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="users-empty">No activity logs found for this user.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<div id="syncOverlay" class="users-sync-overlay">
    <div class="users-sync-card">
        <i class="fa-solid fa-cloud-arrow-down"></i>
        <h3>Syncing ERPNext Users</h3>
        <p>Please wait while employee records are fetched and updated.</p>
        <div class="users-progress"><span></span></div>
        <small>Connecting securely to ERPNext…</small>
    </div>
</div>

<script>
    function showSyncProgress() {
        document.getElementById('syncOverlay')?.classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const toast = document.getElementById('usersToast');
        if (toast) {
            setTimeout(() => toast.classList.add('fade-out'), 3500);
            setTimeout(() => toast.remove(), 4100);
        }
    });
</script>

<?php include '../inc/footer.php'; ?>