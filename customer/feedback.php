<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/feedback.php
 *  MODULE  : 7 -- Feedback & Rating
 *  PURPOSE : Leave a star rating and comment for a completed job.
 *
 *  THREE RULES ENFORCED HERE
 *  -------------------------
 *   1. Only the customer who raised the booking may review it.
 *   2. Only a COMPLETED booking may be reviewed -- so a rating always
 *      reflects work that actually happened.
 *   3. One review per booking. The UNIQUE index on feedback.booking_id
 *      is the real guarantee; the check below only produces a friendlier
 *      message than a constraint violation would.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo       = db();
$userId    = current_user_id();
$bookingId = get_int('booking') ?: post_int('booking_id');

/* -- Load the booking, scoped to this customer ----------------------- */
$stmt = $pdo->prepare(
    "SELECT b.*, u.full_name AS provider_name, c.category_name, s.service_name,
            p.provider_id, f.feedback_id
       FROM bookings   b
       JOIN providers  p ON p.provider_id = b.provider_id
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
       LEFT JOIN services s ON s.service_id = b.service_id
       LEFT JOIN feedback f ON f.booking_id = b.booking_id
      WHERE b.booking_id = :bid AND b.user_id = :uid"
);
$stmt->execute([':bid' => $bookingId, ':uid' => $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'That booking was not found.');
    redirect('customer/my-bookings.php');
}
if ($booking['status'] !== 'completed') {
    flash('warning', 'You can rate a job once it has been marked complete.');
    redirect('customer/my-bookings.php');
}
if ($booking['feedback_id']) {
    flash('info', 'You have already reviewed that job. Thank you.');
    redirect('customer/my-bookings.php');
}

$errors = [];
$rating   = post_int('rating');
$comments = post('comments');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_guard();

    if ($rating < 1 || $rating > 5) {
        $errors['rating'] = 'Choose a rating between 1 and 5 stars.';
    }
    if (mb_strlen($comments) > 1000) {
        $errors['comments'] = 'Please keep your review under 1000 characters.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO feedback (booking_id, user_id, provider_id, rating, comments)
                 VALUES (:bid, :uid, :pid, :rating, :comments)'
            );
            $stmt->execute([
                ':bid'      => $bookingId,
                ':uid'      => $userId,
                ':pid'      => (int) $booking['provider_id'],
                ':rating'   => $rating,
                ':comments' => $comments !== '' ? $comments : null,
            ]);

            // Refresh the professional's cached rating from the real rows.
            recalculate_provider_rating($pdo, (int) $booking['provider_id']);

            $stmt = $pdo->prepare(
                'SELECT u.user_id FROM providers p JOIN users u ON u.user_id = p.user_id
                  WHERE p.provider_id = :pid'
            );
            $stmt->execute([':pid' => (int) $booking['provider_id']]);
            $providerUserId = (int) $stmt->fetchColumn();

            notify(
                $pdo,
                $providerUserId,
                'You received a review',
                current_name() . ' rated ' . $booking['booking_code'] . ' ' . $rating . ' out of 5.',
                'provider/dashboard.php',
                'star'
            );

            $pdo->commit();

            log_activity('feedback_submitted', 'bookings', $bookingId, 'Rating: ' . $rating);
            flash('success', 'Thank you. Your review helps the next customer choose well.');
            redirect('customer/my-bookings.php');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23000 = the UNIQUE index caught a double submission.
            $errors['form'] = $exception->getCode() === '23000'
                ? 'You have already reviewed that job.'
                : (APP_DEBUG ? 'Database error: ' . $exception->getMessage()
                             : 'Your review could not be saved. Please try again.');
        }
    }
}

$pageTitle   = 'Leave a rating';
$pageHeading = 'How did it go?';
$pageLede    = 'Your rating is the main thing the next customer will look at.';

include __DIR__ . '/../includes/header.php';
?>

<div class="content--narrow">

    <?= form_error($errors) ?>

    <section class="card">
        <div class="card__body">
            <div class="person u-mb-4">
                <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($booking['provider_name'])) ?></span>
                <div>
                    <div class="person__name"><?= e($booking['provider_name']) ?></div>
                    <div class="person__meta"><?= e($booking['category_name']) ?></div>
                </div>
                <span class="ref u-ml-auto"><?= e($booking['booking_code']) ?></span>
            </div>

            <dl class="jobcard__facts">
                <div><dt>Job</dt><dd><?= e($booking['service_name'] ?: 'General visit') ?></dd></div>
                <div><dt>Date</dt><dd><?= e(show_date($booking['booking_date'])) ?></dd></div>
                <div><dt>Amount charged</dt><dd><?= e(money($booking['final_cost'])) ?></dd></div>
            </dl>
        </div>
    </section>

    <form class="card" method="post" action="feedback.php">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
        <input type="hidden" name="rating" id="ratingValue" value="<?= $rating > 0 ? (int) $rating : '' ?>">

        <div class="card__head"><h3>Your rating</h3></div>

        <div class="card__body">
            <div class="field">
                <span class="label">How would you rate the work? <span class="req">*</span></span>

                <!-- Real buttons rather than styled spans, so the picker
                     is reachable and operable from the keyboard. -->
                <div id="starPicker" data-value="<?= $rating > 0 ? (int) $rating : 0 ?>"
                     style="display:flex;gap:var(--sp-1)"
                     role="group" aria-label="Star rating, 1 to 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button"
                                style="background:none;border:none;font-size:34px;cursor:pointer;
                                       color:var(--star);line-height:1;padding:0 2px"
                                aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">
                            <?= $rating >= $i ? '&#9733;' : '&#9734;' ?>
                        </button>
                    <?php endfor; ?>
                </div>

                <?php if (isset($errors['rating'])): ?>
                    <div class="error"><?= e($errors['rating']) ?></div>
                <?php else: ?>
                    <div class="hint">1 star is poor, 5 stars is excellent.</div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="comments">Tell others what happened</label>
                <textarea class="textarea<?= isset($errors['comments']) ? ' is-invalid' : '' ?>"
                          id="comments" name="comments" rows="5"
                          placeholder="Did they arrive on time? Was the work tidy? Was the price fair?"><?= e($comments) ?></textarea>
                <?php if (isset($errors['comments'])): ?>
                    <div class="error"><?= e($errors['comments']) ?></div>
                <?php else: ?>
                    <div class="hint">Optional, but a sentence or two is far more useful than stars alone.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card__foot">
            <div class="btn-row">
                <button class="btn btn--accent btn--lg" type="submit">Submit my review</button>
                <a class="btn btn--ghost" href="my-bookings.php">Not now</a>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
