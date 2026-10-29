<?php ob_start();
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';
$pageTitle = 'Bulk Upload Facility Assets';
$uid = (int) ($_SESSION['user_id'] ?? 0);
$canManage = facilityHasRole($connection, $uid, ['facility_officer', 'facility_project_manager', 'facility_admin']);
if (!$canManage) {
    header('Location: facility_assets.php');
    exit;
}
facilityCsrfToken(); /* ============================================================ HELPERS ============================================================ */
function bulkAssetJson(array $payload, int $status = 200): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}
function bulkAssetNormalizeText($value): string
{
    return trim((string) $value);
}
function bulkAssetNormalizeHeader($value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
    return strtolower(trim($value));
}
function bulkAssetNormalizeDate($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}
function bulkAssetNormalizeStatus(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace([' ', '-'], '_', $value);
    return $value;
} /* ============================================================ CSV TEMPLATE ============================================================ */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'download_template') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="facility_asset_import_template.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $output = fopen('php://output', 'w'); /* * UTF-8 BOM. * Helps Microsoft Excel display text correctly. */
    fwrite($output, "\xEF\xBB\xBF"); /* * COLUMN HEADINGS */
    fputcsv($output, ['Asset Tag *', 'Asset Name *', 'Category *', 'Outlet *', 'Brand', 'Model', 'Serial Number', 'Exact Location', 'Purchase Date (YYYY-MM-DD)', 'Installation Date (YYYY-MM-DD)', 'Purchase Cost', 'Warranty Start (YYYY-MM-DD)', 'Warranty End (YYYY-MM-DD)', 'Warranty Provider', 'Condition', 'Operational Status', 'PM Required', 'PM Frequency', 'PM Unit', 'Next PM Date (YYYY-MM-DD)', 'Notes']); /* * EXAMPLE ROW * * User may replace/delete this row. */
    fputcsv($output, ['DC-AC-001', 'Production Split Unit AC', 'Air Conditioner', 'Lekki Outlet', 'LG', 'Dual Inverter', 'SN12345678', 'Production Area', '2026-01-15', '2026-01-20', '850000', '2026-01-20', '2027-01-19', 'LG Nigeria', 'Good', 'Operational', 'Yes', '3', 'month', '2026-10-20', 'Example row - replace or delete before upload']);
    fclose($output);
    exit;
} /* ============================================================ BULK CSV IMPORT ============================================================ */
if ($action === 'bulk_import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!facilityValidateCsrf()) {
        bulkAssetJson(['success' => false, 'error' => 'Your session request expired. Refresh the page and try again.'], 419);
    }
    if (!isset($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
        bulkAssetJson(['success' => false, 'error' => 'Please select a valid CSV file.'], 422);
    }
    $uploadedName = $_FILES['asset_file']['name'] ?? '';
    $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        bulkAssetJson(['success' => false, 'error' => 'Only CSV files created from the Facility Asset template are accepted.'], 422);
    }
    if ((int) $_FILES['asset_file']['size'] > (5 * 1024 * 1024)) {
        bulkAssetJson(['success' => false, 'error' => 'The CSV file is too large. Maximum file size is 5 MB.'], 422);
    }
    $handle = fopen($_FILES['asset_file']['tmp_name'], 'r');
    if (!$handle) {
        bulkAssetJson(['success' => false, 'error' => 'Unable to open the uploaded CSV file.'], 500);
    } /* ======================================================== READ HEADER ======================================================== */
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        bulkAssetJson(['success' => false, 'error' => 'The CSV file is empty.'], 422);
    }
    $header = array_map('bulkAssetNormalizeHeader', $header); /* * Map expected column name => * normalized CSV heading. */
    $requiredHeaders = ['asset_tag' => 'asset tag *', 'asset_name' => 'asset name *', 'category' => 'category *', 'outlet' => 'outlet *'];
    $optionalHeaders = ['brand' => 'brand', 'model' => 'model', 'serial_number' => 'serial number', 'location_detail' => 'exact location', 'purchase_date' => 'purchase date (yyyy-mm-dd)', 'installation_date' => 'installation date (yyyy-mm-dd)', 'purchase_cost' => 'purchase cost', 'warranty_start' => 'warranty start (yyyy-mm-dd)', 'warranty_end' => 'warranty end (yyyy-mm-dd)', 'warranty_provider' => 'warranty provider', 'condition' => 'condition', 'operational_status' => 'operational status', 'pm_required' => 'pm required', 'pm_frequency' => 'pm frequency', 'pm_unit' => 'pm unit', 'next_pm' => 'next pm date (yyyy-mm-dd)', 'notes' => 'notes'];
    $indexes = [];
    foreach ($requiredHeaders as $key => $heading) {
        $index = array_search($heading, $header, true);
        if ($index === false) {
            fclose($handle);
            bulkAssetJson(['success' => false, 'error' => 'Invalid Facility Asset CSV template. Missing required column: ' . ucwords(str_replace('*', '', $heading))], 422);
        }
        $indexes[$key] = $index;
    }
    foreach ($optionalHeaders as $key => $heading) {
        $index = array_search($heading, $header, true);
        $indexes[$key] = $index === false ? null : $index;
    } /* ======================================================== LOAD ACTIVE OUTLETS ======================================================== */
    $outletMap = [];
    $outletQuery = mysqli_query($connection, " SELECT id, warehouse_name FROM outlets WHERE status = 'active' ORDER BY warehouse_name ");
    while ($outletQuery && ($outlet = mysqli_fetch_assoc($outletQuery))) {
        $key = mb_strtolower(trim($outlet['warehouse_name']));
        $outletMap[$key] = ['id' => (int) $outlet['id'], 'name' => $outlet['warehouse_name']];
    } /* ======================================================== LOAD ACTIVE ASSET CATEGORIES ======================================================== */
    $categoryMap = [];
    $categoryQuery = mysqli_query($connection, " SELECT id, category_name FROM facility_asset_categories WHERE status = 'active' ORDER BY category_name ");
    while ($categoryQuery && ($category = mysqli_fetch_assoc($categoryQuery))) {
        $key = mb_strtolower(trim($category['category_name']));
        $categoryMap[$key] = ['id' => (int) $category['id'], 'name' => $category['category_name']];
    } /* ======================================================== VALID VALUES ======================================================== */
    $validConditions = ['excellent', 'good', 'fair', 'poor', 'replacement_candidate'];
    $validOperationalStatuses = ['operational', 'partially_operational', 'down', 'retired'];
    $validPmUnits = ['day', 'week', 'month', 'year']; /* ======================================================== RESULT COUNTERS ======================================================== */
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $blank = 0;
    $details = [];
    $seenAssetTags = [];
    $seenSerials = [];
    $rowNumber = 1; /* ======================================================== CSV ROW LOOP ======================================================== */
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++; /* * Ignore completely blank rows. */
        if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) {
            $blank++;
            continue;
        }
        $getValue = static function (array $row, ?int $index): string {
            if ($index === null) {
                return '';
            }return trim((string) ($row[$index] ?? ''));
        }; /* ==================================================== READ ROW ==================================================== */
        $assetTag = $getValue($row, $indexes['asset_tag']);
        $assetName = $getValue($row, $indexes['asset_name']);
        $categoryName = $getValue($row, $indexes['category']);
        $outletName = $getValue($row, $indexes['outlet']);
        $brand = $getValue($row, $indexes['brand']);
        $model = $getValue($row, $indexes['model']);
        $serialNumber = $getValue($row, $indexes['serial_number']);
        $locationDetail = $getValue($row, $indexes['location_detail']);
        $purchaseDateRaw = $getValue($row, $indexes['purchase_date']);
        $installationDateRaw = $getValue($row, $indexes['installation_date']);
        $purchaseCostRaw = $getValue($row, $indexes['purchase_cost']);
        $warrantyStartRaw = $getValue($row, $indexes['warranty_start']);
        $warrantyEndRaw = $getValue($row, $indexes['warranty_end']);
        $warrantyProvider = $getValue($row, $indexes['warranty_provider']);
        $conditionRaw = $getValue($row, $indexes['condition']);
        $operationalRaw = $getValue($row, $indexes['operational_status']);
        $pmRequiredRaw = $getValue($row, $indexes['pm_required']);
        $frequencyRaw = $getValue($row, $indexes['pm_frequency']);
        $frequencyUnitRaw = $getValue($row, $indexes['pm_unit']);
        $nextPmRaw = $getValue($row, $indexes['next_pm']);
        $notes = $getValue($row, $indexes['notes']); /* * Skip example row. */
        if (strtolower($assetTag) === 'dc-ac-001' && str_contains(strtolower($notes), 'example row')) {
            $blank++;
            continue;
        }
        $result = ['row' => $rowNumber, 'asset_tag' => $assetTag ?: '—', 'asset' => $assetName ?: '—', 'status' => '', 'message' => '']; /* ==================================================== REQUIRED FIELDS ==================================================== */
        if ($assetTag === '' || $assetName === '' || $categoryName === '' || $outletName === '') {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = 'Asset Tag, Asset Name, Category and Outlet are required.';
            $details[] = $result;
            continue;
        } /* ==================================================== ASSET TAG DUPLICATE IN SAME CSV ==================================================== */
        $assetTagKey = mb_strtolower($assetTag);
        if (isset($seenAssetTags[$assetTagKey])) {
            $skipped++;
            $result['status'] = 'skipped';
            $result['message'] = 'Duplicate Asset Tag within this CSV file.';
            $details[] = $result;
            continue;
        }
        $seenAssetTags[$assetTagKey] = true; /* ==================================================== DATABASE ASSET TAG DUPLICATE ==================================================== */
        $tagStatement = mysqli_prepare($connection, " SELECT id, asset_code, asset_name FROM facility_assets WHERE LOWER(asset_code) = LOWER(?) LIMIT 1 ");
        mysqli_stmt_bind_param($tagStatement, 's', $assetTag);
        mysqli_stmt_execute($tagStatement);
        $tagResult = mysqli_stmt_get_result($tagStatement);
        if ($tagResult && ($existing = mysqli_fetch_assoc($tagResult))) {
            mysqli_stmt_close($tagStatement);
            $skipped++;
            $result['status'] = 'skipped';
            $result['message'] = "Asset Tag {$assetTag} already exists.";
            $details[] = $result;
            continue;
        }
        mysqli_stmt_close($tagStatement); /* ==================================================== CATEGORY ==================================================== */
        $categoryKey = mb_strtolower($categoryName);
        if (!isset($categoryMap[$categoryKey])) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = "Asset Category '{$categoryName}' was not found or is inactive.";
            $details[] = $result;
            continue;
        }
        $categoryId = $categoryMap[$categoryKey]['id']; /* ==================================================== OUTLET ==================================================== */
        $outletKey = mb_strtolower($outletName);
        if (!isset($outletMap[$outletKey])) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = "Outlet '{$outletName}' was not found or is inactive.";
            $details[] = $result;
            continue;
        }
        $outletId = $outletMap[$outletKey]['id']; /* ==================================================== SERIAL NUMBER DUPLICATES ==================================================== */
        if ($serialNumber !== '') {
            $serialKey = mb_strtolower($serialNumber);
            if (isset($seenSerials[$serialKey])) {
                $skipped++;
                $result['status'] = 'skipped';
                $result['message'] = "Duplicate Serial Number '{$serialNumber}' within this CSV.";
                $details[] = $result;
                continue;
            }
            $seenSerials[$serialKey] = true;
            $serialStatement = mysqli_prepare($connection, " SELECT id, asset_code FROM facility_assets WHERE LOWER(serial_number) = LOWER(?) LIMIT 1 ");
            mysqli_stmt_bind_param($serialStatement, 's', $serialNumber);
            mysqli_stmt_execute($serialStatement);
            $serialResult = mysqli_stmt_get_result($serialStatement);
            if ($serialResult && ($existingSerial = mysqli_fetch_assoc($serialResult))) {
                mysqli_stmt_close($serialStatement);
                $skipped++;
                $result['status'] = 'skipped';
                $result['message'] = "Serial Number already belongs to {$existingSerial['asset_code']}.";
                $details[] = $result;
                continue;
            }
            mysqli_stmt_close($serialStatement);
        } /* ==================================================== CONDITION ==================================================== */
        $conditionStatus = $conditionRaw !== '' ? bulkAssetNormalizeStatus($conditionRaw) : 'good';
        if (!in_array($conditionStatus, $validConditions, true)) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = 'Condition must be Excellent, Good, Fair, Poor or Replacement Candidate.';
            $details[] = $result;
            continue;
        } /* ==================================================== OPERATIONAL STATUS ==================================================== */
        $operationalStatus = $operationalRaw !== '' ? bulkAssetNormalizeStatus($operationalRaw) : 'operational';
        if (!in_array($operationalStatus, $validOperationalStatuses, true)) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = 'Operational Status must be Operational, Partially Operational, Down or Retired.';
            $details[] = $result;
            continue;
        } /* ==================================================== DATES ==================================================== */
        $purchaseDate = bulkAssetNormalizeDate($purchaseDateRaw);
        $installationDate = bulkAssetNormalizeDate($installationDateRaw);
        $warrantyStart = bulkAssetNormalizeDate($warrantyStartRaw);
        $warrantyEnd = bulkAssetNormalizeDate($warrantyEndRaw);
        $nextMaintenance = bulkAssetNormalizeDate($nextPmRaw);
        $dateChecks = ['Purchase Date' => [$purchaseDateRaw, $purchaseDate], 'Installation Date' => [$installationDateRaw, $installationDate], 'Warranty Start' => [$warrantyStartRaw, $warrantyStart], 'Warranty End' => [$warrantyEndRaw, $warrantyEnd], 'Next PM Date' => [$nextPmRaw, $nextMaintenance]];
        $badDate = false;
        foreach ($dateChecks as $label => [$rawDate, $normalizedDate]) {
            if ($rawDate !== '' && $normalizedDate === null) {
                $failed++;
                $result['status'] = 'error';
                $result['message'] = "{$label} must use YYYY-MM-DD.";
                $details[] = $result;
                $badDate = true;
                break;
            }
        }
        if ($badDate) {
            continue;
        } /* ==================================================== PURCHASE COST ==================================================== */
        $purchaseCost = null;
        if ($purchaseCostRaw !== '') {
            $numericCost = str_replace(',', '', $purchaseCostRaw);
            if (!is_numeric($numericCost)) {
                $failed++;
                $result['status'] = 'error';
                $result['message'] = 'Purchase Cost must be numeric.';
                $details[] = $result;
                continue;
            }
            $purchaseCost = max(0, (float) $numericCost);
        } /* ==================================================== PREVENTIVE MAINTENANCE ==================================================== */
        $pmText = mb_strtolower(trim($pmRequiredRaw));
        $pmRequired = in_array($pmText, ['yes', 'y', '1', 'true'], true) ? 1 : 0;
        $frequencyValue = $frequencyRaw !== '' ? max(1, (int) $frequencyRaw) : 1;
        $frequencyUnit = $frequencyUnitRaw !== '' ? bulkAssetNormalizeStatus($frequencyUnitRaw) : 'month';
        if ($pmRequired && !in_array($frequencyUnit, $validPmUnits, true)) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = 'PM Unit must be day, week, month or year.';
            $details[] = $result;
            continue;
        }
        if ($pmRequired && !$nextMaintenance) {
            $failed++;
            $result['status'] = 'error';
            $result['message'] = 'Next PM Date is required when PM Required is Yes.';
            $details[] = $result;
            continue;
        } /* ==================================================== INSERT ASSET + PM SCHEDULE ==================================================== */
        mysqli_begin_transaction($connection);
        try { /* * Asset Tag from CSV becomes * facility_assets.asset_code. * * NO automatic generation. */
            $insert = mysqli_prepare($connection, " INSERT INTO facility_assets ( asset_code, asset_name, category_id, outlet_id, brand, model, serial_number, location_detail, purchase_date, installation_date, purchase_cost, warranty_start_date, warranty_end_date, warranty_provider, condition_status, operational_status, preventive_maintenance_required, pm_frequency_value, pm_frequency_unit, next_maintenance_date, notes, created_by ) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? ) ");
            if (!$insert) {
                throw new RuntimeException(mysqli_error($connection));
            }
            mysqli_stmt_bind_param($insert, 'ssiissssssdsssssiisssi', $assetTag, $assetName, $categoryId, $outletId, $brand, $model, $serialNumber, $locationDetail, $purchaseDate, $installationDate, $purchaseCost, $warrantyStart, $warrantyEnd, $warrantyProvider, $conditionStatus, $operationalStatus, $pmRequired, $frequencyValue, $frequencyUnit, $nextMaintenance, $notes, $uid);
            if (!mysqli_stmt_execute($insert)) {
                throw new RuntimeException(mysqli_stmt_error($insert));
            }
            $assetId = mysqli_insert_id($connection);
            mysqli_stmt_close($insert); /* ================================================ CREATE PM SCHEDULE ================================================ */
            if ($pmRequired && $nextMaintenance) {
                $pmStatement = mysqli_prepare($connection, " INSERT INTO facility_pm_schedules ( asset_id, frequency_value, frequency_unit, next_due_date, created_by ) VALUES (?, ?, ?, ?, ?) ");
                if (!$pmStatement) {
                    throw new RuntimeException(mysqli_error($connection));
                }
                mysqli_stmt_bind_param($pmStatement, 'iissi', $assetId, $frequencyValue, $frequencyUnit, $nextMaintenance, $uid);
                if (!mysqli_stmt_execute($pmStatement)) {
                    throw new RuntimeException(mysqli_stmt_error($pmStatement));
                }
                mysqli_stmt_close($pmStatement);
            }
            mysqli_commit($connection);
            $imported++;
            $result['status'] = 'inserted';
            $result['message'] = "Asset {$assetTag} created successfully.";
            $details[] = $result;
        } catch (Throwable $e) {
            mysqli_rollback($connection);
            $failed++;
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
            $details[] = $result;
        }
    }
    fclose($handle);
    bulkAssetJson(['success' => true, 'message' => "Import completed: {$imported} uploaded, {$skipped} skipped, {$failed} failed.", 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'blank' => $blank, 'details' => $details]);
} /* ============================================================ NORMAL PAGE ============================================================ */
include '../inc/header.php'; ?>
<link rel="stylesheet" href="../assets/css/facility-asset-bulk.css?v=1.0">
<div class="fab-page">
    <!-- ======================================================== PAGE HEADER ========================================================= -->
    <div class="fab-page-header">
        <div> <a href="facility_assets.php" class="fab-back-link"> <i class="fa-solid fa-arrow-left"></i> Back to Asset
                Register </a>
            <div class="fab-eyebrow"> Facility Management </div>
            <!-- <h2> Bulk Upload Facility Assets </h2>
            <p> Register multiple Facility assets using the approved CSV import template. </p> -->
        </div> <a href="facility_asset_bulk_upload.php?action=download_template" class="fab-btn fab-btn-secondary"> <i
                class="fa-solid fa-file-csv"></i> Download CSV Template </a>
    </div>
    <!-- ======================================================== WORKFLOW CARD ========================================================= -->
    <section class="fab-card">
        <div class="fab-card-head">
            <div> <span class="fab-card-kicker"> BULK ASSET REGISTRATION </span>
                <h3> Import Facility Assets </h3>
                <p> Use the official CSV template to avoid Asset Tag, Category, Outlet or date errors. </p>
            </div>
        </div>
        <!-- ==================================================== THREE STEPS ===================================================== -->
        <div class="fab-steps">
            <div class="fab-step">
                <div class="fab-step-number"> 1 </div>
                <div> <strong> Download Template </strong> <small> Start with the official Facility Asset CSV structure.
                    </small> </div>
            </div>
            <div class="fab-step">
                <div class="fab-step-number"> 2 </div>
                <div> <strong> Enter Asset Records </strong> <small> Asset Tag, Asset Name, Category and Outlet are
                        mandatory. </small> </div>
            </div>
            <div class="fab-step">
                <div class="fab-step-number"> 3 </div>
                <div> <strong> Upload CSV </strong> <small> New assets are created; duplicates and invalid rows are
                        reported. </small> </div>
            </div>
        </div>
        <!-- ==================================================== DOWNLOAD TEMPLATE CARD ===================================================== -->
        <!-- <a href="facility_asset_bulk_upload.php?action=download_template" class="fab-template-card">
            <div class="fab-template-icon"> <i class="fa-solid fa-download"></i> </div>
            <div> <strong> Facility Asset CSV Template </strong> <small> Download a clean, Excel-compatible CSV import
                    template </small> </div> <i class="fa-solid fa-arrow-down fab-template-arrow"></i>
        </a> -->
        <!-- ==================================================== REQUIRED FIELD INFORMATION ===================================================== -->
        <div class="fab-field-guide">
            <div class="fab-field-group required"> <span> Required </span> <strong> Asset Tag </strong> <strong> Asset
                    Name </strong> <strong> Category </strong> <strong> Outlet </strong> </div>
            <div class="fab-field-group"> <span> Asset Information </span> <strong> Brand </strong> <strong> Model
                </strong> <strong> Serial Number </strong> <strong> Exact Location </strong> </div>
            <div class="fab-field-group"> <span> Preventive Maintenance </span> <strong> PM Required </strong> <strong>
                    Frequency </strong> <strong> Unit </strong> <strong> Next PM Date </strong> </div>
        </div>
        <!-- ==================================================== UPLOAD ===================================================== -->
        <form id="facilityAssetBulkForm" enctype="multipart/form-data"> <input type="hidden" name="csrf_token"
                value="<?= facilityH(facilityCsrfToken()) ?>"> <input type="hidden" name="action" value="bulk_import">
            <label class="fab-dropzone" for="facilityAssetCsv" id="facilityAssetDropzone"> <input type="file"
                    name="asset_file" id="facilityAssetCsv" accept=".csv,text/csv">
                <div class="fab-upload-icon"> <i class="fa-solid fa-cloud-arrow-up"></i> </div> <strong
                    id="facilityAssetFileLabel"> Choose CSV file </strong> <span> Click here to browse for your
                    completed Facility Asset template </span> <small> CSV only · Maximum file size 5 MB </small>
            </label> <!-- RESULT BOX -->
            <div class="fab-import-result" id="facilityAssetImportResult" hidden> </div> <!-- ACTIONS -->
            <div class="fab-actions"> <a href="facility_assets.php" class="fab-btn fab-btn-light"> Cancel </a> <button
                    type="submit" class="fab-btn fab-btn-primary" id="facilityAssetUploadButton"> <i
                        class="fa-solid fa-file-arrow-up"></i> Start Upload </button> </div>
        </form>
    </section>
    <!-- ======================================================== IMPORT RULES ========================================================= -->
    <section class="fab-info-card">
        <div class="fab-info-icon"> <i class="fa-solid fa-circle-info"></i> </div>
        <div>
            <h3> CSV Import Rules </h3>
            <div class="fab-rules"> <span> Asset Tag / ID must be unique. </span> <span> Category must match an active
                    Facility Asset Category. </span> <span> Outlet must match an active Cost Centre / Outlet name.
                </span> <span> Dates must use YYYY-MM-DD. </span> <span> Existing Asset Tags are skipped, not
                    overwritten. </span> <span> PM Unit must be day, week, month or year. </span> </div>
        </div>
    </section>
