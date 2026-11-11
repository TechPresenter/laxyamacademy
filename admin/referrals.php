<?php
// Referral program (Refer & Earn): settings, friends who joined with a student's code and the reward
// coupons issued (includes/referrals.php). Rewards are issued automatically once the friend's payment is
// confirmed; a full refund withdraws a reward that has not been used yet.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/referrals.php';

$me = require_staff_page();
require_permission('coupons.manage');

if (!referrals_ready()) {
    admin_start('Referral Program', null, 'payments', ['head' => true]);
    echo '<div class="alert alert-warning">Apply the latest database update first (System → Database Updates).</div>';
    admin_end();
    exit;
}

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'settings') {
        $type = ($_POST['friend_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = round((float) ($_POST['friend_value'] ?? 0), 2);
        $max = round(max(0, (float) ($_POST['friend_max'] ?? 0)), 2);
        $reward = round((float) ($_POST['reward_value'] ?? 0), 2);
        $days = (int) ($_POST['reward_days'] ?? 90);
        $error = match (true) {
            $value <= 0 => 'Enter the friend’s discount.',
            $type === 'percent' && $value > 90 => 'Keep the friend’s discount at 90% or less.',
            $reward < 0 || $reward > 100000 => 'Enter a reward between ₹0 and ₹1,00,000 (₹0 = no reward).',
            $days < 7 || $days > 365 => 'Rewards should be valid for 7 to 365 days.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect_to('referrals.php');
        }
        $values = ['referral_enabled' => empty($_POST['enabled']) ? '0' : '1', 'referral_friend_type' => $type, 'referral_friend_value' => (string) $value,
            'referral_friend_max' => (string) $max, 'referral_reward_value' => (string) $reward, 'referral_reward_days' => (string) $days];
        foreach ($values as $k => $v) {
            db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'referrals', 0, ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$k, $v, $me['id']]);
        }
        $synced = referral_sync_codes([$type, $value, $type === 'percent' && $max > 0 ? $max : null]);
        audit_log('referral_settings_saved', 'settings', 0, $values);
        flash('success', 'Referral program ' . ($values['referral_enabled'] === '1' ? 'is on' : 'is off') . '. ' . ($synced ? plural_n($synced, 'student code') . ' updated to the new offer.' : ''));
    } elseif ($act === 'withdraw') {
        $r = db_one("SELECT r.*, c.used_count FROM referral_rewards r LEFT JOIN coupons c ON c.id = r.reward_coupon_id WHERE r.id = ? AND r.status = 'issued'", [(int) ($_POST['id'] ?? 0)]);
        if (!$r || (int) $r['used_count'] > 0) {
            flash('error', $r ? 'This reward was already used, so it cannot be withdrawn.' : 'Reward not found.');
        } else {
            db_query("UPDATE referral_rewards SET status = 'revoked' WHERE id = ?", [$r['id']]);
            if ($r['reward_coupon_id']) {
                db_query('UPDATE coupons SET is_active = 0 WHERE id = ?', [$r['reward_coupon_id']]);
            }
            audit_log('referral_reward_revoked', 'referral_rewards', (int) $r['id'], ['by' => 'staff'], 'warning');
            flash('success', 'Reward withdrawn.');
        }
    }
    redirect_to('referrals.php');
}

