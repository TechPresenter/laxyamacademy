-- ==================================================================
-- Migration 012: Sign in with Google and mobile OTP login
-- Students can sign in with their Google account, or with a one-time code sent to their mobile
-- by WhatsApp or SMS. OTP login works only for a number the student has confirmed by OTP.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN google_sub VARCHAR(64) NULL COMMENT 'Google account id linked for Sign in with Google' AFTER email_verified_at,
    ADD COLUMN phone_verified_at DATETIME NULL COMMENT 'mobile number confirmed by OTP (OTP login works only for verified numbers)' AFTER google_sub,
    ADD UNIQUE KEY uq_users_google (google_sub),
    ADD KEY idx_users_phone (phone);

CREATE TABLE IF NOT EXISTS login_otps (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone       VARCHAR(20) NOT NULL COMMENT 'E.164, e.g. +919876543210',
    purpose     ENUM('login','verify') NOT NULL DEFAULT 'login',
    user_id     INT UNSIGNED NULL COMMENT 'verify: the signed-in account adding this number',
    channel     ENUM('whatsapp','sms') NOT NULL,
    code_hash   CHAR(64) NOT NULL COMMENT 'HMAC of the code - the code itself is never stored',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_phone (phone, purpose, created_at),
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES (12, 'Sign in with Google and mobile OTP login');