</div>
<!-- ============================================================ RESULT DIALOG ============================================================ -->
<div class="fab-dialog-overlay" id="facilityAssetDialog">
    <div class="fab-dialog">
        <div class="fab-dialog-icon" id="facilityAssetDialogIcon"> <i class="fa-solid fa-circle-check"></i> </div>
        <h3 id="facilityAssetDialogTitle"> Import Complete </h3>
        <p id="facilityAssetDialogMessage"></p> <button type="button" class="fab-btn fab-btn-primary"
            onclick="closeFacilityAssetDialog()"> Continue </button>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('facilityAssetBulkForm');
    const input = document.getElementById('facilityAssetCsv');
    const label = document.getElementById('facilityAssetFileLabel');
    const button = document.getElementById('facilityAssetUploadButton');
    const resultBox = document.getElementById(
        'facilityAssetImportResult'
    ); /* ===================================================== SHOW SELECTED FILE ====================================================== */
    input?.addEventListener('change', function() {
        if (input.files && input.files.length) {
            label.textContent = input.files[0].name;
        } else {
            label.textContent = 'Choose CSV file';
        }
    }); /* ===================================================== AJAX IMPORT ====================================================== */
    form?.addEventListener('submit', async function(event) {
        event.preventDefault();
        const file = input.files[0];
        if (!file) {
            openFacilityAssetDialog('File Required',
                'Please select a CSV file before starting the upload.', 'warning');
            return;
        }
        if (!file.name.toLowerCase().endsWith('.csv')) {
            openFacilityAssetDialog('Invalid File', 'Only CSV files are accepted.', 'error');
            return;
        }
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
        resultBox.hidden = true;
        resultBox.innerHTML = '';
        const formData = new FormData(form);
        try {
            const response = await fetch('facility_asset_bulk_upload.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (!data.success) {
                openFacilityAssetDialog('Import Failed', data.error ||
                    'The asset import could not be completed.', 'error');
                return;
            } /* ========================================= SUMMARY ========================================== */
            let html =
                ` <div class="fab-result-summary"> <div class="fab-result-stat uploaded"> <span> Uploaded </span> <strong> ${data.imported || 0} </strong> </div> <div class="fab-result-stat skipped"> <span> Skipped </span> <strong> ${data.skipped || 0} </strong> </div> <div class="fab-result-stat failed"> <span> Failed </span> <strong> ${data.failed || 0} </strong> </div> </div> `; /* ========================================= ROW DETAILS ========================================== */
            if (data.details && data.details.length) {
                html += ` <div class="fab-result-lines"> `;
                data.details.forEach(function(item) {
                    const status = item.status || '';
                    html +=
                        ` <div class="fab-result-line ${escapeFacilityHtml(status)}" > <div class="fab-result-row" > Row ${Number(item.row)} </div> <div class="fab-result-asset" > <strong> ${escapeFacilityHtml(item.asset_tag)} </strong> <span> ${escapeFacilityHtml(item.asset)} </span> </div> <div class="fab-result-message" > ${escapeFacilityHtml(item.message)} </div> </div> `;
                });
                html += ` </div> `;
            }
            resultBox.innerHTML = html;
            resultBox.hidden = false;
            openFacilityAssetDialog('Import Complete', data.message, 'success');
        } catch (error) {
            openFacilityAssetDialog('System Error',
                'The server response could not be processed. Check your PHP error log if the problem continues.',
                'error');
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i> Start Upload';
        }
    });
}); /* ============================================================= SAFE HTML ============================================================= */
function escapeFacilityHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, function(character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        } [character];
    });
} /* ============================================================= DIALOG ============================================================= */
function openFacilityAssetDialog(title, message, type) {
    const overlay = document.getElementById('facilityAssetDialog');
    const titleElement = document.getElementById('facilityAssetDialogTitle');
    const messageElement = document.getElementById('facilityAssetDialogMessage');
    const icon = document.getElementById('facilityAssetDialogIcon');
    titleElement.textContent = title;
    messageElement.textContent = message;
    icon.className = 'fab-dialog-icon ' + type;
    if (type === 'success') {
        icon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
    } else if (type === 'warning') {
        icon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
    } else {
        icon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
    }
    overlay.classList.add('active');
}

function closeFacilityAssetDialog() {
    document.getElementById('facilityAssetDialog').classList.remove('active');
}
</script>
<?php include '../inc/footer.php'; ?>