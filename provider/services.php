<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/services.php
 *  MODULE  : 3 -- Service Provider
 *  PURPOSE : Publish, edit, deactivate and delete the priced services a
 *            professional offers.
 *
 *  A service is DEACTIVATED rather than deleted once it has bookings
 *  against it, so historical invoices keep describing what was actually
 *  bought. Deletion is offered only for services never used.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$providerId = current_provider_id();

$stmt = $pdo->prepare('SELECT category_id FROM providers WHERE provider_id = :pid');
$stmt->execute([':pid' => $providerId]);
$categoryId = (int) $stmt->fetchColumn();

$errors = [];
$editing = null;

/* =====================================================================
 * CREATE / UPDATE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['create', 'update'], true)) {

    csrf_guard();

    $serviceId = post_int('service_id');
    $name      = post('service_name');
    $desc      = post('description');
    $price     = post('base_price');
    $duration  = post_int('duration_minutes', 60);

    if ($name === '' || mb_strlen($name) < 3) {
        $errors['service_name'] = 'Give the service a clear name of at least 3 characters.';
    }
    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        $errors['base_price'] = 'Enter a price of zero or more.';
    }
    if ($duration < 15 || $duration > 600) {
        $errors['duration_minutes'] = 'Duration must be between 15 and 600 minutes.';
    }

    if (!$errors) {
        if (post('action') === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO services
                    (provider_id, category_id, service_name, description, base_price, duration_minutes)
                 VALUES (:pid, :cid, :name, :desc, :price, :duration)'
            );
            $stmt->execute([
                ':pid'      => $providerId,
                ':cid'      => $categoryId,
                ':name'     => $name,
                ':desc'     => $desc ?: null,
                ':price'    => (float) $price,
                ':duration' => $duration,
            ]);
            log_activity('service_created', 'services', (int) $pdo->lastInsertId(), $name);
            flash('success', '"' . $name . '" is now listed on your profile.');

        } else {
            // provider_id in the WHERE clause is what stops one
            // professional editing another's service.
            $stmt = $pdo->prepare(
                'UPDATE services
                    SET service_name = :name, description = :desc,
                        base_price = :price, duration_minutes = :duration
                  WHERE service_id = :sid AND provider_id = :pid'
            );
            $stmt->execute([
                ':name'     => $name,
                ':desc'     => $desc ?: null,
                ':price'    => (float) $price,
                ':duration' => $duration,
                ':sid'      => $serviceId,
                ':pid'      => $providerId,
            ]);
            log_activity('service_updated', 'services', $serviceId, $name);
            flash('success', '"' . $name . '" has been updated.');
        }

        redirect('provider/services.php');
    }
}

/* =====================================================================
 * TOGGLE ACTIVE / DELETE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle') {

    csrf_guard();

    $stmt = $pdo->prepare(
        'UPDATE services SET is_active = 1 - is_active
          WHERE service_id = :sid AND provider_id = :pid'
    );
    $stmt->execute([':sid' => post_int('service_id'), ':pid' => $providerId]);

    flash('success', 'The service listing has been updated.');
    redirect('provider/services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {

    csrf_guard();

    $serviceId = post_int('service_id');

    // Refuse to delete anything a booking still references.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE service_id = :sid');
    $stmt->execute([':sid' => $serviceId]);

    if ((int) $stmt->fetchColumn() > 0) {
        flash('error', 'This service has bookings against it, so it cannot be deleted. '
                     . 'Deactivate it instead &mdash; it will stop appearing to customers '
                     . 'but past invoices stay accurate.');
    } else {
        $stmt = $pdo->prepare('DELETE FROM services WHERE service_id = :sid AND provider_id = :pid');
        $stmt->execute([':sid' => $serviceId, ':pid' => $providerId]);
        log_activity('service_deleted', 'services', $serviceId);
        flash('success', 'The service has been removed.');
    }

    redirect('provider/services.php');
}

/* -- Load one service for editing ------------------------------------ */
if ($editId = get_int('edit')) {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE service_id = :sid AND provider_id = :pid');
    $stmt->execute([':sid' => $editId, ':pid' => $providerId]);
    $editing = $stmt->fetch() ?: null;
}

