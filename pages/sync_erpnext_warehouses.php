<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/erpnext_config.php';

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    die("Unauthorized access.");
}

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
        throw new Exception("ERPNext returned HTTP {$httpCode}: " . substr($response, 0, 300));
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
$error = "";

try {

    // =====================================================
    // USE ONLY STANDARD WAREHOUSE FIELDS
    // ERPNext does NOT have address fields by default
    // =====================================================
    $fields = urlencode(json_encode([
        "name",
        "warehouse_name",
        "company",
        "parent_warehouse",
        "warehouse_type",
        "disabled"
    ]));

    $limit = 5000;

    $endpoint = "/api/resource/Warehouse?fields={$fields}&limit_page_length={$limit}";

    $result = erpnextGet($endpoint);

    $warehouses = $result['data'] ?? [];

    if (empty($warehouses)) {
        throw new Exception("No warehouses returned from ERPNext. Check if warehouses exist in ERPNext.");
    }

    foreach ($warehouses as $wh) {

        $erpnextWarehouseId = trim($wh['name'] ?? '');
        $warehouseName = trim($wh['warehouse_name'] ?? '');

        // If warehouse_name is empty, use name
        if ($warehouseName === '') {
            $warehouseName = $erpnextWarehouseId;
        }

        $company = trim($wh['company'] ?? '');

        // Build address from parent_warehouse as fallback
        // (ERPNext Warehouse doesn't have a standard address field)
        $address = trim($wh['parent_warehouse'] ?? '');

        // Add warehouse_type info if available
        $warehouseType = trim($wh['warehouse_type'] ?? '');

        if ($warehouseType !== '' && $address !== '') {
            $address = "Type: {$warehouseType} | Parent: {$address}";
        } elseif ($warehouseType !== '') {
            $address = "Type: {$warehouseType}";
        }

        // Disabled field (0 = active, 1 = inactive)
        $isDisabled = (int) ($wh['disabled'] ?? 0);
        $status = $isDisabled === 1 ? 'inactive' : 'active';

        if ($erpnextWarehouseId === '' || $warehouseName === '') {
            $skipped++;
            continue;
        }

        // Check if warehouse already exists
        $check = mysqli_prepare(
            $connection,
            "SELECT id FROM outlets WHERE erpnext_warehouse_id = ? LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "s", $erpnextWarehouseId);
        mysqli_stmt_execute($check);
        $checkRes = mysqli_stmt_get_result($check);

        if ($existing = mysqli_fetch_assoc($checkRes)) {

            $outletId = (int) $existing['id'];

            // UPDATE existing
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE outlets
                    SET warehouse_name = ?,
                        company = ?,
                        address = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssi",
                $warehouseName,
                $company,
                $address,
                $status,
                $outletId
            );

            if (mysqli_stmt_execute($stmt)) {
                $updated++;
            } else {
                $skipped++;
            }

        } else {

            // INSERT new
            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO outlets
                (warehouse_name, company, address, erpnext_warehouse_id, status)
                VALUES (?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssss",
                $warehouseName,
                $company,
                $address,
                $erpnextWarehouseId,
                $status
            );

            if (mysqli_stmt_execute($stmt)) {
                $inserted++;
            } else {
                $skipped++;
            }
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
    max-width:700px;
    margin:0 auto;
">

    <h3 style="margin-bottom:20px;">
        <i class="fa-solid fa-cloud-arrow-down" style="color:#db2777; margin-right:8px;"></i>
        ERPNext Warehouse Sync
    </h3>

    <?php if ($error !== ''): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:14px;border-radius:12px;margin-bottom:16px;">
            <strong>Error:</strong> <?= h($error) ?>
        </div>
    <?php else: ?>
        <div style="background:#dcfce7;color:#166534;padding:18px;border-radius:12px;margin-bottom:20px;">
            <h4 style="margin:0 0 12px;">✓ Sync Completed Successfully</h4>
            <table style="width:100%; font-size:14px;">
                <tr>
                    <td style="padding:4px 0;"><strong>Warehouses Inserted:</strong></td>
                    <td style="text-align:right;"><?= (int) $inserted ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;"><strong>Warehouses Updated:</strong></td>
                    <td style="text-align:right;"><?= (int) $updated ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;"><strong>Skipped (invalid):</strong></td>
                    <td style="text-align:right;"><?= (int) $skipped ?></td>
                </tr>
                <tr style="border-top:1px solid #bbf7d0; padding-top:8px;">
                    <td style="padding:8px 0 0;"><strong>Total Processed:</strong></td>
                    <td style="text-align:right; padding-top:8px;">
                        <strong><?= (int) ($inserted + $updated + $skipped) ?></strong>
                    </td>
                </tr>
            </table>
        </div>

        <?php if ($inserted === 0 && $updated === 0): ?>
            <div style="background:#fff7ed;color:#7c2d12;padding:14px;border-radius:12px;margin-bottom:16px;">
                <strong>ℹ️ No warehouses were synced.</strong><br>
                All warehouses in ERPNext might already exist in your local database.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="outlets.php" style="
            display:inline-flex;
            padding:11px 16px;
            border-radius:10px;
            background:#fff7ed;
            color:#7c2d12;
            text-decoration:none;
            font-weight:600;
            border:1px solid #fed7aa;
        ">
            <i class="fa-solid fa-arrow-left" style="margin-right:7px;"></i>
            Back to Outlets
        </a>

        <a href="sync_erpnext_warehouses.php" style="
            display:inline-flex;
            padding:11px 16px;
            border-radius:10px;
            background:linear-gradient(135deg, #9a3412, #db2777);
            color:#fff;
            text-decoration:none;
            font-weight:600;
        ">
            <i class="fa-solid fa-rotate" style="margin-right:7px;"></i>
            Sync Again
        </a>
    </div>

    <div style="margin-top:24px; padding-top:20px; border-top:1px solid #eef2f7; font-size:12px; color:#64748b;">
        <strong>Field Mapping (Standard ERPNext Warehouse Fields):</strong>
        <ul style="margin:8px 0; padding-left:20px;">
            <li><code>name</code> → <code>erpnext_warehouse_id</code> (unique identifier)</li>
            <li><code>warehouse_name</code> → <code>warehouse_name</code> (display name)</li>
            <li><code>company</code> → <code>company</code></li>
            <li><code>parent_warehouse</code> + <code>warehouse_type</code> → <code>address</code> (informational)</li>
            <li><code>disabled</code> (0/1) → <code>status</code> (active/inactive)</li>
        </ul>
        <p style="margin:8px 0 0; color:#dc2626;">
            <strong>⚠️ Note:</strong> ERPNext's standard Warehouse doctype does NOT have address fields.
            The "address" column shows warehouse hierarchy/type info instead.
        </p>
    </div>

</div>

<?php include '../inc/footer.php'; ?>