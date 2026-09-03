<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/reports.php
 *  MODULE  : 10 -- Reports
 *  PURPOSE : Management reports over a chosen date range, with CSV
 *            export and a print-ready layout.
 *
 *  REPORTS PRODUCED
 *    1. Booking summary        -- volume and value by status
 *    2. Category performance   -- demand and revenue by trade
 *    3. Professional performance -- jobs, earnings and ratings
 *    4. Monthly trend          -- bookings and revenue over time
 *    5. Maintenance contracts  -- AMC take-up and visit completion
 *
 *  The CSV export runs BEFORE any HTML is emitted, because headers
 *  cannot be sent once output has started.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* -- Date range. Defaults to the last twelve months. ------------------ */
$fromDate = get('from');
$toDate   = get('to');

if (!valid_date($fromDate)) {
    $fromDate = date('Y-m-d', strtotime('-12 months'));
}
if (!valid_date($toDate)) {
    $toDate = date('Y-m-d');
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$range = [':from' => $fromDate, ':to' => $toDate];

/* =====================================================================
 * REPORT QUERIES
 * ===================================================================*/

/* 1. Booking summary by status --------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT status,
            COUNT(*) AS bookings,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN final_cost
                              ELSE estimated_cost END), 0) AS value
       FROM bookings
      WHERE booking_date BETWEEN :from AND :to
      GROUP BY status
      ORDER BY bookings DESC"
);
$stmt->execute($range);
$byStatus = $stmt->fetchAll();

$totalBookings = 0;
$totalValue    = 0.0;
foreach ($byStatus as $row) {
    $totalBookings += (int) $row['bookings'];
    $totalValue    += (float) $row['value'];
}

/* 2. Category performance -------------------------------------------- */
$stmt = $pdo->prepare(
    // COALESCE around every SUM: a trade with no bookings in the period
    // would otherwise return NULL and export as an empty CSV cell.
    "SELECT c.category_name,
            COUNT(DISTINCT p.provider_id)                       AS providers,
            COUNT(b.booking_id)                                 AS bookings,
            COALESCE(SUM(b.status = 'completed'), 0)            AS completed,
            COALESCE(SUM(b.status IN ('cancelled','rejected')), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.final_cost END), 0) AS revenue
       FROM categories c
       LEFT JOIN providers p ON p.category_id = c.category_id
       LEFT JOIN bookings  b ON b.provider_id = p.provider_id
                            AND b.booking_date BETWEEN :from AND :to
      GROUP BY c.category_id, c.category_name
      ORDER BY revenue DESC, bookings DESC"
);
$stmt->execute($range);
$byCategory = $stmt->fetchAll();

/* 3. Professional performance ----------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT u.full_name, c.category_name, u.city,
            p.avg_rating, p.total_reviews, p.experience_years, p.hourly_rate,
            COUNT(b.booking_id)                                 AS bookings,
            COALESCE(SUM(b.status = 'completed'), 0)            AS completed,
            COALESCE(SUM(b.status IN ('cancelled','rejected')), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.final_cost END), 0) AS revenue
       FROM providers  p
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
       LEFT JOIN bookings b ON b.provider_id = p.provider_id
                           AND b.booking_date BETWEEN :from AND :to
      WHERE p.verification_status = 'verified'
      GROUP BY p.provider_id, u.full_name, c.category_name, u.city,
               p.avg_rating, p.total_reviews, p.experience_years, p.hourly_rate
      ORDER BY revenue DESC"
);
$stmt->execute($range);
$byProvider = $stmt->fetchAll();

/* 4. Monthly trend ----------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(booking_date, '%Y-%m') AS ym,
            DATE_FORMAT(booking_date, '%b %Y') AS label,
            COUNT(*)                           AS bookings,
            SUM(status = 'completed')          AS completed,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN final_cost END), 0) AS revenue
       FROM bookings
      WHERE booking_date BETWEEN :from AND :to
      GROUP BY ym, label
      ORDER BY ym ASC"
);
$stmt->execute($range);
$monthly = $stmt->fetchAll();

$peakRevenue = 0.0;
foreach ($monthly as $m) {
    $peakRevenue = max($peakRevenue, (float) $m['revenue']);
}

/* 5. Maintenance contracts --------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT mp.plan_name, c.category_name, mp.frequency, mp.price,
            COUNT(mc.contract_id)                 AS contracts,
            SUM(mc.status = 'active')             AS active,
            COALESCE(SUM(mc.amount_paid), 0)      AS revenue,
            COALESCE(SUM(mc.visits_used), 0)      AS visits_done,
            COALESCE(SUM(mc.total_visits), 0)     AS visits_total
       FROM maintenance_plans mp
       JOIN categories c ON c.category_id = mp.category_id
       LEFT JOIN maintenance_contracts mc ON mc.plan_id = mp.plan_id
                                         AND mc.start_date BETWEEN :from AND :to
      GROUP BY mp.plan_id, mp.plan_name, c.category_name, mp.frequency, mp.price
      ORDER BY revenue DESC"
);
$stmt->execute($range);
$byPlan = $stmt->fetchAll();

/* =====================================================================
 * CSV EXPORT
 * ---------------------------------------------------------------------
 * Must run before any HTML output. fputcsv() handles the quoting of
 * commas and quotation marks inside field values correctly.
 * ===================================================================*/
