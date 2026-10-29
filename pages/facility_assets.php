<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = 'Facility Assets';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$canManage = facilityHasRole(
    $connection,
    $uid,
    ['facility_officer', 'facility_project_manager', 'facility_admin']
);

$q = trim($_GET['q'] ?? '');
$outletId = (int) ($_GET['outlet'] ?? 0);
$categoryId = (int) ($_GET['category'] ?? 0);
$status = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $where[] = "(a.asset_code LIKE ? OR a.asset_name LIKE ? OR a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)";
    $like = "%{$q}%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($outletId > 0) {
    $where[] = "a.outlet_id = ?";
    $params[] = $outletId;
    $types .= 'i';
}

if ($categoryId > 0) {
    $where[] = "a.category_id = ?";
    $params[] = $categoryId;
    $types .= 'i';
}

$validStatuses = ['operational', 'partially_operational', 'down', 'retired'];

if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[] = "a.operational_status = ?";
    $params[] = $status;
    $types .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        a.*,
        o.warehouse_name,
        o.company,
        c.category_name
    FROM facility_assets a
    JOIN outlets o ON o.id = a.outlet_id
    JOIN facility_asset_categories c ON c.id = a.category_id
    {$whereSql}
    ORDER BY a.updated_at DESC, a.id DESC
";

$stmt = mysqli_prepare($connection, $sql);

if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$assets = mysqli_stmt_get_result($stmt);

$outlets = mysqli_query(
    $connection,
    "SELECT id, warehouse_name FROM outlets WHERE status='active' ORDER BY warehouse_name"
);

$categories = mysqli_query(
    $connection,
    "SELECT id, category_name FROM facility_asset_categories WHERE status='active' ORDER BY category_name"
);

$summary = [
    'total' => 0,
    'operational' => 0,
    'down' => 0,
    'pm_due' => 0
];

$summaryRes = mysqli_query(
    $connection,
    "
    SELECT
        COUNT(*) AS total,
        SUM(operational_status='operational') AS operational,
        SUM(operational_status='down') AS down_count,
        SUM(
            preventive_maintenance_required=1
            AND next_maintenance_date IS NOT NULL
            AND next_maintenance_date <= CURDATE()
        ) AS pm_due
    FROM facility_assets
    "
);

if ($summaryRes && ($row = mysqli_fetch_assoc($summaryRes))) {
    $summary['total'] = (int) ($row['total'] ?? 0);
    $summary['operational'] = (int) ($row['operational'] ?? 0);
    $summary['down'] = (int) ($row['down_count'] ?? 0);
    $summary['pm_due'] = (int) ($row['pm_due'] ?? 0);
}

include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/facility-assets.css?v=1.0">

