-- ---------------------------------------------------------------------------
-- Maisha Bora — production deployment script (run in phpMyAdmin)
-- Adds the scoring-table change-control feature introduced for
-- /scoring-tables (Table A, Table B and interpretation-band editing
-- with a second-admin approval).
--
-- Safe to run more than once (IF NOT EXISTS). No existing rows are touched.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS scoring_revisions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind          ENUM('table_a','table_b','interpretation') NOT NULL,
    payload       LONGTEXT NOT NULL,                       -- JSON snapshot of the whole table
    change_note   TEXT         DEFAULT NULL,
    status        ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
    created_by    INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by    INT UNSIGNED DEFAULT NULL,
    decided_at    DATETIME     DEFAULT NULL,
    KEY idx_scoring_kind_status (kind, status),
    CONSTRAINT fk_scoring_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_scoring_decider FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Brute-force protection for /login: per-account (5) and per-IP (20) failed
-- attempt counters inside a 15-minute window (see includes/security.php).
-- The application also creates this table on demand (IF NOT EXISTS).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)   NOT NULL,
    username     VARCHAR(191)  NOT NULL,
    attempted_at DATETIME      NOT NULL,
    KEY idx_la_user_time (username, attempted_at),
    KEY idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Runtime settings for the IT-admin panel (/itadmin): lockout limits that
-- override the LOGIN_* keys in the .envmb-index when saved.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key    VARCHAR(64)  PRIMARY KEY,
    setting_value  VARCHAR(255) NOT NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by     INT UNSIGNED DEFAULT NULL,
    CONSTRAINT fk_settings_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Role-based access control: roles, direct grants and group roles.
-- Effective roles = users.role (base) + user_roles + roles from assigned
-- groups. The app also creates these tables on demand (IF NOT EXISTS).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50)  NOT NULL UNIQUE,
    label       VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id    INT UNSIGNED NOT NULL,
    role_id    INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED DEFAULT NULL,
    granted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_group_roles (
    group_id INT UNSIGNED NOT NULL,
    role_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, role_id),
    CONSTRAINT fk_grg_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_grg_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role_groups (
    user_id     INT UNSIGNED NOT NULL,
    group_id    INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED DEFAULT NULL,
    assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    CONSTRAINT fk_urg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_urg_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_urg_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the three built-in roles (idempotent; the app seeds them on demand too)
INSERT IGNORE INTO roles (name, label, description, is_system) VALUES
    ('therapist', 'Therapist', 'Create and edit own assessment records', 1),
    ('admin', 'Administrator', 'Records, scoring tables and user management', 1),
    ('it_admin', 'IT System Administrator', 'Everything an administrator can do, plus roles, groups, settings and the console', 1);

-- ---------------------------------------------------------------------------
-- Bearer tokens for the mobile JSON API (/api/v1). Only the SHA-256 hash of
-- each token is stored; the plaintext is returned once at login. The app also
-- creates this table on demand (IF NOT EXISTS).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    device       VARCHAR(191) DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME     DEFAULT NULL,
    revoked_at   DATETIME     DEFAULT NULL,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_tok_user (user_id),
    CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
