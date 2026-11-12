<?php
// Website CMS: homepage blocks, header/menus/footer, pages & policies, FAQs.
// Blocks are JSON documents (cms_sections.content); this page renders a form from each document's shape.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/media.php';

$tabs = ['home' => 'Homepage Sections', 'layout' => 'Header, Menus & Footer', 'pages' => 'Pages & Policies', 'faqs' => 'FAQs', 'revisions' => 'Revision History'];
$tab = current_tab($tabs);

/** Homepage blocks in the order api/cms.php home_data() knows them; the admin can reorder these. */
const CMS_HOME_KEYS = ['hero', 'features', 'courses', 'categories', 'why_choose', 'stats', 'live_classes', 'job_alerts', 'resources',
    'success_stories', 'testimonials', 'videos_youtube', 'videos_instagram', 'newsletter', 'cta'];
const CMS_LAYOUT_KEYS = ['announcement', 'header', 'nav_main', 'nav_footer', 'footer'];
/** Item shape for lists, used when a list is empty (so "Add new" keeps the fields the website renders). */
const CMS_ITEM_SHAPES = [
    'features.items' => ['icon' => '', 'title' => '', 'text' => ''],
    'why_choose.items' => ['icon' => '', 'title' => '', 'text' => ''],
    'stats.items' => ['icon' => '', 'value' => '', 'label' => '', 'approved' => false],
    'nav_main.items' => ['label' => '', 'url' => '', 'mega' => false],
    'nav_footer.items' => ['label' => '', 'url' => ''],
    'page_about.sections' => ['heading' => '', 'icon' => '', 'body' => ''],
    'page_careers.sections' => ['heading' => '', 'icon' => '', 'body' => ''],
    'page.sections' => ['heading' => '', 'body' => ''],
];
/** Where each section can be previewed on the website (anything not listed is on the homepage). */
const CMS_PUBLIC_PATHS = ['page_about' => 'about', 'page_privacy' => 'privacy-policy', 'page_terms' => 'terms', 'page_refund' => 'refund-policy',
    'page_cancellation' => 'cancellation-policy', 'page_disclaimer' => 'disclaimer', 'page_cookies' => 'cookie-policy', 'page_careers' => 'careers'];
/** Icon names the website's <Icon> component understands (suggestions for "icon" fields). */
const CMS_ICON_NAMES = ['users', 'user', 'clipboard', 'video', 'book', 'file', 'calendar', 'check', 'checkCircle', 'trophy', 'target', 'star', 'headset', 'globe',
    'landmark', 'briefcase', 'bank', 'building', 'presentation', 'shield', 'train', 'badge', 'grid', 'newspaper', 'help', 'bell', 'layers', 'infinity', 'mic',
    'monitor', 'messages', 'ticket', 'calculator', 'puzzle', 'atom', 'languages', 'sparkles', 'graduationCap', 'barChart', 'trendingUp', 'flame', 'gift', 'info',
    'phone', 'phoneCall', 'mail', 'clock', 'mapPin', 'playCircle', 'heart', 'tag', 'megaphone', 'receipt', 'creditCard', 'camera', 'link', 'home', 'eye', 'inbox',
    'whatsapp', 'youtube', 'instagram', 'telegram'];
/** Related admin screens shown next to a section. */
const CMS_SECTION_LINKS = [
    'videos_youtube' => ['videos.php?tab=youtube', 'Manage videos'], 'videos_instagram' => ['videos.php?tab=instagram', 'Manage reels'],
    'newsletter' => ['newsletter.php', 'Subscribers'], 'job_alerts' => ['jobs.php?tab=alerts', 'Job alerts'],
    'testimonials' => ['testimonials.php?type=testimonial', 'Testimonials'], 'success_stories' => ['testimonials.php?type=success_story', 'Stories'],
    'live_classes' => ['live-classes.php', 'Live classes'], 'courses' => ['courses.php', 'Courses'], 'categories' => ['categories.php', 'Categories'],
    'resources' => ['resources.php?tab=categories', 'Resource categories'],
];

/** Which list tab a section belongs to. */
function cms_tab_for(string $key): string
{
    return str_starts_with($key, 'page_') ? 'pages' : (in_array($key, CMS_LAYOUT_KEYS, true) ? 'layout' : 'home');
}

