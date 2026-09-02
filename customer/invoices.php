<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/invoices.php
 *  MODULE  : 8 -- Payment & Invoice
 *  PURPOSE : List the customer's invoices, and render a printable
 *            invoice for one booking.
 *
 *  SCOPE NOTE: per the approved synopsis, live gateway integration is
 *  OUT OF SCOPE. Settlement is simulated -- the customer records how
 *  they paid, and the mode/status/reference are stored. No card details
 *  are collected anywhere in this application.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();

/* =====================================================================
 * RECORD A PAYMENT  (simulated settlement)
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'pay') {

    csrf_guard();

    $bookingId = post_int('booking_id');
    $mode      = post('payment_mode');

    if (!in_array($mode, ['cash', 'upi', 'card', 'netbanking'], true)) {
        $mode = 'cash';
    }

    // Ownership proved through the join to bookings.user_id.
    $stmt = $pdo->prepare(
        "SELECT p.payment_id, p.invoice_no, b.booking_code
           FROM payments p
           JOIN bookings b ON b.booking_id = p.booking_id
          WHERE p.booking_id = :bid
            AND b.user_id = :uid
            AND b.status = 'completed'
            AND p.payment_status = 'pending'"
    );
    $stmt->execute([':bid' => $bookingId, ':uid' => $userId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        flash('error', 'That invoice is not awaiting payment.');
    } else {
        $reference = $mode === 'cash'
            ? null
            : strtoupper(substr($mode, 0, 3)) . date('ymdHi') . strtoupper(bin2hex(random_bytes(2)));

        $stmt = $pdo->prepare(
            "UPDATE payments
                SET payment_status = 'paid', payment_mode = :mode,
                    transaction_ref = :ref, paid_at = NOW()
              WHERE payment_id = :pid"
        );
        $stmt->execute([':mode' => $mode, ':ref' => $reference, ':pid' => (int) $payment['payment_id']]);

        log_activity('payment_recorded', 'payments', (int) $payment['payment_id'], $payment['invoice_no']);
        flash('success', 'Payment recorded against ' . $payment['invoice_no'] . '.');
    }

    redirect('customer/invoices.php');
}

/* =====================================================================
 * SINGLE INVOICE VIEW
 * ===================================================================*/
$viewBookingId = get_int('booking');

