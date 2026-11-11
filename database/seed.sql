-- ==================================================================
-- Laxyam Academy - seed data
-- Run AFTER schema.sql on an empty database.
--
-- Rows with is_demo = 1 are DEMO CONTENT (sample courses, prices, faculty
-- placeholders, schedules). Replace or delete them before going live.
-- Statistics are seeded as NOT approved, and testimonials/success stories
-- as PENDING, so nothing unverified is shown publicly.
-- No admin account is seeded - create one with:
--     php database/create-admin.php "Your Name" you@example.com
-- ==================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- Roles and permissions
-- ------------------------------------------------------------------
INSERT INTO roles (slug, name, description, is_system) VALUES
('super_admin',      'Super Admin',      'Full access, including role assignment and settings', 1),
('academic_manager', 'Academic Manager', 'Courses, lessons, mock tests, live classes and resources', 1),
('content_manager',  'Content Manager',  'Website CMS, blog, testimonials, SEO and resources', 1),
('finance_manager',  'Finance Manager',  'Orders, payments, refunds, coupons and financial reports', 1),
('support_manager',  'Support Manager',  'Enquiries, support tickets, student lookup and notifications', 1);

INSERT INTO permissions (slug, name, module) VALUES
('dashboard.view',      'View dashboard',              'dashboard'),
('users.view',          'View students',               'users'),
('users.manage',        'Manage students',             'users'),
('roles.manage',        'Manage staff roles',          'roles'),
('courses.view',        'View courses',                'courses'),
('courses.manage',      'Manage courses & categories', 'courses'),
('lessons.manage',      'Manage sections & lessons',   'courses'),
('instructors.manage',  'Manage instructors',          'courses'),
('enrolments.manage',   'Grant or revoke enrolments',  'courses'),
('exams.manage',        'Manage mock tests',           'exams'),
('live.manage',         'Manage live classes',         'live'),
('resources.manage',    'Manage learning resources',   'resources'),
('orders.view',         'View orders',                 'finance'),
('payments.view',       'View payments',               'finance'),
('refunds.manage',      'Record refunds',              'finance'),
('coupons.manage',      'Manage coupons',              'finance'),
('enquiries.view',      'View enquiries',              'enquiries'),
('enquiries.manage',    'Manage enquiries',            'enquiries'),
('blog.manage',         'Manage blog',                 'content'),
('testimonials.manage', 'Approve testimonials',        'content'),
('cms.manage',          'Manage website CMS',          'content'),
('seo.manage',          'Manage SEO',                  'content'),
('reports.view',        'View reports',                'reports'),
('reports.export',      'Export reports (CSV)',        'reports'),
('notifications.send',  'Send notifications',          'notifications'),
('support.manage',      'Manage support tickets',      'support'),
('settings.manage',     'Manage settings',             'settings'),
('logs.view',           'View security logs',          'logs');

-- super_admin implicitly has every permission (see includes/permissions.php)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.slug = 'academic_manager' AND p.slug IN ('dashboard.view','users.view','courses.view','courses.manage','lessons.manage','instructors.manage','enrolments.manage','exams.manage','live.manage','resources.manage','reports.view'))
   OR (r.slug = 'content_manager'  AND p.slug IN ('dashboard.view','courses.view','resources.manage','blog.manage','testimonials.manage','cms.manage','seo.manage'))
   OR (r.slug = 'finance_manager'  AND p.slug IN ('dashboard.view','orders.view','payments.view','refunds.manage','coupons.manage','reports.view','reports.export'))
   OR (r.slug = 'support_manager'  AND p.slug IN ('dashboard.view','users.view','enquiries.view','enquiries.manage','support.manage','notifications.send'));

-- ------------------------------------------------------------------
-- Site settings (is_public = 1 values may be sent to the browser)
-- ------------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES
('site_name',        'Laxyam Academy',                     'general', 1),
('tagline',          'Learn • Prepare • Achieve',          'general', 1),
('contact_email',    'info@laxyamacademy.com',             'contact', 1),
('contact_phone',    '+91 87005 11018',                    'contact', 1),
('whatsapp_number',  '918700511018',                       'contact', 1),
('address',          'Dharmveer Market, Badarpur, New Delhi', 'contact', 1),
('office_hours',     '',                                   'contact', 1),
('social_youtube',   '',                                   'social',  1),
('social_facebook',  '',                                   'social',  1),
('social_instagram', '',                                   'social',  1),
('social_linkedin',  '',                                   'social',  1),
('social_telegram',  '',                                   'social',  1),
('currency',         'INR',                                'general', 1),
('timezone',         'Asia/Kolkata',                       'general', 0),
('maintenance_mode', '0',                                  'general', 0),
('upload_max_mb',    '20',                                 'uploads', 0),
('default_seo_title', 'Laxyam Academy - Competitive Exam Preparation', 'seo', 1),
('default_seo_description', 'Prepare for UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and Police exams with structured courses, live classes, mock tests and study material from Laxyam Academy, New Delhi.', 'seo', 1);

