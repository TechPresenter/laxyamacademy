-- ==================================================================
-- Migration 015: Daily quiz and streaks
-- The academy picks one quiz for each day (admin → Tests & Quizzes → Daily Quiz). Students who finish
-- the day's quiz on that day keep their streak going; days without a scheduled quiz never break it.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS daily_quizzes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_date  DATE NOT NULL,
    exam_id    INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daily_date (quiz_date),
    KEY idx_daily_exam (exam_id),
    CONSTRAINT fk_daily_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_daily_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES (15, 'Daily quiz and streaks');
