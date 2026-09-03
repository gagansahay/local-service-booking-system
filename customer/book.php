<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/book.php
 *  MODULE  : 5 -- Booking Management  (with Module 4, Customer)
 *  PURPOSE : Create a booking against a professional.
 *
 *  PROCESS LOGIC
 *  -------------
 *   1. Validate every submitted field on the server.
 *   2. Re-run check_slot_available(). The AJAX call the browser already
 *      made is only a convenience -- between that call and this submit,
 *      somebody else may have taken the slot.
 *   3. Inside ONE transaction:
 *        a. insert the booking,
 *        b. write the opening row of its status history,
 *        c. create a pending payment record,
 *        d. notify the professional.
 *      If any step fails, the whole thing rolls back. A booking with no
 *      audit row, or an invoice with no booking, must never exist.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();
$me     = current_user();

$providerId = get_int('provider') ?: post_int('provider_id');
$serviceId  = get_int('service')  ?: post_int('service_id');

if ($providerId <= 0) {
    flash('error', 'Choose a professional to book.');
    redirect('customer/search.php');
}

/* -- Load the professional ------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT * FROM vw_provider_directory
      WHERE provider_id = :pid
        AND verification_status = 'verified'
        AND account_status = 'active'"
);
$stmt->execute([':pid' => $providerId]);
$provider = $stmt->fetch();

if (!$provider) {
    flash('error', 'That professional is not currently accepting bookings.');
    redirect('customer/search.php');
}

/* -- Their services -------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT service_id, service_name, base_price, duration_minutes
       FROM services
      WHERE provider_id = :pid AND is_active = 1
      ORDER BY service_name'
);
$stmt->execute([':pid' => $providerId]);
$services = $stmt->fetchAll();

$serviceById = [];
foreach ($services as $s) {
    $serviceById[(int) $s['service_id']] = $s;
}

/* -- Form state ------------------------------------------------------ */
$errors = [];
$in = [
    'service_id'          => $serviceId > 0 ? (string) $serviceId : '',
    'booking_date'        => date('Y-m-d'),
    'booking_time'        => '',
    'service_address'     => (string) ($me['address'] ?? ''),
    'city'                => (string) ($me['city'] ?? ''),
    'pincode'             => (string) ($me['pincode'] ?? ''),
    'problem_description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_guard();

    foreach ($in as $key => $_) {
        $in[$key] = post($key);
    }

    /* ---- 1. Field validation --------------------------------------- */

    $chosenService = null;
    if ($in['service_id'] !== '') {
        if (!isset($serviceById[(int) $in['service_id']])) {
            $errors['service_id'] = 'That service is not offered by this professional.';
        } else {
            $chosenService = $serviceById[(int) $in['service_id']];
        }
    }

    if ($in['booking_date'] === '' || !valid_date($in['booking_date'])) {
        $errors['booking_date'] = 'Choose a valid date.';
    }
    if ($in['booking_time'] === '' || !valid_time($in['booking_time'])) {
        $errors['booking_time'] = 'Choose a start time.';
    }
    if ($in['service_address'] === '') {
        $errors['service_address'] = 'Enter the address where the work is needed.';
    }
    if ($in['pincode'] !== '' && !valid_pincode($in['pincode'])) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }
    if (mb_strlen($in['problem_description']) > 1000) {
        $errors['problem_description'] = 'Please keep the description under 1000 characters.';
    }

    $duration = $chosenService ? (int) $chosenService['duration_minutes'] : 60;
    $estimate = $chosenService
        ? (float) $chosenService['base_price']
        : (float) $provider['hourly_rate'];

    /* ---- 2. Re-check the slot on the server ------------------------ */
    if (!$errors) {
        $slot = check_slot_available($pdo, $providerId, $in['booking_date'], $in['booking_time'], $duration);
        if (!$slot['ok']) {
            $errors['booking_time'] = $slot['reason'];
        }
    }

    /* ---- 3. Write it, all or nothing ------------------------------- */
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $code = next_booking_code($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO bookings
                    (booking_code, user_id, provider_id, service_id, booking_date, booking_time,
                     duration_minutes, service_address, city, pincode, problem_description,
                     status, estimated_cost)
                 VALUES
                    (:code, :uid, :pid, :sid, :bdate, :btime,
                     :duration, :address, :city, :pincode, :problem,
                     :status, :estimate)'
            );
            $stmt->execute([
                ':code'     => $code,
                ':uid'      => $userId,
                ':pid'      => $providerId,
                ':sid'      => $chosenService ? (int) $chosenService['service_id'] : null,
                ':bdate'    => $in['booking_date'],
                ':btime'    => $in['booking_time'] . ':00',
                ':duration' => $duration,
                ':address'  => $in['service_address'],
                ':city'     => $in['city'] ?: null,
                ':pincode'  => $in['pincode'] ?: null,
                ':problem'  => $in['problem_description'] ?: null,
                ':status'   => 'pending',
                ':estimate' => $estimate,
            ]);

            $bookingId = (int) $pdo->lastInsertId();

            // Opening row of the audit trail.
            record_status_change($pdo, $bookingId, '', 'pending', $userId, 'Booking created by customer.');

            // A pending invoice, so the amount owed is tracked from the start.
            $stmt = $pdo->prepare(
                'INSERT INTO payments (booking_id, invoice_no, amount, payment_mode, payment_status)
                 VALUES (:bid, :inv, :amount, :mode, :status)'
            );
            $stmt->execute([
                ':bid'    => $bookingId,
                ':inv'    => make_invoice_no($bookingId),
                ':amount' => $estimate,
                ':mode'   => 'cash',
                ':status' => 'pending',
            ]);

            // Tell the professional there is work waiting.
            notify(
                $pdo,
                (int) $provider['user_id'],
                'New booking request',
                current_name() . ' requested ' . ($chosenService['service_name'] ?? $provider['category_name'])
                    . ' on ' . show_date($in['booking_date']) . '.',
                'provider/requests.php',
                'inbox'
            );

            $pdo->commit();

            log_activity('booking_created', 'bookings', $bookingId, $code);
            flash('success', 'Request ' . $code . ' sent to ' . $provider['full_name']
                           . '. You will be notified when it is accepted.');
            redirect('customer/my-bookings.php');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['form'] = APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'The booking could not be saved. Please try again.';
        }
    }
}