-- ------------------------------------------------------------------
-- Homepage CMS blocks (edited in admin/cms.php)
-- ------------------------------------------------------------------
INSERT INTO cms_sections (section_key, title, content, sort_order) VALUES
('announcement', 'Top announcement bar', JSON_OBJECT(
    'text', 'Be a Part of Our Success Story!',
    'link', ''
), 1),
('hero', 'Hero section', JSON_OBJECT(
    'heading', 'Build Your Career with',
    'highlight', 'Laxyam Academy',
    'tagline', 'Learn • Prepare • Achieve',
    'description', 'Preparation for competitive exams with expert faculty, live & recorded classes, mock tests and comprehensive study material.',
    'primary_label', 'Explore Courses', 'primary_url', '/courses',
    'secondary_label', 'Watch Demo', 'secondary_url', '',
    'image', 'assets/images/hero-illustration.svg',
    'card_badge', 'Announcement',
    'card_title', 'We Are Hiring!',
    'card_text', 'Join our growing team of educators and shape the future of competitive exam aspirants.',
    'card_cta_label', 'View Current Openings', 'card_cta_url', '/careers'
), 2),
('features', 'Feature strip', JSON_OBJECT('items', JSON_ARRAY(
    JSON_OBJECT('icon', 'users',     'title', 'Expert Faculty', 'text', 'Learn from experienced educators'),
    JSON_OBJECT('icon', 'clipboard', 'title', 'Mock Tests',     'text', 'Practice & improve your performance'),
    JSON_OBJECT('icon', 'video',     'title', 'Live Classes',   'text', 'Interactive learning with doubt support'),
    JSON_OBJECT('icon', 'book',      'title', 'Study Material', 'text', 'PDFs, notes & more')
)), 3),
('courses', 'Popular courses heading', JSON_OBJECT(
    'title', 'Start Learning Today',
    'subtitle', 'Choose from our most popular courses and begin your preparation journey.'
), 4),
('categories', 'Exam categories heading', JSON_OBJECT(
    'title', 'Popular Exam Categories',
    'subtitle', 'Explore courses by exam category.'
), 5),
('why_choose', 'Why choose us', JSON_OBJECT(
    'title', 'Why Choose', 'highlight', 'Laxyam Academy?',
    'items', JSON_ARRAY(
        JSON_OBJECT('icon', 'users',     'title', 'Expert Faculty',             'text', 'Learn from experienced, result-oriented teachers.'),
        JSON_OBJECT('icon', 'trophy',    'title', 'Comprehensive Courses',      'text', 'Complete syllabus coverage with structured learning.'),
        JSON_OBJECT('icon', 'clipboard', 'title', 'Mock Tests & Analysis',      'text', 'Improve with detailed performance analysis.'),
        JSON_OBJECT('icon', 'video',     'title', 'Live & Interactive Classes', 'text', 'Doubt resolution and personal guidance.'),
        JSON_OBJECT('icon', 'file',      'title', 'Structured Study Material',  'text', 'Topic-wise notes, PDFs and practice sets.'),
        JSON_OBJECT('icon', 'headset',   'title', 'Student Support',            'text', 'Help with courses, payments and preparation queries.')
    )
), 6),
('stats', 'Statistics band', JSON_OBJECT('items', JSON_ARRAY(
    JSON_OBJECT('icon', 'users',     'value', '', 'label', 'Happy Learners',       'approved', false),
    JSON_OBJECT('icon', 'clipboard', 'value', '', 'label', 'Mock Tests',           'approved', false),
    JSON_OBJECT('icon', 'user',      'value', '', 'label', 'Expert Faculty',       'approved', false),
    JSON_OBJECT('icon', 'star',      'value', '', 'label', 'Student Satisfaction', 'approved', false)
)), 7),
('live_classes', 'Live classes heading', JSON_OBJECT(
    'title', 'Upcoming Live Classes',
    'subtitle', 'Join interactive sessions with our faculty.'
), 8),
('resources', 'Free resources heading', JSON_OBJECT(
    'title', 'Free Learning Resources',
    'subtitle', 'Download study material, notes, previous year papers and more.'
), 9),
('success_stories', 'Success stories heading', JSON_OBJECT(
    'title', 'Our Students, Our Pride',
    'subtitle', 'Real results. Real success stories.'
), 10),
('testimonials', 'Testimonials heading', JSON_OBJECT(
    'title', 'What Our Students Say',
    'subtitle', ''
), 11),
('cta', 'Call to action banner', JSON_OBJECT(
    'heading', 'Take the Next Step in Your Exam Preparation',
    'text', 'Start your journey towards a brighter future with structured courses, live classes and mock tests.',
    'primary_label', 'Get Started Now', 'primary_url', '/register',
    'secondary_label', 'View Courses', 'secondary_url', '/courses',
    'script_text', 'Be a Part of Our Success Story!',
    'image', 'assets/images/cta-illustration.svg'
), 12),
('footer', 'Footer', JSON_OBJECT(
    'description', 'Your trusted partner in competitive exam preparation. Learn from expert faculty, practise with mock tests and achieve your career goals.',
    'copyright', 'Laxyam Academy. All rights reserved.'
), 13);

