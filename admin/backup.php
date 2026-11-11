<?php
// Backup & restore: one-click download of the whole database (Super Admins) and restore guidance.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('settings.manage');

const BACKUP_SKIP_DATA = ['rate_limit_hits']; // throttling counters only - structure is still included

/** PDO attribute for MySQL buffered queries (PHP 8.4+ Pdo\Mysql constant; the old PDO:: one is deprecated in 8.5). */
function backup_buffered_attr(): int
{
    return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
}

/** Stream the database as gzip-compressed SQL. Large tables are read unbuffered, 200 rows per INSERT. */
function stream_database_backup(array $me): never
{
    @set_time_limit(0);
    session_write_close();
    $pdo = db();
    $buffered = backup_buffered_attr();
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="laxyam-backup-' . date('Ymd-His') . '.sql.gz"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'wb');
    stream_filter_append($out, 'zlib.deflate', STREAM_FILTER_WRITE, ['level' => 6, 'window' => 31]); // 31 = gzip format
    fwrite($out, "-- " . setting('site_name', 'Laxyam Academy') . " database backup\n-- Created " . date('Y-m-d H:i:s T') . ' by ' . $me['name'] . "\n"
        . "-- Restore: phpMyAdmin → Import (this .gz file), or: gunzip -c file.sql.gz | mysql -u USER -p DATABASE\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '" . (new DateTime())->format('P') . "';\n");
    $rows = 0;
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
        fwrite($out, "\n-- Table {$t}\nDROP TABLE IF EXISTS {$q};\n{$create};\n");
        if (in_array($t, BACKUP_SKIP_DATA, true)) {
            continue;
        }
        $pdo->setAttribute($buffered, false);
        $st = $pdo->query("SELECT * FROM {$q}");
        $cols = null;
        $batch = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols ??= '(' . implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($r))) . ')';
            $batch[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
            $rows++;
            if (count($batch) === 200) {
                fwrite($out, "INSERT INTO {$q} {$cols} VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($out, "INSERT INTO {$q} {$cols} VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $st->closeCursor();
        $pdo->setAttribute($buffered, true);
    }
    fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of backup ({$rows} rows)\n");
    fclose($out);
    $record = json_encode(['at' => date('Y-m-d H:i:s'), 'by' => $me['name'], 'tables' => count($tables), 'rows' => $rows]);
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES ('backup_last_at', ?, 'system', 0)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$record]);
    audit_log('database_backup_downloaded', 'backup', null, ['tables' => count($tables), 'rows' => $rows], 'warning');
    exit;
}

if (admin_post() && ($_POST['_action'] ?? '') === 'download') {
    if (!is_super_admin($me)) {
        flash('error', 'Only a Super Admin can download a backup - it contains every student’s personal data.');
        redirect_to('backup.php');
    }
    stream_database_backup($me);
}

/** [files, bytes] under a folder (skipping the cache). */
function folder_usage(string $dir): array
{
    $n = $b = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && !str_contains(str_replace('\\', '/', $f->getPathname()), '/private/cache/')) {
                $n++;
                $b += $f->getSize();
            }
        }
    }
    return [$n, $b];
}

$last = json_decode_array(setting('backup_last_at'));
$dbSize = (int) db_value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
[$files, $bytes] = folder_usage(APP_ROOT . '/uploads');
$mb = fn(int $b) => number_format($b / 1048576, 1) . ' MB';
admin_start('Backup & Restore', null, 'settings', ['head' => true, 'subtitle' => 'Keep a recent copy of the database and uploaded files somewhere other than this server.']);
?>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h2><?= icon('database') ?> Database backup</h2><?= !empty($last['at']) && strtotime($last['at']) > time() - 7 * 86400 ? badge('active') : '<span class="badge b-amber">Due</span>' ?></div>
    <div class="card-pad">
      <dl class="kv">
        <dt>Last backup</dt><dd><?= !empty($last['at']) ? e(fmt_dt($last['at'])) . ' by ' . e((string) ($last['by'] ?? '')) . ' · ' . number_format((int) ($last['rows'] ?? 0)) . ' rows' : 'Never from the admin panel' ?></dd>
        <dt>Database size</dt><dd><?= $mb($dbSize) ?> (the download is compressed)</dd>
      </dl>
      <p class="muted" style="font-size:13px">The file contains students’ personal data and password hashes. Keep it private, for example in your own cloud drive. Never upload it to the website folder.</p>
      <?php if (is_super_admin($me)): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="download"><button class="btn btn-primary"><?= icon('download') ?> Download database backup (.sql.gz)</button></form>
      <?php else: ?>
        <p class="muted">Only a Super Admin can download backups.</p>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= icon('folder') ?> Uploaded files</h2></div>
    <div class="card-pad">
      <dl class="kv">
        <dt>Uploads folder</dt><dd><?= number_format($files) ?> files · <?= $mb($bytes) ?></dd>
      </dl>
      <p style="margin-top:0">Images, course PDFs/videos, resumes and student documents live in <code>uploads/</code>. Back them up with your hosting tools:</p>
      <ul style="line-height:1.8;padding-left:20px;margin:0">
        <li>Hostinger: hPanel → Files → Backups (automatic) or File Manager → right-click <code>uploads</code> → Compress → Download.</li>
        <li>Keep the server <code>.env</code> file safe too - it holds your keys.</li>
      </ul>
    </div>
  </div>
</div>
<div class="card mt">
  <div class="card-head"><h2><?= icon('refresh') ?> Restore</h2></div>
  <div class="card-pad">
    <ol style="line-height:1.9;padding-left:20px;margin:0">
      <li>Restoring replaces all current data, so download a fresh backup first.</li>
      <li>Hostinger: hPanel → Databases → phpMyAdmin → select the database → <strong>Import</strong> → choose the <code>.sql.gz</code> file → Go.</li>
      <li>Your own computer (XAMPP): phpMyAdmin → Import, or <code>gunzip -c backup.sql.gz | mysql -u root laxyam_academy</code>.</li>
      <li>Copy back the <code>uploads/</code> folder if files were lost, then open the site and sign in to check.</li>
    </ol>
  </div>
</div>
<?php admin_end();
