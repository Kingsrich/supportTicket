<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';
require '../inc/facility_functions.php';

$pageTitle = 'Asset History';

$id = (int) ($_GET['id'] ?? 0);

/* ============================================================
   LOAD ASSET
============================================================ */

$assetResult = mysqli_query(
    $connection,
    "
    SELECT
        a.*,
        o.warehouse_name,
        o.company,
        c.category_name,
        v.vendor_name
    FROM facility_assets a

    INNER JOIN outlets o
        ON o.id = a.outlet_id

    INNER JOIN facility_asset_categories c
        ON c.id = a.category_id

    LEFT JOIN facility_vendors v
        ON v.id = a.preferred_vendor_id

    WHERE a.id = {$id}

    LIMIT 1
    "
);

$asset = $assetResult
    ? mysqli_fetch_assoc($assetResult)
    : null;

if (!$asset) {
    header('Location: facility_assets.php');
    exit;
}


/* ============================================================
   MAINTENANCE HISTORY
============================================================ */

$history = mysqli_query(
    $connection,
    "
    SELECT
        m.*,
        COALESCE(SUM(c.amount), 0) total_cost

    FROM facility_maintenance_requests m

    LEFT JOIN facility_maintenance_costs c
        ON c.maintenance_request_id = m.id

    WHERE m.asset_id = {$id}

    GROUP BY m.id

    ORDER BY m.created_at DESC
    "
);


/* ============================================================
   SUMMARY
============================================================ */

$summaryQuery = mysqli_query(
    $connection,
    "
    SELECT
        COUNT(*) jobs,
        SUM(status = 'Closed') closed_jobs,
        COALESCE(
            SUM(
                TIMESTAMPDIFF(
                    MINUTE,
                    downtime_started_at,
                    COALESCE(
                        restored_at,
                        NOW()
                    )
                )
            ),
            0
        ) downtime

    FROM facility_maintenance_requests

    WHERE asset_id = {$id}
    "
);

$summary = $summaryQuery
    ? mysqli_fetch_assoc($summaryQuery)
    : [];


/* ============================================================
   TOTAL COST
============================================================ */

$costQuery = mysqli_query(
    $connection,
    "
    SELECT
        COALESCE(
            SUM(c.amount),
            0
        ) total

    FROM facility_maintenance_costs c

    INNER JOIN facility_maintenance_requests m
        ON m.id = c.maintenance_request_id

    WHERE m.asset_id = {$id}
    "
);

$totalCost = $costQuery
    ? (float) (
        mysqli_fetch_assoc(
            $costQuery
        )['total']
        ?? 0
    )
    : 0;


/* ============================================================
   HEALTH SCORE
============================================================ */

$totalJobs = (int) (
    $summary['jobs']
    ?? 0
);

$closedJobs = (int) (
    $summary['closed_jobs']
    ?? 0
);

$totalDowntimeMinutes = (int) (
    $summary['downtime']
    ?? 0
);

$totalDowntimeHours = round(
    $totalDowntimeMinutes / 60,
    1
);

$health =
    (
        $totalJobs >= 6 ||
        $totalCost >= 1000000
    )
        ? 'Replacement Candidate'
        : (
            (
                $totalJobs >= 3 ||
                $totalCost >= 400000
            )
                ? 'Monitor'
                : 'Healthy'
        );

$healthClass =
    strtolower(
        str_replace(
            ' ',
            '-',
            $health
        )
    );


/* ============================================================
   FORMATTED STATUS
============================================================ */

$conditionLabel =
    ucwords(
        str_replace(
            '_',
            ' ',
            $asset['condition_status']
            ?? ''
        )
    );

$operationalLabel =
    ucwords(
        str_replace(
            '_',
            ' ',
            $asset['operational_status']
            ?? ''
        )
    );

$operationalClass =
    'status-' .
    str_replace(
        '_',
        '-',
        $asset['operational_status']
        ?? 'operational'
    );

include '../inc/header.php';
?>

<link
    rel="stylesheet"
    href="../assets/css/facility-asset-view.css?v=1.0"
>

