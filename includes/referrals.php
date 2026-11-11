<?php
/**
 * Refer & Earn, built on coupons:
 *   - a student's referral code is a coupon (kind referral, owner_id = the student) giving a friend the
 *     configured discount on the friend's FIRST paid course - never on the owner's own purchases;
 *   - fulfil_order() calls referral_reward_for_order() after a paid order commits: the student who
 *     shared the code gets a single-use reward coupon (kind reward) that only they can use - once per
 *     order (unique key), and never for a ₹0 order;
 *   - a full refund of the friend's order withdraws the reward while it is still unused.
 * The price is always computed on the server (evaluate_coupon); referral_coupon_error() adds these rules.
 * Settings (admin/referrals.php): referral_enabled, referral_friend_type/value/max, referral_reward_value/days.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/invoice.php'; // inr()

const REFERRAL_DEFAULTS = [
    'referral_enabled' => '0', 'referral_friend_type' => 'percent', 'referral_friend_value' => '10', 'referral_friend_max' => '500',
    'referral_reward_value' => '200', 'referral_reward_days' => '90',
];

/** Database update 014 applied? */
function referrals_ready(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW TABLES LIKE 'referral_rewards'");
}

function referral_setting(string $key): string
{
    return (string) setting($key, REFERRAL_DEFAULTS[$key]);
}

function referral_enabled(): bool
{
    return referrals_ready() && referral_setting('referral_enabled') === '1';
}

/** "₹500" for whole rupees, "₹499.50" otherwise. */
function referral_inr(float $amount): string
{
    return '₹' . inr_format($amount, fmod($amount, 1.0) != 0.0);
}

/** The friend's offer in words, e.g. "10% off (up to ₹500)" or "₹300 off". */
function referral_offer_text(): string
{
    $value = (float) referral_setting('referral_friend_value');
    if (referral_setting('referral_friend_type') === 'fixed') {
        return referral_inr($value) . ' off';
    }
    $max = (float) referral_setting('referral_friend_max');
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% off' . ($max > 0 ? ' (up to ' . referral_inr($max) . ')' : '');
}

/** Coupon columns for the friend's discount from the current settings: [type, value, max]. */
function referral_discount_columns(): array
{
    $fixed = referral_setting('referral_friend_type') === 'fixed';
    $max = (float) referral_setting('referral_friend_max');
    return [$fixed ? 'fixed' : 'percent', (float) referral_setting('referral_friend_value'), !$fixed && $max > 0 ? $max : null];
}

/** A fresh unused coupon code: prefix + 4-6 random characters. */
function referral_new_code(string $prefix): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I
    for ($len = 4; ; $len = min(8, $len + 1)) {
        $code = $prefix;
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!db_value('SELECT 1 FROM coupons WHERE code = ?', [$code])) {
            return $code;
        }
    }
}

/** The student's own referral coupon, created the first time it is needed. */
function referral_coupon_for(array $user): array
{
    $c = db_one("SELECT * FROM coupons WHERE kind = 'referral' AND owner_id = ?", [$user['id']]);
    if ($c) {
        return $c;
    }
    $first = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', trim((string) $user['name']))[0]));
    [$type, $value, $max] = referral_discount_columns();
    db_query("INSERT INTO coupons (code, kind, owner_id, description, discount_type, discount_value, max_discount, min_order_amount, per_user_limit, applies_to, is_active)
              VALUES (?, 'referral', ?, ?, ?, ?, ?, 0, 1, 'all', 1)",
        [referral_new_code(substr($first !== '' ? $first : 'LX', 0, 8)), $user['id'], 'Referral code of ' . mb_substr((string) $user['name'], 0, 60), $type, $value, $max]);
    return db_one('SELECT * FROM coupons WHERE id = ?', [(int) db()->lastInsertId()]);
}

/** Keep every referral code in step with the friend's offer (pass the values just saved, or read the settings). */
function referral_sync_codes(?array $columns = null): int
{
    [$type, $value, $max] = $columns ?? referral_discount_columns();
    return db_query("UPDATE coupons SET discount_type = ?, discount_value = ?, max_discount = ? WHERE kind = 'referral'", [$type, $value, $max])->rowCount();
}

/**
 * Extra rules for referral / reward coupons, used by evaluate_coupon(). Returns an error or null.
 * Referral: program on, not the owner's own code, and the buyer has no earlier paid order.
 * Reward: only its owner may use it (anyone else is told the code is not valid).
 */
function referral_coupon_error(array $coupon, int $userId): ?string
{
    if ($coupon['kind'] === 'reward') {
        return (int) $coupon['owner_id'] === $userId ? null : 'This coupon code is not valid.';
    }
    if ($coupon['kind'] !== 'referral') {
        return null;
    }
    if (!referral_enabled()) {
        return 'Referral codes are not active right now.';
    }
    if ((int) $coupon['owner_id'] === $userId) {
        return 'This is your own referral code - share it with friends so they get the discount and you earn rewards.';
    }
    if (db_value("SELECT 1 FROM orders WHERE user_id = ? AND total > 0 AND status IN ('paid','partially_refunded','refunded') LIMIT 1", [$userId])) {
        return 'Referral codes are for a friend’s first course purchase.';
    }
    return null;
}

