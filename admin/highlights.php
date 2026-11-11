<?php
// Header highlights: short "what's new" items (results, admit cards, offers…) shown in the
// website's highlights ticker / menu. Optional schedule window; the website shows up to 12.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/media.php';

$me = require_staff_page();
require_permission('cms.manage');

$c = db_one("SELECT COUNT(*) AS total,
        SUM(" . schedule_tab_sql('live') . ") AS live,
        SUM(" . schedule_tab_sql('scheduled') . ") AS scheduled,
        SUM(" . schedule_tab_sql('expired') . ") AS expired,
        SUM(" . schedule_tab_sql('inactive') . ") AS inactive
    FROM site_highlights t") ?: [];
$n = fn(string $k) => (int) ($c[$k] ?? 0);
$tabs = [
    'all' => 'All · ' . $n('total'),
    'live' => 'Live now · ' . $n('live'),
    'scheduled' => 'Scheduled · ' . $n('scheduled'),
    'expired' => 'Ended · ' . $n('expired'),
    'inactive' => 'Hidden · ' . $n('inactive'),
];
$tab = current_tab($tabs);
const HIGHLIGHT_BADGE_TONES = ['new' => 'blue', 'hot' => 'red', 'live' => 'red', 'result' => 'green', 'admit_card' => 'purple', 'update' => 'gray', 'offer' => 'amber'];

// Quick show / hide from the list
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['_action'] ?? '') === 'toggle' && admin_post()) {
    $row = db_one('SELECT id, title, is_active FROM site_highlights WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($row) {
        db_query('UPDATE site_highlights SET is_active = 1 - is_active WHERE id = ?', [$row['id']]);
        audit_log('updated', 'site_highlights', (int) $row['id'], ['is_active' => $row['is_active'] ? 0 : 1]);
        flash('success', '"' . $row['title'] . '" ' . ($row['is_active'] ? 'hidden.' : 'is active again.'));
    }
    redirect_to(url_with([]));
}

crud([
    'table' => 'site_highlights',
    'title' => 'Highlights',
    'singular' => 'highlight',
    'permission' => 'cms.manage',
    'active' => 'cms',
    'subtitle' => 'Short updates shown in the website header ticker (newest results, admit cards, offers). '
        . $n('live') . ' live now - the website shows the first 12 by display order.',
    'tabs' => admin_tabs($tabs, $tab),
    'where' => schedule_tab_sql($tab),
    'order' => 't.is_active DESC, t.sort_order, t.id DESC',
    'search' => ['t.title', 't.url'],
    'sortable' => ['title' => 't.title', 'sort_order' => 't.sort_order', 'badge' => 't.badge'],
    'filters' => ['badge' => ['col' => 't.badge', 'label' => 'Badges', 'options' => HIGHLIGHT_BADGES]],
    'bulk_status' => ['field' => 'is_active', 'label' => 'status', 'options' => ['1' => 'Active (show)', '0' => 'Inactive (hide)']],
    'defaults' => ['created_by' => (int) $me['id']],
    'columns' => [
        'title' => ['Highlight', fn($r) => '<div class="d2-w200"><strong>' . e($r['title']) . '</strong>' . ($r['url'] ? '<span class="d2-sub"><code class="d2-path" title="' . e($r['url']) . '">' . e($r['url']) . '</code></span>' : '<span class="d2-sub">No link</span>') . '</div>'],
        'badge' => ['Badge', fn($r) => '<span class="badge b-' . (HIGHLIGHT_BADGE_TONES[$r['badge']] ?? 'gray') . '">' . e(HIGHLIGHT_BADGES[$r['badge']] ?? $r['badge']) . '</span>'],
        'schedule' => ['Schedule', fn($r) => '<div>' . schedule_cell($r) . '</div>'], // live / scheduled / ended / hidden + window
        'sort_order' => ['Order', fn($r) => '<span class="d2-pos">' . (int) $r['sort_order'] . '</span>'],
    ],
    'row_actions' => fn($r) => action_form('toggle', ['id' => $r['id']], icon('eye') . ($r['is_active'] ? ' Hide' : ' Show'), 'btn btn-light btn-sm')
        // Only http(s) or site-path links get a button (a row written outside this form is not trusted).
        . ($r['url'] && preg_match('#^(https?://|/(?!/))#i', (string) $r['url']) ? ' <a class="btn btn-light btn-sm" href="' . e(str_starts_with($r['url'], '/') ? app_url(ltrim($r['url'], '/')) : $r['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open link" title="Open link">' . icon('external') . '</a>' : ''),
    'before_save' => function (array $d) {
        $errors = [];
        if ($d['url'] !== null && !is_site_link($d['url'])) {
            $errors['url'] = 'Use a site path starting with / (e.g. /jobs) or a full https:// link.';
        }
        if ($d['starts_at'] && $d['ends_at'] && $d['ends_at'] <= $d['starts_at']) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        if ($errors) {
            throw new CrudError($errors);
        }
        return $d;
    },
    'fields' => [
        'title' => ['label' => 'Text', 'required' => true, 'max' => 160, 'full' => true, 'counter' => '90', 'placeholder' => 'e.g. SSC CGL 2026 Tier 1 result declared', 'hint' => 'Keep it short - about 60-90 characters reads best in the ticker.'],
        'url' => ['label' => 'Link (optional)', 'max' => 255, 'placeholder' => '/jobs or https://ssc.gov.in/…', 'hint' => 'A page on this website (starts with /) or a full https:// link.'],
        'badge' => ['label' => 'Badge', 'type' => 'select', 'required' => true, 'default' => 'new', 'options' => HIGHLIGHT_BADGES],
        'starts_at' => ['label' => 'Show from (optional)', 'type' => 'datetime', 'hint' => 'Leave empty to show immediately.'],
        'ends_at' => ['label' => 'Show until (optional)', 'type' => 'datetime', 'hint' => 'Leave empty to keep it until you hide it.'],
        'sort_order' => ['label' => 'Display order', 'type' => 'number', 'default' => 0, 'min' => -999, 'max' => 9999, 'hint' => 'Lower numbers appear first.'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
    ],
]);
