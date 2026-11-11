<?php
// Blog posts + categories. Content is sanitised HTML (script, iframe, style and event attributes are stripped).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

$tabs = ['posts' => 'Posts', 'categories' => 'Categories'];
$tab = current_tab($tabs);

if ($tab === 'categories') {
    crud([
        'table' => 'blog_categories', 'title' => 'Blog Categories', 'singular' => 'category', 'permission' => 'blog.manage', 'active' => 'blog',
        'tabs' => admin_tabs($tabs, $tab), 'order' => 't.sort_order, t.name',
        'select' => 't.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = t.id) AS n',
        'columns' => ['name' => ['Name', fn($r) => '<strong>' . e($r['name']) . '</strong>'], 'slug' => ['Slug', fn($r) => '<code style="font-size:12px">' . e($r['slug']) . '</code>'], 'n' => ['Posts', fn($r) => (int) $r['n'] . ' post' . ((int) $r['n'] === 1 ? '' : 's')]],        'fields' => [
            'name' => ['label' => 'Name', 'required' => true, 'max' => 100],
            'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'name', 'required' => true, 'unique' => true],
            'description' => ['label' => 'Description', 'max' => 300, 'full' => true],
            'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        ],
    ]);
    exit;
}

crud([
    'table' => 'blog_posts',
    'title' => 'Blog Posts',
    'singular' => 'post',
    'permission' => 'blog.manage',
    'active' => 'blog',
    'tabs' => admin_tabs($tabs, $tab),
    'from' => 'blog_posts t LEFT JOIN blog_categories bc ON bc.id = t.category_id LEFT JOIN users u ON u.id = t.author_id',
    'select' => 't.*, bc.name AS category_name, u.name AS author_name',
    'order' => 't.published_at IS NULL DESC, t.published_at DESC, t.id DESC',
    'search' => ['t.title', 't.tags'],
    'sortable' => ['title' => 't.title', 'published_at' => 't.published_at', 'views' => 't.views', 'status' => 't.status'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
    'filters' => ['status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published']]],
    'columns' => [
        'title' => ['Post', fn($r) => '<div class="d2-minw"><strong>' . e($r['title']) . '</strong>' . demo_badge($r) . '<span class="d2-sub">' . e($r['category_name'] ?? 'Uncategorised') . ' · ' . e($r['author_name'] ?? 'Laxyam Academy') . '</span></div>'],
        'published_at' => ['Publish date', fn($r) => $r['published_at'] ? '<div class="d2-nowrap">' . e(fmt_dt($r['published_at'], 'j M Y')) . '<span class="d2-sub">' . e(fmt_dt($r['published_at'], 'g:i A')) . '</span></div>' : '<span class="muted">Not set</span>'],
        'views' => ['Views', fn($r) => number_format((int) $r['views'])],
        'status' => ['Status', fn($r) => badge($r['status'] === 'scheduled' && $r['published_at'] && strtotime($r['published_at']) <= time() ? 'published' : $r['status'])],
    ],
    'row_actions' => fn($r) => $r['status'] !== 'draft' ? '<a class="btn btn-light btn-sm" href="' . e(app_url('blog/' . $r['slug'])) . '" target="_blank" rel="noopener" aria-label="View ' . e($r['title']) . ' on the website (new tab)" title="View on the website">' . icon('eye') . '</a>' : '',
    'before_save' => function (array $d, ?array $old) {
        if ($d['status'] === 'published' && !$d['published_at']) {
            $d['published_at'] = date('Y-m-d H:i:s');
        }
        if ($d['status'] === 'scheduled' && (!$d['published_at'] || strtotime($d['published_at']) <= time())) {
            throw new CrudError(['published_at' => 'Choose a future date for scheduled posts.']);
        }
        if (!$old) {
            $d['author_id'] = $GLOBALS['__admin_me']['id'] ?? null;
        }
        return $d;
    },
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 200],
        'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
        'category_id' => ['label' => 'Category', 'type' => 'select', 'options' => fn() => options_from('SELECT id, name AS label FROM blog_categories ORDER BY sort_order')],
        'tags' => ['label' => 'Tags (comma separated)', 'max' => 500],
        'featured_image_path' => ['label' => 'Featured image (16:9)', 'type' => 'image', 'folder' => 'blog', 'w' => 1600, 'h' => 900],
        'excerpt' => ['label' => 'Excerpt', 'type' => 'textarea', 'max' => 500, 'rows' => 2],
        'content' => ['label' => 'Content (HTML: p, h2, h3, ul, ol, li, a, strong, em, img, table, blockquote)', 'type' => 'html', 'rows' => 16],
        '_sec_seo' => ['label' => 'Search engines', 'type' => 'section', 'hint' => 'How the post appears in Google results. Leave blank to use the title and excerpt.'],
        'seo_title' =>['label' => 'SEO title (blank = post title)', 'max' => 160, 'counter' => '60,70'],
        'seo_description' => ['label' => 'Meta description (blank = excerpt)', 'max' => 320, 'counter' => '160,320'],
        '_sec_publish' => ['label' => 'Publishing', 'type' => 'section'],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published']],
        'published_at' => ['label' => 'Publish date/time', 'type' => 'datetime'],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ],
]);
