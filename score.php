<?php
// Maisha Bora Scoring Logic
// Route: /score — receives the POSTed rating form, validates required fields,
// stores the record in the database and logs the change in the activity trail.

// --- application bootstrap -------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/rating_form.php';
require_once __DIR__ . '/includes/scoring.php';
define('GARS_ROUTE', 'score');

$currentUser = gars_require_login();

// --- process form submission -------------------------------------------------


// --- process form submission -------------------------------------------------
$individualName = trim($_POST['individual_name'] ?? '');
$school = trim($_POST['school'] ?? '');
$raterName = trim($_POST['rater_name'] ?? '');
$raterTitle = trim($_POST['rater_title'] ?? '');
$dob = $_POST['date_of_birth'] ?? '';
$age = trim($_POST['age'] ?? '');
$gender = $_POST['gender'] ?? '';
$grade = trim($_POST['grade'] ?? '');
$examinerName = trim($_POST['examiner_name'] ?? '');
$examinerTitle = trim($_POST['examiner_title'] ?? '');
$dateOfRating = $_POST['date_of_rating'] ?? '';
$raterKnownFor = trim($_POST['rater_known_for'] ?? '');
$isMute = ($_POST['is_mute'] ?? 'no') === 'yes';

// --- REQUIRED-field validation: nothing is saved until these are filled ------
$missing = gars_validate_required([
    'individual_name' => $individualName,
    'school'          => $school,
    'rater_name'      => $raterName,
    'rater_title'     => $raterTitle,
    'date_of_birth'   => $dob,
    'age'             => $age,
    'gender'          => $gender,
    'examiner_name'   => $examinerName,
    'examiner_title'  => $examinerTitle,
    'date_of_rating'  => $dateOfRating,
], [
    'individual_name' => "Individual's Name",
    'school'          => 'School',
    'rater_name'      => "Rater's Name",
    'rater_title'     => "Rater's Title",
    'date_of_birth'   => 'Date of Birth',
    'age'             => 'Age',
    'gender'          => 'Gender',
    'examiner_name'   => "Examiner's Name",
    'examiner_title'  => "Examiner's Title",
    'date_of_rating'  => 'Date of Rating',
]);

if ($missing) {
    gars_log_activity('validation_failed', 'score', 'Assessment not saved — missing required field(s): ' . implode(', ', $missing));
    gars_track_page('page_visit', 'Blocked: missing required fields');
    http_response_code(422);
    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B5CAF">
    <?php gars_favicon_links(); ?>
    <title>Nothing was saved</title>
<?php gars_styles(); ?>
</head>
<body>
<main class="wrap narrow" id="main">
    <div class="page-head" style="padding-top:26px;">
        <h1>Nothing was saved</h1>
        <p class="sub">The record can only be scored once every required field of the identifying information is filled in. These are still empty:</p>
    </div>
    <div class="notice notice-error">
        <ul style="margin-left:18px;"><?php foreach ($missing as $m) echo '<li>' . htmlspecialchars($m, ENT_QUOTES) . '</li>'; ?></ul>
    </div>
    <button class="btn" type="button" onclick="history.back()">Go back and complete the form</button>
</main>
</body>
</html><?php
    exit;
} // data beyond this point may be saved safely

$computed = gars_compute_scores($_POST, $isMute);
$subscaleResults = $computed['subscale_results'];
$useSix = $computed['use_six'];
$sumScaled = $computed['sum_scaled'];
$compositePercentile = $computed['composite_percentile'];
$autismIndex = $computed['autism_index'];
$probability = $computed['probability'];
$severity = $computed['severity'];
$descriptor = $computed['descriptor'];

