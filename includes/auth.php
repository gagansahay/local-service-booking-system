<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : includes/auth.php
 *  PURPOSE : Authentication and authorisation (Module 1).
 *
 *            Authentication answers "who is this?"  -- attempt_login()
 *            Authorisation  answers "may they?"     -- require_role()
 *
 *            Every protected page calls one of the require_* guards on
 *            its FIRST executable line. Hiding a menu link is not
 *            access control -- the check must happen on the server,
 *            before any data is read.
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';


/* =====================================================================
 * SESSION STATE
 * ===================================================================*/

/**
 * Is anybody logged in on this session?
 *
 * @return bool
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * The current user's id, or 0 when signed out.
 *
 * @return int
 */
function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * The current user's role: customer | provider | admin | '' when out.
 *
 * @return string
 */
function current_role(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

/**
 * The current user's display name.
 *
 * @return string
 */
function current_name(): string
{
    return (string) ($_SESSION['full_name'] ?? '');
}

/**
 * For a signed-in provider, their providers.provider_id.
 *
 * @return int
 */
function current_provider_id(): int
{
    return (int) ($_SESSION['provider_id'] ?? 0);
}

/**
 * Test the current role against one or more allowed roles.
 *
 * @param  string ...$roles
 * @return bool
 */
function has_role(string ...$roles): bool
{
    return in_array(current_role(), $roles, true);
}

/**
 * The landing page for a role, used after login and by guards.
 *
 * @param  string $role
 * @return string
 */
function home_for_role(string $role): string
{
    return [
        'admin'    => 'admin/dashboard.php',
        'provider' => 'provider/dashboard.php',
        'customer' => 'customer/dashboard.php',
    ][$role] ?? 'index.php';
}


/* =====================================================================
 * GUARDS
 * ===================================================================*/

/**
 * Require any signed-in user. Remembers where they were heading so the
 * login screen can return them there.
 *
 * @return void
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('warning', 'Please sign in to continue.');
        redirect('auth/login.php');
    }
}

/**
 * Require one of the given roles. A signed-in user with the wrong role
 * is sent to their own dashboard rather than the login page -- telling
 * them to "log in" while they already are would be confusing.
 *
 * @param  string ...$roles
 * @return void
 */
function require_role(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
        log_activity(
            'authorisation_denied',
            'security',
            current_user_id(),
            'Role ' . current_role() . ' attempted ' . ($_SERVER['SCRIPT_NAME'] ?? '')
        );
        flash('error', 'You do not have permission to open that page.');
        redirect(home_for_role(current_role()));
    }
}

/** Shorthand guards used at the top of each module's pages. */
function require_admin(): void    { require_role('admin'); }
function require_provider(): void { require_role('provider'); }
function require_customer(): void { require_role('customer'); }

/**
 * For pages that only make sense when signed OUT (login, register).
 *
 * @return void
 */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(home_for_role(current_role()));
    }
}


/* =====================================================================
 * LOGIN / LOGOUT
 * ===================================================================*/

/**
 * Attempt to authenticate a user.
 *
 * SECURITY NOTES
 * --------------
 * 1. The same message is returned whether the email is unknown or the
 *    password is wrong. Distinguishing them would let an attacker
 *    enumerate which email addresses hold accounts.
 *
 * 2. password_verify() compares in constant time, so response timing
 *    does not leak how much of the hash matched.
 *
 * 3. A dummy verify runs when the account does not exist, so the
 *    "unknown email" path costs roughly the same time as the "wrong
 *    password" path. Without it, a fast rejection would itself reveal
 *    that the email is unregistered.
 *
 * 4. session_regenerate_id() on success defeats session fixation: any
 *    session id the attacker planted before login is discarded.
 *
 * @param  string $email
 * @param  string $password
 * @return array{ok:bool, error:?string, role:?string}
 */
function attempt_login(string $email, string $password): array
{
    $generic = 'Incorrect email or password. Please try again.';
    $pdo     = db();

    $stmt = $pdo->prepare(
        'SELECT user_id, full_name, email, password_hash, role, status
           FROM users
          WHERE email = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Constant-work dummy check -- see note 3 above.
        password_verify($password, '$2y$10$usesomesillystringforsalt0123456789abcdefghijklmnopqr');
        log_activity('login_failed', 'auth', null, 'Unknown email: ' . mb_substr($email, 0, 100));
        return ['ok' => false, 'error' => $generic, 'role' => null];
    }

    if (!password_verify($password, $user['password_hash'])) {
        log_activity('login_failed', 'users', (int) $user['user_id'], 'Wrong password');
        return ['ok' => false, 'error' => $generic, 'role' => null];
    }

    if ($user['status'] === 'suspended') {
        log_activity('login_blocked', 'users', (int) $user['user_id'], 'Account suspended');
        return [
            'ok'    => false,
            'error' => 'This account has been suspended. Please contact the administrator.',
            'role'  => null,
        ];
    }

    // ---- Authentication succeeded -----------------------------------

    // Rehash transparently if the cost factor has since been raised.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id');
        $rehash->execute([
            ':h'  => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $user['user_id'],
        ]);
    }

    session_regenerate_id(true);            // defeat session fixation

    $_SESSION['user_id']   = (int) $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['logged_in_at'] = time();

    // A provider needs their provider_id on hand for every query.
    if ($user['role'] === 'provider') {
        $p = $pdo->prepare('SELECT provider_id, verification_status FROM providers WHERE user_id = :uid');
        $p->execute([':uid' => $user['user_id']]);
        if ($row = $p->fetch()) {
            $_SESSION['provider_id']         = (int) $row['provider_id'];
            $_SESSION['verification_status'] = $row['verification_status'];
        }
    }

    log_activity('login_success', 'users', (int) $user['user_id'], 'Role: ' . $user['role']);

    return ['ok' => true, 'error' => null, 'role' => $user['role']];
}

