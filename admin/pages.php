<?php
// Page builder: block-based pages served at /p/{slug}. Drafts, publish, revisions, device preview.
// Also edits reusable sections (?reusable=ID) with the same block editor.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/blocks.php';

$me = require_staff_page();
require_permission('cms.manage');
$id = (int) ($_GET['id'] ?? 0);
$rid = (int) ($_GET['reusable'] ?? 0);
$isReusable = isset($_GET['reusable']);

function block_editor(array $blocks): void
{
    $types = block_types();
    $cats = array_column(db_all('SELECT slug, name FROM course_categories WHERE is_active = 1 ORDER BY sort_order'), 'name', 'slug');
    $reusables = array_column(db_all('SELECT id, name FROM reusable_sections ORDER BY name'), 'name', 'id');
    echo '<div id="blocks" data-blocks>';
    foreach ($blocks as $b) {
        $uid = bin2hex(random_bytes(4));
        [$label, $fields] = $types[$b['type']] ?? ['Unknown', []];
        echo '<div class="card" data-block style="margin-bottom:12px">';
        echo '<div class="card-head" style="padding:10px 14px"><strong>' . e($label) . '</strong><span style="display:flex;gap:6px;align-items:center">'
            . '<label class="switch" style="font-size:12px"><input type="checkbox" name="blocks[' . $uid . '][visible]" value="1"' . (($b['visible'] ?? true) ? ' checked' : '') . '><span class="track"></span> Visible</label>'
            . '<button type="button" class="btn btn-light btn-sm" data-move="up" aria-label="Move up">' . icon('up') . '</button>'
            . '<button type="button" class="btn btn-light btn-sm" data-move="down" aria-label="Move down">' . icon('down') . '</button>'
            . '<button type="button" class="btn btn-light btn-sm" data-dup aria-label="Duplicate">' . icon('copy') . '</button>'
            . '<button type="button" class="btn btn-danger btn-sm" data-del aria-label="Remove block">' . icon('trash') . '</button></span></div>';
        echo '<input type="hidden" name="blocks[' . $uid . '][type]" value="' . e($b['type']) . '"><input type="hidden" name="blocks[' . $uid . '][_uid]" value="' . $uid . '">';
        echo '<div class="card-pad form">';
        if (!$fields) {
            echo '<p class="muted full" style="margin:0">No settings.</p>';
        }
        foreach ($fields as $key => [$flabel, $ftype]) {
            $v = $b['data'][$key] ?? '';
            $name = 'blocks[' . $uid . '][data][' . $key . ']';
            $fid = 'b' . $uid . $key;
            $full = in_array($ftype, ['html', 'lines', 'textarea'], true) ? ' full' : '';
            echo '<div class="field' . $full . '"><label for="' . $fid . '">' . e($flabel) . '</label>';
            if (str_starts_with($ftype, 'select:')) {
                $src = substr($ftype, 7);
                $opts = $src === 'categories' ? ['' => 'All categories'] + $cats : ($src === 'reusable' ? ['' => 'Choose…'] + $reusables : array_column(array_map(fn($p) => explode('=', $p), explode(',', $src)), 1, 0));
                echo '<select id="' . $fid . '" name="' . e($name) . '">';
                foreach ($opts as $k => $l) {
                    echo '<option value="' . e((string) $k) . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
                }
                echo '</select>';
            } elseif ($ftype === 'html') {
                echo '<textarea id="' . $fid . '" name="' . e($name) . '" rows="8" data-rte>' . e((string) $v) . '</textarea>';
            } elseif ($ftype === 'textarea' || $ftype === 'lines') {
                echo '<textarea id="' . $fid . '" name="' . e($name) . '" rows="' . ($ftype === 'lines' ? 5 : 3) . '">' . e((string) $v) . '</textarea>';
            } elseif ($ftype === 'image') {
                echo '<input type="hidden" name="' . e($name) . '" value="' . e((string) $v) . '"><input id="' . $fid . '" type="file" accept="image/*" name="blocks_files[' . $uid . '][' . $key . ']" data-preview="' . $fid . 'pv">'
                    . '<img id="' . $fid . 'pv" class="thumb-preview" alt="" ' . ($v ? 'src="' . e(public_file_url((string) $v)) . '"' : 'hidden') . '>';
            } elseif ($ftype === 'number') {
                echo '<input id="' . $fid . '" type="number" min="1" max="24" name="' . e($name) . '" value="' . e((string) ($v ?: 4)) . '">';
            } else {
                echo '<input id="' . $fid . '" type="text" name="' . e($name) . '" value="' . e((string) $v) . '">';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '</div>';
    echo '<div class="card card-pad" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><label for="add-type" class="lbl" style="margin:0">Add block</label><select id="add-type" class="in" style="width:auto" name="add_type"><option value="">Choose a block…</option>';
    foreach ($types as $k => [$label]) {
        echo '<option value="' . $k . '">' . e($label) . '</option>';
    }
    echo '</select><button class="btn btn-light" name="_add" value="1">' . icon('plus') . ' Add &amp; save</button><span class="muted" style="font-size:12px">Use ↑ ↓ to reorder; changes are kept when you save.</span></div>';
}

function posted_blocks(): array
{
    $blocks = clean_blocks((array) ($_POST['blocks'] ?? []), (array) ($_FILES['blocks_files'] ?? []));
    $add = (string) ($_POST['add_type'] ?? '');
    if (!empty($_POST['_add']) && isset(block_types()[$add])) {
        $blocks[] = ['type' => $add, 'visible' => true, 'data' => []];
    }
    return $blocks;
}

// ---------------- Reusable sections ----------------
if ($isReusable) {
    $section = $rid ? db_one('SELECT * FROM reusable_sections WHERE id = ?', [$rid]) : null;
    if (admin_post()) {
        if (($_POST['_action'] ?? '') === 'delete' && $section) {
            $used = (int) db_value('SELECT COUNT(*) FROM pages WHERE blocks LIKE ?', ['%"section_id":"' . $rid . '"%']);
            if ($used) {
                flash('error', 'This section is used on ' . plural_n($used, 'page') . '. Remove it there first.');
            } else {
                db_query('DELETE FROM reusable_sections WHERE id = ?', [$rid]);
                audit_log('reusable_section_deleted', 'reusable_sections', $rid, [], 'warning');
                flash('success', 'Section deleted.');
                redirect_to('pages.php?tab=reusable');
            }
            redirect_to('pages.php?reusable=' . $rid);
        }
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: 'Untitled section';
        try {
            $blocks = array_values(array_filter(posted_blocks(), fn($b) => $b['type'] !== 'reusable'));
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect_to('pages.php?reusable=' . $rid);
        }
        if ($section) {
            db_query('UPDATE reusable_sections SET name = ?, blocks = ? WHERE id = ?', [$name, json_encode($blocks, JSON_UNESCAPED_UNICODE), $rid]);
        } else {
            db_query('INSERT INTO reusable_sections (name, blocks) VALUES (?, ?)', [$name, json_encode($blocks, JSON_UNESCAPED_UNICODE)]);
            $rid = (int) db()->lastInsertId();
        }
        audit_log('reusable_section_saved', 'reusable_sections', $rid);
        flash('success', 'Section saved. Pages using it update automatically.');
        redirect_to('pages.php?reusable=' . $rid . (!empty($_POST['_add']) ? '#blocks' : ''));
    }
    admin_start($section ? 'Section: ' . $section['name'] : 'New reusable section', null, 'cms', ['head' => true, 'crumbs' => '<a href="pages.php?tab=reusable">Reusable Sections</a> / Edit']);
    echo '<form method="post" enctype="multipart/form-data" data-dirty-check>' . csrf_field()
        . '<div class="card card-pad" style="margin-bottom:12px"><div class="field"><label for="rs-name">Section name</label><input id="rs-name" type="text" name="name" value="' . e($section['name'] ?? '') . '" required maxlength="120"></div></div>';
    block_editor(json_decode_array($section['blocks'] ?? '[]'));
    echo '<div class="card mt"><div class="form-actions" style="border:0"><span class="dirty-flag">' . icon('alert') . ' Unsaved changes</span><a class="btn btn-light" href="pages.php?tab=reusable">Back</a><button class="btn btn-primary">' . icon('check') . ' Save section</button></div></div></form>';
    if ($section) {
        echo '<div class="mt">' . action_form('delete', [], icon('trash') . ' Delete section', 'btn btn-danger', 'Delete this reusable section?') . '</div>';
    }
    admin_end();
    exit;
}

// ---------------- Page editor ----------------
if ($id || ($_GET['action'] ?? '') === 'new') {
    $page = $id ? db_one('SELECT * FROM pages WHERE id = ?', [$id]) : null;
    if ($id && !$page) {
        redirect_to('pages.php');
    }
    if (admin_post()) {
        $act = $_POST['_action'] ?? 'save';
        if ($act === 'restore' && $page) {
            $rev = db_one('SELECT * FROM page_revisions WHERE id = ? AND page_id = ?', [(int) $_POST['rev'], $id]);
            if ($rev) {
                db_query('INSERT INTO page_revisions (page_id, title, blocks, saved_by) VALUES (?, ?, ?, ?)', [$id, $page['title'], $page['blocks'], $me['id']]);
                db_query('UPDATE pages SET title = ?, blocks = ?, updated_by = ? WHERE id = ?', [$rev['title'], $rev['blocks'], $me['id'], $id]);
                audit_log('page_revision_restored', 'pages', $id);
                flash('success', 'Version restored (current version saved in history).');
            }
            redirect_to('pages.php?id=' . $id);
        }
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 180);
        $slug = strtolower(trim((string) ($_POST['slug'] ?? ''))) ?: slugify($title);
        $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'archived'], true) ? $_POST['status'] : 'draft';
        $err = match (true) {
            $title === '' => 'Enter a page title.',
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) => 'The URL may use lowercase letters, numbers and hyphens.',
            (bool) db_value('SELECT 1 FROM pages WHERE slug = ? AND id <> ?', [$slug, $id]) => 'Another page already uses this URL.',
            default => null,
        };
        try {
            $blocks = posted_blocks();
        } catch (InvalidArgumentException $e) {
            $err = $e->getMessage();
        }
        if ($err) {
            flash('error', $err);
            redirect_to($id ? 'pages.php?id=' . $id : 'pages.php?action=new');
        }
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $seo = [mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 160) ?: null, mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 320) ?: null];
        if ($page) {
            if ($page['blocks'] !== $json || $page['title'] !== $title) {
                db_query('INSERT INTO page_revisions (page_id, title, blocks, saved_by) VALUES (?, ?, ?, ?)', [$id, $page['title'], $page['blocks'], $me['id']]);
            }
            db_query('UPDATE pages SET title = ?, slug = ?, blocks = ?, status = ?, seo_title = ?, seo_description = ?, updated_by = ? WHERE id = ?', [$title, $slug, $json, $status, ...$seo, $me['id'], $id]);
        } else {
            db_query('INSERT INTO pages (title, slug, blocks, status, seo_title, seo_description, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [$title, $slug, $json, $status, ...$seo, $me['id']]);
            $id = (int) db()->lastInsertId();
        }
        audit_log('page_saved', 'pages', $id, ['status' => $status]);
        flash('success', $status === 'published' ? 'Page saved and published.' : 'Draft saved.');
        redirect_to('pages.php?id=' . $id . (!empty($_POST['_add']) ? '#blocks' : ''));
    }
    $blocks = json_decode_array($page['blocks'] ?? '[{"type":"hero","visible":true,"data":{}},{"type":"text","visible":true,"data":{}}]');
    admin_start($page ? 'Edit page: ' . $page['title'] : 'New page', null, 'cms', ['head' => true, 'crumbs' => '<a href="pages.php">Pages</a> / Editor',
        'actions' => $page ? '<a class="btn btn-light" href="' . e(app_url('p/' . $page['slug'] . ($page['status'] === 'published' ? '' : '?preview=1'))) . '" target="_blank" rel="noopener">' . icon('eye') . ' Open ' . ($page['status'] === 'published' ? 'page' : 'preview') . '</a>' : '']);
    ?>
    <form method="post" enctype="multipart/form-data" data-dirty-check>
      <?= csrf_field() ?>
      <div class="split-2-1">
        <div style="min-width:0">
          <div class="card card-pad form" style="margin-bottom:12px">
            <div class="field"><label for="pt">Page title</label><input id="pt" type="text" name="title" value="<?= e($page['title'] ?? '') ?>" required maxlength="180"></div>
            <div class="field"><label for="ps">URL</label><div style="display:flex;align-items:center;gap:6px"><span class="muted">/p/</span><input id="ps" type="text" name="slug" value="<?= e($page['slug'] ?? '') ?>" data-slug-from="title" pattern="[a-z0-9-]+"></div></div>
          </div>
          <?php block_editor($blocks); ?>
        </div>
        <div class="sticky-aside" style="display:grid;gap:12px">
          <div class="card card-pad" style="display:grid;gap:12px">
            <div class="field"><label for="pst">Status</label><select id="pst" name="status"><?php foreach (['draft' => 'Draft (staff preview only)', 'published' => 'Published', 'archived' => 'Archived'] as $k => $l): ?><option value="<?= $k ?>"<?= ($page['status'] ?? 'draft') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
            <button class="btn btn-primary"><?= icon('check') ?> Save</button>
            <?php if ($page): ?><p class="muted" style="font-size:12px;margin:0">Last saved <?= e(fmt_dt($page['updated_at'])) ?></p><?php endif; ?>
          </div>
          <div class="card card-pad" style="display:grid;gap:12px">
            <strong>SEO</strong>
            <div class="field"><label for="seot">SEO title</label><input id="seot" type="text" name="seo_title" value="<?= e($page['seo_title'] ?? '') ?>" maxlength="160" data-counter="60,70"></div>
            <div class="field"><label for="seod">Meta description</label><textarea id="seod" name="seo_description" rows="3" maxlength="320" data-counter="160,320"><?= e($page['seo_description'] ?? '') ?></textarea></div>
          </div>
          <?php if ($page): $revs = db_all('SELECT r.id, r.created_at, u.name FROM page_revisions r LEFT JOIN users u ON u.id = r.saved_by WHERE r.page_id = ? ORDER BY r.id DESC LIMIT 10', [$id]); ?>
          <div class="card card-pad"><strong>Revision history</strong>
            <?php foreach ($revs as $r): ?><div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px"><span><?= e(fmt_dt($r['created_at'], 'j M, g:i A')) ?><br><span class="muted"><?= e($r['name'] ?? '') ?></span></span><button class="btn btn-light btn-sm" form="restore-<?= (int) $r['id'] ?>">Restore</button></div><?php endforeach; ?>
            <?php if (!$revs): ?><p class="muted" style="font-size:12px;margin:6px 0 0">Previous versions appear here after you save changes.</p><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <?php foreach ($revs ?? [] as $r): ?><form id="restore-<?= (int) $r['id'] ?>" method="post" data-confirm="Restore this version? The current version is saved in history first."><?= csrf_field() ?><input type="hidden" name="_action" value="restore"><input type="hidden" name="rev" value="<?= (int) $r['id'] ?>"></form><?php endforeach; ?>
    <?php if ($page): ?>
    <div class="card mt">
      <div class="card-head"><h2>Preview</h2><span style="display:flex;gap:6px" role="group" aria-label="Device"><?php foreach (['100%' => 'Desktop', '768px' => 'Tablet', '390px' => 'Mobile'] as $w => $l): ?><button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('pv').style.width='<?= $w ?>'"><?= $l ?></button><?php endforeach; ?></span></div>
      <div style="background:var(--surface);padding:16px;overflow:auto"><iframe id="pv" title="Page preview" src="<?= e(app_url('p/' . $page['slug'] . '?preview=1')) ?>" style="width:100%;height:720px;border:1px solid var(--line);border-radius:10px;background:#fff;display:block;margin:0 auto;transition:width .2s"></iframe></div>
      <p class="muted" style="font-size:12px;padding:0 20px 14px;margin:0">Shows the last saved version. Save to refresh.</p>
    </div>
    <?php endif; ?>
    <?php
    admin_end();
    exit;
}

