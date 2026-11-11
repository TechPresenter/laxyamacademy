<?php
// Media library for public images in /uploads. Private files (lessons, resources, resumes) are not listed here.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('cms.manage');
const MEDIA_FOLDERS = ['cms' => 'Website / CMS', 'courses' => 'Courses', 'blog' => 'Blog', 'profiles' => 'People', 'resources' => 'Resources'];
/** File types the library lists (?type=image is the sidebar "Image Manager"). Public uploads are re-encoded images only. */
const MEDIA_TYPES = ['image' => ['label' => 'Images', 'ext' => ['webp', 'jpg', 'jpeg', 'png', 'gif']]];

/** Where an image path is referenced (prevents deleting images that are in use). */
function media_usage(string $path): array
{
    $uses = [];
    foreach ([
        ['courses', 'thumbnail_path', 'Course'], ['blog_posts', 'featured_image_path', 'Blog post'], ['instructors', 'photo_path', 'Faculty'],
        ['testimonials', 'photo_path', 'Testimonial'], ['learning_resources', 'thumbnail_path', 'Resource'], ['seo_pages', 'og_image', 'SEO page'], ['users', 'avatar_path', 'User avatar'],
        ['popups', 'image_path', 'Popup'], ['promo_banners', 'image_path', 'Promo banner'], ['media_videos', 'thumbnail_path', 'Video cover'],
        ['exam_questions', 'image_path', 'Question image'], ['site_settings', 'setting_value', 'Site setting (logo / sharing image)'],
    ] as [$table, $col, $label]) {
        try {
            if ($n = (int) db_value("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?", [$path])) {
                $uses[] = "{$label} ({$n})";
            }
        } catch (PDOException $e) {
            // A table from a newer migration may be missing on an older database - skip it.
        }
    }
    // JSON documents store "/" either plain (MariaDB keeps the text) or escaped as "\/" (json_encode default).
    $likes = ['%' . addcslashes($path, '%_\\') . '%', '%' . addcslashes(str_replace('/', '\\/', $path), '%_\\') . '%'];
    foreach ([['cms_sections', 'content', 'CMS section'], ['pages', 'blocks', 'Page builder page'], ['reusable_sections', 'blocks', 'Reusable section'], ['blog_posts', 'content', 'Blog content']] as [$table, $col, $label]) {
        if ($n = (int) db_value("SELECT COUNT(*) FROM {$table} WHERE {$col} LIKE ? OR {$col} LIKE ?", $likes)) {
            $uses[] = "{$label} ({$n})";
        }
    }
    return $uses;
}

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'upload') {
        $folder = array_key_exists($_POST['folder'] ?? '', MEDIA_FOLDERS) ? $_POST['folder'] : 'cms';
        $ok = 0;
        $errs = [];
        $files = $_FILES['files'] ?? null;
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        for ($i = 0; $i < min($count, 20); $i++) {
            $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            try {
                $path = store_image_upload($one, $folder);
                audit_log('media_uploaded', 'media', $path);
                $ok++;
            } catch (InvalidArgumentException $e) {
                $errs[] = $one['name'] . ': ' . $e->getMessage();
            }
        }
        flash($errs ? 'warning' : 'success', $ok . ' image' . ($ok === 1 ? '' : 's') . ' uploaded.' . ($errs ? ' ' . implode(' ', $errs) : ''));
    }
    if ($act === 'delete') {
        $rel = (string) ($_POST['path'] ?? '');
        $full = realpath(APP_ROOT . '/' . $rel);
        $base = realpath(APP_ROOT . '/uploads');
        if (!$full || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || str_contains($full, DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR) || !is_file($full)) {
            flash('error', 'File not found.');
        } elseif ($uses = media_usage($rel)) {
            flash('error', 'This image is in use: ' . implode(', ', $uses) . '. Remove it there first.');
        } else {
            unlink($full);
            audit_log('media_deleted', 'media', $rel, [], 'warning');
            flash('success', 'Image deleted.');
        }
    }
    redirect_to(url_with([]));
}

