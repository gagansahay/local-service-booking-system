<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/plans.php
 *  MODULE  : 6 -- Maintenance (AMC), administered by Module 2
 *  PURPOSE : Create and maintain the Annual Maintenance Contract plans
 *            customers can subscribe to, and monitor live contracts.
 *
 *  Changing a plan's price or frequency affects only FUTURE
 *  subscriptions. Existing contracts carry their own total_visits and
 *  amount_paid, so a customer's agreed terms cannot be altered under
 *  them after the fact.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo     = db();
$errors  = [];
$editing = null;

$frequencies = [
    'monthly'     => ['Every month',    12],
    'quarterly'   => ['Every 3 months',  4],
    'half_yearly' => ['Every 6 months',  2],
    'yearly'      => ['Once a year',     1],
];

/* =====================================================================
 * CREATE / UPDATE A PLAN
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['create', 'update'], true)) {

    csrf_guard();

    $planId    = post_int('plan_id');
    $name      = post('plan_name');
    $desc      = post('description');
    $catId     = post_int('category_id');
    $frequency = post('frequency');
    $visits    = post_int('visits_per_year');
    $price     = post('price');
    $months    = post_int('duration_months', 12);

    if ($name === '' || mb_strlen($name) < 3) {
        $errors['plan_name'] = 'Give the plan a name of at least 3 characters.';
    }
    if (!isset($frequencies[$frequency])) {
        $errors['frequency'] = 'Choose how often the visits happen.';
    }
    if ($visits < 1 || $visits > 12) {
        $errors['visits_per_year'] = 'Visits per year must be between 1 and 12.';
    }
    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        $errors['price'] = 'Enter a price of zero or more.';
    }
    if ($months < 1 || $months > 36) {
        $errors['duration_months'] = 'Term must be between 1 and 36 months.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE category_id = :cid AND is_active = 1');
    $stmt->execute([':cid' => $catId]);
    if ((int) $stmt->fetchColumn() === 0) {
        $errors['category_id'] = 'Choose an active category.';
    }

    if (!$errors) {
        if (post('action') === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO maintenance_plans
                    (category_id, plan_name, description, frequency, visits_per_year, price, duration_months)
                 VALUES (:cid, :name, :desc, :freq, :visits, :price, :months)'
            );
            $stmt->execute([
                ':cid'    => $catId,
                ':name'   => $name,
                ':desc'   => $desc ?: null,
                ':freq'   => $frequency,
                ':visits' => $visits,
                ':price'  => (float) $price,
                ':months' => $months,
            ]);
            log_activity('plan_created', 'maintenance_plans', (int) $pdo->lastInsertId(), $name);
            flash('success', 'The "' . $name . '" plan is now on sale.');

        } else {
            $stmt = $pdo->prepare(
                'UPDATE maintenance_plans
                    SET category_id = :cid, plan_name = :name, description = :desc,
                        frequency = :freq, visits_per_year = :visits,
                        price = :price, duration_months = :months
                  WHERE plan_id = :pid'
            );
            $stmt->execute([
                ':cid'    => $catId,
                ':name'   => $name,
                ':desc'   => $desc ?: null,
                ':freq'   => $frequency,
                ':visits' => $visits,
                ':price'  => (float) $price,
                ':months' => $months,
                ':pid'    => $planId,
            ]);
            log_activity('plan_updated', 'maintenance_plans', $planId, $name);
            flash('success', 'The "' . $name . '" plan has been updated. '
                           . 'Existing contracts keep the terms they were sold on.');
        }

        redirect('admin/plans.php');
    }
}

/* =====================================================================
 * TOGGLE ON SALE / DELETE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle') {

    csrf_guard();

    $stmt = $pdo->prepare('UPDATE maintenance_plans SET is_active = 1 - is_active WHERE plan_id = :pid');
    $stmt->execute([':pid' => post_int('plan_id')]);

    flash('success', 'The plan listing has been updated.');
    redirect('admin/plans.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {

    csrf_guard();

    $planId = post_int('plan_id');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM maintenance_contracts WHERE plan_id = :pid');
    $stmt->execute([':pid' => $planId]);

    if ((int) $stmt->fetchColumn() > 0) {
        flash('error', 'Customers hold contracts on this plan, so it cannot be deleted. '
                     . 'Take it off sale instead &mdash; existing contracts continue to run.');
    } else {
        $stmt = $pdo->prepare('DELETE FROM maintenance_plans WHERE plan_id = :pid');
        $stmt->execute([':pid' => $planId]);
        log_activity('plan_deleted', 'maintenance_plans', $planId);
        flash('success', 'The plan has been deleted.');
    }

    redirect('admin/plans.php');
}

/* -- Load one for editing -------------------------------------------- */
if ($editId = get_int('edit')) {
    $stmt = $pdo->prepare('SELECT * FROM maintenance_plans WHERE plan_id = :pid');
    $stmt->execute([':pid' => $editId]);
    $editing = $stmt->fetch() ?: null;
}

