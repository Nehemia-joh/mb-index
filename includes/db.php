<?php
/**
 * includes/db.php — the single MySQL connection used by the whole system.
 * Host, name, user and password all come from the .envmb-index file (one step behind
 * the project folder). Exposes gars_db(): a shared PDO handle.
 */

if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}

function gars_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Wrong host/name/user/password in the .envmb-index, or MySQL down — surface it clearly.
        http_response_code(500);
        exit(
            '<strong>MySQL connection failed.</strong> Check DB_NAME, DB_USER and DB_PASS '
            . 'in the .envmb-index file (loaded one step behind the project folder).<br><br>'
            . 'Detail: ' . htmlspecialchars($e->getMessage())
        );
    }
    return $pdo;
}

/** TRUE when the schema has been installed. */
function gars_db_installed(): bool
{
    try {
        return (bool) gars_db()->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
