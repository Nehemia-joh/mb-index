<?php
/**
 * login.php — password login for therapist / admin / IT system administrator.
 * Route: /login
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'login');

if (gars_db_installed() === false) {
    gars_header('Setup required', 'login', false);
    echo '<div class="section"><h2>Database not installed</h2><p class="card">Run <code>php setup.php</code> once (or open /setup) to create the database tables.</p></div>';
    gars_footer();
    exit;
}

if (gars_is_logged_in()) {
    gars_redirect(gars_url(gars_home_route()));
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$error = null;
$missing = gars_validate_required(
    ['username' => $username, 'password' => $password],
    ['username' => 'Username', 'password' => 'Password']
);

$flash = gars_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($missing) {
        $error = 'Please fill in every required field: ' . implode(', ', $missing) . '.';
    } else {
        $user = gars_find_user($username);
        if ($user && (int) $user['is_active'] && password_verify($password, $user['password_hash'])) {
            // successful login — register the session in the database
            session_regenerate_id(true);
            $_SESSION['gars_user'] = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'full_name' => $user['full_name'],
            ];
            gars_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $user['id']]);
            gars_log_activity('login', 'login', 'Logged in as ' . $user['username'] . ' (' . $user['role'] . ')');
            gars_track_page('page_visit');
            gars_flash('Welcome back, ' . $user['full_name'] . '!');
            gars_redirect(gars_url(gars_home_route($user)));
        }
        $error = 'Invalid username or password.';
        gars_log_activity('login_failed', 'login', 'Failed login attempt for "' . $username . '"');
    }
}

gars_header('Login', 'login', false);
?>
<div class="section">
    <h2>Login</h2>
    <?php if ($error): ?><div class="notice notice-error"><?php echo gars_e($error); ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="notice notice-<?php echo gars_e($flash['type']); ?>"><?php echo gars_e($flash['message']); ?></div><?php endif; ?>
    <div class="card" style="max-width:420px; margin:0 auto;">
        <form method="post" action="<?php echo gars_url('login'); ?>" autocomplete="off">
            <div class="form-group" style="margin-bottom:14px;">
                <label for="username">Username <span class="req">*</span></label>
                <input type="text" id="username" name="username" required
                       value="<?php echo gars_e($username); ?>" autofocus>
            </div>
            <div class="form-group" style="margin-bottom:18px;">
                <label for="password">Password <span class="req">*</span></label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn green" style="width:100%;">Login</button>
        </form>
        <p class="muted" style="margin-top:14px; text-align:center;">
            Accounts are created by the Administrator or IT System Administrator.
        </p>
    </div>
</div>
<?php gars_footer(); ?>
