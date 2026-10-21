<?php
/**
 * route.php — front controller for clean URLs.
 *
 *     yourdomain.com/admin   ->  admin.php
 *     yourdomain.com/itadmin  ->  itadmin.php
 *     your-domain.com/login     ->  login.php
 *     your-domain.com/score     ->  score.php     (POSTs welcome)
 *
 * 1. Exact matches from the $routes map first.
 * 2. Otherwise "<name>" maps to "<name>.php" when that file exists.
 *    Protected folders (includes/) and the setup file are never routed.
 */

declare(strict_types=1);

define('GARS_APP_ROOT', __DIR__);
if (!defined('GARS_FRONT_CONTROLLER')) {
    define('GARS_FRONT_CONTROLLER', true);
}

$routes = [
    '/'          => 'index.php',
    ''           => 'index.php',
    'home'       => 'index.php',
    'index'      => 'index.php',
    'about'      => 'about.php',
    'score'      => 'score.php',
    'scoring'    => 'score.php',
    'login'      => 'login.php',
    'signin'     => 'login.php',
    'logout'     => 'logout.php',
    'register'   => 'register.php',
    'create-user'=> 'register.php',
    'admin'      => 'admin.php',
    'itadmin'    => 'itadmin.php',
    'it-admin'   => 'itadmin.php',
    'therapist'  => 'therapist.php',
    'records'    => 'assessments.php',
    'assessments'=> 'assessments.php',
    'assessment' => 'assessment.php',
    'setup'      => 'setup.php',
];

/* ------------------------------------------------------------------ resolve */

if (!defined('GARS_APP_ROOT')) { /* unreachable, keeps IDEs calm */ }

$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptDir !== '/' && str_starts_with($requested, rtrim($scriptDir, '/'))) {
    $requested = substr($requested, strlen(rtrim($scriptDir, '/')));
}
$requested = trim($requested, '/');
$requested = preg_replace('/\.(php|html?)$/i', '', $requested) ?? '';
$requested = $requested === '' ? '/' : strtolower($requested);

$target = $routes[$requested] ?? null;

// unknown route? allow automatic "name.php" mapping for safe file names
if ($target === null && $requested !== '/' && preg_match('/^[a-z0-9_\/-]+$/i', $requested)) {
    $candidate = GARS_APP_ROOT . '/' . basename($requested) . '.php';
    if (is_file($candidate) && substr($candidate, -10) !== 'includes/') {
        $target = basename($requested) . '.php';
    }
}

// never expose internal folders or the .envmb-index loader
$blocked = ['includes', 'config', 'vendor', 'templates', 'db'];
if ($target !== null && in_array(strtolower(pathinfo($target, PATHINFO_FILENAME)), $blocked, true)) {
    $target = null;
}

if ($target === null) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Page not found</title>
    <style>body{font-family:'DM Sans',system-ui,sans-serif;background:#f4f8ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{background:#fff;border-radius:12px;padding:40px;max-width:520px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3)}h1{color:#0B5CAF}p{color:#666;margin:12px 0}a{color:#0B5CAF;font-weight:600;text-decoration:none}</style></head>
    <body><div class="card"><h1>404 — Page not found</h1>
    <p>No page is mapped to <strong>/<?php echo htmlspecialchars($requested === '/' ? '' : $requested, ENT_QUOTES); ?></strong>.</p>
    <a href="<?php echo $scriptDir === '/' ? '/' : rtrim($scriptDir, '/') . '/'; ?>">Go to the homepage</a></div></body></html><?php
    exit;
}

define('GARS_ROUTE_NAME', $requested === '/' ? 'index' : $requested);
define('GARS_TARGET_FILE', GARS_APP_ROOT . '/' . $target);

require GARS_TARGET_FILE;
