<?php
// Security module, one screen per tab (all read from real data; nothing decorative):
//   ?tab=overview     security dashboard: checks, alerts, failed sign-ins chart
//   ?tab=logins       login history (filters + CSV)
//   ?tab=failed       failed sign-in attempts, current lockouts, most targeted accounts and IPs (CSV)
//   ?tab=sessions     open sessions and active devices (sign out one / all staff)
//   ?tab=password     password policy as enforced by the code (read-only)
//   ?tab=limits       login throttling + every rate limit found in the code, with live hit counts
//   ?tab=protections  CSRF, security headers, session cookies and upload security (read-only status)
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-security.php';

$me = require_staff_page();
require_permission('logs.view');

$tabs = [
    'overview' => ['label' => 'Security Dashboard', 'icon' => 'shield'],
    'logins' => ['label' => 'Login History', 'icon' => 'history'],
    'failed' => ['label' => 'Failed Logins', 'icon' => 'alert'],
    'sessions' => ['label' => 'Sessions & Devices', 'icon' => 'monitor'],
    'password' => ['label' => 'Password Policy', 'icon' => 'key'],
    'limits' => ['label' => 'Rate Limiting', 'icon' => 'gauge'],
    'protections' => ['label' => 'Protections', 'icon' => 'lock'],
];
$tab = current_tab($tabs);
$lifetime = (int) config('SESSION_LIFETIME', 120);
$currentHash = hash('sha256', session_id());

// ------------------------------------------------------------------ actions
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    $back = 'security.php?tab=' . (in_array($_POST['tab'] ?? '', array_keys($tabs), true) ? $_POST['tab'] : 'sessions');
    if ($act === 'revoke') {
        $s = db_one('SELECT s.id, s.session_hash, s.user_id, u.user_type, u.name FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.revoked_at IS NULL', [(int) ($_POST['id'] ?? 0)]);
        if (!$s) {
            flash('info', 'That session had already ended.');
        } elseif (hash_equals($s['session_hash'], $currentHash)) {
            flash('error', 'This is your current session. Use Sign out instead.');
        } else {
            require_permission($s['user_type'] === 'staff' ? 'roles.manage' : 'users.manage');
            db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?', [$s['id']]);
            audit_log('session_revoked', 'user_sessions', (int) $s['id'], ['user_id' => (int) $s['user_id']], 'warning');
            flash('success', $s['name'] . ' was signed out on that device.');
        }
    } elseif ($act === 'revoke_all_staff') {
        require_permission('roles.manage');
        $n = db_query("UPDATE user_sessions s JOIN users u ON u.id = s.user_id SET s.revoked_at = NOW() WHERE u.user_type = 'staff' AND s.revoked_at IS NULL AND s.session_hash <> ?", [$currentHash])->rowCount();
        audit_log('all_staff_sessions_revoked', 'user_sessions', null, ['sessions' => $n], 'critical');
        flash('success', "Signed out {$n} other staff session" . ($n === 1 ? '' : 's') . '. Your own session stays open.');
    } elseif ($act === 'release_limit') {
        require_permission('settings.manage');
        $bucket = (string) ($_POST['bucket'] ?? '');
        $limits = array_filter(sec_rate_limits(), fn($l) => $l['bucket'] === $bucket);
        if (!$limits) {
            flash('error', 'Unknown rate limit.');
        } else {
            $window = max(array_column($limits, 'window'));
            $n = db_query('DELETE FROM rate_limit_hits WHERE bucket = ? AND created_at > NOW() - INTERVAL ? SECOND', [$bucket, $window])->rowCount();
            audit_log('rate_limit_released', 'rate_limit', $bucket, ['hits_cleared' => $n], 'warning');
            flash('success', 'Counters for “' . (SEC_RATE_LIMIT_LABELS[$bucket] ?? $bucket) . "” reset ({$n} recent hits cleared). Anyone blocked by it can try again now.");
        }
        $back = 'security.php?tab=limits';
    } else {
        flash('error', 'Unknown action.');
    }
    redirect_to($back);
}

