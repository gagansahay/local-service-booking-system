<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : includes/functions.php
 *  PURPOSE : Shared helper library used by every module -- output
 *            escaping, CSRF protection, validation, flash messages,
 *            the booking slot-conflict engine, notifications, audit
 *            logging and presentation formatting.
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';


/* =====================================================================
 * SECTION 1 -- OUTPUT ESCAPING  (defence against Cross-Site Scripting)
 * ===================================================================*/

/**
 * Escape a value for safe placement in HTML.
 *
 * Every single piece of database or user-supplied text is printed
 * through this function. Escaping on OUTPUT (rather than sanitising on
 * input) is the correct strategy: the database keeps exactly what the
 * user typed, and the escaping is appropriate to the context it is
 * rendered in.
 *
 * @param  mixed $value
 * @return string
 */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape a value for safe embedding inside a JavaScript literal.
 *
 * @param  mixed $value
 * @return string
 */
function ejs($value): string
{
    return json_encode((string) ($value ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}


/* =====================================================================
 * SECTION 2 -- CSRF PROTECTION
 * ---------------------------------------------------------------------
 * A Cross-Site Request Forgery attack works by making the victim's
 * browser submit a form to our site while they are logged in. Because
 * the browser attaches the session cookie automatically, the request
 * looks authentic.
 *
 * The defence: every state-changing form carries a random token that
 * lives in the session. An attacker's page cannot read that token
 * (the same-origin policy stops it), so the forged request arrives
 * without it and is rejected.
 * ===================================================================*/

/**
 * Return the session CSRF token, generating one on first use.
 *
 * @return string 64-character hexadecimal token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes() is a cryptographically secure source.
        // rand()/mt_rand() would be predictable and therefore useless here.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Emit the hidden CSRF input. Place inside every POST form.
 *
 * @return string
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the submitted CSRF token.
 *
 * hash_equals() is used rather than === because it compares in constant
 * time, so an attacker cannot learn the token byte-by-byte by measuring
 * how long the comparison takes.
 *
 * @param  string|null $token
 * @return bool
 */
function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Guard the top of every POST handler. Aborts the request if the token
 * is missing or wrong.
 *
 * @return void
 */
function csrf_guard(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        log_activity('csrf_rejected', 'security', null, 'Invalid or missing CSRF token');

        // 403 Forbidden: the request was understood and is being refused.
        // (Some frameworks return 419 here, but that is not a registered
        // HTTP status code -- Apache does not recognise it and rewrites
        // the response to 500, which would misreport a security refusal
        // as a server fault.)
        http_response_code(403);
        exit('<h1>403 &mdash; Request refused</h1>'
           . '<p>Your session token was missing or invalid, so this request was '
           . 'not carried out. Please go back, reload the page and submit the form again.</p>');
    }
}


/* =====================================================================
 * SECTION 3 -- REQUEST INPUT
 * ===================================================================*/

/**
 * Read and trim a POST field.
 *
 * @param  string $key
 * @param  string $default
 * @return string
 */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key])
        ? trim((string) $_POST[$key])
        : $default;
}

/**
 * Read and trim a GET field.
 *
 * @param  string $key
 * @param  string $default
 * @return string
 */
function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_scalar($_GET[$key])
        ? trim((string) $_GET[$key])
        : $default;
}

/**
 * Read a positive integer from GET, useful for record ids and paging.
 *
 * @param  string $key
 * @param  int    $default
 * @return int
 */
function get_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return ($value === false || $value === null || $value < 0) ? $default : $value;
}

/**
 * Read a positive integer from POST.
 *
 * @param  string $key
 * @param  int    $default
 * @return int
 */
function post_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return ($value === false || $value === null) ? $default : $value;
}


/* =====================================================================
 * SECTION 4 -- VALIDATION
 * ---------------------------------------------------------------------
 * Every rule enforced by JavaScript on the client is enforced AGAIN
 * here. Client-side validation is a convenience for honest users; it is
 * trivially bypassed with the browser console or curl, so the server
 * must never trust it.
 * ===================================================================*/

/**
 * @param  string $email
 * @return bool
 */
function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Indian mobile numbers: exactly ten digits, first digit 6-9.
 *
 * @param  string $phone
 * @return bool
 */
