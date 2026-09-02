<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/dashboard.php
 *  MODULE  : 3 -- Service Provider
 *  PURPOSE : The professional's home screen: work waiting, today's
 *            schedule, earnings and standing.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$providerId = current_provider_id();

/* -- Verification state gates everything else ------------------------ */
$stmt = $pdo->prepare(
    'SELECT p.*, c.category_name
       FROM providers p
       JOIN categories c ON c.category_id = p.category_id
      WHERE p.provider_id = :pid'
);
$stmt->execute([':pid' => $providerId]);
$profile = $stmt->fetch();

/* -- Counters -------------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT
        SUM(status = 'pending')                                   AS pending,
        SUM(status = 'confirmed')                                 AS confirmed,
        SUM(status = 'in_progress')                               AS in_progress,
        SUM(status = 'completed')                                 AS completed,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN final_cost END), 0) AS earned,
        COALESCE(SUM(CASE WHEN status = 'completed'
                           AND MONTH(booking_date) = MONTH(CURDATE())
                           AND YEAR(booking_date)  = YEAR(CURDATE())
                          THEN final_cost END), 0)                AS earned_month
       FROM bookings
      WHERE provider_id = :pid"
);
$stmt->execute([':pid' => $providerId]);
$stats = $stmt->fetch();

/* -- Today's schedule ------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT b.*, u.full_name AS customer_name, u.phone AS customer_phone, s.service_name
       FROM bookings b
       JOIN users    u ON u.user_id    = b.user_id
       LEFT JOIN services s ON s.service_id = b.service_id
      WHERE b.provider_id  = :pid
        AND b.booking_date = CURDATE()
        AND b.status IN ('confirmed','in_progress')
      ORDER BY b.booking_time ASC"
);
$stmt->execute([':pid' => $providerId]);
$today = $stmt->fetchAll();

/* -- Requests awaiting a decision ------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT b.*, u.full_name AS customer_name, s.service_name
       FROM bookings b
       JOIN users    u ON u.user_id    = b.user_id
       LEFT JOIN services s ON s.service_id = b.service_id
      WHERE b.provider_id = :pid AND b.status = 'pending'
      ORDER BY b.booking_date ASC, b.booking_time ASC
      LIMIT 4"
);
$stmt->execute([':pid' => $providerId]);
$requests = $stmt->fetchAll();

/* -- Latest reviews -------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT f.rating, f.comments, f.created_at, u.full_name
       FROM feedback f
       JOIN users u ON u.user_id = f.user_id
      WHERE f.provider_id = :pid AND f.is_approved = 1
      ORDER BY f.created_at DESC
      LIMIT 3'
);
$stmt->execute([':pid' => $providerId]);
$reviews = $stmt->fetchAll();

/* -- Upcoming AMC visits --------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT v.scheduled_date, v.visit_number, v.status,
            mp.plan_name, mc.contract_code, u.full_name AS customer_name
       FROM maintenance_visits    v
       JOIN maintenance_contracts mc ON mc.contract_id = v.contract_id
       JOIN maintenance_plans     mp ON mp.plan_id     = mc.plan_id
       JOIN users                 u  ON u.user_id      = mc.user_id
      WHERE mc.provider_id = :pid
        AND v.status IN ('due','scheduled')
      ORDER BY v.scheduled_date ASC
      LIMIT 4"
);
$stmt->execute([':pid' => $providerId]);
$amcVisits = $stmt->fetchAll();

$pageTitle   = 'Dashboard';
$pageHeading = 'Hello, ' . explode(' ', current_name())[0];
$pageLede    = $profile['category_name'] . ' · ' . money($profile['hourly_rate']) . ' per hour';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== VERIFICATION NOTICE ====================== -->
<?php if ($profile['verification_status'] !== 'verified'): ?>
    <div class="alert alert--<?= $profile['verification_status'] === 'rejected' ? 'error' : 'warning' ?>" role="alert">
        <span class="alert__icon">&#9888;</span>
        <span class="alert__text">
            <?php if ($profile['verification_status'] === 'pending'): ?>
                <strong>Your profile is awaiting verification.</strong>
                You will not appear in customer searches until an administrator approves it.
                Complete your profile and add your services in the meantime &mdash; it will
                go live the moment you are approved.
            <?php else: ?>
                <strong>Your profile was not approved.</strong>
                Please contact the administrator to find out what needs correcting.
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<!-- ==================== COUNTERS ================================= -->
<div class="grid grid--4" style="margin-bottom:var(--sp-6)">
    <div class="stat stat--accent">
        <div class="stat__label">New requests</div>
        <div class="stat__value"><?= (int) $stats['pending'] ?></div>
        <div class="stat__meta">Waiting for your decision</div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Scheduled</div>
        <div class="stat__value"><?= (int) $stats['confirmed'] + (int) $stats['in_progress'] ?></div>
        <div class="stat__meta">Accepted and under way</div>
    </div>
    <div class="stat stat--success">
        <div class="stat__label">Jobs completed</div>
        <div class="stat__value"><?= (int) $stats['completed'] ?></div>
        <div class="stat__meta">Lifetime</div>
    </div>
    <div class="stat">
        <div class="stat__label">Earned this month</div>
        <div class="stat__value" style="font-size:var(--text-xl)"><?= e(money($stats['earned_month'])) ?></div>
        <div class="stat__meta"><?= e(money($stats['earned'])) ?> lifetime</div>
    </div>
</div>

<div class="grid grid--2" style="align-items:start">

    <div>
        <!-- ------------- Today ---------------------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Today &mdash; <?= e(date(DATE_FORMAT)) ?></h3>
                <a class="btn btn--ghost btn--sm" href="jobs.php">All jobs</a>
            </div>
            <div class="card__body<?= $today ? ' card__body--flush' : '' ?>">
                <?php if (!$today): ?>
                    <div class="empty" style="padding:var(--sp-8) 0">
                        <div class="empty__icon" aria-hidden="true">&#9200;</div>
                        <h3>Nothing scheduled today</h3>
                        <p>Accepted jobs for today will appear here with the customer's address and number.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr><th>Time</th><th>Customer</th><th>Job</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($today as $t): ?>
                                <tr>
                                    <td class="table__primary"><?= e(show_time($t['booking_time'])) ?></td>
                                    <td>
                                        <span class="table__primary"><?= e($t['customer_name']) ?></span><br>
                                        <span class="ref"><?= e($t['customer_phone']) ?></span>
                                    </td>
                                    <td>
                                        <?= e($t['service_name'] ?: 'General visit') ?><br>
                                        <span class="text-small text-muted"><?= e(excerpt($t['service_address'], 40)) ?></span>
                                    </td>
                                    <td><?= status_badge($t['status']) ?></td>
                                    <td class="text-right">
                                        <a class="btn btn--outline btn--sm" href="jobs.php#b<?= (int) $t['booking_id'] ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ------------- Requests -------------------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Requests waiting</h3>
                <a class="btn btn--ghost btn--sm" href="requests.php">See all</a>
            </div>
            <div class="card__body">
                <?php if (!$requests): ?>
                    <div class="empty" style="padding:var(--sp-8) 0">
                        <div class="empty__icon" aria-hidden="true">&#9993;</div>
                        <h3>No new requests</h3>
                        <p>When a customer books you, their request appears here for you to accept or decline.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <div style="padding:var(--sp-3) 0;border-bottom:1px solid var(--line-soft)">
                            <div style="display:flex;justify-content:space-between;gap:var(--sp-3)">
                                <div>
                                    <strong style="color:var(--ink-900)"><?= e($r['customer_name']) ?></strong>
                                    <div class="text-small text-muted">
                                        <?= e($r['service_name'] ?: 'General visit') ?> &middot;
                                        <?= e(show_date($r['booking_date'])) ?> at <?= e(show_time($r['booking_time'])) ?>
                                    </div>
                                </div>
                                <span class="ref"><?= e($r['booking_code']) ?></span>
                            </div>
                            <div class="btn-row" style="margin-top:var(--sp-2)">
                                <a class="btn btn--accent btn--sm" href="requests.php#b<?= (int) $r['booking_id'] ?>">Review</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div>
        <!-- ------------- Standing -------------------------------- -->
        <section class="card">
            <div class="card__head"><h3>Your standing</h3></div>
            <div class="card__body">
                <div style="text-align:center;padding:var(--sp-4) 0">
                    <div class="stat__value" style="font-size:var(--text-3xl)">
                        <?= number_format((float) $profile['avg_rating'], 1) ?>
                    </div>
                    <?= star_rating((float) $profile['avg_rating'], (int) $profile['total_reviews']) ?>
                </div>
                <dl class="jobcard__facts">
                    <div><dt>Experience</dt><dd><?= (int) $profile['experience_years'] ?> years</dd></div>
                    <div><dt>Hourly rate</dt><dd><?= e(money($profile['hourly_rate'])) ?></dd></div>
                    <div><dt>Status</dt><dd><?= status_badge($profile['verification_status']) ?></dd></div>
                </dl>
                <a class="btn btn--outline btn--block" style="margin-top:var(--sp-4)" href="profile.php">Edit my profile</a>
            </div>
        </section>

        <!-- ------------- AMC visits ------------------------------ -->
        <?php if ($amcVisits): ?>
        <section class="card">
            <div class="card__head">
                <h3>Maintenance visits</h3>
                <a class="btn btn--ghost btn--sm" href="maintenance.php">All</a>
            </div>
            <div class="card__body">
                <?php foreach ($amcVisits as $v): ?>
                    <div style="display:flex;justify-content:space-between;gap:var(--sp-3);padding:var(--sp-3) 0;border-bottom:1px solid var(--line-soft)">
                        <div>
                            <strong style="color:var(--ink-900)"><?= e($v['customer_name']) ?></strong>
                            <div class="text-small text-muted">
                                <?= e($v['plan_name']) ?> · visit <?= (int) $v['visit_number'] ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-small"><?= e(show_date($v['scheduled_date'])) ?></div>
                            <?= status_badge($v['status']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ------------- Reviews --------------------------------- -->
        <?php if ($reviews): ?>
        <section class="card">
            <div class="card__head"><h3>Recent reviews</h3></div>
            <div class="card__body">
                <?php foreach ($reviews as $r): ?>
                    <div style="padding:var(--sp-3) 0;border-bottom:1px solid var(--line-soft)">
                        <div style="display:flex;justify-content:space-between;gap:var(--sp-3)">
                            <strong style="color:var(--ink-900)"><?= e($r['full_name']) ?></strong>
                            <?= star_rating((float) $r['rating']) ?>
                        </div>
                        <?php if (!empty($r['comments'])): ?>
                            <p class="text-small" style="margin:var(--sp-2) 0 0"><?= e(excerpt($r['comments'], 110)) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
