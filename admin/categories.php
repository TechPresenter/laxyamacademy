<?php
// Exam / course categories.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

const CATEGORY_ICONS = ['landmark' => 'Landmark (UPSC)', 'briefcase' => 'Briefcase', 'bank' => 'Bank', 'building' => 'Building', 'presentation' => 'Presentation (Teaching)', 'shield' => 'Shield (Defence)', 'train' => 'Train (Railway)', 'badge' => 'Badge (Police)', 'grid' => 'Grid (Other)', 'book' => 'Book', 'globe' => 'Globe'];

crud([
    'table' => 'course_categories',
    'title' => 'Exam Categories',
    'singular' => 'category',
    'permission' => 'courses.manage',
    'active' => 'categories',
    'subtitle' => 'Categories group courses, tests and live classes on the website and in the mega menu.',
    'select' => 't.*, p.name AS parent_name, (SELECT COUNT(*) FROM courses c WHERE c.category_id = t.id) AS course_count, (SELECT COUNT(*) FROM course_categories s WHERE s.parent_id = t.id) AS sub_count',
    'from' => 'course_categories t LEFT JOIN course_categories p ON p.id = t.parent_id',
    'order' => 'COALESCE(p.sort_order, t.sort_order), COALESCE(t.parent_id, t.id), t.parent_id IS NOT NULL, t.sort_order, t.name',
    'filters' => ['level' => ['col' => "IF(t.parent_id IS NULL, 'main', 'sub')", 'label' => 'Levels', 'options' => ['main' => 'Main categories', 'sub' => 'Subcategories']]],
    'search' => ['t.name', 't.slug'],
    'columns' => [
        'name' => ['Name', fn($r) => '<div class="d1-main sm"' . ($r['parent_id'] ? ' style="padding-left:18px"' : '') . '><span class="d1-t">'
            . ($r['parent_id'] ? '<span class="muted" aria-hidden="true">↳ </span>' : '') . '<span class="d1-dot" style="border-radius:3px;background:' . e($r['color'] ?: '#cbd5e1') . '"></span>' . e($r['name']) . '</span>'
            . '<div class="d1-sub"><span>/' . e($r['slug']) . '</span>' . ($r['parent_name'] ? '<span>in ' . e($r['parent_name']) . '</span>' : ($r['sub_count'] ? '<span>' . (int) $r['sub_count'] . ' subcategor' . ((int) $r['sub_count'] === 1 ? 'y' : 'ies') . '</span>' : '')) . '</div></div>'],
        'course_count' => ['Courses', fn($r) => (int) $r['course_count'] ? '<a href="courses.php?category=' . (int) $r['id'] . '">' . number_format((int) $r['course_count']) . '</a>' : '0'],
        'sort_order' => ['Order', fn($r) => '<span class="d1-num">' . (int) $r['sort_order'] . '</span>'],
        'is_active' => ['Status', fn($r) => badge($r['is_active'] ? 'active' : 'inactive')],
    ],
    'can_delete' => fn($r) => (int) $r['course_count'] === 0 && (int) $r['sub_count'] === 0,
    'before_save' => function (array $d, ?array $old) {
        if ($d['parent_id'] !== null) {
            if ($old && (int) $d['parent_id'] === (int) $old['id']) {
                throw new CrudError(['parent_id' => 'A category cannot be its own parent.']);
            }
            if (db_value('SELECT parent_id FROM course_categories WHERE id = ?', [$d['parent_id']])) {
                throw new CrudError(['parent_id' => 'Choose a top-level category (one level of subcategories is supported).']);
            }
            if ($old && db_value('SELECT 1 FROM course_categories WHERE parent_id = ?', [$old['id']])) {
                throw new CrudError(['parent_id' => 'This category has subcategories, so it must stay top-level.']);
            }
        }
        return $d;
    },
    'fields' => [
        'name' => ['label' => 'Name', 'required' => true, 'max' => 100],
        'parent_id' => ['label' => 'Parent category (leave empty for a main category)', 'type' => 'select', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories WHERE parent_id IS NULL ORDER BY sort_order, name')],
        'slug' => ['label' => 'Slug (URL)', 'type' => 'slug', 'from' => 'name', 'required' => true, 'unique' => true],
        'short_name' => ['label' => 'Short name', 'max' => 30],
        'icon' => ['label' => 'Icon', 'type' => 'select', 'options' => CATEGORY_ICONS],
        'color' => ['label' => 'Colour', 'type' => 'color'],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'description' => ['label' => 'Description', 'type' => 'textarea'],
        'seo_title' => ['label' => 'SEO title (category course listing)', 'max' => 160, 'counter' => '60,70', 'hint' => 'Used on /courses?category=<slug>. Blank = "<Name> Courses | site name".'],
        'seo_description' => ['label' => 'SEO description', 'max' => 320, 'counter' => '160,320', 'hint' => 'Blank = the description above.'],
        'is_active' => ['label' => 'Active (visible on website)', 'type' => 'checkbox', 'default' => 1],
    ],
]);
