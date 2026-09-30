<?php
// GARS-3 Scoring Logic

$subscales = [
    ["id" => "rb", "name" => "Restricted/Repetitive Behaviors", "short" => "RB"],
    ["id" => "si", "name" => "Social Interaction", "short" => "SI"],
    ["id" => "sc", "name" => "Social Communication", "short" => "SC"],
    ["id" => "er", "name" => "Emotional Responses", "short" => "ER"],
    ["id" => "cs", "name" => "Cognitive Style", "short" => "CS"],
    ["id" => "ms", "name" => "Maladaptive Speech", "short" => "MS"],
];

$questions = [
    "rb" => 13, "si" => 13, "sc" => 9, "er" => 8, "cs" => 7, "ms" => 7,
];

// Table A: Raw Score Ranges -> Scaled Score & Percentile Rank
// [scaled_score, percentile_rank, RB_range, SI_range, SC_range, ER_range, CS_range, MS_range]
$tableA = [
    [1, "<1", null, null, null, null, null, null],
    [2, "<1", null, null, [0,1], null, null, null],
    [3, "1", null, [0,0], [2,4], [0,1], null, null],
    [3, "2", [0,3], [1,4], [3,5], [2,4], null, null],
    [5, "3", [4,6], [5,8], [9,11], [5,6], [0,0], [0,0]],
    [6, "9", [7,9], [9,12], [12,13], [7,8], [1,1], [1,2]],
    [7, "16", [10,13], [13,15], [14,16], [9,10], [0,3], [3,4]],
    [8, "25", [14,16], [16,19], [17,18], [11,12], [4,8], [5,5]],
    [9, "32", [17,19], [20,23], [19,21], [13,14], [6,9], [6,7]],
    [10, "50", [20,22], [24,27], [22,23], [15,16], [9,10], [8,9]],
    [11, "63", [23,26], [28,30], [24,25], [17,18], [11,13], [10,11]],
    [12, "75", [27,29], [31,34], [26,27], [19,20], [14,16], [12,13]],
    [13, "84", [30,32], [35,38], null, [21,22], [16,17], [14,15]],
    [14, "91", [33,36], [39,42], null, [23,24], [18,19], [16,18]],
    [15, "95", [37,39], null, null, null, [20,21], [17,18]],
    [16, "98", null, null, null, null, [19,20], [19,20]],
    [17, "99", null, null, null, null, [21,21], [21,21]],
    [18, ">99", null, null, null, null, null, [18,18]],
    [19, ">99", null, null, null, null, null, [19,19]],
    [20, ">99", null, null, null, null, null, [20,20]],
];

// Table B: Sum of Scaled Scores -> Percentile Rank & Autism Index
// [percentile_rank, sum_4_range, sum_6_range, autism_index]
$tableB = [
    [">99", null, [87,87], 140],
    [">99", null, [86,86], 139],
    [">99", null, [85,85], 137],
    [">99", null, [84,84], 136],
    ["99", null, [83,83], 134],
    ["99", null, [82,82], 133],
    ["99", null, [81,81], 131],
    ["98", null, [80,80], 130],
    ["98", null, [79,79], 128],
    ["97", null, [78,78], 127],
    ["96", [55,55], null, 126],
    ["95", [54,54], [77,77], 125],
    ["95", null, [76,76], 124],
    ["94", [53,53], null, 123],
    ["93", null, [75,75], 122],
    ["92", [52,52], [74,74], 121],
    ["91", null, [73,73], 120],
    ["90", [51,51], null, 119],
    ["89", [50,50], [72,72], 118],
    ["87", null, [71,71], 117],
    ["86", [49,49], null, 116],
    ["84", null, [70,70], 115],
    ["82", [48,48], [69,69], 114],
    ["79", [47,47], [68,68], 112],
    ["77", [46,46], [67,67], 111],
    ["73", [45,45], [66,66], 109],
    ["70", null, [65,65], 108],
    ["68", [44,44], null, 107],
    ["65", [43,43], [64,64], 106],
    ["63", null, [63,63], 105],
    ["61", [42,42], null, 104],
    ["58", null, [62,62], 103],
    ["55", [41,41], [61,61], 102],
    ["50", [40,40], [60,60], 100],
    ["47", [39,39], [59,59], 99],
    ["42", [38,38], [58,58], 97],
    ["39", null, [57,57], 96],
    ["37", [37,37], null, 95],
    ["35", null, [56,56], 94],
    ["32", [36,36], [55,55], 93],
    ["29", [35,35], [54,54], 92],
    ["25", [34,34], [53,53], 90],
    ["23", null, [52,52], 89],
    ["21", [33,33], null, 88],
    ["18", [32,32], [50,50], 86],
    ["16", [31,31], null, 85],
    ["14", null, [49,49], 84],
    ["13", [30,30], [48,48], 83],
    ["10", [29,29], [47,47], 81],
    ["9", null, [46,46], 80],
    ["8", [28,28], null, 79],
    ["7", [27,27], [45,45], 78],
    ["6", null, [44,44], 77],
    ["5", [26,26], null, 76],
    ["5", null, [43,43], 75],
    ["4", [25,25], [42,42], 74],
    ["3", [24,24], null, 73],
    ["3", null, [41,41], 72],
    ["3", [23,23], [40,40], 71],
    ["2", [22,22], [39,39], 69],
    ["1", null, [38,38], 68],
    ["1", [21,21], null, 67],
    ["1", [20,20], [37,37], 66],
    ["1", null, [36,36], 65],
    ["<1", [19,19], null, 64],
    ["<1", null, [35,35], 63],
    ["<1", [18,18], [34,34], 62],
    ["<1", null, [33,33], 61],
    ["<1", [17,17], null, 60],
    ["<1", [16,16], [32,32], 59],
    ["<1", null, [31,31], 58],
    ["<1", [15,15], null, 57],
    ["<1", null, [30,30], 56],
    ["<1", [14,14], [29,29], 55],
    ["<1", [13,13], [28,28], 53],
    ["<1", [12,12], [27,27], 52],
    ["<1", [12,12], [26,26], 50],
    ["<1", null, [25,25], 49],
    ["<1", null, [24,24], 47],
    ["<1", null, [23,23], 46],
    ["<1", null, [22,22], 44],
    ["<1", null, [21,21], 43],
];

