<?php
require '../inc/auth.php';
require '../inc/connection.php';
require '../inc/ticket_functions.php';
require '../inc/it_assign.php';

$pageTitle = "Auto-assign Rotation";

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (($_SESSION['role'] ?? 'staff') !== 'admin') {
    header("Location: it_tickets.php");
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function flash($type, $text)
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_text'] = $text;
}

/* =========================
   ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('error', 'Your session expired. Please try again.');
        header("Location: it_rotation.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_officer') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $st = $connection->prepare("INSERT IGNORE INTO it_officers (user_id) VALUES (?)");
        $st->bind_param('i', $uid);
        $st->execute();
        flash('success', 'Officer added to the rotation.');
    } elseif ($action === 'set_status') {
        $status = $_POST['status'] ?? '';
        $oid = (int) ($_POST['officer_id'] ?? 0);
        if (in_array($status, ['active', 'on_leave', 'inactive'], true)) {
            $st = $connection->prepare("UPDATE it_officers SET status = ? WHERE id = ?");
            $st->bind_param('si', $status, $oid);
            $st->execute();
            flash('success', 'Officer status updated.');
        }
    } elseif ($action === 'set_max') {
        $oid = (int) ($_POST['officer_id'] ?? 0);
        $max = (int) ($_POST['max_open'] ?? 0); // 0 = no limit
        $st = $connection->prepare("UPDATE it_officers SET max_open = NULLIF(?, 0) WHERE id = ?");
        $st->bind_param('ii', $max, $oid);
        $st->execute();
        flash('success', 'Open-ticket limit updated.');
    } elseif ($action === 'run_now') {
        $n = autoAssignBacklog($connection, $userId);
        flash($n > 0 ? 'success' : 'error', $n > 0
            ? "$n waiting ticket(s) assigned."
            : 'Nothing was assigned. Either no tickets are waiting or no officer is available.');
    }

    header("Location: it_rotation.php");
    exit;
}

/* =========================
   DATA
========================= */
$officers = $connection->query(
    "SELECT o.id, o.user_id, o.status, o.max_open, o.last_assigned_at, o.total_auto_assigned, u.fullname,
            (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = o.user_id
               AND t.status NOT IN ('Resolved','Closed','Cancelled')) AS open_count
     FROM it_officers o JOIN users u ON u.id = o.user_id
     ORDER BY u.fullname"
)->fetch_all(MYSQLI_ASSOC);

$eligible = itEligibleOfficers($connection);
$nextId = $eligible ? (int) $eligible[0]['id'] : 0;

$waiting = (int) $connection->query("SELECT COUNT(*) AS c FROM tickets WHERE assigned_to IS NULL AND status IN ('New','Open')")->fetch_assoc()['c'];

$candidates = $connection->query(
    "SELECT id, fullname FROM users
     WHERE status = 'active' AND id NOT IN (SELECT user_id FROM it_officers)
     ORDER BY fullname"
)->fetch_all(MYSQLI_ASSOC);

include '../inc/header.php';
?>
<link rel="stylesheet" href="../assets/css/reports.css">
<link rel="stylesheet" href="../assets/css/it-reports.css?v=1.0">

<?php if (!empty($_SESSION['flash_text'])): ?>
    <div class="message <?= h($_SESSION['flash_type']) ?>"><?= h($_SESSION['flash_text']) ?></div>
    <?php unset($_SESSION['flash_text'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="panel rpt-panel">
    <h2 class="page-heading">Auto-assign rotation</h2>
    <p class="page-subheading">
        New tickets go to the active officer who was assigned a ticket longest ago. Officers on leave or at their
        open-ticket limit are skipped.
    </p>
    <form method="POST" class="inline-form rpt-rotation-actions">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="run_now">
        <button type="submit" class="btn btn-primary small-btn" <?= $waiting ? '' : 'disabled' ?>>
            Assign <?= $waiting ?> waiting ticket<?= $waiting === 1 ? '' : 's' ?> now
        </button>
        <a class="btn btn-light small-btn" href="it_reports.php">Back to weekly report</a>
    </form>
</div>

<div class="panel rpt-panel">
    <div class="form-section-title">Officers</div>
    <div class="table-wrap">
        <table class="report-print-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Status</th>
                    <th>Open tickets</th>
                    <th>Max open</th>
                    <th>Last assigned</th>
                    <th>Auto-assigned (total)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($officers): ?>
                    <?php foreach ($officers as $o): ?>
                        <tr>
                            <td>
                                <strong><?= h($o['fullname']) ?></strong>
                                <?php if ((int) $o['id'] === $nextId): ?><span class="rpt-next">Next in line</span><?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="officer_id" value="<?= (int) $o['id'] ?>">
                                    <select name="status" class="form-control" onchange="this.form.submit()">
                                        <?php foreach (['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Not in rotation'] as $k => $label): ?>
                                            <option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><?= (int) $o['open_count'] ?></td>
                            <td>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="set_max">
                                    <input type="hidden" name="officer_id" value="<?= (int) $o['id'] ?>">
                                    <input type="number" min="0" name="max_open" class="form-control" style="width:80px"
                                        value="<?= (int) $o['max_open'] ?>" placeholder="none" title="0 = no limit">
                                    <button type="submit" class="btn btn-light small-btn">Save</button>
                                </form>
                            </td>
                            <td><?= $o['last_assigned_at'] ? h(date('M d, g:i A', strtotime($o['last_assigned_at']))) : 'Never' ?></td>
                            <td><?= (int) $o['total_auto_assigned'] ?></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="empty-row">No officers yet. Add one below.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($candidates): ?>
        <form method="POST" class="inline-form rpt-rotation-add">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="add_officer">
            <label class="toggle-label">Add officer</label>
            <select name="user_id" class="form-control" required>
                <option value="">Choose a user...</option>
                <?php foreach ($candidates as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= h($c['fullname']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary small-btn">Add to rotation</button>
        </form>
    <?php endif; ?>
</div>

<?php include '../inc/footer.php'; ?>