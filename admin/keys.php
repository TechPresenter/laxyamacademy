<?php
// Keys & credentials: integration API keys and passwords saved from the admin panel (encrypted, includes/app-config.php).
// Anyone with settings.manage sees which keys are set; only super admins can change them. Secrets are never shown in full.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('settings.manage');
$canEdit = is_super_admin($me);
$ready = app_config_table_ready();
$hasSsl = function_exists('openssl_encrypt');

// First visit after the deploy: when update 009 (this table) is the only pending database update,
// apply it here exactly as Settings → Database Updates would, so keys can be saved straight away.
if (!$ready && $canEdit) {
    require_once APP_ROOT . '/includes/migrations.php';
    $pending = pending_migrations();
    if (array_keys($pending) === [9]) {
        $r = apply_migration(9, $pending[9][0], $pending[9][1]);
        audit_log($r['ok'] ? 'database_migration_applied' : 'database_migration_failed', 'schema_migrations', 9,
            ['executed' => $r['executed'], 'skipped' => count($r['skipped']), 'error' => $r['error'], 'from' => 'keys.php'], 'critical');
        $ready = app_config_table_ready();
    }
}

$errors = [];
$openGroup = (string) ($_GET['group'] ?? '');

/** Validate one submitted value; returns an error message or null. */
$validate = function (string $key, string $v): ?string {
    [, , $type, $opts] = APP_CONFIG_FIELDS[$key];
    if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
        return 'Remove line breaks and special characters.';
    }
    if (strlen($v) > ($type === 'secret' ? 4096 : 500)) {
        return 'This is too long.';
    }
    return match (true) {
        $type === 'select' => array_key_exists($v, $opts) ? null : 'Choose one of the options.',
        $type === 'int' => preg_match('/^\d{1,5}$/', $v) && (int) $v >= 1 && (int) $v <= 65535 ? null : 'Enter a port number (1-65535).',
        $type === 'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.',
        str_ends_with($key, '_MODEL') => preg_match('/^[A-Za-z0-9][A-Za-z0-9.:_-]{1,100}$/', $v) ? null : 'Use the exact model name, e.g. gpt-6-luna.',
        $key === 'GOOGLE_CLIENT_ID' => preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $v) ? null : 'Paste the whole client ID, e.g. 1234567890-abc123.apps.googleusercontent.com',
        $key === 'OTP_WHATSAPP_TEMPLATE' => preg_match('/^[a-z0-9_]{1,512}$/', $v) ? null : 'Use the template name exactly as Interakt shows it (lowercase letters, numbers and _).',
        $key === 'OTP_WHATSAPP_LANGUAGE' => preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $v) ? null : 'A language code such as en or hi.',
        $type === 'secret', in_array($key, ['SMTP_HOST', 'S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_KEY', 'CASHFREE_APP_ID', 'RAZORPAY_KEY_ID', 'WHATSAPP_PHONE_ID', 'OPENAI_ORG_ID', 'MSG91_OTP_TEMPLATE_ID'], true)
            => preg_match('/\s/', $v) ? 'Spaces are not allowed - paste the value exactly.' : null,
        default => null,
    };
};