-- ------------------------------------------------------------------
-- Exam categories
-- ------------------------------------------------------------------
INSERT INTO course_categories (name, slug, short_name, icon, color, description, sort_order) VALUES
('UPSC',        'upsc',        'UPSC',      'landmark',     '#0B63F6', 'Civil Services (IAS, IPS, IFS) preparation.', 1),
('SSC',         'ssc',         'SSC',       'briefcase',    '#16A34A', 'SSC CGL, CHSL, MTS, CPO and GD preparation.', 2),
('Banking',     'banking',     'Banking',   'bank',         '#0369A1', 'IBPS, SBI, RBI PO and Clerk preparation.', 3),
('State PSC',   'state-psc',   'State PSC', 'building',     '#D97706', 'State Public Service Commission exams.', 4),
('Teaching',    'teaching',    'Teaching',  'presentation', '#7C3AED', 'CTET, STET, KVS and DSSSB preparation.', 5),
('Defence',     'defence',     'Defence',   'shield',       '#15803D', 'NDA, CDS and AFCAT preparation.', 6),
('Railway',     'railway',     'Railway',   'train',        '#DC2626', 'RRB NTPC, Group D and ALP preparation.', 7),
('Police',      'police',      'Police',    'badge',        '#0E7490', 'State police constable and SI exams.', 8),
('Other Exams', 'other-exams', 'Other',     'grid',         '#475569', 'Other competitive and recruitment exams.', 9);

-- ------------------------------------------------------------------
-- DEMO faculty placeholders (no credentials claimed - replace with real profiles)
-- ------------------------------------------------------------------
INSERT INTO instructors (name, slug, designation, bio, is_demo) VALUES
('Demo Faculty – Polity',  'demo-faculty-polity',  'Polity & Governance', 'Demo profile. Replace with the real faculty member''s verified details.', 1),
('Demo Faculty – History', 'demo-faculty-history', 'History & Culture',   'Demo profile. Replace with the real faculty member''s verified details.', 1),
('Demo Faculty – Quant',   'demo-faculty-quant',   'Quantitative Aptitude & Reasoning', 'Demo profile. Replace with the real faculty member''s verified details.', 1),
('Demo Faculty – GS',      'demo-faculty-gs',      'General Studies & Current Affairs', 'Demo profile. Replace with the real faculty member''s verified details.', 1);

-- ------------------------------------------------------------------
-- DEMO courses (titles, prices and durations are samples)
-- Ratings are 0 until genuine reviews are approved.
-- ------------------------------------------------------------------
INSERT INTO courses (category_id, instructor_id, title, slug, subtitle, short_description, description, duration_label, validity_days, language, level, course_type, price, discount_price, learning_outcomes, includes, requirements, faqs, badge, is_featured, sort_order, status, published_at, is_demo, seo_title, seo_description) VALUES
((SELECT id FROM course_categories WHERE slug='upsc'), 1,
 'UPSC Foundation 2027', 'upsc-foundation-2027', 'Complete 2 Year Program',
 'A structured foundation program for UPSC Civil Services 2027 with live classes, recorded lectures and answer-writing practice.',
 'The UPSC Foundation 2027 course is designed for aspirants who are starting their civil services preparation from scratch or want a strong conceptual base. It covers the UPSC syllabus with a structured approach, live interactive classes, recorded lectures, study material and regular assessment through mock tests.',
 '12 Months', 365, 'bilingual', 'beginner', 'hybrid', 14999, 9999,
 JSON_ARRAY('Complete UPSC syllabus coverage','Live + recorded classes','NCERT based foundation','Current affairs integration','Chapter-wise notes & PDFs','Regular mock tests & answer writing','Personalised doubt support'),
 JSON_ARRAY('Live classes with doubt support','Downloadable study material (PDF)','Mock tests & practice questions','Access on web and mobile browsers','Certificate of completion'),
 JSON_ARRAY('No prior preparation needed','Graduation (or final year) for exam eligibility'),
 JSON_ARRAY(JSON_OBJECT('q','Is this course suitable for beginners?','a','Yes. The course starts from NCERT-level fundamentals.'), JSON_OBJECT('q','Are classes available in Hindi?','a','Classes are bilingual (Hindi and English).'), JSON_OBJECT('q','How long can I access the course?','a','Access is valid for the validity period shown on this page from the date of enrolment.')),
 'Bestseller', 1, 1, 'published', NOW(), 1,
 'UPSC Foundation 2027 Course | Laxyam Academy', 'Structured UPSC Civil Services 2027 foundation course with live classes, recorded lectures, notes and mock tests.'),

