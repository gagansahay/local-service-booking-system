<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/categories.php
 *  MODULE  : 2 -- Admin
 *  PURPOSE : Maintain the master list of service categories.
 *
 *  categories is referenced by providers, services and
 *  maintenance_plans with ON DELETE RESTRICT, so the database itself
 *  refuses to delete a category still in use. The check below produces
 *  a helpful message rather than letting the user meet a raw constraint
 *  violation.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo     = db();
$errors  = [];
$editing = null;

/* =====================================================================
 * CREATE / UPDATE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['create', 'update'], true)) {

    csrf_guard();

    $categoryId = post_int('category_id');
    $name       = post('category_name');
    $desc       = post('description');
    $icon       = post('icon');

    if ($name === '' || mb_strlen($name) < 3) {
        $errors['category_name'] = 'Give the category a name of at least 3 characters.';
    }

    // Friendly duplicate check. The UNIQUE index on category_name is
    // still the real guarantee.
    if (!$errors) {
        $sql = 'SELECT COUNT(*) FROM categories WHERE category_name = :name';
        $params = [':name' => $name];
        if (post('action') === 'update') {
            $sql .= ' AND category_id <> :cid';
            $params[':cid'] = $categoryId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() > 0) {
            $errors['category_name'] = 'A category with that name already exists.';
        }
    }

    if (!$errors) {
        if (post('action') === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO categories (category_name, description, icon)
                 VALUES (:name, :desc, :icon)'
            );
            $stmt->execute([
                ':name' => $name,
                ':desc' => $desc ?: null,
                ':icon' => $icon ?: null,
            ]);
            log_activity('category_created', 'categories', (int) $pdo->lastInsertId(), $name);
            flash('success', 'The "' . $name . '" category has been added.');

        } else {
            $stmt = $pdo->prepare(
                'UPDATE categories SET category_name = :name, description = :desc, icon = :icon
                  WHERE category_id = :cid'
            );
            $stmt->execute([
                ':name' => $name,
                ':desc' => $desc ?: null,
                ':icon' => $icon ?: null,
                ':cid'  => $categoryId,
            ]);
            log_activity('category_updated', 'categories', $categoryId, $name);
            flash('success', 'The "' . $name . '" category has been updated.');
        }

        redirect('admin/categories.php');
    }
}

/* =====================================================================
 * TOGGLE ACTIVE / DELETE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle') {

    csrf_guard();

    $stmt = $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE category_id = :cid');
    $stmt->execute([':cid' => post_int('category_id')]);

    flash('success', 'The category listing has been updated.');
    redirect('admin/categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {

    csrf_guard();

    $categoryId = post_int('category_id');

    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM providers         WHERE category_id = :c1) AS providers,
            (SELECT COUNT(*) FROM services          WHERE category_id = :c2) AS services,
            (SELECT COUNT(*) FROM maintenance_plans WHERE category_id = :c3) AS plans'
    );
    $stmt->execute([':c1' => $categoryId, ':c2' => $categoryId, ':c3' => $categoryId]);
    $usage = $stmt->fetch();

    $inUse = (int) $usage['providers'] + (int) $usage['services'] + (int) $usage['plans'];

    if ($inUse > 0) {
        flash('error', 'This category is used by ' . (int) $usage['providers'] . ' professional(s), '
                     . (int) $usage['services'] . ' service(s) and ' . (int) $usage['plans']
                     . ' maintenance plan(s), so it cannot be deleted. Deactivate it instead &mdash; '
                     . 'it will stop appearing to customers while existing records stay intact.');
    } else {
        $stmt = $pdo->prepare('DELETE FROM categories WHERE category_id = :cid');
        $stmt->execute([':cid' => $categoryId]);
        log_activity('category_deleted', 'categories', $categoryId);
        flash('success', 'The category has been deleted.');
    }

    redirect('admin/categories.php');
}

/* -- Load one for editing -------------------------------------------- */
if ($editId = get_int('edit')) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE category_id = :cid');
    $stmt->execute([':cid' => $editId]);
    $editing = $stmt->fetch() ?: null;
}

/* -- Listing with live usage counts ----------------------------------- */
$categories = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM providers p WHERE p.category_id = c.category_id) AS provider_count,
            (SELECT COUNT(*) FROM providers p WHERE p.category_id = c.category_id
                AND p.verification_status = 'verified')                            AS verified_count,
            (SELECT COUNT(*) FROM services s WHERE s.category_id = c.category_id)  AS service_count,
            (SELECT COUNT(*) FROM maintenance_plans m WHERE m.category_id = c.category_id) AS plan_count,
            (SELECT COUNT(*) FROM bookings b
               JOIN providers p2 ON p2.provider_id = b.provider_id
              WHERE p2.category_id = c.category_id)                                AS booking_count
       FROM categories c
      ORDER BY c.is_active DESC, c.category_name"
)->fetchAll();

