<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/jobs.php
 *  MODULE  : 3 -- Service Provider  (with Module 5, Booking Management)
 *  PURPOSE : Every accepted job, with the controls to move it through
 *            the workflow: start work, then mark complete with the
 *            final amount charged.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

require_once __DIR__ . '/../includes/booking-actions.php';   // handles the POST

$pdo        = db();
$providerId = current_provider_id();

$filter = get('status', 'active');

$tabs = [
    'active'      => 'Active',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
    'all'         => 'All',
];
if (!isset($tabs[$filter])) {
    $filter = 'active';
}

$where  = ['b.provider_id = :pid'];
$params = [':pid' => $providerId];

switch ($filter) {
    case 'active':
        $where[] = "b.status IN ('confirmed','in_progress')";
        break;
    case 'cancelled':
        $where[] = "b.status IN ('cancelled','rejected')";
        break;
    case 'all':
        break;
    default:
        $where[] = 'b.status = :status';
        $params[':status'] = $filter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$window     = page_window($pdo, "SELECT COUNT(*) FROM bookings b $whereSql", $params);
$page       = $window['page'];
$totalPages = $window['total_pages'];
$totalRows  = $window['total_rows'];

$sql = "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone,
               s.service_name, pay.payment_status
          FROM bookings b
          JOIN users    u ON u.user_id    = b.user_id
          LEFT JOIN services s   ON s.service_id  = b.service_id
          LEFT JOIN payments pay ON pay.booking_id = b.booking_id
          $whereSql
         ORDER BY b.booking_date DESC, b.booking_time DESC
         " . $window['limit_sql'];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$pageTitle   = 'My jobs';
$pageHeading = 'My jobs';
$pageLede    = 'Move each job through the workflow as the work progresses.';

include __DIR__ . '/../includes/header.php';
?>

<div class="btn-row u-mb-5">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="btn <?= $filter === $key ? 'btn--primary' : 'btn--outline' ?> btn--sm"
           href="jobs.php?status=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$jobs): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128736;</div>
            <h3>No jobs with this status</h3>
            <p>Accepted bookings appear here so you can start and complete them.</p>
            <a class="btn btn--accent" href="requests.php">Check new requests</a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($jobs as $j): ?>
        <article class="jobcard" id="b<?= (int) $j['booking_id'] ?>">
            <div class="jobcard__stub jobcard__stub--<?= e($j['status']) ?>">
                <span class="jobcard__code"><?= e($j['booking_code']) ?></span>
            </div>

            <div class="jobcard__body">
                <div class="jobcard__top">
                    <div>
                        <h3 class="jobcard__title">
                            <?= e($j['service_name'] ?: 'General visit') ?>
                            <?php if ($j['is_maintenance']): ?>
                                <span class="badge badge--scheduled" style="margin-left:var(--sp-2)">AMC visit</span>
                            <?php endif; ?>
                        </h3>
                        <div class="jobcard__sub">
                            <?= e($j['customer_name']) ?> &middot; <?= e($j['customer_phone']) ?>
                        </div>
                    </div>
                    <?= status_badge($j['status']) ?>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Date</dt><dd><?= e(show_date($j['booking_date'])) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e(show_time($j['booking_time'])) ?></dd></div>
                    <div><dt>Duration</dt><dd><?= (int) $j['duration_minutes'] ?> min</dd></div>
                    <div>
                        <dt><?= $j['final_cost'] !== null ? 'Charged' : 'Estimate' ?></dt>
                        <dd><?= e(money($j['final_cost'] ?? $j['estimated_cost'])) ?></dd>
                    </div>
                    <?php if (!empty($j['payment_status'])): ?>
                        <div><dt>Payment</dt><dd><?= status_badge($j['payment_status']) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <p class="text-small u-m0 u-mb-3">
                    <strong>Address:</strong> <?= e($j['service_address']) ?>
                    <?= $j['city'] ? ', ' . e($j['city']) : '' ?>
                </p>

                <?php if (!empty($j['problem_description'])): ?>
                    <p class="text-small text-muted u-m0 u-mb-3">
                        <?= e($j['problem_description']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($j['cancellation_reason'])): ?>
                    <div class="alert alert--warning u-mb-3">
                        <span class="alert__icon">&#9888;</span>
                        <span class="alert__text"><?= e($j['cancellation_reason']) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Workflow controls, driven by the permitted
                     transitions table in functions.php -->
                <div class="jobcard__foot">
                    <?php if ($j['status'] === 'confirmed'): ?>
                        <form method="post" action="jobs.php" class="u-ml-auto">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"     value="set_status">
                            <input type="hidden" name="booking_id" value="<?= (int) $j['booking_id'] ?>">
                            <input type="hidden" name="new_status" value="in_progress">
                            <input type="hidden" name="remarks"    value="Technician reached site.">
                            <input type="hidden" name="return_to"  value="provider/jobs.php?status=<?= e($filter) ?>">
                            <button class="btn btn--accent" type="submit">Start this job</button>
                        </form>

                    <?php elseif ($j['status'] === 'in_progress'): ?>
                        <form method="post" action="jobs.php" style="width:100%">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"     value="set_status">
                            <input type="hidden" name="booking_id" value="<?= (int) $j['booking_id'] ?>">
                            <input type="hidden" name="new_status" value="completed">
                            <input type="hidden" name="return_to"  value="provider/jobs.php?status=<?= e($filter) ?>">

                            <div class="form-grid" style="align-items:end">
                                <div class="field">
                                    <label class="label" for="cost<?= (int) $j['booking_id'] ?>">
                                        Final amount charged (<?= e(CURRENCY) ?>) <span class="req">*</span>
                                    </label>
                                    <input class="input" type="number" step="0.01" min="0" required
                                           id="cost<?= (int) $j['booking_id'] ?>" name="final_cost"
                                           value="<?= e(number_format((float) $j['estimated_cost'], 2, '.', '')) ?>">
                                    <div class="hint">Adjust if parts or extra time were needed.</div>
                                </div>
                                <div class="field">
                                    <label class="label" for="rem<?= (int) $j['booking_id'] ?>">Work done</label>
                                    <input class="input" type="text" name="remarks"
                                           id="rem<?= (int) $j['booking_id'] ?>"
                                           placeholder="Replaced washer and spindle">
                                </div>
                                <div class="field">
                                    <button class="btn btn--success btn--block" type="submit">Mark complete</button>
                                </div>
                            </div>
                        </form>

                    <?php elseif ($j['status'] === 'completed'): ?>
                        <span class="text-small text-success u-ml-auto">
                            &#10004; Completed &middot; <?= e(money($j['final_cost'])) ?> charged
                        </span>

                    <?php else: ?>
                        <span class="text-small text-muted u-ml-auto">
                            No further action available.
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?= paginate($page, $totalPages, 'status=' . urlencode($filter)) ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
