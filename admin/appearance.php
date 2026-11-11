<?php
// Website appearance, in panels: Branding (colours), Logo & Favicon, Theme (presets + corners),
// Typography (body font) and Layout (admin panel layout on this device).
// Website values are injected as CSS variables by the front controller (index.php); the admin
// brand (sidebar logo, favicon) uses the uploaded logo and favicon too.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('settings.manage');

const THEME_FONTS = [
    'Inter' => ['Inter (default)', 'Self-hosted, crisp and very readable on phones.'],
    'Poppins' => ['Poppins', 'Geometric and friendly. Loaded from Google Fonts.'],
    'Roboto' => ['Roboto', 'Neutral Android-style font. Loaded from Google Fonts.'],
    'Noto Sans' => ['Noto Sans', 'Good Hindi (Devanagari) support. Loaded from Google Fonts.'],
    'Mukta' => ['Mukta', 'Designed for Hindi + English. Loaded from Google Fonts.'],
    'system' => ['System font', 'Fastest - uses each device’s own font, nothing to download.'],
];
const THEME_RADIUS = ['sharp' => ['Sharp', '4-10px corners', '4px'], 'standard' => ['Standard (default)', '8-24px corners', '12px'], 'rounded' => ['Extra rounded', '12-30px corners', '20px']];
/** One-click colour themes: [label, primary, navy, accent]. White text must stay readable on primary and navy. */
const THEME_PRESETS = [
    'laxyam' => ['Laxyam (default)', '#0B63F6', '#08264D', '#FFC928'],
    'royal' => ['Royal Indigo', '#4F46E5', '#1E1B4B', '#F59E0B'],
    'emerald' => ['Emerald', '#047857', '#064E3B', '#FBBF24'],
    'saffron' => ['Saffron', '#C2410C', '#431407', '#FACC15'],
    'crimson' => ['Crimson', '#DC2626', '#1F2937', '#FBBF24'],
    'ocean' => ['Ocean Teal', '#0F766E', '#0C4A6E', '#FFC928'],
];
const APPEARANCE_PANELS = [
    'branding' => ['Branding', 'palette'],
    'logo' => ['Logo & Favicon', 'image'],
    'theme' => ['Theme', 'sparkles'],
    'typography' => ['Typography', 'type'],
    'layout' => ['Layout Preferences', 'layout'],
];
$defaults = ['theme_primary' => '#0B63F6', 'theme_navy' => '#08264D', 'theme_gold' => '#FFC928', 'theme_font' => 'Inter', 'theme_radius' => 'standard'];

/** Save a square PNG favicon set (32, 180, 192, 512) from an uploaded image. Returns the base path. */
function store_favicon(array $file): string
{
    $tmp = store_image_upload($file, 'branding', 512, 512, true, 4_194_304);
    $src = imagecreatefromwebp(APP_ROOT . '/' . $tmp);
    $base = 'uploads/branding/favicon-' . bin2hex(random_bytes(4));
    foreach ([32, 180, 192, 512] as $s) {
        $dst = imagecreatetruecolor($s, $s);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $s, $s, imagesx($src), imagesy($src));
        imagepng($dst, APP_ROOT . '/' . $base . '-' . $s . '.png', 9);
    }
    @unlink(APP_ROOT . '/' . $tmp);
    return $base;
}

/** Upsert one public appearance setting (group and visibility are kept in sync too). */
function save_setting(string $k, string $v, int $public, int $by): void
{
    db_query('INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), is_public = VALUES(is_public), updated_by = VALUES(updated_by)',
        [$k, $v, 'appearance', $public, $by]);
}

