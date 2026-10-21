<?php
/**
 * admin.php — Administrator dashboard: users, all assessment records, activity trail.
 * Route: /admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'admin');

$user = gars_require_role(['admin', 'it_admin']);
gars_track_page('page_visit', 'Administrator dashboard');

$db = gars_db();
$stats = [
    'users'       => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'assessments' => (int) $db->query('SELECT COUNT(*) FROM assessments')->fetchColumn(),
    'active'      => (int) $db->query('SELECT COUNT(*) FROM user_sessions WHERE logout_at IS NULL')->fetchColumn(),
    'activities'  => (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];
$records = $db->query(
    'SELECT a.*, u.username AS creator FROM assessments a LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC, a.id DESC LIMIT 25'
)->fetchAll();

gars_header('Admin', 'admin');
?>
<div class="section">
    <h2>Administrator dashboard</h2>
    <div class="grid">
        <div class="card"><strong>Users:</strong> <?php echo $stats['users']; ?></div>
        <div class="card"><strong>Assessment records:</strong> <?php echo $stats['assessments']; ?></div>
        <div class="card"><strong>Active login sessions:</strong> <?php echo $stats['active']; ?></div>
        <div class="card"><strong>Activity entries:</strong> <?php echo $stats['activities']; ?></div>
    </div>
    <p style="margin-top:18px;">
        <a class="btn green" href="<?php echo gars_url('register'); ?>">Create user</a>
        <a class="btn" href="<?php echo gars_url('itadmin'); ?>">IT admin view</a>
    </p>
</div>
<div class="section">
    <h2>Latest assessment records (25)</h2>
    <table class="data">
        <thead><tr><th>ID</th><th>Individual</th><th>School</th><th>By</th><th>Index</th><th>Probability</th><th>Saved</th></tr></thead>
        <tbody>
        <?php foreach ($records as $row): ?>
            <tr>
                <td><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">#<?php echo (int) $row['id']; ?></a></td>
                <td><?php echo gars_e($row['individual_name']); ?></td>
                <td><?php echo gars_e($row['school']); ?></td>
                <td><?php echo gars_e($row['creator'] ?? '-'); ?></td>
                <td><strong><?php echo (int) $row['autism_index']; ?></strong></td>
                <td><?php echo gars_e($row['probability']); ?></td>
                <td><?php echo gars_e($row['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="7" class="muted">No records yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<div class="section">
    <h2>Recent activity (change trail)</h2>
    <table class="data">
        <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Page</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($db->query('SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 25') as $row): ?>
            <tr>
                <td><?php echo gars_e($row['created_at']); ?></td>
                <td><?php echo gars_e($row['username'] ?? 'guest'); ?></td>
                <td><?php echo $row['role'] ? '<span class="badge ' . gars_e($row['role']) . '">' . gars_e(gars_role_label($row['role'])) . '</span>' : '-'; ?></td>
                <td><strong><?php echo gars_e($row['action']); ?></strong></td>
                <td><?php echo gars_e($row['page'] ?? '-'); ?></td>
                <td class="muted"><?php echo gars_e($row['details'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php gars_footer(); ?>