// ---------------- Lists ----------------
if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    $p = db_one('SELECT * FROM pages WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($p && $act === 'duplicate') {
        $slug = $p['slug'] . '-copy';
        for ($i = 2; db_value('SELECT 1 FROM pages WHERE slug = ?', [$slug]); $i++) {
            $slug = $p['slug'] . '-copy-' . $i;
        }
        db_query("INSERT INTO pages (title, slug, blocks, status, seo_title, seo_description, updated_by) VALUES (?, ?, ?, 'draft', ?, ?, ?)", [$p['title'] . ' (copy)', $slug, $p['blocks'], $p['seo_title'], $p['seo_description'], $me['id']]);
        audit_log('page_duplicated', 'pages', $p['id']);
        flash('success', 'Page duplicated as a draft.');
    }
    if ($p && $act === 'archive') {
        db_query("UPDATE pages SET status = 'archived' WHERE id = ?", [$p['id']]);
        audit_log('page_archived', 'pages', $p['id']);
        flash('success', 'Page archived (no longer public).');
    }
    if ($p && $act === 'delete' && $p['status'] === 'archived') {
        db_query('DELETE FROM pages WHERE id = ?', [$p['id']]);
        audit_log('page_deleted', 'pages', $p['id'], [], 'warning');
        flash('success', 'Page deleted.');
    }
    redirect_to(url_with([]));
}

