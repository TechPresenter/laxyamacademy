<?php
// YouTube & Instagram media: videos shown in the website's video sections
// (homepage, courses page, about page or one course page) + the section headings.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/media.php';

$me = require_staff_page();
require_permission('cms.manage');

$counts = ['youtube' => ['n' => 0, 'active' => 0], 'instagram' => ['n' => 0, 'active' => 0]];
foreach (db_all('SELECT platform, COUNT(*) AS n, SUM(is_active) AS active FROM media_videos GROUP BY platform') as $r) {
    $counts[$r['platform']] = ['n' => (int) $r['n'], 'active' => (int) $r['active']];
}
$tabs = [
    'youtube' => 'YouTube videos · ' . $counts['youtube']['n'],
    'instagram' => 'Instagram reels · ' . $counts['instagram']['n'],
    'headings' => 'Section headings',
];
$tab = current_tab($tabs);
const VIDEO_HEADING_KEYS = ['youtube' => ['videos_youtube', 'channel_url', 'YouTube channel link'], 'instagram' => ['videos_instagram', 'profile_url', 'Instagram profile link']];

// ---------------- Quick activate / deactivate from the list ----------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['_action'] ?? '') === 'toggle' && admin_post()) {
    $row = db_one('SELECT id, title, is_active FROM media_videos WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($row) {
        db_query('UPDATE media_videos SET is_active = 1 - is_active WHERE id = ?', [$row['id']]);
        audit_log('updated', 'media_videos', (int) $row['id'], ['is_active' => $row['is_active'] ? 0 : 1]);
        flash('success', '"' . $row['title'] . '" ' . ($row['is_active'] ? 'hidden from' : 'shown on') . ' the website.');
    }
    redirect_to(url_with([]));
}

// ---------------- Section headings (cms_sections videos_youtube / videos_instagram) ----------------
if ($tab === 'headings') {
    $docs = [];
    foreach (VIDEO_HEADING_KEYS as $platform => [$key]) {
        $docs[$platform] = db_one('SELECT * FROM cms_sections WHERE section_key = ?', [$key]);
    }
    if (admin_post() && ($_POST['_action'] ?? '') === 'heading') {
        $platform = (string) ($_POST['platform'] ?? '');
        $cur = $docs[$platform] ?? null;
        if (!$cur) {
            flash('error', 'Section not found. Run Database Updates first.');
            redirect_to('videos.php?tab=headings');
        }
        [$key, $linkKey, $linkLabel] = VIDEO_HEADING_KEYS[$platform];
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
        $subtitle = mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 300);
        $link = trim((string) ($_POST['link'] ?? ''));
        if ($title === '') {
            flash('error', 'Add a heading for the ' . VIDEO_PLATFORMS[$platform] . ' section.');
        } elseif ($link !== '' && !preg_match('#^https://[^\s<>"]+$#i', $link)) {
            flash('error', $linkLabel . ' must be a full https:// link.');
        } else {
            // Keep the previous version in Revision History (same as admin/cms.php)
            db_query('INSERT INTO cms_revisions (section_key, content, is_active, saved_by) VALUES (?, ?, ?, ?)', [$key, $cur['content'], $cur['is_active'], $me['id']]);
            $doc = array_merge(json_decode_array($cur['content']), ['title' => $title, 'subtitle' => $subtitle, $linkKey => $link]);
            db_query('UPDATE cms_sections SET content = ?, is_active = ?, updated_by = ? WHERE section_key = ?',
                [json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), empty($_POST['is_active']) ? 0 : 1, $me['id'], $key]);
            audit_log('cms_section_updated', 'cms_sections', $key);
            flash('success', VIDEO_PLATFORMS[$platform] . ' section saved. Changes are live on the website.');
        }
        redirect_to('videos.php?tab=headings');
    }

    admin_start('YouTube & Instagram Media', null, 'cms', ['head' => true, 'subtitle' => 'Headings for the video sections. A section appears on the homepage only when it is switched on and has at least one active video.']);
    echo admin_tabs($tabs, $tab);
    echo '<div class="grid g-2">';
    foreach (VIDEO_HEADING_KEYS as $platform => [$key, $linkKey, $linkLabel]) {
        $row = $docs[$platform];
        $doc = $row ? json_decode_array($row['content']) : [];
        $p = 'h-' . $platform;
        echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="_action" value="heading"><input type="hidden" name="platform" value="' . e($platform) . '">';
        echo '<div class="card-head"><h2>' . icon($platform) . ' ' . e(VIDEO_PLATFORMS[$platform]) . ' section</h2>' . ($row ? badge($row['is_active'] ? 'active' : 'inactive') : '') . '</div>';
        if (!$row) {
            echo '<div class="card-pad"><div class="alert alert-warning" style="margin:0">The <code>' . e($key) . '</code> section is missing. Run admin → Database Updates (migration 008).</div></div></form>';
            continue;
        }
        echo '<div class="card-pad form">'
            . '<div class="field full"><label for="' . $p . '-t">Heading</label><input id="' . $p . '-t" type="text" name="title" maxlength="160" required value="' . e((string) ($doc['title'] ?? '')) . '"></div>'
            . '<div class="field full"><label for="' . $p . '-s">Sub-heading</label><textarea id="' . $p . '-s" name="subtitle" rows="2" maxlength="300">' . e((string) ($doc['subtitle'] ?? '')) . '</textarea></div>'
            . '<div class="field full"><label for="' . $p . '-l">' . e($linkLabel) . ' (optional)</label><input id="' . $p . '-l" type="url" name="link" placeholder="https://' . ($platform === 'youtube' ? 'www.youtube.com/@laxyamacademy' : 'www.instagram.com/laxyamacademy') . '" value="' . e((string) ($doc[$linkKey] ?? '')) . '"><div class="hint">Shown as a "' . ($platform === 'youtube' ? 'Visit channel' : 'Follow us') . '" button next to the heading.</div></div>'
            . '<div class="field full"><label class="check"><input type="checkbox" name="is_active" value="1"' . ($row['is_active'] ? ' checked' : '') . '> Show this section on the homepage</label></div>'
            . '<div class="field full muted" style="font-size:13px">' . $counts[$platform]['active'] . ' active of ' . $counts[$platform]['n'] . ' ' . ($platform === 'youtube' ? 'videos' : 'reels') . ' · <a href="?tab=' . $platform . '">Manage ' . ($platform === 'youtube' ? 'videos' : 'reels') . '</a> · <a href="cms.php?tab=revisions">Revision history</a></div>'
            . '</div><div class="form-actions"><button class="btn btn-primary">' . icon('check') . ' Save</button></div></form>';
    }
    echo '</div>';
    admin_end();
    exit;
}

