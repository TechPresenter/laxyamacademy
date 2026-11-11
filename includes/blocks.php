<?php
/**
 * Page-builder block definitions, shared by the admin editor (admin/pages.php)
 * and the public renderer (api/cms.php?action=custom-page → pages/CustomPage.jsx).
 *
 * Field types: text, textarea, html (sanitised), url, image, number, select, lines
 * ("lines" = one item per line, "a | b" pairs where noted).
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function block_types(): array
{
    return [
        'hero' => ['Hero banner', ['heading' => ['Heading', 'text'], 'subheading' => ['Sub-heading', 'textarea'], 'button_label' => ['Button label', 'text'], 'button_url' => ['Button link', 'url'], 'image' => ['Image', 'image']]],
        'text' => ['Rich text', ['html' => ['Content', 'html']]],
        'image' => ['Image', ['image' => ['Image', 'image'], 'alt' => ['Alt text (describe the image)', 'text'], 'caption' => ['Caption', 'text']]],
        'video' => ['YouTube video', ['youtube_url' => ['YouTube link', 'url'], 'caption' => ['Caption', 'text']]],
        'buttons' => ['Buttons', ['items' => ['Buttons (one per line: Label | /link)', 'lines']]],
        'course_grid' => ['Course grid', ['title' => ['Title', 'text'], 'category' => ['Category', 'select:categories'], 'limit' => ['Number of courses', 'number']]],
        'category_grid' => ['Exam categories', ['title' => ['Title', 'text']]],
        'features' => ['Feature cards', ['title' => ['Title', 'text'], 'items' => ['Cards (one per line: Title | description)', 'lines']]],
        'stats' => ['Statistics', ['items' => ['Figures (one per line: Value | Label) - verified numbers only', 'lines']]],
        'testimonials' => ['Testimonials', ['title' => ['Title', 'text'], 'limit' => ['Number to show', 'number']]],
        'faqs' => ['FAQs', ['title' => ['Title', 'text'], 'items' => ['Questions (one per line: Question | Answer)', 'lines']]],
        'cta' => ['Call-to-action banner', ['heading' => ['Heading', 'text'], 'text' => ['Text', 'textarea'], 'button_label' => ['Button label', 'text'], 'button_url' => ['Button link', 'url']]],
        'contact' => ['Contact & enquiry form', ['title' => ['Title', 'text']]],
        'newsletter' => ['Newsletter sign-up', ['title' => ['Title', 'text'], 'text' => ['Text', 'textarea']]],
        'spacer' => ['Spacer', ['size' => ['Size', 'select:sm=Small,md=Medium,lg=Large']]],
        'divider' => ['Divider', []],
        'reusable' => ['Reusable section', ['section_id' => ['Section', 'select:reusable']]],
    ];
}

/** Validate and normalise posted blocks (admin). Images are uploaded here. */
function clean_blocks(array $posted, array $files, string $prefix = 'blocks'): array
{
    $types = block_types();
    $out = [];
    foreach (array_values($posted) as $i => $b) {
        $type = (string) ($b['type'] ?? '');
        if (!isset($types[$type])) {
            continue;
        }
        $data = [];
        foreach ($types[$type][1] as $key => [$label, $ftype]) {
            $v = $b['data'][$key] ?? '';
            if ($ftype === 'image') {
                $f = isset($files['name'][$b['_uid'] ?? ''][$key]) ? [
                    'name' => $files['name'][$b['_uid']][$key], 'type' => $files['type'][$b['_uid']][$key], 'tmp_name' => $files['tmp_name'][$b['_uid']][$key],
                    'error' => $files['error'][$b['_uid']][$key], 'size' => $files['size'][$b['_uid']][$key],
                ] : null;
                $v = uploaded_file_ok($f) ? store_image_upload($f, 'cms') : (string) ($b['data'][$key] ?? '');
                $v = preg_match('#^uploads/cms/[\w.-]+$#', $v) ? $v : '';
            } elseif ($ftype === 'html') {
                $v = sanitize_html((string) $v);
            } elseif ($ftype === 'url') {
                $v = block_url((string) $v);
            } elseif ($ftype === 'number') {
                $v = max(1, min(24, (int) $v ?: 4));
            } else {
                $v = mb_substr(trim((string) $v), 0, $ftype === 'lines' || $ftype === 'textarea' ? 5000 : 300);
            }
            $data[$key] = $v;
        }
        $out[] = ['type' => $type, 'visible' => !empty($b['visible']), 'data' => $data];
    }
    return array_slice($out, 0, 60);
}