function valid_phone(string $phone): bool
{
    return (bool) preg_match('/^[6-9][0-9]{9}$/', $phone);
}

/**
 * @param  string $pincode
 * @return bool
 */
function valid_pincode(string $pincode): bool
{
    return (bool) preg_match('/^[1-9][0-9]{5}$/', $pincode);
}

/**
 * Password policy: minimum length, and at least one letter and one
 * digit. Deliberately not more aggressive than that -- over-strict
 * rules push users towards writing passwords down.
 *
 * @param  string $password
 * @return string|null  Error message, or null when acceptable
 */
function password_problem(string $password): ?string
{
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        return 'Password must contain at least one letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    return null;
}

/**
 * Validate a date string in Y-m-d form and confirm it is a real date
 * (this rejects 2026-02-31, which a regex alone would accept).
 *
 * @param  string $date
 * @return bool
 */
function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/**
 * Validate a time string in H:i or H:i:s form.
 *
 * @param  string $time
 * @return bool
 */
function valid_time(string $time): bool
{
    return (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time);
}


/* =====================================================================
 * SECTION 5 -- FLASH MESSAGES AND REDIRECTION
 * ===================================================================*/

/**
 * Queue a one-shot message to be displayed after the next redirect.
 *
 * @param  string $type  success | error | warning | info
 * @param  string $message
 * @return void
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Render and clear all queued flash messages.
 *
 * @return string
 */
function render_flashes(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }

    $icons = ['success' => '&#10004;', 'error' => '&#10006;', 'warning' => '&#9888;', 'info' => '&#8505;'];
    $html  = '';

    foreach ($_SESSION['flash'] as $f) {
        $type  = in_array($f['type'], ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info';
        $html .= '<div class="alert alert--' . $type . '" role="alert">'
               . '<span class="alert__icon">' . ($icons[$type] ?? '') . '</span>'
               . '<span class="alert__text">' . e($f['message']) . '</span>'
               . '<button type="button" class="alert__close" aria-label="Dismiss">&times;</button>'
               . '</div>';
    }

    unset($_SESSION['flash']);   // one-shot: clear after rendering
    return $html;
}

/**
 * Issue an HTTP redirect relative to the application root and stop.
 *
 * @param  string $path
 * @return never
 */
