<?php
// Promotional banners: image + text + button blocks shown on the homepage, the courses page
// or every course detail page. Optional schedule window; clicks are counted.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/media.php';

$me = require_staff_page();
require_permission('cms.manage');

$counts = array_fill_keys(array_keys(BANNER_PLACEMENTS), 0);
foreach (db_all('SELECT placement, COUNT(*) AS n FROM promo_banners GROUP BY placement') as $r) {
    $counts[$r['placement']] = (int) $r['n'];
}
$tabs = ['all' => 'All · ' . array_sum($counts)];
foreach (BANNER_PLACEMENTS as $k => $label) {
    $tabs[$k] = $label . ' · ' . $counts[$k];
}
$tab = current_tab($tabs);
$live = (int) db_value('SELECT COUNT(*) FROM promo_banners t WHERE ' . schedule_tab_sql('live'));

// Quick show / hide from the list
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['_action'] ?? '') === 'toggle' && admin_post()) {
    $row = db_one('SELECT id, title, is_active FROM promo_banners WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($row) {
        db_query('UPDATE promo_banners SET is_active = 1 - is_active WHERE id = ?', [$row['id']]);
        audit_log('updated', 'promo_banners', (int) $row['id'], ['is_active' => $row['is_active'] ? 0 : 1]);
        flash('success', '"' . $row['title'] . '" ' . ($row['is_active'] ? 'hidden from' : 'shown on') . ' the website.');
    }
    redirect_to(url_with([]));
}

crud([
    'table' => 'promo_banners',
    'title' => 'Promotional Banners',
    'singular' => 'banner',
    'permission' => 'cms.manage',
    'active' => 'cms',
    'subtitle' => 'Eye-catching offer / admission banners. ' . $live . ' live now. Use a wide image (about 1600×600) without important text near the edges.',
    // Placement views (real filtered lists, not in the module tab bar); pointless while there are no banners at all.
    'tabs' => array_sum($counts) > 0 || $tab !== 'all' ? admin_tabs($tabs, $tab, ['label' => 'Placements']) : '',
    'empty' => ['title' => 'No banners yet', 'icon' => 'banner'],
    'where' => $tab !== 'all' ? 't.placement = ?' : '1=1',
    'where_params' => $tab !== 'all' ? [$tab] : [],
    'order' => 't.is_active DESC, t.placement, t.sort_order, t.id DESC',
    'search' => ['t.title', 't.subtitle', 't.cta_label'],
    'sortable' => ['title' => 't.title', 'sort_order' => 't.sort_order', 'clicks' => 't.clicks'],
    'filters' => [
        'theme' => ['col' => 't.theme', 'label' => 'Themes', 'options' => BANNER_THEMES],
        'active' => ['col' => 't.is_active', 'label' => 'Statuses', 'options' => ['1' => 'Active', '0' => 'Inactive']],
    ],
    'bulk_status' => ['field' => 'is_active', 'label' => 'status', 'options' => ['1' => 'Active (show)', '0' => 'Inactive (hide)']],
    'defaults' => ['created_by' => (int) $me['id']],
    'columns' => [
        'image' => ['Banner', fn($r) => $r['image_path']
            ? '<img src="' . e(public_file_url($r['image_path'])) . '" alt="" loading="lazy" style="width:128px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block">'
            : '<span style="width:128px;height:48px;border-radius:8px;display:grid;place-items:center;color:#fff;font-size:11px;font-weight:700;background:' . e(BANNER_THEME_COLORS[$r['theme']] ?? '#0B63F6') . '">Text banner</span>'],
        'title' => ['Content', fn($r) => '<div class="d2-w200"><strong>' . e($r['title']) . '</strong>'
            . ($r['subtitle'] ? '<span class="d2-sub d2-clip">' . e($r['subtitle']) . '</span>' : '')
            . ($r['cta_label'] ? '<span class="d2-sub" style="margin-top:4px"><span class="badge b-blue">' . e($r['cta_label']) . '</span> <code class="d2-path" title="' . e((string) $r['cta_url']) . '">' . e((string) $r['cta_url']) . '</code></span>' : '') . '</div>'],
        'placement' => ['Placement', fn($r) => '<div>' . e(BANNER_PLACEMENTS[$r['placement']] ?? $r['placement'])
            . '<span class="d2-sub"><span aria-hidden="true" style="display:inline-block;width:10px;height:10px;border-radius:3px;vertical-align:-1px;background:' . e(BANNER_THEME_COLORS[$r['theme']] ?? '#ccc') . '"></span> ' . e(BANNER_THEMES[$r['theme']] ?? $r['theme']) . ' theme</span></div>'],
        'schedule' => ['Schedule', fn($r) => '<div>' . schedule_cell($r) . '</div>'], // live / scheduled / ended / hidden + window
        'clicks' => ['Clicks', fn($r) => number_format((int) $r['clicks'])],
        'sort_order' => ['Order', fn($r) => '<span class="d2-pos">' . (int) $r['sort_order'] . '</span>'],
    ],
    'row_actions' => fn($r) => action_form('toggle', ['id' => $r['id']], icon('eye') . ($r['is_active'] ? ' Hide' : ' Show'), 'btn btn-light btn-sm'),
    'before_save' => function (array $d) {
        $errors = [];
        if ($d['cta_url'] !== null && !is_site_link($d['cta_url'])) {
            $errors['cta_url'] = 'Use a site path starting with / (e.g. /courses) or a full https:// link.';
        }
        if ($d['cta_label'] && !$d['cta_url']) {
            $errors['cta_url'] = 'Add a link for the button.';
        }
        if ($d['cta_url'] && !$d['cta_label']) {
            $errors['cta_label'] = 'Add a button label (e.g. "Enroll now").';
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
        'title' => ['label' => 'Heading', 'required' => true, 'max' => 160, 'full' => true, 'placeholder' => 'e.g. Admissions open - SSC CGL 2026 batch'],
        'subtitle' => ['label' => 'Supporting text (optional)', 'type' => 'textarea', 'max' => 300, 'rows' => 2],
        'image_path' => ['label' => 'Image (optional)', 'type' => 'image', 'folder' => 'cms', 'w' => 1600, 'h' => 900, 'hint' => 'Without an image the banner uses the selected colour theme.'],
        'theme' => ['label' => 'Colour theme', 'type' => 'select', 'required' => true, 'default' => 'blue', 'options' => BANNER_THEMES],
        'cta_label' => ['label' => 'Button label (optional)', 'max' => 60, 'placeholder' => 'Enroll now'],
        'cta_url' => ['label' => 'Button link', 'max' => 255, 'placeholder' => '/courses/ssc-cgl-2026 or https://…', 'hint' => 'A page on this website (starts with /) or a full https:// link.'],
        'placement' => ['label' => 'Show on', 'type' => 'select', 'required' => true, 'default' => $tab !== 'all' ? $tab : 'home', 'options' => BANNER_PLACEMENTS],
        'sort_order' => ['label' => 'Display order', 'type' => 'number', 'default' => 0, 'min' => -999, 'max' => 9999, 'hint' => 'Lower numbers appear first.'],
        'starts_at' => ['label' => 'Show from (optional)', 'type' => 'datetime'],
        'ends_at' => ['label' => 'Show until (optional)', 'type' => 'datetime', 'hint' => 'For limited-time offers - the banner disappears automatically.'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
    ],
]);
