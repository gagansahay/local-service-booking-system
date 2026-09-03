<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/activity-log.php
 *  MODULE  : 2 -- Admin (security auditing)
 *  PURPOSE : The security and audit trail -- sign-ins, failed sign-ins,
 *            rejected CSRF tokens, blocked authorisation attempts and
 *            every administrative action, each with its IP address.
 *
 *  WHY THIS SCREEN EXISTS
 *  ----------------------
 *  A log nobody reads is not a security control. This screen surfaces
 *  the events that matter -- repeated failed sign-ins from one address,
 *  or authorisation denials, are what a break-in attempt looks like
 *  from the inside.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* -- Filters ---------------------------------------------------------- */
$filter   = get('type', 'all');
$keyword  = get('q');
$fromDate = get('from');
$toDate   = get('to');

/* Event groups. Each maps to the set of action strings written by
   log_activity() elsewhere in the application. */
$groups = [
    'all'      => ['All events',      []],
    'security' => ['Security',        ['login_failed', 'login_blocked', 'csrf_rejected',
                                       'authorisation_denied', 'password_change_failed']],
    'auth'     => ['Sign-ins',        ['login_success', 'logout', 'register', 'password_changed']],
    'admin'    => ['Admin actions',   ['provider_verified', 'provider_rejected', 'provider_pending',
                                       'account_toggled', 'account_suspended', 'account_active',
                                       'category_created', 'category_updated', 'category_deleted',
                                       'plan_created', 'plan_updated', 'plan_deleted',
                                       'review_approved', 'review_hidden', 'report_exported']],
    'booking'  => ['Bookings',        ['booking_created', 'booking_cancelled', 'booking_status_change',
                                       'amc_subscribed', 'amc_visit_raised', 'payment_recorded']],
];
if (!isset($groups[$filter])) {
    $filter = 'all';
}

$where  = [];
$params = [];

if ($filter !== 'all' && $groups[$filter][1]) {
    // Build one placeholder per action so the list stays parameterised.
    $actions = $groups[$filter][1];
    $names   = [];
    foreach ($actions as $index => $action) {
        $key = ':a' . $index;
        $names[]      = $key;
        $params[$key] = $action;
    }
    $where[] = 'a.action IN (' . implode(', ', $names) . ')';
}

if ($keyword !== '') {
    $where[] = '(a.action LIKE :kw1 OR a.details LIKE :kw2 OR u.full_name LIKE :kw3 OR a.ip_address LIKE :kw4)';
    $like = '%' . $keyword . '%';
    $params[':kw1'] = $like;
    $params[':kw2'] = $like;
    $params[':kw3'] = $like;
    $params[':kw4'] = $like;
}
if (valid_date($fromDate)) {
    $where[] = 'DATE(a.created_at) >= :from';
    $params[':from'] = $fromDate;
}
if (valid_date($toDate)) {
    $where[] = 'DATE(a.created_at) <= :to';
    $params[':to'] = $toDate;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* -- Security counters ------------------------------------------------ */
$security = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM activity_log WHERE action = 'login_failed')          AS failed_logins,
        (SELECT COUNT(*) FROM activity_log WHERE action = 'csrf_rejected')         AS csrf,
        (SELECT COUNT(*) FROM activity_log WHERE action = 'authorisation_denied')  AS denied,
        (SELECT COUNT(*) FROM activity_log)                                        AS total,
        (SELECT COUNT(DISTINCT ip_address) FROM activity_log)                      AS addresses,
        (SELECT COUNT(*) FROM activity_log
          WHERE action = 'login_failed'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))                   AS failed_24h"
)->fetch();

/* -- Addresses with repeated failures --------------------------------- */
$suspiciousIps = $pdo->query(
    "SELECT ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_seen
       FROM activity_log
      WHERE action IN ('login_failed', 'csrf_rejected', 'authorisation_denied')
      GROUP BY ip_address
     HAVING attempts >= 2
      ORDER BY attempts DESC
      LIMIT 6"
)->fetchAll();

/* -- The log itself, paginated ---------------------------------------- */
$baseSql = "FROM activity_log a LEFT JOIN users u ON u.user_id = a.user_id $whereSql";

$window     = page_window($pdo, "SELECT COUNT(*) $baseSql", $params, 25);
$page       = $window['page'];
$totalPages = $window['total_pages'];
$totalRows  = $window['total_rows'];

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name, u.role
     $baseSql
     ORDER BY a.created_at DESC, a.log_id DESC
     " . $window['limit_sql']
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

/* Which actions should read as a warning rather than routine. */
$alarming = ['login_failed', 'login_blocked', 'csrf_rejected',
             'authorisation_denied', 'password_change_failed'];

