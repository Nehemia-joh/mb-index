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
require __DIR__ . '/../includes/tracker.php';
require __DIR__ . '/../includes/scoring.php';
require __DIR__ . '/../includes/login_core.php';
require __DIR__ . '/../includes/assessment_store.php';
require __DIR__ . '/../includes/api.php';

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
$loginCoreSrc = file_get_contents(__DIR__ . '/../includes/login_core.php');
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
ok(str_contains($loginSrc, 'gars_attempt_login('), 'login handler runs the shared credential check');
ok(str_contains($loginCoreSrc, 'gars_login_lock(') && str_contains($loginCoreSrc, 'gars_record_login_failure('),
    'shared login core consults the lockout and records failures');
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

echo "== Security: P1 headers, timing, bcrypt cost, idle timeout ==\n";
$authSrc = file_get_contents(__DIR__ . '/../includes/auth.php');
$htSrc = file_get_contents(__DIR__ . '/../.htaccess');
ok(str_contains($secSrc, 'Content-Security-Policy') && str_contains($secSrc, "frame-ancestors 'none'"),
    'PHP sends a CSP with frame-ancestors');
ok(str_contains($secSrc, 'X-Frame-Options: DENY') && str_contains($secSrc, 'Referrer-Policy: strict-origin-when-cross-origin')
    && str_contains($secSrc, 'Permissions-Policy:'),
    'clickjacking, referrer and permissions headers present');
ok(str_contains($secSrc, 'X-Content-Type-Options: nosniff'), 'nosniff sent on dynamic responses too');
ok(str_contains($cfgSrc, 'gars_send_security_headers()'), 'headers sent on every request via config');
ok(gars_csp_policy() === gars_csp_policy() && str_contains(gars_csp_policy(), "default-src 'self'"),
    'CSP policy builder returns a concrete policy');
ok(str_contains($htSrc, 'Options -Indexes'), 'directory listing disabled in .htaccess');
ok(str_contains($htSrc, 'RewriteRule ^(tests|ads)/ - [F,L]'), 'tests/ and ads/ blocked before the file rule');
ok(str_contains($htSrc, 'Content-Security-Policy') && str_contains($htSrc, 'X-Frame-Options'),
    'hardening headers shipped for static files in .htaccess');
ok(str_contains($routeSrc, 'tests|ads') && str_contains($routeSrc, "\\.sql"),
    'dev router blocks tests/, ads/ and SQL dumps');
ok(str_contains($routeSrc, '.env'), 'dev router blocks env files');
ok(str_starts_with(GARS_TIMING_DUMMY, '$2y$12$'), 'timing dummy is a cost-12 bcrypt hash');
ok(str_contains($loginCoreSrc, 'GARS_TIMING_DUMMY'), 'login equalises timing for unknown accounts');
ok(str_contains($loginCoreSrc, 'password_needs_rehash'), 'legacy hashes upgraded on successful login');
ok(str_contains($authSrc, "['cost' => 12]"), 'new passwords hashed at bcrypt cost 12');
$uid = gars_create_user('unitcost_e2e', 'CostPass!2026', 'therapist', 'Unit Cost');
$h = (string) $db->query("SELECT password_hash FROM users WHERE username='unitcost_e2e'")->fetchColumn();
ok(str_starts_with($h, '$2y$12$'), 'stored hash carries the cost-12 prefix');
ok(!password_needs_rehash($h, PASSWORD_BCRYPT, ['cost' => 12]), 'stored hash satisfies the cost-12 policy');
$db->exec('DELETE FROM users WHERE username = "unitcost_e2e"');

// idle timeout: first (and only) runtime call to gars_current_user in this process
$_SESSION['gars_user'] = ['id' => 1, 'username' => 'admin', 'role' => 'it_admin', 'full_name' => 'Admin'];
$_SESSION['gars_last_seen'] = time() - (SESSION_TIMEOUT + 60);
ok(gars_current_user() === null, 'idle session is treated as logged out');
ok(!isset($_SESSION['gars_user']), 'idle session state cleared');
ok(SESSION_TIMEOUT > 0, 'idle timeout enabled by default');

