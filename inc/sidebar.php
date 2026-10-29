<!-- SIDEBAR -->
<aside class="w-72 bg-indigo-900 text-white fixed top-0 left-0 h-screen shadow-2xl z-50">
    <!-- LOGO -->
    <div class="px-8 py-6 border-b border-indigo-800">
        <h1 class="text-2xl font-extrabold">
            KPI System
        </h1>
        <p class="text-indigo-200 text-sm mt-1">
            Weekly Reporting Platform
        </p>
    </div>
    <!-- USER -->
    <div class="px-8 py-6 border-b border-indigo-800">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-indigo-700 flex items-center justify-center text-xl font-bold">
                <?= strtoupper(substr($_SESSION['fullname'], 0, 1)); ?>
            </div>
            <div>
                <h3 class="font-bold text-lg">
                    <?= $_SESSION['fullname']; ?>
                </h3>
                <p class="text-indigo-200 text-sm capitalize">
                    <?= $_SESSION['role']; ?>
                </p>
            </div>
        </div>
    </div>
    <?php
    $userRole = strtolower(trim($_SESSION['role'] ?? 'user'));
    $isAdmin = ($userRole === 'admin');
    ?>

    <!-- NAVIGATION -->
    <nav class="p-6 space-y-3 overflow-y-auto h-[calc(100vh-250px)]">

        <?php if ($isAdmin): ?>
        <a href="dashboard.php"
            class="flex items-center gap-4 px-5 py-4 rounded-2xl bg-indigo-800 hover:bg-indigo-700 transition">
            <i class="fas fa-chart-line text-lg"></i>
            <span class="font-medium">Dashboard</span>
        </a>
        <?php endif; ?>

        <!-- Available to every logged-in user -->
        <a href="it_tickets.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-headset text-lg"></i>
            <span class="font-medium">Support Tickets</span>
        </a>

        <!-- Available to every logged-in user -->
        <a href="reports.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-file-alt text-lg"></i>
            <span class="font-medium">Weekly Reports</span>
        </a>

        <?php if ($isAdmin): ?>

        <a href="create-report.php"
            class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-plus-circle text-lg"></i>
            <span class="font-medium">Create Report</span>
        </a>

        <a href="kpis.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-bullseye text-lg"></i>
            <span class="font-medium">KPI Management</span>
        </a>

        <a href="departments.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-building text-lg"></i>
            <span class="font-medium">Departments</span>
        </a>

        <a href="users.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-users text-lg"></i>
            <span class="font-medium">Users</span>
        </a>

        <a href="analytics.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-chart-pie text-lg"></i>
            <span class="font-medium">Analytics</span>
        </a>

        <a href="notifications.php"
            class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-bell text-lg"></i>
            <span class="font-medium">Notifications</span>
        </a>

        <a href="settings.php" class="flex items-center gap-4 px-5 py-4 rounded-2xl hover:bg-indigo-800 transition">
            <i class="fas fa-cog text-lg"></i>
            <span class="font-medium">Settings</span>
        </a>

        <?php endif; ?>

    </nav>
    <!-- FOOT -->
    <div class="absolute bottom-0 left-0 w-full p-6 border-t border-indigo-800">
        <a href="../actions/logout.php"
            class="flex items-center justify-center gap-3 bg-red-500 hover:bg-red-600 transition px-5 py-4 rounded-2xl font-bold">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>
</aside>