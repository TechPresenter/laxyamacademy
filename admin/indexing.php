<?php
// Indexing status from Google Search Console (URL Inspection API, read-only).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/gsc.php';

$me = require_staff_page();
require_permission('seo.manage');

/** Public URLs worth inspecting, most important first. */
function indexable_urls(): array
{
    $urls = array_map(fn($p) => app_url($p), ['', 'courses', 'mock-tests', 'live-classes', 'resources', 'blog', 'about', 'contact', 'careers', 'jobs']);
    foreach (db_all("SELECT slug FROM courses WHERE status = 'published' ORDER BY is_featured DESC, id DESC") as $r) {
        $urls[] = app_url('courses/' . $r['slug']);
    }
    foreach (db_all("SELECT slug FROM blog_posts WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC") as $r) {
        $urls[] = app_url('blog/' . $r['slug']);
    }
    foreach (db_all("SELECT slug FROM pages WHERE status = 'published'") as $r) {
        $urls[] = app_url('p/' . $r['slug']);
    }
    return array_values(array_unique($urls));
}

$property = (string) setting('gsc_property', '');
if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'config') {
        $p = trim((string) ($_POST['gsc_property'] ?? ''));
        if ($p !== '' && !preg_match('#^(sc-domain:[a-z0-9.-]+\.[a-z]{2,}|https?://[^\s]+/)$#i', $p)) {
            flash('error', 'Property must be “sc-domain:example.com” or a URL-prefix property ending with “/”, exactly as in Search Console.');
            redirect_to('indexing.php');
        }
        // Ownership verification: the content value of Search Console's HTML-tag method (index.php prints the meta tag).
        $verify = trim((string) ($_POST['gsc_verification'] ?? ''));
        if (preg_match('/content="([^"]+)"/', $verify, $mm)) {
            $verify = $mm[1]; // the whole <meta> tag was pasted
        }
        if ($verify !== '' && !preg_match('/^[A-Za-z0-9_-]{20,80}$/', $verify)) {
            flash('error', 'The verification code should be the content="…" value of the google-site-verification meta tag.');
            redirect_to('indexing.php');
        }
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES ('gsc_property', ?, 'seo', 0, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$p, $me['id']]);
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES ('gsc_verification', ?, 'integrations', 0, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$verify, $me['id']]);
        if (!empty($_FILES['key']['name'])) {
            $f = $_FILES['key'];
            $json = uploaded_file_ok($f) && $f['size'] < 20_000 ? (string) file_get_contents($f['tmp_name']) : '';
            if ($err = gsc_validate_key($json)) {
                flash('error', $err);
                redirect_to('indexing.php');
            }
            if (!is_dir(dirname(GSC_KEY_FILE))) {
                mkdir(dirname(GSC_KEY_FILE), 0750, true);
            }
            file_put_contents(GSC_KEY_FILE, $json, LOCK_EX);
            @chmod(GSC_KEY_FILE, 0640);
            audit_log('gsc_key_uploaded', 'integration', 'search-console', ['client_email' => json_decode($json, true)['client_email']], 'warning');
        }
        audit_log('gsc_settings_saved', 'integration', 'search-console');
        flash('success', 'Search Console settings saved.');
        redirect_to('indexing.php');
    }
    if ($act === 'remove_key') {
        @unlink(GSC_KEY_FILE);
        foreach (glob(cache_dir() . '/gsc-token-*.php') ?: [] as $t) {
            @unlink($t);
        }
        audit_log('gsc_key_removed', 'integration', 'search-console', [], 'warning');
        flash('success', 'Service-account key removed.');
        redirect_to('indexing.php');
    }
    if ($act === 'check') {
        if (!gsc_credentials() || $property === '' || !function_exists('curl_init')) {
            flash('error', 'Add the property and service-account key first (PHP cURL is also required).');
            redirect_to('indexing.php');
        }
        @set_time_limit(180);
        $only = (string) ($_POST['url'] ?? '');
        $urls = $only !== '' && in_array($only, indexable_urls(), true) ? [$only] : [];
        if (!$urls) {
            // Never checked first, then the oldest checks. Google allows ~2,000 inspections a day.
            $checked = array_column(db_all('SELECT url, checked_at FROM indexing_status'), 'checked_at', 'url');
            $all = indexable_urls();
            usort($all, fn($a, $b) => strcmp($checked[$a] ?? '', $checked[$b] ?? ''));
            $urls = array_slice($all, 0, 20);
        }
        $errors = 0;
        foreach ($urls as $u) {
            $errors += gsc_inspect($u, $property)['error'] ? 1 : 0;
        }
        audit_log('gsc_inspected', 'integration', 'search-console', ['urls' => count($urls), 'errors' => $errors]);
        flash($errors ? 'error' : 'success', 'Inspected ' . count($urls) . ' URL' . (count($urls) === 1 ? '' : 's') . ($errors ? " - {$errors} returned an error (see the table)." : '.'));
        redirect_to('indexing.php');
    }
}

