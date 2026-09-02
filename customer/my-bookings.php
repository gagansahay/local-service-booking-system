<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/my-bookings.php
 *  MODULE  : 4 -- Customer  (with Module 5, Booking Management)
 *  PURPOSE : Every booking the signed-in customer has raised, filtered
 *            by status, with the full audit trail and a cancel action.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();

/* =====================================================================
 * CANCELLATION
 * ---------------------------------------------------------------------
 * Ownership is proved by including user_id in the WHERE clause, not by
 * trusting the booking_id in the request. Posting somebody else's
 * booking_id therefore updates nothing.
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'cancel') {

    csrf_guard();

    $bookingId = post_int('booking_id');
    $reason    = post('cancellation_reason');

    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.booking_code, b.status, b.provider_id, u.user_id AS provider_user_id
           FROM bookings b
           JOIN providers p ON p.provider_id = b.provider_id
           JOIN users     u ON u.user_id     = p.user_id
          WHERE b.booking_id = :bid AND b.user_id = :uid'
    );
    $stmt->execute([':bid' => $bookingId, ':uid' => $userId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        flash('error', 'That booking was not found.');

    } elseif (!can_transition('customer', $booking['status'], 'cancelled')) {
        // The permitted transitions live in one table in functions.php,
        // so this rule cannot drift between screens.
        flash('error', 'A booking that is ' . str_replace('_', ' ', $booking['status'])
                     . ' can no longer be cancelled. Please call the professional directly.');

    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE bookings
                    SET status = 'cancelled', cancellation_reason = :reason
                  WHERE booking_id = :bid AND user_id = :uid"
            );
            $stmt->execute([
                ':reason' => $reason !== '' ? $reason : 'Cancelled by customer.',
                ':bid'    => $bookingId,
                ':uid'    => $userId,
            ]);

            record_status_change($pdo, $bookingId, $booking['status'], 'cancelled', $userId,
                                 $reason !== '' ? $reason : 'Cancelled by customer.');

            // Any pending invoice for a cancelled job is void.
            $stmt = $pdo->prepare(
                "UPDATE payments SET payment_status = 'refunded'
                  WHERE booking_id = :bid AND payment_status = 'pending'"
            );
            $stmt->execute([':bid' => $bookingId]);

            notify(
                $pdo,
                (int) $booking['provider_user_id'],
                'Booking cancelled',
                current_name() . ' cancelled ' . $booking['booking_code'] . '.',
                'provider/jobs.php',
                'x'
            );

            $pdo->commit();

            log_activity('booking_cancelled', 'bookings', $bookingId, $booking['booking_code']);
            flash('success', 'Booking ' . $booking['booking_code'] . ' has been cancelled.');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'The booking could not be cancelled. Please try again.');
        }
    }

    redirect('customer/my-bookings.php');
}

/* =====================================================================
 * LISTING
 * ===================================================================*/
$filter = get('status', 'all');

$tabs = [
    'all'         => 'All',
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
];
if (!isset($tabs[$filter])) {
    $filter = 'all';
}

/* Counts per tab, so each badge shows a real number. */
$stmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS n FROM bookings WHERE user_id = :uid GROUP BY status'
);
$stmt->execute([':uid' => $userId]);

$counts = ['all' => 0];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
    $counts['all'] += (int) $row['n'];
}

/* The page of bookings itself. */
$where  = ['b.user_id = :uid'];
$params = [':uid' => $userId];

