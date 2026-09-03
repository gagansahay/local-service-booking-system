<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/earnings.php
 *  MODULE  : 3 -- Service Provider  (with Module 10, Reports)
 *  PURPOSE : What the professional has earned, broken down by month and
 *            by service, with the settled and outstanding split.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$providerId = current_provider_id();

/* -- Headline totals -------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.final_cost END), 0) AS lifetime,
        COALESCE(SUM(CASE WHEN b.status = 'completed'
                           AND MONTH(b.booking_date) = MONTH(CURDATE())
                           AND YEAR(b.booking_date)  = YEAR(CURDATE())
                          THEN b.final_cost END), 0)                             AS this_month,
        COALESCE(SUM(CASE WHEN pay.payment_status = 'paid'    THEN pay.amount END), 0) AS settled,
        COALESCE(SUM(CASE WHEN pay.payment_status = 'pending'
                           AND b.status = 'completed'         THEN pay.amount END), 0) AS outstanding,
        SUM(b.status = 'completed')                                              AS jobs
       FROM bookings b
       LEFT JOIN payments pay ON pay.booking_id = b.booking_id
      WHERE b.provider_id = :pid"
);
$stmt->execute([':pid' => $providerId]);
$totals = $stmt->fetch();

$averageJob = ((int) $totals['jobs']) > 0
    ? (float) $totals['lifetime'] / (int) $totals['jobs']
    : 0.0;

/* -- Last twelve months ----------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(booking_date, '%Y-%m') AS ym,
            DATE_FORMAT(booking_date, '%b %Y') AS label,
            COUNT(*)                           AS jobs,
            COALESCE(SUM(final_cost), 0)       AS revenue
       FROM bookings
      WHERE provider_id = :pid
        AND status = 'completed'
        AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      GROUP BY ym, label
      ORDER BY ym ASC"
);
$stmt->execute([':pid' => $providerId]);
$monthly = $stmt->fetchAll();

$peakRevenue = 0.0;
foreach ($monthly as $m) {
    $peakRevenue = max($peakRevenue, (float) $m['revenue']);
}

/* -- Which services actually earn ------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT COALESCE(s.service_name, 'General visit') AS service_name,
            COUNT(*)                     AS jobs,
            COALESCE(SUM(b.final_cost), 0) AS revenue
       FROM bookings b
       LEFT JOIN services s ON s.service_id = b.service_id
      WHERE b.provider_id = :pid AND b.status = 'completed'
      GROUP BY service_name
      ORDER BY revenue DESC"
);
$stmt->execute([':pid' => $providerId]);
$byService = $stmt->fetchAll();

/* -- Recent settled and outstanding invoices --------------------------- */
$stmt = $pdo->prepare(
    "SELECT b.booking_code, b.booking_date, b.final_cost,
            pay.invoice_no, pay.payment_mode, pay.payment_status, pay.paid_at,
            u.full_name AS customer_name
       FROM bookings b
       JOIN users    u   ON u.user_id     = b.user_id
       JOIN payments pay ON pay.booking_id = b.booking_id
      WHERE b.provider_id = :pid AND b.status = 'completed'
      ORDER BY b.booking_date DESC
      LIMIT 20"
);
$stmt->execute([':pid' => $providerId]);
$invoices = $stmt->fetchAll();

$pageTitle   = 'Earnings';
$pageHeading = 'Earnings';
$pageLede    = 'What you have billed, what has been settled, and what is still owed.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--4 u-mb-6">
    <div class="stat stat--success">
        <div class="stat__label">Earned this month</div>
        <div class="stat__value stat__value--sm"><?= e(money($totals['this_month'])) ?></div>
        <div class="stat__meta"><?= e(date('F Y')) ?></div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Lifetime</div>
        <div class="stat__value stat__value--sm"><?= e(money($totals['lifetime'])) ?></div>
        <div class="stat__meta"><?= (int) $totals['jobs'] ?> completed jobs</div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Awaiting payment</div>
        <div class="stat__value stat__value--sm"><?= e(money($totals['outstanding'])) ?></div>
        <div class="stat__meta">Completed but unsettled</div>
    </div>
    <div class="stat">
        <div class="stat__label">Average per job</div>
        <div class="stat__value stat__value--sm"><?= e(money($averageJob)) ?></div>
        <div class="stat__meta">Across all completed work</div>
    </div>
