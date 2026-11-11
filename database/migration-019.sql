-- ==================================================================
-- Migration 19: Enroll Now on every course
-- Courses get a third admission mode, "both": students can create an account and pay online
-- (Enroll Now) and can still send the Apply Now form for a counsellor call. Every course that
-- took admission through the Apply Now form only (the classroom courses of migration 017) is
-- switched to "both", so the website shows an Enroll Now button on them. Prices, fees and
-- course content are not changed. Change a course back in Admin -> Courses -> Details ->
-- Price & enrolment.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin -> Settings -> Database Updates.
-- ==================================================================
SET NAMES utf8mb4;

ALTER TABLE courses
    MODIFY admission_mode ENUM('online','both','enquiry') NOT NULL DEFAULT 'online'
        COMMENT 'online = Enroll Now (pay online); both = Enroll Now + Apply Now form; enquiry = Apply Now form only';

UPDATE courses SET admission_mode = 'both' WHERE admission_mode = 'enquiry';

INSERT INTO schema_migrations (version, description) VALUES (19, 'Enroll Now on every course');
