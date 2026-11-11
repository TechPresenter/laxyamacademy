<?php
// Newsletter subscribers: sign-ups from the website newsletter forms (footer, homepage band, pages, popups).
// enquiries.view to view; enquiries.manage to unsubscribe / resubscribe / delete; export also needs reports.export or enquiries.manage.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('enquiries.view');
$canManage = can('enquiries.manage');
$canExport = $canManage || can('reports.export');

const NEWSLETTER_SOURCE_LABELS = [
    'footer' => 'Website footer', 'home' => 'Homepage band', 'page' => 'Custom page', 'popup' => 'Popup', 'blog' => 'Blog',
    'course_page' => 'Course page', 'newsletter_block' => 'Newsletter block', 'other' => 'Other',
];
const NEWSLETTER_PER_PAGE = 25;

/** Filters from the query string → [where SQL, params, normalised filter values]. */
function newsletter_filters(): array
{
    $f = [
        'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        'status' => in_array($_GET['status'] ?? '', ['subscribed', 'unsubscribed'], true) ? $_GET['status'] : '',
        'source' => preg_match('/^[a-z_]{1,40}$/', (string) ($_GET['source'] ?? '')) ? (string) $_GET['source'] : '',
        'from' => DateTime::createFromFormat('!Y-m-d', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
        'to' => DateTime::createFromFormat('!Y-m-d', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
    ];
    $where = ['1=1'];
    $params = [];
    if ($f['q'] !== '') {
        $where[] = '(s.email LIKE ? OR s.name LIKE ?)';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        array_push($params, $like, $like);
    }
    foreach (['status', 'source'] as $k) {
        if ($f[$k] !== '') {
            $where[] = "s.{$k} = ?";
            $params[] = $f[$k];
        }
    }
    if ($f['from'] !== '') {
        $where[] = 's.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $where[] = 's.created_at < ? + INTERVAL 1 DAY';
        $params[] = $f['to'];
    }
    return [implode(' AND ', $where), $params, $f];
}

// ---------------- Actions (managers only) ----------------
if (admin_post()) {
    if (!$canManage) {
        require_permission('enquiries.manage');
    }
    $act = (string) ($_POST['_action'] ?? '');
    $ids = $act === 'bulk' ? array_slice(array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))), 0, 500) : [(int) ($_POST['id'] ?? 0)];
    $op = $act === 'bulk' ? (string) ($_POST['bulk'] ?? '') : $act;
    $ids = array_values(array_filter($ids));
    if (!$ids) {
        flash('info', 'Nothing selected.');
    } elseif (!in_array($op, ['unsubscribe', 'resubscribe', 'delete'], true)) {
        flash('info', 'Choose an action.');
    } else {
        $in = db_placeholders(count($ids));
        if ($op === 'unsubscribe') {
            $n = db_query("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE status = 'subscribed' AND id IN ({$in})", $ids)->rowCount();
            audit_log('newsletter_unsubscribed', 'newsletter_subscribers', count($ids) === 1 ? $ids[0] : null, ['ids' => $ids]);
            flash('success', $n . ' subscriber' . ($n === 1 ? '' : 's') . ' unsubscribed. They will not receive newsletter emails.');
        } elseif ($op === 'resubscribe') {
            // Staff resubscribe only on the person's own request; the consent time is renewed and logged.
            foreach ($ids as $id) {
                db_query("UPDATE newsletter_subscribers SET status = 'subscribed', consent_at = NOW(), unsubscribed_at = NULL,
                    unsubscribe_token = COALESCE(unsubscribe_token, ?) WHERE id = ? AND status = 'unsubscribed'", [bin2hex(random_bytes(16)), $id]);
            }
            audit_log('newsletter_resubscribed', 'newsletter_subscribers', count($ids) === 1 ? $ids[0] : null, ['ids' => $ids, 'by_staff' => (int) $me['id']], 'warning');
            flash('success', 'Subscription restored. The consent date was set to now.');
        } else {
            $n = db_query("DELETE FROM newsletter_subscribers WHERE id IN ({$in})", $ids)->rowCount();
            audit_log('newsletter_deleted', 'newsletter_subscribers', count($ids) === 1 ? $ids[0] : null, ['ids' => $ids], 'warning');
            flash('success', $n . ' subscriber' . ($n === 1 ? '' : 's') . ' deleted permanently.');
        }
    }
    redirect_to(url_with([]));
}