echo "== RBAC: tables, effective roles, safeguards ==\n";
gars_ensure_rbac_tables();
foreach (['roles', 'user_roles', 'role_groups', 'role_group_roles', 'user_role_groups'] as $t) {
    ok((bool) $db->query("SHOW TABLES LIKE '$t'")->fetchColumn(), "$t table exists");
}
$sysRoles = $db->query('SELECT name FROM roles WHERE is_system = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
ok($sysRoles === ['admin', 'it_admin', 'therapist'], 'three system roles seeded');

$db->exec('DELETE FROM users WHERE username IN ("rbac_a", "rbac_b", "rbac_it2")');
$uidA = gars_create_user('rbac_a', 'RbacPass!2026', 'therapist', 'RBAC A');
$uidB = gars_create_user('rbac_b', 'RbacPass!2026', 'admin', 'RBAC B');
$userA = ['id' => $uidA, 'role' => 'therapist'];
ok(gars_user_role_names($userA) === ['therapist'], 'base role is in the effective set');

$rid = gars_create_role('e2e_unit_role', 'Unit Reviewer', 'unit test role');
ok($rid > 0, 'custom role created');
gars_grant_role($uidA, $rid, $uidB);
ok(in_array('e2e_unit_role', gars_user_role_names($userA), true), 'direct grant appears in effective roles');

$gid = gars_create_group('Unit Panel', 'unit group');
ok($gid > 0, 'role group created');
$adminRid = (int) gars_role_id_by_name('admin');
gars_set_group_roles($gid, [$adminRid, $rid]);
gars_assign_group($uidA, $gid, $uidB);
$eff = gars_user_role_names($userA);
ok(in_array('admin', $eff, true), 'group role appears in effective roles');
ok(in_array('therapist', $eff, true), 'base role survives alongside group roles');
ok(count(gars_group_roles($gid)) === 2, 'group carries both roles');
ok(gars_group_members($gid) === 1, 'group member counted');

// a direct-only role disappears on revoke while group-carried roles remain
$rid2 = gars_create_role('e2e_unit_solo', 'Unit Solo', 'direct-only role');
gars_grant_role($uidA, $rid2, $uidB);
ok(in_array('e2e_unit_solo', gars_user_role_names($userA), true), 'second direct grant appears');
gars_revoke_role($uidA, $rid2);
$eff = gars_user_role_names($userA);
ok(!in_array('e2e_unit_solo', $eff, true) && in_array('admin', $eff, true) && in_array('e2e_unit_role', $eff, true),
    'revoke removes the direct grant only; group roles remain');
gars_revoke_role($uidA, $rid);
gars_unassign_group($uidA, $gid);
$eff = gars_user_role_names($userA);
ok(!in_array('admin', $eff, true) && !in_array('e2e_unit_role', $eff, true),
    'unassigning the group removes its roles');
ok(in_array('therapist', $eff, true), 'base role survives group removal');

try {
    gars_delete_role($adminRid);
    ok(false, 'built-in roles cannot be deleted');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'Built-in'), 'built-in roles cannot be deleted');
}
try {
    gars_create_role('admin', 'Dup');
    ok(false, 'duplicate role name refused');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'built-in'), 'built-in role names cannot be reused');
}
$usage = gars_role_usage($adminRid);
ok($usage['direct'] >= 0 && $usage['via_groups'] >= 0, 'role usage counters computed');

// safeguards: self-protection and the last active IT administrator
$adminRow = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
$actorB = ['id' => $uidB, 'role' => 'admin'];
try {
    gars_assert_can_change_target($adminRow, $adminRow, false, 'therapist');
    ok(false, 'self base-role change refused');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'own base role'), 'self base-role change refused');
}
try {
    gars_assert_can_change_target($adminRow, $adminRow, true, null);
    ok(false, 'self deactivation refused');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'own account'), 'self deactivation refused');
}
$uidIt2 = gars_create_user('rbac_it2', 'RbacPass!2026', 'it_admin', 'RBAC IT 2');
try {
    gars_assert_can_change_target($actorB, $adminRow, false, 'admin');
    ok(true, 'demotion allowed while another IT administrator exists');
} catch (RuntimeException $e) {
    ok(false, 'demotion allowed while another IT administrator exists');
}
$db->exec('DELETE FROM users WHERE username = "rbac_it2"');
try {
    gars_assert_can_change_target($actorB, $adminRow, false, 'admin');
    ok(false, 'last IT administrator cannot be demoted');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'last active IT administrator'), 'last IT administrator cannot be demoted');
}