$pageTitle   = 'Categories';
$pageHeading = 'Service categories';
$pageLede    = 'The trades customers can search. Every professional belongs to exactly one.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--2 grid--rail">

    <!-- ---------------- Listing ---------------------------------- -->
    <section class="card">
        <div class="card__head">
            <h3>All categories</h3>
            <input class="input" type="search" style="max-width:220px"
                   data-filter-table="catTable" placeholder="Search categories">
        </div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table" id="catTable">
                    <thead>
                        <tr>
                            <th>Category</th><th class="text-right">Pros</th>
                            <th class="text-right">Services</th><th class="text-right">Bookings</th>
                            <th>Status</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td>
                                <span class="table__primary">
                                    <?= e($c['icon']) ?> <?= e($c['category_name']) ?>
                                </span><br>
                                <span class="text-small text-muted"><?= e(excerpt($c['description'], 52)) ?></span>
                            </td>
                            <td class="text-right">
                                <?= (int) $c['provider_count'] ?>
                                <?php if ((int) $c['verified_count'] < (int) $c['provider_count']): ?>
                                    <br><span class="text-small text-muted"><?= (int) $c['verified_count'] ?> verified</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= (int) $c['service_count'] ?></td>
                            <td class="text-right"><?= (int) $c['booking_count'] ?></td>
                            <td>
                                <?= $c['is_active']
                                    ? '<span class="badge badge--active">Live</span>'
                                    : '<span class="badge badge--expired">Hidden</span>' ?>
                            </td>
                            <td class="text-right">
                                <div class="table__actions">
                                    <a class="btn btn--outline btn--sm"
                                       href="categories.php?edit=<?= (int) $c['category_id'] ?>">Edit</a>

                                    <form method="post" action="categories.php" class="u-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="category_id" value="<?= (int) $c['category_id'] ?>">
                                        <button class="btn btn--ghost btn--sm" type="submit">
                                            <?= $c['is_active'] ? 'Hide' : 'Show' ?>
                                        </button>
                                    </form>

                                    <?php
                                    $unused = (int) $c['provider_count'] === 0
                                           && (int) $c['service_count']  === 0
                                           && (int) $c['plan_count']     === 0;
                                    ?>
                                    <?php if ($unused): ?>
                                        <form method="post" action="categories.php" class="u-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id" value="<?= (int) $c['category_id'] ?>">
                                            <button class="btn btn--ghost btn--sm" type="submit"
                                                    data-confirm="Delete &quot;<?= e($c['category_name']) ?>&quot; permanently?">
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ---------------- Add / edit form --------------------------- -->
    <form class="card" method="post" action="categories.php" data-validate-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="category_id" value="<?= (int) $editing['category_id'] ?>">
        <?php endif; ?>

        <div class="card__head">
            <h3><?= $editing ? 'Edit category' : 'Add a category' ?></h3>
            <?php if ($editing): ?>
                <a class="btn btn--ghost btn--sm" href="categories.php">Cancel</a>
            <?php endif; ?>
        </div>

        <div class="card__body">
            <div class="field">
                <label class="label" for="category_name">Name <span class="req">*</span></label>
                <input class="input<?= isset($errors['category_name']) ? ' is-invalid' : '' ?>"
                       type="text" id="category_name" name="category_name"
                       value="<?= e(post('category_name') ?: ($editing['category_name'] ?? '')) ?>"
                       placeholder="Pest Control" data-validate="required">
                <?php if (isset($errors['category_name'])): ?>
                    <div class="error"><?= e($errors['category_name']) ?></div>
                <?php else: ?>
                    <div class="hint">Name it the way a customer would search for it.</div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="icon">Icon</label>
                <input class="input" type="text" id="icon" name="icon" maxlength="8"
                       value="<?= e(post('icon') ?: ($editing['icon'] ?? '')) ?>"
                       placeholder="&#128028;" style="font-size:20px;max-width:90px">
                <div class="hint">A single emoji, shown on the landing page tile.</div>
            </div>

            <div class="field">
                <label class="label" for="description">Description</label>
                <textarea class="textarea" id="description" name="description"
                          placeholder="Cockroach, termite, mosquito and rodent treatment."><?= e(post('description') ?: ($editing['description'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="card__foot">
            <button class="btn btn--accent btn--block" type="submit">
                <?= $editing ? 'Save changes' : 'Add category' ?>
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