$on = referral_enabled();
$s = fn(string $k) => referral_setting($k);
$stats = db_one("SELECT
        (SELECT COUNT(*) FROM coupons WHERE kind = 'referral') AS codes,
        (SELECT COUNT(*) FROM orders o JOIN coupons c ON c.id = o.coupon_id AND c.kind = 'referral' WHERE o.status IN ('paid','partially_refunded') AND o.total > 0) AS friends,
        (SELECT COALESCE(SUM(o.total), 0) FROM orders o JOIN coupons c ON c.id = o.coupon_id AND c.kind = 'referral' WHERE o.status IN ('paid','partially_refunded') AND o.total > 0) AS revenue,
        (SELECT COUNT(*) FROM referral_rewards WHERE status = 'issued') AS rewards,
        (SELECT COUNT(*) FROM referral_rewards r JOIN coupons c ON c.id = r.reward_coupon_id WHERE c.used_count > 0) AS used");
$rows = db_all('SELECT r.*, a.name AS referrer, a.email AS referrer_email, b.name AS friend, o.order_number, o.total, o.status AS order_status,
                       c.code, c.discount_value, c.used_count, c.ends_at, c.is_active
                FROM referral_rewards r JOIN users a ON a.id = r.referrer_id JOIN users b ON b.id = r.referred_id JOIN orders o ON o.id = r.order_id
                LEFT JOIN coupons c ON c.id = r.reward_coupon_id ORDER BY r.id DESC LIMIT 100');
$state = fn(array $r) => match (true) {
    $r['status'] === 'revoked' || !$r['is_active'] => '<span class="badge b-gray">Withdrawn</span>',
    (int) $r['used_count'] > 0 => '<span class="badge b-green">Used</span>',
    $r['ends_at'] && strtotime($r['ends_at']) < time() => '<span class="badge b-purple">Expired</span>',
    default => '<span class="badge b-blue">Available</span>',
};

admin_start('Referral Program', null, 'payments', ['head' => true,
    'subtitle' => 'Students share their own code; a friend gets a discount on their first course, and the student earns a reward coupon once the friend’s payment is confirmed.']);
?>
<section class="grid g-4" style="margin-bottom:18px" aria-label="Referral figures">
  <?php foreach ([['share', 't-blue', 'Student codes', number_format((int) $stats['codes']), 'Created when a student opens Refer & Earn'],
      ['users', 't-green', 'Friends who bought', number_format((int) $stats['friends']), 'Paid orders with a referral code'],
      ['rupee', 't-amber', 'Sales from referrals', money($stats['revenue']), 'Total of those orders'],
      ['gift', 't-purple', 'Rewards issued / used', (int) $stats['rewards'] . ' / ' . (int) $stats['used'], 'Reward coupons for students who shared']] as [$ico, $tone, $label, $value, $sub]): ?>
  <div class="card stat"><span class="ico <?= $tone ?>"><?= icon($ico) ?></span><span><span class="label"><?= e($label) ?></span><span class="value"><?= e((string) $value) ?></span><span class="sub"><?= e($sub) ?></span></span></div>
  <?php endforeach; ?>
</section>
<div class="split-2-1" style="align-items:start">
  <section class="card">
    <div class="card-head"><h2><?= icon('gift') ?> Rewards issued</h2></div>
    <?php if ($rows): ?>
    <div class="table-wrap"><table class="t stack"><thead><tr><th>Shared by</th><th>Friend</th><th>Order</th><th>Reward</th><th>Status</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-label="Shared by"><div><?= e($r['referrer']) ?><span class="d2-sub"><?= e($r['referrer_email']) ?></span></div></td>
        <td data-label="Friend"><?= e($r['friend']) ?></td>
        <td data-label="Order"><div><a href="orders.php?order=<?= urlencode($r['order_number']) ?>"><?= e($r['order_number']) ?></a><span class="d2-sub"><?= e(money($r['total'])) ?> · <?= e(str_replace('_', ' ', $r['order_status'])) ?></span></div></td>
        <td data-label="Reward"><div><span class="d2-code"><?= e((string) $r['code']) ?></span><span class="d2-sub"><?= e(money($r['discount_value'])) ?> off<?= $r['ends_at'] ? ' · until ' . e(fmt_dt($r['ends_at'], 'j M Y')) : '' ?></span></div></td>
        <td data-label="Status"><?= $state($r) ?></td>
        <td class="actions"><?php if ($r['status'] === 'issued' && $r['is_active'] && !(int) $r['used_count']): ?><?= action_form('withdraw', ['id' => (int) $r['id']], 'Withdraw', 'btn btn-light btn-sm', 'Withdraw this reward? The student can no longer use the code ' . $r['code'] . '.') ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?>
    <div class="empty"><span class="empty-ic"><?= icon('gift') ?></span><strong>No rewards yet</strong><p>When a friend buys a course with a student’s referral code, the reward appears here.</p></div>
    <?php endif; ?>
  </section>
  <form method="post" class="card sticky-aside" data-dirty-check>
    <?= csrf_field() ?><input type="hidden" name="_action" value="settings">
    <div class="card-head"><h2><?= icon('settings') ?> Settings</h2><?= $on ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>' ?></div>
    <div class="card-pad form" style="grid-template-columns:1fr">
      <label class="check"><input type="checkbox" name="enabled" value="1"<?= $s('referral_enabled') === '1' ? ' checked' : '' ?>> <strong>Referral program is on</strong> (shows “Refer &amp; Earn” in student dashboards)</label>
      <div class="field"><label for="ft">Friend’s discount</label><select id="ft" name="friend_type"><option value="percent"<?= $s('referral_friend_type') !== 'fixed' ? ' selected' : '' ?>>Percentage</option><option value="fixed"<?= $s('referral_friend_type') === 'fixed' ? ' selected' : '' ?>>Fixed amount (₹)</option></select></div>
      <div class="field"><label for="fv">Discount value</label><input id="fv" type="number" name="friend_value" min="1" step="0.01" value="<?= e($s('referral_friend_value')) ?>" required><div class="hint">e.g. 10 (%) or 300 (₹). Only on the friend’s first paid course.</div></div>
      <div class="field"><label for="fm">Maximum discount (₹, percentage only)</label><input id="fm" type="number" name="friend_max" min="0" step="1" value="<?= e($s('referral_friend_max')) ?>"><div class="hint">0 = no cap.</div></div>
      <div class="field"><label for="rv">Reward for the student who shared (₹ off their next course)</label><input id="rv" type="number" name="reward_value" min="0" step="1" value="<?= e($s('referral_reward_value')) ?>" required><div class="hint">Issued as a one-time coupon only they can use. 0 = no reward.</div></div>
      <div class="field"><label for="rd">Reward valid for (days)</label><input id="rd" type="number" name="reward_days" min="7" max="365" value="<?= e($s('referral_reward_days')) ?>" required></div>
      <p class="muted" style="margin:0;font-size:12.5px">Rewards are issued only for paid orders (never for free ones), once per order. A full refund withdraws an unused reward.</p>
    </div>
    <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
  </form>
</div>
<?php
admin_end();
