<?php
/**
 * Interakt webhook receiver (Interakt → Settings → Developer Settings → Webhooks):
 *   URL:        https://your-domain/api/whatsapp-webhook.php   (HTTPS, must answer 200 within 3 seconds)
 *   Secret key: the same value as INTERAKT_WEBHOOK_SECRET in .env
 *   Events:     message status of API-sent templates (message_api_sent / _delivered / _read / _failed);
 *               message_received is optional and is used only to honour "STOP" replies.
 *
 * Authenticity: Interakt sends "Interakt-Signature: sha256=<hex HMAC-SHA256 of the body with the secret>".
 * Calls without a valid signature (or while no secret is configured) are rejected and never touch data.
 * Each event (type + message id + status, from the decoded payload) is processed once (webhook_events,
 * provider 'interakt'); Interakt does not retry webhooks.
 * No session / CSRF here - authenticity comes from the signature.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/whatsapp.php';

require_method('POST');

$raw = file_get_contents('php://input', false, null, 0, 1_048_576) ?: '';
$secret = (string) config('INTERAKT_WEBHOOK_SECRET', '');
if ($secret === '') {
    api_error('WhatsApp webhooks are not configured on this server.', 503, 'not_configured');
}
if (!whatsapp_webhook_signature_ok($raw, (string) ($_SERVER['HTTP_INTERAKT_SIGNATURE'] ?? ''), $secret)) {
    // Logged at most 20 times an hour per address so a flood cannot fill the audit log.
    if (rate_limit('wa-webhook-invalid', client_ip(), 20, 3600)) {
        audit_log('whatsapp_webhook_signature_invalid', 'webhook', null, ['ip' => client_ip()], 'warning');
    }
    api_error('Invalid signature.', 401, 'signature_invalid');
}
$event = json_decode($raw, true);
if (!is_array($event) || !is_string($event['type'] ?? null)) {
    api_error('Invalid payload.', 400, 'invalid_payload');
}
if (!whatsapp_schema_ready()) {
    api_ok(['received' => true, 'result' => 'ignored']);
}

// Process each event once. Only a minimal summary is stored (no message text, no customer traits).
$type = mb_substr($event['type'], 0, 80);
$messageId = is_scalar($event['data']['message']['id'] ?? null) ? mb_substr((string) $event['data']['message']['id'], 0, 100) : null;
$msgStatus = is_scalar($event['data']['message']['message_status'] ?? null) ? mb_substr((string) $event['data']['message']['message_status'], 0, 30) : null;
// The de-dup key comes from the decoded payload, not the raw bytes: the signature check also accepts the
// re-encoded JSON, so a replay with different whitespace must still count as the same event.
$eventId = hash('sha256', $messageId !== null && $messageId !== ''
    ? 'evt|' . $type . '|' . $messageId . '|' . (string) $msgStatus
    : 'json|' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$summary = json_encode(['type' => $type, 'message_id' => $messageId, 'status' => $msgStatus], JSON_UNESCAPED_SLASHES);
$ins = db_query('INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_valid, payload) VALUES (?, ?, ?, 1, ?)', ['interakt', $eventId, $type, $summary]);
if ($ins->rowCount() === 0 && in_array((string) db_value("SELECT status FROM webhook_events WHERE provider = 'interakt' AND event_id = ?", [$eventId]), ['processed', 'ignored'], true)) {
    api_ok(['received' => true, 'duplicate' => true]);
}

try {
    $r = whatsapp_handle_webhook($event);
    db_query("UPDATE webhook_events SET status = ?, processed_at = NOW(), error = NULL WHERE provider = 'interakt' AND event_id = ?",
        [$r['result'] === 'ignored' ? 'ignored' : 'processed', $eventId]);
} catch (Throwable $e) {
    db_query("UPDATE webhook_events SET status = 'failed', error = ? WHERE provider = 'interakt' AND event_id = ?", [mb_substr($e->getMessage(), 0, 250), $eventId]);
    error_log('[laxyam] interakt webhook failed: ' . $e->getMessage());
    api_error('Could not process the event.', 500, 'server_error');
}
api_ok(['received' => true, 'result' => $r['result']]);
