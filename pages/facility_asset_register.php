<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = 'Register Facility Asset';

$uid = (int) ($_SESSION['user_id'] ?? 0);

$canManage = facilityHasRole(
    $connection,
    $uid,
    ['facility_officer', 'facility_project_manager', 'facility_admin']
);

if (!$canManage) {
    header('Location: facility_assets.php');
    exit;
}

facilityCsrfToken();

function assetFlash(string $type, string $message): void
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $message;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!facilityValidateCsrf()) {
        assetFlash('error', 'Your session request expired. Please try again.');
        header('Location: facility_asset_register.php');
        exit;
    }

    $assetCode = trim($_POST['asset_code'] ?? '');
    $assetName = trim($_POST['asset_name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $outletId = (int) ($_POST['outlet_id'] ?? 0);

    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $locationDetail = trim($_POST['location_detail'] ?? '');

    $purchaseDate = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $installationDate = !empty($_POST['installation_date']) ? $_POST['installation_date'] : null;
    $purchaseCost = ($_POST['purchase_cost'] ?? '') !== '' ? (float) $_POST['purchase_cost'] : null;

    $warrantyStart = !empty($_POST['warranty_start_date']) ? $_POST['warranty_start_date'] : null;
    $warrantyEnd = !empty($_POST['warranty_end_date']) ? $_POST['warranty_end_date'] : null;
    $warrantyProvider = trim($_POST['warranty_provider'] ?? '');

    $conditionStatus = $_POST['condition_status'] ?? 'good';
    $operationalStatus = $_POST['operational_status'] ?? 'operational';

    $pmRequired = isset($_POST['pm_required']) ? 1 : 0;
    $frequencyValue = max(1, (int) ($_POST['frequency_value'] ?? 1));
    $frequencyUnit = $_POST['frequency_unit'] ?? 'month';
    $nextMaintenance = !empty($_POST['next_maintenance_date'])
        ? $_POST['next_maintenance_date']
        : null;

    $notes = trim($_POST['notes'] ?? '');

    $validConditionStatuses = [
        'excellent',
        'good',
        'fair',
        'poor',
        'replacement_candidate'
    ];

    $validOperationalStatuses = [
        'operational',
        'partially_operational',
        'down',
        'retired'
    ];

    $validFrequencyUnits = ['day', 'week', 'month', 'year'];

    if ($assetCode === '' || $assetName === '' || $categoryId <= 0 || $outletId <= 0) {
        assetFlash('error', 'Asset Tag / ID, asset name, category, and outlet are required.');
    } elseif (!in_array($conditionStatus, $validConditionStatuses, true)) {
        assetFlash('error', 'Invalid asset condition.');
    } elseif (!in_array($operationalStatus, $validOperationalStatuses, true)) {
        assetFlash('error', 'Invalid operational status.');
    } elseif (!in_array($frequencyUnit, $validFrequencyUnits, true)) {
        assetFlash('error', 'Invalid preventive maintenance frequency.');
    } elseif ($pmRequired && !$nextMaintenance) {
        assetFlash(
            'error',
            'Next maintenance date is required when preventive maintenance is enabled.'
        );
    } else {

        $outletCheck = mysqli_query(
            $connection,
            "SELECT id FROM outlets WHERE id={$outletId} AND status='active' LIMIT 1"
        );

        $categoryCheck = mysqli_query(
            $connection,
            "SELECT id FROM facility_asset_categories WHERE id={$categoryId} AND status='active' LIMIT 1"
        );

        if (!$outletCheck || mysqli_num_rows($outletCheck) === 0) {
            assetFlash('error', 'Selected outlet is not valid.');
        } elseif (!$categoryCheck || mysqli_num_rows($categoryCheck) === 0) {
            assetFlash('error', 'Selected asset category is not valid.');
        } else {

            $assetCodeCheck = mysqli_prepare($connection, "SELECT id FROM facility_assets WHERE asset_code = ? LIMIT 1");
            mysqli_stmt_bind_param($assetCodeCheck, 's', $assetCode);
            mysqli_stmt_execute($assetCodeCheck);
            $assetCodeResult = mysqli_stmt_get_result($assetCodeCheck);
            if ($assetCodeResult && mysqli_num_rows($assetCodeResult) > 0) {
                assetFlash(
                    'error',
                    'Asset Tag / ID already exists.'
                );
                header('Location: facility_asset_register.php');
                exit;
            }

            mysqli_begin_transaction($connection);
            try {

                $stmt = mysqli_prepare(
                    $connection,
                    "
                    INSERT INTO facility_assets
                    (
                        asset_code,
                        asset_name,
                        category_id,
                        outlet_id,
                        brand,
                        model,
                        serial_number,
                        location_detail,
                        purchase_date,
                        installation_date,
                        purchase_cost,
                        warranty_start_date,
                        warranty_end_date,
                        warranty_provider,
                        condition_status,
                        operational_status,
                        preventive_maintenance_required,
                        pm_frequency_value,
                        pm_frequency_unit,
                        next_maintenance_date,
                        notes,
                        created_by
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?
                    )
                    "
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'ssiissssssdsisssiisssi',
                    $assetCode,
                    $assetName,
                    $categoryId,
                    $outletId,
                    $brand,
                    $model,
                    $serialNumber,
                    $locationDetail,
                    $purchaseDate,
                    $installationDate,
                    $purchaseCost,
                    $warrantyStart,
                    $warrantyEnd,
                    $warrantyProvider,
                    $conditionStatus,
                    $operationalStatus,
                    $pmRequired,
                    $frequencyValue,
                    $frequencyUnit,
                    $nextMaintenance,
                    $notes,
                    $uid
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(mysqli_stmt_error($stmt));
                }

                $assetId = mysqli_insert_id($connection);

                if ($pmRequired && $nextMaintenance) {

                    $pmStmt = mysqli_prepare(
                        $connection,
                        "
                        INSERT INTO facility_pm_schedules
                        (
                            asset_id,
                            frequency_value,
                            frequency_unit,
                            next_due_date,
                            created_by
                        )
                        VALUES (?, ?, ?, ?, ?)
                        "
                    );

                    mysqli_stmt_bind_param(
                        $pmStmt,
                        'iissi',
                        $assetId,
                        $frequencyValue,
                        $frequencyUnit,
                        $nextMaintenance,
                        $uid
                    );

                    if (!mysqli_stmt_execute($pmStmt)) {
                        throw new Exception(mysqli_stmt_error($pmStmt));
                    }
                }

                mysqli_commit($connection);

                assetFlash(
                    'success',
                    "Asset {$assetCode} registered successfully."
                );

                header('Location: facility_assets.php');
                exit;

            } catch (Throwable $e) {

                mysqli_rollback($connection);

                assetFlash(
                    'error',
                    'Unable to register asset: ' . $e->getMessage()
                );
            }
        }
    }
}

$outlets = mysqli_query(
    $connection,
    "
    SELECT id, warehouse_name, company
    FROM outlets
    WHERE status='active'
    ORDER BY warehouse_name
    "
);

$categories = mysqli_query(
    $connection,
    "
    SELECT id, category_name
    FROM facility_asset_categories
    WHERE status='active'
    ORDER BY category_name
    "
);

include '../inc/header.php';
?>

<link rel="stylesheet" href="../assets/css/facility-assets.css?v=1.0">

<div class="fa-page">

    <div class="fa-registration-header">

        <div>

            <a href="facility_assets.php" class="fa-back-link">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Asset Register
            </a>

            <div class="fa-eyebrow">Facility Management</div>

            <h2>Register New Asset</h2>

            <p>
                Create a complete asset record and optionally schedule preventive maintenance.
            </p>

        </div>

    </div>

    <?php if (!empty($_SESSION['flash_text'])): ?>

    <div class="fa-alert <?= facilityH($_SESSION['flash_type']) ?>">
        <?= facilityH($_SESSION['flash_text']) ?>
    </div>

    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>

    <?php endif; ?>

    <form method="POST" class="fa-register-form" id="assetRegistrationForm">

        <input type="hidden" name="csrf_token" value="<?= facilityH(facilityCsrfToken()) ?>">

        <section class="fa-form-section">

            <div class="fa-section-heading">
                <div class="fa-section-number">1</div>
                <div>
                    <h3>Asset Information</h3>
                    <p>Core identification and classification information.</p>
                </div>
            </div>

            <div class="fa-form-grid">
                <div class="fa-field">
                    <label>
                        Asset Tag / ID <span>*</span>
                    </label>
                    <input type="text" name="asset_code" maxlength="100" required placeholder="e.g. DC-FA-00182">
                </div>
                <div class="fa-field">
                    <label>Asset Name <span>*</span></label>
                    <input type="text" name="asset_name" maxlength="190" required
                        placeholder="e.g. Production Chiller 01">
                </div>

                <div class="fa-field">
                    <label>Asset Category <span>*</span></label>

                    <select name="category_id" required>
                        <option value="">Select category</option>

                        <?php while ($category = mysqli_fetch_assoc($categories)): ?>
                        <option value="<?= (int) $category['id'] ?>">
                            <?= facilityH($category['category_name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="fa-field">
                    <label>Outlet <span>*</span></label>

                    <select name="outlet_id" required>
                        <option value="">Select outlet</option>

                        <?php while ($outlet = mysqli_fetch_assoc($outlets)): ?>
                        <option value="<?= (int) $outlet['id'] ?>">
                            <?= facilityH($outlet['warehouse_name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="fa-field">
                    <label>Brand</label>
                    <input type="text" name="brand" maxlength="120" placeholder="e.g. LG">
                </div>

                <div class="fa-field">
                    <label>Model</label>
                    <input type="text" name="model" maxlength="120" placeholder="Model number">
                </div>

                <div class="fa-field">
                    <label>Serial Number</label>
                    <input type="text" name="serial_number" maxlength="150" placeholder="Manufacturer serial number">
                </div>

                <div class="fa-field">
                    <label>Exact Location</label>
                    <input type="text" name="location_detail" maxlength="190" placeholder="e.g. Production Room">
                </div>

            </div>

        </section>

        <section class="fa-form-section">

            <div class="fa-section-heading">
                <div class="fa-section-number">2</div>
                <div>
                    <h3>Purchase & Installation</h3>
                    <p>Acquisition, installation and current asset status.</p>
                </div>
            </div>

            <div class="fa-form-grid">

                <div class="fa-field">
                    <label>Purchase Date</label>
                    <input type="date" name="purchase_date">
                </div>

                <div class="fa-field">
                    <label>Installation Date</label>
                    <input type="date" name="installation_date">
                </div>

                <div class="fa-field">
                    <label>Purchase Cost</label>

                    <div class="fa-money-input">
                        <span>₦</span>
                        <input type="number" name="purchase_cost" min="0" step="0.01" placeholder="0.00">
                    </div>
                </div>

                <div class="fa-field">
                    <label>Condition</label>

                    <select name="condition_status">
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="poor">Poor</option>
                        <option value="replacement_candidate">Replacement Candidate</option>
                    </select>
                </div>

                <div class="fa-field">
                    <label>Operational Status</label>

                    <select name="operational_status">
                        <option value="operational" selected>Operational</option>
                        <option value="partially_operational">Partially Operational</option>
                        <option value="down">Down</option>
                        <option value="retired">Retired</option>
                    </select>
                </div>

            </div>

        </section>

        <section class="fa-form-section">

            <div class="fa-section-heading">
                <div class="fa-section-number">3</div>
                <div>
                    <h3>Warranty Information</h3>
                    <p>Track warranty coverage and provider details.</p>
                </div>
            </div>

            <div class="fa-form-grid">

                <div class="fa-field">
                    <label>Warranty Start</label>
                    <input type="date" name="warranty_start_date">
                </div>

                <div class="fa-field">
                    <label>Warranty End</label>
                    <input type="date" name="warranty_end_date">
                </div>

                <div class="fa-field fa-span-2">
                    <label>Warranty Provider</label>
                    <input type="text" name="warranty_provider" maxlength="190"
                        placeholder="Vendor, manufacturer, or service provider">
                </div>

            </div>

        </section>

        <section class="fa-form-section">

            <div class="fa-section-heading">
                <div class="fa-section-number">4</div>
                <div>
                    <h3>Preventive Maintenance</h3>
                    <p>Enable recurring maintenance scheduling for this asset.</p>
                </div>
            </div>

            <div class="fa-pm-toggle">

                <label>

                    <input type="checkbox" name="pm_required" id="pmRequired" value="1">

                    <span class="fa-toggle-ui"></span>

                    <div>
                        <strong>Requires preventive maintenance</strong>
                        <small>
                            Turn this on to create a maintenance schedule for the asset.
                        </small>
                    </div>

                </label>

            </div>

            <div class="fa-pm-fields" id="pmFields">

                <div class="fa-field">
                    <label>Frequency</label>
                    <input type="number" name="frequency_value" min="1" value="1">
                </div>

                <div class="fa-field">
                    <label>Frequency Unit</label>

                    <select name="frequency_unit">
                        <option value="day">Day(s)</option>
                        <option value="week">Week(s)</option>
                        <option value="month" selected>Month(s)</option>
                        <option value="year">Year(s)</option>
                    </select>
                </div>

                <div class="fa-field">
                    <label>First / Next PM Date</label>
                    <input type="date" name="next_maintenance_date" id="nextMaintenanceDate">
                </div>

            </div>

        </section>

        <section class="fa-form-section">

            <div class="fa-section-heading">
                <div class="fa-section-number">5</div>
                <div>
                    <h3>Notes</h3>
                    <p>Optional information relevant to asset management.</p>
                </div>
            </div>

            <div class="fa-field">
                <textarea name="notes" rows="4"
                    placeholder="Enter any additional information about this asset..."></textarea>
            </div>

        </section>

        <div class="fa-form-actions">

            <a href="facility_assets.php" class="fa-btn fa-btn-light">
                Cancel
            </a>

            <button type="submit" class="fa-btn fa-btn-primary" id="saveAssetBtn">
                <i class="fa-solid fa-floppy-disk"></i>
                Register Asset
            </button>

        </div>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const pmRequired = document.getElementById('pmRequired');
    const pmFields = document.getElementById('pmFields');
    const nextMaintenanceDate = document.getElementById('nextMaintenanceDate');

    function updatePmFields() {
        const enabled = pmRequired && pmRequired.checked;

        if (pmFields) {
            pmFields.classList.toggle('active', enabled);
        }

        if (nextMaintenanceDate) {
            nextMaintenanceDate.required = enabled;
        }
    }

    pmRequired?.addEventListener('change', updatePmFields);
    updatePmFields();

    const form = document.getElementById('assetRegistrationForm');
    const submitBtn = document.getElementById('saveAssetBtn');

    form?.addEventListener('submit', function(event) {

        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = 'true';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Registering...';
        }
    });
});
</script>

<?php include '../inc/footer.php'; ?>