if ($filter !== 'all') {
    // 'cancelled' groups rejected jobs too -- from the customer's point
    // of view the outcome is the same: the job is not happening.
    if ($filter === 'cancelled') {
        $where[] = "b.status IN ('cancelled','rejected')";
    } else {
        $where[] = 'b.status = :status';
        $params[':status'] = $filter;
    }
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$page       = max(1, get_int('page', 1));
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM bookings b $whereSql");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalRows / RECORDS_PER_PAGE);
$page       = $totalPages > 0 ? min($page, $totalPages) : 1;
$offset     = ($page - 1) * RECORDS_PER_PAGE;

$sql = "SELECT b.*, u.full_name AS provider_name, u.phone AS provider_phone,
               c.category_name, s.service_name,
               pay.payment_status, pay.invoice_no,
               f.feedback_id
          FROM bookings   b
          JOIN providers  p   ON p.provider_id = b.provider_id
          JOIN users      u   ON u.user_id     = p.user_id
          JOIN categories c   ON c.category_id = p.category_id
          LEFT JOIN services s ON s.service_id = b.service_id
          LEFT JOIN payments pay ON pay.booking_id = b.booking_id
          LEFT JOIN feedback f   ON f.booking_id   = b.booking_id
          $whereSql
         ORDER BY b.booking_date DESC, b.booking_time DESC
         LIMIT " . (int) RECORDS_PER_PAGE . " OFFSET " . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

/* Audit trail for each booking on this page, fetched in one query
   rather than one per booking (avoids the N+1 query problem). */
$history = [];
if ($bookings) {
    $ids          = array_column($bookings, 'booking_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT h.booking_id, h.old_status, h.new_status, h.remarks, h.changed_at, u.full_name
           FROM booking_status_history h
           LEFT JOIN users u ON u.user_id = h.changed_by
          WHERE h.booking_id IN ($placeholders)
          ORDER BY h.changed_at ASC, h.history_id ASC"
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $row) {
        $history[(int) $row['booking_id']][] = $row;
    }
}

$pageTitle   = 'My bookings';
$pageHeading = 'My bookings';
$pageLede    = 'Track every job you have raised, from request to completion.';
$pageActions = '<a class="btn btn--accent" href="' . e(BASE_URL) . 'customer/search.php">Book a service</a>';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== STATUS TABS ============================== -->
<div class="btn-row u-mb-5">
    <?php foreach ($tabs as $key => $label): ?>
        <?php
        $n = $key === 'cancelled'
            ? (($counts['cancelled'] ?? 0) + ($counts['rejected'] ?? 0))
            : ($counts[$key] ?? 0);
        ?>
        <a class="btn <?= $filter === $key ? 'btn--primary' : 'btn--outline' ?> btn--sm"
           href="my-bookings.php?status=<?= e($key) ?>">
            <?= e($label) ?>
            <?php if ($n > 0): ?><span class="ref" style="background:none;padding:0 0 0 4px"><?= $n ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==================== BOOKINGS ================================= -->
<?php if (!$bookings): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128203;</div>
            <h3>Nothing here yet</h3>
            <p>
                <?= $filter === 'all'
                    ? 'You have not booked a service yet. Find a verified professional and raise your first request.'
                    : 'No bookings have this status right now.' ?>
            </p>
            <a class="btn btn--accent" href="search.php">Find a professional</a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($bookings as $b): ?>
        <?php $canCancel = can_transition('customer', $b['status'], 'cancelled'); ?>

        <article class="jobcard" id="b<?= (int) $b['booking_id'] ?>">
            <div class="jobcard__stub jobcard__stub--<?= e($b['status']) ?>">
                <span class="jobcard__code"><?= e($b['booking_code']) ?></span>
            </div>

            <div class="jobcard__body">
                <div class="jobcard__top">
                    <div>
                        <h3 class="jobcard__title">
                            <?= e($b['service_name'] ?: $b['category_name'] . ' visit') ?>
                            <?php if ($b['is_maintenance']): ?>
                                <span class="badge badge--scheduled" style="margin-left:var(--sp-2)">AMC</span>
                            <?php endif; ?>
                        </h3>
                        <div class="jobcard__sub">
                            <?= e($b['provider_name']) ?> &middot; <?= e($b['category_name']) ?>
                        </div>
                    </div>
                    <?= status_badge($b['status']) ?>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Date</dt><dd><?= e(show_date($b['booking_date'])) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e(show_time($b['booking_time'])) ?></dd></div>
                    <div><dt>Duration</dt><dd><?= (int) $b['duration_minutes'] ?> min</dd></div>
                    <div>
                        <dt><?= $b['final_cost'] !== null ? 'Final cost' : 'Estimate' ?></dt>
                        <dd><?= e(money($b['final_cost'] ?? $b['estimated_cost'])) ?></dd>
                    </div>
                    <?php if (in_array($b['status'], ['confirmed', 'in_progress'], true)): ?>
                        <div><dt>Contact</dt><dd class="ref"><?= e($b['provider_phone']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($b['payment_status'])): ?>
                        <div><dt>Payment</dt><dd><?= status_badge($b['payment_status']) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if (!empty($b['problem_description'])): ?>
                    <p class="text-small u-m0 u-mb-3">
                        <strong>You wrote:</strong> <?= e($b['problem_description']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($b['cancellation_reason'])): ?>
                    <div class="alert alert--warning u-mb-3">
                        <span class="alert__icon">&#9888;</span>
                        <span class="alert__text"><?= e($b['cancellation_reason']) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Audit trail ------------------------------------- -->
                <?php if (!empty($history[(int) $b['booking_id']])): ?>
                    <details class="u-mb-3">
                        <summary class="text-small" style="cursor:pointer;color:var(--blue-600)">
                            Show status history
                            (<?= count($history[(int) $b['booking_id']]) ?> updates)
                        </summary>
                        <div class="table-wrap u-mt-3">
                            <table class="table">
                                <thead>
                                    <tr><th>When</th><th>Change</th><th>By</th><th>Remarks</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($history[(int) $b['booking_id']] as $h): ?>
                                    <tr>
                                        <td class="text-small"><?= e(show_datetime($h['changed_at'])) ?></td>
                                        <td>
                                            <?php if ($h['old_status']): ?>
                                                <span class="text-muted text-small"><?= e(str_replace('_', ' ', $h['old_status'])) ?> &rarr; </span>
                                            <?php endif; ?>
                                            <?= status_badge($h['new_status']) ?>
                                        </td>
                                        <td class="text-small"><?= e($h['full_name'] ?: 'System') ?></td>
                                        <td class="text-small text-muted"><?= e($h['remarks'] ?: '--') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <div class="jobcard__foot">
                    <span class="text-small text-muted">
                        Raised <?= e(show_datetime($b['created_at'])) ?>
                    </span>

                    <div class="btn-row">
                        <?php if ($b['status'] === 'completed' && empty($b['feedback_id'])): ?>
                            <a class="btn btn--accent btn--sm"
                               href="feedback.php?booking=<?= (int) $b['booking_id'] ?>">Leave a rating</a>
                        <?php endif; ?>

                        <?php if ($b['status'] === 'completed'): ?>
                            <a class="btn btn--outline btn--sm"
                               href="invoices.php?booking=<?= (int) $b['booking_id'] ?>">View invoice</a>
                        <?php endif; ?>

                        <?php if ($canCancel): ?>
                            <form method="post" action="my-bookings.php" class="u-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
                                <button class="btn btn--outline btn--sm" type="submit"
                                        data-confirm="Cancel booking <?= e($b['booking_code']) ?>? This cannot be undone.">
                                    Cancel booking
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?= paginate($page, $totalPages, 'status=' . urlencode($filter)) ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
