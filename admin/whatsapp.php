<?php
// WhatsApp automation (Interakt WhatsApp Business API): connection overview, one approved template per
// event (text, ordered parameters, header / URL button, live preview, test send), delivery logs with
// retry, opt-ins, course broadcasts and settings. API credentials live only in .env and are never shown.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/whatsapp.php';

$me = require_staff_page();
require_permission('notifications.send');
$canSettings = can('settings.manage');
$canPeople = can('users.view');

if (!whatsapp_schema_ready()) {
    admin_start('WhatsApp', null, 'email', ['head' => true]);
    echo '<div class="alert alert-warning">' . icon('alert') . '<span>The WhatsApp tables are missing. Apply the latest update in Settings → Database Updates, then reload this page.</span></div>';
    admin_end();
    exit;
}
whatsapp_seed_templates(); // a disabled row for any event added later; never changes existing rows

const WA_LOG_STATUSES = [
    'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed (gave up)',
    'retrying' => 'Retry scheduled', 'queued' => 'Queued / sending', 'skipped' => 'Skipped (rules)',
];

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/** Redirect target from the form (this page only). */
function wa_back(): string
{
    $b = (string) ($_POST['back'] ?? '');
    return preg_match('#^whatsapp\.php(\?[^\s]*)?$#', $b) ? $b : 'whatsapp.php';
}

function wa_save_setting(string $key, string $value, int $by): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'integrations', 0, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$key, $value, $by]);
}

/** Phone as shown to this staff member (masked without users.view). */
function wa_phone(?string $cc, ?string $number, bool $full): string
{
    if (!$number) {
        return '—';
    }
    $p = whatsapp_normalize_phone(($cc ?: '') . $number);
    if (!$p) {
        return $full ? (string) $number : whatsapp_mask_phone((string) $number);
    }
    return $full ? $p['display'] : whatsapp_mask_phone($p['e164']);
}

function wa_recipient_badge(string $r): string
{
    return match ($r) {
        'staff' => '<span class="badge b-purple">Staff</span>',
        'lead' => '<span class="badge b-blue">Lead / candidate</span>',
        default => '<span class="badge b-gray">Student</span>',
    };
}

/** Validate the template form for an event: [clean values, errors, warnings]. */
function wa_template_input(string $event): array
{
    $ev = WHATSAPP_EVENTS[$event];
    $allowed = whatsapp_event_placeholders($event);
    $errors = [];
    $warnings = [];
    $name = strtolower(trim((string) ($_POST['interakt_template_name'] ?? '')));
    if ($name !== '' && !preg_match('/^[a-z0-9_]{1,120}$/', $name)) {
        $errors['interakt_template_name'] = 'Use the template’s code name: lowercase letters, numbers and underscores (as in Interakt’s template list).';
    }
    $lang = trim((string) ($_POST['language_code'] ?? 'en'));
    if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) {
        $errors['language_code'] = 'Use the language code the template was approved in, e.g. en, en_US or hi.';
    }
    $category = in_array($_POST['category'] ?? '', ['utility', 'marketing', 'authentication'], true) ? (string) $_POST['category'] : 'utility';
    $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body_preview'] ?? '')));
    if (mb_strlen($body) > 1024) {
        $errors['body_preview'] = 'WhatsApp template bodies are limited to 1,024 characters.';
    } elseif ($unknown = array_diff(whatsapp_text_placeholders($body), $allowed)) {
        $errors['body_preview'] = 'This event cannot fill {{' . implode('}}, {{', $unknown) . '}}.';
    }
    $params = [];
    foreach ((array) ($_POST['params'] ?? []) as $p) {
        $p = trim((string) $p);
        if ($p === '') {
            continue;
        }
        if (!in_array($p, $allowed, true)) {
            $errors['params'] = 'Unknown parameter “' . mb_substr($p, 0, 40) . '”.';
            break;
        }
        $params[] = $p;
    }
    if (count($params) > 20) {
        $errors['params'] = 'Use at most 20 body parameters.';
    }
    $htype = in_array($_POST['header_type'] ?? '', ['none', 'text', 'image', 'document'], true) ? (string) $_POST['header_type'] : 'none';
    $hparam = trim((string) ($_POST['header_param'] ?? ''));
    if ($htype === 'none') {
        $hparam = '';
    } elseif ($hparam === '') {
        $errors['header_param'] = 'Choose what fills the header.';
    } elseif ($htype === 'text' && !in_array($hparam, $allowed, true)) {
        $errors['header_param'] = 'Pick a placeholder for the text header.';
    } elseif ($htype !== 'text' && !preg_match('#^https://\S{4,250}$#i', $hparam) && !(in_array($hparam, $allowed, true) && str_ends_with($hparam, '_url'))) {
        $errors['header_param'] = 'Use a link placeholder (…_url) or a fixed https:// link to the image / PDF.';
    }
    $button = trim((string) ($_POST['button_url_param'] ?? ''));
    if ($button !== '' && !in_array($button, $allowed, true)) {
        $errors['button_url_param'] = 'Pick a placeholder for the URL button.';
    }
    $optIn = !empty($ev['always_opt_in']) ? 1 : ($ev['recipient'] === 'staff' ? 0 : (empty($_POST['requires_opt_in']) ? 0 : 1));
    $enabled = empty($_POST['is_enabled']) ? 0 : 1;
    if ($enabled && $name === '') {
        $errors['is_enabled'] = 'Add the approved template name before switching this message on.';
    }
    $inText = whatsapp_text_placeholders($body);
    if ($body !== '' && $inText !== array_values(array_unique($params))) {
        $warnings[] = 'The parameter list differs from the placeholders in the preview text (' . (count($inText) ? '{{' . implode('}}, {{', $inText) . '}}' : 'none') . '). Make sure it matches the approved template’s {{1}}, {{2}} … order.';
    }
    if ($category === 'marketing' && ($ev['kind'] ?? '') !== 'broadcast') {
        $warnings[] = 'Marketing templates need marketing opt-in and are charged at marketing rates; transactional messages are usually approved as Utility.';
    }
    if (!$optIn && $ev['recipient'] !== 'staff') {
        $warnings[] = 'Opt-in is switched off for this message. Only do this if the form that triggers it already collects WhatsApp consent.';
    }
    return [[
        'interakt_template_name' => $name, 'language_code' => $lang, 'category' => $category, 'body_preview' => $body,
        'body_params' => $params, 'header_type' => $htype, 'header_param' => $hparam, 'button_url_param' => $button,
        'requires_opt_in' => $optIn, 'is_enabled' => $enabled,
    ], $errors, $warnings];
}

