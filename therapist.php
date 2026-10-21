<?php
/**
 * therapist.php — Therapist dashboard: start an assessment and review own records.
 * Route: /therapist
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'therapist');

$user = gars_require_role(['therapist']);
gars_track_page('page_visit', 'Therapist dashboard');

$db = gars_db();
$own = $db->prepare('SELECT COUNT(*) FROM assessments WHERE created_by = ?');
$own->execute([$user['id']]);
$ownCount = (int) $own->fetchColumn();
$allCount = (int) $db->query('SELECT COUNT(*) FROM assessments')->fetchColumn();
$recent = $db->prepare('SELECT * FROM assessments WHERE created_by = ? ORDER BY created_at DESC, id DESC LIMIT 5');
$recent->execute([$user['id']]);
$recentRows = $recent->fetchAll();

gars_header('Therapist', 'therapist');
?>
<div class="section">
    <h2>Welcome, <?php echo gars_e($user['full_name']); ?></h2>
    <div class="grid">
        <div class="card"><strong>My assessments:</strong> <?php echo $ownCount; ?></div>
        <div class="card"><strong>All records in system:</strong> <?php echo $allCount; ?></div>
        <div class="card"><strong>Role:</strong> <span class="badge therapist">Therapist</span></div>
    </div>
    <p style="margin-top:18px;">
        <a class="btn green" href="<?php echo gars_url('index'); ?>">Start a new assessment</a>
        <a class="btn" href="<?php echo gars_url('assessments'); ?>">View my records</a>
    </p>
</div>
<div class="section">
    <h2>My last 5 assessments</h2>
    <?php if (!$recentRows): ?>
        <p class="muted">No assessments yet — start one from “New Assessment”.</p>
    <?php else: ?>
        <table class="data">
            <thead><tr><th>ID</th><th>Individual</th><th>Autism Index</th><th>Probability</th><th>Severity</th><th>Saved</th></tr></thead>
            <tbody>
            <?php foreach ($recentRows as $row): ?>
                <tr>
                    <td><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">#<?php echo (int) $row['id']; ?></a></td>
                    <td><?php echo gars_e($row['individual_name']); ?></td>
                    <td><strong><?php echo (int) $row['autism_index']; ?></strong></td>
                    <td><?php echo gars_e($row['probability']); ?></td>
                    <td><?php echo gars_e($row['severity_level']); ?> — <?php echo gars_e($row['severity_desc']); ?></td>
                    <td><?php echo gars_e($row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php gars_footer(); ?>