// source-level: guards and pages use the effective set
$editSrc = file_get_contents(__DIR__ . '/../edit.php');
$assessSrc = file_get_contents(__DIR__ . '/../assessment.php');
$itSrc = file_get_contents(__DIR__ . '/../itadmin.php');
ok(str_contains($uiSrc, 'gars_user_role_names($user)'), 'nav links are built from effective roles');
ok(str_contains($editSrc, "gars_has_role('admin', 'it_admin')"), 'record ownership uses effective admin check');
ok(str_contains($assessSrc, "gars_has_role('admin', 'it_admin')"), 'assessment view uses effective admin check');
ok(str_contains($authSrc, 'gars_has_role(...$roles)'), 'require_role delegates to effective roles');
ok(str_contains($itSrc, "'roles'    => ['it_admin']") && str_contains($itSrc, "'users'    => ['admin', 'it_admin']"),
    'console tabs carry their own role lists');
ok(str_contains($itSrc, 'set_group_roles') && str_contains($itSrc, 'update_user') && str_contains($itSrc, 'gars_assert_can_change_target'),
    'console implements group-role and user-management actions with safeguards');

// cleanup
$db->exec('DELETE FROM users WHERE username IN ("rbac_a", "rbac_b", "rbac_it2")');
$db->exec("DELETE FROM role_groups WHERE name = 'Unit Panel'");
$db->exec("DELETE FROM roles WHERE name IN ('e2e_unit_role', 'e2e_unit_solo')");
gars_rbac_flush();

echo "== API: bearer tokens, shared login core, JSON contract ==\n";
$apiFrontSrc = file_get_contents(__DIR__ . '/../api/index.php');
$apiLibSrc   = file_get_contents(__DIR__ . '/../includes/api.php');
$routeSrc    = file_get_contents(__DIR__ . '/../route.php');
$scoreSrc    = file_get_contents(__DIR__ . '/../score.php');
$storeSrc    = file_get_contents(__DIR__ . '/../includes/assessment_store.php');

ok(str_contains($routeSrc, '/api(?:/|$)'), 'router dispatches /api/... to the JSON front controller');
ok(str_contains($apiFrontSrc, 'GARS_API_MODE'), 'API front controller sets GARS_API_MODE');
ok(str_contains($authSrc, 'GARS_API_MODE'), 'session loader never starts a session in API mode');
ok(str_contains($apiLibSrc, "hash('sha256'"), 'tokens are stored only as SHA-256 hashes');
ok(str_contains($apiFrontSrc, 'gars_compute_scores'), 'API scoring reuses the server-side engine');
ok(str_contains($scoreSrc, 'gars_persist_assessment') && str_contains($storeSrc, 'INSERT INTO assessments'),
    'web and API persist records through the shared store');
ok(str_contains($scoreSrc, 'gars_rating_missing') && str_contains($apiFrontSrc, 'gars_rating_missing'),
    'web and API validate required fields through the shared list');
$editSrc = file_get_contents(__DIR__ . '/../edit.php');
ok(str_contains($storeSrc, 'UPDATE assessments') && str_contains($editSrc, 'gars_update_assessment')
    && !str_contains($editSrc, 'UPDATE assessments'),
    'web edit reuses the shared recalculation UPDATE in the store');
ok(str_contains($apiFrontSrc, 'PUT    /api/v1/assessments/{id}')
    && str_contains($apiFrontSrc, 'gars_update_assessment'),
    'API exposes PUT /assessments/{id} through the shared store');
ok(str_contains($itSrc, 'gars_api_revoke_user_tokens'), 'console revokes API tokens on deactivate/reset');

gars_api_ensure_token_table();
ok((bool) $db->query("SHOW TABLES LIKE 'api_tokens'")->fetchColumn(), 'api_tokens table exists');

