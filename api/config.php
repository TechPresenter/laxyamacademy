<?php
/**
 * Configuration loader + bootstrap.
 *
 * Every API endpoint and admin page starts with:
 *     require __DIR__ . '/config.php';            (from /api)
 *     require __DIR__ . '/../api/config.php';     (from /admin)
 *
 * It reads `.env`, sets PHP runtime options, and loads the shared
 * database connection and helper libraries exactly once.
 * Direct web access to this file is blocked in .htaccess.
 */
declare(strict_types=1);

if (defined('LAXYAM_BOOTSTRAPPED')) {
    return;
}
define('LAXYAM_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));
define('PRIVATE_UPLOADS', APP_ROOT . '/uploads/private');

/** Parse a .env file into an array (no variable expansion, no shell execution). */
function load_env_file(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }
    $vars = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim(str_replace("\xEF\xBB\xBF", '', $line));
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value);
        }
        $vars[$key] = $value;
    }
    return $vars;
}

/** The parsed .env file (read once per request). */
function env_values(): array
{
    static $env = null;
    return $env ??= load_env_file(APP_ROOT . '/.env');
}

/**
 * Read a configuration value. Secrets never leave the server.
 * Integration keys saved in admin → Keys & Credentials (includes/app-config.php) take precedence over .env.
 */
function config(string $key, mixed $default = null): mixed
{
    $value = defined('APP_CONFIG_FIELDS') && isset(APP_CONFIG_FIELDS[$key]) ? app_config_value($key) : '';
    if ($value === null) {
        return $default; // saved in admin but unreadable (encryption key lost): treat as not set
    }
    if ($value === '') {
        $value = env_values()[$key] ?? getenv($key);
    }
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        default => $value,
    };
}

function is_production(): bool
{
    return config('APP_ENV', 'production') === 'production';
}

// --- Runtime settings ------------------------------------------------
date_default_timezone_set((string) config('APP_TIMEZONE', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', (config('APP_DEBUG', false) && !is_production()) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

require __DIR__ . '/db.php';
require APP_ROOT . '/includes/app-config.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/includes/auth-check.php';
require APP_ROOT . '/includes/permissions.php';
