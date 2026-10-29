<?php require '../inc/auth.php';
require '../inc/connection.php';
mysqli_report(MYSQLI_REPORT_OFF);
$pageTitle = "Outlets Management";
$message = "";
$error = "";
function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
// CSRF Token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = strtolower($_SESSION['role'] ?? 'staff');
$canManageOutlets = in_array($currentRole, ['admin', 'manager'], true);
$isAdmin = ($currentRole === 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF Validation
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";

    } elseif (!$canManageOutlets) {
        $error = "You are not authorized to manage outlets.";

    } else {

        // CREATE
        if (isset($_POST['save_outlet'])) {

            $warehouseName = trim($_POST['warehouse_name'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($warehouseName === '') {
                $error = "Warehouse name is required.";

            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid outlet email address.";

            } else {

                $stmt = mysqli_prepare(
                    $connection,
                    "INSERT INTO outlets
                    (warehouse_name, company, email, address, status)
                    VALUES (?, ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "sssss",
                    $warehouseName,
                    $company,
                    $email,
                    $address,
                    $status
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Outlet created successfully.";
                } else {
                    $error = "Unable to create outlet.";
                }
            }
        }

        // UPDATE
        if (isset($_POST['update_outlet'])) {

            $outletId = (int) ($_POST['outlet_id'] ?? 0);
            $warehouseName = trim($_POST['warehouse_name'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($outletId <= 0) {
                $error = "Invalid outlet selected.";

            } elseif ($warehouseName === '') {
                $error = "Warehouse name is required.";

            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid outlet email address.";

            } else {

                $stmt = mysqli_prepare(
                    $connection,
                    "UPDATE outlets
             SET warehouse_name = ?, company = ?, email = ?, address = ?, status = ?
             WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "sssssi",
                    $warehouseName,
                    $company,
                    $email,
                    $address,
                    $status,
                    $outletId
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Outlet updated successfully.";
                } else {
                    $error = "Unable to update outlet.";
                }
            }
        }

        // DELETE
        if (isset($_POST['delete_outlet'])) {

            $outletId = (int) ($_POST['outlet_id'] ?? 0);

            if ($outletId > 0) {

                $delStmt = mysqli_prepare(
                    $connection,
                    "DELETE FROM outlets WHERE id = ?"
                );

                mysqli_stmt_bind_param($delStmt, "i", $outletId);

                if (mysqli_stmt_execute($delStmt)) {
                    $message = "Outlet deleted successfully.";
                } else {
                    $error = "Unable to delete outlet.";
                }
            }
        }

    }
}

/* =========================================================
   FILTERS
========================================================= */

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
$companyFilter = trim($_GET['company'] ?? '');

$whereClauses = [];
$params = [];
$types = "";

if ($search !== '') {

    $safeSearch = addcslashes(
        mysqli_real_escape_string($connection, $search),
        '%_\\'
    );

    $likeParam = "%{$safeSearch}%";

    $whereClauses[] = "(
        warehouse_name LIKE ?
        OR company LIKE ?
        OR email LIKE ?
        OR address LIKE ?
    )";

    $params[] = $likeParam;
    $params[] = $likeParam;
    $params[] = $likeParam;
    $params[] = $likeParam;

    $types .= "ssss";
}

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $whereClauses[] = "status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($companyFilter !== '') {
    $whereClauses[] = "company = ?";
    $params[] = $companyFilter;
    $types .= "s";
}

$where = "";

if (!empty($whereClauses)) {
    $where = "WHERE " . implode(" AND ", $whereClauses);
}

/* =========================================================
   OUTLET LIST
========================================================= */

$sql = "SELECT
            id,
            warehouse_name,
            company,
            email,
            address,
            erpnext_warehouse_id,
            status,
            created_at
        FROM outlets
        {$where}
        ORDER BY created_at DESC";

$stmt = mysqli_prepare($connection, $sql);

if ($stmt === false) {
    die("Query Error: " . mysqli_error($connection));
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$outletsResult = mysqli_stmt_get_result($stmt);
$outletsList = $outletsResult ? mysqli_fetch_all($outletsResult, MYSQLI_ASSOC) : [];

// Quick KPI stats derived from the current filtered set
$totalCount = count($outletsList);
$activeCount = 0;
$syncedCount = 0;
foreach ($outletsList as $o) {
    if (strtolower($o['status'] ?? '') === 'active') {
        $activeCount++;
    }
    if (!empty($o['erpnext_warehouse_id'])) {
        $syncedCount++;
    }
}
$inactiveCount = $totalCount - $activeCount;

$companiesResult = mysqli_query(
    $connection,
    "SELECT DISTINCT company
     FROM outlets
     WHERE company IS NOT NULL
     AND company != ''
     ORDER BY company"
);
include '../inc/header.php'; ?>
<style>
    :root {
        --brand-1: #9a3412;
        --brand-2: #db2777;
        --bg-page: #f6f7fb;
        --surface: #ffffff;
        --surface-soft: #fbf7f4;
        --border: #eef1f6;
        --border-strong: #e2e8f0;
        --text: #1e293b;
        --text-muted: #6b7686;
        --text-faint: #94a3b8;
        --radius-lg: 22px;
        --radius-md: 14px;
        --radius-sm: 10px;
    }

    body {
        background: var(--bg-page);
    }

    .page-wrap {
        width: 100%;
        max-width: 1280px;
        margin: 0 auto;
    }

    /* ---------- Page Header ---------- */
    .ov-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 22px;
    }

    .ov-header-text h1 {
        font-size: 22px;
        font-weight: 800;
        color: var(--text);
        margin: 0 0 4px;
        letter-spacing: -0.3px;
    }

    .ov-header-text p {
        margin: 0;
        color: var(--text-muted);
        font-size: 13.5px;
    }

    .ov-header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ---------- Buttons ---------- */
    .btn {
        border: none;
        cursor: pointer;
        border-radius: var(--radius-sm);
        padding: 11px 18px;
        font-weight: 700;
        font-size: 12.5px;
        text-decoration: none;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    }

    .btn:active {
        transform: translateY(1px);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
        color: #fff;
        box-shadow: 0 10px 20px -8px rgba(219, 39, 119, 0.55);
    }

    .btn-primary:hover {
        box-shadow: 0 14px 24px -8px rgba(219, 39, 119, 0.65);
    }

    .btn-light {
        background: #fff;
        color: #7c2d12;
        border: 1px solid #f1d9c8;
    }

    .btn-light:hover {
        background: #fff7ed;
    }

    .btn-danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .btn-danger:hover {
        background: #fecaca;
    }

    .btn-ghost {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--border-strong);
    }

    .btn-ghost:hover {
        background: #f8fafc;
        color: var(--text);
    }

    .btn-block {
        width: 100%;
    }

    /* ---------- KPI Stat Row ---------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 22px;
    }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        min-width: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        color: #fff;
    }

    .stat-icon.total {
        background: linear-gradient(135deg, #9a3412, #db2777);
    }

    .stat-icon.active {
        background: linear-gradient(135deg, #059669, #10b981);
    }

    .stat-icon.inactive {
        background: linear-gradient(135deg, #64748b, #94a3b8);
    }

    .stat-icon.synced {
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
    }

    .stat-info h4 {
        margin: 0 0 2px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--text-faint);
        font-weight: 700;
    }

    .stat-info .num {
        font-size: 21px;
        font-weight: 800;
        color: var(--text);
        line-height: 1;
    }

    /* ---------- Panels ---------- */
    .panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        margin-bottom: 22px;
    }

    /* ---------- Filter Bar ---------- */
    .filter-panel {
        padding: 16px 18px;
    }

    .search-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .field-icon {
        position: relative;
        flex: 2;
        min-width: 220px;
    }

    .field-icon i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-faint);
        font-size: 13px;
        pointer-events: none;
    }

    .field-icon input {
        padding-left: 38px !important;
    }

    .search-row select {
        flex: 1;
        min-width: 160px;
    }

    .search-row .btn {
        flex: none;
    }

    .form-control {
        width: 100%;
        height: 42px;
        border: 1px solid var(--border-strong);
        border-radius: var(--radius-sm);
        padding: 0 14px;
        outline: none;
        font-size: 13px;
        background: #fff;
        color: var(--text);
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .form-control:focus {
        border-color: var(--brand-2);
        box-shadow: 0 0 0 4px rgba(219, 39, 119, 0.10);
    }

    textarea.form-control {
        min-height: 80px;
        padding: 12px 14px;
        resize: vertical;
        height: auto;
    }

    /* ---------- Table Card ---------- */
    .table-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .table-card-head h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .count-pill {
        background: var(--surface-soft);
        color: var(--brand-1);
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
        border: 1px solid #f3e2d3;
    }

    .table-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
    }

    .outlet-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }

    .outlet-table th {
        text-align: left;
        background: var(--surface-soft);
        color: #7c2d12;
        padding: 13px 16px;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: .03em;
        border-bottom: 1px solid #f3e2d3;
        white-space: nowrap;
    }

    .outlet-table td {
        padding: 13px 16px;
        font-size: 13px;
        color: #334155;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .outlet-table tbody tr {
        transition: background .12s ease;
    }

    .outlet-table tbody tr:hover {
        background: #fbfbfd;
    }

    .outlet-table tbody tr:last-child td {
        border-bottom: none;
    }

    .sn-cell {
        color: var(--text-faint);
        font-weight: 600;
        width: 36px;
    }

    /* Outlet identity cell */
    .outlet-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .outlet-avatar {
        width: 38px;
        height: 38px;
        min-width: 38px;
        border-radius: 11px;
        background: linear-gradient(135deg, #9a3412, #db2777);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 14px;
        text-transform: uppercase;
    }

    .outlet-meta {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }

    .outlet-name-btn {
        border: none;
        background: transparent;
        color: #1e293b;
        font-weight: 700;
        cursor: pointer;
        padding: 0;
        font-size: 13.5px;
        white-space: nowrap;
        text-align: left;
    }

    .outlet-name-btn:hover {
        color: var(--brand-2);
        text-decoration: underline;
    }

    .outlet-sub {
        font-size: 11.5px;
        color: var(--text-faint);
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .company-cell {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .company-main {
        color: #334155;
        font-weight: 600;
    }

    .company-sub {
        font-size: 11px;
        color: var(--text-faint);
    }

    /* ---------- Badges ---------- */
    .badge {
        padding: 5px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .badge-active {
        background: #dcfce7;
        color: #166534;
    }

    .badge-active::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #22c55e;
    }

    .badge-inactive {
        background: #f1f5f9;
        color: #475569;
    }

    .badge-inactive::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #94a3b8;
    }

    .badge-sync {
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 10.5px;
    }

    /* ---------- Row Actions ---------- */
    .row-actions {
        display: flex;
        gap: 6px;
    }

    .icon-btn {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        border: 1px solid var(--border-strong);
        background: #fff;
        color: var(--text-muted);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 12.5px;
        transition: all .15s ease;
    }

    .icon-btn:hover {
        background: var(--surface-soft);
        color: var(--brand-2);
        border-color: #f3e2d3;
    }

    /* ---------- Empty State ---------- */
    .empty-state {
        text-align: center;
        padding: 56px 20px;
    }

    .empty-state i {
        font-size: 32px;
        color: #e2c9b8;
        margin-bottom: 14px;
        display: block;
    }

    .empty-state p {
        color: var(--text-faint);
        font-size: 13.5px;
        margin: 0 0 16px;
    }

    /* ---------- Modals ---------- */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        backdrop-filter: blur(2px);
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-box {
        width: 100%;
        max-width: 560px;
        max-height: 92vh;
        overflow-y: auto;
        background: #fff;
        border-radius: 24px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.25);
        animation: modalPop .18s ease;
    }

    @keyframes modalPop {
        from {
            opacity: 0;
            transform: scale(.97) translateY(6px);
        }

        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 22px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(135deg, #fff7ed, #fdf2f8);
        border-radius: 24px 24px 0 0;
    }

    .modal-header-title {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .modal-header-icon {
        width: 38px;
        height: 38px;
        border-radius: 11px;
        background: linear-gradient(135deg, #9a3412, #db2777);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 16.5px;
        color: var(--text);
    }

    .modal-header p {
        margin: 2px 0 0;
        font-size: 11.5px;
        color: var(--text-muted);
    }

    .modal-close {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 10px;
        background: #fee2e2;
        color: #991b1b;
        font-size: 16px;
        cursor: pointer;
        font-weight: 600;
    }

    .modal-body {
        padding: 22px 24px 24px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 6px;
        font-size: 12px;
        font-weight: 700;
        color: #475569;
    }

    .form-group label i {
        color: var(--brand-2);
        font-size: 11px;
    }

    .form-row-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    .modal-actions .btn {
        flex: 1;
    }

    /* ---------- Toast ---------- */
    .toast-alert {
        position: fixed;
        right: 24px;
        bottom: 24px;
        min-width: 320px;
        max-width: 420px;
        padding: 16px 18px;
        border-radius: 16px;
        color: #fff;
        font-size: 13px;
        font-weight: 500;
        z-index: 99999;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
        display: flex;
        align-items: center;
        gap: 10px;
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

    /* ---------- Sync Overlay ---------- */
    .sync-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.75);
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .sync-overlay.active {
        display: flex;
    }

    .sync-card {
        background: #fff;
        border-radius: 24px;
        padding: 34px 42px;
        text-align: center;
        max-width: 400px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
    }

    .sync-icon {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        margin: 0 auto 16px;
        background: linear-gradient(135deg, #9a3412, #db2777);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
    }

    .sync-card h3 {
        margin: 0 0 8px;
        font-size: 18px;
        color: var(--text);
    }

    .sync-card p {
        color: #64748b;
        font-size: 13px;
        margin: 0 0 20px;
    }

    .progress-track {
        width: 100%;
        height: 8px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 12px;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(135deg, #9a3412, #db2777);
        width: 0%;
        animation: progressAnim 2s ease-in-out infinite;
    }

    @keyframes progressAnim {
        0% {
            width: 0%;
            margin-left: 0%;
        }

        50% {
            width: 70%;
            margin-left: 30%;
        }

        100% {
            width: 0%;
            margin-left: 100%;
        }
    }

    .sync-card small {
        color: #94a3b8;
        font-size: 12px;
    }

    @media (max-width: 900px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .form-row-split {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 560px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .ov-header {
            align-items: flex-start;
        }
    }
</style>

<div class="page-wrap">

    <!-- Page Header -->
    <div class="ov-header">
        <div class="ov-header-text">
            <h1>Outlets &amp; Warehouses</h1>
            <p>Manage every DivaCakes outlet, sync warehouse data from ERPNext, and keep locations up to date.</p>
        </div>
        <div class="ov-header-actions">
            <?php if ($isAdmin): ?>
                <a href="sync_erpnext_warehouses.php" class="btn btn-light" onclick="showSyncProgress()">
                    <i class="fa-solid fa-rotate"></i> Sync from ERPNext
                </a>
            <?php endif; ?>
            <?php if ($canManageOutlets): ?>
                <button type="button" class="btn btn-primary" onclick="openAddOutletModal()">
                    <i class="fa-solid fa-plus"></i> Add Outlet
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fa-solid fa-shop"></i></div>
            <div class="stat-info">
                <h4>Total Outlets</h4>
                <div class="num"><?= (int) $totalCount ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon active"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-info">
                <h4>Active</h4>
                <div class="num"><?= (int) $activeCount ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon inactive"><i class="fa-solid fa-circle-minus"></i></div>
            <div class="stat-info">
                <h4>Inactive</h4>
                <div class="num"><?= (int) $inactiveCount ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon synced"><i class="fa-solid fa-cloud"></i></div>
            <div class="stat-info">
                <h4>ERPNext Synced</h4>
                <div class="num"><?= (int) $syncedCount ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="panel filter-panel">
        <form method="GET" class="search-row">
            <div class="field-icon">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="form-control" placeholder="Search by name, company, or address"
                    value="<?= h($search) ?>">
            </div>
            <select name="company" class="form-control">
                <option value="">All Companies</option>
                <?php if ($companiesResult && mysqli_num_rows($companiesResult) > 0): ?>
                    <?php while ($c = mysqli_fetch_assoc($companiesResult)): ?>
                        <option value="<?= h($c['company']) ?>" <?= $companyFilter === $c['company'] ? 'selected' : '' ?>>
                            <?= h($c['company']) ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
            <select name="status_filter" class="form-control">
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Search</button>
            <a href="outlets.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a>
        </form>
    </div>

    <!-- Outlet Table -->
    <div class="panel">
        <div class="table-card-head">
            <h3><i class="fa-solid fa-store" style="color:var(--brand-2); font-size:13px;"></i> Outlet Directory
                <span class="count-pill"><?= (int) $totalCount ?> total</span>
            </h3>
        </div>
        <div class="table-wrap">
            <table class="outlet-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Outlet</th>
                        <th>Company</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($outletsList)): ?>
                        <?php $sn = 1; ?>
                        <?php foreach ($outletsList as $o): ?>
                            <?php
                            $status = strtolower($o['status'] ?? 'inactive');
                            $statusClass = 'badge-' . $status;
                            $isErpSynced = !empty($o['erpnext_warehouse_id']);
                            $initial = strtoupper(substr(trim($o['warehouse_name'] ?? '?'), 0, 1)) ?: '?';
                            ?>
                            <tr>
                                <td class="sn-cell"><?= $sn++ ?></td>
                                <td>
                                    <div class="outlet-cell">
                                        <div class="outlet-avatar"><?= h($initial) ?></div>
                                        <div class="outlet-meta">
                                            <?php if ($canManageOutlets): ?>
                                                <button type="button" class="outlet-name-btn" data-id="<?= (int) $o['id'] ?>"
                                                    data-name="<?= h($o['warehouse_name']) ?>"
                                                    data-company="<?= h($o['company']) ?>" data-email="<?= h($o['email'] ?? '') ?>"
                                                    data-address="<?= h($o['address']) ?>" data-status="<?= h($o['status']) ?>">
                                                    <?= h($o['warehouse_name']) ?>
                                                </button>
                                            <?php else: ?>
                                                <span style="font-weight:700; color:#1e293b;"><?= h($o['warehouse_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($o['address'])): ?>
                                                <span class="outlet-sub" title="<?= h($o['address']) ?>">
                                                    <i class="fa-solid fa-location-dot" style="font-size:10px;"></i>
                                                    <?= h($o['address']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="company-cell">
                                        <span class="company-main"><?= h($o['company'] ?? '-') ?></span>
                                        <?php if (!empty($o['email'])): ?>
                                            <span class="company-sub"><?= h($o['email']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isErpSynced): ?>
                                        <span class="badge badge-sync" title="From ERPNext">
                                            <i class="fa-solid fa-cloud"></i> ERPNext
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= h($statusClass) ?>"><?= h($status) ?></span>
                                </td>
                                <td><?= h(date('M d, Y', strtotime($o['created_at']))) ?></td>
                                <td>
                                    <?php if ($canManageOutlets): ?>
                                        <div class="row-actions">
                                            <button type="button" class="icon-btn" title="Edit outlet"
                                                data-id="<?= (int) $o['id'] ?>" data-name="<?= h($o['warehouse_name']) ?>"
                                                data-company="<?= h($o['company']) ?>" data-email="<?= h($o['email'] ?? '') ?>"
                                                data-address="<?= h($o['address']) ?>" data-status="<?= h($o['status']) ?>"
                                                onclick="openOutletModal(this.dataset)">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--text-faint); font-size:11px;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fa-solid fa-box-open"></i>
                                    <p>No outlets match your current filters.</p>
                                    <?php if ($canManageOutlets): ?>
                                        <button type="button" class="btn btn-primary" onclick="openAddOutletModal()">
                                            <i class="fa-solid fa-plus"></i> Add your first outlet
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Outlet Modal -->
<div class="modal-overlay" id="addOutletModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-title">
                <div class="modal-header-icon"><i class="fa-solid fa-store"></i></div>
                <div>
                    <h3>Add Outlet</h3>
                    <p>Create a new warehouse / outlet record</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeAddOutletModal()">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="form-group">
                    <label><i class="fa-solid fa-shop"></i> Warehouse Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="warehouse_name" class="form-control" required maxlength="150"
                        placeholder="e.g. Diva Cakes - Victoria Island">
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-building"></i> Company</label>
                    <input type="text" name="company" class="form-control" maxlength="150"
                        placeholder="e.g. Diva Cakes & Confections Ltd">
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" class="form-control" maxlength="190"
                        placeholder="e.g. outlet@divacakes.com">
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-location-dot"></i> Address</label>
                    <textarea name="address" class="form-control" placeholder="Enter full address"></textarea>
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-toggle-on"></i> Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <button type="submit" name="save_outlet" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-check"></i> Save Outlet
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Outlet Modal -->
<div class="modal-overlay" id="editOutletModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-title">
                <div class="modal-header-icon"><i class="fa-solid fa-pen"></i></div>
                <div>
                    <h3>Edit Outlet</h3>
                    <p>Update outlet details or remove it</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeOutletModal()">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="outlet_id" id="edit_outlet_id">
                <div class="form-group">
                    <label><i class="fa-solid fa-shop"></i> Warehouse Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="warehouse_name" id="edit_warehouse_name" class="form-control" required
                        maxlength="150">
                </div>
                <div class="form-row-split">
                    <div class="form-group">
                        <label><i class="fa-solid fa-building"></i> Company</label>
                        <input type="text" name="company" id="edit_company" class="form-control" maxlength="150">
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-toggle-on"></i> Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" id="edit_email" class="form-control" maxlength="190">
                </div>
                <div class="form-group">
                    <label><i class="fa-solid fa-location-dot"></i> Address</label>
                    <textarea name="address" id="edit_address" class="form-control"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" name="update_outlet" class="btn btn-primary">
                        <i class="fa-solid fa-check"></i> Save Changes
                    </button>
                    <button type="submit" name="delete_outlet" class="btn btn-danger"
                        onclick="return confirm('Delete this outlet permanently?');">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="toast-alert toast-success"><i class="fa-solid fa-circle-check"></i> <?= h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="toast-alert toast-error"><i class="fa-solid fa-circle-exclamation"></i> <?= h($error) ?></div>
<?php endif; ?>

<div id="syncOverlay" class="sync-overlay">
    <div class="sync-card">
        <div class="sync-icon"><i class="fa-solid fa-cloud-arrow-down"></i></div>
        <h3>Syncing ERPNext Warehouses</h3>
        <p>Please wait while warehouse records are being fetched and updated.</p>
        <div class="progress-track">
            <div class="progress-fill"></div>
        </div>
        <small>Connecting securely to ERPNext...</small>
    </div>
</div>

<script>
    function showSyncProgress() {
        const overlay = document.getElementById('syncOverlay');
        if (overlay) overlay.classList.add('active');
    }

    function openAddOutletModal() {
        document.getElementById('addOutletModal').classList.add('active');
    }

    function closeAddOutletModal() {
        document.getElementById('addOutletModal').classList.remove('active');
    }

    function openOutletModal(data) {
        document.getElementById('edit_outlet_id').value = data.id || '';
        document.getElementById('edit_warehouse_name').value = data.name || '';
        document.getElementById('edit_company').value = data.company || '';
        document.getElementById('edit_email').value = data.email || '';
        document.getElementById('edit_address').value = data.address || '';
        document.getElementById('edit_status').value = data.status || 'active';
        document.getElementById('editOutletModal').classList.add('active');
    }

    function closeOutletModal() {
        document.getElementById('editOutletModal').classList.remove('active');
    }

    // Event delegation for name-click editing + overlay close clicks
    document.addEventListener('click', function (e) {
        const nameBtn = e.target.closest('.outlet-name-btn');
        if (nameBtn) {
            openOutletModal({
                id: nameBtn.dataset.id,
                name: nameBtn.dataset.name,
                company: nameBtn.dataset.company,
                email: nameBtn.dataset.email,
                address: nameBtn.dataset.address,
                status: nameBtn.dataset.status
            });
            return;
        }

        const addModal = document.getElementById('addOutletModal');
        const editModal = document.getElementById('editOutletModal');

        if (e.target === addModal) closeAddOutletModal();
        if (e.target === editModal) closeOutletModal();
    });

    // Close modals with Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAddOutletModal();
            closeOutletModal();
        }
    });
</script>
<?php include '../inc/footer.php'; ?>