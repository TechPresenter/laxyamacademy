<?php
/**
 * Server-side role-based access control.
 * Roles and permissions live in MySQL (roles, permissions, role_permissions, user_roles).
 * The `super_admin` role implicitly holds every permission.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Role slugs for a user. */
function user_role_slugs(int $userId): array
{
    static $cache = [];
    return $cache[$userId] ??= array_column(db_all(
        'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
        [$userId]
    ), 'slug');
}

/** Permission slugs for a user. */
function user_permission_slugs(int $userId): array
{
    static $cache = [];
    return $cache[$userId] ??= array_column(db_all(
        'SELECT DISTINCT p.slug
         FROM user_roles ur
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE ur.user_id = ?',
        [$userId]
    ), 'slug');
}

function is_super_admin(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && $user['user_type'] === 'staff' && in_array('super_admin', user_role_slugs((int) $user['id']), true);
}

/** Does the current (or given) staff user hold $permission? */
function can(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    if (!$user || $user['user_type'] !== 'staff') {
        return false;
    }
    if (is_super_admin($user)) {
        return true;
    }
    return in_array($permission, user_permission_slugs((int) $user['id']), true);
}

/** Stop with 403 (JSON for API, page for admin) unless the user holds $permission. */
function require_permission(string $permission): void
{
    if (can($permission)) {
        return;
    }
    $user = current_user();
    audit_log('access_denied', 'permission', $permission, ['path' => $_SERVER['REQUEST_URI'] ?? ''], 'warning');
    if (is_api_request()) {
        api_error('You do not have permission to do this.', $user ? 403 : 401, 'forbidden');
    }
    // Admin pages: the styled access-denied screen inside the admin shell (includes/admin-header.php).
    if ($user && $user['user_type'] === 'staff' && function_exists('admin_forbidden')) {
        admin_forbidden($permission);
    }
    http_response_code(403);
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access denied</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F9FF;font-family:Inter,Segoe UI,system-ui,sans-serif;color:#08264D">'
        . '<main style="max-width:440px;margin:24px;padding:32px;background:#fff;border:1px solid #E2EAF4;border-radius:20px;box-shadow:0 12px 32px rgb(8 38 77 / .08);text-align:center">'
        . '<h1 style="font-size:20px;margin:0 0 8px">You don’t have access to this page</h1>'
        . '<p style="color:#64748B;margin:0">Ask a Super Admin to add the permission to your role if you need it.</p></main></body></html>');
}
