-- ==================================================================
-- Migration 014: Referral program (Refer & Earn)
-- Every student can share a referral code. It is a coupon (kind = referral) that gives a friend a
-- discount on the friend's first paid course. When that payment is confirmed, the student who shared
-- the code gets a reward coupon (kind = reward) that only they can use. Settings: admin → Referral Program.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

ALTER TABLE coupons
    ADD COLUMN kind ENUM('standard','referral','reward') NOT NULL DEFAULT 'standard' AFTER code,
    ADD COLUMN owner_id INT UNSIGNED NULL COMMENT 'referral: the student who shares it; reward: the only student who may use it' AFTER kind,
    ADD KEY idx_coupons_owner (owner_id, kind),
    ADD CONSTRAINT fk_coupons_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS referral_rewards (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referrer_id      INT UNSIGNED NOT NULL,
    referred_id      INT UNSIGNED NOT NULL,
    order_id         INT UNSIGNED NOT NULL,
    reward_coupon_id INT UNSIGNED NULL,
    status           ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rr_order (order_id),
    KEY idx_rr_referrer (referrer_id),
    CONSTRAINT fk_rr_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_coupon FOREIGN KEY (reward_coupon_id) REFERENCES coupons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO notification_templates (template_key, name, subject, body, send_email, send_inapp, send_whatsapp, is_system) VALUES
    ('referral_reward', 'Referral reward earned', 'You earned a reward: {amount} off your next course', 'Hello {name},\n\nYour friend joined {course} with your referral code. Thank you for sharing Laxyam Academy!\n\nYour reward: {amount} off your next course with the code {order} (valid until {when}).\n\nSee your rewards: {link}', 1, 1, 0, 1);

INSERT INTO schema_migrations (version, description) VALUES (14, 'Referral program: referral codes and reward coupons');
