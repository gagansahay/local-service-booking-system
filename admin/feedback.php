<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : admin/feedback.php
 *  MODULE  : 7 -- Feedback & Rating, administered by Module 2
 *  PURPOSE : Moderate customer reviews.
 *
 *  Hiding a review is not simply cosmetic: recalculate_provider_rating()
 *  counts only approved rows, so withdrawing an abusive review also
 *  removes its effect on the professional's average. The review itself
 *  is never deleted, which keeps the moderation decision auditable.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

/* =====================================================================
 * APPROVE / HIDE
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'moderate') {

    csrf_guard();

    $feedbackId = post_int('feedback_id');
    $approve    = post('approve') === '1' ? 1 : 0;

    $stmt = $pdo->prepare('SELECT provider_id FROM feedback WHERE feedback_id = :fid');
    $stmt->execute([':fid' => $feedbackId]);
    $providerId = (int) $stmt->fetchColumn();

    if ($providerId === 0) {
        flash('error', 'That review was not found.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE feedback SET is_approved = :ok WHERE feedback_id = :fid');
            $stmt->execute([':ok' => $approve, ':fid' => $feedbackId]);

            // The professional's average must follow the moderation
            // decision immediately, or the two would disagree.
            recalculate_provider_rating($pdo, $providerId);

            $pdo->commit();

            log_activity($approve ? 'review_approved' : 'review_hidden', 'feedback', $feedbackId);
            flash('success', $approve
                ? 'The review is visible again and counts towards the rating.'
                : 'The review is hidden and no longer counts towards the rating.');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'The review could not be updated. Please try again.');
        }
    }

    redirect('admin/feedback.php?status=' . urlencode(get('status', 'all')));
}

/* =====================================================================
 * LISTING
 * ===================================================================*/
$filter = get('status', 'all');
$tabs   = ['all' => 'All', 'approved' => 'Visible', 'hidden' => 'Hidden', 'low' => 'Low ratings'];
if (!isset($tabs[$filter])) {
    $filter = 'all';
}

$where  = [];
$params = [];

if ($filter === 'approved') {
    $where[] = 'f.is_approved = 1';
} elseif ($filter === 'hidden') {
    $where[] = 'f.is_approved = 0';
} elseif ($filter === 'low') {
    // Low ratings are what an administrator most needs to see: they are
    // either a genuine service problem or an unfair review.
    $where[] = 'f.rating <= 2';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stats = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(ROUND(AVG(CASE WHEN is_approved = 1 THEN rating END), 2), 0) AS avg_rating,
            SUM(is_approved = 0) AS hidden,
            SUM(rating <= 2)     AS low
       FROM feedback'
)->fetch();

$sql = "SELECT f.*, b.booking_code, b.booking_date, b.final_cost,
               cu.full_name AS customer_name,
               pu.full_name AS provider_name,
               c.category_name
          FROM feedback   f
          JOIN bookings   b  ON b.booking_id  = f.booking_id
          JOIN users      cu ON cu.user_id    = f.user_id
          JOIN providers  p  ON p.provider_id = f.provider_id
          JOIN users      pu ON pu.user_id    = p.user_id
          JOIN categories c  ON c.category_id = p.category_id
          $whereSql
         ORDER BY f.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

/* -- Rating distribution across the platform -------------------------- */
$distribution = array_fill(1, 5, 0);
foreach ($pdo->query(
    'SELECT rating, COUNT(*) AS n FROM feedback WHERE is_approved = 1 GROUP BY rating'
)->fetchAll() as $row) {
    $distribution[(int) $row['rating']] = (int) $row['n'];
}
$approvedTotal = array_sum($distribution);

$pageTitle   = 'Reviews';
$pageHeading = 'Customer reviews';
$pageLede    = 'Moderate what customers have written. Hiding a review also removes it from the rating.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--4 u-mb-6">
    <div class="stat stat--accent">
        <div class="stat__label">Average rating</div>
        <div class="stat__value"><?= number_format((float) $stats['avg_rating'], 2) ?></div>
        <div class="stat__meta">Across visible reviews</div>
    </div>
    <div class="stat stat--blue">
        <div class="stat__label">Total reviews</div>
        <div class="stat__value"><?= (int) $stats['total'] ?></div>
        <div class="stat__meta">Since launch</div>
    </div>
    <div class="stat stat--danger">
        <div class="stat__label">Low ratings</div>
        <div class="stat__value"><?= (int) $stats['low'] ?></div>
        <div class="stat__meta">Two stars or fewer</div>
    </div>
    <div class="stat">
        <div class="stat__label">Hidden</div>
        <div class="stat__value"><?= (int) $stats['hidden'] ?></div>
        <div class="stat__meta">Withdrawn by moderation</div>
    </div>
