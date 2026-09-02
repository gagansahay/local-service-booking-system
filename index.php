<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : index.php
 *  PURPOSE : Public landing page. Anyone may browse trades and see how
 *            many verified professionals are listed; booking requires
 *            an account.
 *
 *  The hero leads with the search control itself rather than a slogan,
 *  because finding a professional is the page's single job.
 * =====================================================================
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

/* -- Categories, each with a live count of verified professionals ---- */
$categories = $pdo->query(
    "SELECT c.category_id, c.category_name, c.description, c.icon,
            COUNT(p.provider_id) AS provider_count
       FROM categories c
       LEFT JOIN providers p
              ON p.category_id = c.category_id
             AND p.verification_status = 'verified'
      WHERE c.is_active = 1
      GROUP BY c.category_id, c.category_name, c.description, c.icon
      ORDER BY c.category_name"
)->fetchAll();

/* -- Headline figures ------------------------------------------------ */
$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM providers WHERE verification_status = 'verified') AS providers,
        (SELECT COUNT(*) FROM categories WHERE is_active = 1)                   AS categories,
        (SELECT COUNT(*) FROM bookings  WHERE status = 'completed')             AS jobs_done,
        (SELECT COUNT(*) FROM maintenance_contracts WHERE status = 'active')    AS amc_active"
)->fetch();

/* -- Highest-rated professionals, for the "trusted" strip ------------ */
$topProviders = $pdo->query(
    "SELECT provider_id, full_name, category_name, city, hourly_rate,
            avg_rating, total_reviews, total_jobs, experience_years
       FROM vw_provider_directory
      WHERE verification_status = 'verified'
        AND account_status = 'active'
      ORDER BY avg_rating DESC, total_reviews DESC
      LIMIT 4"
)->fetchAll();

