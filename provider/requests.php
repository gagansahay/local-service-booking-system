<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/requests.php
 *  MODULE  : 3 -- Service Provider  (with Module 5, Booking Management)
 *  PURPOSE : Incoming booking requests, with accept and decline.
 *
 *  The status change itself is handled by includes/booking-actions.php,
 *  which is shared with the jobs and admin screens so the workflow
 *  rules exist in exactly one place.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

require_once __DIR__ . '/../includes/booking-actions.php';   // handles the POST

$pdo        = db();
$providerId = current_provider_id();

$stmt = $pdo->prepare(
    "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
            s.service_name, s.duration_minutes AS service_duration
       FROM bookings b
       JOIN users    u ON u.user_id    = b.user_id
       LEFT JOIN services s ON s.service_id = b.service_id
      WHERE b.provider_id = :pid AND b.status = 'pending'
      ORDER BY b.booking_date ASC, b.booking_time ASC"
);
$stmt->execute([':pid' => $providerId]);
$requests = $stmt->fetchAll();

$pageTitle   = 'New requests';
$pageHeading = 'New requests';
$pageLede    = count($requests) === 1
    ? '1 customer is waiting for your answer.'
    : count($requests) . ' customers are waiting for your answer.';

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$requests): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#9993;</div>
            <h3>No requests waiting</h3>
            <p>
                You are all caught up. New booking requests will appear here, and you will
                get a notification the moment one arrives.
            </p>
            <a class="btn btn--primary" href="jobs.php">See your accepted jobs</a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($requests as $r): ?>
        <article class="jobcard" id="b<?= (int) $r['booking_id'] ?>">
            <div class="jobcard__stub jobcard__stub--pending">
                <span class="jobcard__code"><?= e($r['booking_code']) ?></span>
            </div>

            <div class="jobcard__body">
                <div class="jobcard__top">
                    <div>
                        <h3 class="jobcard__title">
                            <?= e($r['service_name'] ?: 'General visit') ?>
                            <?php if ($r['is_maintenance']): ?>
                                <span class="badge badge--scheduled" style="margin-left:var(--sp-2)">AMC visit</span>
                            <?php endif; ?>
                        </h3>
                        <div class="jobcard__sub">
                            Requested by <?= e($r['customer_name']) ?>
                            &middot; raised <?= e(show_datetime($r['created_at'])) ?>
                        </div>
                    </div>
                    <?= status_badge('pending') ?>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Date</dt><dd><?= e(show_date($r['booking_date'])) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e(show_time($r['booking_time'])) ?></dd></div>
                    <div><dt>Duration</dt><dd><?= (int) $r['duration_minutes'] ?> min</dd></div>
                    <div><dt>Estimate</dt><dd><?= e(money($r['estimated_cost'])) ?></dd></div>
                    <div><dt>Contact</dt><dd class="ref"><?= e($r['customer_phone']) ?></dd></div>
                </dl>

                <p class="text-small u-m0 u-mb-3">
                    <strong>Address:</strong> <?= e($r['service_address']) ?>
                    <?= $r['city'] ? ', ' . e($r['city']) : '' ?>
                    <?= $r['pincode'] ? ' &ndash; ' . e($r['pincode']) : '' ?>
                </p>

                <?php if (!empty($r['problem_description'])): ?>
                    <p class="text-small u-m0 u-mb-4">
                        <strong>Problem described:</strong> <?= e($r['problem_description']) ?>
                    </p>
                <?php endif; ?>

                <!-- Accept / decline ------------------------------- -->
                <div class="jobcard__foot" style="gap:var(--sp-4);align-items:flex-start">

                    <form method="post" action="requests.php" style="flex:1;min-width:220px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"      value="set_status">
                        <input type="hidden" name="booking_id"  value="<?= (int) $r['booking_id'] ?>">
                        <input type="hidden" name="new_status"  value="rejected">
                        <input type="hidden" name="return_to"   value="provider/requests.php">

                        <label class="label" for="reason<?= (int) $r['booking_id'] ?>">
                            Reason, if declining
                        </label>
                        <div style="display:flex;gap:var(--sp-2)">
                            <input class="input" type="text" name="remarks"
                                   id="reason<?= (int) $r['booking_id'] ?>"
                                   placeholder="Outside my service area">
                            <button class="btn btn--outline" type="submit"
                                    data-confirm="Decline <?= e($r['booking_code']) ?>?">
                                Decline
                            </button>
                        </div>
                    </form>

                    <form method="post" action="requests.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"     value="set_status">
                        <input type="hidden" name="booking_id" value="<?= (int) $r['booking_id'] ?>">
                        <input type="hidden" name="new_status" value="confirmed">
                        <input type="hidden" name="remarks"    value="Accepted by professional.">
                        <input type="hidden" name="return_to"  value="provider/requests.php">

                        <span class="label" style="visibility:hidden">Accept</span>
                        <button class="btn btn--accent btn--lg" type="submit">Accept this job</button>
                    </form>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