((SELECT id FROM course_categories WHERE slug='ssc'), 3,
 'SSC CGL 2025', 'ssc-cgl-2025', 'Complete Preparation',
 'Complete SSC CGL preparation covering Quant, Reasoning, English and General Awareness with topic-wise tests.',
 'Prepare for SSC CGL Tier 1 and Tier 2 with concept classes, shortcut techniques, topic-wise practice and full-length mock tests.',
 '10 Months', 300, 'bilingual', 'intermediate', 'hybrid', 7999, 4999,
 JSON_ARRAY('Quantitative Aptitude from basics','Reasoning shortcuts','English grammar & vocabulary','General Awareness & current affairs','Full-length mock tests'),
 JSON_ARRAY('Live + recorded classes','Topic-wise PDFs','Doubt support','Mock tests'),
 JSON_ARRAY('Class 10 level mathematics'),
 JSON_ARRAY(JSON_OBJECT('q','Does this cover Tier 2?','a','Yes, both Tier 1 and Tier 2 syllabus are covered.')),
 'Popular', 1, 2, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='banking'), 3,
 'Banking Exam Preparation', 'banking-exam-preparation', 'IBPS | SBI | RBI | Others',
 'Preparation for IBPS, SBI and RBI PO & Clerk exams with interview guidance.',
 'A complete banking exam course covering Reasoning, Quantitative Aptitude, English, Banking Awareness and Computer Knowledge, with sectional tests and interview guidance.',
 '8 Months', 240, 'bilingual', 'intermediate', 'hybrid', 6999, 3999,
 JSON_ARRAY('Prelims and Mains syllabus','Banking & financial awareness','Data interpretation','Interview guidance'),
 JSON_ARRAY('Live + recorded classes','Banking PDFs & notes','Sectional and full mock tests'),
 JSON_ARRAY('Graduation for exam eligibility'),
 JSON_ARRAY(), NULL, 1, 3, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='state-psc'), 4,
 'State PSC Exams', 'state-psc-exams', 'Multiple State PCS',
 'Foundation course for State Public Service Commission exams with state-specific material.',
 'Covers the common General Studies syllabus for state PSC exams along with state-specific history, geography and economy modules.',
 '12 Months', 365, 'hindi', 'beginner', 'hybrid', 8999, 5999,
 JSON_ARRAY('Common GS syllabus','State-specific modules','Answer writing practice'),
 JSON_ARRAY('Live + recorded classes','State specific material','Mock tests'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 1, 4, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='teaching'), 2,
 'Teaching Exam Preparation', 'teaching-exam-preparation', 'CTET | STET | KVS | DSSSB',
 'Child pedagogy, subject knowledge and previous year paper practice for teaching exams.',
 'Prepare for CTET, STET, KVS and DSSSB with child development & pedagogy, language papers, subject modules and previous year paper discussions.',
 '6 Months', 180, 'hindi', 'beginner', 'recorded', 5999, 3499,
 JSON_ARRAY('Child development & pedagogy','Language I & II','Maths and EVS pedagogy','Previous year papers'),
 JSON_ARRAY('Recorded classes','Child pedagogy notes','Practice tests'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 1, 5, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='defence'), 4,
 'NDA / CDS / AFCAT', 'nda-cds-afcat', 'Complete Preparation',
 'Written exam preparation for NDA, CDS and AFCAT with SSB guidance.',
 'Covers Mathematics, General Ability, English and General Knowledge for defence entrance exams, plus an orientation module on the SSB interview process.',
 '8 Months', 240, 'bilingual', 'intermediate', 'hybrid', 7999, 4999,
 JSON_ARRAY('Mathematics','General Ability Test','English','SSB orientation'),
 JSON_ARRAY('Live + recorded classes','SSB guidance','Mock tests'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 1, 6, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='railway'), 3,
 'Railway Exam Preparation', 'railway-exam-preparation', 'RRB NTPC | Group D | ALP',
 'Topic-wise preparation for RRB NTPC, Group D and ALP exams.',
 'Mathematics, General Intelligence & Reasoning, General Awareness and General Science for RRB exams with topic-wise notes and previous year papers.',
 '6 Months', 180, 'hindi', 'beginner', 'hybrid', 6999, 3999,
 JSON_ARRAY('Mathematics','Reasoning','General Science','General Awareness'),
 JSON_ARRAY('Live + recorded classes','Topic-wise notes & PDFs','Previous year papers'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 1, 7, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='police'), 3,
 'Police Constable Exam Preparation', 'police-constable-exam-preparation', 'Delhi Police | State Police',
 'Preparation for police constable and SI written exams.',
 'Reasoning, numerical ability, general knowledge and computer awareness for Delhi Police and state police recruitment exams.',
 '4 Months', 120, 'hindi', 'beginner', 'recorded', 4999, 2999,
 JSON_ARRAY('Reasoning','Numerical ability','General knowledge','Computer awareness'),
 JSON_ARRAY('Recorded classes','Notes','Practice tests'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 0, 8, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='upsc'), 4,
 'Current Affairs Monthly Pack', 'current-affairs-monthly-pack', 'Monthly Compilation',
 'Monthly current affairs compilation for all competitive exams.',
 'A free monthly current affairs series covering national, international, economy, science and government schemes, useful for all competitive exams.',
 'Monthly', NULL, 'bilingual', 'all', 'free', 0, NULL,
 JSON_ARRAY('National & international news','Economy & schemes','Science & technology'),
 JSON_ARRAY('Monthly PDF compilation','Practice questions'),
 JSON_ARRAY(), JSON_ARRAY(), 'Free', 0, 9, 'published', NOW(), 1, NULL, NULL),

((SELECT id FROM course_categories WHERE slug='ssc'), 3,
 'SSC CHSL Preparation', 'ssc-chsl-preparation', 'Tier 1 + Tier 2',
 'Recorded course for SSC CHSL with practice sets.',
 'Recorded lessons for SSC CHSL covering all four sections, with practice sets for every topic.',
 '6 Months', 180, 'bilingual', 'beginner', 'recorded', 4999, 2999,
 JSON_ARRAY('All four sections','Practice sets','Typing test guidance'),
 JSON_ARRAY('Recorded classes','Practice sets'),
 JSON_ARRAY(), JSON_ARRAY(), NULL, 0, 10, 'published', NOW(), 1, NULL, NULL);