if ($viewBookingId > 0) {

    $stmt = $pdo->prepare(
        'SELECT b.*, pay.invoice_no, pay.amount, pay.payment_mode, pay.payment_status,
                pay.transaction_ref, pay.paid_at,
                u.full_name AS provider_name, u.phone AS provider_phone, u.email AS provider_email,
                u.address AS provider_address, u.city AS provider_city,
                c.category_name, s.service_name,
                cu.full_name AS customer_name, cu.phone AS customer_phone, cu.email AS customer_email
           FROM bookings   b
           JOIN providers  p  ON p.provider_id = b.provider_id
           JOIN users      u  ON u.user_id     = p.user_id
           JOIN categories c  ON c.category_id = p.category_id
           JOIN users      cu ON cu.user_id    = b.user_id
           LEFT JOIN services s   ON s.service_id  = b.service_id
           LEFT JOIN payments pay ON pay.booking_id = b.booking_id
          WHERE b.booking_id = :bid AND b.user_id = :uid'
    );
    $stmt->execute([':bid' => $viewBookingId, ':uid' => $userId]);
    $inv = $stmt->fetch();

    if (!$inv) {
        flash('error', 'That invoice was not found.');
        redirect('customer/invoices.php');
    }

    $pageTitle   = 'Invoice';
    $pageHeading = '';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="btn-row no-print u-mb-5">
        <a class="btn btn--ghost btn--sm" href="invoices.php">&larr; All invoices</a>
        <button class="btn btn--primary btn--sm" type="button" onclick="window.print()">Print this invoice</button>
    </div>

    <?php if (!$inv['invoice_no']): ?>
        <div class="alert alert--info">
            <span class="alert__icon">&#8505;</span>
            <span class="alert__text">
                This visit was covered by a maintenance contract, so no separate invoice was raised.
            </span>
        </div>
    <?php else: ?>

    <div class="invoice">
        <div class="invoice__head">
            <div>
                <p class="invoice__title">INVOICE</p>
                <p class="text-small text-muted u-m0">
                    <?= e(APP_NAME) ?>
                </p>
            </div>
            <div class="text-right">
                <dl class="text-small u-m0">
                    <div><dt class="text-muted">Invoice number</dt>
                         <dd class="ref u-m0"><?= e($inv['invoice_no']) ?></dd></div>
                    <div class="u-mt-2"><dt class="text-muted">Booking</dt>
                         <dd class="ref u-m0"><?= e($inv['booking_code']) ?></dd></div>
                    <div class="u-mt-2"><dt class="text-muted">Status</dt>
                         <dd class="u-m0"><?= status_badge($inv['payment_status']) ?></dd></div>
                </dl>
            </div>
        </div>

        <div class="invoice__parties">
            <div>
                <p class="eyebrow">Billed to</p>
                <strong class="u-ink"><?= e($inv['customer_name']) ?></strong><br>
                <?= e($inv['service_address']) ?><br>
                <?= e($inv['city']) ?> <?= e($inv['pincode']) ?><br>
                <?= e($inv['customer_phone']) ?><br>
                <?= e($inv['customer_email']) ?>
            </div>
            <div>
                <p class="eyebrow">Service by</p>
                <strong class="u-ink"><?= e($inv['provider_name']) ?></strong><br>
                <?= e($inv['category_name']) ?><br>
                <?= e($inv['provider_address'] ?: '') ?><br>
                <?= e($inv['provider_phone']) ?><br>
                <?= e($inv['provider_email']) ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Description</th><th>Date</th><th>Duration</th><th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <span class="table__primary"><?= e($inv['service_name'] ?: $inv['category_name'] . ' visit') ?></span>
                            <?php if (!empty($inv['problem_description'])): ?>
                                <br><span class="text-small text-muted"><?= e($inv['problem_description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(show_date($inv['booking_date'])) ?></td>
                        <td><?= (int) $inv['duration_minutes'] ?> min</td>
                        <td class="text-right table__primary"><?= e(money($inv['amount'])) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="invoice__total">
            <span>Total payable</span>
            <span><?= e(money($inv['amount'])) ?></span>
        </div>

        <div style="margin-top:var(--sp-6);padding-top:var(--sp-4);border-top:1px solid var(--line)">
            <dl class="jobcard__facts" style="border:none;padding:0">
                <div><dt>Payment mode</dt><dd><?= e(ucfirst($inv['payment_mode'])) ?></dd></div>
                <div><dt>Paid on</dt><dd><?= e(show_datetime($inv['paid_at'])) ?></dd></div>
                <?php if ($inv['transaction_ref']): ?>
                    <div><dt>Reference</dt><dd class="ref"><?= e($inv['transaction_ref']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <p class="text-small text-muted u-mt-4">
                This invoice is generated by the <?= e(APP_SHORT) ?> academic project
                (<?= e(COURSE_CODE) ?>, IGNOU) and is not a commercial tax document.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* =====================================================================
 * INVOICE LIST
 * ===================================================================*/
$stmt = $pdo->prepare(
    "SELECT b.booking_id, b.booking_code, b.booking_date, b.status AS booking_status,
            pay.invoice_no, pay.amount, pay.payment_mode, pay.payment_status, pay.paid_at,
            u.full_name AS provider_name, c.category_name
       FROM bookings   b
       JOIN providers  p ON p.provider_id = b.provider_id
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
       JOIN payments pay ON pay.booking_id = b.booking_id
      WHERE b.user_id = :uid
      ORDER BY b.booking_date DESC"
);
$stmt->execute([':uid' => $userId]);
$invoices = $stmt->fetchAll();

$totalPaid    = 0.0;
$totalPending = 0.0;
foreach ($invoices as $i) {
    if ($i['payment_status'] === 'paid') {
        $totalPaid += (float) $i['amount'];
    } elseif ($i['payment_status'] === 'pending') {
        $totalPending += (float) $i['amount'];
    }
}

$pageTitle   = 'Invoices';
$pageHeading = 'Invoices';
$pageLede    = 'Every invoice raised against your bookings.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--3 u-mb-6">
    <div class="stat stat--success">
        <div class="stat__label">Paid</div>
        <div class="stat__value stat__value--sm"><?= e(money($totalPaid)) ?></div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Awaiting payment</div>
        <div class="stat__value stat__value--sm"><?= e(money($totalPending)) ?></div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Invoices</div>
        <div class="stat__value"><?= count($invoices) ?></div>
    </div>
</div>

<?php if (!$invoices): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128179;</div>
            <h3>No invoices yet</h3>
            <p>An invoice is raised as soon as you book, and settled once the job is complete.</p>
            <a class="btn btn--accent" href="search.php">Book a service</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card__head">
            <h3>All invoices</h3>
            <input class="input" type="search" style="max-width:240px"
                   data-filter-table="invoiceTable" placeholder="Search invoices">
        </div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table" id="invoiceTable">
                    <thead>
                        <tr>
                            <th>Invoice</th><th>Professional</th><th>Date</th>
                            <th>Mode</th><th>Status</th><th class="text-right">Amount</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <tr>
                            <td>
                                <span class="ref"><?= e($i['invoice_no']) ?></span><br>
                                <span class="text-small text-muted"><?= e($i['booking_code']) ?></span>
                            </td>
                            <td>
                                <span class="table__primary"><?= e($i['provider_name']) ?></span><br>
                                <span class="text-small text-muted"><?= e($i['category_name']) ?></span>
                            </td>
                            <td><?= e(show_date($i['booking_date'])) ?></td>
                            <td><?= e(ucfirst($i['payment_mode'])) ?></td>
                            <td><?= status_badge($i['payment_status']) ?></td>
                            <td class="text-right table__primary"><?= e(money($i['amount'])) ?></td>
                            <td class="text-right">
                                <div class="table__actions">
                                    <a class="btn btn--outline btn--sm"
                                       href="invoices.php?booking=<?= (int) $i['booking_id'] ?>">View</a>

                                    <?php if ($i['payment_status'] === 'pending' && $i['booking_status'] === 'completed'): ?>
                                        <form method="post" action="invoices.php" style="display:flex;gap:4px">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="pay">
                                            <input type="hidden" name="booking_id" value="<?= (int) $i['booking_id'] ?>">
                                            <select class="select" name="payment_mode" style="padding:4px 24px 4px 8px;font-size:var(--text-sm)">
                                                <option value="cash">Cash</option>
                                                <option value="upi">UPI</option>
                                                <option value="card">Card</option>
                                                <option value="netbanking">Net banking</option>
                                            </select>
                                            <button class="btn btn--accent btn--sm" type="submit">Mark paid</button>
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
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