<div class="fa-page">

    <div class="fa-page-header">
        <div>
            <div class="fa-eyebrow">Facility Management</div>
            <h2>Asset Register</h2>
            <p>View and manage registered equipment and facility assets across all outlets.</p>
        </div>

        <?php if ($canManage): ?>

            <div class="fa-header-actions">

                <a
                    href="../assets/templates/facility_asset_bulk_upload_template.xlsx"
                    class="fa-btn fa-btn-light"
                    download
                >
                    <i class="fa-solid fa-file-excel"></i>
                    Download Template
                </a>

                <a
                    href="facility_asset_bulk_upload.php"
                    class="fa-btn fa-btn-light"
                >
                    <i class="fa-solid fa-file-arrow-up"></i>
                    Bulk Upload
                </a>

                <a
                    href="facility_asset_register.php"
                    class="fa-btn fa-btn-primary"
                >
                    <i class="fa-solid fa-plus"></i>
                    Add Asset
                </a>

            </div>

        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_text'])): ?>
        <div class="fa-alert <?= facilityH($_SESSION['flash_type']) ?>">
            <?= facilityH($_SESSION['flash_text']) ?>
        </div>
        <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="fa-summary-grid">

        <div class="fa-summary-card">
            <div class="fa-summary-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
                <span>Total Assets</span>
                <strong><?= $summary['total'] ?></strong>
            </div>
        </div>

        <div class="fa-summary-card">
            <div class="fa-summary-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <span>Operational</span>
                <strong><?= $summary['operational'] ?></strong>
            </div>
        </div>

        <div class="fa-summary-card">
            <div class="fa-summary-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <span>Down</span>
                <strong><?= $summary['down'] ?></strong>
            </div>
        </div>

        <div class="fa-summary-card">
            <div class="fa-summary-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <span>PM Due</span>
                <strong><?= $summary['pm_due'] ?></strong>
            </div>
        </div>

    </div>

    <div class="fa-panel">

        <form method="GET" class="fa-filter-bar">

            <div class="fa-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="text"
                    name="q"
                    value="<?= facilityH($q) ?>"
                    placeholder="Search code, asset, brand, model or serial..."
                >
            </div>

            <select name="outlet">
                <option value="0">All Outlets</option>
                <?php while ($o = mysqli_fetch_assoc($outlets)): ?>
                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= $outletId === (int) $o['id'] ? 'selected' : '' ?>
                    >
                        <?= facilityH($o['warehouse_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="category">
                <option value="0">All Categories</option>
                <?php while ($c = mysqli_fetch_assoc($categories)): ?>
                    <option
                        value="<?= (int) $c['id'] ?>"
                        <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>
                    >
                        <?= facilityH($c['category_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="status">
                <option value="">All Statuses</option>
                <option value="operational" <?= $status === 'operational' ? 'selected' : '' ?>>Operational</option>
                <option value="partially_operational" <?= $status === 'partially_operational' ? 'selected' : '' ?>>Partially Operational</option>
                <option value="down" <?= $status === 'down' ? 'selected' : '' ?>>Down</option>
                <option value="retired" <?= $status === 'retired' ? 'selected' : '' ?>>Retired</option>
            </select>

            <button type="submit" class="fa-btn fa-btn-primary fa-btn-compact">Filter</button>

            <a href="facility_assets.php" class="fa-btn fa-btn-light fa-btn-compact">Reset</a>

        </form>

        <div class="fa-table-wrap">

            <table class="fa-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Asset</th>
                        <th>Category</th>
                        <th>Outlet</th>
                        <th>Brand / Model</th>
                        <th>Status</th>
                        <th>Next PM</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($assets && mysqli_num_rows($assets) > 0): ?>
                    <?php $sn = 1; ?>

                    <?php while ($asset = mysqli_fetch_assoc($assets)): ?>

                        <?php
                        $statusClass = 'status-' . str_replace(
                            '_',
                            '-',
                            $asset['operational_status']
                        );
                        ?>

                        <tr onclick="window.location='facility_asset_view.php?id=<?= (int) $asset['id'] ?>'">

                            <td class="fa-sn"><?= $sn++ ?></td>

                            <td>
                                <div class="fa-asset-main">
                                    <strong><?= facilityH($asset['asset_code']) ?></strong>
                                    <span><?= facilityH($asset['asset_name']) ?></span>

                                    <?php if (!empty($asset['serial_number'])): ?>
                                        <small>S/N: <?= facilityH($asset['serial_number']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td><?= facilityH($asset['category_name']) ?></td>

                            <td>
                                <div class="fa-outlet">
                                    <strong><?= facilityH($asset['warehouse_name']) ?></strong>

                                    <?php if (!empty($asset['location_detail'])): ?>
                                        <small><?= facilityH($asset['location_detail']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div class="fa-brand">
                                    <span><?= facilityH($asset['brand'] ?: '—') ?></span>
                                    <small><?= facilityH($asset['model'] ?: '') ?></small>
                                </div>
                            </td>

                            <td>
                                <span class="fa-status <?= facilityH($statusClass) ?>">
                                    <?= facilityH(
                                        ucwords(
                                            str_replace('_', ' ', $asset['operational_status'])
                                        )
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($asset['next_maintenance_date'])): ?>

                                    <?php
                                    $isDue =
                                        strtotime($asset['next_maintenance_date'])
                                        <= strtotime(date('Y-m-d'));
                                    ?>

                                    <span class="fa-date <?= $isDue ? 'due' : '' ?>">
                                        <?= facilityH(
                                            date(
                                                'M d, Y',
                                                strtotime($asset['next_maintenance_date'])
                                            )
                                        ) ?>
                                    </span>

                                <?php else: ?>
                                    <span class="fa-muted">—</span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="fa-empty">
                            <div class="fa-empty-state">
                                <i class="fa-solid fa-box-open"></i>
                                <strong>No assets found</strong>
                                <span>Try adjusting the filters or register a new asset.</span>
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php include '../inc/footer.php'; ?>
