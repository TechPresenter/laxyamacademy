-- ==================================================================
-- Migration 013: Student doubts
-- Students ask a doubt on a lesson of their course or on a question of a finished mock test
-- (optionally with a photo); teachers answer from admin → Doubts and the student is notified.
-- Plain SQL that runs on MySQL 8 and MariaDB. Apply from admin → System → Database Updates
-- (re-running is safe: "already exists" errors are skipped there).
-- ==================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS doubts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NULL,
    lesson_id      INT UNSIGNED NULL,
    exam_id        INT UNSIGNED NULL,
    question_id    INT UNSIGNED NULL COMMENT 'mock test question the doubt is about',
    attempt_id     INT UNSIGNED NULL,
    subject        VARCHAR(190) NOT NULL COMMENT 'what the doubt is about, e.g. lesson or test question',
    status         ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    assigned_to    INT UNSIGNED NULL,
    student_unread TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a teacher replied and the student has not opened it yet',
    last_reply_at  DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_doubts_user (user_id, updated_at),
    KEY idx_doubts_status (status, last_reply_at),
    KEY idx_doubts_lesson (lesson_id),
    KEY idx_doubts_question (question_id),
    CONSTRAINT fk_doubts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_doubts_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_doubts_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
    CONSTRAINT fk_doubts_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL,
    CONSTRAINT fk_doubts_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE SET NULL,
    CONSTRAINT fk_doubts_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE SET NULL,
    CONSTRAINT fk_doubts_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doubt_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doubt_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    is_staff   TINYINT(1) NOT NULL DEFAULT 0,
    body       TEXT NOT NULL,
    image_path VARCHAR(255) NULL COMMENT 'private file (uploads/private/doubts/...)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dm_doubt (doubt_id, id),
    CONSTRAINT fk_dm_doubt FOREIGN KEY (doubt_id) REFERENCES doubts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, name, module) VALUES ('doubts.manage', 'Answer student doubts', 'courses');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'doubts.manage' WHERE r.slug IN ('super_admin', 'academic_manager', 'support_manager');

INSERT IGNORE INTO notification_templates (template_key, name, subject, body, send_email, send_inapp, send_whatsapp, is_system) VALUES
    ('doubt_answered', 'Doubt answered', 'Your doubt has an answer: {course}', 'Hello {name},\n\nA teacher has answered your doubt about "{course}":\n\n{message}\n\nRead the full answer: {link}', 1, 1, 0, 1);

INSERT INTO schema_migrations (version, description) VALUES (13, 'Student doubts on lessons and test questions');
