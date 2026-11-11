-- ==================================================================
-- Migration 006: Faculty recruitment
-- subject-wise faculty positions (Full-time / Part-time), detailed applications,
-- interview scheduling and an applicant activity timeline.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

-- A faculty position belongs to a subject; employment_type may list several types (e.g. full_time,part_time)
ALTER TABLE job_openings
    ADD COLUMN subject VARCHAR(60) NULL AFTER department,
    ADD KEY idx_job_openings_subject (subject);

ALTER TABLE job_applications
    ADD COLUMN subject VARCHAR(60) NULL AFTER opening_id,
    ADD COLUMN employment_type VARCHAR(30) NULL AFTER subject,
    ADD COLUMN qualifications VARCHAR(500) NULL AFTER phone,
    ADD COLUMN english_level VARCHAR(20) NULL AFTER experience_years,
    ADD COLUMN hindi_level VARCHAR(20) NULL AFTER english_level,
    ADD COLUMN expected_salary VARCHAR(60) NULL AFTER hindi_level,
    ADD COLUMN interview_at DATETIME NULL AFTER status,
    ADD COLUMN interview_mode VARCHAR(20) NULL AFTER interview_at,
    ADD COLUMN interview_location VARCHAR(255) NULL AFTER interview_mode,
    ADD KEY idx_job_applications_subject (subject, status),
    ADD KEY idx_job_applications_interview (interview_at);

