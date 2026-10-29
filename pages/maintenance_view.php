<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';
require '../inc/facility_functions.php';
$pageTitle = 'Facility Maintenance Request';
$userId = (int) ($_SESSION['user_id'] ?? 0);
facilityCsrfToken();
$id = (int) ($_GET['id'] ?? 0);
$canOfficer = facilityHasRole($connection, $userId, ['facility_officer', 'facility_admin']);
$isTech = facilityHasRole($connection, $userId, 'facility_technician');
$canVerify = facilityHasRole($connection, $userId, ['facility_verifier', 'facility_officer', 'facility_admin']);
$canApprove = facilityHasRole($connection, $userId, ['facility_project_manager', 'facility_admin']);
$canInternal = facilityCanSeeInternal($connection, $userId);
function mvFlash($t, $m)
{
    $_SESSION['flash_type'] = $t;
    $_SESSION['flash_text'] = $m;
}
function getMaint(mysqli $c, int $id)
{
    $r = mysqli_query($c, "SELECT m.*,o.warehouse_name,a.asset_code,a.asset_name,a.brand,a.model,a.serial_number,ac.category_name asset_category,mc.category_name maintenance_category,rq.fullname requester_name,tu.fullname technician_name,v.vendor_name,vf.fullname verifier_name FROM facility_maintenance_requests m JOIN outlets o ON o.id=m.outlet_id LEFT JOIN facility_assets a ON a.id=m.asset_id LEFT JOIN facility_asset_categories ac ON ac.id=a.category_id LEFT JOIN facility_maintenance_categories mc ON mc.id=m.maintenance_category_id LEFT JOIN users rq ON rq.id=m.requested_by LEFT JOIN facility_technicians ft ON ft.id=m.assigned_technician_id LEFT JOIN users tu ON tu.id=ft.user_id LEFT JOIN facility_vendors v ON v.id=m.assigned_vendor_id LEFT JOIN users vf ON vf.id=m.verified_by WHERE m.id={$id} LIMIT 1");
    return $r ? mysqli_fetch_assoc($r) : null;
}
$m = getMaint($connection, $id);
if (!$m) {
    header('Location:support_tickets.php?tab=facility');
    exit;
}
$techProfile = null;
if ($isTech) {
    $tr = mysqli_query($connection, "SELECT id FROM facility_technicians WHERE user_id={$userId} AND status='active' LIMIT 1");
    $techProfile = $tr ? mysqli_fetch_assoc($tr) : null;
}
$allowed = $canOfficer || $canVerify || $canApprove || (int) $m['requested_by'] === $userId || ($techProfile && (int) $m['assigned_technician_id'] === (int) $techProfile['id']);
if (!$allowed) {
    header('Location:support_tickets.php?tab=facility');
    exit;
}
mysqli_query($connection, "UPDATE facility_notifications SET is_read=1 WHERE maintenance_request_id={$id} AND user_id={$userId}");
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!facilityValidateCsrf()) {
        mvFlash('error', 'Invalid request token.');
        header("Location:maintenance_view.php?id={$id}");
        exit;
    }
    $a = $_POST['action'] ?? '';
    if ($a === 'review' && $canOfficer && $m['status'] === 'New') {
        mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='Under Review',reviewed_by={$userId},reviewed_at=NOW() WHERE id={$id}");
        facilityLog($connection, $id, $userId, 'Request reviewed');
        mvFlash('success', 'Request reviewed.');
    }
    if ($a === 'assign' && $canOfficer) {
        $kind = $_POST['assignment_type'] ?? '';
        $techId = (int) ($_POST['technician_id'] ?? 0);
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        if ($kind === 'technician') {
            $vr = mysqli_query($connection, "SELECT ft.id,ft.user_id,u.fullname FROM facility_technicians ft JOIN users u ON u.id=ft.user_id AND u.status='active' JOIN facility_user_roles fur ON fur.user_id=u.id AND fur.role_key='facility_technician' WHERE ft.id={$techId} AND ft.status='active' LIMIT 1");
            $v = $vr ? mysqli_fetch_assoc($vr) : null;
            if (!$v) {
                mvFlash('error', 'Selected person is not eligible.');
                header("Location:maintenance_view.php?id={$id}");
                exit;
            }
            mysqli_query($connection, "UPDATE facility_maintenance_assignments SET assignment_status='reassigned' WHERE maintenance_request_id={$id} AND assignment_status IN ('assigned','accepted','in_progress')");
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_assignments(maintenance_request_id,assignment_type,technician_id,assigned_by) VALUES (?,'technician',?,?)");
            mysqli_stmt_bind_param($s, 'iii', $id, $techId, $userId);
            mysqli_stmt_execute($s);
            mysqli_query($connection, "UPDATE facility_maintenance_requests SET assigned_type='technician',assigned_technician_id={$techId},assigned_vendor_id=NULL,assigned_by={$userId},assigned_at=NOW(),status='Assigned' WHERE id={$id}");
            facilityLog($connection, $id, $userId, 'Assigned to technician', $v['fullname']);
            facilityNotify($connection, (int) $v['user_id'], $id, 'assigned', "{$m['request_number']} has been assigned to you.", $m['asset_id'] ?: null);
            mvFlash('success', 'Job assigned.');
        } elseif ($kind === 'vendor') {
            $vr = mysqli_query($connection, "SELECT id,vendor_name FROM facility_vendors WHERE id={$vendorId} AND status='active' LIMIT 1");
            $v = $vr ? mysqli_fetch_assoc($vr) : null;
            if (!$v) {
                mvFlash('error', 'Selected vendor is not active.');
                header("Location:maintenance_view.php?id={$id}");
                exit;
            }
            mysqli_query($connection, "UPDATE facility_maintenance_assignments SET assignment_status='reassigned' WHERE maintenance_request_id={$id} AND assignment_status IN ('assigned','accepted','in_progress')");
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_assignments(maintenance_request_id,assignment_type,vendor_id,assigned_by) VALUES (?,'vendor',?,?)");
            mysqli_stmt_bind_param($s, 'iii', $id, $vendorId, $userId);
            mysqli_stmt_execute($s);
            mysqli_query($connection, "UPDATE facility_maintenance_assignments SET assignment_status='in_progress', accepted_at=COALESCE(accepted_at,NOW()), started_at=COALESCE(started_at,NOW()) WHERE maintenance_request_id={$id} AND vendor_id={$vendorId} AND assignment_type='vendor' AND assignment_status='assigned'");
            mysqli_query($connection, "UPDATE facility_maintenance_requests SET assigned_type='vendor', assigned_vendor_id={$vendorId}, assigned_technician_id=NULL, assigned_by={$userId}, assigned_at=NOW(), started_at=COALESCE(started_at,NOW()), status='In Progress' WHERE id={$id}");
            facilityLog($connection, $id, $userId, 'Assigned to vendor', $v['vendor_name']);
            mvFlash(
                'success',
                'External vendor assigned. Work is now in progress.'
            );
        }
    }
    if ($a === 'start_work' && $techProfile && (int) $m['assigned_technician_id'] === (int) $techProfile['id'] && in_array($m['status'], ['Assigned', 'Verification Failed'], true)) {
        mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='In Progress',started_at=COALESCE(started_at,NOW()) WHERE id={$id}");
        mysqli_query($connection, "UPDATE facility_maintenance_assignments SET assignment_status='in_progress',accepted_at=COALESCE(accepted_at,NOW()),started_at=COALESCE(started_at,NOW()) WHERE maintenance_request_id={$id} AND technician_id=" . (int) $techProfile['id'] . " AND assignment_status IN ('assigned','accepted')");
        facilityLog($connection, $id, $userId, 'Repair started');
        mvFlash('success', 'Work started.');
    }
    if ($a === 'add_work' && $techProfile) {
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $work = trim($_POST['work_performed'] ?? '');
        $rec = trim($_POST['recommendation'] ?? '');
        if ($work !== '') {
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_work_logs(maintenance_request_id,technician_id,diagnosis,work_performed,recommendation,created_by) VALUES (?,?,?,?,?,?)");
            $tid = (int) $techProfile['id'];
            mysqli_stmt_bind_param($s, 'iisssi', $id, $tid, $diagnosis, $work, $rec, $userId);
            mysqli_stmt_execute($s);
            facilityLog($connection, $id, $userId, 'Work log added');
            mvFlash('success', 'Work log added.');
        }
    }
    if ($a === 'add_part' && ($isTech || $canOfficer)) {
        $part = trim($_POST['part_name'] ?? '');
        $partNo = trim($_POST['part_number'] ?? '');
        $qty = max(.01, (float) ($_POST['quantity'] ?? 1));
        $unit = max(0, (float) ($_POST['unit_cost'] ?? 0));
        if ($part !== '') {
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_parts_used(maintenance_request_id,part_name,part_number,quantity,unit_cost,replaced_by) VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($s, 'issddi', $id, $part, $partNo, $qty, $unit, $userId);
            mysqli_stmt_execute($s);
            if ($unit > 0) {
                $amount = $qty * $unit;
                $desc = $part . ' x ' . $qty;
                $c = mysqli_prepare($connection, "INSERT INTO facility_maintenance_costs(maintenance_request_id,cost_type,description,amount,created_by) VALUES (?,'Spare Part',?,?,?)");
                mysqli_stmt_bind_param($c, 'isdi', $id, $desc, $amount, $userId);
                mysqli_stmt_execute($c);
            }
            facilityLog($connection, $id, $userId, 'Spare part recorded', $part);
            mvFlash('success', 'Part recorded.');
        }
    }
    if ($a === 'add_cost' && ($isTech || $canOfficer)) {
        $type = $_POST['cost_type'] ?? 'Other';
        $desc = trim($_POST['description'] ?? '');
        $amount = max(0, (float) ($_POST['amount'] ?? 0));
        if (in_array($type, ['Labour', 'Vendor', 'Transportation', 'Other'], true) && $amount > 0) {
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_costs(maintenance_request_id,cost_type,description,amount,created_by) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($s, 'issdi', $id, $type, $desc, $amount, $userId);
            mysqli_stmt_execute($s);
            facilityLog($connection, $id, $userId, 'Maintenance cost added', $type);
            mvFlash('success', 'Cost recorded.');
        }
    }
    if ($a === 'complete' && $techProfile && $m['status'] === 'In Progress') {
        mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='Awaiting Verification',repair_completed_at=NOW(),repair_completed_by={$userId},restored_at=CASE WHEN asset_id IS NOT NULL THEN NOW() ELSE restored_at END WHERE id={$id}");
        if ($m['asset_id'])
            mysqli_query($connection, "UPDATE facility_assets SET operational_status='operational' WHERE id=" . (int) $m['asset_id']);
        mysqli_query($connection, "UPDATE facility_maintenance_assignments SET assignment_status='completed',completed_at=NOW() WHERE maintenance_request_id={$id} AND assignment_status='in_progress'");
        facilityLog($connection, $id, $userId, 'Repair completed', 'Awaiting verification');
        mvFlash('success', 'Repair sent for verification.');
    }

    /* ============================================================
   CLOSE EXTERNAL VENDOR JOB
   Facility Officer / Facility Admin only.
   No verification or Project Manager approval required.
============================================================ */

    if (
        $a === 'close_vendor' && $canOfficer && ($m['assigned_type'] ?? '') === 'vendor' && in_array($m['status'], ['In Progress', 'Pending Vendor'], true)
    ) {
        $closureNotes = trim($_POST['vendor_closure_notes'] ?? '');
        $safeNotes = mysqli_real_escape_string($connection, $closureNotes);
        mysqli_begin_transaction($connection);
        try {
            /* ----------------------------------------------------
               CLOSE MAINTENANCE REQUEST
            ---------------------------------------------------- */
            $closeRequest = mysqli_query($connection, " UPDATE facility_maintenance_requests SET status = 'Closed', repair_completed_at = COALESCE( repair_completed_at, NOW() ), repair_completed_by = {$userId}, restored_at = COALESCE( restored_at, NOW() ), approval_notes = '{$safeNotes}', closed_at = NOW() WHERE id = {$id} ");
            if (!$closeRequest) {
                throw new Exception(
                    mysqli_error(
                        $connection
                    )
                );
            }

            /* ----------------------------------------------------
               MARK VENDOR ASSIGNMENT COMPLETED
            ---------------------------------------------------- */
            mysqli_query($connection, " UPDATE facility_maintenance_assignments SET assignment_status = 'completed', completed_at = NOW() WHERE maintenance_request_id = {$id} AND assignment_type = 'vendor' AND assignment_status IN ( 'assigned', 'accepted', 'in_progress' ) ");
            /* ----------------------------------------------------
               UPDATE ASSET
            ---------------------------------------------------- */
            if (
                !empty($m['asset_id'])
            ) {
                $assetId = (int) $m['asset_id'];
                mysqli_query($connection, " UPDATE facility_assets SET operational_status = 'operational', last_maintenance_date = CURDATE() WHERE id = {$assetId} ");
                /* ------------------------------------------------
                   ADVANCE ACTIVE PM SCHEDULE
               ------------------------------------------------ */
                $scheduleResult =
                    mysqli_query($connection, " SELECT * FROM facility_pm_schedules WHERE asset_id = {$assetId} AND status = 'active' ORDER BY id DESC LIMIT 1 ");
                if (
                    $scheduleResult && ($schedule = mysqli_fetch_assoc($scheduleResult))
                ) {
                    $nextDate = facilityNextDate(date('Y-m-d'), (int) $schedule['frequency_value'], $schedule['frequency_unit']);
                    $scheduleId = (int) $schedule['id'];
                    mysqli_query($connection, " UPDATE facility_pm_schedules SET last_completed_date = CURDATE(), next_due_date = '{$nextDate}' WHERE id = {$scheduleId} ");
                    mysqli_query($connection, " UPDATE facility_assets SET next_maintenance_date = '{$nextDate}' WHERE id = {$assetId} ");
                }
            }
            /* ----------------------------------------------------
               AUDIT LOG
            ---------------------------------------------------- */
            facilityLog($connection, $id, $userId, 'External vendor job completed and closed', $closureNotes);
            /* ----------------------------------------------------
               NOTIFY REQUESTER
            ---------------------------------------------------- */
            facilityNotify($connection, (int) $m['requested_by'], $id, 'closed', "{$m['request_number']} has been completed by the external vendor and closed.", $m['asset_id'] ?: null);
            mysqli_commit(
                $connection
            );
            mvFlash('success', 'External vendor job completed and ticket closed.');
        } catch (Throwable $e) {
            mysqli_rollback($connection);
            error_log('Vendor ticket close error: ' . $e->getMessage());
            mvFlash('error', 'Unable to close the external vendor ticket.');
        }
    }

    if ($a === 'verify' && $canVerify && $m['status'] === 'Awaiting Verification') {
        $result = $_POST['verification_status'] ?? '';
        $notes = trim($_POST['verification_notes'] ?? '');
        if (in_array($result, ['passed', 'failed', 'further_work'], true)) {
            $next = $result === 'passed' ? 'Awaiting Approval' : 'Verification Failed';
            $safe = mysqli_real_escape_string($connection, $notes);
            $res = mysqli_real_escape_string($connection, $result);
            mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='{$next}',verified_by={$userId},verified_at=NOW(),verification_status='{$res}',verification_notes='{$safe}' WHERE id={$id}");
            facilityLog($connection, $id, $userId, $result === 'passed' ? 'Repair verified' : 'Verification failed', $notes);
            mvFlash('success', $result === 'passed' ? 'Verified and sent for approval.' : 'Returned for further work.');
        }
    }
    if ($a === 'approve' && $canApprove && $m['status'] === 'Awaiting Approval') {
        $notes = mysqli_real_escape_string($connection, trim($_POST['approval_notes'] ?? ''));
        mysqli_begin_transaction($connection);
        try {
            mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='Closed',approved_by={$userId},approved_at=NOW(),approval_status='approved',approval_notes='{$notes}',closed_at=NOW() WHERE id={$id}");
            if ($m['asset_id']) {
                mysqli_query($connection, "UPDATE facility_assets SET last_maintenance_date=CURDATE() WHERE id=" . (int) $m['asset_id']);
                $sr = mysqli_query($connection, "SELECT * FROM facility_pm_schedules WHERE asset_id=" . (int) $m['asset_id'] . " AND status='active' ORDER BY id DESC LIMIT 1");
                if ($sr && ($sch = mysqli_fetch_assoc($sr))) {
                    $next = facilityNextDate(date('Y-m-d'), (int) $sch['frequency_value'], $sch['frequency_unit']);
                    mysqli_query($connection, "UPDATE facility_pm_schedules SET last_completed_date=CURDATE(),next_due_date='{$next}' WHERE id=" . (int) $sch['id']);
                    mysqli_query($connection, "UPDATE facility_assets SET next_maintenance_date='{$next}' WHERE id=" . (int) $m['asset_id']);
                }
            }
            facilityLog($connection, $id, $userId, 'Request approved and closed');
            facilityNotify($connection, (int) $m['requested_by'], $id, 'closed', "{$m['request_number']} has been approved and closed.", $m['asset_id'] ?: null);
            mysqli_commit($connection);
            mvFlash('success', 'Request approved and closed.');
        } catch (Throwable $e) {
            mysqli_rollback($connection);
            mvFlash('error', 'Unable to close request.');
        }
    }
    if ($a === 'return' && $canApprove && $m['status'] === 'Awaiting Approval') {
        $notes = mysqli_real_escape_string($connection, trim($_POST['approval_notes'] ?? ''));
        mysqli_query($connection, "UPDATE facility_maintenance_requests SET status='Verification Failed',approval_status='returned',approval_notes='{$notes}',approved_by={$userId},approved_at=NOW() WHERE id={$id}");
        facilityLog($connection, $id, $userId, 'Closure returned', $notes);
        mvFlash('success', 'Returned for further work.');
    }
    if ($a === 'comment') {
        $text = trim($_POST['comment'] ?? '');
        $internal = isset($_POST['is_internal']) && $canInternal ? 1 : 0;
        if ($text !== '') {
            $s = mysqli_prepare($connection, "INSERT INTO facility_maintenance_comments(maintenance_request_id,user_id,comment,is_internal) VALUES (?,?,?,?)");
            mysqli_stmt_bind_param($s, 'iisi', $id, $userId, $text, $internal);
            mysqli_stmt_execute($s);
            facilityLog($connection, $id, $userId, $internal ? 'Internal note added' : 'Public reply added');
        }
    }
    header("Location:maintenance_view.php?id={$id}");
    exit;
}
$m = getMaint($connection, $id);
$att = mysqli_query($connection, "SELECT * FROM facility_maintenance_attachments WHERE maintenance_request_id={$id} ORDER BY created_at");
$work = mysqli_query($connection, "SELECT w.*,u.fullname FROM facility_maintenance_work_logs w LEFT JOIN users u ON u.id=w.created_by WHERE w.maintenance_request_id={$id} ORDER BY w.created_at");
$parts = mysqli_query($connection, "SELECT * FROM facility_maintenance_parts_used WHERE maintenance_request_id={$id} ORDER BY created_at");
$costs = mysqli_query($connection, "SELECT * FROM facility_maintenance_costs WHERE maintenance_request_id={$id} ORDER BY created_at");
$tc = mysqli_query($connection, "SELECT COALESCE(SUM(amount),0) total FROM facility_maintenance_costs WHERE maintenance_request_id={$id}");
$total = $tc ? (float) (mysqli_fetch_assoc($tc)['total'] ?? 0) : 0;
$cw = $canInternal ? '' : 'AND c.is_internal=0';
$comments = mysqli_query($connection, "SELECT c.*,u.fullname FROM facility_maintenance_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.maintenance_request_id={$id} {$cw} ORDER BY c.created_at");
$activity = mysqli_query($connection, "SELECT l.*,u.fullname FROM facility_maintenance_activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.maintenance_request_id={$id} ORDER BY l.created_at");
$techs = mysqli_query($connection, "SELECT ft.id,u.fullname,ft.specialization FROM facility_technicians ft JOIN users u ON u.id=ft.user_id AND u.status='active' JOIN facility_user_roles r ON r.user_id=u.id AND r.role_key='facility_technician' WHERE ft.status='active' ORDER BY u.fullname");
$vendors = mysqli_query($connection, "SELECT id,vendor_name FROM facility_vendors WHERE status='active' ORDER BY vendor_name");
$downtime = '—';
if ($m['downtime_started_at']) {
    $secs = max(0, strtotime($m['restored_at'] ?: date('Y-m-d H:i:s')) - strtotime($m['downtime_started_at']));
    $downtime = intdiv($secs, 3600) . 'h ' . intdiv($secs % 3600, 60) . 'm';
}
$response = '—';
if ($m['assigned_at']) {
    $secs = max(0, strtotime($m['assigned_at']) - strtotime($m['created_at']));
    $response = intdiv($secs, 3600) . 'h ' . intdiv($secs % 3600, 60) . 'm';
}
include '../inc/header.php'; ?>

