<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/maintenance.php
 *  MODULE  : 6 -- Maintenance (AMC)
 *  PURPOSE : The customer's Annual Maintenance Contracts -- browse and
 *            subscribe to a plan, see the visit schedule, and raise the
 *            booking for a visit that has fallen due.
 *
 *  WHY THIS MODULE EXISTS
 *  ----------------------
 *  Ad-hoc booking only helps once something has already broken.
 *  A maintenance contract inverts that: the customer pays once, the
 *  system generates the whole year's visits up front, and each one is
 *  raised on schedule. The service is never simply forgotten.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();

/* =====================================================================
 * ACTION 1 -- SUBSCRIBE TO A PLAN
 * ---------------------------------------------------------------------
 * The contract row and its whole visit schedule are written in ONE
 * transaction. A contract without visits would be a contract that never
 * fires, so the two must succeed or fail together.
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'subscribe') {

    csrf_guard();

    $planId     = post_int('plan_id');
    $providerId = post_int('provider_id');
    $startDate  = post('start_date');
    $address    = post('service_address');

    $errors = [];

    // The plan must exist and be on sale.
    $stmt = $pdo->prepare('SELECT * FROM maintenance_plans WHERE plan_id = :pid AND is_active = 1');
    $stmt->execute([':pid' => $planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        $errors[] = 'That maintenance plan is no longer available.';
    }

    // The professional must be verified AND work in the plan's trade.
    $stmt = $pdo->prepare(
        "SELECT d.*, p.category_id
           FROM vw_provider_directory d
           JOIN providers p ON p.provider_id = d.provider_id
          WHERE d.provider_id = :pid AND d.verification_status = 'verified' AND d.account_status = 'active'"
    );
    $stmt->execute([':pid' => $providerId]);
    $provider = $stmt->fetch();

    if (!$provider) {
        $errors[] = 'That professional is not available for maintenance contracts.';
    } elseif ($plan && (int) $provider['category_id'] !== (int) $plan['category_id']) {
        $errors[] = 'That professional does not work in this plan\'s trade.';
    }

    if (!valid_date($startDate) || $startDate < date('Y-m-d')) {
        $errors[] = 'Choose a start date of today or later.';
    }
    if ($address === '') {
        $errors[] = 'Enter the address the visits should be made to.';
    }

    if ($errors) {
        foreach ($errors as $message) {
            flash('error', $message);
        }
        redirect('customer/maintenance.php');
    }

    try {
        $pdo->beginTransaction();

        $code    = next_contract_code($pdo);
        $endDate = (new DateTime($startDate))
            ->modify('+' . (int) $plan['duration_months'] . ' months')
            ->format('Y-m-d');

        $stmt = $pdo->prepare(
            'INSERT INTO maintenance_contracts
                (contract_code, user_id, provider_id, plan_id, start_date, end_date,
                 next_due_date, visits_used, total_visits, amount_paid, service_address, status)
             VALUES
                (:code, :uid, :pid, :plan, :start, :end,
                 :next, 0, :total, :amount, :address, :status)'
        );
        $stmt->execute([
            ':code'    => $code,
            ':uid'     => $userId,
            ':pid'     => $providerId,
            ':plan'    => $planId,
            ':start'   => $startDate,
            ':end'     => $endDate,
            ':next'    => $startDate,
            ':total'   => (int) $plan['visits_per_year'],
            ':amount'  => (float) $plan['price'],
            ':address' => $address,
            ':status'  => 'active',
        ]);

        $contractId = (int) $pdo->lastInsertId();

        // Generate every visit for the term of the contract.
        $firstVisit = generate_maintenance_visits(
            $pdo,
            $contractId,
            $startDate,
            $plan['frequency'],
            (int) $plan['visits_per_year']
        );

        $stmt = $pdo->prepare('UPDATE maintenance_contracts SET next_due_date = :d WHERE contract_id = :cid');
        $stmt->execute([':d' => $firstVisit, ':cid' => $contractId]);

        notify(
            $pdo,
            (int) $provider['user_id'],
            'New maintenance contract',
            current_name() . ' subscribed to ' . $plan['plan_name'] . ' (' . $code . ').',
            'provider/maintenance.php',
            'wrench'
        );

        $pdo->commit();

        log_activity('amc_subscribed', 'maintenance_contracts', $contractId, $code);
        flash('success', 'Contract ' . $code . ' created. '
                       . (int) $plan['visits_per_year'] . ' visits have been scheduled, '
                       . 'the first on ' . show_date($firstVisit) . '.');

    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', APP_DEBUG
            ? 'Database error: ' . $exception->getMessage()
            : 'The contract could not be created. Please try again.');
    }

    redirect('customer/maintenance.php');
}

/* =====================================================================
 * ACTION 2 -- RAISE THE BOOKING FOR A DUE VISIT
 * ---------------------------------------------------------------------
 * A due visit becomes an ordinary booking flagged is_maintenance = 1,
 * so it flows through the same job queue and status workflow as any
 * other work. Nothing about the provider's screens needs to special-case it.
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'raise_visit') {

    csrf_guard();

    $visitId     = post_int('visit_id');
    $visitDate   = post('booking_date');
    $visitTime   = post('booking_time');

    // Ownership proved through the join back to the contract's user_id.
    $stmt = $pdo->prepare(
        "SELECT v.*, mc.user_id, mc.provider_id, mc.service_address, mc.contract_code,
                mp.plan_name, u.user_id AS provider_user_id
           FROM maintenance_visits    v
           JOIN maintenance_contracts mc ON mc.contract_id = v.contract_id
           JOIN maintenance_plans     mp ON mp.plan_id     = mc.plan_id
           JOIN providers             p  ON p.provider_id  = mc.provider_id
           JOIN users                 u  ON u.user_id      = p.user_id
          WHERE v.visit_id = :vid AND mc.user_id = :uid AND v.status = 'due'"
    );
    $stmt->execute([':vid' => $visitId, ':uid' => $userId]);
    $visit = $stmt->fetch();

    if (!$visit) {
        flash('error', 'That visit is not due, or does not belong to you.');
        redirect('customer/maintenance.php');
    }

    if ($visit['booking_id']) {
        flash('warning', 'A booking has already been raised for that visit.');
        redirect('customer/maintenance.php');
    }

    $slot = check_slot_available($pdo, (int) $visit['provider_id'], $visitDate, $visitTime, 90);
    if (!$slot['ok']) {
        flash('error', $slot['reason']);
        redirect('customer/maintenance.php');
    }

    try {
        $pdo->beginTransaction();

        $code = next_booking_code($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO bookings
                (booking_code, user_id, provider_id, service_id, booking_date, booking_time,
                 duration_minutes, service_address, problem_description, status,
                 estimated_cost, is_maintenance)
             VALUES
                (:code, :uid, :pid, NULL, :bdate, :btime,
                 90, :address, :problem, :status, 0.00, 1)'
        );
        $stmt->execute([
            ':code'    => $code,
            ':uid'     => $userId,
            ':pid'     => $visit['provider_id'],
            ':bdate'   => $visitDate,
            ':btime'   => $visitTime . ':00',
            ':address' => $visit['service_address'],
            ':problem' => 'Scheduled maintenance visit ' . (int) $visit['visit_number']
                        . ' under contract ' . $visit['contract_code'] . '.',
            ':status'  => 'pending',
        ]);

        $bookingId = (int) $pdo->lastInsertId();

        record_status_change($pdo, $bookingId, '', 'pending', $userId,
            'Auto-generated from AMC contract ' . $visit['contract_code'] . '.');

        $stmt = $pdo->prepare('UPDATE maintenance_visits SET booking_id = :bid WHERE visit_id = :vid');
        $stmt->execute([':bid' => $bookingId, ':vid' => $visitId]);

        notify(
            $pdo,
            (int) $visit['provider_user_id'],
            'Maintenance visit booked',
            current_name() . ' scheduled ' . $visit['plan_name'] . ' for ' . show_date($visitDate) . '.',
            'provider/requests.php',
            'wrench'
        );

        $pdo->commit();

        log_activity('amc_visit_raised', 'maintenance_visits', $visitId, $code);
        flash('success', 'Visit booked as ' . $code . ' for ' . show_date($visitDate) . '.');

    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', APP_DEBUG
            ? 'Database error: ' . $exception->getMessage()
            : 'The visit could not be booked. Please try again.');
    }

    redirect('customer/maintenance.php');
}

/* =====================================================================
 * PAGE DATA
 * ===================================================================*/