function redirect(string $path): void
{
    // Strip any CR/LF to prevent HTTP response-splitting via a crafted path.
    $path = str_replace(["\r", "\n"], '', $path);
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/**
 * Send a JSON response and stop. Used by the ajax/ endpoints.
 *
 * @param  array $payload
 * @param  int   $status
 * @return never
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}


/* =====================================================================
 * SECTION 6 -- REFERENCE NUMBER GENERATION
 * ===================================================================*/

/**
 * Build the next human-readable booking reference, e.g. LSB-2026-000042.
 *
 * The sequence is derived from the table's AUTO_INCREMENT rather than
 * from COUNT(*), so deleting a row can never cause a duplicate code.
 *
 * @param  PDO $pdo
 * @return string
 */
function next_booking_code(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT AUTO_INCREMENT AS next_id
                           FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'bookings'");
    $next = (int) ($stmt->fetchColumn() ?: 1);
    return sprintf('LSB-%s-%06d', date('Y'), $next);
}

/**
 * Build the next maintenance contract reference, e.g. AMC-2026-000007.
 *
 * @param  PDO $pdo
 * @return string
 */
function next_contract_code(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT AUTO_INCREMENT AS next_id
                           FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'maintenance_contracts'");
    $next = (int) ($stmt->fetchColumn() ?: 1);
    return sprintf('AMC-%s-%06d', date('Y'), $next);
}

/**
 * Build an invoice number for a booking, e.g. INV-2026-000042.
 *
 * @param  int $bookingId
 * @return string
 */
function make_invoice_no(int $bookingId): string
{
    return sprintf('INV-%s-%06d', date('Y'), $bookingId);
}


/* =====================================================================
 * SECTION 7 -- THE BOOKING SLOT ENGINE
 * ---------------------------------------------------------------------
 * This is the core business logic of the system. A slot is bookable
 * only when ALL of the following hold:
 *
 *   (a) the date is today or later, and not further ahead than
 *       MAX_ADVANCE_DAYS;
 *   (b) if it is today, the time has not already passed;
 *   (c) the provider publishes working hours for that weekday, and the
 *       whole requested duration fits inside that window;
 *   (d) no existing non-cancelled booking for that provider OVERLAPS
 *       the requested interval.
 *
 * Rule (d) is an interval-overlap test, not an equality test. Two
 * bookings clash whenever  newStart < existingEnd  AND
 * existingStart < newEnd -- so a 09:00 two-hour job correctly blocks a
 * 10:00 one-hour job even though the start times differ.
 * ===================================================================*/

/**
 * Check whether a slot can be booked.
 *
 * @param  PDO      $pdo
 * @param  int      $providerId
 * @param  string   $date              Y-m-d
 * @param  string   $time              H:i or H:i:s
 * @param  int      $durationMinutes
 * @param  int|null $ignoreBookingId   Exclude this booking (used when rescheduling)
 * @return array{ok:bool, reason:string}
 */
function check_slot_available(
    PDO $pdo,
    int $providerId,
    string $date,
    string $time,
    int $durationMinutes = 60,
    ?int $ignoreBookingId = null
): array {
    // ---- (a) the date must be well-formed and in range ---------------
    if (!valid_date($date) || !valid_time($time)) {
        return ['ok' => false, 'reason' => 'Please choose a valid date and time.'];
    }

    $slotStart = new DateTime($date . ' ' . substr($time, 0, 5));
    $slotEnd   = (clone $slotStart)->modify('+' . $durationMinutes . ' minutes');
    $now       = new DateTime();

    if ($slotStart < $now) {
        return ['ok' => false, 'reason' => 'That time is already in the past. Please pick a future slot.'];
    }

    $latest = (new DateTime())->modify('+' . MAX_ADVANCE_DAYS . ' days');
    if ($slotStart > $latest) {
        return ['ok' => false, 'reason' => 'Bookings can only be made up to ' . MAX_ADVANCE_DAYS . ' days in advance.'];
    }

    // ---- (c) the provider must be working then -----------------------
    $dayOfWeek = (int) $slotStart->format('w');   // 0 = Sunday

    $stmt = $pdo->prepare(
        'SELECT start_time, end_time
           FROM provider_availability
          WHERE provider_id  = :pid
            AND day_of_week  = :dow
            AND is_available = 1'
    );
    $stmt->execute([':pid' => $providerId, ':dow' => $dayOfWeek]);
    $window = $stmt->fetch();

    if ($window) {
        $workStart = new DateTime($date . ' ' . $window['start_time']);
        $workEnd   = new DateTime($date . ' ' . $window['end_time']);

        if ($slotStart < $workStart || $slotEnd > $workEnd) {
            return [
                'ok'     => false,
                'reason' => 'The professional works '
                          . date(TIME_FORMAT, strtotime($window['start_time'])) . ' to '
                          . date(TIME_FORMAT, strtotime($window['end_time']))
                          . ' on ' . $slotStart->format('l') . '. Please pick a time inside that window.',
            ];
        }
    } else {
        // No published window for this weekday means the provider is off.
        return [
            'ok'     => false,
            'reason' => 'The professional does not accept bookings on ' . $slotStart->format('l') . '.',
        ];
    }

    // ---- (d) no overlapping booking ----------------------------------
    // The interval test is done in SQL so the composite index
    // idx_bookings_slot (provider_id, booking_date, booking_time) can
    // satisfy the lookup without a table scan.
    $sql = "SELECT booking_code, booking_time, duration_minutes
              FROM bookings
             WHERE provider_id  = :pid
               AND booking_date = :bdate
               AND status NOT IN ('cancelled', 'rejected')
               AND booking_time < :slot_end
               AND ADDTIME(booking_time, SEC_TO_TIME(duration_minutes * 60)) > :slot_start";

    $params = [
        ':pid'        => $providerId,
        ':bdate'      => $date,
        ':slot_end'   => $slotEnd->format('H:i:s'),
        ':slot_start' => $slotStart->format('H:i:s'),
    ];

    if ($ignoreBookingId !== null) {
        $sql .= ' AND booking_id <> :ignore';
        $params[':ignore'] = $ignoreBookingId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clash = $stmt->fetch();

    if ($clash) {
        return [
            'ok'     => false,
            'reason' => 'This professional is already booked at '
                      . date(TIME_FORMAT, strtotime($clash['booking_time']))
                      . ' that day. Please choose another time.',
        ];
    }

    return ['ok' => true, 'reason' => 'Slot is available.'];
}

/**
 * Build the list of selectable start times for the booking form.
 *
 * @param  PDO    $pdo
 * @param  int    $providerId
 * @param  string $date  Y-m-d
 * @param  int    $durationMinutes
 * @return array<int, array{value:string, label:string, free:bool, reason:string}>
 */
function build_slot_grid(PDO $pdo, int $providerId, string $date, int $durationMinutes = 60): array
{
    $slots = [];

    if (!valid_date($date)) {
        return $slots;
    }

    $cursor = new DateTime($date . ' ' . WORKDAY_START);
    $close  = new DateTime($date . ' ' . WORKDAY_END);

    while ($cursor < $close) {
        $time   = $cursor->format('H:i');
        $status = check_slot_available($pdo, $providerId, $date, $time, $durationMinutes);

        $slots[] = [
            'value'  => $time,
            'label'  => $cursor->format('h:i A'),
            'free'   => $status['ok'],
            'reason' => $status['reason'],
        ];

        $cursor->modify('+' . SLOT_INTERVAL_MINUTES . ' minutes');
    }

    return $slots;
}

/**
 * Record a booking status transition, writing both the UPDATE and the
 * audit row. The caller is expected to have opened a transaction.
 *
 * @param  PDO         $pdo
 * @param  int         $bookingId
 * @param  string      $oldStatus
 * @param  string      $newStatus
 * @param  int|null    $changedBy
 * @param  string|null $remarks
 * @return void
 */
function record_status_change(
    PDO $pdo,
    int $bookingId,
    string $oldStatus,
    string $newStatus,
    ?int $changedBy,
    ?string $remarks = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO booking_status_history
                (booking_id, old_status, new_status, changed_by, remarks)
         VALUES (:bid, :old, :new, :by, :remarks)'
    );
    $stmt->execute([
        ':bid'     => $bookingId,
        ':old'     => $oldStatus !== '' ? $oldStatus : null,
        ':new'     => $newStatus,
        ':by'      => $changedBy,
        ':remarks' => $remarks,
    ]);
}

/**
 * The status transitions each role is permitted to perform.
 *
 * Keeping this as data rather than scattered if-statements means the
 * workflow can be read at a glance and cannot drift between screens.
 *
 * @return array<string, array<string, array<int, string>>>
 */
function status_transitions(): array
{
    return [
        'provider' => [
            'pending'     => ['confirmed', 'rejected'],
            'confirmed'   => ['in_progress', 'cancelled'],
            'in_progress' => ['completed'],
        ],
        'customer' => [
            'pending'   => ['cancelled'],
            'confirmed' => ['cancelled'],
        ],
        'admin' => [
            'pending'     => ['confirmed', 'cancelled', 'rejected'],
            'confirmed'   => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
        ],
    ];
}

/**
 * Test whether a role may move a booking from one status to another.
 *
 * @param  string $role
 * @param  string $from
 * @param  string $to
 * @return bool
 */
function can_transition(string $role, string $from, string $to): bool
{
    $map = status_transitions();
    return in_array($to, $map[$role][$from] ?? [], true);
}


/* =====================================================================
 * SECTION 8 -- MAINTENANCE (AMC) HELPERS
 * ===================================================================*/

/**
 * Map a plan frequency onto the month interval between visits.
 *
 * @param  string $frequency
 * @return int
 */
function frequency_months(string $frequency): int
{
    return [
        'monthly'     => 1,
        'quarterly'   => 3,
        'half_yearly' => 6,
        'yearly'      => 12,
    ][$frequency] ?? 3;
}

/**
 * Human label for a plan frequency.
 *
 * @param  string $frequency
 * @return string
 */
function frequency_label(string $frequency): string
{
    return [
        'monthly'     => 'Every month',
        'quarterly'   => 'Every 3 months',
        'half_yearly' => 'Every 6 months',
        'yearly'      => 'Once a year',
    ][$frequency] ?? ucfirst($frequency);
}

/**
 * Generate the full visit schedule for a newly created contract.
 *
 * Called inside the same transaction that inserts the contract, so a
 * contract can never exist without its visits.
 *
 * @param  PDO    $pdo
 * @param  int    $contractId
 * @param  string $startDate   Y-m-d
 * @param  string $frequency
 * @param  int    $totalVisits
 * @return string  The date of the first scheduled visit (Y-m-d)
 */
function generate_maintenance_visits(
    PDO $pdo,
    int $contractId,
    string $startDate,
    string $frequency,
    int $totalVisits
): string {
    $months = frequency_months($frequency);
    $stmt   = $pdo->prepare(
        'INSERT INTO maintenance_visits
                (contract_id, visit_number, scheduled_date, status)
         VALUES (:cid, :num, :sched, :status)'
    );

    $firstVisit = null;

    for ($i = 1; $i <= $totalVisits; $i++) {
        // Visit 1 falls on the start date, visit 2 one interval later,
        // and so on. Using a fresh DateTime each iteration avoids the
        // month-end drift that repeated "+3 months" on one object
        // produces (31 Jan +1 month lands on 3 Mar).
        $date = (new DateTime($startDate))
            ->modify('+' . ($months * ($i - 1)) . ' months')
            ->format('Y-m-d');

        if ($firstVisit === null) {
            $firstVisit = $date;
        }

        $stmt->execute([
            ':cid'    => $contractId,
            ':num'    => $i,
            ':sched'  => $date,
            ':status' => $date <= date('Y-m-d') ? 'due' : 'scheduled',
        ]);
    }

    return $firstVisit ?? $startDate;
}

/**
 * Advance a contract after a visit is completed: increment the counter
 * and move next_due_date to the next outstanding visit (or NULL when
 * the entitlement is exhausted).
 *
 * @param  PDO $pdo
 * @param  int $contractId
 * @return void
 */
function advance_contract(PDO $pdo, int $contractId): void
{
    $stmt = $pdo->prepare(
        "SELECT MIN(scheduled_date) AS next_due
           FROM maintenance_visits
          WHERE contract_id = :cid
            AND status IN ('scheduled', 'due')"
    );
    $stmt->execute([':cid' => $contractId]);
    $nextDue = $stmt->fetchColumn() ?: null;

    $stmt = $pdo->prepare(
        "UPDATE maintenance_contracts
            SET visits_used   = (SELECT COUNT(*) FROM maintenance_visits
                                  WHERE contract_id = :cid1 AND status = 'completed'),
                next_due_date = :next,
                status        = CASE WHEN :next2 IS NULL THEN 'expired' ELSE status END
          WHERE contract_id = :cid2"
    );
    $stmt->execute([
        ':cid1'  => $contractId,
        ':next'  => $nextDue,
        ':next2' => $nextDue,
        ':cid2'  => $contractId,
    ]);
}


/* =====================================================================
 * SECTION 9 -- RATING RECALCULATION
 * ===================================================================*/

/**
 * Recompute the cached avg_rating / total_reviews on a provider from
 * the authoritative feedback rows.
 *
 * @param  PDO $pdo
 * @param  int $providerId
 * @return void
 */
function recalculate_provider_rating(PDO $pdo, int $providerId): void
{
    $stmt = $pdo->prepare(
        'UPDATE providers p
            SET p.avg_rating = COALESCE((
                    SELECT ROUND(AVG(f.rating), 2) FROM feedback f
                     WHERE f.provider_id = :pid1 AND f.is_approved = 1), 0),
                p.total_reviews = (
                    SELECT COUNT(*) FROM feedback f
                     WHERE f.provider_id = :pid2 AND f.is_approved = 1)
          WHERE p.provider_id = :pid3'
    );
    $stmt->execute([':pid1' => $providerId, ':pid2' => $providerId, ':pid3' => $providerId]);
}

/**
 * Recompute a provider's completed-job counter.
 *
 * @param  PDO $pdo
 * @param  int $providerId
 * @return void
 */
function recalculate_provider_jobs(PDO $pdo, int $providerId): void
{
    $stmt = $pdo->prepare(
        "UPDATE providers
            SET total_jobs = (SELECT COUNT(*) FROM bookings
                               WHERE provider_id = :pid1 AND status = 'completed')
          WHERE provider_id = :pid2"
    );
    $stmt->execute([':pid1' => $providerId, ':pid2' => $providerId]);
}


/* =====================================================================
 * SECTION 10 -- NOTIFICATIONS AND AUDIT LOG
 * ===================================================================*/

/**
 * Queue an in-app notification for a user.
 *
 * @param  PDO         $pdo
 * @param  int         $userId
 * @param  string      $title
 * @param  string      $message
 * @param  string|null $link
 * @param  string|null $icon
 * @return void
 */
function notify(PDO $pdo, int $userId, string $title, string $message, ?string $link = null, ?string $icon = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, link, icon)
         VALUES (:uid, :title, :message, :link, :icon)'
    );
    $stmt->execute([
        ':uid'     => $userId,
        ':title'   => mb_substr($title, 0, 120),
        ':message' => mb_substr($message, 0, 255),
        ':link'    => $link,
        ':icon'    => $icon,
    ]);
}

