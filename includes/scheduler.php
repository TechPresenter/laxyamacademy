<?php
/**
 * Scheduled jobs: live-class reminders, test-result and failed-payment emails, the email
 * and WhatsApp retry queues, enrolment expiry, stale pending orders, scheduled blog posts and
 * rate-limit housekeeping.
 *
 * The server cron runs them every 15 minutes (database/cron.php). When no cron run has
 * been recorded for 30 minutes, the same jobs run from ordinary website traffic
 * (api/track.php) at most once every 15 minutes, so nothing is missed on hosting
 * where cron has not been set up yet. Admin → Go-Live Checklist shows which is active.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Run an optional job from another module; failures are logged and never stop the other jobs. */
function scheduler_hook(string $file, string $function): int
{
    $path = APP_ROOT . '/includes/' . $file;
    if (!function_exists($function) && is_file($path)) {
        require_once $path;
    }
    if (!function_exists($function)) {
        return 0;
    }
    try {
        return (int) $function();
    } catch (Throwable $e) {
        error_log("[laxyam] scheduled job {$function} failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Run every job once. $source is 'cron' or 'web'. Only one run at a time (cron or web fallback): a database lock,
 * released automatically if the process dies, so slow email sending can never make two runs send things twice.
 */
function run_scheduled_jobs(string $source): array
{
    $lock = 'laxyam_scheduler_' . substr(md5((string) config('DB_NAME', '')), 0, 12);
    try {
        $v = db_value('SELECT GET_LOCK(?, 0)', [$lock]);
        $got = $v === null ? 1 : (int) $v; // NULL = lock error: run anyway rather than never running
    } catch (Throwable) {
        $got = 1; // no lock support: behave as before rather than never running
    }
    if ($got !== 1) {
        return ['busy' => 1]; // the previous run is still working
    }
    try {
        return run_scheduled_jobs_locked($source);
    } finally {
        try {
            db_value('SELECT RELEASE_LOCK(?)', [$lock]);
        } catch (Throwable) {
        }
    }
}

function run_scheduled_jobs_locked(string $source): array
{
    require_once APP_ROOT . '/includes/catalog.php';
    require_once APP_ROOT . '/includes/notify.php';
    $result = [
        'reminders' => send_due_class_reminders(), // lead time: admin → Live Class Settings
        'test_results' => scheduler_hook('exams.php', 'exam_results_notify_due'), // results released by time / by staff
        'payment_failed' => scheduler_hook('payments.php', 'notify_stale_failed_payments'), // still unpaid 15+ min after a failure
        'expired_enrolments' => db_query("UPDATE course_enrolments SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()")->rowCount(),
        'cancelled_orders' => db_query("UPDATE orders SET status = 'cancelled' WHERE status = 'pending' AND created_at < NOW() - INTERVAL 2 DAY")->rowCount(),
        'published_posts' => db_query("UPDATE blog_posts SET status = 'published' WHERE status = 'scheduled' AND published_at <= NOW()")->rowCount(),
    ];
    // WhatsApp retries + queued broadcasts (back-off 2m, 10m, 1h, 6h; admin → WhatsApp → Delivery logs).
    $result['whatsapp_sent'] = scheduler_hook('whatsapp.php', 'whatsapp_scheduled_job');
    // Last, so emails queued by the jobs above go out in the same run.
    $queue = process_email_queue(50, 600); // at most 10 minutes of sending per run; the rest waits for the next run
    $result['emails_sent'] = $queue['sent'];
    $result['emails_failed'] = $queue['failed'];
    $result['emails_logged'] = $queue['skipped']; // SMTP not configured: written to mail.log
    db_query('DELETE FROM rate_limit_hits WHERE created_at < NOW() - INTERVAL 1 DAY');
    if ($result['published_posts']) {
        cache_clear(); // homepage / layout caches list the latest posts
    }
    $record = json_encode(['at' => date('Y-m-d H:i:s'), 'source' => $source] + $result);
    foreach ($source === 'cron' ? ['scheduler_last_run', 'cron_last_run'] : ['scheduler_last_run'] as $key) {
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'system', 0)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, $record]);
    }
    return $result;
}

/** Fallback when the server cron is not running: run from web traffic, at most every 15 minutes. */
function maybe_run_scheduler(): void
{
    $cron = json_decode_array(setting('cron_last_run'));
    if (!empty($cron['at']) && strtotime($cron['at']) > time() - 1800) {
        return; // the real cron is working
    }
    $lock = cache_dir() . '/scheduler.lock';
    if (is_file($lock) && filemtime($lock) > time() - 900) {
        return;
    }
    if (!is_dir(cache_dir())) {
        @mkdir(cache_dir(), 0750, true);
    }
    $fh = @fopen($lock, 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return; // another request is running the jobs
    }
    try {
        // The file holds the last start time; re-check it under the lock.
        if ((int) stream_get_contents($fh) > time() - 900) {
            return;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) time());
        fflush($fh);
        run_scheduled_jobs('web');
    } catch (Throwable $e) {
        error_log('[laxyam] scheduled jobs failed: ' . $e->getMessage());
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
