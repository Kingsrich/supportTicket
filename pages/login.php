<?php

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.gc_maxlifetime', 604800);

    session_set_cookie_params([
        'lifetime' => 604800,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

require '../inc/connection.php';

if (isset($_SESSION['user_id'])) {
    require_once '../inc/connection.php';
    require_once '../inc/facility_functions.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));
    $isFacilityOfficer = facilityHasRole($connection, $userId, 'facility_officer');
    $isProjectManager = facilityHasRole($connection, $userId, 'facility_project_manager');
    if ($role === 'admin' || $isFacilityOfficer || $isProjectManager) {
        header("Location: dashboard.php");
    } else {
        header("Location: my_dashboard.php");
    }
    exit;
}

$error = "";
$activeTab = $_POST['login_type'] ?? 'admin';

$staffUsers = mysqli_query(
    $connection,
    "SELECT id, fullname, employee_id, email, role
     FROM users
     WHERE STATUS = 'active'
     AND role IN ('staff', 'manager')
     ORDER BY fullname ASC"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $loginType = $_POST['login_type'] ?? 'admin';
    $activeTab = $loginType;

    if ($loginType === 'admin') {

        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {

            $error = "Please enter your email and password.";

        } else {

            $stmt = mysqli_prepare(
                $connection,
                "SELECT
                    u.id,
                    u.employee_id,
                    u.fullname,
                    u.email,
                    u.password,
                    u.role,
                    u.status,
                    u.must_change_password,
                    d.department_name,
                    g.designation_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN designations g ON g.id = u.designation_id
                WHERE u.email = ?
                AND u.role IN ('admin','manager')
                LIMIT 1"
            );

            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if ($user = mysqli_fetch_assoc($result)) {

                if ($user['status'] !== 'active') {

                    $error = "Your account is inactive.";

                } elseif (password_verify($password, $user['password'])) {

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['employee_id'] = $user['employee_id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department_name'] = $user['department_name'];
                    $_SESSION['designation_name'] = $user['designation_name'];

                    mysqli_query(
                        $connection,
                        "UPDATE users
                         SET last_login = NOW()
                         WHERE id = " . (int) $user['id']
                    );

                    if ((int) $user['must_change_password'] === 1) {
                        header("Location: change_password.php");
                        exit;
                    }

                    header("Location: dashboard.php");
                    exit;

                } else {

                    $error = "Invalid login credentials.";
                }

            } else {

                $error = "Invalid admin login credentials.";
            }
        }
    }

    if ($loginType === 'staff') {

        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $password = trim($_POST['staff_password'] ?? '');

        if ($staffId <= 0 || $password === '') {

            $error = "Please select your name and enter your password.";

        } else {

            $stmt = mysqli_prepare(
                $connection,
                "SELECT
                    u.id,
                    u.employee_id,
                    u.fullname,
                    u.email,
                    u.password,
                    u.role,
                    u.status,
                    u.must_change_password,
                    d.department_name,
                    g.designation_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN designations g ON g.id = u.designation_id
                WHERE u.id = ?
                AND u.role IN ('staff', 'manager')
                LIMIT 1"
            );

            mysqli_stmt_bind_param($stmt, "i", $staffId);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if ($user = mysqli_fetch_assoc($result)) {

                if ($user['status'] !== 'active') {

                    $error = "Your account is inactive.";

                } elseif (password_verify($password, $user['password'])) {

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['employee_id'] = $user['employee_id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department_name'] = $user['department_name'];
                    $_SESSION['designation_name'] = $user['designation_name'];

                    mysqli_query(
                        $connection,
                        "UPDATE users
                         SET last_login = NOW()
                         WHERE id = " . (int) $user['id']
                    );

                    if ((int) $user['must_change_password'] === 1) {
                        header("Location: change_password.php");
                        exit;
                    }

                    header("Location: dashboard.php");
                    exit;

                } else {

                    $error = "Invalid password.";
                }

            } else {

                $error = "Invalid staff account.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login - Diva Cakes Support Ticket</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/customer-service.ico">
    <link rel="stylesheet" href="../assets/css/login.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

    <div class="login-container">
        <div class="login-card">
            <div class="brand">
                <div class="brand-badge">
                    <img src="../assets/images/logo.png" alt="Logo">
                </div>
                <h1>Welcome Back</h1>
                <p>Diva Cakes Support System</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="error-box">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="tab-buttons">
                <button type="button" class="tab-btn <?= $activeTab === 'admin' ? 'active' : '' ?>"
                    onclick="switchTab('admin')">
                    System Administrator
                </button>

                <button type="button" class="tab-btn <?= $activeTab === 'staff' ? 'active' : '' ?>"
                    onclick="switchTab('staff')">
                    Staff Login
                </button>
            </div>

            <div id="adminTab" class="tab-content <?= $activeTab === 'admin' ? 'active' : '' ?>">
                <form method="POST">

                    <input type="hidden" name="login_type" value="admin">

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter admin or manager email"
                            required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" class="form-control"
                                placeholder="Enter password" required>
                            <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">
                        Login
                    </button>
                </form>
            </div>
            <div id="staffTab" class="tab-content <?= $activeTab === 'staff' ? 'active' : '' ?>">
                <form method="POST">
                    <input type="hidden" name="login_type" value="staff">
                    <input type="hidden" name="staff_id" id="staff_id">
                    <div class="form-group staff-search-box">
                        <label class="form-label">Search Your Name</label>
                        <input type="text" id="staffSearch" class="form-control"
                            placeholder="Type your name or employee ID" autocomplete="off">
                        <div class="staff-results" id="staffResults">
                            <?php if ($staffUsers && mysqli_num_rows($staffUsers) > 0): ?>
                                <?php while ($staff = mysqli_fetch_assoc($staffUsers)): ?>

                                    <div class="staff-option" data-id="<?= (int) $staff['id'] ?>"
                                        data-name="<?= htmlspecialchars($staff['fullname'], ENT_QUOTES) ?>"
                                        data-employee="<?= htmlspecialchars($staff['employee_id'] ?? '', ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES) ?>" data-search="<?= htmlspecialchars(
                                                 strtolower(
                                                     $staff['fullname'] . ' ' .
                                                     ($staff['employee_id'] ?? '') . ' ' .
                                                     ($staff['email'] ?? '')
                                                 ),
                                                 ENT_QUOTES
                                             ) ?>">
                                        <strong><?= htmlspecialchars($staff['fullname']) ?></strong>
                                        <span>
                                            <?= htmlspecialchars($staff['employee_id'] ?? '') ?>
                                            <?php if (!empty($staff['email'])): ?>
                                                • <?= htmlspecialchars($staff['email']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                <?php endwhile; ?>
                            <?php else: ?>

                                <div class="staff-option">
                                    <strong>No active staff found</strong>
                                    <span>Please contact admin.</span>
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="hint">
                        Select your name from the list before entering your password.
                    </div>

                    <div class="form-group"> <label class="form-label"> Password </label>
                        <div class="password-wrapper"> <input type="password" name="staff_password" id="staff_password"
                                class="form-control" placeholder="Enter password" required> <i
                                class="fa-solid fa-eye toggle-password"
                                onclick="togglePassword('staff_password', this)"></i> </div>
                    </div>

                    <button type="submit" class="login-btn">
                        Staff Login
                    </button>

                </form>

            </div>

            <div class="bottom-text">
                Return to <a href="index.php">Homepage</a>
            </div>

        </div>

    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            if (tab === 'admin') {
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                document.getElementById('adminTab').classList.add('active');
            }

            if (tab === 'staff') {
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
                document.getElementById('staffTab').classList.add('active');
            }
        }

        function togglePassword(inputId, icon) {
            const passwordInput = document.getElementById(inputId);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        const staffSearch = document.getElementById('staffSearch');
        const staffResults = document.getElementById('staffResults');
        const staffIdInput = document.getElementById('staff_id');
        const staffOptions = [...document.querySelectorAll('.staff-option[data-id]')];

        function normalizeStaffValue(value) {
            return (value || '').toLowerCase().trim();
        }

        function selectStaff(option) {
            if (!option) return;

            staffSearch.value = option.dataset.name || '';
            staffIdInput.value = option.dataset.id || '';
            staffResults.style.display = 'none';
        }

        function findExactStaff(value) {
            const searchValue = normalizeStaffValue(value);

            if (!searchValue) return null;

            return staffOptions.find(option => {
                const name = normalizeStaffValue(option.dataset.name);
                const employee = normalizeStaffValue(option.dataset.employee);
                const email = normalizeStaffValue(option.dataset.email);

                return searchValue === name ||
                    searchValue === employee ||
                    searchValue === email;
            }) || null;
        }

        function resolveStaffSelection() {
            const exactMatch = findExactStaff(staffSearch.value);

            if (exactMatch) {
                staffIdInput.value = exactMatch.dataset.id;
                return true;
            }

            staffIdInput.value = '';
            return false;
        }

        staffSearch.addEventListener('input', function () {
            const value = normalizeStaffValue(this.value);

            staffIdInput.value = '';

            if (value.length < 1) {
                staffResults.style.display = 'none';
                return;
            }

            let visibleCount = 0;
            let onlyMatch = null;

            staffOptions.forEach(option => {
                const searchText = normalizeStaffValue(option.dataset.search);
                const matches = searchText.includes(value);

                option.style.display = matches ? 'block' : 'none';

                if (matches) {
                    visibleCount++;
                    onlyMatch = option;
                }
            });

            /*
             * If the entered value exactly matches a staff member's
             * name, employee ID or email, automatically set staff_id.
             */
            resolveStaffSelection();

            /*
             * Keep suggestions visible unless an exact staff
             * account has already been resolved.
             */
            staffResults.style.display =
                visibleCount > 0 && !staffIdInput.value ?
                    'block' :
                    'none';
        });

        staffOptions.forEach(option => {
            option.addEventListener('click', function () {
                selectStaff(this);
            });
        });

        /*
         * Browser autofill may populate the visible input without
         * triggering the custom dropdown selection. Resolve it on blur.
         */
        staffSearch.addEventListener('blur', function () {
            setTimeout(function () {
                resolveStaffSelection();
            }, 100);
        });

        /*
         * Also resolve when the user presses Enter.
         */
        staffSearch.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;

            const exactMatch = findExactStaff(staffSearch.value);

            if (exactMatch) {
                event.preventDefault();
                selectStaff(exactMatch);

                document.getElementById('staff_password')?.focus();
            }
        });

        /*
         * Final safeguard before the login form is submitted.
         */
        const staffForm = document.querySelector('#staffTab form');

        staffForm?.addEventListener('submit', function () {
            resolveStaffSelection();
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.staff-search-box')) {
                staffResults.style.display = 'none';
            }
        });
    </script>

</body>

</html>