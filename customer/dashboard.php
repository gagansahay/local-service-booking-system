<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/dashboard.php
 *  MODULE  : 4 -- Customer
 *  PURPOSE : The customer's home screen: current activity, anything
 *            needing their attention, and a shortcut into search.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();

/* -- Headline counters ---------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT
        COUNT(*)                                              AS total,
        SUM(status IN ('pending','confirmed','in_progress'))  AS open_jobs,
        SUM(status = 'completed')                             AS completed,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN final_cost END), 0) AS spent
       FROM bookings
      WHERE user_id = :uid"
);
$stmt->execute([':uid' => $userId]);
$stats = $stmt->fetch();

/* -- Active maintenance contracts ------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM maintenance_contracts WHERE user_id = :uid AND status = 'active'"
);
$stmt->execute([':uid' => $userId]);
$activeContracts = (int) $stmt->fetchColumn();

/* -- Upcoming and in-flight bookings --------------------------------- */
$stmt = $pdo->prepare(
    "SELECT b.*, u.full_name AS provider_name, c.category_name
       FROM bookings b
       JOIN providers  p ON p.provider_id = b.provider_id
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
      WHERE b.user_id = :uid
        AND b.status IN ('pending','confirmed','in_progress')
      ORDER BY b.booking_date ASC, b.booking_time ASC
      LIMIT 4"
);
$stmt->execute([':uid' => $userId]);
$upcoming = $stmt->fetchAll();

/* -- Completed jobs still awaiting a review -------------------------- */
$stmt = $pdo->prepare(
    "SELECT b.booking_id, b.booking_code, b.booking_date, u.full_name AS provider_name, c.category_name
       FROM bookings b
       JOIN providers  p ON p.provider_id = b.provider_id
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
       LEFT JOIN feedback f ON f.booking_id = b.booking_id
      WHERE b.user_id = :uid
        AND b.status  = 'completed'
        AND f.feedback_id IS NULL
      ORDER BY b.booking_date DESC
      LIMIT 3"
);
$stmt->execute([':uid' => $userId]);
$awaitingReview = $stmt->fetchAll();

/* -- Maintenance visits that have fallen due ------------------------- */
$stmt = $pdo->prepare(
    "SELECT v.visit_id, v.visit_number, v.scheduled_date,
            mc.contract_code, mp.plan_name, u.full_name AS provider_name
       FROM maintenance_visits    v
       JOIN maintenance_contracts mc ON mc.contract_id = v.contract_id
       JOIN maintenance_plans     mp ON mp.plan_id     = mc.plan_id
       JOIN providers             p  ON p.provider_id  = mc.provider_id
       JOIN users                 u  ON u.user_id      = p.user_id
      WHERE mc.user_id = :uid
        AND v.status   = 'due'
      ORDER BY v.scheduled_date ASC"
);
$stmt->execute([':uid' => $userId]);
$dueVisits = $stmt->fetchAll();

/* -- Popular categories, as a starting point ------------------------- */
$categories = $pdo->query(
    "SELECT c.category_id, c.category_name, c.icon, COUNT(p.provider_id) AS n
       FROM categories c
       LEFT JOIN providers p ON p.category_id = c.category_id AND p.verification_status = 'verified'
      WHERE c.is_active = 1
      GROUP BY c.category_id, c.category_name, c.icon
      ORDER BY n DESC, c.category_name
      LIMIT 6"
)->fetchAll();

$pageTitle   = 'Dashboard';
$pageHeading = 'Hello, ' . explode(' ', current_name())[0];
$pageLede    = 'Here is where your bookings stand today.';
$pageActions = '<a class="btn btn--accent" href="' . e(BASE_URL) . 'customer/search.php">Book a service</a>';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== COUNTERS ================================= -->
<div class="grid grid--4" style="margin-bottom:var(--sp-6)">
    <div class="stat stat--accent">
        <div class="stat__label">Open jobs</div>
        <div class="stat__value"><?= (int) $stats['open_jobs'] ?></div>
        <div class="stat__meta">Pending, confirmed or under way</div>
    </div>
    <div class="stat stat--success">
        <div class="stat__label">Completed</div>
        <div class="stat__value"><?= (int) $stats['completed'] ?></div>
        <div class="stat__meta">Jobs finished to date</div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">AMC plans</div>
        <div class="stat__value"><?= $activeContracts ?></div>
        <div class="stat__meta">Active maintenance contracts</div>
    </div>
    <div class="stat">
        <div class="stat__label">Total spent</div>
        <div class="stat__value" style="font-size:var(--text-xl)"><?= e(money($stats['spent'])) ?></div>
        <div class="stat__meta">Across completed jobs</div>
    </div>