/**
 * Sign the current user out and destroy the session completely.
 *
 * @return void
 */
function do_logout(): void
{
    if (is_logged_in()) {
        log_activity('logout', 'users', current_user_id());
    }

    $_SESSION = [];

    // Expire the cookie as well -- clearing $_SESSION alone would leave
    // the session id valid in the browser.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}


/* =====================================================================
 * REGISTRATION
 * ===================================================================*/

/**
 * Create a customer or provider account.
 *
 * The users row and (for a provider) the providers row are written in
 * ONE transaction. A provider account without its profile row would be
 * unusable, so the two inserts must succeed or fail together.
 *
 * @param  array $data  Validated field values
 * @return array{ok:bool, error:?string, user_id:?int}
 */
function register_user(array $data): array
{
    $pdo = db();

    // Pre-check for a friendly message. The UNIQUE index on
    // users.email remains the real guarantee -- between this SELECT and
    // the INSERT another request could claim the same address, and the
    // index is what actually prevents the duplicate.
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $data['email']]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'An account already exists with that email address.', 'user_id' => null];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, phone, address, city, pincode, role)
             VALUES (:name, :email, :hash, :phone, :address, :city, :pincode, :role)'
        );
        $stmt->execute([
            ':name'    => $data['full_name'],
            ':email'   => $data['email'],
            // PASSWORD_DEFAULT is bcrypt today and will follow PHP to
            // whatever is strongest later. The salt is generated and
            // stored inside the hash automatically.
            ':hash'    => password_hash($data['password'], PASSWORD_DEFAULT),
            ':phone'   => $data['phone'],
            ':address' => $data['address'] ?: null,
            ':city'    => $data['city'] ?: null,
            ':pincode' => $data['pincode'] ?: null,
            ':role'    => $data['role'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($data['role'] === 'provider') {
            $stmt = $pdo->prepare(
                'INSERT INTO providers
                        (user_id, category_id, experience_years, hourly_rate, skills, service_area, bio)
                 VALUES (:uid, :cat, :exp, :rate, :skills, :area, :bio)'
            );
            $stmt->execute([
                ':uid'    => $userId,
                ':cat'    => $data['category_id'],
                ':exp'    => $data['experience_years'] ?? 0,
                ':rate'   => $data['hourly_rate'] ?? 0,
                ':skills' => $data['skills'] ?: null,
                ':area'   => $data['service_area'] ?: null,
                ':bio'    => $data['bio'] ?: null,
            ]);

            $providerId = (int) $pdo->lastInsertId();

            // Seed a sensible Monday-to-Saturday working week so a new
            // provider is bookable immediately after verification.
            $slot = $pdo->prepare(
                'INSERT INTO provider_availability (provider_id, day_of_week, start_time, end_time)
                 VALUES (:pid, :dow, :start, :end)'
            );
            for ($dow = 1; $dow <= 6; $dow++) {
                $slot->execute([
                    ':pid'   => $providerId,
                    ':dow'   => $dow,
                    ':start' => '09:00:00',
                    ':end'   => '18:00:00',
                ]);
            }

            // Tell every admin there is somebody waiting for approval.
            $admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($admins as $admin) {
                notify(
                    $pdo,
                    (int) $admin['user_id'],
                    'New professional awaiting verification',
                    $data['full_name'] . ' has registered and needs approval.',
                    'admin/providers.php?status=pending',
                    'shield'
                );
            }
        }

        $pdo->commit();
        log_activity('register', 'users', $userId, 'Role: ' . $data['role']);

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // 23000 is the SQLSTATE for an integrity constraint violation --
        // here it means the UNIQUE email index caught a race.
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'error' => 'An account already exists with that email address.', 'user_id' => null];
        }

        if (APP_DEBUG) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage(), 'user_id' => null];
        }
        return ['ok' => false, 'error' => 'Registration could not be completed. Please try again.', 'user_id' => null];
    }
}

/**
 * Fetch the signed-in user's full record.
 *
 * @return array|null
 */
function current_user(): ?array
{
    static $cached = null;

    if (!is_logged_in()) {
        return null;
    }
    if ($cached !== null) {
        return $cached;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = :id');
    $stmt->execute([':id' => current_user_id()]);

    return $cached = ($stmt->fetch() ?: null);
}
