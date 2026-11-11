-- ==================================================================
-- Migration 002: jobs & careers, newsletter, extra policies, FAQs, social settings
-- Fresh install order: schema.sql -> seed.sql -> migration-002.sql
-- ==================================================================
SET NAMES utf8mb4;

-- Government job / exam notifications (always link to the OFFICIAL source)
CREATE TABLE IF NOT EXISTS job_alerts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     SMALLINT UNSIGNED NULL,
    title           VARCHAR(200) NOT NULL,
    slug            VARCHAR(220) NOT NULL,
    organization    VARCHAR(160) NOT NULL,
    alert_type      ENUM('notification','admit_card','result','answer_key','syllabus') NOT NULL DEFAULT 'notification',
    summary         TEXT NULL,
    vacancies       VARCHAR(60) NULL COMMENT 'as stated in the official notification',
    qualification   VARCHAR(200) NULL,
    apply_start     DATE NULL,
    last_date       DATE NULL,
    official_url    VARCHAR(500) NOT NULL,
    is_featured     TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at    DATETIME NULL,
    is_demo         TINYINT(1) NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_alerts_slug (slug),
    KEY idx_job_alerts_listing (status, published_at),
    CONSTRAINT fk_job_alerts_category FOREIGN KEY (category_id) REFERENCES course_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_alerts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Careers at Laxyam Academy
CREATE TABLE IF NOT EXISTS job_openings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(160) NOT NULL,
    slug             VARCHAR(180) NOT NULL,
    department       VARCHAR(80) NULL,
    employment_type  ENUM('full_time','part_time','contract','internship','visiting') NOT NULL DEFAULT 'full_time',
    location         VARCHAR(120) NOT NULL DEFAULT 'Badarpur, New Delhi',
    experience       VARCHAR(80) NULL,
    salary_range     VARCHAR(80) NULL,
    description      TEXT NULL,
    responsibilities JSON NULL,
    requirements     JSON NULL,
    status           ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
    closes_at        DATE NULL,
    is_demo          TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_openings_slug (slug),
    KEY idx_job_openings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_applications (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opening_id       INT UNSIGNED NULL,
    name             VARCHAR(120) NOT NULL,
    email            VARCHAR(190) NOT NULL,
    phone            VARCHAR(20) NOT NULL,
    experience_years TINYINT UNSIGNED NULL,
    subjects         VARCHAR(200) NULL,
    message          TEXT NULL,
    resume_path      VARCHAR(255) NULL COMMENT 'uploads/private/resumes - never public',
    status           ENUM('new','shortlisted','interview','rejected','hired') NOT NULL DEFAULT 'new',
    notes            TEXT NULL,
    ip_address       VARCHAR(45) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_job_applications_status (status, created_at),
    CONSTRAINT fk_job_applications_opening FOREIGN KEY (opening_id) REFERENCES job_openings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL,
    status          ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
    source          VARCHAR(40) NOT NULL DEFAULT 'footer',
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_newsletter_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New public settings
INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
('social_x',            '', 'social',  1),
('social_whatsapp_channel', '', 'social', 1),
('helpline_label',      'Admission Helpline', 'contact', 1),
('office_hours',        '', 'contact', 1),
('map_query',           'Dharmveer Market, Badarpur, New Delhi', 'contact', 1),
('app_android_url',     '', 'apps', 1),
('app_ios_url',         '', 'apps', 1);

-- Additional policy pages (DRAFTS - review before launch). Razorpay activation
-- requires: Contact, Privacy, Terms, Refund/Cancellation and Shipping/Delivery pages.
INSERT IGNORE INTO cms_sections (section_key, title, content, sort_order) VALUES
('page_delivery', 'Delivery Policy', JSON_OBJECT(
    'title', 'Shipping & Delivery Policy',
    'intro', 'DRAFT - review before publishing. Laxyam Academy sells digital courses and services; no physical goods are shipped unless stated.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Digital delivery', 'body', 'Course access is activated in your student dashboard immediately after the payment is verified. You will also receive an in-site notification and, when email is configured, a confirmation email.'),
        JSON_OBJECT('heading', 'Delays', 'body', 'If access is not visible within 30 minutes of a successful payment, contact info@laxyamacademy.com or call +91 87005 11018 with your order number.'),
        JSON_OBJECT('heading', 'Printed material', 'body', '[If the academy ships printed notes, add delivery timelines, regions served and charges here.]')
    )
), 24),
('page_cancellation', 'Cancellation Policy', JSON_OBJECT(
    'title', 'Cancellation Policy',
    'intro', 'DRAFT - the academy must confirm these terms before publishing.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Cancelling an enrolment', 'body', '[To be confirmed: whether and within how many days an enrolment can be cancelled after purchase.]'),
        JSON_OBJECT('heading', 'Cancelled classes', 'body', 'If the academy cancels a live class, it will be rescheduled or a recording will be provided. Updates appear on the Live Classes page and in your dashboard.'),
        JSON_OBJECT('heading', 'Refunds', 'body', 'Refunds for approved cancellations follow the Refund Policy.')
    )
), 25),
('page_disclaimer', 'Disclaimer', JSON_OBJECT(
    'title', 'Disclaimer',
    'intro', 'DRAFT - review before publishing.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Not a government website', 'body', 'Laxyam Academy is a private coaching institute. It is not affiliated with UPSC, SSC, IBPS, RRB, any State PSC or any government body.'),
        JSON_OBJECT('heading', 'Job and exam notifications', 'body', 'Job alerts on this website are summaries for convenience. Always read the official notification on the recruiting body''s website before applying; the official notification prevails.'),
        JSON_OBJECT('heading', 'No guarantee of selection', 'body', 'Our courses support preparation. Results depend on individual effort, and no course guarantees selection.'),
        JSON_OBJECT('heading', 'External links', 'body', 'Links to external websites are provided for information. We are not responsible for their content.')
    )
), 26),
('page_cookies', 'Cookie Policy', JSON_OBJECT(
    'title', 'Cookie Policy',
    'intro', 'DRAFT - review before publishing.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Essential cookie', 'body', 'We use one essential session cookie to keep you signed in and protect forms from misuse. It is deleted when you close your browser or log out.'),
        JSON_OBJECT('heading', 'Payments', 'body', 'Our payment partner (Razorpay) may set its own cookies during checkout to process the payment securely.'),
        JSON_OBJECT('heading', 'No advertising cookies', 'body', 'We do not use advertising or cross-site tracking cookies.')
    )
), 27);

