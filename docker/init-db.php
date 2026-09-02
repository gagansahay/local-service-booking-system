<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : docker/init-db.php
 *  PURPOSE : Import the schema and demonstration data on first start.
 *
 *  WHY THIS EXISTS
 *  ---------------
 *  The obvious way to seed a MySQL container is to mount the .sql files
 *  into /docker-entrypoint-initdb.d/. That works with plain Docker
 *  Compose, where the repository sits on the host beside the compose
 *  file. It does NOT work on a platform such as Coolify, which builds
 *  the image from a checkout it then discards: the host path the bind
 *  mount refers to does not exist, so Docker silently creates an empty
 *  DIRECTORY in its place, MySQL finds no .sql files, and the database
 *  initialises empty. The application then fails on its first query.
 *
 *  Running the import from inside the application container removes the
 *  dependency on any host path, because the SQL files are baked into
 *  the image alongside the code.
 *
 *  The import is idempotent: it does nothing at all if the schema is
 *  already populated, so a redeploy never destroys live data.
 * =====================================================================
 */

declare(strict_types=1);

const MAX_WAIT_SECONDS = 60;

function out(string $message): void
{
    fwrite(STDERR, '[init-db] ' . $message . PHP_EOL);
}

function env_value(string $key, string $fallback): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }
    return $value !== '' ? (string) $value : $fallback;
}

/**
 * Split a SQL script into individual statements.
 *
 * A naive explode(';') is wrong here: this schema contains column
 * COMMENT strings that themselves include a semicolon, for example
 * "bcrypt output of password_hash(); never plaintext". Splitting on
 * those would produce invalid fragments, so quote state is tracked.
 *
 * @param  string $sql
 * @return array<int, string>
 */
function split_statements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inSingle   = false;
    $inDouble   = false;
    $inBacktick = false;
    $inComment  = false;

    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        // Line comments run to the end of the line.
        if ($inComment) {
            $current .= $char;
            if ($char === "\n") {
                $inComment = false;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if (($char === '-' && $next === '-') || $char === '#') {
                $inComment = true;
                $current  .= $char;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            // A doubled quote is an escaped quote, not a terminator.
            if ($inSingle && $next === "'") {
                $current .= $char . $next;
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        } elseif ($char === '\\' && ($inSingle || $inDouble)) {
            // Backslash escape inside a string: consume the next char.
            $current .= $char . $next;
            $i++;
            continue;
        } elseif ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statements[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return $statements;
}

/**
 * Should this statement be skipped?
 *
 * The application's database user holds privileges on its own schema
 * only, so it cannot DROP or CREATE a database. The container platform
 * has already created the schema; these statements are therefore both
 * unnecessary and impossible, and are filtered out.
 *
 * @param  string $statement
 * @return bool
 */
function should_skip(string $statement): bool
{
    // Strip comment lines before deciding.
    $lines = [];
    foreach (explode("\n", $statement) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $lines[] = $line;
    }

    $body = strtoupper(trim(implode(' ', $lines)));

    if ($body === '') {
        return true;
    }

    foreach (['DROP DATABASE', 'CREATE DATABASE', 'USE '] as $prefix) {
        if (str_starts_with($body, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Run one SQL file against an open connection.
 *
 * @param  PDO    $pdo
 * @param  string $path
 * @return array{ran:int, skipped:int, failed:int}
 */
function run_file(PDO $pdo, string $path): array
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        out('cannot read ' . $path);
        return ['ran' => 0, 'skipped' => 0, 'failed' => 1];
    }

    $ran = $skipped = $failed = 0;

    foreach (split_statements($sql) as $statement) {
        if (should_skip($statement)) {
            $skipped++;
            continue;
        }
        try {
            $pdo->exec($statement);
            $ran++;
        } catch (PDOException $e) {
            $failed++;
            out('statement failed: ' . substr(preg_replace('/\s+/', ' ', trim($statement)), 0, 110));
            out('  -> ' . $e->getMessage());
        }
    }

    return ['ran' => $ran, 'skipped' => $skipped, 'failed' => $failed];
}


/* =====================================================================
 * MAIN
 * ===================================================================*/

$host = env_value('DB_HOST', '127.0.0.1');
$port = env_value('DB_PORT', '3306');
$name = env_value('DB_NAME', 'lsbms_db');
$user = env_value('DB_USER', 'root');
$pass = env_value('DB_PASSWORD', '');

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name);

// ---- wait for the database to accept connections --------------------
$pdo      = null;
$deadline = time() + MAX_WAIT_SECONDS;

while (time() < $deadline) {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        break;
    } catch (PDOException $e) {
        out('waiting for the database ... ' . $e->getMessage());
        sleep(3);
    }
}

if (!$pdo instanceof PDO) {
    out('gave up waiting for the database; leaving the schema alone');
    exit(0);           // never block Apache from starting
}

// ---- is the schema already populated? -------------------------------
try {
    $tables = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
} catch (PDOException $e) {
    out('cannot inspect the schema: ' . $e->getMessage());
    exit(0);
}

if ($tables > 0) {
    out(sprintf('schema already has %d tables; nothing to do', $tables));
    exit(0);
}

out('schema is empty — importing');

$base = dirname(__DIR__) . '/database/';

foreach (['lsbms_schema.sql', 'lsbms_seed.sql'] as $file) {
    $path = $base . $file;
    if (!is_file($path)) {
        out('missing ' . $path);
        continue;
    }
    $r = run_file($pdo, $path);
    out(sprintf('%s: %d executed, %d skipped, %d failed',
                $file, $r['ran'], $r['skipped'], $r['failed']));
}

$tables = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();

out(sprintf('import complete — %d tables now present', $tables));
exit(0);
