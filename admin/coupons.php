<?php
// Discount coupons - validated on the server at checkout (includes/payments.php).
// Students' referral codes and their reward coupons are managed under Referral Program (referrals.php).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

$hasKinds = (bool) db_value("SHOW COLUMNS FROM coupons LIKE 'kind'");

/** What a student sees at checkout right now: inactive, scheduled, expired, used up or active. */
function coupon_state_badge(array $r): string
{
    $now = time();
    return match (true) {
        !(int) $r['is_active'] => '<span class="badge b-gray">Inactive</span>',
        $r['ends_at'] && strtotime($r['ends_at']) <= $now => '<span class="badge b-purple">Expired</span>',
        $r['starts_at'] && strtotime($r['starts_at']) > $now => '<span class="badge b-blue">Starts ' . e(fmt_dt($r['starts_at'], 'j M')) . '</span>',
        $r['usage_limit'] && (int) $r['used_count'] >= (int) $r['usage_limit'] => '<span class="badge b-amber">Used up</span>',
        default => '<span class="badge b-green">Active</span>',
    };
}

crud([
    'table' => 'coupons',
    'title' => 'Coupons',
    'singular' => 'coupon',
    'permission' => 'coupons.manage',
    'active' => 'coupons',
    'subtitle' => 'Discount codes students enter at checkout. Every rule is checked again on the server when the order is created.'
        . ($hasKinds ? ' Referral codes and reward coupons are under Referral Program.' : ''),
    'select' => 't.*, (SELECT COUNT(*) FROM coupon_courses cc WHERE cc.coupon_id = t.id) AS course_count',
    'where' => $hasKinds ? "t.kind = 'standard'" : '1=1',
    'search' => ['t.code', 't.description'],
    'sortable' => ['code' => 't.code', 'used_count' => 't.used_count', 'ends_at' => 't.ends_at'],
    'filters' => ['is_active' => ['col' => 't.is_active', 'label' => 'Statuses', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
    'columns' => [
        'code' => ['Code', fn($r) => '<div><span class="d2-code">' . e($r['code']) . '</span>' . ($r['description'] ? '<span class="d2-sub" style="margin-top:4px">' . e($r['description']) . '</span>' : '') . '</div>'],
        'discount_value' => ['Discount', fn($r) => '<div><strong>' . ($r['discount_type'] === 'percent' ? e(rtrim(rtrim(number_format((float) $r['discount_value'], 2), '0'), '.')) . '% off' : e(money($r['discount_value'])) . ' off') . '</strong>'
            . ($r['discount_type'] === 'percent' && $r['max_discount'] ? '<span class="d2-sub">up to ' . e(money($r['max_discount'])) . '</span>' : '')
            . ((float) $r['min_order_amount'] > 0 ? '<span class="d2-sub">min. order ' . e(money($r['min_order_amount'])) . '</span>' : '') . '</div>'],
        'applies_to' => ['Applies to', fn($r) => $r['applies_to'] === 'selected'
            ? '<span class="badge b-blue">' . (int) $r['course_count'] . ' course' . ((int) $r['course_count'] === 1 ? '' : 's') . '</span>'
            : '<span class="muted">All courses</span>'],
        'used_count' => ['Used', fn($r) => '<div><strong>' . number_format((int) $r['used_count']) . '</strong>' . ($r['usage_limit'] ? ' <span class="muted">of ' . number_format((int) $r['usage_limit']) . '</span>' : ' <span class="muted">· no limit</span>')
            . '<span class="d2-sub">' . (int) $r['per_user_limit'] . ' per student</span></div>'],
        'ends_at' => ['Valid until', fn($r) => $r['ends_at'] ? '<span class="d2-nowrap">' . e(fmt_dt($r['ends_at'], 'j M Y')) . '</span>' : '<span class="muted">No expiry</span>'],
        'is_active' => ['Status', fn($r) => coupon_state_badge($r)],
    ],
    'can_delete' => fn($r) => (int) $r['used_count'] === 0,
    'before_save' => function (array $d, ?array $old) {
        if ($d['discount_type'] === 'percent' && $d['discount_value'] > 100) {
            throw new CrudError(['discount_value' => 'A percentage cannot exceed 100.']);
        }
        if ($d['starts_at'] && $d['ends_at'] && $d['ends_at'] <= $d['starts_at']) {
            throw new CrudError(['ends_at' => 'End must be after start.']);
        }
        // Blank "Minimum order" means no minimum (the column is NOT NULL).
        $d['min_order_amount'] ??= 0;
        // Record the creator once; editing a coupon must not overwrite it.
        if (!$old) {
            $d['created_by'] = current_user()['id'] ?? null;
        }
        return $d;
    },
    'after_save' => function (int $id) {
        db_query('DELETE FROM coupon_courses WHERE coupon_id = ?', [$id]);
        foreach (array_map('intval', (array) ($_POST['course_ids'] ?? [])) as $cid) {
            db_query('INSERT IGNORE INTO coupon_courses (coupon_id, course_id) VALUES (?, ?)', [$id, $cid]);
        }
    },
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true, 'max' => 40, 'unique' => true, 'transform' => 'upper', 'hint' => 'Letters and numbers, e.g. WELCOME10'],
        'description' => ['label' => 'Description', 'max' => 255],
        'discount_type' => ['label' => 'Discount type', 'type' => 'select', 'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount (₹)'], 'required' => true, 'default' => 'percent'],
        'discount_value' => ['label' => 'Discount value', 'type' => 'money', 'required' => true, 'min' => 0.01],
        'max_discount' => ['label' => 'Maximum discount (₹)', 'type' => 'money', 'min' => 0, 'show_if' => 'discount_type=percent', 'hint' => 'Caps a percentage discount. Leave empty for no cap.'],
        'min_order_amount' => ['label' => 'Minimum order (₹)', 'type' => 'money', 'min' => 0, 'default' => 0],
        'starts_at' => ['label' => 'Valid from', 'type' => 'datetime'],
        'ends_at' => ['label' => 'Valid until', 'type' => 'datetime'],
        'usage_limit' => ['label' => 'Total uses allowed (blank = unlimited)', 'type' => 'number', 'min' => 1],
        'per_user_limit' => ['label' => 'Uses per student', 'type' => 'number', 'min' => 1, 'default' => 1, 'required' => true],
        'applies_to' => ['label' => 'Applies to', 'type' => 'select', 'options' => ['all' => 'All courses', 'selected' => 'Selected courses only'], 'required' => true, 'default' => 'all'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
        'course_ids' => [
            'label' => 'Eligible courses', 'type' => 'checkboxes', 'virtual' => true, 'show_if' => 'applies_to=selected',
            'options' => fn() => options_from("SELECT id, title AS label FROM courses WHERE status <> 'archived' ORDER BY title"),
            'current' => fn($row) => array_column(db_all('SELECT course_id FROM coupon_courses WHERE coupon_id = ?', [$row['id']]), 'course_id'),
        ],
    ],
]);
