-- Migration 004: CMS revision history (previous versions of homepage sections and pages)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cms_revisions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_key VARCHAR(60) NOT NULL,
    content     JSON NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    saved_by    INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rev_section (section_key, created_at),
    CONSTRAINT fk_rev_section FOREIGN KEY (section_key) REFERENCES cms_sections(section_key) ON DELETE CASCADE,
    CONSTRAINT fk_rev_user FOREIGN KEY (saved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES (4, 'CMS revision history');
