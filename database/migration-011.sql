-- ==================================================================
-- Migration 011: Bilingual questions (English + Hindi) and test sections
-- Every question, option and explanation can carry a Hindi version; students switch language
-- inside the test. Questions can be grouped into sections (Quant, Reasoning, English ...), and a
-- mock test can give each section its own timer (sectional timing, IBPS / SSC CGL style).
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS exam_sections (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(80) NOT NULL,
    title_hi         VARCHAR(80) NULL,
    duration_minutes SMALLINT UNSIGNED NULL COMMENT 'own timer when the test uses sectional timing',
    sort_order       SMALLINT NOT NULL DEFAULT 0,
    KEY idx_es_exam (exam_id, sort_order),
    CONSTRAINT fk_es_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exam_questions
    ADD COLUMN section_id INT UNSIGNED NULL AFTER exam_id,
    ADD COLUMN question_text_hi TEXT NULL AFTER question_text,
    ADD COLUMN explanation_hi TEXT NULL AFTER explanation,
    ADD KEY idx_eq_section (section_id),
    ADD CONSTRAINT fk_eq_section FOREIGN KEY (section_id) REFERENCES exam_sections(id) ON DELETE SET NULL;

ALTER TABLE exam_options
    ADD COLUMN option_text_hi TEXT NULL AFTER option_text;

ALTER TABLE exams
    ADD COLUMN sectional_timing TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = each section has its own timer' AFTER duration_minutes;

ALTER TABLE exam_attempts
    ADD COLUMN section_plan LONGTEXT NULL COMMENT 'JSON: the sections of this attempt, their timers and early finishes' AFTER option_order;

INSERT INTO schema_migrations (version, description) VALUES (11, 'Bilingual questions and test sections with their own timers');
