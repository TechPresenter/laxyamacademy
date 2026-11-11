<?php
// Demo content clean-up: review and remove the installer's sample data once real content exists.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/demo-content.php';

$me = require_staff_page();
require_permission('settings.manage');

if (admin_post()) {
    if (!is_super_admin($me)) {
        flash('error', 'Only a Super Admin can remove demo content.');
        redirect_to('demo-content.php');
    }
    $chosen = array_values(array_intersect((array) ($_POST['groups'] ?? []), array_keys(demo_groups())));
    if (!$chosen) {
        flash('error', 'Tick at least one group to remove.');
        redirect_to('demo-content.php');
    }
    $summary = remove_demo_content($chosen);
    cache_clear();
    audit_log('demo_content_removed', 'demo_content', null, $summary, 'warning');
    $parts = [];
    foreach ($summary as $g => $s) {
        $parts[] = demo_groups()[$g][0] . ': ' . $s['deleted'] . ' removed' . ($s['archived'] ? ', ' . $s['archived'] . ' archived' : '') . ($s['kept'] ? ', ' . $s['kept'] . ' kept' : '');
    }
    flash('success', implode(' · ', $parts));
    redirect_to('demo-content.php');
}

$groups = demo_groups();
$counts = demo_counts();
$total = array_sum($counts);
admin_start('Demo Content', null, 'settings', ['head' => true, 'subtitle' => 'Sample data from the installer. Remove it once your real courses, tests and posts are in place.']);
if (!$total) {
    echo '<div class="card"><div class="empty">' . icon('check') . ' No demo content or demo accounts left.</div></div>';
    admin_end();
    exit;
}
?>
<div class="alert alert-warning">Removal cannot be undone. <a href="backup.php">Download a database backup</a> first. Anything real students used (orders, enrolments, attempts, downloads, applications) is archived or kept, never deleted.</div>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="t">
    <thead><tr><th style="width:40px"></th><th>Group</th><th>Items</th><th>Examples</th></tr></thead>
    <tbody>
    <?php foreach ($groups as $key => [$label, $sql, $note]): if (!$counts[$key]) continue; ?>
      <tr>
        <td><input type="checkbox" name="groups[]" value="<?= e($key) ?>" id="g-<?= e($key) ?>" aria-label="Remove <?= e($label) ?>"></td>
        <td><label for="g-<?= e($key) ?>"><strong><?= e($label) ?></strong></label><?= $note ? '<br><span class="muted" style="font-size:12px">' . e($note) . '</span>' : '' ?></td>
        <td><?= (int) $counts[$key] ?></td>
        <td class="muted" style="font-size:13px"><?= e(implode(', ', array_column(db_all($sql . ' LIMIT 4'), 'label'))) ?><?= $counts[$key] > 4 ? ' …' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="form-actions">
    <?php if (is_super_admin($me)): ?>
      <button class="btn btn-danger" data-confirm="Permanently remove the ticked demo content? This cannot be undone."><?= icon('trash') ?> Remove selected</button>
    <?php else: ?>
      <span class="muted">Only a Super Admin can remove demo content.</span>
    <?php endif; ?>
  </div>
</form>
<?php admin_end();
