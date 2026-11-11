-- ==================================================================
-- Laxyam Academy - MySQL schema (InnoDB, utf8mb4)
-- Schema version: 1  (see schema_migrations + docs/DATABASE.md)
--
-- Date/time convention: DATETIME columns store Asia/Kolkata local time.
-- db.php sets the MySQL session time_zone to +05:30 so NOW() matches PHP.
-- `is_demo = 1` marks seed/demo rows that must be replaced before launch.
-- ==================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- Versioning
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    version     INT UNSIGNED NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Users, roles and permissions
-- ------------------------------------------------------------------
CREATE TABLE users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(120) NOT NULL,
    email                 VARCHAR(190) NOT NULL,
    phone                 VARCHAR(20) NULL,
    password_hash         VARCHAR(255) NOT NULL,
    user_type             ENUM('student','staff') NOT NULL DEFAULT 'student',
    status                ENUM('pending','active','inactive','deleted') NOT NULL DEFAULT 'active',
    avatar_path           VARCHAR(255) NULL,
    email_verified_at     DATETIME NULL,
    last_login_at         DATETIME NULL,
    last_login_ip         VARCHAR(45) NULL,
    deletion_requested_at DATETIME NULL,
    is_demo               TINYINT(1) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_type_status (user_type, status),
    KEY idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(60) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id     SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug   VARCHAR(80) NOT NULL,
    name   VARCHAR(120) NOT NULL,
    module VARCHAR(60) NOT NULL,
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id     INT UNSIGNED NOT NULL,
    role_id     SMALLINT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ur_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_profiles (
    user_id              INT UNSIGNED NOT NULL PRIMARY KEY,
    date_of_birth        DATE NULL,
    gender               ENUM('male','female','other','undisclosed') NULL,
    city                 VARCHAR(100) NULL,
    state                VARCHAR(100) NULL,
    exam_preferences     JSON NULL,
    notify_email         TINYINT(1) NOT NULL DEFAULT 1,
    notify_sms           TINYINT(1) NOT NULL DEFAULT 0,
    notify_class_reminders TINYINT(1) NOT NULL DEFAULT 1,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    session_hash    CHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      DATETIME NULL,
    UNIQUE KEY uq_sessions_hash (session_hash),
    KEY idx_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope           ENUM('student','admin') NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    user_id         INT UNSIGNED NULL,
    ip_address      VARCHAR(45) NOT NULL,
    user_agent      VARCHAR(255) NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_ident (scope, identifier_hash, created_at),
    KEY idx_la_ip (ip_address, created_at),
    KEY idx_la_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic sliding-window rate limiter (enquiries, password resets, ...)
CREATE TABLE rate_limit_hits (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket     VARCHAR(60) NOT NULL,
    key_hash   CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rl (bucket, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prt_hash (token_hash),
    KEY idx_prt_user (user_id),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_verification_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evt_hash (token_hash),
    KEY idx_evt_user (user_id),
    CONSTRAINT fk_evt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Catalogue
-- ------------------------------------------------------------------
CREATE TABLE instructors (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NULL,
    name             VARCHAR(120) NOT NULL,
    slug             VARCHAR(140) NOT NULL,
    designation      VARCHAR(160) NULL,
    bio              TEXT NULL,
    photo_path       VARCHAR(255) NULL,
    experience_years TINYINT UNSIGNED NULL,
    highlights       JSON NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_demo          TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_instructors_slug (slug),
    CONSTRAINT fk_instructors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_categories (
    id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id       SMALLINT UNSIGNED NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    short_name      VARCHAR(30) NULL,
    icon            VARCHAR(40) NULL,
    color           VARCHAR(7) NULL,
    description     TEXT NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    seo_title       VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cc_slug (slug),
    KEY idx_cc_active (is_active, sort_order),
    CONSTRAINT fk_cc_parent FOREIGN KEY (parent_id) REFERENCES course_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE courses (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id            SMALLINT UNSIGNED NOT NULL,
    instructor_id          INT UNSIGNED NULL,
    prerequisite_course_id INT UNSIGNED NULL,
    title                  VARCHAR(180) NOT NULL,
    slug                   VARCHAR(200) NOT NULL,
    subtitle               VARCHAR(200) NULL,
    short_description      VARCHAR(500) NULL,
    description            MEDIUMTEXT NULL,
    thumbnail_path         VARCHAR(255) NULL,
    promo_video_url        VARCHAR(500) NULL,
    duration_label         VARCHAR(60) NULL,
    validity_days          SMALLINT UNSIGNED NULL COMMENT 'NULL = lifetime access',
    language               ENUM('hindi','english','bilingual') NOT NULL DEFAULT 'bilingual',
    level                  ENUM('beginner','intermediate','advanced','all') NOT NULL DEFAULT 'all',
    course_type            ENUM('free','paid','recorded','live','hybrid') NOT NULL DEFAULT 'paid',
    price                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_price         DECIMAL(10,2) NULL,
    learning_outcomes      JSON NULL,
    includes               JSON NULL,
    requirements           JSON NULL,
    faqs                   JSON NULL,
    badge                  VARCHAR(40) NULL,
    is_featured            TINYINT(1) NOT NULL DEFAULT 0,
    sort_order             SMALLINT NOT NULL DEFAULT 0,
    status                 ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    rating_avg             DECIMAL(3,2) NOT NULL DEFAULT 0.00 COMMENT 'cached from approved course_reviews',
    rating_count           INT UNSIGNED NOT NULL DEFAULT 0,
    seo_title              VARCHAR(160) NULL,
    seo_description        VARCHAR(320) NULL,
    published_at           DATETIME NULL,
    is_demo                TINYINT(1) NOT NULL DEFAULT 0,
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_courses_slug (slug),
    KEY idx_courses_listing (status, category_id, sort_order),
    KEY idx_courses_featured (status, is_featured, sort_order),
    KEY idx_courses_price (status, price),
    FULLTEXT KEY ft_courses_search (title, subtitle, short_description),
    CONSTRAINT fk_courses_category FOREIGN KEY (category_id) REFERENCES course_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_courses_instructor FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL,
    CONSTRAINT fk_courses_prereq FOREIGN KEY (prerequisite_course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_courses_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_courses_price CHECK (price >= 0 AND (discount_price IS NULL OR discount_price >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_sections (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(180) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    KEY idx_sections_course (course_id, sort_order),
    CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lessons (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id        INT UNSIGNED NOT NULL,
    section_id       INT UNSIGNED NOT NULL,
    title            VARCHAR(200) NOT NULL,
    lesson_type      ENUM('video','text','pdf','external_video','live','quiz') NOT NULL DEFAULT 'video',
    content          MEDIUMTEXT NULL COMMENT 'text lesson body (sanitised HTML)',
    video_path       VARCHAR(255) NULL COMMENT 'private storage path - never sent to unauthorised users',
    external_url     VARCHAR(500) NULL COMMENT 'private - never sent to unauthorised users',
    file_path        VARCHAR(255) NULL COMMENT 'private storage path',
    duration_minutes SMALLINT UNSIGNED NULL,
    is_preview       TINYINT(1) NOT NULL DEFAULT 0,
    is_downloadable  TINYINT(1) NOT NULL DEFAULT 0,
    exam_id          INT UNSIGNED NULL,
    live_class_id    INT UNSIGNED NULL,
    sort_order       SMALLINT NOT NULL DEFAULT 0,
    status           ENUM('draft','published') NOT NULL DEFAULT 'published',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lessons_section (section_id, sort_order),
    KEY idx_lessons_course (course_id, status),
    CONSTRAINT fk_lessons_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_lessons_section FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_lessons_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL,
    CONSTRAINT fk_lessons_live FOREIGN KEY (live_class_id) REFERENCES live_classes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_reviews (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    review      TEXT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review (course_id, user_id),
    KEY idx_reviews_course (course_id, status),
    CONSTRAINT fk_reviews_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Enrolments and progress
-- ------------------------------------------------------------------
CREATE TABLE course_enrolments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    course_id        INT UNSIGNED NOT NULL,
    order_id         INT UNSIGNED NULL,
    source           ENUM('free','purchase','admin') NOT NULL,
    status           ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    enrolled_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at       DATETIME NULL,
    completed_at     DATETIME NULL,
    granted_by       INT UNSIGNED NULL,
    UNIQUE KEY uq_enrolment (user_id, course_id),
    KEY idx_enrol_course (course_id, status),
    CONSTRAINT fk_enrol_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrol_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrol_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_enrol_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_progress (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL,
    course_id             INT UNSIGNED NOT NULL,
    lesson_id             INT UNSIGNED NOT NULL,
    status                ENUM('started','completed') NOT NULL DEFAULT 'started',
    last_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at          DATETIME NULL,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_progress (user_id, lesson_id),
    KEY idx_progress_course (user_id, course_id),
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    course_id      INT UNSIGNED NOT NULL,
    certificate_no VARCHAR(40) NOT NULL,
    issued_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    issued_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_cert_no (certificate_no),
    UNIQUE KEY uq_cert_user_course (user_id, course_id),
    CONSTRAINT fk_cert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cert_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cert_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Mock tests / examinations
-- ------------------------------------------------------------------
CREATE TABLE exams (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id         SMALLINT UNSIGNED NULL,
    course_id           INT UNSIGNED NULL,
    title               VARCHAR(180) NOT NULL,
    slug                VARCHAR(200) NOT NULL,
    description         TEXT NULL,
    instructions        TEXT NULL,
    duration_minutes    SMALLINT UNSIGNED NOT NULL,
    total_marks         DECIMAL(7,2) NOT NULL DEFAULT 0,
    pass_marks          DECIMAL(7,2) NULL,
    negative_marking    TINYINT(1) NOT NULL DEFAULT 0,
    max_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = unlimited',
    access_type         ENUM('free','registered','enrolled') NOT NULL DEFAULT 'registered',
    allow_multiple_correct TINYINT(1) NOT NULL DEFAULT 0,
    shuffle_questions   TINYINT(1) NOT NULL DEFAULT 0,
    result_release      ENUM('immediate','after_end','manual') NOT NULL DEFAULT 'immediate',
    results_released_at DATETIME NULL,
    show_explanations   TINYINT(1) NOT NULL DEFAULT 1,
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    status              ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    is_demo             TINYINT(1) NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exams_slug (slug),
    KEY idx_exams_listing (status, category_id),
    KEY idx_exams_course (course_id),
    CONSTRAINT fk_exams_category FOREIGN KEY (category_id) REFERENCES course_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_questions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id        INT UNSIGNED NOT NULL,
    question_type  ENUM('single','true_false','multiple') NOT NULL DEFAULT 'single',
    question_text  TEXT NOT NULL,
    explanation    TEXT NULL,
    marks          DECIMAL(5,2) NOT NULL DEFAULT 1,
    negative_marks DECIMAL(5,2) NOT NULL DEFAULT 0,
    sort_order     SMALLINT NOT NULL DEFAULT 0,
    KEY idx_eq_exam (exam_id, sort_order),
    CONSTRAINT fk_eq_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_options (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    option_text TEXT NOT NULL,
    is_correct  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    KEY idx_eo_question (question_id, sort_order),
    CONSTRAINT fk_eo_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_attempts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id          INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    attempt_no       TINYINT UNSIGNED NOT NULL,
    status           ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
    question_order   JSON NULL,
    started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at       DATETIME NOT NULL,
    submitted_at     DATETIME NULL,
    score            DECIMAL(7,2) NULL,
    correct_count    SMALLINT UNSIGNED NULL,
    wrong_count      SMALLINT UNSIGNED NULL,
    unanswered_count SMALLINT UNSIGNED NULL,
    ip_address       VARCHAR(45) NULL,
    UNIQUE KEY uq_attempt (exam_id, user_id, attempt_no),
    KEY idx_attempts_user (user_id, status),
    CONSTRAINT fk_attempts_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_answers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id          INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    selected_option_ids JSON NULL,
    is_marked_review    TINYINT(1) NOT NULL DEFAULT 0,
    is_correct          TINYINT(1) NULL,
    marks_awarded       DECIMAL(5,2) NULL,
    answered_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_answer (attempt_id, question_id),
    CONSTRAINT fk_answers_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Live classes
-- ------------------------------------------------------------------
CREATE TABLE live_classes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id        INT UNSIGNED NULL,
    category_id      SMALLINT UNSIGNED NULL,
    instructor_id    INT UNSIGNED NULL,
    title            VARCHAR(180) NOT NULL,
    description      TEXT NULL,
    starts_at        DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    meeting_provider VARCHAR(40) NULL,
    meeting_url      VARCHAR(500) NULL COMMENT 'private - only released to eligible users',
    access_type      ENUM('public','registered','enrolled') NOT NULL DEFAULT 'enrolled',
    status           ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
    recording_url    VARCHAR(500) NULL,
    announcement     VARCHAR(500) NULL,
    rescheduled_from DATETIME NULL,
    is_published     TINYINT(1) NOT NULL DEFAULT 1,
    is_demo          TINYINT(1) NOT NULL DEFAULT 0,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_live_upcoming (is_published, status, starts_at),
    KEY idx_live_course (course_id),
    CONSTRAINT fk_live_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_live_category FOREIGN KEY (category_id) REFERENCES course_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_live_instructor FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL,
    CONSTRAINT fk_live_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_class_reminders (
    user_id       INT UNSIGNED NOT NULL,
    live_class_id INT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at   DATETIME NULL,
    PRIMARY KEY (user_id, live_class_id),
    CONSTRAINT fk_lcr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lcr_class FOREIGN KEY (live_class_id) REFERENCES live_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_class_attendance (
    user_id       INT UNSIGNED NOT NULL,
    live_class_id INT UNSIGNED NOT NULL,
    joined_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, live_class_id),
    CONSTRAINT fk_lca_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lca_class FOREIGN KEY (live_class_id) REFERENCES live_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Learning resources
-- ------------------------------------------------------------------
CREATE TABLE resource_categories (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    subtitle    VARCHAR(120) NULL,
    icon        VARCHAR(40) NULL,
    color       VARCHAR(7) NULL,
    description TEXT NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_rc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_resources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     SMALLINT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NULL,
    title           VARCHAR(200) NOT NULL,
    slug            VARCHAR(220) NOT NULL,
    description     TEXT NULL,
    thumbnail_path  VARCHAR(255) NULL,
    file_path       VARCHAR(255) NULL COMMENT 'relative to storage/private - served via authorised endpoint',
    file_type       VARCHAR(20) NULL,
    file_size       INT UNSIGNED NULL,
    external_url    VARCHAR(500) NULL,
    access_type     ENUM('free','registered','enrolled') NOT NULL DEFAULT 'free',
    download_count  INT UNSIGNED NOT NULL DEFAULT 0,
    seo_title       VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at    DATETIME NULL,
    is_demo         TINYINT(1) NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lr_slug (slug),
    KEY idx_lr_listing (status, category_id, published_at),
    FULLTEXT KEY ft_lr_search (title, description),
    CONSTRAINT fk_lr_category FOREIGN KEY (category_id) REFERENCES resource_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lr_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_lr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_downloads (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id   INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NULL,
    ip_hash       CHAR(64) NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rd_resource (resource_id, downloaded_at),
    CONSTRAINT fk_rd_resource FOREIGN KEY (resource_id) REFERENCES learning_resources(id) ON DELETE CASCADE,
    CONSTRAINT fk_rd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Commerce
-- ------------------------------------------------------------------
CREATE TABLE coupons (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(40) NOT NULL,
    description      VARCHAR(255) NULL,
    discount_type    ENUM('percent','fixed') NOT NULL,
    discount_value   DECIMAL(10,2) NOT NULL,
    max_discount     DECIMAL(10,2) NULL,
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    starts_at        DATETIME NULL,
    ends_at          DATETIME NULL,
    usage_limit      INT UNSIGNED NULL,
    per_user_limit   INT UNSIGNED NOT NULL DEFAULT 1,
    used_count       INT UNSIGNED NOT NULL DEFAULT 0,
    applies_to       ENUM('all','selected') NOT NULL DEFAULT 'all',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupons_code (code),
    CONSTRAINT fk_coupons_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_coupon_value CHECK (discount_value > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_courses (
    coupon_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (coupon_id, course_id),
    CONSTRAINT fk_cpc_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_cpc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number      VARCHAR(30) NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    status            ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    subtotal          DECIMAL(10,2) NOT NULL,
    discount          DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax               DECIMAL(10,2) NOT NULL DEFAULT 0,
    total             DECIMAL(10,2) NOT NULL,
    currency          CHAR(3) NOT NULL DEFAULT 'INR',
    coupon_id         INT UNSIGNED NULL,
    provider          VARCHAR(20) NULL,
    provider_order_id VARCHAR(100) NULL,
    paid_at           DATETIME NULL,
    ip_address        VARCHAR(45) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orders_number (order_number),
    UNIQUE KEY uq_orders_provider (provider, provider_order_id),
    KEY idx_orders_user (user_id, status),
    KEY idx_orders_status (status, created_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
    CONSTRAINT chk_orders_total CHECK (total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id   INT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    title      VARCHAR(180) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    discount   DECIMAL(10,2) NOT NULL DEFAULT 0,
    total      DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_order_course (order_id, course_id),
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    order_id    INT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_redemption_order (order_id),
    KEY idx_redemption_user (coupon_id, user_id),
    CONSTRAINT fk_cr_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_transactions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED NOT NULL,
    provider            VARCHAR(20) NOT NULL,
    provider_order_id   VARCHAR(100) NULL,
    provider_payment_id VARCHAR(100) NULL,
    amount              DECIMAL(10,2) NOT NULL,
    currency            CHAR(3) NOT NULL DEFAULT 'INR',
    status              ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
    method              VARCHAR(40) NULL,
    verified            TINYINT(1) NOT NULL DEFAULT 0,
    verified_via        ENUM('signature','webhook','api') NULL,
    error_code          VARCHAR(80) NULL,
    error_description   VARCHAR(255) NULL,
    raw_payload         JSON NULL COMMENT 'provider response without secrets',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pt_payment (provider, provider_payment_id),
    KEY idx_pt_order (order_id),
    CONSTRAINT fk_pt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook idempotency: each provider event is processed at most once
CREATE TABLE webhook_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider        VARCHAR(20) NOT NULL,
    event_id        VARCHAR(120) NOT NULL,
    event_type      VARCHAR(80) NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
    payload         JSON NULL,
    error           VARCHAR(255) NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME NULL,
    UNIQUE KEY uq_webhook_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id       INT UNSIGNED NOT NULL,
    receipt_number VARCHAR(30) NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    issued_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipts_order (order_id),
    UNIQUE KEY uq_receipts_number (receipt_number),
    CONSTRAINT fk_receipts_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id           INT UNSIGNED NOT NULL,
    transaction_id     INT UNSIGNED NULL,
    provider_refund_id VARCHAR(100) NULL,
    amount             DECIMAL(10,2) NOT NULL,
    reason             VARCHAR(255) NULL,
    status             ENUM('requested','processing','processed','failed') NOT NULL DEFAULT 'requested',
    processed_by       INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_refunds_order (order_id),
    CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_tx FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_refunds_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Leads, content and engagement
-- ------------------------------------------------------------------
CREATE TABLE enquiries (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(190) NULL,
    phone        VARCHAR(20) NULL,
    subject      VARCHAR(160) NULL,
    message      TEXT NOT NULL,
    course_id    INT UNSIGNED NULL,
    source       VARCHAR(40) NOT NULL DEFAULT 'contact_form',
    status       ENUM('new','contacted','follow_up','converted','closed','spam') NOT NULL DEFAULT 'new',
    assigned_to  INT UNSIGNED NULL,
    notes        TEXT NULL,
    follow_up_at DATETIME NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_enq_status (status, created_at),
    KEY idx_enq_assigned (assigned_to, status),
    CONSTRAINT fk_enq_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_enq_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_categories (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    description VARCHAR(300) NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_bc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_posts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id         SMALLINT UNSIGNED NULL,
    author_id           INT UNSIGNED NULL,
    title               VARCHAR(200) NOT NULL,
    slug                VARCHAR(220) NOT NULL,
    excerpt             VARCHAR(500) NULL,
    content             MEDIUMTEXT NULL COMMENT 'sanitised HTML',
    featured_image_path VARCHAR(255) NULL,
    tags                VARCHAR(500) NULL COMMENT 'comma separated',
    status              ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft',
    published_at        DATETIME NULL,
    seo_title           VARCHAR(160) NULL,
    seo_description     VARCHAR(320) NULL,
    views               INT UNSIGNED NOT NULL DEFAULT 0,
    is_demo             TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bp_slug (slug),
    KEY idx_bp_listing (status, published_at),
    CONSTRAINT fk_bp_category FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_bp_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Testimonials and success stories share one table and one approval workflow.
CREATE TABLE testimonials (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('testimonial','success_story') NOT NULL DEFAULT 'testimonial',
    user_id     INT UNSIGNED NULL,
    name        VARCHAR(120) NOT NULL,
    photo_path  VARCHAR(255) NULL,
    exam_name   VARCHAR(100) NULL,
    exam_year   SMALLINT UNSIGNED NULL,
    result_label VARCHAR(60) NULL COMMENT 'e.g. AIR 45 - publish only after verification',
    content     TEXT NOT NULL,
    rating      TINYINT UNSIGNED NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'result/identity checked by staff',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_demo     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_testimonials_public (type, status, is_verified, sort_order),
    CONSTRAINT fk_testimonials_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_testimonials_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_testimonial_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(40) NOT NULL,
    title      VARCHAR(160) NOT NULL,
    message    VARCHAR(500) NULL,
    link       VARCHAR(255) NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    subject     VARCHAR(180) NOT NULL,
    category    VARCHAR(40) NOT NULL DEFAULT 'general',
    priority    ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    status      ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open',
    assigned_to INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tickets_status (status, updated_at),
    KEY idx_tickets_user (user_id),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_ticket_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    message     TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'staff-only note',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stm_ticket (ticket_id, created_at),
    CONSTRAINT fk_stm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_stm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category   VARCHAR(40) NOT NULL DEFAULT 'general',
    question   VARCHAR(300) NOT NULL,
    answer     TEXT NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_faqs (category, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Settings, CMS and SEO
-- ------------------------------------------------------------------
CREATE TABLE site_settings (
    setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    setting_group VARCHAR(40) NOT NULL DEFAULT 'general',
    is_public     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = may be sent to the browser',
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Homepage / website blocks. `content` is JSON edited from admin/cms.php.
CREATE TABLE cms_sections (
    section_key VARCHAR(60) NOT NULL PRIMARY KEY,
    title       VARCHAR(120) NOT NULL,
    content     JSON NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cms_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_pages (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path              VARCHAR(255) NOT NULL COMMENT 'e.g. / or /courses',
    title             VARCHAR(160) NULL,
    meta_description  VARCHAR(320) NULL,
    canonical_url     VARCHAR(500) NULL,
    og_title          VARCHAR(160) NULL,
    og_description    VARCHAR(320) NULL,
    og_image          VARCHAR(255) NULL,
    robots            ENUM('index,follow','noindex,follow','noindex,nofollow') NOT NULL DEFAULT 'index,follow',
    structured_data   JSON NULL,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_seo_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redirects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_path   VARCHAR(255) NOT NULL,
    to_path     VARCHAR(500) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    hits        INT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_redirect_from (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Audit trail (never store passwords, tokens or payment secrets here)
-- ------------------------------------------------------------------
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id   VARCHAR(60) NULL,
    details     JSON NULL,
    severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO schema_migrations (version, description) VALUES (1, 'Initial schema');
