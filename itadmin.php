<?php
/**
 * itadmin.php — IT System Administrator dashboard: sessions, full audit trail, user list.
 * Route: /itadmin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'itadmin');

$user = gars_require_role(['it_admin']);
gars_track_page('page_visit', 'IT admin console');

$db = gars_db();

$stats = [
    'users'      => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'     => (int) $db->query('SELECT COUNT(*) FROM user_sessions WHERE logout_at IS NULL')->fetchColumn(),
    'sessions'   => (int) $db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(),
    'activities' => (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];

$expired = SESSION_TIMEOUT > 0 ? date('Y-m-d H:i:s', time() - SESSION_TIMEOUT) : null;
if ($expired) {
    // auto-close sessions idle for longer than SESSION_TIMEOUT (keeps the trail accurate)
    $db->prepare("UPDATE user_sessions SET logout_at = COALESCE(logout_at, ?)
                  WHERE logout_at IS NULL AND last_activity_at < ?")->execute([$expired, $expired]);
}

$sessions = $db->query(
    'SELECT s.*, u.username, u.role FROM user_sessions s
     LEFT JOIN users u ON u.id = s.user_id
     ORDER BY (s.logout_at IS NULL) DESC, s.last_activity_at DESC LIMIT 30'
)->fetchAll();

$activities = $db->query(
    'SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 50'
)->fetchAll();

gars_header('IT Admin', 'itadmin');
?>
<div class="section">
    <h2>IT System Administrator console</h2>
    <div class="grid">
        <div class="card"><strong>User accounts:</strong> <?php echo $stats['users']; ?></div>
        <div class="card"><strong>Active sessions:</strong> <?php echo $stats['active']; ?></div>
        <div class="card"><strong>Total sessions:</strong> <?php echo $stats['sessions']; ?></div>
        <div class="card"><strong>Activity entries:</strong> <?php echo $stats['activities']; ?></div>
    </div>
    <p class="muted" style="margin-top:10px;">
        Sessions idle over <?php echo (int) (SESSION_TIMEOUT / 60); ?> minutes are closed automatically.
        Database: MySQL (<strong><?php echo gars_e(DB_HOST); ?></strong>), credentials from the .envmb-index file.
    </p>
    <p style="margin-top:12px;">
        <a class="btn" href="<?php echo gars_url('register'); ?>">Create user</a>
        <a class="btn red" href="#" onclick="return false;" title="Contact your own account">Session security: automatic</a>
    </p>
</div>

<div class="section">
    <h2>Login sessions (page-to-page movements)</h2>
    <table class="data">
        <thead><tr><th>User</th><th>Role</th><th>Current page</th><th>Came from</th><th>Page views</th><th>Logged in</th><th>Last activity</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><?php echo gars_e($s['username'] ?? ('user #' . $s['user_id'])); ?></td>
                <td><?php echo $s['role'] ? '<span class="badge ' . gars_e($s['role']) . '">' . gars_e(gars_role_label($s['role'])) . '</span>' : '-'; ?></td>
                <td><strong><?php echo gars_e($s['current_page'] ?? '-'); ?></strong></td>
                <td><?php echo gars_e($s['prev_page'] ?? '—'); ?></td>
                <td><?php echo (int) $s['page_views']; ?></td>
                <td><?php echo gars_e($s['login_at']); ?></td>
                <td><?php echo gars_e($s['last_activity_at']); ?></td>
                <td><span class="badge <?php echo $s['logout_at'] ? 'off' : 'on'; ?>"><?php echo $s['logout_at'] ? 'Closed' : 'Active'; ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Full activity trail (latest 50)</h2>
    <table class="data">
        <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Page</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($activities as $row): ?>
            <tr>
                <td><?php echo gars_e($row['created_at']); ?></td>
                <td><?php echo gars_e($row['username'] ?? 'guest'); ?></td>
                <td><?php echo $row['role'] ? '<span class="badge ' . gars_e($row['role']) . '">' . gars_e(gars_role_label($row['role'])) . '</span>' : '-'; ?></td>
                <td><strong><?php echo gars_e($row['action']); ?></strong></td>
                <td><?php echo gars_e($row['page'] ?? '-'); ?></td>
                <td class="muted"><?php echo gars_e($row['details'] ?? ''); ?></td>
                <td class="muted"><?php echo gars_e($row['ip_address'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php gars_footer(); ?>
