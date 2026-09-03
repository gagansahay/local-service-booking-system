<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/providers.php
 *  MODULE  : 2 -- Admin
 *  PURPOSE : Verify, reject and suspend service professionals.
 *
 *  Verification is the trust mechanism of the whole platform. Until an
 *  administrator approves a professional, they do not appear in any
 *  customer search and cannot be booked -- the search query filters on
 *  verification_status = 'verified' in every place it runs.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* =====================================================================
 * VERIFY / REJECT
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'set_verification') {

    csrf_guard();

    $providerId = post_int('provider_id');
    $decision   = post('decision');

    if (!in_array($decision, ['verified', 'rejected', 'pending'], true)) {
        flash('error', 'That is not a valid verification decision.');
        redirect('admin/providers.php');
    }

    $stmt = $pdo->prepare(
        'SELECT p.provider_id, u.user_id, u.full_name
           FROM providers p JOIN users u ON u.user_id = p.user_id
          WHERE p.provider_id = :pid'
    );
    $stmt->execute([':pid' => $providerId]);
    $provider = $stmt->fetch();

    if (!$provider) {
        flash('error', 'That professional was not found.');
        redirect('admin/providers.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE providers
                SET verification_status = :status,
                    verified_by = :admin,
                    verified_at = :at
              WHERE provider_id = :pid'
        );
        $stmt->execute([
            ':status' => $decision,
            ':admin'  => $decision === 'pending' ? null : current_user_id(),
            ':at'     => $decision === 'pending' ? null : date('Y-m-d H:i:s'),
            ':pid'    => $providerId,
        ]);

        $messages = [
            'verified' => ['Your profile is verified',
                           'You now appear in customer searches and can receive bookings.'],
            'rejected' => ['Your profile was not approved',
                           'Please contact the administrator to find out what needs correcting.'],
            'pending'  => ['Your profile is under review again',
                           'An administrator has reopened your verification.'],
        ];
        [$title, $message] = $messages[$decision];

        notify($pdo, (int) $provider['user_id'], $title, $message, 'provider/profile.php', 'shield');

        $pdo->commit();

        log_activity('provider_' . $decision, 'providers', $providerId, $provider['full_name']);
        flash('success', $provider['full_name'] . ' has been marked ' . $decision . '.');

    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', APP_DEBUG
            ? 'Database error: ' . $exception->getMessage()
            : 'The decision could not be saved. Please try again.');
    }

    redirect('admin/providers.php?status=' . urlencode(get('status', 'all')));
}

/* =====================================================================
 * SUSPEND / REINSTATE THE ACCOUNT
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle_account') {

    csrf_guard();

    $userId = post_int('user_id');

    // An administrator must not be able to suspend their own account and
    // lock themselves out of the system.
    if ($userId === current_user_id()) {
        flash('error', 'You cannot suspend your own account.');
        redirect('admin/providers.php');
    }

    $stmt = $pdo->prepare(
        "UPDATE users
            SET status = IF(status = 'active', 'suspended', 'active')
          WHERE user_id = :uid AND role = 'provider'"
    );
    $stmt->execute([':uid' => $userId]);

    log_activity('account_toggled', 'users', $userId);
    flash('success', 'The account status has been changed.');
    redirect('admin/providers.php?status=' . urlencode(get('status', 'all')));
}

/* =====================================================================
 * LISTING
 * ===================================================================*/
$filter  = get('status', 'all');
$keyword = get('q');

$tabs = [
    'all'      => 'All',
    'pending'  => 'Pending',
    'verified' => 'Verified',
    'rejected' => 'Rejected',
];
if (!isset($tabs[$filter])) {
    $filter = 'all';
}

$where  = [];
$params = [];

