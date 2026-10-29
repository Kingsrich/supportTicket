<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/facility_functions.php';

$pageTitle = 'Facility Vendors';

$uid = (int) ($_SESSION['user_id'] ?? 0);

$can = facilityHasRole(
    $connection,
    $uid,
    [
        'facility_officer',
        'facility_project_manager',
        'facility_admin'
    ]
);

facilityCsrfToken();


/* ============================================================
   CREATE VENDOR
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $can &&
    facilityValidateCsrf()
) {

    $name = trim(
        $_POST['vendor_name']
        ?? ''
    );

    if ($name !== '') {

        $contact = trim(
            $_POST['contact_person']
            ?? ''
        );

        $email = trim(
            $_POST['email']
            ?? ''
        );

        $phone = trim(
            $_POST['phone']
            ?? ''
        );

        $services = trim(
            $_POST['service_categories']
            ?? ''
        );

        $stmt = mysqli_prepare(
            $connection,
            "
            INSERT INTO facility_vendors
            (
                vendor_name,
                contact_person,
                email,
                phone,
                service_categories,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?)
            "
        );

        mysqli_stmt_bind_param(
            $stmt,
            'sssssi',
            $name,
            $contact,
            $email,
            $phone,
            $services,
            $uid
        );

        mysqli_stmt_execute(
            $stmt
        );

        $_SESSION['flash_type'] =
            'success';

        $_SESSION['flash_text'] =
            'Vendor created successfully.';
    }

    header(
        'Location: facility_vendors.php'
    );

    exit;
}


/* ============================================================
   VENDOR PERFORMANCE
============================================================ */

$vendors = mysqli_query(
    $connection,
    "
    SELECT
        v.*,
        COUNT(DISTINCT m.id) jobs,
        SUM(m.status = 'Closed') completed,
        COALESCE(
            AVG(
                CASE
                    WHEN m.closed_at IS NOT NULL
                    THEN TIMESTAMPDIFF(
                        HOUR,
                        m.created_at,
                        m.closed_at
                    )
                END
            ),
            0
        ) avg_resolution_hours,
        COALESCE(
            SUM(c.amount),
            0
        ) total_cost

    FROM facility_vendors v

    LEFT JOIN facility_maintenance_requests m
        ON m.assigned_vendor_id = v.id

    LEFT JOIN facility_maintenance_costs c
        ON c.maintenance_request_id = m.id
        AND c.cost_type = 'Vendor'

    GROUP BY
        v.id

    ORDER BY
        v.vendor_name
    "
);


/* ============================================================
   SUMMARY METRICS
============================================================ */

$vendorCount = 0;
$activeCount = 0;
$totalJobs = 0;
$totalVendorCost = 0;

$vendorSummary = [];

if ($vendors) {

    while (
        $row = mysqli_fetch_assoc($vendors)
    ) {

        $vendorSummary[] = $row;

        $vendorCount++;

        if (
            strtolower(
                $row['status']
                ?? ''
            ) === 'active'
        ) {
            $activeCount++;
        }

        $totalJobs +=
            (int) (
                $row['jobs']
                ?? 0
            );

        $totalVendorCost +=
            (float) (
                $row['total_cost']
                ?? 0
            );
    }
}


include '../inc/header.php';
?>

<link
    rel="stylesheet"
    href="../assets/css/facility-vendors.css?v=1.0"
>