if ($tab === 'faqs') {
    crud([
        'table' => 'faqs', 'title' => 'Website & CMS', 'singular' => 'FAQ', 'permission' => 'cms.manage', 'active' => 'cms',
        'tabs' => admin_tabs($tabs, $tab), 'order' => 't.category, t.sort_order', 'search' => ['t.question', 't.answer'],
        'filters' => ['category' => ['col' => 't.category', 'label' => 'Categories', 'options' => ['general' => 'General', 'courses' => 'Courses', 'tests' => 'Mock Tests', 'payments' => 'Payments', 'account' => 'Account']]],
        'columns' => [
            'question' => ['Question', fn($r) => '<div class="d2-minw"><strong>' . e($r['question']) . '</strong><span class="d2-sub d2-clip">' . e($r['answer']) . '</span></div>'],
            'category' => ['Category', fn($r) => '<span class="badge b-blue">' . e(['general' => 'General', 'courses' => 'Courses', 'tests' => 'Mock Tests', 'payments' => 'Payments', 'account' => 'Account'][$r['category']] ?? ucfirst((string) $r['category'])) . '</span>'],
            'sort_order' => ['Order', fn($r) => '<span class="d2-pos">' . (int) $r['sort_order'] . '</span>'],
            'is_active' => ['On the website', fn($r) => $r['is_active'] ? '<span class="badge b-green">Visible</span>' : '<span class="badge b-gray">Hidden</span>'],
        ],
        'fields' => [
            'question' => ['label' => 'Question', 'required' => true, 'max' => 300, 'full' => true],
            'answer' => ['label' => 'Answer', 'type' => 'textarea', 'required' => true],
            'category' => ['label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'general', 'options' => ['general' => 'General', 'courses' => 'Courses', 'tests' => 'Mock Tests', 'payments' => 'Payments', 'account' => 'Account']],
            'sort_order' => ['label' => 'Order', 'type' => 'number', 'default' => 0],
            'is_active' => ['label' => 'Show on website', 'type' => 'checkbox', 'default' => 1],
        ],
    ]);
    exit;
}

$me = require_staff_page();
require_permission('cms.manage');
$key = (string) ($_GET['section'] ?? '');
$section = $key !== '' ? db_one('SELECT * FROM cms_sections WHERE section_key = ?', [$key]) : null;
const LONG_KEYS = ['description', 'text', 'intro', 'body', 'card_text', 'subtitle'];

/** Template for one list item: the first item's keys, or the known shape when the list is empty. */
function cms_item_template(array $list, string $path, string $k): array
{
    $section = (string) ($GLOBALS['cms_section_key'] ?? '');
    $shape = $path === '' ? (CMS_ITEM_SHAPES[$section . '.' . $k] ?? (str_starts_with($section, 'page_') && $k === 'sections' ? CMS_ITEM_SHAPES['page.sections'] : [])) : [];
    $first = is_array($list[0] ?? null) ? $list[0] : [];
    return ($first + $shape) ?: ['heading' => '', 'body' => ''];
}

/** Links staff may put on the website: site path, https://, tel: or mailto:. Profile links must be https://. */
function cms_check_url(string $k, string $v, string $path): void
{
    if ($v === '') {
        return;
    }
    $label = ucwords(str_replace('_', ' ', $k)) . ($path !== '' ? ' (item ' . ((int) explode('.', $path)[1] + 1) . ')' : '');
    if (in_array($k, ['channel_url', 'profile_url'], true)) {
        if (!preg_match('#^https://[^\s<>"]+$#i', $v)) {
            throw new InvalidArgumentException($label . ' must be a full https:// link.');
        }
        return;
    }
    if (!is_site_link($v) && !preg_match('#^(tel:\+?[\d\s-]{6,20}|mailto:\S+@\S+)$#i', $v)) {
        throw new InvalidArgumentException($label . ': use a site path like /courses, a full https:// link, tel: or mailto:.');
    }
}

/** Rebuild the JSON document from POST, following the stored document's shape. */
function cms_rebuild(array $shape, array $post, string $path = ''): array
{
    $out = [];
    foreach ($shape as $k => $v) {
        $in = $post[$k] ?? null;
        if (is_bool($v) || ($k === 'approved')) {
            $out[$k] = !empty($in);
        } elseif ($k === 'image' || $k === 'og_image') {
            $field = 'img_' . md5($path . $k);
            if (uploaded_file_ok($_FILES[$field] ?? null)) {
                $out[$k] = store_image_upload($_FILES[$field], 'cms', 1600, 1600);
            } else {
                $out[$k] = !empty($post[$k . '__remove']) ? '' : (string) $v;
            }
        } elseif (is_array($v) && array_is_list($v) && ($v === [] || is_array($v[0] ?? null))) {
            // list of objects (items / sections)
            $template = cms_item_template($v, $path, (string) $k);
            $rows = [];
            foreach ((array) $in as $i => $row) {
                if (!is_array($row) || !empty($row['__remove'])) {
                    continue;
                }
                $clean = cms_rebuild($template, $row, "{$path}{$k}.{$i}.");
                if (implode('', array_map(fn($x) => is_scalar($x) && !is_bool($x) ? (string) $x : '', $clean)) !== '') {
                    $rows[] = $clean;
                }
            }
            $out[$k] = $rows;
        } elseif (is_array($v)) {
            $out[$k] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $in))));
        } else {
            $s = mb_substr(preg_replace('/[^\P{C}\n\t]/u', '', trim((string) $in)) ?? '', 0, 5000);
            if ($k === 'url' || $k === 'link' || str_ends_with((string) $k, '_url')) {
                cms_check_url((string) $k, $s, $path);
            }
            $out[$k] = $s;
        }
    }
    return $out;
}

