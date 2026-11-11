-- ==================================================================
-- Migration 007: Website chatbot (admission assistant)
-- conversations and messages from the chat widget, callback leads (stored as enquiries
-- with source "chatbot") and the chatbot settings shown in admin → Chatbot.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

-- One row per chat started on the website. `token` is the public id kept in the visitor's browser.
CREATE TABLE IF NOT EXISTS chat_conversations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token            CHAR(32) NOT NULL,
    user_id          INT UNSIGNED NULL,
    enquiry_id       INT UNSIGNED NULL COMMENT 'callback request created from this chat',
    page_path        VARCHAR(255) NULL COMMENT 'page where the chat started',
    ip_address       VARCHAR(45) NULL,
    user_agent       VARCHAR(255) NULL,
    message_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_token (token),
    KEY idx_chat_created (created_at),
    KEY idx_chat_enquiry (enquiry_id),
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_chat_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- engine: ai = answered by Claude, basic = built-in answers (no API key, daily limit reached or AI unavailable)
CREATE TABLE IF NOT EXISTS chat_messages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id  INT UNSIGNED NOT NULL,
    role             ENUM('user','assistant') NOT NULL,
    content          TEXT NOT NULL,
    engine           ENUM('ai','basic','system') NULL,
    input_tokens     INT UNSIGNED NULL,
    output_tokens    INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chatmsg_conv (conversation_id, id),
    KEY idx_chatmsg_engine (engine, created_at),
    CONSTRAINT fk_chatmsg_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
    ('chatbot_enabled', '1', 'chatbot', 1),
    ('chatbot_name', 'Laxyam Admission Assistant', 'chatbot', 1),
    ('chatbot_greeting', 'Ask me about courses, fees, mock tests, live classes or admission - in English or Hindi.', 'chatbot', 1),
    ('chatbot_teaser', 'Hi! Need help choosing the right course?', 'chatbot', 1),
    ('chatbot_ai_enabled', '1', 'chatbot', 0),
    ('chatbot_ai_daily_limit', '300', 'chatbot', 0),
    ('chatbot_knowledge', '', 'chatbot', 0);

INSERT INTO schema_migrations (version, description) VALUES (7, 'Website chatbot: conversations, messages, callback leads, settings');
