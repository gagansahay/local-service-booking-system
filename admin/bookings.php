<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/bookings.php
 *  MODULE  : 2 -- Admin  (with Module 5, Booking Management)
 *  PURPOSE : Every booking in the system, for audit and dispute
 *            resolution, with the administrator's override controls.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

require_once __DIR__ . '/../includes/booking-actions.php';   // handles the POST

$pdo = db();

/* -- Filters ---------------------------------------------------------- */
$filter     = get('status', 'all');
$categoryId = get_int('category');
$keyword    = get('q');
$fromDate   = get('from');
$toDate     = get('to');

$tabs = [
    'all'         => 'All',
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
    'rejected'    => 'Rejected',
];
if (!isset($tabs[$filter])) {
    $filter = 'all';
}

$where  = [];
$params = [];

if ($filter !== 'all') {
    $where[] = 'b.status = :status';
    $params[':status'] = $filter;
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = :cid';
    $params[':cid'] = $categoryId;
}
if ($keyword !== '') {
    $where[] = '(b.booking_code LIKE :kw1 OR cu.full_name LIKE :kw2 OR pu.full_name LIKE :kw3)';
    $like = '%' . $keyword . '%';
    $params[':kw1'] = $like;
    $params[':kw2'] = $like;
    $params[':kw3'] = $like;
}
if (valid_date($fromDate)) {
    $where[] = 'b.booking_date >= :from';
    $params[':from'] = $fromDate;
}
if (valid_date($toDate)) {
    $where[] = 'b.booking_date <= :to';
    $params[':to'] = $toDate;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$counts = ['all' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status')->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
    $counts['all'] += (int) $row['n'];
}

$baseSql = "FROM bookings   b
            JOIN users      cu ON cu.user_id    = b.user_id
            JOIN providers  p  ON p.provider_id = b.provider_id
            JOIN users      pu ON pu.user_id    = p.user_id
            JOIN categories c  ON c.category_id = p.category_id
            LEFT JOIN services s   ON s.service_id  = b.service_id
            LEFT JOIN payments pay ON pay.booking_id = b.booking_id
            $whereSql";

$window     = page_window($pdo, "SELECT COUNT(*) $baseSql", $params);
$page       = $window['page'];
$totalPages = $window['total_pages'];
$totalRows  = $window['total_rows'];

$stmt = $pdo->prepare(
    "SELECT b.*, cu.full_name AS customer_name, cu.phone AS customer_phone,
            pu.full_name AS provider_name, c.category_name, s.service_name,
            pay.invoice_no, pay.payment_status
     $baseSql
     ORDER BY b.booking_date DESC, b.booking_time DESC
     " . $window['limit_sql']
);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

/* -- Totals for the visible filter ------------------------------------ */
$sumStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.final_cost
                              ELSE b.estimated_cost END), 0) AS value $baseSql"
);
$sumStmt->execute($params);
$filteredValue = (float) $sumStmt->fetchColumn();

$categories = $pdo->query(
    'SELECT category_id, category_name FROM categories ORDER BY category_name'
)->fetchAll();

$queryParts = array_filter([
    'status'   => $filter !== 'all' ? $filter : null,
    'category' => $categoryId > 0 ? $categoryId : null,
    'q'        => $keyword !== '' ? $keyword : null,
    'from'     => valid_date($fromDate) ? $fromDate : null,
    'to'       => valid_date($toDate)   ? $toDate   : null,
], static fn($v) => $v !== null);
$baseQuery = http_build_query($queryParts);

