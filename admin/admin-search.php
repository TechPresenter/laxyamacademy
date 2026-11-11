<?php
/**
 * Top-bar global search (Ctrl+K): grouped JSON suggestions for staff.
 *   GET admin-search.php?q=<2+ chars>
 *   → [{group, icon, items: [{title, sub, url, badge}]}]
 * Each group is returned only to staff who may open that screen. Pressing Enter in the search box
 * opens the full results page (search.php).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$me = current_user();
if (!$me || $me['user_type'] !== 'staff') {
    http_response_code(401);
    exit('{"error":"Please sign in again."}');
}
require_once APP_ROOT . '/includes/admin-access.php';
if (admin_access_denied($me)) {
    http_response_code(403);
    exit('{"error":"Admin access is not allowed from here."}');
}
// Same mandate as require_staff_page(): no data until the required two-factor setup is done.
if (staff_2fa_setup_required($me)) {
    http_response_code(403);
    exit('{"error":"Set up two-factor authentication to continue.","code":"two_factor_required"}');
}
if (!rate_limit('admin-search', (string) $me['id'], 120, 60)) {
    http_response_code(429);
    exit('{"error":"Too many searches. Wait a moment."}');
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    exit('[]');
}
$q = mb_substr($q, 0, 100);
$like = '%' . addcslashes($q, '%_\\') . '%';
$digits = preg_replace('/\D/', '', $q);
$phoneLike = strlen($digits) >= 4 ? '%' . $digits . '%' : $like;

$out = [];
/** Add a group when it has rows. */
$add = function (string $group, string $icon, array $items) use (&$out): void {
    if ($items) {
        $out[] = ['group' => $group, 'icon' => $icon, 'items' => array_values($items)];
    }
};
/** Run a query; a table that is not migrated yet simply returns nothing. */
$rows = function (string $sql, array $params): array {
    try {
        return db_all($sql, $params);
    } catch (PDOException) {
        return [];
    }
};

if (can('users.view')) {
    $add('Students', 'users', array_map(fn($r) => [
        'title' => $r['name'], 'sub' => trim($r['email'] . ($r['phone'] ? ' · ' . $r['phone'] : '')),
        'url' => admin_url('users.php?id=' . (int) $r['id']), 'badge' => $r['status'] !== 'active' ? ucfirst($r['status']) : null,
    ], $rows("SELECT id, name, email, phone, status FROM users WHERE user_type = 'student' AND status <> 'deleted'
              AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) ORDER BY id DESC LIMIT 5", [$like, $like, $phoneLike])));
}
if (can('cms.manage')) {
    $add('Job applications', 'briefcase', array_map(fn($r) => [
        'title' => $r['name'], 'sub' => ($r['title'] ?: ($r['subject'] ? ucwords(str_replace('_', ' ', $r['subject'])) . ' faculty' : 'General application')) . ' · ' . $r['email'],
        'url' => admin_url('jobs.php?tab=applications&application=' . (int) $r['id']), 'badge' => ucfirst($r['status']),
    ], $rows('SELECT a.id, a.name, a.email, a.status, a.subject, o.title FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id
              WHERE a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? ORDER BY a.id DESC LIMIT 5', [$like, $like, $phoneLike])));
}
if (can('orders.view')) {
    $add('Fees & orders', 'cart', array_map(fn($r) => [
        'title' => $r['order_number'] . ' · ' . money($r['total']), 'sub' => $r['name'] . ' · ' . fmt_dt($r['created_at'], 'j M Y'),
        'url' => admin_url('orders.php?order=' . rawurlencode($r['order_number'])), 'badge' => ucwords(str_replace('_', ' ', $r['status'])),
    ], $rows('SELECT o.order_number, o.total, o.status, o.created_at, u.name FROM orders o JOIN users u ON u.id = o.user_id
              WHERE o.order_number LIKE ? OR o.provider_order_id LIKE ? OR u.name LIKE ? OR u.email LIKE ? ORDER BY o.id DESC LIMIT 5', [$like, $like, $like, $like])));
    $add('Invoices', 'invoice', array_map(fn($r) => [
        'title' => $r['invoice_number'] . ' · ' . money($r['total']), 'sub' => $r['billing_name'] . ' · ' . fmt_dt($r['issued_at'], 'j M Y'),
        'url' => admin_url('invoices.php?q=' . rawurlencode($r['invoice_number'])), 'badge' => $r['status'] === 'cancelled' ? 'Cancelled' : null,
    ], $rows('SELECT invoice_number, total, status, issued_at, billing_name FROM invoices
              WHERE invoice_number LIKE ? OR billing_name LIKE ? OR billing_email LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like, $like])));
}
if (can('enquiries.view')) {
    $add('Enquiries', 'inbox', array_map(fn($r) => [
        'title' => $r['name'], 'sub' => trim(($r['phone'] ?? '') . ' · ' . mb_strimwidth((string) ($r['subject'] ?: $r['message']), 0, 60, '…'), ' ·'),
        'url' => admin_url('enquiries.php?action=edit&id=' . (int) $r['id']), 'badge' => ucwords(str_replace('_', ' ', $r['status'])),
    ], $rows('SELECT id, name, phone, subject, message, status FROM enquiries WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 5',
        [$like, $like, $phoneLike])));
}
if (can('courses.view')) {
    $add('Courses', 'book', array_map(fn($r) => [
        'title' => $r['title'], 'sub' => $r['category'] ?? 'Course', 'url' => admin_url('courses.php?action=edit&id=' . (int) $r['id']), 'badge' => ucfirst($r['status']),
    ], $rows('SELECT c.id, c.title, c.status, cat.name AS category FROM courses c LEFT JOIN course_categories cat ON cat.id = c.category_id
              WHERE c.title LIKE ? OR c.slug LIKE ? ORDER BY c.id DESC LIMIT 5', [$like, $like])));
}
if (can('exams.manage')) {
    $add('Tests & quizzes', 'clipboard', array_map(fn($r) => [
        'title' => $r['title'], 'sub' => ($r['mode'] ?? 'exam') === 'practice' ? 'Practice quiz' : 'Mock test',
        'url' => admin_url('mock-tests.php?action=edit&id=' . (int) $r['id']), 'badge' => ucfirst($r['status']),
    ], $rows('SELECT id, title, status, mode FROM exams WHERE title LIKE ? OR slug LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like])));
}
if (can('roles.manage')) {
    $add('Staff', 'shield', array_map(fn($r) => [
        'title' => $r['name'], 'sub' => $r['email'], 'url' => admin_url('users.php?type=staff'), 'badge' => $r['status'] !== 'active' ? ucfirst($r['status']) : null,
    ], $rows("SELECT name, email, status FROM users WHERE user_type = 'staff' AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 3", [$like, $like])));
}
// Admin screens by name (module tab bar items this staff member can open)
$screens = [];
foreach (admin_menu() as [, $module, , $items]) {
    foreach ($items as $c) {
        if (count($screens) < 6 && $c[1] !== 'preview:' && menu_can($c[2] ?? null) && (stripos($c[0], $q) !== false || stripos($module, $q) !== false)) {
            $screens[$c[1]] ??= ['title' => $c[0], 'sub' => $module, 'url' => menu_href($c[1]), 'badge' => null];
        }
    }
}
$add('Admin screens', 'grid', $screens);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
