<?php
// Admin activity / audit trail: every recorded change and security event, filterable by person, action,
// area, severity and date, with expandable details and CSV export. Passwords, tokens and card data are
// never written to this log. Sign-in attempts live in security.php?tab=logins (older links redirect there).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

require_staff_page();
require_permission('logs.view');

// Older links: logs.php?tab=logins[&result=failed] → Security → Login history / Failed logins.
if (($_GET['tab'] ?? '') === 'logins') {
    $failed = ($_GET['result'] ?? '') === 'failed';
    redirect_to('security.php?' . http_build_query(['tab' => $failed ? 'failed' : 'logins'] + array_intersect_key($_GET, array_flip(['q', 'scope']))));
}

$q = trim((string) ($_GET['q'] ?? ''));
$sev = in_array($_GET['severity'] ?? '', ['info', 'warning', 'critical'], true) ? (string) $_GET['severity'] : '';
$userF = (string) ($_GET['user'] ?? '');
$actionF = preg_match('/^[a-z0-9_.-]{1,80}$/i', (string) ($_GET['action'] ?? '')) ? (string) $_GET['action'] : '';
$entityF = preg_match('/^[a-z0-9_.-]{1,60}$/i', (string) ($_GET['entity'] ?? '')) ? (string) $_GET['entity'] : '';
$validDate = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : '';
$from = $validDate((string) ($_GET['from'] ?? ''));
$to = $validDate((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];
if ($sev !== '') {
    $where[] = 'a.severity = ?';
    $params[] = $sev;
}
if ($userF === 'system') {
    $where[] = 'a.user_id IS NULL';
} elseif ($userF === 'staff') {
    $where[] = "u.user_type = 'staff'";
} elseif ($userF === 'students') {
    $where[] = "u.user_type = 'student'";
} elseif (ctype_digit($userF) && $userF !== '') {
    $where[] = 'a.user_id = ?';
    $params[] = (int) $userF;
}
if ($actionF !== '') {
    $where[] = 'a.action = ?';
    $params[] = $actionF;
}
if ($entityF !== '') {
    $where[] = 'a.entity_type = ?';
    $params[] = $entityF;
}
if ($from !== '') {
    $where[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR a.ip_address LIKE ?)';
    array_push($params, ...array_fill(0, 6, $like));
}
$w = implode(' AND ', $where);
$base = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . $w;

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    csv_download('audit-trail-' . date('Ymd') . '.csv', ['Time', 'Person', 'Email', 'Type', 'Action', 'Area', 'Item', 'Severity', 'IP address', 'Details'],
        array_map(fn($r) => [$r['created_at'], $r['name'] ?? 'System / visitor', $r['email'] ?? '', $r['user_type'] ?? '', $r['action'], $r['entity_type'] ?? '', $r['entity_id'] ?? '', $r['severity'], $r['ip_address'] ?? '', (string) $r['details']],
            db_all("SELECT a.*, u.name, u.email, u.user_type {$base} ORDER BY a.id DESC LIMIT 50000", $params)));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) db_value("SELECT COUNT(*) {$base}", $params);