-- ------------------------------------------------------------------
-- DEMO curriculum (no private media - URLs are added by admins)
-- ------------------------------------------------------------------
INSERT INTO course_sections (course_id, title, sort_order) VALUES
(1, 'Module 1 - Polity Fundamentals', 1),
(1, 'Module 2 - Economy Basics', 2),
(1, 'Module 3 - Modern History', 3),
(2, 'Quantitative Aptitude', 1),
(2, 'Reasoning', 2),
(3, 'Reasoning Ability', 1),
(9, 'Monthly Compilations', 1);

INSERT INTO lessons (course_id, section_id, title, lesson_type, duration_minutes, is_preview, sort_order) VALUES
(1, 1, 'Introduction to the Indian Constitution', 'video', 42, 1, 1),
(1, 1, 'Constitutional Framework', 'video', 38, 0, 2),
(1, 1, 'Fundamental Rights', 'video', 45, 0, 3),
(1, 1, 'Polity Notes (PDF)', 'pdf', NULL, 0, 4),
(1, 2, 'Basic Concepts of Economy', 'video', 40, 1, 1),
(1, 2, 'National Income', 'video', 44, 0, 2),
(1, 2, 'Money and Banking', 'video', 50, 0, 3),
(1, 3, 'Advent of Europeans', 'video', 46, 0, 1),
(1, 3, 'Revolt of 1857', 'video', 52, 0, 2),
(2, 4, 'Number System', 'video', 55, 1, 1),
(2, 4, 'Percentage', 'video', 48, 0, 2),
(2, 5, 'Coding-Decoding', 'video', 40, 0, 1),
(3, 6, 'Seating Arrangement', 'video', 50, 1, 1),
(9, 7, 'Current Affairs - Overview', 'text', 15, 1, 1);

-- ------------------------------------------------------------------
-- DEMO live classes (dates are relative to the import date)
-- ------------------------------------------------------------------
INSERT INTO live_classes (course_id, category_id, instructor_id, title, description, starts_at, duration_minutes, access_type, status, is_demo) VALUES
(9, 1, 4, 'Current Affairs Analysis', 'Weekly current affairs discussion.', DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 10 HOUR, 60, 'registered', 'scheduled', 1),
(4, 4, 2, 'Modern History Revision', 'Revision session for state PSC aspirants.', DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 14 HOUR, 90, 'enrolled', 'scheduled', 1),
(2, 2, 3, 'Quantitative Aptitude', 'Number system practice session.', DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 17 HOUR, 60, 'enrolled', 'scheduled', 1),
(1, 1, 1, 'Polity Doubt Session', 'Open doubt session on Indian Polity.', DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 19 HOUR, 60, 'enrolled', 'scheduled', 1);

-- ------------------------------------------------------------------
-- Resource categories
-- ------------------------------------------------------------------
INSERT INTO resource_categories (name, slug, subtitle, icon, color, sort_order) VALUES
('Current Affairs Analysis', 'current-affairs',      'Daily & Monthly',     'newspaper', '#DC2626', 1),
('Previous Year Papers',     'previous-year-papers', 'Solved Papers',       'file',      '#7C3AED', 2),
('NCERT Notes',              'ncert-notes',          'Class 6–12',          'book',      '#EA580C', 3),
('Geography Maps',           'geography-maps',       'High Quality Maps',   'globe',     '#0B63F6', 4),
('Daily Quiz',               'daily-quiz',           'Test Your Knowledge', 'help',      '#9333EA', 5),
('Exam Strategy',            'exam-strategy',        'Preparation Tips',    'target',    '#16A34A', 6);

-- ------------------------------------------------------------------
-- DEMO testimonials / success stories - PENDING, therefore not public.
-- They exist only to demonstrate the approval workflow.
-- ------------------------------------------------------------------
INSERT INTO testimonials (type, name, exam_name, exam_year, result_label, content, rating, is_verified, status, is_demo) VALUES
('success_story', 'Demo Student A', 'UPSC', 2024, 'Demo result', 'Demo story - replace with a verified student story.', NULL, 0, 'pending', 1),
('testimonial',   'Demo Student B', 'SSC CGL', NULL, NULL, 'Demo testimonial - replace with genuine student feedback.', 5, 0, 'pending', 1);

-- ------------------------------------------------------------------
-- SEO defaults
-- ------------------------------------------------------------------
INSERT INTO seo_pages (path, title, meta_description, robots) VALUES
('/',        'Laxyam Academy - UPSC, SSC, Banking & Competitive Exam Preparation', 'Build your career with Laxyam Academy. Courses, live classes, mock tests and study material for UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and Police exams.', 'index,follow'),
('/courses', 'Course Catalogue - Laxyam Academy', 'Find the right course for your competitive exam preparation: UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and more.', 'index,follow'),
('/contact', 'Contact Us - Laxyam Academy', 'Contact Laxyam Academy, Dharmveer Market, Badarpur, New Delhi. Call or WhatsApp +91 87005 11018.', 'index,follow');

