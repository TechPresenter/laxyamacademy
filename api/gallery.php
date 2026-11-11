<?php
/**
 * Public photo & video gallery (read-only; albums are managed in admin/gallery.php).
 *
 *   GET gallery.php?action=albums             active albums that have at least one active item
 *   GET gallery.php?action=album&slug=<slug>  one active album + its active items (+ a few other albums)
 *
 * Responses are cached like the CMS (cache_remember); any admin save clears the cache via audit_log().
 */
declare(strict_types=1);

require __DIR__ . '/config.php';

require_method('GET');

match ($_GET['action'] ?? 'albums') {
    'albums' => api_ok(cache_remember('gallery_albums', 300, fn() => ['items' => gallery_albums_list()]), 200, true),
    'album' => gallery_album_action(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function gallery_album_action(): never
{
    $slug = query_str('slug', 180);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        api_error('Album not found.', 404, 'not_found');
    }
    // Look the album up BEFORE the cache: only existing active albums get a cache file, keyed by id
    // (a cache file per guessed slug would let anyone fill the disk / inode quota).
    try {
        $id = (int) db_value('SELECT id FROM gallery_albums WHERE slug = ? AND is_active = 1', [$slug]);
    } catch (PDOException $e) {
        error_log('[laxyam] gallery: ' . $e->getMessage()); // tables not installed yet
        $id = 0;
    }
    $data = $id ? cache_remember('gallery_album_' . $id, 300, fn() => gallery_album_data($id)) : null;
    if (!$data) {
        api_error('Album not found.', 404, 'not_found');
    }
    api_ok($data, 200, true);
}

/**
 * SQL (album alias `a`) for the cover to show: the chosen cover_image_path, unless it is the file of one of the
 * album's HIDDEN items - a hidden photo must not stay public as the cover. NULL means "use the first visible photo".
 * (index.php uses the same rule for og:image.)
 */
function gallery_cover_sql(): string
{
    return 'CASE WHEN a.cover_image_path IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gallery_items h
              WHERE h.album_id = a.id AND h.is_active = 0 AND a.cover_image_path IN (h.image_path, h.thumb_path))
            THEN a.cover_image_path END';
}

/** YouTube's own thumbnail for a video id (used when an album only has videos). */
function gallery_youtube_thumb(string $id): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
}

/**
 * Album cards. Cover: the chosen cover image, else the album's first active photo, else the first video's
 * YouTube thumbnail. Albums without any active item are not listed (nothing to show yet).
 */
function gallery_albums_list(?int $exceptId = null, int $limit = 200): array
{
    try {
        $rows = db_all(
            "SELECT a.id, a.title, a.slug, a.description, " . gallery_cover_sql() . " AS cover_image_path, a.event_date, a.updated_at,
                    SUM(i.type = 'image') AS photo_count, SUM(i.type = 'youtube') AS video_count,
                    (SELECT COALESCE(fi.thumb_path, fi.image_path) FROM gallery_items fi
                      WHERE fi.album_id = a.id AND fi.is_active = 1 AND fi.type = 'image' ORDER BY fi.sort_order, fi.id LIMIT 1) AS first_image,
                    (SELECT fv.youtube_id FROM gallery_items fv
                      WHERE fv.album_id = a.id AND fv.is_active = 1 AND fv.type = 'youtube' ORDER BY fv.sort_order, fv.id LIMIT 1) AS first_video
               FROM gallery_albums a
               JOIN gallery_items i ON i.album_id = a.id AND i.is_active = 1
              WHERE a.is_active = 1" . ($exceptId ? ' AND a.id <> ' . (int) $exceptId : '') . "
              GROUP BY a.id, a.title, a.slug, a.description, a.cover_image_path, a.event_date, a.updated_at, a.sort_order, a.created_at
              ORDER BY a.sort_order, COALESCE(a.event_date, DATE(a.created_at)) DESC, a.id DESC
              LIMIT " . max(1, min(200, $limit))
        );
    } catch (PDOException $e) {
        // Tables not created yet (Database Updates not run on this server): behave like an empty gallery.
        error_log('[laxyam] gallery: ' . $e->getMessage());
        return [];
    }
    return array_map('gallery_album_card', $rows);
}

