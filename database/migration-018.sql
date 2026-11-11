-- ==================================================================
-- Migration 18: Email OTP login
-- Students can sign in (or sign up) with a one-time code sent to their email address, as well as with
-- their password. Codes use the existing login_otps table: the identifier column now also holds an
-- email address and the channel can be 'email'. Only widens a column and an ENUM - no data is changed.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin -> Settings -> Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

ALTER TABLE login_otps
    MODIFY phone VARCHAR(190) NOT NULL COMMENT 'mobile number in E.164 (+919876543210), or the email address for email codes',
    MODIFY channel ENUM('whatsapp','sms','email') NOT NULL;

INSERT INTO schema_migrations (version, description) VALUES (18, 'Email OTP login');