if (get('export') !== '') {

    $exports = [
        'status'   => ['booking-summary',           ['Status', 'Bookings', 'Value'],                     $byStatus],
        'category' => ['category-performance',      ['Category', 'Professionals', 'Bookings', 'Completed', 'Cancelled', 'Revenue'], $byCategory],
        'provider' => ['professional-performance',  ['Professional', 'Trade', 'City', 'Rating', 'Reviews', 'Experience', 'Hourly rate', 'Bookings', 'Completed', 'Cancelled', 'Revenue'], $byProvider],
        'monthly'  => ['monthly-trend',             ['Month', 'Bookings', 'Completed', 'Revenue'],       $monthly],
        'plan'     => ['maintenance-contracts',     ['Plan', 'Trade', 'Frequency', 'Price', 'Contracts', 'Active', 'Revenue', 'Visits done', 'Visits total'], $byPlan],
    ];

    $which = get('export');

    if (isset($exports[$which])) {
        [$slug, $headings, $rows] = $exports[$which];

        $filename = sprintf('lsbms-%s-%s-to-%s.csv', $slug, $fromDate, $toDate);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM so Excel opens the file with the right encoding.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Local Service Booking & Management System']);
        fputcsv($out, [ucfirst(str_replace('-', ' ', $slug)) . ' report']);
        fputcsv($out, ['Period', show_date($fromDate) . ' to ' . show_date($toDate)]);
        fputcsv($out, ['Generated', date(DATETIME_FORMAT) . ' by ' . current_name()]);
        fputcsv($out, []);
        fputcsv($out, $headings);

        foreach ($rows as $row) {
            // array_values keeps the column order aligned with $headings.
            fputcsv($out, array_values($row));
        }

        fclose($out);

        log_activity('report_exported', 'reports', null, $slug . ' ' . $fromDate . '..' . $toDate);
        exit;
    }
}

$rangeQuery = http_build_query(['from' => $fromDate, 'to' => $toDate]);

$pageTitle   = 'Reports';
$pageHeading = 'Management reports';
$pageLede    = 'Period: ' . show_date($fromDate) . ' to ' . show_date($toDate);

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== RANGE PICKER ============================= -->
<form class="filters no-print" method="get" action="reports.php">
    <div class="filters__row">
        <div>
            <label class="label" for="from">From</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($fromDate) ?>">
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($toDate) ?>">
        </div>
        <div class="btn-row">
            <button class="btn btn--primary" type="submit">Run reports</button>
            <button class="btn btn--outline" type="button" onclick="window.print()">Print</button>
        </div>
        <div class="btn-row">
            <a class="btn btn--ghost btn--sm"
               href="reports.php?from=<?= e(date('Y-m-01')) ?>&amp;to=<?= e(date('Y-m-t')) ?>">This month</a>
            <a class="btn btn--ghost btn--sm"
               href="reports.php?from=<?= e(date('Y-01-01')) ?>&amp;to=<?= e(date('Y-12-31')) ?>">This year</a>
        </div>
    </div>
</form>

