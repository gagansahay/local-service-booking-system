<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : includes/booking-actions.php
 *  MODULE  : 5 -- Booking Management
 *  PURPOSE : The single handler for every booking status transition.
 *
 *  Both the professional's screens and the administrator's screens post
 *  status changes. Putting the logic here rather than duplicating it
 *  means the workflow rules, the audit trail, the notifications and the
 *  derived-column updates can never fall out of step between screens.
 *
 *  USAGE: include this file at the top of a page, AFTER its role guard.
 *         It handles the POST and redirects; otherwise it does nothing.
 * =====================================================================
 */

require_once __DIR__ . '/auth.php';

/**
 * Apply a status transition to a booking.
 *
 * @param  PDO      $pdo
 * @param  int      $bookingId
 * @param  string   $newStatus
 * @param  string   $role         'provider' or 'admin'
 * @param  int|null $providerId   Restricts a provider to their own jobs
 * @param  string   $remarks
 * @param  float|null $finalCost  Required when completing a job
 * @return array{ok:bool, message:string}
 */
function apply_booking_transition(
    PDO $pdo,
    int $bookingId,
    string $newStatus,
    string $role,
    ?int $providerId,
    string $remarks = '',
    ?float $finalCost = null
): array {
    /* ---- Load the booking, scoped to the caller -------------------- */
    $sql = 'SELECT b.*, u.user_id AS customer_user_id, u.full_name AS customer_name,
                   pu.full_name AS provider_name, c.category_name
              FROM bookings   b
              JOIN users      u  ON u.user_id     = b.user_id
              JOIN providers  p  ON p.provider_id = b.provider_id
              JOIN users      pu ON pu.user_id    = p.user_id
              JOIN categories c  ON c.category_id = p.category_id
             WHERE b.booking_id = :bid';

    $params = [':bid' => $bookingId];

    // A professional may only touch their own jobs. This is enforced in
    // the WHERE clause, so a forged booking_id simply matches no row.
    if ($role === 'provider') {
        $sql .= ' AND b.provider_id = :pid';
        $params[':pid'] = $providerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $booking = $stmt->fetch();

    if (!$booking) {
        return ['ok' => false, 'message' => 'That booking was not found.'];
    }

    $oldStatus = $booking['status'];

    /* ---- Is this transition allowed for this role? ----------------- */
    if (!can_transition($role, $oldStatus, $newStatus)) {
        return [
            'ok'      => false,
            'message' => 'A booking that is ' . str_replace('_', ' ', $oldStatus)
                       . ' cannot be moved to ' . str_replace('_', ' ', $newStatus) . '.',
        ];
    }

    /* ---- Completing a job needs a final amount --------------------- */
    if ($newStatus === 'completed') {
        if ($finalCost === null || $finalCost < 0) {
            return ['ok' => false, 'message' => 'Enter the final amount charged before marking the job complete.'];
        }
    }

    /* ---- Apply it, all or nothing ---------------------------------- */
    try {
        $pdo->beginTransaction();

        if ($newStatus === 'completed') {
            $stmt = $pdo->prepare(
                'UPDATE bookings SET status = :status, final_cost = :cost WHERE booking_id = :bid'
            );
            $stmt->execute([':status' => $newStatus, ':cost' => $finalCost, ':bid' => $bookingId]);

            // Settle the invoice at the amount actually charged.
            $stmt = $pdo->prepare(
                'UPDATE payments SET amount = :amount WHERE booking_id = :bid'
            );
            $stmt->execute([':amount' => $finalCost, ':bid' => $bookingId]);

        } elseif (in_array($newStatus, ['cancelled', 'rejected'], true)) {
            $stmt = $pdo->prepare(
                'UPDATE bookings SET status = :status, cancellation_reason = :reason WHERE booking_id = :bid'
            );
            $stmt->execute([
                ':status' => $newStatus,
                ':reason' => $remarks !== '' ? $remarks : 'No reason given.',
                ':bid'    => $bookingId,
            ]);

            // A job that is not happening should not carry a live invoice.
            $stmt = $pdo->prepare(
                "UPDATE payments SET payment_status = 'refunded'
                  WHERE booking_id = :bid AND payment_status = 'pending'"
            );
            $stmt->execute([':bid' => $bookingId]);

        } else {
            $stmt = $pdo->prepare('UPDATE bookings SET status = :status WHERE booking_id = :bid');
            $stmt->execute([':status' => $newStatus, ':bid' => $bookingId]);
        }

        /* ---- Audit trail ------------------------------------------- */
        record_status_change($pdo, $bookingId, $oldStatus, $newStatus, current_user_id(),
                             $remarks !== '' ? $remarks : null);

        /* ---- If this was an AMC visit, advance the contract --------- */
        if ($newStatus === 'completed' && (int) $booking['is_maintenance'] === 1) {
            $stmt = $pdo->prepare(
                "UPDATE maintenance_visits
                    SET status = 'completed', completed_date = CURDATE(),
                        technician_remarks = :remarks
                  WHERE booking_id = :bid"
            );
            $stmt->execute([
                ':remarks' => $remarks !== '' ? $remarks : 'Scheduled maintenance visit completed.',
                ':bid'     => $bookingId,
            ]);

            $stmt = $pdo->prepare('SELECT contract_id FROM maintenance_visits WHERE booking_id = :bid');
            $stmt->execute([':bid' => $bookingId]);

            if ($contractId = $stmt->fetchColumn()) {
                advance_contract($pdo, (int) $contractId);
            }
        }

        /* ---- Keep the professional's derived counters honest -------- */
        if ($newStatus === 'completed') {
            recalculate_provider_jobs($pdo, (int) $booking['provider_id']);
        }

        /* ---- Tell the customer what happened ------------------------ */
        $messages = [
            'confirmed'   => ['Booking confirmed',   'accepted your request for '],
            'in_progress' => ['Work has started',    'has started work on '],
            'completed'   => ['Job completed',       'marked the job complete for '],
            'rejected'    => ['Request declined',    'could not take on '],
            'cancelled'   => ['Booking cancelled',   'cancelled '],
        ];

        if (isset($messages[$newStatus])) {
            [$title, $verb] = $messages[$newStatus];
            notify(
                $pdo,
                (int) $booking['customer_user_id'],
                $title,
                $booking['provider_name'] . ' ' . $verb . $booking['booking_code'] . '.',
                'customer/my-bookings.php',
                'bell'
            );
        }

        $pdo->commit();

        log_activity('booking_status_change', 'bookings', $bookingId,
                     $oldStatus . ' -> ' . $newStatus);

        $labels = [
            'confirmed'   => 'accepted',
            'in_progress' => 'marked as in progress',
            'completed'   => 'marked complete',
            'rejected'    => 'declined',
            'cancelled'   => 'cancelled',
        ];

        return [
            'ok'      => true,
            'message' => 'Booking ' . $booking['booking_code'] . ' '
                       . ($labels[$newStatus] ?? 'updated') . '.',
        ];

    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok'      => false,
            'message' => APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'The booking could not be updated. Please try again.',
        ];
    }
}


/* =====================================================================
 * POST HANDLER
 * ---------------------------------------------------------------------
 * Runs automatically when this file is included on a page that receives
 * a status-change post.
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'set_status') {

    csrf_guard();

    $role = current_role();
    if (!in_array($role, ['provider', 'admin'], true)) {
        flash('error', 'You do not have permission to change a booking status.');
        redirect(home_for_role($role));
    }

    $finalCostRaw = post('final_cost');

    $result = apply_booking_transition(
        db(),
        post_int('booking_id'),
        post('new_status'),
        $role,
        $role === 'provider' ? current_provider_id() : null,
        post('remarks'),
        $finalCostRaw !== '' && is_numeric($finalCostRaw) ? (float) $finalCostRaw : null
    );

    flash($result['ok'] ? 'success' : 'error', $result['message']);

    // Return to the page that posted, preserving its filters.
    $back = post('return_to');
    redirect($back !== '' ? $back : home_for_role($role));
}
