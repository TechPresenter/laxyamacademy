-- ==================================================================
-- Migration 003: student panel - wishlist, study preferences, notification preferences
-- ==================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wishlists (
    user_id    INT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, course_id),
    CONSTRAINT fk_wish_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS preferred_language ENUM('english','hindi') NOT NULL DEFAULT 'english' AFTER exam_preferences,
    ADD COLUMN IF NOT EXISTS study_goal VARCHAR(60) NULL AFTER preferred_language,
    ADD COLUMN IF NOT EXISTS notify_prefs JSON NULL COMMENT 'course_updates, test_results, offers, announcements, study_tips' AFTER notify_class_reminders;

INSERT INTO schema_migrations (version, description) VALUES (3, 'Student panel: wishlist, study and notification preferences');
