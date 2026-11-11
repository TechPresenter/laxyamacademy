<?php
// API keys for api/v1.php (scoped, revocable; the full key is shown once) and the API access log.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
$tabs = ['keys' => 'API Keys', 'logs' => 'API Access Logs'];
$tab = current_tab($tabs);
require_permission($tab === 'logs' ? 'logs.view' : 'settings.manage');
$newKey = null;

if (admin_post()) {
    require_permission('settings.manage');
    $act = $_POST['_action'] ?? '';
    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $scopes = array_values(array_intersect((array) ($_POST['scopes'] ?? []), array_keys(API_SCOPES)));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !$scopes) {
            flash('error', 'Give the key a name and choose at least one permission.');
            redirect_to('api-keys.php');
        }
        $newKey = 'lxa_' . bin2hex(random_bytes(20));
        db_query('INSERT INTO api_keys (name, key_prefix, key_hash, scopes, created_by) VALUES (?, ?, ?, ?, ?)',
            [$name, substr($newKey, 0, 12), hash('sha256', $newKey), json_encode($scopes), $me['id']]);
        audit_log('api_key_created', 'api_keys', (int) db()->lastInsertId(), ['name' => $name, 'scopes' => $scopes], 'warning');
    } elseif ($act === 'revoke') {
        db_query('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [(int) $_POST['id']]);
        audit_log('api_key_revoked', 'api_keys', (int) $_POST['id'], [], 'warning');
        flash('success', 'Key revoked. Requests using it are now rejected.');
        redirect_to('api-keys.php');
    }
}

admin_start($tabs[$tab], null, 'integrations', ['head' => true, 'subtitle' => $tab === 'keys' ? 'Let other systems (CRM, Zapier, reports) read or send data through ' . app_url('api/v1.php') : 'Every call made with an API key, newest first.']);
echo admin_tabs($tabs, $tab);

