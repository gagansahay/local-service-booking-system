<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : includes/sidebar.php
 *  PURPOSE : Role-aware navigation. Included by header.php.
 *
 *  IMPORTANT: hiding a link here is presentation, NOT access control.
 *  Each page still runs its own require_role() guard, so typing a URL
 *  directly gets a user nowhere they are not entitled to be.
 * =====================================================================
 */

$role = current_role();
$pdo  = $pdo ?? db();

/* ---------------------------------------------------------------------
 * Live counters shown as pills beside the menu items that need action.
 * ------------------------------------------------------------------ */
$badges = ['requests' => 0, 'pending_providers' => 0, 'due_visits' => 0];

if ($role === 'provider' && current_provider_id() > 0) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE provider_id = :pid AND status = 'pending'");
    $s->execute([':pid' => current_provider_id()]);
    $badges['requests'] = (int) $s->fetchColumn();

} elseif ($role === 'admin') {
    $badges['pending_providers'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM providers WHERE verification_status = 'pending'"
    )->fetchColumn();

} elseif ($role === 'customer') {
    $s = $pdo->prepare(
        "SELECT COUNT(*)
           FROM maintenance_visits v
           JOIN maintenance_contracts c ON c.contract_id = v.contract_id
          WHERE c.user_id = :uid AND v.status = 'due'"
    );
    $s->execute([':uid' => current_user_id()]);
    $badges['due_visits'] = (int) $s->fetchColumn();
}

/* ---------------------------------------------------------------------
 * Menu definition. Held as data so the three role menus stay readable
 * and cannot drift apart in styling.
 *   [ folder, file, icon, label, badge-key|null ]
 * ------------------------------------------------------------------ */
$menus = [
    'admin' => [
        'Overview' => [
            ['admin', 'dashboard.php',    '&#9632;', 'Dashboard',       null],
            ['admin', 'reports.php',      '&#9776;', 'Reports',         null],
        ],
        'Marketplace' => [
            ['admin', 'categories.php',   '&#9635;', 'Categories',      null],
            ['admin', 'providers.php',    '&#9873;', 'Professionals',   'pending_providers'],
            ['admin', 'users.php',        '&#9787;', 'Customers',       null],
        ],
        'Operations' => [
            ['admin', 'bookings.php',     '&#9998;', 'All bookings',    null],
            ['admin', 'plans.php',        '&#9850;', 'Maintenance plans', null],
            ['admin', 'feedback.php',     '&#9733;', 'Reviews',         null],
        ],
        'System' => [
            ['admin', 'activity-log.php', '&#9881;', 'Activity log',    null],
        ],
    ],

    'provider' => [
        'Work' => [
            ['provider', 'dashboard.php',   '&#9632;', 'Dashboard',    null],
            ['provider', 'requests.php',    '&#9993;', 'New requests', 'requests'],
            ['provider', 'jobs.php',        '&#9998;', 'My jobs',      null],
            ['provider', 'maintenance.php', '&#9850;', 'AMC visits',   null],
        ],
        'Business' => [
            ['provider', 'services.php',     '&#9635;', 'My services',  null],
            ['provider', 'availability.php', '&#9200;', 'Availability', null],
            ['provider', 'earnings.php',     '&#8377;', 'Earnings',     null],
        ],
        'Account' => [
            ['provider', 'profile.php',      '&#9787;', 'My profile',   null],
        ],
    ],

    'customer' => [
        'Book' => [
            ['customer', 'dashboard.php',   '&#9632;', 'Dashboard',       null],
            ['customer', 'search.php',      '&#9906;', 'Find a pro',      null],
            ['customer', 'my-bookings.php', '&#9998;', 'My bookings',     null],
        ],
        'Maintenance' => [
            ['customer', 'maintenance.php', '&#9850;', 'My AMC plans',    'due_visits'],
        ],
        'Account' => [
            ['customer', 'invoices.php',    '&#8377;', 'Invoices',        null],
            ['customer', 'profile.php',     '&#9787;', 'My profile',      null],
        ],
    ],
];

$menu = $menus[$role] ?? [];

$roleLabels = ['admin' => 'Administrator', 'provider' => 'Professional', 'customer' => 'Customer'];
?>
<aside class="sidebar" id="sidebar">

    <a class="sidebar__brand" href="<?= e(BASE_URL) ?><?= e(home_for_role($role)) ?>">
        <span class="sidebar__mark" aria-hidden="true">LS</span>
        <span>
            <span class="sidebar__name">Local Service</span><br>
            <span class="sidebar__role"><?= e($roleLabels[$role] ?? $role) ?></span>
        </span>
    </a>

    <nav class="sidebar__nav" aria-label="Main navigation">
        <?php foreach ($menu as $groupName => $items): ?>
            <div class="sidebar__group"><?= e($groupName) ?></div>
            <?php foreach ($items as [$folder, $file, $icon, $label, $badgeKey]): ?>
                <?php $count = $badgeKey !== null ? ($badges[$badgeKey] ?? 0) : 0; ?>
                <a class="sidebar__link<?= nav_active($file) ?>"
                   href="<?= e(BASE_URL . $folder . '/' . $file) ?>">
                    <span class="sidebar__icon" aria-hidden="true"><?= $icon ?></span>
                    <span><?= e($label) ?></span>
                    <?php if ($count > 0): ?>
                        <span class="sidebar__count"><?= (int) $count ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="sidebar__group">Session</div>
        <a class="sidebar__link" href="<?= e(BASE_URL) ?>index.php">
            <span class="sidebar__icon" aria-hidden="true">&#8962;</span>
            <span>Public site</span>
        </a>
        <a class="sidebar__link" href="<?= e(BASE_URL) ?>auth/logout.php">
            <span class="sidebar__icon" aria-hidden="true">&#8594;</span>
            <span>Sign out</span>
        </a>
    </nav>

    <div class="sidebar__foot">
        <?= e(APP_SHORT) ?> v<?= e(APP_VERSION) ?><br>
        <?= e(COURSE_CODE) ?> &middot; <?= e(STUDENT_NAME) ?><br>
        Enrol. <?= e(STUDENT_ENROLMENT) ?>
    </div>
</aside>
