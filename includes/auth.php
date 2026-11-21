<?php
/**
 * includes/auth.php — user accounts, password login and role guards.
 * Base roles: therapist | admin | it_admin (users.role column).
 * Effective access also includes direct grants and group roles — see rbac.php.
 */
if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';

function gars_roles(): array
{
    global $GARS_ROLES;
    return $GARS_ROLES ?? ['therapist' => 'Therapist', 'admin' => 'Administrator', 'it_admin' => 'IT System Administrator'];
}

function gars_role_label(string $role): string
{
    return gars_roles()[$role] ?? ucfirst($role);
}

/** All fields the login/registration forms require. */
function gars_validate_required(array $fields, array $labels = []): array
{
    $missing = [];
    foreach ($fields as $key => $value) {
        if (trim((string) $value) === '') {
            $missing[] = $labels[$key] ?? $key;
        }
    }
    return $missing;
}

/* ------------------------------------------------------------------ users */

function gars_find_user(string $username): ?array
{
    $stmt = gars_db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function gars_create_user(string $username, string $password, string $role, string $fullName = '', string $email = '', bool $mustChange = true): int
{
    $allowed = array_keys(gars_roles());
    if (!in_array($role, $allowed, true)) {
        throw new InvalidArgumentException('Invalid role.');
    }
    if (gars_find_user($username)) {
        throw new RuntimeException('That username is already taken.');
    }
    $stmt = gars_db()->prepare(
        'INSERT INTO users (username, email, full_name, password_hash, role, is_active, must_change_password)
         VALUES (?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        $username,
        $email !== '' ? $email : null,
        $fullName !== '' ? $fullName : $username,
        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        $role,
        $mustChange ? 1 : 0,
    ]);
    // capture the id first: the ensure/DELETE below would reset LAST_INSERT_ID()
    $newId = (int) gars_db()->lastInsertId();
    // a newly set password wipes any failed-attempt/lockout state for this name
    gars_clear_login_failures($username);
    return $newId;
}

/* --------------------------------------------------------------- sessions */

function gars_current_user(): ?array
{
    // the JSON API authenticates with bearer tokens and must never start a
    // PHP session (no Set-Cookie on API responses)
    if (defined('GARS_API_MODE') && GARS_API_MODE) {
        return null;
    }
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        gars_session_start();
    }
    if (empty($_SESSION['gars_user'])) {
        return $user = null;
    }
    // server-side idle timeout: SESSION_TIMEOUT seconds without a request
    // ends the session (0 = never); the clock starts now for sessions that
    // predate gars_last_seen so a deploy never logs everybody out at once
    $now = time();
    $last = (int) ($_SESSION['gars_last_seen'] ?? 0);
    if ($last <= 0) {
        $last = $now;
    }
    if (SESSION_TIMEOUT > 0 && ($now - $last) > SESSION_TIMEOUT) {
        $token = (string) ($_SESSION['gars_token'] ?? '');
        if ($token !== '') {
            try {
                gars_db()->prepare(
                    'UPDATE user_sessions SET logout_at = ? WHERE session_token = ? AND logout_at IS NULL'
                )->execute([date('Y-m-d H:i:s', $now), $token]);
            } catch (Throwable $e) {
                // trail bookkeeping must never break the redirect to /login
            }
        }
        unset($_SESSION['gars_user'], $_SESSION['gars_token'], $_SESSION['csrf_token'], $_SESSION['gars_last_seen']);
        return $user = null;
    }
    $_SESSION['gars_last_seen'] = $now;
    $dbUser = gars_find_user($_SESSION['gars_user']['username'] ?? '');
    if (!$dbUser || !(int) $dbUser['is_active']) {
        unset($_SESSION['gars_user']);
        return $user = null;
    }
    return $user = $dbUser;
}

function gars_is_logged_in(): bool
{
    return gars_current_user() !== null;
}

function gars_has_role(string ...$roles): bool
{
    $user = gars_current_user();
    if ($user === null) {
        return false;
    }
    $mine = gars_user_role_names($user);
    foreach ($roles as $role) {
        if (in_array($role, $mine, true)) {
            return true;
        }
    }
    return false;
}

/** Block a page unless logged in (redirects to /login). */
function gars_require_login(): array
{
    $user = gars_current_user();
    if ($user === null) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['gars_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        }
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        gars_redirect(($base ?: '') . '/login');
    }
    return $user;
}

/** Block a page unless the user's effective roles include one of the given. */
function gars_require_role(array $roles): array
{
    $user = gars_require_login();
    if (!gars_has_role(...$roles)) {
        http_response_code(403);
        exit('403 — You do not have permission to view that page.');
    }
    return $user;
}

function gars_redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}
function gars_home_route(?array $user = null): string
{
    $user  = $user ?: gars_current_user();
    $route = 'index';
    if ($user) {
        $mine = gars_user_role_names($user);
        $route = match (true) {
            in_array('it_admin', $mine, true) => 'itadmin',
            in_array('admin', $mine, true)    => 'admin',
            default                           => 'therapist',
        };
    }
    return $route;
}
