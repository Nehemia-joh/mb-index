<?php
/**
 * admin.php — Administrator dashboard: users, all assessment records, activity trail.
 * Route: /admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
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
<div class="page-head">
    <h1>Administrator dashboard</h1>
    <p class="sub">Accounts, records and the activity trail across the whole system.</p>
</div>

<div class="stats">
    <div class="stat"><span class="s-n"><?php echo $stats['users']; ?></span><span class="s-l">User accounts</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['assessments']; ?></span><span class="s-l">Assessment records</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['active']; ?></span><span class="s-l">Active login sessions</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['activities']; ?></span><span class="s-l">Activity entries</span></div>
</div>

<div class="section">
    <div class="actions" style="margin-top:0;">
        <a class="btn" href="<?php echo gars_url('register'); ?>">Create user</a>
        <a class="btn ghost" href="<?php echo gars_url('itadmin'); ?>">IT admin view</a>
    </div>
</div>

<div class="section">
    <h2>Latest assessment records</h2>
    <?php if ($records): ?>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th class="n">ID</th><th>Individual</th><th>School</th><th>Saved by</th><th class="n">Index</th><th>Probability</th><th>Saved</th></tr></thead>
        <tbody>
        <?php foreach ($records as $row): ?>
            <tr>
                <td class="n"><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">#<?php echo (int) $row['id']; ?></a></td>
                <td><strong><?php echo gars_e($row['individual_name']); ?></strong></td>
                <td><?php echo gars_e($row['school']); ?></td>
                <td><?php echo gars_e($row['creator'] ?? '-'); ?></td>
                <td class="n"><strong><?php echo (int) $row['autism_index']; ?></strong></td>
                <td><span class="badge <?php echo gars_prob_class($row['probability']); ?>"><?php echo gars_e($row['probability']); ?></span></td>
                <td><?php echo gars_e($row['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p class="empty"><b>No records yet.</b> Records appear here as soon as an assessment is scored.</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>Recent activity</h2>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Page</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($db->query('SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 25') as $row): ?>
            <tr>
                <td><?php echo gars_e($row['created_at']); ?></td>
                <td><strong><?php echo gars_e($row['username'] ?? 'guest'); ?></strong></td>
                <td><?php echo $row['role'] ? '<span class="badge ' . gars_e($row['role']) . '">' . gars_e(gars_role_label($row['role'])) . '</span>' : '-'; ?></td>
                <td><?php echo gars_e($row['action']); ?></td>
                <td><?php echo gars_e($row['page'] ?? '-'); ?></td>
                <td class="muted"><?php echo gars_e($row['details'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php gars_footer(); ?>