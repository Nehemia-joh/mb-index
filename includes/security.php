<?php
/**
 * includes/security.php — session hardening, CSRF protection, HTTPS enforcement
 * and login brute-force lockout. Loaded at the end of config.php so every
 * request (web or CLI) has these helpers available before any session starts.
 */
if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}

/* ------------------------------------------------------------------- HTTPS */

function gars_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

/**
 * Force HTTPS (301) and send HSTS once we are on it.
 * FORCE_HTTPS=0 in the .envmb-index turns it off; loopback dev servers
 * (localhost / 127.0.0.1) are always exempt so `php -S` keeps working.
 */
function gars_force_https(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (env('FORCE_HTTPS', '1') === '0' || headers_sent()) {
        return;
    }
    if (gars_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        return;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostOnly = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (in_array($hostOnly, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
        return; // local development server
    }
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

/* --------------------------------------------------------------- sessions */

/**
 * The single place sessions start: strict mode on, cookie marked
 * HttpOnly + SameSite=Lax, Secure whenever the request arrived over HTTPS.
 */
function gars_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        // unit tests: no real headers/cookies — keep a plain superglobal
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        return;
    }
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => gars_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

/* -------------------------------------------------------------------- CSRF */

/** This session's anti-CSRF token (64 hex chars), created on first use. */
function gars_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        gars_session_start();
    }
    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

/** New token for this session — used after login to rotate away the pre-login token. */
function gars_csrf_rotate(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        gars_session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Hidden form field carrying the token; print inside every POST form. */
function gars_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(gars_csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Reject any POST that does not carry this session's token.
 * Call first thing inside every POST handler. Logs the attempt and exits 403.
 */
function gars_csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = (string) ($_POST['csrf_token'] ?? '');
    $want = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sent !== '' && $want !== '' && hash_equals($want, $sent)) {
        return;
    }
    if (function_exists('gars_log_activity')) {
        gars_log_activity(
            'csrf_blocked',
            function_exists('gars_current_route') ? gars_current_route() : 'unknown',
            'POST without a valid CSRF token'
        );
    }
    http_response_code(403);
    exit('403 — Your session token is missing or expired. Refresh the page and try again.');
}

/* ------------------------------------------------------- brute force lock */

/** Rolling count window shared by the account and IP limits (seconds). */
const GARS_LOCK_WINDOW = 900; // 15 minutes

/**
 * Active lockout limits, in precedence order:
 *   1. app_settings row (saved from the /itadmin panel)
 *   2. LOGIN_* key in the .envmb-index
 *   3. the defaults below
 * Each spec: env key, default, clamp min/max.
 */
const GARS_LOCK_LIMITS = [
    'login_user_limit'   => ['env' => 'LOGIN_USER_LIMIT',     'def' => 5,  'min' => 1, 'max' => 100],
    'login_ip_limit'     => ['env' => 'LOGIN_IP_LIMIT',       'def' => 60, 'min' => 1, 'max' => 500],
    'login_lock_minutes' => ['env' => 'LOGIN_LOCK_MINUTES',   'def' => 30, 'min' => 1, 'max' => 1440],
];

/** Bounded, always-UTF-8-safe key for storing/looking up a posted username. */
function gars_lock_key(string $value, int $max = 191): string
{
    if (preg_match('/^.{0,' . $max . '}/us', $value, $m)) {
        return $m[0];
    }
    // not valid UTF-8 (possible with hand-crafted requests): keep printable ASCII only
    $ascii = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    return substr($ascii, 0, $max);
}

function gars_ensure_security_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    gars_db()->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45)   NOT NULL,
            username     VARCHAR(191)  NOT NULL,
            attempted_at DATETIME      NOT NULL,
            KEY idx_la_user_time (username, attempted_at),
            KEY idx_la_ip_time (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    gars_db()->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key    VARCHAR(64)  PRIMARY KEY,
            setting_value  VARCHAR(255) NOT NULL,
            updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by     INT UNSIGNED DEFAULT NULL,
            CONSTRAINT fk_settings_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Drop the per-request settings cache (panel saves and unit tests). */
function gars_login_settings_reset(): void
{
    unset($GLOBALS['GARS_LOGIN_SETTINGS_CACHE']);
}

/**
 * Effective value for one lockout limit: database override → env → default,
 * always clamped to the spec's range. Returns ['value' => int, 'source' => 'database'|'env'|'default'].
 */
function gars_login_setting_info(string $key): array
{
    $spec = GARS_LOCK_LIMITS[$key] ?? null;
    if ($spec === null) {
        throw new InvalidArgumentException('Unknown lockout setting: ' . $key);
    }
    $cache = $GLOBALS['GARS_LOGIN_SETTINGS_CACHE'] ?? [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $value = null;
    $source = 'default';
    try {
        gars_ensure_security_tables();
        $stmt = gars_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) {
            $dbValue = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
            if ($dbValue !== false) {
                $value = (int) $dbValue;
                $source = 'database';
            }
        }
    } catch (Throwable $e) {
        $value = null; // table missing / no DB — fall through to env
    }
    if ($value === null) {
        $env = env($spec['env']);
        if (is_numeric($env)) {
            $value = (int) $env;
            $source = 'env';
        } else {
            $value = $spec['def'];
        }
    }
    $value = max($spec['min'], min($spec['max'], $value));
    $result = ['value' => $value, 'source' => $source];
    $GLOBALS['GARS_LOGIN_SETTINGS_CACHE'][$key] = $result;
    return $result;
}

function gars_login_setting(string $key): int
{
    return gars_login_setting_info($key)['value'];
}

function gars_login_user_limit(): int
{
    return gars_login_setting('login_user_limit');
}

function gars_login_ip_limit(): int
{
    return gars_login_setting('login_ip_limit');
}

/** Lock duration in seconds (login_lock_minutes × 60). */
function gars_login_lock_seconds(): int
{
    return gars_login_setting('login_lock_minutes') * 60;
}

/** Persist one limit from the /itadmin panel. */
function gars_save_login_setting(string $key, int $value, int $adminId): void
{
    if (!isset(GARS_LOCK_LIMITS[$key])) {
        throw new InvalidArgumentException('Unknown lockout setting: ' . $key);
    }
    gars_ensure_security_tables();
    $stmt = gars_db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, (string) $value, $adminId]);
    gars_login_settings_reset();
}

