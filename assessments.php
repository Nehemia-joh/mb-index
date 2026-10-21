<?php
/**
 * assessments.php — stored assessment records.
 * Route: /records or /assessments
 * Therapists see only their own records; admins and IT admins see all.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'assessments');

$user = gars_require_login();
gars_track_page('page_visit', 'Assessment records');

$db = gars_db();

if (in_array($user['role'], ['admin', 'it_admin'], true)) {
    $rows = $db->query(
        'SELECT a.*, u.username AS creator FROM assessments a
         LEFT JOIN users u ON u.id = a.created_by
         ORDER BY a.created_at DESC, a.id DESC LIMIT 100'
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        'SELECT a.*, u.username AS creator FROM assessments a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.created_by = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 100'
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
}

gars_header('Records', 'assessments');
?>
<div class="section">
    <h2>Assessment records</h2>
    <p class="muted"><?php echo count($rows); ?> record(s) <?php echo $user['role'] === 'therapist' ? 'saved by you' : 'in the system'; ?>.</p>
    <table class="data">
        <thead><tr><th>ID</th><th>Individual</th><th>School</th><th>Rater</th><th>By</th><th>Index</th><th>Probability</th><th>Severity</th><th>Saved</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo gars_e($row['individual_name']); ?></td>
                <td><?php echo gars_e($row['school']); ?></td>
                <td><?php echo gars_e($row['rater_name']); ?></td>
                <td><?php echo gars_e($row['creator'] ?? '-'); ?></td>
                <td><strong><?php echo (int) $row['autism_index']; ?></strong></td>
                <td><?php echo gars_e($row['probability']); ?></td>
                <td><?php echo gars_e($row['severity_level']); ?></td>
                <td><?php echo gars_e($row['created_at']); ?></td>
                <td><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="muted">No records yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php gars_footer(); ?>