/* -- Distinct cities, to populate the hero search -------------------- */
$cities = $pdo->query(
    "SELECT DISTINCT u.city
       FROM users u
       JOIN providers p ON p.user_id = u.user_id
      WHERE u.city IS NOT NULL AND u.city <> ''
        AND p.verification_status = 'verified'
      ORDER BY u.city"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?></title>
<meta name="description" content="<?= e(APP_TAGLINE) ?>. Book verified plumbers, electricians, carpenters and cleaners near you.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(ASSETS_URL) ?>css/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%2316233A'/><text x='16' y='22' font-size='16' font-family='sans-serif' font-weight='700' fill='%23F0A202' text-anchor='middle'>L</text></svg>">
</head>
<body>

<a class="skip-link" href="#categories">Skip to service categories</a>

<!-- ==================== NAVIGATION ============================== -->
<nav class="public-nav">
    <div class="public-nav__inner">
        <a class="brand" href="<?= e(BASE_URL) ?>index.php">
            <span class="brand__mark" aria-hidden="true">LS</span>
            <span>
                <span class="brand__text">Local Service</span><br>
                <span class="brand__sub">Booking &amp; Management</span>
            </span>
        </a>

        <div class="public-nav__links">
            <a href="#categories">Services</a>
            <a href="#how">How it works</a>
            <a href="#about">About</a>

            <?php if (is_logged_in()): ?>
                <a class="btn btn--primary btn--sm"
                   href="<?= e(BASE_URL . home_for_role(current_role())) ?>">My dashboard</a>
            <?php else: ?>
                <a href="<?= e(BASE_URL) ?>auth/login.php">Sign in</a>
                <a class="btn btn--accent btn--sm" href="<?= e(BASE_URL) ?>auth/register.php">Create account</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ==================== HERO ==================================== -->
<header class="hero">
    <div class="hero__inner">
        <p class="eyebrow" style="color:var(--marigold-500)">Verified local professionals</p>
        <h1>Find a trusted professional for the job at hand.</h1>
        <p class="hero__lede">
            Plumbers, electricians, carpenters, AC technicians and cleaners across Delhi NCR
            and Meerut &mdash; each one verified before they appear here.
        </p>

        <form class="hero__search" method="get" action="<?= e(BASE_URL) ?>customer/search.php">
            <label class="visually-hidden" for="heroCategory">Service needed</label>
            <select class="select" id="heroCategory" name="category">
                <option value="">What do you need?</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>"><?= e($c['category_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="visually-hidden" for="heroCity">City</label>
            <select class="select" id="heroCity" name="city">
                <option value="">Any city</option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= e($city) ?>"><?= e($city) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn--accent btn--lg" type="submit">Search</button>
        </form>

        <div class="hero__stats">
            <div class="hero__stat">
                <b><?= (int) $stats['providers'] ?></b>
                <span>Verified pros</span>
            </div>
            <div class="hero__stat">
                <b><?= (int) $stats['categories'] ?></b>
                <span>Trades covered</span>
            </div>
            <div class="hero__stat">
                <b><?= (int) $stats['jobs_done'] ?></b>
                <span>Jobs completed</span>
            </div>
            <div class="hero__stat">
                <b><?= (int) $stats['amc_active'] ?></b>
                <span>Active AMC plans</span>
            </div>
        </div>
    </div>
</header>

<!-- ==================== CATEGORIES ============================== -->
<section class="section" id="categories">
    <div class="section__inner">
        <div class="section__head">
            <p class="eyebrow">Browse by trade</p>
            <h2>What needs doing?</h2>
            <p>Pick a trade to see who is available near you, what they charge and how they are rated.</p>
        </div>

        <div class="cat-grid">
            <?php foreach ($categories as $c): ?>
                <a class="cat-tile" href="<?= e(BASE_URL) ?>customer/search.php?category=<?= (int) $c['category_id'] ?>">
                    <div class="cat-tile__icon" aria-hidden="true"><?= e($c['icon']) ?></div>
                    <span class="cat-tile__name"><?= e($c['category_name']) ?></span>
                    <span class="cat-tile__count">
                        <?php $n = (int) $c['provider_count']; ?>
                        <?= $n === 0 ? 'Coming soon' : $n . ' pro' . ($n === 1 ? '' : 's') ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== TOP RATED =============================== -->
<?php if ($topProviders): ?>
<section class="section section--paper">
    <div class="section__inner">
        <div class="section__head">
            <p class="eyebrow">Highest rated</p>
            <h2>Professionals customers come back to</h2>
            <p>Ratings come only from customers whose job was actually marked complete.</p>
        </div>

        <div class="grid grid--4">
            <?php foreach ($topProviders as $p): ?>
                <article class="provider-card">
                    <div class="provider-card__head">
                        <span class="avatar" aria-hidden="true"><?= e(initials($p['full_name'])) ?></span>
                        <div>
                            <div class="provider-card__name"><?= e($p['full_name']) ?></div>
                            <div class="provider-card__cat"><?= e($p['category_name']) ?></div>
                        </div>
                    </div>

                    <?= star_rating((float) $p['avg_rating'], (int) $p['total_reviews']) ?>

                    <div class="provider-card__facts">
                        <span><b><?= (int) $p['experience_years'] ?></b> yrs exp.</span>
                        <span><b><?= (int) $p['total_jobs'] ?></b> jobs done</span>
                    </div>

                    <div class="provider-card__foot">
                        <span class="provider-card__rate">
                            <?= e(money($p['hourly_rate'])) ?><small>/hr</small>
                        </span>
                        <a class="btn btn--outline btn--sm" style="margin-left:auto"
                           href="<?= e(BASE_URL) ?>customer/provider-view.php?id=<?= (int) $p['provider_id'] ?>">
                            View profile
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ==================== HOW IT WORKS ============================ -->
<!-- Numbered because this genuinely is a sequence: each step depends
     on the one before it. -->
<section class="section" id="how">
    <div class="section__inner">
        <div class="section__head">
            <p class="eyebrow">How it works</p>
            <h2>From search to signed-off, in four steps</h2>
        </div>

        <div class="steps">
            <div class="step">
                <h4>Search</h4>
                <p>Filter by trade, city, rating and hourly rate to shortlist the right professional.</p>
            </div>
            <div class="step">
                <h4>Book a slot</h4>
                <p>Pick a date and time. Slots already taken, or outside the professional's working hours, cannot be selected.</p>
            </div>
            <div class="step">
                <h4>Track the job</h4>
                <p>Watch the request move from pending to confirmed, in progress and complete, with a notification at each step.</p>
            </div>
            <div class="step">
                <h4>Pay and rate</h4>
                <p>Settle the invoice, then leave a rating that helps the next customer choose well.</p>
            </div>
        </div>
    </div>
</section>

<!-- ==================== MAINTENANCE ============================== -->
<section class="section section--paper">
    <div class="section__inner">
        <div class="grid grid--2" style="align-items:center">
            <div>
                <p class="eyebrow">Annual Maintenance Contracts</p>
                <h2>Stop remembering. Start scheduling.</h2>
                <p>
                    An AMC plan books your recurring servicing for the whole year in one go.
                    The system generates each visit on schedule, reminds you when one falls due,
                    and keeps a permanent record of what was done and by whom.
                </p>
                <p>
                    Useful for AC servicing, plumbing inspections, electrical safety audits and
                    routine deep cleaning &mdash; the jobs that only get noticed once they have
                    been neglected too long.
                </p>
                <a class="btn btn--primary" href="<?= e(BASE_URL) ?>auth/register.php">Browse maintenance plans</a>
            </div>

            <div class="card" style="margin:0">
                <div class="card__head">
                    <h3>AC Quarterly Care</h3>
                    <span class="badge badge--active">Example</span>
                </div>
                <div class="card__body">
                    <p class="text-small text-muted">
                        Four scheduled services a year. Here is how a live contract looks after
                        two completed visits, with the third now due.
                    </p>

                    <!-- The AMC visit strip: the signature component of this
                         interface, modelled on the service card stuck to the
                         back of an appliance. -->
                    <div class="visit-strip">
                        <div class="visit-stamp visit-stamp--completed">
                            <span class="visit-stamp__disc">1</span>
                            <span class="visit-stamp__label">09 Mar</span>
                        </div>
                        <div class="visit-stamp visit-stamp--completed">
                            <span class="visit-stamp__disc">2</span>
                            <span class="visit-stamp__label">11 Jun</span>
                        </div>
                        <div class="visit-stamp visit-stamp--due">
                            <span class="visit-stamp__disc">3</span>
                            <span class="visit-stamp__label">09 Sep</span>
                        </div>
                        <div class="visit-stamp">
                            <span class="visit-stamp__disc">4</span>
                            <span class="visit-stamp__label">09 Dec</span>
                        </div>
                    </div>

                    <div class="jobcard__facts" style="border-bottom:none;padding-bottom:0">
                        <div><dt>Frequency</dt><dd>Every 3 months</dd></div>
                        <div><dt>Visits used</dt><dd>2 of 4</dd></div>
                        <div><dt>Plan price</dt><dd><?= e(money(2400)) ?>/year</dd></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==================== ABOUT (academic context) ================= -->
<section class="section" id="about">
    <div class="section__inner">
        <div class="card" style="max-width:760px;margin:0 auto">
            <div class="card__head"><h3>About this project</h3></div>
            <div class="card__body">
                <p class="text-small">
                    This application was developed as the final-semester project for
                    <strong><?= e(COURSE_CODE) ?></strong>, part of the
                    <?= e(STUDENT_PROGRAMME) ?> programme at the Indira Gandhi National Open University.
                </p>
                <div class="jobcard__facts" style="border-bottom:none">
                    <div><dt>Student</dt><dd><?= e(STUDENT_NAME) ?></dd></div>
                    <div><dt>Enrolment no.</dt><dd class="ref"><?= e(STUDENT_ENROLMENT) ?></dd></div>
                    <div><dt>Project guide</dt><dd><?= e(GUIDE_NAME) ?></dd></div>
                    <div><dt>Regional centre</dt><dd><?= e(REGIONAL_CENTRE) ?></dd></div>
                    <div><dt>Study centre</dt><dd><?= e(STUDY_CENTRE) ?></dd></div>
                    <div><dt>Built with</dt><dd>PHP, MySQL, HTML5, CSS3, JavaScript</dd></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==================== FOOTER ================================== -->
<footer class="site-foot">
    <div class="site-foot__inner">
        <div class="site-foot__cols">
            <div>
                <h4><?= e(APP_NAME) ?></h4>
                <p style="margin:0;max-width:34ch"><?= e(APP_TAGLINE) ?>.</p>
            </div>
            <div>
                <h4>Services</h4>
                <?php foreach (array_slice($categories, 0, 5) as $c): ?>
                    <a href="<?= e(BASE_URL) ?>customer/search.php?category=<?= (int) $c['category_id'] ?>"><?= e($c['category_name']) ?></a>
                <?php endforeach; ?>
            </div>
            <div>
                <h4>Account</h4>
                <a href="<?= e(BASE_URL) ?>auth/login.php">Sign in</a>
                <a href="<?= e(BASE_URL) ?>auth/register.php">Create an account</a>
                <a href="<?= e(BASE_URL) ?>auth/register.php">List your trade</a>
            </div>
            <div>
                <h4>Project</h4>
                <a href="#about">About this project</a>
                <a href="#how">How it works</a>
            </div>
        </div>

        <div class="site-foot__bar">
            <span><?= e(COURSE_CODE) ?> &middot; <?= e(STUDENT_NAME) ?> &middot; Enrolment <?= e(STUDENT_ENROLMENT) ?></span>
            <span>School of Computer and Information Sciences, IGNOU</span>
        </div>
    </div>
</footer>

<script>window.LSBMS_BASE = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= e(ASSETS_URL) ?>js/main.js" defer></script>
</body>
</html>