// ---------------- Videos list + form ----------------
$isYt = $tab === 'youtube';
$courseOptions = fn() => options_from("SELECT id, CONCAT(title, IF(status = 'published', '', CONCAT(' (', status, ')'))) AS label FROM courses WHERE status <> 'archived' ORDER BY title");

/** Small preview tile for the list: custom thumbnail, YouTube frame or an Instagram placeholder. */
$preview = function (array $r): string {
    $thumb = video_thumbnail_url($r['platform'], $r['video_id'], $r['thumbnail_path']);
    $box = $r['platform'] === 'youtube' ? 'width:112px;height:63px' : 'width:54px;height:96px';
    if ($thumb) {
        return '<a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open video"><img src="' . e($thumb) . '" alt="" loading="lazy" style="' . $box . ';object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block"></a>';
    }
    return '<a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open reel" style="' . $box . ';border-radius:8px;display:grid;place-items:center;color:#fff;background:linear-gradient(160deg,#F58529,#DD2A7B 55%,#8134AF)">' . icon('instagram') . '</a>';
};

crud([
    'table' => 'media_videos',
    'title' => 'YouTube & Instagram Media',
    'singular' => $isYt ? 'YouTube video' : 'Instagram reel',
    'permission' => 'cms.manage',
    'active' => 'cms',
    'subtitle' => $isYt
        ? 'Paste any YouTube link (watch, youtu.be, Shorts, embed or live). The thumbnail is taken from YouTube unless you upload your own.'
        : 'Paste an Instagram reel or post link. Instagram does not share thumbnails, so upload a cover image for each reel (9:16 works best).',
    'tabs' => admin_tabs($tabs, $tab),
    'from' => 'media_videos t LEFT JOIN courses c ON c.id = t.course_id',
    'select' => 't.*, c.title AS course_title',
    'where' => 't.platform = ?',
    'where_params' => [$tab],
    'order' => "t.is_active DESC, FIELD(t.placement, 'home', 'courses', 'about', 'course'), t.sort_order, t.id DESC",
    'search' => ['t.title', 't.description', 't.video_id'],
    'sortable' => ['title' => 't.title', 'sort_order' => 't.sort_order', 'placement' => 't.placement'],
    'filters' => [
        'placement' => ['col' => 't.placement', 'label' => 'Placements', 'options' => VIDEO_PLACEMENTS],
        'active' => ['col' => 't.is_active', 'label' => 'Statuses', 'options' => ['1' => 'Active', '0' => 'Inactive']],
    ],
    'bulk_status' => ['field' => 'is_active', 'label' => 'status', 'options' => ['1' => 'Active (show)', '0' => 'Inactive (hide)']],
    'defaults' => ['created_by' => (int) $me['id']],
    'after_save_redirect' => fn(int $id) => 'videos.php?tab=' . (db_value('SELECT platform FROM media_videos WHERE id = ?', [$id]) ?: 'youtube'),
    'columns' => [
        'preview' => ['Preview', $preview],
        'title' => ['Video', fn($r) => '<div class="d2-w200"><strong>' . e($r['title']) . '</strong>'
            . ($r['description'] ? '<span class="d2-sub d2-clip">' . e($r['description']) . '</span>' : '')
            . '<span class="d2-sub"><code style="font-size:11px">' . e($r['video_id']) . '</code>'
            . ($r['platform'] === 'instagram' && !$r['thumbnail_path'] ? ' <span class="badge b-amber">No cover image</span>' : '') . '</span></div>'],
        'placement' => ['Shown on', fn($r) => '<div>' . e(VIDEO_PLACEMENTS[$r['placement']] ?? $r['placement']) . ($r['placement'] === 'course' ? '<span class="d2-sub">' . e($r['course_title'] ?? 'Course removed') . '</span>' : '') . '</div>'],
        'sort_order' => ['Order', fn($r) => '<span class="d2-pos">' . (int) $r['sort_order'] . '</span>'],
        'is_active' => ['Status', fn($r) => $r['is_active'] ? '<span class="badge b-green">Visible</span>' : '<span class="badge b-gray">Hidden</span>'],
    ],
    'row_actions' => fn($r) => action_form('toggle', ['id' => $r['id']], icon('eye') . ($r['is_active'] ? ' Hide' : ' Show') . '<span class="sr-only"> ' . e($r['title']) . '</span>', 'btn btn-light btn-sm')
        . ' <a class="btn btn-light btn-sm" href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open on ' . e(VIDEO_PLATFORMS[$r['platform']]) . '" title="Open on ' . e(VIDEO_PLATFORMS[$r['platform']]) . '">' . icon('external') . '</a>',
    'before_save' => function (array $d, ?array $old) {
        $parsed = parse_video_url((string) $d['platform'], (string) $d['url']);
        if (!$parsed) {
            throw new CrudError(['url' => $d['platform'] === 'instagram'
                ? 'Paste an Instagram reel or post link, e.g. https://www.instagram.com/reel/Cx1AbCdEfG/'
                : 'Paste a YouTube video link, e.g. https://www.youtube.com/watch?v=dQw4w9WgXcQ or https://youtu.be/dQw4w9WgXcQ']);
        }
        $d['video_id'] = $parsed['id'];
        $d['url'] = $parsed['url'];
        if ($d['placement'] === 'course') {
            if (empty($d['course_id'])) {
                throw new CrudError(['course_id' => 'Choose the course page this video appears on.']);
            }
        } else {
            $d['course_id'] = null;
        }
        $dupe = db_value('SELECT id FROM media_videos WHERE platform = ? AND video_id = ? AND placement = ? AND course_id <=> ?' . ($old ? ' AND id <> ?' : ''),
            array_merge([$d['platform'], $d['video_id'], $d['placement'], $d['course_id']], $old ? [$old['id']] : []));
        if ($dupe) {
            throw new CrudError(['url' => 'This video is already listed for this placement.']);
        }
        return $d;
    },
    'fields' => [
        'platform' => ['label' => 'Platform', 'type' => 'select', 'required' => true, 'default' => $tab, 'options' => VIDEO_PLATFORMS],
        'url' => ['label' => 'Video link', 'required' => true, 'max' => 500, 'full' => true,
            'placeholder' => $isYt ? 'https://www.youtube.com/watch?v=…  or  https://youtu.be/…' : 'https://www.instagram.com/reel/…',
            'hint' => 'YouTube: watch, youtu.be, Shorts, embed and live links. Instagram: /reel/, /reels/, /p/ and /tv/ links. The video id is checked when you save.'],
        'title' => ['label' => 'Title', 'required' => true, 'max' => 160],
        'sort_order' => ['label' => 'Display order', 'type' => 'number', 'default' => 0, 'min' => -999, 'max' => 9999, 'hint' => 'Lower numbers appear first.'],
        'description' => ['label' => 'Short description (optional)', 'type' => 'textarea', 'max' => 300, 'rows' => 2],
        'thumbnail_path' => ['label' => 'Cover image' . ($isYt ? ' (optional)' : ' (recommended)'), 'type' => 'image', 'folder' => 'cms', 'w' => 1280, 'h' => 1280,
            'hint' => 'YouTube videos use their own thumbnail when this is empty. Instagram reels show a branded placeholder without one.'],
        'placement' => ['label' => 'Show on', 'type' => 'select', 'required' => true, 'default' => 'home', 'options' => VIDEO_PLACEMENTS],
        'course_id' => ['label' => 'Course page', 'type' => 'select', 'options' => $courseOptions, 'show_if' => 'placement=course',
            'hint' => 'Only for "A specific course page" - the video then appears on that course\'s page.'],
        'is_active' => ['label' => 'Active (show on the website)', 'type' => 'checkbox', 'default' => 1],
    ],
    'form_extra' => fn(?array $row) => video_preview_widget($row),
]);

