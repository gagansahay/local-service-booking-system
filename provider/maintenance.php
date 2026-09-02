<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/maintenance.php
 *  MODULE  : 6 -- Maintenance (AMC)
 *  PURPOSE : The professional's side of maintenance contracts -- every
 *            contract they service, the visit schedule, and which
 *            visits are due next.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$providerId = current_provider_id();

/* -- Contracts serviced by this professional ------------------------- */
$stmt = $pdo->prepare(
    'SELECT mc.*, mp.plan_name, mp.frequency, u.full_name AS customer_name,
            u.phone AS customer_phone, c.category_name
       FROM maintenance_contracts mc
       JOIN maintenance_plans     mp ON mp.plan_id     = mc.plan_id
       JOIN users                 u  ON u.user_id      = mc.user_id
       JOIN providers             p  ON p.provider_id  = mc.provider_id
       JOIN categories            c  ON c.category_id  = p.category_id
      WHERE mc.provider_id = :pid
      ORDER BY FIELD(mc.status, :s1, :s2, :s3), mc.next_due_date ASC'
);
$stmt->execute([':pid' => $providerId, ':s1' => 'active', ':s2' => 'expired', ':s3' => 'cancelled']);
$contracts = $stmt->fetchAll();

/* -- Their visits, fetched in one query ------------------------------ */
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

/* -- Headline counters ------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT
        COUNT(DISTINCT mc.contract_id)                       AS contracts,
        SUM(mc.status = 'active')                            AS active,
        COALESCE(SUM(mc.amount_paid), 0)                     AS contract_value,
        (SELECT COUNT(*) FROM maintenance_visits v
           JOIN maintenance_contracts c2 ON c2.contract_id = v.contract_id
          WHERE c2.provider_id = :pid1 AND v.status = 'due')  AS due_visits
       FROM maintenance_contracts mc
      WHERE mc.provider_id = :pid2"
);
$stmt->execute([':pid1' => $providerId, ':pid2' => $providerId]);
$stats = $stmt->fetch();

$pageTitle   = 'AMC visits';
$pageHeading = 'Maintenance contracts';
$pageLede    = 'Recurring work that is already paid for and scheduled.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--4 u-mb-6">
    <div class="stat stat--blue">
        <div class="stat__label">Contracts</div>
        <div class="stat__value"><?= (int) $stats['contracts'] ?></div>
        <div class="stat__meta"><?= (int) $stats['active'] ?> currently active</div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Visits due</div>
        <div class="stat__value"><?= (int) $stats['due_visits'] ?></div>
        <div class="stat__meta">Waiting to be scheduled</div>
    </div>
    <div class="stat stat--success">
        <div class="stat__label">Contract value</div>
        <div class="stat__value stat__value--sm"><?= e(money($stats['contract_value'])) ?></div>
        <div class="stat__meta">Committed revenue</div>
    </div>
    <div class="stat">
        <div class="stat__label">Why this matters</div>
        <div class="text-small u-mt-2">
            Contract work is booked and paid up front, so it is the most predictable
            income on your calendar.
        </div>
    </div>
</div>

<?php if (!$contracts): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128467;</div>
            <h3>No maintenance contracts yet</h3>
            <p>
                Customers subscribe to a plan in your trade and pick you as the professional.
                Each contract books a year of visits in advance, so the work is already
                scheduled and paid for. Keeping your rating high is the surest way to be chosen.
            </p>
            <a class="btn btn--primary" href="profile.php">Review my profile</a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($contracts as $contract): ?>
        <?php $contractVisits = $visits[(int) $contract['contract_id']] ?? []; ?>

        <section class="card">
            <div class="card__body">

                <div class="contract-head">
                    <span class="avatar avatar--lg" aria-hidden="true">
                        <?= e(initials($contract['customer_name'])) ?>
                    </span>
                    <div class="contract-head__plan">
                        <h3><?= e($contract['plan_name']) ?></h3>
                        <p class="text-small text-muted u-m0">
                            <?= e($contract['customer_name']) ?>
                            &middot; <span class="ref"><?= e($contract['contract_code']) ?></span>
                            &middot; <?= e(frequency_label($contract['frequency'])) ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <?= status_badge($contract['status']) ?>
                        <div class="text-small text-muted u-mt-1">
                            <?= (int) $contract['visits_used'] ?> of
                            <?= (int) $contract['total_visits'] ?> visits done
                        </div>
                    </div>
                </div>

                <!-- The AMC visit strip, provider's view -------------- -->
                <div class="visit-strip" role="list"
                     aria-label="Visit schedule for <?= e($contract['contract_code']) ?>">
                    <?php foreach ($contractVisits as $v): ?>
                        <div class="visit-stamp visit-stamp--<?= e($v['status']) ?>" role="listitem">
                            <span class="visit-stamp__disc" aria-hidden="true"><?= (int) $v['visit_number'] ?></span>
                            <span class="visit-stamp__label">
                                <?= e(date('d M', strtotime($v['scheduled_date']))) ?>
                            </span>
                            <span class="visually-hidden">
                                Visit <?= (int) $v['visit_number'] ?>, <?= e($v['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <dl class="jobcard__facts">
                    <div><dt>Customer contact</dt><dd class="ref"><?= e($contract['customer_phone']) ?></dd></div>
                    <div><dt>Address</dt><dd><?= e(excerpt($contract['service_address'], 40)) ?></dd></div>
                    <div>
                        <dt>Next due</dt>
                        <dd><?= $contract['next_due_date'] ? e(show_date($contract['next_due_date'])) : 'All visits used' ?></dd>
                    </div>
                    <div><dt>Runs until</dt><dd><?= e(show_date($contract['end_date'])) ?></dd></div>
                </dl>

                <details>
                    <summary class="text-small" style="cursor:pointer;color:var(--blue-600)">
                        Show the visit log
                    </summary>
                    <div class="table-wrap u-mt-3">
                        <table class="table">
                            <thead>
                                <tr><th>#</th><th>Scheduled</th><th>Status</th><th>Completed</th>
                                    <th>Booking</th><th>Your remarks</th></tr>
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
                                            <a href="jobs.php?status=all" class="ref"><?= e($v['booking_code']) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">Not raised yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-small text-muted"><?= e($v['technician_remarks'] ?: '--') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <div class="alert alert--info" style="margin:var(--sp-4) 0 0">
                    <span class="alert__icon">&#8505;</span>
                    <span class="alert__text">
                        When the customer schedules a due visit it arrives in
                        <a href="requests.php">New requests</a> like any other job.
                        Completing it there closes the visit and moves the contract forward automatically.
                    </span>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
