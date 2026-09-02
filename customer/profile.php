<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : customer/profile.php
 *  MODULE  : 1 -- Authentication & Account
 *  PURPOSE : Let a customer update their own details and change their
 *            password.
 *
 *  Changing a password requires the CURRENT one, even though the user
 *  is already signed in. That is what stops somebody who walks up to an
 *  unlocked machine from silently taking the account over.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_customer();

$pdo    = db();
$userId = current_user_id();

$errors  = [];
$success = null;

/* =====================================================================
 * UPDATE DETAILS
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update_profile') {

    csrf_guard();

    $fullName = post('full_name');
    $phone    = post('phone');
    $address  = post('address');
    $city     = post('city');
    $pincode  = post('pincode');

    if ($fullName === '' || mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Enter your full name.';
    }
    if (!valid_phone($phone)) {
        $errors['phone'] = 'Enter a 10-digit mobile number starting with 6, 7, 8 or 9.';
    }
    if ($pincode !== '' && !valid_pincode($pincode)) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }

    // Profile photo is optional; handle_image_upload validates by magic
    // bytes and renames the file, so a disguised script cannot land here.
    $photoPath = null;
    if (!empty($_FILES['profile_photo']['name'])) {
        $upload = handle_image_upload($_FILES['profile_photo'], 'avatar');
        if (!$upload['ok']) {
            $errors['profile_photo'] = $upload['error'];
        } else {
            $photoPath = $upload['path'];
        }
    }

    if (!$errors) {
        $sql = 'UPDATE users SET full_name = :name, phone = :phone, address = :address,
                                 city = :city, pincode = :pincode';
        $params = [
            ':name'    => $fullName,
            ':phone'   => $phone,
            ':address' => $address ?: null,
            ':city'    => $city ?: null,
            ':pincode' => $pincode ?: null,
            ':uid'     => $userId,
        ];

        if ($photoPath !== null) {
            $sql .= ', profile_photo = :photo';
            $params[':photo'] = $photoPath;
        }
        $sql .= ' WHERE user_id = :uid';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_SESSION['full_name'] = $fullName;   // keep the top bar in step

        log_activity('profile_updated', 'users', $userId);
        flash('success', 'Your details have been saved.');
        redirect('customer/profile.php');
    }
}

/* =====================================================================
 * CHANGE PASSWORD
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'change_password') {

    csrf_guard();

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $errors['current_password'] = 'That is not your current password.';
        log_activity('password_change_failed', 'users', $userId, 'Wrong current password');
    }
    if ($problem = password_problem($new)) {
        $errors['new_password'] = $problem;
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :uid');
        $stmt->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':uid' => $userId]);

        // A password change should invalidate any other stolen session.
        session_regenerate_id(true);

        log_activity('password_changed', 'users', $userId);
        flash('success', 'Your password has been changed.');
        redirect('customer/profile.php');
    }
}

$me = current_user();

/* -- A short activity summary for context ---------------------------- */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS bookings,
            SUM(status = 'completed') AS completed,
            (SELECT COUNT(*) FROM maintenance_contracts WHERE user_id = :uid1 AND status = 'active') AS contracts,
            (SELECT COUNT(*) FROM feedback WHERE user_id = :uid2) AS reviews
       FROM bookings WHERE user_id = :uid3"
);
$stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
$summary = $stmt->fetch();