/* -- Plans with live subscription counts ------------------------------ */
$plans = $pdo->query(
    "SELECT mp.*, c.category_name, c.icon,
            (SELECT COUNT(*) FROM maintenance_contracts mc WHERE mc.plan_id = mp.plan_id) AS contracts,
            (SELECT COUNT(*) FROM maintenance_contracts mc
              WHERE mc.plan_id = mp.plan_id AND mc.status = 'active')                     AS active_contracts,
            (SELECT COALESCE(SUM(mc.amount_paid), 0) FROM maintenance_contracts mc
              WHERE mc.plan_id = mp.plan_id)                                              AS revenue
       FROM maintenance_plans mp
       JOIN categories c ON c.category_id = mp.category_id
      ORDER BY mp.is_active DESC, mp.price"
)->fetchAll();

/* -- Live contracts across the platform -------------------------------- */
$contracts = $pdo->query(
    "SELECT mc.*, mp.plan_name, cu.full_name AS customer_name, pu.full_name AS provider_name,
            (SELECT COUNT(*) FROM maintenance_visits v
              WHERE v.contract_id = mc.contract_id AND v.status = 'due') AS due_visits
       FROM maintenance_contracts mc
       JOIN maintenance_plans mp ON mp.plan_id     = mc.plan_id
       JOIN users             cu ON cu.user_id     = mc.user_id
       JOIN providers         p  ON p.provider_id  = mc.provider_id
       JOIN users             pu ON pu.user_id     = p.user_id
      ORDER BY FIELD(mc.status, 'active', 'expired', 'cancelled'), mc.next_due_date ASC
      LIMIT 25"
)->fetchAll();

$categories = $pdo->query(
    'SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name'
)->fetchAll();