/** WCAG contrast ratio between two #RRGGBB colours. */
function contrast_ratio(string $a, string $b): float
{
    $lum = function (string $hex): float {
        $c = array_map(fn($x) => $x / 255, sscanf($hex, '#%02x%02x%02x'));
        $c = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$l1, $l2] = [$lum($a), $lum($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

$errors = [];
$panel = 'branding';
if (admin_post()) {
    $do = (string) ($_POST['do'] ?? 'branding');
    $panel = in_array($_POST['panel'] ?? '', array_keys(APPEARANCE_PANELS), true) ? (string) $_POST['panel'] : 'branding';
    $back = fn(string $p) => redirect_to('appearance.php#' . $p);
    if ($do === 'reset') {
        foreach ($defaults as $k => $v) {
            save_setting($k, $v, 1, (int) $me['id']);
        }
        audit_log('appearance_reset', 'site_settings', null, [], 'warning');
        flash('success', 'Colours, font and corners reset to the Laxyam defaults.');
        $back($panel);
    }
    if ($do === 'remove_logo' || $do === 'remove_favicon') {
        save_setting($do === 'remove_logo' ? 'logo_path' : 'favicon_path', '', 1, (int) $me['id']);
        audit_log('appearance_' . $do, 'site_settings');
        flash('success', $do === 'remove_logo' ? 'Custom logo removed - the default logo is back.' : 'Custom favicon removed - the default icon is back.');
        $back('logo');
    }
    if ($do === 'preset') {
        $key = (string) ($_POST['preset'] ?? '');
        if (!isset(THEME_PRESETS[$key])) {
            flash('error', 'Choose one of the listed themes.');
            $back('theme');
        }
        [$label, $p, $n, $g] = THEME_PRESETS[$key];
        foreach (['theme_primary' => $p, 'theme_navy' => $n, 'theme_gold' => $g] as $k => $v) {
            save_setting($k, $v, 1, (int) $me['id']);
        }
        audit_log('appearance_preset_applied', 'site_settings', $key, [], 'warning');
        flash('success', $label . ' theme applied. Refresh the website to see it.');
        $back('theme');
    }
    $vals = [];
    if ($do === 'branding') {
        foreach (['theme_primary', 'theme_navy', 'theme_gold'] as $k) {
            $v = strtoupper(trim((string) ($_POST[$k] ?? '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $v)) {
                $errors[$k] = 'Use a 6-digit hex colour like #0B63F6.';
            }
            $vals[$k] = $v;
        }
    } elseif ($do === 'theme') {
        if (!array_key_exists($_POST['theme_radius'] ?? '', THEME_RADIUS)) {
            $errors['theme_radius'] = 'Choose a corner style.';
        }
        $vals['theme_radius'] = (string) ($_POST['theme_radius'] ?? 'standard');
    } elseif ($do === 'typography') {
        if (!array_key_exists($_POST['theme_font'] ?? '', THEME_FONTS)) {
            $errors['theme_font'] = 'Choose one of the listed fonts.';
        }
        $vals['theme_font'] = (string) ($_POST['theme_font'] ?? 'Inter');
    } elseif ($do === 'logo') {
        $hasLogo = !empty($_FILES['logo']['name']);
        $hasFav = !empty($_FILES['favicon']['name']);
        if (!$hasLogo && !$hasFav) {
            $errors['logo'] = 'Choose a logo or a favicon image to upload.';
        }
        try {
            if ($hasLogo) {
                $vals['logo_path'] = store_image_upload($_FILES['logo'], 'branding', 900, 300, false, 4_194_304);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $errors['logo'] = $e->getMessage();
        }
        try {
            if ($hasFav) {
                $vals['favicon_path'] = store_favicon($_FILES['favicon']);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $errors['favicon'] = $e->getMessage();
        }
    } else {
        flash('error', 'Unknown action.');
        $back($panel);
    }
    if (!$errors) {
        foreach ($vals as $k => $v) {
            save_setting($k, $v, 1, (int) $me['id']);
        }
        audit_log('appearance_updated', 'site_settings', null, ['keys' => array_keys($vals)], 'warning');
        flash('success', APPEARANCE_PANELS[$panel][0] . ' saved. Refresh the website to see the change.');
        $back($panel);
    }
    flash('error', 'Please correct the highlighted fields.');
}

$cur = settings_all() + $defaults;
$val = fn($k) => (string) ($_POST[$k] ?? (($cur[$k] ?? '') !== '' ? $cur[$k] : ($defaults[$k] ?? '')));
$err = fn($k) => isset($errors[$k]) ? '<div class="err" id="err-' . e($k) . '">' . icon('alert') . ' ' . e($errors[$k]) . '</div>' : '';
$inv = fn($k) => isset($errors[$k]) ? ' invalid' : '';
$logoUrl = public_file_url(($cur['logo_path'] ?? '') ?: 'assets/logo.png');
$favUrl = public_file_url(($cur['favicon_path'] ?? '') ? $cur['favicon_path'] . '-192.png' : 'assets/icons/icon-192.png');
$radiusPx = THEME_RADIUS[$val('theme_radius')][2] ?? '12px';
$previewVars = '--p:' . e($val('theme_primary')) . ';--n:' . e($val('theme_navy')) . ';--g:' . e($val('theme_gold')) . ';--r:' . e($radiusPx);
$hidden = fn(string $p, string $do) => csrf_field() . '<input type="hidden" name="panel" value="' . $p . '"><input type="hidden" name="do" value="' . $do . '">';

admin_start('Appearance', null, 'appearance', ['head' => true, 'subtitle' => 'Brand colours, logo, theme, typography and layout used across the public website and this admin panel.',
    'actions' => '<a class="btn btn-light" href="' . e(app_url('')) . '" target="_blank" rel="noopener">' . icon('external') . ' View website</a>']);
// Alternative body fonts are previewed in their real typeface (the public site loads them the same way).
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@600&family=Roboto:wght@500&family=Noto+Sans:wght@600&family=Mukta:wght@600&display=swap">';

/** The live preview card shown beside the colour, theme and font forms. */
$preview = function () use ($previewVars, $val): string {
    $font = $val('theme_font') === 'system' ? 'system-ui, sans-serif' : '"' . $val('theme_font') . '", Inter, sans-serif';
    return '<div class="card sticky-aside"><div class="card-head"><h2>' . icon('eye') . ' Live preview</h2><span class="muted" style="font-size:12px">Not saved until you click Save</span></div><div class="card-pad">'
        . '<div class="ap-preview" data-theme-preview style="' . $previewVars . ';font-family:' . e($font) . '">'
        . '<div class="bar"><span>Helpline · 9:00 AM - 7:00 PM</span><span>Login</span></div>'
        . '<div class="stage"><div class="h">Learn. Prepare. <span>Achieve.</span></div>'
        . '<p class="muted" style="margin:6px 0 14px">UPSC, SSC, Banking and State PSC preparation with expert faculty.</p>'
        . '<span class="cta">Explore courses</span><span class="pill">New</span>'
        . '<div class="tile-demo"><i></i><div><strong style="color:var(--n)">Course card</strong><div class="muted" style="font-size:12px">Cards and buttons follow the corner style</div></div></div></div></div>'
        . '<div class="alert alert-warning" data-contrast-warn hidden style="margin:14px 0 0">' . icon('alert') . '<span>White text is hard to read on one of these colours. Pick a darker primary or navy.</span></div>'
        . '</div></div>';
};
?>
<div data-tabs="appearance">
  <div class="tab-list" aria-label="Appearance sections">
    <?php foreach (APPEARANCE_PANELS as $pid => [$label, $ico]): ?>
      <button type="button" data-tab="<?= $pid ?>"><?= icon($ico) ?> <?= e($label) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ===================== Branding: brand colours ===================== -->
  <section data-tab-panel="branding" aria-label="Branding">
    <div class="split-13-1">
      <form method="post" class="card" novalidate data-dirty-check data-theme-form>
        <?= $hidden('branding', 'branding') ?>
        <div class="card-head"><div><h2><?= icon('palette') ?> Brand colours</h2><p class="muted">Used for buttons, links, the header bar, footer and highlights on the website.</p></div></div>
        <div class="card-pad form">
          <?php foreach (['theme_primary' => ['Primary', 'Buttons, links and active states'], 'theme_navy' => ['Navy', 'Top bar, footer and headings'], 'theme_gold' => ['Accent', 'Badges, highlights and offers']] as $k => [$label, $help]): ?>
            <div class="field<?= $inv($k) ?>">
              <label for="c-<?= $k ?>"><?= e($label) ?></label>
              <div class="color-row">
                <input type="color" value="<?= e($val($k)) ?>" aria-label="<?= e($label) ?> colour picker" data-color-for="c-<?= $k ?>">
                <input id="c-<?= $k ?>" type="text" name="<?= $k ?>" value="<?= e($val($k)) ?>" maxlength="7" data-theme-var="<?= $k ?>" spellcheck="false" autocomplete="off"<?= isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '' ?>>
              </div>
              <div class="hint"><?= e($help) ?></div>
              <?= $err($k) ?>
            </div>
          <?php endforeach; ?>
          <div class="field full"><div class="alert alert-info" style="margin:0"><?= icon('info') ?><span>Contrast check: white text needs a ratio of at least 3:1 on the primary and navy colours. Current ratios - primary <strong data-ratio="theme_primary"><?= number_format(contrast_ratio($val('theme_primary'), '#FFFFFF'), 1) ?>:1</strong>, navy <strong data-ratio="theme_navy"><?= number_format(contrast_ratio($val('theme_navy'), '#FFFFFF'), 1) ?>:1</strong>.</span></div></div>
        </div>
        <div class="form-actions">
          <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
          <button class="btn btn-primary"><?= icon('check') ?> Save colours</button>
        </div>
      </form>
      <?= $preview() ?>
    </div>
  </section>

  <!-- ===================== Logo & favicon ===================== -->
  <section data-tab-panel="logo" aria-label="Logo and favicon">
    <div class="split-13-1">
      <form method="post" class="card" enctype="multipart/form-data" novalidate>
        <?= $hidden('logo', 'logo') ?>
        <div class="card-head"><div><h2><?= icon('image') ?> Logo &amp; favicon</h2><p class="muted">Shown in the website header, footer, emails, invoices and this admin panel.</p></div></div>
        <div class="card-pad form">
          <div class="field full<?= $inv('logo') ?>">
            <label for="c-logo">Upload a new logo</label>
            <input id="c-logo" type="file" name="logo" accept="image/png,image/webp,image/jpeg" data-preview="logo-pv"<?= isset($errors['logo']) ? ' aria-invalid="true" aria-describedby="err-logo"' : '' ?>>
            <div class="hint">PNG or WebP with a transparent background, wide format (about 3:1), up to 4 MB. Resized to fit 900×300.</div>
            <?= $err('logo') ?>
          </div>
          <div class="field full<?= $inv('favicon') ?>">
            <label for="c-fav">Upload a new favicon / app icon</label>
            <input id="c-fav" type="file" name="favicon" accept="image/png,image/webp,image/jpeg" data-preview="fav-pv"<?= isset($errors['favicon']) ? ' aria-invalid="true" aria-describedby="err-favicon"' : '' ?>>
            <div class="hint">A square image, at least 192×192. The 32, 180, 192 and 512 px sizes are created automatically.</div>
            <?= $err('favicon') ?>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary"><?= icon('upload') ?> Upload</button>
        </div>
      </form>
      <div class="card">
        <div class="card-head"><h2><?= icon('eye') ?> Current</h2></div>
        <div class="card-pad stack-y">
          <div>
            <span class="lbl">Logo on light and dark backgrounds</span>
            <div class="logo-stage"><img id="logo-pv" src="<?= e($logoUrl) ?>" alt="Current logo"></div>
            <div class="logo-stage dark mt-6"><span style="background:#fff;border-radius:12px;padding:6px 10px;display:inline-flex"><img src="<?= e($logoUrl) ?>" alt="Current logo in the admin sidebar" style="max-height:40px"></span><span class="muted" style="color:#c9d6ee">Admin sidebar</span></div>
            <p class="hint" style="margin:6px 0 0"><?= !empty($cur['logo_path']) ? 'A custom logo is in use.' : 'The default Laxyam logo is in use.' ?></p>
          </div>
          <div>
            <span class="lbl">Favicon</span>
            <div style="display:flex;gap:12px;align-items:center">
              <img id="fav-pv" src="<?= e($favUrl) ?>" alt="Current favicon" width="56" height="56" style="border:1px solid var(--line);border-radius:14px;background:#fff">
              <span class="muted" style="font-size:13px"><?= !empty($cur['favicon_path']) ? 'A custom favicon is in use.' : 'The default icon is in use.' ?></span>
            </div>
          </div>
          <?php if (!empty($cur['logo_path']) || !empty($cur['favicon_path'])): ?>
          <form method="post" class="ph-actions">
            <?= csrf_field() ?><input type="hidden" name="panel" value="logo">
            <?php if (!empty($cur['logo_path'])): ?><button class="btn btn-danger btn-sm" name="do" value="remove_logo" data-confirm="Remove the custom logo and go back to the default Laxyam logo?"><?= icon('trash') ?> Remove logo</button><?php endif; ?>
            <?php if (!empty($cur['favicon_path'])): ?><button class="btn btn-danger btn-sm" name="do" value="remove_favicon" data-confirm="Remove the custom favicon and go back to the default icon?"><?= icon('trash') ?> Remove favicon</button><?php endif; ?>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================== Theme: presets + corner style ===================== -->
  <section data-tab-panel="theme" aria-label="Theme">
    <div class="split-13-1">
      <div class="stack-y">
        <form method="post" class="card">
          <?= $hidden('theme', 'preset') ?>
          <div class="card-head"><div><h2><?= icon('sparkles') ?> Colour themes</h2><p class="muted">Apply a ready-made, readable colour set in one click. Fine-tune it later under Branding.</p></div></div>
          <div class="card-pad ap-swatches">
            <?php foreach (THEME_PRESETS as $key => [$label, $p, $n, $g]):
                $active = strtoupper($val('theme_primary')) === $p && strtoupper($val('theme_navy')) === $n && strtoupper($val('theme_gold')) === $g; ?>
              <button class="ap-swatch" name="preset" value="<?= e($key) ?>" data-confirm="Apply the <?= e($label) ?> colours to the website?"<?= $active ? ' aria-current="true" style="border-color:var(--primary)"' : '' ?>>
                <span class="dots"><i style="background:<?= e($p) ?>"></i><i style="background:<?= e($n) ?>"></i><i style="background:<?= e($g) ?>"></i></span>
                <strong><?= e($label) ?></strong>
                <span class="muted" style="font-size:12px"><?= $active ? 'In use' : 'Apply' ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </form>
        <form method="post" class="card" novalidate data-dirty-check>
          <?= $hidden('theme', 'theme') ?>
          <div class="card-head"><div><h2><?= icon('layout') ?> Corner style</h2><p class="muted">Roundness of cards, buttons, images and inputs on the website.</p></div></div>
          <div class="card-pad">
            <div class="radius-cards" role="radiogroup" aria-label="Corner style">
              <?php foreach (THEME_RADIUS as $k => [$label, $sub, $px]): ?>
                <label class="font-card"><input type="radio" name="theme_radius" value="<?= e($k) ?>" data-radius="<?= e($px) ?>"<?= $val('theme_radius') === $k ? ' checked' : '' ?>>
                  <span class="shape" style="display:block;border-radius:<?= e($px) ?>"></span><strong><?= e($label) ?></strong><small><?= e($sub) ?></small></label>
              <?php endforeach; ?>
            </div>
            <?= $err('theme_radius') ?>
          </div>
          <div class="form-actions">
            <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
            <button class="btn btn-ghost" name="do" value="reset" data-confirm="Reset colours, font and corners to the Laxyam defaults?"><?= icon('refresh') ?> Reset all to defaults</button>
            <button class="btn btn-primary"><?= icon('check') ?> Save corner style</button>
          </div>
        </form>
      </div>
      <?= $preview() ?>
    </div>
  </section>

  <!-- ===================== Typography ===================== -->
  <section data-tab-panel="typography" aria-label="Typography">
    <div class="split-13-1">
      <form method="post" class="card" novalidate data-dirty-check>
        <?= $hidden('typography', 'typography') ?>
        <div class="card-head"><div><h2><?= icon('type') ?> Body font</h2><p class="muted">Used for paragraphs, forms and navigation. Headings always use Plus Jakarta Sans (self-hosted).</p></div></div>
        <div class="card-pad">
          <div class="font-cards" role="radiogroup" aria-label="Body font">
            <?php foreach (THEME_FONTS as $k => [$label, $sub]): $fam = $k === 'system' ? 'system-ui, sans-serif' : '"' . $k . '", Inter, sans-serif'; ?>
              <label class="font-card"><input type="radio" name="theme_font" value="<?= e($k) ?>" data-font="<?= e($fam) ?>"<?= $val('theme_font') === $k ? ' checked' : '' ?>>
                <span class="sample" style="font-family:<?= e($fam) ?>">Aa · अआ · 123</span><strong style="display:block;margin-top:6px"><?= e($label) ?></strong><small><?= e($sub) ?></small></label>
            <?php endforeach; ?>
          </div>
          <?= $err('theme_font') ?>
        </div>
        <div class="form-actions">
          <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
          <button class="btn btn-primary"><?= icon('check') ?> Save font</button>
        </div>
      </form>
      <?= $preview() ?>
    </div>
  </section>

  <!-- ===================== Layout preferences (this device) ===================== -->
  <section data-tab-panel="layout" aria-label="Layout preferences">
    <div class="split-13-1">
      <div class="card">
        <div class="card-head"><div><h2><?= icon('layout') ?> Admin panel layout</h2><p class="muted">Saved in this browser only, so each staff member can set their own.</p></div></div>
        <div class="card-pad stack-y">
          <label class="switch"><input type="checkbox" value="1" data-pref="collapsed"><span class="track"></span> <span><strong>Compact sidebar</strong><br><span class="muted" style="font-size:12.5px">Show only module icons on wide screens (hover for names).</span></span></label>
          <div class="field" style="max-width:320px">
            <label for="ap-density">Table density</label>
            <select id="ap-density" class="in" data-pref="density"><option value="comfortable">Comfortable</option><option value="compact">Compact (more rows on screen)</option></select>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2><?= icon('info') ?> Good to know</h2></div>
        <div class="card-pad">
          <ul style="margin:0;padding-left:18px;line-height:1.8">
            <li>Every module shows its screens as tabs under the page title.</li>
            <li>Press <kbd>Ctrl</kbd> + <kbd>K</kbd> (or <kbd>/</kbd>) anywhere to search students, orders, enquiries and screens.</li>
            <li>Your password, sessions and two-factor login are under <a href="profile.php">My profile</a>.</li>
          </ul>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
(function () {
  var hex = /^#[0-9a-f]{6}$/i;
  var vars = { theme_primary: '--p', theme_navy: '--n', theme_gold: '--g' };
  var previews = document.querySelectorAll('[data-theme-preview]');
  function lum(h) {
    return [1, 3, 5].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); })
      .reduce(function (s, v, i) { return s + v * [0.2126, 0.7152, 0.0722][i]; }, 0);
  }
  function ratio(h) { return 1.05 / (lum(h) + 0.05); }
  function set(name, value) { previews.forEach(function (p) { p.style.setProperty(name, value); }); }
  function check() {
    var low = false;
    ['theme_primary', 'theme_navy'].forEach(function (k) {
      var el = document.querySelector('[data-theme-var="' + k + '"]');
      if (!el || !hex.test(el.value)) return;
      var r = ratio(el.value);
      var out = document.querySelector('[data-ratio="' + k + '"]');
      if (out) out.textContent = r.toFixed(1) + ':1';
      if (r < 3) low = true;
    });
    document.querySelectorAll('[data-contrast-warn]').forEach(function (w) { w.hidden = !low; });
  }
  document.querySelectorAll('[data-color-for]').forEach(function (picker) {
    var text = document.getElementById(picker.dataset.colorFor);
    picker.addEventListener('input', function () { text.value = picker.value.toUpperCase(); text.dispatchEvent(new Event('input', { bubbles: true })); });
  });
  document.querySelectorAll('[data-theme-var]').forEach(function (i) {
    i.addEventListener('input', function () {
      if (!hex.test(i.value)) return;
      set(vars[i.dataset.themeVar], i.value);
      var picker = document.querySelector('[data-color-for="' + i.id + '"]');
      if (picker) picker.value = i.value;
      check();
    });
  });
  document.querySelectorAll('[data-radius]').forEach(function (r) { r.addEventListener('change', function () { set('--r', r.dataset.radius); }); });
  document.querySelectorAll('[data-font]').forEach(function (r) { r.addEventListener('change', function () { previews.forEach(function (p) { p.style.fontFamily = r.dataset.font; }); }); });
  check();
})();
</script>
<?php admin_end();
