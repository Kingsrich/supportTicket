<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

$fullname = $_SESSION['fullname'] ?? 'User';
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));
$department = $_SESSION['department_name'] ?? '';
$isAdmin = ($role === 'admin');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

// Facility Access Logic
$facilityRoles = [];
$hasFacilityAccess = $isFacilityOfficer = $isFacilityTechnician = $isFacilityVerifier = $isFacilityProjectManager = $isFacilityAdmin = false;

if ($isAdmin) {
    $hasFacilityAccess = $isFacilityOfficer = $isFacilityTechnician = $isFacilityVerifier = $isFacilityProjectManager = $isFacilityAdmin = true;
} elseif ($currentUserId > 0 && isset($connection) && $connection instanceof mysqli) {
    $tableCheck = mysqli_query($connection, "SHOW TABLES LIKE 'facility_user_roles'");
    if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
        $stmt = mysqli_prepare($connection, "SELECT role_key FROM facility_user_roles WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $currentUserId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result))
                $facilityRoles[] = $row['role_key'];
            mysqli_stmt_close($stmt);
        }
    }
    $isFacilityOfficer = in_array('facility_officer', $facilityRoles, true);
    $isFacilityTechnician = in_array('facility_technician', $facilityRoles, true);
    $isFacilityVerifier = in_array('facility_verifier', $facilityRoles, true);
    $isFacilityProjectManager = in_array('facility_project_manager', $facilityRoles, true);
    $isFacilityAdmin = in_array('facility_admin', $facilityRoles, true);
    $hasFacilityAccess = !empty($facilityRoles);
}