-- ------------------------------------------------------------------
-- Content pages. Policy texts are DRAFT TEMPLATES - have them reviewed
-- (and fill the bracketed items) before launch.
-- ------------------------------------------------------------------
INSERT INTO cms_sections (section_key, title, content, sort_order) VALUES
('page_about', 'About Us page', JSON_OBJECT(
    'title', 'About Laxyam Academy',
    'intro', 'Laxyam Academy is a competitive exam preparation institute based in Badarpur, New Delhi. We help aspirants prepare for UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and Police examinations through structured courses, live and recorded classes, mock tests and study material.',
    'image', 'assets/images/hero-illustration.svg',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Our Mission', 'icon', 'target', 'body', 'To make quality exam preparation accessible and affordable, with clear guidance at every step - Learn, Prepare, Achieve.'),
        JSON_OBJECT('heading', 'How We Teach', 'icon', 'book', 'body', 'Concept classes build strong fundamentals, regular practice and mock tests measure progress, and doubt sessions keep students on track.'),
        JSON_OBJECT('heading', 'Online and Offline', 'icon', 'video', 'body', 'Students can learn from our centre at Dharmveer Market, Badarpur, or online through live classes, recordings and downloadable material.')
    )
), 20),
('page_privacy', 'Privacy Policy', JSON_OBJECT(
    'title', 'Privacy Policy',
    'intro', 'DRAFT - review with a legal adviser before publishing. This policy explains how Laxyam Academy collects and uses personal information.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Information we collect', 'body', 'Name, email address, phone number and exam preferences you provide when registering or making an enquiry; course progress and test attempts; payment status and references received from our payment provider. We do not store card or UPI credentials.'),
        JSON_OBJECT('heading', 'How we use it', 'body', 'To provide courses and tests, process enrolments and payments, send service messages such as class reminders and receipts, respond to enquiries, and improve our services.'),
        JSON_OBJECT('heading', 'Sharing', 'body', 'We share information only with service providers needed to run the platform (for example the payment gateway and email provider) or when required by law.'),
        JSON_OBJECT('heading', 'Your choices', 'body', 'You can update your profile and notification preferences from your dashboard and request deletion of your account. Contact info@laxyamacademy.com for any privacy request.'),
        JSON_OBJECT('heading', 'Cookies', 'body', 'We use an essential session cookie to keep you signed in and protect forms. We do not use advertising cookies.')
    )
), 21),
('page_terms', 'Terms & Conditions', JSON_OBJECT(
    'title', 'Terms & Conditions',
    'intro', 'DRAFT - review with a legal adviser before publishing.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Accounts', 'body', 'Keep your login details confidential. Accounts are personal and must not be shared; we may suspend accounts that are shared or misused.'),
        JSON_OBJECT('heading', 'Course access', 'body', 'Access to a course is granted after successful payment and is valid for the period shown on the course page.'),
        JSON_OBJECT('heading', 'Content', 'body', 'Videos, notes, tests and other material are for your personal study only and may not be copied, recorded, resold or distributed.'),
        JSON_OBJECT('heading', 'Live classes', 'body', 'Schedules may change; rescheduled or cancelled classes will be announced on the website and in your dashboard.'),
        JSON_OBJECT('heading', 'Governing law', 'body', 'These terms are governed by the laws of India. [Jurisdiction to be confirmed by the academy.]')
    )
), 22),
('page_refund', 'Refund Policy', JSON_OBJECT(
    'title', 'Refund Policy',
    'intro', 'DRAFT - the academy must confirm the refund terms below before publishing.',
    'sections', JSON_ARRAY(
        JSON_OBJECT('heading', 'Eligibility', 'body', '[To be confirmed by the academy: refund window in days and conditions, for example how much of the course may have been accessed.]'),
        JSON_OBJECT('heading', 'How to request', 'body', 'Email info@laxyamacademy.com or call +91 87005 11018 with your order number.'),
        JSON_OBJECT('heading', 'Processing', 'body', 'Approved refunds are returned to the original payment method. Banks usually take 5-7 working days to credit the amount.'),
        JSON_OBJECT('heading', 'Duplicate payments', 'body', 'If you are charged twice for the same order, the duplicate amount will be refunded in full.')
    )
), 23);

-- ------------------------------------------------------------------
-- DEMO lesson content
-- ------------------------------------------------------------------
UPDATE lessons SET content = '<p>Welcome to the monthly current affairs series. Each month covers:</p><ul><li>National and international events</li><li>Economy, budget and government schemes</li><li>Science and technology</li><li>Awards, appointments and important days</li></ul><p>Revise each compilation twice and attempt the practice questions at the end of the month.</p>' WHERE id = 14;

-- ------------------------------------------------------------------
-- DEMO mock tests (questions are factual; tests are samples)
-- ------------------------------------------------------------------
INSERT INTO exams (category_id, course_id, title, slug, description, instructions, duration_minutes, total_marks, pass_marks, negative_marking, max_attempts, access_type, allow_multiple_correct, result_release, show_explanations, starts_at, ends_at, status, is_demo) VALUES
(1, NULL, 'General Studies Practice Quiz', 'general-studies-practice-quiz', 'A short practice quiz on Polity, Geography, Economy and Arithmetic.',
 'This quiz has 5 questions and lasts 10 minutes.\n\nEach correct answer carries 2 marks and 0.5 marks are deducted for each wrong answer. Unanswered questions carry no marks.\n\nThe test is submitted automatically when the timer ends.', 10, 10, 6, 1, 3, 'registered', 0, 'immediate', 1, NULL, NULL, 'published', 1),
