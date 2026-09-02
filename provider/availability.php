<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/availability.php
 *  MODULE  : 3 -- Service Provider  (feeds Module 5, Booking Management)
 *  PURPOSE : Set the weekly working hours the booking engine checks
 *            every requested slot against.
 *
 *  These rows are the first gate in check_slot_available(): a customer
 *  simply cannot select a time outside the window set here, so this
 *  screen is what protects the professional's own time.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$providerId = current_provider_id();

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$errors   = [];

/* =====================================================================
 * SAVE THE WEEK
 * ---------------------------------------------------------------------
 * The whole week is written in one transaction. A half-saved week would
 * leave the booking engine consulting a schedule the professional never
 * actually agreed to.
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_week') {

    csrf_guard();

    $working = $_POST['working']    ?? [];
    $starts  = $_POST['start_time'] ?? [];
    $ends    = $_POST['end_time']   ?? [];

    // Validate every enabled day before writing anything.
    for ($day = 0; $day <= 6; $day++) {
        if (empty($working[$day])) {
            continue;
        }

        $start = $starts[$day] ?? '';
        $end   = $ends[$day]   ?? '';

        if (!valid_time($start) || !valid_time($end)) {
            $errors[$day] = 'Enter both a start and an end time.';
        } elseif (strtotime($end) <= strtotime($start)) {
            $errors[$day] = 'The finish time must be after the start time.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Replace the week wholesale -- simpler and less error-prone
            // than working out which individual rows changed.
            $stmt = $pdo->prepare('DELETE FROM provider_availability WHERE provider_id = :pid');
            $stmt->execute([':pid' => $providerId]);

            $insert = $pdo->prepare(
                'INSERT INTO provider_availability
                    (provider_id, day_of_week, start_time, end_time, is_available)
                 VALUES (:pid, :dow, :start, :end, 1)'
            );

            $daysSet = 0;
            for ($day = 0; $day <= 6; $day++) {
                if (empty($working[$day])) {
                    continue;
                }
                $insert->execute([
                    ':pid'   => $providerId,
                    ':dow'   => $day,
                    ':start' => $starts[$day] . ':00',
                    ':end'   => $ends[$day] . ':00',
                ]);
                $daysSet++;
            }

            $pdo->commit();

            log_activity('availability_updated', 'providers', $providerId, $daysSet . ' days set');
            flash('success', $daysSet === 0
                ? 'You are now marked unavailable every day. Customers cannot book you until you set some hours.'
                : 'Working hours saved for ' . $daysSet . ' day' . ($daysSet === 1 ? '' : 's') . ' a week.');
            redirect('provider/availability.php');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['form'] = APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'Your hours could not be saved. Please try again.';
        }
    }
}

/* -- Current week ---------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT day_of_week, start_time, end_time, is_available
       FROM provider_availability
      WHERE provider_id = :pid'
);
$stmt->execute([':pid' => $providerId]);

$week = [];
foreach ($stmt->fetchAll() as $row) {
    $week[(int) $row['day_of_week']] = $row;
}

/* -- How many bookings each weekday actually attracts ----------------
   Shown beside each day so the professional can see where demand is. */
$stmt = $pdo->prepare(
    "SELECT DAYOFWEEK(booking_date) - 1 AS dow, COUNT(*) AS n
       FROM bookings
      WHERE provider_id = :pid AND status NOT IN ('cancelled','rejected')
      GROUP BY dow"
);
$stmt->execute([':pid' => $providerId]);

$demand = [];
foreach ($stmt->fetchAll() as $row) {
    $demand[(int) $row['dow']] = (int) $row['n'];
}

$pageTitle   = 'Availability';
$pageHeading = 'Weekly working hours';
$pageLede    = 'Customers can only book inside these windows, so set them to suit you.';

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert--error" role="alert">
        <span class="alert__icon">&#10006;</span>
        <span class="alert__text"><?= e($errors['form']) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid--2 grid--rail">

    <form class="card" method="post" action="availability.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_week">

        <div class="card__head">
            <h3>Set your week</h3>
            <span class="text-small text-muted">Untick a day to mark yourself off</span>
        </div>

        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:44px">Working</th>
                            <th>Day</th>
                            <th>From</th>
                            <th>Until</th>
                            <th class="text-right">Bookings so far</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for ($day = 0; $day <= 6; $day++): ?>
                        <?php
                        $on    = isset($week[$day]) && $week[$day]['is_available'];
                        $start = $on ? substr($week[$day]['start_time'], 0, 5) : '09:00';
                        $end   = $on ? substr($week[$day]['end_time'], 0, 5)   : '18:00';
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="working[<?= $day ?>]" value="1"
                                       id="work<?= $day ?>" <?= $on ? 'checked' : '' ?>
                                       style="accent-color:var(--marigold-500);width:18px;height:18px">
                            </td>
                            <td>
                                <label class="table__primary" for="work<?= $day ?>" style="cursor:pointer">
                                    <?= e($dayNames[$day]) ?>
                                </label>
                                <?php if ($day === 0): ?>
                                    <br><span class="text-small text-muted">Most professionals rest</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input class="input<?= isset($errors[$day]) ? ' is-invalid' : '' ?>"
                                       type="time" name="start_time[<?= $day ?>]"
                                       value="<?= e($start) ?>" style="max-width:130px">
                            </td>
                            <td>
                                <input class="input<?= isset($errors[$day]) ? ' is-invalid' : '' ?>"
                                       type="time" name="end_time[<?= $day ?>]"
                                       value="<?= e($end) ?>" style="max-width:130px">
                                <?php if (isset($errors[$day])): ?>
                                    <div class="error"><?= e($errors[$day]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if (!empty($demand[$day])): ?>
                                    <span class="badge badge--scheduled"><?= (int) $demand[$day] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card__foot">
            <button class="btn btn--accent" type="submit">Save my hours</button>
        </div>
    </form>

    <div>
        <section class="alert alert--info" style="display:block">
            <strong>How this is used</strong>
            <p class="text-small" style="margin:var(--sp-2) 0 0">
                When a customer picks a date, the system reads these hours and offers only the
                start times that fit. It also refuses any slot that would overlap a job you have
                already accepted &mdash; so a two-hour job at 10:00 blocks 11:00 as well, not
                just 10:00.
            </p>
        </section>

        <section class="card">
            <div class="card__head"><h3>Current week</h3></div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                        <?php for ($day = 1; $day <= 6; $day++): ?>
                            <tr>
                                <td class="table__primary"><?= e($dayNames[$day]) ?></td>
                                <td class="text-right">
                                    <?php if (isset($week[$day]) && $week[$day]['is_available']): ?>
                                        <?= e(show_time($week[$day]['start_time'])) ?>
                                        &ndash;
                                        <?= e(show_time($week[$day]['end_time'])) ?>
                                    <?php else: ?>
                                        <span class="badge badge--expired">Off</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endfor; ?>
                        <tr>
                            <td class="table__primary"><?= e($dayNames[0]) ?></td>
                            <td class="text-right">
                                <?php if (isset($week[0]) && $week[0]['is_available']): ?>
                                    <?= e(show_time($week[0]['start_time'])) ?>
                                    &ndash;
                                    <?= e(show_time($week[0]['end_time'])) ?>
                                <?php else: ?>
                                    <span class="badge badge--expired">Off</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