$tabs = ['pages' => 'Pages', 'reusable' => 'Reusable Sections'];
$tab = current_tab($tabs);
// The Pages / Reusable Sections strip below is hidden by admin_tabs() when the module tab bar lists both.
admin_start($tab === 'reusable' ? 'Reusable Sections' : 'Page Builder', null, 'cms', ['head' => true, 'subtitle' => 'Build landing pages from blocks. Published pages are available at /p/your-url.',
    'actions' => $tab === 'pages' ? '<a class="btn btn-primary" href="?action=new">' . icon('plus') . ' New page</a>' : '<a class="btn btn-primary" href="?reusable=0">' . icon('plus') . ' New section</a>']);
echo admin_tabs($tabs, $tab);
$blockCount = function (string $json): string {
    $n = count(json_decode_array($json));
    return $n . ' block' . ($n === 1 ? '' : 's');
};
if ($tab === 'reusable') {
    $rows = db_all('SELECT * FROM reusable_sections ORDER BY name');
    if (!$rows) {
        echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('copy') . '</span><strong>No reusable sections yet</strong><p>Build a section once (for example an admissions banner), then add it to any page with the “Reusable section” block.</p>'
            . '<a class="btn btn-primary btn-sm" href="?reusable=0">' . icon('plus') . ' New section</a></div></div>';
        admin_end();
        exit;
    }
    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Section</th><th>Blocks</th><th>Updated</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-label="Section"><strong>' . e($r['name']) . '</strong></td><td data-label="Blocks">' . $blockCount((string) $r['blocks']) . '</td><td data-label="Updated" class="d2-nowrap">' . e(fmt_dt($r['updated_at'])) . '</td><td class="actions"><a class="btn btn-light btn-sm" href="?reusable=' . (int) $r['id'] . '">' . icon('edit') . ' Edit</a></td></tr>';
    }
    echo '</tbody></table></div></div>';
    admin_end();
    exit;
}
$rows = db_all("SELECT p.*, u.name AS author FROM pages p LEFT JOIN users u ON u.id = p.updated_by ORDER BY p.status = 'archived', p.updated_at DESC");
if (!$rows) {
    echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('layers') . '</span><strong>No pages yet</strong><p>Create a landing page from ready-made blocks (hero, text, courses, FAQs, call to action). Published pages live at /p/your-url.</p>'
        . '<a class="btn btn-primary btn-sm" href="?action=new">' . icon('plus') . ' New page</a></div></div>';
    admin_end();
    exit;
}
echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Page</th><th>Blocks</th><th>Status</th><th>Last updated</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $url = app_url('p/' . $r['slug'] . ($r['status'] === 'published' ? '' : '?preview=1'));
    echo '<tr><td data-label="Page"><div><strong>' . e($r['title']) . '</strong><span class="d2-sub"><code style="font-size:11px">/p/' . e($r['slug']) . '</code></span></div></td>'
        . '<td data-label="Blocks" class="d2-nowrap">' . $blockCount((string) $r['blocks']) . '</td><td data-label="Status">' . badge($r['status']) . '</td>'
        . '<td data-label="Last updated" class="d2-nowrap"><div>' . e(fmt_dt($r['updated_at'])) . ($r['author'] ? '<span class="d2-sub">by ' . e($r['author']) . '</span>' : '') . '</div></td><td class="actions"><span class="d2-acts">'
        . '<a class="btn btn-light btn-sm" href="' . e($url) . '" target="_blank" rel="noopener" aria-label="' . ($r['status'] === 'published' ? 'Open' : 'Preview') . ' ' . e($r['title']) . ' (new tab)" title="' . ($r['status'] === 'published' ? 'Open page' : 'Preview draft') . '">' . icon('eye') . '</a>'
        . '<a class="btn btn-light btn-sm" href="?id=' . (int) $r['id'] . '">' . icon('edit') . ' Edit</a>'
        . action_form('duplicate', ['id' => $r['id']], icon('copy') . '<span class="sr-only">Duplicate ' . e($r['title']) . '</span>', 'btn btn-light btn-sm')
        . ($r['status'] !== 'archived'
            ? action_form('archive', ['id' => $r['id']], icon('folder') . ' Archive', 'btn btn-light btn-sm', 'Archive this page? It will no longer be public.')
            : action_form('delete', ['id' => $r['id']], icon('trash') . '<span class="sr-only">Delete ' . e($r['title']) . '</span>', 'btn btn-danger btn-sm', 'Delete this archived page permanently?'))
        . '</span></td></tr>';
}
echo '</tbody></table></div></div>';
admin_end();
