<?php
/**
 * includes/rbac.php — role-based access control: roles, group roles and
 * effective-permission helpers.
 *
 * The users.role column stays the account's BASE role (it feeds the badge,
 * the legacy display and the home-route default). Effective access =
 *   base role + direct grants (user_roles) + roles from assigned groups
 *   (user_role_groups → role_group_roles).
 * All page guards (gars_has_role / gars_require_role) check the effective set.
 */
if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ schema */

/** Create the RBAC tables and seed the three system roles (once per process). */
function gars_ensure_rbac_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = gars_db();
    $ddl = [
        "CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            granted_by INT UNSIGNED DEFAULT NULL,
            granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_ur_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS role_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS role_group_roles (
            group_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, role_id),
            CONSTRAINT fk_grg_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_grg_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_role_groups (
            user_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED DEFAULT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, group_id),
            CONSTRAINT fk_urg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_urg_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_urg_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) {
        $db->exec($sql);
    }
    // seed the three system roles (idempotent; system roles cannot be deleted)
    $ins = $db->prepare('INSERT IGNORE INTO roles (name, label, description, is_system) VALUES (?, ?, ?, 1)');
    $ins->execute(['therapist', 'Therapist', 'Create and edit own assessment records']);
    $ins->execute(['admin', 'Administrator', 'Records, scoring tables and user management']);
    $ins->execute(['it_admin', 'IT System Administrator', 'Everything an administrator can do, plus roles, groups, settings and the console']);
}

/** Drop the per-request effective-role cache (call after any RBAC mutation). */
function gars_rbac_flush(): void
{
    unset($GLOBALS['GARS_RBAC_CACHE']);
}

/* ------------------------------------------------------------ role catalog */

/** All roles as rows (system roles first), labels for selects and chips. */
function gars_all_roles(): array
{
    gars_ensure_rbac_tables();
    return gars_db()->query('SELECT * FROM roles ORDER BY is_system DESC, label')->fetchAll();
}

function gars_role_id_by_name(string $name): ?int
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/* --------------------------------------------------------- effective roles */

/**
 * Every role name that counts for this user: base role + direct grants +
 * roles carried by assigned groups. Cached per user per request; mutations
 * call gars_rbac_flush() so later checks in the same request stay fresh.
 */
function gars_user_role_names(array $user): array
{
    $id = (int) ($user['id'] ?? 0);
    $base = (string) ($user['role'] ?? '');
    if ($id <= 0) {
        return $base !== '' ? [$base] : [];
    }
    $cache = $GLOBALS['GARS_RBAC_CACHE'] ?? [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare(
        'SELECT r.name FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
         UNION
         SELECT r.name FROM user_role_groups ug
         JOIN role_group_roles gr ON gr.group_id = ug.group_id
         JOIN roles r ON r.id = gr.role_id
         WHERE ug.user_id = ?'
    );
    $stmt->execute([$id, $id]);
    $names = $base !== '' ? [$base] : [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $names[] = (string) $n;
    }
    $names = array_values(array_unique($names));
    $GLOBALS['GARS_RBAC_CACHE'][$id] = $names;
    return $names;
}

/* --------------------------------------------------------- assignment data */

function gars_user_direct_roles(int $userId): array
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare(
        'SELECT r.* FROM user_roles ur JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ? ORDER BY r.is_system DESC, r.label'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function gars_user_groups(int $userId): array
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare(
        'SELECT g.* FROM user_role_groups ug JOIN role_groups g ON g.id = ug.group_id
         WHERE ug.user_id = ? ORDER BY g.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function gars_groups(): array
{
    gars_ensure_rbac_tables();
    return gars_db()->query('SELECT * FROM role_groups ORDER BY name')->fetchAll();
}

function gars_group_roles(int $groupId): array
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare(
        'SELECT r.* FROM role_group_roles gr JOIN roles r ON r.id = gr.role_id
         WHERE gr.group_id = ? ORDER BY r.is_system DESC, r.label'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function gars_group_members(int $groupId): int
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare('SELECT COUNT(*) FROM user_role_groups WHERE group_id = ?');
    $stmt->execute([$groupId]);
    return (int) $stmt->fetchColumn();
}

/** Usage counters shown in the Roles & groups tab. */
function gars_role_usage(int $roleId): array
{
    gars_ensure_rbac_tables();
    $db = gars_db();
    $s = $db->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = ?');
    $s->execute([$roleId]);
    $direct = (int) $s->fetchColumn();
    $s = $db->prepare(
        'SELECT COUNT(*) FROM user_role_groups ug
         JOIN role_group_roles gr ON gr.group_id = ug.group_id WHERE gr.role_id = ?'
    );
    $s->execute([$roleId]);
    return ['direct' => $direct, 'via_groups' => (int) $s->fetchColumn()];
}

/* -------------------------------------------------------------------- CRUD */

function gars_create_role(string $name, string $label, string $description = ''): int
{
    gars_ensure_rbac_tables();
    $name = strtolower(trim($name));
    if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $name)) {
        throw new RuntimeException('Role name must be 2–40 characters: lowercase letters, numbers, underscores (must start with a letter).');
    }
    if (in_array($name, array_keys(gars_roles()), true)) {
        throw new RuntimeException('"' . $name . '" is a built-in role — pick another name.');
    }
    $stmt = gars_db()->prepare('INSERT INTO roles (name, label, description, is_system) VALUES (?, ?, ?, 0)');
    try {
        $stmt->execute([$name, trim($label) !== '' ? trim($label) : $name, trim($description) ?: null]);
    } catch (PDOException $e) {
        throw new RuntimeException('A role called "' . $name . '" already exists.');
    }
    gars_rbac_flush();
    return (int) gars_db()->lastInsertId();
}

function gars_update_role(int $id, string $label, string $description = ''): void
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare('UPDATE roles SET label = ?, description = ? WHERE id = ?');
    $stmt->execute([trim($label) !== '' ? trim($label) : '', trim($description) ?: null, $id]);
    gars_rbac_flush();
}

