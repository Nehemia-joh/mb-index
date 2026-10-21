<?php
/**
 * logout.php — Route: /logout
 * Closes the login session in the database, then sends the user to /login.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['gars_user'])) {
    // close the database session row, then the trail entry
    $stmt = gars_db()->prepare('UPDATE user_sessions SET logout_at = ? WHERE session_token = ? AND logout_at IS NULL');
    $stmt->execute([date('Y-m-d H:i:s'), gars_session_token()]);
    gars_log_activity('logout', gars_current_route(), 'Logged out');

    unset($_SESSION['gars_user'], $_SESSION['gars_token']);
    session_regenerate_id(true);
    session_destroy();
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
header('Location: ' . ($base ?: '') . '/login', true, 302);
exit;
