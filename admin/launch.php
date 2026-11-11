<?php
// Go-live checklist: live status of everything that must be right on the public site.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/launch.php';

$me = require_staff_page();
require_permission('settings.manage');

if (admin_post()) {
    $key = (string) ($_POST['key'] ?? '');
    if (array_key_exists($key, LAUNCH_MANUAL)) {
        $manual = json_decode_array(setting('launch_manual'));
        if (($_POST['_action'] ?? '') === 'done') {
            $manual[$key] = ['by' => $me['name'], 'at' => date('Y-m-d H:i:s')];
        } else {
            unset($manual[$key]);
        }
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES ('launch_manual', ?, 'system', 0, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [json_encode($manual), $me['id']]);
        audit_log('launch_step_' . (isset($manual[$key]) ? 'done' : 'reopened'), 'launch', $key);
    }
    redirect_to('launch.php');
}

$groups = launch_checks();
[$done, $total] = launch_progress($groups);
$pct = (int) round($done / max(1, $total) * 100);
$required = 0;
foreach ($groups as $items) {
    $required += count(array_filter($items, fn($i) => $i['state'] === 'fail'));
}
$state = [
    'ok' => ['ok', 'check', '<span class="badge b-green">Done</span>'],
    'warn' => ['bad', 'alert', '<span class="badge b-amber">To do</span>'],
    'fail' => ['fail', 'alert', '<span class="badge b-red">Required</span>'],
    'manual' => ['na', 'flag', '<span class="badge b-gray">Manual step</span>'],
];
$groupIcons = ['Security' => 'shield', 'Content' => 'file', 'Email & payments' => 'rupee', 'Operations' => 'server', 'Manual steps' => 'flag'];
admin_start('Go-Live Checklist', null, 'settings', ['head' => true, 'subtitle' => "{$done} of {$total} done · checks run live every time this page opens"]);
?>
<section class="card sc-hero lc-hero">
  <div class="sc-score" style="--p:<?= $pct ?>" role="img" aria-label="<?= $done ?> of <?= $total ?> launch steps done"><span><?= $pct ?>%</span><small>ready</small></div>
  <div class="sc-hero-t">
    <h2><?= $done === $total ? 'Everything is ready for launch' : ($total - $done) . ' step' . ($total - $done === 1 ? '' : 's') . ' left before the site is launch-ready' ?></h2>
    <p class="muted"><?= $required ? $required . ' of them ' . ($required === 1 ? 'is' : 'are') . ' required. ' : '' ?>Each check reads the real configuration, so fixing an item ticks it automatically.</p>
  </div>
  <div class="sc-hero-kpis lc-kpis">
    <span><b><?= $done ?></b><span>done</span></span>
    <span><b><?= $required ?></b><span>required</span></span>
    <span><b><?= $total - $done - $required ?></b><span>to do / manual</span></span>
  </div>
</section>

<div class="lc-groups mt">
<?php foreach ($groups as $group => $items): $ok = count(array_filter($items, fn($i) => $i['state'] === 'ok')); ?>
<section class="card">
  <div class="card-head"><h2><?= icon($groupIcons[$group] ?? 'check-circle') ?> <?= e($group) ?></h2><span class="badge <?= $ok === count($items) ? 'b-green' : 'b-gray' ?>"><?= $ok ?> / <?= count($items) ?></span></div>
  <ul class="sc-checks">
  <?php foreach ($items as $i): [$cls, $ico, $badge] = $state[$i['state']] ?? $state['warn']; ?>
    <li>
      <span class="sc-state <?= $cls ?>" aria-hidden="true"><?= icon($ico) ?></span>
      <span class="sc-check-t"><strong><?= e($i['title']) ?></strong><small><?= e($i['detail']) ?></small></span>
      <?= $badge ?>
      <?php if ($i['link'] && $i['state'] !== 'ok'): ?><a class="btn btn-light btn-sm" href="<?= e($i['link']) ?>"><?= e($i['action'] ?? 'Open') ?></a><?php endif; ?>
      <?php if (array_key_exists($i['key'], LAUNCH_MANUAL)): ?>
        <?= $i['state'] === 'ok'
            ? action_form('undo', ['key' => $i['key']], 'Mark not done', 'btn btn-light btn-sm')
            : action_form('done', ['key' => $i['key']], icon('check') . ' Mark done', 'btn btn-primary btn-sm') ?>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
</div>
<?php admin_end();
