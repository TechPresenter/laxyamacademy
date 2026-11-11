<?php
/**
 * Declarative CRUD for admin pages: list (search, filters, pagination), create,
 * edit, delete, validation, image/private uploads and audit logging.
 *
 *   crud([
 *     'table' => 'coupons', 'title' => 'Coupons', 'singular' => 'Coupon',
 *     'permission' => 'coupons.manage', 'active' => 'coupons',
 *     'search' => ['t.code'], 'order' => 't.id DESC',
 *     'columns' => ['code' => 'Code', 'is_active' => ['Status', fn($r) => badge($r['is_active'])]],
 *     'fields'  => ['code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 40]],
 *   ]);
 *
 * Column and table names come only from this trusted config; every value is bound.
 * Optional hooks: before_save($data, $old), after_save($id, $data, $old), after_bulk($ids, $statusValue),
 * after_bulk_delete($deletedIds), form_actions($row|null) → header buttons HTML on the add/edit form.
 *
 * Other options:
 *   'can_create' / 'can_edit' => false   enforced server-side (the form and the save are refused)
 *   'keep' => ['opening', ...]           extra query params kept by the toolbar (every scoped param is kept anyway)
 *   field 'show_if' => 'type=video,pdf'  show the field only when another field has one of these values
 *                                        ('field!=a,b' negates; join several rules with ';'). admin.js toggles it.
 *   field 'help' => 'text'               alias of 'hint' (small help line under the input)
 *   column ['Price', fn($r) => …, 'num']  optional 3rd item: CSS class for the column's th/td
 *                                        (num = right-aligned figures, col-date / col-status = no wrap, col-main = wide)
 *   'row_actions' => fn($r) => row_actions([...])  extra buttons / a ⋯ menu per row (see row_actions() in admin-header.php)
 *   'plural' => 'faculty members'        plural of 'singular' (default: an English plural of it) for messages
 *   'empty' => ['title' => …, 'text' => …, 'icon' => …]   empty-list message (each key optional)
 *   'label_field' => 'title'             column (or fn($row) => string) shown in the edit heading: "Edit course · <title>"
 *                                        (default: title, name or code when the row has one; false = none)
 *   filter 'all_label' => 'All levels'    first option of a filter select (default "All <label>")
 *   field 'type' => 'section'            a heading (label + optional 'hint') that splits a long form; not saved.
 *                                        Supports 'show_if' like a field. 'nav' => short name for the jump strip.
 *   field 'type' => 'custom'             'render' => fn(?array $row): string - a full-width block drawn in place
 *                                        (e.g. a repeater); not saved by crud (before_save reads its $_POST keys).
 *   'form_before' => fn(?array $row): string   HTML shown above the add/edit form (e.g. a record's tab bar)
 *   'section_nav' => true                a "Jump to" strip of the form's sections above the fields
 * 'where' also scopes edit/delete/bulk: a row outside the configured scope is treated as not found.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin-header.php';

final class CrudError extends Exception
{
    public function __construct(public array $fields, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }
}

function crud(array $cfg): void
{
    $cfg += [
        'pk' => 'id', 'from' => $cfg['table'] . ' t', 'select' => 't.*', 'where' => '1=1', 'where_params' => [],
        'order' => 't.id DESC', 'search' => [], 'filters' => [], 'per_page' => 20, 'defaults' => [],
        'can_create' => true, 'can_edit' => true, 'can_delete' => true, 'singular' => 'item',
        'manage_permission' => $cfg['permission'] ?? null, 'subtitle' => null, 'tabs' => '', 'header_actions' => '',
        'plural' => null, 'empty' => [], 'label_field' => null,
    ];
    $cfg['plural'] ??= crud_plural((string) $cfg['singular']);
    $action = $_GET['action'] ?? 'list';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $errors = [];
    $formValues = null;

    $me = require_staff_page();
    require_permission($cfg['permission']);
    // Hooks (before_save / after_save) read the signed-in staff member from here, before admin_start() runs.
    $GLOBALS['__admin_me'] = $me;
    $canManage = !$cfg['manage_permission'] || can($cfg['manage_permission']);

    if (admin_post()) {
        if (!$canManage) {
            require_permission($cfg['manage_permission']);
        }
        $postAction = $_POST['_action'] ?? 'save';
        if ($postAction === 'delete') {
            crud_delete($cfg, (int) ($_POST['id'] ?? 0));
        }
        if ($postAction === 'bulk') {
            crud_bulk($cfg, array_map('intval', (array) ($_POST['ids'] ?? [])), (string) ($_POST['bulk'] ?? ''));
        }
        if ($postAction === 'save') {
            // Screens that are list-only (or create-only) refuse the save itself, not just the button.
            if ((!$id && !$cfg['can_create']) || ($id && !$cfg['can_edit'])) {
                flash('error', $id ? 'This ' . $cfg['singular'] . ' cannot be edited here.' : 'New ' . $cfg['singular'] . ' records cannot be created here.');
                redirect_to(crud_list_url());
            }
            $old = $id ? crud_find($cfg, $id) : null;
            if ($id && !$old) {
                flash('error', ucfirst($cfg['singular']) . ' not found.');
                redirect_to(crud_list_url());
            }
            try {
                $newId = crud_save($cfg, $old);
                flash('success', ucfirst($cfg['singular']) . ($old ? ' updated.' : ' created.'));
                redirect_to(!empty($cfg['after_save_redirect']) ? ($cfg['after_save_redirect'])($newId) : crud_list_url());
            } catch (CrudError $e) {
                $errors = $e->fields;
                $formValues = $_POST;
                $action = $id ? 'edit' : 'new';
                flash('error', $e->getMessage());
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $errors = [];
                $formValues = $_POST;
                $action = $id ? 'edit' : 'new';
                flash('error', 'This conflicts with an existing record (duplicate value or invalid reference).');
            }
        }
    }

    $title = $cfg['title'];
    if ($action === 'new' || $action === 'edit') {
        if (!$canManage) {
            require_permission($cfg['manage_permission'] ?? 'settings.manage');
        }
        if (($action === 'new' && !$cfg['can_create']) || ($action === 'edit' && !$cfg['can_edit'])) {
            flash('error', $action === 'edit' ? 'This ' . $cfg['singular'] . ' cannot be edited here.' : 'New ' . $cfg['singular'] . ' records cannot be created here.');
            redirect_to(crud_list_url());
        }
        $row = $action === 'edit' ? crud_find($cfg, (int) $id) : null;
        if ($action === 'edit' && !$row) {
            flash('error', ucfirst($cfg['singular']) . ' not found.');
            redirect_to(crud_list_url());
        }
        $name = $row ? crud_row_label($cfg, $row) : '';
        admin_start(($row ? 'Edit ' : 'New ') . $cfg['singular'] . ($name !== '' ? ' · ' . mb_strimwidth($name, 0, 80, '…') : ''), null, $cfg['active'], [
            'head' => true,
            'crumbs' => '<a href="' . e(crud_list_url()) . '">' . e($title) . '</a> / ' . ($row ? 'Edit' : 'New'),
            'actions' => !empty($cfg['form_actions']) ? ($cfg['form_actions'])($row) : '', // e.g. a Preview button
        ]);
        if (!empty($cfg['form_before'])) {
            echo ($cfg['form_before'])($row);
        }
        crud_form($cfg, $row, $formValues, $errors);
        admin_end();
        return;
    }

    // ---------------- List ----------------
    [$where, $params] = crud_where($cfg);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = $cfg['per_page'];
    $total = (int) db_value("SELECT COUNT(*) FROM {$cfg['from']} WHERE {$where}", $params);
    // Sorting: only whitelisted expressions from 'sortable' => [column key => SQL expression]
    $sortable = $cfg['sortable'] ?? [];
    $sortKey = (string) ($_GET['sort'] ?? '');
    $dir = ($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
    $order = isset($sortable[$sortKey]) ? $sortable[$sortKey] . ' ' . $dir . ', t.' . $cfg['pk'] . ' DESC' : $cfg['order'];
    $rows = db_all("SELECT {$cfg['select']} FROM {$cfg['from']} WHERE {$where} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
    $bulkStatus = $cfg['bulk_status'] ?? null;
    $bulkDelete = $cfg['can_delete'] === true;
    $bulk = $canManage && ($bulkDelete || $bulkStatus);

    $actions = $cfg['header_actions'];
    if ($canManage && $cfg['can_create']) {
        $actions .= '<a class="btn btn-primary" href="' . e(url_with(['action' => 'new', 'id' => null, 'page' => null])) . '">' . icon('plus') . ' New ' . e($cfg['singular']) . '</a>';
    }
    admin_start($title, null, $cfg['active'], ['head' => true, 'subtitle' => $cfg['subtitle'], 'actions' => $actions]);
    echo $cfg['tabs'];
    echo '<div class="card"' . ($bulk ? ' data-bulk' : '') . '>';
    crud_toolbar($cfg);
    if (!$rows) {
        $filtered = trim((string) ($_GET['q'] ?? '')) !== '' || array_filter(array_intersect_key($_GET, $cfg['filters']), fn($v) => $v !== '');
        $empty = (array) $cfg['empty'];
        $plural = ucfirst($cfg['plural']);
        // Only promise "records you add" to someone who can add them here.
        $emptyText = $empty['text'] ?? match (true) {
            $canManage && $cfg['can_create'] => $plural . ' you add will be listed here.',
            (bool) $cfg['can_create'] => $plural . ' added by your team will be listed here.',
            default => $plural . ' will be listed here as soon as there are any.',
        };
        echo '<div class="empty"><span class="empty-ic">' . icon($filtered ? 'search' : (string) ($empty['icon'] ?? 'file')) . '</span>'
            . '<strong>' . e($filtered ? 'Nothing matches these filters' : (string) ($empty['title'] ?? 'No ' . $cfg['plural'] . ' yet')) . '</strong>'
            . ($filtered || $emptyText !== '' ? '<p>' . e($filtered ? 'Try a different search or clear the filters.' : (string) $emptyText) . '</p>' : '');
        if ($filtered) {
            echo '<a class="btn btn-light btn-sm" href="' . e(crud_reset_url($cfg)) . '">Clear filters</a>';
        } elseif ($canManage && $cfg['can_create']) {
            echo '<a class="btn btn-primary btn-sm" href="' . e(url_with(['action' => 'new', 'id' => null, 'page' => null])) . '">' . icon('plus') . ' New ' . e($cfg['singular']) . '</a>';
        }
        echo '</div>';
    } else {
        if ($bulk) {
            echo '<form method="post" id="bulk-form">' . csrf_field() . '<input type="hidden" name="_action" value="bulk"><div class="bulkbar"><strong><span data-bulk-count>0</span> selected</strong>';
            if ($bulkStatus) {
                echo '<select class="in" style="width:auto" name="bulk" aria-label="Bulk action"><option value="">Change ' . e($bulkStatus['label'] ?? 'status') . ' to…</option>';
                foreach ($bulkStatus['options'] as $k => $l) {
                    echo '<option value="status:' . e((string) $k) . '">' . e($l) . '</option>';
                }
                echo '</select><button class="btn btn-light btn-sm" data-confirm="Apply this change to the selected records?">Apply</button>';
            }
            if ($bulkDelete) {
                echo '<button class="btn btn-danger btn-sm" name="bulk" value="delete" data-confirm="Delete the selected records? Records in use will be skipped.">' . icon('trash') . ' Delete selected</button>';
            }
            echo '</div></form>';
        }
        // 'stack' turns rows into labelled cards on phones (labels come from data-label)
        echo '<div class="table-wrap"><table class="t stack"><thead><tr>';
        if ($bulk) {
            echo '<th style="width:36px"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>';
        }
        $colClass = fn($def) => is_array($def) && !empty($def[2]) && is_string($def[2]) ? ' class="' . e($def[2]) . '"' : '';
        foreach ($cfg['columns'] as $col => $def) {
            $label = e(is_array($def) ? $def[0] : $def);
            if (isset($sortable[$col])) {
                $isSorted = $sortKey === $col;
                $next = $isSorted && $dir === 'DESC' ? 'asc' : 'desc';
                $arrow = $isSorted ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
                echo '<th scope="col"' . $colClass($def) . ' aria-sort="' . ($isSorted ? ($dir === 'ASC' ? 'ascending' : 'descending') : 'none') . '"><a class="th-sort" href="' . e(url_with(['sort' => $col, 'dir' => $next, 'page' => null])) . '">' . $label . $arrow . '</a></th>';
            } else {
                echo '<th scope="col"' . $colClass($def) . '>' . $label . '</th>';
            }
        }
        echo '<th scope="col" class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            if ($bulk) {
                echo '<td class="sel"><input type="checkbox" form="bulk-form" name="ids[]" value="' . (int) $r[$cfg['pk']] . '" data-row-check aria-label="Select row"></td>';
            }
            foreach ($cfg['columns'] as $col => $def) {
                $cell = is_array($def) && isset($def[1]) ? ($def[1])($r) : e((string) ($r[$col] ?? ''));
                echo '<td' . $colClass($def) . ' data-label="' . e(is_array($def) ? (string) $def[0] : (string) $def) . '">' . $cell . '</td>';
            }
            echo '<td class="actions">';
            if (!empty($cfg['row_actions'])) {
                echo ($cfg['row_actions'])($r) . ' ';
            }
            if ($canManage && $cfg['can_edit']) {
                echo '<a class="btn btn-light btn-sm" href="' . e(url_with(['action' => 'edit', 'id' => $r[$cfg['pk']], 'page' => null])) . '">' . icon('edit') . ' Edit</a> ';
            }
            $deletable = is_callable($cfg['can_delete']) ? ($cfg['can_delete'])($r) : $cfg['can_delete'];
            if ($canManage && $deletable) {
                echo action_form('delete', ['id' => $r[$cfg['pk']]], icon('trash') . '<span class="sr-only">Delete this ' . e($cfg['singular']) . '</span>', 'btn btn-danger btn-sm', 'Delete this ' . $cfg['singular'] . '? This cannot be undone.');
            } elseif ($canManage && $cfg['can_delete'] !== false) {
                // Rows that cannot be deleted keep an empty Delete slot, so Edit lines up with the other rows.
                echo '<span class="btn btn-sm act-slot" aria-hidden="true">' . icon('trash') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    render_pager($total, $page, $per);
    echo '</div>';
    admin_end();
}

/** Bulk delete / status change for selected ids (permission already checked). */
function crud_bulk(array $cfg, array $ids, string $op): never
{
    $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 200);
    // Only rows inside the configured scope ('where') can be changed from this screen.
    if ($ids) {
        $ids = array_map('intval', array_column(db_all(
            "SELECT t.{$cfg['pk']} AS id FROM {$cfg['from']} WHERE t.{$cfg['pk']} IN (" . db_placeholders(count($ids)) . ") AND ({$cfg['where']})",
            [...$ids, ...$cfg['where_params']]
        ), 'id'));
    }
    if (!$ids) {
        flash('info', 'Nothing selected.');
        redirect_to(crud_list_url());
    }
    if ($op === 'delete' && $cfg['can_delete'] === true) {
        $done = 0;
        $deleted = [];
        foreach ($ids as $id) {
            try {
                db_query("DELETE FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
                $done++;
                $deleted[] = $id;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        audit_log('bulk_deleted', $cfg['table'], null, ['ids' => $ids, 'deleted' => $done], 'warning');
        if ($deleted && !empty($cfg['after_bulk_delete'])) {
            ($cfg['after_bulk_delete'])($deleted);
        }
        flash($done === count($ids) ? 'success' : 'warning', $done === count($ids) ? plural_n($done, 'record') . ' deleted.' : "{$done} of " . count($ids) . ' deleted (the others are in use).');
    } elseif (str_starts_with($op, 'status:') && !empty($cfg['bulk_status'])) {
        $value = substr($op, 7);
        $field = $cfg['bulk_status']['field'];
        if (!array_key_exists($value, $cfg['bulk_status']['options'])) {
            flash('error', 'Invalid status.');
        } else {
            db_query("UPDATE {$cfg['table']} SET {$field} = ? WHERE {$cfg['pk']} IN (" . db_placeholders(count($ids)) . ')', [$value, ...$ids]);
            audit_log('bulk_updated', $cfg['table'], null, ['ids' => $ids, $field => $value]);
            if (!empty($cfg['after_bulk'])) {
                ($cfg['after_bulk'])($ids, $value);
            }
            flash('success', plural_n(count($ids), 'record') . ' updated.');
        }
    } else {
        flash('info', 'Choose a bulk action.');
    }
    redirect_to(crud_list_url());
}

function crud_list_url(): string
{
    return url_with(['action' => null, 'id' => null]);
}

/**
 * One row by id, only if it lies inside the configured scope ('from' + 'where'). It carries the list's
 * extra 'select' columns too (counts used by can_delete etc.); the table's own columns always win.
 */
function crud_find(array $cfg, int $id): ?array
{
    $cfg += ['pk' => 'id', 'from' => $cfg['table'] . ' t', 'select' => 't.*', 'where' => '1=1', 'where_params' => []];
    $select = trim((string) $cfg['select']) === 't.*' ? 't.*' : $cfg['select'] . ', t.*';
    return db_one("SELECT {$select} FROM {$cfg['from']} WHERE t.{$cfg['pk']} = ? AND ({$cfg['where']}) LIMIT 1", [$id, ...$cfg['where_params']]);
}

/** Query params that define the current list's scope (kept across search/filter/reset). */
function crud_scope_params(array $cfg): array
{
    $drop = array_merge(['q', 'page', 'action', 'id', 'export', '_csrf'], array_keys($cfg['filters'] ?? []));
    $keep = [];
    foreach ($_GET as $k => $v) {
        if (is_string($k) && is_scalar($v) && (string) $v !== '' && !in_array($k, $drop, true) && preg_match('/^[a-z0-9_]{1,40}$/i', $k)) {
            $keep[$k] = (string) $v;
        }
    }
    // Configured extras stay even if the page set them programmatically.
    foreach ((array) ($cfg['keep'] ?? []) as $k) {
        if (isset($_GET[$k]) && is_scalar($_GET[$k]) && (string) $_GET[$k] !== '') {
            $keep[$k] = (string) $_GET[$k];
        }
    }
    return $keep;
}

/** List URL with search, filters and paging cleared but the scope (tab, course, opening …) kept. */
function crud_reset_url(array $cfg): string
{
    $q = crud_scope_params($cfg);
    unset($q['sort'], $q['dir']);
    return strtok($_SERVER['REQUEST_URI'], '?') . ($q ? '?' . http_build_query($q) : '');
}

function crud_where(array $cfg): array
{
    $where = [$cfg['where']];
    $params = $cfg['where_params'];
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '' && $cfg['search']) {
        $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        $where[] = '(' . implode(' OR ', array_map(fn($c) => "{$c} LIKE ?", $cfg['search'])) . ')';
        array_push($params, ...array_fill(0, count($cfg['search']), $like));
    }
    foreach ($cfg['filters'] as $name => $f) {
        $v = $_GET[$name] ?? '';
        if (is_string($v) && $v !== '' && array_key_exists($v, crud_options($f['options']))) {
            // $v is one of the configured option keys, so it is written as a quoted literal: filter columns such as
            // IF(t.parent_id IS NULL, 'main', 'sub') are text literals too, and MySQL 8 rejects comparing them with a
            // prepared-statement parameter ("Illegal mix of collations") although MariaDB allows it.
            $where[] = "{$f['col']} = " . db()->quote($v);
        }
    }
    return [implode(' AND ', $where), $params];
}

function crud_options(array|callable $options): array
{
    return is_callable($options) ? $options() : $options;
}

/** English plural of a short noun phrase: 'live class' → 'live classes', 'enquiry' → 'enquiries', 'FAQ' → 'FAQs'. */
function crud_plural(string $noun): string
{
    $noun = trim($noun);
    return match (true) {
        $noun === '' => 'records',
        (bool) preg_match('/quiz$/i', $noun) => $noun . 'zes',
        (bool) preg_match('/[^aeiou]y$/i', $noun) => substr($noun, 0, -1) . 'ies',
        (bool) preg_match('/(s|x|z|ch|sh)$/i', $noun) => $noun . 'es',
        default => $noun . 's',
    };
}

/** First option of a filter select: 'all_label', else "All statuses" / "All FAQ topics" (acronyms keep their case). */
function crud_all_label(array $filter): string
{
    if (isset($filter['all_label'])) {
        return (string) $filter['all_label'];
    }
    $label = trim((string) ($filter['label'] ?? ''));
    if ($label === '') {
        return 'All';
    }
    // Lower-case a capitalised first word only ("Statuses" → "statuses", but "FAQ topics" stays).
    if (preg_match('/^\p{Lu}\p{Ll}/u', $label)) {
        $label = mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }
    // Labels are normally plural already; a singular one ("Category") is made plural.
    return 'All ' . (preg_match('/s$/i', $label) ? $label : crud_plural($label));
}

/** Name of a record for the edit heading ("Edit course · <title>"), from 'label_field' or title / name / code. */
function crud_row_label(array $cfg, array $row): string
{
    $field = $cfg['label_field'] ?? null;
    if ($field === false) {
        return '';
    }
    if ($field instanceof Closure) {
        return trim((string) $field($row));
    }
    foreach ($field !== null ? (array) $field : ['title', 'name', 'code'] as $col) {
        if (isset($row[$col]) && is_scalar($row[$col]) && trim((string) $row[$col]) !== '') {
            return trim(preg_replace('/\s+/u', ' ', (string) $row[$col]));
        }
    }
    return '';
}

function crud_toolbar(array $cfg): void
{
    if (!$cfg['search'] && !$cfg['filters']) {
        return;
    }
    echo '<form class="toolbar" method="get" role="search">';
    // Keep every scoped param (tab, course, exam, when, opening, sort …) so filtering never widens the list.
    foreach (crud_scope_params($cfg) as $k => $v) {
        echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    if ($cfg['search']) {
        echo '<label class="search-in"><span class="sr-only">Search</span>' . icon('search') . '<input class="in" type="search" name="q" value="' . e((string) ($_GET['q'] ?? '')) . '" placeholder="Search…"></label>';
    }
    foreach ($cfg['filters'] as $name => $f) {
        echo '<select class="in" style="width:auto" name="' . e($name) . '" aria-label="' . e($f['label']) . '" data-autosubmit><option value="">' . e(crud_all_label($f)) . '</option>';
        foreach (crud_options($f['options']) as $k => $label) {
            echo '<option value="' . e((string) $k) . '"' . ((string) ($_GET[$name] ?? '') === (string) $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select>';
    }
    echo '<button class="btn btn-light">' . icon('search') . ' Filter</button>';
    if (trim((string) ($_GET['q'] ?? '')) !== '' || array_filter(array_intersect_key($_GET, $cfg['filters']), fn($v) => $v !== '')) {
        echo '<a class="btn btn-ghost" href="' . e(crud_reset_url($cfg)) . '">Reset</a>';
    }
    echo '</form>';
}

/** Convert, validate and persist POSTed field values. Returns the row id. */
function crud_save(array $cfg, ?array $old): int
{
    $data = [];
    $errors = [];
    foreach ($cfg['fields'] as $name => $f) {
        $f += ['type' => 'text', 'required' => false, 'label' => $name];
        if (!empty($f['readonly']) || !empty($f['virtual']) || $f['type'] === 'section' || $f['type'] === 'custom') {
            continue; // virtual fields (e.g. link tables) are persisted by after_save; sections are headings; custom blocks save in before_save
        }
        $raw = $_POST[$name] ?? null;
        $raw = is_string($raw) ? trim($raw) : $raw;
        try {
            $value = crud_convert($name, $f, $raw, $old);
        } catch (InvalidArgumentException $e) {
            $errors[$name] = $e->getMessage();
            continue;
        }
        if ($value === '__KEEP__') {
            continue;
        }
        if ($f['required'] && ($value === null || $value === '' || $value === '[]')) {
            $errors[$name] = 'This field is required.';
            continue;
        }
        if (!empty($f['unique']) && $value !== null) {
            $dupe = db_value("SELECT {$cfg['pk']} FROM {$cfg['table']} WHERE {$name} = ?" . ($old ? " AND {$cfg['pk']} <> ?" : ''), $old ? [$value, $old[$cfg['pk']]] : [$value]);
            if ($dupe) {
                $errors[$name] = 'This value is already in use.';
                continue;
            }
        }
        $data[$name] = $value;
    }
    if ($errors) {
        throw new CrudError($errors);
    }
    if (!$old) {
        $data = $cfg['defaults'] + $data;
    }
    if (!empty($cfg['before_save'])) {
        $data = ($cfg['before_save'])($data, $old);
    }

    if ($old) {
        $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        if ($data) {
            db_query("UPDATE {$cfg['table']} SET {$sets} WHERE {$cfg['pk']} = ?", [...array_values($data), $old[$cfg['pk']]]);
        }
        $id = (int) $old[$cfg['pk']];
    } else {
        $cols = implode(', ', array_keys($data));
        db_query("INSERT INTO {$cfg['table']} ({$cols}) VALUES (" . db_placeholders(count($data)) . ')', array_values($data));
        $id = (int) db()->lastInsertId();
    }
    audit_log($old ? 'updated' : 'created', $cfg['table'], $id, ['fields' => array_keys($data)]);
    if (!empty($cfg['after_save'])) {
        ($cfg['after_save'])($id, $data, $old);
    }
    return $id;
}

function crud_convert(string $name, array $f, mixed $raw, ?array $old): mixed
{
    $max = $f['max'] ?? null;
    $str = fn($v) => ($v === null || $v === '') ? null : (string) $v;
    switch ($f['type']) {
        case 'checkbox':
            return empty($raw) ? 0 : 1;
        case 'number':
        case 'money':
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException('Enter a number.');
            }
            $n = $f['type'] === 'money' ? round((float) $raw, 2) : (int) $raw;
            if (isset($f['min']) && $n < $f['min']) {
                throw new InvalidArgumentException("Must be at least {$f['min']}.");
            }
            if (isset($f['max']) && $n > $f['max']) {
                throw new InvalidArgumentException("Must be at most {$f['max']}.");
            }
            return $n;
        case 'email':
            if ($raw && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid email.');
            }
            return $str($raw ? strtolower($raw) : null);
        case 'url':
            if ($raw && !preg_match('#^https://[^\s]+$#i', $raw) && !(!empty($f['allow_relative']) && preg_match('#^/[^\s/][^\s]*$|^/$#', $raw))) {
                throw new InvalidArgumentException('Enter a full https:// link.');
            }
            return $str($raw);
        case 'slug':
            $v = $raw ?: (!empty($f['from']) ? slugify((string) ($_POST[$f['from']] ?? '')) : '');
            if ($v === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v)) {
                throw new InvalidArgumentException('Use lowercase letters, numbers and hyphens.');
            }
            return substr($v, 0, 200);
        case 'select':
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!array_key_exists($raw, crud_options($f['options']))) {
                throw new InvalidArgumentException('Invalid choice.');
            }
            return $raw;
        case 'date':
            if (!$raw) {
                return null;
            }
            if (!DateTime::createFromFormat('Y-m-d', $raw)) {
                throw new InvalidArgumentException('Enter a valid date.');
            }
            return $raw;
        case 'datetime':
            if (!$raw) {
                return null;
            }
            $d = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
            if (!$d) {
                throw new InvalidArgumentException('Enter a valid date and time.');
            }
            // The input shows minutes only: an unchanged value keeps its stored seconds (e.g. an offer ending 23:59:59).
            if (!empty($old[$name]) && substr((string) $old[$name], 0, 16) === $d->format('Y-m-d H:i')) {
                return (string) $old[$name];
            }
            return $d->format('Y-m-d H:i:s');
        case 'lines':
            $items = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $raw))));
            return json_encode($items, JSON_UNESCAPED_UNICODE);
        case 'faqs':
            $out = [];
            foreach (array_filter(array_map('trim', preg_split('/\R/', (string) $raw))) as $line) {
                [$q, $a] = array_map('trim', explode('::', $line, 2) + [1 => '']);
                if ($q !== '' && $a !== '') {
                    $out[] = ['q' => $q, 'a' => $a];
                }
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        case 'json':
            if ($raw === null || $raw === '') {
                return null;
            }
            json_decode((string) $raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
            }
            return $raw;
        case 'html':
            return $str(sanitize_html((string) $raw));
        case 'color':
            if ($raw && !preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
                throw new InvalidArgumentException('Use a #RRGGBB colour.');
            }
            return $str($raw);
        case 'image':
            $file = $_FILES[$name] ?? null;
            if (uploaded_file_ok($file)) {
                return store_image_upload($file, $f['folder'] ?? 'cms', $f['w'] ?? 1600, $f['h'] ?? 1600, $f['crop'] ?? false);
            }
            if (!empty($_POST[$name . '__remove'])) {
                return null;
            }
            return $old ? '__KEEP__' : null;
        case 'private_file':
            $file = $_FILES[$name] ?? null;
            if (uploaded_file_ok($file)) {
                $stored = store_private_upload($file, $f['folder'] ?? 'files', upload_limit_bytes(), $f['types'] ?? PRIVATE_UPLOAD_TYPES);
                $_POST['__stored'][$name] = $stored; // available to before_save (size/type columns)
                return $stored['path'];
            }
            if (!empty($_POST[$name . '__remove'])) {
                return null;
            }
            return $old ? '__KEEP__' : null;
        default: // text, textarea
            $v = $str($raw);
            if ($v !== null) {
                $v = preg_replace('/[^\P{C}\n\t]/u', '', $v);
                if ($max && mb_strlen($v) > $max) {
                    throw new InvalidArgumentException("Maximum {$max} characters.");
                }
                if (!empty($f['transform']) && $f['transform'] === 'upper') {
                    $v = strtoupper($v);
                }
            }
            return $v;
    }
}