$cred = gsc_credentials();
$urls = indexable_urls();
$status = array_column(db_all('SELECT * FROM indexing_status'), null, 'url');
$counts = ['PASS' => 0, 'NEUTRAL' => 0, 'FAIL' => 0, 'unchecked' => 0, 'error' => 0];
foreach ($urls as $u) {
    $s = $status[$u] ?? null;
    $counts[!$s ? 'unchecked' : ($s['error'] ? 'error' : ($s['verdict'] ?: 'NEUTRAL'))] = ($counts[!$s ? 'unchecked' : ($s['error'] ? 'error' : ($s['verdict'] ?: 'NEUTRAL'))] ?? 0) + 1;
}
$ready = $cred && $property !== '';
$verification = (string) setting('gsc_verification', '');
admin_start('Google Search Console', null, 'seo', [
    'head' => true,
    'subtitle' => 'Ownership verification, sitemap and indexing status of ' . count($urls) . ' public URLs (URL Inspection API, read-only).',
    'actions' => $ready ? '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="_action" value="check"><button class="btn btn-primary" data-busy="Checking…">' . icon('search') . ' Check next 20 URLs</button></form>' : '',
]);
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head"><h2>Connection</h2><?= $ready ? badge('active') : '<span class="badge b-red">Not connected</span>' ?></div>
  <form method="post" enctype="multipart/form-data" class="card-pad form">
    <?= csrf_field() ?><input type="hidden" name="_action" value="config">
    <div class="field full">
      <label for="g-verify">Ownership verification code (HTML tag method)</label>
      <input id="g-verify" name="gsc_verification" value="<?= e($verification) ?>" placeholder="content value of &lt;meta name=&quot;google-site-verification&quot; …&gt;" autocomplete="off" spellcheck="false">
      <div class="hint"><?= $verification !== '' ? '<span class="badge b-green">On the website</span> The meta tag is printed on every public page. ' : '' ?>Search Console → Add property → URL prefix → HTML tag. Paste the code (or the whole tag), save, then click Verify in Search Console. A Domain property is verified by DNS instead. Sitemap to submit: <code><?= e(app_url('sitemap.xml')) ?></code></div>
    </div>
    <div class="field">
      <label for="g-prop">Search Console property</label>
      <input id="g-prop" name="gsc_property" value="<?= e($property) ?>" placeholder="sc-domain:laxyamacademy.com">
      <div class="hint">Exactly as shown in Search Console: a Domain property (sc-domain:…) or URL-prefix (https://…/).</div>
    </div>
    <div class="field">
      <label for="g-key">Service-account key (JSON)</label>
      <?php if ($cred): ?>
        <div style="margin-bottom:6px"><?= badge('active') ?> <code><?= e($cred['client_email']) ?></code> · private key stored securely (hidden)</div>
      <?php endif; ?>
      <input id="g-key" type="file" name="key" accept="application/json,.json">
      <div class="hint">Google Cloud → IAM → Service accounts → Keys → Add key (JSON). Then in Search Console → Settings → Users, add the service-account email with Restricted access.</div>
    </div>
    <div class="form-actions full" style="padding:0;border:0">
      <button class="btn btn-primary"><?= icon('check') ?> Save</button>
    </div>
  </form>
  <?php if ($cred && !config('GSC_SERVICE_ACCOUNT_FILE')): ?>
    <form method="post" class="card-pad" style="padding-top:0"><?= csrf_field() ?><input type="hidden" name="_action" value="remove_key"><button class="btn btn-light btn-sm" data-confirm="Remove the stored service-account key?"><?= icon('trash') ?> Remove key</button></form>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:18px">
  <?php foreach (['PASS' => ['Indexed', 'b-green'], 'NEUTRAL' => ['Not indexed', 'b-amber'], 'FAIL' => ['Problem', 'b-red'], 'error' => ['Check failed', 'b-red'], 'unchecked' => ['Not checked yet', 'b-gray']] as $k => [$l, $cls]): ?>
    <div class="card card-pad"><div class="muted" style="font-size:13px"><?= e($l) ?></div><div style="font-size:26px;font-weight:700"><?= (int) $counts[$k] ?></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head"><h2>URLs</h2></div>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>URL</th><th>Status</th><th>Coverage</th><th>Last crawl</th><th>Checked</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($urls as $u): $s = $status[$u] ?? null; ?>
    <tr>
      <td data-label="URL" style="word-break:break-all;max-width:380px"><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://[^/]+#', '', $u) ?: '/') ?></a></td>
      <td data-label="Status"><?= !$s ? '<span class="badge b-gray">Not checked</span>' : ($s['error'] ? '<span class="badge b-red">Error</span>' : match ($s['verdict']) {
          'PASS' => '<span class="badge b-green">Indexed</span>', 'FAIL' => '<span class="badge b-red">Problem</span>', default => '<span class="badge b-amber">Not indexed</span>' }) ?></td>
      <td data-label="Coverage"><?= e($s ? ($s['error'] ?: (string) $s['coverage']) : '') ?></td>
      <td data-label="Last crawl"><?= $s && $s['last_crawl'] ? e(fmt_dt($s['last_crawl'])) : '<span class="muted">-</span>' ?></td>
      <td data-label="Checked"><?= $s ? e(fmt_dt($s['checked_at'])) : '<span class="muted">-</span>' ?></td>
      <td class="actions"><?php if ($ready): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="check"><input type="hidden" name="url" value="<?= e($u) ?>"><button class="btn btn-light btn-sm">Check</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php admin_end();
