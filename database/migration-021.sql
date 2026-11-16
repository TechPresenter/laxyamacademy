-- ==================================================================
-- Migration 21: SEO - category landing pages, keywords, 404 monitor and page titles
-- 1. course_categories.intro: introduction text of the new category pages (/categories/<slug>),
--    edited in admin -> Courses -> Categories.
-- 2. seo_pages.focus_keyword / secondary_keywords: editorial keywords for admin -> SEO (checked by
--    the SEO health dashboard; never printed as a meta keywords tag).
-- 3. seo_404_log: website URLs that answered "page not found" (admin -> SEO -> 404 Monitor).
-- 4. Search titles and descriptions for the course categories and courses, the homepage title and
--    H1 - written ONLY where the field is still empty or still holds the original seed text, so
--    anything an admin has already typed is kept. The old /courses and /contact seed rows (which
--    still named UPSC, Defence and Teaching exams) are removed; those pages now get their title and
--    description from the live course catalogue and contact settings (includes/seo.php).
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin -> Settings -> Database Updates.
-- ==================================================================
SET NAMES utf8mb4;

-- 1. Category introduction
ALTER TABLE course_categories ADD COLUMN intro TEXT NULL COMMENT 'introduction on /categories/<slug> (plain text, blank line = new paragraph)' AFTER description;

-- 2. Keywords for the SEO dashboard
ALTER TABLE seo_pages
    ADD COLUMN focus_keyword VARCHAR(100) NULL AFTER robots,
    ADD COLUMN secondary_keywords VARCHAR(255) NULL COMMENT 'comma separated' AFTER focus_keyword;

-- 3. 404 monitor
CREATE TABLE seo_404_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path          VARCHAR(255) NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 1,
    last_referrer VARCHAR(255) NULL,
    first_seen    DATETIME NOT NULL,
    last_seen     DATETIME NOT NULL,
    UNIQUE KEY uq_404_path (path),
    KEY idx_404_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4a. Homepage (seed row only)
UPDATE seo_pages SET
    title = 'Laxyam Academy | Railway, SSC, Banking & Government Exam Coaching',
    meta_description = 'Prepare for Railway NTPC, Group D, SSC, Banking, Police and Government Exams with Laxyam Academy. Join expert-guided offline coaching in Badarpur, New Delhi.'
WHERE path = '/' AND title = 'Laxyam Academy - UPSC, SSC, Banking & Competitive Exam Preparation';

DELETE FROM seo_pages WHERE path = '/courses' AND title = 'Course Catalogue - Laxyam Academy'
    AND meta_description = 'Find the right course for your competitive exam preparation: UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and more.';
DELETE FROM seo_pages WHERE path = '/contact' AND title = 'Contact Us - Laxyam Academy'
    AND meta_description = 'Contact Laxyam Academy, Dharmveer Market, Badarpur, New Delhi. Call or WhatsApp +91 87005 11018.';

UPDATE site_settings SET setting_value = 'Laxyam Academy | Government Exam Coaching in Badarpur, New Delhi'
WHERE setting_key = 'default_seo_title' AND setting_value = 'Laxyam Academy - Competitive Exam Preparation';

-- Homepage H1 and introduction (shown above the courses while the hero is switched off)
INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
    ('home_h1', 'Achieve Your Government Job Dream with Laxyam Academy', 'seo', 1),
    ('home_intro', 'Offline classroom coaching in Badarpur, New Delhi for Railway, SSC, Banking, Delhi Police and State government exams, plus foundation courses in Maths, Reasoning, GK/GS and English, with regular mock tests.', 'seo', 1)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 4b. Course categories (empty fields only)
UPDATE course_categories SET seo_title = 'Railway Coaching in Delhi: NTPC, Group D, ALP | Laxyam Academy' WHERE slug = 'railway' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Offline Railway exam coaching in Badarpur, New Delhi: RRB NTPC, Group D and ALP / Technician courses with weekly mock tests and previous-year papers.' WHERE slug = 'railway' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Laxyam Academy runs offline classroom coaching for the Railway Recruitment Board (RRB) exams at its centre in Badarpur, New Delhi. The Railway courses prepare you for RRB NTPC, Group D and ALP / Technician: Mathematics, General Intelligence & Reasoning and General Science / General Awareness are taught from the basics to the exam level.

Every course comes with daily practice questions, topic-wise tests, weekly mock tests and previous-year RRB papers. Always check the latest RRB notification for the eligibility, the exam stages and the pattern.' WHERE slug = 'railway' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'SSC Coaching in Delhi: CGL, CHSL, MTS & GD | Laxyam Academy' WHERE slug = 'ssc' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Offline SSC coaching in Badarpur, New Delhi for CGL, CHSL, MTS and GD Constable: Quant, Reasoning, English and General Awareness with weekly mock tests.' WHERE slug = 'ssc' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Offline classroom coaching at Laxyam Academy, Badarpur, New Delhi, for the Staff Selection Commission exams: SSC CGL, CHSL, MTS and GD Constable. Classes cover Quantitative Aptitude, Reasoning, English and General Awareness, with weekly mock tests to build speed and accuracy.

