<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : includes/header.php
 *  PURPOSE : Opening markup for every signed-in screen -- document head,
 *            sidebar navigation and the top bar. Pair with footer.php.
 *
 *  USAGE   : $pageTitle = 'Dashboard';
 *            include __DIR__ . '/../includes/header.php';
 * ---------------------------------------------------------------------
 *  Expects auth.php to have been included and a role guard to have run
 *  already, so that everything below can assume a signed-in user.
 * =====================================================================
 */

require_once __DIR__ . '/auth.php';

$pageTitle   = $pageTitle   ?? 'Dashboard';
$pageHeading = $pageHeading ?? $pageTitle;
$pageLede    = $pageLede    ?? '';

$pdo         = db();
$unreadCount = unread_count($pdo, current_user_id());

// The five most recent notifications feed the bell panel.
$notifStmt = $pdo->prepare(
    'SELECT notification_id, title, message, link, is_read, created_at
       FROM notifications
      WHERE user_id = :uid
      ORDER BY created_at DESC
      LIMIT 5'
);
$notifStmt->execute([':uid' => current_user_id()]);
$recentNotifications = $notifStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; <?= e(APP_SHORT) ?></title>
<meta name="description" content="<?= e(APP_NAME) ?> &mdash; <?= e(APP_TAGLINE) ?>">

<?php /* Fonts are progressive enhancement: every stack in style.css
         ends in a face that ships with Windows, so an offline machine
         renders the same layout with different letterforms. */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">

<link rel="stylesheet" href="<?= e(ASSETS_URL) ?>css/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%2316233A'/><text x='16' y='22' font-size='16' font-family='sans-serif' font-weight='700' fill='%23F0A202' text-anchor='middle'>L</text></svg>">
</head>
<body>

<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="shell">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main">

        <header class="topbar">
            <button type="button" class="topbar__toggle" id="sidebarToggle"
                    aria-label="Show navigation" aria-expanded="false">&#9776;</button>

            <div class="topbar__title"><?= e($pageTitle) ?></div>
            <div class="topbar__spacer"></div>

            <!-- Notification bell -->
            <div class="bell">
                <button type="button" class="bell__button" id="bellButton"
                        aria-expanded="false"
                        aria-label="Notifications<?= $unreadCount > 0 ? ' (' . $unreadCount . ' unread)' : '' ?>">
                    &#128276;
                    <?php if ($unreadCount > 0): ?>
                        <span class="bell__dot" aria-hidden="true"><?= $unreadCount > 9 ? '9+' : (int) $unreadCount ?></span>
                    <?php endif; ?>
                </button>

                <div class="bell__panel" id="bellPanel" hidden>
                    <?php if (!$recentNotifications): ?>
                        <div class="bell__item">
                            <strong>Nothing yet</strong>
                            <span>Updates about your bookings will appear here.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentNotifications as $n): ?>
                            <a class="bell__item<?= $n['is_read'] ? '' : ' is-unread' ?>"
                               href="<?= e(BASE_URL . ($n['link'] ?: home_for_role(current_role()))) ?>">
                                <strong><?= e($n['title']) ?></strong>
                                <span><?= e($n['message']) ?></span>
                                <span class="text-muted"> &middot; <?= e(show_datetime($n['created_at'])) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Signed-in user -->
            <div class="userchip">
                <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials(current_name())) ?></span>
                <span class="userchip__meta">
                    <span class="userchip__name"><?= e(current_name()) ?></span><br>
                    <span class="userchip__role"><?= e(current_role()) ?></span>
                </span>
            </div>
        </header>

        <main class="content" id="main-content">

            <?= render_flashes() ?>

            <?php if ($pageHeading !== '' || $pageLede !== ''): ?>
            <div class="page-head">
                <div>
                    <h1><?= e($pageHeading) ?></h1>
                    <?php if ($pageLede !== ''): ?><p><?= e($pageLede) ?></p><?php endif; ?>
                </div>
                <?php if (!empty($pageActions)): ?>
                    <div class="btn-row"><?= $pageActions /* trusted markup built by the page itself */ ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