(1, 1, 'UPSC Polity Sectional Test', 'upsc-polity-sectional-test', 'Sectional test for students of the UPSC Foundation course.',
 'This test has 3 questions and lasts 10 minutes.\n\nQuestion 3 may have more than one correct answer - select all that apply.\n\nNo negative marking.', 10, 6, NULL, 0, 2, 'enrolled', 1, 'immediate', 1, NULL, NULL, 'published', 1),
(2, NULL, 'SSC Quant & Reasoning Mini Test', 'ssc-quant-reasoning-mini-test', 'Scheduled mini test on arithmetic and number series.',
 'This test has 2 questions and lasts 5 minutes. Results are released after the test window closes.', 5, 4, NULL, 0, 1, 'registered', 0, 'after_end', 1,
 DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL 10 HOUR, DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL 22 HOUR, 'published', 1);

INSERT INTO exam_questions (exam_id, question_type, question_text, explanation, marks, negative_marks, sort_order) VALUES
(1, 'single', 'Who was the Chairman of the Drafting Committee of the Constituent Assembly?', 'Dr B. R. Ambedkar chaired the Drafting Committee and is widely called the chief architect of the Indian Constitution.', 2, 0.5, 1),
(1, 'single', 'Article 21 of the Constitution of India deals with:', 'Article 21 guarantees the protection of life and personal liberty.', 2, 0.5, 2),
(1, 'true_false', 'The Tropic of Cancer passes through Rajasthan.', 'True. The Tropic of Cancer passes through eight states including Gujarat, Rajasthan and Madhya Pradesh.', 2, 0.5, 3),
(1, 'single', 'What is 15% of 240?', '15% of 240 = 0.15 x 240 = 36.', 2, 0.5, 4),
(1, 'single', 'In which year was the Reserve Bank of India established?', 'The RBI was established on 1 April 1935 under the RBI Act, 1934.', 2, 0.5, 5),
(2, 'single', 'The Preamble of the Indian Constitution was amended by which Constitutional Amendment Act?', 'The 42nd Amendment Act (1976) added the words Socialist, Secular and Integrity.', 2, 0, 1),
(2, 'single', 'Which Article deals with President''s Rule (failure of constitutional machinery) in a State?', 'Article 356 provides for President''s Rule in a State.', 2, 0, 2),
(2, 'multiple', 'Which of the following are Fundamental Rights under Part III of the Constitution? (Select all that apply)', 'Right to Equality and Right against Exploitation are Fundamental Rights. The Right to Property was removed from Part III by the 44th Amendment (1978), and voting is a constitutional/statutory right, not a Fundamental Right.', 2, 0, 3),
(3, 'single', 'A train covers 120 km in 2 hours. What is its average speed?', 'Speed = distance / time = 120 / 2 = 60 km/h.', 2, 0, 1),
(3, 'single', 'Find the next number: 2, 6, 12, 20, ?', 'Differences are 4, 6, 8, so the next difference is 10: 20 + 10 = 30.', 2, 0, 2);

INSERT INTO exam_options (question_id, option_text, is_correct, sort_order) VALUES
(1, 'Jawaharlal Nehru', 0, 1), (1, 'Dr B. R. Ambedkar', 1, 2), (1, 'Dr Rajendra Prasad', 0, 3), (1, 'Sardar Vallabhbhai Patel', 0, 4),
(2, 'Right to equality before law', 0, 1), (2, 'Protection of life and personal liberty', 1, 2), (2, 'Right to constitutional remedies', 0, 3), (2, 'Freedom of religion', 0, 4),
(3, 'True', 1, 1), (3, 'False', 0, 2),
(4, '32', 0, 1), (4, '36', 1, 2), (4, '40', 0, 3), (4, '30', 0, 4),
(5, '1935', 1, 1), (5, '1947', 0, 2), (5, '1949', 0, 3), (5, '1950', 0, 4),
(6, '24th Amendment Act', 0, 1), (6, '42nd Amendment Act', 1, 2), (6, '44th Amendment Act', 0, 3), (6, '73rd Amendment Act', 0, 4),
(7, 'Article 352', 0, 1), (7, 'Article 356', 1, 2), (7, 'Article 360', 0, 3), (7, 'Article 370', 0, 4),
(8, 'Right to Equality', 1, 1), (8, 'Right against Exploitation', 1, 2), (8, 'Right to Property', 0, 3), (8, 'Right to Vote', 0, 4),
(9, '40 km/h', 0, 1), (9, '60 km/h', 1, 2), (9, '80 km/h', 0, 3), (9, '240 km/h', 0, 4),
(10, '28', 0, 1), (10, '30', 1, 2), (10, '32', 0, 3), (10, '26', 0, 4);

