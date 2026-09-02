<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : provider/profile.php
 *  MODULE  : 3 -- Service Provider  (with Module 1, Account)
 *  PURPOSE : The professional's own profile -- contact details, trade
 *            information, hourly rate, and password.
 *
 *  The trade CATEGORY is deliberately read-only here. Changing it would
 *  silently move every past booking into a different trade and would
 *  side-step the administrator's verification, which was granted for
 *  one specific trade. Changing trade requires an administrator.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

require_provider();

$pdo        = db();
$userId     = current_user_id();
$providerId = current_provider_id();

$errors = [];

/* =====================================================================
 * UPDATE PROFILE  (users row + providers row, in one transaction)
 * ===================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update_profile') {

    csrf_guard();

    $fullName    = post('full_name');
    $phone       = post('phone');
    $address     = post('address');
    $city        = post('city');
    $pincode     = post('pincode');
    $experience  = post_int('experience_years');
    $hourlyRate  = post('hourly_rate');
    $skills      = post('skills');
    $serviceArea = post('service_area');
    $bio         = post('bio');

    if ($fullName === '' || mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Enter your full name.';
    }
    if (!valid_phone($phone)) {
        $errors['phone'] = 'Enter a 10-digit mobile number starting with 6, 7, 8 or 9.';
    }
    if ($pincode !== '' && !valid_pincode($pincode)) {
        $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
    }
    if ($hourlyRate === '' || !is_numeric($hourlyRate) || (float) $hourlyRate < 0) {
        $errors['hourly_rate'] = 'Enter an hourly rate of zero or more.';
    }
    if ($experience < 0 || $experience > 60) {
        $errors['experience_years'] = 'Enter experience between 0 and 60 years.';
    }

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
        try {
            $pdo->beginTransaction();

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

            $stmt = $pdo->prepare(
                'UPDATE providers
                    SET experience_years = :exp, hourly_rate = :rate,
                        skills = :skills, service_area = :area, bio = :bio
                  WHERE provider_id = :pid'
            );
            $stmt->execute([
                ':exp'    => $experience,
                ':rate'   => (float) $hourlyRate,
                ':skills' => $skills ?: null,
                ':area'   => $serviceArea ?: null,
                ':bio'    => $bio ?: null,
                ':pid'    => $providerId,
            ]);

            $pdo->commit();

            $_SESSION['full_name'] = $fullName;

            log_activity('profile_updated', 'providers', $providerId);
            flash('success', 'Your profile has been saved.');
            redirect('provider/profile.php');

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['form'] = APP_DEBUG
                ? 'Database error: ' . $exception->getMessage()
                : 'Your profile could not be saved. Please try again.';
        }
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

        session_regenerate_id(true);

        log_activity('password_changed', 'users', $userId);
        flash('success', 'Your password has been changed.');
        redirect('provider/profile.php');
    }
}

/* -- Current values --------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT p.*, u.full_name, u.email, u.phone, u.address, u.city, u.pincode, u.created_at,
            c.category_name
       FROM providers  p
       JOIN users      u ON u.user_id     = p.user_id
       JOIN categories c ON c.category_id = p.category_id
      WHERE p.provider_id = :pid'
);
$stmt->execute([':pid' => $providerId]);
$me = $stmt->fetch();

$pageTitle   = 'My profile';
$pageHeading = 'My profile';
$pageLede    = 'This is what customers see before they decide to book you.';

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert--error" role="alert">
        <span class="alert__icon">&#10006;</span>
        <span class="alert__text"><?= e($errors['form']) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid--2 grid--rail">

    <div>
        <!-- ---------------- Profile ------------------------------- -->
        <form class="card" method="post" action="profile.php"
              enctype="multipart/form-data" data-validate-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">

            <div class="card__head"><h3>Contact details</h3></div>

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
                        <div class="hint">Your sign-in name. It cannot be changed here.</div>
                    </div>

                    <div class="field">
                        <label class="label" for="phone">Mobile number <span class="req">*</span></label>
                        <input class="input<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
                               type="tel" id="phone" name="phone"
                               value="<?= e(post('phone') ?: $me['phone']) ?>"
                               data-numeric="10" data-validate="required|phone">
                        <?php if (isset($errors['phone'])): ?>
                            <div class="error"><?= e($errors['phone']) ?></div>
                        <?php else: ?>
                            <div class="hint">Customers see this once they book you.</div>
                        <?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="label" for="address">Address</label>
                        <input class="input" type="text" id="address" name="address"
                               value="<?= e(post('address') ?: (string) $me['address']) ?>">
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

                <h3 class="u-mt-6">Your trade</h3>

                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="category">Trade</label>
                        <input class="input" type="text" id="category" value="<?= e($me['category_name']) ?>" disabled>
                        <div class="hint">
                            Your verification was granted for this trade, so only an
                            administrator can change it.
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="experience_years">Years of experience</label>
                        <input class="input<?= isset($errors['experience_years']) ? ' is-invalid' : '' ?>"
                               type="number" min="0" max="60" id="experience_years" name="experience_years"
                               value="<?= e(post('experience_years') ?: $me['experience_years']) ?>">
                        <?php if (isset($errors['experience_years'])): ?>
                            <div class="error"><?= e($errors['experience_years']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="hourly_rate">Hourly rate (<?= e(CURRENCY) ?>) <span class="req">*</span></label>
                        <input class="input<?= isset($errors['hourly_rate']) ? ' is-invalid' : '' ?>"
                               type="number" min="0" step="10" id="hourly_rate" name="hourly_rate"
                               value="<?= e(post('hourly_rate') ?: $me['hourly_rate']) ?>"
                               data-validate="required|positive">
                        <?php if (isset($errors['hourly_rate'])): ?>
                            <div class="error"><?= e($errors['hourly_rate']) ?></div>
                        <?php else: ?>
                            <div class="hint">Used when a customer books a general visit.</div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="service_area">Areas you serve</label>
                        <input class="input" type="text" id="service_area" name="service_area"
                               value="<?= e(post('service_area') ?: (string) $me['service_area']) ?>"
                               placeholder="Meerut, Modinagar, Partapur">
                    </div>

                    <div class="field field--full">
                        <label class="label" for="skills">Skills</label>
                        <input class="input" type="text" id="skills" name="skills"
                               value="<?= e(post('skills') ?: (string) $me['skills']) ?>"
                               placeholder="Pipe fitting, Leak detection, Tap repair">
                        <div class="hint">
                            Separate each with a comma. These are searchable, so list what
                            customers actually type.
                        </div>
                    </div>

                    <div class="field field--full">
                        <label class="label" for="bio">About your work</label>
                        <textarea class="textarea" id="bio" name="bio" rows="4"
                                  placeholder="A short description customers read on your profile."><?= e(post('bio') ?: (string) $me['bio']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card__foot">
                <button class="btn btn--accent" type="submit">Save my profile</button>
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

    <!-- ---------------- Preview rail ------------------------------ -->
    <div>
        <section class="card">
            <div class="card__head"><h3>How customers see you</h3></div>
            <div class="card__body">
                <article class="provider-card" style="border:none;box-shadow:none;padding:0">
                    <div class="provider-card__head">
                        <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($me['full_name'])) ?></span>
                        <div>
                            <div class="provider-card__name"><?= e($me['full_name']) ?></div>
                            <div class="provider-card__cat"><?= e($me['category_name']) ?></div>
                        </div>
                    </div>

                    <?= star_rating((float) $me['avg_rating'], (int) $me['total_reviews']) ?>

                    <?php if (!empty($me['skills'])): ?>
                        <p class="text-small text-muted u-m0"><?= e(excerpt($me['skills'], 70)) ?></p>
                    <?php endif; ?>

                    <div class="provider-card__facts">
                        <span><b><?= (int) $me['experience_years'] ?></b> yrs exp.</span>
                        <span><b><?= (int) $me['total_jobs'] ?></b> jobs</span>
                        <?php if (!empty($me['city'])): ?><span><?= e($me['city']) ?></span><?php endif; ?>
                    </div>

                    <div class="provider-card__foot">
                        <span class="provider-card__rate"><?= e(money($me['hourly_rate'])) ?><small>/hr</small></span>
                        <span class="u-ml-auto"><?= status_badge($me['verification_status']) ?></span>
                    </div>
                </article>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h3>Account</h3></div>
            <div class="card__body">
                <dl class="jobcard__facts" style="border-top:none;border-bottom:none;padding-top:0">
                    <div><dt>Verification</dt><dd><?= status_badge($me['verification_status']) ?></dd></div>
                    <div><dt>Rating</dt><dd><?= number_format((float) $me['avg_rating'], 2) ?> / 5</dd></div>
                    <div><dt>Reviews</dt><dd><?= (int) $me['total_reviews'] ?></dd></div>
                    <div><dt>Jobs done</dt><dd><?= (int) $me['total_jobs'] ?></dd></div>
                    <div><dt>Joined</dt><dd><?= e(show_date($me['created_at'])) ?></dd></div>
                </dl>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
