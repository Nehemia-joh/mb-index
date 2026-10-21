<?php
/**
 * config.php — loads the .envmb-index file and defines shared app constants.
 *
 * The .envmb-index file is kept ONE STEP BEHIND the project folder (../.envmb-index),
 * so it is never reachable from the web, even on shared hosting.
 */

if (!defined('GARS_APP_ROOT')) {
    define('GARS_APP_ROOT', __DIR__);
}
/* .envmb-index location: one step behind the project folder */
define('GARS_ENV_DIR', dirname(GARS_APP_ROOT));
define('GARS_ENV_FILE', GARS_ENV_DIR . '/.envmb-index');

/* --- Lightweight .envmb-index parser (no external libraries needed) ---------------- */
function gars_load_env(string $file): array
{
    $vars = [];
    if (!is_readable($file)) {
        return $vars;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name  = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        if (function_exists('getenv') && getenv($name) !== false) {
            $value = getenv($name); // real environment wins over the .envmb-index file
        }
        $_ENV[$name] = $value;
        $vars[$name] = $value;
    }
    return $vars;
}

$GARS_ENV = gars_load_env(GARS_ENV_FILE);

function env(string $key, $default = null)
{
    global $GARS_ENV;
    $value = $GARS_ENV[$key] ?? ($_ENV[$key] ?? getenv($key) ?: null);
    return ($value === null || $value === '') ? $default : $value;
}

/* --- Shared constants ------------------------------------------------------ */
define('APP_NAME', env('APP_NAME', 'Maisha Bora Autism Rating Scale'));
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'maishabora_gars3'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
define('DEFAULT_ADMIN_USER', env('DEFAULT_ADMIN_USER', 'admin'));
define('DEFAULT_ADMIN_PASSWORD', env('DEFAULT_ADMIN_PASSWORD', ''));
define('DEFAULT_ADMIN_ROLE', env('DEFAULT_ADMIN_ROLE', 'admin'));
define('SESSION_TIMEOUT', (int) env('SESSION_TIMEOUT', 7200));

/* Roles the application knows about */
$GARS_ROLES = ['therapist' => 'Therapist', 'admin' => 'Administrator', 'it_admin' => 'IT System Administrator'];
