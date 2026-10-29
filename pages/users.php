<?php
require '../inc/auth.php';
require '../inc/connection.php';
mysqli_report(MYSQLI_REPORT_OFF);

$pageTitle = "User Management";
$error = "";

define('DEFAULT_PASSWORD', 'Welcome123@');
define('MIN_PASSWORD_LENGTH', 8);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = strtolower($_SESSION['role'] ?? 'staff');
$canManageUsers = in_array($currentRole, ['admin', 'manager'], true);

$allowedRoles = ['staff', 'manager', 'admin'];
$allowedStatuses = ['active', 'inactive', 'suspended'];

/* =========================================================
   HELPERS
========================================================= */
function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function sFlash($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

/* "just now", "3 minutes ago", "1 hour ago", "2 days ago" ... */
function timeAgo($timestamp)
{
    if (!$timestamp) return '—';
    $time = strtotime($timestamp);
    if (!$time) return '—';

    $diff = time() - $time;
    if ($diff < 60) return 'just now';

    $units = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    foreach ($units as [$name, $seconds]) {
        if ($diff >= $seconds) {
            $n = (int) floor($diff / $seconds);
            return $n . ' ' . $name . ($n > 1 ? 's' : '') . ' ago';
        }
    }

    return 'just now';
}

/* ---------- Audit logging ---------- */
function logAudit($connection, $adminId, $targetId, $action, $details)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = mysqli_prepare(
        $connection,
        "INSERT INTO user_audit_log (admin_id, target_user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, "iisss", $adminId, $targetId, $action, $details, $ip);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* One audit row per changed field, stored as JSON so the trail can show old -> new. */
function logFieldChange($connection, $adminId, $targetId, $field, $old, $new)
{
    $details = json_encode([
        'field' => $field,
        'old' => mb_substr((string) $old, 0, 120),
        'new' => mb_substr((string) $new, 0, 120),
    ], JSON_UNESCAPED_UNICODE);

    logAudit($connection, $adminId, $targetId, 'FIELD_CHANGE', $details);
}

function logChangeList($connection, $adminId, $targetId, array $changes)
{
    foreach ($changes as [$field, $old, $new]) {
        logFieldChange($connection, $adminId, $targetId, $field, $old, $new);
    }
}

/* Returns [[label, old, new], ...] for every pair whose value actually changed. */
function diffFields(array $pairs)
{
    $changes = [];
    foreach ($pairs as $label => [$old, $new]) {
        $old = trim((string) $old);
        $new = trim((string) $new);
        if ($old !== $new) {
            $changes[] = [$label, $old, $new];
        }
    }
    return $changes;
}

/* ---------- Activity trail ---------- */

/*
   Turns raw log rows into "events". Rows written by the same person for the same user
   within 2 seconds (one Save click) are merged into a single event.
*/
function buildAuditEvents($res)
{
    $events = [];
    if (!$res) return $events;

    while ($log = mysqli_fetch_assoc($res)) {

        $adminId = (int) $log['admin_id'];
        $targetId = (int) $log['target_user_id'];
        $last = count($events) - 1;

        $merge = $last >= 0
            && $events[$last]['admin_id'] === $adminId
            && $events[$last]['target_id'] === $targetId
            && abs(strtotime($events[$last]['oldest_at']) - strtotime($log['created_at'])) <= 2;

        if (!$merge) {
            $events[] = [
                'admin_id' => $adminId,
                'admin_name' => $log['admin_name'],
                'target_id' => $targetId,
                'created_at' => $log['created_at'],
                'oldest_at' => $log['created_at'],
                'ip' => $log['ip_address'],
                'items' => [],
            ];
            $last = count($events) - 1;
        } else {
            $events[$last]['oldest_at'] = $log['created_at'];
        }

        $action = (string) $log['action'];
        $details = (string) ($log['details'] ?? '');

        switch ($action) {

            case 'FIELD_CHANGE':
                $d = json_decode($details, true);
                if (is_array($d) && isset($d['field'])) {
                    $events[$last]['items'][] = [
                        'type' => 'field',
                        'field' => (string) $d['field'],
                        'old' => (string) ($d['old'] ?? ''),
                        'new' => (string) ($d['new'] ?? ''),
                    ];
                } else {
                    $events[$last]['items'][] = ['type' => 'text', 'text' => 'made a change'];
                }
                break;

            case 'PASSWORD_CHANGE':
                $events[$last]['items'][] = [
                    'type' => 'password',
                    'note' => str_contains($details, 'require_change') ? 'require_change' : '',
                ];
                break;

            case 'PASSWORD_RESET':
            case 'Password Reset':
                $events[$last]['items'][] = ['type' => 'password', 'note' => 'reset'];
                break;

            case 'USER_CREATE':
                $events[$last]['items'][] = [
                    'type' => 'create',
                    'bulk' => str_contains($details, 'bulk upload'),
                ];
                break;

            case 'USER_DELETE':
                $events[$last]['items'][] = ['type' => 'delete'];
                break;

            case 'User Update':
                $events[$last]['items'][] = ['type' => 'text', 'text' => 'updated the profile details'];
                break;

            case 'STATUS_CHANGE':
                $events[$last]['items'][] = [
                    'type' => 'text',
                    'text' => $details !== '' ? lcfirst($details) : 'changed the status',
                ];
                break;

            default:
                $events[$last]['items'][] = [
                    'type' => 'text',
                    'text' => 'performed ' . $action . ($details !== '' ? ' — ' . $details : ''),
                ];
        }
    }

    // Rows arrive newest-first; show the changes inside one event in the order they were saved.
    foreach ($events as &$ev) {
        $ev['items'] = array_reverse($ev['items']);
    }
    unset($ev);

    return $events;
}

function auditDayLabel($timestamp)
{
    $time = strtotime($timestamp);
    $day = date('Y-m-d', $time);

    if ($day === date('Y-m-d')) return 'Today';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';

    return date('l, M j, Y', $time);
}

function renderAuditEvent(array $ev)
{
    $self = ($ev['admin_id'] === $ev['target_id']);
    $who  = '<strong>' . h($ev['admin_name']) . '</strong>';

    $val = function ($v) {
        return '<span class="trail-v">&ldquo;' . h($v) . '&rdquo;</span>';
    };

    $fieldParts = [];
    $text = '';
    $passwordText = '';

    foreach ($ev['items'] as $it) {
        switch ($it['type']) {
            case 'field':
                $f = '<strong>' . h($it['field']) . '</strong>';
                if ($it['old'] === '') {
                    $fieldParts[] = $f . ' to ' . $val($it['new']);
                } elseif ($it['new'] === '') {
                    $fieldParts[] = $f . ' to <em>empty</em>';
                } else {
                    $fieldParts[] = $f . ' from ' . $val($it['old']) . ' to ' . $val($it['new']);
                }
                break;

            case 'password':
                $passwordText = (($it['note'] ?? '') === 'reset')
                    ? 'reset the password to the default'
                    : 'changed ' . ($self ? 'their own' : 'the') . ' password';
                break;

            case 'create':
                $text = 'created this user' . (!empty($it['bulk']) ? ' via bulk upload' : '');
                break;

            case 'delete':
                $text = 'deleted this user';
                break;

            case 'text':
                $text = h($it['text']);
                break;
        }
    }

    if ($text === '') {
        $text = $fieldParts ? 'changed ' . implode(', ', $fieldParts) : '';
        if ($passwordText !== '') {
            $text .= ($text !== '' ? ' and ' : '') . $passwordText;
        }
    }

    $exact = date('M d, Y h:i:s A', strtotime($ev['created_at'])) . ' · IP ' . ($ev['ip'] ?: 'unknown');

    return '<div class="trail-line">'
        . $who . ' ' . $text
        . '<span class="trail-time" title="' . h($exact) . '">· ' . h(timeAgo($ev['created_at'])) . '</span>'
        . '</div>';
}

/* ---------- Lookups ---------- */
function lookupName($connection, $table, $id)
{
    $map = [
        'departments' => 'department_name',
        'designations' => 'designation_name',
        'users' => 'fullname',
    ];
    $id = (int) $id;
    if (!isset($map[$table]) || $id <= 0) return '';

    $col = $map[$table];
    $res = mysqli_query($connection, "SELECT {$col} AS n FROM {$table} WHERE id = {$id} LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row['n'] ?? '';
}

function fetchUserSnapshot($connection, $id)
{
    $id = (int) $id;
    $res = mysqli_query(
        $connection,
        "SELECT u.*, d.department_name, g.designation_name, lm.fullname AS line_manager_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN designations g ON g.id = u.designation_id
         LEFT JOIN users lm ON lm.id = u.line_manager_id
         WHERE u.id = {$id}
         LIMIT 1"
    );
    return $res ? mysqli_fetch_assoc($res) : null;
}

/* A manager must not be able to change an admin's password. */
function canModifyUser($connection, $currentRole, $targetId)
{
    if ($currentRole === 'admin') return true;

    $targetId = (int) $targetId;
    $res = mysqli_query($connection, "SELECT role FROM users WHERE id = {$targetId} LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) return false;

    return strtolower($row['role']) !== 'admin';
}

function validateNewPassword($password)
{
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return "Password must be at least " . MIN_PASSWORD_LENGTH . " characters.";
    }
    return '';
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

/* =========================================================
   POST HANDLERS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Staff may only submit their own profile update; everything else needs admin/manager.
    $isSelfUpdate = isset($_POST['update_user']) && !$canManageUsers;

    if (!$canManageUsers && !$isSelfUpdate) {
        $error = "You are not authorized to perform this action.";

        /* ---------------- CREATE USER ---------------- */
    } elseif (isset($_POST['save_user'])) {

        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $designationId = (int) ($_POST['designation_id'] ?? 0);
        $lineManagerId = !empty($_POST['line_manager_id']) ? (int) $_POST['line_manager_id'] : null;
        $role = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'staff';
        $status = in_array($_POST['status'] ?? '', $allowedStatuses, true) ? $_POST['status'] : 'active';
        $requiresWeeklyReport = isset($_POST['requires_weekly_report']) ? 1 : 0;

        $customPassword = (string) ($_POST['new_password'] ?? '');
        $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

        if ($fullname === '' || $email === '') {
            $error = "Full name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif ($customPassword !== '' && ($pwError = validateNewPassword($customPassword)) !== '') {
            $error = $pwError;
        } else {

            $usingDefault = ($customPassword === '');
            $plainPassword = $usingDefault ? DEFAULT_PASSWORD : $customPassword;

            // The shared default password must always be changed at first login.
            if ($usingDefault) {
                $mustChange = 1;
            }

            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO users (employee_id, fullname, email, phone, company, password, department_id, designation_id, line_manager_id, role, status, requires_weekly_report, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssiiissii",
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
                $requiresWeeklyReport,
                $mustChange
            );

            if (mysqli_stmt_execute($stmt)) {
                logAudit($connection, $currentUserId, mysqli_insert_id($connection), 'USER_CREATE', "Created user $fullname");

                sFlash(
                    'success',
                    $usingDefault
                        ? "User created successfully. Default password is " . DEFAULT_PASSWORD
                        : "User created successfully with the custom password you set."
                );
                header("Location: users.php");
                exit;
            } else {
                $dbError = strtolower(mysqli_error($connection));

                if (str_contains($dbError, 'employee_id')) {
                    $error = "A user with this Employee ID already exists.";
                } elseif (str_contains($dbError, 'email')) {
                    $error = "A user with this email address already exists.";
                } else {
                    $error = "Unable to create user. Please try again.";
                }
            }
        }

        /* ---------------- BULK UPLOAD ---------------- */
    } elseif (isset($_POST['bulk_upload'])) {

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please upload a valid CSV file.";
        } else {

            $handle = fopen($_FILES['csv_file']['tmp_name'], "r");

            if ($handle === false) {
                $error = "Unable to read uploaded file.";
            } else {

                $inserted = 0;
                $skipped = 0;
                $hashedPassword = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);

                $headers = fgetcsv($handle, 10000, ",");

                if (!$headers) {
                    $error = "CSV file is empty or invalid.";
                    fclose($handle);
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

                    if ($error !== "") {
                        fclose($handle);
                    } else {

                        while (($data = fgetcsv($handle, 10000, ",")) !== false) {

                            $employeeId = trim($data[$headerMap["employee_id"]] ?? "");
                            $fullname = trim($data[$headerMap["fullname"]] ?? "");
                            $email = trim($data[$headerMap["email"]] ?? "");
                            $phone = trim($data[$headerMap["phone"]] ?? "");
                            $company = trim($data[$headerMap["company"]] ?? "");
                            $requiresWeeklyReportRaw = strtolower(trim($data[$headerMap["requires_weekly_report"]] ?? "0"));
                            $requiresWeeklyReport = in_array($requiresWeeklyReportRaw, ["1", "yes", "true", "required"], true) ? 1 : 0;
                            $departmentName = trim($data[$headerMap["department"]] ?? "");
                            $designationName = trim($data[$headerMap["designation"]] ?? "");
                            $lineManagerEmail = strtolower(trim($data[$headerMap["line_manager_email"]] ?? ""));
                            $role = strtolower(trim($data[$headerMap["role"]] ?? "staff"));
                            $status = strtolower(trim($data[$headerMap["status"]] ?? "active"));

                            if ($fullname === "" || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $skipped++;
                                continue;
                            }

                            if (!in_array($role, $allowedRoles, true)) {
                                $role = "staff";
                            }
                            if (!in_array($status, $allowedStatuses, true)) {
                                $status = "active";
                            }

                            $departmentId = getOrCreateDepartment($connection, $departmentName);
                            $designationId = getOrCreateDesignation($connection, $designationName);

                            $lineManagerId = null;
                            if ($lineManagerEmail !== "") {
                                $stmtMgr = mysqli_prepare(
                                    $connection,
                                    "SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1"
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
                                "INSERT INTO users (employee_id, fullname, email, phone, company, password, department_id, designation_id, line_manager_id, role, status, requires_weekly_report, must_change_password)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
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
                                $newId = mysqli_insert_id($connection);
                                logAudit($connection, $currentUserId, $newId, 'USER_CREATE', "Created user $fullname via bulk upload");
                                $inserted++;
                            } else {
                                $skipped++;
                            }
                        }

                        fclose($handle);
                        sFlash('success', "Bulk upload completed. Inserted: {$inserted}. Skipped: {$skipped}.");
                        header("Location: users.php");
                        exit;
                    }
                }
            }
        }

        /* ---------------- TOGGLE STATUS ---------------- */
    } elseif (isset($_POST['toggle_status'])) {

        $userId = (int) ($_POST['user_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', $allowedStatuses, true) ? $_POST['new_status'] : 'inactive';

        if ($userId > 0) {
            $old = fetchUserSnapshot($connection, $userId);

            $stmt = mysqli_prepare($connection, "UPDATE users SET status = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $newStatus, $userId);

            if (mysqli_stmt_execute($stmt)) {
                logFieldChange(
                    $connection,
                    $currentUserId,
                    $userId,
                    'Status',
                    ucfirst(strtolower($old['status'] ?? '')),
                    ucfirst($newStatus)
                );
                sFlash('success', "User status updated.");
                header("Location: users.php");
                exit;
            }
        }

        /* ---------------- UPDATE USER (profile + optional new password, one save) ---------------- */
    } elseif (isset($_POST['update_user'])) {

        $userId = (int) ($_POST['user_id'] ?? 0);

        if (!$canManageUsers) {
            // Staff can only edit their own name, email and phone
            if ($userId !== $currentUserId) {
                $error = "You are not authorized to edit this user.";
            } else {
                $fullname = trim($_POST['fullname'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');

                if ($fullname === '' || $email === '') {
                    $error = "Full name and email are required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Please enter a valid email address.";
                } else {
                    $old = fetchUserSnapshot($connection, $userId);

                    $stmt = mysqli_prepare(
                        $connection,
                        "UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, "sssi", $fullname, $email, $phone, $userId);

                    if (mysqli_stmt_execute($stmt)) {
                        if ($old) {
                            logChangeList($connection, $currentUserId, $userId, diffFields([
                                'Full Name' => [$old['fullname'], $fullname],
                                'Email' => [$old['email'], $email],
                                'Phone' => [$old['phone'], $phone],
                            ]));
                        }
                        sFlash('success', "Profile updated successfully.");
                        header("Location: users.php");
                        exit;
                    } else {
                        $error = str_contains(strtolower(mysqli_error($connection)), 'email')
                            ? "That email address is already in use."
                            : "Unable to update profile.";
                    }
                }
            }
        } else {
            $fullname = trim($_POST['fullname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $employeeId = trim($_POST['employee_id'] ?? '');
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $designationId = (int) ($_POST['designation_id'] ?? 0);
            $lineManagerId = !empty($_POST['line_manager_id']) ? (int) $_POST['line_manager_id'] : null;
            $role = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'staff';
            $status = in_array($_POST['status'] ?? '', $allowedStatuses, true) ? $_POST['status'] : 'active';
            $requiresWeeklyReport = isset($_POST['requires_weekly_report']) ? 1 : 0;

            // Optional: only changes the password when something is typed in the field
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

            if ($userId <= 0 || $fullname === '' || $email === '') {
                $error = "Full name and email are required.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif ($departmentId <= 0) {
                $error = "Please select a valid department.";
            } elseif ($newPassword !== '' && ($pwError = validateNewPassword($newPassword)) !== '') {
                $error = $pwError;
            } elseif ($newPassword !== '' && !canModifyUser($connection, $currentRole, $userId)) {
                $error = "Only an admin can change another admin's password.";
            } else {
                $old = fetchUserSnapshot($connection, $userId);

                if (!$old) {
                    $error = "User not found.";
                } else {
                    mysqli_begin_transaction($connection);

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

                    $ok = mysqli_stmt_execute($stmt);

                    if ($ok && $newPassword !== '') {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                        $pwSql = $mustChange
                            ? "UPDATE users SET password = ?, must_change_password = 1, password_changed_at = NULL WHERE id = ?"
                            : "UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?";

                        $pwStmt = mysqli_prepare($connection, $pwSql);
                        mysqli_stmt_bind_param($pwStmt, "si", $hashedPassword, $userId);
                        $ok = mysqli_stmt_execute($pwStmt);
                    }

                    if ($ok) {
                        mysqli_commit($connection);

                        $changes = diffFields([
                            'Employee ID' => [$old['employee_id'], $employeeId],
                            'Full Name' => [$old['fullname'], $fullname],
                            'Email' => [$old['email'], $email],
                            'Phone' => [$old['phone'], $phone],
                            'Company' => [$old['company'], $company],
                            'Department' => [$old['department_name'], lookupName($connection, 'departments', $departmentId)],
                            'Designation' => [$old['designation_name'], lookupName($connection, 'designations', $designationId)],
                            'Line Manager' => [$old['line_manager_name'], lookupName($connection, 'users', $lineManagerId)],
                            'Role' => [ucfirst(strtolower((string) $old['role'])), ucfirst($role)],
                            'Status' => [ucfirst(strtolower((string) $old['status'])), ucfirst($status)],
                            'Weekly Report Required' => [
                                !empty($old['requires_weekly_report']) ? 'Yes' : 'No',
                                $requiresWeeklyReport ? 'Yes' : 'No'
                            ],
                        ]);

                        logChangeList($connection, $currentUserId, $userId, $changes);

                        // The password itself is never written to the log.
                        if ($newPassword !== '') {
                            logAudit($connection, $currentUserId, $userId, 'PASSWORD_CHANGE', $mustChange ? 'require_change' : '');
                        }

                        if ($newPassword !== '') {
                            sFlash('success', "User updated and password changed.");
                        } else {
                            sFlash('success', $changes ? "User updated successfully." : "No changes were made.");
                        }
                        header("Location: users.php?edit=" . $userId);
                        exit;
                    } else {
                        $dbError = strtolower(mysqli_error($connection));
                        mysqli_rollback($connection);

                        if (str_contains($dbError, 'employee_id')) {
                            $error = "A user with this Employee ID already exists.";
                        } elseif (str_contains($dbError, 'email')) {
                            $error = "A user with this email address already exists.";
                        } else {
                            $error = "Unable to update user.";
                        }
                    }
                }
            }
        }

        /* ---------------- DELETE USER ---------------- */
    } elseif (isset($_POST['delete_user'])) {

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $error = "Invalid user.";
        } elseif ($userId === $currentUserId) {
            $error = "You cannot delete your own account.";
        } else {
            $check = mysqli_query(
                $connection,
                "SELECT COUNT(*) AS total FROM weekly_reports WHERE user_id = {$userId}"
            );
            $row = mysqli_fetch_assoc($check);
            $hasReports = (int) ($row['total'] ?? 0);

            if ($hasReports > 0) {
                $error = "This user already has reports. Disable the user instead of deleting.";
            } else {
                $victim = fetchUserSnapshot($connection, $userId);
                $victimName = $victim['fullname'] ?? "#{$userId}";

                mysqli_query($connection, "DELETE FROM users WHERE id = {$userId}");
                logAudit($connection, $currentUserId, $userId, 'USER_DELETE', "Permanently deleted user {$victimName}");
                sFlash('success', "User deleted successfully.");
                header("Location: users.php");
                exit;
            }
        }
    }
}

/* =========================================================
   LIST QUERY
========================================================= */
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
$editId = 0;

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

/* Activity trail for the user being viewed (admin/manager only) */
$auditEvents = [];
if ($viewMode === 'edit' && $editUser && $canManageUsers) {
    $auditLogs = mysqli_query(
        $connection,
        "SELECT a.*, COALESCE(u.fullname, 'Deleted user') AS admin_name
         FROM user_audit_log a
         LEFT JOIN users u ON a.admin_id = u.id
         WHERE a.target_user_id = " . (int) $editId . "
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 150"
    );
    $auditEvents = buildAuditEvents($auditLogs);
}

/* Toast: flash message (after redirect) or an error from this request */
$toastText = '';
$toastType = 'success';
if (!empty($_SESSION['flash_text'])) {
    $toastText = $_SESSION['flash_text'];
    $toastType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_text'], $_SESSION['flash_type']);
} elseif ($error !== '') {
    $toastText = $error;
    $toastType = 'error';
}

include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/users.css?v=1">

<style>
    /* ---- Password field helpers ---- */
    .pw-wrap {
        display: flex;
        gap: 8px;
        align-items: stretch;
    }

    .pw-wrap input {
        flex: 1;
        min-width: 0;
    }

    .pw-toggle,
    .pw-gen {
        padding: 0 14px;
        border: 1px solid var(--u-border, rgba(148, 163, 184, .4));
        border-radius: 10px;
        background: transparent;
        color: var(--u-muted, #64748b);
        font-weight: 600;
        font-size: 12px;
        white-space: nowrap;
        cursor: pointer;
    }

    .pw-toggle:hover,
    .pw-gen:hover {
        color: var(--u-text, #1e293b);
        border-color: var(--u-muted, #64748b);
    }

    .pw-hint {
        margin-left: 4px;
        font-size: 11px;
        font-weight: 400;
        color: var(--u-muted, #64748b);
    }

    .users-toast-error {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    /* ---- Activity trail (compact, ERPNext style) ---- */
    .trail-bar {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .trail-count {
        font-size: 11px;
        color: var(--u-muted, #64748b);
    }

    .trail {
        margin-top: 10px;
        max-height: 240px;
        /* keeps the section small; scrolls for more */
        overflow-y: auto;
        padding-right: 6px;
    }

    .trail-line {
        position: relative;
        padding: 5px 0 5px 18px;
        font-size: 12.5px;
        line-height: 1.5;
        color: var(--u-muted, #64748b);
    }

    .trail-line strong {
        font-weight: 600;
        color: var(--u-text, inherit);
    }

    /* small dot */
    .trail-line::before {
        content: "";
        position: absolute;
        left: 3px;
        top: 12px;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #94a3b8;
    }

    /* thin connecting line */
    .trail-line:not(:last-child)::after {
        content: "";
        position: absolute;
        left: 6px;
        top: 19px;
        bottom: -12px;
        width: 1px;
        background: var(--u-border, rgba(148, 163, 184, .35));
    }

    .trail-v {
        color: var(--u-text, inherit);
    }

    .trail-time {
        margin-left: 6px;
        font-size: 11.5px;
        white-space: nowrap;
        opacity: .8;
        cursor: help;
    }
</style>

<?php if ($toastText !== ''): ?>
    <div class="users-toast users-toast-<?= h($toastType) ?>" id="usersToast">
        <?= h($toastText) ?>
    </div>
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
                            <th>Department</th>
                            <th>Modified</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users && mysqli_num_rows($users) > 0): ?>
                            <?php $sn = 1; ?>
                            <?php while ($u = mysqli_fetch_assoc($users)): ?>
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
                                    <td><?= h($u['department_name'] ?? 'N/A') ?></td>
                                    <td style="color: var(--u-muted); font-size: 10px;"><?= h(timeAgo($u['last_activity'])) ?></td>
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

            <form method="POST" autocomplete="off">
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
                        <p>Define the user's system role, password and reporting responsibilities.</p>
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

                    <div class="users-field">
                        <label>Password <span class="pw-hint">(blank = default password)</span></label>
                        <div class="pw-wrap">
                            <input type="password" id="pwField" name="new_password" minlength="<?= MIN_PASSWORD_LENGTH ?>"
                                autocomplete="new-password" placeholder="Leave blank for default">
                            <button type="button" class="pw-toggle" data-target="pwField">Show</button>
                            <button type="button" class="pw-gen" data-generate="pwField">Generate</button>
                        </div>
                    </div>

                    <label class="users-check-card">
                        <input type="checkbox" name="must_change_password" value="1" checked>
                        <span>
                            <strong>Require change on first login</strong>
                            <small>Always applied when the default password is used.</small>
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
                        <span>If you leave the password blank, the user receives <b><?= h(DEFAULT_PASSWORD) ?></b> and must
                            change it on first login.</span>
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

            <form method="POST" autocomplete="off">
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
                        <p>Manage account access<?= $canManageUsers ? ', password' : '' ?> and weekly reporting
                            responsibility.</p>
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

                    <?php if ($canManageUsers): ?>
                        <div class="users-field">
                            <label>New Password <span class="pw-hint">(blank = keep current)</span></label>
                            <div class="pw-wrap">
                                <input type="password" id="pwField" name="new_password" minlength="<?= MIN_PASSWORD_LENGTH ?>"
                                    autocomplete="new-password" placeholder="Leave blank to keep current">
                                <button type="button" class="pw-toggle" data-target="pwField">Show</button>
                                <button type="button" class="pw-gen" data-generate="pwField">Generate</button>
                            </div>
                        </div>

                        <label class="users-check-card">
                            <input type="checkbox" name="must_change_password" value="1" checked>
                            <span>
                                <strong>Require change at next login</strong>
                                <small>Only applies when a new password is entered.</small>
                            </span>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="users-form-actions">
                    <button type="submit" name="update_user" class="users-btn users-btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $canManageUsers ? 'Save Changes' : 'Save Profile' ?>
                    </button>

                    <?php if ($canManageUsers): ?>
                        <button type="submit" name="delete_user" class="users-btn users-btn-danger"
                            onclick="return confirm('Delete this user? This is only allowed if the user has no reports.');">
                            <i class="fa-solid fa-trash"></i> Delete User
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($canManageUsers): ?>
            <!-- Activity trail -->
            <div class="audit-section">
                <div class="audit-title trail-bar">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Activity History for <?= h($editUser['fullname']) ?>
                    <?php if ($auditEvents): ?>
                        <span class="trail-count"><?= count($auditEvents) ?> event<?= count($auditEvents) === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($auditEvents): ?>
                    <div class="trail">
                        <?php foreach ($auditEvents as $ev): ?>
                            <?= renderAuditEvent($ev) ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="users-empty">No activity recorded for this user yet.</div>
                <?php endif; ?>
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

    /* Strong random password: at least one upper, lower, digit and symbol */
    function generatePassword(len = 12) {
        const sets = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%&*?'
        ];
        const all = sets.join('');
        const rand = n => {
            const a = new Uint32Array(1);
            crypto.getRandomValues(a);
            return a[0] % n;
        };

        const chars = sets.map(s => s[rand(s.length)]);
        while (chars.length < len) chars.push(all[rand(all.length)]);

        for (let i = chars.length - 1; i > 0; i--) {
            const j = rand(i + 1);
            [chars[i], chars[j]] = [chars[j], chars[i]];
        }
        return chars.join('');
    }

    document.addEventListener('click', function(e) {

        // Show / hide password
        const toggle = e.target.closest('.pw-toggle');
        if (toggle) {
            const input = document.getElementById(toggle.dataset.target);
            if (input) {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.textContent = show ? 'Hide' : 'Show';
            }
            return;
        }

        // Generate a password (shown in plain text so it can be copied)
        const gen = e.target.closest('[data-generate]');
        if (gen) {
            const input = document.getElementById(gen.dataset.generate);
            if (input) {
                input.value = generatePassword();
                input.type = 'text';
                document.querySelectorAll('.pw-toggle[data-target="' + input.id + '"]')
                    .forEach(b => b.textContent = 'Hide');
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const toast = document.getElementById('usersToast');
        if (toast) {
            const isError = toast.classList.contains('users-toast-error');
            setTimeout(() => toast.classList.add('fade-out'), isError ? 8000 : 3500);
            setTimeout(() => toast.remove(), isError ? 8600 : 4100);
        }
    });
</script>

<?php include '../inc/footer.php'; ?>