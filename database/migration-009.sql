-- ==================================================================
-- Migration 009: Keys & credentials managed from the admin panel
-- Adds the app_config table: integration keys (OpenAI / Claude chatbot, Cashfree / Razorpay, SMTP, Interakt,
-- reCAPTCHA, S3) saved in admin → SEO & Integrations → Keys & Credentials. Values are AES-256-GCM encrypted by
-- the application (key in .env CONFIG_KEY or uploads/private/.config-key) and override the same names in .env.
-- Only adds a table - no existing data is changed or removed. Plain SQL for MySQL 8 and MariaDB;
-- apply from admin → Settings → Database Updates (re-running is safe).
-- ==================================================================
SET NAMES utf8mb4;

CREATE TABLE app_config (
    config_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    config_value TEXT         NOT NULL,
    updated_by   INT UNSIGNED NULL,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES (9, 'Keys & credentials managed from the admin panel');
