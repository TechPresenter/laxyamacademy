<?php
// Global admin search across students, courses, orders, enquiries and tests (results respect permissions).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$q = trim((string) ($_GET['q'] ?? ''));

// JSON suggestions for the header search box (same permission rules as the results page).
if (($_GET['format'] ?? '') === 'json') {
    require_staff_page();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (mb_strlen($q) < 2) {
        exit('[]');
    }
    $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
    $out = [];
    $add = function (string $group, array $items) use (&$out) {
        if ($items) {
            $out[] = ['group' => $group, 'items' => $items];
        }
    };
    if (can('users.view')) {
        $add('Students', array_map(fn($r) => ['title' => $r['name'], 'sub' => $r['email'], 'url' => admin_url('users.php?id=' . $r['id'])],
            db_all("SELECT id, name, email FROM users WHERE user_type = 'student' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 5", [$like, $like, $like])));
    }
    if (can('courses.view')) {
        $add('Courses', array_map(fn($r) => ['title' => $r['title'], 'sub' => ucfirst($r['status']), 'url' => admin_url('courses.php?action=edit&id=' . $r['id'])],
            db_all('SELECT id, title, status FROM courses WHERE title LIKE ? LIMIT 5', [$like])));
    }
    if (can('orders.view')) {
        $add('Orders', array_map(fn($r) => ['title' => $r['order_number'], 'sub' => $r['name'] . ' · ' . money($r['total']), 'url' => admin_url('orders.php?order=' . urlencode($r['order_number']))],
            db_all('SELECT o.order_number, o.total, u.name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_number LIKE ? OR u.email LIKE ? LIMIT 5', [$like, $like])));
    }
    if (can('orders.view')) {
        $add('Invoices', array_map(fn($r) => ['title' => $r['invoice_number'], 'sub' => $r['billing_name'] . ' · ' . money($r['total']), 'url' => admin_url('invoices.php?q=' . urlencode($r['invoice_number']))],
            db_all('SELECT invoice_number, billing_name, total FROM invoices WHERE invoice_number LIKE ? OR billing_email LIKE ? OR billing_name LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like, $like])));
    }
    if (can('enquiries.view')) {
        $add('Enquiries', array_map(fn($r) => ['title' => $r['name'], 'sub' => $r['phone'] ?? '', 'url' => admin_url('enquiries.php?action=edit&id=' . $r['id'])],
            db_all('SELECT id, name, phone FROM enquiries WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? LIMIT 5', [$like, $like, $like])));
    }
    if (can('cms.manage')) {
        $add('Job applications', array_map(fn($r) => ['title' => $r['name'], 'sub' => ucfirst((string) $r['status']) . ($r['subject'] ? ' · ' . $r['subject'] : ''), 'url' => admin_url('jobs.php?tab=applications&application=' . $r['id'])],
            db_all('SELECT id, name, status, subject FROM job_applications WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like, $like])));
    }
    if (can('exams.manage')) {
        $add('Mock tests', array_map(fn($r) => ['title' => $r['title'], 'sub' => ucfirst((string) $r['status']), 'url' => admin_url('mock-tests.php?action=edit&id=' . $r['id'])],
            db_all('SELECT id, title, status FROM exams WHERE title LIKE ? LIMIT 5', [$like])));
    }
    if (can('cms.manage')) {
        $add('Pages', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['section_key'], 'url' => admin_url('cms.php?tab=' . (str_starts_with($r['section_key'], 'page_') ? 'pages' : 'home') . '&section=' . urlencode($r['section_key']))],
            db_all('SELECT section_key, title FROM cms_sections WHERE title LIKE ? LIMIT 5', [$like])));
    }
    // Admin screens by name (from the sidebar menu)
    $screens = [];
    foreach (admin_menu() as [, $groupLabel, , $children]) {
        foreach ($children as [$label, $href, $perm]) {
            if (count($screens) < 5 && menu_can($perm ?? null) && stripos($label, $q) !== false) {
                $screens[] = ['title' => $label, 'sub' => $groupLabel, 'url' => menu_href($href)];
            }
        }
    }
    $add('Admin screens', $screens);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

admin_start('Search', 'dashboard.view', 'dashboard', ['head' => true]);
if (mb_strlen($q) < 2) {
    echo '<div class="card empty">Type at least 2 characters in the search box above.</div>';
    admin_end();
    exit;
}
$like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
$groups = [];
if (can('users.view')) {
    $groups['Students'] = array_map(fn($r) => ['users.php?id=' . $r['id'], $r['name'], $r['email'] . ' · ' . ($r['phone'] ?? '')],
        db_all("SELECT id, name, email, phone FROM users WHERE user_type = 'student' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 10", [$like, $like, $like]));
}
if (can('courses.view')) {
    $groups['Courses'] = array_map(fn($r) => ['courses.php?action=edit&id=' . $r['id'], $r['title'], ucfirst($r['status'])],
        db_all('SELECT id, title, status FROM courses WHERE title LIKE ? OR slug LIKE ? LIMIT 10', [$like, $like]));
}
if (can('orders.view')) {
    $groups['Orders'] = array_map(fn($r) => ['orders.php?order=' . urlencode($r['order_number']), $r['order_number'], $r['name'] . ' · ' . money($r['total']) . ' · ' . $r['status']],
        db_all('SELECT o.order_number, o.total, o.status, u.name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_number LIKE ? OR o.provider_order_id LIKE ? OR u.email LIKE ? LIMIT 10', [$like, $like, $like]));
}
if (can('enquiries.view')) {
    $groups['Enquiries'] = array_map(fn($r) => ['enquiries.php?action=edit&id=' . $r['id'], $r['name'], ($r['phone'] ?? '') . ' · ' . $r['status']],
        db_all('SELECT id, name, phone, status FROM enquiries WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? LIMIT 10', [$like, $like, $like]));
}
if (can('orders.view')) {
    $groups['Invoices'] = array_map(fn($r) => ['invoices.php?q=' . urlencode($r['invoice_number']), $r['invoice_number'], $r['billing_name'] . ' · ' . money($r['total']) . ' · ' . fmt_dt($r['issued_at'], 'j M Y')],
        db_all('SELECT invoice_number, billing_name, total, issued_at FROM invoices WHERE invoice_number LIKE ? OR billing_email LIKE ? OR billing_name LIKE ? ORDER BY id DESC LIMIT 10', [$like, $like, $like]));
}
if (can('cms.manage')) {
    $groups['Job applications'] = array_map(fn($r) => ['jobs.php?tab=applications&application=' . $r['id'], $r['name'], ucfirst((string) $r['status']) . ($r['subject'] ? ' · ' . $r['subject'] : '') . ' · ' . fmt_dt($r['created_at'], 'j M Y')],
        db_all('SELECT id, name, status, subject, created_at FROM job_applications WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 10', [$like, $like, $like]));
}
if (can('exams.manage')) {
    $groups['Mock tests'] = array_map(fn($r) => ['mock-tests.php?action=edit&id=' . $r['id'], $r['title'], ucfirst($r['status'])],
        db_all('SELECT id, title, status FROM exams WHERE title LIKE ? LIMIT 10', [$like]));
}
if (can('live.manage')) {
    $groups['Live classes'] = array_map(fn($r) => ['live-classes.php?' . ((int) $r['ended'] ? 'when=past&' : '') . 'action=edit&id=' . $r['id'], $r['title'], fmt_dt($r['starts_at'])],
        db_all('SELECT id, title, starts_at, DATE_ADD(starts_at, INTERVAL duration_minutes MINUTE) <= NOW() AS ended FROM live_classes WHERE title LIKE ? ORDER BY starts_at DESC LIMIT 10', [$like]));
}
$found = array_sum(array_map('count', $groups));
echo '<p class="muted">' . plural_n($found, 'result') . ' for “' . e($q) . '”</p><div class="grid g-2">';
foreach ($groups as $label => $items) {
    if (!$items) {
        continue;
    }
    echo '<div class="card"><div class="card-head"><h2>' . e($label) . '</h2></div><div class="table-wrap"><table class="t"><tbody>';
    foreach ($items as [$href, $title, $sub]) {
        echo '<tr><td><a href="' . e($href) . '"><strong>' . e($title) . '</strong></a><br><span class="muted">' . e($sub) . '</span></td></tr>';
    }
    echo '</tbody></table></div></div>';
}
echo '</div>';
if (!$found) {
    echo '<div class="card empty">Nothing found.</div>';
}
admin_end();
