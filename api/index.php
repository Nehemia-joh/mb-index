<?php
/**
 * api/index.php — JSON API front controller for the Maisha Bora mobile app
 * (Flutter). Routed by route.php for every /api/... request.
 *
 * Contract
 *   Base path : /api/v1
 *   Auth      : Authorization: Bearer <token>   (except POST /auth/login)
 *   Envelope  : {"ok":true,"data":...}  |  {"ok":false,"error":{"code","message"}}
 *   Codes     : 200/201 ok · 400 bad JSON · 401 auth · 403 forbidden ·
 *               404 not found · 405 method · 413 body too large ·
 *               422 validation · 423 locked · 500 save failed
 *
 * Endpoints
 *   GET    /api/v1                 discovery (endpoint list)
 *   POST   /api/v1/auth/login      {username,password,device?} -> {token,user}
 *   POST   /api/v1/auth/logout     revokes the presented token
 *   GET    /api/v1/auth/me         the signed-in user + effective roles
 *   GET    /api/v1/meta/form       subscales, item wordings, required fields
 *   GET    /api/v1/assessments     list (own records; admins see all) ?page&per_page
 *   POST   /api/v1/assessments     raw rating form -> scored, stored, 201
 *   GET    /api/v1/assessments/{id} full stored record
 *
 * Scoring never happens on the phone: POST sends the raw item answers and the
 * server runs the same gars_compute_scores() as the website, so scoring-table
 * revisions apply instantly to every client.
 */

declare(strict_types=1);

define('GARS_API_MODE', true); // auth.php: bearer tokens, never PHP sessions

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tracker.php';
require_once __DIR__ . '/../includes/rating_form.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/assessment_store.php';
require_once __DIR__ . '/../includes/login_core.php';
require_once __DIR__ . '/../includes/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ------------------------------------------------------------ path + route */

$uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (!preg_match('#/api/(.*)$#', $uri, $m)) {
    gars_api_error('Not found.', 404, 'not_found');
}
$path = trim($m[1], '/');
$path = (string) preg_replace('#^index\.php/*#', '', $path);
$path = (string) preg_replace('#^v1/*#', '', $path); // version prefix
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

gars_api_ensure_token_table();

$endpoints = [
    'GET    /api/v1',
    'POST   /api/v1/auth/login',
    'POST   /api/v1/auth/logout',
    'GET    /api/v1/auth/me',
    'GET    /api/v1/meta/form',
    'GET    /api/v1/assessments',
    'POST   /api/v1/assessments',
    'GET    /api/v1/assessments/{id}',
];

if ($method === 'GET' && $path === '') {
    gars_api_json([
        'name'      => 'Maisha Bora GARS-3 API',
        'version'   => 'v1',
        'endpoints' => $endpoints,
    ]);
}

/* ------------------------------------------------------------- handlers */

$ip = gars_client_ip();

if ($method === 'POST' && $path === 'auth/login') {
    $body = gars_api_body();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $device   = mb_substr(trim((string) ($body['device'] ?? '')), 0, 191);
    if ($username === '' || $password === '') {
        gars_api_error('Username and password are required.', 422, 'validation');
    }
    $result = gars_attempt_login($username, $password, $ip);
    if ($result['blocked']) {
        gars_api_error((string) $result['error'], 423, 'locked');
    }
    if (!$result['ok']) {
        gars_api_error((string) $result['error'], 401, 'invalid_credentials');
    }
    $user = $result['user'];
    $GLOBALS['GARS_LOG_ACTOR'] = $user;
    $token = gars_api_issue_token((int) $user['id'], $device);
    gars_log_activity('login', 'api', 'API login as ' . $user['username'] . ' (' . $user['role'] . ')'
        . ($device !== '' ? ' via ' . $device : ''));
    gars_api_json([
        'token' => $token,
        'user'  => gars_api_user_payload($user),
    ]);
}

if ($method === 'POST' && $path === 'auth/logout') {
    $auth = gars_api_require_user();
    gars_api_revoke_token_id($auth['token_id']);
    gars_log_activity('logout', 'api', 'API logout for ' . $auth['user']['username']);
    gars_api_json(['signed_out' => true]);
}

if ($method === 'GET' && $path === 'auth/me') {
    $auth = gars_api_require_user();
    gars_api_json(['user' => gars_api_user_payload($auth['user'])]);
}