// issue / resolve / revoke
$db->exec('DELETE FROM api_tokens');
$plain = gars_api_issue_token(1, 'unit-test');
ok(preg_match('/^[a-f0-9]{64}$/', $plain) === 1, 'issued token is 64 hex chars');
$storedHash = $db->query('SELECT token_hash FROM api_tokens ORDER BY id DESC LIMIT 1')->fetchColumn();
ok($storedHash === hash('sha256', $plain), 'database stores only the SHA-256 hash');
$res = gars_api_resolve_token($plain);
ok(isset($res['user']) && $res['user']['username'] === 'admin', 'valid token resolves to the user');
ok((gars_api_resolve_token(str_repeat('a', 64))['error'] ?? '') === 'invalid_token', 'unknown token rejected');
$tokenId = (int) ($res['token_id'] ?? 0);
gars_api_revoke_token_id($tokenId);
ok((gars_api_resolve_token($plain)['error'] ?? '') === 'token_revoked', 'revoked token rejected');

// sliding idle expiry (same SESSION_TIMEOUT policy as web sessions)
$plainIdle = gars_api_issue_token(1, 'unit-idle');
$stale = date('Y-m-d H:i:s', time() - SESSION_TIMEOUT - 60);
$db->exec("UPDATE api_tokens SET last_used_at = '$stale' WHERE token_hash = '" . hash('sha256', $plainIdle) . "'");
ok((gars_api_resolve_token($plainIdle)['error'] ?? '') === 'token_expired', 'idle-expired token rejected');

// deactivation kills live tokens
$db->exec('DELETE FROM users WHERE username = "api_unit_probe"');
$probeId = gars_create_user('api_unit_probe', 'ApiPass!2026', 'therapist', 'API Probe');
$plainProbe = gars_api_issue_token($probeId, 'unit');
ok(isset(gars_api_resolve_token($plainProbe)['user']), 'probe token resolves while active');
$db->exec("UPDATE users SET is_active = 0 WHERE id = $probeId");
ok((gars_api_resolve_token($plainProbe)['error'] ?? '') === 'account_disabled', 'disabled account token rejected');
$db->exec("UPDATE users SET is_active = 1 WHERE id = $probeId");
$plainProbe2 = gars_api_issue_token($probeId, 'unit');
ok(gars_api_revoke_user_tokens($probeId) >= 1, 'all user tokens revoked in one call');
ok((gars_api_resolve_token($plainProbe2)['error'] ?? '') === 'token_revoked', 'revoked user token rejected');

// shared login core — the exact path the API hits
$r = gars_attempt_login('api_unit_probe', 'WrongPass!1', '198.51.100.20');
ok($r['ok'] === false && $r['blocked'] === false && $r['user'] === null, 'wrong password refused by shared core');
$r = gars_attempt_login('no_such_user_xyz', 'WhateverPass1', '198.51.100.20');
ok($r['ok'] === false && $r['blocked'] === false, 'unknown account refused (timing-dummy path)');
$r = gars_attempt_login('api_unit_probe', 'ApiPass!2026', '198.51.100.20');
ok($r['ok'] === true && (int) $r['user']['id'] === $probeId, 'correct password accepted by shared core');
$db->exec("UPDATE users SET is_active = 0 WHERE id = $probeId");
$r = gars_attempt_login('api_unit_probe', 'ApiPass!2026', '198.51.100.20');
ok($r['ok'] === false && $r['blocked'] === false, 'inactive account refused by shared core');
$missing = gars_rating_missing(['individual_name' => 'x', 'school' => '']);
ok($missing === ['School', "Rater's Name", "Rater's Title", 'Date of Birth', 'Age', 'Gender',
    "Examiner's Name", "Examiner's Title", 'Date of Rating'],
    'shared required-field list reports the missing labels');

// cleanup
$db->exec("DELETE FROM login_attempts WHERE username = 'api_unit_probe' OR ip = '198.51.100.20'");
$db->exec("DELETE FROM activity_logs WHERE details LIKE '%api_unit_probe%' OR details LIKE '%no_such_user_xyz%'");
$db->exec('DELETE FROM users WHERE username = "api_unit_probe"');
$db->exec('DELETE FROM api_tokens');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
