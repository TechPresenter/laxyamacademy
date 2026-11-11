<?php
/**
 * Admin-managed website media: YouTube / Instagram videos, header highlights,
 * promotional banners and enquiry-popup defaults.
 *
 * Shared by the public API (api/cms.php), the admin screens (admin/videos.php,
 * admin/highlights.php, admin/banners.php, admin/popups.php) and the page
 * builder (includes/blocks.php). Public presenters never expose private paths.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const VIDEO_PLATFORMS = ['youtube' => 'YouTube', 'instagram' => 'Instagram'];
const VIDEO_PLACEMENTS = ['home' => 'Homepage', 'courses' => 'Courses page', 'about' => 'About page', 'course' => 'A specific course page'];
const HIGHLIGHT_BADGES = ['new' => 'New', 'hot' => 'Hot', 'live' => 'Live', 'result' => 'Result', 'admit_card' => 'Admit card', 'update' => 'Update', 'offer' => 'Offer'];
const BANNER_PLACEMENTS = ['home' => 'Homepage', 'courses' => 'Courses page', 'course_detail' => 'Course detail pages'];
const BANNER_THEMES = ['blue' => 'Blue', 'navy' => 'Navy', 'gold' => 'Gold', 'violet' => 'Violet', 'green' => 'Green', 'red' => 'Red'];
/** Swatch colours for the admin lists (the website maps the theme names to its own tokens). */
const BANNER_THEME_COLORS = ['blue' => '#0B63F6', 'navy' => '#08264D', 'gold' => '#FFC928', 'violet' => '#6D4AFF', 'green' => '#0EA5A4', 'red' => '#F04E23'];
const POPUP_CONSENT_DEFAULT = 'I agree to be contacted by Laxyam Academy by phone, WhatsApp, SMS or email about my enquiry.';
const POPUP_SUCCESS_DEFAULT = 'Thank you! Our counsellor will contact you shortly.';
const JOB_ALERT_TYPES = ['notification' => 'Recruitment notification', 'admit_card' => 'Admit card', 'result' => 'Result', 'answer_key' => 'Answer key', 'syllabus' => 'Syllabus'];

// ------------------------------------------------------------------
// Video links
// ------------------------------------------------------------------

/** parse_url() for links pasted with or without the scheme; null for anything that is not http(s). */
function media_url_parts(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || preg_match('/\s/', $url)) {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    $p['host'] = strtolower((string) preg_replace('/^(www\.|m\.|music\.)/i', '', $p['host']));
    $p['path'] = $p['path'] ?? '/';
    return $p;
}

/**
 * 11-character YouTube video id from a watch, youtu.be, shorts, embed or live link
 * (or a bare id). Null when the link is not a YouTube video.
 */
function youtube_video_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[\w-]{11}$/', $url)) {
        return $url;
    }
    $p = media_url_parts($url);
    if (!$p) {
        return null;
    }
    $id = '';
    if ($p['host'] === 'youtu.be') {
        $id = explode('/', trim($p['path'], '/'))[0] ?? '';
    } elseif (in_array($p['host'], ['youtube.com', 'youtube-nocookie.com'], true)) {
        if (preg_match('#^/(?:shorts|embed|live|v|e)/([^/?\#]+)#', $p['path'], $m)) {
            $id = $m[1];
        } elseif (rtrim($p['path'], '/') === '/watch') {
            parse_str($p['query'] ?? '', $q);
            $id = is_string($q['v'] ?? null) ? $q['v'] : '';
        }
    }
    return preg_match('/^[\w-]{11}$/', $id) ? $id : null;
}

/** Instagram shortcode + link kind (reel | p | tv) from a reel, reels, post or IGTV link. */
function instagram_shortcode(string $url): ?array
{
    $p = media_url_parts($url);
    if (!$p || !in_array($p['host'], ['instagram.com', 'instagr.am'], true)) {
        return null;
    }
    // instagram.com/reel/CODE/, /reels/CODE/, /p/CODE/, /tv/CODE/ and the newer /<username>/reel/CODE/
    if (!preg_match('#^/(?:[\w.]{1,30}/)?(reels?|p|tv)/([\w-]{5,40})/?$#', $p['path'], $m)) {
        return null;
    }
    return ['code' => $m[2], 'kind' => str_starts_with($m[1], 'reel') ? 'reel' : $m[1]];
}