$pageTitle   = 'All bookings';
$pageHeading = 'All bookings';
$pageLede    = $totalRows . ' booking' . ($totalRows === 1 ? '' : 's') . ' match, worth ' . money($filteredValue) . '.';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== FILTERS ================================== -->
<form class="filters" method="get" action="bookings.php">
    <input type="hidden" name="status" value="<?= e($filter) ?>">
    <div class="filters__row">
        <div>
            <label class="label" for="q">Search</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($keyword) ?>"
                   placeholder="Code, customer or professional">
        </div>
        <div>
            <label class="label" for="category">Trade</label>
            <select class="select" id="category" name="category">
                <option value="">All trades</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>"
                        <?= $categoryId === (int) $c['category_id'] ? 'selected' : '' ?>>
                        <?= e($c['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="label" for="from">From</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($fromDate) ?>">
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($toDate) ?>">
        </div>
        <div class="btn-row">
            <button class="btn btn--primary" type="submit">Apply</button>
            <?php if ($baseQuery !== ''): ?>
                <a class="btn btn--ghost" href="bookings.php">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="btn-row u-mb-5">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="btn <?= $filter === $key ? 'btn--primary' : 'btn--outline' ?> btn--sm"
           href="bookings.php?status=<?= e($key) ?>">
            <?= e($label) ?>
            <?php if (!empty($counts[$key])): ?>
                <span class="ref" style="background:none;padding:0 0 0 4px"><?= (int) $counts[$key] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==================== TABLE ==================================== -->
<?php if (!$bookings): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128203;</div>
            <h3>No bookings match</h3>
            <p>Try widening the date range, or clearing the filters.</p>
            <a class="btn btn--primary" href="bookings.php">Show all bookings</a>
        </div>
    </div>
<?php else: ?>
    <section class="card">
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking</th><th>Customer</th><th>Professional</th>
                            <th>Scheduled</th><th>Status</th>
                            <th class="text-right">Value</th><th>Payment</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td>
                                <span class="ref"><?= e($b['booking_code']) ?></span>
                                <?php if ($b['is_maintenance']): ?>
                                    <br><span class="badge badge--scheduled">AMC</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="table__primary"><?= e($b['customer_name']) ?></span><br>
                                <span class="text-small text-muted"><?= e($b['customer_phone']) ?></span>
                            </td>
                            <td>
                                <span class="table__primary"><?= e($b['provider_name']) ?></span><br>
                                <span class="text-small text-muted"><?= e($b['category_name']) ?></span>
                            </td>
                            <td class="text-small">
                                <?= e(show_date($b['booking_date'])) ?><br>
                                <span class="text-muted"><?= e(show_time($b['booking_time'])) ?></span>
                            </td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td class="text-right table__primary">
                                <?= e(money($b['final_cost'] ?? $b['estimated_cost'])) ?>
                            </td>
                            <td>
                                <?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted">--</span>' ?>
                            </td>
                            <td class="text-right">
                                <?php
                                // Only offer overrides the workflow actually permits.
                                $allowed = status_transitions()['admin'][$b['status']] ?? [];
                                ?>
                                <?php if ($allowed): ?>
                                    <details>
                                        <summary class="btn btn--outline btn--sm" style="cursor:pointer;list-style:none">
                                            Override
                                        </summary>
                                        <form method="post" action="bookings.php?<?= e($baseQuery) ?>"
                                              style="margin-top:var(--sp-2);text-align:left;min-width:230px">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action"     value="set_status">
                                            <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
                                            <input type="hidden" name="return_to"
                                                   value="admin/bookings.php?<?= e($baseQuery) ?>">

                                            <label class="label" for="ns<?= (int) $b['booking_id'] ?>">Move to</label>
                                            <select class="select" name="new_status" id="ns<?= (int) $b['booking_id'] ?>">
                                                <?php foreach ($allowed as $to): ?>
                                                    <option value="<?= e($to) ?>">
                                                        <?= e(ucfirst(str_replace('_', ' ', $to))) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <?php if (in_array('completed', $allowed, true)): ?>
                                                <label class="label u-mt-2"
                                                       for="fc<?= (int) $b['booking_id'] ?>">
                                                    Final amount (if completing)
                                                </label>
                                                <input class="input" type="number" step="0.01" min="0"
                                                       id="fc<?= (int) $b['booking_id'] ?>" name="final_cost"
                                                       value="<?= e(number_format((float) $b['estimated_cost'], 2, '.', '')) ?>">
                                            <?php endif; ?>

                                            <label class="label u-mt-2"
                                                   for="rm<?= (int) $b['booking_id'] ?>">Reason</label>
                                            <input class="input" type="text" name="remarks"
                                                   id="rm<?= (int) $b['booking_id'] ?>"
                                                   placeholder="Administrative override">

                                            <button class="btn btn--accent btn--sm btn--block u-mt-3" type="submit"
                                                    data-confirm="Override the status of <?= e($b['booking_code']) ?>?">
                                                Apply override
                                            </button>
                                        </form>
                                    </details>
                                <?php else: ?>
                                    <span class="text-small text-muted">Final</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= paginate($page, $totalPages, $baseQuery) ?>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