if ($filter !== 'all') {
    $where[] = 'p.verification_status = :status';
    $params[':status'] = $filter;
}
if ($keyword !== '') {
    $where[] = '(u.full_name LIKE :kw1 OR u.email LIKE :kw2 OR u.city LIKE :kw3)';
    $like = '%' . $keyword . '%';
    $params[':kw1'] = $like;
    $params[':kw2'] = $like;
    $params[':kw3'] = $like;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$counts = [];
foreach ($pdo->query(
    'SELECT verification_status, COUNT(*) AS n FROM providers GROUP BY verification_status'
)->fetchAll() as $row) {
    $counts[$row['verification_status']] = (int) $row['n'];
}
$counts['all'] = array_sum($counts);

$sql = "SELECT p.*, u.user_id, u.full_name, u.email, u.phone, u.city, u.pincode,
               u.status AS account_status, u.created_at, u.address,
               c.category_name,
               (SELECT COUNT(*) FROM bookings b WHERE b.provider_id = p.provider_id) AS booking_count,
               (SELECT COUNT(*) FROM services s WHERE s.provider_id = p.provider_id) AS service_count,
               av.full_name AS verified_by_name
          FROM providers  p
          JOIN users      u ON u.user_id     = p.user_id
          JOIN categories c ON c.category_id = p.category_id
          LEFT JOIN users av ON av.user_id   = p.verified_by
          $whereSql
         ORDER BY FIELD(p.verification_status, 'pending', 'verified', 'rejected'), u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$providers = $stmt->fetchAll();

$pageTitle   = 'Professionals';
$pageHeading = 'Service professionals';
$pageLede    = 'Verify new registrations and manage existing accounts.';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== FILTERS ================================== -->
<div class="toolbar">
    <?= filter_tabs('providers.php', $tabs, $filter, $counts, 'btn-row') ?>

    <form method="get" action="providers.php" class="toolbar__end">
        <input type="hidden" name="status" value="<?= e($filter) ?>">
        <input class="input" type="search" name="q" value="<?= e($keyword) ?>"
               placeholder="Name, email or city" style="max-width:240px">
        <button class="btn btn--outline btn--sm" type="submit">Search</button>
    </form>
</div>

<!-- ==================== LIST ===================================== -->
<?php if (!$providers): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#9873;</div>
            <h3>No professionals match</h3>
            <p>Try a different status tab, or clear the search.</p>
            <a class="btn btn--primary" href="providers.php">Show all</a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($providers as $p): ?>
        <section class="card" id="p<?= (int) $p['provider_id'] ?>">
            <div class="card__body">

                <div class="contract-head">
                    <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($p['full_name'])) ?></span>
                    <div class="contract-head__plan">
                        <h3 style="margin-bottom:2px"><?= e($p['full_name']) ?></h3>
                        <p class="text-small text-muted u-m0">
                            <?= e($p['category_name']) ?> &middot; <?= e($p['email']) ?>
                            &middot; <span class="ref"><?= e($p['phone']) ?></span>
                        </p>
                        <div class="u-mt-2">
                            <?= star_rating((float) $p['avg_rating'], (int) $p['total_reviews']) ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <?= status_badge($p['verification_status']) ?>
                        <?php if ($p['account_status'] === 'suspended'): ?>
                            <div class="u-mt-1"><?= status_badge('suspended') ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Experience</dt><dd><?= (int) $p['experience_years'] ?> years</dd></div>
                    <div><dt>Hourly rate</dt><dd><?= e(money($p['hourly_rate'])) ?></dd></div>
                    <div><dt>Services listed</dt><dd><?= (int) $p['service_count'] ?></dd></div>
                    <div><dt>Bookings</dt><dd><?= (int) $p['booking_count'] ?></dd></div>
                    <div><dt>Jobs done</dt><dd><?= (int) $p['total_jobs'] ?></dd></div>
                    <div><dt>City</dt><dd><?= e($p['city'] ?: 'Not set') ?></dd></div>
                    <div><dt>Registered</dt><dd><?= e(show_date($p['created_at'])) ?></dd></div>
                    <?php if ($p['verified_by_name']): ?>
                        <div><dt>Verified by</dt><dd><?= e($p['verified_by_name']) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if (!empty($p['skills'])): ?>
                    <p class="text-small" style="margin:0 0 var(--sp-2)">
                        <strong>Skills:</strong> <?= e($p['skills']) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($p['service_area'])): ?>
                    <p class="text-small" style="margin:0 0 var(--sp-2)">
                        <strong>Serves:</strong> <?= e($p['service_area']) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($p['bio'])): ?>
                    <p class="text-small text-muted u-m0 u-mb-3"><?= e($p['bio']) ?></p>
                <?php endif; ?>

                <!-- Decisions ---------------------------------------- -->
                <div class="jobcard__foot">
                    <?php if ($p['verification_status'] === 'pending'): ?>
                        <span class="text-small text-muted">
                            Approving this profile makes it visible to customers immediately.
                        </span>
                    <?php endif; ?>

                    <div class="btn-row u-ml-auto">
                        <?php if ($p['verification_status'] !== 'verified'): ?>
                            <form method="post" action="providers.php?status=<?= e($filter) ?>" class="u-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_verification">
                                <input type="hidden" name="provider_id" value="<?= (int) $p['provider_id'] ?>">
                                <input type="hidden" name="decision" value="verified">
                                <button class="btn btn--success btn--sm" type="submit"
                                        data-confirm="Verify <?= e($p['full_name']) ?>? They will appear in customer searches.">
                                    Verify
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($p['verification_status'] !== 'rejected'): ?>
                            <form method="post" action="providers.php?status=<?= e($filter) ?>" class="u-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_verification">
                                <input type="hidden" name="provider_id" value="<?= (int) $p['provider_id'] ?>">
                                <input type="hidden" name="decision" value="rejected">
                                <button class="btn btn--outline btn--sm" type="submit"
                                        data-confirm="Reject <?= e($p['full_name']) ?>?">
                                    Reject
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="providers.php?status=<?= e($filter) ?>" class="u-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_account">
                            <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                            <button class="btn btn--ghost btn--sm" type="submit"
                                    data-confirm="<?= $p['account_status'] === 'active'
                                        ? 'Suspend this account? They will not be able to sign in.'
                                        : 'Reinstate this account?' ?>">
                                <?= $p['account_status'] === 'active' ? 'Suspend account' : 'Reinstate' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