<link rel="stylesheet" href="../assets/css/maintenance-view.css?v=1.0">
<div class="mv-page">
    <?php if (!empty($_SESSION['flash_text'])): ?>
        <div class="mv-alert <?= facilityH($_SESSION['flash_type']) ?>"><?= facilityH($_SESSION['flash_text']) ?></div>
        <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); endif; ?>
    <a href="support_tickets.php?tab=facility" class="mv-back-link"><i class="fa-solid fa-arrow-left"></i> Back to
        Facility Maintenance</a>
    <section class="mv-request-header">
        <div class="mv-header-main">
            <div class="mv-eyebrow">Facility Maintenance Request
                <span>
                    <?= facilityH($m['request_number']) ?>
                </span>
            </div>
            <h2><?= facilityH($m['subject']) ?></h2>
            <div class="mv-meta-row"><span
                    class="mv-priority <?= facilityH(facilityPriorityClass($m['priority'])) ?>"><?= facilityH($m['priority']) ?></span><span
                    class="mv-status <?= facilityH(facilityStatusClass($m['status'])) ?>"><?= facilityH($m['status']) ?></span><span
                    class="mv-meta-item"><i
                        class="fa-solid fa-layer-group"></i><?= facilityH($m['maintenance_category'] ?? 'General Maintenance') ?></span><span
                    class="mv-meta-item"><i
                        class="fa-solid fa-location-dot"></i><?= facilityH($m['warehouse_name']) ?></span></div>
            <p class="mv-submitted">Submitted by <strong><?= facilityH($m['requester_name']) ?></strong> on
                <?= facilityH(date('M d, Y g:i A', strtotime($m['created_at']))) ?>
            </p>
        </div>
        <div class="mv-header-actions"><?php if ($canOfficer && $m['status'] === 'New'): ?>
                <form method="POST"><input type="hidden" name="csrf_token"
                        value="<?= facilityH(facilityCsrfToken()) ?>"><button name="action" value="review"
                        class="mv-btn mv-btn-primary"><i class="fa-solid fa-magnifying-glass"></i>Review Request</button>
                </form>
            <?php endif; ?>
            <?php
            $assignedToCurrentEngineer =
                $techProfile
                &&
                (int) ($m['assigned_technician_id'] ?? 0)
                ===
                (int) ($techProfile['id'] ?? 0);
            ?>

            <?php if (
                $assignedToCurrentEngineer
                &&
                in_array(
                    $m['status'],
                    ['Assigned', 'Verification Failed'],
                    true
                )
            ): ?>

                <form method="POST">

                    <input type="hidden" name="csrf_token" value="<?= facilityH(facilityCsrfToken()) ?>">

                    <button type="submit" name="action" value="start_work" class="mv-btn mv-btn-success">
                        <i class="fa-solid fa-play"></i>
                        Start Work
                    </button>

                </form>

            <?php endif; ?>
            <?php if ($techProfile && $m['status'] === 'In Progress'): ?>
                <form method="POST"><input type="hidden" name="csrf_token"
                        value="<?= facilityH(facilityCsrfToken()) ?>"><button name="action" value="complete"
                        class="mv-btn mv-btn-success"><i class="fa-solid fa-circle-check"></i>Mark Repair Complete</button>
                </form><?php endif; ?>
        </div>
    </section>
    <div class="mv-kpi-grid">
        <div class="mv-kpi-card"><span>Current
                Status</span><strong><?= facilityH($m['status']) ?></strong><small>Workflow stage</small></div>
        <div class="mv-kpi-card"><span>Priority</span><strong><?= facilityH($m['priority']) ?></strong><small>Request
                urgency</small></div>
        <div class="mv-kpi-card"><span>Response Time</span><strong><?= facilityH($response) ?></strong><small>Created to
                assignment</small></div>
        <div class="mv-kpi-card"><span>Downtime</span><strong><?= facilityH($downtime) ?></strong><small>Recorded asset
                downtime</small></div>
        <div class="mv-kpi-card"><span>Total Cost</span><strong
                class="mv-money">₦<?= number_format($total, 2) ?></strong><small>Maintenance spend</small></div>
    </div>
    <div class="mv-layout">
        <div class="mv-main-col">
            <section class="mv-panel">
                <div class="mv-panel-header">
                    <div>
                        <h3>Problem Description</h3>
                        <p>Original issue submitted by the requester.</p>
                    </div>
                </div>
                <div class="mv-description"><?= nl2br(facilityH($m['description'])) ?></div>
                <?php if ($att && mysqli_num_rows($att)): ?>
                    <div class="mv-attachments">
                        <div class="mv-subheading">Supporting Evidence</div><?php while ($f = mysqli_fetch_assoc($att)): ?>
                            <div class="mv-file-row">
                                <div class="mv-file-main"><i class="fa-solid fa-paperclip"></i>
                                    <div>
                                        <strong><?= facilityH($f['file_name']) ?></strong><span><?= facilityH($f['attachment_type']) ?></span>
                                    </div>
                                </div><a href="../<?= facilityH($f['file_path']) ?>" target="_blank" class="mv-text-link">View
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                            </div><?php endwhile; ?>
                    </div><?php endif; ?>
            </section>
            <?php if ($canOfficer && in_array($m['status'], ['Under Review', 'Assigned', 'Pending Vendor'], true)): ?>
                <section class="mv-panel mv-action-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3> <?= !empty($m['assigned_type']) ? 'Current Assignment' : 'Assignment' ?> </h3>
                            <p> <?php if (($m['assigned_type'] ?? '') === 'technician'): ?> Currently assigned to <strong>
                                        <?= facilityH($m['technician_name'] ?? 'Engineer') ?> </strong>. You may reassign if
                                    necessary. <?php elseif (($m['assigned_type'] ?? '') === 'vendor'): ?> Currently assigned to
                                    <strong> <?= facilityH($m['vendor_name'] ?? 'Vendor') ?> </strong>. You may reassign if
                                    necessary. <?php else: ?> Assign this request to an engineer or external vendor.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <form method="POST" class="mv-assignment-form"><input type="hidden" name="csrf_token"
                            value="<?= facilityH(facilityCsrfToken()) ?>">
                        <div class="mv-field"> <label> Assignment Type </label> <select name="assignment_type"
                                id="assignmentType">
                                <option value="technician" <?= ($m['assigned_type'] ?? 'technician') === 'technician' ? 'selected' : '' ?>>
                                    Internal Engineer </option>
                                <option value="vendor" <?= ($m['assigned_type'] ?? '') === 'vendor' ? 'selected' : '' ?>>
                                    External Vendor </option>
                            </select> </div>
                        <div class="mv-field" id="staffField"> <label for="techSelect"> Staff </label> <select
                                name="technician_id" id="techSelect">
                                <option value="0"> Select Staff </option>
                                <?php while ($t = mysqli_fetch_assoc($techs)): ?>
                                    <?php $isAssignedEngineer = (int) ($m['assigned_technician_id'] ?? 0) === (int) $t['id']; ?>
                                    <option value="<?= (int) $t['id'] ?>" <?= $isAssignedEngineer ? 'selected' : '' ?>>
                                        <?= facilityH($t['fullname'] . ($t['specialization'] ? ' — ' . $t['specialization'] : '')) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select> </div>
                        <div class="mv-field" id="vendorField"> <label for="vendorSelect"> Vendor </label> <select
                                name="vendor_id" id="vendorSelect">
                                <option value="0"> Select Vendor </option>
                                <?php while ($v = mysqli_fetch_assoc($vendors)): ?>
                                    <?php $isAssignedVendor = (int) ($m['assigned_vendor_id'] ?? 0) === (int) $v['id']; ?>
                                    <option value="<?= (int) $v['id'] ?>" <?= $isAssignedVendor ? 'selected' : '' ?>>
                                        <?= facilityH($v['vendor_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select> </div>
                        <div class="mv-assignment-action">
                            <button name="action" value="assign" class="mv-btn mv-btn-primary">
                                <i class="fa-solid fa-user-check"></i>

                                <?= !empty($m['assigned_type'])
                                    ? 'Reassign'
                                    : 'Assign'
                                    ?>
                            </button>
                        </div>
                    </form>
                </section><?php endif; ?>

            <?php if (
                $canOfficer
                &&
                ($m['assigned_type'] ?? '') === 'vendor'
                &&
                in_array(
                    $m['status'],
                    ['In Progress', 'Pending Vendor'],
                    true
                )
            ): ?>

                <section class="mv-panel mv-decision-panel">

                    <div class="mv-panel-header">
                        <div>
                            <h3>External Vendor Job</h3>

                            <p>
                                This request is assigned to
                                <strong>
                                    <?= facilityH($m['vendor_name'] ?? 'External Vendor') ?>
                                </strong>.
                                A Facility Officer can close the ticket once the vendor
                                has completed the work.
                            </p>
                        </div>
                    </div>

                    <div class="mv-approval-summary">

                        <div>
                            <span>Vendor</span>
                            <strong>
                                <?= facilityH($m['vendor_name'] ?? '—') ?>
                            </strong>
                        </div>

                        <div>
                            <span>Total Cost</span>
                            <strong>
                                ₦<?= number_format($total, 2) ?>
                            </strong>
                        </div>

                        <div>
                            <span>Current Status</span>
                            <strong>
                                <?= facilityH($m['status']) ?>
                            </strong>
                        </div>

                    </div>

                    <form method="POST" class="mv-stack-form">
                        <input type="hidden" name="csrf_token" value="<?= facilityH(facilityCsrfToken()) ?>">
                        <div class="mv-field">
                            <label>Completion / Closure Notes</label>
                            <textarea name="vendor_closure_notes" rows="3"
                                placeholder="Describe the work completed by the vendor..."></textarea>
                        </div>

                        <div class="mv-form-actions">
                            <button type="submit" name="action" value="close_vendor" class="mv-btn mv-btn-success"
                                onclick="return confirm('Confirm that the external vendor has completed this job and close the ticket?');">
                                <i class="fa-solid fa-circle-check"></i>
                                Complete & Close Ticket
                            </button>
                        </div>
                    </form>

                </section>

            <?php endif; ?>
            <?php if ($techProfile && $m['status'] === 'In Progress'): ?>
                <section class="mv-panel mv-action-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Engineer Work Log</h3>
                            <p>Record diagnosis, work performed and recommendations.</p>
                        </div>
                    </div>
                    <form method="POST" class="mv-stack-form"><input type="hidden" name="csrf_token"
                            value="<?= facilityH(facilityCsrfToken()) ?>">
                        <div class="mv-field"><label>Diagnosis</label><textarea name="diagnosis" rows="2"
                                placeholder="Describe the diagnosed fault..."></textarea></div>
                        <div class="mv-field"><label>Work Performed <span>*</span></label><textarea name="work_performed"
                                rows="3" required
                                placeholder="Describe the repair or maintenance carried out..."></textarea></div>
                        <div class="mv-field"><label>Recommendation</label><textarea name="recommendation" rows="2"
                                placeholder="Any recommendation or follow-up..."></textarea></div>
                        <div class="mv-form-actions"><button name="action" value="add_work" class="mv-btn mv-btn-primary"><i
                                    class="fa-solid fa-plus"></i>Add Work Log</button></div>
                    </form>
                </section><?php endif; ?>
            <?php if ($work && mysqli_num_rows($work)): ?>
                <section class="mv-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Diagnosis & Work Performed</h3>
                            <p>Engineer repair history.</p>
                        </div>
                    </div>
                    <div class="mv-work-log-list"><?php while ($w = mysqli_fetch_assoc($work)): ?>
                            <article class="mv-work-log">
                                <div class="mv-log-head">
                                    <strong><?= facilityH($w['fullname'] ?? 'Technician') ?></strong><span><?= facilityH(timeAgo($w['created_at'])) ?></span>
                                </div><?php if ($w['diagnosis']): ?>
                                    <div class="mv-log-block"><span>Diagnosis</span>
                                        <p><?= nl2br(facilityH($w['diagnosis'])) ?></p>
                                    </div><?php endif; ?>
                                <div class="mv-log-block"><span>Work Performed</span>
                                    <p><?= nl2br(facilityH($w['work_performed'])) ?></p>
                                </div><?php if ($w['recommendation']): ?>
                                    <div class="mv-log-block"><span>Recommendation</span>
                                        <p><?= nl2br(facilityH($w['recommendation'])) ?></p>
                                    </div><?php endif; ?>
                            </article><?php endwhile; ?>
                    </div>
                </section><?php endif; ?>
            <?php if (($isTech || $canOfficer) && !in_array($m['status'], ['Closed', 'Cancelled', 'Rejected'], true)): ?>
                <div class="mv-two-col">
                    <section class="mv-panel">
                        <div class="mv-panel-header">
                            <div>
                                <h3>Add Spare Part</h3>
                                <p>Record replacement parts used.</p>
                            </div>
                        </div>
                        <form method="POST" class="mv-stack-form"><input type="hidden" name="csrf_token"
                                value="<?= facilityH(facilityCsrfToken()) ?>">
                            <div class="mv-field"><label>Part Name <span>*</span></label><input name="part_name" required
                                    placeholder="Part name"></div>
                            <div class="mv-field"><label>Part Number</label><input name="part_number"
                                    placeholder="Part number"></div>
                            <div class="mv-form-grid">
                                <div class="mv-field"><label>Quantity</label><input type="number" step="0.01" min="0.01"
                                        name="quantity" value="1"></div>
                                <div class="mv-field"><label>Unit Cost</label><input type="number" step="0.01" min="0"
                                        name="unit_cost" placeholder="0.00"></div>
                            </div>
                            <div class="mv-form-actions"><button name="action" value="add_part"
                                    class="mv-btn mv-btn-light">Record Part</button></div>
                        </form>
                    </section>
                    <section class="mv-panel">
                        <div class="mv-panel-header">
                            <div>
                                <h3>Add Other Cost</h3>
                                <p>Record labour, vendor, transport or other costs.</p>
                            </div>
                        </div>
                        <form method="POST" class="mv-stack-form"><input type="hidden" name="csrf_token"
                                value="<?= facilityH(facilityCsrfToken()) ?>">
                            <div class="mv-field"><label>Cost Type</label><select name="cost_type">
                                    <option>Labour</option>
                                    <option>Vendor</option>
                                    <option>Transportation</option>
                                    <option>Other</option>
                                </select></div>
                            <div class="mv-field"><label>Description</label><input name="description"
                                    placeholder="Description"></div>
                            <div class="mv-field"><label>Amount <span>*</span></label><input type="number" step="0.01"
                                    min="0" name="amount" placeholder="0.00" required></div>
                            <div class="mv-form-actions"><button name="action" value="add_cost"
                                    class="mv-btn mv-btn-light">Record Cost</button></div>
                        </form>
                    </section>
                </div><?php endif; ?>
            <div class="mv-two-col">
                <section class="mv-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Parts Used</h3>
                            <p>Replacement parts recorded.</p>
                        </div>
                    </div>
                    <div class="mv-detail-list">
                        <?php if ($parts && mysqli_num_rows($parts)):
                            while ($p = mysqli_fetch_assoc($parts)): ?>
                                <div class="mv-detail-row"><span><?= facilityH($p['part_name']) ?> ×
                                        <?= facilityH($p['quantity']) ?></span><strong>₦<?= number_format((float) $p['total_cost'], 2) ?></strong>
                                </div><?php endwhile; else: ?>
                            <div class="mv-empty-inline">No parts recorded.</div><?php endif; ?>
                    </div>
                </section>
                <section class="mv-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Maintenance Costs</h3>
                            <p>All recorded maintenance costs.</p>
                        </div>
                    </div>
                    <div class="mv-detail-list">
                        <?php if ($costs && mysqli_num_rows($costs)):
                            while ($c = mysqli_fetch_assoc($costs)): ?>
                                <div class="mv-detail-row">
                                    <span><?= facilityH($c['cost_type'] . ($c['description'] ? ' — ' . $c['description'] : '')) ?></span><strong>₦<?= number_format((float) $c['amount'], 2) ?></strong>
                                </div><?php endwhile; endif; ?>
                        <div class="mv-detail-row mv-total-row">
                            <span>Total</span><strong>₦<?= number_format($total, 2) ?></strong>
                        </div>
                    </div>
                </section>
            </div>
            <?php if ($canVerify && $m['status'] === 'Awaiting Verification'): ?>
                <section class="mv-panel mv-decision-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Facility Officer Verification</h3>
                            <p>Confirm whether the repair is satisfactory.</p>
                        </div>
                    </div>
                    <form method="POST" class="mv-stack-form"><input type="hidden" name="csrf_token"
                            value="<?= facilityH(facilityCsrfToken()) ?>">
                        <div class="mv-field"><label>Verification Result</label><select name="verification_status">
                                <option value="passed">Working satisfactorily — Pass</option>
                                <option value="failed">Repair unsuccessful — Fail</option>
                                <option value="further_work">Further work required</option>
                            </select></div>
                        <div class="mv-field"><label>Verification Notes</label><textarea name="verification_notes" rows="3"
                                placeholder="Verification notes"></textarea></div>
                        <div class="mv-form-actions"><button name="action" value="verify"
                                class="mv-btn mv-btn-success">Submit Verification</button></div>
                    </form>
                </section><?php endif; ?>
            <?php if ($canApprove && $m['status'] === 'Awaiting Approval'): ?>
                <section class="mv-panel mv-decision-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Project Manager Closure Approval</h3>
                            <p>Review verified work before final closure.</p>
                        </div>
                    </div>
                    <div class="mv-approval-summary">
                        <div><span>Verified By</span><strong><?= facilityH($m['verifier_name'] ?? '—') ?></strong></div>
                        <div><span>Total Cost</span><strong>₦<?= number_format($total, 2) ?></strong></div>
                        <div><span>Downtime</span><strong><?= facilityH($downtime) ?></strong></div>
                    </div>
                    <form method="POST" class="mv-stack-form"><input type="hidden" name="csrf_token"
                            value="<?= facilityH(facilityCsrfToken()) ?>">
                        <div class="mv-field"><label>Approval Notes</label><textarea name="approval_notes" rows="2"
                                placeholder="Approval notes"></textarea></div>
                        <div class="mv-form-actions mv-form-actions-split"><button name="action" value="return"
                                class="mv-btn mv-btn-light">Return for Further Work</button><button name="action"
                                value="approve" class="mv-btn mv-btn-success">Approve & Close</button></div>
                    </form>
                </section><?php endif; ?>
            <section class="mv-panel">
                <div class="mv-panel-header">
                    <div>
                        <h3>Conversation</h3>
                        <p>Public replies and Facility internal notes.</p>
                    </div>
                </div>
                <div class="mv-conversation">
                    <?php if ($comments && mysqli_num_rows($comments)):
                        while ($c = mysqli_fetch_assoc($comments)): ?>
                            <article class="mv-comment <?= $c['is_internal'] ? 'internal' : '' ?>">
                                <div class="mv-comment-head"><strong><?= facilityH($c['fullname'] ?? 'User') ?></strong>
                                    <div class="mv-comment-meta"><?php if ($c['is_internal']): ?><span
                                                class="mv-internal-badge">Facility
                                                Internal</span><?php endif; ?><span><?= facilityH(timeAgo($c['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="mv-comment-text"><?= nl2br(facilityH($c['comment'])) ?></div>
                            </article><?php endwhile; else: ?>
                        <div class="mv-empty-inline">No replies yet.</div><?php endif; ?>
                </div>
                <form method="POST" class="mv-reply-form"><input type="hidden" name="csrf_token"
                        value="<?= facilityH(facilityCsrfToken()) ?>">
                    <div class="mv-field"><label>Reply</label><textarea name="comment" rows="3" required
                            placeholder="Write a reply..."></textarea></div>
                    <div class="mv-reply-footer"><?php if ($canInternal): ?><label class="mv-internal-toggle"><input
                                    type="checkbox" name="is_internal" value="1"><span>Facility
                                    Internal</span></label><?php endif; ?><button name="action" value="comment"
                            class="mv-btn mv-btn-primary">Send Reply</button></div>
                </form>
            </section>
        </div>
        <aside class="mv-side-col">
            <section class="mv-panel mv-side-panel">
                <div class="mv-panel-header">
                    <div>
                        <h3>Request Details</h3>
                        <p>Operational summary.</p>
                    </div>
                </div>
                <div class="mv-detail-list">
                    <div class="mv-detail-row">
                        <span>Request</span><strong><?= facilityH($m['request_number']) ?></strong>
                    </div>
                    <div class="mv-detail-row"><span>Status</span><strong><?= facilityH($m['status']) ?></strong></div>
                    <div class="mv-detail-row"><span>Priority</span><strong><?= facilityH($m['priority']) ?></strong>
                    </div>
                    <div class="mv-detail-row">
                        <span>Outlet</span><strong><?= facilityH($m['warehouse_name']) ?></strong>
                    </div>
                    <div class="mv-detail-row">
                        <span>Engineer</span><strong><?= facilityH($m['technician_name'] ?? 'Unassigned') ?></strong>
                    </div>
                    <div class="mv-detail-row">
                        <span>Vendor</span><strong><?= facilityH($m['vendor_name'] ?? '—') ?></strong>
                    </div>
                    <div class="mv-detail-row"><span>Response</span><strong><?= facilityH($response) ?></strong></div>
                    <div class="mv-detail-row"><span>Downtime</span><strong><?= facilityH($downtime) ?></strong></div>
                </div>
            </section>
            <?php if ($m['asset_id']): ?>
                <section class="mv-panel mv-side-panel">
                    <div class="mv-panel-header">
                        <div>
                            <h3>Asset</h3>
                            <p>Equipment linked to this request.</p>
                        </div>
                    </div>
                    <div class="mv-asset-badge"><span>Asset Tag /
                            ID</span><strong><?= facilityH($m['asset_code']) ?></strong></div>
                    <div class="mv-detail-list">
                        <div class="mv-detail-row"><span>Name</span><strong><?= facilityH($m['asset_name']) ?></strong>
                        </div>
                        <div class="mv-detail-row">
                            <span>Category</span><strong><?= facilityH($m['asset_category'] ?? '—') ?></strong>
                        </div>
                        <div class="mv-detail-row"><span>Brand</span><strong><?= facilityH($m['brand'] ?? '—') ?></strong>
                        </div>
                        <div class="mv-detail-row"><span>Model</span><strong><?= facilityH($m['model'] ?? '—') ?></strong>
                        </div>
                        <div class="mv-detail-row"><span>Serial
                                Number</span><strong><?= facilityH($m['serial_number'] ?? '—') ?></strong></div>
                    </div><a class="mv-btn mv-btn-light mv-full-btn"
                        href="facility_asset_view.php?id=<?= (int) $m['asset_id'] ?>">View Asset History</a>
                </section><?php endif; ?>
            <section class="mv-panel mv-side-panel">
                <div class="mv-panel-header">
                    <div>
                        <h3>Activity Log</h3>
                        <p>Request audit trail.</p>
                    </div>
                </div>
                <div class="mv-activity-list">
                    <?php if ($activity && mysqli_num_rows($activity)):
                        while ($x = mysqli_fetch_assoc($activity)): ?>
                            <div class="mv-activity-item"><span class="mv-activity-dot"></span>
                                <div>
                                    <div class="mv-activity-text"><?= facilityH($x['action']) ?> <span>by
                                            <?= facilityH($x['fullname'] ?? 'System') ?></span></div>
                                    <?php if ($x['description']): ?>
                                        <div class="mv-activity-description"><?= facilityH($x['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="mv-activity-time"><?= facilityH(timeAgo($x['created_at'])) ?></div>
                                </div>
                            </div><?php endwhile; else: ?>
                        <div class="mv-empty-inline">No activity.</div><?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const assignmentType =
            document.getElementById('assignmentType');
        const staffField = document.getElementById('staffField');
        const vendorField = document.getElementById('vendorField');
        const techSelect = document.getElementById('techSelect');
        const vendorSelect = document.getElementById('vendorSelect');

        function syncAssignmentFields() {

            if (!assignmentType) {
                return;
            }

            const isVendor = assignmentType.value === 'vendor';
            if (staffField) {
                staffField.style.display = isVendor ? 'none' : '';
            }
            if (vendorField) {
                vendorField.style.display = isVendor ? '' : 'none';
            }
            if (techSelect) {
                techSelect.disabled =
                    isVendor;
            }

            if (vendorSelect) {
                vendorSelect.disabled = !isVendor;
            }
        }
        assignmentType?.addEventListener('change', syncAssignmentFields);

        syncAssignmentFields();
        document.querySelectorAll('.mv-page form').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                if (f.dataset.submitting === '1') {
                    e.preventDefault();
                    return;
                }
                const b = e.submitter;
                if (
                    b &&
                    b.name &&
                    b.value
                ) {

                    const hiddenAction = document.createElement('input');
                    hiddenAction.type = 'hidden';
                    hiddenAction.name = b.name;
                    hiddenAction.value = b.value;
                    f.appendChild(hiddenAction);
                }
                f.dataset.submitting = '1';
                if (b) {
                    b.disabled = true;
                    const originalText = b.textContent.trim();
                    b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (originalText ||
                        'Submitting...');
                }

            });

        });
    });
</script>
<?php include '../inc/footer.php'; ?>