if ($method === 'GET' && $path === 'meta/form') {
    gars_api_require_user();
    $spec = gars_rating_required();
    gars_api_json([
        'api_version'     => 'v1',
        'subscales'       => array_map(
            fn (array $s): array => [
                'id'         => $s['id'],
                'name'       => $s['name'],
                'short'      => $s['short'],
                'item_count' => count($s['questions']),
                'questions'  => $s['questions'],
            ],
            gars_subscale_defs()
        ),
        'item_scale'      => ['min' => 0, 'max' => 3],
        'gender_options'  => ['Female', 'Male'],
        'is_mute_values'  => ['no', 'yes'],
        'required_fields' => array_keys($spec['fields']),
        'required_labels' => $spec['labels'],
    ]);
}

if ($path === 'assessments' || str_starts_with($path, 'assessments/')) {
    $auth = gars_api_require_user();
    $user = $auth['user'];

    if ($method === 'GET' && $path === 'assessments') {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;
        $seeAll  = gars_api_sees_all_records($user);
        $db = gars_db();
        if ($seeAll) {
            $total = (int) $db->query('SELECT COUNT(*) FROM assessments')->fetchColumn();
            $stmt = $db->query(
                'SELECT a.*, u.username AS creator FROM assessments a
                 LEFT JOIN users u ON u.id = a.created_by
                 ORDER BY a.created_at DESC, a.id DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
        } else {
            $cnt = $db->prepare('SELECT COUNT(*) FROM assessments WHERE created_by = ?');
            $cnt->execute([(int) $user['id']]);
            $total = (int) $cnt->fetchColumn();
            $stmt = $db->prepare(
                'SELECT a.*, u.username AS creator FROM assessments a
                 LEFT JOIN users u ON u.id = a.created_by
                 WHERE a.created_by = ?
                 ORDER BY a.created_at DESC, a.id DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute([(int) $user['id']]);
        }
        $rows = $stmt->fetchAll();
        gars_api_json([
            'items'     => array_map('gars_assessment_list_shape', $rows),
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'has_more'  => $offset + count($rows) < $total,
            'scope'     => $seeAll ? 'all' : 'own',
        ]);
    }

    if ($method === 'POST' && $path === 'assessments') {
        $body = gars_api_body();
        $missing = gars_rating_missing($body);
        if ($missing) {
            gars_api_error(
                'Missing required field(s): ' . implode(', ', $missing) . '.',
                422,
                'validation',
                ['missing' => $missing]
            );
        }
        $isMute = ($body['is_mute'] ?? 'no') === 'yes';
        $computed = gars_compute_scores($body, $isMute);
        try {
            $id = gars_persist_assessment((int) $user['id'], $body, $computed);
        } catch (Throwable $e) {
            error_log('[api] assessment save failed: ' . $e->getMessage());
            gars_log_activity('save_failed', 'api', 'Assessment save failed: ' . $e->getMessage());
            gars_api_error('The record could not be saved.', 500, 'save_failed');
        }
        $GLOBALS['GARS_LOG_ACTOR'] = $user;
        gars_log_activity(
            'create_assessment',
            'api',
            'API saved record #' . $id . ' for "' . trim((string) ($body['individual_name'] ?? ''))
                . '" (Autism Index ' . (int) $computed['autism_index'] . ')'
        );
        $stmt = gars_db()->prepare(
            'SELECT a.*, u.username AS creator FROM assessments a
             LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        gars_api_json([
            'id'     => $id,
            'record' => $row ? gars_assessment_api_shape($row) : null,
            'report' => $computed,
        ], 201);
    }

    if ($method === 'GET' && preg_match('#^assessments/(\d+)$#', $path, $m2)) {
        $stmt = gars_db()->prepare(
            'SELECT a.*, u.username AS creator FROM assessments a
             LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?'
        );
        $stmt->execute([(int) $m2[1]]);
        $row = $stmt->fetch();
        // other people's records do not exist for non-admins (no existence leak)
        if (!$row || (!gars_api_sees_all_records($user) && (int) $row['created_by'] !== (int) $user['id'])) {
            gars_api_error('Record not found.', 404, 'not_found');
        }
        gars_api_json(['record' => gars_assessment_api_shape($row)]);
    }

    // known resource, wrong verb
    gars_api_error('Method not allowed.', 405, 'method_not_allowed');
}

if (str_starts_with($path, 'auth/') || str_starts_with($path, 'meta/')) {
    gars_api_error('Method not allowed.', 405, 'method_not_allowed');
}

gars_api_error('Not found.', 404, 'not_found');