// --- save the record in the database + record the change in the audit trail --
$savedId = null;
try {
    $scaledStr = [];
    $pctStr = [];
    foreach ($subscaleResults as $s) {
        $scaledStr[] = strtoupper($s['short']) . '=' . ($s['scaled_score'] ?? '-');
        $pctStr[]    = strtoupper($s['short']) . '=' . ($s['percentile_rank'] ?? '-');
    }
    $itemScoresJson = json_encode(array_map(
        fn ($s) => [$s['short'] => $s['item_scores']],
        $subscaleResults
    ));

    $stmt = gars_db()->prepare(
        'INSERT INTO assessments
            (created_by, individual_name, school, rater_name, rater_title, date_of_birth, age, gender, grade,
             examiner_name, examiner_title, date_of_rating, rater_known_for, is_mute,
             raw_rb, raw_si, raw_sc, raw_er, raw_cs, raw_ms, scaled, percentiles, sum_scaled,
             composite_percentile, autism_index, probability, severity_level, severity_desc, item_scores)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $rawByShort = [];
    foreach ($subscaleResults as $s) {
        $rawByShort[$s['short']] = (int) $s['raw_score'];
    }
    $stmt->execute([
        $currentUser['id'],
        $individualName, $school, $raterName, $raterTitle,
        $dob !== '' ? $dob : null, $age, $gender, $grade !== '' ? $grade : null,
        $examinerName, $examinerTitle, $dateOfRating !== '' ? $dateOfRating : null, $raterKnownFor,
        $isMute ? 1 : 0,
        $rawByShort['RB'] ?? 0, $rawByShort['SI'] ?? 0, $rawByShort['SC'] ?? 0,
        $rawByShort['ER'] ?? 0, $rawByShort['CS'] ?? 0, $rawByShort['MS'] ?? 0,
        implode('|', $scaledStr), implode('|', $pctStr), $sumScaled,
        (string) $compositePercentile ?: '-', (int) $autismIndex,
        (string) $probability ?: '-', (string) $severity ?: '-', (string) $descriptor ?: '-',
        $itemScoresJson,
    ]);
    $savedId = (int) gars_db()->lastInsertId();
    gars_log_activity(
        'create_assessment',
        'score',
        "Saved Maisha Bora record #$savedId for \"$individualName\" (Autism Index " . (int) $autismIndex . ')'
    );
    $GLOBALS['GARS_SUPPRESS_PAGE_VISIT_LOG'] = true; // this POST already logged its change
    gars_track_page('page_visit', "Created record #$savedId");
} catch (Throwable $e) {
    gars_log_activity('save_failed', 'score', 'Database save failed: ' . $e->getMessage());
    $saveError = 'Scoring succeeded but the record could NOT be saved to the database: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B5CAF">
    <?php gars_favicon_links(); ?>
    <title>Scoring report</title>
    <?php gars_styles(); ?>
    <style>
        /* ---------------- scoring-report page ---------------- */
        /* the Index plate, stamped like MMRC's blue impact stats: blue ground, gold numeral */
        .plate{ background:var(--brand); color:#fff; border-radius:4px; padding:26px 30px; display:flex; gap:14px 48px; align-items:center; flex-wrap:wrap; }
        .plate-label{ display:block; font-size:.92rem; color:rgba(255,255,255,.78); }
        .plate-n{ display:block; font-family:var(--display); font-weight:700; font-size:3.6rem; line-height:1.05; color:var(--gold); }
        .plate-main{ margin-right:auto; }
        .plate-figs{ display:flex; gap:38px; flex-wrap:wrap; }
        .pfig-n{ display:block; font-family:var(--display); font-weight:700; font-size:1.8rem; line-height:1.25; font-variant-numeric:tabular-nums; }
        .pfig-l{ font-size:.84rem; color:rgba(255,255,255,.78); }

        /* the Autism Index normative band scale */
        .index-scale{ margin-top:6px; }
        .is-cap{ font-size:.85rem; font-weight:600; color:var(--ink); margin-bottom:2px; }
        .is-space{ position:relative; padding-top:42px; margin-top:4px; direction:ltr; }
        .is-chip{ position:absolute; top:0; transform:translateX(-50%); background:var(--ink); color:#fff; font-weight:600; font-size:.8rem; line-height:1; padding:6px 9px; border-radius:99px; font-variant-numeric:tabular-nums; }
        .is-chip::after{ content:''; position:absolute; left:50%; top:100%; transform:translateX(-50%); height:12px; border-left:2px solid var(--ink); }
        .is-bar{ display:flex; height:14px; border-radius:99px; overflow:hidden; }
        .is-bar i{ display:block; }
        .b-u{ width:14%; background:#1f7a4d; }
        .b-p{ width:16%; background:var(--amber); }
        .b-2{ width:30%; background:var(--orange); }
        .b-3{ width:40%; background:var(--red-deep); }
        .is-labels{ display:flex; margin-top:9px; }
        .is-labels span{ text-align:center; font-size:.76rem; line-height:1.35; color:var(--muted); }
        .is-labels b{ display:block; color:var(--ink); font-weight:600; }
        .is-labels small{ font-size:.72rem; }
        .iw-14{ width:14%; } .iw-16{ width:16%; } .iw-30{ width:30%; } .iw-40{ width:40%; }
        @media (max-width:560px){ .is-labels small{ display:none; } }

        .verdict{ font-size:1.05rem; line-height:1.65; max-width:44em; }
        .actions{ display:flex; gap:12px; margin-top:26px; flex-wrap:wrap; }

        @media (max-width:600px){
            .plate{ padding:22px; }
            .plate-n{ font-size:2.7rem; }
        }
    </style>
</head>
<body>
<?php gars_navbar('index'); ?>
<main class="wrap mid" id="main">
    <div class="page-head">
        <h1>Scoring report</h1>
        <p class="sub">Maisha Bora Autism Rating Scale, Third Edition. Scores for <?php echo htmlspecialchars($individualName) !== '' ? htmlspecialchars($individualName) : 'the rated individual'; ?>.</p>
    </div>

    <?php if (!empty($savedId)): ?>
    <div class="notice notice-success"><strong>Saved as record #<?php echo (int) $savedId; ?>.</strong>
        <a href="<?php echo gars_e(gars_url('assessment')) . '?id=' . (int) $savedId; ?>">Open the stored record</a> — the activity trail keeps a log of every page move and change.</div>
    <?php elseif (!empty($saveError)): ?>
    <div class="notice notice-error"><strong>Not saved:</strong> <?php echo htmlspecialchars($saveError, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <!-- Section 1 -->
    <div class="section">
        <h2>Section 1: Identifying information</h2>
        <div class="facts">
        <?php
        $facts = [
            "Individual's name"        => $individualName,
            'School'                   => $school,
            "Rater's name"             => $raterName,
            "Rater's title"            => $raterTitle,
            'Date of birth'            => $dob,
            'Age'                      => $age,
            'Gender'                   => $gender,
            'Grade'                    => $grade,
            "Examiner's name"          => $examinerName,
            "Examiner's title"         => $examinerTitle,
            'Date of rating'           => $dateOfRating,
            'Rater has known for'      => $raterKnownFor,
            'Mute'                     => $isMute ? 'Yes' : 'No',
        ];
        foreach ($facts as $k => $v):
            $v = trim((string) $v);
        ?>
        <div class="fact<?php echo $v === '' ? ' off' : ''; ?>">
            <span class="f-k"><?php echo gars_e($k); ?></span>
            <span class="f-v"><?php echo $v !== '' ? gars_e($v) : 'not provided'; ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Section 2 -->
    <div class="section">
        <h2>Section 2: Subscale performance</h2>
        <?php if ($isMute): ?>
        <div class="notice notice-amber">The individual is mute, so Cognitive Style and Maladaptive Speech were not completed. The composite uses the other 4 subscales.</div>
        <?php endif; ?>
        <div class="tablewrap">
        <table class="data">
            <thead>
                <tr><th>Subscale</th><th class="n">Raw score</th><th class="n">Percentile rank</th><th class="n">Scaled score</th></tr>
            </thead>
            <tbody>
                <?php foreach ($subscaleResults as $subscale):
                    $excluded = $isMute && in_array($subscale['short'], ['CS', 'MS'], true);
                ?>
                <tr>
                    <td<?php echo $excluded ? ' class="dim"' : ''; ?>>
                        <strong><?php echo gars_e($subscale['name']); ?></strong> (<?php echo gars_e($subscale['short']); ?>)
                        <?php if ($excluded): ?><small>Not completed — the individual is mute</small><?php endif; ?>
                    </td>
                    <td class="n<?php echo $excluded ? ' dim' : ''; ?>"><?php echo (int) $subscale['raw_score']; ?></td>
                    <td class="n<?php echo $excluded ? ' dim' : ''; ?>"><?php echo gars_e($subscale['percentile_rank'] ?? 'n/a'); ?></td>
                    <td class="n<?php echo $excluded ? ' dim' : ''; ?>"><?php echo gars_e($subscale['scaled_score'] ?? 'n/a'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Section 3 -->
    <div class="section">
        <h2>Section 3: Composite performance</h2>
        <?php if ($autismIndex !== null): ?>
        <?php
        $bandPosRaw = min(100, max(0, ($autismIndex - 40) / 100 * 100));
        $bandPos = round($bandPosRaw, 1);
        $chipPos = min(94, max(6, $bandPos));
        ?>
        <div class="plate" role="group" aria-label="Composite results">
            <div class="plate-main">
                <span class="plate-label">Autism Index</span>
                <span class="plate-n"><?php echo (int) $autismIndex; ?></span>
            </div>
            <div class="plate-figs">
                <div class="pfig">
                    <span class="pfig-n"><?php echo $sumScaled; ?></span>
                    <span class="pfig-l">Sum of scaled scores, <?php echo $useSix ? '6' : '4'; ?> subscales</span>
                </div>
                <div class="pfig">
                    <span class="pfig-n"><?php echo gars_e($compositePercentile ?? 'n/a'); ?></span>
                    <span class="pfig-l">Percentile rank</span>
                </div>
            </div>
        </div>

        <div class="index-scale" aria-hidden="true">
            <div class="is-cap">Where this index falls on the standard score scale (mean 100, scale 40–140)</div>
            <div class="is-space">
                <span class="is-chip" style="left:<?php echo $chipPos; ?>%"><?php echo (int) $autismIndex; ?></span>
                <div class="is-bar">
                    <i class="b-u"></i><i class="b-p"></i><i class="b-2"></i><i class="b-3"></i>
                </div>
            </div>
            <div class="is-labels">
                <span class="iw-14"><b>Unlikely</b><small>not ASD</small></span>
                <span class="iw-16"><b>Probable</b><small>Level 1</small></span>
                <span class="iw-30"><b>Very likely</b><small>Level 2</small></span>
                <span class="iw-40"><b>Very likely</b><small>Level 3</small></span>
            </div>
        </div>
        <?php else: ?>
        <p class="empty">The composite could not be determined from the sum of scaled scores.</p>
        <?php endif; ?>
    </div>

    <!-- Section 4 -->
    <div class="section">
        <h2>Section 4: Interpretation</h2>
        <?php if ($autismIndex !== null):
            $probWord = strtolower((string) $probability);
            if ($severity === 'Not ASD'):
        ?>
        <p class="verdict">The probability of an autism spectrum disorder is <strong><?php echo gars_e($probWord); ?></strong>:
            the profile does not meet the DSM-5 criteria for ASD, so no severity level is assigned.</p>
        <?php else: ?>
        <p class="verdict">The probability of an autism spectrum disorder is <strong><?php echo gars_e($probWord); ?></strong>,
            corresponding to DSM-5 severity <strong><?php echo gars_e(strtolower((string) $severity)); ?></strong> (<?php echo gars_e((string) $descriptor); ?>).</p>
        <?php endif; ?>
        <?php endif; ?>

        <div class="tablewrap" style="margin-top:18px;">
        <table class="data">
            <thead>
                <tr><th>Autism Index</th><th>Probability of ASD</th><th>DSM-5 severity level</th><th>Descriptor</th></tr>
            </thead>
            <tbody>
                <tr><td>54 or below</td><td><span class="badge on">Unlikely</span></td><td>Not ASD</td><td>Not ASD</td></tr>
                <tr><td>55–70</td><td><span class="badge warn">Probable</span></td><td>Level 1</td><td>Minimal support required</td></tr>
                <tr><td>71–100</td><td><span class="badge off">Very likely</span></td><td>Level 2</td><td>Requiring substantial support</td></tr>
                <tr><td>101 and above</td><td><span class="badge off">Very likely</span></td><td>Level 3</td><td>Requiring very substantial support</td></tr>
            </tbody>
        </table>
        </div>

        <div class="actions">
            <a href="<?php echo gars_e(gars_url('index')); ?>" class="btn">Start a new assessment</a>
            <a href="<?php echo gars_e(gars_url('assessments')); ?>" class="btn ghost">All records</a>
        </div>
    </div>
</main>
</body>
</html>