/**
 * Count a user's unread notifications.
 *
 * @param  PDO $pdo
 * @param  int $userId
 * @return int
 */
function unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute([':uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Append a row to the security / audit log.
 *
 * Written with its own try/catch because an audit failure must never
 * take down the operation being audited.
 *
 * @param  string      $action
 * @param  string|null $entity
 * @param  int|null    $entityId
 * @param  string|null $details
 * @return void
 */
function log_activity(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, action, entity, entity_id, details, ip_address)
             VALUES (:uid, :action, :entity, :eid, :details, :ip)'
        );
        $stmt->execute([
            ':uid'     => $_SESSION['user_id'] ?? null,
            ':action'  => mb_substr($action, 0, 60),
            ':entity'  => $entity,
            ':eid'     => $entityId,
            ':details' => $details !== null ? mb_substr($details, 0, 255) : null,
            ':ip'      => client_ip(),
        ]);
    } catch (Throwable $e) {
        // Swallow deliberately: logging is best-effort. In production
        // this would be forwarded to the PHP error log instead.
        if (APP_DEBUG) {
            error_log('activity_log failed: ' . $e->getMessage());
        }
    }
}

/**
 * Best-effort client IP address.
 *
 * @return string
 */
function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}


/* =====================================================================
 * SECTION 11 -- FILE UPLOADS
 * ---------------------------------------------------------------------
 * An upload form is the most direct route to remote code execution on a
 * PHP host: if an attacker can place attack.php in a web-reachable
 * folder, they own the server. The checks below are layered so that
 * defeating one is not enough.
 * ===================================================================*/