/* -- The customer's contracts ---------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT mc.*, mp.plan_name, mp.frequency, mp.description AS plan_description,
            u.full_name AS provider_name, u.phone AS provider_phone, c.category_name
       FROM maintenance_contracts mc
       JOIN maintenance_plans     mp ON mp.plan_id     = mc.plan_id
       JOIN providers             p  ON p.provider_id  = mc.provider_id
       JOIN users                 u  ON u.user_id      = p.user_id
       JOIN categories            c  ON c.category_id  = p.category_id
      WHERE mc.user_id = :uid
      ORDER BY FIELD(mc.status, :s1, :s2, :s3), mc.start_date DESC'
);
$stmt->execute([':uid' => $userId, ':s1' => 'active', ':s2' => 'expired', ':s3' => 'cancelled']);
$contracts = $stmt->fetchAll();

/* -- Their visits, in one query rather than one per contract --------- */
$visits = [];
if ($contracts) {
    $ids          = array_column($contracts, 'contract_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT v.*, b.booking_code, b.status AS booking_status
           FROM maintenance_visits v
           LEFT JOIN bookings b ON b.booking_id = v.booking_id
          WHERE v.contract_id IN ($placeholders)
          ORDER BY v.visit_number ASC"
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $row) {
        $visits[(int) $row['contract_id']][] = $row;
    }
}