/* -- The listing ----------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.service_id = s.service_id) AS booking_count
       FROM services s
      WHERE s.provider_id = :pid
      ORDER BY s.is_active DESC, s.service_name'
);
$stmt->execute([':pid' => $providerId]);
$services = $stmt->fetchAll();

$pageTitle   = 'My services';
$pageHeading = 'My services';
$pageLede    = 'Publish what you do and what it costs, so customers can book the right thing.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--2" style="align-items:start;grid-template-columns:1.4fr 1fr">

    <!-- ---------------- Listing ---------------------------------- -->
    <div>
        <?php if (!$services): ?>
            <div class="card">
                <div class="empty">
                    <div class="empty__icon" aria-hidden="true">&#128736;</div>
                    <h3>No services listed yet</h3>
                    <p>
                        Add your first service using the form beside this. Customers can still book
                        you for a general visit at your hourly rate, but a named service with a
                        clear price gets booked far more often.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($services as $s): ?>
                <article class="card" style="<?= $s['is_active'] ? '' : 'opacity:.66' ?>">
                    <div class="card__body">
                        <div class="jobcard__top">
                            <div>
                                <h3 style="margin-bottom:2px"><?= e($s['service_name']) ?></h3>
                                <p class="text-small text-muted" style="margin:0">
                                    <?= e(excerpt($s['description'], 90)) ?>
                                </p>
                            </div>
                            <?= $s['is_active']
                                ? '<span class="badge badge--active">Live</span>'
                                : '<span class="badge badge--expired">Hidden</span>' ?>
                        </div>

                        <dl class="jobcard__facts">
                            <div><dt>Price</dt><dd><?= e(money($s['base_price'])) ?></dd></div>
                            <div><dt>Duration</dt><dd><?= (int) $s['duration_minutes'] ?> min</dd></div>
                            <div><dt>Booked</dt><dd><?= (int) $s['booking_count'] ?> times</dd></div>
                        </dl>

                        <div class="jobcard__foot">
                            <div class="btn-row" style="margin-left:auto">
                                <a class="btn btn--outline btn--sm"
                                   href="services.php?edit=<?= (int) $s['service_id'] ?>">Edit</a>

                                <form method="post" action="services.php" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="service_id" value="<?= (int) $s['service_id'] ?>">
                                    <button class="btn btn--outline btn--sm" type="submit">
                                        <?= $s['is_active'] ? 'Hide' : 'Make live' ?>
                                    </button>
                                </form>

                                <?php if ((int) $s['booking_count'] === 0): ?>
                                    <form method="post" action="services.php" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="service_id" value="<?= (int) $s['service_id'] ?>">
                                        <button class="btn btn--ghost btn--sm" type="submit"
                                                data-confirm="Delete &quot;<?= e($s['service_name']) ?>&quot; permanently?">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ---------------- Add / edit form --------------------------- -->
    <form class="card" method="post" action="services.php" data-validate-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="service_id" value="<?= (int) $editing['service_id'] ?>">
        <?php endif; ?>

        <div class="card__head">
            <h3><?= $editing ? 'Edit service' : 'Add a service' ?></h3>
            <?php if ($editing): ?>
                <a class="btn btn--ghost btn--sm" href="services.php">Cancel</a>
            <?php endif; ?>
        </div>

        <div class="card__body">
            <div class="field">
                <label class="label" for="service_name">Service name <span class="req">*</span></label>
                <input class="input<?= isset($errors['service_name']) ? ' is-invalid' : '' ?>"
                       type="text" id="service_name" name="service_name"
                       value="<?= e(post('service_name') ?: ($editing['service_name'] ?? '')) ?>"
                       placeholder="Tap and mixer repair" data-validate="required">
                <?php if (isset($errors['service_name'])): ?>
                    <div class="error"><?= e($errors['service_name']) ?></div>
                <?php else: ?>
                    <div class="hint">Name it the way a customer would describe the problem.</div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="description">What it covers</label>
                <textarea class="textarea" id="description" name="description"
                          placeholder="Repair or replacement of leaking taps, mixers and diverters."><?= e(post('description') ?: ($editing['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="label" for="base_price">Price (<?= e(CURRENCY) ?>) <span class="req">*</span></label>
                    <input class="input<?= isset($errors['base_price']) ? ' is-invalid' : '' ?>"
                           type="number" min="0" step="10" id="base_price" name="base_price"
                           value="<?= e(post('base_price') ?: ($editing['base_price'] ?? '')) ?>"
                           data-validate="required|positive">
                    <?php if (isset($errors['base_price'])): ?><div class="error"><?= e($errors['base_price']) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="duration_minutes">Duration (min) <span class="req">*</span></label>
                    <input class="input<?= isset($errors['duration_minutes']) ? ' is-invalid' : '' ?>"
                           type="number" min="15" max="600" step="15"
                           id="duration_minutes" name="duration_minutes"
                           value="<?= e(post('duration_minutes') ?: ($editing['duration_minutes'] ?? '60')) ?>"
                           data-validate="required">
                    <?php if (isset($errors['duration_minutes'])): ?>
                        <div class="error"><?= e($errors['duration_minutes']) ?></div>
                    <?php else: ?>
                        <div class="hint">This decides how much of your day the slot blocks out.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card__foot">
            <button class="btn btn--accent btn--block" type="submit">
                <?= $editing ? 'Save changes' : 'Add this service' ?>
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