$pageTitle   = 'My profile';
$pageHeading = 'My profile';
$pageLede    = 'Keep your contact details current so professionals can reach you.';

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--2" style="align-items:start;grid-template-columns:1.5fr 1fr">

    <div>
        <!-- ---------------- Details ------------------------------- -->
        <form class="card" method="post" action="profile.php"
              enctype="multipart/form-data" data-validate-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">

            <div class="card__head"><h3>Your details</h3></div>

            <div class="card__body">
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="label" for="full_name">Full name <span class="req">*</span></label>
                        <input class="input<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                               type="text" id="full_name" name="full_name"
                               value="<?= e(post('full_name') ?: $me['full_name']) ?>"
                               data-validate="required">
                        <?php if (isset($errors['full_name'])): ?><div class="error"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="email">Email address</label>
                        <input class="input" type="email" id="email" value="<?= e($me['email']) ?>" disabled>
                        <div class="hint">Your email is your sign-in name and cannot be changed here.</div>
                    </div>

                    <div class="field">
                        <label class="label" for="phone">Mobile number <span class="req">*</span></label>
                        <input class="input<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
                               type="tel" id="phone" name="phone"
                               value="<?= e(post('phone') ?: $me['phone']) ?>"
                               data-numeric="10" data-validate="required|phone">
                        <?php if (isset($errors['phone'])): ?><div class="error"><?= e($errors['phone']) ?></div><?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="label" for="address">Address</label>
                        <input class="input" type="text" id="address" name="address"
                               value="<?= e(post('address') ?: (string) $me['address']) ?>">
                        <div class="hint">Used to pre-fill the booking form.</div>
                    </div>

                    <div class="field">
                        <label class="label" for="city">City</label>
                        <input class="input" type="text" id="city" name="city"
                               value="<?= e(post('city') ?: (string) $me['city']) ?>">
                    </div>

                    <div class="field">
                        <label class="label" for="pincode">PIN code</label>
                        <input class="input<?= isset($errors['pincode']) ? ' is-invalid' : '' ?>"
                               type="text" id="pincode" name="pincode"
                               value="<?= e(post('pincode') ?: (string) $me['pincode']) ?>"
                               data-numeric="6" data-validate="pincode">
                        <?php if (isset($errors['pincode'])): ?><div class="error"><?= e($errors['pincode']) ?></div><?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="label" for="profile_photo">Profile photo</label>
                        <input class="input<?= isset($errors['profile_photo']) ? ' is-invalid' : '' ?>"
                               type="file" id="profile_photo" name="profile_photo"
                               accept="image/jpeg,image/png,image/webp">
                        <?php if (isset($errors['profile_photo'])): ?>
                            <div class="error"><?= e($errors['profile_photo']) ?></div>
                        <?php else: ?>
                            <div class="hint">JPG, PNG or WEBP, up to <?= round(MAX_UPLOAD_BYTES / 1048576, 1) ?> MB.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card__foot">
                <button class="btn btn--accent" type="submit">Save changes</button>
            </div>
        </form>

        <!-- ---------------- Password ------------------------------ -->
        <form class="card" method="post" action="profile.php" data-validate-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">

            <div class="card__head"><h3>Change password</h3></div>

            <div class="card__body">
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="label" for="current_password">Current password <span class="req">*</span></label>
                        <input class="input<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                               type="password" id="current_password" name="current_password"
                               autocomplete="current-password" data-validate="required">
                        <?php if (isset($errors['current_password'])): ?>
                            <div class="error"><?= e($errors['current_password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="new_password">New password <span class="req">*</span></label>
                        <input class="input<?= isset($errors['new_password']) ? ' is-invalid' : '' ?>"
                               type="password" id="new_password" name="new_password"
                               autocomplete="new-password" data-validate="required|password">
                        <?php if (isset($errors['new_password'])): ?>
                            <div class="error"><?= e($errors['new_password']) ?></div>
                        <?php else: ?>
                            <div class="hint">At least <?= (int) MIN_PASSWORD_LENGTH ?> characters, with a letter and a number.</div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="confirm_password">Confirm new password <span class="req">*</span></label>
                        <input class="input<?= isset($errors['confirm_password']) ? ' is-invalid' : '' ?>"
                               type="password" id="confirm_password" name="confirm_password"
                               autocomplete="new-password"
                               data-match="new_password" data-validate="required|match">
                        <?php if (isset($errors['confirm_password'])): ?>
                            <div class="error"><?= e($errors['confirm_password']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card__foot">
                <button class="btn btn--primary" type="submit">Change my password</button>
            </div>
        </form>
    </div>

    <!-- ---------------- Summary rail ------------------------------ -->
    <div>
        <section class="card">
            <div class="card__body text-center">
                <span class="avatar avatar--lg" style="margin:0 auto var(--sp-3)" aria-hidden="true">
                    <?= e(initials($me['full_name'])) ?>
                </span>
                <h3 style="margin-bottom:2px"><?= e($me['full_name']) ?></h3>
                <p class="text-small text-muted"><?= e($me['email']) ?></p>
                <span class="badge badge--active">Customer</span>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h3>Your activity</h3></div>
            <div class="card__body">
                <dl class="jobcard__facts" style="border-top:none;border-bottom:none;padding-top:0">
                    <div><dt>Bookings</dt><dd><?= (int) $summary['bookings'] ?></dd></div>
                    <div><dt>Completed</dt><dd><?= (int) $summary['completed'] ?></dd></div>
                    <div><dt>AMC plans</dt><dd><?= (int) $summary['contracts'] ?></dd></div>
                    <div><dt>Reviews left</dt><dd><?= (int) $summary['reviews'] ?></dd></div>
                    <div><dt>Member since</dt><dd><?= e(show_date($me['created_at'])) ?></dd></div>
                </dl>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
