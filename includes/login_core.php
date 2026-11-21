<?php
/**
 * includes/login_core.php — the shared credential check used by both the web
 * login form (login.php) and the JSON API (api/index.php). One code path means
 * lockout, timing equalisation and bcrypt rehashing behave identically for the
 * website and the mobile app.
 *
 * Requires config.php (security.php) and includes/auth.php to be loaded first.
 *
 * Returns: ['ok' => bool, 'blocked' => bool, 'user' => ?array, 'error' => ?string]
 *   - blocked = lockout active (caller surfaces the lock message, HTTP 423 in the API)
 *   - on success the caller must establish its own context (session or bearer
 *     token) and log the 'login' activity row — gars_log_activity attributes
 *     rows through the session or $GLOBALS['GARS_LOG_ACTOR'].
 */

function gars_attempt_login(string $username, string $password, string $ip): array
{
    $lockMessage = gars_login_lock($username, $ip);
    if ($lockMessage !== null) {
        gars_log_activity('login_blocked', 'login', 'Blocked sign-in attempt (lockout active) for "' . gars_lock_key($username) . '"');
        return ['ok' => false, 'blocked' => true, 'user' => null, 'error' => $lockMessage];
    }

    $user = gars_find_user($username);
    $ok = false;
    if ($user && (int) $user['is_active']) {
        $ok = password_verify($password, $user['password_hash']);
        if ($ok && password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            // quietly upgrade legacy hashes to the current cost policy
            gars_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
        }
    } else {
        // timing equalisation: a missing or inactive account must cost the
        // same as a wrong password on a real account
        password_verify($password, GARS_TIMING_DUMMY);
    }

    if ($ok) {
        gars_clear_login_failures($username);
        gars_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $user['id']]);
        return ['ok' => true, 'blocked' => false, 'user' => $user, 'error' => null];
    }

    gars_log_activity('login_failed', 'login', 'Failed login attempt for "' . $username . '"');
    gars_record_login_failure($username, $ip);
    return ['ok' => false, 'blocked' => false, 'user' => null, 'error' => 'That username and password do not match an account.'];
}
