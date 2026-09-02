<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : config/database.php
 *  PURPOSE : Database connectivity layer.
 *
 *  The whole application talks to MySQL through ONE PDO connection
 *  obtained from Database::getConnection(). Centralising this gives
 *  three concrete benefits:
 *
 *    1. SECURITY -- PDO with emulated prepares DISABLED means the SQL
 *       text and the user-supplied values travel to the server
 *       separately. The value can therefore never be parsed as SQL,
 *       which structurally defeats SQL injection rather than merely
 *       filtering for it.
 *
 *    2. CORRECTNESS -- ERRMODE_EXCEPTION makes every failed query throw.
 *       Errors cannot be silently ignored, which is what the project
 *       guidelines mean by "complete error handling".
 *
 *    3. EFFICIENCY -- the connection is created once per request
 *       (Singleton pattern) instead of once per query.
 * =====================================================================
 */

class Database
{
    /**
     * Connection settings, resolved at runtime.
     *
     * Each value is read from an environment variable if one is set, and
     * otherwise falls back to the XAMPP default. That single rule lets
     * the same code run in both places the project needs to work:
     *
     *   - LOCALLY under XAMPP, where no variables are set, it connects
     *     to 127.0.0.1 as root with no password, exactly as before.
     *
     *   - ON A SERVER (Docker, Coolify), where the platform injects
     *     DB_HOST / DB_USER / DB_PASSWORD, it uses those instead.
     *
     * The point is that a real password never has to be written into a
     * file that gets committed to version control.
     *
     * @param  string $key      Environment variable name
     * @param  string $fallback Value to use when it is not set
     * @return string
     */
    private static function env(string $key, string $fallback): string
    {
        // getenv() covers variables exported into the process;
        // $_ENV / $_SERVER cover those injected by the web server.
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return $value !== '' ? (string) $value : $fallback;
    }

    /** @var string Must match the CHARSET of the schema. */
    private const DB_CHARSET = 'utf8mb4';

    /** @var PDO|null The one connection shared by the whole request. */
    private static ?PDO $connection = null;

    /**
     * Return the shared PDO connection, opening it on first use.
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::env('DB_HOST', '127.0.0.1'),
            (int) self::env('DB_PORT', '3306'),
            self::env('DB_NAME', 'lsbms_db'),
            self::DB_CHARSET
        );

        $options = [
            // Throw a PDOException on any error instead of returning false.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

            // fetch() returns an associative array by default.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // CRITICAL: turn OFF driver-side emulation so that real
            // server-side prepared statements are used. With emulation
            // on, PDO interpolates the values itself, which reintroduces
            // the very injection risk prepared statements exist to stop.
            PDO::ATTR_EMULATE_PREPARES   => false,

            // Keep integers as PHP integers rather than numeric strings.
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            self::$connection = new PDO(
                $dsn,
                self::env('DB_USER', 'root'),
                self::env('DB_PASSWORD', ''),
                $options
            );

            // Strict mode makes MySQL reject (rather than silently
            // truncate) values that do not fit a column. Silent
            // truncation is a classic source of corrupt data.
            self::$connection->exec(
                "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'"
            );
            self::$connection->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            self::fail($e);
        }

        return self::$connection;
    }

    /**
     * Render a friendly, actionable message when the database is
     * unreachable, and stop. The raw driver message is shown only while
     * APP_DEBUG is on -- in production it would disclose the host,
     * schema name and credentials structure to a visitor.
     *
     * @param  PDOException $e
     * @return never
     */
    private static function fail(PDOException $e): void
    {
        http_response_code(503);

        $detail = (defined('APP_DEBUG') && APP_DEBUG)
            ? '<p class="db-error__detail">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        echo '<!doctype html><meta charset="utf-8">'
           . '<title>Database unavailable</title>'
           . '<style>'
           . 'body{font:15px/1.6 system-ui,Segoe UI,sans-serif;background:#f5f6f8;'
           . 'margin:0;display:grid;place-items:center;min-height:100vh;color:#1c2430}'
           . '.db-error{background:#fff;max-width:560px;padding:32px 36px;border-radius:12px;'
           . 'box-shadow:0 8px 32px rgba(16,24,40,.10);border-top:4px solid #d92d20}'
           . 'h1{margin:0 0 8px;font-size:19px}'
           . 'ol{margin:14px 0 0;padding-left:20px}li{margin:6px 0}'
           . 'code{background:#eef0f4;padding:2px 6px;border-radius:4px;font-size:13px}'
           . '.db-error__detail{margin-top:16px;padding:10px 12px;background:#fff4f3;'
           . 'border-left:3px solid #d92d20;font-size:13px;color:#912018;word-break:break-word}'
           . '</style>'
           . '<div class="db-error">'
           . '<h1>Cannot connect to the database</h1>'
           . '<p>The application is running, but MySQL did not answer. To fix this:</p>'
           . '<ol>'
           . '<li>Open the <strong>XAMPP Control Panel</strong>.</li>'
           . '<li>Press <strong>Start</strong> next to <code>MySQL</code> and wait for it to turn green.</li>'
           . '<li>Confirm the schema exists &mdash; import <code>database/lsbms_schema.sql</code> '
           . 'in <a href="http://localhost/phpmyadmin">phpMyAdmin</a> if it does not.</li>'
           . '<li>Reload this page.</li>'
           . '</ol>'
           . $detail
           . '</div>';
        exit;
    }

    /** Prevent instantiation -- this class is a static service. */
    private function __construct() {}

    /** Prevent cloning of the singleton. */
    private function __clone() {}
}

/**
 * Convenience shorthand used throughout the application.
 *
 * @return PDO
 */
function db(): PDO
{
    return Database::getConnection();
}
