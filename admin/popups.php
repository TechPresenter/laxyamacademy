<?php
// Popup manager: promotional popups and enquiry-form popups shown on the website
// (with page targeting, delay and frequency). Enquiry popups save leads to Enquiries (source "Website popup").
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/media.php';

const POPUP_KINDS = ['promo' => 'Promotion (text, image, button)', 'enquiry' => 'Enquiry form (name, phone, course)'];

/** "*" → All pages; otherwise each path pattern as a small chip ("/courses*" → "/courses…"). */
function popup_pages_cell(string $showOn): string
{
    $paths = array_filter(array_map('trim', explode(',', $showOn)));
    if (!$paths || in_array('*', $paths, true)) {
        return '<span class="badge b-blue">All pages</span>';
    }
    return '<div class="d2-chips">' . implode('', array_map(fn($p) => '<code title="' . e($p) . (str_ends_with($p, '*') ? ' and every page under it' : '') . '">'
        . e($p === '/' ? 'Home page' : rtrim($p, '*') . (str_ends_with($p, '*') ? '…' : '')) . '</code>', $paths)) . '</div>';
}

/** Live, scheduled, ended or switched off - what visitors get right now. */
function popup_state_badge(array $r): string
{
    $now = time();
    return match (true) {
        !(int) $r['is_active'] => '<span class="badge b-gray">Inactive</span>',
        $r['ends_at'] && strtotime($r['ends_at']) <= $now => '<span class="badge b-purple">Ended</span>',
        $r['starts_at'] && strtotime($r['starts_at']) > $now => '<span class="badge b-blue">Starts ' . e(fmt_dt($r['starts_at'], 'j M')) . '</span>',
        default => '<span class="badge b-green">Live</span>',
    };
}

/** Views → clicks (promo) or views → form submissions (enquiry) as a rate. */
$rate = fn(int $hits, int $views) => $views > 0 ? ' <span class="muted">(' . round($hits / $views * 100, 1) . '%)</span>' : '';