</div>

<div class="grid grid--2 u-items-start">

    <!-- ---------------- Monthly trend ---------------------------- -->
    <section class="card">
        <div class="card__head"><h3>Last twelve months</h3></div>
        <div class="card__body">
            <?php if (!$monthly): ?>
                <div class="empty u-pad-y-8">
                    <div class="empty__icon" aria-hidden="true">&#8377;</div>
                    <h3>No completed jobs yet</h3>
                    <p>Once you complete a job, your earnings will be charted here month by month.</p>
                </div>
            <?php else: ?>
                <?php foreach ($monthly as $m): ?>
                    <?php $pct = $peakRevenue > 0 ? round((float) $m['revenue'] / $peakRevenue * 100) : 0; ?>
                    <div class="u-mb-4">
                        <div class="meter-label">
                            <span class="table__primary"><?= e($m['label']) ?></span>
                            <span>
                                <?= e(money($m['revenue'])) ?>
                                <span class="text-muted">&middot; <?= (int) $m['jobs'] ?> job<?= (int) $m['jobs'] === 1 ? '' : 's' ?></span>
                            </span>
                        </div>
                        <!-- Bar width is the real proportion of the best
                             month, so the chart cannot mislead. -->
                        <div class="meter" style="height:10px;margin:0">
                            <div class="meter__fill"
                                 style="width:<?= $pct ?>%;background:var(--blue-600)"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ---------------- By service -------------------------------- -->
    <section class="card">
        <div class="card__head"><h3>Where the money comes from</h3></div>
        <div class="card__body card__body--flush">
            <?php if (!$byService): ?>
                <div class="empty u-pad-y-8">
                    <div class="empty__icon" aria-hidden="true">&#9635;</div>
                    <h3>Nothing to break down yet</h3>
                    <p>This will show which of your services earn the most once jobs are completed.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Service</th><th class="text-right">Jobs</th><th class="text-right">Revenue</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($byService as $s): ?>
                            <tr>
                                <td class="table__primary"><?= e($s['service_name']) ?></td>
                                <td class="text-right"><?= (int) $s['jobs'] ?></td>
                                <td class="text-right table__primary"><?= e(money($s['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ---------------- Invoice log ---------------------------------- -->
<section class="card">
    <div class="card__head">
        <h3>Completed jobs and their invoices</h3>
        <input class="input" type="search" style="max-width:240px"
               data-filter-table="earnTable" placeholder="Search by customer or code">
    </div>
    <div class="card__body card__body--flush">
        <?php if (!$invoices): ?>
            <div class="empty u-pad-y-10">
                <div class="empty__icon" aria-hidden="true">&#128179;</div>
                <h3>No invoices yet</h3>
                <p>An invoice appears here for every job you mark complete.</p>
                <a class="btn btn--accent" href="jobs.php">Go to my jobs</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="earnTable">
                    <thead>
                        <tr>
                            <th>Invoice</th><th>Customer</th><th>Date</th>
                            <th>Mode</th><th>Status</th><th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <tr>
                            <td>
                                <span class="ref"><?= e($i['invoice_no']) ?></span><br>
                                <span class="text-small text-muted"><?= e($i['booking_code']) ?></span>
                            </td>
                            <td class="table__primary"><?= e($i['customer_name']) ?></td>
                            <td><?= e(show_date($i['booking_date'])) ?></td>
                            <td><?= e(ucfirst($i['payment_mode'])) ?></td>
                            <td><?= status_badge($i['payment_status']) ?></td>
                            <td class="text-right table__primary"><?= e(money($i['final_cost'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