// Access Levels
$canViewFacilityDashboard = $isAdmin || $isFacilityOfficer || $isFacilityVerifier || $isFacilityProjectManager || $isFacilityAdmin;
$canViewFacilityAssets = $isAdmin || $isFacilityOfficer || $isFacilityTechnician || $isFacilityVerifier || $isFacilityProjectManager || $isFacilityAdmin;
$canViewPreventiveMaintenance = $isAdmin || $isFacilityOfficer || $isFacilityTechnician || $isFacilityProjectManager || $isFacilityAdmin;
$canViewFacilityVendors = $isAdmin || $isFacilityOfficer || $isFacilityProjectManager || $isFacilityAdmin;
$canViewFacilityReports = $isAdmin || $isFacilityOfficer || $isFacilityProjectManager || $isFacilityAdmin;
$canManageFacilityAccess = $isAdmin || $isFacilityAdmin;

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$pageDescriptions = [
    'support_tickets.php' => 'Raise and track IT Support or Facility Maintenance requests.',
    'ticket_view.php' => 'Review and manage IT support request details.',
    'maintenance_view.php' => 'Track maintenance work, repairs, verification, and approval.',
    'facility_dashboard.php' => 'Monitor maintenance operations, costs, downtime, and performance.',
    'facility_assets.php' => 'Manage facility assets across all outlets.',
    'facility_asset_view.php' => 'View asset information, maintenance history, costs, and downtime.',
    'preventive_maintenance.php' => 'Plan and monitor scheduled preventive maintenance activities.',
    'facility_vendors.php' => 'Manage external maintenance vendors and service performance.',
    'facility_reports.php' => 'Analyse maintenance performance, response time, downtime, and costs.',
    'facility_roles.php' => 'Manage Facility Management responsibilities and access.',
    'it_reports.php' => 'Monitor IT support performance, workload, and service levels.',
    'weekly_reports.php' => 'Prepare and review weekly departmental reports.',
    'dashboard.php' => 'Monitor support operations and system performance.',
    'users.php' => 'Manage system users and account information.',
    'departments.php' => 'Manage departments and organisational structure.',
    'outlets.php' => 'Manage outlets, warehouses, and operational locations.',
    'designations.php' => 'Manage staff designations and job titles.',
    'manager_review.php' => 'Review submitted reports and employee performance.',
    'analytics.php' => 'View organisational reporting and performance analytics.',
    'email_settings.php' => 'Configure system email and notification settings.',
    'notifications.php' => 'View system notifications and alerts.'
];
$topbarDescription = $pageDescriptions[$currentPage] ?? 'Support and operational management platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard' ?></title>
    <link rel="icon" type="image/x-icon" href="../assets/images/customer-service.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-logo"><img src="../assets/images/logo.png" alt="Diva Cakes Logo"></div>
                <div class="brand-text">
                    <div class="brand-title">Diva Cakes</div>
                    <div class="brand-sub">Support Management</div>
                </div>
            </div>
            <div class="menu-title">Main Menu</div>
            <nav class="sidebar-menu">
                <a href="support_tickets.php" class="<?= $currentPage === 'support_tickets.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-headset"></i><span>Support Tickets</span></a>
                <a href="weekly_reports.php" class="<?= $currentPage === 'weekly_reports.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-file-lines"></i><span>Weekly Reports</span></a>

                <?php if ($hasFacilityAccess): ?>
                <div class="menu-title sidebar-section-title">Facility Management</div>
                <?php if ($canViewFacilityDashboard): ?>
                <a href="facility_dashboard.php"
                    class="<?= $currentPage === 'facility_dashboard.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-gauge-high"></i><span>Facility Dashboard</span></a>
                <?php endif; ?>
                <a href="support_tickets.php?tab=facility"><i
                        class="fa-solid fa-screwdriver-wrench"></i><span>Maintenance Requests</span></a>
                <?php if ($canViewFacilityAssets): ?>
                <a href="facility_assets.php"
                    class="<?= in_array($currentPage, ['facility_assets.php', 'facility_asset_view.php'], true) ? 'active' : '' ?>"><i
                        class="fa-solid fa-boxes-stacked"></i><span>Assets</span></a>
                <?php endif; ?>
                <?php if ($canViewPreventiveMaintenance): ?>
                <a href="preventive_maintenance.php"
                    class="<?= $currentPage === 'preventive_maintenance.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-calendar-check"></i><span>Preventive Maintenance</span></a>
                <?php endif; ?>
                <?php if ($canViewFacilityVendors): ?>
                <a href="facility_vendors.php" class="<?= $currentPage === 'facility_vendors.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-handshake"></i><span>Vendors</span></a>
                <?php endif; ?>
                <?php if ($canViewFacilityReports): ?>
                <a href="facility_reports.php" class="<?= $currentPage === 'facility_reports.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-chart-column"></i><span>Facility Reports</span></a>
                <?php endif; ?>
                <?php if ($canManageFacilityAccess): ?>
                <a href="facility_roles.php" class="<?= $currentPage === 'facility_roles.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-user-gear"></i><span>Facility Access</span></a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                <div class="menu-title sidebar-section-title">Administration</div>
                <a href="dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-chart-line"></i><span>Dashboard</span></a>
                <a href="it_reports.php" class="<?= $currentPage === 'it_reports.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-chart-column"></i><span>IT Reports</span></a>
                <a href="kpis.php" style="display:none;"><i class="fa-solid fa-bullseye"></i><span>KPIs</span></a>
                <a href="users.php" class="<?= $currentPage === 'users.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-users"></i><span>Users</span></a>
                <a href="departments.php" class="<?= $currentPage === 'departments.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-building"></i><span>Departments</span></a>
                <a href="outlets.php" class="<?= $currentPage === 'outlets.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-location-dot"></i><span>Cost Centres</span></a>
                <a href="designations.php" class="<?= $currentPage === 'designations.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-id-badge"></i><span>Designations</span></a>
                <!-- <a href="manager_review.php" class="<?= $currentPage === 'manager_review.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-user-check"></i><span>Manager Review</span></a>
                <a href="analytics.php" class="<?= $currentPage === 'analytics.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-chart-pie"></i><span>Analytics</span></a> -->
                <a href="email_settings.php" class="<?= $currentPage === 'email_settings.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-envelope-circle-check"></i><span>Email Setup</span></a>
                <!-- <a href="notifications.php" class="<?= $currentPage === 'notifications.php' ? 'active' : '' ?>"><i
                        class="fa-solid fa-bell"></i><span>Notifications</span></a> -->
                <?php endif; ?>
            </nav>
            <div class="sidebar-bottom">
                <a href="../actions/logout.php" class="logout-btn" title="Logout"><span class="logout-icon"><i
                            class="fa-solid fa-right-from-bracket"></i></span></a>
            </div>
        </aside>

        <div class="main-wrapper">
            <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>
            <header class="topbar">
                <div class="topbar-left">
                    <h2><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard' ?></h2>
                    <p><?= htmlspecialchars($topbarDescription) ?></p>
                </div>
                <div class="topbar-right">
                    <a href="users.php" class="user-card" title="View my profile">
                        <div class="user-avatar"><?= strtoupper(substr(trim($fullname), 0, 1)) ?></div>
                        <div class="user-details">
                            <div class="user-name"><?= htmlspecialchars($fullname) ?></div>
                            <div class="user-role">
                                <?= htmlspecialchars(ucfirst($role)) ?><?php if ($department !== ''): ?> •
                                <?= htmlspecialchars($department) ?><?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
            </header>
            <main class="content-area">
                <script>
                function toggleSidebar() {
                    document
                        .getElementById('sidebar')
                        .classList
                        .toggle('active');
                }

                window.addEventListener('click', function(e) {
                    const sidebar = document.getElementById('sidebar');
                    const button = document.querySelector('.mobile-menu-btn');
                    if (window.innerWidth <= 900 && sidebar && button && !sidebar.contains(e.target) && !button
                        .contains(e.target)) {
                        sidebar.classList.remove('active');
                    }

                });

                document.addEventListener('DOMContentLoaded', function() {
                    const messages = document.querySelectorAll(
                        '.message, .es-alert, .fv-alert, .fr-alert, .fra-alert, .pm-alert, .mv-alert, .fwr-alert'
                    );
                    messages.forEach(function(msg) {
                        setTimeout(function() {
                            msg.classList.add('fade-out');
                            setTimeout(function() {
                                msg.remove();
                            }, 600);
                        }, 4000);
                    });

                });
                </script>