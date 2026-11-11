<?php
// Photo & video gallery: albums (cover, event date, order, visibility) and their photos / YouTube videos,
// shown on the website at /gallery and /gallery/<album>. Photos are re-encoded to WebP on upload
// (full size for the lightbox + a grid-size thumbnail); phone photos are turned upright first.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/media.php'; // youtube_video_id()

$me = require_staff_page();
require_permission('cms.manage');

const GALLERY_FOLDER = 'gallery';
const GALLERY_FULL_PX = 2000;  // longest side of the lightbox image
const GALLERY_THUMB_PX = 900;  // longest side of the grid thumbnail
const GALLERY_COVER_PX = 1400;
const GALLERY_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/** "40M" style php.ini sizes -> bytes (0 / empty = no limit). */
function gallery_ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    $n = (int) $v;
    $n = match (strtolower(substr($v, -1))) { 'g' => $n * 1_073_741_824, 'm' => $n * 1_048_576, 'k' => $n * 1024, default => $n };
    return $n > 0 ? $n : PHP_INT_MAX;
}

function gallery_mb(int $bytes): string
{
    return $bytes >= PHP_INT_MAX ? 'no limit' : rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.') . ' MB';
}

/** Per-photo size limit, photos per request and the request size limit of this server. */
function gallery_limits(): array
{
    $maxBytes = min(15 * 1_048_576, upload_limit_bytes(), gallery_ini_bytes('upload_max_filesize'));
    $maxFiles = max(1, min(20, (int) ini_get('max_file_uploads') ?: 20));
    return [$maxBytes, $maxFiles, gallery_ini_bytes('post_max_size')];
}

function gallery_is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || ($_POST['_ajax'] ?? '') === '1';
}

function gallery_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Invisible/control characters removed from admin text: every \p{C} EXCEPT the joiners that real text needs -
 * ZWNJ/ZWJ (U+200C/U+200D: Devanagari conjuncts such as क्‍ष, emoji sequences such as 👨‍👩‍👧) and the emoji tag
 * characters (U+E0020-E007F: subdivision flags). Used inside a character class.
 */
const GALLERY_KEEP_FORMAT = '\x{200C}\x{200D}\x{E0020}-\x{E007F}';

/** Trimmed single-line text without control characters; null when empty. */
function gallery_text(mixed $v, int $max): ?string
{
    if (!is_scalar($v)) {
        return null;
    }
    $s = trim((string) preg_replace('/[^\P{C}' . GALLERY_KEEP_FORMAT . ']+/u', ' ', (string) $v));
    $s = mb_substr((string) preg_replace('/\s+/u', ' ', $s), 0, $max);
    return $s === '' ? null : $s;
}

function gallery_upload_error(int $code, int $maxBytes): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is larger than ' . gallery_mb($maxBytes) . '.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose an image to upload.',
        default => 'The server could not receive this file (error ' . $code . ').',
    };
}

/** $_FILES['files'] (multiple) -> list of single-file arrays. */
function gallery_files(?array $f): array
{
    if (!$f || !is_array($f['name'] ?? null)) {
        return [];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => (string) $f['name'][$i], 'type' => (string) $f['type'][$i], 'tmp_name' => (string) $f['tmp_name'][$i], 'error' => (int) $f['error'][$i], 'size' => (int) $f['size'][$i]];
    }
    return $out;
}

/** Phone cameras store photos sideways + an EXIF "Orientation" flag: rotate the uploaded JPEG upright in place. */
function gallery_fix_orientation(string $tmp): void
{
    if (!function_exists('exif_read_data') || (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'image/jpeg') {
        return;
    }
    $o = (int) ((@exif_read_data($tmp) ?: [])['Orientation'] ?? 1);
    if (!in_array($o, [3, 6, 8], true)) {
        return;
    }
    $img = @imagecreatefromjpeg($tmp);
    $rot = $img ? imagerotate($img, [3 => 180, 6 => -90, 8 => 90][$o], 0) : false;
    if ($rot) {
        imagejpeg($rot, $tmp, 92);
    }
}

/** Grid-size WebP next to a stored full image (null when the image is already small). */
function gallery_make_thumb(string $path, int $w, int $h): ?string
{
    if (max($w, $h) <= GALLERY_THUMB_PX * 1.15 || !function_exists('imagecreatefromwebp')) {
        return null;
    }
    $src = @imagecreatefromwebp(APP_ROOT . '/' . $path);
    if (!$src) {
        return null;
    }
    $scale = GALLERY_THUMB_PX / max($w, $h);
    $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $thumb = substr($path, 0, -5) . '-t.webp';
    return imagewebp($dst, APP_ROOT . '/' . $thumb, 78) ? $thumb : null;
}

/** Validate + store one uploaded photo. Returns [path, thumb|null, width, height]. Throws InvalidArgumentException. */
function gallery_store_photo(array $file, int $maxBytes, int $px = GALLERY_FULL_PX, bool $thumb = true): array
{
    if (uploaded_file_ok($file)) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, GALLERY_MIME, true)) {
            throw new InvalidArgumentException('Please upload a JPG, PNG, WebP or GIF image.');
        }
        if ($file['size'] <= $maxBytes) {
            gallery_fix_orientation($file['tmp_name']);
        }
    }
    $path = store_image_upload($file, GALLERY_FOLDER, $px, $px, false, $maxBytes);
    [$w, $h] = @getimagesize(APP_ROOT . '/' . $path) ?: [0, 0];
    return [$path, $thumb && $w && $h ? gallery_make_thumb($path, (int) $w, (int) $h) : null, (int) $w ?: null, (int) $h ?: null];
}

/** True while any website content still points at an uploaded file (then it is kept on delete). */
function gallery_path_in_use(string $path): bool
{
    if (db_value('SELECT 1 FROM gallery_items WHERE image_path = ? OR thumb_path = ? LIMIT 1', [$path, $path])
        || db_value('SELECT 1 FROM gallery_albums WHERE cover_image_path = ? LIMIT 1', [$path])) {
        return true;
    }
    // Same places the Media Library checks before deleting an image.
    foreach ([['courses', 'thumbnail_path'], ['blog_posts', 'featured_image_path'], ['instructors', 'photo_path'], ['testimonials', 'photo_path'],
              ['learning_resources', 'thumbnail_path'], ['seo_pages', 'og_image'], ['users', 'avatar_path'], ['popups', 'image_path'],
              ['promo_banners', 'image_path'], ['media_videos', 'thumbnail_path'], ['exam_questions', 'image_path'], ['site_settings', 'setting_value']] as [$table, $col]) {
        try {
            if (db_value("SELECT 1 FROM {$table} WHERE {$col} = ? LIMIT 1", [$path])) {
                return true;
            }
        } catch (PDOException $e) {
            // table from a newer migration missing on this database
        }
    }
    $likes = ['%' . addcslashes($path, '%_\\') . '%', '%' . addcslashes(str_replace('/', '\\/', $path), '%_\\') . '%'];
    foreach ([['cms_sections', 'content'], ['pages', 'blocks'], ['reusable_sections', 'blocks'], ['blog_posts', 'content']] as [$table, $col]) {
        try {
            if (db_value("SELECT 1 FROM {$table} WHERE {$col} LIKE ? OR {$col} LIKE ? LIMIT 1", $likes)) {
                return true;
            }
        } catch (PDOException $e) {
        }
    }
    return false;
}

/** Delete a gallery upload unless something still uses it. Call after the referencing rows are gone. */
function gallery_unlink(?string $path): void
{
    if (!$path || !preg_match('#^uploads/' . GALLERY_FOLDER . '/[A-Za-z0-9._-]+\.webp$#', $path) || gallery_path_in_use($path)) {
        return;
    }
    $full = APP_ROOT . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Delete items of an album (rows first, then their files). Returns how many were deleted. */
function gallery_delete_items(int $albumId, array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return 0;
    }
    $rows = db_all('SELECT id, image_path, thumb_path FROM gallery_items WHERE album_id = ? AND id IN (' . db_placeholders(count($ids)) . ')', [$albumId, ...$ids]);
    if (!$rows) {
        return 0;
    }
    $paths = array_values(array_unique(array_filter(array_merge(array_column($rows, 'image_path'), array_column($rows, 'thumb_path')))));
    db_transaction(function () use ($rows, $paths) {
        $found = array_map('intval', array_column($rows, 'id'));
        db_query('DELETE FROM gallery_items WHERE id IN (' . db_placeholders(count($found)) . ')', $found);
        if ($paths) {
            // An album whose cover was one of these photos falls back to its first photo.
            db_query('UPDATE gallery_albums SET cover_image_path = NULL WHERE cover_image_path IN (' . db_placeholders(count($paths)) . ')', $paths);
        }
    });
    foreach ($paths as $p) {
        gallery_unlink($p);
    }
    return count($rows);
}

/**
 * A hidden photo must not stay public as the album cover: when one of $rows (items being hidden) is the chosen
 * cover, the album goes back to its first visible photo. Returns true when the cover was reset.
 */
function gallery_uncover_hidden(array $album, array $rows): bool
{
    $cover = (string) ($album['cover_image_path'] ?? '');
    foreach ($cover === '' ? [] : $rows as $r) {
        if ($r['type'] === 'image' && in_array($cover, array_filter([$r['image_path'], $r['thumb_path']]), true)) {
            db_query('UPDATE gallery_albums SET cover_image_path = NULL WHERE id = ? AND cover_image_path = ?', [$album['id'], $cover]);
            return true;
        }
    }
    return false;
}

/**
 * An album now owns /gallery/<slug>: drop any redirect FROM that address (left by an earlier rename of another
 * album), otherwise index.php would keep sending visitors of this album to the old one.
 */
function gallery_claim_address(string $slug): void
{
    db_query('DELETE FROM redirects WHERE from_path = ?', ['/gallery/' . $slug]);
}