crud([
    'table' => 'popups',
    'title' => 'Popup Manager',
    'singular' => 'popup',
    'permission' => 'cms.manage',
    'active' => 'cms',
    'subtitle' => 'Popups appear after a delay on matching pages. Visitors can always close them; they never block checkout or tests. Enquiry popups collect a call-back request with consent.',
    'header_actions' => can('enquiries.view') ? '<a class="btn btn-light" href="enquiries.php?source=popup">' . icon('mail') . ' Popup leads</a>' : '',
    'order' => 't.is_active DESC, t.id DESC',
    'search' => ['t.title', 't.body', 't.form_title'],
    'filters' => [
        'kind' => ['col' => 't.kind', 'label' => 'Types', 'options' => ['promo' => 'Promotion', 'enquiry' => 'Enquiry form']],
        'active' => ['col' => 't.is_active', 'label' => 'Statuses', 'options' => ['1' => 'Active', '0' => 'Inactive']],
    ],
    'bulk_status' => ['field' => 'is_active', 'label' => 'status', 'options' => ['1' => 'Active', '0' => 'Inactive']],
    'columns' => [
        'title' => ['Popup', fn($r) => '<div class="d2-w200"><strong>' . e($r['title']) . '</strong> ' . ($r['kind'] === 'enquiry' ? '<span class="badge b-purple">Enquiry form</span>' : '<span class="badge b-gray">Promotion</span>')
            . '<span class="d2-sub d2-clip">' . e((string) ($r['kind'] === 'enquiry' ? ($r['form_title'] ?: $r['body']) : $r['body'])) . '</span></div>'],
        'show_on' => ['Pages', fn($r) => popup_pages_cell((string) $r['show_on'])],
        'frequency' => ['Shows', fn($r) => '<div>' . e(['session' => 'Once per visit', 'day' => 'Once a day', 'once' => 'Only once'][$r['frequency']] ?? $r['frequency']) . '<span class="d2-sub">after ' . (int) $r['delay_seconds'] . ' second' . ((int) $r['delay_seconds'] === 1 ? '' : 's') . '</span></div>'],
        'views' => ['Views', fn($r) => number_format((int) $r['views'])],
        'clicks' => ['Clicks (CTR)', fn($r) => $r['kind'] === 'promo' ? number_format((int) $r['clicks']) . $rate((int) $r['clicks'], (int) $r['views']) : '<span class="muted">—</span>'],
        'submissions' => ['Leads', fn($r) => $r['kind'] === 'enquiry'
            ? (can('enquiries.view') ? '<a href="enquiries.php?source=popup">' . number_format((int) $r['submissions']) . '</a>' : number_format((int) $r['submissions'])) . $rate((int) $r['submissions'], (int) $r['views'])
            : '<span class="muted">—</span>'],
        'is_active' => ['Status', fn($r) => popup_state_badge($r)],
    ],
    'before_save' => function (array $d) {
        $errors = [];
        if ($d['starts_at'] && $d['ends_at'] && $d['ends_at'] <= $d['starts_at']) {
            $errors['ends_at'] = 'End must be after start.';
        }
        if ($d['kind'] === 'promo') {
            if ($d['cta_label'] && !$d['cta_url']) {
                $errors['cta_url'] = 'Add a link for the button.';
            }
        } else {
            // Enquiry popups show the lead form instead of a button; sensible defaults for empty texts.
            $d['form_title'] = $d['form_title'] ?: 'Request a free call back';
            $d['success_message'] = $d['success_message'] ?: POPUP_SUCCESS_DEFAULT;
            $d['consent_text'] = $d['consent_text'] ?: POPUP_CONSENT_DEFAULT;
        }
        $paths = array_filter(array_map('trim', explode(',', (string) $d['show_on'])));
        foreach ($paths as $p) {
            if ($p !== '*' && !preg_match('#^/[\w\-/]*\*?$#', $p)) {
                $errors['show_on'] = 'Use * or paths like /, /courses, /courses* separated by commas.';
            }
        }
        if ($errors) {
            throw new CrudError($errors);
        }
        $d['show_on'] = implode(',', $paths) ?: '*';
        return $d;
    },
    'fields' => [
        'kind' => ['label' => 'Popup type', 'type' => 'select', 'required' => true, 'default' => 'promo', 'options' => POPUP_KINDS, 'full' => true,
            'hint' => 'An enquiry form popup collects name, phone and course and saves the lead in Enquiries (source "Website popup").'],
        'title' => ['label' => 'Heading', 'required' => true, 'max' => 160],
        'body' => ['label' => 'Text', 'type' => 'textarea', 'max' => 600, 'rows' => 3],
        'image_path' => ['label' => 'Image (optional)', 'type' => 'image', 'folder' => 'cms', 'w' => 900, 'h' => 600],
        'cta_label' => ['label' => 'Button label (promotion only)', 'max' => 60, 'show_if' => 'kind=promo'],
        'cta_url' => ['label' => 'Button link (promotion only)', 'type' => 'url', 'allow_relative' => true, 'placeholder' => '/courses or https://…', 'show_if' => 'kind=promo'],
        'form_title' => ['label' => 'Form heading (enquiry form only)', 'max' => 160, 'show_if' => 'kind=enquiry', 'placeholder' => 'Request a free call back', 'hint' => 'Shown above the form. Leave empty for "Request a free call back".'],
        'success_message' => ['label' => 'Thank-you message (enquiry form only)', 'max' => 300, 'show_if' => 'kind=enquiry', 'placeholder' => POPUP_SUCCESS_DEFAULT],
        'consent_text' => ['label' => 'Consent checkbox text (enquiry form only)', 'type' => 'textarea', 'rows' => 2, 'max' => 400, 'show_if' => 'kind=enquiry', 'default' => POPUP_CONSENT_DEFAULT,
            'hint' => 'Visitors must tick this box before the form can be sent; the time of consent is saved with the lead.'],
        'show_on' => ['label' => 'Show on pages', 'required' => true, 'default' => '*', 'max' => 255, 'hint' => '* = all pages; or e.g. /, /courses* (prefix match). Never shown during tests, checkout or in the dashboard.'],
        'delay_seconds' => ['label' => 'Delay before showing (seconds)', 'type' => 'number', 'min' => 0, 'max' => 120, 'default' => 5, 'required' => true],
        'frequency' => ['label' => 'Frequency per visitor', 'type' => 'select', 'required' => true, 'default' => 'session', 'options' => ['session' => 'Once per visit', 'day' => 'Once a day', 'once' => 'Only once']],
        'starts_at' => ['label' => 'Start (optional)', 'type' => 'datetime'],
        'ends_at' => ['label' => 'End (optional)', 'type' => 'datetime'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
]);