-- ------------------------------------------------------------------
-- Learning resources: genuine official sources (external links) + demo items
-- ------------------------------------------------------------------
INSERT INTO learning_resources (category_id, course_id, title, slug, description, file_type, external_url, access_type, status, published_at, is_demo) VALUES
((SELECT id FROM resource_categories WHERE slug='ncert-notes'), NULL, 'NCERT Textbooks (Official, Free)', 'ncert-textbooks-official', 'Official NCERT textbooks for Classes 6-12, free to read online on the NCERT website. NCERTs are the foundation for UPSC and State PSC preparation.', 'link', 'https://ncert.nic.in/textbook.php', 'free', 'published', NOW(), 0),
((SELECT id FROM resource_categories WHERE slug='previous-year-papers'), NULL, 'UPSC Previous Year Question Papers (Official)', 'upsc-previous-year-papers-official', 'Previous year question papers published by the Union Public Service Commission.', 'link', 'https://upsc.gov.in/examinations/previous-question-papers', 'free', 'published', NOW(), 0),
((SELECT id FROM resource_categories WHERE slug='current-affairs'), NULL, 'Press Information Bureau (PIB) Releases', 'pib-press-releases', 'Official Government of India press releases - a primary source for current affairs on schemes, policies and appointments.', 'link', 'https://pib.gov.in', 'free', 'published', NOW(), 0),
((SELECT id FROM resource_categories WHERE slug='exam-strategy'), NULL, 'SSC Official Website - Notices & Exam Calendar', 'ssc-official-notices', 'Official notices, exam calendar and syllabus from the Staff Selection Commission.', 'link', 'https://ssc.gov.in', 'free', 'published', NOW(), 0),
((SELECT id FROM resource_categories WHERE slug='geography-maps'), NULL, 'India Physical Map Practice Sheet', 'india-physical-map-practice-sheet', 'Demo item for registered students. Upload the PDF from the admin panel to make it downloadable.', 'pdf', NULL, 'registered', 'published', NOW(), 1),
((SELECT id FROM resource_categories WHERE slug='current-affairs'), 1, 'UPSC Foundation - Polity Notes', 'upsc-foundation-polity-notes', 'Demo item available only to students enrolled in UPSC Foundation 2027. Upload the PDF from the admin panel.', 'pdf', NULL, 'enrolled', 'published', NOW(), 1);

-- ------------------------------------------------------------------
-- DEMO coupon and blog
-- ------------------------------------------------------------------
INSERT INTO coupons (code, description, discount_type, discount_value, max_discount, min_order_amount, usage_limit, per_user_limit, applies_to, is_active) VALUES
('WELCOME10', 'Demo coupon: 10% off (max ₹1,000)', 'percent', 10, 1000, 0, 100, 1, 'all', 1);

INSERT INTO blog_categories (name, slug, sort_order) VALUES
('Exam Strategy', 'exam-strategy', 1), ('UPSC', 'upsc', 2), ('SSC', 'ssc', 3);

INSERT INTO blog_posts (category_id, title, slug, excerpt, content, tags, status, published_at, seo_description, is_demo) VALUES
(2, 'How to Start UPSC Preparation from Scratch', 'how-to-start-upsc-preparation', 'A practical first-month plan for beginners: understand the syllabus, build an NCERT base and start reading the news.',
 '<p>Starting UPSC preparation can feel overwhelming. Break it into clear steps.</p><h2>1. Read the official syllabus</h2><p>Print the Prelims and Mains syllabus from the UPSC website and keep it on your desk. Every topic you study should map to a line in the syllabus.</p><h2>2. Build the NCERT base</h2><p>Read the NCERT books for History, Geography, Polity and Economy from Class 6 to 12. Make short notes as you go.</p><h2>3. Follow the news daily</h2><p>Read one national newspaper and note issues linked to the syllabus.</p><h2>4. Practise from the start</h2><p>Solve previous year questions topic by topic to understand what UPSC asks.</p>',
 'UPSC, Beginners, NCERT', 'published', NOW() - INTERVAL 6 DAY, 'A step-by-step plan for starting UPSC preparation from scratch.', 1),
(3, 'SSC CGL Preparation Strategy: Section by Section', 'ssc-cgl-preparation-strategy', 'How to divide your time between Quant, Reasoning, English and General Awareness for SSC CGL.',
 '<p>SSC CGL rewards speed and accuracy. Plan each section separately.</p><h2>Quantitative Aptitude</h2><p>Master arithmetic first - percentages, ratio, profit and loss, time and work - before advanced maths.</p><h2>Reasoning</h2><p>Practise a fixed number of questions daily to build speed.</p><h2>English</h2><p>Revise grammar rules and learn vocabulary in context.</p><h2>General Awareness</h2><p>Combine static GK with monthly current affairs revision.</p>',
 'SSC, CGL, Strategy', 'published', NOW() - INTERVAL 3 DAY, 'A section-wise SSC CGL preparation strategy.', 1),
(1, 'Making the Most of Mock Tests', 'making-the-most-of-mock-tests', 'Mock tests are only useful if you analyse them. Here is a simple post-test routine.',
 '<p>Taking a mock test is half the work; analysing it is the other half.</p><ul><li>Review every wrong and skipped question.</li><li>Note the topic and the reason for each mistake: concept, calculation or time.</li><li>Revise those topics before the next test.</li><li>Track your accuracy and time per section across tests.</li></ul>',
 'Mock Tests, Strategy', 'published', NOW() - INTERVAL 1 DAY, 'How to analyse mock tests to improve your score.', 1);

INSERT INTO faqs (category, question, answer, sort_order) VALUES
('general', 'How do I enrol in a course?', 'Open the course page, click Enroll Now and complete the checkout. Access is granted as soon as the payment is verified.', 1),
('general', 'Can I study in Hindi?', 'Many courses are bilingual. The language is shown on every course page.', 2);
