<?php
/**
 * includes/scoring.php — the Maisha Bora conversion and interpretation engine.
 *
 * Table A: raw subscale score -> scaled score + percentile rank.
 * Table B: sum of scaled scores -> percentile rank + Autism Index.
 * Interpretation bands: Autism Index -> probability / severity / descriptor.
 *
 * The constants below are the published instrument and the built-in fallback.
 * Administrators may propose edits to any of the three tables on
 * /scoring-tables; a change only takes effect globally after ANOTHER
 * admin/it_admin approves it (an editor can never approve their own change).
 * Approved revisions live in the scoring_revisions table and are loaded here.
 *
 * Shared by score.php (first save) and edit.php (recalculation).
 */

require_once __DIR__ . '/rating_form.php'; // gars_subscale_defs()
require_once __DIR__ . '/db.php';          // gars_db() — for the active-revision lookup

const GARS_TABLE_A = [
    // [scaled_score, percentile_rank, RB_range, SI_range, SC_range, ER_range, CS_range, MS_range]
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

const GARS_TABLE_B = [
    // [percentile_rank, sum_4_range, sum_6_range, autism_index]
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

/** The published interpretation bands (Autism Index upper bound -> outcome). */
function gars_default_interpretation_bands(): array
{
    return [
        ['max' => 54,  'probability' => 'Unlikely',   'severity' => 'Not ASD',   'descriptor' => 'Not ASD'],
        ['max' => 70,  'probability' => 'Probable',    'severity' => 'Level 1',   'descriptor' => 'Minimal Support Required'],
        ['max' => 100, 'probability' => 'Very Likely', 'severity' => 'Level 2',   'descriptor' => 'Requiring Substantial Support'],
        ['max' => null,'probability' => 'Very Likely', 'severity' => 'Level 3',   'descriptor' => 'Requiring Very Substantial Support'],
    ];
}

/** Per-request cache of approved table revisions (null = use the built-in table). */
function &gars_scoring_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Drop the cached revisions (tests, after a revision is approved). */
function gars_reset_scoring_cache(): void
{
    $c = &gars_scoring_cache();
    $c = [];
}

/**
 * The active version of a scoring table: the newest APPROVED revision in the
 * database, or null when none exists (-> the built-in published table).
 */
function gars_active_revision(string $kind): ?array
{
    $cache = &gars_scoring_cache();
    if (array_key_exists($kind, $cache)) {
        return $cache[$kind];
    }
    $cache[$kind] = null;
    try {
        $stmt = gars_db()->prepare(
            "SELECT payload FROM scoring_revisions WHERE kind = ? AND status = 'approved' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$kind]);
        $payload = $stmt->fetchColumn();
        if ($payload !== false && $payload !== null) {
            $decoded = json_decode((string) $payload, true);
            if (is_array($decoded) && $decoded !== []) {
                $cache[$kind] = $decoded;
            }
        }
    } catch (Throwable $e) {
        // scoring_revisions not migrated yet, or no DB in this context — built-in table
    }
    return $cache[$kind];
}

/** Table A in use right now (approved revision or the published constant). */
function gars_table_a(): array
{
    return gars_active_revision('table_a') ?? GARS_TABLE_A;
}

/** Table B in use right now (approved revision or the published constant). */
function gars_table_b(): array
{
    return gars_active_revision('table_b') ?? GARS_TABLE_B;
}

/** Interpretation bands in use right now (approved revision or the published bands). */
function gars_interpretation_bands(): array
{
    return gars_active_revision('interpretation') ?? gars_default_interpretation_bands();
}

/** Raw subscale score -> [scaled score, percentile rank]. */
function gars_get_scaled_percentile(int $rawScore, string $short): array
{
    $idxMap = ["RB" => 2, "SI" => 3, "SC" => 4, "ER" => 5, "CS" => 6, "MS" => 7];
    $idx = $idxMap[$short] ?? null;
    if ($idx === null) {
        return [null, null];
    }

    foreach (gars_table_a() as $entry) {
        $range = $entry[$idx] ?? null;
        if (is_array($range) && $range[0] <= $rawScore && $rawScore <= $range[1]) {
            return [$entry[0], $entry[1]];
        }
    }
    return [null, null];
}

/** Sum of scaled scores -> [percentile rank, Autism Index]. */
function gars_get_composite(int $sumScaledScores, bool $useSix): array
{
    foreach (gars_table_b() as $entry) {
        $range = $useSix ? ($entry[2] ?? null) : ($entry[1] ?? null);
        if (is_array($range) && $range[0] <= $sumScaledScores && $sumScaledScores <= $range[1]) {
            return [$entry[0], $entry[3]];
        }
    }
    return [null, null];
}

/** Autism Index -> [probability, severity level, descriptor]. */
function gars_get_interpretation(?int $autismIndex): array
{
    if ($autismIndex === null) return [null, null, null];
    foreach (gars_interpretation_bands() as $band) {
        $max = $band['max'] ?? null;
        if ($max === null || $autismIndex <= (int) $max) {
            return [
                (string) $band['probability'],
                (string) $band['severity'],
                (string) $band['descriptor'],
            ];
        }
    }
    return [null, null, null];
}

/* --------------------------------------------------- payload validation ---- */

/** Highest possible raw score per subscale (items x 3) — bounds for Table A ranges. */
function gars_table_a_max_raw(): array
{
    $max = [];
    foreach (gars_subscale_defs() as $sub) {
        $max[strtoupper($sub['short'])] = count($sub['questions']) * 3;
    }
    return $max; // RB=39 SI=42 SC=27 ER=24 CS=21 MS=21
}

/**
 * Validate + normalise a proposed table payload.
 * Returns [$errors, $normalized]; $normalized is only trustworthy when $errors is empty.
 * Shapes:
 *   table_a        list of [scaled, pct, rb, si, sc, er, cs, ms]   (range = [lo,hi] | null)
 *   table_b        list of [pct, sum4, sum6, autismIndex]          (range = [lo,hi] | null)
 *   interpretation list of {max, probability, severity, descriptor}(max = int | null)
 */
function gars_prepare_scoring_payload(string $kind, mixed $raw): array
{
    $errors = [];
    if (!is_array($raw) || $raw === []) {
        return [['The table is empty — at least one row is required.'], null];
    }
    // accept an object-wrapped decode ({rows:[...]}) defensively
    if (array_key_exists('rows', $raw) && is_array($raw['rows'])) {
        $raw = $raw['rows'];
    }

    $isRange = static function (mixed $v): bool {
        return is_array($v) && array_is_list($v) && count($v) === 2
            && is_numeric($v[0]) && is_numeric($v[1])
            && (int) $v[0] >= 0 && (int) $v[0] <= (int) $v[1];
    };

    $out = [];

    if ($kind === 'table_a') {
        $maxRaw = gars_table_a_max_raw();
        $subKeys = ['RB', 'SI', 'SC', 'ER', 'CS', 'MS'];
        foreach (array_values($raw) as $i => $row) {
            $n = $i + 1;
            if (!is_array($row) || count($row) !== 8) {
                $errors[] = "Row $n: expected 8 cells (scaled, percentile, then 6 raw ranges).";
                continue;
            }
            $row = array_values($row);
            $scaled = $row[0];
            $pct = trim((string) ($row[1] ?? ''));
            if (!is_numeric($scaled) || (int) $scaled < 1 || (int) $scaled > 30) {
                $errors[] = "Row $n: scaled score must be a number between 1 and 30.";
                $scaled = 0;
            }
            if ($pct === '' || mb_strlen($pct) > 10) {
                $errors[] = "Row $n: percentile rank is required (max 10 characters).";
            }
            $ranges = [];
            foreach ($subKeys as $j => $key) {
                $v = $row[2 + $j];
                if ($v === null || $v === '') {
                    $ranges[] = null;
                    continue;
                }
                if (!$isRange($v)) {
                    $errors[] = "Row $n: $key range must be empty or 'low-high' with low ≤ high.";
                    $ranges[] = null;
                    continue;
                }
                $lo = (int) $v[0];
                $hi = (int) $v[1];
                if ($hi > $maxRaw[$key]) {
                    $errors[] = "Row $n: $key range $lo-$hi exceeds the maximum raw score of {$maxRaw[$key]}.";
                }
                $ranges[] = [$lo, $hi];
            }
            $out[] = [(int) $scaled, $pct, ...$ranges];
        }
    } elseif ($kind === 'table_b') {
        foreach (array_values($raw) as $i => $row) {
            $n = $i + 1;
            if (!is_array($row) || count($row) !== 4) {
                $errors[] = "Row $n: expected 4 cells (percentile, 4-score range, 6-score range, Autism Index).";
                continue;
            }
            $row = array_values($row);
            $pct = trim((string) ($row[0] ?? ''));
            if ($pct === '' || mb_strlen($pct) > 10) {
                $errors[] = "Row $n: percentile rank is required (max 10 characters).";
            }
            $sum4 = $row[1];
            $sum6 = $row[2];
            foreach ([['4-score', $sum4], ['6-score', $sum6]] as [$label, $v]) {
                if ($v !== null && $v !== '' && !$isRange($v)) {
                    $errors[] = "Row $n: $label range must be empty or 'low-high' with low ≤ high.";
                }
            }
            $sum4 = ($sum4 === null || $sum4 === '' || !$isRange($sum4)) ? null : [(int) $sum4[0], (int) $sum4[1]];
            $sum6 = ($sum6 === null || $sum6 === '' || !$isRange($sum6)) ? null : [(int) $sum6[0], (int) $sum6[1]];
            if ($sum4 === null && $sum6 === null) {
                $errors[] = "Row $n: give at least one of the 4-score or 6-score ranges.";
            }
            $ai = $row[3];
            if (!is_numeric($ai) || (int) $ai < 0 || (int) $ai > 200) {
                $errors[] = "Row $n: Autism Index must be a number between 0 and 200.";
                $ai = 0;
            }
            $out[] = [$pct, $sum4, $sum6, (int) $ai];
        }
    } elseif ($kind === 'interpretation') {
        $prevMax = -1;
        foreach (array_values($raw) as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                $errors[] = "Band $n: must be an object with max, probability, severity and descriptor.";
                continue;
            }
            $row = $row + ['max' => null, 'probability' => '', 'severity' => '', 'descriptor' => ''];
            $max = $row['max'];
            if ($max !== null && $max !== '') {
                if (!is_numeric($max) || (int) $max < 0) {
                    $errors[] = "Band $n: upper Autism Index bound must be a number or empty (open-ended).";
                    $max = null;
                } else {
                    $max = (int) $max;
                    if ($max <= $prevMax) {
                        $errors[] = "Band $n: upper bounds must increase (previous was $prevMax).";
                    }
                    $prevMax = $max;
                }
            } else {
                $max = null;
            }
            $prob = trim((string) $row['probability']);
            $sev = trim((string) $row['severity']);
            $desc = trim((string) $row['descriptor']);
            if ($prob === '' || mb_strlen($prob) > 30) $errors[] = "Band $n: probability is required (max 30 characters).";
            if ($sev === '' || mb_strlen($sev) > 30)  $errors[] = "Band $n: severity level is required (max 30 characters).";
            if ($desc === '' || mb_strlen($desc) > 80) $errors[] = "Band $n: descriptor is required (max 80 characters).";
            $out[] = ['max' => $max, 'probability' => $prob, 'severity' => $sev, 'descriptor' => $desc];
        }
        $last = $out === [] ? null : $out[count($out) - 1];
        if ($last !== null && $last['max'] !== null) {
            $errors[] = 'The last band must have an empty upper bound so every Autism Index is covered.';
        }
        foreach ($out as $i => $band) {
            if ($band['max'] === null && $i !== count($out) - 1) {
                $errors[] = 'Only the last band may have an empty upper bound.';
            }
        }
    } else {
        $errors[] = "Unknown table kind \"$kind\".";
    }

    return [$errors, $errors === [] ? $out : null];
}

/**
 * Compute every subscale and the composite from a rating-form POST array.
 * Requires includes/rating_form.php (gars_subscale_defs).
 */
function gars_compute_scores(array $post, bool $isMute): array
{
    $subscaleResults = [];
    foreach (gars_subscale_defs() as $subscale) {
        $rawScore = 0;
        $itemScores = [];
        $numQuestions = count($subscale['questions']);
        for ($i = 0; $i < $numQuestions; $i++) {
            $fieldName = $subscale['id'] . '_item_' . $i;
            $score = isset($post[$fieldName]) ? (int) $post[$fieldName] : 0;
            if ($score < 0) $score = 0;
            if ($score > 3) $score = 3;
            $itemScores[] = $score;
            $rawScore += $score;
        }
        [$scaledScore, $percentileRank] = gars_get_scaled_percentile($rawScore, $subscale['short']);
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

    $includedSubscales = $isMute ? array_slice($subscaleResults, 0, 4) : $subscaleResults;
    $sumScaled = 0;
    foreach ($includedSubscales as $s) {
        if ($s['scaled_score'] !== null) $sumScaled += (int) $s['scaled_score'];
    }

    [$compositePercentile, $autismIndex] = gars_get_composite($sumScaled, !$isMute);
    [$probability, $severity, $descriptor] = gars_get_interpretation($autismIndex);

    return [
        'subscale_results'    => $subscaleResults,
        'use_six'             => !$isMute,
        'sum_scaled'          => $sumScaled,
        'composite_percentile' => $compositePercentile,
        'autism_index'        => $autismIndex,
        'probability'         => $probability,
        'severity'            => $severity,
        'descriptor'          => $descriptor,
    ];
}
