<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/provider-view.php
 *  MODULE  : 4 -- Customer  (with Module 7, Feedback & Rating)
 *  PURPOSE : Full public profile for one professional: skills, rates,
 *            services offered, weekly working hours and every approved
 *            review.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo        = db();
$providerId = get_int('id');

if ($providerId <= 0) {
    flash('error', 'No professional was specified.');
    redirect('customer/search.php');
}

/* -- The professional ------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT d.*, p.bio
       FROM vw_provider_directory d
       JOIN providers p ON p.provider_id = d.provider_id
      WHERE d.provider_id = :pid
        AND d.verification_status = 'verified'
        AND d.account_status = 'active'"
);
$stmt->execute([':pid' => $providerId]);
$provider = $stmt->fetch();

if (!$provider) {
    flash('error', 'That professional is no longer listed.');
    redirect('customer/search.php');
}

/* -- Services offered ------------------------------------------------ */
$stmt = $pdo->prepare(
    'SELECT service_id, service_name, description, base_price, duration_minutes
       FROM services
      WHERE provider_id = :pid AND is_active = 1
      ORDER BY base_price ASC'
);
$stmt->execute([':pid' => $providerId]);
$services = $stmt->fetchAll();

/* -- Weekly availability --------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT day_of_week, start_time, end_time, is_available
       FROM provider_availability
      WHERE provider_id = :pid
      ORDER BY day_of_week'
);
$stmt->execute([':pid' => $providerId]);

$availability = [];
foreach ($stmt->fetchAll() as $row) {
    $availability[(int) $row['day_of_week']] = $row;
}

/* -- Approved reviews ------------------------------------------------ */
$stmt = $pdo->prepare(
    'SELECT f.rating, f.comments, f.created_at, u.full_name, b.booking_date, c.category_name
       FROM feedback   f
       JOIN users      u ON u.user_id     = f.user_id
       JOIN bookings   b ON b.booking_id  = f.booking_id
       JOIN providers  p ON p.provider_id = f.provider_id
       JOIN categories c ON c.category_id = p.category_id
      WHERE f.provider_id = :pid AND f.is_approved = 1
      ORDER BY f.created_at DESC
      LIMIT 10'
);
$stmt->execute([':pid' => $providerId]);
$reviews = $stmt->fetchAll();

/* -- Rating distribution, for the summary bar chart ------------------ */
$stmt = $pdo->prepare(
    'SELECT rating, COUNT(*) AS n
       FROM feedback
      WHERE provider_id = :pid AND is_approved = 1
      GROUP BY rating'
);
$stmt->execute([':pid' => $providerId]);

$distribution = array_fill(1, 5, 0);
foreach ($stmt->fetchAll() as $row) {
    $distribution[(int) $row['rating']] = (int) $row['n'];
}
$totalReviews = array_sum($distribution);

/* -- Any AMC plans this professional services ------------------------ */
$stmt = $pdo->prepare(
    'SELECT plan_id, plan_name, frequency, visits_per_year, price
       FROM maintenance_plans
      WHERE category_id = :cid AND is_active = 1
      ORDER BY price'
);
$stmt->execute([':cid' => (int) $provider['category_id']]);
$plans = $stmt->fetchAll();

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$pageTitle   = $provider['full_name'];
$pageHeading = '';
$pageActions = '';

include __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:var(--sp-4)">
    <a class="btn btn--ghost btn--sm" href="search.php">&larr; Back to search</a>
</p>