<div class="fav-page">

    <!-- ========================================================
         BACK
    ========================================================= -->

    <a
        href="facility_assets.php"
        class="fav-back-link"
    >
        <i class="fa-solid fa-arrow-left"></i>
        Back to Asset Register
    </a>


    <!-- ========================================================
         ASSET HEADER
    ========================================================= -->

    <section class="fav-asset-header">

        <div class="fav-asset-main">

            <div class="fav-eyebrow">
                Facility Asset
            </div>


            <div class="fav-asset-tag">

                <span>
                    Asset Tag / ID
                </span>

                <strong>
                    <?= facilityH(
                        $asset['asset_code']
                    ) ?>
                </strong>

            </div>


            <h2>
                <?= facilityH(
                    $asset['asset_name']
                ) ?>
            </h2>


            <div class="fav-meta">

                <span>

                    <i class="fa-solid fa-layer-group"></i>

                    <?= facilityH(
                        $asset['category_name']
                    ) ?>

                </span>


                <span>

                    <i class="fa-solid fa-location-dot"></i>

                    <?= facilityH(
                        $asset['warehouse_name']
                    ) ?>

                </span>


                <?php if (
                    !empty(
                        $asset['location_detail']
                    )
                ): ?>

                    <span>

                        <i class="fa-solid fa-location-crosshairs"></i>

                        <?= facilityH(
                            $asset['location_detail']
                        ) ?>

                    </span>

                <?php endif; ?>

            </div>

        </div>


        <div class="fav-header-status">

            <span class="
                fav-operational-status
                <?= facilityH(
                    $operationalClass
                ) ?>
            ">
                <?= facilityH(
                    $operationalLabel
                ) ?>
            </span>

        </div>

    </section>


    <!-- ========================================================
         KPI SUMMARY
    ========================================================= -->

    <div class="fav-kpi-grid">

        <div class="fav-kpi-card">

            <div class="fav-kpi-icon">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>

            <div>

                <span>
                    Maintenance Jobs
                </span>

                <strong>
                    <?= $totalJobs ?>
                </strong>

                <small>
                    Total service requests
                </small>

            </div>

        </div>


        <div class="fav-kpi-card">

            <div class="fav-kpi-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <div>

                <span>
                    Closed Jobs
                </span>

                <strong>
                    <?= $closedJobs ?>
                </strong>

                <small>
                    Approved and completed
                </small>

            </div>

        </div>


        <div class="fav-kpi-card">

            <div class="fav-kpi-icon">
                <i class="fa-solid fa-naira-sign"></i>
            </div>

            <div>

                <span>
                    Total Maintenance Cost
                </span>

                <strong class="fav-money">
                    ₦<?= number_format(
                        $totalCost,
                        0
                    ) ?>
                </strong>

                <small>
                    Recorded lifetime cost
                </small>

            </div>

        </div>


        <div class="fav-kpi-card">

            <div class="fav-kpi-icon">
                <i class="fa-solid fa-clock"></i>
            </div>

            <div>

                <span>
                    Total Downtime
                </span>

                <strong>
                    <?= $totalDowntimeHours ?>
                    <em>hrs</em>
                </strong>

                <small>
                    Recorded equipment downtime
                </small>

            </div>

        </div>

    </div>


    <!-- ========================================================
         DETAILS + HEALTH
    ========================================================= -->

    <div class="fav-two-col">

        <!-- ASSET DETAILS -->
        <section class="fav-panel">

            <div class="fav-panel-header">

                <div>

                    <h3>
                        Asset Details
                    </h3>

                    <p>
                        Identification, warranty and maintenance information.
                    </p>

                </div>

            </div>


            <div class="fav-detail-list">

                <div class="fav-detail-row">

                    <span>
                        Asset Tag / ID
                    </span>

                    <strong>
                        <?= facilityH(
                            $asset['asset_code']
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Brand
                    </span>

                    <strong>
                        <?= facilityH(
                            $asset['brand']
                            ?: '—'
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Model
                    </span>

                    <strong>
                        <?= facilityH(
                            $asset['model']
                            ?: '—'
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Serial Number
                    </span>

                    <strong>
                        <?= facilityH(
                            $asset['serial_number']
                            ?: '—'
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Installation Date
                    </span>

                    <strong>

                        <?= !empty(
                            $asset[
                                'installation_date'
                            ]
                        )
                            ? facilityH(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $asset[
                                            'installation_date'
                                        ]
                                    )
                                )
                            )
                            : '—'
                        ?>

                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Warranty Ends
                    </span>

                    <strong>

                        <?= !empty(
                            $asset[
                                'warranty_end_date'
                            ]
                        )
                            ? facilityH(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $asset[
                                            'warranty_end_date'
                                        ]
                                    )
                                )
                            )
                            : '—'
                        ?>

                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Last Maintenance
                    </span>

                    <strong>

                        <?= !empty(
                            $asset[
                                'last_maintenance_date'
                            ]
                        )
                            ? facilityH(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $asset[
                                            'last_maintenance_date'
                                        ]
                                    )
                                )
                            )
                            : '—'
                        ?>

                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Next PM
                    </span>

                    <strong>

                        <?= !empty(
                            $asset[
                                'next_maintenance_date'
                            ]
                        )
                            ? facilityH(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $asset[
                                            'next_maintenance_date'
                                        ]
                                    )
                                )
                            )
                            : '—'
                        ?>

                    </strong>

                </div>

            </div>

        </section>


        <!-- HEALTH -->
        <section class="fav-panel">

            <div class="fav-panel-header">

                <div>

                    <h3>
                        Asset Health
                    </h3>

                    <p>
                        Current operating condition and maintenance risk.
                    </p>

                </div>

            </div>


            <div class="fav-health-card <?= facilityH($healthClass) ?>">

                <div class="fav-health-icon">

                    <?php if (
                        $health === 'Healthy'
                    ): ?>

                        <i class="fa-solid fa-shield-check"></i>

                    <?php elseif (
                        $health === 'Monitor'
                    ): ?>

                        <i class="fa-solid fa-eye"></i>

                    <?php else: ?>

                        <i class="fa-solid fa-triangle-exclamation"></i>

                    <?php endif; ?>

                </div>


                <div>

                    <span>
                        Health Assessment
                    </span>

                    <strong>
                        <?= facilityH(
                            $health
                        ) ?>
                    </strong>

                    <small>

                        <?php if (
                            $health === 'Healthy'
                        ): ?>

                            Maintenance frequency and cost remain within normal range.

                        <?php elseif (
                            $health === 'Monitor'
                        ): ?>

                            Review maintenance frequency and cost trend closely.

                        <?php else: ?>

                            Consider replacement or major refurbishment planning.

                        <?php endif; ?>

                    </small>

                </div>

            </div>


            <div class="fav-detail-list">

                <div class="fav-detail-row">

                    <span>
                        Condition
                    </span>

                    <strong>
                        <?= facilityH(
                            $conditionLabel
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Operational Status
                    </span>

                    <strong>
                        <?= facilityH(
                            $operationalLabel
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Preferred Vendor
                    </span>

                    <strong>
                        <?= facilityH(
                            $asset['vendor_name']
                            ?: '—'
                        ) ?>
                    </strong>

                </div>


                <div class="fav-detail-row">

                    <span>
                        Preventive Maintenance
                    </span>

                    <strong>

                        <?= !empty(
                            $asset[
                                'preventive_maintenance_required'
                            ]
                        )
                            ? 'Required'
                            : 'Not Required'
                        ?>

                    </strong>

                </div>

            </div>

        </section>

    </div>


    <!-- ========================================================
         MAINTENANCE HISTORY
    ========================================================= -->

    <section class="fav-panel fav-history-panel">

        <div class="fav-panel-header">

            <div>

                <h3>
                    Maintenance History
                </h3>

                <p>
                    Complete service and breakdown history for this asset.
                </p>

            </div>


            <a
                href="support_tickets.php?tab=facility&new=1"
                class="fav-btn fav-btn-primary"
            >
                <i class="fa-solid fa-plus"></i>
                New Maintenance Request
            </a>

        </div>


        <div class="fav-table-wrap">

            <table class="fav-table">

                <thead>

                    <tr>

                        <th>
                            Request
                        </th>

                        <th>
                            Date
                        </th>

                        <th>
                            Issue
                        </th>

                        <th>
                            Status
                        </th>

                        <th class="number">
                            Cost
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (
                    $history &&
                    mysqli_num_rows($history) > 0
                ): ?>

                    <?php while (
                        $request =
                        mysqli_fetch_assoc($history)
                    ): ?>

                        <?php
                        $statusClass =
                            'status-' .
                            strtolower(
                                str_replace(
                                    ' ',
                                    '-',
                                    $request['status']
                                )
                            );
                        ?>

                        <tr
                            onclick="
                                location.href =
                                'maintenance_view.php?id=<?= (int) $request['id'] ?>'
                            "
                        >

                            <td>

                                <strong class="fav-request-number">
                                    <?= facilityH(
                                        $request[
                                            'request_number'
                                        ]
                                    ) ?>
                                </strong>

                            </td>


                            <td>

                                <?= facilityH(
                                    date(
                                        'M d, Y',
                                        strtotime(
                                            $request[
                                                'created_at'
                                            ]
                                        )
                                    )
                                ) ?>

                            </td>


                            <td>

                                <div class="fav-issue">

                                    <strong>
                                        <?= facilityH(
                                            $request[
                                                'subject'
                                            ]
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= facilityH(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $request[
                                                        'request_type'
                                                    ]
                                                )
                                            )
                                        ) ?>
                                    </span>

                                </div>

                            </td>


                            <td>

                                <span class="
                                    fav-status
                                    <?= facilityH(
                                        $statusClass
                                    ) ?>
                                ">
                                    <?= facilityH(
                                        $request[
                                            'status'
                                        ]
                                    ) ?>
                                </span>

                            </td>


                            <td class="number">

                                ₦<?= number_format(
                                    (float)
                                    $request[
                                        'total_cost'
                                    ],
                                    2
                                ) ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="5"
                            class="fav-empty-cell"
                        >

                            <div class="fav-empty-state">

                                <i class="fa-solid fa-clock-rotate-left"></i>

                                <strong>
                                    No maintenance history yet
                                </strong>

                                <span>
                                    Maintenance requests linked to this asset will appear here.
                                </span>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>

<?php include '../inc/footer.php'; ?>
