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
<div class="page-head">
    <h1>Assessment records</h1>
    <p class="sub"><?php echo count($rows); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?>, <?php echo $user['role'] === 'therapist' ? 'all saved by you' : 'saved by every account in the system'; ?>. Open one to see the full scoring report.</p>
</div>

<?php if ($rows): ?>
<div class="tablewrap">
<table class="data">
    <thead><tr><th class="n">ID</th><th>Individual</th><th>School</th><th>Rater</th><th>Saved by</th><th class="n">Index</th><th>Probability</th><th>Severity</th><th>Saved</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td class="n"><?php echo (int) $row['id']; ?></td>
            <td><strong><?php echo gars_e($row['individual_name']); ?></strong></td>
            <td><?php echo gars_e($row['school']); ?></td>
            <td><?php echo gars_e($row['rater_name']); ?></td>
            <td><?php echo gars_e($row['creator'] ?? '-'); ?></td>
            <td class="n"><strong><?php echo (int) $row['autism_index']; ?></strong></td>
            <td><span class="badge <?php echo gars_prob_class($row['probability']); ?>"><?php echo gars_e($row['probability']); ?></span></td>
            <td><?php echo gars_e($row['severity_level']); ?></td>
            <td><?php echo gars_e($row['created_at']); ?></td>
            <td style="text-align:right;"><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">Open</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
<p class="empty"><b>No records yet.</b> Start the first one from <a href="<?php echo gars_url('index'); ?>">New assessment</a>.</p>
<?php endif; ?>
<?php gars_footer(); ?>