/**
 * Validate a pasted video link for a platform.
 * Returns ['id' => video id / shortcode, 'url' => canonical link] or null when it does not match.
 */
function parse_video_url(string $platform, string $url): ?array
{
    if ($platform === 'youtube') {
        $id = youtube_video_id($url);
        return $id ? ['id' => $id, 'url' => 'https://www.youtube.com/watch?v=' . $id] : null;
    }
    if ($platform === 'instagram') {
        $ig = instagram_shortcode($url);
        return $ig ? ['id' => $ig['code'], 'url' => 'https://www.instagram.com/' . $ig['kind'] . '/' . $ig['code'] . '/'] : null;
    }
    return null;
}

function video_thumbnail_url(string $platform, string $videoId, ?string $customPath): ?string
{
    if ($customPath) {
        return public_file_url($customPath);
    }
    return $platform === 'youtube' ? 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg' : null;
}

function video_embed_url(string $platform, string $videoId): string
{
    return $platform === 'youtube'
        ? 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId)
        : 'https://www.instagram.com/p/' . rawurlencode($videoId) . '/embed/';
}

/** Public shape of one media_videos row. */
function format_media_video(array $v): array
{
    return [
        'id' => (int) $v['id'],
        'platform' => $v['platform'],
        'title' => $v['title'],
        'description' => ($v['description'] ?? '') !== '' ? $v['description'] : null,
        'video_id' => $v['video_id'],
        'url' => $v['url'],
        'thumbnail_url' => video_thumbnail_url($v['platform'], $v['video_id'], $v['thumbnail_path'] ?? null),
        'embed_url' => video_embed_url($v['platform'], $v['video_id']),
    ];
}

/**
 * Video sections for a placement: { youtube: {heading, items}, instagram: {heading, items} }.
 * Headings come from cms_sections videos_youtube / videos_instagram (null when hidden).
 * On the homepage a hidden heading hides that whole platform section.
 */
function media_video_sections(string $placement, ?int $courseId = null, int $limit = 12): array
{
    $heads = [];
    foreach (db_all("SELECT section_key, content, is_active FROM cms_sections WHERE section_key IN ('videos_youtube', 'videos_instagram')") as $r) {
        $heads[$r['section_key']] = $r['is_active'] ? json_decode_array($r['content']) : null;
    }
    $out = [];
    foreach (['youtube' => 'channel_url', 'instagram' => 'profile_url'] as $platform => $linkKey) {
        $h = $heads['videos_' . $platform] ?? null;
        $heading = $h !== null ? [
            'title' => (string) ($h['title'] ?? ''),
            'subtitle' => (string) ($h['subtitle'] ?? ''),
            $linkKey => is_string($h[$linkKey] ?? null) && preg_match('#^https://\S+$#i', $h[$linkKey]) ? $h[$linkKey] : null,
        ] : null;
        $items = [];
        if ($heading !== null || $placement !== 'home') {
            $where = 'is_active = 1 AND platform = ? AND placement = ?';
            $params = [$platform, $placement];
            if ($placement === 'course') {
                $where .= ' AND course_id = ?';
                $params[] = (int) $courseId;
            }
            $items = array_map('format_media_video', db_all(
                "SELECT id, platform, title, url, video_id, description, thumbnail_path FROM media_videos WHERE {$where} ORDER BY sort_order, id DESC LIMIT " . max(1, min(50, $limit)),
                $params
            ));
        }
        $out[$platform] = ['heading' => $heading, 'items' => $items];
    }
    return $out;
}

// ------------------------------------------------------------------
// Links, schedules, highlights and banners
// ------------------------------------------------------------------

