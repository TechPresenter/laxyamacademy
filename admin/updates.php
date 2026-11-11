<?php
// Database updates after a deploy: apply new database/migration-NNN.sql files with one click.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/migrations.php';

$me = require_staff_page();
require_permission('settings.manage');

if (admin_post() && ($_POST['_action'] ?? '') === 'apply') {
    if (!is_super_admin($me)) {
        flash('error', 'Only a Super Admin can apply database updates.');
        redirect_to('updates.php');
    }
    @set_time_limit(300);
    $done = [];
    foreach (pending_migrations() as $version => [$file, $desc]) {
        $r = apply_migration($version, $file, $desc);
        audit_log($r['ok'] ? 'database_migration_applied' : 'database_migration_failed', 'schema_migrations', $version,
            ['executed' => $r['executed'], 'skipped' => count($r['skipped']), 'error' => $r['error']], 'critical');
        if (!$r['ok']) {
            flash('error', "Update {$version} stopped. {$r['error']} Nothing after it was run. Fix the cause (or restore your backup) and try again - completed steps are skipped safely.");
            redirect_to('updates.php');
        }
        $done[] = "#{$version} ({$r['executed']} steps" . ($r['skipped'] ? ', ' . count($r['skipped']) . ' already present' : '') . ')';
    }
    foreach (obsolete_files() as $f) {
        if (@unlink(APP_ROOT . '/' . $f)) {
            $done[] = "removed old file {$f}";
            audit_log('obsolete_file_removed', 'deploy', $f, [], 'warning');
        }
    }
    cache_clear();
    flash('success', $done ? 'Database updated: ' . implode(', ', $done) . '.' : 'The database is already up to date.');
    redirect_to('updates.php');
}

$files = migration_files();
$applied = applied_migrations();
$pending = pending_migrations();
$obsolete = obsolete_files();
$waiting = count($pending) + ($obsolete ? 1 : 0);
admin_start('Database Updates', null, 'settings', ['head' => true, 'subtitle' => 'Schema version ' . ($applied ? max($applied) : 0) . ($waiting ? ' · ' . plural_n($waiting, 'update') . ' waiting' : ' · up to date')]);
if ($waiting): ?>
<div class="alert alert-warning">
  New code was deployed that needs <?= count($pending) ? e(plural_n(count($pending), 'database update')) : 'a clean-up' ?><?= $obsolete ? ' (and removal of old file' . (count($obsolete) > 1 ? 's' : '') . ': ' . e(implode(', ', $obsolete)) . ')' : '' ?>. <a href="backup.php">Download a backup</a> first, then apply.
  <?php if (is_super_admin($me)): ?>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="_action" value="apply"><button class="btn btn-primary" data-busy="Updating…" data-confirm="Apply <?= $waiting === 1 ? 'the waiting update' : 'the ' . $waiting . ' waiting updates' ?> now?"><?= icon('database') ?> Apply <?= e(plural_n($waiting, 'update')) ?></button></form>
  <?php else: ?>
    <br>Ask a Super Admin to apply them.
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Version</th><th>Update</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($files, true) as $v => [, $desc]): ?>
      <tr><td>#<?= (int) $v ?></td><td><?= e($desc) ?></td><td><?= in_array($v, $applied, true) ? '<span class="badge b-green">Applied</span>' : '<span class="badge b-amber">Waiting</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php admin_end();