function cms_fields(array $doc, string $prefix, string $path = ''): void
{
    foreach ($doc as $k => $v) {
        $name = $prefix . '[' . $k . ']';
        $id = 'c-' . md5($path . $k);
        $label = ucwords(str_replace('_', ' ', (string) $k));
        if (is_bool($v) || $k === 'approved') {
            echo '<div class="field"><label class="check"><input type="checkbox" name="' . e($name) . '" value="1"' . ($v ? ' checked' : '') . '> ' . e($label === 'Approved' ? 'Approved (show publicly)' : $label) . '</label></div>';
        } elseif ($k === 'image' || $k === 'og_image') {
            echo '<div class="field"><label for="' . $id . '">' . e($label) . '</label><input id="' . $id . '" type="file" accept="image/*" name="img_' . md5($path . $k) . '">';
            if ($v) {
                echo '<img class="thumb-preview" src="' . e(public_file_url((string) $v)) . '" alt=""><label class="check"><input type="checkbox" name="' . e($prefix . '[' . $k . '__remove]') . '" value="1"> Remove</label>';
            }
            echo '</div>';
        } elseif (is_array($v) && array_is_list($v) && ($v === [] || is_array($v[0] ?? null))) {
            $template = array_map(fn($x) => is_bool($x) ? false : '', cms_item_template($v, $path, (string) $k));
            echo '<div class="field full"><span class="lbl">' . e($label) . '</span>';
            foreach ([...$v, $template] as $i => $row) {
                $isNew = $i === count($v);
                echo '<fieldset style="border:1px solid var(--line);border-radius:10px;padding:12px;margin:0 0 10px"><legend class="muted" style="font-size:12px;padding:0 6px">' . ($isNew ? 'Add new' : '#' . ($i + 1)) . '</legend><div class="form">';
                cms_fields($row + array_diff_key($template, $row), $name . '[' . $i . ']', "{$path}{$k}.{$i}.");
                if (!$isNew) {
                    echo '<div class="field"><label class="check"><input type="checkbox" name="' . e($name . '[' . $i . '][__remove]') . '" value="1"> Remove this item</label></div>';
                }
                echo '</div></fieldset>';
            }
            echo '</div>';
        } elseif (is_array($v)) {
            echo '<div class="field full"><label for="' . $id . '">' . e($label) . ' (one per line)</label><textarea id="' . $id . '" name="' . e($name) . '" rows="4">' . e(implode("\n", $v)) . '</textarea></div>';
        } else {
            $long = in_array($k, LONG_KEYS, true) || mb_strlen((string) $v) > 90;
            $isUrl = $k === 'url' || $k === 'link' || str_ends_with((string) $k, '_url');
            $hint = match (true) {
                $k === 'icon' => '<div class="hint">Pick a suggestion or type an icon name (e.g. users, trophy, video).</div>',
                in_array($k, ['channel_url', 'profile_url'], true) => '<div class="hint">Full https:// link to your ' . ($k === 'channel_url' ? 'YouTube channel' : 'Instagram profile') . '. Leave empty to hide the button.</div>',
                $isUrl => '<div class="hint">Site path like /courses, a full https:// link, tel: or mailto:.</div>',
                default => '',
            };
            echo '<div class="field' . ($long ? ' full' : '') . '"><label for="' . $id . '">' . e($label) . '</label>' . ($long
                ? '<textarea id="' . $id . '" name="' . e($name) . '" rows="3">' . e((string) $v) . '</textarea>'
                : '<input id="' . $id . '" type="text" name="' . e($name) . '" value="' . e((string) $v) . '"' . ($k === 'icon' ? ' list="cms-icons" autocomplete="off"' : '') . '>') . $hint . '</div>';
        }
    }
}