Check the latest SSC notification for the eligibility, the exam dates and the pattern of the exam you are preparing for.' WHERE slug = 'ssc' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'Banking Coaching in Delhi for IBPS PO & Clerk | Laxyam Academy' WHERE slug = 'banking' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Offline bank exam coaching in Badarpur, New Delhi for IBPS PO, Clerk and RRB: Quantitative Aptitude, Reasoning, English and General Awareness with mock tests.' WHERE slug = 'banking' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Offline classroom coaching at Laxyam Academy, Badarpur, New Delhi, for IBPS and other bank recruitment exams such as PO, Clerk and RRB posts. Classes cover Quantitative Aptitude, Reasoning, English and General Awareness, with mock tests.

Check the latest IBPS or bank notification for the eligibility and the exam pattern.' WHERE slug = 'banking' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'Delhi Police Exam Coaching in Badarpur | Laxyam Academy' WHERE slug = 'police' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Offline coaching for the Delhi Police Constable exam in Badarpur, New Delhi: GK, current affairs, Reasoning, Numerical Ability and Computer Awareness with mock tests.' WHERE slug = 'police' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Offline classroom coaching at Laxyam Academy, Badarpur, New Delhi, for the Delhi Police Constable exam. Classes cover General Knowledge and current affairs, Reasoning, Numerical Ability and Computer Awareness, with mock tests.

Check the latest official notification for the eligibility, the physical standards and the exam pattern.' WHERE slug = 'police' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'State Government Exam Coaching in Delhi | Laxyam Academy' WHERE slug = 'state-govt-exams' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Offline coaching in Badarpur, New Delhi for state government recruitment exams: Mathematics, Reasoning, GK/GS and English with weekly mock tests.' WHERE slug = 'state-govt-exams' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Offline classroom coaching at Laxyam Academy, Badarpur, New Delhi, for state government recruitment exams. Classes cover Mathematics, Reasoning, GK / GS and English, with weekly mock tests.

Check the official notification of your state recruitment board for the eligibility and the syllabus.' WHERE slug = 'state-govt-exams' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'Government Job Preparation Course in Delhi | Laxyam Academy' WHERE slug = 'govt-job-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = 'Start your government job preparation with an offline foundation in Maths, Reasoning, GK/GS and English at Laxyam Academy, Badarpur, New Delhi, with mock tests.' WHERE slug = 'govt-job-foundation' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'A complete offline foundation in Mathematics, Reasoning, GK / GS and English for students who are starting their preparation for government jobs, at Laxyam Academy, Badarpur, New Delhi.

Daily practice questions, topic-wise and chapter-wise tests, weekly mock tests and previous-year papers prepare you for SSC, Railway, Banking, Police and State government exams.' WHERE slug = 'govt-job-foundation' AND (intro IS NULL OR intro = '');

UPDATE course_categories SET seo_title = 'Maths, Reasoning, GK & English Foundation Courses | Laxyam Academy' WHERE slug = 'subject-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE course_categories SET seo_description = '3-month offline foundation courses in Mathematics, Reasoning, GK/GS and English at Laxyam Academy, Badarpur, New Delhi, for government exam aspirants.' WHERE slug = 'subject-foundation' AND (seo_description IS NULL OR seo_description = '');
UPDATE course_categories SET intro = 'Offline foundation courses at Laxyam Academy, Badarpur, New Delhi, for students who want to strengthen one subject for government job exams: Mathematics / Quantitative Aptitude, Reasoning, GK / GS or English. Each course builds the subject from the basics to the exam level.' WHERE slug = 'subject-foundation' AND (intro IS NULL OR intro = '');

-- 4c. Courses: search titles (empty fields only; the meta description stays the course's short description)
UPDATE courses SET seo_title = 'Railway NTPC & Group D Coaching in Delhi | Laxyam Academy' WHERE slug = 'railway-ntpc-group-d' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'Railway ALP & Technician Coaching in Delhi | Laxyam Academy' WHERE slug = 'railway-alp-technician' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'SSC CGL, CHSL, MTS & GD Coaching in Delhi | Laxyam Academy' WHERE slug = 'ssc-cgl-chsl-mts-gd' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'Bank & IBPS Exam Coaching in Delhi | Laxyam Academy' WHERE slug = 'bank-ibps' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'Delhi Police Constable Exam Coaching | Laxyam Academy' WHERE slug = 'delhi-police' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'State Government Exam Course in Delhi | Laxyam Academy' WHERE slug = 'state-government-exam' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'All Government Job Foundation Course in Delhi | Laxyam Academy' WHERE slug = 'all-government-job-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'Mathematics Foundation Course for Govt Exams | Laxyam Academy' WHERE slug = 'mathematics-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'Reasoning Foundation Course for Govt Exams | Laxyam Academy' WHERE slug = 'reasoning-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'GK & GS Foundation Course for Govt Exams | Laxyam Academy' WHERE slug = 'gk-gs-foundation' AND (seo_title IS NULL OR seo_title = '');
UPDATE courses SET seo_title = 'English Foundation Course for Govt Exams | Laxyam Academy' WHERE slug = 'english-foundation' AND (seo_title IS NULL OR seo_title = '');

INSERT INTO schema_migrations (version, description) VALUES (21, 'SEO: category pages, keywords, 404 monitor, page titles');
