<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/erpnext_config.php';

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    die("Unauthorized");
}

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function getOrCreateDepartment($connection, $name)
{
    $name = trim($name);

    if ($name === '') {
        return null;
    }

    $stmt = mysqli_prepare(
        $connection,
        "SELECT id FROM departments WHERE department_name = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($res)) {
        return (int) $row['id'];
    }

    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 20));

    $stmt = mysqli_prepare(
        $connection,
        "INSERT INTO departments (department_name, department_code, status)
         VALUES (?, ?, 'active')"
    );

    mysqli_stmt_bind_param($stmt, "ss", $name, $code);
    mysqli_stmt_execute($stmt);

    return mysqli_insert_id($connection);
}

function getOrCreateDesignation($connection, $name)
{
    $name = trim($name);

    if ($name === '') {
        return null;
    }

    $stmt = mysqli_prepare(
        $connection,
        "SELECT id FROM designations WHERE designation_name = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($res)) {
        return (int) $row['id'];
    }

    $stmt = mysqli_prepare(
        $connection,
        "INSERT INTO designations (designation_name)
         VALUES (?)"
    );

    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);

    return mysqli_insert_id($connection);
}

function erpnextGet($endpoint)
{
    $url = rtrim(ERPNEXT_BASE_URL, '/') . $endpoint;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . ERPNEXT_API_KEY . ':' . ERPNEXT_API_SECRET,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 45,

        // LOCAL WAMP FIX
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: " . $error);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("ERPNext returned HTTP {$httpCode}: " . $response);
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        throw new Exception("Invalid JSON response from ERPNext.");
    }

    return $json;
}

$inserted = 0;
$updated = 0;
$skipped = 0;
$reportsToUpdated = 0;
$reportsToMissing = 0;
$error = "";

try {

    $fields = urlencode(json_encode([
        "name",
        "employee_name",
        "company_email",
        "personal_email",
        "cell_number",
        "company",
        "department",
        "designation",
        "reports_to",
        "status"
    ]));

    $limit = 5000;

    $endpoint = "/api/resource/Employee?fields={$fields}&limit_page_length={$limit}";

    $result = erpnextGet($endpoint);

    $employees = $result['data'] ?? [];

    foreach ($employees as $emp) {

        $employeeId = trim($emp['name'] ?? '');
        $fullname = trim($emp['employee_name'] ?? '');
        $email = trim($emp['company_email'] ?? '');

        if ($email === '') {
            $email = trim($emp['personal_email'] ?? '');
        }

        $phone = trim($emp['cell_number'] ?? '');
        $company = trim($emp['company'] ?? '');

        if ($company === '') {
            $company = 'DIVA CAKES & CONFECTIONS LTD';
        }
        $departmentName = trim($emp['department'] ?? '');
        $designationName = trim($emp['designation'] ?? '');
        $reportsToEmployeeId = trim($emp['reports_to'] ?? '');
        $erpStatus = strtolower(trim($emp['status'] ?? 'active'));

        if ($employeeId === '' || $fullname === '' || $email === '') {
            $skipped++;
            continue;
        }

        $departmentId = getOrCreateDepartment($connection, $departmentName);
        $designationId = getOrCreateDesignation($connection, $designationName);

        $localStatus = in_array($erpStatus, ['active', 'left']) && $erpStatus === 'active'
            ? 'active'
            : 'inactive';

        $role = 'staff';
        $defaultPassword = "Welcome123@";
        $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

        $check = mysqli_prepare(
            $connection,
            "SELECT id FROM users WHERE employee_id = ? OR email = ? LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "ss", $employeeId, $email);
        mysqli_stmt_execute($check);

        $checkRes = mysqli_stmt_get_result($check);

        if ($existing = mysqli_fetch_assoc($checkRes)) {

            $userId = (int) $existing['id'];

            $stmt = mysqli_prepare(
                $connection,
                "UPDATE users
                    SET employee_id = ?,
                        erpnext_employee_id = ?,
                        fullname = ?,
                        email = ?,
                        phone = ?,
                        company = ?,
                        department_id = ?,
                        designation_id = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssiisi",
                $employeeId,
                $employeeId,
                $fullname,
                $email,
                $phone,
                $company,
                $departmentId,
                $designationId,
                $localStatus,
                $userId
            );

            mysqli_stmt_execute($stmt);
            $updated++;

        } else {

            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO users
                (employee_id, erpnext_employee_id, fullname, email, phone, company, password, department_id, designation_id, role, status, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssssssiiss",
                $employeeId,
                $employeeId,
                $fullname,
                $email,
                $phone,
                $company,
                $hashedPassword,
                $departmentId,
                $designationId,
                $role,
                $localStatus
            );

            mysqli_stmt_execute($stmt);
            $inserted++;
        }
    }

    foreach ($employees as $emp) {

        $employeeId = trim($emp['name'] ?? '');
        $reportsToEmployeeId = trim($emp['reports_to'] ?? '');

        if ($employeeId === '' || $reportsToEmployeeId === '') {
            continue;
        }

        $staffRes = mysqli_query(
            $connection,
            "SELECT id FROM users 
         WHERE erpnext_employee_id = '" . mysqli_real_escape_string($connection, $employeeId) . "'
         LIMIT 1"
        );

        $managerRes = mysqli_query(
            $connection,
            "SELECT id FROM users 
         WHERE erpnext_employee_id = '" . mysqli_real_escape_string($connection, $reportsToEmployeeId) . "'
         LIMIT 1"
        );

        $staff = mysqli_fetch_assoc($staffRes);
        $manager = mysqli_fetch_assoc($managerRes);

        if ($staff && $manager) {
            mysqli_query(
                $connection,
                "UPDATE users 
         SET line_manager_id = " . (int) $manager['id'] . "
         WHERE id = " . (int) $staff['id']
            );

            $reportsToUpdated++;
        } else {
            $reportsToMissing++;
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}


include '../inc/header.php';
?>

<div style="
    background:#fff;
    border:1px solid #eef2f7;
    border-radius:20px;
    padding:24px;
">
    <h3>ERPNext User Sync</h3>

    <?php if ($error !== ''): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:14px;border-radius:12px;margin-top:16px;">
            <?= h($error) ?>
        </div>
    <?php else: ?>
        <div style="background:#dcfce7;color:#166534;padding:14px;border-radius:12px;margin-top:16px;">
            Sync completed. Inserted: <?= (int) $inserted ?>,
            Updated: <?= (int) $updated ?>,
            Skipped: <?= (int) $skipped ?>,
            Reports To Updated: <?= (int) $reportsToUpdated ?>,
            Reports To Missing: <?= (int) $reportsToMissing ?>.
        </div>
    <?php endif; ?>

    <br>

    <a href="users.php" style="
        display:inline-flex;
        padding:11px 16px;
        border-radius:10px;
        background:#fff7ed;
        color:#7c2d12;
        text-decoration:none;
        font-weight:600;
        border:1px solid #fed7aa;
    ">
        Back to Users
    </a>
</div>

<?php include '../inc/footer.php'; ?>