/** Keep the current version before it is overwritten. */
function cms_snapshot(array $section, int $userId): void
{
    db_query('INSERT INTO cms_revisions (section_key, content, is_active, saved_by) VALUES (?, ?, ?, ?)', [$section['section_key'], $section['content'], $section['is_active'], $userId]);
}

if (admin_post() && in_array($_POST['_action'] ?? '', ['restore', 'toggle', 'move'], true)) {
    if ($_POST['_action'] === 'restore') {
        $rev = db_one('SELECT * FROM cms_revisions WHERE id = ?', [(int) $_POST['id']]);
        $cur = $rev ? db_one('SELECT * FROM cms_sections WHERE section_key = ?', [$rev['section_key']]) : null;
        if ($rev && $cur) {
            cms_snapshot($cur, (int) $me['id']);
            db_query('UPDATE cms_sections SET content = ?, is_active = ?, updated_by = ? WHERE section_key = ?', [$rev['content'], $rev['is_active'], $me['id'], $rev['section_key']]);
            audit_log('cms_revision_restored', 'cms_sections', $rev['section_key'], ['revision' => (int) $rev['id']], 'warning');
            flash('success', 'Version from ' . fmt_dt($rev['created_at']) . ' restored. The replaced version was saved in history.');
        }
        redirect_to('cms.php?tab=revisions');
    }
    if ($_POST['_action'] === 'move') {
        // Swap a homepage block with its neighbour; the set of sort_order values is reused so other rows keep theirs.
        $rows = db_all('SELECT section_key, sort_order FROM cms_sections WHERE section_key IN (' . db_placeholders(count(CMS_HOME_KEYS)) . ') ORDER BY sort_order, section_key', CMS_HOME_KEYS);
        $keys = array_column($rows, 'section_key');
        $i = array_search((string) ($_POST['key'] ?? ''), $keys, true);
        $j = $i === false ? false : $i + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        if ($i !== false && $j >= 0 && $j < count($keys)) {
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
            $slots = array_map('intval', array_column($rows, 'sort_order'));
            sort($slots);
            // Duplicate sort values would make the order ambiguous: spread them out first.
            for ($n = 1; $n < count($slots); $n++) {
                $slots[$n] = max($slots[$n], $slots[$n - 1] + 1);
            }
            foreach ($keys as $n => $k) {
                db_query('UPDATE cms_sections SET sort_order = ? WHERE section_key = ?', [$slots[$n], $k]);
            }
            audit_log('cms_section_reordered', 'cms_sections', (string) $_POST['key'], ['order' => $keys]);
            flash('success', 'Homepage order updated. The website shows the sections in this order.');
        }
        redirect_to('cms.php?tab=home');
    }
    $cur = db_one('SELECT * FROM cms_sections WHERE section_key = ?', [(string) $_POST['key']]);
    if ($cur) {
        cms_snapshot($cur, (int) $me['id']);
        db_query('UPDATE cms_sections SET is_active = 1 - is_active, updated_by = ? WHERE section_key = ?', [$me['id'], $cur['section_key']]);
        audit_log('cms_section_visibility', 'cms_sections', $cur['section_key']);
        flash('success', $cur['title'] . ($cur['is_active'] ? ' hidden from' : ' shown on') . ' the website.');
    }
    redirect_to('cms.php?tab=' . cms_tab_for($cur['section_key'] ?? ''));
}

