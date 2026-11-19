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