/**
 * Validate and store an uploaded image, returning its relative path.
 *
 * @param  array  $file    One entry from $_FILES
 * @param  string $prefix  Filename prefix, e.g. 'avatar'
 * @return array{ok:bool, path:?string, error:?string}
 */
function handle_image_upload(array $file, string $prefix = 'img'): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];   // nothing supplied is not an error
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'The file could not be uploaded. Please try again.'];
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'path' => null,
                'error' => 'Image must be smaller than ' . round(MAX_UPLOAD_BYTES / 1048576, 1) . ' MB.'];
    }

    // CHECK 1: is_uploaded_file confirms the path really came from a
    // POST upload, not from a crafted path elsewhere on disk.
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid upload.'];
    }

    // CHECK 2: read the real MIME type from the file's magic bytes.
    // The browser-supplied $file['type'] is attacker-controlled and is
    // never trusted.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowed = $GLOBALS['ALLOWED_IMAGE_TYPES'];
    $ext     = array_search($mime, $allowed, true);

    if ($ext === false) {
        return ['ok' => false, 'path' => null,
                'error' => 'Only JPG, PNG and WEBP images are accepted.'];
    }

    // CHECK 3: confirm it really decodes as an image.
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'path' => null, 'error' => 'That file is not a readable image.'];
    }

    if (!is_dir(UPLOAD_PATH) && !mkdir(UPLOAD_PATH, 0755, true) && !is_dir(UPLOAD_PATH)) {
        return ['ok' => false, 'path' => null, 'error' => 'Upload folder is not writable.'];
    }

    // CHECK 4: the stored name is generated by us, never taken from the
    // user. The extension comes from the verified MIME type, so a file
    // called "shell.php" cannot keep its extension.
    $name = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $ext);
    $dest = UPLOAD_PATH . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not save the uploaded image.'];
    }

    return ['ok' => true, 'path' => 'assets/uploads/' . $name, 'error' => null];
}