/** Live preview of the parsed link (the server re-checks it on save). */
function video_preview_widget(?array $row): string
{
    $custom = $row && $row['thumbnail_path'] ? public_file_url($row['thumbnail_path']) : '';
    ob_start(); ?>
<div class="card" id="vid-preview" style="display:flex;gap:16px;align-items:center;padding:14px;background:var(--surface);box-shadow:none" data-custom="<?= e((string) $custom) ?>">
  <div id="vp-frame" style="flex:none;width:192px;aspect-ratio:16/9;border-radius:10px;overflow:hidden;display:grid;place-items:center;background:#e8eef7;color:var(--muted)">
    <img id="vp-img" alt="" hidden style="width:100%;height:100%;object-fit:cover">
    <span id="vp-ph" style="font-size:12px;padding:8px;text-align:center">No preview yet</span>
  </div>
  <div style="min-width:0">
    <strong style="display:block;color:var(--navy)">Live preview</strong>
    <span id="vp-status" class="muted" aria-live="polite">Paste a link above to check it.</span>
    <div><a id="vp-open" href="#" target="_blank" rel="noopener noreferrer" hidden>Open link ↗</a></div>
  </div>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const url = $('f-url'), plat = $('f-platform'), img = $('vp-img'), ph = $('vp-ph'), status = $('vp-status'), open = $('vp-open'), frame = $('vp-frame');
  if (!url || !plat) return;
  const custom = $('vid-preview').dataset.custom;
  const parse = (u) => { u = u.trim(); try { return new URL(/^https?:\/\//i.test(u) ? u : 'https://' + u); } catch { return null; } };
  const yt = (u) => {
    if (/^[\w-]{11}$/.test(u.trim())) return u.trim();
    const x = parse(u); if (!x) return null;
    const h = x.hostname.toLowerCase().replace(/^(www\.|m\.|music\.)/, '');
    let id = '';
    if (h === 'youtu.be') id = x.pathname.split('/')[1] || '';
    else if (h === 'youtube.com' || h === 'youtube-nocookie.com') {
      const m = x.pathname.match(/^\/(?:shorts|embed|live|v|e)\/([^/?#]+)/);
      id = m ? m[1] : (x.pathname.replace(/\/$/, '') === '/watch' ? (x.searchParams.get('v') || '') : '');
    }
    return /^[\w-]{11}$/.test(id) ? id : null;
  };
  const ig = (u) => {
    const x = parse(u); if (!x) return null;
    const h = x.hostname.toLowerCase().replace(/^(www\.|m\.)/, '');
    if (h !== 'instagram.com' && h !== 'instagr.am') return null;
    const m = x.pathname.match(/^\/(?:[\w.]{1,30}\/)?(reels?|p|tv)\/([\w-]{5,40})\/?$/);
    return m ? { code: m[2], kind: m[1].startsWith('reel') ? 'reel' : m[1] } : null;
  };
  const show = (src) => { img.hidden = !src; ph.hidden = !!src; if (src) img.src = src; };
  const update = () => {
    const v = url.value;
    const isYt = plat.value === 'youtube';
    frame.style.width = isYt ? '192px' : '96px';
    frame.style.aspectRatio = isYt ? '16/9' : '9/16';
    frame.style.background = isYt ? '#e8eef7' : 'linear-gradient(160deg,#F58529,#DD2A7B 55%,#8134AF)';
    ph.style.color = isYt ? '' : '#fff';
    open.hidden = true;
    if (!v.trim()) { show(custom); ph.textContent = 'No preview yet'; status.textContent = 'Paste a link above to check it.'; return; }
    if (isYt) {
      const id = yt(v);
      if (!id) { show(''); ph.textContent = 'Not a YouTube link'; status.textContent = 'This does not look like a YouTube video link.'; return; }
      show(custom || 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg');
      status.textContent = 'YouTube video id: ' + id + (custom ? ' · using your cover image' : '');
      open.href = 'https://www.youtube.com/watch?v=' + id; open.hidden = false;
    } else {
      const r = ig(v);
      if (!r) { show(''); ph.textContent = 'Not an Instagram link'; status.textContent = 'Use a reel or post link like instagram.com/reel/CODE/.'; return; }
      show(custom); ph.textContent = 'Instagram ' + r.kind;
      status.textContent = 'Instagram shortcode: ' + r.code + (custom ? ' · using your cover image' : ' · upload a cover image for the best look');
      open.href = 'https://www.instagram.com/' + r.kind + '/' + r.code + '/'; open.hidden = false;
    }
  };
  url.addEventListener('input', update);
  plat.addEventListener('change', update);
  update();
})();
</script>
<?php
    return (string) ob_get_clean();
}