-- Activity timeline: status changes, interviews, emails sent, calls and notes
CREATE TABLE IF NOT EXISTS job_application_events (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    type           ENUM('status','interview','email','call','note') NOT NULL,
    summary        VARCHAR(255) NOT NULL,
    body           TEXT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_jae_application (application_id, created_at),
    CONSTRAINT fk_jae_application FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_jae_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subjects (admin → Jobs & Careers → Faculty Subjects)
INSERT IGNORE INTO lookup_values (list_key, value, label, sort_order) VALUES
('faculty_subject', 'quantitative_aptitude', 'Quantitative Aptitude', 1),
('faculty_subject', 'reasoning', 'Reasoning', 2),
('faculty_subject', 'general_studies', 'General Knowledge (GK) / General Studies', 3),
('faculty_subject', 'science_technology', 'Science & Technology', 4),
('faculty_subject', 'english', 'English Language', 5);

-- The five faculty positions (real openings provided by the academy, not demo content)
INSERT IGNORE INTO job_openings (title, slug, department, subject, employment_type, location, experience, description, responsibilities, requirements, status, is_demo) VALUES
('Quantitative Aptitude Faculty', 'quantitative-aptitude-faculty', 'Academics', 'quantitative_aptitude', 'full_time,part_time', 'Badarpur, New Delhi', 'Competitive exam / online teaching experience is an advantage',
 'Teach Quantitative Aptitude to students preparing for competitive exams. Full-time and part-time positions are available.',
 '["Teach Quantitative Aptitude with clear, step-by-step explanations","Explain complex concepts in a simple and understandable manner","Prepare high-quality study notes, practice questions, mock tests and assignments","Clarify students\' doubts and provide academic guidance","Manage students and keep classes engaging"]',
 '["Comfortable communicating in both English and Hindi","Strong subject knowledge with excellent teaching and explanation skills","Good communication, classroom management and student engagement skills","Experience in competitive exam preparation or online teaching is an added advantage"]',
 'open', 0),
('Reasoning Faculty', 'reasoning-faculty', 'Academics', 'reasoning', 'full_time,part_time', 'Badarpur, New Delhi', 'Competitive exam / online teaching experience is an advantage',
 'Teach Reasoning to students preparing for competitive exams. Full-time and part-time positions are available.',
 '["Teach Reasoning with clear, step-by-step explanations","Explain complex concepts in a simple and understandable manner","Prepare high-quality study notes, practice questions, mock tests and assignments","Clarify students\' doubts and provide academic guidance","Manage students and keep classes engaging"]',
 '["Comfortable communicating in both English and Hindi","Strong subject knowledge with excellent teaching and explanation skills","Good communication, classroom management and student engagement skills","Experience in competitive exam preparation or online teaching is an added advantage"]',
 'open', 0),
('General Knowledge (GK) / General Studies Faculty', 'general-studies-faculty', 'Academics', 'general_studies', 'full_time,part_time', 'Badarpur, New Delhi', 'Competitive exam / online teaching experience is an advantage',
 'Teach General Knowledge and General Studies to students preparing for competitive exams. Full-time and part-time positions are available.',
 '["Teach General Knowledge / General Studies with clear explanations","Explain complex concepts in a simple and understandable manner","Prepare high-quality study notes, practice questions, mock tests and assignments","Clarify students\' doubts and provide academic guidance","Manage students and keep classes engaging"]',
 '["Comfortable communicating in both English and Hindi","Strong subject knowledge with excellent teaching and explanation skills","Good communication, classroom management and student engagement skills","Experience in competitive exam preparation or online teaching is an added advantage"]',
 'open', 0),
('Science & Technology Faculty', 'science-technology-faculty', 'Academics', 'science_technology', 'full_time,part_time', 'Badarpur, New Delhi', 'Competitive exam / online teaching experience is an advantage',
 'Teach Science & Technology to students preparing for competitive exams. Full-time and part-time positions are available.',
 '["Teach Science & Technology with clear explanations","Explain complex concepts in a simple and understandable manner","Prepare high-quality study notes, practice questions, mock tests and assignments","Clarify students\' doubts and provide academic guidance","Manage students and keep classes engaging"]',
 '["Comfortable communicating in both English and Hindi","Strong subject knowledge with excellent teaching and explanation skills","Good communication, classroom management and student engagement skills","Experience in competitive exam preparation or online teaching is an added advantage"]',
 'open', 0),
('English Language Faculty', 'english-language-faculty', 'Academics', 'english', 'full_time,part_time', 'Badarpur, New Delhi', 'Competitive exam / online teaching experience is an advantage',
 'Teach English Language to students preparing for competitive exams. Full-time and part-time positions are available.',
 '["Teach English Language (grammar, vocabulary and comprehension) with clear explanations","Explain complex concepts in a simple and understandable manner","Prepare high-quality study notes, practice questions, mock tests and assignments","Clarify students\' doubts and provide academic guidance","Manage students and keep classes engaging"]',
 '["Comfortable communicating in both English and Hindi","Strong subject knowledge with excellent teaching and explanation skills","Good communication, classroom management and student engagement skills","Experience in competitive exam preparation or online teaching is an added advantage"]',
 'open', 0);

-- The installer's demo faculty openings (combined subjects) are replaced by the positions above
UPDATE job_openings SET status = 'closed' WHERE is_demo = 1 AND subject IS NULL AND title LIKE 'Faculty%';

-- Emails to candidates (editable in admin → Notifications → Templates)
INSERT IGNORE INTO notification_templates (template_key, name, subject, body, send_email, send_inapp, is_system) VALUES
('application_shortlisted', 'Job application shortlisted', 'You are shortlisted - {course}', 'Hello {name},\n\nGood news! Your application for {course} at Laxyam Academy has been shortlisted. Our team will contact you soon with the next steps.', 1, 0, 1),
('interview_scheduled', 'Interview scheduled', 'Interview for {course} - {date}', 'Hello {name},\n\nYour interview for {course} at Laxyam Academy is scheduled.\n\nDate and time: {date}\nMode: {mode}\nVenue / link: {location}\n{message}\n\nIf you need a different time, please reply to this email or call us.', 1, 0, 1),
('application_rejected', 'Job application not selected', 'Your application - {course}', 'Hello {name},\n\nThank you for your interest in {course} at Laxyam Academy. After reviewing your application, we will not be moving forward at this time. We wish you all the best.', 1, 0, 1),
('application_hired', 'Job offer', 'Welcome to Laxyam Academy - {course}', 'Hello {name},\n\nCongratulations! We are happy to offer you the position of {course} at Laxyam Academy. Our team will contact you with the joining details.', 1, 0, 1);

INSERT INTO schema_migrations (version, description) VALUES (6, 'Faculty recruitment: subject positions, detailed applications, interviews, applicant timeline');