if ($tab === 'revisions') {
    admin_start('Website & CMS', null, 'cms', ['head' => true, 'subtitle' => 'Every save keeps the previous version. Restoring also saves the current version first.']);
    echo admin_tabs($tabs, $tab);
    $revs = db_all('SELECT r.id, r.section_key, r.created_at, r.is_active, s.title, u.name FROM cms_revisions r JOIN cms_sections s ON s.section_key = r.section_key LEFT JOIN users u ON u.id = r.saved_by ORDER BY r.id DESC LIMIT 100');
    if (!$revs) {
        echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('history') . '</span><strong>No previous versions yet</strong><p>A version is kept every time a section is edited, shown or hidden.</p></div></div>';
        admin_end();
        exit;
    }
    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Section</th><th>Version saved</th><th>By</th><th>Was</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($revs as $r) {
        echo '<tr><td data-label="Section"><div><strong>' . e($r['title']) . '</strong><span class="d2-sub"><code style="font-size:11px">' . e($r['section_key']) . '</code></span></div></td>'
            . '<td data-label="Version saved" class="d2-nowrap">' . e(fmt_dt($r['created_at'])) . '</td><td data-label="By">' . e($r['name'] ?? '—') . '</td>'
            . '<td data-label="Was">' . ($r['is_active'] ? '<span class="badge b-green">Visible</span>' : '<span class="badge b-gray">Hidden</span>') . '</td><td class="actions">'
            . action_form('restore', ['id' => $r['id']], icon('history') . ' Restore', 'btn btn-light btn-sm', 'Restore this version? The current content will be saved in history first.') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    admin_end();
    exit;
}

if ($section && admin_post()) {
    $GLOBALS['cms_section_key'] = $key;
    try {
        $doc = cms_rebuild(json_decode_array($section['content']), (array) ($_POST['c'] ?? []));
        cms_snapshot($section, (int) $me['id']); // only after the new content validated
        db_query('UPDATE cms_sections SET content = ?, is_active = ?, updated_by = ? WHERE section_key = ?',
            [json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), empty($_POST['is_active']) ? 0 : 1, $me['id'], $key]);
        audit_log('cms_section_updated', 'cms_sections', $key);
        flash('success', $section['title'] . ' saved. Changes are live on the website.');
    } catch (InvalidArgumentException $e) {
        flash('error', 'Not saved - ' . $e->getMessage());
    }
    redirect_to('cms.php?tab=' . cms_tab_for($key) . '&section=' . urlencode($key));
}

admin_start('Website & CMS', null, 'cms', ['head' => true, 'subtitle' => 'Edit homepage content, header and footer, pages and policies. Changes go live immediately.']);
echo admin_tabs($tabs, $section ? cms_tab_for($key) : $tab);

if ($section) {
    $GLOBALS['cms_section_key'] = $key;
    $doc = json_decode_array($section['content']);
    $back = 'cms.php?tab=' . cms_tab_for($key);
    $related = CMS_SECTION_LINKS[$key] ?? null;
    echo '<form method="post" enctype="multipart/form-data" class="card">' . csrf_field();
    echo '<div class="card-head"><h2>' . e($section['title']) . '</h2><span style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
        . ($related ? '<a class="btn btn-light btn-sm" href="' . e($related[0]) . '">' . icon('arrow') . ' ' . e($related[1]) . '</a>' : '')
        . '<a href="' . e(app_url(CMS_PUBLIC_PATHS[$key] ?? '')) . '" target="_blank" rel="noopener">' . icon('eye') . ' Preview</a>'
        . '<a href="' . e($back) . '">← All sections</a></span></div><div class="card-pad form">';
    cms_fields($doc, 'c');
    echo '<datalist id="cms-icons">' . implode('', array_map(fn($n) => '<option value="' . e($n) . '">', CMS_ICON_NAMES)) . '</datalist>';
    echo '<div class="field full"><label class="check"><input type="checkbox" name="is_active" value="1"' . ($section['is_active'] ? ' checked' : '') . '> Show this section on the website</label>'
        . (in_array($key, CMS_HOME_KEYS, true) ? '<div class="hint">When unticked the whole block (heading and its items) is removed from the homepage.</div>' : '') . '</div>';
    if ($key === 'stats') {
        echo '<div class="field full"><div class="alert alert-warning" style="margin:0">Publish only figures you can substantiate. Each statistic appears only when it has a value AND "Approved" is ticked.</div></div>';
    }
    if ($key === 'videos_youtube' || $key === 'videos_instagram') {
        echo '<div class="field full"><div class="alert alert-info" style="margin:0">This section appears on the homepage only when it has at least one active ' . ($key === 'videos_youtube' ? 'YouTube video' : 'Instagram reel') . '. Add them under <a href="' . e(CMS_SECTION_LINKS[$key][0]) . '">YouTube &amp; Instagram Media</a>.</div></div>';
    }
    echo '</div><div class="form-actions"><a class="btn btn-light" href="' . e($back) . '">Cancel</a><button class="btn btn-primary">' . icon('check') . ' Save</button></div></form>';
    admin_end();
    exit;
}