/* -- Plans on offer --------------------------------------------------- */
$plans = $pdo->query(
    'SELECT mp.*, c.category_name, c.icon,
            (SELECT COUNT(*) FROM providers p
              WHERE p.category_id = mp.category_id
                AND p.verification_status = \'verified\') AS provider_count
       FROM maintenance_plans mp
       JOIN categories c ON c.category_id = mp.category_id
      WHERE mp.is_active = 1
      ORDER BY mp.price'
)->fetchAll();

/* -- Verified professionals per trade, for the subscribe form -------- */
$providersByCategory = [];
foreach ($pdo->query(
    "SELECT provider_id, full_name, category_id, avg_rating, hourly_rate
       FROM vw_provider_directory
      WHERE verification_status = 'verified' AND account_status = 'active'
      ORDER BY avg_rating DESC"
)->fetchAll() as $row) {
    $providersByCategory[(int) $row['category_id']][] = $row;
}

/* Pre-select a plan when arriving from a professional's profile. */
$subscribePlanId     = get_int('subscribe');
$subscribeProviderId = get_int('provider');

$me = current_user();

$pageTitle   = 'My AMC plans';
$pageHeading = 'Maintenance contracts';
$pageLede    = 'Book a year of servicing once, and let the schedule take care of itself.';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== ACTIVE CONTRACTS ========================= -->
<?php if (!$contracts): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128467;</div>
            <h3>No maintenance contracts yet</h3>
            <p>
                A contract schedules a whole year of servicing in one go. Pick a plan below
                and the system will generate every visit and remind you when one falls due.
            </p>
            <a class="btn btn--accent" href="#plans">See the plans</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($contracts as $contract): ?>
        <?php
        $contractVisits = $visits[(int) $contract['contract_id']] ?? [];
        $dueVisit = null;
        foreach ($contractVisits as $v) {
            if ($v['status'] === 'due' && empty($v['booking_id'])) {
                $dueVisit = $v;
                break;
            }
        }
        ?>
        <section class="card">
            <div class="card__body">

                <div class="contract-head">
                    <div class="contract-head__plan">
                        <h3><?= e($contract['plan_name']) ?></h3>
                        <p class="text-small text-muted u-m0">
                            <span class="ref"><?= e($contract['contract_code']) ?></span>
                            &middot; <?= e($contract['provider_name']) ?>
                            &middot; <?= e(frequency_label($contract['frequency'])) ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <?= status_badge($contract['status']) ?>
                        <div class="text-small text-muted u-mt-1">
                            <?= (int) $contract['visits_used'] ?> of
                            <?= (int) $contract['total_visits'] ?> visits used
                        </div>
                    </div>
                </div>

                <!-- ===== THE AMC VISIT STRIP =====================
                     One disc per entitled visit, filled as each is
                     completed. Modelled on the service card stuck to
                     the back of an appliance: it answers "where am I
                     in this contract?" at a glance. -->
                <div class="visit-strip" role="list"
                     aria-label="Visit schedule for <?= e($contract['contract_code']) ?>">
                    <?php foreach ($contractVisits as $v): ?>
                        <div class="visit-stamp visit-stamp--<?= e($v['status']) ?>" role="listitem">
                            <span class="visit-stamp__disc" aria-hidden="true"><?= (int) $v['visit_number'] ?></span>
                            <span class="visit-stamp__label">
                                <?= e(date('d M', strtotime($v['scheduled_date']))) ?>
                            </span>
                            <span class="visually-hidden">
                                Visit <?= (int) $v['visit_number'] ?>,
                                scheduled <?= e(show_date($v['scheduled_date'])) ?>,
                                <?= e($v['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Started</dt><dd><?= e(show_date($contract['start_date'])) ?></dd></div>
                    <div><dt>Runs until</dt><dd><?= e(show_date($contract['end_date'])) ?></dd></div>
                    <div>
                        <dt>Next due</dt>
                        <dd><?= $contract['next_due_date'] ? e(show_date($contract['next_due_date'])) : 'All visits used' ?></dd>
                    </div>
                    <div><dt>Paid</dt><dd><?= e(money($contract['amount_paid'])) ?></dd></div>
                    <div><dt>Professional</dt><dd class="ref"><?= e($contract['provider_phone']) ?></dd></div>
                </dl>

                <!-- Raise the booking for a due visit ---------------- -->
                <?php if ($dueVisit): ?>
                    <div class="alert alert--warning" style="display:block">
                        <strong>Visit <?= (int) $dueVisit['visit_number'] ?> is due.</strong>
                        <p class="text-small" style="margin:var(--sp-1) 0 var(--sp-3)">
                            It was scheduled for <?= e(show_date($dueVisit['scheduled_date'])) ?>.
                            Pick a date and time and it will be sent to
                            <?= e(explode(' ', $contract['provider_name'])[0]) ?> as a booking.
                        </p>

                        <form method="post" action="maintenance.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"   value="raise_visit">
                            <input type="hidden" name="visit_id" value="<?= (int) $dueVisit['visit_id'] ?>">

                            <div class="form-grid" style="align-items:end">
                                <div class="field">
                                    <label class="label" for="vd<?= (int) $dueVisit['visit_id'] ?>">Date</label>
                                    <input class="input" type="date" required
                                           id="vd<?= (int) $dueVisit['visit_id'] ?>" name="booking_date"
                                           value="<?= e(max($dueVisit['scheduled_date'], date('Y-m-d'))) ?>"
                                           min="<?= e(date('Y-m-d')) ?>">
                                </div>
                                <div class="field">
                                    <label class="label" for="vt<?= (int) $dueVisit['visit_id'] ?>">Time</label>
                                    <select class="select" id="vt<?= (int) $dueVisit['visit_id'] ?>" name="booking_time" required>
                                        <?php for ($h = 9; $h <= 17; $h++): ?>
                                            <option value="<?= sprintf('%02d:00', $h) ?>">
                                                <?= e(date('h:i A', mktime($h, 0))) ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <button class="btn btn--accent btn--block" type="submit">Book this visit</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Visit log --------------------------------------- -->
                <details>
                    <summary class="text-small" style="cursor:pointer;color:var(--blue-600)">
                        Show the full visit log
                    </summary>
                    <div class="table-wrap u-mt-3">
                        <table class="table">
                            <thead>
                                <tr><th>#</th><th>Scheduled</th><th>Status</th><th>Completed</th>
                                    <th>Booking</th><th>Technician remarks</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($contractVisits as $v): ?>
                                <tr>
                                    <td class="table__primary"><?= (int) $v['visit_number'] ?></td>
                                    <td><?= e(show_date($v['scheduled_date'])) ?></td>
                                    <td><?= status_badge($v['status']) ?></td>
                                    <td><?= e(show_date($v['completed_date'])) ?></td>
                                    <td>
                                        <?php if ($v['booking_code']): ?>
                                            <span class="ref"><?= e($v['booking_code']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">--</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-small text-muted"><?= e($v['technician_remarks'] ?: '--') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ==================== PLANS ON OFFER =========================== -->
<div class="page-head" id="plans" class="u-mt-6">
    <div>
        <h2>Plans you can subscribe to</h2>
        <p>Each plan schedules its visits automatically for a full year.</p>
    </div>
</div>

<div class="grid grid--2">
    <?php foreach ($plans as $plan): ?>
        <?php $available = $providersByCategory[(int) $plan['category_id']] ?? []; ?>
        <section class="card u-m0">
            <div class="card__head">
                <div>
                    <h3><?= e($plan['icon']) ?> <?= e($plan['plan_name']) ?></h3>
                    <p class="text-small text-muted u-m0"><?= e($plan['category_name']) ?></p>
                </div>
                <div class="text-right">
                    <div class="provider-card__rate"><?= e(money($plan['price'])) ?></div>
                    <div class="text-small text-muted">per year</div>
                </div>
            </div>

            <div class="card__body">
                <p class="text-small"><?= e($plan['description']) ?></p>

                <dl class="jobcard__facts">
                    <div><dt>Frequency</dt><dd><?= e(frequency_label($plan['frequency'])) ?></dd></div>
                    <div><dt>Visits</dt><dd><?= (int) $plan['visits_per_year'] ?> a year</dd></div>
                    <div><dt>Term</dt><dd><?= (int) $plan['duration_months'] ?> months</dd></div>
                    <div>
                        <dt>Cost per visit</dt>
                        <dd><?= e(money((float) $plan['price'] / max(1, (int) $plan['visits_per_year']))) ?></dd>
                    </div>
                </dl>

                <?php if (!$available): ?>
                    <div class="alert alert--info" style="margin:var(--sp-4) 0 0">
                        <span class="alert__icon">&#8505;</span>
                        <span class="alert__text">No verified professional covers this trade yet. Check back soon.</span>
                    </div>
                <?php else: ?>
                    <details <?= $subscribePlanId === (int) $plan['plan_id'] ? 'open' : '' ?>
                             class="u-mt-4">
                        <summary class="btn btn--accent btn--block" style="cursor:pointer;list-style:none">
                            Subscribe to this plan
                        </summary>

                        <form method="post" action="maintenance.php" class="u-mt-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"  value="subscribe">
                            <input type="hidden" name="plan_id" value="<?= (int) $plan['plan_id'] ?>">

                            <div class="field">
                                <label class="label" for="prov<?= (int) $plan['plan_id'] ?>">Professional</label>
                                <select class="select" id="prov<?= (int) $plan['plan_id'] ?>" name="provider_id" required>
                                    <?php foreach ($available as $p): ?>
                                        <option value="<?= (int) $p['provider_id'] ?>"
                                            <?= $subscribeProviderId === (int) $p['provider_id'] ? 'selected' : '' ?>>
                                            <?= e($p['full_name']) ?>
                                            (<?= number_format((float) $p['avg_rating'], 1) ?> stars)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label class="label" for="sd<?= (int) $plan['plan_id'] ?>">First visit on</label>
                                <input class="input" type="date" required
                                       id="sd<?= (int) $plan['plan_id'] ?>" name="start_date"
                                       value="<?= e(date('Y-m-d')) ?>" min="<?= e(date('Y-m-d')) ?>">
                            </div>

                            <div class="field">
                                <label class="label" for="ad<?= (int) $plan['plan_id'] ?>">Service address</label>
                                <input class="input" type="text" required
                                       id="ad<?= (int) $plan['plan_id'] ?>" name="service_address"
                                       value="<?= e($me['address'] ?? '') ?>">
                            </div>

                            <button class="btn btn--accent btn--block" type="submit">
                                Confirm &mdash; <?= e(money($plan['price'])) ?>
                            </button>
                            <p class="hint text-center u-mt-2">
                                Payment is settled with the professional. No card details are taken here.
                            </p>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