/* -- Slot grid for the initially selected date ----------------------- */
$slotGrid = build_slot_grid(
    $pdo,
    $providerId,
    valid_date($in['booking_date']) ? $in['booking_date'] : date('Y-m-d'),
    $in['service_id'] !== '' && isset($serviceById[(int) $in['service_id']])
        ? (int) $serviceById[(int) $in['service_id']]['duration_minutes']
        : 60
);

$pageTitle   = 'Book a service';
$pageHeading = 'Book ' . $provider['full_name'];
$pageLede    = $provider['category_name'] . ' · ' . money($provider['hourly_rate']) . ' per hour';

include __DIR__ . '/../includes/header.php';
?>

<p class="u-mb-4">
    <a class="btn btn--ghost btn--sm" href="provider-view.php?id=<?= (int) $providerId ?>">&larr; Back to profile</a>
</p>

<?= form_error($errors) ?>

<div class="grid grid--2 grid--rail">

    <!-- ================= The form ================================= -->
    <form class="card" method="post" action="book.php" data-validate-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="provider_id" value="<?= (int) $providerId ?>">
        <input type="hidden" id="providerId" value="<?= (int) $providerId ?>">

        <div class="card__head"><h3>Job details</h3></div>

        <div class="card__body">
            <div class="form-grid">

                <div class="field field--full">
                    <label class="label" for="service_id">Service</label>
                    <select class="select<?= isset($errors['service_id']) ? ' is-invalid' : '' ?>"
                            id="service_id" name="service_id">
                        <option value="">General visit &mdash; charged at the hourly rate</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int) $s['service_id'] ?>"
                                    data-duration="<?= (int) $s['duration_minutes'] ?>"
                                <?= (string) $in['service_id'] === (string) $s['service_id'] ? 'selected' : '' ?>>
                                <?= e($s['service_name']) ?>
                                &mdash; <?= e(money($s['base_price'])) ?>
                                (<?= (int) $s['duration_minutes'] ?> min)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['service_id'])): ?>
                        <div class="error"><?= e($errors['service_id']) ?></div>
                    <?php else: ?>
                        <div class="hint">Choosing a service sets the price and how long the slot needs to be.</div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="bookingDate">Date <span class="req">*</span></label>
                    <input class="input<?= isset($errors['booking_date']) ? ' is-invalid' : '' ?>"
                           type="date" id="bookingDate" name="booking_date"
                           value="<?= e($in['booking_date']) ?>"
                           min="<?= e(date('Y-m-d')) ?>"
                           max="<?= e(date('Y-m-d', strtotime('+' . MAX_ADVANCE_DAYS . ' days'))) ?>"
                           data-validate="required|futuredate">
                    <?php if (isset($errors['booking_date'])): ?>
                        <div class="error"><?= e($errors['booking_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="bookingTime">Start time <span class="req">*</span></label>
                    <select class="select<?= isset($errors['booking_time']) ? ' is-invalid' : '' ?>"
                            id="bookingTime" name="booking_time" data-validate="required">
                        <option value="">Select a time</option>
                        <?php foreach ($slotGrid as $slot): ?>
                            <option value="<?= e($slot['value']) ?>"
                                    <?= $slot['free'] ? '' : 'disabled' ?>
                                    <?= $in['booking_time'] === $slot['value'] ? 'selected' : '' ?>>
                                <?= e($slot['label']) ?><?= $slot['free'] ? '' : ' -- booked' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="slotNote" class="hint"></div>
                    <?php if (isset($errors['booking_time'])): ?>
                        <div class="error"><?= e($errors['booking_time']) ?></div>
                    <?php endif; ?>
                </div>

                <input type="hidden" id="durationMinutes" value="60">

                <div class="field field--full">
                    <label class="label" for="service_address">Address for the visit <span class="req">*</span></label>
                    <input class="input<?= isset($errors['service_address']) ? ' is-invalid' : '' ?>"
                           type="text" id="service_address" name="service_address"
                           value="<?= e($in['service_address']) ?>"
                           data-validate="required">
                    <?php if (isset($errors['service_address'])): ?>
                        <div class="error"><?= e($errors['service_address']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="city">City</label>
                    <input class="input" type="text" id="city" name="city" value="<?= e($in['city']) ?>">
                </div>

                <div class="field">
                    <label class="label" for="pincode">PIN code</label>
                    <input class="input<?= isset($errors['pincode']) ? ' is-invalid' : '' ?>"
                           type="text" id="pincode" name="pincode" value="<?= e($in['pincode']) ?>"
                           data-numeric="6" data-validate="pincode">
                    <?php if (isset($errors['pincode'])): ?>
                        <div class="error"><?= e($errors['pincode']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field field--full">
                    <label class="label" for="problem_description">What needs doing?</label>
                    <textarea class="textarea<?= isset($errors['problem_description']) ? ' is-invalid' : '' ?>"
                              id="problem_description" name="problem_description"
                              placeholder="Describe the problem. The more detail you give, the better prepared they arrive."><?= e($in['problem_description']) ?></textarea>
                    <?php if (isset($errors['problem_description'])): ?>
                        <div class="error"><?= e($errors['problem_description']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card__foot">
            <div class="btn-row">
                <button class="btn btn--accent btn--lg" type="submit">Send booking request</button>
                <a class="btn btn--ghost" href="provider-view.php?id=<?= (int) $providerId ?>">Cancel</a>
            </div>
        </div>
    </form>

    <!-- ================= Summary rail ============================= -->
    <div>
        <section class="card">
            <div class="card__body">
                <div class="person u-mb-4">
                    <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($provider['full_name'])) ?></span>
                    <div>
                        <div class="person__name"><?= e($provider['full_name']) ?></div>
                        <div class="person__meta"><?= e($provider['category_name']) ?></div>
                        <?= star_rating((float) $provider['avg_rating'], (int) $provider['total_reviews']) ?>
                    </div>
                </div>

                <dl class="jobcard__facts" style="border-top:none">
                    <div><dt>Hourly rate</dt><dd><?= e(money($provider['hourly_rate'])) ?></dd></div>
                    <div><dt>Experience</dt><dd><?= (int) $provider['experience_years'] ?> years</dd></div>
                    <div><dt>Jobs done</dt><dd><?= (int) $provider['total_jobs'] ?></dd></div>
                </dl>

                <p class="text-small text-muted" style="margin:var(--sp-4) 0 0">
                    Sending a request does not confirm the job.
                    <?= e(explode(' ', $provider['full_name'])[0]) ?> will accept or decline it,
                    and you will be notified either way.
                </p>
            </div>
        </section>

        <section class="alert alert--info" style="display:block">
            <strong>How the price works</strong>
            <p class="text-small" style="margin:var(--sp-2) 0 0">
                The figure shown is an estimate. The professional confirms the final amount when
                the job is marked complete, so extra parts or extra time are charged honestly
                rather than guessed at up front.
            </p>
        </section>
    </div>
</div>

<script>
/* Keep the hidden duration field in step with the chosen service, so
   the slot grid asks for a window of the right length. */
(function () {
    var serviceSelect = document.getElementById('service_id');
    var durationField = document.getElementById('durationMinutes');
    if (!serviceSelect || !durationField) return;

    serviceSelect.addEventListener('change', function () {
        var option = serviceSelect.options[serviceSelect.selectedIndex];
        durationField.value = option.getAttribute('data-duration') || 60;
        durationField.dispatchEvent(new Event('change'));
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
