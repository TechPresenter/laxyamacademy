<?php
// Cloud storage for private uploads (S3-compatible). Credentials come from Keys & Credentials (keys.php) or .env.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/storage.php';

$me = require_staff_page();
require_permission('settings.manage');
$c = s3_config();

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'test' || $act === 'driver') {
        if (!$c || !function_exists('curl_init')) {
            flash('error', 'Enter the bucket details in Keys & Credentials (or .env) first (PHP cURL is also required).');
            redirect_to('storage.php');
        }
        // Round trip: upload, read back through a signed URL, delete.
        $tmp = tempnam(sys_get_temp_dir(), 'lxs');
        $probe = 'laxyam-test ' . bin2hex(random_bytes(8));
        file_put_contents($tmp, $probe);
        $key = 'healthcheck/' . date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.txt';
        $ok = false;
        $msg = '';
        try {
            s3_put($tmp, $key, 'text/plain');
            $ch = curl_init(s3_presign($key, 'test.txt', true, 60));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            $got = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch); // curl_close() is deprecated in PHP 8.5 (handles close when released)
            $ok = $code === 200 && $got === $probe;
            $msg = $ok ? '' : 'Upload worked but reading it back failed (HTTP ' . $code . '). Check the region and path-style setting.';
            storage_delete('s3:' . $key);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        @unlink($tmp);
        audit_log('storage_test', 'integration', 'cloud-storage', ['ok' => $ok]);
        if (!$ok) {
            flash('error', 'Connection test failed: ' . $msg);
            redirect_to('storage.php');
        }
        if ($act === 'test') {
            flash('success', 'Connection works: upload, signed download and delete all succeeded.');
            redirect_to('storage.php');
        }
    }
    if ($act === 'driver' || $act === 'local') {
        $driver = $act === 'driver' ? 's3' : 'local';
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES ('storage_driver', ?, 'integrations', 0, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$driver, $me['id']]);
        audit_log('storage_driver_changed', 'integration', 'cloud-storage', ['driver' => $driver], 'warning');
        flash('success', $driver === 's3' ? 'New private uploads now go to cloud storage. Existing files stay where they are and keep working.' : 'New private uploads are stored on this server again. Files already in the cloud keep working.');
        redirect_to('storage.php');
    }
}

$driver = setting('storage_driver', 'local');
$mask = fn(string $v) => strlen($v) > 6 ? substr($v, 0, 4) . str_repeat('•', 8) . substr($v, -2) : '••••';
$counts = [];
foreach (['learning_resources' => ['file_path'], 'lessons' => ['video_path', 'file_path'], 'student_documents' => ['file_path'], 'job_applications' => ['resume_path']] as $t => $cols) {
    foreach ($cols as $col) {
        try {
            $r = db_one("SELECT SUM({$col} LIKE 's3:%') AS cloud, SUM({$col} IS NOT NULL AND {$col} <> '' AND {$col} NOT LIKE 's3:%') AS local FROM {$t}");
            $counts['cloud'] = ($counts['cloud'] ?? 0) + (int) $r['cloud'];
            $counts['local'] = ($counts['local'] ?? 0) + (int) $r['local'];
        } catch (PDOException) {
        }
    }
}
admin_start('Cloud Storage', null, 'integrations', ['head' => true, 'subtitle' => 'Where private files (course videos, PDFs, resumes, student documents) are stored.']);
?>
<section class="grid g-3" style="margin-bottom:18px" aria-label="Storage summary">
  <div class="card stat"><span class="ico tone-blue"><?= icon($driver === 's3' && $c ? 'server' : 'database') ?></span><span><span class="label">New uploads go to</span><span class="value" style="display:block;font-size:20px"><?= $driver === 's3' && $c ? 'Cloud (S3)' : 'This server' ?></span></span></div>
  <div class="card stat"><span class="ico tone-green"><?= icon('folder') ?></span><span><span class="label">Files on this server</span><span class="value" style="display:block;font-size:20px"><?= number_format($counts['local'] ?? 0) ?></span></span></div>
  <div class="card stat"><span class="ico tone-purple"><?= icon('upload') ?></span><span><span class="label">Files in cloud storage</span><span class="value" style="display:block;font-size:20px"><?= number_format($counts['cloud'] ?? 0) ?></span></span></div>
</section>

<div class="card" style="margin-bottom:18px">
  <div class="card-head"><h2><?= icon('server') ?> S3-compatible bucket</h2><?= $c ? '<span class="badge b-green">Configured</span>' : '<span class="badge b-red">Not configured</span>' ?></div>
  <div class="card-pad">
    <?php if ($c): ?>
      <dl class="kv">
        <dt>Endpoint</dt><dd><?= e($c['endpoint']) ?></dd>
        <dt>Region</dt><dd><?= e($c['region']) ?></dd>
        <dt>Bucket</dt><dd><?= e($c['bucket']) ?> <span class="muted">(<?= $c['path_style'] ? 'path-style' : 'virtual-hosted' ?> URLs)</span></dd>
        <dt>Access key</dt><dd><code><?= e($mask($c['key'])) ?></code></dd>
        <dt>Secret key</dt><dd><code>••••••••••••</code> <span class="muted">never shown</span></dd>
      </dl>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        <?= action_form('test', [], icon('plug') . ' Test connection', 'btn btn-light') ?>
        <?php if ($driver !== 's3'): ?>
          <?= action_form('driver', [], icon('check') . ' Test &amp; use cloud storage', 'btn btn-primary', 'Store all new private uploads in this bucket? The connection is tested first.') ?>
        <?php else: ?>
          <?= action_form('local', [], 'Store new uploads on this server', 'btn btn-light', 'Switch new uploads back to this server? Files already in the cloud keep working.') ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p style="margin-top:0">Enter the bucket details in <a href="keys.php#storage"><strong>Keys &amp; Credentials → Cloud storage</strong></a> (stored encrypted), or add these lines to the server's <code>.env</code> file, then reload this page:</p>
      <pre style="background:var(--surface,#f5f9ff);padding:14px;border-radius:10px;overflow:auto">S3_ENDPOINT=https://s3.ap-south-1.amazonaws.com
S3_REGION=ap-south-1
S3_BUCKET=laxyam-private
S3_KEY=your-access-key-id
S3_SECRET=your-secret-access-key
S3_PATH_STYLE=1</pre>
      <p class="muted" style="margin-bottom:0">Works with AWS S3, Cloudflare R2 (region <code>auto</code>), Wasabi, DigitalOcean Spaces and MinIO. Keep the bucket private - the site gives students 5-minute download links after checking their access.</p>
    <?php endif; ?>
  </div>
</div>
<?php admin_end();
