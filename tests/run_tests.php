<?php
/**
 * tests/run_tests.php — unit tests for the scoring engine, wording fixes and
 * the scoring-table change control. Run:  php tests/run_tests.php
 * Exits non-zero when anything fails.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/scoring.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $msg\n";
    } else {
        $fail++;
        echo "  FAIL  $msg\n";
    }
}

echo "== Scoring defaults (built-in tables) ==\n";
ok(gars_get_composite(60, true) === ['50', 100], 'sum6 60 -> 50 / AI 100');
ok(gars_get_composite(70, true) === ['84', 115], 'sum6 70 -> 84 / AI 115');
ok(gars_get_composite(40, false) === ['50', 100], 'sum4 40 -> 50 / AI 100');
ok(gars_get_composite(51, true) === [null, null], 'sum6 51 has no row (known gap) -> null');
[$s, $p] = gars_get_scaled_percentile(26, 'RB');
ok($s === 11 && $p === '63', 'RB raw 26 -> scaled 11 / 63');
[$s, $p] = gars_get_scaled_percentile(0, 'CS');
ok($s === 5 && $p === '3', 'CS raw 0 -> scaled 5 / 3');
[$s, $p] = gars_get_scaled_percentile(7, 'SC');
ok($s === null && $p === null, 'SC raw 7 falls in the table gap -> null');
ok(gars_get_interpretation(54) === ['Unlikely', 'Not ASD', 'Not ASD'], 'AI 54 -> Unlikely / Not ASD');
ok(gars_get_interpretation(70) === ['Probable', 'Level 1', 'Minimal Support Required'], 'AI 70 -> Probable / Level 1');
ok(gars_get_interpretation(71) === ['Very Likely', 'Level 2', 'Requiring Substantial Support'], 'AI 71 -> Level 2');
ok(gars_get_interpretation(101)[2] === 'Requiring Very Substantial Support', 'AI 101 -> Level 3');
ok(gars_get_interpretation(null) === [null, null, null], 'null AI -> null interpretation');

echo "== gars_compute_scores (full form POST) ==\n";
$post = [];
foreach ([['rb', 13], ['si', 14], ['sc', 9], ['er', 8], ['cs', 7], ['ms', 7]] as [$id, $n]) {
    for ($i = 0; $i < $n; $i++) {
        $post[$id . '_item_' . $i] = 2;
    }
}
$r = gars_compute_scores($post, false);
ok($r['sum_scaled'] === 65, 'all-2 form -> sum 65 (got ' . $r['sum_scaled'] . ')');
ok($r['composite_percentile'] === '70' && $r['autism_index'] === 108, 'sum 65 -> 70 / AI 108');
ok($r['severity'] === 'Level 3', 'AI 108 -> Level 3');
$r4 = gars_compute_scores($post, true);
ok($r4['sum_scaled'] === 40 && $r4['composite_percentile'] === '50', 'mute: 4 subscales sum 40 -> 50');
ok($r4['use_six'] === false, 'mute uses the 4-score composite');

echo "== Wording & title fixes ==\n";
$rf = file_get_contents(__DIR__ . '/../includes/rating_form.php');
ok(str_contains($rf, 'humorous stimuli'), 'heinous -> humorous');
ok(str_contains($rf, 'slang expressions'), 'sting -> slang');
ok(!str_contains($rf, 'heinous'), 'no "heinous" left in the form');
ok(!str_contains($rf, 'sting expressions'), 'no "sting expressions" left in the form');
$idx = file_get_contents(__DIR__ . '/../index.php');
ok(substr_count($idx, 'Therapist form') === 2, 'index.php title + h1 say "Therapist form"');
ok(!str_contains($idx, 'Rating form'), 'index.php no longer says "Rating form"');
ok(str_contains($rf, 'autoFillAge'), 'age auto-fill script present in the form');

echo "== Payload validation ==\n";
[$e, $n] = gars_prepare_scoring_payload('table_a', [[1, '<1', null, null, [0, 1], null, null, null]]);
ok($e === [] && is_array($n), 'valid Table A row accepted');
[$e] = gars_prepare_scoring_payload('table_a', [[1, '<1', [5, 2], null, null, null, null, null]]);
ok($e !== [], 'inverted range (5-2) rejected');
[$e] = gars_prepare_scoring_payload('table_a', [[1, '<1', [0, 99], null, null, null, null, null]]);
ok($e !== [], 'range beyond subscale maximum (RB 39) rejected');
[$e] = gars_prepare_scoring_payload('table_a', []);
ok($e !== [], 'empty Table A rejected');
[$e, $n] = gars_prepare_scoring_payload('table_b', [['50', [40, 40], [60, 60], 100]]);
ok($e === [] && $n === [['50', [40, 40], [60, 60], 100]], 'valid Table B row accepted');
[$e] = gars_prepare_scoring_payload('table_b', [['50', null, null, 100]]);
ok($e !== [], 'Table B row without any sum range rejected');
[$e] = gars_prepare_scoring_payload('table_b', [['50', [40, 40], [60, 60], 999]]);
ok($e !== [], 'Autism Index out of 0-200 rejected');
$bandOk = [
    ['max' => 54, 'probability' => 'Unlikely', 'severity' => 'Not ASD', 'descriptor' => 'Not ASD'],
    ['max' => null, 'probability' => 'Very Likely', 'severity' => 'Level 3', 'descriptor' => 'x'],
];
[$e, $n] = gars_prepare_scoring_payload('interpretation', $bandOk);
ok($e === [] && $n === $bandOk, 'valid interpretation bands accepted');
[$e] = gars_prepare_scoring_payload('interpretation', [
    ['max' => 100, 'probability' => 'A', 'severity' => 'B', 'descriptor' => 'C'],
    ['max' => 50, 'probability' => 'A', 'severity' => 'B', 'descriptor' => 'C'],
    ['max' => null, 'probability' => 'D', 'severity' => 'E', 'descriptor' => 'F'],
]);
ok($e !== [], 'non-increasing band bounds rejected');
[$e] = gars_prepare_scoring_payload('interpretation', [
    ['max' => 100, 'probability' => 'A', 'severity' => 'B', 'descriptor' => 'C'],
    ['max' => 200, 'probability' => 'D', 'severity' => 'E', 'descriptor' => 'F'],
]);
ok($e !== [], 'bands without an open-ended last row rejected');

echo "== Approved revision takes effect through the DB ==\n";
$db = gars_db();
$db->exec("DELETE FROM scoring_revisions WHERE change_note LIKE 'UNITTEST%'");
$adminId = (int) $db->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
$stmt = $db->prepare(
    "INSERT INTO scoring_revisions (kind, payload, change_note, status, created_by, decided_by, decided_at)
     VALUES ('table_b', ?, 'UNITTEST', 'approved', ?, ?, NOW())"
);
$stmt->execute([json_encode([['88', null, [65, 65], 111]]), $adminId, $adminId]);
$revId = (int) $db->lastInsertId();
gars_reset_scoring_cache();
ok(gars_get_composite(65, true) === ['88', 111], 'approved revision overrides Table B lookup');
ok(gars_get_composite(60, true) === [null, null], 'rows outside the revision no longer resolve');
ok(gars_active_revision('table_b') !== null, 'active revision is visible to the loader');
// undo
$db->prepare('DELETE FROM scoring_revisions WHERE id = ?')->execute([$revId]);
gars_reset_scoring_cache();
ok(gars_get_composite(65, true) === ['70', 108], 'built-in table restored after the revision is removed');
ok(gars_get_composite(60, true) === ['50', 100], 'built-in sum60 lookup restored');

echo "== Scoring-table page: versions & restore ==\n";
$st = file_get_contents(__DIR__ . '/../scoring_tables.php');
ok(str_contains($st, "action === 'restore'"), 'restore action present on the page');
ok(str_contains($st, 'Restored from'), 'restore note format present');
ok(str_contains($st, 'gars_version_of'), 'version label helper present');
ok(str_contains($st, "'v1.'"), 'v1.x version numbering present');
ok(str_contains($st, "status IN ('approved','superseded')"), 'version numbering counts approved revisions');

echo "== Audit pages (sessions + activity log) ==\n";
$sessSrc = file_get_contents(__DIR__ . '/../sessions.php');
$actSrc = file_get_contents(__DIR__ . '/../activity.php');
$uiSrc = file_get_contents(__DIR__ . '/../includes/ui.php');
$routeSrc = file_get_contents(__DIR__ . '/../route.php');
ok(str_contains($sessSrc, "gars_require_role(['admin', 'it_admin'])"), 'sessions page restricted to admin/it_admin');
ok(str_contains($actSrc, "gars_require_role(['admin', 'it_admin'])"), 'activity page restricted to admin/it_admin');
ok(str_contains($actSrc, 'action = ?') && str_contains($actSrc, 'username = ?') && str_contains($actSrc, 'details LIKE ?'),
    'activity filters use bound parameters (no SQL concatenation of user input)');
ok(str_contains($actSrc, "DateTime::createFromFormat('!Y-m-d'"), 'date filters validated strictly');
ok(str_contains($sessSrc, "in_array(\$fUser, \$allUsers, true)"), 'sessions user filter whitelisted against the users table');
ok(str_contains($actSrc, 'LIMIT') && str_contains($actSrc, 'OFFSET'), 'activity log paginates');
ok(str_contains($uiSrc, "\$links['sessions'] = 'Sessions'") && str_contains($uiSrc, "\$links['activity'] = 'Activity'"),
    'nav shows Sessions + Activity to admin/it_admin');
ok(str_contains($routeSrc, "'sessions.php'") && str_contains($routeSrc, "'activity.php'"), 'routes /sessions and /activity registered');

echo "== Schema ==\n";
ok((bool) $db->query("SHOW TABLES LIKE 'scoring_revisions'")->fetchColumn(), 'scoring_revisions table exists');

echo "== Security: CSRF tokens ==\n";
$secSrc = file_get_contents(__DIR__ . '/../includes/security.php');
$loginSrc = file_get_contents(__DIR__ . '/../login.php');
$cfgSrc = file_get_contents(__DIR__ . '/../config.php');
$token = gars_csrf_token();
ok(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'csrf token is 64 hex chars');
ok(gars_csrf_token() === $token, 'csrf token is stable within the session');
ok(str_contains(gars_csrf_field(), $token), 'csrf field carries the session token');
$before = gars_csrf_token();
gars_csrf_rotate();
ok(gars_csrf_token() !== $before, 'token rotates after a successful login');
foreach (['login.php', 'register.php', 'includes/rating_form.php', 'scoring_tables.php'] as $formFile) {
    $src = file_get_contents(__DIR__ . '/../' . $formFile);
    ok(str_contains($src, 'gars_csrf_field()'), "$formFile renders the CSRF field");
}
foreach (['login.php', 'register.php', 'edit.php', 'score.php', 'scoring_tables.php'] as $postFile) {
    $src = file_get_contents(__DIR__ . '/../' . $postFile);
    ok(str_contains($src, 'gars_csrf_check()'), "$postFile enforces the CSRF check on POST");
}

echo "== Security: session cookies, HTTPS, lockout ==\n";
ok(str_contains($secSrc, "'httponly' => true") && str_contains($secSrc, "'samesite' => 'Lax'"),
    'session cookie is HttpOnly + SameSite=Lax');
ok(str_contains($secSrc, 'gars_is_https()') && str_contains($secSrc, "'secure'"),
    'session cookie becomes Secure on HTTPS');
ok(str_contains($secSrc, 'Strict-Transport-Security'), 'HSTS header sent once on HTTPS');
ok(str_contains($secSrc, 'FORCE_HTTPS') && str_contains($cfgSrc, 'gars_force_https()'),
    'HTTPS enforced on every request via config');
foreach (['includes/auth.php', 'includes/tracker.php', 'includes/ui.php', 'logout.php'] as $sessFile) {
    $src = file_get_contents(__DIR__ . '/../' . $sessFile);
    ok(!str_contains($src, '@session_start()'), "$sessFile starts sessions only via gars_session_start");
}
ok(str_contains($loginSrc, 'gars_login_lock('), 'login handler consults the lockout');
ok(str_contains($loginSrc, 'gars_record_login_failure('), 'failed attempts are recorded');
ok(str_contains($loginSrc, 'gars_csrf_rotate()'), 'token rotates on successful login');

gars_ensure_security_tables();
ok((bool) $db->query("SHOW TABLES LIKE 'login_attempts'")->fetchColumn(), 'login_attempts table exists');
$db->exec("DELETE FROM login_attempts WHERE username LIKE 'unitlock%' OR ip LIKE '198.51.100.%'");
ok(gars_login_lock('unitlock_probe', '198.51.100.7') === null, 'clean state is not locked');
for ($i = 0; $i < 5; $i++) {
    gars_record_login_failure('unitlock_probe', '198.51.100.7');
}
$lock = gars_login_lock('unitlock_probe', '198.51.100.7');
ok($lock !== null && str_contains($lock, 'Too many failed sign-in attempts'), 'account locked after 5 failures');
ok(gars_login_lock('unitlock_other', '198.51.100.7') === null, 'other accounts unaffected (IP under limit)');
for ($i = 0; $i < 60; $i++) {
    gars_record_login_failure('ipflood_' . $i, '198.51.100.8');
}
ok(gars_login_lock('ipflood_probe', '198.51.100.8') !== null, 'IP locked after 60 failures from one address');
gars_clear_login_failures('unitlock_probe');
ok(gars_login_lock('unitlock_probe', '198.51.100.7') === null, 'lock clears after successful-login cleanup');
$db->exec("DELETE FROM login_attempts WHERE ip LIKE '198.51.100.%'");

echo "== Security: lockout limits (env + panel settings) ==\n";
$db->exec('DELETE FROM app_settings');
gars_login_settings_reset();
ok(gars_login_user_limit() === 5, 'default account limit is 5');
ok(gars_login_ip_limit() === 60, 'default IP limit is 60 (shared networks tolerated)');
ok(gars_login_lock_seconds() === 1800, 'default lock is 30 minutes');
$GLOBALS['GARS_ENV']['LOGIN_USER_LIMIT'] = '9';
gars_login_settings_reset();
ok(gars_login_user_limit() === 9, 'env LOGIN_USER_LIMIT honoured');
unset($GLOBALS['GARS_ENV']['LOGIN_USER_LIMIT']);
$db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['login_user_limit', '12']);
gars_login_settings_reset();
ok(gars_login_user_limit() === 12, 'database setting beats env and default');
ok(gars_login_setting_info('login_user_limit')['source'] === 'database', 'source reported as database');
$db->exec("UPDATE app_settings SET setting_value = '9999' WHERE setting_key = 'login_user_limit'");
gars_login_settings_reset();
ok(gars_login_user_limit() === 100, 'value clamped to the maximum');
$db->exec("UPDATE app_settings SET setting_value = '7' WHERE setting_key = 'login_user_limit'");
gars_login_settings_reset();
for ($i = 0; $i < 6; $i++) {
    gars_record_login_failure('unitlock_probe', '198.51.100.9');
}
ok(gars_login_lock('unitlock_probe', '198.51.100.9') === null, 'raised limit: 6 failures not yet locked');
gars_record_login_failure('unitlock_probe', '198.51.100.9');
ok(gars_login_lock('unitlock_probe', '198.51.100.9') !== null, 'raised limit: 7 failures locked');
$adminId = (int) $db->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
gars_save_login_setting('login_ip_limit', 90, $adminId);
ok(gars_login_ip_limit() === 90, 'panel save helper persists a value');
gars_delete_login_settings();
ok(gars_login_user_limit() === 5 && gars_login_ip_limit() === 60, 'defaults return after reset');
$db->exec("DELETE FROM login_attempts WHERE ip LIKE '198.51.100.%'");

echo "== Security: setting a password clears the failure counter ==\n";
$db->exec('DELETE FROM users WHERE username = "unitpass_e2e"');
for ($i = 0; $i < 5; $i++) {
    $db->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
        ->execute(['198.51.100.11', 'unitpass_e2e', date('Y-m-d H:i:s')]);
}
ok(gars_login_lock('unitpass_e2e', '198.51.100.12') !== null, 'probe account locked before the password change');
$uid = gars_create_user('unitpass_e2e', 'UnitPass!2026', 'therapist', 'Unit Pass');
ok($uid > 0, 'probe account created with a new password');
ok(gars_login_lock('unitpass_e2e', '198.51.100.12') === null, 'failure counter reset when the password is set');
$db->exec('DELETE FROM users WHERE username = "unitpass_e2e"');
$db->exec("DELETE FROM login_attempts WHERE username = 'unitpass_e2e'");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