$pageTitle   = 'Maintenance plans';
$pageHeading = 'Maintenance plans';
$pageLede    = 'The AMC products customers can subscribe to, and the contracts running on them.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--2" style="align-items:start;grid-template-columns:1.5fr 1fr">

    <!-- ---------------- Plans ------------------------------------ -->
    <div>
        <?php foreach ($plans as $p): ?>
            <section class="card" style="<?= $p['is_active'] ? '' : 'opacity:.66' ?>">
                <div class="card__body">
                    <div class="jobcard__top">
                        <div>
                            <h3 style="margin-bottom:2px">
                                <?= e($p['icon']) ?> <?= e($p['plan_name']) ?>
                            </h3>
                            <p class="text-small text-muted" style="margin:0"><?= e($p['category_name']) ?></p>
                        </div>
                        <div class="text-right">
                            <div class="provider-card__rate"><?= e(money($p['price'])) ?></div>
                            <?= $p['is_active']
                                ? '<span class="badge badge--active">On sale</span>'
                                : '<span class="badge badge--expired">Withdrawn</span>' ?>
                        </div>
                    </div>

                    <p class="text-small text-muted"><?= e(excerpt($p['description'], 120)) ?></p>

                    <dl class="jobcard__facts">
                        <div><dt>Frequency</dt><dd><?= e(frequency_label($p['frequency'])) ?></dd></div>
                        <div><dt>Visits</dt><dd><?= (int) $p['visits_per_year'] ?> a year</dd></div>
                        <div><dt>Term</dt><dd><?= (int) $p['duration_months'] ?> months</dd></div>
                        <div><dt>Subscribers</dt><dd><?= (int) $p['active_contracts'] ?> active</dd></div>
                        <div><dt>Revenue</dt><dd><?= e(money($p['revenue'])) ?></dd></div>
                    </dl>

                    <div class="jobcard__foot">
                        <div class="btn-row" style="margin-left:auto">
                            <a class="btn btn--outline btn--sm"
                               href="plans.php?edit=<?= (int) $p['plan_id'] ?>">Edit</a>

                            <form method="post" action="plans.php" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="plan_id" value="<?= (int) $p['plan_id'] ?>">
                                <button class="btn btn--ghost btn--sm" type="submit">
                                    <?= $p['is_active'] ? 'Take off sale' : 'Put on sale' ?>
                                </button>
                            </form>

                            <?php if ((int) $p['contracts'] === 0): ?>
                                <form method="post" action="plans.php" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="plan_id" value="<?= (int) $p['plan_id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"
                                            data-confirm="Delete &quot;<?= e($p['plan_name']) ?>&quot; permanently?">
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <!-- ---------------- Add / edit form --------------------------- -->
    <form class="card" method="post" action="plans.php" data-validate-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="plan_id" value="<?= (int) $editing['plan_id'] ?>">
        <?php endif; ?>

        <div class="card__head">
            <h3><?= $editing ? 'Edit plan' : 'Create a plan' ?></h3>
            <?php if ($editing): ?>
                <a class="btn btn--ghost btn--sm" href="plans.php">Cancel</a>
            <?php endif; ?>
        </div>

        <div class="card__body">
            <div class="field">
                <label class="label" for="plan_name">Plan name <span class="req">*</span></label>
                <input class="input<?= isset($errors['plan_name']) ? ' is-invalid' : '' ?>"
                       type="text" id="plan_name" name="plan_name"
                       value="<?= e(post('plan_name') ?: ($editing['plan_name'] ?? '')) ?>"
                       placeholder="AC Quarterly Care" data-validate="required">
                <?php if (isset($errors['plan_name'])): ?><div class="error"><?= e($errors['plan_name']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="category_id">Trade <span class="req">*</span></label>
                <select class="select<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>"
                        id="category_id" name="category_id">
                    <option value="">Select a trade</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['category_id'] ?>"
                            <?= (string) ($editing['category_id'] ?? post('category_id')) === (string) $c['category_id'] ? 'selected' : '' ?>>
                            <?= e($c['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['category_id'])): ?>
                    <div class="error"><?= e($errors['category_id']) ?></div>
                <?php else: ?>
                    <div class="hint">Only professionals in this trade can service the plan.</div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="description">What it covers</label>
                <textarea class="textarea" id="description" name="description"
                          placeholder="Filter clean, coil wash, gas pressure check and a written report."><?= e(post('description') ?: ($editing['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="label" for="frequency">Frequency <span class="req">*</span></label>
                    <select class="select<?= isset($errors['frequency']) ? ' is-invalid' : '' ?>"
                            id="frequency" name="frequency">
                        <?php foreach ($frequencies as $key => [$label, $defaultVisits]): ?>
                            <option value="<?= e($key) ?>" data-visits="<?= $defaultVisits ?>"
                                <?= ($editing['frequency'] ?? post('frequency')) === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['frequency'])): ?><div class="error"><?= e($errors['frequency']) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="visits_per_year">Visits a year <span class="req">*</span></label>
                    <input class="input<?= isset($errors['visits_per_year']) ? ' is-invalid' : '' ?>"
                           type="number" min="1" max="12" id="visits_per_year" name="visits_per_year"
                           value="<?= e(post('visits_per_year') ?: ($editing['visits_per_year'] ?? '4')) ?>">
                    <?php if (isset($errors['visits_per_year'])): ?>
                        <div class="error"><?= e($errors['visits_per_year']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="price">Price (<?= e(CURRENCY) ?>) <span class="req">*</span></label>
                    <input class="input<?= isset($errors['price']) ? ' is-invalid' : '' ?>"
                           type="number" min="0" step="100" id="price" name="price"
                           value="<?= e(post('price') ?: ($editing['price'] ?? '')) ?>"
                           data-validate="required|positive">
                    <?php if (isset($errors['price'])): ?><div class="error"><?= e($errors['price']) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="duration_months">Term (months) <span class="req">*</span></label>
                    <input class="input<?= isset($errors['duration_months']) ? ' is-invalid' : '' ?>"
                           type="number" min="1" max="36" id="duration_months" name="duration_months"
                           value="<?= e(post('duration_months') ?: ($editing['duration_months'] ?? '12')) ?>">
                    <?php if (isset($errors['duration_months'])): ?>
                        <div class="error"><?= e($errors['duration_months']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card__foot">
            <button class="btn btn--accent btn--block" type="submit">
                <?= $editing ? 'Save changes' : 'Create plan' ?>
            </button>
        </div>
    </form>
</div>

<!-- ==================== LIVE CONTRACTS =========================== -->
<section class="card">
    <div class="card__head">
        <h3>Contracts across the platform</h3>
        <input class="input" type="search" style="max-width:240px"
               data-filter-table="contractTable" placeholder="Search contracts">
    </div>
    <div class="card__body card__body--flush">
        <?php if (!$contracts): ?>
            <div class="empty" style="padding:var(--sp-10) 0">
                <div class="empty__icon" aria-hidden="true">&#128467;</div>
                <h3>No contracts yet</h3>
                <p>Once a customer subscribes to a plan, their contract will be listed here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="contractTable">
                    <thead>
                        <tr>
                            <th>Contract</th><th>Plan</th><th>Customer</th><th>Professional</th>
                            <th>Progress</th><th>Next due</th><th>Status</th><th class="text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contracts as $c): ?>
                        <tr>
                            <td><span class="ref"><?= e($c['contract_code']) ?></span></td>
                            <td class="table__primary"><?= e($c['plan_name']) ?></td>
                            <td><?= e($c['customer_name']) ?></td>
                            <td><?= e($c['provider_name']) ?></td>
                            <td>
                                <?= (int) $c['visits_used'] ?> / <?= (int) $c['total_visits'] ?>
                                <div class="meter" style="height:6px;margin-top:4px;width:70px">
                                    <div class="meter__fill"
                                         style="width:<?= (int) $c['total_visits'] > 0
                                             ? round((int) $c['visits_used'] / (int) $c['total_visits'] * 100) : 0 ?>%;
                                                background:var(--green-600)"></div>
                                </div>
                            </td>
                            <td class="text-small">
                                <?= $c['next_due_date'] ? e(show_date($c['next_due_date'])) : '--' ?>
                                <?php if ((int) $c['due_visits'] > 0): ?>
                                    <br><span class="badge badge--due">Due now</span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge($c['status']) ?></td>
                            <td class="text-right table__primary"><?= e(money($c['amount_paid'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
/* Suggest the matching visit count when the frequency changes. The
   administrator can still override it -- a plan might sell 3 visits on
   a quarterly cycle. */
(function () {
    var frequency = document.getElementById('frequency');
    var visits    = document.getElementById('visits_per_year');
    if (!frequency || !visits) return;

    frequency.addEventListener('change', function () {
        var option = frequency.options[frequency.selectedIndex];
        visits.value = option.getAttribute('data-visits') || visits.value;
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
