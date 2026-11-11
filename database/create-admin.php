<?php
/**
 * Create (or promote) a Super Admin from the command line.
 * The /database folder is blocked from the web by .htaccess; this script also refuses non-CLI use.
 *
 *   php database/create-admin.php "Full Name" admin@example.com
 *
 * You will be prompted for the password (minimum 10 characters).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/config.php';

[$script, $name, $email] = array_pad($argv, 3, null);
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php database/create-admin.php \"Full Name\" admin@example.com\n");
    exit(1);
}

fwrite(STDOUT, 'Password (min 10 chars): ');
// Strip a UTF-8 BOM that some shells (e.g. Windows PowerShell) prepend to piped input.
$password = trim(str_replace("\xEF\xBB\xBF", '', (string) fgets(STDIN)));
if (strlen($password) < 10) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}

$email = strtolower($email);
db_transaction(function () use ($name, $email, $password) {
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        $userId = (int) $existing['id'];
        db_query("UPDATE users SET name = ?, password_hash = ?, user_type = 'staff', status = 'active' WHERE id = ?",
            [$name, password_hash($password, PASSWORD_DEFAULT), $userId]);
    } else {
        db_query("INSERT INTO users (name, email, password_hash, user_type, status, email_verified_at) VALUES (?, ?, ?, 'staff', 'active', NOW())",
            [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) db()->lastInsertId();
    }
    db_query("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'super_admin'", [$userId]);
    db_query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, severity) VALUES (?, 'admin_created_cli', 'user', ?, 'critical')",
        [$userId, (string) $userId]);
});

fwrite(STDOUT, "Super Admin ready: {$email}\n");