/** Move an item one place up (-1) or down (+1); ties in the order numbers are renumbered 1..n first. */
function gallery_move(int $albumId, int $itemId, int $dir): bool
{
    $rows = db_all('SELECT id, sort_order FROM gallery_items WHERE album_id = ? ORDER BY sort_order, id', [$albumId]);
    $ids = array_map('intval', array_column($rows, 'id'));
    $sorts = array_map('intval', array_column($rows, 'sort_order'));
    $pos = array_search($itemId, $ids, true);
    if ($pos === false || !isset($ids[$pos + $dir])) {
        return false;
    }
    if (count(array_unique($sorts)) !== count($sorts)) {
        foreach ($ids as $i => $id) {
            if ($sorts[$i] !== $i + 1) {
                db_query('UPDATE gallery_items SET sort_order = ? WHERE id = ?', [$i + 1, $id]);
            }
        }
        $sorts = range(1, count($ids));
    }
    $to = $pos + $dir;
    db_query('UPDATE gallery_items SET sort_order = ? WHERE id = ?', [$sorts[$to], $ids[$pos]]);
    db_query('UPDATE gallery_items SET sort_order = ? WHERE id = ?', [$sorts[$pos], $ids[$to]]);
    return true;
}

function gallery_next_sort(int $albumId): int
{
    return min(32000, (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM gallery_items WHERE album_id = ?', [$albumId]) + 1);
}

function gallery_album_or_fail(int $id): array
{
    $a = $id ? db_one('SELECT * FROM gallery_albums WHERE id = ?', [$id]) : null;
    if (!$a) {
        if (gallery_is_ajax()) {
            gallery_json(['ok' => false, 'error' => 'Album not found. It may have been deleted.'], 404);
        }
        flash('error', 'Album not found. It may have been deleted.');
        redirect_to('gallery.php');
    }
    return $a;
}

/** Validate the album form. Returns [values, errors]. */
function gallery_validate_album(array $in, ?array $old): array
{
    $v = [
        'title' => gallery_text($in['title'] ?? '', 160) ?? '',
        'slug' => strtolower(trim((string) ($in['slug'] ?? ''))),
        'description' => trim((string) preg_replace('/[^\P{C}\n' . GALLERY_KEEP_FORMAT . ']/u', '', (string) ($in['description'] ?? ''))),
        'event_date' => trim((string) ($in['event_date'] ?? '')),
        'sort_order' => trim((string) ($in['sort_order'] ?? '0')),
        'is_active' => !empty($in['is_active']) ? 1 : 0,
    ];
    $err = [];
    if ($v['title'] === '') {
        $err['title'] = 'Give the album a title, e.g. "Annual Day 2026".';
    }
    if ($v['slug'] === '') {
        $base = substr(slugify($v['title'] ?: 'album'), 0, 170);
        $slug = $base;
        for ($n = 2; db_value('SELECT 1 FROM gallery_albums WHERE slug = ? AND id <> ?', [$slug, (int) ($old['id'] ?? 0)]); $n++) {
            $slug = $base . '-' . $n;
        }
        $v['slug'] = $slug;
    } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v['slug']) || strlen($v['slug']) > 180) {
        $err['slug'] = 'Use lowercase letters, numbers and hyphens only (max 180).';
    } elseif (db_value('SELECT 1 FROM gallery_albums WHERE slug = ? AND id <> ?', [$v['slug'], (int) ($old['id'] ?? 0)])) {
        $err['slug'] = 'Another album already uses this web address.';
    }
    if (mb_strlen($v['description']) > 2000) {
        $err['description'] = 'Keep the description under 2,000 characters.';
    }
    if ($v['event_date'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v['event_date']);
        if (!$d || $d->format('Y-m-d') !== $v['event_date'] || (int) $d->format('Y') < 1990 || (int) $d->format('Y') > (int) date('Y') + 5) {
            $err['event_date'] = 'Enter a valid date.';
        }
    }
    $sort = filter_var($v['sort_order'] === '' ? '0' : $v['sort_order'], FILTER_VALIDATE_INT);
    if ($sort === false || $sort < -999 || $sort > 9999) {
        $err['sort_order'] = 'Use a whole number between -999 and 9999.';
    } else {
        $v['sort_order'] = $sort;
    }
    return [$v, $err];
}

/** Public URL of an album (absolute). */
function gallery_public_url(string $slug): string
{
    return app_url('gallery/' . $slug);
}

/** Thumbnail URL for an admin list/grid cell. */
function gallery_thumb_url(array $item): ?string
{
    if ($item['type'] === 'youtube') {
        return 'https://i.ytimg.com/vi/' . rawurlencode((string) $item['youtube_id']) . '/mqdefault.jpg';
    }
    return public_file_url($item['thumb_path'] ?: $item['image_path']);
}

// ------------------------------------------------------------------
// Database not updated yet on this server
// ------------------------------------------------------------------
$ready = (bool) db_value("SHOW TABLES LIKE 'gallery_albums'") && (bool) db_value("SHOW TABLES LIKE 'gallery_items'");
if (!$ready) {
    admin_start('Photo Gallery', null, 'media', ['head' => true]);
    echo '<div class="alert alert-warning">' . icon('alert') . '<span>The gallery tables are not installed on this database yet. Open <a href="updates.php">Database Updates</a> and apply the pending update, then come back.</span></div>';
    admin_end();
    exit;
}

[$maxBytes, $maxFiles, $postMax] = gallery_limits();
$action = (string) ($_GET['action'] ?? '');
$formState = null; // re-rendered album form after a failed save

// ------------------------------------------------------------------
// POST handlers
// ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Bigger than post_max_size: PHP drops the whole request (including the CSRF token), so explain instead of failing.
    $msg = 'That upload is larger than this server accepts at once (' . gallery_mb($postMax) . '). Please upload fewer photos at a time.';
    if (gallery_is_ajax()) {
        gallery_json(['ok' => false, 'error' => $msg], 413);
    }
    flash('error', $msg);
    redirect_to(url_with([]));
}