<div class="grid grid--2" style="align-items:start;grid-template-columns:1.6fr 1fr">

    <!-- ================= LEFT: profile ============================= -->
    <div>
        <section class="card">
            <div class="card__body">
                <div class="contract-head" style="border-bottom:none;padding-bottom:0">
                    <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($provider['full_name'])) ?></span>
                    <div class="contract-head__plan">
                        <h1 style="font-size:var(--text-xl);margin-bottom:2px"><?= e($provider['full_name']) ?></h1>
                        <p class="provider-card__cat" style="margin:0"><?= e($provider['category_name']) ?></p>
                        <div style="margin-top:var(--sp-2)">
                            <?= star_rating((float) $provider['avg_rating'], (int) $provider['total_reviews']) ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="provider-card__rate"><?= e(money($provider['hourly_rate'])) ?><small>/hr</small></div>
                        <span class="badge badge--verified" style="margin-top:var(--sp-2)">Verified</span>
                    </div>
                </div>

                <dl class="jobcard__facts" style="margin-top:var(--sp-5)">
                    <div><dt>Experience</dt><dd><?= (int) $provider['experience_years'] ?> years</dd></div>
                    <div><dt>Jobs completed</dt><dd><?= (int) $provider['total_jobs'] ?></dd></div>
                    <div><dt>Based in</dt><dd><?= e($provider['city'] ?: 'Not stated') ?></dd></div>
                    <div><dt>Serves</dt><dd><?= e($provider['service_area'] ?: 'On request') ?></dd></div>
                </dl>

                <?php if (!empty($provider['bio'])): ?>
                    <h3 style="margin-top:var(--sp-5)">About</h3>
                    <p style="margin:0"><?= e($provider['bio']) ?></p>
                <?php endif; ?>

                <?php if (!empty($provider['skills'])): ?>
                    <h3 style="margin-top:var(--sp-5)">Skills</h3>
                    <div class="btn-row">
                        <?php foreach (array_filter(array_map('trim', explode(',', $provider['skills']))) as $skill): ?>
                            <span class="badge badge--scheduled"><?= e($skill) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ---------------- Services ------------------------------- -->
        <?php if ($services): ?>
        <section class="card">
            <div class="card__head"><h3>Services and prices</h3></div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Duration</th>
                                <th class="text-right">From</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $s): ?>
                                <tr>
                                    <td>
                                        <span class="table__primary"><?= e($s['service_name']) ?></span><br>
                                        <span class="text-small text-muted"><?= e(excerpt($s['description'], 70)) ?></span>
                                    </td>
                                    <td><?= (int) $s['duration_minutes'] ?> min</td>
                                    <td class="text-right table__primary"><?= e(money($s['base_price'])) ?></td>
                                    <td class="text-right">
                                        <a class="btn btn--accent btn--sm"
                                           href="book.php?provider=<?= (int) $providerId ?>&amp;service=<?= (int) $s['service_id'] ?>">
                                            Book
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ---------------- Reviews -------------------------------- -->
        <section class="card">
            <div class="card__head">
                <h3>Reviews</h3>
                <span class="text-small text-muted"><?= $totalReviews ?> total</span>
            </div>
            <div class="card__body">
                <?php if (!$reviews): ?>
                    <div class="empty" style="padding:var(--sp-8) 0">
                        <div class="empty__icon" aria-hidden="true">&#9734;</div>
                        <h3>No reviews yet</h3>
                        <p>This professional has not been rated. Reviews can only be left after a job is marked complete.</p>
                    </div>
                <?php else: ?>

                    <!-- Rating distribution. Bars are sized from the real
                         counts, so this is data, not decoration. -->
                    <div style="margin-bottom:var(--sp-6)">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <?php $pct = $totalReviews > 0 ? round($distribution[$star] / $totalReviews * 100) : 0; ?>
                            <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:6px">
                                <span class="text-small" style="width:44px"><?= $star ?> star</span>
                                <div class="meter" style="flex:1;margin:0;height:8px">
                                    <div class="meter__fill" style="width:<?= $pct ?>%;background:var(--marigold-500)"></div>
                                </div>
                                <span class="text-small text-muted" style="width:28px;text-align:right"><?= $distribution[$star] ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <?php foreach ($reviews as $r): ?>
                        <div style="padding:var(--sp-4) 0;border-top:1px solid var(--line-soft)">
                            <div class="person" style="margin-bottom:var(--sp-2)">
                                <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($r['full_name'])) ?></span>
                                <div>
                                    <div class="person__name"><?= e($r['full_name']) ?></div>
                                    <div class="person__meta">
                                        <?= e($r['category_name']) ?> &middot; <?= e(show_date($r['booking_date'])) ?>
                                    </div>
                                </div>
                                <div style="margin-left:auto"><?= star_rating((float) $r['rating']) ?></div>
                            </div>
                            <?php if (!empty($r['comments'])): ?>
                                <p style="margin:0"><?= e($r['comments']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ================= RIGHT: booking rail ====================== -->
    <div>
        <section class="card">
            <div class="card__body">
                <h3>Book <?= e(explode(' ', $provider['full_name'])[0]) ?></h3>
                <p class="text-small text-muted">
                    Pick a slot inside the working hours below. Times already taken cannot be selected.
                </p>
                <a class="btn btn--accent btn--block btn--lg"
                   href="book.php?provider=<?= (int) $providerId ?>">Choose a date and time</a>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h3>Working hours</h3></div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <?php for ($d = 1; $d <= 6; $d++): ?>
                                <tr>
                                    <td class="table__primary"><?= e($dayNames[$d]) ?></td>
                                    <td class="text-right">
                                        <?php if (isset($availability[$d]) && $availability[$d]['is_available']): ?>
                                            <?= e(show_time($availability[$d]['start_time'])) ?>
                                            &ndash;
                                            <?= e(show_time($availability[$d]['end_time'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not working</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                            <tr>
                                <td class="table__primary">Sunday</td>
                                <td class="text-right"><span class="text-muted">Not working</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if ($plans): ?>
        <section class="card">
            <div class="card__head"><h3>Maintenance plans</h3></div>
            <div class="card__body">
                <p class="text-small text-muted">
                    Cover a whole year in one go. Visits are scheduled automatically.
                </p>
                <?php foreach ($plans as $plan): ?>
                    <div style="padding:var(--sp-3) 0;border-top:1px solid var(--line-soft)">
                        <div style="display:flex;justify-content:space-between;gap:var(--sp-3);align-items:baseline">
                            <strong style="color:var(--ink-900)"><?= e($plan['plan_name']) ?></strong>
                            <span class="table__primary"><?= e(money($plan['price'])) ?></span>
                        </div>
                        <div class="text-small text-muted">
                            <?= e(frequency_label($plan['frequency'])) ?> &middot;
                            <?= (int) $plan['visits_per_year'] ?> visits a year
                        </div>
                        <a class="btn btn--outline btn--sm" style="margin-top:var(--sp-2)"
                           href="maintenance.php?subscribe=<?= (int) $plan['plan_id'] ?>&amp;provider=<?= (int) $providerId ?>">
                            Subscribe
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
