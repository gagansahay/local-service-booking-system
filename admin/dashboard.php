<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/dashboard.php
 *  MODULE  : 2 -- Admin
 *  PURPOSE : System-wide overview: registrations, bookings, revenue,
 *            what needs the administrator's attention, and recent
 *            activity.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* -- Headline figures, in one round trip ----------------------------- */
$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'customer')                        AS customers,
        (SELECT COUNT(*) FROM providers)                                            AS providers,
        (SELECT COUNT(*) FROM providers WHERE verification_status = 'verified')      AS verified,
        (SELECT COUNT(*) FROM providers WHERE verification_status = 'pending')       AS pending_providers,
        (SELECT COUNT(*) FROM categories WHERE is_active = 1)                        AS categories,
        (SELECT COUNT(*) FROM bookings)                                              AS bookings,
        (SELECT COUNT(*) FROM bookings WHERE status = 'pending')                     AS pending_bookings,
        (SELECT COUNT(*) FROM bookings WHERE status = 'completed')                   AS completed,
        (SELECT COUNT(*) FROM bookings WHERE status IN ('cancelled','rejected'))     AS cancelled,
        (SELECT COALESCE(SUM(final_cost), 0) FROM bookings WHERE status = 'completed') AS revenue,
        (SELECT COUNT(*) FROM maintenance_contracts WHERE status = 'active')         AS amc_active,
        (SELECT COALESCE(SUM(amount_paid), 0) FROM maintenance_contracts WHERE status = 'active') AS amc_value,
        (SELECT COUNT(*) FROM feedback)                                              AS reviews,
        (SELECT COALESCE(ROUND(AVG(rating), 2), 0) FROM feedback WHERE is_approved = 1) AS avg_rating"
)->fetch();

$completionRate = ((int) $stats['bookings']) > 0
    ? round((int) $stats['completed'] / (int) $stats['bookings'] * 100)
    : 0;

/* -- Professionals awaiting verification ------------------------------ */
$pendingProviders = $pdo->query(
    "SELECT p.provider_id, u.full_name, u.email, u.city, u.created_at,
            c.category_name, p.experience_years, p.hourly_rate
       FROM providers  p
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
      WHERE p.verification_status = 'pending'
      ORDER BY u.created_at ASC
      LIMIT 5"
)->fetchAll();

/* -- Category performance, straight from the reporting view ----------- */
$categoryPerformance = $pdo->query(
    'SELECT * FROM vw_category_performance ORDER BY total_bookings DESC, category_name'
)->fetchAll();

$peakBookings = 0;
foreach ($categoryPerformance as $c) {
    $peakBookings = max($peakBookings, (int) $c['total_bookings']);
}

/* -- Recent bookings --------------------------------------------------- */
$recentBookings = $pdo->query(
    "SELECT b.booking_id, b.booking_code, b.booking_date, b.status, b.estimated_cost, b.final_cost,
            cu.full_name AS customer_name, pu.full_name AS provider_name, c.category_name
       FROM bookings   b
       JOIN users      cu ON cu.user_id    = b.user_id
       JOIN providers  p  ON p.provider_id = b.provider_id
       JOIN users      pu ON pu.user_id    = p.user_id
       JOIN categories c  ON c.category_id = p.category_id
      ORDER BY b.created_at DESC
      LIMIT 8"
)->fetchAll();

/* -- Recent security / audit events ------------------------------------ */
$recentActivity = $pdo->query(
    'SELECT a.action, a.entity, a.details, a.ip_address, a.created_at, u.full_name
       FROM activity_log a
       LEFT JOIN users u ON u.user_id = a.user_id
      ORDER BY a.created_at DESC
      LIMIT 8'
)->fetchAll();

$pageTitle   = 'Dashboard';
$pageHeading = 'System overview';
$pageLede    = 'Everything happening across the platform right now.';
$pageActions = '<a class="btn btn--outline" href="' . e(BASE_URL) . 'admin/reports.php">Open reports</a>';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== NEEDS ATTENTION ========================== -->
<?php if ((int) $stats['pending_providers'] > 0): ?>
    <div class="alert alert--warning" role="alert">
        <span class="alert__icon">&#9888;</span>
        <span class="alert__text">
            <strong><?= (int) $stats['pending_providers'] ?>
            professional<?= (int) $stats['pending_providers'] === 1 ? '' : 's' ?>
            waiting for verification.</strong>
            They stay invisible to customers until you approve them.
            <a href="providers.php?status=pending">Review them now</a>
        </span>
    </div>
<?php endif; ?>

<!-- ==================== COUNTERS ================================= -->
<div class="grid grid--4 u-mb-5">
    <div class="stat stat--blue">
        <div class="stat__label">Customers</div>
        <div class="stat__value"><?= (int) $stats['customers'] ?></div>
        <div class="stat__meta">Registered accounts</div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Professionals</div>
        <div class="stat__value"><?= (int) $stats['providers'] ?></div>
        <div class="stat__meta"><?= (int) $stats['verified'] ?> verified, <?= (int) $stats['pending_providers'] ?> pending</div>
    </div>
    <div class="stat stat--success">
        <div class="stat__label">Bookings</div>
        <div class="stat__value"><?= (int) $stats['bookings'] ?></div>
        <div class="stat__meta"><?= $completionRate ?>% completed</div>
    </div>
    <div class="stat">
        <div class="stat__label">Revenue</div>
        <div class="stat__value stat__value--sm"><?= e(money($stats['revenue'])) ?></div>
        <div class="stat__meta">Across completed jobs</div>
    </div>
