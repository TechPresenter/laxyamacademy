<?php
/**
 * Go-live checklist: live checks of the things that must be right before (and after)
 * launch, plus a few manual steps an admin ticks off. Shown in admin/launch.php and
 * summarised on the dashboard. Every automatic item reads real configuration or data.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/demo-content.php';
require_once APP_ROOT . '/includes/migrations.php';
require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/payments.php';

/** Manual steps: key => [title, how to do it, link]. Done state lives in setting launch_manual. */
const LAUNCH_MANUAL = [
    'policies_reviewed' => ['Policy pages reviewed', 'Have the privacy, terms, refund and cancellation pages checked for your business (ideally by a legal professional).', 'cms.php?tab=pages&view=legal'],
    'test_email' => ['Test email received', 'Send a test email from Integrations → SMTP and confirm it arrives (check spam too).', 'integrations.php#smtp'],
    'test_payment' => ['Live test purchase done', 'Buy a low-priced course with a real card/UPI in live mode, check the receipt and enrolment, then refund it.', 'orders.php'],
    'host_backups' => ['Hosting backups switched on', 'In hPanel → Files → Backups, confirm automatic backups run for files and the database.', null],
];

/** Groups of items: ['key','title','state' => ok|warn|fail|manual,'detail','link','action']. */
function launch_checks(): array
{
    $item = fn(string $key, string $title, string $state, string $detail, ?string $link = null, ?string $action = null): array
        => compact('key', 'title', 'state', 'detail', 'link', 'action');
    $ago = fn(?string $at): string => $at ? fmt_ago($at) : 'never';

    $demoStaff = db_all("SELECT email FROM users WHERE user_type = 'staff' AND status = 'active' AND (email LIKE '%@example.com' OR email LIKE '%@example.org' OR email LIKE '%@example.net')");
    $staff = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'");
    $staff2fa = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active' AND totp_enabled_at IS NOT NULL");
    $debug = (bool) config('APP_DEBUG', false);
    $counts = demo_counts();
    $demoRows = array_sum($counts) - $counts['students'];
    $realCourses = (int) db_value("SELECT COUNT(*) FROM courses WHERE status = 'published' AND is_demo = 0");
    $contact = array_filter([setting('contact_email'), setting('contact_phone'), setting('address')]);
    $social = array_filter(array_map(fn($k) => setting($k), ['social_youtube', 'social_facebook', 'social_instagram', 'social_x', 'social_linkedin', 'social_threads', 'social_telegram', 'social_whatsapp_channel']));
    $live = config('PAYMENT_MODE', 'sandbox') === 'live';
    // Active gateway (PAYMENT_PROVIDER): its keys and webhook are what the checklist checks.
    $gwKnown = in_array(payment_provider(), ['razorpay', 'cashfree'], true);
    $gw = payment_gateway_status($gwKnown ? payment_provider() : 'razorpay');
    $gwHookRecent = !empty($gw['last_webhook']) && strtotime((string) $gw['last_webhook']['received_at']) > time() - 30 * 86400;
    $cron = json_decode_array(setting('cron_last_run'));
    $sched = json_decode_array(setting('scheduler_last_run'));
    $backup = json_decode_array(setting('backup_last_at'));
    $pendingDb = count(pending_migrations()) + (obsolete_files() ? 1 : 0);
    $fresh =fn(array $r, int $sec): bool => !empty($r['at']) && strtotime($r['at']) > time() - $sec;

    $groups = [
        'Security' => [
            $item('demo_admin', 'Demo admin login replaced', $demoStaff ? 'fail' : 'ok',
                $demoStaff ? implode(', ', array_column($demoStaff, 'email')) . ' can still sign in with the shared demo password. Change it to your own email and set a new password.' : 'No staff account uses a demo email address.',
                'profile.php#email', 'Change sign-in email'),
            $item('two_factor', 'Two-step login for staff', $staff && $staff2fa === $staff ? 'ok' : 'warn',
                "{$staff2fa} of {$staff} active staff accounts use an authenticator app" . (setting('admin_require_2fa') === '1' ? ' (required for all).' : '.'),
                'two-factor.php', 'Set up'),
            $item('https', 'HTTPS (SSL certificate)', is_https() ? 'ok' : 'fail',
                is_https() ? 'This connection is encrypted.' : 'This page was not loaded over HTTPS. On the live site, enable SSL in hPanel → Security → SSL.'),
            $item('production', 'Production mode', is_production() && !$debug ? 'ok' : 'fail',
                is_production() && !$debug ? 'APP_ENV=production and error details are hidden.' : 'Set APP_ENV=production and APP_DEBUG=false in the server .env file.' . (is_production() ? '' : ' (Expected on a local copy.)')),
        ],
        'Content' => [
            $item('real_courses', 'Real courses published', $realCourses ? 'ok' : 'fail',
                $realCourses ? plural_n($realCourses, 'published course') . ' that ' . ($realCourses === 1 ? 'is' : 'are') . ' not demo content.' : 'Only demo courses are published. Add your real courses, prices and lessons.',
                'courses.php?action=new', 'Add a course'),
            $item('demo_content', 'Demo content removed', $demoRows ? 'warn' : 'ok',
                $demoRows ? plural_n($demoRows, 'demo item') . ' ' . ($demoRows == 1 ? 'is' : 'are') . ' still on the website (courses, tests, blog posts, testimonials…). Remove them once your real content is in.' : 'No demo content left.',
                'demo-content.php', 'Review demo content'),
            $item('demo_students', 'Demo & test student accounts removed', $counts['students'] ? 'warn' : 'ok',
                $counts['students'] ? plural_n((int) $counts['students'], 'student account') . ((int) $counts['students'] === 1 ? ' uses an example.com email or is marked demo.' : ' use example.com emails or are marked demo.') : 'None left.',
                'demo-content.php', 'Remove'),
            $item('contact', 'Contact details', count($contact) === 3 ? 'ok' : 'warn',
                count($contact) === 3 ? 'Email, phone and address are set.' : 'Add the contact email, phone and address shown in the header, footer and receipts.',
                'settings.php#contact', 'Edit'),
            $item('social', 'Social media links', $social ? 'ok' : 'warn',
                $social ? plural_n(count($social), 'network') . ' linked.' : 'No social links yet - the icons stay hidden until you add them.',
                'settings.php#social-media', 'Add links'),
        ],
        'Email & payments' => [
            $item('smtp', 'Email sending (SMTP)', mail_configured() ? 'ok' : 'fail',
                mail_configured() ? 'SMTP is configured.' : 'Not configured: verification, password-reset, receipt and enquiry emails are not sent. Add the SMTP details in Keys & Credentials → Email (Hostinger: hPanel → Emails).',
                'integrations.php#smtp', 'Open'),
            $item('payments', 'Online payments (' . $gw['label'] . ')', !$gwKnown ? 'fail' : (payments_configured() ? ($live ? 'ok' : 'warn') : 'fail'),
                !$gwKnown ? 'PAYMENT_PROVIDER=' . payment_provider() . ' is not supported: choose Cashfree or Razorpay in Keys & Credentials → Payment gateway.'
                    : (payments_configured() ? ($live ? 'Live ' . $gw['label'] . ' keys configured.' : $gw['label'] . ' keys configured but PAYMENT_MODE is sandbox - real payments are not collected.')
                        : 'Not configured: paid courses cannot be bought. Add ' . implode(' / ', $gw['missing']) . ' in Keys & Credentials → Payment gateway.')
                    . ($gw['provider'] === 'cashfree' && $gw['api_override'] ? ' CASHFREE_API_BASE points to a test server (ignored when APP_ENV=production) - remove it.' : ''),
                'integrations.php?tab=payments', 'Open'),
            $gw['provider'] === 'cashfree'
                // Cashfree signs webhooks with the client secret: what matters is that the URL is saved in its dashboard.
                ? $item('webhook', 'Payment webhook (Cashfree)', !$gw['webhook_ready'] ? 'fail' : ($gwHookRecent ? 'ok' : 'warn'),
                    !$gw['webhook_ready'] ? 'Add the Cashfree keys first - webhooks are verified with CASHFREE_SECRET_KEY.'
                        : ($gwHookRecent ? 'Webhooks arrive and are verified (last ' . $ago($gw['last_webhook']['received_at']) . ').'
                            : 'No Cashfree webhook received in the last 30 days. Add ' . $gw['webhook_url'] . ' in Cashfree → Developers → Webhooks (Success, Failed and User Dropped Payment) so payments confirm even if a student closes the page.'),
                    'payments.php?tab=setup', 'Setup guide')
                : $item('webhook', 'Payment webhook secret', (string) config('RAZORPAY_WEBHOOK_SECRET', '') !== '' ? 'ok' : 'fail',
                    (string) config('RAZORPAY_WEBHOOK_SECRET', '') !== '' ? 'Webhook signatures are verified.' : 'Add RAZORPAY_WEBHOOK_SECRET and the webhook URL in the Razorpay dashboard so payments confirm even if a student closes the page.',
                    'payments.php?tab=setup', 'Setup guide'),
        ],
        'Operations' => [
            $item('db_updates', 'Database up to date', $pendingDb ? 'fail' : 'ok',
                $pendingDb ? plural_n($pendingDb, 'database update') . ' from the latest code ' . ($pendingDb === 1 ? 'is' : 'are') . " waiting - features that need them will fail until applied." : 'All database updates are applied.',
                'updates.php', 'Apply updates'),
            $item('cron', 'Scheduled jobs', $fresh($cron, 3600) ? 'ok' : ($fresh($sched, 7200) ? 'warn' : 'fail'),
                $fresh($cron, 3600) ? 'Server cron ran ' . $ago($cron['at']) . '.'
                    : ($fresh($sched, 7200) ? 'Running from website traffic (last ' . $ago($sched['at']) . '). Add a cron job so class reminders go out on time even when the site is quiet: every 15 minutes, php ' . APP_ROOT . '/database/cron.php'
                    : 'Jobs have not run recently (last ' . $ago($sched['at'] ?? null) . '). Add a cron job every 15 minutes: php ' . APP_ROOT . '/database/cron.php'),
                'help.php#troubleshooting', 'Help'),
            $item('backup', 'Recent database backup', $fresh($backup, 7 * 86400) ? 'ok' : 'warn',
                !empty($backup['at']) ? 'Last downloaded ' . $ago($backup['at']) . ' by ' . ($backup['by'] ?? 'staff') . '.' : 'No backup downloaded from the admin panel yet.',
                'backup.php', 'Back up now'),
            $item('search_console', 'Google Search Console', setting('gsc_verification') ? 'ok' : 'warn',
                setting('gsc_verification') ? 'Verification tag is on the site. Submit sitemap.xml in Search Console.' : 'Add the verification code so you can submit the sitemap and see search performance.',
                'integrations.php#search-console', 'Open'),
        ],
    ];

    $manual = json_decode_array(setting('launch_manual'));
    foreach (LAUNCH_MANUAL as $key => [$title, $how, $link]) {
        $done = $manual[$key] ?? null;
        $groups['Manual steps'][] = $item($key, $title, $done ? 'ok' : 'manual',
            $done ? 'Marked done by ' . ($done['by'] ?? 'staff') . ' on ' . fmt_dt($done['at'], 'j M Y') . '.' : $how, $link, $link ? 'Open' : null);
    }
    return $groups;
}

/** [done, total] across all items. */
function launch_progress(array $groups): array
{
    $all = array_merge(...array_values($groups));
    return [count(array_filter($all, fn($i) => $i['state'] === 'ok')), count($all)];
}