/** Remove all panel overrides so env/defaults apply again. */
function gars_delete_login_settings(): void
{
    gars_ensure_security_tables();
    gars_db()->exec('DELETE FROM app_settings');
    gars_login_settings_reset();
}

/**
 * Returns a user-facing lock message when sign-in must be refused right now,
 * or null when the attempt may proceed. Keyed by the posted username string
 * (existing or not) and by REMOTE_ADDR only — never the spoofable XFF header.
 * While locked no new rows are recorded, so the lock always ends exactly
 * the configured number of minutes after the failure that triggered it.
 */
function gars_login_lock(?string $username, string $ip): ?string
{
    gars_ensure_security_tables();
    $db = gars_db();
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    $hold = gars_login_lock_seconds();

    $locked = function (string $column, string $value, int $limit, string $who) use ($hold): ?string {
        $since = date('Y-m-d H:i:s', time() - GARS_LOCK_WINDOW);
        $stmt = gars_db()->prepare(
            'SELECT COUNT(*) AS n, MAX(attempted_at) AS last_at
             FROM login_attempts WHERE ' . $column . ' = ? AND attempted_at >= ?'
        );
        $stmt->execute([$value, $since]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['n'] < $limit) {
            return null;
        }
        $until = strtotime((string) $row['last_at']) + $hold;
        if (time() >= $until) {
            return null;
        }
        $minutes = (int) ceil(($until - time()) / 60);
        $unit = $minutes === 1 ? 'minute' : 'minutes';
        return 'Too many failed sign-in attempts for ' . $who . '. Try again in ' . $minutes . ' ' . $unit . '.';
    };

    $userKey = $username !== null && $username !== '' ? gars_lock_key($username) : '';
    if ($userKey !== '') {
        $msg = $locked('username', $userKey, gars_login_user_limit(), 'this account');
        if ($msg !== null) {
            return $msg;
        }
    }
    $ip = substr($ip, 0, 45);
    if ($ip !== '') {
        $msg = $locked('ip', $ip, gars_login_ip_limit(), 'from your network');
        if ($msg !== null) {
            return $msg;
        }
    }
    return null;
}

function gars_record_login_failure(string $username, string $ip): void
{
    gars_ensure_security_tables();
    $stmt = gars_db()->prepare(
        'INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([
        substr($ip !== '' ? $ip : '-', 0, 45),
        gars_lock_key($username),
        date('Y-m-d H:i:s'),
    ]);
}

/**
 * Reset the failed-attempt counter for one account. Called after a successful
 * sign-in AND whenever a password is (re)set — a fresh password wipes any
 * pending lockout state for that username.
 */
function gars_clear_login_failures(string $username): void
{
    gars_ensure_security_tables();
    $stmt = gars_db()->prepare('DELETE FROM login_attempts WHERE username = ?');
    $stmt->execute([gars_lock_key($username)]);
}