/* =====================================================================
 * SECTION 12 -- PRESENTATION HELPERS
 * ===================================================================*/

/**
 * Format an amount as Indian currency.
 *
 * @param  float|string|null $amount
 * @return string
 */
function money($amount): string
{
    return CURRENCY . ' ' . number_format((float) $amount, 2);
}

/**
 * Format a stored date for display.
 *
 * @param  string|null $date
 * @return string
 */
function show_date(?string $date): string
{
    return $date ? date(DATE_FORMAT, strtotime($date)) : '--';
}

/**
 * Format a stored time for display.
 *
 * @param  string|null $time
 * @return string
 */
function show_time(?string $time): string
{
    return $time ? date(TIME_FORMAT, strtotime($time)) : '--';
}

/**
 * Format a stored datetime for display.
 *
 * @param  string|null $dt
 * @return string
 */
function show_datetime(?string $dt): string
{
    return $dt ? date(DATETIME_FORMAT, strtotime($dt)) : '--';
}

/**
 * Render a coloured status pill.
 *
 * @param  string $status
 * @return string
 */
function status_badge(string $status): string
{
    $labels = [
        'pending'     => 'Pending',
        'confirmed'   => 'Confirmed',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
        'rejected'    => 'Rejected',
        'active'      => 'Active',
        'suspended'   => 'Suspended',
        'verified'    => 'Verified',
        'expired'     => 'Expired',
        'scheduled'   => 'Scheduled',
        'due'         => 'Due',
        'missed'      => 'Missed',
        'paid'        => 'Paid',
        'refunded'    => 'Refunded',
    ];

    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    return '<span class="badge badge--' . e($status) . '">' . e($label) . '</span>';
}

