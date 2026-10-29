<?php require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';
$pageTitle = 'Facility Access';
$uid = (int) ($_SESSION['user_id'] ?? 0);
if (strtolower($_SESSION['role'] ?? 'staff') !== 'admin' && !facilityHasRole($connection, $uid, 'facility_admin')) {
    header('Location: support_tickets.php?tab=facility');
    exit;
}
facilityCsrfToken(); /* ============================================================ FACILITY ROLE DEFINITIONS ============================================================ */
$keys = ['facility_officer' => 'Facility Officer', 'facility_technician' => 'Facility Technician', 'facility_verifier' => 'Facility Verifier', 'facility_project_manager' => 'Project Manager', 'facility_admin' => 'Facility Admin']; /* ============================================================ UPDATE USER ACCESS ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && facilityValidateCsrf()) {
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $roles = array_intersect(array_keys($keys), (array) ($_POST['roles'] ?? []));
    mysqli_begin_transaction($connection);
    try {
        mysqli_query($connection, " DELETE FROM facility_user_roles WHERE user_id = {$targetUserId} ");
        foreach ($roles as $roleKey) {
            $escapedRole = mysqli_real_escape_string($connection, $roleKey);
            mysqli_query($connection, " INSERT INTO facility_user_roles ( user_id, role_key, created_by ) VALUES ( {$targetUserId}, '{$escapedRole}', {$uid} ) ");
        }
        if (in_array('facility_technician', $roles, true)) {
            mysqli_query($connection, " INSERT IGNORE INTO facility_technicians ( user_id ) VALUES ( {$targetUserId} ) ");
        }
        mysqli_commit($connection);
        $_SESSION['flash_type'] = 'success';
        $_SESSION['flash_text'] = 'Facility access updated successfully.';
    } catch (Throwable $e) {
        mysqli_rollback($connection);
        $_SESSION['flash_type'] = 'error';
        $_SESSION['flash_text'] = 'Unable to update Facility access.';
    }
header('Location: facility_roles.php');
exit;
}

/* ============================================================
   FILTERS
============================================================ */

$search = trim($_GET['q'] ?? '');
$globalRoleFilter = trim($_GET['global_role'] ?? '');
$facilityRoleFilter = trim($_GET['facility_role'] ?? '');
$accessFilter = trim($_GET['access'] ?? '');

$userWhere = [
    "u.status = 'active'"
];


if ($search !== '') {
    $searchEscaped = mysqli_real_escape_string( $connection, $search );
    $userWhere[] = "( u.fullname LIKE '%{$searchEscaped}%' OR u.email LIKE '%{$searchEscaped}%' )";
}

if ($globalRoleFilter !== '') {
    $roleEscaped = mysqli_real_escape_string( $connection, $globalRoleFilter );
    $userWhere[] = "u.role = '{$roleEscaped}'";
}

if (
    $facilityRoleFilter !== '' && array_key_exists( $facilityRoleFilter, $keys )
) {
    $facilityRoleEscaped = mysqli_real_escape_string( $connection, $facilityRoleFilter );
    $userWhere[] = "EXISTS ( SELECT 1 FROM facility_user_roles fur_filter WHERE fur_filter.user_id = u.id AND fur_filter.role_key = '{$facilityRoleEscaped}' )";
}


if ($accessFilter === 'assigned') {
    $userWhere[] = "EXISTS ( SELECT 1 FROM facility_user_roles fur_access WHERE fur_access.user_id = u.id )";
} elseif ($accessFilter === 'unassigned') {
    $userWhere[] = "NOT EXISTS ( SELECT 1 FROM facility_user_roles fur_access WHERE fur_access.user_id = u.id )";
}

$userWhereSql = implode( ' AND ', $userWhere );

