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

/** Thresholds: 5 failures per account or 20 per IP inside 15 minutes →
 *  locked for 30 minutes from the most recent recorded failure. */
const GARS_LOCK_LIMIT      = 5;
const GARS_LOCK_IP_LIMIT   = 20;
const GARS_LOCK_WINDOW     = 900;   // seconds (rolling count window)
const GARS_LOCK_HOLD       = 1800;  // seconds (how long the lock lasts)

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

function gars_ensure_login_attempts_table(): void
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
}

/**
 * Returns a user-facing lock message when sign-in must be refused right now,
 * or null when the attempt may proceed. Keyed by the posted username string
 * (existing or not) and by REMOTE_ADDR only — never the spoofable XFF header.
 * While locked no new rows are recorded, so the lock always ends exactly
 * GARS_LOCK_HOLD after the failure that triggered it.
 */
function gars_login_lock(?string $username, string $ip): ?string
{
    gars_ensure_login_attempts_table();
    $db = gars_db();
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');

    $locked = function (string $column, string $value, int $limit, string $who): ?string {
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
        $until = strtotime((string) $row['last_at']) + GARS_LOCK_HOLD;
        if (time() >= $until) {
            return null;
        }
        $minutes = (int) ceil(($until - time()) / 60);
        $unit = $minutes === 1 ? 'minute' : 'minutes';
        return 'Too many failed sign-in attempts for ' . $who . '. Try again in ' . $minutes . ' ' . $unit . '.';
    };

    $userKey = $username !== null && $username !== '' ? gars_lock_key($username) : '';
    if ($userKey !== '') {
        $msg = $locked('username', $userKey, GARS_LOCK_LIMIT, 'this account');
        if ($msg !== null) {
            return $msg;
        }
    }
    $ip = substr($ip, 0, 45);
    if ($ip !== '') {
        $msg = $locked('ip', $ip, GARS_LOCK_IP_LIMIT, 'from your network');
        if ($msg !== null) {
            return $msg;
        }
    }
    return null;
}

function gars_record_login_failure(string $username, string $ip): void
{
    gars_ensure_login_attempts_table();
    $stmt = gars_db()->prepare(
        'INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([
        substr($ip !== '' ? $ip : '-', 0, 45),
        gars_lock_key($username),
        date('Y-m-d H:i:s'),
    ]);
}

/** Called after a successful sign-in so a small slip-up never lingers. */
function gars_clear_login_failures(string $username): void
{
    gars_ensure_login_attempts_table();
    $stmt = gars_db()->prepare('DELETE FROM login_attempts WHERE username = ?');
    $stmt->execute([gars_lock_key($username)]);
}