$folder = array_key_exists($_GET['folder'] ?? '', MEDIA_FOLDERS) ? $_GET['folder'] : '';
$type = array_key_exists($_GET['type'] ?? '', MEDIA_TYPES) ? (string) $_GET['type'] : '';
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
$view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$exts = MEDIA_TYPES[$type ?: 'image']['ext'];
$items = [];
foreach ($folder ? [$folder] : array_keys(MEDIA_FOLDERS) as $f) {
    foreach (glob(APP_ROOT . '/uploads/' . $f . '/*.{' . implode(',', $exts) . '}', GLOB_BRACE) ?: [] as $file) {
        $name = basename($file);
        if ($q !== '' && !str_contains(mb_strtolower($name), $q)) {
            continue;
        }
        $items[] = ['path' => 'uploads/' . $f . '/' . $name, 'name' => $name, 'folder' => $f, 'size' => filesize($file), 'time' => filemtime($file), 'dim' => @getimagesize($file) ?: [0, 0]];
    }
}
usort($items, fn($a, $b) => $b['time'] <=> $a['time']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = count($items);
$items = array_slice($items, ($page - 1) * 48, 48);
$kb = fn(int $b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';

admin_start($type === 'image' ? 'Image Manager' : 'Media Library', null, 'cms', ['head' => true, 'subtitle' => ($type === 'image' ? 'All website images in one place. ' : 'Public images used on the website. ') . 'Images are re-encoded to WebP on upload. Alt text is set where each image is used (course, post, page). Images that are still in use cannot be deleted.']);
?>
<form method="post" enctype="multipart/form-data" class="card card-pad" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:18px">
  <?= csrf_field() ?><input type="hidden" name="_action" value="upload">
  <div class="field" style="flex:1;min-width:220px"><label for="mf">Upload images (JPG, PNG, WebP, GIF · up to 20 at once)</label><input id="mf" class="in" type="file" name="files[]" accept="image/*" multiple required></div>
  <div class="field"><label for="mfo">Folder</label><select id="mfo" class="in" name="folder"><?php foreach (MEDIA_FOLDERS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary"><?= icon('upload') ?> Upload</button>
</form>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search media</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search file name…"></label>
    <select class="in" style="width:auto" name="folder" aria-label="Folder" data-autosubmit><option value="">All folders</option><?php foreach (MEDIA_FOLDERS as $k => $l): ?><option value="<?= $k ?>"<?= $folder === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <input type="hidden" name="view" value="<?= $view ?>">
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($q !== '' || $folder !== ''): ?><a class="btn btn-ghost" href="<?= e(url_with(['q' => null, 'folder' => null, 'page' => null])) ?>">Reset</a><?php endif; ?>
    <nav class="d2-seg" aria-label="Layout">
      <a href="<?= e(url_with(['view' => 'grid'])) ?>"<?= $view === 'grid' ? ' aria-current="page"' : '' ?>><?= icon('grid') ?> Grid</a>
      <a href="<?= e(url_with(['view' => 'list'])) ?>"<?= $view === 'list' ? ' aria-current="page"' : '' ?>><?= icon('list') ?> List</a>
    </nav>
  </form>
  <?php if (!$items): $mFiltered = $q !== '' || $folder !== ''; ?>
  <div class="empty"><span class="empty-ic"><?= icon($mFiltered ? 'search' : 'image') ?></span>
    <strong><?= $mFiltered ? 'No images match' : 'No images yet' ?></strong>
    <p><?= $mFiltered ? 'Try another file name or folder.' : 'Upload images above, or add them from the course, blog and page forms.' ?></p>
  </div>
  <?php elseif ($view === 'grid'): ?>
  <div class="media-grid">
    <?php foreach ($items as $m): $url = app_url($m['path']); ?>
    <div class="media-item">
      <div class="pv" style="background-image:url('<?= e($url) ?>')" role="img" aria-label="<?= e($m['name']) ?>"></div>
      <div class="meta"><strong title="<?= e($m['name']) ?>"><?= e($m['name']) ?></strong><span class="muted"><?= (int) $m['dim'][0] ?>×<?= (int) $m['dim'][1] ?> · <?= $kb($m['size']) ?> · <?= e(MEDIA_FOLDERS[$m['folder']]) ?></span>
        <div style="display:flex;gap:6px;margin-top:8px"><button type="button" class="btn btn-light btn-sm" data-copy="<?= e($url) ?>"><?= icon('copy') ?> URL</button><?= action_form('delete', ['path' => $m['path']], icon('trash') . '<span class="sr-only">Delete ' . e($m['name']) . '</span>', 'btn btn-danger btn-sm', 'Delete this image? Images still in use cannot be deleted.') ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Preview</th><th>File</th><th>Dimensions</th><th>Size</th><th>Uploaded</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
    <?php foreach ($items as $m): $url = app_url($m['path']); ?>
    <tr><td data-label="Preview"><img src="<?= e($url) ?>" alt="" width="64" height="48" style="object-fit:cover;border-radius:6px" loading="lazy"></td>
      <td data-label="File"><div class="d2-w200"><strong style="overflow-wrap:anywhere"><?= e($m['name']) ?></strong><span class="d2-sub"><?= e(MEDIA_FOLDERS[$m['folder']]) ?></span></div></td>
      <td data-label="Dimensions" class="d2-nowrap"><?= (int) $m['dim'][0] ?>×<?= (int) $m['dim'][1] ?></td><td data-label="Size" class="d2-nowrap"><?= $kb($m['size']) ?></td><td data-label="Uploaded" class="d2-nowrap"><?= e(date('j M Y', $m['time'])) ?></td>
      <td class="actions"><span class="d2-acts"><button type="button" class="btn btn-light btn-sm" data-copy="<?= e($url) ?>"><?= icon('copy') ?> Copy URL</button><?= action_form('delete', ['path' => $m['path']], icon('trash') . '<span class="sr-only">Delete ' . e($m['name']) . '</span>', 'btn btn-danger btn-sm', 'Delete this image? Images still in use cannot be deleted.') ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php render_pager($total, $page, 48); ?>
</div>
<?php admin_end();
