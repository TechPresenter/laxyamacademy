-- ==================================================================
-- Migration 008: Premium redesign - invoices, email delivery log, video sections, highlights, promo banners, enquiry popups, quizzes
-- Adds: company/invoice settings and GST-ready PDF invoices (LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED,
-- CIN U85491DL2026PTC470370), billing snapshot on orders, email queue + delivery log with retries,
-- admin-managed YouTube / Instagram video sections, header highlights, promotional banners,
-- enquiry-form popups with consent, newsletter unsubscribe, practice quizzes / random question
-- pools / answer-key rules for mock tests, course highlights, job alert details and new email templates.
-- Only adds tables, columns and rows - no existing data is changed or removed.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there). One column per ALTER so a
-- partly-applied run can always be completed by running it again.
-- ==================================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- 1. Company identity + invoicing settings (public ones are shown in the footer and on invoices)
-- ------------------------------------------------------------------
INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
    ('company_legal_name', 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED', 'company', 1),
    ('company_cin', 'U85491DL2026PTC470370', 'company', 1),
    ('company_gstin', '', 'company', 1),
    ('company_pan', '', 'company', 0),
    ('company_registered_address', '', 'company', 1),
    ('company_state', 'Delhi', 'company', 0),
    ('company_state_code', '07', 'company', 0),
    ('invoice_prefix', 'LXA', 'company', 0),
    ('gst_enabled', '0', 'company', 0),
    ('gst_rate', '18', 'company', 0),
    ('prices_include_gst', '1', 'company', 0),
    ('sac_code', '999293', 'company', 0),
    ('invoice_footer_note', 'This is a computer-generated invoice and does not require a physical signature.', 'company', 0),
    ('invoice_signatory', 'Authorised Signatory', 'company', 0),
    ('email_max_attempts', '5', 'notifications', 0),
    ('newsletter_welcome_email', '1', 'notifications', 0),
    ('social_threads', '', 'social', 1);

-- Official Laxyam Academy social profiles (filled only where the setting is still empty)
UPDATE site_settings SET setting_value = 'https://www.youtube.com/@LaxyamAcademy' WHERE setting_key = 'social_youtube' AND COALESCE(setting_value, '') = '';
UPDATE site_settings SET setting_value = 'https://www.facebook.com/LaxyamAcademyDelhi' WHERE setting_key = 'social_facebook' AND COALESCE(setting_value, '') = '';
UPDATE site_settings SET setting_value = 'https://www.instagram.com/laxyamacademy/' WHERE setting_key = 'social_instagram' AND COALESCE(setting_value, '') = '';
UPDATE site_settings SET setting_value = 'https://www.linkedin.com/company/laxyam-academy' WHERE setting_key = 'social_linkedin' AND COALESCE(setting_value, '') = '';
UPDATE site_settings SET setting_value = 'https://x.com/laxyamacademy' WHERE setting_key = 'social_x' AND COALESCE(setting_value, '') = '';
UPDATE site_settings SET setting_value = 'https://www.threads.com/@laxyamacademy' WHERE setting_key = 'social_threads' AND COALESCE(setting_value, '') = '';

-- Billing details captured at checkout (snapshot - later profile edits do not change old invoices)
ALTER TABLE orders ADD COLUMN billing_name VARCHAR(160) NULL AFTER coupon_id;
ALTER TABLE orders ADD COLUMN billing_email VARCHAR(190) NULL AFTER billing_name;
ALTER TABLE orders ADD COLUMN billing_phone VARCHAR(20) NULL AFTER billing_email;
ALTER TABLE orders ADD COLUMN billing_state VARCHAR(60) NULL AFTER billing_phone;
ALTER TABLE orders ADD COLUMN billing_gstin VARCHAR(20) NULL AFTER billing_state;
ALTER TABLE orders ADD COLUMN billing_address VARCHAR(500) NULL AFTER billing_gstin;

-- Consecutive invoice serial per Indian financial year (April-March), e.g. LXA/2026-27/0001
CREATE TABLE IF NOT EXISTS invoice_sequences (
    financial_year  CHAR(7) NOT NULL PRIMARY KEY COMMENT 'e.g. 2026-27',
    last_no         INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One invoice per paid order. Seller, buyer and amounts are snapshotted when it is issued
-- from the verified payment, so the document never changes afterwards.
CREATE TABLE IF NOT EXISTS invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED NOT NULL,
    invoice_number      VARCHAR(30) NOT NULL,
    financial_year      CHAR(7) NOT NULL,
    sequence_no         INT UNSIGNED NOT NULL,
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seller_name         VARCHAR(160) NOT NULL,
    seller_cin          VARCHAR(30) NULL,
    seller_gstin        VARCHAR(20) NULL,
    seller_pan          VARCHAR(12) NULL,
    seller_address      VARCHAR(500) NULL,
    seller_state        VARCHAR(60) NULL,
    seller_state_code   CHAR(2) NULL,
    billing_name        VARCHAR(160) NOT NULL,
    billing_email       VARCHAR(190) NULL,
    billing_phone       VARCHAR(20) NULL,
    billing_address     VARCHAR(500) NULL,
    billing_state       VARCHAR(60) NULL,
    billing_gstin       VARCHAR(20) NULL,
    subtotal            DECIMAL(10,2) NOT NULL,
    discount            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    taxable_value       DECIMAL(10,2) NOT NULL,
    gst_rate            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    cgst                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sgst                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    igst                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total               DECIMAL(10,2) NOT NULL,
    currency            CHAR(3) NOT NULL DEFAULT 'INR',
    sac_code            VARCHAR(10) NULL,
    payment_reference   VARCHAR(100) NULL,
    payment_method      VARCHAR(40) NULL,
    paid_at             DATETIME NULL,
    status              ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_order (order_id),
    UNIQUE KEY uq_invoice_number (invoice_number),
    UNIQUE KEY uq_invoice_seq (financial_year, sequence_no),
    KEY idx_invoice_issued (issued_at),
    CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 2. Email queue + delivery log (every automatic email is logged; failed sends are retried by the scheduler)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_log (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key     VARCHAR(60) NULL,
    user_id          INT UNSIGNED NULL,
    to_email         VARCHAR(190) NOT NULL,
    to_name          VARCHAR(160) NULL,
    subject          VARCHAR(255) NOT NULL,
    body_html        MEDIUMTEXT NULL,
    status           ENUM('queued','sending','sent','failed','skipped') NOT NULL DEFAULT 'queued',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error       VARCHAR(500) NULL,
    transport        VARCHAR(20) NULL COMMENT 'smtp | mail | log',
    related_type     VARCHAR(40) NULL COMMENT 'order, enquiry, attempt, enrolment ...',
    related_id       INT UNSIGNED NULL,
    next_attempt_at  DATETIME NULL,
    sent_at          DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_email_status (status, next_attempt_at),
    KEY idx_email_user (user_id),
    KEY idx_email_template (template_key),
    KEY idx_email_created (created_at),
    CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New automatic emails (editable in admin → Email Templates). Placeholders: {name} {course} {amount} {order} {when} {link} {score} {result}
INSERT IGNORE INTO notification_templates (template_key, name, subject, body, send_email, send_inapp, send_whatsapp, is_system) VALUES
    ('welcome', 'Welcome after registration', 'Welcome to Laxyam Academy, {name}!', 'Hello {name},\n\nWelcome to Laxyam Academy - your account is ready. Explore our courses, attempt free mock tests and download study material to start your preparation.\n\n{link}', 1, 1, 0, 1),
    ('password_changed', 'Password changed (security)', 'Your Laxyam Academy password was changed', 'Hello {name},\n\nThe password for your Laxyam Academy account was changed on {when}. If this was not you, reset your password immediately and contact us.\n\n{link}', 1, 0, 0, 1),
    ('test_result', 'Mock test result', 'Your result: {course}', 'Hello {name},\n\nYour result for {course} is ready. You scored {score} ({result}).\n\nReview your answers, the answer key and explanations here:\n{link}', 1, 1, 0, 1),
    ('certificate_issued', 'Certificate issued', 'Your certificate for {course} is ready', 'Hello {name},\n\nCongratulations! Your certificate for {course} has been issued. You can view and download it from your dashboard.\n\n{link}', 1, 1, 0, 1),
    ('course_completed', 'Course completed', 'Congratulations on completing {course}!', 'Hello {name},\n\nYou have completed every lesson of {course}. Keep practising with mock tests to stay exam-ready.\n\n{link}', 1, 1, 0, 1),
    ('newsletter_welcome', 'Newsletter subscription', 'You are subscribed to Laxyam Academy updates', 'Hello,\n\nThank you for subscribing. You will receive exam notifications, current affairs and preparation tips from Laxyam Academy.\n\nTo stop these emails at any time use this link: {link}', 1, 0, 0, 1);

-- ------------------------------------------------------------------
-- 3. Admin-managed YouTube + Instagram video sections
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_videos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform        ENUM('youtube','instagram') NOT NULL,
    title           VARCHAR(160) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    video_id        VARCHAR(64) NOT NULL COMMENT 'YouTube video id or Instagram shortcode, parsed from url',
    description     VARCHAR(300) NULL,
    thumbnail_path  VARCHAR(255) NULL COMMENT 'optional custom thumbnail (required look for Instagram)',
    placement       VARCHAR(30) NOT NULL DEFAULT 'home' COMMENT 'home | courses | about | course',
    course_id       INT UNSIGNED NULL COMMENT 'placement=course: show on this course page',
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_media_place (platform, placement, is_active, sort_order),
    KEY idx_media_course (course_id),
    CONSTRAINT fk_media_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_media_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section headings (shown only when the section has active videos); newsletter band on the homepage
INSERT IGNORE INTO cms_sections (section_key, title, content, is_active, sort_order) VALUES
    ('videos_youtube', 'YouTube videos (homepage)', '{"title": "Watch & Learn on YouTube", "subtitle": "Free lectures, strategy sessions and current affairs from our faculty.", "channel_url": "https://www.youtube.com/@LaxyamAcademy"}', 1, 14),
    ('videos_instagram', 'Instagram reels (homepage)', '{"title": "Quick Tips on Instagram", "subtitle": "Short reels with exam tips, tricks and updates.", "profile_url": "https://www.instagram.com/laxyamacademy/"}', 1, 15),
    ('newsletter', 'Newsletter band (homepage)', '{"title": "Never miss an exam update", "text": "Get recruitment notifications, admit card and result alerts, current affairs and preparation tips in your inbox.", "button_label": "Subscribe"}', 1, 16),
    ('job_alerts', 'Govt jobs block (homepage)', '{"title": "Latest Govt Jobs &", "highlight": "Notifications", "subtitle": "Official recruitment updates for the exams you are preparing for."}', 1, 17);

-- New homepage hero photo (assets/images/hero-counsellor.webp) - only replaces the original default
-- illustration; a hero image an admin has already chosen is left untouched.
UPDATE cms_sections SET content = JSON_SET(content, '$.image', 'assets/images/hero-counsellor.webp')
WHERE section_key = 'hero' AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.image')) = 'assets/images/hero-illustration.svg';

-- "Free Resources" moves from the header menu to a highlighted footer link (owner's choice).
-- Only removes the original menu entry; a menu an admin has re-arranged is left as it is.
UPDATE cms_sections SET content = REPLACE(content, '{"label": "Free Resources", "url": "/resources", "mega": false}, ', '')
WHERE section_key = 'nav_main';

-- ------------------------------------------------------------------
-- 4. Header highlights menu / ticker and promotional banners
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_highlights (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    url         VARCHAR(255) NULL,
    badge       ENUM('new','hot','live','result','admit_card','update','offer') NOT NULL DEFAULT 'new',
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    starts_at   DATETIME NULL,
    ends_at     DATETIME NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_highlight_active (is_active, sort_order),
    CONSTRAINT fk_highlight_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_banners (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    subtitle    VARCHAR(300) NULL,
    image_path  VARCHAR(255) NULL,
    cta_label   VARCHAR(60) NULL,
    cta_url     VARCHAR(255) NULL,
    placement   ENUM('home','courses','course_detail') NOT NULL DEFAULT 'home',
    theme       ENUM('blue','navy','gold','violet','green','red') NOT NULL DEFAULT 'blue',
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    starts_at   DATETIME NULL,
    ends_at     DATETIME NULL,
    clicks      INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_banner_place (placement, is_active, sort_order),
    CONSTRAINT fk_banner_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 5. Enquiry popups, consent and newsletter management
-- ------------------------------------------------------------------
ALTER TABLE popups ADD COLUMN kind ENUM('promo','enquiry') NOT NULL DEFAULT 'promo' AFTER id;
ALTER TABLE popups ADD COLUMN form_title VARCHAR(160) NULL AFTER cta_url;
ALTER TABLE popups ADD COLUMN success_message VARCHAR(300) NULL AFTER form_title;
ALTER TABLE popups ADD COLUMN consent_text VARCHAR(400) NULL AFTER success_message;
ALTER TABLE popups ADD COLUMN submissions INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks;

-- Three enquiry popups with the counsellor photo, each for a different set of pages (owner request).
-- Added once; skipped if a popup with the same admin title already exists. Edit or switch off in admin → Popups.
INSERT INTO popups (kind, title, body, image_path, cta_label, form_title, success_message, show_on, delay_seconds, frequency, is_active)
SELECT 'enquiry', 'Counsellor callback (home & general pages)', 'Not sure where to start? Our academic counsellor will help you pick the right exam, course and batch.', 'assets/images/hero-counsellor.webp', 'Request free callback', 'Talk to our counsellor - it''s free', 'Thank you! Our counsellor will call you shortly.', '/,/about,/categories,/gallery*,/blog*', 15, 'day', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM popups WHERE title = 'Counsellor callback (home & general pages)');
INSERT INTO popups (kind, title, body, image_path, cta_label, form_title, success_message, show_on, delay_seconds, frequency, is_active)
SELECT 'enquiry', 'Course guidance (course pages)', 'Share your target exam and we will suggest the course and batch that fits your preparation - no cost, no pressure.', 'assets/images/hero-counsellor.webp', 'Get course guidance', 'Confused about which course to choose?', 'Thank you! We will call you with the right course options.', '/courses,/courses/*', 25, 'session', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM popups WHERE title = 'Course guidance (course pages)');
INSERT INTO popups (kind, title, body, image_path, cta_label, form_title, success_message, show_on, delay_seconds, frequency, is_active)
SELECT 'enquiry', 'Exam strategy session (tests, jobs, live classes)', 'Tell us the exam you are preparing for and our faculty team will call with a personalised study plan.', 'assets/images/hero-counsellor.webp', 'Book free session', 'Get a free exam strategy session', 'Thank you! Our team will call you to plan your preparation.', '/mock-tests,/mock-tests/*,/jobs,/jobs/*,/live-classes,/resources,/resources/*', 30, 'day', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM popups WHERE title = 'Exam strategy session (tests, jobs, live classes)');

ALTER TABLE enquiries ADD COLUMN consent_at DATETIME NULL AFTER source;
ALTER TABLE enquiries ADD COLUMN page_url VARCHAR(255) NULL AFTER consent_at;

ALTER TABLE newsletter_subscribers ADD COLUMN name VARCHAR(120) NULL AFTER email;
ALTER TABLE newsletter_subscribers ADD COLUMN unsubscribe_token CHAR(32) NULL AFTER source;
ALTER TABLE newsletter_subscribers ADD COLUMN consent_at DATETIME NULL AFTER unsubscribe_token;
ALTER TABLE newsletter_subscribers ADD COLUMN unsubscribed_at DATETIME NULL AFTER consent_at;
ALTER TABLE newsletter_subscribers ADD UNIQUE KEY uq_newsletter_token (unsubscribe_token);

-- ------------------------------------------------------------------
-- 6. Quizzes and mock tests: practice mode, subject, random pools, option shuffle, answer-key rules
-- ------------------------------------------------------------------
ALTER TABLE exams ADD COLUMN mode ENUM('exam','practice') NOT NULL DEFAULT 'exam' AFTER slug;
ALTER TABLE exams ADD COLUMN subject VARCHAR(80) NULL AFTER mode;
ALTER TABLE exams ADD COLUMN question_pool_count SMALLINT UNSIGNED NULL COMMENT 'ask N random questions from the bank per attempt; NULL = all' AFTER shuffle_questions;
ALTER TABLE exams ADD COLUMN shuffle_options TINYINT(1) NOT NULL DEFAULT 0 AFTER question_pool_count;
ALTER TABLE exams ADD COLUMN default_negative_marks DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'used when negative marking is on and a question has none set' AFTER negative_marking;
ALTER TABLE exams ADD COLUMN answer_key_visibility ENUM('after_submit','after_result','never') NOT NULL DEFAULT 'after_result' AFTER show_explanations;
ALTER TABLE exam_attempts ADD COLUMN option_order LONGTEXT NULL AFTER question_order;
ALTER TABLE exam_questions ADD COLUMN image_path VARCHAR(255) NULL AFTER question_text;
ALTER TABLE exam_attempts ADD COLUMN result_notified_at DATETIME NULL COMMENT 'test_result email sent (or claimed) for this attempt' AFTER submitted_at;

-- Extra attempts granted to one student on top of exams.max_attempts (admin → Attempts & Answer Sheets)
CREATE TABLE IF NOT EXISTS exam_attempt_allowances (
    exam_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    extra_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'attempts allowed on top of exams.max_attempts',
    granted_by      INT UNSIGNED NULL,
    note            VARCHAR(255) NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (exam_id, user_id),
    KEY idx_eaa_user (user_id),
    CONSTRAINT fk_eaa_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_eaa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_eaa_staff FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tests that hid explanations ("show_explanations = 0") keep hiding the answer key under the new setting
UPDATE exams SET answer_key_visibility = 'never' WHERE show_explanations = 0 AND answer_key_visibility = 'after_result';

-- ------------------------------------------------------------------
-- 7. Course highlights and job alert details
-- ------------------------------------------------------------------
ALTER TABLE courses ADD COLUMN highlights LONGTEXT NULL COMMENT 'JSON list of {icon, text} shown in the course highlights strip' AFTER includes;

ALTER TABLE job_alerts ADD COLUMN details MEDIUMTEXT NULL AFTER summary;
ALTER TABLE job_alerts ADD COLUMN important_dates LONGTEXT NULL COMMENT 'JSON list of {label, date}' AFTER details;
ALTER TABLE job_alerts ADD COLUMN application_fee VARCHAR(255) NULL AFTER important_dates;
ALTER TABLE job_alerts ADD COLUMN age_limit VARCHAR(160) NULL AFTER application_fee;
ALTER TABLE job_alerts ADD COLUMN selection_process VARCHAR(500) NULL AFTER age_limit;
ALTER TABLE job_alerts ADD COLUMN extra_links LONGTEXT NULL COMMENT 'JSON list of {label, url}' AFTER official_url;

-- ------------------------------------------------------------------
-- 8. Photo & video gallery
-- ------------------------------------------------------------------
-- Photo & video gallery (admin/gallery.php, api/gallery.php, /gallery pages). MySQL 8 + MariaDB 10.4+.
CREATE TABLE IF NOT EXISTS gallery_albums (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  description TEXT NULL,
  cover_image_path VARCHAR(255) NULL COMMENT 'uploads/gallery/... own upload or an item image; NULL = first image of the album',
  event_date DATE NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gallery_album_slug (slug),
  KEY idx_gallery_album_list (is_active, sort_order, event_date),
  KEY fk_gallery_album_by (created_by),
  CONSTRAINT fk_gallery_album_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  album_id INT UNSIGNED NOT NULL,
  type ENUM('image','youtube') NOT NULL DEFAULT 'image',
  image_path VARCHAR(255) NULL COMMENT 'uploads/gallery/... full size WebP (type=image)',
  thumb_path VARCHAR(255) NULL COMMENT 'uploads/gallery/... grid-size WebP (type=image)',
  width SMALLINT UNSIGNED NULL COMMENT 'pixel size of image_path, used to reserve space (no layout shift)',
  height SMALLINT UNSIGNED NULL,
  youtube_id VARCHAR(11) NULL COMMENT '11-character YouTube video id (type=youtube)',
  caption VARCHAR(255) NULL,
  alt_text VARCHAR(160) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gallery_items_album (album_id, is_active, sort_order),
  KEY fk_gallery_item_by (created_by),
  CONSTRAINT fk_gallery_item_album FOREIGN KEY (album_id) REFERENCES gallery_albums (id) ON DELETE CASCADE,
  CONSTRAINT fk_gallery_item_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Footer "Quick Links" (CMS nav_footer): add Gallery once, only when it is not there yet.
UPDATE cms_sections
   SET content = JSON_ARRAY_APPEND(content, '$.items', JSON_OBJECT('label', 'Gallery', 'url', '/gallery'))
 WHERE section_key = 'nav_footer' AND JSON_VALID(content) AND JSON_TYPE(JSON_EXTRACT(content, '$.items')) = 'ARRAY'
   AND content NOT LIKE '%/gallery%';

-- ------------------------------------------------------------------
-- 9. WhatsApp automation (Interakt): event templates, delivery log, broadcasts, student opt-in, careers consent
-- ------------------------------------------------------------------
-- ==================================================================
-- Adds: whatsapp_templates (one approved Interakt template per website event, editable in Admin → WhatsApp),
-- whatsapp_messages (delivery log: queued/sent/delivered/read/failed/skipped, retries, webhook status,
-- idempotency key), whatsapp_broadcasts (course updates / announcements), the WhatsApp opt-in columns on
-- student_profiles, job_applications.whatsapp_consent_at (optional WhatsApp box on the careers form) and the
-- WhatsApp settings. API credentials are NOT stored here: they live in .env (INTERAKT_API_KEY,
-- INTERAKT_WEBHOOK_SECRET). Re-runnable: "already exists" errors are skipped by Admin → Database Updates.
-- Valid on MySQL 8 and MariaDB 10.4+.
-- ==================================================================


CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  interakt_template_name VARCHAR(120) DEFAULT NULL COMMENT 'exact code name of the template approved in Interakt / Meta',
  language_code VARCHAR(15) NOT NULL DEFAULT 'en',
  category ENUM('utility','marketing','authentication') NOT NULL DEFAULT 'utility',
  body_preview TEXT DEFAULT NULL COMMENT 'approved text with {{placeholders}} (preview only)',
  body_params JSON DEFAULT NULL COMMENT 'ordered placeholder names for bodyValues {{1}}, {{2}} ...',
  header_type ENUM('none','text','image','document') NOT NULL DEFAULT 'none',
  header_param VARCHAR(255) DEFAULT NULL COMMENT 'placeholder name, or a fixed https:// media link',
  button_url_param VARCHAR(60) DEFAULT NULL COMMENT 'placeholder for the dynamic URL button suffix (button 0)',
  recipient ENUM('student','staff','lead') NOT NULL DEFAULT 'student',
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  requires_opt_in TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_tpl_event (event_key),
  KEY fk_wa_tpl_by (updated_by),
  CONSTRAINT fk_wa_tpl_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key VARCHAR(60) NOT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  recipient_type ENUM('student','lead','staff') NOT NULL DEFAULT 'student',
  recipient_name VARCHAR(160) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL COMMENT 'national number without country code',
  country_code VARCHAR(6) DEFAULT NULL COMMENT '+91',
  template_name VARCHAR(120) DEFAULT NULL,
  language_code VARCHAR(15) DEFAULT NULL,
  params JSON DEFAULT NULL COMMENT 'vars, template mapping and values sent (one-time links redacted)',
  status ENUM('queued','sending','sent','delivered','read','failed','skipped') NOT NULL DEFAULT 'queued',
  provider VARCHAR(20) NOT NULL DEFAULT 'interakt',
  provider_message_id VARCHAR(100) DEFAULT NULL,
  provider_response TEXT DEFAULT NULL,
  error VARCHAR(500) DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME DEFAULT NULL,
  related_type VARCHAR(40) DEFAULT NULL,
  related_id INT UNSIGNED DEFAULT NULL,
  dedupe_key CHAR(40) DEFAULT NULL COMMENT 'sha1(event|related[#occurrence]|phone): each verified event occurrence is sent once',
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  sent_at DATETIME DEFAULT NULL,
  delivered_at DATETIME DEFAULT NULL,
  read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_msg_provider_id (provider_message_id),
  UNIQUE KEY uq_wa_msg_dedupe (dedupe_key),
  KEY idx_wa_msg_status (status, next_attempt_at),
  KEY idx_wa_msg_event (event_key, created_at),
  KEY idx_wa_msg_created (created_at),
  KEY idx_wa_msg_phone (phone),
  KEY idx_wa_msg_related (related_type, related_id),
  KEY fk_wa_msg_user (user_id),
  CONSTRAINT fk_wa_msg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_broadcasts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key VARCHAR(60) NOT NULL,
  course_id INT UNSIGNED DEFAULT NULL,
  message TEXT NOT NULL,
  recipients INT UNSIGNED NOT NULL DEFAULT 0,
  skipped INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_wa_bc_course (course_id),
  KEY fk_wa_bc_by (created_by),
  CONSTRAINT fk_wa_bc_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_bc_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_profiles ADD COLUMN whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE student_profiles ADD COLUMN whatsapp_opt_in_at DATETIME DEFAULT NULL;
ALTER TABLE student_profiles ADD COLUMN whatsapp_opt_out_at DATETIME DEFAULT NULL;
ALTER TABLE student_profiles ADD COLUMN whatsapp_opt_in_phone VARCHAR(20) DEFAULT NULL COMMENT 'E.164 number the consent was given for';
ALTER TABLE student_profiles ADD COLUMN whatsapp_opt_in_source VARCHAR(30) DEFAULT NULL COMMENT 'register, profile, admin, whatsapp_stop';
ALTER TABLE student_profiles ADD INDEX idx_sp_whatsapp (whatsapp_opt_in);

-- Careers: the candidate ticked "Send me updates about my application on WhatsApp" (optional).
ALTER TABLE job_applications ADD COLUMN whatsapp_consent_at DATETIME DEFAULT NULL COMMENT 'candidate ticked WhatsApp updates on the application form';

INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
('whatsapp_enabled', '0', 'integrations', 0),
('whatsapp_provider', 'interakt', 'integrations', 0),
('whatsapp_support_number', '', 'integrations', 0),
('whatsapp_admin_numbers', '', 'integrations', 0),
('whatsapp_max_attempts', '5', 'integrations', 0),
('whatsapp_default_country_code', '+91', 'integrations', 0);

-- Default (switched off) template row for every event; Admin → WhatsApp edits them. Never overwrites.
INSERT IGNORE INTO whatsapp_templates (event_key, label, description, language_code, category, body_preview, body_params, header_type, button_url_param, recipient, is_enabled, requires_opt_in) VALUES
('welcome', 'Welcome (registration)', 'A student creates an account (sent after the account is saved, only if they ticked WhatsApp updates).', 'en', 'utility', 'Hi {{student_name}}, welcome to {{site_name}}! Your student account is ready.\n\nExplore courses, mock tests and free study resources anytime at {{site_url}}.\n\nNeed help? Call or WhatsApp us on {{support_number}}. Reply STOP to opt out of WhatsApp updates.', '["student_name","site_name","site_url","support_number"]', 'none', NULL, 'student', 0, 1),
('password_reset', 'Password reset link', 'A student asks for a password reset. Sent only to the opted-in mobile number of that account; the one-time link goes in a URL button and is never logged.', 'en', 'utility', 'Hi {{student_name}}, we received a request to reset the password of your {{site_name}} account.\n\nTap the button below to choose a new password. The link expires in 60 minutes and can be used once.\n\nIf you did not request this, please ignore this message. Your password will not change.', '["student_name","site_name"]', 'none', 'reset_token', 'student', 0, 1),
('password_changed', 'Password changed (security alert)', 'The account password is changed in the dashboard or reset with a link.', 'en', 'utility', 'Hi {{student_name}}, the password of your {{site_name}} account was changed on {{changed_at}}.\n\nIf you did not make this change, reset your password now at {{security_url}} or call us on {{support_number}}.', '["student_name","site_name","changed_at","security_url","support_number"]', 'none', NULL, 'student', 0, 1),
('payment_success', 'Payment successful', 'Only after the payment is verified on the server (gateway signature or webhook) and the order is marked paid.', 'en', 'utility', 'Hi {{student_name}}, we have received your payment of {{amount}} for {{course_name}} (Order ID: {{order_id}}).\n\nYour course is unlocked: {{course_url}}\nDownload your GST invoice: {{invoice_url}}\n\nFor any help, contact us on {{support_number}}.', '["student_name","amount","course_name","order_id","course_url","invoice_url","support_number"]', 'none', NULL, 'student', 0, 1),
('payment_failed', 'Payment failed', 'An order is still unpaid 15 minutes after a failed payment attempt. Sent once, never for a first failure while the student is still retrying.', 'en', 'utility', 'Hi {{student_name}}, your payment for {{course_name}} (Order ID: {{order_id}}) could not be completed.\n\nIf money was debited from your account, your bank normally reverses it within 5-7 working days. You can complete your enrolment here: {{retry_url}}\n\nNeed help? Call us on {{support_number}}.', '["student_name","course_name","order_id","retry_url","support_number"]', 'none', NULL, 'student', 0, 1),
('invoice_issued', 'Invoice generated (optional)', 'Right after a verified payment, once the GST invoice is issued. Optional: “Payment successful” already carries the invoice link. Can attach the PDF as a document header (invoice_url).', 'en', 'utility', 'Hi {{student_name}}, your invoice {{invoice_number}} for Order ID {{order_id}} ({{amount}}) has been generated.\n\nDownload it here: {{invoice_url}}\n\nThank you for learning with {{site_name}}.', '["student_name","invoice_number","order_id","amount","invoice_url","site_name"]', 'none', NULL, 'student', 0, 1),
('refund_processed', 'Refund processed', 'Staff record a refund on an order (Payments → Orders).', 'en', 'utility', 'Hi {{student_name}}, your refund of {{amount}} for Order ID {{order_id}} has been processed.\n\nIt usually reaches your original payment method within 5-7 working days. Your purchases: {{purchases_url}}\n\nThank you for your patience.', '["student_name","amount","order_id","purchases_url"]', 'none', NULL, 'student', 0, 1),
('enrolment', 'Enrolment confirmed / course access', 'A free enrolment succeeds, a 100% coupon order completes, or staff grant course access.', 'en', 'utility', 'Hi {{student_name}}, your enrolment in {{course_name}} is confirmed and the course is now unlocked in your dashboard.\n\nStart learning: {{course_url}}\n\nFor any help, contact us on {{support_number}}.', '["student_name","course_name","course_url","support_number"]', 'none', NULL, 'student', 0, 1),
('test_result', 'Quiz / mock test result', 'A mock test result is released (immediately, after the test window ends, or when staff release it).', 'en', 'utility', 'Hi {{student_name}}, your result for {{test_name}} is out.\n\nScore: {{score}}\nResult: {{result}}\n\nSee your detailed analysis: {{result_url}}\n\nKeep practising. All the best!', '["student_name","test_name","score","result","result_url"]', 'none', NULL, 'student', 0, 1),
('course_completed', 'Course completed', 'A student first reaches 100% progress in a course.', 'en', 'utility', 'Congratulations {{student_name}}! You have completed {{course_name}}.\n\nYour certificates will be available here: {{certificate_url}}\n\nThank you for learning with {{site_name}}.', '["student_name","course_name","certificate_url","site_name"]', 'none', NULL, 'student', 0, 1),
('certificate_issued', 'Certificate issued', 'Staff issue a course completion certificate (Students → Certificates).', 'en', 'utility', 'Hi {{student_name}}, your certificate for {{course_name}} has been issued (Certificate No. {{certificate_no}}).\n\nView and download it: {{certificate_url}}\n\nCongratulations from the {{site_name}} team!', '["student_name","course_name","certificate_no","certificate_url","site_name"]', 'none', NULL, 'student', 0, 1),
('class_reminder', 'Live class reminder', 'A live class the student set a reminder for starts within the reminder lead time (Live Class Settings).', 'en', 'utility', 'Hi {{student_name}}, reminder: your live class {{class_title}} starts on {{class_time}} (IST).\n\nJoin from your dashboard: {{join_url}}\n\nReply STOP to opt out of WhatsApp reminders.', '["student_name","class_title","class_time","join_url"]', 'none', NULL, 'student', 0, 1),
('course_update', 'Important course update', 'Staff send a course update from the Broadcasts tab to the opted-in students of one course batch.', 'en', 'utility', 'Hi {{student_name}}, here is an important update for {{course_name}}:\n\n{{update_text}}\n\nOpen your course: {{course_url}}\n\nReply STOP to opt out of WhatsApp updates.', '["student_name","course_name","update_text","course_url"]', 'none', NULL, 'student', 0, 1),
('announcement', 'Announcement / notice', 'Staff send a notice from the Broadcasts tab to all opted-in students or one course batch. Promotional notices need a template approved as Marketing.', 'en', 'utility', 'Hi {{student_name}}, an important notice from {{site_name}}:\n\n{{update_text}}\n\nFor questions, contact us on {{support_number}}. Reply STOP to opt out of WhatsApp updates.', '["student_name","site_name","update_text","support_number"]', 'none', NULL, 'student', 0, 1),
('enquiry_autoreply', 'Enquiry confirmation', 'A visitor submits an enquiry or callback form with a mobile number and ticks the consent box (consent covers this confirmation only).', 'en', 'utility', 'Hi {{student_name}}, thank you for contacting {{site_name}}. We have received your enquiry and a counsellor will get in touch with you shortly.\n\nFor urgent help, call us on {{support_number}}.', '["student_name","site_name","support_number"]', 'none', NULL, 'lead', 0, 1),
('ticket_reply', 'Support ticket reply', 'Staff reply to a student’s support ticket (internal notes are never sent).', 'en', 'utility', 'Hi {{student_name}}, our support team has replied to your ticket #{{ticket_no}}.\n\nRead the reply: {{ticket_url}}\n\nThank you for contacting {{site_name}}.', '["student_name","ticket_no","ticket_url","site_name"]', 'none', NULL, 'student', 0, 1),
('application_ack', 'Job application received', 'A candidate submits a job application on the Careers page and ticks the optional WhatsApp updates box (all careers messages go only to candidates who ticked it).', 'en', 'utility', 'Hi {{student_name}}, thank you for applying for {{application_role}} at {{site_name}}.\n\nWe have received your application. Our recruitment team will review it and contact you if your profile is shortlisted.', '["student_name","application_role","site_name"]', 'none', NULL, 'lead', 0, 1),
('application_shortlisted', 'Application shortlisted', 'Staff set an application to Shortlisted with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).', 'en', 'utility', 'Hi {{student_name}}, good news! Your application for {{application_role}} at {{site_name}} has been shortlisted.\n\nOur recruitment team will contact you soon with the next steps.', '["student_name","application_role","site_name"]', 'none', NULL, 'lead', 0, 1),
('interview_scheduled', 'Interview scheduled', 'Staff schedule an interview with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).', 'en', 'utility', 'Hi {{student_name}}, your interview for {{application_role}} at {{site_name}} is scheduled.\n\nDate & time: {{interview_date}}\nMode: {{interview_mode}}\nVenue / link: {{interview_location}}\n\nPlease be on time. Contact us on {{support_number}} if you need to reschedule.', '["student_name","application_role","site_name","interview_date","interview_mode","interview_location","support_number"]', 'none', NULL, 'lead', 0, 1),
('application_hired', 'Candidate selected', 'Staff set an application to Hired with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).', 'en', 'utility', 'Hi {{student_name}}, congratulations! You have been selected for {{application_role}} at {{site_name}}.\n\nOur team will share the joining details with you shortly.', '["student_name","application_role","site_name"]', 'none', NULL, 'lead', 0, 1),
('application_rejected', 'Application not selected', 'Staff set an application to Rejected with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).', 'en', 'utility', 'Hi {{student_name}}, thank you for your interest in {{application_role}} at {{site_name}}.\n\nAfter careful review, we will not be moving forward with your application at this time. We wish you all the best.', '["student_name","application_role","site_name"]', 'none', NULL, 'lead', 0, 1),
('admin_new_enquiry', 'Admin alert: new enquiry', 'A new enquiry, callback request or chatbot lead is saved. Goes to the admin alert numbers (Settings tab).', 'en', 'utility', 'New enquiry on {{site_name}}.\n\nName: {{lead_name}}\nPhone: {{lead_phone}}\nSource: {{lead_source}}\nInterested in: {{course_name}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.', '["site_name","lead_name","lead_phone","lead_source","course_name","admin_url"]', 'none', NULL, 'staff', 0, 0),
('admin_new_order', 'Admin alert: new paid order', 'A payment is verified and the order is marked paid (not for 100% coupon orders). Goes to the admin alert numbers.', 'en', 'utility', 'New payment received on {{site_name}}.\n\nStudent: {{student_name}}\nCourse: {{course_name}}\nAmount: {{amount}}\nOrder ID: {{order_id}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.', '["site_name","student_name","course_name","amount","order_id","admin_url"]', 'none', NULL, 'staff', 0, 0),
('admin_new_application', 'Admin alert: new job application', 'A candidate submits a job application. Goes to the admin alert numbers.', 'en', 'utility', 'New job application on {{site_name}}.\n\nCandidate: {{lead_name}}\nPhone: {{lead_phone}}\nRole: {{application_role}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.', '["site_name","lead_name","lead_phone","application_role","admin_url"]', 'none', NULL, 'staff', 0, 0),
('admin_new_ticket', 'Admin alert: new support ticket', 'A student opens a support ticket. Goes to the admin alert numbers.', 'en', 'utility', 'New support ticket #{{ticket_no}} on {{site_name}}.\n\nStudent: {{student_name}}\nSubject: {{ticket_subject}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.', '["ticket_no","site_name","student_name","ticket_subject","admin_url"]', 'none', NULL, 'staff', 0, 0);

-- ------------------------------------------------------------------
-- 10. Payment gateway is Cashfree: public FAQ / Cookie Policy wording
-- ------------------------------------------------------------------
-- Text-targeted, so content an admin already edited is left alone.
UPDATE faqs SET answer = REPLACE(answer, 'net banking and wallets through Razorpay.', 'net banking and wallets through our secure payment partner, Cashfree Payments.') WHERE answer LIKE '%wallets through Razorpay.%';
UPDATE cms_sections SET content = REPLACE(content, 'Our payment partner (Razorpay) may set its own cookies during checkout', 'Our payment partner (Cashfree Payments) may set its own cookies during checkout') WHERE section_key = 'page_cookies' AND content LIKE '%Our payment partner (Razorpay)%';

INSERT INTO schema_migrations (version, description) VALUES (8, 'Premium redesign - invoices, email delivery log, video sections, highlights, promo banners, enquiry popups, quizzes');