</div>

<!-- ==================== NEEDS ATTENTION ========================== -->
<?php if ($dueVisits): ?>
    <div class="alert alert--warning" role="alert">
        <span class="alert__icon">&#9888;</span>
        <span class="alert__text">
            <strong>Maintenance due.</strong>
            <?php foreach ($dueVisits as $v): ?>
                Visit <?= (int) $v['visit_number'] ?> of <?= e($v['plan_name']) ?>
                was scheduled for <?= e(show_date($v['scheduled_date'])) ?>.
            <?php endforeach; ?>
            <a href="<?= e(BASE_URL) ?>customer/maintenance.php">Open your AMC plans</a>
        </span>
    </div>
<?php endif; ?>

<div class="grid grid--2" style="align-items:start">

    <!-- ---------------- Upcoming bookings ------------------------- -->
    <section>
        <div class="page-head" style="margin-bottom:var(--sp-4)">
            <h2 style="font-size:var(--text-lg)">What is coming up</h2>
            <a class="btn btn--ghost btn--sm" href="<?= e(BASE_URL) ?>customer/my-bookings.php">See all</a>
        </div>

        <?php if (!$upcoming): ?>
            <div class="card">
                <div class="empty">
                    <div class="empty__icon" aria-hidden="true">&#128197;</div>
                    <h3>No bookings yet</h3>
                    <p>When you book a professional, the job will appear here so you can follow its progress.</p>
                    <a class="btn btn--accent" href="<?= e(BASE_URL) ?>customer/search.php">Find a professional</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming as $b): ?>
                <article class="jobcard">
                    <div class="jobcard__stub jobcard__stub--<?= e($b['status']) ?>">
                        <span class="jobcard__code"><?= e($b['booking_code']) ?></span>
                    </div>
                    <div class="jobcard__body">
                        <div class="jobcard__top">
                            <div>
                                <h3 class="jobcard__title"><?= e($b['category_name']) ?></h3>
                                <div class="jobcard__sub">with <?= e($b['provider_name']) ?></div>
                            </div>
                            <?= status_badge($b['status']) ?>
                        </div>

                        <dl class="jobcard__facts">
                            <div><dt>Date</dt><dd><?= e(show_date($b['booking_date'])) ?></dd></div>
                            <div><dt>Time</dt><dd><?= e(show_time($b['booking_time'])) ?></dd></div>
                            <div><dt>Estimate</dt><dd><?= e(money($b['estimated_cost'])) ?></dd></div>
                        </dl>

                        <div class="jobcard__foot">
                            <span class="text-small text-muted"><?= e(excerpt($b['problem_description'], 54)) ?></span>
                            <div class="btn-row">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= e(BASE_URL) ?>customer/my-bookings.php#b<?= (int) $b['booking_id'] ?>">Details</a>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <!-- ---------------- Side column -------------------------------- -->
    <div>
        <?php if ($awaitingReview): ?>
        <section class="card">
            <div class="card__head">
                <h3>Rate your recent jobs</h3>
            </div>
            <div class="card__body">
                <p class="text-small text-muted">
                    Your rating is what helps the next customer choose well.
                </p>
                <?php foreach ($awaitingReview as $r): ?>
                    <div class="jobcard__foot" style="padding:var(--sp-3) 0;border-bottom:1px solid var(--line-soft)">
                        <div>
                            <div style="font-weight:600;color:var(--ink-900)"><?= e($r['category_name']) ?></div>
                            <div class="text-small text-muted">
                                <?= e($r['provider_name']) ?> &middot; <?= e(show_date($r['booking_date'])) ?>
                            </div>
                        </div>
                        <div class="btn-row">
                            <a class="btn btn--accent btn--sm"
                               href="<?= e(BASE_URL) ?>customer/feedback.php?booking=<?= (int) $r['booking_id'] ?>">
                                Leave a rating
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card__head"><h3>Book something else</h3></div>
            <div class="card__body">
                <div class="cat-grid" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:var(--sp-3)">
                    <?php foreach ($categories as $c): ?>
                        <a class="cat-tile" style="padding:var(--sp-4)"
                           href="<?= e(BASE_URL) ?>customer/search.php?category=<?= (int) $c['category_id'] ?>">
                            <div class="cat-tile__icon" style="font-size:22px" aria-hidden="true"><?= e($c['icon']) ?></div>
                            <span class="cat-tile__name" style="font-size:var(--text-sm)"><?= e($c['category_name']) ?></span>
                            <span class="cat-tile__count"><?= (int) $c['n'] ?> available</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
