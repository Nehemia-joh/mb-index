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
    echo '<div class="section"><h2>Record not found</h2><p class="muted">Record #' . $id . ' does not exist, or it was saved by another therapist.</p><p style="margin-top:14px;"><a class="btn" href="' . gars_url('assessments') . '">Back to records</a></p></div>';
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

$autismIndex = (int) $rec['autism_index'];
$bandPosRaw = min(100, max(0, ($autismIndex - 40) / 100 * 100));
$bandPos = round($bandPosRaw, 1);
$chipPos = min(94, max(6, $bandPos));

gars_header('Record #' . $id, 'assessment');
?>
<style>
    .plate{ background:var(--brand); color:#fff; border-radius:4px; padding:22px 26px; display:flex; gap:12px 40px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
    .plate-label{ display:block; font-size:.9rem; color:rgba(255,255,255,.78); }
    .plate-n{ display:block; font-family:var(--display); font-weight:700; font-size:2.8rem; line-height:1.05; color:var(--gold); }
    .plate-main{ margin-right:auto; }
    .plate-figs{ display:flex; gap:32px; flex-wrap:wrap; }
    .pfig-n{ display:block; font-family:var(--display); font-weight:700; font-size:1.5rem; line-height:1.25; font-variant-numeric:tabular-nums; }
    .pfig-l{ font-size:.82rem; color:rgba(255,255,255,.78); }
    .is-space{ position:relative; padding-top:40px; }
    .is-chip{ position:absolute; top:0; transform:translateX(-50%); background:var(--ink); color:#fff; font-weight:600; font-size:.78rem; line-height:1; padding:5px 8px; border-radius:99px; }
    .is-chip::after{ content:''; position:absolute; left:50%; top:100%; transform:translateX(-50%); height:11px; border-left:2px solid var(--ink); }
    .is-bar{ display:flex; height:13px; border-radius:99px; overflow:hidden; }
    .is-bar i{ display:block; }
    .b-u{ width:14%; background:#1f7a4d; }
    .b-p{ width:16%; background:var(--amber); }
    .b-2{ width:30%; background:var(--orange); }
    .b-3{ width:40%; background:var(--red-deep); }
    .is-labels{ display:flex; margin-top:8px; }
    .is-labels span{ text-align:center; font-size:.74rem; line-height:1.35; color:var(--muted); }
    .is-labels b{ display:block; color:var(--ink); font-weight:600; }
    .iw-14{ width:14%; } .iw-16{ width:16%; } .iw-30{ width:30%; } .iw-40{ width:40%; }
    .actions{ display:flex; gap:12px; margin-top:22px; flex-wrap:wrap; }
    @media (max-width:560px){ .is-labels small{ display:none; } }
</style>

<div class="page-head">
    <h1>Record #<?php echo (int) $id; ?> — <?php echo gars_e($rec['individual_name']); ?></h1>
    <p class="sub">Saved by <?php echo gars_e($rec['creator'] ?? 'unknown'); ?> on <?php echo gars_e($rec['created_at']); ?>.
        <span class="badge <?php echo gars_prob_class($rec['probability']); ?>"><?php echo gars_e($rec['probability']); ?></span></p>
</div>

<div class="sheet">

    <div class="section" style="border-top:none;">
        <?php
        $facts = [
            'Individual'        => $rec['individual_name'],
            'School'            => $rec['school'],
            'Date of birth'     => $rec['date_of_birth'] ?? '-',
            'Age / gender'      => trim($rec['age'] . ' / ' . $rec['gender'], ' /'),
            'Grade'             => $rec['grade'],
            'Rater'             => $rec['rater_name'] . ($rec['rater_title'] !== '' ? ' (' . $rec['rater_title'] . ')' : ''),
            'Examiner'          => $rec['examiner_name'],
            'Date of rating'    => $rec['date_of_rating'] ?? '-',
            'Mute'              => $isMute ? 'Yes' : 'No',
        ];
        ?>
        <div class="facts">
        <?php foreach ($facts as $k => $v):
            $v = trim((string) $v);
        ?>
        <div class="fact<?php echo $v === '' || $v === '-' ? ' off' : ''; ?>">
            <span class="f-k"><?php echo gars_e($k); ?></span>
            <span class="f-v"><?php echo $v !== '' && $v !== '-' ? gars_e($v) : 'not provided'; ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="section">
        <h2>Subscale scores</h2>
        <div class="tablewrap">
        <table class="data">
            <thead><tr><th>Subscale</th><th class="n">Raw score</th><th class="n">Scaled score</th><th class="n">Percentile rank</th></tr></thead>
            <tbody>
            <?php foreach ($shownSubscales as $k): ?>
                <tr>
                    <td><?php echo gars_e($subscales[$k] ?? $k); ?> (<?php echo gars_e($k); ?>)</td>
                    <td class="n"><?php echo (int) $rec['raw_' . strtolower($k)]; ?></td>
                    <td class="n"><?php echo gars_e($scaled[$k] ?? '-'); ?></td>
                    <td class="n"><?php echo gars_e($percents[$k] ?? '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($isMute): ?>
        <p class="muted" style="padding:12px 14px;">Cognitive Style and Maladaptive Speech were not completed (the individual is mute), so the composite uses 4 subscales.</p>
        <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <h2>Composite</h2>
        <div class="plate" role="group" aria-label="Composite results">
            <div class="plate-main">
                <span class="plate-label">Autism Index</span>
                <span class="plate-n"><?php echo $autismIndex; ?></span>
            </div>
            <div class="plate-figs">
                <div class="pfig">
                    <span class="pfig-n"><?php echo (int) $rec['sum_scaled']; ?></span>
                    <span class="pfig-l">Sum of scaled scores</span>
                </div>
                <div class="pfig">
                    <span class="pfig-n"><?php echo gars_e($rec['composite_percentile']); ?></span>
                    <span class="pfig-l">Percentile rank</span>
                </div>
            </div>
        </div>

        <div class="index-scale" aria-hidden="true">
            <div class="is-space">
                <span class="is-chip" style="left:<?php echo $chipPos; ?>%"><?php echo $autismIndex; ?></span>
                <div class="is-bar">
                    <i class="b-u"></i><i class="b-p"></i><i class="b-2"></i><i class="b-3"></i>
                </div>
            </div>
            <div class="is-labels">
                <span class="iw-14"><b>Unlikely</b></span>
                <span class="iw-16"><b>Probable</b></span>
                <span class="iw-30"><b>Very likely, Level 2</b></span>
                <span class="iw-40"><b>Very likely, Level 3</b></span>
            </div>
        </div>

        <p style="margin-top:16px;"><?php echo gars_e($rec['probability'] === 'Not ASD'
            ? 'The probability of an autism spectrum disorder is unlikely; no DSM-5 severity level is assigned.'
            : 'DSM-5 severity ' . strtolower((string) $rec['severity_level']) . ' (' . $rec['probability'] . '): ' . $rec['severity_desc'] . '.'); ?></p>
    </div>
</div>

<div class="actions">
    <a class="btn" href="<?php echo gars_e(gars_url('edit')); ?>?id=<?php echo $id; ?>">Edit and recalculate</a>
    <a class="btn ghost" href="<?php echo gars_e(gars_url('assessments')); ?>">Back to records</a>
    <a class="btn ghost" href="<?php echo gars_e(gars_url('index')); ?>">New assessment</a>
</div>
<?php gars_footer(); ?>