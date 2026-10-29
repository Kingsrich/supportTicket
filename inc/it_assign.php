<?php
/**
 * Round-robin ticket assignment for IT officers.
 *
 * Rule: the active officer who was assigned a ticket longest ago goes next
 * (officers never assigned go first). Officers on leave / inactive, or at
 * their max_open limit, are skipped. Needs the it_officers table.
 */

/** Officers eligible right now, in the order they will receive tickets. */
function itEligibleOfficers(mysqli $db): array
{
    $sql = "SELECT o.id, o.user_id, o.last_assigned_at, o.max_open, u.fullname,
                   (SELECT COUNT(*) FROM tickets t
                     WHERE t.assigned_to = o.user_id
                       AND t.status NOT IN ('Resolved','Closed','Cancelled')) AS open_count
            FROM it_officers o
            JOIN users u ON u.id = o.user_id
            WHERE o.status = 'active' AND u.status = 'active'
            HAVING o.max_open IS NULL OR open_count < o.max_open
            ORDER BY (o.last_assigned_at IS NULL) DESC, o.last_assigned_at ASC, o.id ASC";
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Assign one unassigned ticket to the next officer in the rotation.
 * Returns the officer's user id, or null if the ticket is already assigned,
 * missing, or no officer is available (it then stays unassigned).
 *
 * Call it right after a ticket is inserted:
 *     autoAssignTicket($connection, (int) mysqli_insert_id($connection));
 */
function autoAssignTicket(mysqli $db, int $ticketId, int $actorId = 0): ?int
{
    // One assignment at a time, so two tickets created together can't pick the same officer
    $db->query("SELECT GET_LOCK('it_auto_assign', 5)");
    try {
        $st = $db->prepare("SELECT user_id, assigned_to, ticket_number FROM tickets WHERE id = ?");
        $st->bind_param('i', $ticketId);
        $st->execute();
        $ticket = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$ticket || $ticket['assigned_to'] !== null) {
            return null;
        }

        $officers = itEligibleOfficers($db);
        if (!$officers) {
            return null;
        }
        $pick = $officers[0];
        $officerId = (int) $pick['user_id'];

        $db->begin_transaction();

        $st = $db->prepare(
            "UPDATE tickets
             SET assigned_to = ?, assigned_at = NOW(),
                 status = IF(status IN ('New','Open'), 'Assigned', status)
             WHERE id = ? AND assigned_to IS NULL"
        );
        $st->bind_param('ii', $officerId, $ticketId);
        $st->execute();
        $changed = $st->affected_rows > 0;
        $st->close();
        if (!$changed) {
            $db->rollback();
            return null;
        }

        $st = $db->prepare("UPDATE it_officers SET last_assigned_at = NOW(), total_auto_assigned = total_auto_assigned + 1 WHERE id = ?");
        $st->bind_param('i', $pick['id']);
        $st->execute();
        $st->close();

        $logUser = $actorId > 0 ? $actorId : (int) $ticket['user_id'];
        $action = 'Auto-assigned to ' . $pick['fullname'] . ' (rotation)';
        $st = $db->prepare("INSERT INTO ticket_activity_log (ticket_id, user_id, action) VALUES (?, ?, ?)");
        $st->bind_param('iis', $ticketId, $logUser, $action);
        $st->execute();
        $st->close();

        $msg = 'Ticket ' . $ticket['ticket_number'] . ' was assigned to you';
        $type = 'assigned';
        $st = $db->prepare("INSERT INTO ticket_notifications (user_id, ticket_id, type, message) VALUES (?, ?, ?, ?)");
        $st->bind_param('iiss', $officerId, $ticketId, $type, $msg);
        $st->execute();
        $st->close();

        $db->commit();
        return $officerId;
    } catch (Throwable $e) {
        $db->rollback();
        error_log('autoAssignTicket failed: ' . $e->getMessage());
        return null;
    } finally {
        $db->query("SELECT RELEASE_LOCK('it_auto_assign')");
    }
}

/** Assign every waiting (New/Open, unassigned) ticket, oldest first. Returns how many were assigned. */
function autoAssignBacklog(mysqli $db, int $actorId = 0): int
{
    $res = $db->query("SELECT id FROM tickets WHERE assigned_to IS NULL AND status IN ('New','Open') ORDER BY created_at ASC");
    $count = 0;
    foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $row) {
        if (autoAssignTicket($db, (int) $row['id'], $actorId) === null) {
            break; // nobody available, stop trying
        }
        $count++;
    }
    return $count;
}
