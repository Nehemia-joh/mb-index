-- ---------------------------------------------------------------------------
-- Maisha Bora Autism Rating Scale : MySQL database schema
-- Import this on cPanel (phpMyAdmin) after creating the database & user.
-- setup.php creates the same schema without manual import.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    email         VARCHAR(190) DEFAULT NULL,
    full_name     VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('therapist','admin','it_admin') NOT NULL DEFAULT 'therapist',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Active login sessions: one row per successful password login.
CREATE TABLE IF NOT EXISTS user_sessions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    session_token    VARCHAR(128) NOT NULL UNIQUE,
    ip_address       VARCHAR(45)  DEFAULT NULL,
    user_agent       VARCHAR(255) DEFAULT NULL,
    current_page     VARCHAR(190) DEFAULT NULL,
    prev_page        VARCHAR(190) DEFAULT NULL,
    page_views       INT UNSIGNED NOT NULL DEFAULT 0,
    login_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logout_at        DATETIME     DEFAULT NULL,
    CONSTRAINT uq_user_sessions_token UNIQUE (session_token),
    KEY idx_user_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail: every page-to-page move and every change (create/update/login/logout/user mgmt).
CREATE TABLE IF NOT EXISTS activity_logs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED DEFAULT NULL,
    user_session_id  INT UNSIGNED DEFAULT NULL,
    username         VARCHAR(60)  DEFAULT NULL,
    role             VARCHAR(20)  DEFAULT NULL,
    action           VARCHAR(60)  NOT NULL,              -- login | logout | page_visit | create_assessment | user_create ...
    page             VARCHAR(190) DEFAULT NULL,          -- route the user was on (clean route, e.g. "admin")
    details          TEXT         DEFAULT NULL,
    ip_address       VARCHAR(45)  DEFAULT NULL,
    user_agent       VARCHAR(255) DEFAULT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_user   (user_id),
    KEY idx_activity_action (action),
    KEY idx_activity_time   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stored Maisha Bora scoring records (one row per scored assessment).
CREATE TABLE IF NOT EXISTS assessments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by       INT UNSIGNED NOT NULL,
    -- Section 1: identifying information
    individual_name  VARCHAR(190) NOT NULL,
    school           VARCHAR(190) NOT NULL,
    rater_name       VARCHAR(190) NOT NULL,
    rater_title      VARCHAR(190) NOT NULL,
    date_of_birth    DATE         DEFAULT NULL,
    age              VARCHAR(20)  NOT NULL,
    gender           VARCHAR(10)  NOT NULL,
    grade            VARCHAR(30)  DEFAULT NULL,
    examiner_name    VARCHAR(190) NOT NULL,
    examiner_title   VARCHAR(190) NOT NULL,
    date_of_rating   DATE         DEFAULT NULL,
    rater_known_for  VARCHAR(60)  DEFAULT NULL,
    is_mute          TINYINT(1)   NOT NULL DEFAULT 0,
    -- Section 2: raw + converted subscale results
    raw_rb           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    raw_si           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    raw_sc           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    raw_er           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    raw_cs           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    raw_ms           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    scaled           VARCHAR(190) NOT NULL,             -- "RB=10|SI=11|SC=9|ER=12|CS=8|MS=9" (4 used when mute)
    percentiles      VARCHAR(190) NOT NULL,             -- "RB=50|SI=63|SC=32|ER=75|CS=25|MS=32"
    sum_scaled       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    composite_percentile VARCHAR(20) NOT NULL,
    autism_index     SMALLINT UNSIGNED NOT NULL,
    probability      VARCHAR(30)  NOT NULL,
    severity_level   VARCHAR(30)  NOT NULL,
    severity_desc    VARCHAR(80)  NOT NULL,
    item_scores      TEXT         DEFAULT NULL,          -- JSON of every item rating
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assess_creator (created_by),
    KEY idx_assess_date    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
