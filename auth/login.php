<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : auth/login.php
 *  MODULE  : 1 -- Authentication & Account
 *  PURPOSE : Sign-in screen for all three roles. The role is read from
 *            the account, not chosen by the user, so nobody can elevate
 *            themselves by picking "Administrator" on a form.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_guest();          // already signed in? go to your dashboard

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_guard();

    $email    = post('email');
    $password = $_POST['password'] ?? '';

    // Presence checks only. The credentials themselves are judged by
    // attempt_login(), which deliberately gives one generic message.
    if ($email === '')    { $errors['email']    = 'Enter your email address.'; }
    if ($password === '') { $errors['password'] = 'Enter your password.'; }

    if (!$errors) {
        $result = attempt_login($email, $password);

        if ($result['ok']) {
            flash('success', 'Welcome back, ' . current_name() . '.');

            // Return the user to whatever they were trying to open
            // before the guard intercepted them.
            $intended = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);

            if ($intended && str_starts_with($intended, BASE_URL)) {
                header('Location: ' . $intended);
                exit;
            }
            redirect(home_for_role($result['role']));
        }

        $errors['form'] = $result['error'];
    }
}

$pageTitle = 'Sign in';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(APP_SHORT) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(ASSETS_URL) ?>css/style.css">
</head>
<body>

<div class="auth">

    <!-- Left: what the platform is for -------------------------------->
    <aside class="auth__aside">
        <a class="brand" href="<?= e(BASE_URL) ?>index.php" style="margin-bottom:var(--sp-8)">
            <span class="brand__mark" style="background:var(--marigold-500);color:var(--indigo-900)">LS</span>
            <span>
                <span class="brand__text" style="color:#fff">Local Service</span><br>
                <span class="brand__sub">Booking &amp; Management</span>
            </span>
        </a>

        <h2>Every trade in your neighbourhood, on one booking screen.</h2>

        <ul class="auth__points">
            <li><span class="auth__tick">&#10003;</span>
                <span><b>Verified professionals</b>Every plumber, electrician and technician is checked by an administrator before they appear.</span></li>
            <li><span class="auth__tick">&#10003;</span>
                <span><b>No double bookings</b>Slots are checked against the professional's real working hours the moment you pick a time.</span></li>
            <li><span class="auth__tick">&#10003;</span>
                <span><b>Maintenance on schedule</b>Annual maintenance contracts raise their own visits, so a service is never forgotten.</span></li>
        </ul>
    </aside>

    <!-- Right: the form ----------------------------------------------->
    <div class="auth__panel">
        <div class="auth__form">

            <p class="eyebrow">Sign in</p>
            <h1>Welcome back</h1>
            <p class="auth__lede">Use the email address you registered with.</p>

            <?= render_flashes() ?>

            <?php if (isset($errors['form'])): ?>
                <div class="alert alert--error" role="alert">
                    <span class="alert__icon">&#10006;</span>
                    <span class="alert__text"><?= e($errors['form']) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" data-validate-form novalidate>
                <?= csrf_field() ?>

                <div class="field">
                    <label class="label" for="email">Email address <span class="req">*</span></label>
                    <input class="input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                           type="email" id="email" name="email"
                           value="<?= e($email) ?>"
                           autocomplete="username" autofocus
                           data-validate="required|email">
                    <?php if (isset($errors['email'])): ?>
                        <div class="error"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="password">Password <span class="req">*</span></label>
                    <input class="input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           type="password" id="password" name="password"
                           autocomplete="current-password"
                           data-validate="required">
                    <?php if (isset($errors['password'])): ?>
                        <div class="error"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <button class="btn btn--accent btn--block btn--lg" type="submit">Sign in</button>
            </form>

            <p class="text-small text-center u-mt-5">
                New here? <a href="register.php">Create an account</a>
            </p>

            <!-- Seeded demonstration accounts.
                 This block exists for the project demonstration and viva.
                 It would be deleted before any real deployment. -->
            <div class="demo-keys">
                <h4>Demonstration accounts</h4>
                <p class="text-small text-muted u-mb-3">
                    Seeded by <code>lsbms_seed.sql</code>. Click a row to fill the form.
                </p>
                <table>
                    <tr>
                        <td>Administrator</td>
                        <td><code>admin@lsbms.local</code></td>
                        <td class="text-right">
                            <button type="button" class="demo-fill"
                                    data-email="admin@lsbms.local" data-password="Lsbms@2026">use</button>
                        </td>
                    </tr>
                    <tr>
                        <td>Professional</td>
                        <td><code>imran.actech@lsbms.local</code></td>
                        <td class="text-right">
                            <button type="button" class="demo-fill"
                                    data-email="imran.actech@lsbms.local" data-password="Lsbms@2026">use</button>
                        </td>
                    </tr>
                    <tr>
                        <td>Customer</td>
                        <td><code>gagan@example.com</code></td>
                        <td class="text-right">
                            <button type="button" class="demo-fill"
                                    data-email="gagan@example.com" data-password="Lsbms@2026">use</button>
                        </td>
                    </tr>
                </table>
                <p class="text-small text-muted" style="margin:var(--sp-3) 0 0">
                    Password for all three: <code>Lsbms@2026</code>
                </p>
            </div>

        </div>
    </div>
</div>

<script>window.LSBMS_BASE = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= e(ASSETS_URL) ?>js/main.js" defer></script>
<script src="<?= e(ASSETS_URL) ?>js/validation.js" defer></script>
</body>
</html>