/**
 * After a paid order commits (fulfil_order): reward the student whose referral code the buyer used.
 * Once per order (unique key on referral_rewards.order_id), never for a free order or one's own code.
 */
function referral_reward_for_order(array $order, array $items = []): void
{
    if (!referrals_ready() || !$order['coupon_id'] || (float) $order['total'] <= 0) {
        return;
    }
    $c = db_one("SELECT * FROM coupons WHERE id = ? AND kind = 'referral'", [$order['coupon_id']]);
    $value = (float) referral_setting('referral_reward_value');
    if (!$c || !$c['owner_id'] || (int) $c['owner_id'] === (int) $order['user_id'] || $value <= 0) {
        return;
    }
    $referrer = (int) $c['owner_id'];
    $ends = time() + max(7, (int) referral_setting('referral_reward_days')) * 86400;
    $code = db_transaction(function () use ($order, $referrer, $value, $ends): ?string {
        if (db_query('INSERT IGNORE INTO referral_rewards (referrer_id, referred_id, order_id) VALUES (?, ?, ?)', [$referrer, $order['user_id'], $order['id']])->rowCount() !== 1) {
            return null; // already rewarded (a repeated webhook or payment confirmation)
        }
        $code = referral_new_code('RWD');
        db_query("INSERT INTO coupons (code, kind, owner_id, description, discount_type, discount_value, min_order_amount, usage_limit, per_user_limit, applies_to, is_active, ends_at)
                  VALUES (?, 'reward', ?, 'Referral reward', 'fixed', ?, 0, 1, 1, 'all', 1, ?)", [$code, $referrer, $value, date('Y-m-d H:i:s', $ends)]);
        db_query('UPDATE referral_rewards SET reward_coupon_id = ? WHERE order_id = ?', [(int) db()->lastInsertId(), $order['id']]);
        return $code;
    });
    if ($code === null) {
        return;
    }
    audit_log('referral_reward_issued', 'order', (string) $order['order_number'], ['referrer' => $referrer, 'code' => $code]);
    try {
        require_once APP_ROOT . '/includes/notify.php';
        send_template('referral_reward', $referrer, [
            'course' => implode(', ', array_column($items, 'title')) ?: 'a course', 'amount' => referral_inr($value), 'order' => $code,
            'when' => date('j M Y', $ends), 'link' => '/dashboard/refer', 'button_label' => 'See my rewards', 'related' => ['user', $referrer],
        ]);
    } catch (Throwable $e) {
        error_log('[laxyam] referral_reward notification failed: ' . $e->getMessage());
    }
}

/** A fully refunded order loses its referral reward while the reward coupon is unused. */
function referral_on_refund(int $orderId): void
{
    if (!referrals_ready()) {
        return;
    }
    $r = db_one("SELECT * FROM referral_rewards WHERE order_id = ? AND status = 'issued'", [$orderId]);
    if (!$r) {
        return;
    }
    if ($r['reward_coupon_id'] && (int) db_value('SELECT used_count FROM coupons WHERE id = ?', [$r['reward_coupon_id']]) > 0) {
        audit_log('referral_reward_kept', 'order', (string) $orderId, ['reason' => 'the reward was already used'], 'warning');
        return;
    }
    db_query("UPDATE referral_rewards SET status = 'revoked' WHERE id = ?", [$r['id']]);
    if ($r['reward_coupon_id']) {
        db_query('UPDATE coupons SET is_active = 0 WHERE id = ?', [$r['reward_coupon_id']]);
    }
    audit_log('referral_reward_revoked', 'order', (string) $orderId);
}

/** Everything the student's Refer & Earn page shows. */
function referral_overview(array $user): array
{
    $c = referral_coupon_for($user);
    $rows = db_all('SELECT r.status, r.created_at, c.code, c.discount_value, c.ends_at, c.used_count, c.is_active, u.name AS friend
                    FROM referral_rewards r LEFT JOIN coupons c ON c.id = r.reward_coupon_id JOIN users u ON u.id = r.referred_id
                    WHERE r.referrer_id = ? ORDER BY r.id DESC', [$user['id']]);
    $state = fn(array $r) => match (true) {
        $r['status'] === 'revoked' || !$r['is_active'] => 'withdrawn',
        (int) $r['used_count'] > 0 => 'used',
        $r['ends_at'] && strtotime($r['ends_at']) < time() => 'expired',
        default => 'available',
    };
    return [
        'code' => $c['code'],
        'link' => app_url('?ref=' . rawurlencode($c['code'])),
        'friend_offer' => referral_offer_text(),
        'reward_amount' => (float) referral_setting('referral_reward_value'),
        'reward_days' => max(7, (int) referral_setting('referral_reward_days')),
        'friends_joined' => (int) db_value("SELECT COUNT(*) FROM orders WHERE coupon_id = ? AND total > 0 AND status IN ('paid','partially_refunded')", [$c['id']]),
        'rewards' => array_map(fn($r) => [
            'friend' => explode(' ', trim((string) $r['friend']))[0], // first name only
            'code' => $r['code'], 'amount' => (float) $r['discount_value'], 'status' => $state($r),
            'expires_at' => iso_datetime($r['ends_at']), 'earned_at' => iso_datetime($r['created_at']),
        ], $rows),
    ];
}