$rows = db_all("SELECT a.*, u.name, u.email, u.user_type {$base} ORDER BY a.id DESC LIMIT 30 OFFSET " . (($page - 1) * 30), $params);
$staff = db_all("SELECT id, name FROM users WHERE user_type = 'staff' ORDER BY status = 'active' DESC, name");
$actions = array_column(db_all('SELECT action, COUNT(*) n FROM audit_logs GROUP BY action ORDER BY action LIMIT 300'), 'n', 'action');
$entities = array_column(db_all('SELECT entity_type FROM audit_logs WHERE entity_type IS NOT NULL GROUP BY entity_type ORDER BY entity_type LIMIT 200'), 'entity_type');
$kpi = db_one("SELECT SUM(created_at >= CURDATE()) today, SUM(created_at > NOW() - INTERVAL 7 DAY AND severity = 'critical') crit7,
    SUM(created_at > NOW() - INTERVAL 7 DAY AND severity = 'warning') warn7,
    COUNT(DISTINCT CASE WHEN created_at > NOW() - INTERVAL 7 DAY THEN user_id END) people7 FROM audit_logs");
$filtered = $q !== '' || $sev !== '' || $userF !== '' || $actionF !== '' || $entityF !== '' || $from !== '' || $to !== '';
$who = ctype_digit($userF) && $userF !== '' ? db_value('SELECT name FROM users WHERE id = ?', [(int) $userF]) : null;

$quick = ['Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], 'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]];
if (function_exists('admin_session')) {
    $s = admin_session();
    $quick['Session ' . $s['label']] = [$s['from'], $s['to']];
}
$sel = function (string $name, array $opts, string $cur, string $label): string {
    $h = '<select class="in" style="width:auto;max-width:220px" name="' . $name . '" aria-label="' . e($label) . '" data-autosubmit>';
    foreach ($opts as $k => $l) {
        $h .= '<option value="' . e((string) $k) . '"' . ($cur === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select>';
};
$userOpts = ['' => 'Everyone', 'staff' => 'All staff', 'students' => 'Students', 'system' => 'System / visitors'];
foreach ($staff as $st) {
    $userOpts[(string) $st['id']] = $st['name'];
}
// Readable labels (includes/admin-header.php): "whatsapp_opt_in" → "WhatsApp opt-in", "media_videos" → "Videos".
$nice = fn(string $a) => audit_action_label($a);

admin_start('Audit Trail', null, 'logs', ['head' => true,
    'subtitle' => $who ? 'Everything recorded for ' . $who . '.' : 'Every change made in the admin panel and every security event. Sensitive values (passwords, tokens, card data) are never recorded.',
    'actions' => can('reports.export') ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' Export CSV</a>' : '']);
?>
<section class="grid g-4 sc-mini">
  <a class="card stat lg-kpi" href="<?= e('?from=' . date('Y-m-d') . '&to=' . date('Y-m-d')) ?>"><span class="ico tone-blue"><?= icon('activity') ?></span><span><span class="label">Events today</span><span class="value" style="display:block"><?= number_format((int) $kpi['today']) ?></span></span></a>
  <a class="card stat lg-kpi" href="?severity=critical&amp;from=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>"><span class="ico tone-red"><?= icon('alert') ?></span><span><span class="label">Critical (7 days)</span><span class="value" style="display:block"><?= number_format((int) $kpi['crit7']) ?></span><span class="sub">roles, access, payments</span></span></a>
  <a class="card stat lg-kpi" href="?severity=warning&amp;from=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>"><span class="ico tone-amber"><?= icon('flag') ?></span><span><span class="label">Warnings (7 days)</span><span class="value" style="display:block"><?= number_format((int) $kpi['warn7']) ?></span></span></a>
  <div class="card stat"><span class="ico tone-purple"><?= icon('users') ?></span><span><span class="label">People active (7 days)</span><span class="value" style="display:block"><?= number_format((int) $kpi['people7']) ?></span></span></div>
</section>

<section class="card mt">
  <form class="toolbar sc-filters" method="get" aria-label="Filter the audit trail">
    <input class="in search-in" type="search" name="q" value="<?= e($q) ?>" placeholder="Action, person, item or IP…" aria-label="Search">
    <?= $sel('user', $userOpts, $userF, 'Person') ?>
    <?= $sel('action', ['' => 'All actions'] + array_combine(array_keys($actions), array_map(fn($a, $n) => $nice((string) $a) . ' (' . $n . ')', array_keys($actions), $actions)), $actionF, 'Action') ?>
    <?= $sel('entity', ['' => 'All areas'] + array_combine($entities, array_map(fn($x) => audit_entity_label((string) $x), $entities)), $entityF, 'Area') ?>
    <?= $sel('severity', ['' => 'All severities', 'info' => 'Info', 'warning' => 'Warning', 'critical' => 'Critical'], $sev, 'Severity') ?>
    <label class="sc-date"><span>From</span><input class="in" type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="sc-date"><span>To</span><input class="in" type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-light"><?= icon('filter') ?> Apply</button>
  </form>
  <div class="sc-quick" aria-label="Quick date ranges">
    <?php foreach ($quick as $label => [$a, $b]): $on = $from === $a && $to === $b; ?><a class="sc-pill<?= $on ? ' on' : '' ?>" href="<?= e(url_with(['from' => $a, 'to' => $b, 'page' => null])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
    <?php if ($filtered): ?><a class="sc-pill clear" href="logs.php"><?= icon('close') ?> Clear filters</a><?php endif; ?>
    <span class="muted lg-count"><?= number_format($total) ?> event<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <?php if (!$rows): ?>
    <?= empty_state($filtered ? 'No events match these filters' : 'Nothing recorded yet', $filtered ? 'Try a wider date range or clear the filters.' : 'Changes made in the admin panel appear here.', 'log') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack lg-table">
    <thead><tr><th>Time</th><th>Person</th><th>Action</th><th>Item</th><th>IP address</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $details = json_decode((string) $r['details'], true);
        $details = is_array($details) ? $details : [];
        $person = $r['name'] !== null
            ? ($r['user_type'] === 'staff' ? '<a href="?user=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a> <span class="badge b-purple">Staff</span>' : e($r['name']) . ' <span class="badge b-blue">Student</span>')
            : '<span class="muted">System / visitor</span>'; ?>
      <tr>
        <td data-label="Time" class="sa-when"><?= e(fmt_dt($r['created_at'], 'j M Y, g:i A')) ?><small><?= e(fmt_ago($r['created_at'])) ?></small></td>
        <td data-label="Person"><?= $person ?></td>
        <td data-label="Action"><a class="lg-action" href="<?= e(url_with(['action' => $r['action'], 'page' => null])) ?>"><?= e(audit_action_label((string) $r['action'], $r['entity_type'] ?? null)) ?></a>
          <?php if ($details): ?><details class="lg-details"><summary>Details</summary><dl><?php foreach ($details as $k => $v): ?><dt><?= e((string) $k) ?></dt><dd><?= e(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></dd><?php endforeach; ?></dl></details><?php endif; ?></td>
        <td data-label="Item"><?= $r['entity_type'] ? '<a href="' . e(url_with(['entity' => $r['entity_type'], 'page' => null])) . '">' . e(audit_entity_label($r['entity_type'], true)) . '</a>' . ($r['entity_id'] !== null && $r['entity_id'] !== '' ? ' <code>' . e($r['entity_id']) . '</code>' : '') : '<span class="muted">—</span>' ?></td>
        <td data-label="IP"><?= $r['ip_address'] ? '<code>' . e($r['ip_address']) . '</code>' : '<span class="muted">—</span>' ?></td>
        <td data-label="Severity"><?= $r['severity'] === 'critical' ? '<span class="badge b-red">Critical</span>' : ($r['severity'] === 'warning' ? '<span class="badge b-amber">Warning</span>' : '<span class="badge b-gray">Info</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php render_pager($total, $page, 30); ?>
  <?php endif; ?>
</section>
<?php admin_end();