/**
 * Render a five-star rating display.
 *
 * @param  float $rating
 * @param  int   $reviews
 * @return string
 */
function star_rating(float $rating, int $reviews = 0): string
{
    $full  = (int) floor($rating);
    $half  = ($rating - $full) >= 0.5;
    $stars = '';

    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full) {
            $stars .= '<span class="star star--full">&#9733;</span>';
        } elseif ($i === $full + 1 && $half) {
            $stars .= '<span class="star star--half">&#9733;</span>';
        } else {
            $stars .= '<span class="star star--empty">&#9734;</span>';
        }
    }

    $suffix = $reviews > 0
        ? '<span class="rating__count">' . number_format($rating, 1) . ' (' . $reviews . ')</span>'
        : '<span class="rating__count rating__count--none">No reviews yet</span>';

    return '<span class="rating">' . $stars . $suffix . '</span>';
}

/**
 * Produce the initials used by the avatar placeholder.
 *
 * @param  string $name
 * @return string
 */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * Truncate text for table cells, preserving whole words.
 *
 * @param  string|null $text
 * @param  int         $limit
 * @return string
 */
function excerpt(?string $text, int $limit = 60): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '--';
    }
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . '...';
}

/**
 * Mark the current navigation item.
 *
 * @param  string $file  Basename to compare against, e.g. 'dashboard.php'
 * @return string
 */
function nav_active(string $file): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '') === $file ? ' is-active' : '';
}

/**
 * Render pagination links.
 *
 * @param  int    $currentPage
 * @param  int    $totalPages
 * @param  string $baseQuery  Existing query string without the page param
 * @return string
 */
function paginate(int $currentPage, int $totalPages, string $baseQuery = ''): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $sep  = $baseQuery !== '' ? '&amp;' : '';
    $html = '<nav class="pagination" aria-label="Pagination">';

    $prevDisabled = $currentPage <= 1 ? ' is-disabled' : '';
    $html .= '<a class="pagination__link' . $prevDisabled . '" href="?' . e($baseQuery) . $sep
           . 'page=' . max(1, $currentPage - 1) . '">&laquo; Prev</a>';

    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' is-active' : '';
        $html  .= '<a class="pagination__link' . $active . '" href="?' . e($baseQuery) . $sep
                . 'page=' . $i . '">' . $i . '</a>';
    }

    $nextDisabled = $currentPage >= $totalPages ? ' is-disabled' : '';
    $html .= '<a class="pagination__link' . $nextDisabled . '" href="?' . e($baseQuery) . $sep
           . 'page=' . min($totalPages, $currentPage + 1) . '">Next &raquo;</a>';

    return $html . '</nav>';
}