/* ============================================================ ACTIVE USERS ============================================================ */
$users = mysqli_query( $connection, " SELECT u.id, u.fullname, u.email, u.role FROM users u WHERE {$userWhereSql} ORDER BY u.fullname " );
/* ============================================================ EXISTING FACILITY ROLE ASSIGNMENTS ============================================================ */
$assigned = [];
$assignedResult = mysqli_query($connection, " SELECT user_id, role_key FROM facility_user_roles ");
while ($assignedResult && ($row = mysqli_fetch_assoc($assignedResult))) {
    $assigned[(int) $row['user_id']][] = $row['role_key'];
} /* ============================================================ SUMMARY ============================================================ */
$totalUsers = 0;
$facilityUsers = 0;
$facilityTechnicians = 0;
$facilityAdmins = 0;
$userRows = [];
while ($users && ($user = mysqli_fetch_assoc($users))) {
    $userRows[] = $user;
    $totalUsers++;
    $userRoles = $assigned[(int) $user['id']] ?? [];
    if (!empty($userRoles)) {
        $facilityUsers++;
    }
    if (in_array('facility_technician', $userRoles, true)) {
        $facilityTechnicians++;
    }
    if (in_array('facility_admin', $userRoles, true)) {
        $facilityAdmins++;
    }
}
include '../inc/header.php'; ?>
<link rel="stylesheet" href="../assets/css/facility-roles.css?v=1.0">
<div class="fra-page">
    <!-- ======================================================== HEADER ========================================================= -->
    <div class="fra-page-header">
        <div>
            <div class="fra-eyebrow"> Facility Management </div>
            <h2> Facility Access </h2>
            <p> Assign Facility responsibilities to active users without changing their global system role. </p>
        </div>
    </div>
    <!-- ======================================================== FLASH MESSAGE ========================================================= -->
    <?php if (!empty($_SESSION['flash_text'])): ?>
    <div class=" fra-alert <?= facilityH($_SESSION['flash_type']) ?> "> <?= facilityH($_SESSION['flash_text']) ?>
    </div> <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?> <?php endif; ?>
    <!-- ======================================================== SUMMARY ========================================================= -->
    <div class="fra-kpi-grid">
        <div class="fra-kpi-card">
            <div class="fra-kpi-icon users"> <i class="fa-solid fa-users"></i> </div>
            <div> <span> Active Users </span> <strong> <?= $totalUsers ?> </strong> <small> Users available for Facility
                    access </small> </div>
        </div>
        <div class="fra-kpi-card">
            <div class="fra-kpi-icon assigned"> <i class="fa-solid fa-user-check"></i> </div>
            <div> <span> Facility Users </span> <strong> <?= $facilityUsers ?> </strong> <small> Users with at least one
                    Facility role </small> </div>
        </div>
        <div class="fra-kpi-card">
            <div class="fra-kpi-icon technicians"> <i class="fa-solid fa-screwdriver-wrench"></i> </div>
            <div> <span> Officers </span> <strong> <?= $facilityTechnicians ?> </strong> <small> Users assigned as
                    Facility Engineers </small> </div>
        </div>
        <div class="fra-kpi-card">
            <div class="fra-kpi-icon admins"> <i class="fa-solid fa-user-shield"></i> </div>
            <div> <span> Facility Admins </span> <strong> <?= $facilityAdmins ?> </strong> <small> Users with Facility
                    Admin access </small> </div>
        </div>
    </div>
    <!-- ======================================================== ACCESS TABLE ========================================================= -->
    <section class="fra-panel">
        <div class="fra-panel-header">
            <div>
                <h3> User Facility Responsibilities </h3>
                <p> Select one or more responsibilities for each user and save the row. </p>
            </div>
        </div>
        <form method="GET" class="fra-filter-bar">

            <div class="fra-filter-search">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input type="text" name="q" value="<?= facilityH($search) ?>" placeholder="Search name or email...">

            </div>


            <select name="global_role">

                <option value="">
                    All Global Roles
                </option>

                <option value="admin" <?= $globalRoleFilter === 'admin'
                ? 'selected'
                : ''
            ?>>
                    Admin
                </option>

                <option value="staff" <?= $globalRoleFilter === 'staff'
                ? 'selected'
                : ''
            ?>>
                    Staff
                </option>

            </select>


            <select name="facility_role">

                <option value="">
                    All Facility Roles
                </option>

                <?php foreach (
            $keys
            as $roleKey => $roleLabel
        ): ?>

                <option value="<?= facilityH($roleKey) ?>" <?= $facilityRoleFilter === $roleKey
                    ? 'selected'
                    : ''
                ?>>
                    <?= facilityH($roleLabel) ?>
                </option>

                <?php endforeach; ?>

            </select>


            <select name="access">

                <option value="">
                    All Access
                </option>

                <option value="assigned" <?= $accessFilter === 'assigned'
                ? 'selected'
                : ''
            ?>>
                    Facility Access Assigned
                </option>

                <option value="unassigned" <?= $accessFilter === 'unassigned'
                ? 'selected'
                : ''
            ?>>
                    No Facility Access
                </option>

            </select>


            <button type="submit" class="fra-btn fra-btn-primary">
                <i class="fa-solid fa-filter"></i>
                Filter
            </button>


            <a href="facility_roles.php" class="fra-btn fra-btn-light">
                Reset
            </a>

        </form>

        <div class="fra-table-wrap">
            <table class="fra-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Global Role</th>
                        <th>Facility Responsibilities</th>
                    </tr>
                </thead>
                <tbody> <?php if (!empty($userRows)): ?> <?php foreach ($userRows as $user): ?>
                    <?php $userId = (int) $user['id'];
                            $userRoles = $assigned[$userId] ?? [];
                            $initial = strtoupper(substr(trim($user['fullname']), 0, 1)); ?>
                    <tr>
                        <td>
                            <div class="fra-user">
                                <div class="fra-avatar"> <?= facilityH($initial) ?> </div>
                                <div> <strong> <?= facilityH($user['fullname']) ?> </strong> <span>
                                        <?= facilityH($user['email']) ?> </span> </div>
                            </div>
                        </td>
                        <td> <span class="fra-global-role">
                                <?= facilityH(ucwords(str_replace('_', ' ', $user['role']))) ?> </span> </td>
                        <td>
                            <form method="POST" class="fra-role-form"> <input type="hidden" name="csrf_token"
                                    value="<?= facilityH(facilityCsrfToken()) ?>"> <input type="hidden" name="user_id"
                                    value="<?= $userId ?>">
                                <div class="fra-role-options"> <?php foreach ($keys as $key => $label): ?>
                                    <?php $checked = in_array($key, $userRoles, true); ?> <label
                                        class=" fra-role-chip <?= $checked ? 'selected' : '' ?> "> <input
                                            type="checkbox" name="roles[]" value="<?= facilityH($key) ?>"
                                            <?= $checked ? 'checked' : '' ?>> <span class="fra-check-box"> <i
                                                class="fa-solid fa-check"></i> </span> <span> <?= facilityH($label) ?>
                                        </span> </label> <?php endforeach; ?>
                                </div> <button type="submit" class="fra-btn fra-btn-light fra-save-btn"> <i
                                        class="fa-solid fa-floppy-disk"></i>
                                    Save </button>
                            </form>
                        </td>
                    </tr> <?php endforeach; ?> <?php else: ?>
                    <tr>
                        <td colspan="3" class="fra-empty-cell">
                            <div class="fra-empty-state"> <i class="fa-solid fa-users-slash"></i> <strong> No active
                                    users found </strong> <span> Active users will appear here when available. </span>
                            </div>
                        </td>
                    </tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.fra-role-chip input[type="checkbox"]').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const chip = checkbox.closest('.fra-role-chip');
            if (chip) {
                chip.classList.toggle('selected', checkbox.checked);
            }
        });
    });
    document.querySelectorAll('.fra-role-form').forEach(function(form) {
        const button = form.querySelector('.fra-save-btn');
        form.addEventListener('submit', function(event) {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            }
        });
    });
});
</script>
<?php include '../inc/footer.php'; ?>