<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/search.php
 *  MODULE  : 11 -- Search & Filter  (with Module 4, Customer)
 *  PURPOSE : Find a verified professional by trade, city, rating and
 *            hourly rate, with pagination.
 *
 *  NOTE ON QUERY BUILDING
 *  ----------------------
 *  The WHERE clause is assembled from a fixed set of SQL fragments and
 *  the user's values are bound as parameters. No user input is ever
 *  concatenated into the SQL text. The ORDER BY column cannot be bound
 *  as a parameter, so it is resolved through a whitelist array instead.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo = db();

/* -- Filter values off the query string ------------------------------ */
$categoryId = get_int('category');
$city       = get('city');
$minRating  = get('rating');
$maxRate    = get('max_rate');
$keyword    = get('q');
$sortKey    = get('sort', 'rating');
$page       = max(1, get_int('page', 1));

/* -- Build the WHERE clause ------------------------------------------ */
$where  = ["verification_status = 'verified'", "account_status = 'active'"];
$params = [];

if ($categoryId > 0) {
    $where[] = 'category_id = :category_id';
    $params[':category_id'] = $categoryId;
}
if ($city !== '') {
    $where[] = 'city = :city';
    $params[':city'] = $city;
}
if ($minRating !== '' && is_numeric($minRating)) {
    $where[] = 'avg_rating >= :min_rating';
    $params[':min_rating'] = (float) $minRating;
}
if ($maxRate !== '' && is_numeric($maxRate)) {
    $where[] = 'hourly_rate <= :max_rate';
    $params[':max_rate'] = (float) $maxRate;
}
if ($keyword !== '') {
    $where[] = '(full_name LIKE :kw1 OR skills LIKE :kw2 OR service_area LIKE :kw3)';
    // The wildcards are added to the VALUE, not to the SQL, so the
    // bound parameter stays a plain string.
    $like = '%' . $keyword . '%';
    $params[':kw1'] = $like;
    $params[':kw2'] = $like;
    $params[':kw3'] = $like;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* -- Sorting. Whitelisted, because a column name cannot be bound. ---- */
$sorts = [
    'rating'     => ['avg_rating DESC, total_reviews DESC', 'Highest rated'],
    'price_low'  => ['hourly_rate ASC',                     'Lowest hourly rate'],
    'price_high' => ['hourly_rate DESC',                    'Highest hourly rate'],
    'experience' => ['experience_years DESC',               'Most experienced'],
    'jobs'       => ['total_jobs DESC',                     'Most jobs completed'],
];
if (!isset($sorts[$sortKey])) {
    $sortKey = 'rating';
}
$orderSql = $sorts[$sortKey][0];

/* -- Count, then fetch one page -------------------------------------- */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vw_provider_directory $whereSql");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalRows / RECORDS_PER_PAGE);
$page       = $totalPages > 0 ? min($page, $totalPages) : 1;
$offset     = ($page - 1) * RECORDS_PER_PAGE;

// LIMIT and OFFSET are cast to int and interpolated because MySQL will
// not accept them as bound parameters in prepared statements. They come
// from get_int(), so they can only ever be integers.
$sql = "SELECT * FROM vw_provider_directory
        $whereSql
        ORDER BY $orderSql
        LIMIT " . (int) RECORDS_PER_PAGE . " OFFSET " . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$providers = $stmt->fetchAll();

/* -- Filter dropdown sources ----------------------------------------- */
$categories = $pdo->query(
    'SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name'
)->fetchAll();

$cities = $pdo->query(
    "SELECT DISTINCT city FROM vw_provider_directory
      WHERE verification_status = 'verified' AND city IS NOT NULL AND city <> ''
      ORDER BY city"
)->fetchAll(PDO::FETCH_COLUMN);

/* Preserve the active filters across pagination links. */
$queryParts = array_filter([
    'category' => $categoryId > 0 ? $categoryId : null,
    'city'     => $city    !== '' ? $city    : null,
    'rating'   => $minRating !== '' ? $minRating : null,
    'max_rate' => $maxRate !== '' ? $maxRate : null,
    'q'        => $keyword !== '' ? $keyword : null,
    'sort'     => $sortKey !== 'rating' ? $sortKey : null,
], static fn($v) => $v !== null);
$baseQuery = http_build_query($queryParts);

$activeCategoryName = '';
foreach ($categories as $c) {
    if ((int) $c['category_id'] === $categoryId) {
        $activeCategoryName = $c['category_name'];
    }
}

$pageTitle   = 'Find a professional';
$pageHeading = $activeCategoryName !== '' ? $activeCategoryName : 'Find a professional';
$pageLede    = $totalRows === 1
    ? '1 verified professional matches your filters.'
    : $totalRows . ' verified professionals match your filters.';

include __DIR__ . '/../includes/header.php';
?>

<!-- ==================== FILTERS ================================== -->
<form class="filters" method="get" action="search.php">
    <div class="filters__row">
        <div>
            <label class="label" for="q">Search</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($keyword) ?>"
                   placeholder="Name, skill or area">
        </div>

        <div>
            <label class="label" for="category">Trade</label>
            <select class="select" id="category" name="category">
                <option value="">All trades</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>"
                        <?= $categoryId === (int) $c['category_id'] ? 'selected' : '' ?>>
                        <?= e($c['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="label" for="city">City</label>
            <select class="select" id="city" name="city">
                <option value="">Any city</option>
                <?php foreach ($cities as $cityOption): ?>
                    <option value="<?= e($cityOption) ?>" <?= $city === $cityOption ? 'selected' : '' ?>>
                        <?= e($cityOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="label" for="rating">Minimum rating</label>
            <select class="select" id="rating" name="rating">
                <option value="">Any rating</option>
                <?php foreach ([4.5, 4, 3.5, 3] as $r): ?>
                    <option value="<?= $r ?>" <?= $minRating !== '' && (float) $minRating === (float) $r ? 'selected' : '' ?>>
                        <?= $r ?> stars and above
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="label" for="max_rate">Rate up to</label>
            <select class="select" id="max_rate" name="max_rate">
                <option value="">Any rate</option>
                <?php foreach ([250, 350, 450, 600] as $r): ?>
                    <option value="<?= $r ?>" <?= $maxRate !== '' && (int) $maxRate === $r ? 'selected' : '' ?>>
                        <?= e(money($r)) ?> per hour
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="label" for="sort">Sort by</label>
            <select class="select" id="sort" name="sort">
                <?php foreach ($sorts as $key => [$_, $label]): ?>
                    <option value="<?= e($key) ?>" <?= $sortKey === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="btn-row">
            <button class="btn btn--primary" type="submit">Apply filters</button>
            <?php if ($baseQuery !== ''): ?>
                <a class="btn btn--ghost" href="search.php">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ==================== RESULTS ================================== -->
<?php if (!$providers): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon" aria-hidden="true">&#128269;</div>
            <h3>Nobody matches those filters</h3>
            <p>
                Try widening the search &mdash; remove the city filter, lower the minimum
                rating, or raise the hourly rate ceiling.
            </p>
            <a class="btn btn--primary" href="search.php">Show all professionals</a>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid--3">
        <?php foreach ($providers as $p): ?>
            <article class="provider-card">
                <div class="provider-card__head">
                    <span class="avatar" aria-hidden="true"><?= e(initials($p['full_name'])) ?></span>
                    <div>
                        <div class="provider-card__name"><?= e($p['full_name']) ?></div>
                        <div class="provider-card__cat"><?= e($p['category_name']) ?></div>
                    </div>
                </div>

                <?= star_rating((float) $p['avg_rating'], (int) $p['total_reviews']) ?>

                <?php if (!empty($p['skills'])): ?>
                    <p class="text-small text-muted" style="margin:0"><?= e(excerpt($p['skills'], 70)) ?></p>
                <?php endif; ?>

                <div class="provider-card__facts">
                    <span><b><?= (int) $p['experience_years'] ?></b> yrs exp.</span>
                    <span><b><?= (int) $p['total_jobs'] ?></b> jobs</span>
                    <?php if (!empty($p['city'])): ?>
                        <span><?= e($p['city']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="provider-card__foot">
                    <span class="provider-card__rate">
                        <?= e(money($p['hourly_rate'])) ?><small>/hr</small>
                    </span>
                    <div class="btn-row" style="margin-left:auto">
                        <a class="btn btn--outline btn--sm"
                           href="provider-view.php?id=<?= (int) $p['provider_id'] ?>">Profile</a>
                        <a class="btn btn--accent btn--sm"
                           href="book.php?provider=<?= (int) $p['provider_id'] ?>">Book</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= paginate($page, $totalPages, $baseQuery) ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