if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');

    // ---------- Create / update an album ----------
    if ($act === 'save_album') {
        $id = (int) ($_POST['id'] ?? 0);
        $old = $id ? gallery_album_or_fail($id) : null;
        [$v, $errors] = gallery_validate_album($_POST, $old);
        $cover = $_FILES['cover'] ?? null;
        $coverPath = null;
        if (!$errors && $cover && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($cover['error'] !== UPLOAD_ERR_OK) {
                $errors['cover'] = gallery_upload_error((int) $cover['error'], $maxBytes);
            } else {
                try {
                    $coverPath = gallery_store_photo($cover, $maxBytes, GALLERY_COVER_PX, false)[0];
                } catch (InvalidArgumentException | RuntimeException $e) {
                    $errors['cover'] = $e->getMessage();
                }
            }
        }
        if ($errors) {
            $formState = ['values' => $v, 'errors' => $errors];
            $action = $id ? 'edit' : 'new';
            $_GET['id'] = (string) $id;
        } else {
            $newCover = $coverPath ?? (!empty($_POST['remove_cover']) ? null : ($old['cover_image_path'] ?? null));
            $params = [$v['title'], $v['slug'], $v['description'] !== '' ? $v['description'] : null, $newCover, $v['event_date'] ?: null, $v['sort_order'], $v['is_active']];
            if ($old) {
                db_query('UPDATE gallery_albums SET title = ?, slug = ?, description = ?, cover_image_path = ?, event_date = ?, sort_order = ?, is_active = ? WHERE id = ?', [...$params, $old['id']]);
                if ($old['cover_image_path'] && $old['cover_image_path'] !== $newCover) {
                    gallery_unlink($old['cover_image_path']); // kept when it is one of the album's photos
                }
                // Keep shared links working when the address changes.
                if ($old['slug'] !== $v['slug']) {
                    gallery_claim_address($v['slug']);
                    db_query('INSERT IGNORE INTO redirects (from_path, to_path, status_code) VALUES (?, ?, 301)', ['/gallery/' . $old['slug'], '/gallery/' . $v['slug']]);
                    db_query('UPDATE redirects SET to_path = ? WHERE to_path = ?', ['/gallery/' . $v['slug'], '/gallery/' . $old['slug']]);
                }
                audit_log('gallery_album_updated', 'gallery_albums', (int) $old['id'], ['title' => $v['title']]);
                flash('success', 'Album "' . $v['title'] . '" saved.' . ($old['slug'] !== $v['slug'] ? ' The old link now redirects to the new address.' : ''));
                redirect_to('gallery.php?album=' . (int) $old['id']);
            }
            // The address may have been freed by renaming another album (its old link redirects): it is this album's now.
            gallery_claim_address($v['slug']);
            db_query('INSERT INTO gallery_albums (title, slug, description, cover_image_path, event_date, sort_order, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [...$params, (int) $me['id']]);
            $newId = (int) db()->lastInsertId();
            audit_log('gallery_album_created', 'gallery_albums', $newId, ['title' => $v['title']]);
            flash('success', 'Album "' . $v['title'] . '" created. Now add photos and videos.');
            redirect_to('gallery.php?album=' . $newId);
        }
    }

    // ---------- Album list actions ----------
    if ($act === 'toggle_album') {
        $a = gallery_album_or_fail((int) ($_POST['id'] ?? 0));
        db_query('UPDATE gallery_albums SET is_active = 1 - is_active WHERE id = ?', [$a['id']]);
        audit_log('gallery_album_updated', 'gallery_albums', (int) $a['id'], ['is_active' => $a['is_active'] ? 0 : 1]);
        flash('success', '"' . $a['title'] . '" is now ' . ($a['is_active'] ? 'hidden from' : 'shown on') . ' the website.');
        redirect_to((string) ($_POST['back'] ?? '') === 'album' ? 'gallery.php?album=' . (int) $a['id'] : url_with([]));
    }
    if ($act === 'delete_album') {
        $a = gallery_album_or_fail((int) ($_POST['id'] ?? 0));
        $items = db_all('SELECT image_path, thumb_path FROM gallery_items WHERE album_id = ?', [$a['id']]);
        $paths = array_values(array_unique(array_filter([$a['cover_image_path'], ...array_column($items, 'image_path'), ...array_column($items, 'thumb_path')])));
        db_query('DELETE FROM gallery_albums WHERE id = ?', [$a['id']]); // items go with it (ON DELETE CASCADE)
        foreach ($paths as $p) {
            gallery_unlink($p);
        }
        audit_log('gallery_album_deleted', 'gallery_albums', (int) $a['id'], ['title' => $a['title'], 'items' => count($items)], 'warning');
        flash('success', 'Album "' . $a['title'] . '" and its ' . plural_n(count($items), 'item') . ' were deleted.');
        redirect_to('gallery.php');
    }

    // ---------- Photos: multi-file upload (plain form, or batches from the page script) ----------
    if ($act === 'upload') {
        $a = gallery_album_or_fail((int) ($_POST['album_id'] ?? 0));
        @set_time_limit(300);
        $files = gallery_files($_FILES['files'] ?? null);
        $results = [];
        $ok = 0;
        $next = gallery_next_sort((int) $a['id']);
        foreach ($files as $i => $f) {
            $name = mb_substr(basename(str_replace('\\', '/', $f['name'])), 0, 120);
            if ($i >= $maxFiles) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => 'Only ' . $maxFiles . ' photos can be sent at once.'];
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => gallery_upload_error($f['error'], $maxBytes)];
                continue;
            }
            try {
                [$path, $thumb, $w, $h] = gallery_store_photo($f, $maxBytes);
                db_query("INSERT INTO gallery_items (album_id, type, image_path, thumb_path, width, height, sort_order, created_by) VALUES (?, 'image', ?, ?, ?, ?, ?, ?)",
                    [$a['id'], $path, $thumb, $w, $h, $next, (int) $me['id']]);
                $next = min(32000, $next + 1);
                $ok++;
                $results[] = ['name' => $name, 'ok' => true, 'id' => (int) db()->lastInsertId()];
            } catch (InvalidArgumentException | RuntimeException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        if ($ok) {
            audit_log('gallery_photos_uploaded', 'gallery_albums', (int) $a['id'], ['count' => $ok]);
        }
        $failed = count(array_filter($results, fn($r) => !$r['ok']));
        if (gallery_is_ajax()) {
            // The page script sends several batches, then reloads: one combined message is shown then.
            $_SESSION['gallery_uploaded'][(int) $a['id']] = ($_SESSION['gallery_uploaded'][(int) $a['id']] ?? 0) + $ok;
            gallery_json(['ok' => true, 'uploaded' => $ok, 'failed' => $failed, 'results' => $results]);
        }
        if (!$files) {
            flash('error', 'Choose one or more photos to upload.');
        } else {
            $errs = array_map(fn($r) => $r['name'] . ': ' . $r['error'], array_filter($results, fn($r) => !$r['ok']));
            flash($errs ? ($ok ? 'warning' : 'error') : 'success', plural_n($ok, 'photo') . ' uploaded.' . ($errs ? ' Not uploaded - ' . implode(' · ', array_slice($errs, 0, 8)) . (count($errs) > 8 ? ' …' : '') : ''));
        }
        redirect_to('gallery.php?album=' . (int) $a['id'] . '#items');
    }

    // ---------- YouTube video ----------
    if ($act === 'add_video') {
        $a = gallery_album_or_fail((int) ($_POST['album_id'] ?? 0));
        $url = mb_substr(trim((string) ($_POST['url'] ?? '')), 0, 500);
        $caption = gallery_text($_POST['caption'] ?? '', 255);
        $vid = youtube_video_id($url);
        $back = 'gallery.php?album=' . (int) $a['id'];
        if (!$vid) {
            $_SESSION['gallery_video_old'] = ['url' => $url, 'caption' => (string) $caption];
            flash('error', 'Paste a YouTube video link, e.g. https://www.youtube.com/watch?v=… or https://youtu.be/… (Shorts and live links work too).');
            redirect_to($back . '#add-video');
        }
        if ($dupe = db_value("SELECT id FROM gallery_items WHERE album_id = ? AND type = 'youtube' AND youtube_id = ?", [$a['id'], $vid])) {
            flash('info', 'This video is already in the album.');
            redirect_to($back . '#item-' . (int) $dupe);
        }
        db_query("INSERT INTO gallery_items (album_id, type, youtube_id, caption, sort_order, created_by) VALUES (?, 'youtube', ?, ?, ?, ?)",
            [$a['id'], $vid, $caption, gallery_next_sort((int) $a['id']), (int) $me['id']]);
        $newId = (int) db()->lastInsertId();
        audit_log('gallery_video_added', 'gallery_albums', (int) $a['id'], ['youtube_id' => $vid]);
        flash('success', 'YouTube video added to the album.');
        redirect_to($back . '#item-' . $newId);
    }

    // ---------- Cover back to "first photo" ----------
    if ($act === 'cover_auto') {
        $a = gallery_album_or_fail((int) ($_POST['album_id'] ?? 0));
        db_query('UPDATE gallery_albums SET cover_image_path = NULL WHERE id = ?', [$a['id']]);
        gallery_unlink($a['cover_image_path']);
        audit_log('gallery_album_updated', 'gallery_albums', (int) $a['id'], ['cover' => 'auto']);
        flash('success', 'The album now uses its first photo as the cover.');
        redirect_to('gallery.php?album=' . (int) $a['id']);
    }

    // ---------- Items: captions / alt / order, then an optional per-item or bulk action ----------
    if ($act === 'items') {
        $a = gallery_album_or_fail((int) ($_POST['album_id'] ?? 0));
        $aid = (int) $a['id'];
        $byId = array_column(db_all('SELECT * FROM gallery_items WHERE album_id = ?', [$aid]), null, 'id');
        $changed = 0;
        foreach ((array) ($_POST['items'] ?? []) as $iid => $f) {
            $r = $byId[(int) $iid] ?? null;
            if (!$r || !is_array($f)) {
                continue;
            }
            $set = [];
            foreach (['caption' => 255, 'alt_text' => 160] as $col => $max) {
                $key = $col === 'alt_text' ? 'alt' : $col;
                if (array_key_exists($key, $f) && ($val = gallery_text($f[$key], $max)) !== ($r[$col] ?? null)) {
                    $set[$col] = $val;
                }
            }
            if (array_key_exists('sort', $f) && ($s = filter_var($f['sort'], FILTER_VALIDATE_INT)) !== false && max(-999, min(9999, $s)) !== (int) $r['sort_order']) {
                $set['sort_order'] = max(-999, min(9999, $s));
            }
            if ($set) {
                db_query('UPDATE gallery_items SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $r['id']]);
                $changed++;
            }
        }
        $notes = $changed ? [plural_n($changed, 'item') . ' updated.'] : [];
        $anchor = '#items';
        $op = (string) ($_POST['op'] ?? '');
        $bulk = (string) ($_POST['bulk'] ?? '');
        if (preg_match('/^(up|down|cover|toggle|delete):(\d+)$/', $op, $m) && isset($byId[(int) $m[2]])) {
            $item = $byId[(int) $m[2]];
            $anchor = '#item-' . (int) $item['id'];
            switch ($m[1]) {
                case 'up':
                case 'down':
                    if (gallery_move($aid, (int) $item['id'], $m[1] === 'up' ? -1 : 1)) {
                        $notes[] = 'Moved ' . $m[1] . '.';
                    }
                    break;
                case 'cover':
                    if ($item['type'] === 'image' && !(int) $item['is_active']) {
                        // A hidden photo would still be public as the cover (album card, link previews).
                        $notes[] = 'This photo is hidden - show it on the website first, then make it the cover.';
                    } elseif ($item['type'] === 'image') {
                        $path = $item['thumb_path'] ?: $item['image_path'];
                        db_query('UPDATE gallery_albums SET cover_image_path = ? WHERE id = ?', [$path, $aid]);
                        if ($a['cover_image_path'] && $a['cover_image_path'] !== $path) {
                            gallery_unlink($a['cover_image_path']); // an uploaded cover nobody uses any more
                        }
                        $notes[] = 'Album cover updated.';
                    }
                    break;
                case 'toggle':
                    db_query('UPDATE gallery_items SET is_active = 1 - is_active WHERE id = ?', [$item['id']]);
                    $notes[] = $item['is_active'] ? 'Item hidden from the website.' : 'Item shown on the website.';
                    if ($item['is_active'] && gallery_uncover_hidden($a, [$item])) {
                        $notes[] = 'It was the album cover, so the album now uses its first visible photo.';
                    }
                    break;
                case 'delete':
                    gallery_delete_items($aid, [(int) $item['id']]);
                    $notes[] = ($item['type'] === 'youtube' ? 'Video' : 'Photo') . ' deleted.';
                    $anchor = '#items';
                    break;
            }
        } elseif (in_array($bulk, ['delete', 'hide', 'show'], true)) {
            $ids = array_values(array_intersect(array_map('intval', (array) ($_POST['ids'] ?? [])), array_map('intval', array_keys($byId))));
            if (!$ids) {
                $notes[] = 'Select one or more items first.';
            } elseif ($bulk === 'delete') {
                $notes[] = plural_n(gallery_delete_items($aid, $ids), 'item') . ' deleted.';
            } else {
                db_query('UPDATE gallery_items SET is_active = ? WHERE album_id = ? AND id IN (' . db_placeholders(count($ids)) . ')', [$bulk === 'show' ? 1 : 0, $aid, ...$ids]);
                $notes[] = plural_n(count($ids), 'item') . ' ' . ($bulk === 'show' ? 'shown on' : 'hidden from') . ' the website.';
                if ($bulk === 'hide' && gallery_uncover_hidden($a, array_intersect_key($byId, array_flip($ids)))) {
                    $notes[] = 'The album cover was among them, so the album now uses its first visible photo.';
                }
            }
        }
        if ($changed || $op !== '' || $bulk !== '') {
            audit_log('gallery_items_updated', 'gallery_albums', $aid, array_filter(['edited' => $changed, 'op' => $op ?: null, 'bulk' => $bulk ?: null]), $bulk === 'delete' || str_starts_with($op, 'delete') ? 'warning' : 'info');
        }
        flash($notes ? 'success' : 'info', $notes ? implode(' ', $notes) : 'No changes to save.');
        redirect_to('gallery.php?album=' . $aid . $anchor);
    }
    if (!$formState) {
        redirect_to(url_with([]));
    }
}

