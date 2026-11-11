<?php
/**
 * Learning resource library.
 *
 *   GET resources.php?action=list&q=&category=&page=
 *   GET resources.php?action=detail&slug=
 *   GET resources.php?action=download&slug=     authorised file stream (or redirect for external links)
 *   GET resources.php?action=mine               resources linked to the student's courses + recent downloads
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';

require_method('GET');

const RESOURCE_COLUMNS = "r.id, r.title, r.slug, r.description, r.thumbnail_path, r.file_type, r.file_size, r.access_type,
    r.course_id, r.download_count, r.published_at, r.is_demo, r.file_path, r.external_url,
    rc.name AS category_name, rc.slug AS category_slug, rc.icon AS category_icon, rc.color AS category_color,
    c.slug AS course_slug, c.title AS course_title";
const RESOURCE_FROM = "FROM learning_resources r
    JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
    LEFT JOIN courses c ON c.id = r.course_id";
/** Published and not scheduled for later (alias r). Used by every public query. */
const RESOURCE_VISIBLE = "r.status = 'published' AND (r.published_at IS NULL OR r.published_at <= NOW())";

match ($_GET['action'] ?? '') {
    'list' => resource_list(),
    'detail' => resource_detail(),
    'download' => download(),
    'mine' => mine(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/**
 * Why a resource is locked for this viewer (null when it can be opened):
 * login_required (guest, registered/enrolled resource) | enrolment_required (logged in, not enrolled).
 */
function resource_lock_reason(array $r, ?array $user): ?string
{
    if (content_access_allowed($user, $r['access_type'], $r['course_id'] ? (int) $r['course_id'] : null)) {
        return null;
    }
    return $user ? 'enrolment_required' : 'login_required';
}

function format_resource(array $r, ?array $user): array
{
    $reason = resource_lock_reason($r, $user);
    return [
        'id' => (int) $r['id'],
        'title' => $r['title'],
        'slug' => $r['slug'],
        'description' => $r['description'],
        'thumbnail_url' => public_file_url($r['thumbnail_path']),
        'file_type' => $r['external_url'] && !$r['file_path'] ? 'link' : $r['file_type'],
        'file_size' => $r['file_size'] !== null ? (int) $r['file_size'] : null,
        'access_type' => $r['access_type'],
        'can_access' => $reason === null,
        'locked' => $reason !== null,
        'lock_reason' => $reason,
        'lock_message' => match ($reason) {
            'login_required' => $r['access_type'] === 'enrolled' ? 'Log in with an account enrolled in the linked course to download.' : 'Log in (free) to download this resource.',
            'enrolment_required' => $r['course_title'] ? 'Available to students of “' . $r['course_title'] . '”.' : 'Available to enrolled students only.',
            default => null,
        },
        // Server-checked download link (the file path itself is never exposed); null while locked or when no file/link exists.
        'download_url' => $reason === null && ($r['file_path'] || $r['external_url'])
            ? app_url('api/resources.php?action=download&slug=' . rawurlencode($r['slug'])) : null,
        'downloads' => (int) $r['download_count'],
        'published_at' => iso_datetime($r['published_at']),
        'category' => ['name' => $r['category_name'], 'slug' => $r['category_slug'], 'icon' => $r['category_icon'], 'color' => $r['category_color']],
        'course' => $r['course_slug'] ? ['slug' => $r['course_slug'], 'title' => $r['course_title']] : null,
        'is_demo' => (bool) $r['is_demo'],
    ];
}

function resource_list(): never
{
    $where = [RESOURCE_VISIBLE];
    $params = [];
    if (($q = query_str('q', 100)) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(r.title LIKE ? OR r.description LIKE ?)';
        array_push($params, $like, $like);
    }
    if ($cats = query_slugs('category')) {
        $where[] = 'rc.slug IN (' . db_placeholders(count($cats)) . ')';
        array_push($params, ...$cats);
    }
    $access = query_str('access', 20);
    if (in_array($access, ['free', 'registered', 'enrolled'], true)) {
        $where[] = 'r.access_type = ?';
        $params[] = $access;
    }
    $page = query_int('page', 1, 1, 500);
    $per = 12;
    $sqlWhere = implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*) ' . RESOURCE_FROM . " WHERE {$sqlWhere}", $params);
    $rows = db_all('SELECT ' . RESOURCE_COLUMNS . ' ' . RESOURCE_FROM . " WHERE {$sqlWhere} ORDER BY r.published_at DESC, r.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
    $user = current_user();
    api_ok([
        'items' => array_map(fn($r) => format_resource($r, $user), $rows),
        'pagination' => pagination_meta($total, $page, $per),
        'categories' => db_all(
            "SELECT rc.name, rc.slug, rc.subtitle, rc.icon, rc.color,
                    (SELECT COUNT(*) FROM learning_resources r WHERE r.category_id = rc.id AND " . RESOURCE_VISIBLE . ") AS count
             FROM resource_categories rc WHERE rc.is_active = 1 ORDER BY rc.sort_order, rc.name"
        ),
    ]);
}

function find_resource(string $slug): array
{
    $r = preg_match('/^[a-z0-9-]{1,220}$/', $slug)
        ? db_one('SELECT ' . RESOURCE_COLUMNS . ', r.seo_title, r.seo_description ' . RESOURCE_FROM . ' WHERE r.slug = ? AND ' . RESOURCE_VISIBLE, [$slug])
        : null;
    if (!$r) {
        api_error('Resource not found.', 404, 'not_found');
    }
    return $r;
}

function resource_detail(): never
{
    $r = find_resource(query_str('slug', 220));
    $user = current_user();
    $related = db_all('SELECT ' . RESOURCE_COLUMNS . ' ' . RESOURCE_FROM . ' WHERE ' . RESOURCE_VISIBLE . ' AND rc.slug = ? AND r.id <> ? ORDER BY r.published_at DESC LIMIT 4', [$r['category_slug'], $r['id']]);
    api_ok(format_resource($r, $user) + [
        'description_html' => text_to_html($r['description']),
        'seo' => ['title' => $r['seo_title'] ?: $r['title'] . ' | Laxyam Academy', 'description' => $r['seo_description'] ?: mb_substr(strip_tags((string) $r['description']), 0, 160)],
        'related' => array_map(fn($x) => format_resource($x, $user), $related),
    ]);
}

function download(): never
{
    $r = find_resource(query_str('slug', 220));
    $user = current_user();
    if (!content_access_allowed($user, $r['access_type'], $r['course_id'] ? (int) $r['course_id'] : null)) {
        api_error($user ? 'This resource is available to enrolled students only.' : 'Please log in to download this resource.', $user ? 403 : 401, $user ? 'not_enrolled' : 'unauthenticated');
    }
    $path = private_file_path($r['file_path']);
    if (!$path && !($r['external_url'] && preg_match('#^https://#i', $r['external_url']))) {
        api_error('This file is not available yet.', 404, 'not_found');
    }
    db_query('UPDATE learning_resources SET download_count = download_count + 1 WHERE id = ?', [$r['id']]);
    db_query('INSERT INTO resource_downloads (resource_id, user_id, ip_hash) VALUES (?, ?, ?)',
        [$r['id'], $user['id'] ?? null, hash('sha256', client_ip() . date('Y-m-d'))]);
    if (!$path) {
        header('Location: ' . $r['external_url'], true, 302);
        exit;
    }
    stream_file($path, $r['slug'] . '.' . pathinfo($path, PATHINFO_EXTENSION), false);
}

function mine(): never
{
    $user = require_student();
    $courseIds = accessible_course_ids((int) $user['id']);
    $linked = $courseIds ? db_all(
        'SELECT ' . RESOURCE_COLUMNS . ' ' . RESOURCE_FROM . ' WHERE ' . RESOURCE_VISIBLE . ' AND r.course_id IN (' . db_placeholders(count($courseIds)) . ') ORDER BY r.published_at DESC LIMIT 50',
        $courseIds
    ) : [];
    $recent = db_all(
        'SELECT ' . RESOURCE_COLUMNS . ', MAX(d.downloaded_at) AS last_download ' . RESOURCE_FROM . "
         JOIN resource_downloads d ON d.resource_id = r.id AND d.user_id = ?
         WHERE " . RESOURCE_VISIBLE . ' GROUP BY r.id ORDER BY last_download DESC LIMIT 20',
        [$user['id']]
    );
    api_ok([
        'course_resources' => array_map(fn($r) => format_resource($r, $user), $linked),
        'recent_downloads' => array_map(fn($r) => format_resource($r, $user), $recent),
    ]);
}