function getScaledScoreAndPercentile($rawScore, $subscaleShort) {
    global $tableA;
    $idxMap = ["RB" => 2, "SI" => 3, "SC" => 4, "ER" => 5, "CS" => 6, "MS" => 7];
    $idx = $idxMap[$subscaleShort] ?? null;
    if ($idx === null) return [null, null];

    foreach ($tableA as $entry) {
        $range = $entry[$idx];
        if ($range !== null && $range[0] <= $rawScore && $rawScore <= $range[1]) {
            return [$entry[0], $entry[1]];
        }
    }
    return [null, null];
}

function getCompositeResults($sumScaledScores, $useSix) {
    global $tableB;
    foreach ($tableB as $entry) {
        $range = $useSix ? $entry[2] : $entry[1];
        if ($range !== null && $range[0] <= $sumScaledScores && $sumScaledScores <= $range[1]) {
            return [$entry[0], $entry[3]];
        }
    }
    return [null, null];
}

function getInterpretation($autismIndex) {
    if ($autismIndex === null) return [null, null, null];
    if ($autismIndex <= 54) return ["Unlikely", "Not ASD", "Not ASD"];
    if ($autismIndex <= 70) return ["Probable", "Level 1", "Minimal Support Required"];
    if ($autismIndex <= 100) return ["Very Likely", "Level 2", "Requiring Substantial Support"];
    return ["Very Likely", "Level 3", "Requiring Very Substantial Support"];
}

// Process form submission
$individualName = $_POST['individual_name'] ?? '';
$school = $_POST['school'] ?? '';
$raterName = $_POST['rater_name'] ?? '';
$raterTitle = $_POST['rater_title'] ?? '';
$dob = $_POST['date_of_birth'] ?? '';
$age = $_POST['age'] ?? '';
$gender = $_POST['gender'] ?? '';
$grade = $_POST['grade'] ?? '';
$examinerName = $_POST['examiner_name'] ?? '';
$examinerTitle = $_POST['examiner_title'] ?? '';
$dateOfRating = $_POST['date_of_rating'] ?? '';
$raterKnownFor = $_POST['rater_known_for'] ?? '';
$isMute = ($_POST['is_mute'] ?? 'no') === 'yes';

$subscaleResults = [];
foreach ($subscales as $subscale) {
    $rawScore = 0;
    $itemScores = [];
    $numQuestions = $questions[$subscale['id']];
    for ($i = 0; $i < $numQuestions; $i++) {
        $fieldName = $subscale['id'] . '_item_' . $i;
        $score = isset($_POST[$fieldName]) ? intval($_POST[$fieldName]) : 0;
        $itemScores[] = $score;
        $rawScore += $score;
    }
    [$scaledScore, $percentileRank] = getScaledScoreAndPercentile($rawScore, $subscale['short']);
    $subscaleResults[] = [
        'id' => $subscale['id'],
        'name' => $subscale['name'],
        'short' => $subscale['short'],
        'raw_score' => $rawScore,
        'scaled_score' => $scaledScore,
        'percentile_rank' => $percentileRank,
        'item_scores' => $itemScores,
    ];
}