/**
 * A block link: a site path ("/courses", not "//host") or an https:// URL; anything else
 * (javascript:, data:, protocol-relative…) becomes ''. Used on save and again when rendering.
 */
function block_url(string $v): string
{
    $v = trim($v);
    return preg_match('#^(/(?!/)[\w\-./?=&%]*|https://\S+)$#', $v) ? mb_substr($v, 0, 500) : '';
}

/** "a | b" lines → [[a, b], …] */
function block_pairs(string $lines): array
{
    $out = [];
    foreach (preg_split('/\R/', $lines) as $line) {
        if (trim($line) === '') {
            continue;
        }
        [$a, $b] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        $out[] = [$a, $b];
    }
    return $out;
}

/** Resolve blocks for the public website (dynamic data, image URLs, reusable sections). */
function resolve_blocks(array $blocks, int $depth = 0): array
{
    require_once APP_ROOT . '/includes/catalog.php';
    $types = block_types();
    $out = [];
    foreach ($blocks as $b) {
        $type = is_array($b) ? (string) ($b['type'] ?? '') : '';
        if (empty($b['visible']) || !isset($types[$type])) {
            continue;
        }
        $d = is_array($b['data'] ?? null) ? $b['data'] : [];
        // Defence in depth: stored rows are re-checked when rendered (a DB import or restore skips
        // clean_blocks), so rich text is re-sanitised and links re-validated every time.
        foreach ($types[$type][1] as $key => [, $ftype]) {
            if ($ftype === 'html') {
                $d[$key] = sanitize_html((string) ($d[$key] ?? ''));
            } elseif ($ftype === 'url') {
                $d[$key] = block_url((string) ($d[$key] ?? ''));
            }
        }
        switch ($type) {
            case 'reusable':
                if ($depth === 0 && ($rs = db_value('SELECT blocks FROM reusable_sections WHERE id = ?', [(int) ($d['section_id'] ?? 0)]))) {
                    array_push($out, ...resolve_blocks(json_decode_array($rs), 1));
                }
                continue 2;
            case 'hero':
            case 'image':
                $d['image_url'] = public_file_url($d['image'] ?? '') ?: null;
                unset($d['image']);
                break;
            case 'video':
                // Same parser as admin/videos.php: watch, youtu.be, shorts, embed and live links
                require_once APP_ROOT . '/includes/media.php';
                $d['video_id'] = youtube_video_id((string) ($d['youtube_url'] ?? ''));
                unset($d['youtube_url']);
                break;
            case 'buttons':
            case 'features':
            case 'stats':
            case 'faqs':
                $d['items'] = block_pairs((string) ($d['items'] ?? ''));
                if ($b['type'] === 'buttons') {
                    $d['items'] = array_values(array_filter($d['items'], fn($p) => preg_match('#^(/(?!/)|https://)#', $p[1])));
                }
                break;
            case 'course_grid':
                $cat = (string) ($d['category'] ?? '');
                $d['courses'] = $cat !== ''
                    ? fetch_course_cards('(cat.slug = ? OR cat.parent_id = (SELECT id FROM course_categories WHERE slug = ?))', [$cat, $cat], 'c.is_featured DESC, c.sort_order', (int) ($d['limit'] ?? 4))
                    : fetch_course_cards('1=1', [], 'c.is_featured DESC, c.sort_order', (int) ($d['limit'] ?? 4));
                break;
            case 'category_grid':
                $d['categories'] = array_values(array_filter(fetch_course_categories(), fn($c) => $c['parent_id'] === null));
                break;
            case 'testimonials':
                $d['items'] = db_all("SELECT name, exam_name, content, rating FROM testimonials WHERE status = 'approved' AND type = 'testimonial' ORDER BY sort_order LIMIT " . max(1, min(12, (int) ($d['limit'] ?? 3))));
                break;
        }
        $out[] = ['type' => $b['type'], 'data' => $d];
    }
    return $out;
}
