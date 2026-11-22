<?php
/**
 * edit.php — reopen a stored Maisha Bora record in the rating form and
 * re-score it over the same database row.
 *
 * Route: /edit?id=123   (GET shows the form, POST recalculates and saves)
 *
 * The raw marks of every item are stored in assessments.item_scores as JSON,
 * so the record can be fully reconstructed, corrected and recalculated.
 * The audit trail keeps the history of each recalculation.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/rating_form.php';
require_once __DIR__ . '/includes/scoring.php';
define('GARS_ROUTE', 'edit');

$currentUser = gars_require_login();
$id = (int) ($_GET['id'] ?? 0);
$db = gars_db();

$stmt = $db->prepare('SELECT * FROM assessments WHERE id = ?');
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec || (!gars_has_role('admin', 'it_admin') && (int) $rec['created_by'] !== (int) $currentUser['id'])) {
    http_response_code(404);
    gars_header('Record not found', 'assessment', false);
    echo '<div class="section"><h2>Record not found</h2>'
       . '<p class="muted">Record #' . $id . ' does not exist, or it was saved by another therapist.</p>'
       . '<p style="margin-top:14px;"><a class="btn" href="' . gars_e(gars_url('assessments')) . '">Back to records</a></p></div>';
    gars_footer();
    exit;
}

// --- POST: validate, recalculate and update the existing row -----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    gars_csrf_check();
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
        gars_log_activity('validation_failed', 'edit', "Record #$id not updated — missing required field(s): " . implode(', ', $missing));
        gars_track_page('page_visit', 'Blocked: missing required fields');
        http_response_code(422);
        gars_header('Nothing was updated', 'edit', false);
        ?>
        <div class="page-head">
            <h1>Nothing was updated</h1>
            <p class="sub">Record #<?php echo $id; ?> keeps its stored scores until every required field of the identifying information is filled in. These are still empty:</p>
        </div>
        <div class="notice notice-error">
            <ul style="margin-left:18px;"><?php foreach ($missing as $m) echo '<li>' . gars_e($m) . '</li>'; ?></ul>
        </div>
        <button class="btn" type="button" onclick="history.back()">Go back and complete the form</button>
        <?php
        gars_footer();
        exit;
    }

    $oldIndex = (int) $rec['autism_index'];
    $computed = gars_compute_scores($_POST, $isMute);
    $autismIndex = $computed['autism_index'];

    try {
        gars_update_assessment($id, $_POST, $computed);

        gars_log_activity(
            'update_assessment',
            'edit',
            "Recalculated record #$id for \"$individualName\": Autism Index $oldIndex to " . (int) $autismIndex
        );
        $GLOBALS['GARS_SUPPRESS_PAGE_VISIT_LOG'] = true; // this POST already logged its change
        gars_track_page('page_visit', "Updated record #$id");

        $change = $oldIndex === (int) $autismIndex
            ? 'Autism Index stayed at ' . (int) $autismIndex
            : 'Autism Index ' . $oldIndex . ' to ' . (int) $autismIndex;
        gars_flash("Record #$id was recalculated and updated. $change.", 'success');
        header('Location: ' . gars_url('assessment') . '?id=' . $id);
        exit;
    } catch (Throwable $e) {
        gars_log_activity('save_failed', 'edit', "Database update failed for record #$id: " . $e->getMessage());
        $saveError = 'The recalculation ran but the record could NOT be updated: ' . $e->getMessage();
    }
}

// --- GET: rebuild the form from the stored raw marks -------------------------
$saved = [];
$decoded = json_decode((string) ($rec['item_scores'] ?? ''), true);
if (is_array($decoded)) {
    foreach ($decoded as $entry) {
        if (!is_array($entry)) continue;
        foreach ($entry as $short => $vals) {
            if (is_array($vals)) {
                $saved[strtoupper((string) $short)] = $vals;
            }
        }
    }
}

$marks = [];
foreach (gars_subscale_defs() as $def) {
    $list = $saved[$def['short']] ?? [];
    foreach ($def['questions'] as $i => $q) {
        $v = isset($list[$i]) && is_numeric($list[$i]) ? (int) $list[$i] : 0;
        $marks[$def['id'] . '_item_' . $i] = max(0, min(3, $v));
    }
}

$prefill = [
    'fields' => [
        'individual_name' => (string) $rec['individual_name'],
        'school'          => (string) $rec['school'],
        'rater_name'      => (string) $rec['rater_name'],
        'rater_title'     => (string) $rec['rater_title'],
        'date_of_birth'   => (string) ($rec['date_of_birth'] ?? ''),
        'age'             => (string) $rec['age'],
        'gender'          => (string) $rec['gender'],
        'grade'           => (string) ($rec['grade'] ?? ''),
        'examiner_name'   => (string) $rec['examiner_name'],
        'examiner_title'  => (string) $rec['examiner_title'],
        'date_of_rating'  => (string) ($rec['date_of_rating'] ?? ''),
        'rater_known_for' => (string) ($rec['rater_known_for'] ?? ''),
    ],
    'marks' => $marks,
    'is_mute' => ((int) $rec['is_mute'] === 1) ? 'yes' : 'no',
];

gars_track_page('page_visit', "Edit form for record #$id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B5CAF">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(gars_csrf_token(), ENT_QUOTES); ?>">
    <?php gars_favicon_links(); ?>
    <title>Edit record #<?php echo $id; ?></title>
    <?php gars_styles(); ?>
    <?php gars_rating_form_assets(); ?>
</head>
<body>
<?php gars_navbar('assessment'); ?>
<main class="wrap" id="main">
    <div class="page-head">
        <h1>Edit record #<?php echo $id; ?></h1>
        <p class="sub"><?php echo gars_e($rec['individual_name']); ?> — change any identifying detail or item mark below. Saving recalculates the subscale scores and the Autism Index and updates this same record; the audit trail keeps a note of every recalculation.</p>
    </div>

    <?php if (!empty($saveError)): ?>
    <div class="notice notice-error"><?php echo gars_e($saveError); ?></div>
    <?php endif; ?>

    <p style="margin-bottom:18px;"><a class="a-link" href="<?php echo gars_e(gars_url('assessment')); ?>?id=<?php echo $id; ?>">Cancel and go back to the record</a></p>

<?php gars_rating_form_body(gars_url('edit') . '?id=' . $id, 'Recalculate and update', $prefill); ?>
</body>
</html>