function crud_form(array $cfg, ?array $row, ?array $posted, array $errors): void
{
    $multipart = (bool) array_filter($cfg['fields'], fn($f) => in_array($f['type'] ?? '', ['image', 'private_file'], true));
    echo '<form method="post" class="card' . (!empty($cfg['section_nav']) ? ' form-steps' : '') . '" novalidate data-dirty-check' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>' . csrf_field() . '<input type="hidden" name="_action" value="save">';
    if ($errors) {
        echo '<div class="alert alert-error form-alert" role="alert">' . icon('alert') . ' Please fix ' . count($errors) . ' highlighted field' . (count($errors) > 1 ? 's' : '') . ' below.</div>';
    }
    if (!empty($cfg['section_nav'])) {
        // "Jump to" strip: one numbered link per (always visible) section heading of the form.
        $secs = array_filter($cfg['fields'], fn($f) => ($f['type'] ?? '') === 'section' && empty($f['show_if']));
        if (count($secs) > 2) {
            echo '<nav class="form-jump" aria-label="Parts of this form"><span class="fj-lbl">Jump to</span>';
            $n = 0;
            foreach ($secs as $name => $f) {
                echo '<a href="#sec-' . e($name) . '"><span class="fj-n" aria-hidden="true">' . (++$n) . '</span>' . e((string) ($f['nav'] ?? $f['label'])) . '</a>';
            }
            echo '</nav>';
        }
    }
    echo '<div class="card-pad form">';
    // Current value of any field (posted > saved > default), used to decide the initial show_if state.
    $valueOf = function (string $field) use ($cfg, $row, $posted): string {
        $f = $cfg['fields'][$field] ?? [];
        if ($posted !== null) {
            $v = $posted[$field] ?? null;
            if (($f['type'] ?? '') === 'checkbox') {
                return empty($v) ? '0' : '1';
            }
            if (array_key_exists($field, $posted)) {
                return is_scalar($v) ? (string) $v : '';
            }
        }
        $v = $row[$field] ?? ($f['default'] ?? null);
        if (($f['type'] ?? '') === 'checkbox') {
            return empty($v) ? '0' : '1';
        }
        return is_scalar($v) ? (string) $v : '';
    };
    foreach ($cfg['fields'] as $name => $f) {
        $f += ['type' => 'text', 'label' => $name, 'required' => false];
        if (!empty($f['show_if'])) {
            $f['__shown'] = crud_show_if_match((string) $f['show_if'], $valueOf);
        }
        $value = $posted !== null && array_key_exists($name, $posted) ? $posted[$name] : ($row[$name] ?? ($f['default'] ?? null));
        crud_field($name, $f, $value, $errors[$name] ?? null, $row);
    }
    if (!empty($cfg['form_extra'])) {
        echo '<div class="field full">' . ($cfg['form_extra'])($row) . '</div>';
    }
    echo '</div><div class="form-actions"><span class="dirty-flag">' . icon('alert') . ' Unsaved changes</span><a class="btn btn-light" href="' . e(crud_list_url()) . '">Cancel</a><button class="btn btn-primary">' . icon('check') . ' Save</button></div></form>';
}