function gallery_album_card(array $a): array
{
    $cover = public_file_url($a['cover_image_path'] ?: $a['first_image']);
    if (!$cover && $a['first_video']) {
        $cover = gallery_youtube_thumb($a['first_video']);
    }
    return [
        'id' => (int) $a['id'],
        'title' => $a['title'],
        'slug' => $a['slug'],
        'description' => ($a['description'] ?? '') !== '' ? $a['description'] : null,
        'event_date' => $a['event_date'],
        'cover_url' => $cover,
        'photo_count' => (int) $a['photo_count'],
        'video_count' => (int) $a['video_count'],
        'url' => '/gallery/' . $a['slug'],
        'updated_at' => iso_datetime($a['updated_at']),
    ];
}

/** One active album (by id) with its active items, or null. */
function gallery_album_data(int $id): ?array
{
    try {
        $a = db_one('SELECT a.id, a.title, a.slug, a.description, ' . gallery_cover_sql() . ' AS cover_image_path, a.event_date, a.updated_at
                       FROM gallery_albums a WHERE a.id = ? AND a.is_active = 1', [$id]);
    } catch (PDOException $e) {
        error_log('[laxyam] gallery: ' . $e->getMessage());
        return null;
    }
    if (!$a) {
        return null;
    }
    $items = [];
    foreach (db_all('SELECT id, type, image_path, thumb_path, width, height, youtube_id, caption, alt_text FROM gallery_items
                     WHERE album_id = ? AND is_active = 1 ORDER BY sort_order, id', [$a['id']]) as $i) {
        $caption = ($i['caption'] ?? '') !== '' ? $i['caption'] : null;
        if ($i['type'] === 'youtube') {
            if (!preg_match('/^[\w-]{11}$/', (string) $i['youtube_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $i['id'], 'type' => 'youtube', 'youtube_id' => $i['youtube_id'],
                'thumb_url' => gallery_youtube_thumb($i['youtube_id']), 'image_url' => null,
                'width' => 480, 'height' => 360, // hqdefault.jpg size (4:3 with letterbox; the site crops it to 16:9)
                'caption' => $caption, 'alt' => ($i['alt_text'] ?? '') !== '' ? $i['alt_text'] : ($caption ?? 'Video: ' . $a['title']),
            ];
            continue;
        }
        $full = public_file_url($i['image_path']);
        if (!$full) {
            continue;
        }
        $items[] = [
            'id' => (int) $i['id'], 'type' => 'image', 'youtube_id' => null,
            'image_url' => $full, 'thumb_url' => public_file_url($i['thumb_path']) ?? $full,
            'width' => $i['width'] ? (int) $i['width'] : null, 'height' => $i['height'] ? (int) $i['height'] : null,
            'caption' => $caption, 'alt' => ($i['alt_text'] ?? '') !== '' ? $i['alt_text'] : ($caption ?? 'Photo from ' . $a['title']),
        ];
    }
    $photos = count(array_filter($items, fn($x) => $x['type'] === 'image'));
    $firstImage = current(array_filter($items, fn($x) => $x['type'] === 'image')) ?: null;
    $firstVideo = current(array_filter($items, fn($x) => $x['type'] === 'youtube')) ?: null;
    return [
        'album' => [
            'id' => (int) $a['id'],
            'title' => $a['title'],
            'slug' => $a['slug'],
            'description' => ($a['description'] ?? '') !== '' ? $a['description'] : null,
            'event_date' => $a['event_date'],
            'cover_url' => public_file_url($a['cover_image_path']) ?? ($firstImage['thumb_url'] ?? ($firstVideo['thumb_url'] ?? null)),
            'photo_count' => $photos,
            'video_count' => count($items) - $photos,
            'share_url' => app_url('gallery/' . $a['slug']),
            'updated_at' => iso_datetime($a['updated_at']),
        ],
        'items' => $items,
        'more' => gallery_albums_list((int) $a['id'], 3),
    ];
}
