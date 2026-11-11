-- ==================================================================
-- Migration 16: Email queue - stop re-sending emails that were probably already delivered
-- When the mail server received a whole message but its "accepted" reply was late, the email was logged as
-- failed and retried, so the same email (e.g. a new-enquiry alert) arrived again and again. The code now never
-- retries such messages automatically; this update stops the retries that were already scheduled.
-- Changes only email_log rows that are waiting for an automatic retry after the old code's exact "no reply from
-- server" error (connection failures, rejections and other errors keep retrying), or that have been stuck in
-- "sending" for over 15 minutes. Staff can still re-send any of them with "Retry now". The new email code applies
-- the first rule itself on every queue run, so duplicates stop as soon as the files are deployed.
-- Plain SQL for MySQL 8 and MariaDB; apply from admin -> Settings -> Database Updates (re-running is safe).
-- ==================================================================
SET NAMES utf8mb4;

UPDATE email_log
   SET next_attempt_at = NULL,
       transport = 'smtp-unconfirmed',
       last_error = 'No reply from the mail server - not retried automatically, because the mail server may already have delivered it. Use Retry now only if the recipient did not get it.'
 WHERE status = 'failed'
   AND next_attempt_at IS NOT NULL
   AND last_error = 'SMTP error: no reply from server';

UPDATE email_log
   SET status = 'failed',
       next_attempt_at = NULL,
       transport = 'smtp-unconfirmed',
       last_error = 'Sending was interrupted - not re-sent automatically, because the mail server may already have delivered it. Use Retry now only if the recipient did not get it.'
 WHERE status = 'sending'
   AND updated_at < NOW() - INTERVAL 15 MINUTE;

INSERT INTO schema_migrations (version, description) VALUES (16, 'Email queue: stop re-sending emails that were probably already delivered');