[$where, $params, $f] = newsletter_filters();

// ---------------- CSV export (current filters) ----------------
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        require_permission('reports.export');
    }
    csv_download('newsletter-subscribers-' . date('Ymd') . '.csv', ['Email', 'Name', 'Status', 'Source', 'Consent given', 'Unsubscribed', 'Signed up'],
        array_map(fn($r) => [$r['email'], $r['name'], $r['status'], NEWSLETTER_SOURCE_LABELS[$r['source']] ?? $r['source'], $r['consent_at'], $r['unsubscribed_at'], $r['created_at']],
            db_all("SELECT s.email, s.name, s.status, s.source, s.consent_at, s.unsubscribed_at, s.created_at FROM newsletter_subscribers s WHERE {$where} ORDER BY s.id DESC", $params)));
}

$stats = db_one("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'subscribed'), 0) AS subscribed, COALESCE(SUM(status = 'unsubscribed'), 0) AS unsubscribed,
    COALESCE(SUM(status = 'subscribed' AND created_at >= NOW() - INTERVAL 30 DAY), 0) AS recent FROM newsletter_subscribers") ?: [];
$sources = NEWSLETTER_SOURCE_LABELS;
foreach (db_all('SELECT DISTINCT source FROM newsletter_subscribers') as $r) {
    $sources[$r['source']] ??= ucwords(str_replace('_', ' ', $r['source'])); // keep any older source values filterable
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers s WHERE {$where}", $params);
$rows = db_all("SELECT s.* FROM newsletter_subscribers s WHERE {$where} ORDER BY s.id DESC LIMIT " . NEWSLETTER_PER_PAGE . ' OFFSET ' . (($page - 1) * NEWSLETTER_PER_PAGE), $params);
$filtered = $f['q'] !== '' || $f['status'] !== '' || $f['source'] !== '' || $f['from'] !== '' || $f['to'] !== '';

admin_start('Newsletter Subscribers', null, 'enquiries', [
    'head' => true,
    'subtitle' => 'People who signed up for exam updates on the website. Every email includes a one-click unsubscribe link.',
    'actions' => ($canExport ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' Export CSV' . ($filtered ? ' (filtered)' : '') . '</a>' : '')
        . (can('cms.manage') ? '<a class="btn btn-light" href="cms.php?tab=home&amp;section=newsletter">' . icon('edit') . ' Edit sign-up band</a>' : ''),
]);
$stat = fn(string $label, int $value, string $ico, string $tone, string $sub) => '<div class="card stat"><div class="ico tone-' . $tone . '">' . icon($ico) . '</div><div><div class="label">' . e($label) . '</div><div class="value">' . number_format($value) . '</div><div class="sub">' . e($sub) . '</div></div></div>';
echo '<section class="grid g-4 d2-stats" aria-label="Subscriber figures">'
    . $stat('Active subscribers', (int) ($stats['subscribed'] ?? 0), 'mail', 'green', 'Receive newsletter emails')
    . $stat('New in 30 days', (int) ($stats['recent'] ?? 0), 'chart', 'blue', 'Still subscribed')
    . $stat('Unsubscribed', (int) ($stats['unsubscribed'] ?? 0), 'logout', 'amber', 'Never emailed again')
    . $stat('All sign-ups', (int) ($stats['total'] ?? 0), 'users', 'purple', 'Since launch')
    . '</section>';
?>
<div class="card"<?= $canManage && $rows ? ' data-bulk' : '' ?>>
  <form class="toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search subscribers</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search email or name…"></label>
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit>
      <option value="">All statuses</option>
      <option value="subscribed"<?= $f['status'] === 'subscribed' ? ' selected' : '' ?>>Subscribed</option>
      <option value="unsubscribed"<?= $f['status'] === 'unsubscribed' ? ' selected' : '' ?>>Unsubscribed</option>
    </select>
    <select class="in" style="width:auto" name="source" aria-label="Source" data-autosubmit>
      <option value="">All sources</option>
      <?php foreach ($sources as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <label class="d2-date">From <input class="in" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Signed up from"></label>
    <label class="d2-date">To <input class="in" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="Signed up until"></label>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="newsletter.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'newsletter') ?></span>
      <strong><?= $filtered ? 'No subscribers match these filters' : 'No subscribers yet' ?></strong>
      <p><?= $filtered ? 'Try a different search, source or date range.' : 'Sign-ups from the website newsletter forms appear here.' ?></p>
      <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="newsletter.php">Clear filters</a><?php endif; ?>
    </div>
  <?php else: ?>
    <?php if ($canManage): ?>
    <form method="post" id="bulk-form"><?= csrf_field() ?><input type="hidden" name="_action" value="bulk">
      <div class="bulkbar"><strong><span data-bulk-count>0</span> selected</strong>
        <button class="btn btn-light btn-sm" name="bulk" value="unsubscribe" data-confirm="Unsubscribe the selected people? They will no longer receive newsletter emails."><?= icon('logout') ?> Unsubscribe</button>
        <button class="btn btn-danger btn-sm" name="bulk" value="delete" data-confirm="Delete the selected subscribers permanently? This cannot be undone."><?= icon('trash') ?> Delete</button>
      </div>
    </form>
    <?php endif; ?>
    <div class="table-wrap"><table class="t stack">
      <thead><tr>
        <?php if ($canManage): ?><th style="width:36px"><input type="checkbox" data-check-all aria-label="Select all on this page"></th><?php endif; ?>
        <th scope="col">Subscriber</th><th scope="col">Source</th><th scope="col">Signed up</th><th scope="col">Consent</th><th scope="col">Status</th><th scope="col" class="actions"><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $sub = $r['status'] === 'subscribed'; ?>
        <tr>
          <?php if ($canManage): ?><td class="sel"><input type="checkbox" form="bulk-form" name="ids[]" value="<?= (int) $r['id'] ?>" data-row-check aria-label="Select <?= e($r['email']) ?>"></td><?php endif; ?>
          <td data-label="Subscriber"><div><a href="mailto:<?= e($r['email']) ?>"><strong><?= e($r['email']) ?></strong></a><?= $r['name'] ? '<span class="d2-sub">' . e($r['name']) . '</span>' : '' ?></div></td>
          <td data-label="Source"><span class="badge b-blue"><?= e($sources[$r['source']] ?? $r['source']) ?></span></td>
          <td data-label="Signed up" class="d2-nowrap"><div><?= e(fmt_dt($r['created_at'], 'j M Y')) ?><span class="d2-sub"><?= e(fmt_dt($r['created_at'], 'g:i A')) ?></span></div></td>
          <td data-label="Consent"><?= $r['consent_at'] ? '<div>' . icon('check') . ' Given<span class="d2-sub">' . e(fmt_dt($r['consent_at'], 'j M Y, g:i A')) . '</span></div>' : '<span class="muted">Not recorded</span>' ?></td>
          <td data-label="Status"><div><?= badge($r['status']) ?><?= !$sub && $r['unsubscribed_at'] ? '<span class="d2-sub">since ' . e(fmt_dt($r['unsubscribed_at'], 'j M Y')) . '</span>' : '' ?></div></td>
          <td class="actions"><span class="d2-acts">
            <?php if ($canManage): ?>
              <?= $sub
                  ? action_form('unsubscribe', ['id' => $r['id']], icon('logout') . ' Unsubscribe', 'btn btn-light btn-sm', 'Unsubscribe ' . $r['email'] . '? They will no longer receive newsletter emails.')
                  : action_form('resubscribe', ['id' => $r['id']], icon('mail') . ' Resubscribe', 'btn btn-light btn-sm', 'Only resubscribe ' . $r['email'] . ' if they asked you to (for example by email or phone). Their consent date will be set to now.') ?>
              <?= action_form('delete', ['id' => $r['id']], icon('trash') . '<span class="sr-only">Delete ' . e($r['email']) . '</span>', 'btn btn-danger btn-sm', 'Delete ' . $r['email'] . ' permanently? This cannot be undone.') ?>
            <?php endif; ?>
          </span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php render_pager($total, $page, NEWSLETTER_PER_PAGE); ?>
</div>
<?php admin_end();