if (admin_post()) {
    $group = (string) ($_POST['group'] ?? '');
    if (!$canEdit) {
        flash('error', 'Only a super admin can change keys and credentials.');
        redirect_to('keys.php');
    }
    if (!$ready || !$hasSsl || !isset(APP_CONFIG_GROUPS[$group])) {
        flash('error', !$ready ? 'Run Database Updates first (Settings → Database Updates).' : 'The PHP OpenSSL extension is required.');
        redirect_to('keys.php');
    }
    $in = (array) ($_POST['f'] ?? []);
    $clear = (array) ($_POST['clear'] ?? []);
    $changes = [];
    $saved = app_config_rows();
    foreach (APP_CONFIG_FIELDS as $key => [$g, , $type]) {
        if ($g !== $group) {
            continue;
        }
        $v = trim((string) ($in[$key] ?? ''));
        $exists = isset($saved[$key]);
        $broken = $exists && $saved[$key]['value'] === null; // saved but unreadable: an empty field removes it
        $current = (string) app_config_value($key);
        if ($type === 'secret') {
            if ($v === '') {
                if (!empty($clear[$key]) && $exists) {
                    $changes[$key] = '';
                }
                continue; // empty secret field = keep the saved one
            }
        } elseif ($v === $current && !$broken) {
            continue;
        }
        if ($v !== '' && ($err = $validate($key, $v))) {
            $errors[$key] = $err;
            continue;
        }
        $changes[$key] = $v;
    }
    // The mail password is never sent to a different server automatically: changing where or as whom we log in needs it again.
    if ($group === 'email' && array_intersect(array_keys($changes), ['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER'])
        && (string) config('SMTP_PASS', '') !== '' && (string) ($changes['SMTP_PASS'] ?? '') === '') {
        $errors['SMTP_PASS'] = 'Enter the password again when you change the mail server, port, encryption or username.';
    }
    if (!$errors) {
        try {
            foreach ($changes as $key => $v) {
                app_config_save($key, $v, (int) $me['id']);
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect_to('keys.php?group=' . $group . '#' . $group);
        }
        if ($changes) {
            audit_log('config_keys_saved', 'integration', $group, [
                'saved' => array_keys(array_filter($changes, fn($v) => $v !== '')),
                'removed' => array_keys(array_filter($changes, fn($v) => $v === '')),
            ], 'warning');
        }
        flash('success', $changes ? APP_CONFIG_GROUPS[$group][0] . ': ' . count($changes) . ' change' . (count($changes) === 1 ? '' : 's') . ' saved.' : 'Nothing changed.');
        redirect_to('keys.php?group=' . $group . '#' . $group);
    }
    $openGroup = $group;
}

$rows = app_config_rows();
$src = [];
foreach (APP_CONFIG_FIELDS as $key => $f) {
    $src[$key] = app_config_source($key);
}

admin_start('Keys & Credentials', null, 'integrations', ['head' => true, 'subtitle' => 'API keys and passwords for the chatbot, payments, email, WhatsApp, spam protection and storage - set here, no file editing needed.']);
?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= icon('lock') ?><span><strong>Stored encrypted.</strong> Keys saved here are encrypted on the server, never shown in full again and never sent to the browser or the website code.
  A key saved here is used instead of the same line in the server's <code>.env</code> file. Leave a secret field empty to keep the saved key.
  <?= $canEdit ? '' : '<br><strong>Read-only:</strong> only a super admin can change these.' ?></span>
</div>
<?php if ($brokenKeys = app_config_broken()): ?>
  <div class="alert alert-error" style="margin-bottom:18px"><?= icon('alert') ?><span><strong><?= count($brokenKeys) ?> saved value<?= count($brokenKeys) === 1 ? '' : 's' ?> cannot be read</strong> (<?= e(implode(', ', $brokenKeys)) ?>), so <?= count($brokenKeys) === 1 ? 'it is' : 'they are' ?> treated as not set.
    The encryption key <code>uploads/private/.config-key</code> was lost or changed (or <code>CONFIG_KEY</code> in .env was changed). Restore that file from your backup, or enter the values again / remove them below.</span></div>
<?php endif; ?>
<?php if (!$ready): ?>
  <div class="alert alert-error" style="margin-bottom:18px"><?= icon('alert') ?><span>The database needs an update before keys can be saved here. <a href="updates.php"><strong>Open Database Updates</strong></a> and apply the pending update, then come back.</span></div>
<?php elseif (!$hasSsl): ?>
  <div class="alert alert-error" style="margin-bottom:18px"><?= icon('alert') ?><span>The PHP OpenSSL extension is missing on this server, so keys cannot be stored encrypted. Enable it in hPanel → Advanced → PHP Configuration.</span></div>
<?php endif; ?>

<?php foreach (APP_CONFIG_GROUPS as $g => [$title, $ico, $desc, $test]):
    $keys = array_keys(array_filter(APP_CONFIG_FIELDS, fn($f) => $f[0] === $g));
    $set = count(array_filter($keys, fn($k) => in_array($src[$k], ['admin', 'env'], true) && APP_CONFIG_FIELDS[$k][2] === 'secret'));
    $secrets = count(array_filter($keys, fn($k) => APP_CONFIG_FIELDS[$k][2] === 'secret'));
    $badge = $set ? '<span class="badge b-green">' . $set . ' of ' . $secrets . ' key' . ($secrets === 1 ? '' : 's') . ' set</span>' : '<span class="badge b-gray">Not set</span>';
    $live = $g === 'payments' && config('PAYMENT_MODE', 'sandbox') === 'live';
    $disabled = !$canEdit || !$ready || !$hasSsl ? ' disabled' : ''; ?>
<form method="post" class="card" id="<?= $g ?>" style="margin-bottom:18px;scroll-margin-top:86px" novalidate data-dirty-check autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="group" value="<?= $g ?>">
  <div class="card-head">
    <h2><?= icon($ico) ?> <?= e($title) ?></h2>
    <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= $live ? '<span class="badge b-amber">Live payments</span>' : '' ?><?= $badge ?><a class="btn btn-light btn-sm" href="<?= e($test) ?>"><?= icon('plug') ?> Test</a></span>
  </div>
  <div class="card-pad form">
    <p class="muted full" style="margin:0 0 4px"><?= e($desc) ?></p>
    <?php foreach ($keys as $k):
        [, $label, $type, $opts] = APP_CONFIG_FIELDS[$k];
        $err = $errors[$k] ?? null;
        $saved = $rows[$k]['value'] ?? '';
        $broken = isset($rows[$k]) && $rows[$k]['value'] === null;
        $chip = match (true) {
            $broken => '<span class="badge b-red">Saved, cannot be read - enter it again</span>',
            $src[$k] === 'admin' => '<span class="badge b-green">Saved here</span>',
            $src[$k] === 'env' => '<span class="badge b-blue">From .env file</span>',
            default => '<span class="badge b-gray">Not set</span>',
        };
        $envVal = $src[$k] === 'env' ? (string) (env_values()[$k] ?? getenv($k)) : '';
        $posted = $err ? (string) (($_POST['f'] ?? [])[$k] ?? '') : null; ?>
    <div class="field<?= $err ? ' invalid' : '' ?>">
      <label for="k-<?= $k ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= e($label) ?> <?= $chip ?></label>
      <?php if ($type === 'select'): ?>
        <select id="k-<?= $k ?>" name="f[<?= $k ?>]"<?= $disabled ?>>
          <?php foreach ($opts as $ov => $ol): ?>
            <option value="<?= e((string) $ov) ?>"<?= (string) ($posted ?? $saved) === (string) $ov ? ' selected' : '' ?>><?= e($ov === '' && $envVal !== '' ? 'Use .env (' . $envVal . ')' : $ol) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($type === 'secret'): ?>
        <input id="k-<?= $k ?>" type="text" name="f[<?= $k ?>]" value="" autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
               style="-webkit-text-security:disc;font-family:ui-monospace,monospace" placeholder="<?= e(in_array($src[$k], ['admin', 'env'], true) ? ($canEdit ? 'Saved: ' . app_config_mask((string) config($k, ''), $k) . ' - paste a new key to replace' : 'Saved (hidden)') : 'Paste the key') ?>"<?= $disabled ?>>
        <?php if ($src[$k] === 'admin' || $broken): ?>
          <label class="check" style="margin-top:6px;font-weight:400"><input type="checkbox" name="clear[<?= $k ?>]" value="1"<?= $disabled ?>> Remove the key saved here<?= (string) (env_values()[$k] ?? '') !== '' ? ' (the .env value will be used again)' : '' ?></label>
        <?php endif; ?>
      <?php else: ?>
        <input id="k-<?= $k ?>" type="<?= $type === 'int' ? 'number' : ($type === 'email' ? 'email' : 'text') ?>" name="f[<?= $k ?>]" value="<?= e($posted ?? $saved) ?>" autocomplete="off" spellcheck="false"
               <?= $type === 'int' ? 'min="1" max="65535" ' : '' ?>placeholder="<?= e($envVal !== '' ? 'Using .env: ' . $envVal : '') ?>"<?= $disabled ?>>
      <?php endif; ?>
      <?= $opts && $type !== 'select' ? '<div class="hint">' . e((string) $opts) . '</div>' : '' ?>
      <?= $err ? '<div class="err">' . e($err) . '</div>' : '' ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$disabled): ?>
    <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save <?= e($title) ?></button></div>
  <?php endif; ?>
</form>
<?php endforeach; ?>
<?php admin_end();
