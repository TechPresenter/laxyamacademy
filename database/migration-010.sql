-- ==================================================================
-- Migration 010: Course batches by academic session
-- A course can belong to an academic session (e.g. 2027-28, 1 April - 31 March) so that admissions
-- to that batch are counted for the session from the first day of admissions - even before 1 April.
-- Blank = an evergreen course that is not tied to a session.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

ALTER TABLE courses
    ADD COLUMN academic_session VARCHAR(7) NULL COMMENT 'batch session such as 2027-28 (1 Apr - 31 Mar); NULL = evergreen' AFTER status,
    ADD KEY idx_courses_session (academic_session);

INSERT INTO schema_migrations (version, description) VALUES (10, 'Course batches by academic session');
