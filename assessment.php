<?php
/**
 * assessment.php — view one stored Maisha Bora record.
 * Route: /assessment?id=123
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'assessment');

$user = gars_require_login();
gars_track_page('page_visit', 'Assessment record');

$id = (int) ($_GET['id'] ?? 0);
$db = gars_db();
$stmt = $db->prepare('SELECT a.*, u.username AS creator FROM assessments a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?');
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec || ($user['role'] === 'therapist' && (int) $rec['created_by'] !== (int) $user['id'])) {
    http_response_code(404);
    gars_header('Record not found', 'assessment', false);
    echo '<div class="section"><h2>Record not found</h2><p class="card">Record #' . $id . ' does not exist (or was saved by another therapist).</p><p><a class="btn" href="' . gars_url('assessments') . '">Back to records</a></p></div>';
    gars_footer();
    exit;
}

$scaled = [];
$percents = [];
foreach (explode('|', $rec['scaled'] ?? '') as $part) {
    $parts = explode('=', $part, 2);
    if (count($parts) === 2) {
        $scaled[$parts[0]] = $parts[1];
    }
}
foreach (explode('|', $rec['percentiles'] ?? '') as $part) {
    $parts = explode('=', $part, 2);
    if (count($parts) === 2) {
        $percents[$parts[0]] = $parts[1];
    }
}
$subscales = ['RB' => 'Restricted/Repetitive Behaviors', 'SI' => 'Social Interaction', 'SC' => 'Social Communication', 'ER' => 'Emotional Responses', 'CS' => 'Cognitive Style', 'MS' => 'Maladaptive Speech'];
$isMute = (int) $rec['is_mute'] === 1;
$shownSubscales = $isMute ? ['RB', 'SI', 'SC', 'ER'] : array_keys($subscales);

gars_header('Record #' . $id, 'assessment');
?>
<div class="section">
    <h2>Maisha Bora record #<?php echo (int) $id; ?>
        <span class="badge <?php echo gars_e($rec['severity_level'] === 'Not ASD' ? 'on' : 'off'); ?>"><?php echo gars_e($rec['probability']); ?></span>
    </h2>
    <p class="muted">Saved by <?php echo gars_e($rec['creator'] ?? 'unknown'); ?> on <?php echo gars_e($rec['created_at']); ?></p>
    <div class="grid" style="margin-top:16px;">
        <div class="card"><strong>Individual:</strong> <?php echo gars_e($rec['individual_name']); ?></div>
        <div class="card"><strong>School:</strong> <?php echo gars_e($rec['school']); ?></div>
        <div class="card"><strong>Date of birth:</strong> <?php echo gars_e($rec['date_of_birth'] ?? '-'); ?></div>
        <div class="card"><strong>Age / Gender:</strong> <?php echo gars_e($rec['age']); ?> / <?php echo gars_e($rec['gender']); ?></div>
        <div class="card"><strong>Rater:</strong> <?php echo gars_e($rec['rater_name']); ?> (<?php echo gars_e($rec['rater_title']); ?>)</div>
        <div class="card"><strong>Examiner:</strong> <?php echo gars_e($rec['examiner_name']); ?></div>
        <div class="card"><strong>Date of rating:</strong> <?php echo gars_e($rec['date_of_rating'] ?? '-'); ?></div>
        <div class="card"><strong>Mute:</strong> <?php echo $isMute ? 'Yes' : 'No'; ?></div>
    </div>
</div>
<div class="section">
    <h2>Subscale scores</h2>
    <table class="data">
        <thead><tr><th>Subscale</th><th>Raw score</th><th>Scaled score</th><th>Percentile rank</th></tr></thead>
        <tbody>
        <?php foreach ($shownSubscales as $k): ?>
            <tr>
                <td><?php echo gars_e($subscales[$k] ?? $k); ?> (<?php echo gars_e($k); ?>)</td>
                <td class="raw-score" style="font-weight:bold;color:#dc2626;"><?php echo (int) $rec['raw_' . strtolower($k)]; ?></td>
                <td style="font-weight:bold;color:#a97b00;"><?php echo gars_e($scaled[$k] ?? '-'); ?></td>
                <td style="font-weight:bold;color:#0B5CAF;"><?php echo gars_e($percents[$k] ?? '-'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card" style="margin-top:18px; text-align:center;">
        <div class="muted">Composite (sum of scaled scores: <?php echo (int) $rec['sum_scaled']; ?>) · Percentile: <?php echo gars_e($rec['composite_percentile']); ?></div>
        <div style="font-size:2.4em; font-weight:800; color:#2c3e50;">Autism Index <?php echo (int) $rec['autism_index']; ?></div>
        <div><strong><?php echo gars_e($rec['severity_level']); ?></strong> — <?php echo gars_e($rec['severity_desc']); ?> · Probability of ASD: <strong><?php echo gars_e($rec['probability']); ?></strong></div>
    </div>
    <p><a class="btn" href="<?php echo gars_url('assessments'); ?>">Back to records</a>
       <a class="btn green" href="<?php echo gars_url('index'); ?>">New assessment</a></p>
</div>
<?php gars_footer(); ?>