// ------------------------------------------------------------------ shared filters (logins / failed)
$q = trim((string) ($_GET['q'] ?? ''));
$validDate = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : '';
$from = $validDate((string) ($_GET['from'] ?? ''));
$to = $validDate((string) ($_GET['to'] ?? ''));
$scope = in_array($_GET['scope'] ?? '', ['student', 'admin'], true) ? (string) $_GET['scope'] : '';
$attemptWhere = function (bool $failedOnly) use ($q, $from, $to, $scope): array {
    $w = ['1=1'];
    $p = [];
    if ($failedOnly) {
        $w[] = 'l.success = 0';
    } elseif (in_array($_GET['result'] ?? '', ['failed', 'success'], true)) {
        $w[] = 'l.success = ?';
        $p[] = $_GET['result'] === 'success' ? 1 : 0;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $w[] = '(l.ip_address LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    if ($scope !== '') {
        $w[] = 'l.scope = ?';
        $p[] = $scope;
    }
    if ($from !== '') {
        $w[] = 'l.created_at >= ?';
        $p[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $w[] = 'l.created_at <= ?';
        $p[] = $to . ' 23:59:59';
    }
    return [implode(' AND ', $w), $p];
};
/** Filter bar shared by the login tabs: search, area, result, date range + quick ranges, CSV. */
$filterBar = function (string $tabKey, bool $withResult) use ($q, $from, $to, $scope): string {
    $sel = fn(string $name, array $opts, string $cur, string $label) => '<select class="in" style="width:auto" name="' . $name . '" aria-label="' . $label . '" data-autosubmit>'
        . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ($cur === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select>';
    $result = (string) ($_GET['result'] ?? '');
    $quick = ['Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], 'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]];
    if (function_exists('admin_session')) {
        $s = admin_session();
        $quick['Session ' . $s['label']] = [$s['from'], $s['to']];
    }
    $h = '<form class="toolbar sc-filters" method="get"><input type="hidden" name="tab" value="' . e($tabKey) . '">'
        . '<input class="in search-in" type="search" name="q" value="' . e($q) . '" placeholder="Name, email or IP address…" aria-label="Search">'
        . $sel('scope', ['' => 'Students & staff', 'admin' => 'Admin sign-ins', 'student' => 'Student sign-ins'], $scope, 'Area')
        . ($withResult ? $sel('result', ['' => 'All results', 'success' => 'Successful', 'failed' => 'Failed'], $result, 'Result') : '')
        . '<label class="sc-date"><span>From</span><input class="in" type="date" name="from" value="' . e($from) . '"></label>'
        . '<label class="sc-date"><span>To</span><input class="in" type="date" name="to" value="' . e($to) . '"></label>'
        . '<button class="btn btn-light">' . icon('filter') . ' Apply</button>'
        . (can('reports.export') ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' CSV</a>' : '')
        . '</form><div class="sc-quick" aria-label="Quick date ranges">';
    foreach ($quick as $label => [$a, $b]) {
        $on = $from === $a && $to === $b;
        $h .= '<a class="sc-pill' . ($on ? ' on' : '') . '" href="' . e(url_with(['from' => $a, 'to' => $b, 'page' => null])) . '"' . ($on ? ' aria-current="true"' : '') . '>' . e($label) . '</a>';
    }
    if ($from !== '' || $to !== '' || $q !== '' || $scope !== '' || $result !== '') {
        $h .= '<a class="sc-pill clear" href="?tab=' . e($tabKey) . '">' . icon('close') . ' Clear filters</a>';
    }
    return $h . '</div>';
};
$attemptRows = function (array $rows): string {
    if (!$rows) {
        return empty_state('No sign-in attempts match', 'Try a wider date range or clear the filters.', 'history');
    }
    $h = '<div class="table-wrap"><table class="t stack"><thead><tr><th>Time</th><th>Area</th><th>Account</th><th>Device</th><th>IP address</th><th>Result</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $acct = $r['email']
            ? ($r['user_type'] === 'staff' && can('roles.manage') ? '<a href="users.php?type=staff&amp;id=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a>' : ($r['user_type'] === 'student' && can('users.view') ? '<a href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a>' : e($r['name'])))
                . '<small class="sc-sub">' . e($r['email']) . '</small>'
            : '<span class="muted">Unknown email</span><small class="sc-sub">No account matched</small>';
        $h .= '<tr><td data-label="Time" class="sa-when">' . e(fmt_dt($r['created_at'], 'j M Y, g:i A')) . '<small>' . e(fmt_ago($r['created_at'])) . '</small></td>'
            . '<td data-label="Area">' . ($r['scope'] === 'admin' ? '<span class="badge b-purple">Admin</span>' : '<span class="badge b-blue">Student</span>') . '</td>'
            . '<td data-label="Account">' . $acct . '</td>'
            . '<td data-label="Device"><span class="sa-dev">' . icon(sec_device_icon($r['user_agent'])) . ' ' . e(sec_device_label($r['user_agent'])) . '</span></td>'
            . '<td data-label="IP"><code>' . e($r['ip_address']) . '</code></td>'
            . '<td data-label="Result">' . ($r['success'] ? '<span class="badge b-green">' . icon('check') . ' Success</span>' : '<span class="badge b-red">Failed</span>') . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
};
$attemptSelect = 'SELECT l.*, u.name, u.email, u.user_type FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id';

// CSV exports (before any output)
if (($_GET['export'] ?? '') === 'csv' && in_array($tab, ['logins', 'failed'], true)) {
    require_permission('reports.export');
    [$w, $p] = $attemptWhere($tab === 'failed');
    csv_download(($tab === 'failed' ? 'failed-logins-' : 'login-history-') . date('Ymd') . '.csv', ['Time', 'Area', 'Name', 'Email', 'Device', 'IP address', 'Result'],
        array_map(fn($r) => [$r['created_at'], $r['scope'], $r['name'] ?? '', $r['email'] ?? 'unknown', sec_device_label($r['user_agent']), $r['ip_address'], $r['success'] ? 'success' : 'failed'],
            db_all("{$attemptSelect} WHERE {$w} ORDER BY l.id DESC LIMIT 20000", $p)));
}

admin_start($tabs[$tab]['label'], null, 'security', ['head' => true, 'subtitle' => match ($tab) {
    'overview' => 'Live status of the protections built into the platform, with anything that needs attention.',
    'logins' => 'Every sign-in attempt to the website and the admin panel. Passwords are never recorded.',
    'failed' => 'Wrong passwords and unknown emails. Accounts and networks are locked out automatically after repeated failures.',
    'sessions' => 'Who is signed in right now, on which devices. Signing a session out takes effect on their next click.',
    'password' => 'The password rules the code enforces today.',
    'limits' => 'Automatic limits that stop password guessing, spam and abuse.',
    default => 'What the platform does to protect forms, sessions, uploads and pages.',
}]);
echo admin_tabs($tabs, $tab, ['label' => 'Security screens']);

// ================================================================== overview
if ($tab === 'overview') {
    $failed24 = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 1 DAY');
    $adminFailed24 = (int) db_value("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND scope = 'admin' AND created_at > NOW() - INTERVAL 1 DAY");
    $openSessions = (int) db_value('SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND last_seen_at > NOW() - INTERVAL 1 DAY');
    $staffSessions = (int) db_value("SELECT COUNT(*) FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE u.user_type = 'staff' AND s.revoked_at IS NULL AND s.last_seen_at > NOW() - INTERVAL 1 DAY");
    $staffTotal = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'");
    $staff2fa = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active' AND totp_enabled_at IS NOT NULL");
    $superAdmins = sec_active_super_admins();
    $critical7 = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE severity = 'critical' AND created_at > NOW() - INTERVAL 7 DAY");
    $noisyIps = db_all('SELECT ip_address, COUNT(*) n, MAX(created_at) last_at FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 1 DAY GROUP BY ip_address HAVING n >= 10 ORDER BY n DESC LIMIT 5');
    $lockedAccounts = db_all('SELECT u.id, u.name, u.email, u.user_type, COUNT(*) n FROM login_attempts l JOIN users u ON u.id = l.user_id WHERE l.success = 0 AND l.created_at > NOW() - INTERVAL 15 MINUTE GROUP BY u.id HAVING n >= 5 LIMIT 5');
    $critical = db_all("SELECT a.action, a.created_at, a.ip_address, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.severity = 'critical' AND a.created_at > NOW() - INTERVAL 7 DAY ORDER BY a.id DESC LIMIT 8");
    $no2fa = db_all("SELECT id, name FROM users WHERE user_type = 'staff' AND status = 'active' AND totp_enabled_at IS NULL ORDER BY name LIMIT 6");
    $ipRules = count(array_filter(preg_split('/[\s,]+/', (string) setting('admin_ip_allowlist', ''))));
    // Webhook signing of the ACTIVE gateway (Cashfree signs with the client secret, Razorpay with its webhook secret).
    require_once APP_ROOT . '/includes/payments.php';
    $gwKnown = in_array(payment_provider(), ['razorpay', 'cashfree'], true);
    $gw = payment_gateway_status($gwKnown ? payment_provider() : 'razorpay');
    $webhook = $gwKnown && $gw['webhook_ready'];
    $webhookMsg = !$gwKnown ? 'PAYMENT_PROVIDER=' . payment_provider() . ' in .env is not supported (use cashfree or razorpay) - paid checkout is off.'
        : ($gw['provider'] === 'cashfree'
            ? ($webhook ? 'Cashfree webhooks are verified with the client secret (CASHFREE_SECRET_KEY); stale timestamps are rejected.' : 'CASHFREE_APP_ID / CASHFREE_SECRET_KEY are not set in .env - Cashfree webhooks cannot be verified and are rejected.')
            : ($webhook ? 'Razorpay webhooks are verified with the HMAC secret.' : 'RAZORPAY_WEBHOOK_SECRET is not set in .env - Razorpay webhooks are rejected.'));
    $debugOff = !config('APP_DEBUG', false) || is_production();
    $lastBackup = json_decode_array(setting('backup_last_at'));
    $backupOk = !empty($lastBackup['at']) && strtotime((string) $lastBackup['at']) > time() - 7 * 86400;
    $checks = [
        ['HTTPS', is_https(), is_https() ? 'Connection is encrypted; session cookies are marked Secure.' : 'This connection is not HTTPS. Turn on SSL before going live (secure cookies depend on it).', null],
        ['Production mode', is_production(), is_production() ? 'APP_ENV=production.' : 'APP_ENV is not "production" - search indexing is blocked and errors may show details.', null],
        ['Error details hidden', $debugOff, $debugOff ? 'Visitors never see stack traces.' : 'APP_DEBUG is on - turn it off before launch.', null],
        ['Super Admin accounts', $superAdmins >= 1 && $superAdmins <= 3, $superAdmins . ' active. Keep this small (1-3).', can('roles.manage') ? ['users.php?type=staff&role=super_admin', 'Review'] : null],
        ['Staff two-step verification', $staffTotal > 0 && $staff2fa === $staffTotal, $staff2fa . ' of ' . $staffTotal . ' staff use an authenticator app' . (setting('admin_require_2fa', '0') === '1' ? ' (required for all).' : '.'), can('roles.manage') ? ['access.php#2fa', 'Require'] : null],
        ['Admin IP allowlist', $ipRules > 0 ? true : null, $ipRules ? plural_n((int) $ipRules, 'allowed address/range rule') . '.' : 'Optional - staff can sign in from any network.', can('roles.manage') ? ['access.php', 'Configure'] : null],
        ['Payment webhook signing', $webhook, $webhookMsg, can('payments.view') ? ['payments.php?tab=setup', 'Webhook setup'] : null],
        ['Recent database backup', $backupOk, !empty($lastBackup['at']) ? 'Last downloaded ' . fmt_dt((string) $lastBackup['at']) . '.' : 'No backup has been downloaded from the admin panel yet.', can('settings.manage') ? ['backup.php', 'Backup'] : null],
    ];
    $passed = count(array_filter($checks, fn($c) => $c[1] === true));
    $days = [];
    foreach (range(13, 0) as $i) {
        $days[date('Y-m-d', strtotime("-{$i} days"))] = 0;
    }
    foreach (db_all('SELECT DATE(created_at) d, COUNT(*) n FROM login_attempts WHERE success = 0 AND created_at >= ? GROUP BY DATE(created_at)', [array_key_first($days)]) as $r) {
        $days[$r['d']] = (int) $r['n'];
    }
    $chart = [];
    foreach ($days as $d => $n) {
        $chart[date('j M', strtotime($d))] = $n;
    }
    $score = (int) round($passed / count($checks) * 100);
    ?>
<section class="sc-hero card">
  <div class="sc-score" style="--p:<?= $score ?>" role="img" aria-label="<?= $passed ?> of <?= count($checks) ?> security checks passed"><span><?= $passed ?>/<?= count($checks) ?></span><small>checks</small></div>
  <div class="sc-hero-t">
    <h2><?= $passed === count($checks) ? 'All security checks pass' : (count($checks) - $passed) . ' item' . (count($checks) - $passed === 1 ? '' : 's') . ' to review' ?></h2>
    <p class="muted">Checked live from the server configuration and database each time this page opens.</p>
  </div>
  <div class="sc-hero-kpis">
    <a href="?tab=failed"><b><?= $failed24 ?></b><span>failed sign-ins (24h)</span><small><?= $adminFailed24 ?> on the admin login</small></a>
    <a href="?tab=sessions"><b><?= $openSessions ?></b><span>open sessions (24h)</span><small><?= $staffSessions ?> staff</small></a>
    <a href="logs.php?severity=critical"><b><?= $critical7 ?></b><span>critical events (7 days)</span><small>audit trail</small></a>
  </div>
</section>

<div class="split-13-1 mt">
  <section class="card" id="checks">
    <div class="card-head"><h2><?= icon('check-circle') ?> Security checks</h2><span class="badge <?= $passed === count($checks) ? 'b-green' : 'b-amber' ?>"><?= $passed ?> of <?= count($checks) ?> OK</span></div>
    <ul class="sc-checks">
      <?php foreach ($checks as [$name, $ok, $note, $link]): ?>
      <li>
        <span class="sc-state <?= $ok === null ? 'na' : ($ok ? 'ok' : 'bad') ?>" aria-hidden="true"><?= icon($ok === null ? 'info' : ($ok ? 'check' : 'alert')) ?></span>
        <span class="sc-check-t"><strong><?= e($name) ?></strong><small><?= e($note) ?></small></span>
        <?= $ok === null ? '<span class="badge b-gray">Optional</span>' : ($ok ? '<span class="badge b-green">OK</span>' : '<span class="badge b-amber">Action needed</span>') ?>
        <?= $link && $ok !== true ? '<a class="btn btn-light btn-sm" href="' . e($link[0]) . '">' . e($link[1]) . '</a>' : '' ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card" id="alerts">
    <div class="card-head"><h2><?= icon('bell') ?> Security alerts</h2><a href="logs.php?severity=critical">Audit trail</a></div>
    <div class="card-pad sc-alerts">
      <?php if (!$noisyIps && !$lockedAccounts && !$critical && !$no2fa): ?>
        <?= empty_state('No alerts', 'Nothing suspicious in the last 7 days.', 'check-circle') ?>
      <?php endif; ?>
      <?php foreach ($lockedAccounts as $la): ?>
        <div class="alert alert-error"><?= icon('lock') ?><span><strong><?= e($la['name']) ?></strong> is temporarily locked out after <?= (int) $la['n'] ?> wrong passwords in 15 minutes. It unlocks automatically.</span></div>
      <?php endforeach; ?>
      <?php foreach ($noisyIps as $ip): ?>
        <div class="alert alert-warning"><?= icon('alert') ?><span><strong><?= (int) $ip['n'] ?> failed sign-ins</strong> from <code><?= e($ip['ip_address']) ?></code> in 24 hours (last <?= e(fmt_ago($ip['last_at'])) ?>). It is throttled automatically; <?= can('roles.manage') ? 'restrict admin access with the <a href="access.php">IP allowlist</a> or ' : '' ?>block it at your host if it continues.</span></div>
      <?php endforeach; ?>
      <?php if ($no2fa && $staffTotal): ?>
        <div class="alert alert-info"><?= icon('shield') ?><span><strong><?= $staffTotal - $staff2fa ?> staff</strong> sign in without two-step verification: <?= e(implode(', ', array_column($no2fa, 'name'))) ?><?= $staffTotal - $staff2fa > count($no2fa) ? '…' : '' ?>.</span></div>
      <?php endif; ?>
      <?php if ($critical): ?>
        <h3 class="sc-h3">Critical changes (7 days)</h3>
        <ul class="sa-feed sc-feed">
          <?php foreach ($critical as $c): ?><li><span class="sa-dot bad" aria-hidden="true"></span><span><strong><?= e(ucfirst(str_replace('_', ' ', $c['action']))) ?></strong><small><?= e($c['name'] ?? 'System') ?> · <?= e(fmt_dt($c['created_at'], 'j M, g:i A')) ?> · <?= e((string) $c['ip_address']) ?></small></span></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card mt">
  <div class="card-head"><div><h2><?= icon('chart') ?> Failed sign-ins, last 14 days</h2><p class="muted">Students and staff combined. Spikes usually mean password guessing.</p></div><a class="btn btn-light btn-sm" href="?tab=failed">Details</a></div>
  <div class="card-pad"><?= svg_bar_chart($chart, 'Failed sign-ins per day', false, 900, 220) ?></div>
</section>
    <?php
    admin_end();
    exit;
}

// ================================================================== login history
if ($tab === 'logins') {
    [$w, $p] = $attemptWhere(false);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id WHERE {$w}", $p);
    $rows = db_all("{$attemptSelect} WHERE {$w} ORDER BY l.id DESC LIMIT 30 OFFSET " . (($page - 1) * 30), $p);
    $sum = db_one("SELECT COUNT(*) total, SUM(l.success = 1) ok, SUM(l.success = 0) bad, COUNT(DISTINCT l.ip_address) ips FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id WHERE {$w}", $p);
    echo '<section class="grid g-4 sc-mini">'
        . '<div class="card stat"><span class="ico tone-blue">' . icon('history') . '</span><span><span class="label">Attempts</span><span class="value" style="display:block">' . number_format((int) $sum['total']) . '</span></span></div>'
        . '<div class="card stat"><span class="ico tone-green">' . icon('check') . '</span><span><span class="label">Successful</span><span class="value" style="display:block">' . number_format((int) $sum['ok']) . '</span></span></div>'
        . '<div class="card stat"><span class="ico tone-red">' . icon('alert') . '</span><span><span class="label">Failed</span><span class="value" style="display:block">' . number_format((int) $sum['bad']) . '</span></span></div>'
        . '<div class="card stat"><span class="ico tone-purple">' . icon('globe') . '</span><span><span class="label">Different IPs</span><span class="value" style="display:block">' . number_format((int) $sum['ips']) . '</span></span></div></section>';
    echo '<section class="card mt">' . $filterBar('logins', true) . $attemptRows($rows);
    render_pager($total, $page, 30);
    echo '</section>';
    admin_end();
    exit;
}

// ================================================================== failed logins
if ($tab === 'failed') {
    [$w, $p] = $attemptWhere(true);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id WHERE {$w}", $p);
    $rows = db_all("{$attemptSelect} WHERE {$w} ORDER BY l.id DESC LIMIT 30 OFFSET " . (($page - 1) * 30), $p);
    $lockIps = db_all('SELECT ip_address, COUNT(*) n, MAX(created_at) last_at FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 15 MINUTE GROUP BY ip_address HAVING n >= 20 ORDER BY n DESC');
    $lockIds = db_all('SELECT l.scope, l.identifier_hash, MAX(l.user_id) user_id, COUNT(*) n, MAX(l.created_at) last_at, MAX(u.name) name, MAX(u.email) email FROM login_attempts l LEFT JOIN users u ON u.id = l.user_id
        WHERE l.success = 0 AND l.created_at > NOW() - INTERVAL 15 MINUTE GROUP BY l.scope, l.identifier_hash HAVING n >= 5 ORDER BY n DESC');
    $topIps = db_all('SELECT ip_address, COUNT(*) n FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 7 DAY GROUP BY ip_address ORDER BY n DESC LIMIT 6');
    $topAccts = db_all('SELECT u.id, u.name, u.email, u.user_type, COUNT(*) n FROM login_attempts l JOIN users u ON u.id = l.user_id WHERE l.success = 0 AND l.created_at > NOW() - INTERVAL 7 DAY GROUP BY u.id ORDER BY n DESC LIMIT 6');
    $unknown7 = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND user_id IS NULL AND created_at > NOW() - INTERVAL 7 DAY');
    $bar = function (array $list, string $labelKey, int $max): string {
        $h = '<ul class="sc-bars">';
        foreach ($list as $r) {
            $h .= '<li><span class="sc-bar-l">' . $r[$labelKey] . '</span><span class="sc-bar"><i style="width:' . max(4, round((int) $r['n'] / max(1, $max) * 100)) . '%"></i></span><b>' . (int) $r['n'] . '</b></li>';
        }
        return $h . '</ul>';
    };
    ?>
<div class="grid g-3">
  <section class="card">
    <div class="card-head"><h2><?= icon('lock') ?> Locked out right now</h2><span class="badge <?= $lockIps || $lockIds ? 'b-red' : 'b-green' ?>"><?= count($lockIps) + count($lockIds) ?></span></div>
    <div class="card-pad">
      <?php if (!$lockIps && !$lockIds): ?>
        <p class="muted" style="margin:0">Nobody is locked out. An account locks after 5 wrong passwords in 15 minutes, a network after 20.</p>
      <?php else: ?>
        <ul class="sa-feed sc-feed">
        <?php foreach ($lockIds as $l): ?><li><span class="sa-dot bad" aria-hidden="true"></span><span><strong><?= $l['email'] ? e($l['name']) : 'Unknown email' ?></strong><small><?= (int) $l['n'] ?> failures · <?= $l['scope'] === 'admin' ? 'admin' : 'student' ?> login · unlocks about <?= e(date('g:i A', strtotime($l['last_at']) + 900)) ?></small></span></li><?php endforeach; ?>
        <?php foreach ($lockIps as $l): ?><li><span class="sa-dot bad" aria-hidden="true"></span><span><strong>Network <code><?= e($l['ip_address']) ?></code></strong><small><?= (int) $l['n'] ?> failures · unlocks about <?= e(date('g:i A', strtotime($l['last_at']) + 900)) ?></small></span></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= icon('users') ?> Most targeted accounts (7 days)</h2></div>
    <div class="card-pad">
      <?= $topAccts ? $bar(array_map(fn($r) => ['n' => $r['n'], 'l' => e($r['name']) . ' <span class="badge ' . ($r['user_type'] === 'staff' ? 'b-purple' : 'b-blue') . '">' . ($r['user_type'] === 'staff' ? 'Staff' : 'Student') . '</span>'], $topAccts), 'l', (int) $topAccts[0]['n']) : '<p class="muted" style="margin:0">No failed sign-ins on real accounts.</p>' ?>
      <?= $unknown7 ? '<p class="muted sc-note">' . $unknown7 . ' more attempt' . ($unknown7 === 1 ? '' : 's') . ' used emails that have no account.</p>' : '' ?>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= icon('globe') ?> Top failing IPs (7 days)</h2></div>
    <div class="card-pad"><?= $topIps ? $bar(array_map(fn($r) => ['n' => $r['n'], 'l' => '<code>' . e($r['ip_address']) . '</code>'], $topIps), 'l', (int) $topIps[0]['n']) : '<p class="muted" style="margin:0">No failed sign-ins.</p>' ?></div>
  </section>
</div>
<section class="card mt">
  <div class="card-head"><h2><?= icon('alert') ?> Failed attempts</h2><span class="muted"><?= number_format($total) ?> matching</span></div>
  <?= $filterBar('failed', false) ?>
  <?= $attemptRows($rows) ?>
  <?php render_pager($total, $page, 30); ?>
</section>
    <?php
    admin_end();
    exit;
}

// ================================================================== sessions
if ($tab === 'sessions') {
    $who = in_array($_GET['who'] ?? '', ['staff', 'student'], true) ? (string) $_GET['who'] : '';
    $w = 's.revoked_at IS NULL AND s.last_seen_at > NOW() - INTERVAL 1 DAY' . ($who !== '' ? ' AND u.user_type = ?' : '');
    $p = $who !== '' ? [$who] : [];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE {$w}", $p);
    $rows = db_all("SELECT s.id, s.session_hash, s.ip_address, s.user_agent, s.created_at, s.last_seen_at, u.id AS uid, u.name, u.email, u.user_type
        FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE {$w} ORDER BY u.user_type = 'staff' DESC, s.last_seen_at DESC LIMIT 40 OFFSET " . (($page - 1) * 40), $p);
    $devices = [];
    foreach (db_all('SELECT s.user_agent, u.user_type FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.revoked_at IS NULL AND s.last_seen_at > NOW() - INTERVAL 1 DAY') as $d) {
        $k = sec_device_label($d['user_agent']);
        $devices[$k] = ($devices[$k] ?? 0) + 1;
    }
    arsort($devices);
    $counts = db_one("SELECT SUM(u.user_type = 'staff') staff, SUM(u.user_type = 'student') students, COUNT(DISTINCT s.user_id) people FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.revoked_at IS NULL AND s.last_seen_at > NOW() - INTERVAL 1 DAY");
    ?>
<div class="split-2-1">
  <section class="card">
    <div class="card-head">
      <div><h2><?= icon('monitor') ?> Open sessions</h2><p class="muted">Seen in the last 24 hours. Idle sessions also end on their own after <?= $lifetime ?> minutes.</p></div>
      <?= can('roles.manage') ? action_form('revoke_all_staff', ['tab' => 'sessions'], icon('logout') . ' Sign out all other staff', 'btn btn-danger btn-sm', 'Sign out every other staff session now? You stay signed in.') : '' ?>
    </div>
    <div class="toolbar sc-quick sc-quick-in" aria-label="Show">
      <?php foreach (['' => 'Everyone', 'staff' => 'Staff', 'student' => 'Students'] as $k => $l): ?><a class="sc-pill<?= $who === $k ? ' on' : '' ?>" href="<?= e(url_with(['who' => $k ?: null, 'page' => null])) ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
    <?php if (!$rows): ?><?= empty_state('No open sessions', 'Nobody matching this filter is signed in.', 'monitor') ?><?php else: ?>
    <div class="table-wrap"><table class="t stack"><thead><tr><th>Person</th><th>Device</th><th>IP address</th><th>Signed in</th><th>Last seen</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $s): $mine = hash_equals($s['session_hash'], $currentHash); $mayRevoke = !$mine && can($s['user_type'] === 'staff' ? 'roles.manage' : 'users.manage'); ?>
      <tr>
        <td data-label="Person"><strong><?= e($s['name']) ?></strong> <?= $s['user_type'] === 'staff' ? '<span class="badge b-purple">Staff</span>' : '<span class="badge b-blue">Student</span>' ?><?= $mine ? ' <span class="badge b-green">You, here</span>' : '' ?><small class="sc-sub"><?= e($s['email']) ?></small></td>
        <td data-label="Device"><span class="sa-dev"><?= icon(sec_device_icon($s['user_agent'])) ?> <?= e(sec_device_label($s['user_agent'])) ?></span></td>
        <td data-label="IP"><code><?= e((string) $s['ip_address']) ?></code></td>
        <td data-label="Signed in" class="sa-when"><?= e(fmt_dt($s['created_at'], 'j M, g:i A')) ?></td>
        <td data-label="Last seen" class="sa-when"><?= e(fmt_ago($s['last_seen_at'])) ?></td>
        <td class="actions" data-label=""><?= $mayRevoke ? action_form('revoke', ['id' => $s['id'], 'tab' => 'sessions'], 'Sign out', 'btn btn-light btn-sm', 'Sign ' . $s['name'] . ' out on this device?') : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php render_pager($total, $page, 40); ?>
    <?php endif; ?>
  </section>
  <aside class="stack-y">
    <section class="grid g-2 sc-mini">
      <div class="card stat"><span class="ico tone-purple"><?= icon('shield') ?></span><span><span class="label">Staff sessions</span><span class="value" style="display:block"><?= (int) $counts['staff'] ?></span></span></div>
      <div class="card stat"><span class="ico tone-blue"><?= icon('users') ?></span><span><span class="label">Student sessions</span><span class="value" style="display:block"><?= (int) $counts['students'] ?></span></span></div>
    </section>
    <section class="card">
      <div class="card-head"><h2><?= icon('phone') ?> Active devices</h2><span class="muted"><?= (int) $counts['people'] ?> people</span></div>
      <div class="card-pad">
        <?php if (!$devices): ?><p class="muted" style="margin:0">No devices signed in.</p><?php else: ?>
        <ul class="sc-bars"><?php $max = max($devices); foreach (array_slice($devices, 0, 8, true) as $label => $n): ?><li><span class="sc-bar-l"><?= e($label) ?></span><span class="sc-bar"><i style="width:<?= max(4, round($n / $max * 100)) ?>%"></i></span><b><?= $n ?></b></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </section>
    <section class="card card-pad sa-tips">
      <h2><?= icon('info') ?> Good to know</h2>
      <ul>
        <li>Changing a password, resetting two-step verification or deactivating an account signs that person out everywhere.</li>
        <li>Your own sessions: <a href="profile.php#sessions">My profile</a>.</li>
        <?php if (can('roles.manage')): ?><li>One staff member: open them in <a href="users.php?type=staff">Staff accounts</a>.</li><?php endif; ?>
      </ul>
    </section>
  </aside>
</div>
    <?php
    admin_end();
    exit;
}

// ================================================================== password policy (read-only: enforced in code)
if ($tab === 'password') {
    $resets30 = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action = 'password_reset_requested' AND created_at > NOW() - INTERVAL 30 DAY");
    $done30 = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('password_reset_completed', 'admin_password_reset_completed') AND created_at > NOW() - INTERVAL 30 DAY");
    $forced30 = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff_password_force_reset' AND created_at > NOW() - INTERVAL 30 DAY");
    $neverStaff = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active' AND last_login_at IS NULL");
    $staffResetPage = is_file(APP_ROOT . '/admin/reset-password.php');
    $rules = [
        ['Length', '8 to 128 characters for everyone; staff accounts created or reset by an admin need at least 10.', 'key'],
        ['Characters', 'At least one letter and one number.', 'type'],
        ['Not the email', 'A staff password set by an admin may not contain the email address.', 'mail'],
        ['Storage', 'Only a bcrypt hash is stored (password_hash). Hashes are upgraded automatically at sign-in when the algorithm improves.', 'database'],
        ['Reset links', 'Single-use, stored only as a SHA-256 hash. Student links expire in 60 minutes; staff invitations in 72 hours and admin-sent staff links in 24 hours.', 'link'],
        ['After a change', 'Every other session of that account is signed out and a “password changed” email is sent.', 'logout'],
        ['Wrong passwords', 'An account locks for 15 minutes after 5 failures; a network after 20.', 'lock'],
        ['Idle sessions', 'Signed out after ' . $lifetime . ' minutes without activity (SESSION_LIFETIME in .env).', 'clock'],
    ];
    ?>
<div class="alert alert-info"><?= icon('info') ?><span>These rules are part of the code (<code>password_policy_error()</code> in includes/auth-check.php and the staff screens), so they apply to registration, reset, change-password and staff accounts alike. They are shown here read-only.</span></div>
<div class="split-13-1">
  <section class="card">
    <div class="card-head"><h2><?= icon('key') ?> Enforced password rules</h2><span class="badge b-green">Active</span></div>
    <ul class="sc-checks">
      <?php foreach ($rules as [$t, $d, $ico]): ?><li><span class="sc-state ok" aria-hidden="true"><?= icon($ico) ?></span><span class="sc-check-t"><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span></li><?php endforeach; ?>
    </ul>
  </section>
  <aside class="stack-y">
    <section class="grid g-2 sc-mini">
      <div class="card stat"><span class="ico tone-blue"><?= icon('mail') ?></span><span><span class="label">Reset requests (30d)</span><span class="value" style="display:block"><?= $resets30 ?></span></span></div>
      <div class="card stat"><span class="ico tone-green"><?= icon('check') ?></span><span><span class="label">Resets completed (30d)</span><span class="value" style="display:block"><?= $done30 ?></span></span></div>
      <div class="card stat"><span class="ico tone-red"><?= icon('key') ?></span><span><span class="label">Forced staff resets (30d)</span><span class="value" style="display:block"><?= $forced30 ?></span></span></div>
      <div class="card stat"><span class="ico tone-amber"><?= icon('user') ?></span><span><span class="label">Staff never signed in</span><span class="value" style="display:block"><?= $neverStaff ?></span></span></div>
    </section>
    <section class="card card-pad sa-tips">
      <h2><?= icon('shield') ?> Staff password tools</h2>
      <ul>
        <li>Staff reset their own password from the admin sign-in page<?= is_file(APP_ROOT . '/admin/forgot-password.php') ? ' (<a href="forgot-password.php">Forgot password?</a>)' : '' ?> or <a href="profile.php#password">My profile</a>.</li>
        <?php if (can('roles.manage')): ?><li>Send a reset link or force a reset for anyone in <a href="users.php?type=staff">Staff accounts</a>.</li><?php endif; ?>
        <li>Staff reset links open <?= $staffResetPage ? 'the staff reset page' : 'the website’s reset page' ?>.</li>
        <li>Add a second factor with <a href="two-factor.php">two-step verification</a>.</li>
      </ul>
    </section>
  </aside>
</div>
    <?php
    admin_end();
    exit;
}

// ================================================================== rate limiting
if ($tab === 'limits') {
    $limits = sec_rate_limits();
    $hits = [];
    foreach (db_all('SELECT bucket, SUM(created_at > NOW() - INTERVAL 1 HOUR) h1, COUNT(*) h24 FROM rate_limit_hits WHERE created_at > NOW() - INTERVAL 1 DAY GROUP BY bucket') as $r) {
        $hits[$r['bucket']] = $r;
    }
    $blocked = function (array $l): int {
        return (int) db_value('SELECT COUNT(*) FROM (SELECT key_hash FROM rate_limit_hits WHERE bucket = ? AND created_at > NOW() - INTERVAL ? SECOND GROUP BY key_hash HAVING COUNT(*) >= ?) t', [$l['bucket'], $l['window'], $l['max']]);
    };
    $loginFail15 = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 15 MINUTE');
    ?>
<div class="grid g-3 sc-mini">
  <div class="card stat"><span class="ico tone-blue"><?= icon('gauge') ?></span><span><span class="label">Limits enforced</span><span class="value" style="display:block"><?= count($limits) + 2 ?></span><span class="sub">read from the code</span></span></div>
  <div class="card stat"><span class="ico tone-teal"><?= icon('activity') ?></span><span><span class="label">Counted requests (1h)</span><span class="value" style="display:block"><?= number_format(array_sum(array_column($hits, 'h1'))) ?></span></span></div>
  <div class="card stat"><span class="ico tone-red"><?= icon('alert') ?></span><span><span class="label">Failed sign-ins (15 min)</span><span class="value" style="display:block"><?= $loginFail15 ?></span></span></div>
</div>
<section class="card mt">
  <div class="card-head"><div><h2><?= icon('lock') ?> Sign-in throttling</h2><p class="muted">includes/auth-check.php - applies to the website and the admin sign-in.</p></div></div>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Rule</th><th>Limit</th><th>Counted per</th><th>When reached</th></tr></thead><tbody>
    <tr><td data-label="Rule"><strong>Wrong passwords for one account</strong></td><td data-label="Limit">5 per 15 min</td><td data-label="Per">email address</td><td data-label="Then">Sign-in refused until the window passes</td></tr>
    <tr><td data-label="Rule"><strong>Wrong passwords from one network</strong></td><td data-label="Limit">20 per 15 min</td><td data-label="Per">IP address</td><td data-label="Then">Sign-in refused until the window passes</td></tr>
  </tbody></table></div>
</section>
<section class="card mt">
  <div class="card-head"><div><h2><?= icon('gauge') ?> Request limits</h2><p class="muted">Every <code>rate_limit()</code> call found in the code, with live counts. “Blocked now” = visitors or accounts currently at their limit.</p></div></div>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Protects</th><th>Limit</th><th>Counted per</th><th>Requests 1h / 24h</th><th>Blocked now</th><th>Where</th><th></th></tr></thead><tbody>
  <?php foreach ($limits as $l): $hb = $hits[$l['bucket']] ?? ['h1' => 0, 'h24' => 0]; $b = $blocked($l); ?>
    <tr>
      <td data-label="Protects"><strong><?= e(SEC_RATE_LIMIT_LABELS[$l['bucket']] ?? ucfirst(str_replace(['-', '_'], ' ', $l['bucket']))) ?></strong><small class="sc-sub"><code><?= e($l['bucket']) ?></code></small></td>
      <td data-label="Limit"><?= e(sec_limit_text($l['max'], $l['window'])) ?></td>
      <td data-label="Per"><?= e($l['per']) ?></td>
      <td data-label="1h / 24h"><?= number_format((int) $hb['h1']) ?> / <?= number_format((int) $hb['h24']) ?></td>
      <td data-label="Blocked"><?= $b ? '<span class="badge b-red">' . $b . '</span>' : '<span class="badge b-gray">0</span>' ?></td>
      <td data-label="Where"><code class="sc-where"><?= e($l['where']) ?></code></td>
      <td class="actions" data-label=""><?= $b && can('settings.manage') && $l['bucket'] !== 'totp-used' ? action_form('release_limit', ['bucket' => $l['bucket'], 'tab' => 'limits'], 'Reset', 'btn btn-light btn-sm', 'Reset the counters for this limit so blocked visitors can try again now?') : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="card-pad muted sc-note">Old counters are removed automatically after a day. Limits are changed in code, so they cannot be loosened from the admin by mistake.</div>
</section>
    <?php
    admin_end();
    exit;
}

// ================================================================== protections (read-only status)
$htaccess = (string) @file_get_contents(APP_ROOT . '/.htaccess');
$headers = [];
if (preg_match_all('/^\s*Header\s+(?:always\s+)?set\s+([\w-]+)\s+"([^"]*)"/mi', $htaccess, $m, PREG_SET_ORDER)) {
    foreach ($m as $h) {
        if (strcasecmp($h[1], 'Cache-Control') !== 0) {
            $headers[$h[1]] = $h[2];
        }
    }
}
$hasPoweredOff = (bool) preg_match('/Header\s+unset\s+X-Powered-By/i', $htaccess);
$uploadsHt = (string) @file_get_contents(APP_ROOT . '/uploads/.htaccess');
$privateHt = (string) @file_get_contents(APP_ROOT . '/uploads/private/.htaccess');
$noScripts = str_contains($uploadsHt, 'Require all denied') && stripos($uploadsHt, 'php') !== false;
$privateDenied = str_contains($privateHt, 'Require all denied');
$wantHeaders = [
    'X-Content-Type-Options' => 'Stops browsers guessing file types (blocks some script tricks).',
    'X-Frame-Options' => 'Stops other sites showing this site inside a frame (clickjacking).',
    'Referrer-Policy' => 'Limits how much of a page address is shared with other sites.',
    'Permissions-Policy' => 'Turns off browser features the site does not use (camera, location).',
    'Content-Security-Policy' => 'Limits which scripts may run. Optional; analytics and payment scripts must be allowed if added.',
    'Strict-Transport-Security' => 'Tells browsers to always use HTTPS. Add it once SSL works on the live domain.',
];
$groups = [
    'Forms & requests (CSRF)' => [
        ['Admin forms', true, 'Every admin form carries a per-session token; a request without it is rejected (admin_post() → require_csrf()).'],
        ['Website API', true, 'Every POST/PUT/DELETE to /api needs the X-CSRF-Token header from auth.php?action=csrf.'],
        ['Same-site cookies', true, 'Session cookie is SameSite=Lax, so other sites cannot send signed-in requests.'],
        ['Spam on public forms', recaptcha_enabled() ? true : null, recaptcha_enabled() ? 'reCAPTCHA v3 plus a hidden honeypot field and rate limits.' : 'Hidden honeypot field and rate limits. reCAPTCHA is off (optional, Settings → Forms & Features).'],
    ],
    'Sessions' => [
        ['HttpOnly cookie', true, 'Scripts on the page cannot read the session cookie.'],
        ['Secure cookie', is_https(), is_https() ? 'Sent only over HTTPS.' : 'Marked Secure automatically once the site runs on HTTPS.'],
        ['Fixation protection', true, 'A new session id is issued at every sign-in; strict mode rejects made-up ids.'],
        ['Revocable sessions', true, 'Each sign-in is recorded and can be signed out from Sessions & Devices.'],
    ],
    'Uploads' => [
        ['Type checked by content', true, 'Files are identified from their bytes (finfo), not the name; images are re-encoded.'],
        ['No code in uploads', $noScripts, $noScripts ? 'uploads/.htaccess refuses PHP, scripts, HTML and SVG.' : 'uploads/.htaccess is missing or incomplete - scripts could run from uploads.'],
        ['Private files', $privateDenied, $privateDenied ? 'uploads/private is closed to direct access; paid files are served only after a permission check.' : 'uploads/private/.htaccess is missing - private files may be downloadable directly.'],
        ['Size limit', true, 'Uploads above ' . number_format(upload_limit_bytes() / 1048576) . ' MB are refused (Settings → File Upload Limits).'],
    ],
];
?>
<div class="split-13-1">
  <div class="stack-y">
    <?php foreach ($groups as $title => $items): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon($title === 'Uploads' ? 'upload' : ($title === 'Sessions' ? 'monitor' : 'shield')) ?> <?= e($title) ?></h2></div>
      <ul class="sc-checks">
        <?php foreach ($items as [$n, $ok, $d]): ?><li><span class="sc-state <?= $ok === null ? 'na' : ($ok ? 'ok' : 'bad') ?>" aria-hidden="true"><?= icon($ok === null ? 'info' : ($ok ? 'check' : 'alert')) ?></span><span class="sc-check-t"><strong><?= e($n) ?></strong><small><?= e($d) ?></small></span><?= $ok === null ? '<span class="badge b-gray">Optional</span>' : ($ok ? '<span class="badge b-green">On</span>' : '<span class="badge b-amber">Check</span>') ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>
  <aside class="stack-y">
    <section class="card">
      <div class="card-head"><div><h2><?= icon('code') ?> Security headers</h2><p class="muted">From the site’s .htaccess (sent with every page).</p></div></div>
      <ul class="sc-checks">
        <?php foreach ($wantHeaders as $hn => $why): $set = $headers[$hn] ?? null; $optional = in_array($hn, ['Content-Security-Policy', 'Strict-Transport-Security'], true); ?>
        <li><span class="sc-state <?= $set !== null ? 'ok' : ($optional ? 'na' : 'bad') ?>" aria-hidden="true"><?= icon($set !== null ? 'check' : ($optional ? 'info' : 'alert')) ?></span>
          <span class="sc-check-t"><strong><?= e($hn) ?></strong><small><?= $set !== null ? '<code>' . e($set) . '</code>' : e($why) ?></small></span>
          <?= $set !== null ? '<span class="badge b-green">Set</span>' : ($optional ? '<span class="badge b-gray">Not set</span>' : '<span class="badge b-amber">Missing</span>') ?></li>
        <?php endforeach; ?>
        <li><span class="sc-state <?= $hasPoweredOff ? 'ok' : 'na' ?>" aria-hidden="true"><?= icon($hasPoweredOff ? 'check' : 'info') ?></span><span class="sc-check-t"><strong>X-Powered-By removed</strong><small>Hides the PHP version from visitors.</small></span><?= $hasPoweredOff ? '<span class="badge b-green">Set</span>' : '<span class="badge b-gray">Not set</span>' ?></li>
        <li><span class="sc-state ok" aria-hidden="true"><?= icon('check') ?></span><span class="sc-check-t"><strong>Admin panel</strong><small>noindex, frame protection and same-origin referrer on every admin page.</small></span><span class="badge b-green">Set</span></li>
      </ul>
    </section>
    <section class="sa-links">
      <?php if (can('payments.view')): ?><a class="sa-link" href="payments.php?tab=setup"><span class="ico tone-blue"><?= icon('zap') ?></span><span><strong>Webhook security</strong><small>How payment gateway webhooks are signed and verified.</small></span><?= icon('right', 'i chev') ?></a><?php endif; ?>
      <a class="sa-link" href="api-keys.php?tab=logs"><span class="ico tone-blue"><?= icon('key') ?></span><span><strong>API access logs</strong><small>Who used the public API keys.</small></span><?= icon('right', 'i chev') ?></a>
      <?php if (can('settings.manage')): ?><a class="sa-link" href="backup.php"><span class="ico tone-blue"><?= icon('database') ?></span><span><strong>Backup status</strong><small>Download a database backup.</small></span><?= icon('right', 'i chev') ?></a><?php endif; ?>
    </section>
  </aside>
</div>
<?php admin_end();
