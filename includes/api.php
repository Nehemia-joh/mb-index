<?php
/**
 * includes/api.php — JSON helpers and opaque bearer tokens for the Maisha
 * Bora mobile API (/api/v1/...). Pure function library: no side effects at
 * require time, safe to include from web pages (itadmin.php revokes tokens
 * when an account is disabled or its password is reset).
 *
 * Token model
 *   - 32 random bytes, hex-encoded, returned to the client ONCE at login.
 *   - Only the SHA-256 hash is stored, so a database leak cannot be replayed.
 *   - Revocable (logout, account deactivated, password reset) and subject to
 *     the same SESSION_TIMEOUT idle policy as web sessions.
 *   - Requests send:  Authorization: Bearer <token>
 */

/** Create the api_tokens table if it does not exist (idempotent, cached per request). */
function gars_api_ensure_token_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    gars_db()->exec(
        'CREATE TABLE IF NOT EXISTS api_tokens (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NOT NULL,
            token_hash   CHAR(64)     NOT NULL,
            device       VARCHAR(191) DEFAULT NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at   DATETIME     DEFAULT NULL,
            revoked_at   DATETIME     DEFAULT NULL,
            UNIQUE KEY uq_token_hash (token_hash),
            KEY idx_tok_user (user_id),
            CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

/* ------------------------------------------------------------- JSON output */

/** Emit a success envelope and stop. */
function gars_api_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Emit an error envelope and stop. */
function gars_api_error(string $message, int $status, string $code = 'error', array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

/** Read the JSON request body (cached). Empty body -> []; malformed -> 400. */
function gars_api_body(): array
{
    static $cached = false;
    static $body = [];
    if ($cached) {
        return $body;
    }
    $cached = true;
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $body = [];
    }
    if (strlen($raw) > 1_048_576) {
        gars_api_error('Request body too large.', 413, 'payload_too_large');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        gars_api_error('Request body must be a JSON object.', 400, 'invalid_json');
    }
    return $body = $decoded;
}

/* ------------------------------------------------------------------ tokens */

/**
 * Issue a new bearer token for $userId. The plaintext value is returned once;
 * only its SHA-256 hash is persisted.
 */
function gars_api_issue_token(int $userId, string $device = ''): string
{
    gars_api_ensure_token_table();
    $plain = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s'); // PHP clock: never mix MySQL NOW() with time() checks (tz skew)
    $stmt = gars_db()->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, device, last_used_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, hash('sha256', $plain), $device !== '' ? $device : null, $now]);
    return $plain;
}

/** Revoke a single token by its id. Returns true when a live token was killed. */
function gars_api_revoke_token_id(int $tokenId): bool
{
    gars_api_ensure_token_table();
    $stmt = gars_db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $stmt->execute([$tokenId]);
    return $stmt->rowCount() > 0;
}

/** Revoke every live token of a user (logout-all, deactivation, password reset). */
function gars_api_revoke_user_tokens(int $userId): int
{
    gars_api_ensure_token_table();
    $stmt = gars_db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Resolve a plaintext token to ['user' => array, 'token_id' => int] or fail.
 * On failure returns ['error' => <code>] — the caller turns that into 401.
 * Codes: missing_token, invalid_token, token_revoked, token_expired,
 *        account_disabled.
 */
function gars_api_resolve_token(string $plain): array
{
    gars_api_ensure_token_table();
    if ($plain === '') {
        return ['error' => 'missing_token'];
    }
    $stmt = gars_db()->prepare(
        'SELECT t.id AS token_id, t.revoked_at, t.expires_at, t.last_used_at,
                u.id, u.username, u.role, u.full_name, u.email, u.is_active
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $plain)]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['error' => 'invalid_token'];
    }
    if ($row['revoked_at'] !== null) {
        return ['error' => 'token_revoked'];
    }
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return ['error' => 'token_expired'];
    }
    if (!(int) $row['is_active']) {
        gars_api_revoke_token_id((int) $row['token_id']);
        return ['error' => 'account_disabled'];
    }
    // sliding idle timeout — same policy as web sessions (0 = never)
    $last = strtotime((string) $row['last_used_at']) ?: time();
    if (SESSION_TIMEOUT > 0 && (time() - $last) > SESSION_TIMEOUT) {
        gars_api_revoke_token_id((int) $row['token_id']);
        return ['error' => 'token_expired'];
    }
    $user = [
        'id'        => (int) $row['id'],
        'username'  => (string) $row['username'],
        'role'      => (string) $row['role'],
        'full_name' => (string) $row['full_name'],
        'email'     => (string) ($row['email'] ?? ''),
    ];
    gars_db()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), (int) $row['token_id']]);
    return ['user' => $user, 'token_id' => (int) $row['token_id']];
}

/**
 * Authenticate the Authorization: Bearer header or stop with a 401.
 * Returns ['user' => array, 'token_id' => int] with GARS_LOG_ACTOR set so the
 * activity trail attributes API rows to the calling account.
 */
function gars_api_require_user(): array
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        gars_api_error('Missing bearer token — sign in first.', 401, 'missing_token');
    }
    $result = gars_api_resolve_token($m[1]);
    if (isset($result['error'])) {
        $messages = [
            'missing_token'     => 'Missing bearer token — sign in first.',
            'invalid_token'     => 'That token is not valid.',
            'token_revoked'     => 'This session was signed out.',
            'token_expired'     => 'Your session expired — sign in again.',
            'account_disabled'  => 'This account has been disabled.',
        ];
        $message = $messages[$result['error']] ?? 'Not authenticated.';
        gars_api_error($message, 401, $result['error']);
    }
    $GLOBALS['GARS_LOG_ACTOR'] = $result['user'];
    return $result;
}

/** The user object sent to clients (effective roles + landing route included). */
function gars_api_user_payload(array $user): array
{
    return [
        'id'         => (int) $user['id'],
        'username'   => (string) $user['username'],
        'role'       => (string) $user['role'],
        'full_name'  => (string) $user['full_name'],
        'email'      => (string) ($user['email'] ?? ''),
        'roles'      => gars_user_role_names($user),
        'home_route' => gars_home_route($user),
    ];
}

/** True when the user may see every record (admin / it_admin effective role). */
function gars_api_sees_all_records(array $user): bool
{
    $roles = gars_user_role_names($user);
    return in_array('admin', $roles, true) || in_array('it_admin', $roles, true);
}
