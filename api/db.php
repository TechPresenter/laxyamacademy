<?php
/**
 * The single shared PDO connection. Loaded by api/config.php.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('DB_HOST', '127.0.0.1'),
            (int) config('DB_PORT', 3306),
            config('DB_NAME', 'laxyam_academy')
        );
        $pdo = new PDO($dsn, (string) config('DB_USER', 'root'), (string) config('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Store and compare DATETIMEs in the application timezone (India has no DST).
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        // Long SMTP waits (up to 2 minutes per email in cron) must not let the server drop this idle connection.
        $pdo->exec('SET SESSION wait_timeout = GREATEST(@@session.wait_timeout, 900)');
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Run $fn inside a transaction; commits on success, rolls back and rethrows on failure.
 */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Build "?, ?, ?" for an IN() clause. */
function db_placeholders(int $count): string
{
    return implode(', ', array_fill(0, max(1, $count), '?'));
}