/** WHERE clause for the delivery log filters (?status, event, from, to, q, kind, related). */
function wa_log_filters(): array
{
    $w = [];
    $p = [];
    $sql = match ((string) ($_GET['status'] ?? '')) {
        'sent' => "m.status = 'sent'", 'delivered' => "m.status = 'delivered'", 'read' => "m.status = 'read'",
        'failed' => "m.status = 'failed' AND m.next_attempt_at IS NULL", 'retrying' => "m.status = 'failed' AND m.next_attempt_at IS NOT NULL",
        'queued' => "m.status IN ('queued', 'sending')", 'skipped' => "m.status = 'skipped'", default => null,
    };
    if ($sql) {
        $w[] = $sql;
    }
    $ev = (string) ($_GET['event'] ?? '');
    if (isset(WHATSAPP_EVENTS[$ev])) {
        $w[] = 'm.event_key = ?';
        $p[] = $ev;
    }
    foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
        $d = (string) ($_GET[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) {
            $w[] = "m.created_at {$op} ?";
            $p[] = $k === 'to' ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $digits = preg_replace('/\D/', '', $q);
        $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
        $w[] = '(m.recipient_name LIKE ? OR m.template_name LIKE ?' . (strlen($digits) >= 4 ? ' OR m.phone LIKE ?' : '') . ')';
        array_push($p, $like, $like);
        if (strlen($digits) >= 4) {
            $p[] = '%' . substr($digits, -10) . '%';
        }
    }
    $kind = (string) ($_GET['kind'] ?? '');
    if ($kind === 'live' || $kind === 'test') {
        $w[] = 'm.is_test = ' . ($kind === 'test' ? 1 : 0);
    }
    if (preg_match('/^([a-z_]{1,40}):(\d{1,10})$/', (string) ($_GET['related'] ?? ''), $m)) {
        $w[] = 'm.related_type = ? AND m.related_id = ?';
        array_push($p, $m[1], (int) $m[2]);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Link to the record a message is about (when this staff member can open it). */
function wa_related_link(?string $type, ?int $id): string
{
    if (!$type || !$id) {
        return '—';
    }
    $label = ucfirst(str_replace('_', ' ', $type)) . ' #' . $id;
    $href = match ($type) {
        'order' => can('orders.view') && ($n = db_value('SELECT order_number FROM orders WHERE id = ?', [$id])) ? 'orders.php?order=' . rawurlencode((string) $n) : null,
        'enquiry' => can('enquiries.view') ? 'enquiries.php?action=edit&id=' . $id : null,
        'application' => can('cms.manage') ? 'jobs.php?tab=applications&application=' . $id : null,
        'ticket' => can('support.manage') ? 'support.php?id=' . $id : null,
        'user' => can('users.view') ? 'users.php?id=' . $id : null,
        'broadcast' => 'whatsapp.php?tab=broadcasts',
        default => null,
    };
    if ($type === 'order' && $href) {
        $label = 'Order ' . substr($href, strlen('orders.php?order='));
    }
    return $href ? '<a href="' . e($href) . '">' . e(urldecode($label)) . '</a>' : e($label);
}

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    $back = wa_back();
    $event = (string) ($_POST['event'] ?? '');

    if ($act === 'toggle' && isset(WHATSAPP_EVENTS[$event])) {
        $on = !empty($_POST['on']);
        $tpl = whatsapp_template($event);
        if ($on && trim((string) ($tpl['interakt_template_name'] ?? '')) === '') {
            flash('error', 'Add the approved Interakt template name for “' . WHATSAPP_EVENTS[$event]['label'] . '” before switching it on.');
            redirect_to('whatsapp.php?tab=templates&edit=' . $event);
        }
        db_query('UPDATE whatsapp_templates SET is_enabled = ?, updated_by = ? WHERE event_key = ?', [$on ? 1 : 0, $me['id'], $event]);
        audit_log($on ? 'whatsapp_template_enabled' : 'whatsapp_template_disabled', 'whatsapp_templates', $event, [], 'warning');
        flash('success', '“' . WHATSAPP_EVENTS[$event]['label'] . '” is now ' . ($on ? 'on' : 'off') . '.' . ($on && !whatsapp_enabled() ? ' WhatsApp sending is still switched off in Settings.' : ''));
        redirect_to($back);
    }

    if (in_array($act, ['save_template', 'test'], true) && isset(WHATSAPP_EVENTS[$event])) {
        [$clean, $errors, $warnings] = wa_template_input($event);
        $editUrl = 'whatsapp.php?tab=templates&edit=' . $event;
        if ($act === 'test') {
            $phone = whatsapp_normalize_phone((string) ($_POST['test_phone'] ?? ''));
            $_SESSION['wa_draft'] = ['event' => $event, 'data' => $clean, 'test_phone' => (string) ($_POST['test_phone'] ?? '')];
            $problem = match (true) {
                (bool) $errors => 'Fix the highlighted fields first: ' . implode(' ', $errors),
                $clean['interakt_template_name'] === '' => 'Enter the approved Interakt template name to send a test.',
                !$phone => 'Enter a valid WhatsApp number for the test (e.g. 98765 43210 or +44 7700 900123).',
                !rate_limit('wa-test', (string) $me['id'], 10, 3600) || !rate_limit('wa-test-to', $phone['e164'], 5, 3600) => 'Too many test messages. Please wait a while before sending another.',
                default => null,
            };
            if ($problem) {
                flash('error', $problem);
                redirect_to($editUrl);
            }
            $r = whatsapp_dispatch($event, ['type' => 'staff', 'user_id' => null, 'name' => 'Test by ' . $me['name'], 'phone' => $phone['e164']], whatsapp_sample_vars(),
                ['test' => true, 'template' => $clean + ['event_key' => $event, 'is_enabled' => 1]]);
            audit_log('whatsapp_test_sent', 'whatsapp_messages', $r['id'], ['event' => $event, 'status' => $r['status']], 'info');
            $pid = $r['id'] ? (string) db_value('SELECT provider_message_id FROM whatsapp_messages WHERE id = ?', [$r['id']]) : '';
            flash($r['status'] === 'sent' ? 'success' : 'error', $r['status'] === 'sent'
                ? 'Test accepted by ' . (whatsapp_provider() === 'meta' ? 'WhatsApp' : 'Interakt') . ($pid !== '' ? ' (message id ' . $pid . ')' : '') . ' for ' . $phone['display'] . '. Delivery and read ticks appear in the delivery log once the webhook reports them.'
                : 'Test not sent: ' . ($r['error'] ?: $r['status']));
            redirect_to($editUrl);
        }
        if ($errors) {
            $_SESSION['wa_draft'] = ['event' => $event, 'data' => $clean, 'errors' => $errors];
            flash('error', 'Please correct the highlighted fields.');
            redirect_to($editUrl);
        }
        db_query('UPDATE whatsapp_templates SET interakt_template_name = ?, language_code = ?, category = ?, body_preview = ?, body_params = ?, header_type = ?, header_param = ?,
                  button_url_param = ?, requires_opt_in = ?, is_enabled = ?, updated_by = ? WHERE event_key = ?', [
            $clean['interakt_template_name'] ?: null, $clean['language_code'], $clean['category'], $clean['body_preview'], json_encode($clean['body_params']),
            $clean['header_type'], $clean['header_param'] ?: null, $clean['button_url_param'] ?: null, $clean['requires_opt_in'], $clean['is_enabled'], $me['id'], $event,
        ]);
        audit_log('whatsapp_template_saved', 'whatsapp_templates', $event, ['template' => $clean['interakt_template_name'], 'enabled' => $clean['is_enabled']], 'warning');
        flash('success', 'Template saved.');
        foreach ($warnings as $wmsg) {
            flash('warning', $wmsg);
        }
        redirect_to($editUrl);
    }

    if ($act === 'restore' && isset(WHATSAPP_EVENTS[$event])) {
        $ev = WHATSAPP_EVENTS[$event];
        db_query("UPDATE whatsapp_templates SET body_preview = ?, body_params = ?, header_type = 'none', header_param = NULL, button_url_param = ?, updated_by = ? WHERE event_key = ?",
            [$ev['preview'], json_encode(whatsapp_text_placeholders($ev['preview'])), $ev['button'] ?? null, $me['id'], $event]);
        audit_log('whatsapp_template_restored', 'whatsapp_templates', $event, [], 'warning');
        flash('success', 'Default text and parameters restored. The template name and on/off switch were kept.');
        redirect_to('whatsapp.php?tab=templates&edit=' . $event);
    }

    if ($act === 'retry') {
        $id = (int) ($_POST['id'] ?? 0);
        // Opt-in, preferences and the template switch are checked again; an opted-out student is never re-sent.
        $st = whatsapp_attempt($id, true);
        audit_log('whatsapp_retried', 'whatsapp_messages', $id, ['status' => $st]);
        $err = in_array($st, ['failed', 'skipped'], true) ? (string) db_value('SELECT error FROM whatsapp_messages WHERE id = ?', [$id]) : '';
        flash(in_array($st, ['sent', 'delivered', 'read'], true) ? 'success' : ($st === 'skipped' || $st === 'disabled' ? 'warning' : 'error'), match ($st) {
            'sent', 'delivered', 'read' => "Message #{$id} accepted for delivery.",
            'skipped' => "Message #{$id} was not re-sent. {$err}",
            'disabled' => "WhatsApp sending is switched off (Settings), so message #{$id} was not sent.",
            null => "Message #{$id} cannot be retried (only failed or queued messages, and not while it is being sent).",
            default => "Message #{$id} failed again: {$err}",
        });
        redirect_to($back);
    }

    if ($act === 'bulk_retry') {
        $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), 0, 100);
        $tally = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'ignored' => 0];
        foreach ($ids as $id) {
            $st = whatsapp_attempt($id, true);
            $tally[match ($st) { null, 'disabled' => 'ignored', 'failed' => 'failed', 'skipped' => 'skipped', default => 'sent' }]++;
            if ($st === 'disabled' || ($st === 'failed' && whatsapp_circuit_until() > 0)) {
                break; // sending is off, or the provider is not answering: do not wait on every row
            }
        }
        audit_log('whatsapp_bulk_retry', 'whatsapp_messages', null, $tally);
        flash($tally['failed'] || $tally['skipped'] ? 'warning' : 'success', "Retried: {$tally['sent']} sent, {$tally['failed']} failed"
            . ($tally['skipped'] ? ", {$tally['skipped']} no longer allowed (opted out or message switched off) and marked skipped" : '')
            . ($tally['ignored'] ? ", {$tally['ignored']} not retried (already sent, skipped or sending is off)" : '') . '.');
        redirect_to($back);
    }

    if ($act === 'process') {
        $r = process_whatsapp_queue(50, true);
        audit_log('whatsapp_queue_processed', 'whatsapp_messages', null, $r);
        flash($r['paused'] ? 'warning' : 'success', !whatsapp_enabled() ? 'WhatsApp sending is switched off (Settings), so queued messages are waiting.'
            : ($r['processed'] ? "Processed {$r['processed']} message" . ($r['processed'] === 1 ? '' : 's') . ": {$r['sent']} sent, {$r['failed']} failed"
                . ($r['skipped'] ? ", {$r['skipped']} skipped (no longer allowed)" : '') . '.'
                . ($r['paused'] ? ' The provider did not answer, so the rest wait for the next scheduler run.' : '')
              : 'Nothing is due in the queue right now.'));
        redirect_to($back);
    }

    if ($act === 'opt_out') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid && whatsapp_set_opt_in($uid, false, 'admin')) {
            audit_log('whatsapp_opt_out_by_staff', 'user', $uid, [], 'warning');
            flash('success', 'The student was opted out of WhatsApp updates. They can opt in again from their profile.');
        } else {
            flash('error', 'Could not update this student.');
        }
        redirect_to($back);
    }

    if ($act === 'broadcast') {
        $tpl = whatsapp_template($event);
        $courseId = (int) ($_POST['course_id'] ?? 0) ?: null;
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['message'] ?? '')) ?? '');
        $problem = match (true) {
            (WHATSAPP_EVENTS[$event]['kind'] ?? '') !== 'broadcast' || !$tpl || empty($tpl['is_enabled']) => 'Choose a broadcast template that is switched on.',
            !whatsapp_enabled() => 'WhatsApp sending is switched off in Settings.',
            $event === 'course_update' && !$courseId => 'Choose the course batch for a course update.',
            $courseId !== null && !db_value('SELECT id FROM courses WHERE id = ?', [$courseId]) => 'Course not found.',
            mb_strlen($text) < 5 || mb_strlen($text) > 600 => 'Write the update in 5 to 600 characters.',
            empty($_POST['confirm']) => 'Tick the confirmation box to send.',
            !rate_limit('wa-broadcast', (string) $me['id'], 10, 86400) => 'You have sent 10 broadcasts today. Please try again tomorrow.',
            default => null,
        };
        if ($problem) {
            $_SESSION['wa_bc_draft'] = ['event' => $event, 'course_id' => $courseId, 'message' => $text];
            flash('error', $problem);
            redirect_to('whatsapp.php?tab=broadcasts');
        }
        $r = whatsapp_broadcast($event, $courseId, $text, (int) $me['id']);
        audit_log('whatsapp_broadcast_sent', 'whatsapp_broadcasts', $r['id'], ['event' => $event, 'course' => $courseId, 'queued' => $r['queued'], 'skipped' => $r['skipped']], 'warning');
        flash($r['queued'] ? 'success' : 'warning', $r['queued']
            ? "Broadcast queued for {$r['queued']} student" . ($r['queued'] === 1 ? '' : 's') . ": {$r['sent']} sent now, the rest go out in batches." . ($r['skipped'] ? " {$r['skipped']} skipped (preferences or number changes)." : '')
            : 'No eligible students: only students who opted in to WhatsApp' . ($courseId ? ' and are enrolled in this course' : '') . ' receive broadcasts.');
        redirect_to('whatsapp.php?tab=broadcasts');
    }

    if ($act === 'settings') {
        if (!$canSettings) {
            admin_forbidden('settings.manage');
        }
        $errors = [];
        $provider = ($_POST['provider'] ?? '') === 'meta' ? 'meta' : 'interakt';
        $support = trim((string) ($_POST['support_number'] ?? ''));
        if ($support !== '' && !whatsapp_normalize_phone($support)) {
            $errors[] = 'The support number is not a valid phone number.';
        }
        $admins = [];
        foreach (preg_split('/[,;\n]+/', (string) ($_POST['admin_numbers'] ?? '')) as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $p = whatsapp_normalize_phone($raw);
            if ($p) {
                $admins[$p['e164']] = $p['e164'];
            } else {
                $errors[] = 'Admin alert number “' . mb_substr(trim($raw), 0, 30) . '” is not valid.';
            }
        }
        if (count($admins) > 10) {
            $errors[] = 'Use at most 10 admin alert numbers.';
        }
        $cc = trim((string) ($_POST['default_cc'] ?? '+91'));
        $cc = str_starts_with($cc, '+') ? $cc : '+' . $cc;
        if (!preg_match('/^\+[1-9]\d{0,2}$/', $cc)) {
            $errors[] = 'Default country code must look like +91.';
        }
        $max = filter_var($_POST['max_attempts'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($max === false) {
            $errors[] = 'Attempts must be between 1 and 10.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect_to('whatsapp.php?tab=settings');
        }
        $enabled = !empty($_POST['enabled']);
        wa_save_setting('whatsapp_provider', $provider, (int) $me['id']);
        wa_save_setting('whatsapp_enabled', $enabled ? '1' : '0', (int) $me['id']);
        wa_save_setting('whatsapp_support_number', $support !== '' ? whatsapp_normalize_phone($support)['e164'] : '', (int) $me['id']);
        wa_save_setting('whatsapp_admin_numbers', implode(',', $admins), (int) $me['id']);
        wa_save_setting('whatsapp_default_country_code', $cc, (int) $me['id']);
        wa_save_setting('whatsapp_max_attempts', (string) $max, (int) $me['id']);
        audit_log('whatsapp_settings_updated', 'site_settings', null, ['provider' => $provider, 'enabled' => $enabled, 'admin_numbers' => count($admins)], 'warning');
        flash('success', 'WhatsApp settings saved.' . ($enabled && !whatsapp_credentials_ok($provider) ? ' Sending is on, but the API key is not set in Keys & Credentials yet, so messages will fail until it is added.' : ''));
        redirect_to('whatsapp.php?tab=settings');
    }
    redirect_to($back);
}

// ------------------------------------------------------------------
// Exports
// ------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'logs') {
    [$where, $params] = wa_log_filters();
    $rows = db_all("SELECT m.* FROM whatsapp_messages m {$where} ORDER BY m.id DESC LIMIT 20000", $params);
    csv_download('whatsapp-log-' . date('Y-m-d') . '.csv',
        ['ID', 'Created', 'Event', 'Template', 'Recipient', 'Type', 'Phone', 'Status', 'Attempts', 'Error', 'Provider message id', 'Sent', 'Delivered', 'Read', 'Related', 'Test'],
        array_map(fn($r) => [$r['id'], $r['created_at'], WHATSAPP_EVENTS[$r['event_key']]['label'] ?? $r['event_key'], $r['template_name'], $r['recipient_name'], $r['recipient_type'],
            wa_phone($r['country_code'], $r['phone'], $canPeople), $r['status'] === 'failed' && $r['next_attempt_at'] ? 'retry scheduled' : $r['status'], $r['attempts'], $r['error'],
            $r['provider_message_id'], $r['sent_at'], $r['delivered_at'], $r['read_at'], $r['related_type'] ? $r['related_type'] . ' #' . $r['related_id'] : '', $r['is_test'] ? 'yes' : ''], $rows));
}
if (($_GET['export'] ?? '') === 'optins') {
    if (!$canPeople) {
        admin_forbidden('users.view');
    }
    $rows = db_all("SELECT u.id, u.name, u.email, u.phone, p.whatsapp_opt_in_at, p.whatsapp_opt_in_source, p.whatsapp_opt_in_phone FROM users u JOIN student_profiles p ON p.user_id = u.id
                    WHERE p.whatsapp_opt_in = 1 AND u.user_type = 'student' AND u.status = 'active' ORDER BY p.whatsapp_opt_in_at DESC LIMIT 50000");
    csv_download('whatsapp-opt-ins-' . date('Y-m-d') . '.csv', ['Student ID', 'Name', 'Email', 'Account phone', 'Consented number', 'Opted in at', 'Source'],
        array_map(fn($r) => [$r['id'], $r['name'], $r['email'], $r['phone'], $r['whatsapp_opt_in_phone'], $r['whatsapp_opt_in_at'], $r['whatsapp_opt_in_source']], $rows));
}

// ------------------------------------------------------------------
// Shared data
// ------------------------------------------------------------------
$status = whatsapp_status();
$templates = array_column(array_map('whatsapp_template_row', db_all('SELECT * FROM whatsapp_templates')), null, 'event_key');
$enabledCount = count(array_filter($templates, fn($t) => (int) $t['is_enabled'] === 1));
$stats7 = whatsapp_stats(7);
$failedOpen = (int) db_value("SELECT COUNT(*) FROM whatsapp_messages WHERE status = 'failed' AND is_test = 0 AND created_at >= NOW() - INTERVAL 7 DAY");
$optedIn = whatsapp_optin_ready() ? (int) db_value("SELECT COUNT(*) FROM student_profiles p JOIN users u ON u.id = p.user_id AND u.status = 'active' AND u.user_type = 'student' WHERE p.whatsapp_opt_in = 1") : 0;
$providerName = $status['provider'] === 'meta' ? 'Meta Cloud API' : 'Interakt';

$tabs = [
    'overview' => ['label' => 'Overview', 'icon' => 'gauge'],
    'templates' => ['label' => 'Templates', 'icon' => 'file', 'count' => $enabledCount . '/' . count($templates)],
    'logs' => ['label' => 'Delivery logs', 'icon' => 'log'] + ($failedOpen ? ['count' => $failedOpen, 'tone' => 'red'] : []),
    'broadcasts' => ['label' => 'Broadcasts', 'icon' => 'megaphone'],
    'optins' => ['label' => 'Opt-ins', 'icon' => 'users', 'count' => $optedIn],
    'settings' => ['label' => 'Settings', 'icon' => 'settings'],
];
$tab = current_tab($tabs);
$actions = action_form('process', ['back' => 'whatsapp.php?' . http_build_query(['tab' => $tab])], icon('history') . ' Process queue now', 'btn btn-light')
    . '<a class="btn btn-light" href="whatsapp.php?tab=logs">' . icon('log') . ' Delivery logs</a>';

admin_start('WhatsApp', null, 'email', ['head' => true, 'actions' => $actions,
    'subtitle' => 'Automatic WhatsApp messages through ' . $providerName . ': approved templates per event, delivery tracking, retries and opt-ins.']);
?>
<style>
  .wa { --wa-green: #25D366; --wa-teal: #008069; --wa-chat: #EFEAE2; --wa-bubble: #fff; --wa-ink: #111B21; --wa-meta: #667781; --wa-link: #027EB5; }
  [data-theme="dark"] .wa { --wa-chat: #0B141A; --wa-bubble: #202C33; --wa-ink: #E9EDEF; --wa-meta: #8696A0; --wa-link: #53BDEB; }
  .wa .wa-grid { display: grid; gap: 18px; }
  .wa .wa-hero { display: grid; gap: 18px; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); align-items: stretch; }
  .wa .wa-conn { position: relative; overflow: hidden; padding: 22px; color: #fff; border: 0; background: radial-gradient(600px 220px at 100% 0%, rgb(37 211 102 / .35), transparent 60%), linear-gradient(135deg, #08264D, #0b3d73 55%, #075E54); }
  .wa .wa-conn h2 { color: #fff; font-size: 19px; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
  .wa .wa-conn h2 svg.i { width: 26px; height: 26px; color: var(--wa-green); }
  .wa .wa-conn p { color: #c9d8ee; margin: 0; }
  .wa .wa-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
  .wa .wa-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 99px; font-size: 12.5px; font-weight: 600; background: rgb(255 255 255 / .1); color: #fff; border: 1px solid rgb(255 255 255 / .16); }
  .wa .wa-pill::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #f87171; }
  .wa .wa-pill.ok::before { background: var(--wa-green); box-shadow: 0 0 0 3px rgb(37 211 102 / .25); }
  .wa .wa-pill.warn::before { background: #fbbf24; }
  .wa .wa-ready { margin-top: 18px; padding: 12px 14px; border-radius: 14px; background: rgb(255 255 255 / .08); font-size: 13px; color: #e6eefb; }
  .wa .wa-ready strong { color: #fff; }
  .wa .wa-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .wa .wa-steps li { display: flex; gap: 12px; align-items: flex-start; }
  .wa .wa-steps .n { flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; font-weight: 700; background: var(--line); color: var(--muted); }
  .wa .wa-steps .n.ok { background: var(--green-soft); color: var(--green); }
  .wa .wa-steps .n svg.i { width: 15px; height: 15px; }
  .wa .wa-steps b { display: block; color: var(--ink); font-size: 13.5px; }
  .wa .wa-steps span.d { font-size: 12.5px; color: var(--muted); }
  .wa .wa-group + .wa-group { margin-top: 18px; }
  .wa .wa-group .card-head h2 { display: flex; align-items: center; gap: 8px; }
  .wa .wa-group .card-head h2 svg.i { width: 18px; height: 18px; color: var(--primary); }
  .wa .wa-ev strong { color: var(--ink); }
  .wa .wa-ev .d { display: block; font-size: 12px; color: var(--muted); max-width: 460px; margin-top: 2px; }
  .wa code.wa-name { font-size: 12px; padding: 2px 7px; border-radius: 6px; background: var(--primary-soft); color: var(--primary); word-break: break-all; }
  .wa .wa-mini { font-size: 12px; color: var(--muted); white-space: nowrap; }
  .wa .wa-switch { position: relative; width: 42px; height: 24px; border-radius: 12px; border: 0; padding: 0; cursor: pointer; background: var(--switch-off); transition: background .18s; }
  .wa .wa-switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / .25); transition: left .18s var(--ease); }
  .wa .wa-switch[aria-checked="true"] { background: var(--wa-green); }
  .wa .wa-switch[aria-checked="true"]::after { left: 21px; }
  .wa .wa-switch:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
  .wa .wa-edit { display: grid; gap: 18px; grid-template-columns: minmax(0, 1.35fr) minmax(320px, 1fr); align-items: start; }
  .wa .wa-side { display: grid; gap: 18px; min-width: 0; }
  .wa .wa-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .wa .wa-chips button { border: 1px dashed var(--input-line); background: var(--card-2); color: var(--ink-2); border-radius: 99px; padding: 3px 10px; font: 600 12px var(--font); cursor: pointer; }
  .wa .wa-chips button:hover, .wa .wa-chips button:focus-visible { border-color: var(--primary); color: var(--primary); outline: none; }
  .wa .wa-params { display: grid; gap: 8px; }
  .wa .wa-prow { display: grid; grid-template-columns: 54px minmax(0, 1fr) auto; gap: 8px; align-items: center; }
  .wa .wa-prow .num { font: 700 13px Consolas, monospace; color: var(--primary); text-align: center; padding: 9px 0; border-radius: 10px; background: var(--primary-soft); }
  .wa .wa-prow .acts { display: flex; gap: 4px; }
  .wa .wa-prow .acts button { width: 34px; height: 34px; border-radius: 9px; border: 1px solid var(--line); background: var(--card); color: var(--muted); display: grid; place-items: center; cursor: pointer; }
  .wa .wa-prow .acts button:hover { color: var(--primary); border-color: var(--primary); }
  .wa .wa-prow .acts svg.i { width: 15px; height: 15px; }
  .wa .wa-phone { border-radius: 26px; overflow: hidden; border: 8px solid #1f2937; background: var(--wa-chat); box-shadow: var(--shadow-lg); max-width: 380px; margin: 0 auto; }
  .wa .wa-phone-top { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--wa-teal); color: #fff; }
  .wa .wa-phone-top .av { width: 34px; height: 34px; border-radius: 50%; background: #fff; display: grid; place-items: center; color: var(--wa-teal); font-weight: 800; }
  .wa .wa-phone-top b { display: block; font-size: 14px; }
  .wa .wa-phone-top small { font-size: 11px; opacity: .85; }
  .wa .wa-chat { padding: 18px 12px 22px; min-height: 260px; background-image: radial-gradient(rgb(0 0 0 / .045) 1px, transparent 1px); background-size: 14px 14px; }
  .wa .wa-day { text-align: center; margin-bottom: 12px; }
  .wa .wa-day span { font-size: 11px; padding: 4px 10px; border-radius: 8px; background: var(--wa-bubble); color: var(--wa-meta); box-shadow: 0 1px .5px rgb(0 0 0 / .13); }
  .wa .wa-bubble { position: relative; max-width: 92%; background: var(--wa-bubble); color: var(--wa-ink); border-radius: 0 10px 10px 10px; padding: 7px 9px 6px; box-shadow: 0 1px .5px rgb(0 0 0 / .13); font-size: 14px; line-height: 1.42; overflow-wrap: anywhere; }
  .wa .wa-bubble::before { content: ""; position: absolute; left: -7px; top: 0; border-top: 8px solid var(--wa-bubble); border-left: 8px solid transparent; }
  .wa .wa-bubble .hd { font-weight: 700; margin-bottom: 4px; }
  .wa .wa-bubble .media { display: grid; place-items: center; height: 120px; border-radius: 8px; margin-bottom: 6px; background: rgb(0 0 0 / .08); color: var(--wa-meta); font-size: 12px; }
  .wa .wa-bubble .doc { display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 8px; margin-bottom: 6px; background: rgb(0 0 0 / .06); font-size: 12.5px; }
  .wa .wa-bubble .doc svg.i { width: 26px; height: 26px; color: #e11d48; }
  .wa .wa-bubble .v { background: rgb(37 211 102 / .18); border-radius: 4px; padding: 0 2px; }
  .wa .wa-bubble .v.bad { background: rgb(229 72 77 / .2); color: #b91c1c; }
  .wa .wa-bubble .meta { display: block; text-align: right; font-size: 11px; color: var(--wa-meta); margin-top: 2px; }
  .wa .wa-btn { max-width: 92%; margin-top: 3px; padding: 9px; text-align: center; background: var(--wa-bubble); color: var(--wa-link); border-radius: 10px; font-size: 14px; font-weight: 500; box-shadow: 0 1px .5px rgb(0 0 0 / .13); display: flex; gap: 6px; align-items: center; justify-content: center; }
  .wa .wa-btn svg.i { width: 15px; height: 15px; }
  .wa pre.wa-json { margin: 0; padding: 14px 16px; max-height: 320px; overflow: auto; font: 12px/1.55 Consolas, monospace; background: var(--card-2); color: var(--ink); border-radius: 0 0 var(--radius) var(--radius); white-space: pre-wrap; word-break: break-word; }
  .wa .wa-numbered { white-space: pre-wrap; font: 13px/1.55 Consolas, monospace; background: var(--card-2); border: 1px dashed var(--input-line); border-radius: 12px; padding: 12px 14px; color: var(--ink); }
  .wa .wa-filters { display: grid; gap: 10px; grid-template-columns: minmax(170px, 1.4fr) repeat(3, minmax(130px, 1fr)) repeat(2, minmax(125px, .8fr)) auto; align-items: end; padding: 16px 20px; border-bottom: 1px solid var(--line); }
  .wa .wa-filters label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px; }
  .wa .wa-err { display: block; max-width: 300px; font-size: 12px; color: var(--red); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .wa .wa-sub { display: block; font-size: 12px; color: var(--muted); }
  .wa .wa-timeline { list-style: none; margin: 0; padding: 0; display: grid; gap: 0; }
  .wa .wa-timeline li { position: relative; padding: 0 0 16px 28px; }
  .wa .wa-timeline li::before { content: ""; position: absolute; left: 7px; top: 18px; bottom: 0; width: 2px; background: var(--line); }
  .wa .wa-timeline li:last-child::before { display: none; }
  .wa .wa-timeline .dot { position: absolute; left: 0; top: 3px; width: 16px; height: 16px; border-radius: 50%; background: var(--line); border: 3px solid var(--card); box-shadow: 0 0 0 1px var(--line); }
  .wa .wa-timeline .on .dot { background: var(--wa-green); }
  .wa .wa-timeline .bad .dot { background: var(--red); }
  .wa .wa-timeline b { display: block; font-size: 13.5px; color: var(--ink); }
  .wa .wa-timeline small { color: var(--muted); }
  .wa .wa-kpi { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
  .wa .wa-kpi .stat > span:last-child { display: grid; gap: 1px; min-width: 0; }
  .wa .wa-kpi .stat .label, .wa .wa-kpi .stat .sub { display: block; }
  .wa .wa-kpi a.stat { color: inherit; text-decoration: none; transition: transform .15s, box-shadow .15s; }
  .wa .wa-kpi a.stat:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
  .wa code.wa-name { word-break: normal; overflow-wrap: anywhere; }
  .wa .wa-kpi .value small { font-size: 13px; color: var(--muted); font-weight: 600; margin-left: 4px; }
  .wa .wa-ptable td.k { width: 1%; white-space: nowrap; }
  .wa .wa-ptable td.k code { white-space: nowrap; word-break: normal; overflow-wrap: normal; }
  .wa .wa-ptable td.v { overflow-wrap: anywhere; word-break: normal; }
  .wa .wa-copyrow { display: flex; gap: 8px; align-items: stretch; }
  .wa .wa-copyrow input { flex: 1; min-width: 0; }
  @media (max-width: 1200px) { .wa .wa-edit { grid-template-columns: minmax(0, 1fr); } .wa .wa-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  @media (max-width: 900px) { .wa .wa-hero { grid-template-columns: minmax(0, 1fr); } }
  @media (max-width: 640px) { .wa .wa-filters { grid-template-columns: minmax(0, 1fr); } .wa .wa-prow { grid-template-columns: 46px minmax(0, 1fr); } .wa .wa-prow .acts { grid-column: 2; } .wa .wa-phone { border-width: 6px; } }
  @media (prefers-reduced-motion: reduce) { .wa .wa-switch, .wa .wa-switch::after { transition: none; } }
</style>
<div class="wa">
<?= admin_tabs($tabs, $tab, ['label' => 'WhatsApp sections']) ?>
<?php

// ==================================================================
// Overview
// ==================================================================
if ($tab === 'overview'):
    $named = count(array_filter($templates, fn($t) => trim((string) $t['interakt_template_name']) !== ''));
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-{$i} days"))] = 0;
    }
    foreach (db_all("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM whatsapp_messages WHERE is_test = 0 AND status IN ('sent', 'delivered', 'read') AND created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)") as $r) {
        $days[$r['d']] = (int) $r['n'];
    }
    $chart = [];
    foreach ($days as $d => $n) {
        $chart[date('j M', strtotime($d))] = $n;
    }
    $recentFail = db_all("SELECT * FROM whatsapp_messages WHERE status = 'failed' AND is_test = 0 ORDER BY id DESC LIMIT 6");
    $lastHook = db_value("SELECT MAX(received_at) FROM webhook_events WHERE provider = 'interakt'");
    $delivRate = $stats7['accepted'] ? round($stats7['delivered'] / $stats7['accepted'] * 100) : null;
    $readRate = $stats7['delivered'] ? round($stats7['seen'] / $stats7['delivered'] * 100) : null;
    $steps = [
        [$status['credentials'], 'Add the API key', $status['provider'] === 'meta' ? 'Meta token and phone number ID in Keys & Credentials → WhatsApp (Meta Business → WhatsApp → API Setup).' : 'Interakt Secret Key in Keys & Credentials → WhatsApp (Interakt → Settings → Developer Settings).'],
        [$status['webhook_secret'] && $lastHook, 'Connect delivery webhooks', $status['webhook_secret'] ? ($lastHook ? 'Last status update received ' . fmt_ago((string) $lastHook) . '.' : 'Secret set. Paste the webhook URL into Interakt (Settings tab) to receive delivered / read ticks.') : 'Set the Interakt webhook secret in Keys & Credentials and the same Secret Key in Interakt’s webhook settings.'],
        [$named > 0, 'Get templates approved and enter their names', $named . ' of ' . count($templates) . ' events have an approved template name. Create each template in Interakt with the text shown on its edit screen.'],
        [$enabledCount > 0, 'Switch on the messages you need', $enabledCount . ' message' . ($enabledCount === 1 ? '' : 's') . ' switched on. Send a test from each template first.'],
        [$status['enabled'], 'Turn on WhatsApp sending', $status['enabled'] ? 'Sending is on.' : 'Settings → “Send WhatsApp messages”.'],
    ];
    ?>
    <section class="wa-hero">
      <div class="card wa-conn">
        <h2><?= icon('whatsapp') ?> <?= e($providerName) ?> WhatsApp Business API</h2>
        <p>Messages are sent from the server only after verified events (confirmed payment, saved enrolment, released result …), to students who opted in.</p>
        <div class="wa-pills">
          <span class="wa-pill <?= $status['enabled'] ? 'ok' : 'warn' ?>">Sending <?= $status['enabled'] ? 'on' : 'off' ?></span>
          <span class="wa-pill <?= $status['credentials'] ? 'ok' : '' ?>">API key <?= $status['credentials'] ? 'configured' : 'not configured' ?></span>
          <?php if ($status['provider'] === 'interakt'): ?><span class="wa-pill <?= $status['webhook_secret'] ? 'ok' : '' ?>">Webhook secret <?= $status['webhook_secret'] ? 'configured' : 'not configured' ?></span><?php endif; ?>
          <span class="wa-pill <?= $enabledCount ? 'ok' : 'warn' ?>"><?= $enabledCount ?> template<?= $enabledCount === 1 ? '' : 's' ?> on</span>
          <?php if ($status['custom_base']): ?><span class="wa-pill warn">Custom API base (testing)</span><?php endif; ?>
          <span class="wa-pill <?= $status['background'] === 'inline' ? 'warn' : 'ok' ?>" title="<?= e($status['background'] === 'finish_request'
              ? 'The server hands the page to the visitor first and sends WhatsApp messages afterwards.'
              : ($status['background'] === 'apache' ? 'Apache: the complete page is sent first, then the WhatsApp messages.'
              : 'This server cannot finish a page early: messages are sent within a ' . WHATSAPP_INLINE_BUDGET . '-second budget, the rest by the scheduler.')) ?>">Background sending <?= $status['background'] === 'inline' ? 'limited' : 'on' ?></span>
          <?php if ($status['paused_until']): ?><span class="wa-pill warn">Provider not answering - direct sends paused until <?= e(date('g:i A', $status['paused_until'])) ?></span><?php endif; ?>
        </div>
        <div class="wa-ready">
          <?php if ($status['ready'] && $enabledCount): ?>
            <strong>Ready.</strong> Enabled messages are sent automatically; failures retry after 2 min, 10 min, 1 h and 6 h (up to <?= whatsapp_max_attempts() ?> attempts; a timed-out send first waits <?= WHATSAPP_TIMEOUT_RETRY_MINUTES ?> min for Interakt’s status webhook). Opt-in, preferences and the template switch are checked again before every retry.
          <?php else: ?>
            <strong>Not sending yet.</strong> Finish the checklist on the right. Nothing is sent until sending is on, the API key is set and a template is switched on.
          <?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2>Go-live checklist</h2></div>
        <div class="card-pad">
          <ol class="wa-steps">
            <?php foreach ($steps as $i => [$ok, $title, $desc]): ?>
            <li><span class="n<?= $ok ? ' ok' : '' ?>"><?= $ok ? icon('check') : $i + 1 ?></span><span><b><?= e($title) ?></b><span class="d"><?= e($desc) ?></span></span></li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>
    </section>
    <section class="grid g-5 mt wa-kpi" aria-label="Last 7 days">
      <?php foreach ([
          ['Sent', $stats7['accepted'], 'send', 'tone-blue', 'sent', 'Accepted by ' . $providerName, ''],
          ['Delivered', $stats7['delivered'], 'check', 'tone-green', 'delivered', 'Reached the phone', $delivRate !== null ? $delivRate . '%' : ''],
          ['Read', $stats7['seen'], 'eye', 'tone-teal', 'read', 'Blue ticks', $readRate !== null ? $readRate . '%' : ''],
          ['Failed', $stats7['failed'] + $stats7['retrying'], 'alert', 'tone-red', 'failed', $stats7['retrying'] ? $stats7['retrying'] . ' will retry' : 'Not delivered', ''],
          ['Skipped', $stats7['skipped'], 'shield', 'tone-purple', 'skipped', 'No opt-in, number or preference', ''],
      ] as [$label, $value, $ico, $tone, $st, $sub, $pct]): ?>
      <a class="card stat" href="<?= e('whatsapp.php?' . http_build_query(['tab' => 'logs', 'status' => $st, 'from' => date('Y-m-d', strtotime('-6 days'))])) ?>">
        <span class="ico <?= $tone ?>"><?= icon($ico) ?></span>
        <span><span class="label"><?= e($label) ?> · 7 days</span><span class="value"><?= number_format($value) ?><?= $pct !== '' ? '<small>' . e($pct) . '</small>' : '' ?></span><span class="sub"><?= e($sub) ?></span></span>
      </a>
      <?php endforeach; ?>
    </section>
    <section class="split mt">
      <div class="card">
        <div class="card-head"><h2>Messages sent · last 14 days</h2><a class="btn btn-ghost btn-sm" href="whatsapp.php?tab=logs"><?= icon('log') ?> All logs</a></div>
        <div class="card-pad"><?= array_sum($chart) ? svg_bar_chart($chart, 'WhatsApp messages sent per day', false, 640, 240) : empty_state('No messages yet', 'Messages appear here once templates are switched on and events happen.', 'whatsapp') ?></div>
      </div>
      <div class="card">
        <div class="card-head"><h2>Recent failures</h2><?= $recentFail ? '<a class="btn btn-ghost btn-sm" href="whatsapp.php?tab=logs&amp;status=failed">View all</a>' : '' ?></div>
        <?php if ($recentFail): ?>
        <div class="table-wrap"><table class="t stack"><tbody>
          <?php foreach ($recentFail as $r): ?>
          <tr>
            <td data-label="Message"><div><a href="whatsapp.php?tab=logs&amp;id=<?= (int) $r['id'] ?>"><strong><?= e(WHATSAPP_EVENTS[$r['event_key']]['label'] ?? $r['event_key']) ?></strong></a><span class="wa-err" title="<?= e((string) $r['error']) ?>"><?= e((string) $r['error']) ?></span><span class="wa-sub"><?= e(fmt_ago($r['created_at'])) ?><?= $r['next_attempt_at'] ? ' · retry ' . e(fmt_dt($r['next_attempt_at'], 'g:i A')) : '' ?></span></div></td>
            <td class="actions"><?= action_form('retry', ['id' => $r['id'], 'back' => 'whatsapp.php?tab=overview'], icon('history') . ' Retry', 'btn btn-light btn-xs') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?>
        <?= empty_state('No failures', 'Failed messages show up here with the provider’s reason and a retry button.', 'check-circle') ?>
        <?php endif; ?>
      </div>
    </section>
    <?php

// ==================================================================
// Templates: list + edit
// ==================================================================
elseif ($tab === 'templates'):
    $edit = (string) ($_GET['edit'] ?? '');
    if (isset(WHATSAPP_EVENTS[$edit], $templates[$edit])):
        $ev = WHATSAPP_EVENTS[$edit];
        $t = $templates[$edit];
        $draft = ($_SESSION['wa_draft']['event'] ?? '') === $edit ? $_SESSION['wa_draft'] : null;
        unset($_SESSION['wa_draft']);
        $f = $draft ? array_merge($t, $draft['data']) : $t;
        $errs = $draft['errors'] ?? [];
        $allowed = whatsapp_event_placeholders($edit);
        $ph = whatsapp_placeholders();
        $options = array_intersect_key($ph, array_flip($allowed));
        $recent = db_all('SELECT id, status, error, recipient_name, created_at, next_attempt_at, is_test FROM whatsapp_messages WHERE event_key = ? ORDER BY id DESC LIMIT 5', [$edit]);
        $err = fn(string $k) => isset($errs[$k]) ? '<div class="err" id="err-' . $k . '">' . icon('alert') . e($errs[$k]) . '</div>' : '';
        $inv = fn(string $k) => isset($errs[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
        $sel = function (string $name, string $id, string $current, bool $blank = true, array $extra = []) use ($options): string {
            $h = '<select id="' . e($id) . '" name="' . e($name) . '">' . ($blank ? '<option value="">— None —</option>' : '');
            foreach ($extra + $options as $k => $o) {
                $h .= '<option value="' . e($k) . '"' . ($k === $current ? ' selected' : '') . '>{{' . e($k) . '}} · ' . e($o['label']) . '</option>';
            }
            return $h . '</select>';
        };
        $params = $f['body_params'] ?: [];
        ?>
        <nav class="crumbs" aria-label="Back" style="margin-bottom:12px"><a href="whatsapp.php?tab=templates"><?= icon('left') ?> All templates</a></nav>
        <div class="wa-edit">
          <form method="post" class="card" id="wa-tpl-form" data-dirty-check>
            <?= csrf_field() ?><input type="hidden" name="event" value="<?= e($edit) ?>">
            <div class="card-head"><h2><?= e($ev['label']) ?> <?= wa_recipient_badge($ev['recipient']) ?></h2><?= (int) $t['is_enabled'] ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>' ?></div>
            <div class="card-pad form">
              <div class="field full"><div class="alert alert-info" style="margin:0"><?= icon('zap') ?><span><strong>Trigger:</strong> <?= e($ev['trigger']) ?></span></div></div>
              <div class="form-sec"><h3>Approved template</h3></div>
              <div class="field<?= isset($errs['interakt_template_name']) ? ' invalid' : '' ?>">
                <label for="tn">Interakt template name</label>
                <input id="tn" type="text" name="interakt_template_name" value="<?= e((string) $f['interakt_template_name']) ?>" maxlength="120" autocomplete="off" spellcheck="false" placeholder="e.g. laxyam_<?= e($edit) ?>"<?= $inv('interakt_template_name') ?>>
                <div class="hint">The template’s code name in Interakt (Templates → open it → the part of the URL between <code>template/</code> and <code>/view</code>).</div><?= $err('interakt_template_name') ?>
              </div>
              <div class="field<?= isset($errs['language_code']) ? ' invalid' : '' ?>">
                <label for="tl">Language code</label>
                <input id="tl" type="text" name="language_code" value="<?= e((string) $f['language_code']) ?>" maxlength="15" placeholder="en"<?= $inv('language_code') ?>>
                <div class="hint">Exactly as approved: <code>en</code>, <code>en_US</code>, <code>hi</code> …</div><?= $err('language_code') ?>
              </div>
              <div class="field">
                <label for="tc">Category</label>
                <select id="tc" name="category">
                  <?php foreach (['utility' => 'Utility (transactional updates)', 'marketing' => 'Marketing (promotions, needs marketing opt-in)', 'authentication' => 'Authentication (one-time codes only)'] as $k => $l): ?>
                  <option value="<?= $k ?>"<?= $f['category'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">Must match the category WhatsApp approved. Never put offers in a Utility template.</div>
              </div>
              <div class="field">
                <label>Recipient</label>
                <div style="padding-top:8px"><?= wa_recipient_badge($ev['recipient']) ?> <span class="muted" style="font-size:12px"><?= e(match ($ev['recipient']) { 'staff' => 'Admin alert numbers (Settings tab).', 'lead' => 'The phone number typed on the form.', default => 'The mobile number on the student’s account.' }) ?></span></div>
              </div>

              <div class="form-sec"><h3>Message text</h3></div>
              <div class="field full<?= isset($errs['body_preview']) ? ' invalid' : '' ?>">
                <label for="tb">Approved body text <span class="muted" style="font-weight:500">(with {{placeholders}}, used for the preview and the text you submit to Interakt)</span></label>
                <textarea id="tb" name="body_preview" rows="9" maxlength="1024" data-counter="700,1024"<?= $inv('body_preview') ?>><?= e((string) $f['body_preview']) ?></textarea>
                <div class="wa-chips" role="group" aria-label="Insert a placeholder">
                  <?php foreach ($options as $k => $o): if (str_ends_with($k, '_path')) { continue; } ?><button type="button" data-insert="<?= e($k) ?>" title="<?= e($o['label']) ?>">{{<?= e($k) ?>}}</button><?php endforeach; ?>
                </div>
                <div class="hint">WhatsApp formatting works: *bold*, _italic_, ~strike~. A variable may not start or end the message.</div><?= $err('body_preview') ?>
              </div>

              <div class="form-sec"><h3>Parameter mapping <span class="muted" style="font-weight:500;font-size:12.5px">- what fills {{1}}, {{2}} … of the approved template (Interakt <code>bodyValues</code>, in order)</span></h3></div>
              <div class="field full<?= isset($errs['params']) ? ' invalid' : '' ?>">
                <div class="wa-params" id="wa-params">
                  <?php foreach (array_merge($params, ['']) as $i => $p): ?>
                  <div class="wa-prow">
                    <span class="num">{{<?= $i + 1 ?>}}</span>
                    <?= $sel('params[]', 'p' . $i, (string) $p) ?>
                    <span class="acts"><button type="button" data-move="-1" aria-label="Move up"><?= icon('up') ?></button><button type="button" data-move="1" aria-label="Move down"><?= icon('down') ?></button><button type="button" data-remove aria-label="Remove"><?= icon('close') ?></button></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                  <button type="button" class="btn btn-light btn-sm" id="wa-add"><?= icon('plus') ?> Add parameter</button>
                  <button type="button" class="btn btn-light btn-sm" id="wa-detect"><?= icon('sparkles') ?> Match the text above</button>
                </div>
                <?= $err('params') ?>
              </div>

              <div class="form-sec"><h3>Header &amp; button (optional)</h3></div>
              <div class="field">
                <label for="ht">Header</label>
                <select id="ht" name="header_type">
                  <?php foreach (['none' => 'No header / fixed header', 'text' => 'Text with a variable', 'image' => 'Image (link)', 'document' => 'Document / PDF (link)'] as $k => $l): ?>
                  <option value="<?= $k ?>"<?= $f['header_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">Only for templates approved with a variable or media header (Interakt <code>headerValues</code>).</div>
              </div>
              <div class="field<?= isset($errs['header_param']) ? ' invalid' : '' ?>" data-show-if="header_type!=none">
                <label for="hp">Header value</label>
                <input id="hp" type="text" name="header_param" list="wa-ph-list" value="<?= e((string) $f['header_param']) ?>" maxlength="255" placeholder="course_name, invoice_url or https://…"<?= $inv('header_param') ?>>
                <datalist id="wa-ph-list"><?php foreach ($options as $k => $o): ?><option value="<?= e($k) ?>"><?= e($o['label']) ?></option><?php endforeach; ?></datalist>
                <div class="hint">Text: a placeholder. Image / document: a link placeholder (e.g. <code>invoice_url</code> attaches the invoice PDF) or a fixed https:// link.</div><?= $err('header_param') ?>
              </div>
              <div class="field full<?= isset($errs['button_url_param']) ? ' invalid' : '' ?>">
                <label for="bp">Dynamic URL button value</label>
                <?= $sel('button_url_param', 'bp', (string) $f['button_url_param']) ?>
                <div class="hint">For a template whose first button is a dynamic URL like <code><?= e(rtrim(app_url(''), '/')) ?>/{{1}}</code>: pick the matching <code>…_path</code> (the part after your domain)<?= $edit === 'password_reset' ? ', or <code>reset_token</code> for <code>…/reset-password?token={{1}}</code>' : '' ?>. Sent as Interakt <code>buttonValues {"0": [value]}</code>.</div><?= $err('button_url_param') ?>
              </div>

              <div class="form-sec"><h3>Rules</h3></div>
              <div class="field">
                <label class="check"><input type="checkbox" name="requires_opt_in" value="1"<?= (int) $f['requires_opt_in'] ? ' checked' : '' ?><?= !empty($ev['always_opt_in']) || $ev['recipient'] === 'staff' ? ' disabled' : '' ?>> Only send to people who opted in</label>
                <div class="hint"><?= e(!empty($ev['always_opt_in']) ? 'Always required for this message.' : ($ev['recipient'] === 'staff' ? 'Staff numbers are configured by admins, so no opt-in is needed.' : ($ev['recipient'] === 'lead' ? 'Leads count as opted in only when they tick the consent box on the form.' : 'Students opt in at sign-up or in their profile (WhatsApp policy).'))) ?><?= !empty($ev['pref']) ? ' Students who turned off “' . e(str_replace('_', ' ', $ev['pref'])) . '” are skipped.' : '' ?></div>
              </div>
              <div class="field<?= isset($errs['is_enabled']) ? ' invalid' : '' ?>">
                <label class="switch"><input type="checkbox" name="is_enabled" value="1"<?= (int) $f['is_enabled'] ? ' checked' : '' ?>><span class="track"></span> Send this message automatically</label>
                <?= $err('is_enabled') ?>
              </div>
            </div>
            <div class="form-actions">
              <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
              <button class="btn btn-light" form="wa-restore" data-confirm="Restore the default text, parameters and button for this message? The template name and on/off switch stay as they are."><?= icon('refresh') ?> Restore default</button>
              <button class="btn btn-primary" name="_action" value="save_template"><?= icon('check') ?> Save template</button>
            </div>
          </form>

          <aside class="wa-side">
            <div class="card">
              <div class="card-head"><h2>Live preview</h2><span class="muted" style="font-size:12px">Sample data</span></div>
              <div class="card-pad" style="background:var(--card-2)">
                <div class="wa-phone" aria-label="WhatsApp preview">
                  <div class="wa-phone-top"><span class="av"><?= e(mb_strtoupper(mb_substr((string) setting('site_name', 'L'), 0, 1))) ?></span><span><b><?= e((string) setting('site_name', 'Laxyam Academy')) ?></b><small>Business account</small></span></div>
                  <div class="wa-chat">
                    <div class="wa-day"><span>Today</span></div>
                    <div class="wa-bubble" id="wa-bubble" aria-live="polite"></div>
                    <div class="wa-btn" id="wa-btn" hidden><?= icon('external') ?> <span>Open link</span></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><h2>Send a test</h2></div>
              <div class="card-pad">
                <div class="field">
                  <label for="tp">WhatsApp number</label>
                  <input id="tp" type="tel" name="test_phone" form="wa-tpl-form" inputmode="tel" maxlength="20" placeholder="98765 43210" value="<?= e((string) ($draft['test_phone'] ?? '')) ?>">
                  <div class="hint">Sends this template (as currently filled in, even unsaved) with the sample data through the real <?= e($providerName) ?> API. Logged as a test; max 10 per hour.</div>
                </div>
                <button class="btn btn-whatsapp btn-primary" style="margin-top:12px" form="wa-tpl-form" name="_action" value="test" data-no-loading><?= icon('send') ?> Send test</button>
                <?php if (!$status['credentials']): ?><p class="muted" style="font-size:12px;margin:10px 0 0"><?= icon('alert') ?> The API key is not set in Keys &amp; Credentials yet, so the test will fail with that reason.</p><?php endif; ?>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><h2>Submit this to Interakt</h2><button type="button" class="btn btn-light btn-sm" id="wa-copy-num" data-copy=""><?= icon('copy') ?> Copy</button></div>
              <div class="card-pad">
                <p class="muted" style="margin:0 0 8px;font-size:12.5px">Create the template in Interakt with this text, so its numbered variables match the mapping:</p>
                <div class="wa-numbered" id="wa-numbered"></div>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><h2>Request body sent to <?= e($providerName) ?></h2></div>
              <pre class="wa-json" id="wa-json"></pre>
            </div>
            <div class="card">
              <div class="card-head"><h2>Recent messages</h2><a class="btn btn-ghost btn-sm" href="whatsapp.php?tab=logs&amp;event=<?= e($edit) ?>">All</a></div>
              <?php if ($recent): ?>
              <div class="table-wrap"><table class="t"><tbody>
                <?php foreach ($recent as $r): ?>
                <tr><td><a href="whatsapp.php?tab=logs&amp;id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a> <?= $r['is_test'] ? '<span class="badge b-purple">Test</span>' : '' ?><span class="wa-sub"><?= e(fmt_ago($r['created_at'])) ?></span></td><td><?= whatsapp_status_badge($r) ?><?= $r['error'] ? '<span class="wa-err" title="' . e($r['error']) . '">' . e($r['error']) . '</span>' : '' ?></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <?php else: ?><?= empty_state('Nothing sent yet', 'Tests and live messages for this event appear here.', 'inbox') ?><?php endif; ?>
            </div>
          </aside>
        </div>
        <form method="post" id="wa-restore" hidden><?= csrf_field() ?><input type="hidden" name="_action" value="restore"><input type="hidden" name="event" value="<?= e($edit) ?>"></form>
        <template id="wa-prow-tpl"><div class="wa-prow"><span class="num"></span><?= $sel('params[]', '', '') ?><span class="acts"><button type="button" data-move="-1" aria-label="Move up"><?= icon('up') ?></button><button type="button" data-move="1" aria-label="Move down"><?= icon('down') ?></button><button type="button" data-remove aria-label="Remove"><?= icon('close') ?></button></span></div></template>
        <script>
        (function () {
          const samples = <?= json_encode(array_map(fn($p) => $p['sample'], $ph), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
          const allowed = <?= json_encode($allowed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          const provider = <?= json_encode($status['provider']) ?>;
          const form = document.getElementById('wa-tpl-form');
          const body = document.getElementById('tb');
          const rows = document.getElementById('wa-params');
          const rowTpl = document.getElementById('wa-prow-tpl');
          const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
          const fmt = (h) => h.replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>').replace(/~([^~\n]+)~/g, '<s>$1</s>');
          const val = (name) => (form.elements[name] ? form.elements[name].value.trim() : '');
          const params = () => [...rows.querySelectorAll('select')].map((s) => s.value).filter(Boolean);
          const clean = (v) => String(v ?? '').replace(/[\r\n\t]+/g, ' ').replace(/ {2,}/g, ' ').trim();
          function renumber() { [...rows.children].forEach((r, i) => { r.querySelector('.num').textContent = '{{' + (i + 1) + '}}'; }); }
          function addRow(value) {
            const r = rowTpl.content.firstElementChild.cloneNode(true);
            r.querySelector('select').value = value || '';
            rows.appendChild(r);
            renumber();
            return r;
          }
          function render() {
            const text = body.value;
            const ps = params();
            // Chat bubble with sample values
            let html = esc(text).replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => allowed.includes(k)
              ? '<span class="v" title="{{' + k + '}}">' + esc(samples[k] ?? k) + '</span>'
              : '<span class="v bad" title="This event cannot fill it">{{' + esc(k) + '}}</span>');
            html = fmt(html).replace(/\n/g, '<br>');
            const ht = val('header_type');
            const hp = val('header_param');
            const hv = /^https:\/\//i.test(hp) ? hp : (samples[hp] || '');
            let head = '';
            if (ht === 'text') head = '<div class="hd">' + esc(hv || '{{header}}') + '</div>';
            if (ht === 'image') head = '<div class="media"><?= str_replace("'", "\\'", icon('image')) ?> Image</div>';
            if (ht === 'document') head = '<div class="doc"><?= str_replace("'", "\\'", icon('file')) ?><span>' + esc(samples.invoice_number ? 'Invoice-' + samples.invoice_number.replace(/[^A-Za-z0-9-]+/g, '-') + '.pdf' : 'document.pdf') + '</span></div>';
            const now = new Date();
            document.getElementById('wa-bubble').innerHTML = head + (html || '<span class="muted">Type the approved text to see the preview.</span>')
              + '<span class="meta">' + now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</span>';
            const bp = val('button_url_param');
            document.getElementById('wa-btn').hidden = !bp;
            // Numbered text for Interakt
            const missing = [];
            const numbered = text.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => { const i = ps.indexOf(k); if (i < 0) { missing.push(k); return '{{?' + k + '}}'; } return '{{' + (i + 1) + '}}'; });
            const box = document.getElementById('wa-numbered');
            box.textContent = numbered || '—';
            if (missing.length) box.textContent += '\n\n⚠ Not mapped: ' + [...new Set(missing)].join(', ');
            document.getElementById('wa-copy-num').dataset.copy = numbered;
            // Request body
            const tpl = { name: val('interakt_template_name') || '<template name>', languageCode: val('language_code') || 'en' };
            if (ht !== 'none' && ht) tpl.headerValues = [hv || '<' + hp + '>'];
            if (ht === 'document') tpl.fileName = 'Invoice-LXA-26-27-0012.pdf';
            if (ps.length) tpl.bodyValues = ps.map((k) => clean(samples[k] ?? ''));
            if (bp) tpl.buttonValues = { 0: [clean(samples[bp] ?? '')] };
            const reqBody = provider === 'meta'
              ? { messaging_product: 'whatsapp', to: '919876543210', type: 'template', template: { name: tpl.name, language: { code: tpl.languageCode }, components: [ps.length ? { type: 'body', parameters: tpl.bodyValues.map((t) => ({ type: 'text', text: t })) } : null].filter(Boolean) } }
              : { countryCode: '+91', phoneNumber: '9876543210', callbackData: 'lx:<log id>', type: 'Template', template: tpl };
            document.getElementById('wa-json').textContent = (provider === 'meta' ? 'POST graph.facebook.com/v20.0/<phone id>/messages\n' : 'POST https://api.interakt.ai/v1/public/message/\nAuthorization: Basic <INTERAKT_API_KEY>\n\n') + JSON.stringify(reqBody, null, 2);
          }
          rows.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            const r = b.closest('.wa-prow');
            if (b.hasAttribute('data-remove')) { r.remove(); if (!rows.children.length) addRow(''); }
            else if (b.dataset.move === '-1' && r.previousElementSibling) rows.insertBefore(r, r.previousElementSibling);
            else if (b.dataset.move === '1' && r.nextElementSibling) rows.insertBefore(r.nextElementSibling, r);
            renumber(); render(); form.classList.add('is-dirty');
          });
          document.getElementById('wa-add').addEventListener('click', () => { addRow('').querySelector('select').focus(); render(); });
          document.getElementById('wa-detect').addEventListener('click', () => {
            const found = [...new Set([...body.value.matchAll(/\{\{\s*([a-z_]+)\s*\}\}/g)].map((m) => m[1]).filter((k) => allowed.includes(k)))];
            rows.innerHTML = '';
            (found.length ? found : ['']).forEach((k) => addRow(k));
            render(); form.classList.add('is-dirty');
          });
          document.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
            const ins = '{{' + b.dataset.insert + '}}';
            const [s, en] = [body.selectionStart ?? body.value.length, body.selectionEnd ?? body.value.length];
            body.value = body.value.slice(0, s) + ins + body.value.slice(en);
            body.focus(); body.setSelectionRange(s + ins.length, s + ins.length);
            body.dispatchEvent(new Event('input', { bubbles: true }));
          }));
          form.addEventListener('input', render);
          form.addEventListener('change', render);
          render();
        })();
        </script>
        <?php
    else:
        $stats30 = array_column(db_all("SELECT event_key, SUM(status IN ('sent', 'delivered', 'read')) AS sent, SUM(status = 'failed') AS failed, SUM(status = 'skipped') AS skipped
            FROM whatsapp_messages WHERE is_test = 0 AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY event_key"), null, 'event_key');
        ?>
        <div class="alert alert-info"><?= icon('info') ?><span>WhatsApp only allows business-initiated messages that use templates approved by Meta. For each event: create the template in Interakt with the text from its edit screen, wait for approval, type its exact name here, send a test, then switch it on. Every template is off until you do.</span></div>
        <?php foreach (WHATSAPP_GROUPS as $gk => [$gLabel, $gIcon]):
            $events = array_filter(WHATSAPP_EVENTS, fn($ev) => $ev['group'] === $gk);
            if (!$events) {
                continue;
            } ?>
        <section class="card wa-group">
          <div class="card-head"><h2><?= icon($gIcon) ?> <?= e($gLabel) ?></h2></div>
          <div class="table-wrap"><table class="t stack">
            <thead><tr><th>Message</th><th>Interakt template</th><th>Category</th><th>Recipient</th><th>30 days</th><th>On</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($events as $key => $ev): $t = $templates[$key] ?? null; if (!$t) { continue; } $s = $stats30[$key] ?? null; $on = (int) $t['is_enabled'] === 1; ?>
              <tr>
                <td data-label="Message" class="wa-ev"><div><a href="?tab=templates&amp;edit=<?= e($key) ?>"><strong><?= e($ev['label']) ?></strong></a><span class="d"><?= e($ev['trigger']) ?></span></div></td>
                <td data-label="Template"><?= trim((string) $t['interakt_template_name']) !== '' ? '<code class="wa-name">' . e((string) $t['interakt_template_name']) . '</code><span class="wa-sub">' . e((string) $t['language_code']) . ' · ' . count($t['body_params']) . ' param' . (count($t['body_params']) === 1 ? '' : 's') . '</span>' : '<span class="badge b-amber">Name not set</span>' ?></td>
                <td data-label="Category"><span class="badge <?= $t['category'] === 'marketing' ? 'b-purple' : 'b-blue' ?>"><?= e(ucfirst((string) $t['category'])) ?></span></td>
                <td data-label="Recipient"><?= wa_recipient_badge((string) $t['recipient']) ?></td>
                <td data-label="30 days" class="wa-mini"><?= $s ? (int) $s['sent'] . ' sent' . ((int) $s['failed'] ? ' · <span style="color:var(--red)">' . (int) $s['failed'] . ' failed</span>' : '') . ((int) $s['skipped'] ? ' · ' . (int) $s['skipped'] . ' skipped' : '') : '—' ?></td>
                <td data-label="On">
                  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="event" value="<?= e($key) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><input type="hidden" name="back" value="whatsapp.php?tab=templates">
                    <button class="wa-switch" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e(($on ? 'Turn off ' : 'Turn on ') . $ev['label']) ?>" title="<?= $on ? 'On - click to turn off' : 'Off - click to turn on' ?>" data-no-loading></button>
                  </form>
                </td>
                <td class="actions"><a class="btn btn-light btn-xs" href="?tab=templates&amp;edit=<?= e($key) ?>"><?= icon('edit') ?> Edit &amp; preview</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </section>
        <?php endforeach;
    endif;

// ==================================================================
// Delivery logs: list + detail
// ==================================================================
elseif ($tab === 'logs'):
    $id = (int) ($_GET['id'] ?? 0);
    if ($id && ($r = db_one('SELECT m.*, u.name AS user_name FROM whatsapp_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?', [$id]))):
        $p = json_decode_array($r['params']);
        $tplInfo = (array) ($p['template'] ?? []);
        $values = (array) ($p['values'] ?? []);
        $retryable = in_array($r['status'], ['failed', 'queued'], true) && empty($p['redacted']);
        $steps = [
            ['Queued', $r['created_at'], true, false],
            ['Sent to ' . ($r['provider'] === 'meta' ? 'WhatsApp' : 'Interakt'), $r['sent_at'], (bool) $r['sent_at'], false],
            ['Delivered', $r['delivered_at'], (bool) $r['delivered_at'], false],
            ['Read', $r['read_at'], (bool) $r['read_at'], false],
        ];
        if ($r['status'] === 'failed' || $r['status'] === 'skipped') {
            $steps[] = [$r['status'] === 'skipped' ? 'Skipped' : ($r['next_attempt_at'] ? 'Failed - retry scheduled' : 'Failed'), $r['updated_at'], false, true];
        }
        ?>
        <nav class="crumbs" aria-label="Back" style="margin-bottom:12px"><a href="whatsapp.php?tab=logs"><?= icon('left') ?> All messages</a></nav>
        <div class="split">
          <div class="card">
            <div class="card-head"><h2>Message #<?= $id ?> <?= $r['is_test'] ? '<span class="badge b-purple">Test</span>' : '' ?></h2><?= whatsapp_status_badge($r) ?></div>
            <div class="card-pad">
              <dl class="kv">
                <dt>Event</dt><dd><?= e(WHATSAPP_EVENTS[$r['event_key']]['label'] ?? $r['event_key']) ?> <code class="muted"><?= e($r['event_key']) ?></code></dd>
                <dt>Recipient</dt><dd><?= e((string) ($r['recipient_name'] ?: '—')) ?> <?= wa_recipient_badge((string) $r['recipient_type']) ?><?= $r['user_id'] && $canPeople ? ' · <a href="users.php?id=' . (int) $r['user_id'] . '">Account</a>' : '' ?></dd>
                <dt>Phone</dt><dd><?= e(wa_phone($r['country_code'], $r['phone'], $canPeople)) ?></dd>
                <dt>Template</dt><dd><?= $r['template_name'] ? '<code class="wa-name">' . e((string) $r['template_name']) . '</code> · ' . e((string) $r['language_code']) : '—' ?><?= !empty($tplInfo['legacy']) ? ' <span class="badge b-gray">Legacy mapping</span>' : '' ?></dd>
                <dt>Provider</dt><dd><?= e($r['provider'] === 'meta' ? 'Meta Cloud API' : 'Interakt') ?><?= $r['provider_message_id'] ? ' · id <code>' . e((string) $r['provider_message_id']) . '</code>' : '' ?></dd>
                <dt>Attempts</dt><dd><?= (int) $r['attempts'] ?> of <?= whatsapp_max_attempts() ?><?= $r['next_attempt_at'] ? ' · next ' . e(fmt_dt($r['next_attempt_at'])) : '' ?></dd>
                <?php if ($r['error']): ?><dt><?= $r['status'] === 'skipped' ? 'Reason' : 'Error' ?></dt><dd style="color:<?= $r['status'] === 'skipped' ? 'var(--muted)' : 'var(--red)' ?>"><?= e((string) $r['error']) ?></dd><?php endif; ?>
                <dt>Related</dt><dd><?= wa_related_link($r['related_type'], $r['related_id'] !== null ? (int) $r['related_id'] : null) ?></dd>
              </dl>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
                <?php if ($retryable): ?><?= action_form('retry', ['id' => $id, 'back' => 'whatsapp.php?tab=logs&id=' . $id], icon('history') . ' Retry now', 'btn btn-primary btn-sm') ?><?php endif; ?>
                <?php if (isset(WHATSAPP_EVENTS[$r['event_key']])): ?><a class="btn btn-light btn-sm" href="whatsapp.php?tab=templates&amp;edit=<?= e($r['event_key']) ?>"><?= icon('edit') ?> Template</a><?php endif; ?>
              </div>
              <?php if (!empty($p['redacted']) || in_array('[hidden]', (array) ($p['vars'] ?? []), true)): ?><p class="muted" style="font-size:12px;margin:12px 0 0">This message carried a one-time link. It is hidden here and never re-sent; the student can request a new link.</p>
              <?php elseif ($retryable): ?><p class="muted" style="font-size:12px;margin:12px 0 0">“Retry now” uses the template’s current name and mapping, so a corrected template applies.</p><?php endif; ?>
            </div>
          </div>
          <div class="stack-y">
            <div class="card">
              <div class="card-head"><h2>Delivery status</h2></div>
              <div class="card-pad">
                <ol class="wa-timeline">
                  <?php foreach ($steps as [$label, $at, $on, $bad]): ?>
                  <li class="<?= $bad ? 'bad' : ($on ? 'on' : '') ?>"><span class="dot"></span><b><?= e($label) ?></b><small><?= $on || $bad ? e(fmt_dt($at)) : 'Waiting' ?></small></li>
                  <?php endforeach; ?>
                </ol>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><h2><?= $values ? 'Parameters sent' : 'Parameters (not sent)' ?></h2></div>
              <?php if ($values || !empty($tplInfo['body_params'])): ?>
              <div class="table-wrap"><table class="t wa-ptable"><tbody>
                <?php foreach ((array) ($tplInfo['body_params'] ?? []) as $i => $k): ?>
                <tr><td class="k"><code>{{<?= $i + 1 ?>}}</code></td><td class="k"><code class="muted"><?= e((string) $k) ?></code></td><td class="v"><?= e((string) ($values['body'][$i] ?? ($p['vars'][$k] ?? '—'))) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!empty($values['header'])): ?><tr><td class="k"><code>header</code></td><td class="k"><code class="muted"><?= e((string) ($tplInfo['header_param'] ?? '')) ?></code></td><td class="v"><?= e((string) $values['header'][0]) ?></td></tr><?php endif; ?>
                <?php foreach ((array) ($values['button'] ?? []) as $bi => $bv): ?><tr><td class="k"><code>button <?= e((string) $bi) ?></code></td><td class="k"><code class="muted"><?= e((string) ($tplInfo['button_url_param'] ?? '')) ?></code></td><td class="v"><?= e((string) ((array) $bv)[0]) ?></td></tr><?php endforeach; ?>
              </tbody></table></div>
              <?php else: ?><?= empty_state('No parameters', 'This message was not sent, so no values were built.', 'list') ?><?php endif; ?>
            </div>
            <?php if ($r['provider_response']): ?>
            <div class="card">
              <div class="card-head"><h2>Provider response</h2></div>
              <pre class="wa-json"><?= e((string) $r['provider_response']) ?></pre>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php
    else:
        [$where, $params] = wa_log_filters();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 25;
        $total = (int) db_value("SELECT COUNT(*) FROM whatsapp_messages m {$where}", $params);
        $rows = db_all("SELECT m.id, m.event_key, m.recipient_type, m.recipient_name, m.phone, m.country_code, m.template_name, m.status, m.error, m.attempts, m.next_attempt_at,
                               m.sent_at, m.delivered_at, m.read_at, m.created_at, m.is_test, m.params
                        FROM whatsapp_messages m {$where} ORDER BY m.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
        $filtered = $where !== '';
        $back = 'whatsapp.php?' . http_build_query(array_diff_key($_GET, ['id' => 1, 'export' => 1]) + ['tab' => 'logs']);
        $dueNow = (int) db_value("SELECT COUNT(*) FROM whatsapp_messages WHERE (status = 'failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW()) OR (status = 'queued' AND next_attempt_at <= NOW())");
        ?>
        <?php if ($dueNow): ?><div class="alert alert-info"><?= icon('clock') ?><span><?= $dueNow ?> message<?= $dueNow === 1 ? ' is' : 's are' ?> due for a retry. The scheduler sends them within 15 minutes, or use “Process queue now”.</span></div><?php endif; ?>
        <div class="card" data-bulk>
          <form class="wa-filters" method="get" role="search" aria-label="Filter messages">
            <input type="hidden" name="tab" value="logs">
            <div><label for="f-q">Search</label><input class="in" id="f-q" type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Name, phone or template"></div>
            <div><label for="f-st">Status</label><select class="in" id="f-st" name="status"><option value="">All statuses</option><?php foreach (WA_LOG_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= ($_GET['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div><label for="f-ev">Event</label><select class="in" id="f-ev" name="event"><option value="">All events</option><?php foreach (WHATSAPP_EVENTS as $k => $ev): ?><option value="<?= e($k) ?>"<?= ($_GET['event'] ?? '') === $k ? ' selected' : '' ?>><?= e($ev['label']) ?></option><?php endforeach; ?></select></div>
            <div><label for="f-k">Type</label><select class="in" id="f-k" name="kind"><option value="">Live + tests</option><option value="live"<?= ($_GET['kind'] ?? '') === 'live' ? ' selected' : '' ?>>Live only</option><option value="test"<?= ($_GET['kind'] ?? '') === 'test' ? ' selected' : '' ?>>Tests only</option></select></div>
            <div><label for="f-from">From</label><input class="in" id="f-from" type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
            <div><label for="f-to">To</label><input class="in" id="f-to" type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
            <div style="display:flex;gap:8px"><button class="btn btn-primary"><?= icon('search') ?> Filter</button><?php if ($filtered): ?><a class="btn btn-ghost" href="whatsapp.php?tab=logs">Reset</a><?php endif; ?></div>
          </form>
          <div class="toolbar" style="justify-content:space-between">
            <span class="muted" style="font-size:13px"><?= number_format($total) ?> message<?= $total === 1 ? '' : 's' ?><?= isset($_GET['related']) ? ' for ' . e((string) $_GET['related']) : '' ?></span>
            <a class="btn btn-light btn-sm" href="<?= e(url_with(['export' => 'logs', 'page' => null])) ?>"><?= icon('download') ?> Export CSV</a>
          </div>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="bulkbar"><strong><span data-bulk-count>0</span> selected</strong><button class="btn btn-primary btn-sm" name="_action" value="bulk_retry" data-confirm="Retry the selected failed or queued messages now?"><?= icon('history') ?> Retry selected</button><span class="muted" style="font-size:12px">Only failed or queued messages are retried.</span></div>
            <?php if ($rows): ?>
            <div class="table-wrap"><table class="t stack">
              <thead><tr><th style="width:34px"><input type="checkbox" data-check-all aria-label="Select all on this page"></th><th>Recipient</th><th>Message</th><th>Status</th><th>Tries</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): $pr = json_decode_array($r['params']); $canRetry = in_array($r['status'], ['failed', 'queued'], true) && empty($pr['redacted']); ?>
                <tr>
                  <td class="sel"><?php if ($canRetry): ?><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" data-row-check aria-label="Select message #<?= (int) $r['id'] ?>"><?php endif; ?></td>
                  <td data-label="Recipient"><div><strong><?= e((string) ($r['recipient_name'] ?: '—')) ?></strong><span class="wa-sub"><?= e(wa_phone($r['country_code'], $r['phone'], $canPeople)) ?> · <?= e(ucfirst((string) $r['recipient_type'])) ?></span></div></td>
                  <td data-label="Message"><div><a href="?tab=logs&amp;id=<?= (int) $r['id'] ?>"><strong><?= e(WHATSAPP_EVENTS[$r['event_key']]['label'] ?? $r['event_key']) ?></strong></a><?= $r['is_test'] ? ' <span class="badge b-purple">Test</span>' : '' ?><span class="wa-sub"><?= $r['template_name'] ? e((string) $r['template_name']) : 'no template' ?></span></div></td>
                  <td data-label="Status"><div><?= whatsapp_status_badge($r) ?><?php if ($r['error']): ?><span class="wa-err" title="<?= e((string) $r['error']) ?>"><?= e((string) $r['error']) ?></span><?php endif; ?><?php if ($r['next_attempt_at'] && $r['status'] === 'failed'): ?><span class="wa-sub">next <?= e(fmt_dt($r['next_attempt_at'], 'j M, g:i A')) ?></span><?php endif; ?></div></td>
                  <td data-label="Tries"><?= (int) $r['attempts'] ?></td>
                  <td data-label="Created" class="wa-mini"><div><?= e(fmt_dt($r['created_at'], 'j M Y, g:i A')) ?><?php if ($r['read_at']): ?><span class="wa-sub">read <?= e(fmt_dt($r['read_at'], 'g:i A')) ?></span><?php elseif ($r['delivered_at']): ?><span class="wa-sub">delivered <?= e(fmt_dt($r['delivered_at'], 'g:i A')) ?></span><?php endif; ?></div></td>
                  <td class="actions">
                    <a class="btn btn-light btn-xs" href="?tab=logs&amp;id=<?= (int) $r['id'] ?>" aria-label="View message #<?= (int) $r['id'] ?>"><?= icon('eye') ?> View</a>
                    <?php if ($canRetry): ?><button class="btn btn-primary btn-xs" form="wa-rowact" name="id" value="<?= (int) $r['id'] ?>" aria-label="Retry message #<?= (int) $r['id'] ?>"><?= icon('history') ?> Retry</button><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php else: ?>
            <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'whatsapp') ?></span>
              <strong><?= $filtered ? 'No messages match these filters' : 'No WhatsApp messages yet' ?></strong>
              <p><?= $filtered ? 'Try another status, event or date range.' : 'Every WhatsApp message (sent, failed or skipped with its reason) is listed here.' ?></p>
              <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="whatsapp.php?tab=logs">Clear filters</a><?php endif; ?>
            </div>
            <?php endif; ?>
          </form>
          <form id="wa-rowact" method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="retry"><input type="hidden" name="back" value="<?= e($back) ?>"></form>
          <?php render_pager($total, $page, $per); ?>
        </div>
        <?php
    endif;

// ==================================================================
// Broadcasts
// ==================================================================
elseif ($tab === 'broadcasts'):
    $bcEvents = array_filter(WHATSAPP_EVENTS, fn($ev) => ($ev['kind'] ?? '') === 'broadcast');
    $ready = array_filter(array_keys($bcEvents), fn($k) => !empty($templates[$k]['is_enabled']));
    $draft = $_SESSION['wa_bc_draft'] ?? [];
    unset($_SESSION['wa_bc_draft']);
    $courses = whatsapp_optin_ready() ? db_all("SELECT c.id, c.title, COUNT(DISTINCT e.user_id) AS students,
                    COUNT(DISTINCT CASE WHEN p.whatsapp_opt_in = 1 AND u.status = 'active' THEN e.user_id END) AS opted
             FROM courses c LEFT JOIN course_enrolments e ON e.course_id = c.id AND e.status = 'active'
             LEFT JOIN users u ON u.id = e.user_id LEFT JOIN student_profiles p ON p.user_id = e.user_id
             WHERE c.status <> 'archived' GROUP BY c.id, c.title ORDER BY c.title") : [];
    $history = db_all('SELECT b.*, c.title AS course_title, u.name AS by_name FROM whatsapp_broadcasts b LEFT JOIN courses c ON c.id = b.course_id LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 15');
    $hstats = $history ? array_column(db_all("SELECT related_id, SUM(status IN ('sent', 'delivered', 'read')) AS sent, SUM(status IN ('delivered', 'read')) AS delivered, SUM(status = 'read') AS seen,
                    SUM(status = 'failed') AS failed, SUM(status IN ('queued', 'sending')) AS pending
             FROM whatsapp_messages WHERE related_type = 'broadcast' AND related_id IN (" . implode(',', array_map(fn($h) => (int) $h['id'], $history)) . ') GROUP BY related_id'), null, 'related_id') : [];
    ?>
    <div class="split">
      <div class="card">
        <div class="card-head"><h2>New broadcast</h2></div>
        <?php if (!$ready): ?>
          <?= empty_state('Switch on a broadcast template first', 'Get “Important course update” or “Announcement / notice” approved in Interakt, enter its name and switch it on. Broadcasts only go to students who opted in.', 'megaphone', 'whatsapp.php?tab=templates&edit=course_update', 'Set up the template') ?>
        <?php else: ?>
        <form method="post" class="card-pad form" id="wa-bc">
          <?= csrf_field() ?><input type="hidden" name="_action" value="broadcast">
          <div class="field">
            <label for="bc-ev">Template</label>
            <select id="bc-ev" name="event"><?php foreach ($ready as $k): ?><option value="<?= e($k) ?>"<?= ($draft['event'] ?? '') === $k ? ' selected' : '' ?>><?= e(WHATSAPP_EVENTS[$k]['label']) ?> · <?= e((string) $templates[$k]['interakt_template_name']) ?></option><?php endforeach; ?></select>
            <div class="hint">Only approved, switched-on templates are listed.</div>
          </div>
          <div class="field">
            <label for="bc-course">Audience</label>
            <select id="bc-course" name="course_id">
              <option value="">All opted-in students (announcements only)</option>
              <?php foreach ($courses as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($draft['course_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?> · <?= (int) $c['opted'] ?> of <?= (int) $c['students'] ?> opted in</option><?php endforeach; ?>
            </select>
            <div class="hint">Active enrolments only. <?= number_format($optedIn) ?> students have opted in overall.</div>
          </div>
          <div class="field full">
            <label for="bc-msg">Update text <span class="muted" style="font-weight:500">fills {{update_text}}</span></label>
            <textarea id="bc-msg" name="message" rows="4" maxlength="600" data-counter="300,600" placeholder="e.g. Tomorrow’s live class moves to 7:00 PM. Notes are uploaded in Lesson 12."><?= e((string) ($draft['message'] ?? '')) ?></textarea>
            <div class="hint">One paragraph (WhatsApp parameters cannot contain line breaks). Keep it factual and in line with the approved template; no offers in a Utility template.</div>
          </div>
          <div class="field full"><label class="check"><input type="checkbox" name="confirm" value="1" required> I confirm this is a genuine update for these students and fits the approved template.</label></div>
          <div class="field full" style="display:flex;justify-content:flex-end"><button class="btn btn-primary" data-confirm="Send this WhatsApp broadcast now? It goes to every eligible opted-in student and cannot be recalled."><?= icon('send') ?> Send broadcast</button></div>
        </form>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-head"><h2>How broadcasts work</h2></div>
        <div class="card-pad">
          <ol class="wa-steps">
            <li><span class="n">1</span><span><b>Only opted-in students</b><span class="d">Students who ticked WhatsApp updates for their current mobile number, and did not turn off this kind of update in their profile.</span></span></li>
            <li><span class="n">2</span><span><b>Approved template only</b><span class="d">Your text fills the template’s update variable; everything else is the text WhatsApp approved.</span></span></li>
            <li><span class="n">3</span><span><b>Sent in batches</b><span class="d">The first 25 go out at once, the rest with the scheduler (every 15 minutes). Each student gets it once.</span></span></li>
            <li><span class="n">4</span><span><b>Tracked</b><span class="d">Delivered and read ticks appear below and in the delivery log.</span></span></li>
          </ol>
        </div>
      </div>
    </div>
    <div class="card mt">
      <div class="card-head"><h2>Recent broadcasts</h2></div>
      <?php if ($history): ?>
      <div class="table-wrap"><table class="t stack">
        <thead><tr><th>Sent</th><th>Template &amp; audience</th><th>Message</th><th>Recipients</th><th>Delivery</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): $s = $hstats[$h['id']] ?? null; ?>
          <tr>
            <td data-label="Sent" class="wa-mini"><div><?= e(fmt_dt($h['created_at'])) ?><span class="wa-sub"><?= e((string) ($h['by_name'] ?? '—')) ?></span></div></td>
            <td data-label="Template"><div><strong><?= e(WHATSAPP_EVENTS[$h['event_key']]['label'] ?? $h['event_key']) ?></strong><span class="wa-sub"><?= e($h['course_title'] ? (string) $h['course_title'] : 'All opted-in students') ?></span></div></td>
            <td data-label="Message"><span style="display:block;max-width:340px"><?= e(mb_strimwidth((string) $h['message'], 0, 140, '…')) ?></span></td>
            <td data-label="Recipients"><?= (int) $h['recipients'] ?><?= (int) $h['skipped'] ? '<span class="wa-sub">' . (int) $h['skipped'] . ' skipped</span>' : '' ?></td>
            <td data-label="Delivery" class="wa-mini"><?= $s ? (int) $s['sent'] . ' sent · ' . (int) $s['delivered'] . ' delivered · ' . (int) $s['seen'] . ' read' . ((int) $s['failed'] ? ' · <span style="color:var(--red)">' . (int) $s['failed'] . ' failed</span>' : '') . ((int) $s['pending'] ? ' · ' . (int) $s['pending'] . ' queued' : '') : '—' ?></td>
            <td class="actions"><a class="btn btn-light btn-xs" href="whatsapp.php?tab=logs&amp;related=broadcast:<?= (int) $h['id'] ?>"><?= icon('log') ?> Messages</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><?= empty_state('No broadcasts yet', 'Course updates and notices you send from here are listed with their delivery counts.', 'megaphone') ?><?php endif; ?>
    </div>
    <?php

// ==================================================================
// Opt-ins
// ==================================================================
elseif ($tab === 'optins'):
    if (!whatsapp_optin_ready()) {
        echo '<div class="alert alert-warning">' . icon('alert') . '<span>The opt-in columns are missing. Apply the latest database update.</span></div>';
    } else {
        $view = ($_GET['view'] ?? '') === 'out' ? 'out' : 'in';
        $counts = db_one("SELECT SUM(p.whatsapp_opt_in = 1) AS opted, SUM(p.whatsapp_opt_in = 0 AND p.whatsapp_opt_out_at IS NOT NULL) AS out_n,
                                 SUM(p.whatsapp_opt_in = 1 AND p.whatsapp_opt_in_phone IS NOT NULL AND RIGHT(REGEXP_REPLACE(COALESCE(u.phone, ''), '[^0-9]', ''), 10) <> RIGHT(p.whatsapp_opt_in_phone, 10)) AS changed,
                                 COUNT(*) AS students
                          FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.user_type = 'student' AND u.status = 'active'") ?: [];
        $counts = array_map('intval', $counts);
        $q = trim((string) ($_GET['q'] ?? ''));
        $w = ["u.user_type = 'student'", "u.status = 'active'", $view === 'in' ? 'p.whatsapp_opt_in = 1' : 'p.whatsapp_opt_in = 0 AND p.whatsapp_opt_out_at IS NOT NULL'];
        $prm = [];
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
            $w[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($prm, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $w);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 25;
        $total = (int) db_value("SELECT COUNT(*) FROM users u JOIN student_profiles p ON p.user_id = u.id {$where}", $prm);
        $rows = db_all("SELECT u.id, u.name, u.email, u.phone, p.whatsapp_opt_in_at, p.whatsapp_opt_out_at, p.whatsapp_opt_in_source, p.whatsapp_opt_in_phone
                        FROM users u JOIN student_profiles p ON p.user_id = u.id {$where} ORDER BY " . ($view === 'in' ? 'p.whatsapp_opt_in_at' : 'p.whatsapp_opt_out_at') . " DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $prm);
        $sources = ['register' => 'Sign-up form', 'profile' => 'Profile', 'admin' => 'Staff (opt-out on request)', 'whatsapp_stop' => 'Replied STOP'];
        ?>
        <section class="grid g-4 wa-kpi" aria-label="Opt-in summary">
          <?php foreach ([
              ['Opted in', $counts['opted'] ?? 0, 'check-circle', 'tone-green', ($counts['students'] ?? 0) ? round(($counts['opted'] ?? 0) / max(1, $counts['students']) * 100) . '% of active students' : ''],
              ['Opted out', $counts['out_n'] ?? 0, 'close', 'tone-red', 'Profile switch or STOP reply'],
              ['Number changed', $counts['changed'] ?? 0, 'phone', 'tone-amber', 'Need to opt in again for the new number'],
              ['Active students', $counts['students'] ?? 0, 'users', 'tone-blue', 'Not asked = no WhatsApp'],
          ] as [$label, $value, $ico, $tone, $sub]): ?>
          <div class="card stat"><span class="ico <?= $tone ?>"><?= icon($ico) ?></span><span><span class="label"><?= e($label) ?></span><span class="value"><?= number_format((int) $value) ?></span><span class="sub"><?= e($sub) ?></span></span></div>
          <?php endforeach; ?>
        </section>
        <div class="card mt">
          <form class="toolbar" method="get" role="search" aria-label="Search opt-ins">
            <input type="hidden" name="tab" value="optins"><input type="hidden" name="view" value="<?= $view ?>">
            <?= admin_tabs(['in' => ['label' => 'Opted in', 'href' => '?tab=optins&view=in', 'count' => $counts['opted'] ?? 0], 'out' => ['label' => 'Opted out', 'href' => '?tab=optins&view=out', 'count' => $counts['out_n'] ?? 0]], $view, ['label' => 'Opt-in status']) ?>
            <input class="in search-in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or phone" aria-label="Search students">
            <button class="btn btn-light"><?= icon('search') ?> Search</button>
            <?php if ($canPeople): ?><a class="btn btn-light" href="whatsapp.php?tab=optins&amp;export=optins"><?= icon('download') ?> Export CSV</a><?php endif; ?>
          </form>
          <?php if ($rows): ?>
          <div class="table-wrap"><table class="t stack">
            <thead><tr><th>Student</th><th>Mobile</th><th><?= $view === 'in' ? 'Opted in' : 'Opted out' ?></th><th>Source</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $now = whatsapp_normalize_phone((string) $r['phone']); $changed = $view === 'in' && $r['whatsapp_opt_in_phone'] && (!$now || $now['e164'] !== $r['whatsapp_opt_in_phone']); ?>
              <tr>
                <td data-label="Student"><div><strong><?= $canPeople ? '<a href="users.php?id=' . (int) $r['id'] . '">' . e($r['name']) . '</a>' : e($r['name']) ?></strong><span class="wa-sub"><?= e($canPeople ? (string) $r['email'] : preg_replace('/^(.).*(@.*)$/', '$1•••$2', (string) $r['email'])) ?></span></div></td>
                <td data-label="Mobile"><?= e($now ? ($canPeople ? $now['display'] : whatsapp_mask_phone($now['e164'])) : (string) ($r['phone'] ?: '—')) ?><?= $changed ? ' <span class="badge b-amber" title="Opted in for a different number">Number changed</span>' : '' ?></td>
                <td data-label="When" class="wa-mini"><?= e(fmt_dt($view === 'in' ? $r['whatsapp_opt_in_at'] : $r['whatsapp_opt_out_at'])) ?></td>
                <td data-label="Source"><?= e($sources[(string) $r['whatsapp_opt_in_source']] ?? ucfirst((string) ($r['whatsapp_opt_in_source'] ?? '—'))) ?></td>
                <td class="actions"><?php if ($view === 'in'): ?><?= action_form('opt_out', ['user_id' => $r['id'], 'back' => 'whatsapp.php?tab=optins'], icon('close') . ' Opt out', 'btn btn-light btn-xs', 'Opt ' . $r['name'] . ' out of WhatsApp updates? Do this only when the student asked for it.') ?><a class="btn btn-ghost btn-xs" href="whatsapp.php?tab=logs&amp;q=<?= e(rawurlencode((string) $r['phone'])) ?>"><?= icon('log') ?> Messages</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php else: ?>
          <?= empty_state($q !== '' ? 'No students match' : ($view === 'in' ? 'No opt-ins yet' : 'No opt-outs'), $view === 'in' ? 'Students opt in with the WhatsApp checkbox at sign-up or the WhatsApp switch in their profile. Staff cannot opt students in on their behalf.' : 'Students who switch WhatsApp off or reply STOP appear here.', 'whatsapp') ?>
          <?php endif; ?>
          <?php render_pager($total, $page, $per); ?>
        </div>
        <?php
    }

// ==================================================================
// Settings
// ==================================================================
else:
    $lastHook = db_one("SELECT received_at, event_type, status FROM webhook_events WHERE provider = 'interakt' ORDER BY id DESC LIMIT 1");
    $admins = whatsapp_admin_numbers();
    $ro = !$canSettings;
    ?>
    <?php if ($ro): ?><div class="alert alert-info"><?= icon('lock') ?><span>Only staff with the <code>settings.manage</code> permission can change these settings.</span></div><?php endif; ?>
    <div class="split">
      <form method="post" class="card" data-dirty-check>
        <?= csrf_field() ?><input type="hidden" name="_action" value="settings">
        <div class="card-head"><h2>Connection &amp; sending</h2><?= $status['ready'] ? '<span class="badge b-green">Ready</span>' : '<span class="badge b-amber">Not ready</span>' ?></div>
        <fieldset class="card-pad form" style="border:0;margin:0"<?= $ro ? ' disabled' : '' ?>>
          <div class="field full"><label class="switch"><input type="checkbox" name="enabled" value="1"<?= $status['enabled'] ? ' checked' : '' ?>><span class="track"></span> <strong>Send WhatsApp messages</strong></label><div class="hint">Master switch. While off, nothing is sent or queued (tests still work).</div></div>
          <div class="field">
            <label for="s-prov">Provider</label>
            <select id="s-prov" name="provider"><option value="interakt"<?= $status['provider'] === 'interakt' ? ' selected' : '' ?>>Interakt (recommended)</option><option value="meta"<?= $status['provider'] === 'meta' ? ' selected' : '' ?>>Meta WhatsApp Cloud API</option></select>
          </div>
          <div class="field">
            <label>API credentials (.env)</label>
            <div style="padding-top:8px;display:grid;gap:4px;font-size:13px">
              <?php foreach ($status['provider'] === 'meta' ? ['WHATSAPP_TOKEN + WHATSAPP_PHONE_ID' => whatsapp_credentials_ok('meta')] : ['INTERAKT_API_KEY' => whatsapp_credentials_ok('interakt'), 'INTERAKT_WEBHOOK_SECRET' => $status['webhook_secret']] as $k => $ok): ?>
              <span><span class="status-dot" style="color:<?= $ok ? 'var(--green)' : 'var(--red)' ?>"><?= $ok ? 'Configured' : 'Not configured' ?></span> <code><?= e($k) ?></code></span>
              <?php endforeach; ?>
              <?php if ($status['custom_base']): ?><span><span class="status-dot" style="color:var(--amber)">Custom</span> <code>INTERAKT_API_BASE</code> (testing only)</span><?php endif; ?>
            </div>
            <div class="hint">Secrets are read only on the server and are never shown here.</div>
          </div>
          <div class="form-sec"><h3>Numbers</h3></div>
          <div class="field">
            <label for="s-sup">Support number <span class="muted" style="font-weight:500">{{support_number}}</span></label>
            <input id="s-sup" type="tel" name="support_number" value="<?= e((string) setting('whatsapp_support_number', '')) ?>" placeholder="<?= e(whatsapp_support_number()) ?>">
            <div class="hint">Empty = the contact phone from Academy Information.</div>
          </div>
          <div class="field">
            <label for="s-cc">Default country code</label>
            <input id="s-cc" type="text" name="default_cc" value="<?= e(whatsapp_default_country_code()) ?>" maxlength="4" placeholder="+91">
            <div class="hint">Added to 10-digit numbers without a code.</div>
          </div>
          <div class="field full">
            <label for="s-adm">Admin alert numbers</label>
            <textarea id="s-adm" name="admin_numbers" rows="2" placeholder="98765 43210, +91 99999 88888"><?= e(implode(', ', array_map(fn($n) => whatsapp_normalize_phone($n)['display'] ?? $n, $admins))) ?></textarea>
            <div class="hint">Comma separated, up to 10. Used for the “Admin alert” templates (new enquiry, paid order, job application, support ticket).</div>
          </div>
          <div class="field">
            <label for="s-max">Delivery attempts</label>
            <input id="s-max" type="number" name="max_attempts" min="1" max="10" value="<?= whatsapp_max_attempts() ?>">
            <div class="hint">Retries after 2 min, 10 min, 1 h, then every 6 h. Rate limits, timeouts and server errors are retried; rejected templates or numbers are not.</div>
          </div>
        </fieldset>
        <?php if (!$ro): ?><div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save settings</button></div><?php endif; ?>
      </form>
      <div class="stack-y">
        <div class="card">
          <div class="card-head"><h2>Delivery webhook</h2><?= $lastHook ? '<span class="badge b-green">Receiving</span>' : '<span class="badge b-gray">No events yet</span>' ?></div>
          <div class="card-pad">
            <div class="field">
              <label for="s-hook">Webhook URL</label>
              <div class="wa-copyrow"><input id="s-hook" class="in" type="text" value="<?= e($status['webhook_url']) ?>" readonly><button type="button" class="btn btn-light" data-copy="<?= e($status['webhook_url']) ?>"><?= icon('copy') ?> Copy</button></div>
            </div>
            <ol class="wa-steps" style="margin-top:16px">
              <li><span class="n">1</span><span><b>Interakt → Settings → Developer Settings → Webhooks</b><span class="d">Paste the URL above (must be HTTPS on the live site).</span></span></li>
              <li><span class="n">2</span><span><b>Secret key</b><span class="d">Type a long random secret there and put the same value in <a href="keys.php#whatsapp">Keys &amp; Credentials → Interakt webhook secret</a>.</span></span></li>
              <li><span class="n">3</span><span><b>Events</b><span class="d">Enable message status for API messages (sent, delivered, read, failed). Optional: incoming messages, used only to honour “STOP”.</span></span></li>
            </ol>
            <p class="muted" style="font-size:12.5px;margin:14px 0 0">Verification: every call must carry <code>Interakt-Signature: sha256=&lt;HMAC-SHA256 of the body with the secret&gt;</code>. Unsigned or wrongly signed calls get HTTP 401 and change nothing. Interakt expects a 200 within 3 seconds and does not retry failed webhooks.</p>
            <?php if ($lastHook): ?><p style="font-size:12.5px;margin:10px 0 0"><?= icon('check-circle') ?> Last event: <code><?= e((string) $lastHook['event_type']) ?></code> · <?= e(fmt_ago((string) $lastHook['received_at'])) ?> (<?= e((string) $lastHook['status']) ?>)</p><?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h2>WhatsApp messaging rules</h2></div>
          <div class="card-pad">
            <ol class="wa-steps">
              <li><span class="n"><?= icon('check') ?></span><span><b>Approved templates only</b><span class="d">Business-initiated messages must use a template approved by Meta (through Interakt); the text here is for preview only.</span></span></li>
              <li><span class="n"><?= icon('check') ?></span><span><b>Opt-in first</b><span class="d">Students opt in themselves (sign-up checkbox or profile switch); enquiry consent covers only that enquiry’s confirmation.</span></span></li>
              <li><span class="n"><?= icon('check') ?></span><span><b>Right category</b><span class="d">Payments, enrolments, results and reminders are Utility. Offers and promotions are Marketing and need marketing consent.</span></span></li>
              <li><span class="n"><?= icon('check') ?></span><span><b>Easy opt-out</b><span class="d">Replying STOP (with incoming-message webhooks on) or the profile switch stops further messages.</span></span></li>
            </ol>
          </div>
        </div>
      </div>
    </div>
    <?php
endif;
?>
</div>
<?php admin_end();