<!-- ==================== HEADLINE ================================= -->
<div class="grid grid--4 u-mb-6">
    <div class="stat stat--blue">
        <div class="stat__label">Bookings in period</div>
        <div class="stat__value"><?= $totalBookings ?></div>
    </div>
    <div class="stat stat--success">
        <div class="stat__label">Value in period</div>
        <div class="stat__value stat__value--sm"><?= e(money($totalValue)) ?></div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Trades active</div>
        <div class="stat__value"><?= count(array_filter($byCategory, static fn($c) => (int) $c['bookings'] > 0)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Professionals</div>
        <div class="stat__value"><?= count($byProvider) ?></div>
        <div class="stat__meta">Verified and listed</div>
    </div>
</div>

<!-- ==================== 1. BOOKING SUMMARY ======================= -->
<section class="card">
    <div class="card__head">
        <h3>1. Booking summary by status</h3>
        <a class="btn btn--outline btn--sm no-print"
           href="reports.php?<?= e($rangeQuery) ?>&amp;export=status">Export CSV</a>
    </div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Status</th><th class="text-right">Bookings</th>
                        <th class="text-right">Share</th><th class="text-right">Value</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byStatus as $row): ?>
                    <tr>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="text-right table__primary"><?= (int) $row['bookings'] ?></td>
                        <td class="text-right">
                            <?= $totalBookings > 0 ? round((int) $row['bookings'] / $totalBookings * 100) : 0 ?>%
                        </td>
                        <td class="text-right"><?= e(money($row['value'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--line)">
                        <td class="table__primary">Total</td>
                        <td class="text-right table__primary"><?= $totalBookings ?></td>
                        <td class="text-right">100%</td>
                        <td class="text-right table__primary"><?= e(money($totalValue)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>

<!-- ==================== 2. CATEGORY PERFORMANCE ================== -->
<section class="card">
    <div class="card__head">
        <h3>2. Performance by trade</h3>
        <a class="btn btn--outline btn--sm no-print"
           href="reports.php?<?= e($rangeQuery) ?>&amp;export=category">Export CSV</a>
    </div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Trade</th><th class="text-right">Pros</th><th class="text-right">Bookings</th>
                        <th class="text-right">Completed</th><th class="text-right">Cancelled</th>
                        <th class="text-right">Completion</th><th class="text-right">Revenue</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byCategory as $row): ?>
                    <?php
                    $rate = (int) $row['bookings'] > 0
                        ? round((int) $row['completed'] / (int) $row['bookings'] * 100)
                        : 0;
                    ?>
                    <tr>
                        <td class="table__primary"><?= e($row['category_name']) ?></td>
                        <td class="text-right"><?= (int) $row['providers'] ?></td>
                        <td class="text-right"><?= (int) $row['bookings'] ?></td>
                        <td class="text-right"><?= (int) $row['completed'] ?></td>
                        <td class="text-right"><?= (int) $row['cancelled'] ?></td>
                        <td class="text-right"><?= $rate ?>%</td>
                        <td class="text-right table__primary"><?= e(money($row['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ==================== 3. PROFESSIONAL PERFORMANCE ============== -->
<section class="card">
    <div class="card__head">
        <h3>3. Professional performance</h3>
        <a class="btn btn--outline btn--sm no-print"
           href="reports.php?<?= e($rangeQuery) ?>&amp;export=provider">Export CSV</a>
    </div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Professional</th><th>Trade</th><th>City</th>
                        <th class="text-right">Rating</th><th class="text-right">Bookings</th>
                        <th class="text-right">Completed</th><th class="text-right">Revenue</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byProvider as $row): ?>
                    <tr>
                        <td class="table__primary"><?= e($row['full_name']) ?></td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= e($row['city'] ?: '--') ?></td>
                        <td class="text-right">
                            <?= number_format((float) $row['avg_rating'], 2) ?>
                            <span class="text-small text-muted">(<?= (int) $row['total_reviews'] ?>)</span>
                        </td>
                        <td class="text-right"><?= (int) $row['bookings'] ?></td>
                        <td class="text-right"><?= (int) $row['completed'] ?></td>
                        <td class="text-right table__primary"><?= e(money($row['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ==================== 4. MONTHLY TREND ========================= -->
<section class="card">
    <div class="card__head">
        <h3>4. Monthly trend</h3>
        <a class="btn btn--outline btn--sm no-print"
           href="reports.php?<?= e($rangeQuery) ?>&amp;export=monthly">Export CSV</a>
    </div>
    <div class="card__body">
        <?php if (!$monthly): ?>
            <p class="text-small text-muted">No bookings fall inside this period.</p>
        <?php else: ?>
            <?php foreach ($monthly as $m): ?>
                <?php $pct = $peakRevenue > 0 ? round((float) $m['revenue'] / $peakRevenue * 100) : 0; ?>
                <div class="u-mb-4">
                    <div class="meter-label">
                        <span class="table__primary"><?= e($m['label']) ?></span>
                        <span>
                            <?= e(money($m['revenue'])) ?>
                            <span class="text-muted">
                                &middot; <?= (int) $m['bookings'] ?> bookings,
                                <?= (int) $m['completed'] ?> completed
                            </span>
                        </span>
                    </div>
                    <div class="meter" style="height:10px;margin:0">
                        <div class="meter__fill" style="width:<?= $pct ?>%;background:var(--blue-600)"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ==================== 5. MAINTENANCE CONTRACTS ================= -->
<section class="card">
    <div class="card__head">
        <h3>5. Maintenance contracts</h3>
        <a class="btn btn--outline btn--sm no-print"
           href="reports.php?<?= e($rangeQuery) ?>&amp;export=plan">Export CSV</a>
    </div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Plan</th><th>Trade</th><th>Frequency</th>
                        <th class="text-right">Contracts</th><th class="text-right">Active</th>
                        <th class="text-right">Visits done</th><th class="text-right">Revenue</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byPlan as $row): ?>
                    <tr>
                        <td class="table__primary"><?= e($row['plan_name']) ?></td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= e(frequency_label($row['frequency'])) ?></td>
                        <td class="text-right"><?= (int) $row['contracts'] ?></td>
                        <td class="text-right"><?= (int) $row['active'] ?></td>
                        <td class="text-right">
                            <?= (int) $row['visits_done'] ?> / <?= (int) $row['visits_total'] ?>
                        </td>
                        <td class="text-right table__primary"><?= e(money($row['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<p class="text-small text-muted text-center u-mt-6">
    Generated <?= e(date(DATETIME_FORMAT)) ?> by <?= e(current_name()) ?>
    &middot; <?= e(APP_NAME) ?> &middot; <?= e(COURSE_CODE) ?>
</p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