<div class="fv-page">

    <!-- ========================================================
         HEADER
    ========================================================= -->

    <div class="fv-page-header">

        <div>

            <div class="fv-eyebrow">
                Facility Management
            </div>

            <h2>
                Facility Vendors
            </h2>

            <p>
                Manage external service providers and review vendor performance across maintenance operations.
            </p>

        </div>


        <?php if ($can): ?>

            <button
                type="button"
                class="fv-btn fv-btn-primary"
                onclick="openVendorModal()"
            >
                <i class="fa-solid fa-plus"></i>
                Add Vendor
            </button>

        <?php endif; ?>

    </div>


    <!-- ========================================================
         FLASH MESSAGE
    ========================================================= -->

    <?php if (
        !empty(
            $_SESSION['flash_text']
        )
    ): ?>

        <div
            class="
                fv-alert
                <?= facilityH(
                    $_SESSION['flash_type']
                ) ?>
            "
        >
            <?= facilityH(
                $_SESSION['flash_text']
            ) ?>
        </div>

        <?php
        unset(
            $_SESSION['flash_text'],
            $_SESSION['flash_type']
        );
        ?>

    <?php endif; ?>


    <!-- ========================================================
         SUMMARY
    ========================================================= -->

    <div class="fv-kpi-grid">

        <div class="fv-kpi-card">

            <div class="fv-kpi-icon vendors">
                <i class="fa-solid fa-handshake"></i>
            </div>

            <div>

                <span>
                    Total Vendors
                </span>

                <strong>
                    <?= $vendorCount ?>
                </strong>

                <small>
                    Registered service providers
                </small>

            </div>

        </div>


        <div class="fv-kpi-card">

            <div class="fv-kpi-icon active">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <div>

                <span>
                    Active Vendors
                </span>

                <strong>
                    <?= $activeCount ?>
                </strong>

                <small>
                    Currently available providers
                </small>

            </div>

        </div>


        <div class="fv-kpi-card">

            <div class="fv-kpi-icon jobs">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>

            <div>

                <span>
                    Vendor Jobs
                </span>

                <strong>
                    <?= $totalJobs ?>
                </strong>

                <small>
                    Total assigned maintenance jobs
                </small>

            </div>

        </div>


        <div class="fv-kpi-card">

            <div class="fv-kpi-icon cost">
                <i class="fa-solid fa-naira-sign"></i>
            </div>

            <div>

                <span>
                    Vendor Cost
                </span>

                <strong class="fv-money">
                    ₦<?= number_format(
                        $totalVendorCost,
                        0
                    ) ?>
                </strong>

                <small>
                    Recorded vendor expenditure
                </small>

            </div>

        </div>

    </div>


    <!-- ========================================================
         VENDOR LIST
    ========================================================= -->

    <section class="fv-panel">

        <div class="fv-panel-header">

            <div>

                <h3>
                    Vendor Performance
                </h3>

                <p>
                    Job volume, completion rate, response performance and vendor cost.
                </p>

            </div>

        </div>


        <div class="fv-table-wrap">

            <table class="fv-table">

                <thead>

                    <tr>
                        <th>Vendor</th>
                        <th>Services</th>
                        <th class="number">Jobs</th>
                        <th class="number">Completed</th>
                        <th>Completion Rate</th>
                        <th>Avg Resolution</th>
                        <th class="number">Vendor Cost</th>
                        <th>Status</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (
                    !empty(
                        $vendorSummary
                    )
                ): ?>

                    <?php foreach (
                        $vendorSummary
                        as $vendor
                    ): ?>

                        <?php
                        $jobs =
                            (int) (
                                $vendor['jobs']
                                ?? 0
                            );

                        $completed =
                            (int) (
                                $vendor['completed']
                                ?? 0
                            );

                        $completionRate =
                            $jobs > 0
                                ? round(
                                    (
                                        $completed /
                                        $jobs
                                    ) * 100,
                                    1
                                )
                                : 0;

                        $statusClass =
                            strtolower(
                                str_replace(
                                    ' ',
                                    '-',
                                    $vendor['status']
                                    ?? 'inactive'
                                )
                            );
                        ?>

                        <tr>

                            <td>

                                <div class="fv-vendor-main">

                                    <div class="fv-vendor-avatar">

                                        <?= strtoupper(
                                            substr(
                                                trim(
                                                    $vendor[
                                                        'vendor_name'
                                                    ]
                                                ),
                                                0,
                                                1
                                            )
                                        ) ?>

                                    </div>


                                    <div>

                                        <strong>
                                            <?= facilityH(
                                                $vendor[
                                                    'vendor_name'
                                                ]
                                            ) ?>
                                        </strong>

                                        <?php if (
                                            !empty(
                                                $vendor[
                                                    'contact_person'
                                                ]
                                            )
                                        ): ?>

                                            <span>
                                                <?= facilityH(
                                                    $vendor[
                                                        'contact_person'
                                                    ]
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $vendor[
                                                    'phone'
                                                ]
                                            )
                                        ): ?>

                                            <small>
                                                <i class="fa-solid fa-phone"></i>

                                                <?= facilityH(
                                                    $vendor[
                                                        'phone'
                                                    ]
                                                ) ?>
                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>


                            <td>

                                <div class="fv-services">
                                    <?= facilityH(
                                        $vendor[
                                            'service_categories'
                                        ]
                                        ?: '—'
                                    ) ?>
                                </div>

                            </td>


                            <td class="number">
                                <?= $jobs ?>
                            </td>


                            <td class="number">
                                <?= $completed ?>
                            </td>


                            <td>

                                <div class="fv-rate">

                                    <div class="fv-rate-track">

                                        <span
                                            style="
                                                width:
                                                <?= min(
                                                    100,
                                                    $completionRate
                                                ) ?>%;
                                            "
                                        ></span>

                                    </div>

                                    <strong>
                                        <?= $completionRate ?>%
                                    </strong>

                                </div>

                            </td>


                            <td>

                                <?= round(
                                    (float) (
                                        $vendor[
                                            'avg_resolution_hours'
                                        ]
                                        ?? 0
                                    ),
                                    1
                                ) ?>
                                hrs

                            </td>


                            <td class="number">

                                ₦<?= number_format(
                                    (float) (
                                        $vendor[
                                            'total_cost'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="
                                        fv-status
                                        <?= facilityH(
                                            $statusClass
                                        ) ?>
                                    "
                                >
                                    <?= facilityH(
                                        ucfirst(
                                            $vendor[
                                                'status'
                                            ]
                                            ?? 'inactive'
                                        )
                                    ) ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="8"
                            class="fv-empty-cell"
                        >

                            <div class="fv-empty-state">

                                <i class="fa-solid fa-handshake-slash"></i>

                                <strong>
                                    No vendors yet
                                </strong>

                                <span>
                                    Add your first Facility vendor to begin tracking service-provider performance.
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


<!-- ==========================================================
     ADD VENDOR MODAL
=========================================================== -->

<div
    class="fv-modal-overlay"
    id="vendorModal"
>

    <div
        class="fv-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="vendorModalTitle"
    >

        <div class="fv-modal-header">

            <div>

                <div class="fv-modal-icon">
                    <i class="fa-solid fa-handshake"></i>
                </div>

                <div>

                    <h3 id="vendorModalTitle">
                        Add Facility Vendor
                    </h3>

                    <p>
                        Register an external maintenance or service provider.
                    </p>

                </div>

            </div>


            <button
                type="button"
                class="fv-modal-close"
                onclick="closeVendorModal()"
                aria-label="Close"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            class="fv-vendor-form"
            id="vendorForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= facilityH(
                    facilityCsrfToken()
                ) ?>"
            >


            <div class="fv-form-grid">

                <div class="fv-field fv-span-2">

                    <label>
                        Vendor Name
                        <span>*</span>
                    </label>

                    <input
                        type="text"
                        name="vendor_name"
                        required
                        maxlength="190"
                        placeholder="e.g. ABC Cooling Services"
                    >

                </div>


                <div class="fv-field">

                    <label>
                        Contact Person
                    </label>

                    <input
                        type="text"
                        name="contact_person"
                        maxlength="150"
                        placeholder="Primary contact"
                    >

                </div>


                <div class="fv-field">

                    <label>
                        Phone
                    </label>

                    <input
                        type="text"
                        name="phone"
                        maxlength="50"
                        placeholder="e.g. 080..."
                    >

                </div>


                <div class="fv-field fv-span-2">

                    <label>
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        maxlength="190"
                        placeholder="vendor@example.com"
                    >

                </div>


                <div class="fv-field fv-span-2">

                    <label>
                        Services / Categories
                    </label>

                    <textarea
                        name="service_categories"
                        rows="4"
                        placeholder="e.g. Air conditioning, refrigeration, generator servicing..."
                    ></textarea>

                </div>

            </div>


            <div class="fv-form-actions">

                <button
                    type="button"
                    class="fv-btn fv-btn-light"
                    onclick="closeVendorModal()"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="fv-btn fv-btn-primary"
                    id="saveVendorBtn"
                >
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Vendor
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function openVendorModal() {

    const modal =
        document.getElementById(
            'vendorModal'
        );

    if (modal) {
        modal.classList.add('active');
    }
}


function closeVendorModal() {

    const modal =
        document.getElementById(
            'vendorModal'
        );

    if (modal) {
        modal.classList.remove('active');
    }
}


document.addEventListener(
    'DOMContentLoaded',
    function () {

        const modal =
            document.getElementById(
                'vendorModal'
            );

        modal?.addEventListener(
            'click',
            function (event) {

                if (
                    event.target === modal
                ) {
                    closeVendorModal();
                }

            }
        );


        document.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key === 'Escape'
                ) {
                    closeVendorModal();
                }

            }
        );


        const form =
            document.getElementById(
                'vendorForm'
            );

        const saveButton =
            document.getElementById(
                'saveVendorBtn'
            );

        form?.addEventListener(
            'submit',
            function (event) {

                if (
                    form.dataset.submitting ===
                    'true'
                ) {

                    event.preventDefault();
                    return;

                }

                form.dataset.submitting =
                    'true';

                if (saveButton) {

                    saveButton.disabled = true;

                    saveButton.innerHTML =
                        '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

                }

            }
        );

    }
);

</script>

<?php include '../inc/footer.php'; ?>
