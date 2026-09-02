<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : config/config.php
 *  PURPOSE : Global application configuration. This is the single place
 *            where environment-dependent values live, so that moving the
 *            application to another machine requires editing one file.
 *
 *  Student : Gagan Sahay  (Enrolment No. 2400652732)
 *  Guide   : Soumik Laik
 * =====================================================================
 */

// ---------------------------------------------------------------------
// Error reporting
// ---------------------------------------------------------------------
// During development every notice is surfaced so that bugs are found
// early. On a public server this MUST be off -- a stack trace discloses
// file paths, query structure and sometimes credentials to any visitor.
//
// Defaults to ON locally (no variable set) and is switched off by
// setting APP_DEBUG=false in the hosting platform's environment, so a
// deployment cannot accidentally ship in debug mode.
// ---------------------------------------------------------------------
$debugFlag = getenv('APP_DEBUG');
if ($debugFlag === false || $debugFlag === '') {
    $debugFlag = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'true';
}
define('APP_DEBUG', !in_array(strtolower((string) $debugFlag), ['false', '0', 'off', 'no'], true));

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ---------------------------------------------------------------------
// Application identity
// ---------------------------------------------------------------------
define('APP_NAME',      'Local Service Booking & Management System');
define('APP_SHORT',     'LSBMS');
define('APP_TAGLINE',   'Trusted local professionals, booked in minutes');
define('APP_VERSION',   '1.0');

// Student / academic details, printed on the About screen and invoices.
define('STUDENT_NAME',      'Gagan Sahay');
define('STUDENT_ENROLMENT', '2400652732');
define('STUDENT_PROGRAMME', 'Bachelor of Computer Applications (BCA)');
define('COURSE_CODE',       'BCSP-064');
define('GUIDE_NAME',        'Soumik Laik');
define('REGIONAL_CENTRE',   '39 - NOIDA');
define('STUDY_CENTRE',      '07107 - Maharaja Agrasen College');

// ---------------------------------------------------------------------
// Paths and URLs
// ---------------------------------------------------------------------
// BASE_URL is derived at runtime rather than hard-coded, so the project
// works unchanged whether it is served from http://localhost/lsbms or
// from a virtual host.
// ---------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
// Walk up out of any sub-folder (admin/, customer/, provider/, auth/, ajax/)
// so BASE_URL always points at the application root.
foreach (['/admin', '/customer', '/provider', '/auth', '/ajax'] as $sub) {
    if (substr($scriptDir, -strlen($sub)) === $sub) {
        $scriptDir = substr($scriptDir, 0, -strlen($sub));
        break;
    }
}
$scriptDir = rtrim($scriptDir, '/');

define('BASE_URL',    $scriptDir === '' ? '/' : $scriptDir . '/');
define('ASSETS_URL',  BASE_URL . 'assets/');
define('UPLOAD_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads');
define('UPLOAD_URL',  ASSETS_URL . 'uploads/');

// ---------------------------------------------------------------------
// Locale
// ---------------------------------------------------------------------
date_default_timezone_set('Asia/Kolkata');
define('CURRENCY',        'Rs.');
define('DATE_FORMAT',     'd M Y');
define('TIME_FORMAT',     'h:i A');
define('DATETIME_FORMAT', 'd M Y, h:i A');

// ---------------------------------------------------------------------
// Business rules
// ---------------------------------------------------------------------
define('MIN_PASSWORD_LENGTH',   8);
define('SLOT_INTERVAL_MINUTES', 60);   // Booking grid granularity
define('MAX_ADVANCE_DAYS',      60);   // How far ahead a booking may be made
define('MAX_UPLOAD_BYTES',      2 * 1024 * 1024);   // 2 MB profile photo cap
define('RECORDS_PER_PAGE',      10);

/** Working-hours window offered in the booking form. */
define('WORKDAY_START', '08:00');
define('WORKDAY_END',   '20:00');

/** Allowed image types for profile / ID uploads (extension => MIME). */
$GLOBALS['ALLOWED_IMAGE_TYPES'] = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

// ---------------------------------------------------------------------
// Session hardening
// ---------------------------------------------------------------------
// These must be set BEFORE session_start(), which is why configuration
// is always the first include in every entry point.
//   httponly  -> the cookie cannot be read by JavaScript (blunts XSS)
//   samesite  -> the cookie is not sent on cross-site POSTs (blunts CSRF)
//   use_strict_mode -> refuses a session ID the server never issued
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('LSBMSSESSID');
    session_start();
}
