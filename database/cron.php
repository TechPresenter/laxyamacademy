<?php
// Scheduled jobs. Run every 15 minutes from cron (CLI only; /database is blocked from the web).
// Hostinger (hPanel -> Advanced -> Cron Jobs -> Custom), crontab line:
//   */15 * * * * /usr/bin/php /home/u623595918/public_html/database/cron.php >> /home/u623595918/public_html/uploads/private/logs/cron.log 2>&1
// The PHP binary and home path may differ on your plan/server: hPanel shows the PHP path, and
// admin -> Settings -> Go-Live Checklist -> "Scheduled jobs" shows this file's exact path until cron has run.
// Without cron, the same jobs run from website traffic (includes/scheduler.php), but cron keeps
// reminders, email and WhatsApp retries (and queued WhatsApp broadcasts) on time.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/config.php';
require_once APP_ROOT . '/includes/scheduler.php';

$r = run_scheduled_jobs('cron');
echo date('c') . ' ' . implode(' ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($r), $r)) . "\n";
