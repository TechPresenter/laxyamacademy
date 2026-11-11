-- ==================================================================
-- Migration 005: features previously marked "Soon"
-- managed lists, question topics, student documents, notification templates,
-- page builder, popups, analytics/attribution, broken links, API keys, 2FA
-- ==================================================================
SET NAMES utf8mb4;

-- Managed option lists (departments, employment types, ticket categories, document types)
CREATE TABLE IF NOT EXISTS lookup_values (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_key   VARCHAR(40) NOT NULL,
    value      VARCHAR(60) NOT NULL,
    label      VARCHAR(120) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_lookup (list_key, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lookup_values (list_key, value, label, sort_order) VALUES
('employment_type', 'full_time', 'Full-time', 1), ('employment_type', 'part_time', 'Part-time', 2), ('employment_type', 'contract', 'Contract', 3),
('employment_type', 'internship', 'Internship', 4), ('employment_type', 'visiting', 'Visiting Faculty', 5),
('department', 'Academics', 'Academics', 1), ('department', 'Admissions', 'Admissions', 2), ('department', 'Operations', 'Operations', 3),
('ticket_category', 'general', 'General', 1), ('ticket_category', 'course', 'Course / content', 2), ('ticket_category', 'payment', 'Payment / refund', 3),
('ticket_category', 'technical', 'Technical issue', 4), ('ticket_category', 'test', 'Mock test', 5), ('ticket_category', 'account', 'Account', 6),
('document_type', 'id_proof', 'ID proof', 1), ('document_type', 'marksheet', 'Marksheet / certificate', 2), ('document_type', 'photo', 'Photograph', 3), ('document_type', 'other', 'Other', 9);

ALTER TABLE job_openings MODIFY employment_type VARCHAR(60) NOT NULL DEFAULT 'full_time';
ALTER TABLE support_tickets MODIFY category VARCHAR(60) NOT NULL DEFAULT 'general';

-- Question topics (question categories)
ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS topic VARCHAR(80) NULL AFTER question_type, ADD INDEX IF NOT EXISTS idx_eq_topic (topic);

-- Student documents (private files, staff verification)
CREATE TABLE IF NOT EXISTS student_documents (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    doc_type    VARCHAR(60) NOT NULL,
    title       VARCHAR(160) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    status      ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    note        VARCHAR(255) NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_docs_user (user_id),
    KEY idx_docs_status (status),
    CONSTRAINT fk_docs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_docs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Support canned responses
CREATE TABLE IF NOT EXISTS canned_responses (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(120) NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification templates ({name}, {course}, {amount}, {order}, {when}, {link} placeholders)
CREATE TABLE IF NOT EXISTS notification_templates (
    template_key VARCHAR(60) NOT NULL PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    subject      VARCHAR(200) NOT NULL,
    body         TEXT NOT NULL,
    send_email   TINYINT(1) NOT NULL DEFAULT 1,
    send_inapp   TINYINT(1) NOT NULL DEFAULT 1,
    send_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    is_system    TINYINT(1) NOT NULL DEFAULT 0,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO notification_templates (template_key, name, subject, body, send_email, send_inapp, is_system) VALUES
('payment_success', 'Payment successful', 'Payment received - {order}', 'Hello {name},\n\nWe have received your payment of {amount} for {course} (order {order}). Your course is now active.\n\nStart learning: {link}', 1, 1, 1),
('payment_failed', 'Payment failed', 'Payment not completed - {order}', 'Hello {name},\n\nYour payment for {course} (order {order}) was not completed. No access was granted. If money was deducted, your bank will reverse it.\n\nTry again: {link}', 1, 1, 1),
('refund_processed', 'Refund processed', 'Refund processed - {order}', 'Hello {name},\n\nA refund of {amount} for order {order} has been processed to your original payment method. Banks usually take 5-7 working days.', 1, 1, 1),
('enrolment', 'Course enrolment', 'You are enrolled in {course}', 'Hello {name},\n\nYou now have access to {course}.\n\nStart learning: {link}', 1, 1, 1),
('class_reminder', 'Live class reminder', 'Starting soon: {course}', 'Hello {name},\n\n{course} starts at {when} (IST). The join button appears 15 minutes before the class.\n\n{link}', 1, 1, 1),
('enquiry_autoreply', 'Enquiry auto-reply', 'We received your enquiry', 'Hello {name},\n\nThank you for contacting Laxyam Academy. Our counsellor will get back to you shortly. For urgent help call +91 87005 11018.', 1, 0, 1),
('application_ack', 'Job application received', 'Application received - {course}', 'Hello {name},\n\nThank you for applying for {course} at Laxyam Academy. Our team will review your application and contact you if your profile matches.', 1, 0, 1),
('ticket_reply', 'Support ticket reply', 'Reply on your ticket #{order}', 'Hello {name},\n\nOur support team replied to your ticket #{order}.\n\nView: {link}', 1, 1, 1);

-- Page builder
CREATE TABLE IF NOT EXISTS pages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(160) NOT NULL,
    title           VARCHAR(180) NOT NULL,
    blocks          JSON NOT NULL,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    seo_title       VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pages_slug (slug),
    CONSTRAINT fk_pages_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_revisions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(180) NOT NULL,
    blocks     JSON NOT NULL,
    saved_by   INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_prev_page (page_id, created_at),
    CONSTRAINT fk_prev_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_prev_user FOREIGN KEY (saved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reusable_sections (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    blocks     JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Popups
CREATE TABLE IF NOT EXISTS popups (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(160) NOT NULL,
    body          VARCHAR(600) NULL,
    image_path    VARCHAR(255) NULL,
    cta_label     VARCHAR(60) NULL,
    cta_url       VARCHAR(255) NULL,
    show_on       VARCHAR(255) NOT NULL DEFAULT '*' COMMENT '* = all pages, or comma-separated paths (/ , /courses*)',
    delay_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    frequency     ENUM('session','day','once') NOT NULL DEFAULT 'session',
    starts_at     DATETIME NULL,
    ends_at       DATETIME NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 0,
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    clicks        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First-party analytics (no IP or cookies stored; visitor hash rotates daily)
CREATE TABLE IF NOT EXISTS page_views (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day           DATE NOT NULL,
    path          VARCHAR(255) NOT NULL,
    referrer_host VARCHAR(120) NULL,
    utm_source    VARCHAR(80) NULL,
    utm_medium    VARCHAR(80) NULL,
    utm_campaign  VARCHAR(120) NULL,
    device        ENUM('mobile','tablet','desktop') NOT NULL DEFAULT 'desktop',
    visitor_hash  CHAR(64) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pv_day (day),
    KEY idx_pv_path (day, path),
    KEY idx_pv_visitor (day, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaign attribution for registrations, enquiries and orders
CREATE TABLE IF NOT EXISTS attributions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type   ENUM('user','enquiry','order') NOT NULL,
    entity_id     INT UNSIGNED NOT NULL,
    utm_source    VARCHAR(80) NULL,
    utm_medium    VARCHAR(80) NULL,
    utm_campaign  VARCHAR(120) NULL,
    referrer_host VARCHAR(120) NULL,
    landing_path  VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attr (entity_type, entity_id),
    KEY idx_attr_campaign (utm_source, utm_medium, utm_campaign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Broken link checker results
CREATE TABLE IF NOT EXISTS broken_links (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url         VARCHAR(500) NOT NULL,
    found_on    VARCHAR(255) NOT NULL,
    status_code SMALLINT NULL,
    error       VARCHAR(255) NULL,
    checked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bl_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexing status cache (Search Console URL Inspection)
CREATE TABLE IF NOT EXISTS indexing_status (
    url           VARCHAR(500) NOT NULL,
    url_hash      CHAR(64) NOT NULL PRIMARY KEY,
    verdict       VARCHAR(40) NULL,
    coverage      VARCHAR(160) NULL,
    last_crawl    DATETIME NULL,
    checked_at    DATETIME NOT NULL,
    error         VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys and access logs
CREATE TABLE IF NOT EXISTS api_keys (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    key_prefix   CHAR(12) NOT NULL,
    key_hash     CHAR(64) NOT NULL,
    scopes       JSON NOT NULL,
    created_by   INT UNSIGNED NULL,
    last_used_at DATETIME NULL,
    revoked_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_hash (key_hash),
    CONSTRAINT fk_api_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_access_logs (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT UNSIGNED NULL,
    method     VARCHAR(8) NOT NULL,
    endpoint   VARCHAR(120) NOT NULL,
    status     SMALLINT NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_apilog_key (api_key_id, created_at),
    CONSTRAINT fk_apilog_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-factor authentication (TOTP) for staff
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN IF NOT EXISTS recovery_codes JSON NULL AFTER totp_enabled_at;

-- Careers page content
INSERT IGNORE INTO cms_sections (section_key, title, content, sort_order) VALUES
('page_careers', 'Careers page', JSON_OBJECT(
    'title', 'We Are Hiring!',
    'intro', 'Join our growing team of educators and shape the future of competitive exam aspirants.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Why work with us', 'icon', 'star', 'body', 'Teach motivated aspirants, grow with a focused academy, and work with a supportive team.'),
        JSON_OBJECT('heading', 'How to apply', 'icon', 'file', 'body', 'Choose a role and submit the form with your resume. Shortlisted candidates are contacted for a demo class and interview.')
    )
), 28),
('nav_main', 'Main navigation menu', JSON_OBJECT('items', JSON_ARRAY(
    JSON_OBJECT('label', 'Home', 'url', '/', 'mega', false),
    JSON_OBJECT('label', 'Courses', 'url', '/courses', 'mega', true),
    JSON_OBJECT('label', 'Mock Tests', 'url', '/mock-tests', 'mega', false),
    JSON_OBJECT('label', 'Live Classes', 'url', '/live-classes', 'mega', false),
    JSON_OBJECT('label', 'Free Resources', 'url', '/resources', 'mega', false),
    JSON_OBJECT('label', 'Jobs', 'url', '/jobs', 'mega', false),
    JSON_OBJECT('label', 'About Us', 'url', '/about', 'mega', false),
    JSON_OBJECT('label', 'Contact Us', 'url', '/contact', 'mega', false)
)), 30),
('nav_footer', 'Footer quick links', JSON_OBJECT('items', JSON_ARRAY(
    JSON_OBJECT('label', 'About Us', 'url', '/about'), JSON_OBJECT('label', 'All Courses', 'url', '/courses'),
    JSON_OBJECT('label', 'Mock Tests', 'url', '/mock-tests'), JSON_OBJECT('label', 'Live Classes', 'url', '/live-classes'),
    JSON_OBJECT('label', 'Free Resources', 'url', '/resources'), JSON_OBJECT('label', 'Latest Govt Jobs', 'url', '/jobs'),
    JSON_OBJECT('label', 'Careers', 'url', '/careers'), JSON_OBJECT('label', 'Blog', 'url', '/blog'), JSON_OBJECT('label', 'Contact Us', 'url', '/contact')
)), 31),
('header', 'Header settings', JSON_OBJECT(
    'show_top_bar', true, 'show_helpline', true, 'show_search', true, 'show_social', true,
    'cta_label', '', 'cta_url', ''
), 32);

-- Settings defaults for the new features
INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
('live_join_window_minutes', '15', 'live', 1),
('live_reminder_lead_minutes', '60', 'live', 0),
('enquiry_autoreply', '1', 'enquiries', 0),
('enquiry_notify_email', '', 'enquiries', 0),
('enquiry_require_phone', '0', 'enquiries', 1),
('recruit_notify_email', '', 'recruitment', 0),
('recruit_ack_email', '1', 'recruitment', 0),
('recruit_general_applications', '1', 'recruitment', 1),
('support_default_assignee', '', 'support', 0),
('support_notify_email', '', 'support', 0),
('notify_default_prefs', '{"course_updates":true,"test_results":true,"offers":false,"announcements":true,"study_tips":false}', 'notifications', 0),
('payment_notify_email', '', 'notifications', 0),
('theme_primary', '#0b63f6', 'appearance', 1),
('theme_navy', '#08264d', 'appearance', 1),
('theme_gold', '#ffc928', 'appearance', 1),
('theme_font', 'system', 'appearance', 1),
('theme_radius', 'rounded', 'appearance', 1),
('logo_path', '', 'appearance', 1),
('favicon_path', '', 'appearance', 1),
('language_toggle', '0', 'appearance', 1),
('robots_extra', '', 'seo', 0),
('cache_enabled', '1', 'performance', 0),
('analytics_enabled', '1', 'analytics', 1),
('gsc_property', '', 'integrations', 0),
('admin_ip_allowlist', '', 'security', 0),
('admin_access_hours', '', 'security', 0),
('admin_require_2fa', '0', 'security', 0),
('recaptcha_site_key', '', 'integrations', 1),
('recaptcha_min_score', '0.5', 'integrations', 0),
('storage_driver', 'local', 'integrations', 0),
('whatsapp_enabled', '0', 'integrations', 0);

INSERT INTO schema_migrations (version, description) VALUES (5, 'Previously "Soon" features: lists, topics, documents, templates, page builder, popups, analytics, API keys, 2FA');
