<?php
/**
 * Server-to-server API for integrations (CRM, Zapier, reporting). Keys are created in
 * admin → Integrations → API Keys; each key has scopes and every call is logged.
 *
 *   Authorization: Bearer lxa_…   (or X-API-Key: lxa_…)
 *
 *   GET  v1.php?resource=courses                       scope courses.read    published catalogue
 *   GET  v1.php?resource=enquiries&since=&page=        scope enquiries.read  leads (newest first)
 *   POST v1.php?resource=enquiries  JSON name,email,phone,message,source_note   scope enquiries.write
 *   GET  v1.php?resource=orders&since=&page=           scope orders.read     paid orders
 *   GET  v1.php?resource=students&since=&page=         scope students.read   registrations (no passwords)
 *
 * No cookies or sessions are used; responses are JSON { ok, data, meta }.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Cache-Control: no-store');
$resource = preg_replace('/[^a-z]/', '', (string) ($_GET['resource'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$keyRow = null;

/** Log the call, then send the response. */
function v1_finish(int $status, array $body): never
{
    global $keyRow, $resource, $method;
    db_query('INSERT INTO api_access_logs (api_key_id, method, endpoint, status, ip_address) VALUES (?, ?, ?, ?, ?)',
        [$keyRow['id'] ?? null, substr($method, 0, 8), substr($resource ?: '-', 0, 120), $status, client_ip()]);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function v1_error(int $status, string $code, string $message): never
{
    v1_finish($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
}

$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders()) : [];
$auth = (string) ($headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($headers['x-api-key'] ?? '');
if (!preg_match('/^lxa_[a-f0-9]{40}$/', $key)) {
    v1_error(401, 'unauthorized', 'Send a valid API key in the Authorization: Bearer header.');
}
$keyRow = db_one('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL', [hash('sha256', $key)]);
if (!$keyRow) {
    $keyRow = null;
    v1_error(401, 'unauthorized', 'This API key is not valid or has been revoked.');
}
if (!rate_limit('api-v1', (string) $keyRow['id'], 600, 3600)) {
    v1_error(429, 'rate_limited', 'Rate limit: 600 requests per hour per key.');
}
if (random_int(1, 5) === 1 || !$keyRow['last_used_at']) {
    db_query('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?', [$keyRow['id']]);
}
$scopes = json_decode_array($keyRow['scopes']);
$need = fn(string $s) => in_array($s, $scopes, true) || v1_error(403, 'forbidden', "This key does not have the {$s} scope.");

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 100;
$since = isset($_GET['since']) && strtotime((string) $_GET['since']) ? date('Y-m-d H:i:s', strtotime((string) $_GET['since'])) : '1970-01-01 00:00:00';
$list = function (string $sql, array $params) use ($page, $per): never {
    $rows = db_all($sql . ' LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    v1_finish(200, ['ok' => true, 'data' => array_slice($rows, 0, $per), 'meta' => ['page' => $page, 'per_page' => $per, 'has_more' => count($rows) > $per]]);
};

match (true) {
    $resource === 'courses' && $method === 'GET' => $need('courses.read') && $list(
        "SELECT c.id, c.title, c.slug, cat.name AS category, c.price, c.discount_price, c.course_type, c.level, c.language, c.validity_days, c.updated_at
         FROM courses c JOIN course_categories cat ON cat.id = c.category_id WHERE c.status = 'published' AND c.updated_at >= ? ORDER BY c.id", [$since]),
    $resource === 'enquiries' && $method === 'GET' => $need('enquiries.read') && $list(
        'SELECT e.id, e.name, e.email, e.phone, e.subject, e.message, e.source, e.status, c.slug AS course_slug, e.created_at
         FROM enquiries e LEFT JOIN courses c ON c.id = e.course_id WHERE e.created_at >= ? ORDER BY e.id DESC', [$since]),
    $resource === 'orders' && $method === 'GET' => $need('orders.read') && $list(
        "SELECT o.order_number, o.status, o.subtotal, o.discount, o.total, o.currency, o.paid_at, u.name AS student_name, u.email AS student_email,
                (SELECT GROUP_CONCAT(oi.title SEPARATOR ' | ') FROM order_items oi WHERE oi.order_id = o.id) AS items
         FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status IN ('paid','refunded','partially_refunded') AND o.paid_at >= ? ORDER BY o.paid_at DESC", [$since]),
    $resource === 'students' && $method === 'GET' => $need('students.read') && $list(
        "SELECT id, name, email, phone, status, email_verified_at IS NOT NULL AS email_verified, created_at
         FROM users WHERE user_type = 'student' AND created_at >= ? ORDER BY id DESC", [$since]),
    $resource === 'enquiries' && $method === 'POST' => (function () use ($need, $keyRow): never {
        $need('enquiries.write');
        $in = json_decode((string) file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : [];
        $name = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = preg_replace('/[^\d+]/', '', (string) ($in['phone'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Name is required (2-120 characters).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email.';
        }
        if ($phone !== '' && !preg_match('/^\+?\d{10,15}$/', $phone)) {
            $errors['phone'] = 'Invalid phone.';
        }
        if ($email === '' && $phone === '') {
            $errors['email'] = 'Email or phone is required.';
        }
        if ($errors) {
            v1_finish(422, ['ok' => false, 'error' => ['code' => 'validation_failed', 'message' => 'Please correct the fields.', 'fields' => $errors]]);
        }
        db_query("INSERT INTO enquiries (name, email, phone, subject, message, source, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, 'api', ?, ?)",
            [$name, $email ?: null, $phone ?: null, mb_substr((string) ($in['source_note'] ?? 'API: ' . $keyRow['name']), 0, 160),
             mb_substr((string) ($in['message'] ?? ''), 0, 3000) ?: 'Lead received via API', client_ip(), 'api-key:' . $keyRow['key_prefix']]);
        v1_finish(201, ['ok' => true, 'data' => ['id' => (int) db()->lastInsertId()]]);
    })(),
    default => v1_error(404, 'not_found', 'Unknown resource. Use courses, enquiries, orders or students.'),
};