/** A link staff may use on the website: a site path ("/courses") or a full https:// URL. */
function is_site_link(?string $url): bool
{
    return is_string($url) && (preg_match('#^/(?!/)[^\s<>"]*$#', $url) === 1 || preg_match('#^https://[^\s<>"]+$#i', $url) === 1);
}

/** SQL condition for rows inside their optional starts_at / ends_at window. */
function media_window_sql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}is_active = 1 AND ({$a}starts_at IS NULL OR {$a}starts_at <= NOW()) AND ({$a}ends_at IS NULL OR {$a}ends_at > NOW())";
}

/** live | scheduled | expired | inactive - for admin lists. */
function schedule_state(array $row): string
{
    if (empty($row['is_active'])) {
        return 'inactive';
    }
    if (!empty($row['ends_at']) && strtotime($row['ends_at']) <= time()) {
        return 'expired';
    }
    if (!empty($row['starts_at']) && strtotime($row['starts_at']) > time()) {
        return 'scheduled';
    }
    return 'live';
}

/** Admin list cell: schedule state badge + window ("from … until …"). */
function schedule_cell(array $row): string
{
    $state = schedule_state($row);
    [$tone, $label] = ['live' => ['green', 'Live now'], 'scheduled' => ['blue', 'Scheduled'], 'expired' => ['purple', 'Ended'], 'inactive' => ['gray', 'Hidden']][$state];
    $fmt = fn(?string $d) => $d ? date('j M Y, g:i A', strtotime($d)) : null;
    $window = array_filter([$fmt($row['starts_at'] ?? null) ? 'from ' . $fmt($row['starts_at']) : null, $fmt($row['ends_at'] ?? null) ? 'until ' . $fmt($row['ends_at']) : null]);
    return '<span class="badge b-' . $tone . '">' . e($label) . '</span><br><span class="muted" style="font-size:12px">' . e($window ? implode(' ', $window) : 'No end date') . '</span>';
}

/** SQL conditions for the schedule tabs on the highlights / banners screens. */
function schedule_tab_sql(string $tab): string
{
    return match ($tab) {
        'live' => 't.is_active = 1 AND (t.starts_at IS NULL OR t.starts_at <= NOW()) AND (t.ends_at IS NULL OR t.ends_at > NOW())',
        'scheduled' => 't.is_active = 1 AND t.starts_at > NOW() AND (t.ends_at IS NULL OR t.ends_at > NOW())',
        'expired' => 't.ends_at IS NOT NULL AND t.ends_at <= NOW()',
        'inactive' => 't.is_active = 0',
        default => '1=1',
    };
}

/** Active highlights for the header ticker / menu. */
function fetch_site_highlights(int $limit = 12): array
{
    return array_map(fn($h) => [
        'id' => (int) $h['id'],
        'title' => $h['title'],
        'url' => is_site_link($h['url']) ? $h['url'] : null,
        'badge' => $h['badge'],
    ], db_all('SELECT id, title, url, badge FROM site_highlights WHERE ' . media_window_sql() . ' ORDER BY sort_order, id DESC LIMIT ' . max(1, min(50, $limit))));
}

/** Active promotional banners for a placement. */
function fetch_promo_banners(string $placement, int $limit = 6): array
{
    if (!array_key_exists($placement, BANNER_PLACEMENTS)) {
        return [];
    }
    return array_map(fn($b) => [
        'id' => (int) $b['id'],
        'title' => $b['title'],
        'subtitle' => ($b['subtitle'] ?? '') !== '' ? $b['subtitle'] : null,
        'image_url' => public_file_url($b['image_path']),
        'cta_label' => ($b['cta_label'] ?? '') !== '' && is_site_link($b['cta_url']) ? $b['cta_label'] : null,
        'cta_url' => is_site_link($b['cta_url']) ? $b['cta_url'] : null,
        'theme' => $b['theme'],
    ], db_all(
        'SELECT id, title, subtitle, image_path, cta_label, cta_url, theme FROM promo_banners WHERE placement = ? AND ' . media_window_sql()
        . ' ORDER BY sort_order, id DESC LIMIT ' . max(1, min(20, $limit)),
        [$placement]
    ));
}
