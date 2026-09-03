<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/users.php
 *  MODULE  : 2 -- Admin
 *  PURPOSE : View registered customers and suspend or reinstate them.
 *
 *  Accounts are SUSPENDED rather than deleted. Deleting a user would
 *  cascade through their bookings, invoices and reviews (the foreign
 *  keys are ON DELETE CASCADE), destroying the history other people's
 *  ratings and the platform's reports depend on.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* =====================================================================
 * SUSPEND / REINSTATE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle_account') {

    csrf_guard();

    $userId = post_int('user_id');

    if ($userId === current_user_id()) {
        flash('error', 'You cannot suspend your own account.');
        redirect('admin/users.php');
    }

    // Restricted to role = 'customer' so this screen can never be used
    // to disable another administrator.
    $stmt = $pdo->prepare(
        "SELECT full_name, status FROM users WHERE user_id = :uid AND role = 'customer'"
    );
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('error', 'That customer was not found.');
    } else {
        $newStatus = $user['status'] === 'active' ? 'suspended' : 'active';

        $stmt = $pdo->prepare(
            "UPDATE users SET status = :status WHERE user_id = :uid AND role = 'customer'"
        );
        $stmt->execute([':status' => $newStatus, ':uid' => $userId]);

        if ($newStatus === 'suspended') {
            notify($pdo, $userId, 'Your account has been suspended',
                   'Please contact the administrator for assistance.', null, 'shield');
        }

        log_activity('account_' . $newStatus, 'users', $userId, $user['full_name']);
        flash('success', $user['full_name'] . ' has been ' . $newStatus . '.');
    }

    redirect('admin/users.php?status=' . urlencode(get('status', 'all')));
}

/* =====================================================================
 * LISTING
 * ===================================================================*/
$filter  = get('status', 'all');
$keyword = get('q');

$tabs = ['all' => 'All', 'active' => 'Active', 'suspended' => 'Suspended'];
if (!isset($tabs[$filter])) {
    $filter = 'all';
}

$where  = ["u.role = 'customer'"];
$params = [];

if ($filter !== 'all') {
    $where[] = 'u.status = :status';
    $params[':status'] = $filter;
}
if ($keyword !== '') {
    $where[] = '(u.full_name LIKE :kw1 OR u.email LIKE :kw2 OR u.phone LIKE :kw3 OR u.city LIKE :kw4)';
    $like = '%' . $keyword . '%';
    $params[':kw1'] = $like;
    $params[':kw2'] = $like;
    $params[':kw3'] = $like;
    $params[':kw4'] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$counts = ['all' => 0];
foreach ($pdo->query(
    "SELECT status, COUNT(*) AS n FROM users WHERE role = 'customer' GROUP BY status"
)->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
    $counts['all'] += (int) $row['n'];
}

$window     = page_window($pdo, "SELECT COUNT(*) FROM users u $whereSql", $params);
$page       = $window['page'];
$totalPages = $window['total_pages'];
$totalRows  = $window['total_rows'];

$sql = "SELECT u.*,
               (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.user_id) AS bookings,
               (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.user_id
                  AND b.status = 'completed')                                AS completed,
               (SELECT COALESCE(SUM(b.final_cost), 0) FROM bookings b
                 WHERE b.user_id = u.user_id AND b.status = 'completed')     AS spent,
               (SELECT COUNT(*) FROM maintenance_contracts mc
                 WHERE mc.user_id = u.user_id AND mc.status = 'active')      AS contracts,
               (SELECT COUNT(*) FROM feedback f WHERE f.user_id = u.user_id) AS reviews
          FROM users u
          $whereSql
         ORDER BY u.created_at DESC
         " . $window['limit_sql'];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle   = 'Customers';
$pageHeading = 'Registered customers';
$pageLede    = $totalRows . ' customer account' . ($totalRows === 1 ? '' : 's') . ' on the platform.';

include __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <?= filter_tabs('users.php', $tabs, $filter, $counts, 'btn-row') ?>

    <form method="get" action="users.php" class="toolbar__end">
        <input type="hidden" name="status" value="<?= e($filter) ?>">
        <input class="input" type="search" name="q" value="<?= e($keyword) ?>"
               placeholder="Name, email, phone or city" style="max-width:260px">
        <button class="btn btn--outline btn--sm" type="submit">Search</button>
    </form>
</div>

<?php if (!$customers): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#9787;</div>
            <h3>No customers match</h3>
            <p>Try a different tab, or clear the search.</p>
            <a class="btn btn--primary" href="users.php">Show all</a>
        </div>
    </div>
<?php else: ?>
    <section class="card">
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th><th>Contact</th><th>Location</th>
                            <th class="text-right">Bookings</th><th class="text-right">Spent</th>
                            <th>Joined</th><th>Status</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td>
                                <div class="person">
                                    <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($c['full_name'])) ?></span>
                                    <div>
                                        <div class="person__name"><?= e($c['full_name']) ?></div>
                                        <div class="person__meta">
                                            <?= (int) $c['reviews'] ?> review<?= (int) $c['reviews'] === 1 ? '' : 's' ?>
                                            <?php if ((int) $c['contracts'] > 0): ?>
                                                &middot; <?= (int) $c['contracts'] ?> AMC
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-small">
                                <?= e($c['email']) ?><br>
                                <span class="ref"><?= e($c['phone']) ?></span>
                            </td>
                            <td class="text-small">
                                <?= e($c['city'] ?: '--') ?>
                                <?= $c['pincode'] ? '<br><span class="text-muted">' . e($c['pincode']) . '</span>' : '' ?>
                            </td>
                            <td class="text-right">
                                <?= (int) $c['bookings'] ?>
                                <br><span class="text-small text-muted"><?= (int) $c['completed'] ?> done</span>
                            </td>
                            <td class="text-right table__primary"><?= e(money($c['spent'])) ?></td>
                            <td class="text-small"><?= e(show_date($c['created_at'])) ?></td>
                            <td><?= status_badge($c['status']) ?></td>
                            <td class="text-right">
                                <form method="post" action="users.php?status=<?= e($filter) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_account">
                                    <input type="hidden" name="user_id" value="<?= (int) $c['user_id'] ?>">
                                    <button class="btn btn--outline btn--sm" type="submit"
                                            data-confirm="<?= $c['status'] === 'active'
                                                ? 'Suspend ' . e($c['full_name']) . '? They will not be able to sign in.'
                                                : 'Reinstate ' . e($c['full_name']) . '?' ?>">
                                        <?= $c['status'] === 'active' ? 'Suspend' : 'Reinstate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= paginate($page, $totalPages, 'status=' . urlencode($filter)) ?>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