/**
 * Evaluate a show_if rule ('field=a,b', 'field!=a', several joined with ';') against current values.
 * $valueOf(field) returns the field's current value as a string (checkboxes: '1' / '0').
 */
function crud_show_if_match(string $rule, callable $valueOf): bool
{
    foreach (array_filter(array_map('trim', explode(';', $rule))) as $part) {
        if (!preg_match('/^([a-z0-9_]+)\s*(!?=)\s*(.*)$/i', $part, $m)) {
            continue;
        }
        $in = in_array($valueOf($m[1]), array_map('trim', explode(',', $m[3])), true);
        if ($m[2] === '=' ? !$in : $in) {
            return false;
        }
    }
    return true;
}

function crud_field(string $name, array $f, mixed $value, ?string $error, ?array $row): void
{
    $id = 'f-' . $name;
    $f['hint'] ??= $f['help'] ?? null;
    if ($f['type'] === 'custom') {
        echo '<div class="field full">' . (isset($f['render']) ? ($f['render'])($row) : '') . '</div>';
        return;
    }
    if ($f['type'] === 'section') {
        // Heading that splits a long form into parts (nothing is saved for it).
        echo '<div class="form-sec' . (!empty($f['show_if']) && empty($f['__shown']) ? ' si-off' : '') . '" id="sec-' . e($name) . '"'
            . (!empty($f['show_if']) ? ' data-show-if="' . e((string) $f['show_if']) . '"' : '') . '><h3>' . e((string) $f['label']) . '</h3>'
            . (!empty($f['hint']) ? '<p class="hint">' . e((string) $f['hint']) . '</p>' : '') . '</div>';
        return;
    }
    $full =!empty($f['full']) || in_array($f['type'], ['textarea', 'html', 'lines', 'faqs', 'json', 'checkboxes'], true);
    $req = $f['required'] ? ' <span class="req" aria-hidden="true">*</span>' : '';
    $describedBy = trim(($error ? $id . '-err ' : '') . (!empty($f['hint']) ? $id . '-hint' : ''));
    $attrs = ' id="' . $id . '" name="' . e($name) . '"' . ($f['required'] ? ' required aria-required="true"' : '') . (!empty($f['readonly']) ? ' readonly disabled' : '') . ($error ? ' aria-invalid="true"' : '')
        . ($describedBy !== '' ? ' aria-describedby="' . $describedBy . '"' : '')
        . (!empty($f['counter']) ? ' data-counter="' . e($f['counter']) . '"' : '') . ($f['type'] === 'html' ? ' data-rte' : '');
    // show_if: admin.js toggles the wrapper; the server sets the first state so nothing flashes.
    $showIf = !empty($f['show_if']) ? ' data-show-if="' . e((string) $f['show_if']) . '"' : '';
    $off = !empty($f['show_if']) && empty($f['__shown']) ? ' si-off' : '';
    echo '<div class="field' . ($full ? ' full' : '') . ($error ? ' invalid' : '') . $off . '"' . $showIf . '>';
    if ($f['type'] === 'checkboxes') {
        echo '<span class="lbl">' . e($f['label']) . '</span>';
    } elseif ($f['type'] !== 'checkbox') {
        echo '<label for="' . $id . '">' . e($f['label']) . $req . '</label>';
    }
    $v = is_scalar($value) ? (string) $value : '';
    switch ($f['type']) {
        case 'textarea':
        case 'html':
            echo '<textarea' . $attrs . ' rows="' . ($f['rows'] ?? ($f['type'] === 'html' ? 10 : 4)) . '">' . e($v) . '</textarea>';
            break;
        case 'json':
            echo '<textarea class="code"' . $attrs . '>' . e($v) . '</textarea>';
            break;
        case 'lines':
            $items = is_string($value) && str_starts_with(trim($value), '[') ? json_decode_array($value) : null;
            echo '<textarea' . $attrs . ' rows="5">' . e($items !== null ? implode("\n", $items) : $v) . '</textarea>';
            break;
        case 'faqs':
            $items = is_string($value) && str_starts_with(trim($value), '[') ? json_decode_array($value) : null;
            $text = $items !== null ? implode("\n", array_map(fn($x) => ($x['q'] ?? '') . ' :: ' . ($x['a'] ?? ''), $items)) : $v;
            echo '<textarea' . $attrs . ' rows="5">' . e($text) . '</textarea>';
            break;
        case 'select':
            echo '<select' . $attrs . '>' . (empty($f['required']) || $v === '' ? '<option value="">— Select —</option>' : '');
            foreach (crud_options($f['options']) as $k => $label) {
                echo '<option value="' . e((string) $k) . '"' . ((string) $k === $v ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            echo '</select>';
            break;
        case 'checkbox':
            echo '<label class="check"><input type="checkbox" value="1"' . $attrs . ($v ? ' checked' : '') . '> ' . e($f['label']) . '</label>';
            break;
        case 'checkboxes': // virtual multi-select; current values from $f['current']($row)
            $selected = array_map('strval', is_array($value) ? $value : (!empty($f['current']) && $row ? ($f['current'])($row) : []));
            echo '<div class="check-grid">';
            foreach (crud_options($f['options']) as $k => $label) {
                echo '<label class="check"><input type="checkbox" name="' . e($name) . '[]" value="' . e((string) $k) . '"' . (in_array((string) $k, $selected, true) ? ' checked' : '') . '> ' . e($label) . '</label>';
            }
            echo '</div>';
            break;
        case 'datetime':
            echo '<input type="datetime-local"' . $attrs . ' value="' . e($v ? date('Y-m-d\TH:i', strtotime($v)) : '') . '">';
            break;
        case 'date':
            echo '<input type="date"' . $attrs . ' value="' . e($v) . '">';
            break;
        case 'number':
        case 'money':
            echo '<input type="number"' . $attrs . ' value="' . e($v) . '" step="' . ($f['type'] === 'money' ? '0.01' : '1') . '"' . (isset($f['min']) ? ' min="' . $f['min'] . '"' : '') . '>';
            break;
        case 'color':
            echo '<input type="color"' . $attrs . ' value="' . e($v ?: '#0b63f6') . '">';
            break;
        case 'slug':
            echo '<input type="text"' . $attrs . ' value="' . e($v) . '" data-slug-from="' . e($f['from'] ?? 'title') . '" pattern="[a-z0-9-]+">';
            break;
        case 'image':
            $current = $row[$name] ?? null;
            echo '<input type="file" accept="image/jpeg,image/png,image/webp,image/gif"' . $attrs . ' data-preview="' . $id . '-pv">';
            echo '<img id="' . $id . '-pv" class="thumb-preview" alt="" ' . ($current ? 'src="' . e(public_file_url($current)) . '"' : 'hidden') . '>';
            if ($current) {
                echo '<label class="check mt-6"><input type="checkbox" name="' . e($name) . '__remove" value="1"> Remove image</label>';
            }
            break;
        case 'private_file':
            $current = $row[$name] ?? null;
            echo '<input type="file"' . $attrs . '>';
            if ($current) {
                echo '<div class="hint">Current: ' . e(basename($current)) . (private_file_path($current) ? '' : ' <span class="badge b-red">missing on disk</span>') . '</div>';
                echo '<label class="check mt-6"><input type="checkbox" name="' . e($name) . '__remove" value="1"> Remove file</label>';
            }
            break;
        default:
            $type = in_array($f['type'], ['email', 'url'], true) ? $f['type'] : 'text';
            echo '<input type="' . $type . '"' . $attrs . ' value="' . e($v) . '"' . (!empty($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '') . (!empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '') . '>';
    }
    if (!empty($f['hint'])) {
        echo '<div class="hint" id="' . $id . '-hint">' . e($f['hint']) . '</div>';
    }
    if ($error) {
        echo '<div class="err" id="' . $id . '-err">' . icon('alert') . ' ' . e($error) . '</div>';
    }
    echo '</div>';
}

function crud_delete(array $cfg, int $id): never
{
    $row = crud_find($cfg, $id);
    $allowed = $row && (is_callable($cfg['can_delete']) ? ($cfg['can_delete'])($row) : $cfg['can_delete']);
    if (!$allowed) {
        flash('error', 'This ' . $cfg['singular'] . ' cannot be deleted.');
        redirect_to(crud_list_url());
    }
    try {
        db_query("DELETE FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
        audit_log('deleted', $cfg['table'], $id, ['label' => $row['title'] ?? $row['name'] ?? $row['code'] ?? null], 'warning');
        flash('success', ucfirst($cfg['singular']) . ' deleted.');
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        flash('error', 'This ' . $cfg['singular'] . ' is in use by other records. Unpublish or archive it instead.');
    }
    redirect_to(crud_list_url());
}

/** Options helper: [id => label] from a query. */
function options_from(string $sql, array $params = []): array
{
    $out = [];
    foreach (db_all($sql, $params) as $r) {
        $out[(string) $r['id']] = $r['label'];
    }
    return $out;
}
