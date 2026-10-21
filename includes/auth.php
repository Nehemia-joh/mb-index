<?php
/**
 * includes/auth.php — user accounts, password login and role guards.
 * Roles: therapist | admin | it_admin
 */
if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/db.php';

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
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        $mustChange ? 1 : 0,
    ]);
    return (int) gars_db()->lastInsertId();
}

/* --------------------------------------------------------------- sessions */

function gars_current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['gars_user'])) {
        return $user = null;
    }
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
    return $user !== null && in_array($user['role'], $roles, true);
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

/** Block a page unless the user has one of the given roles. */
function gars_require_role(array $roles): array
{
    $user = gars_require_login();
    if (!in_array($user['role'], $roles, true)) {
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
        $route = match ($user['role']) {
            'it_admin' => 'itadmin',
            'admin'    => 'admin',
            default    => 'therapist',
        };
    }
    return $route;
}