-- DEMO careers openings (from the "We Are Hiring" announcement - confirm details before launch)
INSERT IGNORE INTO job_openings (title, slug, department, employment_type, location, experience, description, responsibilities, requirements, status, is_demo) VALUES
('Faculty - General Studies (UPSC/State PSC)', 'faculty-general-studies', 'Academics', 'full_time', 'Badarpur, New Delhi (On-site / Online)', '2+ years teaching',
 'We are looking for an educator to teach General Studies for UPSC and State PSC aspirants in live and recorded formats.',
 JSON_ARRAY('Plan and deliver live and recorded classes', 'Prepare notes and practice questions', 'Conduct doubt sessions and evaluate answer writing'),
 JSON_ARRAY('Graduate or postgraduate in a relevant subject', 'Clear communication in Hindi and English', 'Experience teaching competitive exam students'), 'open', 1),
('Faculty - Quantitative Aptitude & Reasoning', 'faculty-quant-reasoning', 'Academics', 'part_time', 'Badarpur, New Delhi', '1+ years teaching',
 'Teach Quant and Reasoning for SSC, Banking and Railway exams.',
 JSON_ARRAY('Concept and shortcut-technique classes', 'Create topic-wise tests', 'Track student progress'),
 JSON_ARRAY('Strong command of arithmetic and reasoning', 'Prior coaching experience preferred'), 'open', 1),
('Academic Counsellor', 'academic-counsellor', 'Admissions', 'full_time', 'Badarpur, New Delhi', 'Freshers welcome',
 'Guide students and parents on courses, batches and admissions.',
 JSON_ARRAY('Answer enquiries by phone, WhatsApp and walk-in', 'Explain courses and fee details', 'Follow up with leads'),
 JSON_ARRAY('Good communication in Hindi and English', 'Basic computer skills'), 'open', 1);

-- Job alerts: links to OFFICIAL recruitment websites only. No dates or vacancy
-- numbers are seeded - staff add them from the official notification.
INSERT IGNORE INTO job_alerts (category_id, title, slug, organization, alert_type, summary, official_url, is_featured, status, published_at, is_demo) VALUES
((SELECT id FROM course_categories WHERE slug='upsc'), 'UPSC Examination Notifications', 'upsc-examination-notifications', 'Union Public Service Commission', 'notification', 'Civil Services, CDS, NDA and other UPSC examination notices are published on the UPSC website.', 'https://upsc.gov.in/examinations/active-exams', 1, 'published', NOW(), 1),
((SELECT id FROM course_categories WHERE slug='ssc'), 'SSC Recruitment Notices', 'ssc-recruitment-notices', 'Staff Selection Commission', 'notification', 'CGL, CHSL, MTS, GD and other SSC notices, admit cards and results.', 'https://ssc.gov.in', 1, 'published', NOW(), 1),
((SELECT id FROM course_categories WHERE slug='banking'), 'IBPS Recruitment (PO, Clerk, SO, RRB)', 'ibps-recruitment', 'Institute of Banking Personnel Selection', 'notification', 'Common Recruitment Process notices for public sector banks and Regional Rural Banks.', 'https://www.ibps.in', 1, 'published', NOW(), 1),
((SELECT id FROM course_categories WHERE slug='railway'), 'Railway Recruitment Board Notices', 'rrb-recruitment-notices', 'Railway Recruitment Boards', 'notification', 'RRB NTPC, Group D, ALP and other railway recruitment notices (check your regional RRB website).', 'https://indianrailways.gov.in/railwayboard/view_section.jsp?lang=0&id=0,7,1281', 1, 'published', NOW(), 1);

-- More FAQs for the FAQ page
INSERT INTO faqs (category, question, answer, sort_order) VALUES
('courses', 'Are classes live or recorded?', 'Each course page shows the course type: Live, Recorded or Live + Recorded. Recordings of live classes are added for eligible students when available.', 3),
('courses', 'How long can I access a course?', 'The validity is shown on every course page and starts from the date of enrolment. Free courses have lifetime access unless stated otherwise.', 4),
('tests', 'Do mock tests have negative marking?', 'Some tests do, as in the real exam. The test instructions show the marking scheme before you start.', 5),
('payments', 'Which payment methods are accepted?', 'UPI, debit and credit cards, net banking and wallets through Razorpay. We never see or store your card details or UPI PIN.', 6),
('payments', 'My payment succeeded but I cannot see the course.', 'Access is activated automatically once the payment is verified, usually within seconds. If it does not appear within 30 minutes, contact us with your order number.', 7),
('account', 'I forgot my password.', 'Use "Forgot password" on the login page to receive a reset link that is valid for 60 minutes.', 8),
('general', 'Where is the academy located?', 'Dharmveer Market, Badarpur, New Delhi. Call or WhatsApp +91 87005 11018 before visiting.', 9);

INSERT INTO schema_migrations (version, description) VALUES (2, 'Jobs & careers, newsletter, extra policies, FAQs');
