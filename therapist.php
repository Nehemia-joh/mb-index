<?php
/**
 * therapist.php — Therapist dashboard: start an assessment and review own records.
 * Route: /therapist
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
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
<div class="page-head">
    <h1>Welcome, <?php echo gars_e($user['full_name']); ?></h1>
    <p class="sub">Your rating form and your stored records.</p>
</div>

<div class="stats">
    <div class="stat"><span class="s-n"><?php echo $ownCount; ?></span><span class="s-l">My assessments</span></div>
    <div class="stat"><span class="s-n"><?php echo $allCount; ?></span><span class="s-l">Records in the system</span></div>
    <div class="stat"><span class="s-n">Therapist</span><span class="s-l">Your role</span></div>
</div>

<div class="section">
    <div class="actions" style="margin-top:0;">
        <a class="btn" href="<?php echo gars_url('index'); ?>">Start a new assessment</a>
        <a class="btn ghost" href="<?php echo gars_url('assessments'); ?>">View my records</a>
    </div>
</div>

<div class="section">
    <h2>My last 5 assessments</h2>
    <?php if (!$recentRows): ?>
        <p class="empty"><b>No assessments yet.</b> Start the first one from <a href="<?php echo gars_url('index'); ?>">New assessment</a>.</p>
    <?php else: ?>
        <div class="tablewrap">
        <table class="data">
            <thead><tr><th class="n">ID</th><th>Individual</th><th class="n">Index</th><th>Probability</th><th>Severity</th><th>Saved</th></tr></thead>
            <tbody>
            <?php foreach ($recentRows as $row): ?>
                <tr>
                    <td class="n"><a class="a-link" href="<?php echo gars_url('assessment') . '?id=' . (int) $row['id']; ?>">#<?php echo (int) $row['id']; ?></a></td>
                    <td><strong><?php echo gars_e($row['individual_name']); ?></strong></td>
                    <td class="n"><strong><?php echo (int) $row['autism_index']; ?></strong></td>
                    <td><span class="badge <?php echo gars_prob_class($row['probability']); ?>"><?php echo gars_e($row['probability']); ?></span></td>
                    <td><?php echo gars_e($row['severity_level'] . ' ' . $row['severity_desc']); ?></td>
                    <td><?php echo gars_e($row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php gars_footer(); ?>