<?php
/**
 * includes/assessment_store.php — the storage half of the rating-form
 * contract, shared by score.php (web) and api/index.php (mobile). Scoring
 * itself stays in includes/scoring.php; this file owns the required-field
 * list, the INSERT and the JSON shape of a stored record.
 *
 * POST field names — individual_name … rater_known_for, is_mute and
 * {subscale}_item_{n} — are fixed by includes/rating_form.php. Never rename.
 */

/** The identifying-information fields that must be non-empty before a record may be saved. */
function gars_rating_required(): array
{
    return [
        'fields' => [
            'individual_name' => '',
            'school'          => '',
            'rater_name'      => '',
            'rater_title'     => '',
            'date_of_birth'   => '',
            'age'             => '',
            'gender'          => '',
            'examiner_name'   => '',
            'examiner_title'  => '',
            'date_of_rating'  => '',
        ],
        'labels' => [
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
        ],
    ];
}

/** Labels of the required fields that are missing (empty after trim) in $post. */
function gars_rating_missing(array $post): array
{
    $spec = gars_rating_required();
    $fields = [];
    foreach (array_keys($spec['fields']) as $key) {
        $fields[$key] = (string) ($post[$key] ?? '');
    }
    return gars_validate_required($fields, $spec['labels']);
}

/**
 * Save a computed assessment for $userId and return the new record id.
 * Throws whatever the database throws — callers decide how to report it.
 */
function gars_persist_assessment(int $userId, array $post, array $computed): int
{
    $t = fn (string $key): string => trim((string) ($post[$key] ?? ''));
    $dob          = (string) ($post['date_of_birth'] ?? '');
    $grade        = $t('grade');
    $dateOfRating = (string) ($post['date_of_rating'] ?? '');
    $isMute       = ($post['is_mute'] ?? 'no') === 'yes';

    $subscaleResults = $computed['subscale_results'];
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
    $rawByShort = [];
    foreach ($subscaleResults as $s) {
        $rawByShort[$s['short']] = (int) $s['raw_score'];
    }

    $stmt = gars_db()->prepare(
        'INSERT INTO assessments
            (created_by, individual_name, school, rater_name, rater_title, date_of_birth, age, gender, grade,
             examiner_name, examiner_title, date_of_rating, rater_known_for, is_mute,
             raw_rb, raw_si, raw_sc, raw_er, raw_cs, raw_ms, scaled, percentiles, sum_scaled,
             composite_percentile, autism_index, probability, severity_level, severity_desc, item_scores)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $t('individual_name'), $t('school'), $t('rater_name'), $t('rater_title'),
        $dob !== '' ? $dob : null, $t('age'), $t('gender'), $grade !== '' ? $grade : null,
        $t('examiner_name'), $t('examiner_title'), $dateOfRating !== '' ? $dateOfRating : null, $t('rater_known_for'),
        $isMute ? 1 : 0,
        $rawByShort['RB'] ?? 0, $rawByShort['SI'] ?? 0, $rawByShort['SC'] ?? 0,
        $rawByShort['ER'] ?? 0, $rawByShort['CS'] ?? 0, $rawByShort['MS'] ?? 0,
        implode('|', $scaledStr), implode('|', $pctStr), $computed['sum_scaled'],
        (string) ($computed['composite_percentile'] ?? '') ?: '-', (int) $computed['autism_index'],
        (string) ($computed['probability'] ?? '') ?: '-', (string) ($computed['severity'] ?? '') ?: '-',
        (string) ($computed['descriptor'] ?? '') ?: '-',
        $itemScoresJson,
    ]);
    return (int) gars_db()->lastInsertId();
}

/** Split "RB=10|SI=11|…" into ['RB' => 10, …]; scaled values may be "-". */
function gars_score_map(?string $encoded): array
{
    $out = [];
    foreach (explode('|', (string) $encoded) as $pair) {
        if ($pair === '' || !str_contains($pair, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $out[$k] = ctype_digit($v) ? (int) $v : $v;
    }
    return $out;
}

/** JSON-friendly shape of a stored assessment row (used by the API). */
function gars_assessment_api_shape(array $row): array
{
    $itemScores = null;
    if (!empty($row['item_scores'])) {
        $decoded = json_decode((string) $row['item_scores'], true);
        $itemScores = is_array($decoded) ? $decoded : null;
    }
    return [
        'id'                    => (int) $row['id'],
        'created_by'            => (int) $row['created_by'],
        'creator'               => $row['creator'] ?? null,
        'individual_name'       => (string) $row['individual_name'],
        'school'                => (string) $row['school'],
        'rater_name'            => (string) $row['rater_name'],
        'rater_title'           => (string) $row['rater_title'],
        'date_of_birth'         => $row['date_of_birth'],
        'age'                   => (string) $row['age'],
        'gender'                => (string) $row['gender'],
        'grade'                 => $row['grade'],
        'examiner_name'         => (string) $row['examiner_name'],
        'examiner_title'        => (string) $row['examiner_title'],
        'date_of_rating'        => $row['date_of_rating'],
        'rater_known_for'       => $row['rater_known_for'],
        'is_mute'               => (bool) $row['is_mute'],
        'scores' => [
            'raw'                  => [
                'RB' => (int) $row['raw_rb'], 'SI' => (int) $row['raw_si'], 'SC' => (int) $row['raw_sc'],
                'ER' => (int) $row['raw_er'], 'CS' => (int) $row['raw_cs'], 'MS' => (int) $row['raw_ms'],
            ],
            'scaled'               => gars_score_map($row['scaled'] ?? ''),
            'percentiles'          => gars_score_map($row['percentiles'] ?? ''),
            'sum_scaled'           => (int) $row['sum_scaled'],
            'composite_percentile' => $row['composite_percentile'],
            'autism_index'         => (int) $row['autism_index'],
            'probability'          => $row['probability'],
            'severity_level'       => $row['severity_level'],
            'severity_desc'        => $row['severity_desc'],
        ],
        'item_scores'           => $itemScores,
        'created_at'            => $row['created_at'] ?? null,
    ];
}

/** Compact shape for list responses. */
function gars_assessment_list_shape(array $row): array
{
    return [
        'id'              => (int) $row['id'],
        'individual_name' => (string) $row['individual_name'],
        'age'             => (string) $row['age'],
        'gender'          => (string) $row['gender'],
        'date_of_rating'  => $row['date_of_rating'],
        'is_mute'         => (bool) $row['is_mute'],
        'autism_index'    => (int) $row['autism_index'],
        'severity_level'  => (string) $row['severity_level'],
        'severity_desc'   => (string) $row['severity_desc'],
        'created_at'      => $row['created_at'] ?? null,
        'created_by'      => (int) $row['created_by'],
        'creator'         => $row['creator'] ?? null,
    ];
}