if ($tab === 'logs') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = 50;
    $conds = [];
    if (isset($_GET['key'])) {
        $conds[] = 'l.api_key_id = ' . (int) $_GET['key'];
    }
    $errorsOnly = !empty($_GET['errors']);
    if ($errorsOnly) {
        $conds[] = 'l.status >= 400';
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $total = (int) db_value("SELECT COUNT(*) FROM api_access_logs l {$where}");
    $rows = db_all("SELECT l.*, k.name, k.key_prefix FROM api_access_logs l LEFT JOIN api_keys k ON k.id = l.api_key_id {$where} ORDER BY l.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per));
    $day = db_one('SELECT COUNT(*) n, SUM(status >= 400) bad, COUNT(DISTINCT api_key_id) k FROM api_access_logs WHERE created_at > NOW() - INTERVAL 1 DAY');
    echo '<div class="ig-mini ig-mini-row" style="margin-bottom:18px"><div class="card"><span>' . icon('activity') . ' Calls, last 24 h</span><strong>' . number_format((int) $day['n']) . '</strong></div>'
        . '<div class="card"><span>' . icon('alert') . ' Rejected / errors</span><strong class="' . ((int) $day['bad'] ? 'bad' : '') . '">' . number_format((int) $day['bad']) . '</strong></div>'
        . '<div class="card"><span>' . icon('key') . ' Keys used</span><strong>' . number_format((int) $day['k']) . '</strong></div></div>';
    echo '<div class="card"><div class="toolbar ig-filter" role="group" aria-label="Filter calls">'
        . '<a class="rp-chip' . (!$errorsOnly ? ' on' : '') . '" href="' . e(url_with(['errors' => null, 'page' => null])) . '">All calls</a>'
        . '<a class="rp-chip' . ($errorsOnly ? ' on' : '') . '" href="' . e(url_with(['errors' => 1, 'page' => null])) . '">' . icon('alert') . ' Errors only</a>'
        . (isset($_GET['key']) ? '<a class="rp-chip" href="' . e(url_with(['key' => null, 'page' => null])) . '">' . icon('close') . ' All keys</a>' : '') . '</div>';
    if (!$rows) {
        echo '<div class="empty"><span class="empty-ic">' . icon('log') . '</span><strong>' . ($errorsOnly ? 'No failed calls' : 'No API calls yet') . '</strong><p>Every request made with an API key (or with an invalid key) is listed here.</p></div>';
    } else {
        echo '<div class="table-wrap"><table class="t"><thead><tr><th>Time</th><th>Key</th><th>Request</th><th>Status</th><th>IP</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $st = (int) $r['status'];
            echo '<tr><td>' . e(fmt_dt($r['created_at'])) . '</td><td>' . ($r['name'] ? e($r['name']) . ' <code class="muted">' . e($r['key_prefix']) . '…</code>' : '<span class="muted">Invalid / no key</span>') . '</td>'
                . '<td><code>' . e($r['method']) . ' ' . e($r['endpoint']) . '</code></td><td><span class="badge ' . ($st < 300 ? 'b-green' : ($st < 500 && $st !== 401 && $st !== 403 ? 'b-amber' : 'b-red')) . '">' . $st . '</span></td><td>' . e((string) $r['ip_address']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        render_pager($total, $page, $per);
    }
    echo '</div>';
    admin_end();
    exit;
}

if ($newKey) {
    echo '<div class="card" style="margin-bottom:18px;border-color:#fde68a"><div class="card-head"><h2>Copy your new key now</h2></div><div class="card-pad">'
        . '<p style="margin-top:0">This is the only time the full key is shown. Store it in the other system\'s secret settings - never in a web page or app.</p>'
        . '<div style="display:flex;gap:8px;max-width:640px"><input readonly value="' . e($newKey) . '" style="font-family:monospace"><button type="button" class="btn btn-primary" data-copy="' . e($newKey) . '">' . icon('copy') . ' Copy</button></div>'
        . '<p class="muted" style="margin-bottom:0">Test: <code>curl -H "Authorization: Bearer ' . e(substr($newKey, 0, 12)) . '…" "' . e(app_url('api/v1.php?resource=courses')) . '"</code></p></div></div>';
}

$keys = db_all('SELECT k.*, u.name AS creator, (SELECT COUNT(*) FROM api_access_logs l WHERE l.api_key_id = k.id AND l.created_at > NOW() - INTERVAL 30 DAY) AS calls
    FROM api_keys k LEFT JOIN users u ON u.id = k.created_by ORDER BY k.revoked_at IS NOT NULL, k.id DESC');
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head"><h2>Keys</h2></div>
  <?php if (!$keys): ?><div class="empty"><span class="empty-ic"><?= icon('key') ?></span><strong>No API keys yet</strong><p>Create a key below when another system (CRM, Zapier, reporting) needs to read or send data.</p></div><?php else: ?>
  <div class="table-wrap"><table class="t"><thead><tr><th>Name</th><th>Key</th><th>Permissions</th><th>Calls (30 days)</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($keys as $k): ?>
    <tr>
      <td><strong><?= e($k['name']) ?></strong><br><span class="muted">by <?= e((string) $k['creator']) ?> · <?= e(fmt_dt($k['created_at'])) ?></span></td>
      <td><code><?= e($k['key_prefix']) ?>••••••••</code></td>
      <td><?= e(implode(', ', json_decode_array($k['scopes']))) ?></td>
      <td><a href="?tab=logs&key=<?= (int) $k['id'] ?>"><?= number_format((int) $k['calls']) ?></a></td>
      <td><?= $k['last_used_at'] ? e(fmt_dt($k['last_used_at'])) : '<span class="muted">Never</span>' ?></td>
      <td><?= $k['revoked_at'] ? '<span class="badge b-red">Revoked</span>' : badge('active') ?></td>
      <td class="actions"><?= $k['revoked_at'] ? '' : action_form('revoke', ['id' => $k['id']], 'Revoke', 'btn btn-danger btn-sm', 'Revoke “' . $k['name'] . '”? Systems using it will stop working immediately.') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<form method="post" class="card" novalidate>
  <?= csrf_field() ?><input type="hidden" name="_action" value="create">
  <div class="card-head"><h2>Create a key</h2></div>
  <div class="card-pad form">
    <div class="field"><label for="k-name">Name (where it will be used)</label><input id="k-name" name="name" maxlength="120" placeholder="Zoho CRM sync" required></div>
    <div class="field full"><label>Permissions</label>
      <?php foreach (API_SCOPES as $s => $l): ?>
        <label class="check"><input type="checkbox" name="scopes[]" value="<?= e($s) ?>"> <strong><?= e($s) ?></strong> - <?= e($l) ?></label>
      <?php endforeach; ?>
      <div class="hint">Give each system only what it needs. Limit: 600 requests per hour per key.</div>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary"><?= icon('key') ?> Create key</button></div>
</form>

<div class="card" style="margin-top:18px">
  <div class="card-head"><h2>Endpoints</h2></div>
  <div class="card-pad">
    <dl class="kv">
      <dt><code>GET ?resource=courses</code></dt><dd>Published courses (courses.read)</dd>
      <dt><code>GET ?resource=enquiries&amp;since=2026-01-01</code></dt><dd>Leads, newest first, 100 per page (enquiries.read)</dd>
      <dt><code>POST ?resource=enquiries</code></dt><dd>JSON <code>{"name","email","phone","message","source_note"}</code> (enquiries.write)</dd>
      <dt><code>GET ?resource=orders</code></dt><dd>Paid orders (orders.read)</dd>
      <dt><code>GET ?resource=students</code></dt><dd>Registrations - name, email, phone (students.read)</dd>
    </dl>
    <p class="muted" style="margin-bottom:0">Base URL: <code><?= e(app_url('api/v1.php')) ?></code> · Header: <code>Authorization: Bearer &lt;key&gt;</code> · Pagination: <code>&amp;page=2</code></p>
  </div>
</div>
<?php admin_end();