function gars_delete_role(int $id): void
{
    gars_ensure_rbac_tables();
    $stmt = gars_db()->prepare('SELECT is_system FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('That role does not exist.');
    }
    if ((int) $row['is_system'] === 1) {
        throw new RuntimeException('Built-in roles cannot be deleted.');
    }
    // pivots cascade; users keep their base role column untouched
    gars_db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
    gars_rbac_flush();
}

function gars_create_group(string $name, string $description = ''): int
{
    gars_ensure_rbac_tables();
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Group name is required.');
    }
    $stmt = gars_db()->prepare('INSERT INTO role_groups (name, description) VALUES (?, ?)');
    try {
        $stmt->execute([$name, trim($description) ?: null]);
    } catch (PDOException $e) {
        throw new RuntimeException('A group called "' . $name . '" already exists.');
    }
    gars_rbac_flush();
    return (int) gars_db()->lastInsertId();
}

function gars_update_group(int $id, string $name, string $description = ''): void
{
    gars_ensure_rbac_tables();
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Group name is required.');
    }
    $stmt = gars_db()->prepare('UPDATE role_groups SET name = ?, description = ? WHERE id = ?');
    try {
        $stmt->execute([$name, trim($description) ?: null, $id]);
    } catch (PDOException $e) {
        throw new RuntimeException('A group called "' . $name . '" already exists.');
    }
    gars_rbac_flush();
}

function gars_delete_group(int $id): void
{
    gars_ensure_rbac_tables();
    gars_db()->prepare('DELETE FROM role_groups WHERE id = ?')->execute([$id]);
    gars_rbac_flush();
}

/** Replace a group's role set with the given role ids. */
function gars_set_group_roles(int $groupId, array $roleIds): void
{
    gars_ensure_rbac_tables();
    $db = gars_db();
    $valid = [];
    if ($roleIds) {
        $in = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $db->prepare("SELECT id FROM roles WHERE id IN ($in)");
        $stmt->execute(array_values($roleIds));
        $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $db->prepare('DELETE FROM role_group_roles WHERE group_id = ?')->execute([$groupId]);
    if ($valid) {
        $ins = $db->prepare('INSERT INTO role_group_roles (group_id, role_id) VALUES (?, ?)');
        foreach ($valid as $rid) {
            $ins->execute([$groupId, $rid]);
        }
    }
    gars_rbac_flush();
}

function gars_grant_role(int $userId, int $roleId, ?int $grantedBy = null): void
{
    gars_ensure_rbac_tables();
    gars_db()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?)')
        ->execute([$userId, $roleId, $grantedBy]);
    gars_rbac_flush();
}

function gars_revoke_role(int $userId, int $roleId): void
{
    gars_ensure_rbac_tables();
    gars_db()->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?')
        ->execute([$userId, $roleId]);
    gars_rbac_flush();
}

function gars_assign_group(int $userId, int $groupId, ?int $assignedBy = null): void
{
    gars_ensure_rbac_tables();
    gars_db()->prepare('INSERT IGNORE INTO user_role_groups (user_id, group_id, assigned_by) VALUES (?, ?, ?)')
        ->execute([$userId, $groupId, $assignedBy]);
    gars_rbac_flush();
}

function gars_unassign_group(int $userId, int $groupId): void
{
    gars_ensure_rbac_tables();
    gars_db()->prepare('DELETE FROM user_role_groups WHERE user_id = ? AND group_id = ?')
        ->execute([$userId, $groupId]);
    gars_rbac_flush();
}

/* -------------------------------------------------------------- safeguards */

/** Active accounts whose BASE role is the given role. */
function gars_count_active_base(string $baseRole, int $excludeUserId = 0): int
{
    $stmt = gars_db()->prepare(
        'SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1 AND id <> ?'
    );
    $stmt->execute([$baseRole, $excludeUserId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Refuse changes that would lock everybody out of the console:
 *  - you cannot deactivate your own account or change your own base role;
 *  - you cannot deactivate or demote the last active base IT administrator.
 * $newBaseRole = null means "base role unchanged".
 */
function gars_assert_can_change_target(array $actor, array $target, bool $willDeactivate, ?string $newBaseRole = null): void
{
    $sameUser = (int) $actor['id'] === (int) $target['id'];
    if ($sameUser && $willDeactivate) {
        throw new RuntimeException('You cannot deactivate your own account.');
    }
    if ($sameUser && $newBaseRole !== null && $newBaseRole !== (string) $target['role']) {
        throw new RuntimeException('You cannot change your own base role.');
    }
    $losesItAdmin = (string) $target['role'] === 'it_admin'
        && ($willDeactivate || ($newBaseRole !== null && $newBaseRole !== 'it_admin'));
    if ($losesItAdmin && gars_count_active_base('it_admin', (int) $target['id']) === 0) {
        throw new RuntimeException('This is the last active IT administrator — promote another account first.');
    }
}
