<?php
/**
 * CSRF protection (synchronizer token stored in the PHP session).
 *
 * React fetches the token from api/auth.php?action=csrf and sends it in the
 * X-CSRF-Token header. Admin PHP forms include csrf_field().
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    start_secure_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/** Stop unsafe requests that lack a valid token. */
function require_csrf(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (!csrf_valid()) {
        if (is_api_request()) {
            // 403 rather than 419: Apache rewrites unknown status codes to 500.
            api_error('Your session has expired. Please refresh the page and try again.', 403, 'csrf_failed');
        }
        http_response_code(403);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}