$queryParts = array_filter([
    'type' => $filter !== 'all' ? $filter : null,
    'q'    => $keyword !== '' ? $keyword : null,
    'from' => valid_date($fromDate) ? $fromDate : null,
    'to'   => valid_date($toDate)   ? $toDate   : null,
], static fn($v) => $v !== null);
$baseQuery = http_build_query($queryParts);

$pageTitle   = 'Activity log';
$pageHeading = 'Security and activity log';
$pageLede    = $totalRows . ' event' . ($totalRows === 1 ? '' : 's') . ' recorded.';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== SECURITY COUNTERS ======================== -->
<div class="grid grid--4 u-mb-6">
    <div class="stat <?= (int) $security['failed_24h'] > 0 ? 'stat--danger' : '' ?>">
        <div class="stat__label">Failed sign-ins (24h)</div>
        <div class="stat__value"><?= (int) $security['failed_24h'] ?></div>
        <div class="stat__meta"><?= (int) $security['failed_logins'] ?> all time</div>
    </div>
    <div class="stat <?= (int) $security['csrf'] > 0 ? 'stat--danger' : '' ?>">
        <div class="stat__label">CSRF tokens rejected</div>
        <div class="stat__value"><?= (int) $security['csrf'] ?></div>
        <div class="stat__meta">Forged or expired requests</div>
    </div>
    <div class="stat <?= (int) $security['denied'] > 0 ? 'stat--accent' : '' ?>">
        <div class="stat__label">Access denied</div>
        <div class="stat__value"><?= (int) $security['denied'] ?></div>
        <div class="stat__meta">Wrong role for the page</div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Total events</div>
        <div class="stat__value"><?= (int) $security['total'] ?></div>
        <div class="stat__meta">From <?= (int) $security['addresses'] ?> addresses</div>
    </div>
</div>

<?php if ($suspiciousIps): ?>
    <section class="card">
        <div class="card__head">
            <h3>Addresses with repeated failures</h3>
        </div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>IP address</th><th class="text-right">Failed attempts</th><th>Last seen</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($suspiciousIps as $ip): ?>
                        <tr>
                            <td><span class="ref"><?= e($ip['ip_address'] ?: 'unknown') ?></span></td>
                            <td class="text-right">
                                <span class="badge badge--<?= (int) $ip['attempts'] >= 5 ? 'cancelled' : 'pending' ?>">
                                    <?= (int) $ip['attempts'] ?>
                                </span>
                            </td>
                            <td class="text-small text-muted"><?= e(show_datetime($ip['last_seen'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ==================== FILTERS ================================== -->
<form class="filters" method="get" action="activity-log.php">
    <input type="hidden" name="type" value="<?= e($filter) ?>">
    <div class="filters__row">
        <div>
            <label class="label" for="q">Search</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($keyword) ?>"
                   placeholder="Action, user, detail or IP">
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
                <a class="btn btn--ghost" href="activity-log.php">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="btn-row u-mb-5">
    <?php foreach ($groups as $key => [$label, $_]): ?>
        <a class="btn <?= $filter === $key ? 'btn--primary' : 'btn--outline' ?> btn--sm"
           href="activity-log.php?type=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- ==================== THE LOG ================================== -->
<?php if (!$entries): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#9881;</div>
            <h3>No events match</h3>
            <p>Try a different event type, or widen the date range.</p>
            <a class="btn btn--primary" href="activity-log.php">Show all events</a>
        </div>
    </div>
<?php else: ?>
    <section class="card">
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>When</th><th>Event</th><th>User</th>
                            <th>Entity</th><th>Details</th><th>IP address</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php $isAlarming = in_array($entry['action'], $alarming, true); ?>
                        <tr>
                            <td class="text-small" style="white-space:nowrap">
                                <?= e(show_datetime($entry['created_at'])) ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= $isAlarming ? 'cancelled' : 'confirmed' ?>">
                                    <?= e(str_replace('_', ' ', $entry['action'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($entry['full_name']): ?>
                                    <span class="table__primary"><?= e($entry['full_name']) ?></span><br>
                                    <span class="text-small text-muted"><?= e($entry['role']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not signed in</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-small">
                                <?= e($entry['entity'] ?: '--') ?>
                                <?= $entry['entity_id'] ? ' #' . (int) $entry['entity_id'] : '' ?>
                            </td>
                            <td class="text-small text-muted"><?= e($entry['details'] ?: '--') ?></td>
                            <td><span class="ref"><?= e($entry['ip_address'] ?: 'unknown') ?></span></td>
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
