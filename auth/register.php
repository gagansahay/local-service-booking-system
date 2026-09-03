<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : auth/register.php
 *  MODULE  : 1 -- Authentication & Account
 *  PURPOSE : Account creation for customers and service professionals.
 *
 *  NOTE ON ROLES: the form offers only 'customer' and 'provider'. The
 *  value is checked against that whitelist below, so posting
 *  role=admin by hand creates an ordinary customer, not an
 *  administrator. Administrator accounts are seeded, never self-served.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_guest();

$pdo        = db();
$categories = $pdo->query(
    'SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name'
)->fetchAll();

$errors = [];
$in = [
    'full_name' => '', 'email' => '', 'phone' => '', 'address' => '',
    'city' => '', 'pincode' => '', 'role' => 'customer',
    'category_id' => '', 'experience_years' => '', 'hourly_rate' => '',
    'skills' => '', 'service_area' => '', 'bio' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_guard();

    foreach ($in as $key => $_) {
        $in[$key] = post($key);
    }
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    /* -- Validation. Mirrors assets/js/validation.js exactly. -------- */

    if ($in['full_name'] === '') {
        $errors['full_name'] = 'Enter your full name.';
    } elseif (mb_strlen($in['full_name']) < 3) {
        $errors['full_name'] = 'Name must be at least 3 characters.';
    }

    if ($in['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!valid_email($in['email'])) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($in['phone'] === '') {
        $errors['phone'] = 'Enter your mobile number.';
    } elseif (!valid_phone($in['phone'])) {
        $errors['phone'] = 'Enter a 10-digit mobile number starting with 6, 7, 8 or 9.';
    }

    if ($in['pincode'] !== '' && !valid_pincode($in['pincode'])) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }

    if ($password === '') {
        $errors['password'] = 'Choose a password.';
    } elseif ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    }

    if ($confirm !== $password) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    // Whitelist the role -- never trust the posted value.
    if (!in_array($in['role'], ['customer', 'provider'], true)) {
        $in['role'] = 'customer';
    }

    if ($in['role'] === 'provider') {
        $validCategoryIds = array_column($categories, 'category_id');

        if ($in['category_id'] === '' || !in_array((int) $in['category_id'], $validCategoryIds, true)) {
            $errors['category_id'] = 'Choose the trade you work in.';
        }
        if ($in['hourly_rate'] !== '' && (float) $in['hourly_rate'] < 0) {
            $errors['hourly_rate'] = 'Rate cannot be negative.';
        }
        if ($in['experience_years'] !== '' && ((int) $in['experience_years'] < 0 || (int) $in['experience_years'] > 60)) {
            $errors['experience_years'] = 'Enter experience between 0 and 60 years.';
        }
    }

    if (!$errors) {
        $result = register_user([
            'full_name'        => $in['full_name'],
            'email'            => $in['email'],
            'password'         => $password,
            'phone'            => $in['phone'],
            'address'          => $in['address'],
            'city'             => $in['city'],
            'pincode'          => $in['pincode'],
            'role'             => $in['role'],
            'category_id'      => (int) $in['category_id'],
            'experience_years' => (int) $in['experience_years'],
            'hourly_rate'      => (float) $in['hourly_rate'],
            'skills'           => $in['skills'],
            'service_area'     => $in['service_area'],
            'bio'              => $in['bio'],
        ]);

        if ($result['ok']) {
            if ($in['role'] === 'provider') {
                flash('success', 'Account created. An administrator will verify your profile before it appears in search results.');
            } else {
                flash('success', 'Account created. Please sign in to book your first service.');
            }
            redirect('auth/login.php');
        }

        $errors['form'] = $result['error'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create an account &middot; <?= e(APP_SHORT) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(ASSETS_URL) ?>css/style.css">
</head>
<body>

<div class="auth">

    <aside class="auth__aside">
        <a class="brand" href="<?= e(BASE_URL) ?>index.php" style="margin-bottom:var(--sp-8)">
            <span class="brand__mark" style="background:var(--marigold-500);color:var(--indigo-900)">LS</span>
            <span>
                <span class="brand__text" style="color:#fff">Local Service</span><br>
                <span class="brand__sub">Booking &amp; Management</span>
            </span>
        </a>

        <h2>Join as a customer, or list your trade.</h2>

        <ul class="auth__points">
            <li><span class="auth__tick">&#10003;</span>
                <span><b>Customers</b>Search by trade and locality, compare rates and ratings, and book a slot in under a minute.</span></li>
            <li><span class="auth__tick">&#10003;</span>
                <span><b>Professionals</b>Publish your rates and working hours, and manage every job request from one dashboard.</span></li>
            <li><span class="auth__tick">&#10003;</span>
                <span><b>Free to join</b>No listing fee. Payment is settled directly with the customer after the job.</span></li>
        </ul>
    </aside>

    <div class="auth__panel">
        <div class="auth__form">

            <p class="eyebrow">Create account</p>
            <h1>Get started</h1>
            <p class="auth__lede">Takes about a minute. Fields marked <span class="req">*</span> are required.</p>

            <?= form_error($errors) ?>

            <form method="post" action="register.php" data-validate-form novalidate>
                <?= csrf_field() ?>

                <!-- Role ------------------------------------------------->
                <div class="field">
                    <span class="label">I am joining as <span class="req">*</span></span>
                    <div class="role-picker">
                        <label class="role-option">
                            <input type="radio" name="role" value="customer" id="roleCustomer"
                                   <?= $in['role'] === 'customer' ? 'checked' : '' ?>>
                            <span class="role-option__body">
                                <span class="role-option__icon" aria-hidden="true">&#127968;</span>
                                <span class="role-option__title">A customer</span>
                                <span class="role-option__desc">I need work done at home</span>
                            </span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="provider" id="roleProvider"
                                   <?= $in['role'] === 'provider' ? 'checked' : '' ?>>
                            <span class="role-option__body">
                                <span class="role-option__icon" aria-hidden="true">&#128295;</span>
                                <span class="role-option__title">A professional</span>
                                <span class="role-option__desc">I offer a trade or service</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Common fields ---------------------------------------->
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="label" for="full_name">Full name <span class="req">*</span></label>
                        <input class="input<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                               type="text" id="full_name" name="full_name"
                               value="<?= e($in['full_name']) ?>"
                               autocomplete="name" data-validate="required">
                        <?php if (isset($errors['full_name'])): ?><div class="error"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="email">Email address <span class="req">*</span></label>
                        <input class="input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                               type="email" id="email" name="email"
                               value="<?= e($in['email']) ?>"
                               autocomplete="email" data-validate="required|email">
                        <?php if (isset($errors['email'])): ?><div class="error"><?= e($errors['email']) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="phone">Mobile number <span class="req">*</span></label>
                        <input class="input<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
                               type="tel" id="phone" name="phone"
                               value="<?= e($in['phone']) ?>"
                               autocomplete="tel" data-numeric="10" data-validate="required|phone">
                        <?php if (isset($errors['phone'])): ?>
                            <div class="error"><?= e($errors['phone']) ?></div>
                        <?php else: ?>
                            <div class="hint">10 digits, no country code.</div>
                        <?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="label" for="address">Address</label>
                        <input class="input" type="text" id="address" name="address"
                               value="<?= e($in['address']) ?>" autocomplete="street-address">
                    </div>

                    <div class="field">
                        <label class="label" for="city">City</label>
                        <input class="input" type="text" id="city" name="city"
                               value="<?= e($in['city']) ?>" autocomplete="address-level2">
                    </div>

                    <div class="field">
                        <label class="label" for="pincode">PIN code</label>
                        <input class="input<?= isset($errors['pincode']) ? ' is-invalid' : '' ?>"
                               type="text" id="pincode" name="pincode"
                               value="<?= e($in['pincode']) ?>"
                               data-numeric="6" data-validate="pincode">
                        <?php if (isset($errors['pincode'])): ?><div class="error"><?= e($errors['pincode']) ?></div><?php endif; ?>
                    </div>
                </div>

                <!-- Professional-only fields ----------------------------->
                <fieldset id="providerFields" style="border:none;padding:0;margin:var(--sp-4) 0 0"
                          <?= $in['role'] === 'provider' ? '' : 'hidden' ?>>
                    <legend class="eyebrow" style="padding:0">Your trade</legend>

                    <div class="form-grid">
                        <div class="field">
                            <label class="label" for="category_id">Trade / category <span class="req">*</span></label>
                            <select class="select<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>"
                                    id="category_id" name="category_id">
                                <option value="">Select your trade</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int) $c['category_id'] ?>"
                                        <?= (string) $in['category_id'] === (string) $c['category_id'] ? 'selected' : '' ?>>
                                        <?= e($c['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category_id'])): ?><div class="error"><?= e($errors['category_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="experience_years">Years of experience</label>
                            <input class="input<?= isset($errors['experience_years']) ? ' is-invalid' : '' ?>"
                                   type="number" id="experience_years" name="experience_years"
                                   min="0" max="60" value="<?= e($in['experience_years']) ?>">
                            <?php if (isset($errors['experience_years'])): ?><div class="error"><?= e($errors['experience_years']) ?></div><?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="hourly_rate">Hourly rate (<?= e(CURRENCY) ?>)</label>
                            <input class="input<?= isset($errors['hourly_rate']) ? ' is-invalid' : '' ?>"
                                   type="number" id="hourly_rate" name="hourly_rate"
                                   min="0" step="10" value="<?= e($in['hourly_rate']) ?>"
                                   data-validate="positive">
                            <?php if (isset($errors['hourly_rate'])): ?><div class="error"><?= e($errors['hourly_rate']) ?></div><?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="service_area">Areas you serve</label>
                            <input class="input" type="text" id="service_area" name="service_area"
                                   value="<?= e($in['service_area']) ?>"
                                   placeholder="Meerut, Modinagar">
                        </div>

                        <div class="field field--full">
                            <label class="label" for="skills">Skills</label>
                            <input class="input" type="text" id="skills" name="skills"
                                   value="<?= e($in['skills']) ?>"
                                   placeholder="Pipe fitting, Leak detection, Tap repair">
                            <div class="hint">Separate each skill with a comma.</div>
                        </div>

                        <div class="field field--full">
                            <label class="label" for="bio">About your work</label>
                            <textarea class="textarea" id="bio" name="bio"
                                      placeholder="A short description customers will read on your profile."><?= e($in['bio']) ?></textarea>
                        </div>
                    </div>

                    <div class="alert alert--info">
                        <span class="alert__icon">&#8505;</span>
                        <span class="alert__text">Your profile stays hidden from search until an administrator verifies it.</span>
                    </div>
                </fieldset>

                <!-- Password --------------------------------------------->
                <div class="form-grid u-mt-4">
                    <div class="field">
                        <label class="label" for="password">Password <span class="req">*</span></label>
                        <input class="input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                               type="password" id="password" name="password"
                               autocomplete="new-password" data-validate="required|password">
                        <div class="meter"><div class="meter__fill" id="meterFill"></div></div>
                        <?php if (isset($errors['password'])): ?>
                            <div class="error"><?= e($errors['password']) ?></div>
                        <?php else: ?>
                            <div class="hint">At least <?= (int) MIN_PASSWORD_LENGTH ?> characters, with a letter and a number.</div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="password_confirm">Confirm password <span class="req">*</span></label>
                        <input class="input<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                               type="password" id="password_confirm" name="password_confirm"
                               autocomplete="new-password"
                               data-match="password" data-validate="required|match">
                        <?php if (isset($errors['password_confirm'])): ?><div class="error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
                    </div>
                </div>

                <button class="btn btn--accent btn--block btn--lg" type="submit" class="u-mt-4">
                    Create my account
                </button>
            </form>

            <p class="text-small text-center u-mt-5">
                Already registered? <a href="login.php">Sign in</a>
            </p>
        </div>
    </div>
</div>

<script>
/* Show the trade fields only when "A professional" is selected. The
   server re-checks the role regardless, so this is purely to keep the
   form short for customers. */
(function () {
    var providerFields = document.getElementById('providerFields');
    var radios = document.querySelectorAll('input[name="role"]');

    function sync() {
        var chosen = document.querySelector('input[name="role"]:checked');
        providerFields.hidden = !chosen || chosen.value !== 'provider';
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
})();
</script>
<script>window.LSBMS_BASE = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= e(ASSETS_URL) ?>js/main.js" defer></script>
<script src="<?= e(ASSETS_URL) ?>js/validation.js" defer></script>
</body>
</html>
