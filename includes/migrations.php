<?php
/**
 * Database updates: applies database/migration-NNN.sql files that are newer than the
 * versions recorded in schema_migrations. Used by admin/updates.php after a deploy,
 * so the live database can be updated without phpMyAdmin.
 *
 * Statements run one by one. MySQL commits DDL immediately, so migrations are written to
 * be re-runnable: "already exists" errors (table/column/key/duplicate row) are skipped.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// 1050 table exists, 1060 duplicate column, 1061 duplicate key name, 1062 duplicate entry,
// 1022 duplicate key, 1826 duplicate foreign key, 1091 can't drop (already gone)
const MIGRATION_SKIPPABLE = [1050, 1060, 1061, 1062, 1022, 1826, 1091];

/** Files removed from the codebase that an older deploy may have left on the server (extracting a zip cannot delete them). */
const OBSOLETE_FILES = ['admin/coming.php'];

function obsolete_files(): array
{
    return array_values(array_filter(OBSOLETE_FILES, fn($f) => is_file(APP_ROOT . '/' . $f)));
}

/** version => [file, description] for every migration file, ascending. */
function migration_files(): array
{
    $out = [];
    foreach (glob(APP_ROOT . '/database/migration-*.sql') ?: [] as $file) {
        if (preg_match('/migration-(\d+)\.sql$/', $file, $m)) {
            $head = (string) file_get_contents($file, false, null, 0, 600);
            $desc = preg_match('/--\s*Migration\s+\d+:\s*(.+)/i', $head, $d) ? trim($d[1]) : basename($file);
            $out[(int) $m[1]] = [$file, $desc];
        }
    }
    ksort($out);
    return $out;
}

function applied_migrations(): array
{
    return array_map('intval', array_column(db_all('SELECT version FROM schema_migrations'), 'version'));
}

/** version => [file, description] not applied yet. */
function pending_migrations(): array
{
    $applied = applied_migrations();
    $max = $applied ? max($applied) : 0;
    return array_filter(migration_files(), fn($v) => $v > $max && !in_array($v, $applied, true), ARRAY_FILTER_USE_KEY);
}

/** Split SQL into statements, respecting quotes, backticks and comments. */
function sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $quote = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $sql[++$i]; // doubled quote inside a string
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
        } elseif ($c === '#' || ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))) {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
        } elseif ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
        } elseif ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

/** Run one migration file. Returns ['ok', 'executed', 'skipped' => [messages], 'error']. */
function apply_migration(int $version, string $file, string $description): array
{
    $res = ['ok' => true, 'executed' => 0, 'skipped' => [], 'error' => null];
    foreach (sql_statements((string) file_get_contents($file)) as $n => $stmt) {
        try {
            db()->exec($stmt);
            $res['executed']++;
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($code, MIGRATION_SKIPPABLE, true)) {
                $res['skipped'][] = 'Statement ' . ($n + 1) . ': ' . $e->errorInfo[2];
                continue;
            }
            $res['ok'] = false;
            $res['error'] = 'Statement ' . ($n + 1) . ' failed: ' . ($e->errorInfo[2] ?? $e->getMessage());
            return $res;
        }
    }
    if (!in_array($version, applied_migrations(), true)) {
        db_query('INSERT INTO schema_migrations (version, description) VALUES (?, ?)', [$version, mb_substr($description, 0, 255)]);
    }
    return $res;
}