if ($isMute) {
    $includedSubscales = array_slice($subscaleResults, 0, 4);
    $useSix = false;
} else {
    $includedSubscales = $subscaleResults;
    $useSix = true;
}

$sumScaled = 0;
foreach ($includedSubscales as $s) {
    if ($s['scaled_score'] !== null) $sumScaled += $s['scaled_score'];
}

[$compositePercentile, $autismIndex] = getCompositeResults($sumScaled, $useSix);
[$probability, $severity, $descriptor] = getInterpretation($autismIndex);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GARS-3 Results</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .header { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 2em; margin-bottom: 10px; }
        .section { padding: 25px 30px; border-bottom: 1px solid #eee; }
        .section h2 { color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 3px solid #3498db; display: inline-block; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border-left: 4px solid #3498db; }
        .info-item label { display: block; font-size: 0.8em; color: #666; margin-bottom: 3px; }
        .info-item span { font-weight: 600; color: #2c3e50; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #2c3e50; color: white; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        .raw-score { font-weight: bold; color: #e74c3c; }
        .scaled-score { font-weight: bold; color: #27ae60; }
        .percentile { font-weight: bold; color: #3498db; }
        .composite-box { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 25px; border-radius: 8px; text-align: center; margin: 20px 0; }
        .composite-box h3 { font-size: 1.3em; margin-bottom: 15px; }
        .composite-scores { display: flex; justify-content: center; gap: 40px; flex-wrap: wrap; }
        .composite-item { text-align: center; }
        .composite-item .value { font-size: 2.5em; font-weight: bold; display: block; }
        .composite-item .label { font-size: 0.9em; opacity: 0.9; }
        .interpretation-box { padding: 20px; border-radius: 8px; margin: 15px 0; }
        .interpretation-box.likely { background: #d4edda; border: 2px solid #28a745; }
        .interpretation-box.probable { background: #fff3cd; border: 2px solid #ffc107; }
        .interpretation-box.unlikely { background: #f8d7da; border: 2px solid #dc3545; }
        .interpretation-box h3 { margin-bottom: 10px; }
        .interpretation-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }
        .interpretation-item { background: rgba(255,255,255,0.7); padding: 15px; border-radius: 6px; text-align: center; }
        .interpretation-item .value { font-size: 1.3em; font-weight: bold; color: #2c3e50; }
        .interpretation-item .label { font-size: 0.85em; color: #666; margin-top: 5px; }
        .back-btn { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s; }
        .back-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4); }
        .mute-notice { background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px; padding: 15px; margin-bottom: 15px; color: #856404; }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { padding: 20px 15px; }
            .header h1 { font-size: 1.4em; }
            .section { padding: 15px; }
            .section h2 { font-size: 1.2em; }
            .info-grid { grid-template-columns: 1fr; }
            table { font-size: 0.85em; }
            th, td { padding: 8px 10px; }
            .composite-box { padding: 15px; }
            .composite-scores { gap: 20px; }
            .composite-item .value { font-size: 1.8em; }
            .interpretation-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .header h1 { font-size: 1.2em; }
            th, td { padding: 6px 8px; font-size: 0.8em; }
            .composite-item .value { font-size: 1.5em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>GARS-3 Results</h1>
            <p>Scoring Report</p>
        </div>

        <!-- Section 1: Identifying Information -->
        <div class="section">
            <h2>Section 1: Identifying Information</h2>
            <div class="info-grid">
                <div class="info-item"><label>Individual's Name</label><span><?php echo htmlspecialchars($individualName) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>School</label><span><?php echo htmlspecialchars($school) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Rater's Name</label><span><?php echo htmlspecialchars($raterName) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Rater's Title</label><span><?php echo htmlspecialchars($raterTitle) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Date of Birth</label><span><?php echo htmlspecialchars($dob) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Age</label><span><?php echo htmlspecialchars($age) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Gender</label><span><?php echo htmlspecialchars($gender) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Grade</label><span><?php echo htmlspecialchars($grade) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Examiner's Name</label><span><?php echo htmlspecialchars($examinerName) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Examiner's Title</label><span><?php echo htmlspecialchars($examinerTitle) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Date of Rating</label><span><?php echo htmlspecialchars($dateOfRating) ?: 'Not provided'; ?></span></div>
                <div class="info-item"><label>Rater Known For</label><span><?php echo htmlspecialchars($raterKnownFor) ?: 'Not provided'; ?></span></div>
            </div>
        </div>

        <!-- Section 2: Subscale Performance -->
        <div class="section">
            <h2>Section 2: Subscale Performance</h2>
            <?php if ($isMute): ?>
            <div class="mute-notice">
                <strong>Note:</strong> The individual is mute. Cognitive Style and Maladaptive Speech subscales were not completed. Only 4 subscales were used for composite scoring.
            </div>
            <?php endif; ?>
            <table>
                <thead>
                    <tr><th>Subscale</th><th>Raw Score</th><th>Percentile Rank</th><th>Scaled Score</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subscaleResults as $subscale): ?>
                    <tr <?php if ($isMute && in_array($subscale['short'], ['CS', 'MS'])): ?>style="opacity: 0.5;"<?php endif; ?>>
                        <td>
                            <strong><?php echo htmlspecialchars($subscale['name']); ?></strong> (<?php echo $subscale['short']; ?>)
                            <?php if ($isMute && in_array($subscale['short'], ['CS', 'MS'])): ?>
                            <br><small style="color: #999;">Not completed (mute)</small>
                            <?php endif; ?>
                        </td>
                        <td class="raw-score"><?php echo $subscale['raw_score']; ?></td>
                        <td class="percentile"><?php echo $subscale['percentile_rank'] ?? 'N/A'; ?></td>
                        <td class="scaled-score"><?php echo $subscale['scaled_score'] ?? 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 3: Composite Performance -->
        <div class="section">
            <h2>Section 3: Composite Performance</h2>
            <div class="composite-box">
                <h3>Sum of Scaled Scores (<?php echo $useSix ? '6 Subscales' : '4 Subscales'; ?>)</h3>
                <div class="composite-scores">
                    <div class="composite-item">
                        <span class="value"><?php echo $sumScaled; ?></span>
                        <span class="label">Sum of Scaled Scores</span>
                    </div>
                    <div class="composite-item">
                        <span class="value"><?php echo $compositePercentile ?? 'N/A'; ?></span>
                        <span class="label">Percentile Rank</span>
                    </div>
                    <div class="composite-item">
                        <span class="value"><?php echo $autismIndex ?? 'N/A'; ?></span>
                        <span class="label">Autism Index</span>
                    </div>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Subscale</th><th>Scaled Score</th><th>Included in Composite</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subscaleResults as $subscale): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($subscale['name']); ?></strong> (<?php echo $subscale['short']; ?>)</td>
                        <td class="scaled-score"><?php echo $subscale['scaled_score'] ?? 'N/A'; ?></td>
                        <td>
                            <?php
                            $included = false;
                            foreach ($includedSubscales as $inc) {
                                if ($inc['id'] === $subscale['id']) { $included = true; break; }
                            }
                            if ($included): ?>
                                <span style="color: #27ae60; font-weight: bold;">&#10003; Yes</span>
                            <?php else: ?>
                                <span style="color: #999;">&#10007; No (excluded)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 4: Interpretation Guide -->
        <div class="section">
            <h2>Section 4: Interpretation Guide</h2>
            <?php if ($autismIndex !== null): ?>
                <?php
                $boxClass = 'likely';
                if ($autismIndex <= 54) $boxClass = 'unlikely';
                elseif ($autismIndex <= 70) $boxClass = 'probable';
                ?>
                <div class="interpretation-box <?php echo $boxClass; ?>">
                    <h3>Autism Index: <?php echo $autismIndex; ?></h3>
                    <div class="interpretation-grid">
                        <div class="interpretation-item">
                            <div class="value"><?php echo $probability; ?></div>
                            <div class="label">Probability of ASD</div>
                        </div>
                        <div class="interpretation-item">
                            <div class="value"><?php echo $severity; ?></div>
                            <div class="label">DSM-5 Severity Level</div>
                        </div>
                        <div class="interpretation-item">
                            <div class="value"><?php echo $descriptor; ?></div>
                            <div class="label">Descriptor</div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p style="color: #999; font-style: italic;">Unable to determine interpretation. Please check the sum of scaled scores.</p>
            <?php endif; ?>

            <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <h4 style="color: #2c3e50; margin-bottom: 10px;">Interpretation Reference</h4>
                <table>
                    <thead>
                        <tr><th>Autism Index</th><th>Probability of ASD</th><th>DSM-5 Severity Level</th><th>Descriptor</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>&le;54</td><td>Unlikely</td><td>Not ASD</td><td>Not ASD</td></tr>
                        <tr><td>55-70</td><td>Probable</td><td>Level 1</td><td>Minimal Support Required</td></tr>
                        <tr><td>71-100</td><td>Very Likely</td><td>Level 2</td><td>Requiring Substantial Support</td></tr>
                        <tr><td>&ge;101</td><td>Very Likely</td><td>Level 3</td><td>Requiring Very Substantial Support</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="padding: 20px 30px; text-align: center;">
            <a href="index.php" class="back-btn">Start New Assessment</a>
        </div>
    </div>
</body>
</html>