</div>

<div class="grid grid--2 grid--rail">

    <div>
        <?= filter_tabs('feedback.php', $tabs, $filter, [], 'btn-row u-mb-4') ?>

        <?php if (!$reviews): ?>
            <div class="card">
                <div class="empty">
                    <div class="empty__icon" aria-hidden="true">&#9733;</div>
                    <h3>No reviews in this view</h3>
                    <p>Reviews appear here as soon as customers rate completed jobs.</p>
                    <a class="btn btn--primary" href="feedback.php">Show all reviews</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
                <section class="card" style="<?= $r['is_approved'] ? '' : 'opacity:.7' ?>">
                    <div class="card__body">
                        <div class="jobcard__top">
                            <div class="person">
                                <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($r['customer_name'])) ?></span>
                                <div>
                                    <div class="person__name"><?= e($r['customer_name']) ?></div>
                                    <div class="person__meta">
                                        on <?= e($r['provider_name']) ?>
                                        &middot; <?= e($r['category_name']) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <?= star_rating((float) $r['rating']) ?>
                                <div class="u-mt-1">
                                    <?= $r['is_approved']
                                        ? '<span class="badge badge--active">Visible</span>'
                                        : '<span class="badge badge--expired">Hidden</span>' ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($r['comments'])): ?>
                            <p style="margin:var(--sp-3) 0"><?= e($r['comments']) ?></p>
                        <?php else: ?>
                            <p class="text-small text-muted" style="margin:var(--sp-3) 0">
                                No written comment &mdash; rating only.
                            </p>
                        <?php endif; ?>

                        <div class="jobcard__foot">
                            <span class="text-small text-muted">
                                <span class="ref"><?= e($r['booking_code']) ?></span>
                                &middot; job on <?= e(show_date($r['booking_date'])) ?>
                                &middot; <?= e(money($r['final_cost'])) ?>
                                &middot; reviewed <?= e(show_datetime($r['created_at'])) ?>
                            </span>

                            <form method="post" action="feedback.php?status=<?= e($filter) ?>"
                                  class="u-ml-auto">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="moderate">
                                <input type="hidden" name="feedback_id" value="<?= (int) $r['feedback_id'] ?>">
                                <input type="hidden" name="approve" value="<?= $r['is_approved'] ? '0' : '1' ?>">
                                <button class="btn btn--outline btn--sm" type="submit"
                                        data-confirm="<?= $r['is_approved']
                                            ? 'Hide this review? It will stop counting towards the rating.'
                                            : 'Make this review visible again?' ?>">
                                    <?= $r['is_approved'] ? 'Hide review' : 'Make visible' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ---------------- Distribution ------------------------------ -->
    <section class="card">
        <div class="card__head"><h3>Rating distribution</h3></div>
        <div class="card__body">
            <?php if ($approvedTotal === 0): ?>
                <p class="text-small text-muted">No visible reviews yet.</p>
            <?php else: ?>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php $pct = round($distribution[$star] / $approvedTotal * 100); ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                        <span class="text-small" style="width:48px"><?= $star ?> star</span>
                        <div class="meter" style="flex:1;margin:0;height:10px">
                            <div class="meter__fill"
                                 style="width:<?= $pct ?>%;background:<?= $star >= 4
                                     ? 'var(--green-600)' : ($star === 3 ? 'var(--marigold-500)' : 'var(--red-600)') ?>"></div>
                        </div>
                        <span class="text-small text-muted" style="width:52px;text-align:right">
                            <?= $distribution[$star] ?> &middot; <?= $pct ?>%
                        </span>
                    </div>
                <?php endfor; ?>
            <?php endif; ?>

            <div class="alert alert--info u-mt-4">
                <span class="alert__icon">&#8505;</span>
                <span class="alert__text">
                    Hidden reviews stay in the database so the moderation decision remains
                    auditable &mdash; they are excluded from the average rather than erased.
                </span>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