</div>

<div class="grid grid--4 u-mb-6">
    <div class="stat">
        <div class="stat__label">Awaiting action</div>
        <div class="stat__value"><?= (int) $stats['pending_bookings'] ?></div>
        <div class="stat__meta">Bookings not yet accepted</div>
    </div>
    <div class="stat stat--danger">
        <div class="stat__label">Cancelled</div>
        <div class="stat__value"><?= (int) $stats['cancelled'] ?></div>
        <div class="stat__meta">Cancelled or declined</div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Active AMC</div>
        <div class="stat__value"><?= (int) $stats['amc_active'] ?></div>
        <div class="stat__meta"><?= e(money($stats['amc_value'])) ?> committed</div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Average rating</div>
        <div class="stat__value"><?= number_format((float) $stats['avg_rating'], 2) ?></div>
        <div class="stat__meta">From <?= (int) $stats['reviews'] ?> reviews</div>
    </div>
</div>

<div class="grid grid--2 u-items-start">

    <div>
        <!-- ------------- Pending verifications ------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Waiting for verification</h3>
                <a class="btn btn--ghost btn--sm" href="providers.php?status=pending">See all</a>
            </div>
            <div class="card__body<?= $pendingProviders ? ' card__body--flush' : '' ?>">
                <?php if (!$pendingProviders): ?>
                    <div class="empty u-pad-y-8">
                        <div class="empty__icon" aria-hidden="true">&#10004;</div>
                        <h3>Nothing waiting</h3>
                        <p>Every registered professional has been reviewed.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr><th>Professional</th><th>Trade</th><th>Registered</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendingProviders as $p): ?>
                                <tr>
                                    <td>
                                        <div class="person">
                                            <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($p['full_name'])) ?></span>
                                            <div>
                                                <div class="person__name"><?= e($p['full_name']) ?></div>
                                                <div class="person__meta"><?= e($p['city'] ?: 'City not set') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= e($p['category_name']) ?><br>
                                        <span class="text-small text-muted">
                                            <?= (int) $p['experience_years'] ?> yrs &middot; <?= e(money($p['hourly_rate'])) ?>/hr
                                        </span>
                                    </td>
                                    <td class="text-small"><?= e(show_date($p['created_at'])) ?></td>
                                    <td class="text-right">
                                        <a class="btn btn--accent btn--sm"
                                           href="providers.php?status=pending#p<?= (int) $p['provider_id'] ?>">Review</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ------------- Recent bookings ------------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Latest bookings</h3>
                <a class="btn btn--ghost btn--sm" href="bookings.php">See all</a>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Code</th><th>Customer</th><th>Professional</th><th>Status</th><th class="text-right">Value</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentBookings as $b): ?>
                            <tr>
                                <td><span class="ref"><?= e($b['booking_code']) ?></span></td>
                                <td class="table__primary"><?= e($b['customer_name']) ?></td>
                                <td>
                                    <?= e($b['provider_name']) ?><br>
                                    <span class="text-small text-muted"><?= e($b['category_name']) ?></span>
                                </td>
                                <td><?= status_badge($b['status']) ?></td>
                                <td class="text-right"><?= e(money($b['final_cost'] ?? $b['estimated_cost'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div>
        <!-- ------------- Category performance -------------------- -->
        <section class="card">
            <div class="card__head"><h3>Demand by trade</h3></div>
            <div class="card__body">
                <?php foreach ($categoryPerformance as $c): ?>
                    <?php $pct = $peakBookings > 0 ? round((int) $c['total_bookings'] / $peakBookings * 100) : 0; ?>
                    <div class="u-mb-4">
                        <div class="meter-label">
                            <span class="table__primary"><?= e($c['category_name']) ?></span>
                            <span class="text-muted">
                                <?= (int) $c['total_bookings'] ?> bookings
                                &middot; <?= (int) $c['total_providers'] ?> pros
                            </span>
                        </div>
                        <div class="meter" style="height:10px;margin:0">
                            <div class="meter__fill" style="width:<?= $pct ?>%;background:var(--marigold-500)"></div>
                        </div>
                        <?php if ((float) $c['total_revenue'] > 0): ?>
                            <div class="text-small text-muted" style="margin-top:2px">
                                <?= e(money($c['total_revenue'])) ?> earned
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ------------- Activity log ---------------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Recent activity</h3>
                <a class="btn btn--ghost btn--sm" href="activity-log.php">Full log</a>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                        <?php foreach ($recentActivity as $a): ?>
                            <tr>
                                <td>
                                    <span class="table__primary"><?= e(str_replace('_', ' ', $a['action'])) ?></span><br>
                                    <span class="text-small text-muted">
                                        <?= e($a['full_name'] ?: 'Unknown') ?>
                                        <?= $a['details'] ? ' &middot; ' . e(excerpt($a['details'], 34)) : '' ?>
                                    </span>
                                </td>
                                <td class="text-right text-small text-muted">
                                    <?= e(show_datetime($a['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