// ---------------- Section list ----------------
$filter = match ($tab) {
    // The Shipping & Delivery policy (retired 2026-10-31) is back since 2026-11-12: payment gateways ask for it.
    'pages' => "s.section_key LIKE 'page\\_%'",
    'layout' => 's.section_key IN (' . implode(',', array_map(fn($k) => "'{$k}'", CMS_LAYOUT_KEYS)) . ')',
    default => "s.section_key NOT LIKE 'page\\_%' AND s.section_key NOT IN (" . implode(',', array_map(fn($k) => "'{$k}'", CMS_LAYOUT_KEYS)) . ')',
};
$rows = db_all("SELECT s.section_key, s.title, s.is_active, s.updated_at, u.name AS by_name FROM cms_sections s LEFT JOIN users u ON u.id = s.updated_by WHERE {$filter} ORDER BY s.sort_order, s.section_key");
$homeKeys = array_values(array_filter(array_column($rows, 'section_key'), fn($k) => in_array($k, CMS_HOME_KEYS, true)));
if ($tab === 'home') {
    $shown = count(array_filter($rows, fn($r) => $r['is_active'] && in_array($r['section_key'], $homeKeys, true)));
    echo '<p class="muted" style="margin:0 0 12px">The homepage shows these blocks from top to bottom (' . $shown . ' of ' . count($homeKeys) . ' visible). Use the arrows to change the order; hidden blocks are skipped entirely.</p>';
}
if (!$rows) {
    echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('layout') . '</span><strong>No sections in this group</strong><p>Run System → Database Updates if sections are missing.</p></div></div>';
    admin_end();
    exit;
}
echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr>' . ($tab === 'home' ? '<th style="width:120px">Order</th>' : '') . '<th>Section</th><th>Last updated</th><th>On the website</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $k = $r['section_key'];
    $pos = array_search($k, $homeKeys, true);
    $move = '';
    if ($tab === 'home' && $pos !== false) {
        $move = '<span class="d2-order"><span class="d2-pos" title="Position on the homepage">' . ($pos + 1) . '</span>'
            . ($pos > 0 ? action_form('move', ['key' => $k, 'dir' => 'up'], icon('up') . '<span class="sr-only">Move ' . e($r['title']) . ' up</span>', 'btn btn-light btn-sm') : '<span class="btn btn-light btn-sm d2-ghost" aria-hidden="true">' . icon('up') . '</span>')
            . ($pos < count($homeKeys) - 1 ? action_form('move', ['key' => $k, 'dir' => 'down'], icon('down') . '<span class="sr-only">Move ' . e($r['title']) . ' down</span>', 'btn btn-light btn-sm') : '<span class="btn btn-light btn-sm d2-ghost" aria-hidden="true">' . icon('down') . '</span>')
            . '</span>';
    }
    $related = CMS_SECTION_LINKS[$k] ?? null;
    echo '<tr>' . ($tab === 'home' ? '<td data-label="Order">' . $move . '</td>' : '')
        . '<td data-label="Section"><div><strong>' . e($r['title']) . '</strong><span class="d2-sub"><code style="font-size:11px">' . e($k) . '</code></span></div></td>'
        . '<td data-label="Last updated" class="d2-nowrap"><div>' . e(fmt_dt($r['updated_at'], 'j M Y, g:i A')) . ($r['by_name'] ? '<span class="d2-sub">by ' . e($r['by_name']) . '</span>' : '') . '</div></td>'
        . '<td data-label="On the website">' . ($r['is_active'] ? '<span class="badge b-green">Visible</span>' : '<span class="badge b-gray">Hidden</span>') . '</td>'
        . '<td class="actions"><span class="d2-acts">'
        . ($related ? '<a class="btn btn-light btn-sm" href="' . e($related[0]) . '">' . icon('arrow') . ' ' . e($related[1]) . '</a>' : '')
        . action_form('toggle', ['key' => $k], icon('eye') . ($r['is_active'] ? ' Hide' : ' Show') . '<span class="sr-only"> ' . e($r['title']) . '</span>', 'btn btn-light btn-sm', $r['is_active'] ? 'Hide this section on the website?' : null)
        . '<a class="btn btn-light btn-sm" href="' . e(app_url(CMS_PUBLIC_PATHS[$k] ?? '')) . '" target="_blank" rel="noopener" aria-label="Preview ' . e($r['title']) . ' on the website (new tab)" title="Preview on the website">' . icon('external') . '</a>'
        . '<a class="btn btn-primary btn-sm" href="?tab=' . e($tab) . '&amp;section=' . e(urlencode($k)) . '">' . icon('edit') . ' Edit</a></span></td></tr>';
}
echo '</tbody></table></div></div>';
admin_end();
