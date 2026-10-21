<?php
/**
 * includes/tracker.php — records user sessions and page-to-page movements
 * (plus every change, e.g. created assessment / created user) in the database:
 *   user_sessions  : one row per login; keeps current page, previous page, view count
 *   activity_logs  : append-only trail: login | logout | page_visit | create_* | update_*
 */

if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/db.php';

function gars_client_ip(): string
{
    return substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function gars_client_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/** The clean route we are on, e.g. "admin", "score", "login". */
function gars_current_route(): string
{
    if (defined('GARS_ROUTE_NAME')) {  // set by route.php
        return GARS_ROUTE_NAME;
    }
    if (defined('GARS_ROUTE')) {       // set by pages accessed directly
        return GARS_ROUTE;
    }
    $route = preg_replace('/\.php$/i', '', $script);
    if ($route === 'route') { // front-controller style request
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $route = trim($uri, '/');
        if ($route === '' || $route === 'route.php') {
            $route = 'index';
        }
    }
    return $route !== '' ? $route : 'index';
}

/**
 * Must be called at the top of every page.
 * When logged in: keeps user_sessions up to date (page-to-page movement) and
 * appends a page_visit row into activity_logs.
 */
function gars_track_page(?string $action = 'page_visit', ?string $details = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $user = gars_current_user();
    if (!$user) {
        return; // anonymous visitors (e.g. the login page) are not tracked
    }

    $db = gars_db();
    $route = gars_current_route();
    $token = gars_session_token();
    $now = date('Y-m-d H:i:s');

    // create or refresh the login session row
    $stmt = $db->prepare('SELECT id, current_page, page_views FROM user_sessions WHERE session_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        $stmt = $db->prepare(
            'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, current_page, page_views, login_at, last_activity_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$user['id'], $token, gars_client_ip(), gars_client_agent(), $route, $now, $now]);
        $row = ['id' => (int) $db->lastInsertId(), 'current_page' => null, 'page_views' => 0];
    }

    $prev = $row['current_page'];
    $views = (int) $row['page_views'] + 1;
    $suppressVisit = $GLOBALS['GARS_SUPPRESS_PAGE_VISIT_LOG'] ?? false;
    if (!$suppressVisit) {
        $stmt = $db->prepare(
            'UPDATE user_sessions SET prev_page = ?, current_page = ?, page_views = ?, last_activity_at = ? WHERE id = ?'
        );
        $stmt->execute([$prev, $route, $views, $now, (int) $row['id']]);
    } else {
        $stmt = $db->prepare('UPDATE user_sessions SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$now, (int) $row['id']]);
    }

    gars_log_activity($action, $route, $details, (int) $row['id']);
}

/** The token identifying this browser session (links to user_sessions rows). */
function gars_session_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['gars_token'])) {
        $_SESSION['gars_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['gars_token'];
}

/**
 * Append an entry to activity_logs (a "change": create assessment, create user, login, logout...).
 * Safe to call anywhere after db.php is loaded.
 */
function gars_log_activity(string $action, ?string $page = null, ?string $details = null, ?int $sessionRowId = null): void
{
    try {
        $user = function_exists('gars_current_user') ? gars_current_user() : null;
        $stmt = gars_db()->prepare(
            'INSERT INTO activity_logs (user_id, user_session_id, username, role, action, page, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'] ?? null,
            $sessionRowId,
            $user['username'] ?? null,
            $user['role'] ?? null,
            $action,
            $page,
            $details,
            gars_client_ip(),
            gars_client_agent(),
        ]);
    } catch (Throwable $e) {
        // tracking must never break the page
        error_log('[gars-tracker] ' . $e->getMessage());
    }
}
