<?php
// Managed option lists used across the site: departments, employment types, faculty subjects, ticket categories, document types.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

$lists = [
    'department' => ['Departments', 'cms.manage', 'jobs', 'Used on job openings and the careers page.'],
    'employment_type' => ['Employment Types', 'cms.manage', 'jobs', 'Shown on job openings (Full-time, Part-time, …).'],
    'faculty_subject' => ['Faculty Subjects', 'cms.manage', 'jobs', 'Subjects for faculty positions on the careers page (each open position has its own application form).'],
    'ticket_category' => ['Ticket Categories', 'support.manage', 'support', 'Students choose one when raising a support ticket.'],
    'document_type' => ['Document Types', 'users.manage', 'users', 'Types students can upload in My Documents.'],
];
$list = array_key_exists($_GET['list'] ?? '', $lists) ? $_GET['list'] : 'department';
$_GET['list'] = $list; // so the module tab bar highlights the list actually shown (and every link keeps it)
[$title, $perm, $active, $sub] = $lists[$list];
$usage = fn(array $r) => match ($list) {
    'department' => (int) db_value('SELECT COUNT(*) FROM job_openings WHERE department = ?', [$r['value']]),
    'employment_type' => (int) db_value('SELECT COUNT(*) FROM job_openings WHERE FIND_IN_SET(?, employment_type)', [$r['value']]),
    'faculty_subject' => (int) db_value('SELECT (SELECT COUNT(*) FROM job_openings WHERE subject = ?) + (SELECT COUNT(*) FROM job_applications WHERE subject = ?)', [$r['value'], $r['value']]),
    'ticket_category' => (int) db_value('SELECT COUNT(*) FROM support_tickets WHERE category = ?', [$r['value']]),
    'document_type' => (int) db_value('SELECT COUNT(*) FROM student_documents WHERE doc_type = ?', [$r['value']]),
};

crud([
    'table' => 'lookup_values',
    'title' => $title,
    'singular' => 'option',
    'permission' => $perm,
    'active' => $active,
    'subtitle' => $sub,
    'where' => 't.list_key = ?',
    'where_params' => [$list],
    'defaults' => ['list_key' => $list],
    'order' => 't.sort_order, t.label',
    'search' => ['t.label', 't.value'],
    'columns' => [
        'label' => ['Label', fn($r) => '<div class="d1-main sm"><span class="d1-t">' . e($r['label']) . '</span><div class="d1-sub"><code style="font-size:11px">' . e($r['value']) . '</code></div></div>'],
        'usage' => ['In use', fn($r) => ($n = $usage($r)) ? '<span class="d1-num">' . $n . ' record' . ($n === 1 ? '' : 's') . '</span>' : '<span class="muted">Not used</span>'],
        'sort_order' => ['Order', fn($r) => '<span class="d1-num">' . (int) $r['sort_order'] . '</span>'],
        'is_active' => ['Status', fn($r) => badge($r['is_active'] ? 'active' : 'inactive')],
    ],
    'can_delete' => fn($r) => $usage($r) === 0,
    'before_save' => function (array $d, ?array $old) use ($list) {
        if ($old && $old['value'] !== $d['value'] && db_value('SELECT 1 FROM lookup_values WHERE list_key = ? AND value = ? AND id <> ?', [$list, $d['value'], $old['id']])) {
            throw new CrudError(['value' => 'This value already exists.']);
        }
        if (!$old && db_value('SELECT 1 FROM lookup_values WHERE list_key = ? AND value = ?', [$list, $d['value']])) {
            throw new CrudError(['value' => 'This value already exists.']);
        }
        return $d;
    },
    'after_save' => function (int $id, array $d, ?array $old) use ($list) {
        // Keep existing records consistent when a value is renamed.
        if ($old && $old['value'] !== $d['value']) {
            // Faculty subjects live on both openings and applications (it had no mapping, so a rename crashed).
            $maps = [
                'department' => [['job_openings', 'department']],
                'employment_type' => [['job_openings', 'employment_type']],
                'faculty_subject' => [['job_openings', 'subject'], ['job_applications', 'subject']],
                'ticket_category' => [['support_tickets', 'category']],
                'document_type' => [['student_documents', 'doc_type']],
            ][$list];
            foreach ($maps as [$table, $col]) {
                if ($list === 'employment_type') {
                    // employment_type is a comma-separated set (FIND_IN_SET above): rename the one member, keep the others.
                    // The ",value," strings are built here, not with CONCAT(',', ?, ','): live MySQL 8 refuses to mix a
                    // text literal with a prepared-statement parameter ("Illegal mix of collations").
                    db_query("UPDATE {$table} SET {$col} = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', {$col}, ','), ?, ?)) WHERE FIND_IN_SET(?, {$col})", [',' . $old['value'] . ',', ',' . $d['value'] . ',', $old['value']]);
                } else {
                    db_query("UPDATE {$table} SET {$col} = ? WHERE {$col} = ?", [$d['value'], $old['value']]);
                }
            }
        }
    },
    'fields' => [
        'label' => ['label' => 'Label (shown to users)', 'required' => true, 'max' => 120],
        'value' => ['label' => 'Stored value', 'required' => true, 'max' => 60, 'hint' => 'Short code saved with records, e.g. full_time. Renaming updates existing records.'],
        'sort_order' => ['label' => 'Order', 'type' => 'number', 'default' => 0],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
    ],
    'tabs' => admin_tabs(array_filter(array_map(fn($v) => can($v[1]) ? ['label' => $v[0]] : null, $lists)), $list, ['param' => 'list', 'label' => 'Option lists']),
]);
