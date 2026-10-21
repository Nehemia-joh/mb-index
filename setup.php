<?php
/**
 * setup.php — one-click database installer (MySQL only).
 *   CLI:      php setup.php
 *   Browser:  allowed while no users exist yet, or when logged in as it_admin.
 * Creates all tables (same schema as database.sql) and seeds the default
 * administrator from the .envmb-index file. Requires DB_* values in .envmb-index.
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    $installed = gars_db_installed();
    $u = gars_current_user();
    if ($installed && (!$u || $u['role'] !== 'it_admin')) {
        http_response_code(403);
        exit('Setup is locked. Ask the IT System Administrator, or run: php setup.php');
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

function gars_setup_tables(PDO $db): void
{
    $ddl = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            email VARCHAR(190) DEFAULT NULL,
            full_name VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('therapist','admin','it_admin') NOT NULL DEFAULT 'therapist',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            session_token VARCHAR(128) NOT NULL UNIQUE,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            current_page VARCHAR(190) DEFAULT NULL,
            prev_page VARCHAR(190) DEFAULT NULL,
            page_views INT UNSIGNED NOT NULL DEFAULT 0,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            logout_at DATETIME DEFAULT NULL,
            KEY idx_user_sessions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            user_session_id INT UNSIGNED DEFAULT NULL,
            username VARCHAR(60) DEFAULT NULL,
            role VARCHAR(20) DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            page VARCHAR(190) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_activity_user (user_id),
            KEY idx_activity_action (action),
            KEY idx_activity_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS assessments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_by INT UNSIGNED NOT NULL,
            individual_name VARCHAR(190) NOT NULL,
            school VARCHAR(190) NOT NULL,
            rater_name VARCHAR(190) NOT NULL,
            rater_title VARCHAR(190) NOT NULL,
            date_of_birth DATE DEFAULT NULL,
            age VARCHAR(20) NOT NULL,
            gender VARCHAR(10) NOT NULL,
            grade VARCHAR(30) DEFAULT NULL,
            examiner_name VARCHAR(190) NOT NULL,
            examiner_title VARCHAR(190) NOT NULL,
            date_of_rating DATE DEFAULT NULL,
            rater_known_for VARCHAR(60) DEFAULT NULL,
            is_mute TINYINT(1) NOT NULL DEFAULT 0,
            raw_rb SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            raw_si SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            raw_sc SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            raw_er SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            raw_cs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            raw_ms SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scaled VARCHAR(190) NOT NULL,
            percentiles VARCHAR(190) NOT NULL,
            sum_scaled SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            composite_percentile VARCHAR(20) NOT NULL,
            autism_index SMALLINT UNSIGNED NOT NULL,
            probability VARCHAR(30) NOT NULL,
            severity_level VARCHAR(30) NOT NULL,
            severity_desc VARCHAR(80) NOT NULL,
            item_scores TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assess_creator (created_by),
            KEY idx_assess_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) {
        $db->exec($sql);
    }
}

/* ------------------------------------------------------------- run setup */
$db = gars_db();

// Make sure the database itself exists (best effort — cPanel creates it first).
try {
    (new PDO(sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET), DB_USER, DB_PASS))
        ->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` DEFAULT CHARACTER SET ' . DB_CHARSET . ' COLLATE ' . DB_CHARSET . '_unicode_ci');
} catch (Throwable $e) {
    // ignore — on cPanel the DB already exists; the connect below will confirm
}
gars_setup_tables(gars_db());

$out = [];
if (gars_find_user(DEFAULT_ADMIN_USER) === null) {
    if (DEFAULT_ADMIN_PASSWORD === '') {
        $out[] = '!! DEFAULT_ADMIN_PASSWORD is empty in the .envmb-index file — no admin was created.';
    } else {
        gars_create_user(DEFAULT_ADMIN_USER, DEFAULT_ADMIN_PASSWORD, DEFAULT_ADMIN_ROLE, 'Default ' . gars_role_label(DEFAULT_ADMIN_ROLE), env('DEFAULT_ADMIN_EMAIL', ''), false);
        $out[] = 'Created default user "' . DEFAULT_ADMIN_USER . '" (' . DEFAULT_ADMIN_ROLE . ').';
    }
} else {
    $out[] = 'Default user "' . DEFAULT_ADMIN_USER . '" already exists — skipped.';
}

$counts = [];
foreach (['users', 'user_sessions', 'activity_logs', 'assessments'] as $t) {
    $counts[$t] = (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}

$out[] = '';
$out[] = 'MySQL database is ready.';
foreach ($counts as $t => $c) {
    $out[] = sprintf('   %-16s %d row(s)', $t, $c);
}
$out[] = '';
$out[] = 'You can now log in on route  /' . 'login';
echo implode(PHP_EOL, $out) . PHP_EOL;