// ------------------------------------------------------------------
// Views
// ------------------------------------------------------------------
$albumId = (int) ($_GET['album'] ?? 0);

/** Page-specific styles (scoped to .gal-admin; uses the admin theme tokens, so dark mode works). */
function gallery_styles(): void
{
    ?>
<style>
.gal-admin { --gal-r: 14px; }
.gal-admin .gal-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.gal-admin .gal-stat { display: flex; align-items: center; gap: 12px; padding: 16px 18px; }
.gal-admin .gal-stat .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; flex: none; }
.gal-admin .gal-stat .ic svg.i { width: 21px; height: 21px; }
.gal-admin .gal-stat b { display: block; font: 800 22px/1.15 var(--font-display); color: var(--navy); }
.gal-admin .gal-stat small { color: var(--muted); font-size: 12.5px; }
.gal-admin .tone-blue { background: var(--primary-soft); color: var(--primary); }
.gal-admin .tone-green { background: var(--green-soft); color: var(--green); }
.gal-admin .tone-purple { background: var(--purple-soft); color: var(--purple); }
.gal-admin .tone-red { background: var(--red-soft); color: var(--red); }
.gal-admin .gal-cover { width: 96px; aspect-ratio: 4 / 3; border-radius: 10px; object-fit: cover; display: block; border: 1px solid var(--line); background: var(--surface); }
.gal-admin .gal-cover-ph { width: 96px; aspect-ratio: 4 / 3; border-radius: 10px; display: grid; place-items: center; border: 1px dashed var(--dash-line); background: var(--card-2); color: var(--muted); }
.gal-admin .gal-title { display: block; color: var(--navy); font-weight: 700; }
.gal-admin .gal-sub { display: block; color: var(--muted); font-size: 12.5px; margin-top: 2px; }
.gal-admin .gal-clip { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 420px; }
.gal-admin .gal-counts { display: flex; flex-wrap: wrap; gap: 6px; }
/* Album page */
.gal-admin .gal-hero { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 22px; padding: 18px; margin-bottom: 18px; align-items: start; }
.gal-admin .gal-hero-cover { position: relative; aspect-ratio: 4 / 3; border-radius: 12px; overflow: hidden; background: linear-gradient(135deg, var(--primary-soft), var(--purple-soft)); display: grid; place-items: center; color: var(--primary); }
.gal-admin .gal-hero-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.gal-admin .gal-hero-cover .tag { position: absolute; left: 8px; bottom: 8px; background: rgb(8 38 77 / .78); color: #fff; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
.gal-admin .gal-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.gal-admin .gal-desc { margin: 0 0 12px; color: var(--ink-2); max-width: 70ch; white-space: pre-line; }
.gal-admin .gal-link { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--card-2); border: 1px solid var(--line); font-size: 13px; }
.gal-admin .gal-link a { overflow-wrap: anywhere; font-weight: 600; }
.gal-admin .gal-hero-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.gal-admin .gal-add { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 18px; margin-bottom: 18px; align-items: start; }
.gal-admin .gal-add form.card > .form-actions { position: static; } /* small side-by-side forms: no sticky save bar */
.gal-admin .gal-drop { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; text-align: center; padding: 26px 18px; border: 2px dashed var(--dash-line); border-radius: var(--gal-r); background: var(--card-2); cursor: pointer; transition: border-color .15s, background .15s, transform .15s; }
.gal-admin .gal-drop:hover, .gal-admin .gal-drop.over, .gal-admin .gal-drop:focus-within { border-color: var(--primary); background: var(--primary-soft); }
.gal-admin .gal-drop.over { transform: scale(1.01); }
.gal-admin .gal-drop.busy { pointer-events: none; opacity: .6; }
.gal-admin .gal-drop input[type=file] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.gal-admin .gal-drop strong { color: var(--navy); font-size: 15px; font-family: var(--font-display); }
.gal-admin .gal-drop small { color: var(--muted); max-width: 420px; }
.gal-admin .gal-drop-ic { width: 54px; height: 54px; border-radius: 16px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, var(--primary), var(--purple)); box-shadow: 0 10px 24px rgb(11 99 246 / .25); margin-bottom: 4px; }
.gal-admin .gal-drop-ic svg.i { width: 24px; height: 24px; }
.gal-admin .gal-queue { list-style: none; margin: 14px 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 10px; max-height: 340px; overflow: auto; }
.gal-admin .gal-q { position: relative; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: var(--card); font-size: 11.5px; }
.gal-admin .gal-q .pv { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: grid; place-items: center; background: var(--surface); color: var(--muted); }
.gal-admin .gal-q .qm { padding: 5px 7px 6px; }
.gal-admin .gal-q .qn { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--ink); font-weight: 600; }
.gal-admin .gal-q .qs { display: block; color: var(--muted); }
.gal-admin .gal-q.bad, .gal-admin .gal-q.failed { border-color: var(--red); }
.gal-admin .gal-q.bad .qs, .gal-admin .gal-q.failed .qs { color: var(--red-dark); }
.gal-admin .gal-q.done { border-color: var(--green); }
.gal-admin .gal-q.done .qs { color: var(--txt-green); font-weight: 600; }
.gal-admin .gal-q.busy .pv { opacity: .55; }
.gal-admin .gal-q .qx { position: absolute; top: 5px; right: 5px; width: 26px; height: 26px; border-radius: 50%; border: 0; display: grid; place-items: center; background: rgb(8 38 77 / .75); color: #fff; cursor: pointer; }
.gal-admin .gal-q .qx svg.i { width: 14px; height: 14px; }
.gal-admin .gal-progress { margin-top: 14px; }
.gal-admin .gal-bar { height: 10px; border-radius: 99px; background: var(--track); overflow: hidden; }
.gal-admin .gal-bar span { display: block; height: 100%; width: 0; border-radius: inherit; background: linear-gradient(90deg, var(--primary), var(--purple)); transition: width .25s var(--ease); }
.gal-admin .gal-progress p { margin: 8px 0 0; font-size: 13px; }
.gal-admin .gal-yt { display: flex; gap: 12px; align-items: center; padding: 12px; border-radius: 12px; background: var(--card-2); border: 1px solid var(--line); }
.gal-admin .gal-yt .fr { position: relative; width: 132px; aspect-ratio: 16 / 9; border-radius: 9px; overflow: hidden; background: var(--track); display: grid; place-items: center; flex: none; color: var(--muted); font-size: 11.5px; text-align: center; }
.gal-admin .gal-yt .fr img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.gal-admin .gal-grid { list-style: none; margin: 0; padding: 18px; display: grid; grid-template-columns: repeat(auto-fill, minmax(232px, 1fr)); gap: 16px; }
.gal-admin .gal-item { border: 1px solid var(--line); border-radius: var(--gal-r); background: var(--card); overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .15s, border-color .15s; scroll-margin-top: calc(var(--topbar-h) + 90px); }
.gal-admin .gal-item:hover { box-shadow: var(--shadow); }
.gal-admin .gal-item:target { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-ring); }
.gal-admin .gal-item:has([data-row-check]:checked) { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-ring); background: var(--primary-soft); }
.gal-admin .gal-item.off .gal-thumb img { opacity: .4; filter: grayscale(.7); }
.gal-admin .gal-thumb { position: relative; aspect-ratio: 4 / 3; background: var(--surface); overflow: hidden; }
.gal-admin .gal-thumb > a { display: block; width: 100%; height: 100%; }
.gal-admin .gal-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s var(--ease); }
.gal-admin .gal-thumb:hover img { transform: scale(1.04); }
.gal-admin .gal-play { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; }
.gal-admin .gal-play span { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; background: #ff0033; color: #fff; box-shadow: 0 8px 20px rgb(255 0 51 / .4); }
.gal-admin .gal-play svg.i { width: 20px; height: 20px; fill: currentColor; margin-left: 3px; }
.gal-admin .gal-sel { position: absolute; top: 8px; left: 8px; width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: var(--card); box-shadow: var(--shadow); cursor: pointer; }
.gal-admin .gal-sel input { width: 18px; height: 18px; margin: 0; accent-color: var(--primary); cursor: pointer; }
.gal-admin .gal-tags { position: absolute; top: 8px; right: 8px; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 4px; max-width: 70%; }
.gal-admin .gal-tags .badge { box-shadow: var(--shadow-xs); }
.gal-admin .gal-pos { position: absolute; left: 8px; bottom: 8px; font-size: 11px; font-weight: 700; background: rgb(8 38 77 / .8); color: #fff; padding: 2px 9px; border-radius: 99px; }
.gal-admin .gal-fields { padding: 12px; display: grid; gap: 8px; }
.gal-admin .gal-fields .in { min-height: 36px; padding: 7px 10px; font-size: 13px; }
.gal-admin .gal-fields .lbl { font-size: 11.5px; margin: 0 0 3px; color: var(--muted); font-weight: 600; }
.gal-admin .gal-row { display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
.gal-admin .gal-row .gal-ord { width: 78px; flex: none; }
.gal-admin .gal-tools { display: flex; flex-wrap: wrap; gap: 4px; margin-left: auto; }
.gal-admin .gal-ib { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 9px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); cursor: pointer; transition: border-color .15s, color .15s, background .15s; }
.gal-admin .gal-ib svg.i { width: 16px; height: 16px; }
.gal-admin .gal-ib:hover:not([disabled]) { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
.gal-admin .gal-ib.on { border-color: var(--gold); color: #a16207; background: var(--gold-soft); }
.gal-admin .gal-ib.danger:hover { border-color: var(--red); color: var(--red-dark); background: var(--red-soft); }
.gal-admin .gal-ib[disabled] { opacity: .35; cursor: default; }
.gal-admin .gal-items-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.gal-admin .gal-help { margin: 0; padding: 0 18px 2px; font-size: 12.5px; color: var(--muted); }
.gal-admin .gal-items-card .bulkbar .btn-danger { margin-left: auto; }
@media (max-width: 1100px) { .gal-admin .gal-add { grid-template-columns: 1fr; } }
@media (max-width: 900px) { .gal-admin .gal-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 720px) {
  .gal-admin .gal-hero { grid-template-columns: 1fr; }
  .gal-admin .gal-grid { grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr)); padding: 12px; gap: 12px; }
  .gal-admin .gal-yt { flex-direction: column; align-items: stretch; }
  .gal-admin .gal-yt .fr { width: 100%; }
}
@media (max-width: 420px) { .gal-admin .gal-stats { gap: 10px; } .gal-admin .gal-stat { padding: 12px; } }
</style>
<?php
}

// ---------- Album form (new / edit) ----------
if ($action === 'new' || $action === 'edit') {
    $row = $action === 'edit' ? db_one('SELECT * FROM gallery_albums WHERE id = ?', [(int) ($_GET['id'] ?? 0)]) : null;
    if ($action === 'edit' && !$row) {
        flash('error', 'Album not found.');
        redirect_to('gallery.php');
    }
    $v = $formState['values'] ?? [
        'title' => $row['title'] ?? '', 'slug' => $row['slug'] ?? '', 'description' => $row['description'] ?? '',
        'event_date' => $row['event_date'] ?? '', 'sort_order' => $row['sort_order'] ?? 0, 'is_active' => $row['is_active'] ?? 1,
    ];
    $err = $formState['errors'] ?? [];
    if ($err) {
        http_response_code(422);
    }
    $title = $row ? 'Edit album' : 'New album';
    admin_start($title, null, 'media', ['head' => true,
        'crumbs' => '<a href="gallery.php">Photo Gallery</a> <span class="sep" aria-hidden="true">/</span> ' . e($row ? $row['title'] : 'New album'),
        'subtitle' => 'An album groups the photos and videos of one event, batch or campus tour. It appears on the website once it is active and has at least one visible item.']);
    gallery_styles();
    $f = fn(string $k) => isset($err[$k]) ? ' invalid' : '';
    $msg = fn(string $k) => isset($err[$k]) ? '<div class="err" id="err-' . $k . '">' . icon('alert') . e($err[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($err[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
    $coverUrl = $row && $row['cover_image_path'] ? public_file_url($row['cover_image_path']) : null;
    ?>
<div class="gal-admin">
<?php if ($err): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span>Please correct the highlighted fields.</span></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card" data-dirty-check novalidate>
  <?= csrf_field() ?><input type="hidden" name="_action" value="save_album"><input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
  <div class="card-head"><h2><?= icon('image') ?> Album details</h2><?= $row ? '<a class="btn btn-light btn-sm" href="gallery.php?album=' . (int) $row['id'] . '">' . icon('grid') . ' Photos &amp; videos</a>' : '' ?></div>
  <div class="card-pad form">
    <div class="field<?= $f('title') ?>"><label for="g-title">Album title <span class="req" aria-hidden="true">*</span></label>
      <input id="g-title" type="text" name="title" maxlength="160" required value="<?= e((string) $v['title']) ?>" placeholder="e.g. Annual Day 2026"<?= $inv('title') ?>><?= $msg('title') ?></div>
    <div class="field<?= $f('slug') ?>"><label for="g-slug">Web address</label>
      <input id="g-slug" type="text" name="slug" maxlength="180" value="<?= e((string) $v['slug']) ?>" data-slug-from="title" placeholder="annual-day-2026" pattern="[a-z0-9]+(-[a-z0-9]+)*"<?= $inv('slug') ?>>
      <div class="hint"><?= e(app_url('gallery/')) ?><strong>…</strong> · filled in from the title; changing it later keeps old links working.</div><?= $msg('slug') ?></div>
    <div class="field<?= $f('event_date') ?>"><label for="g-date">Event date (optional)</label>
      <input id="g-date" type="date" name="event_date" value="<?= e((string) $v['event_date']) ?>"<?= $inv('event_date') ?>><div class="hint">Shown on the album card. Albums are listed newest first.</div><?= $msg('event_date') ?></div>
    <div class="field<?= $f('sort_order') ?>"><label for="g-sort">Display order</label>
      <input id="g-sort" type="number" name="sort_order" min="-999" max="9999" step="1" value="<?= e((string) $v['sort_order']) ?>"<?= $inv('sort_order') ?>><div class="hint">Leave 0 for date order. Lower numbers appear first (pin an album with -1).</div><?= $msg('sort_order') ?></div>
    <div class="field full<?= $f('description') ?>"><label for="g-desc">Description (optional)</label>
      <textarea id="g-desc" name="description" rows="3" maxlength="2000" placeholder="A line or two about the event - shown on the album page."<?= $inv('description') ?>><?= e((string) $v['description']) ?></textarea><?= $msg('description') ?></div>
    <div class="field full<?= $f('cover') ?>"><label for="g-cover">Cover image (optional)</label>
      <input id="g-cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="g-cover-pv"<?= $inv('cover') ?>>
      <img id="g-cover-pv" class="thumb-preview" alt="Cover preview" <?= $coverUrl ? 'src="' . e($coverUrl) . '"' : 'hidden' ?>>
      <div class="hint">By default the album's first photo is its cover. Upload one here, or pick any photo as the cover on the album page. JPG, PNG, WebP or GIF up to <?= e(gallery_mb($maxBytes)) ?>.</div>
      <?php if ($coverUrl): ?><label class="check mt-6"><input type="checkbox" name="remove_cover" value="1"> Remove the chosen cover (use the first photo)</label><?php endif; ?>
      <?= $msg('cover') ?></div>
    <div class="field full"><label class="switch"><input type="checkbox" name="is_active" value="1"<?= $v['is_active'] ? ' checked' : '' ?>><span class="track"></span> Active - show this album on the website</label></div>
  </div>
  <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
    <a class="btn btn-light" href="<?= $row ? 'gallery.php?album=' . (int) $row['id'] : 'gallery.php' ?>">Cancel</a>
    <button class="btn btn-primary"><?= icon('check') ?> <?= $row ? 'Save album' : 'Create album' ?></button></div>
</form>
</div>
<?php
    admin_end();
    exit;
}

// ---------- One album: upload, videos, captions, order, cover, visibility ----------
if ($albumId) {
    $a = db_one('SELECT * FROM gallery_albums WHERE id = ?', [$albumId]);
    if (!$a) {
        flash('error', 'Album not found. It may have been deleted.');
        redirect_to('gallery.php');
    }
    if ($n = (int) ($_SESSION['gallery_uploaded'][$albumId] ?? 0)) {
        flash('success', plural_n($n, 'photo') . ' uploaded. Add captions and alt text below, then save.');
    }
    unset($_SESSION['gallery_uploaded'][$albumId]);
    $oldVideo = $_SESSION['gallery_video_old'] ?? ['url' => '', 'caption' => ''];
    unset($_SESSION['gallery_video_old']);

    $items = db_all('SELECT * FROM gallery_items WHERE album_id = ? ORDER BY sort_order, id', [$albumId]);
    $photos = count(array_filter($items, fn($i) => $i['type'] === 'image'));
    $videos = count($items) - $photos;
    $visible = count(array_filter($items, fn($i) => (int) $i['is_active'] === 1));
    $firstPhoto = current(array_filter($items, fn($i) => $i['type'] === 'image' && $i['is_active'])) ?: null;
    // Same rule as the website: a chosen cover that is the file of a hidden photo is ignored (older data).
    foreach ($items as $i) {
        if ($a['cover_image_path'] && !(int) $i['is_active'] && in_array($a['cover_image_path'], [$i['image_path'], $i['thumb_path']], true)) {
            $a['cover_image_path'] = null;
        }
    }
    $coverPath = $a['cover_image_path'] ?: ($firstPhoto ? ($firstPhoto['thumb_path'] ?: $firstPhoto['image_path']) : null);
    $coverUrl = public_file_url($coverPath);
    $coverItemId = null;
    foreach ($items as $i) {
        if ($coverPath && $i['type'] === 'image' && in_array($coverPath, [$i['thumb_path'], $i['image_path']], true)) {
            $coverItemId = (int) $i['id'];
            break;
        }
    }
    $live = $a['is_active'] && $visible > 0;
    $publicUrl = gallery_public_url($a['slug']);

    admin_start($a['title'], null, 'media', ['head' => true,
        'crumbs' => '<a href="gallery.php">Photo Gallery</a> <span class="sep" aria-hidden="true">/</span> ' . e($a['title']),
        'actions' => '<a class="btn btn-light" href="gallery.php">' . icon('left') . ' All albums</a>'
            . '<a class="btn btn-light" href="gallery.php?action=edit&amp;id=' . $albumId . '">' . icon('edit') . ' Edit album</a>'
            . ($live ? '<a class="btn btn-primary" href="' . e($publicUrl) . '" target="_blank" rel="noopener">' . icon('external') . ' View on website</a>' : '')]);
    gallery_styles();
    ?>
<div class="gal-admin">
  <section class="card gal-hero" aria-label="Album summary">
    <div class="gal-hero-cover">
      <?php if ($coverUrl): ?><img src="<?= e($coverUrl) ?>" alt="Album cover"><span class="tag"><?= $a['cover_image_path'] ? 'Chosen cover' : 'Cover · first photo' ?></span>
      <?php else: ?><span style="display:grid;justify-items:center;gap:6px"><?= icon('image') ?><small>No cover yet</small></span><?php endif; ?>
    </div>
    <div style="min-width:0">
      <div class="gal-meta">
        <?= $a['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-gray">Hidden</span>' ?>
        <?php if ($a['event_date']): ?><span class="chip"><?= icon('calendar') ?> <?= e(date('j M Y', strtotime($a['event_date']))) ?></span><?php endif; ?>
        <span class="chip"><?= icon('image') ?> <?= plural_n($photos, 'photo') ?></span>
        <span class="chip"><?= icon('youtube') ?> <?= plural_n($videos, 'video') ?></span>
        <?php if (count($items) - $visible > 0): ?><span class="chip"><?= icon('eye') ?> <?= count($items) - $visible ?> hidden</span><?php endif; ?>
      </div>
      <?php if ($a['description']): ?><p class="gal-desc"><?= e($a['description']) ?></p><?php endif; ?>
      <?php if (!$a['is_active']): ?>
        <div class="alert alert-warning" style="margin:0 0 12px"><?= icon('info') ?><span>This album is hidden from the website. Switch it on when the photos are ready.</span></div>
      <?php elseif (!$visible): ?>
        <div class="alert alert-info" style="margin:0 0 12px"><?= icon('info') ?><span>Add at least one photo or video - empty albums are not listed on the website.</span></div>
      <?php endif; ?>
      <div class="gal-link"><?= icon('link') ?><span class="muted">Website link</span>
        <?= $live ? '<a href="' . e($publicUrl) . '" target="_blank" rel="noopener">' . e($publicUrl) . '</a>' : '<span>' . e($publicUrl) . '</span>' ?>
        <button type="button" class="btn btn-light btn-sm" data-copy="<?= e($publicUrl) ?>"><?= icon('copy') ?> Copy</button></div>
      <div class="gal-hero-acts">
        <?= action_form('toggle_album', ['id' => $albumId, 'back' => 'album'], icon('eye') . ($a['is_active'] ? ' Hide album' : ' Show album on website'), $a['is_active'] ? 'btn btn-light btn-sm' : 'btn btn-primary btn-sm') ?>
        <?php if ($a['cover_image_path']): ?><?= action_form('cover_auto', ['album_id' => $albumId], icon('refresh') . ' Use first photo as cover', 'btn btn-light btn-sm') ?><?php endif; ?>
        <?= action_form('delete_album', ['id' => $albumId], icon('trash') . ' Delete album', 'btn btn-danger btn-sm', 'Delete "' . $a['title'] . '" and its ' . plural_n(count($items), 'photo and video', 'photos and videos') . '? The photo files are removed too (files still used elsewhere on the website are kept). This cannot be undone.') ?>
      </div>
    </div>
  </section>

  <div class="gal-add">
    <form id="gal-upload" class="card" method="post" enctype="multipart/form-data" action="gallery.php?album=<?= $albumId ?>"
      data-max-bytes="<?= (int) min($maxBytes, PHP_INT_MAX) ?>" data-max-files="<?= $maxFiles ?>" data-post-max="<?= (int) min($postMax, 2_000_000_000) ?>">
      <?= csrf_field() ?><input type="hidden" name="_action" value="upload"><input type="hidden" name="album_id" value="<?= $albumId ?>">
      <div class="card-head"><h2><?= icon('upload') ?> Upload photos</h2><span class="muted" style="font-size:12.5px">JPG, PNG, WebP or GIF · up to <?= e(gallery_mb($maxBytes)) ?> each</span></div>
      <div class="card-pad">
        <label class="gal-drop" id="gal-drop">
          <span class="gal-drop-ic"><?= icon('image') ?></span>
          <strong>Drop photos here or click to choose</strong>
          <small>Select many photos at once. They are resized, turned upright and converted to WebP automatically; the order follows your selection.</small>
          <input id="gal-files" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required aria-describedby="gal-up-note">
        </label>
        <ul class="gal-queue" id="gal-queue" hidden aria-label="Selected photos"></ul>
        <div class="gal-progress" id="gal-progress" hidden>
          <div class="gal-bar" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
          <p id="gal-progress-text" role="status" aria-live="polite"></p>
        </div>
      </div>
      <div class="form-actions">
        <span class="muted" id="gal-up-note" style="margin-right:auto;font-size:12.5px">Without JavaScript up to <?= $maxFiles ?> photos per upload.</span>
        <button type="button" class="btn btn-light" id="gal-reload" hidden><?= icon('refresh') ?> Show uploaded photos</button>
        <button class="btn btn-primary" id="gal-up-btn" data-no-loading><?= icon('upload') ?> <span>Upload photos</span></button>
      </div>
    </form>

    <form id="add-video" class="card" method="post" action="gallery.php?album=<?= $albumId ?>">
      <?= csrf_field() ?><input type="hidden" name="_action" value="add_video"><input type="hidden" name="album_id" value="<?= $albumId ?>">
      <div class="card-head"><h2><?= icon('youtube') ?> Add a YouTube video</h2></div>
      <div class="card-pad form" style="grid-template-columns:1fr">
        <div class="field"><label for="gal-yt-url">YouTube link</label>
          <input id="gal-yt-url" type="url" name="url" required maxlength="500" value="<?= e((string) $oldVideo['url']) ?>" placeholder="https://www.youtube.com/watch?v=…  or  https://youtu.be/…">
          <div class="hint">Watch, youtu.be, Shorts, embed and live links work. Plays on the website without YouTube cookies.</div></div>
        <div class="field"><label for="gal-yt-cap">Caption (optional)</label>
          <input id="gal-yt-cap" type="text" name="caption" maxlength="255" value="<?= e((string) $oldVideo['caption']) ?>" placeholder="e.g. Toppers' felicitation - highlights"></div>
        <div class="gal-yt" aria-live="polite"><div class="fr" id="gal-yt-fr"><img id="gal-yt-img" alt="" hidden><span id="gal-yt-ph">Preview</span></div>
          <span class="muted" id="gal-yt-status" style="font-size:13px">Paste a link to check it.</span></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon('plus') ?> Add video</button></div>
    </form>
  </div>

  <section class="card gal-items-card" id="items" data-bulk aria-labelledby="gal-items-h">
    <div class="card-head gal-items-head">
      <h2 id="gal-items-h"><?= icon('grid') ?> Photos &amp; videos <span class="tab-count"><?= count($items) ?></span></h2>
      <?php if ($items): ?><label class="check" style="font-size:13px"><input type="checkbox" data-check-all> Select all</label><?php endif; ?>
    </div>
    <?php if (!$items): ?>
      <?= empty_state('No photos or videos yet', 'Upload photos or add a YouTube video above. They appear here, ready for captions and ordering.', 'image') ?>
    <?php else: ?>
    <form id="gal-items" method="post" action="gallery.php?album=<?= $albumId ?>" data-dirty-check>
      <?= csrf_field() ?><input type="hidden" name="_action" value="items"><input type="hidden" name="album_id" value="<?= $albumId ?>">
      <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Save</button><!-- Enter in a text field = Save -->
      <div class="bulkbar"><strong><span data-bulk-count>0</span> selected</strong>
        <button class="btn btn-light btn-sm" name="bulk" value="show"><?= icon('eye') ?> Show</button>
        <button class="btn btn-light btn-sm" name="bulk" value="hide"><?= icon('eye') ?> Hide</button>
        <button class="btn btn-danger btn-sm" name="bulk" value="delete" data-confirm="Delete the selected photos and videos? Photo files are removed too. This cannot be undone."><?= icon('trash') ?> Delete selected</button>
      </div>
      <p class="gal-help" style="padding-top:12px">Captions show in the lightbox. Alt text describes the photo for screen readers and Google (e.g. "Students receiving prizes on stage"). Lower order numbers come first, or use the arrows.</p>
      <ul class="gal-grid">
        <?php $last = count($items) - 1; foreach ($items as $n => $i):
            $iid = (int) $i['id'];
            $isVideo = $i['type'] === 'youtube';
            $thumb = gallery_thumb_url($i);
            $open = $isVideo ? 'https://www.youtube.com/watch?v=' . rawurlencode((string) $i['youtube_id']) : public_file_url($i['image_path']);
            $label = $isVideo ? 'video ' . ($n + 1) : 'photo ' . ($n + 1);
            $isCover = $coverItemId === $iid; ?>
        <li class="gal-item<?= $i['is_active'] ? '' : ' off' ?>" id="item-<?= $iid ?>">
          <div class="gal-thumb">
            <a href="<?= e((string) $open) ?>" target="_blank" rel="noopener" aria-label="Open <?= e($label) ?> in a new tab">
              <img src="<?= e((string) $thumb) ?>" alt="<?= e((string) ($i['alt_text'] ?: $i['caption'] ?: '')) ?>" loading="lazy" decoding="async"<?= $i['width'] && $i['height'] ? ' width="' . (int) $i['width'] . '" height="' . (int) $i['height'] . '"' : '' ?>>
            </a>
            <?php if ($isVideo): ?><span class="gal-play" aria-hidden="true"><span><?= icon('play') ?></span></span><?php endif; ?>
            <label class="gal-sel"><input type="checkbox" name="ids[]" value="<?= $iid ?>" data-row-check aria-label="Select <?= e($label) ?>"></label>
            <span class="gal-tags">
              <?= $isVideo ? '<span class="badge b-red">YouTube</span>' : '' ?>
              <?= $isCover ? '<span class="badge b-amber">Cover</span>' : '' ?>
              <?= $i['is_active'] ? '' : '<span class="badge b-gray">Hidden</span>' ?>
            </span>
            <span class="gal-pos">#<?= $n + 1 ?><?= !$isVideo && $i['width'] ? ' · ' . (int) $i['width'] . '×' . (int) $i['height'] : '' ?></span>
          </div>
          <div class="gal-fields">
            <div><label class="lbl" for="cap-<?= $iid ?>">Caption</label>
              <input class="in" id="cap-<?= $iid ?>" type="text" name="items[<?= $iid ?>][caption]" maxlength="255" value="<?= e((string) $i['caption']) ?>" placeholder="Optional caption"></div>
            <div><label class="lbl" for="alt-<?= $iid ?>">Alt text</label>
              <input class="in" id="alt-<?= $iid ?>" type="text" name="items[<?= $iid ?>][alt]" maxlength="160" value="<?= e((string) $i['alt_text']) ?>" placeholder="<?= $isVideo ? 'What the video shows' : 'Describe the photo' ?>"></div>
            <div class="gal-row">
              <div class="gal-ord"><label class="lbl" for="ord-<?= $iid ?>">Order</label>
                <input class="in" id="ord-<?= $iid ?>" type="number" name="items[<?= $iid ?>][sort]" min="-999" max="9999" step="1" value="<?= (int) $i['sort_order'] ?>"></div>
              <div class="gal-tools">
                <button class="gal-ib" name="op" value="up:<?= $iid ?>" title="Move earlier" aria-label="Move <?= e($label) ?> earlier"<?= $n === 0 ? ' disabled' : '' ?>><?= icon('left') ?></button>
                <button class="gal-ib" name="op" value="down:<?= $iid ?>" title="Move later" aria-label="Move <?= e($label) ?> later"<?= $n === $last ? ' disabled' : '' ?>><?= icon('right') ?></button>
                <?php if (!$isVideo && !$i['is_active']): ?><button class="gal-ib" type="button" title="Show this photo on the website first to use it as the cover" aria-label="Hidden photos cannot be the album cover" disabled><?= icon('star') ?></button>
                <?php elseif (!$isVideo): ?><button class="gal-ib<?= $isCover ? ' on' : '' ?>" name="op" value="cover:<?= $iid ?>" title="<?= $isCover ? 'Album cover' : 'Set as album cover' ?>" aria-label="<?= $isCover ? 'This is the album cover' : 'Set ' . e($label) . ' as album cover' ?>"<?= $isCover && $a['cover_image_path'] ? ' disabled' : '' ?>><?= icon('star') ?></button><?php endif; ?>
                <button class="gal-ib" name="op" value="toggle:<?= $iid ?>" title="<?= $i['is_active'] ? 'Hide from website' : 'Show on website' ?>" aria-label="<?= ($i['is_active'] ? 'Hide ' : 'Show ') . e($label) ?>"><?= icon($i['is_active'] ? 'eye' : 'unlock') ?></button>
                <button class="gal-ib danger" name="op" value="delete:<?= $iid ?>" title="Delete" aria-label="Delete <?= e($label) ?>" data-confirm="Delete this <?= $isVideo ? 'video' : 'photo' ?>? This cannot be undone."><?= icon('trash') ?></button>
              </div>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save captions &amp; order</button></div>
    </form>
    <?php endif; ?>
  </section>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);

  // ---------- Multi-photo upload: preview, checks, batches with a progress bar (the plain form works without JS) ----------
  const form = $('gal-upload');
  if (form && window.FormData && window.XMLHttpRequest) {
    const input = $('gal-files'), drop = $('gal-drop'), list = $('gal-queue'), prog = $('gal-progress'), text = $('gal-progress-text');
    const bar = prog.querySelector('.gal-bar'), fill = bar.firstElementChild, btn = $('gal-up-btn'), reload = $('gal-reload');
    const maxBytes = +form.dataset.maxBytes, maxFiles = +form.dataset.maxFiles, postMax = +form.dataset.postMax;
    const types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const mb = (b) => (b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB');
    let queue = [], busy = false;
    $('gal-up-note').textContent = 'You can select or drop as many photos as you like - they are sent in small batches.';
    input.required = false;

    const say = (msg) => { prog.hidden = false; text.textContent = msg; };
    const setBar = (r) => { const p = Math.round(Math.max(0, Math.min(1, r)) * 100); fill.style.width = p + '%'; bar.setAttribute('aria-valuenow', String(p)); };
    const ready = () => queue.filter((q) => q.state === 'ready' || q.state === 'failed');
    function add(files) {
      if (busy) return;
      for (const f of files) {
        if (queue.some((q) => q.file.name === f.name && q.file.size === f.size && q.file.lastModified === f.lastModified)) continue;
        const error = !types.includes(f.type) ? 'Not a JPG, PNG, WebP or GIF' : f.size > maxBytes ? 'Larger than ' + mb(maxBytes) : '';
        queue.push({ file: f, state: error ? 'bad' : 'ready', error, url: error ? '' : URL.createObjectURL(f) });
      }
      render();
    }
    function render() {
      list.hidden = !queue.length;
      list.replaceChildren(...queue.map((q, i) => {
        const li = document.createElement('li');
        li.className = 'gal-q ' + q.state;
        const pv = document.createElement(q.url ? 'img' : 'span');
        pv.className = 'pv';
        if (q.url) { pv.src = q.url; pv.alt = ''; } else { pv.textContent = '!'; }
        const meta = document.createElement('span');
        meta.className = 'qm';
        const n = document.createElement('span'); n.className = 'qn'; n.textContent = q.file.name; n.title = q.file.name;
        const s = document.createElement('span'); s.className = 'qs';
        s.textContent = { ready: mb(q.file.size), busy: 'Uploading…', done: 'Uploaded', failed: q.error || 'Failed', bad: q.error }[q.state];
        meta.append(n, s);
        li.append(pv, meta);
        if (!busy && q.state !== 'done') {
          const x = document.createElement('button');
          x.type = 'button'; x.className = 'qx'; x.setAttribute('aria-label', 'Remove ' + q.file.name);
          x.innerHTML = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
          x.addEventListener('click', () => { if (q.url) URL.revokeObjectURL(q.url); queue.splice(i, 1); render(); });
          li.append(x);
        }
        return li;
      }));
      const n = ready().length, bad = queue.filter((q) => q.state === 'bad').length;
      btn.querySelector('span').textContent = n ? 'Upload ' + n + ' photo' + (n === 1 ? '' : 's') : 'Upload photos';
      if (!busy) say(n || bad ? n + ' ready to upload' + (bad ? ' · ' + bad + ' cannot be uploaded (see red items)' : '') : '');
      if (!queue.length) prog.hidden = true;
    }
    input.addEventListener('change', () => { add(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave', 'dragend', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('over')));
    drop.addEventListener('drop', (e) => { e.preventDefault(); add(e.dataTransfer?.files || []); });

    const send = (fd, bytes, onProgress) => new Promise((resolve, reject) => {
      const x = new XMLHttpRequest();
      x.open('POST', form.action);
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.setRequestHeader('Accept', 'application/json');
      x.upload.onprogress = (e) => { if (e.lengthComputable && e.total) onProgress((e.loaded / e.total) * bytes); };
      x.onload = () => {
        let j = null;
        try { j = JSON.parse(x.responseText); } catch { /* not JSON */ }
        if (j && Array.isArray(j.results)) resolve(j);
        else reject(new Error(j?.error || (x.status === 403 ? 'Your session expired - refresh the page and try again.' : 'Upload failed (error ' + x.status + ').')));
      };
      x.onerror = () => reject(new Error('Network error - check your connection and try again.'));
      x.send(fd);
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (busy) return;
      const todo = ready();
      if (!todo.length) { say(queue.length ? 'Nothing left to upload.' : 'Choose or drop some photos first.'); input.focus(); return; }
      busy = true;
      btn.disabled = true; drop.classList.add('busy');
      const total = todo.reduce((s, q) => s + q.file.size, 0) || 1;
      const batches = [];
      let cur = [], curBytes = 0;
      for (const q of todo) {
        if (cur.length && (cur.length >= Math.min(4, maxFiles) || curBytes + q.file.size > postMax * 0.85)) { batches.push(cur); cur = []; curBytes = 0; }
        cur.push(q); curBytes += q.file.size;
      }
      if (cur.length) batches.push(cur);
      let sent = 0, ok = 0, failed = 0;
      setBar(0);
      for (const b of batches) {
        b.forEach((q) => { q.state = 'busy'; });
        render();
        say('Uploading ' + (ok + failed + 1) + '–' + (ok + failed + b.length) + ' of ' + todo.length + '…');
        const bytes = b.reduce((s, q) => s + q.file.size, 0);
        const fd = new FormData();
        ['_csrf', 'album_id'].forEach((k) => fd.append(k, form.elements[k].value));
        fd.append('_action', 'upload'); fd.append('_ajax', '1');
        b.forEach((q) => fd.append('files[]', q.file, q.file.name));
        try {
          const res = await send(fd, bytes, (done) => setBar((sent + done) / total));
          b.forEach((q, i) => {
            const r = res.results[i];
            if (r && r.ok) { q.state = 'done'; ok++; } else { q.state = 'failed'; q.error = (r && r.error) || 'Not uploaded'; failed++; }
          });
        } catch (err) {
          b.forEach((q) => { q.state = 'failed'; q.error = err.message; failed++; });
        }
        sent += bytes;
        setBar(sent / total);
      }
      busy = false;
      btn.disabled = false; drop.classList.remove('busy');
      render();
      if (ok && !failed) { say(ok + ' photo' + (ok === 1 ? '' : 's') + ' uploaded. Refreshing…'); setTimeout(() => location.reload(), 700); return; }
      say((ok ? ok + ' uploaded, ' : '') + failed + ' not uploaded - see the red items. Fix or remove them and try again.');
      reload.hidden = !ok;
    });
    reload.addEventListener('click', () => location.reload());
  }

  // ---------- YouTube link preview (the server checks the link again) ----------
  const yt = $('gal-yt-url');
  if (yt) {
    const img = $('gal-yt-img'), ph = $('gal-yt-ph'), status = $('gal-yt-status');
    const parse = (v) => {
      v = v.trim();
      if (/^[\w-]{11}$/.test(v)) return v;
      let u; try { u = new URL(/^https?:\/\//i.test(v) ? v : 'https://' + v); } catch { return null; }
      const h = u.hostname.toLowerCase().replace(/^(www\.|m\.|music\.)/, '');
      let id = '';
      if (h === 'youtu.be') id = u.pathname.split('/')[1] || '';
      else if (h === 'youtube.com' || h === 'youtube-nocookie.com') {
        const m = u.pathname.match(/^\/(?:shorts|embed|live|v|e)\/([^/?#]+)/);
        id = m ? m[1] : (u.pathname.replace(/\/$/, '') === '/watch' ? (u.searchParams.get('v') || '') : '');
      }
      return /^[\w-]{11}$/.test(id) ? id : null;
    };
    const update = () => {
      const v = yt.value;
      const id = v.trim() ? parse(v) : null;
      img.hidden = !id; ph.hidden = !!id;
      if (id) img.src = 'https://i.ytimg.com/vi/' + id + '/mqdefault.jpg';
      ph.textContent = v.trim() ? 'Not a YouTube link' : 'Preview';
      status.textContent = !v.trim() ? 'Paste a link to check it.' : id ? 'Video found (id ' + id + ').' : 'This does not look like a YouTube video link.';
      yt.setAttribute('aria-invalid', v.trim() && !id ? 'true' : 'false');
    };
    yt.addEventListener('input', update);
    update();
  }

  // ---------- Items: send only the fields that changed (keeps big albums under PHP's input limit) ----------
  const items = $('gal-items');
  if (items) {
    items.addEventListener('formdata', (e) => {
      items.querySelectorAll('.gal-fields input').forEach((el) => { if (el.value === el.defaultValue) e.formData.delete(el.name); });
    });
  }
})();
</script>
<?php
    admin_end();
    exit;
}

// ---------- Albums list ----------
$q = query_str('q', 100);
$status = in_array($_GET['status'] ?? '', ['active', 'hidden'], true) ? (string) $_GET['status'] : '';
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.title LIKE ? OR a.slug LIKE ? OR a.description LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'a.is_active = ' . ($status === 'active' ? 1 : 0);
}
$whereSql = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM gallery_albums a WHERE {$whereSql}", $params);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 30;
$albums = db_all(
    "SELECT a.*, COUNT(i.id) AS item_count, COALESCE(SUM(i.type = 'image'), 0) AS photos, COALESCE(SUM(i.type = 'youtube'), 0) AS videos, COALESCE(SUM(i.is_active), 0) AS visible,
            (SELECT COALESCE(fi.thumb_path, fi.image_path) FROM gallery_items fi WHERE fi.album_id = a.id AND fi.type = 'image' ORDER BY fi.is_active DESC, fi.sort_order, fi.id LIMIT 1) AS first_image,
            (SELECT fv.youtube_id FROM gallery_items fv WHERE fv.album_id = a.id AND fv.type = 'youtube' ORDER BY fv.sort_order, fv.id LIMIT 1) AS first_video
       FROM gallery_albums a LEFT JOIN gallery_items i ON i.album_id = a.id
      WHERE {$whereSql}
      GROUP BY a.id
      ORDER BY a.sort_order, COALESCE(a.event_date, DATE(a.created_at)) DESC, a.id DESC
      LIMIT {$per} OFFSET " . (($page - 1) * $per),
    $params
);
$stats = db_one("SELECT (SELECT COUNT(*) FROM gallery_albums) AS albums, (SELECT COUNT(*) FROM gallery_albums WHERE is_active = 1) AS active_albums,
    (SELECT COUNT(*) FROM gallery_items WHERE type = 'image') AS photos, (SELECT COUNT(*) FROM gallery_items WHERE type = 'youtube') AS videos,
    (SELECT COUNT(*) FROM gallery_items WHERE is_active = 0) AS hidden_items");

admin_start('Photo Gallery', null, 'media', ['head' => true,
    'subtitle' => 'Albums of event photos and videos for the website gallery (/gallery). Upload many photos at once, add YouTube videos, write captions and choose each album\'s cover.',
    'actions' => '<a class="btn btn-light" href="' . e(app_url('gallery')) . '" target="_blank" rel="noopener">' . icon('external') . ' View gallery</a>'
        . '<a class="btn btn-primary" href="gallery.php?action=new">' . icon('plus') . ' New album</a>']);
gallery_styles();
?>
<div class="gal-admin">
  <div class="gal-stats">
    <div class="card gal-stat"><span class="ic tone-blue"><?= icon('folder') ?></span><div><b><?= number_format((int) $stats['albums']) ?></b><small><?= number_format((int) $stats['active_albums']) ?> active album<?= (int) $stats['active_albums'] === 1 ? '' : 's' ?></small></div></div>
    <div class="card gal-stat"><span class="ic tone-green"><?= icon('image') ?></span><div><b><?= number_format((int) $stats['photos']) ?></b><small>Photos</small></div></div>
    <div class="card gal-stat"><span class="ic tone-red"><?= icon('youtube') ?></span><div><b><?= number_format((int) $stats['videos']) ?></b><small>YouTube videos</small></div></div>
    <div class="card gal-stat"><span class="ic tone-purple"><?= icon('eye') ?></span><div><b><?= number_format((int) $stats['hidden_items']) ?></b><small>Hidden items</small></div></div>
  </div>

  <div class="card">
    <form class="toolbar" method="get" role="search">
      <label class="search-in"><span class="sr-only">Search albums</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search albums…"></label>
      <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit>
        <option value="">All statuses</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="hidden"<?= $status === 'hidden' ? ' selected' : '' ?>>Hidden</option>
      </select>
      <button class="btn btn-light"><?= icon('search') ?> Filter</button>
      <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-ghost" href="gallery.php">Reset</a><?php endif; ?>
    </form>
    <?php if (!$albums): ?>
      <?= $q !== '' || $status !== ''
          ? empty_state('No albums match', 'Try another search or clear the filter.', 'search')
          : empty_state('No albums yet', 'Create an album for an event, batch or campus tour, then upload its photos and videos.', 'image', 'gallery.php?action=new', 'Create the first album') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="t stack">
      <thead><tr><th scope="col">Cover</th><th scope="col">Album</th><th scope="col">Items</th><th scope="col">Event date</th><th scope="col">Status</th><th scope="col">Order</th><th scope="col" class="actions"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($albums as $al):
          $aid = (int) $al['id'];
          $cover = public_file_url($al['cover_image_path'] ?: $al['first_image']) ?? ($al['first_video'] ? 'https://i.ytimg.com/vi/' . rawurlencode($al['first_video']) . '/mqdefault.jpg' : null);
          $listed = $al['is_active'] && (int) $al['visible'] > 0; ?>
        <tr>
          <td data-label="Cover"><a href="gallery.php?album=<?= $aid ?>" aria-label="Open <?= e($al['title']) ?>"><?= $cover ? '<img class="gal-cover" src="' . e($cover) . '" alt="" loading="lazy">' : '<span class="gal-cover-ph">' . icon('image') . '</span>' ?></a></td>
          <td data-label="Album"><div style="min-width:180px"><a class="gal-title" href="gallery.php?album=<?= $aid ?>"><?= e($al['title']) ?></a>
            <span class="gal-sub"><code style="font-size:11px">/gallery/<?= e($al['slug']) ?></code></span>
            <?php if ($al['description']): ?><span class="gal-sub gal-clip"><?= e($al['description']) ?></span><?php endif; ?></div></td>
          <td data-label="Items"><div class="gal-counts"><span class="chip"><?= icon('image') ?> <?= (int) $al['photos'] ?></span><span class="chip"><?= icon('youtube') ?> <?= (int) $al['videos'] ?></span></div>
            <?php if ((int) $al['item_count'] > (int) $al['visible']): ?><span class="gal-sub"><?= (int) $al['item_count'] - (int) $al['visible'] ?> hidden</span><?php endif; ?></td>
          <td data-label="Event date" class="d2-nowrap"><?= $al['event_date'] ? e(date('j M Y', strtotime($al['event_date']))) : '<span class="muted">—</span>' ?></td>
          <td data-label="Status"><?= $al['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-gray">Hidden</span>' ?>
            <?php if ($al['is_active'] && !$listed): ?><span class="gal-sub">Not listed yet - no visible items</span><?php endif; ?></td>
          <td data-label="Order"><strong><?= (int) $al['sort_order'] ?></strong></td>
          <td class="actions"><?= row_actions(array_values(array_filter([
              ['Photos & videos', 'gallery.php?album=' . $aid, 'image'],
              ['Edit album', 'gallery.php?action=edit&id=' . $aid, 'edit'],
              $listed ? ['View on website', gallery_public_url($al['slug']), 'external', 'menu' => true, 'blank' => true] : null,
              [$al['is_active'] ? 'Hide from website' : 'Show on website', 'post' => ['toggle_album', ['id' => $aid]], 'icon' => 'eye', 'menu' => true],
              ['Delete album', 'post' => ['delete_album', ['id' => $aid]], 'icon' => 'trash', 'menu' => true, 'danger' => true,
                  'confirm' => 'Delete "' . $al['title'] . '" and its ' . plural_n((int) $al['item_count'], 'photo and video', 'photos and videos') . '? The photo files are removed too (files still used elsewhere on the website are kept). This cannot be undone.'],
          ])), 'Album actions') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php render_pager($total, $page, $per); ?>
  </div>
</div>
<?php admin_end();
