<?php
/**
 * itadmin.php — the administration console, in tabs:
 *   Overview | Users (admin+it_admin) | Roles & groups | Activity logs | Settings
 * Route: /itadmin?tab=…
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/api.php';
define('GARS_ROUTE', 'itadmin');

/* ------------------------------------------------------------------- tabs */

$TAB_ROLES = [
    'overview' => ['it_admin'],
    'users'    => ['admin', 'it_admin'],
    'roles'    => ['it_admin'],
    'logs'     => ['admin', 'it_admin'],
    'settings' => ['it_admin'],
];
$TAB_LABELS = [
    'overview' => 'Overview',
    'users'    => 'Users',
    'roles'    => 'Roles & groups',
    'logs'     => 'Activity logs',
    'settings' => 'Settings',
];

$tab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($TAB_ROLES[$tab])) {
    $tab = 'overview';
}
// an administrator without a console lands on the tab they are allowed to use
if ($tab === 'overview' && gars_has_role('admin') && !gars_has_role('it_admin')) {
    $tab = 'users';
}
$user = gars_require_role($TAB_ROLES[$tab]);

$visibleTabs = [];
foreach ($TAB_ROLES as $key => $roles) {
    if (gars_has_role(...$roles)) {
        $visibleTabs[$key] = $TAB_LABELS[$key];
    }
}
gars_track_page('page_visit', 'IT admin: ' . $TAB_LABELS[$tab]);

$tabUrl = static function (string $t): string {
    return gars_url('itadmin') . '?tab=' . $t;
};

/* ------------------------------------------------------- POST: all actions */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    gars_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $itOnly = ['save_lockout', 'reset_lockout', 'create_role', 'update_role', 'delete_role',
               'create_group', 'update_group', 'delete_group', 'set_group_roles'];
    $userMgmt = ['create_user', 'update_user'];
    if (in_array($action, $itOnly, true) && !gars_has_role('it_admin')) {
        http_response_code(403);
        exit('403 — You do not have permission to view that page.');
    }
    if (in_array($action, $userMgmt, true) && !gars_has_role('admin', 'it_admin')) {
        http_response_code(403);
        exit('403 — You do not have permission to view that page.');
    }
    $back = static function (string $t, array $extra = []) use ($tabUrl): string {
        return $tabUrl($t) . ($extra ? '&' . http_build_query($extra) : '');
    };

    // ---- settings: login lockout limits ------------------------------------
    if ($action === 'save_lockout') {
        $limits = [];
        $badSpec = null;
        foreach (GARS_LOCK_LIMITS as $key => $spec) {
            $raw = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
            if ($raw === false || $raw < $spec['min'] || $raw > $spec['max']) {
                $badSpec = $spec;
                break;
            }
            $limits[$key] = (int) $raw;
        }
        if ($badSpec !== null) {
            gars_flash(sprintf('Lockout limits must be whole numbers between %d and %d.', $badSpec['min'], $badSpec['max']), 'error');
        } else {
            foreach ($limits as $key => $value) {
                gars_save_login_setting($key, $value, (int) $user['id']);
            }
            gars_log_activity(
                'settings',
                'itadmin',
                'Login lockout limits saved: ' . $limits['login_user_limit'] . ' per account, '
                . $limits['login_ip_limit'] . ' per IP, lock ' . $limits['login_lock_minutes'] . ' min'
            );
            gars_flash('Login lockout limits saved.');
        }
        gars_redirect($back('settings'));
    }
    if ($action === 'reset_lockout') {
        gars_delete_login_settings();
        gars_log_activity('settings', 'itadmin', 'Login lockout limits reset to defaults');
        gars_flash('Login lockout limits reset to the defaults.');
        gars_redirect($back('settings'));
    }

    // ---- users: create / update (profile, base role, password, roles, groups)
    if ($action === 'create_user') {
        $username = trim((string) ($_POST['new_username'] ?? ''));
        $password = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $role     = trim((string) ($_POST['role'] ?? ''));
        $missing = gars_validate_required(
            ['new_username' => $username, 'new_password' => $password, 'confirm' => $confirm,
             'full_name' => $fullName, 'role' => $role],
            ['new_username' => 'Username', 'new_password' => 'Password', 'confirm' => 'Confirm password',
             'full_name' => 'Full name', 'role' => 'Role']
        );
        $err = null;
        if ($missing) {
            $err = 'Nothing was saved — these required fields are missing: ' . implode(', ', $missing) . '.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            $err = 'Username must be 3–60 characters (letters, numbers, dots, dashes).';
        } elseif (strlen($password) < 8) {
            $err = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $err = 'Password and confirm password do not match.';
        } elseif (!in_array($role, array_keys(gars_roles()), true)) {
            $err = 'Please choose a valid role.';
        }
        if ($err === null) {
            try {
                $newId = gars_create_user($username, $password, $role, $fullName, $email, true);
                gars_log_activity('user_create', 'itadmin', 'Created ' . $role . " \"{$username}\" (user #{$newId})");
                gars_flash($username . ' was created as ' . gars_role_label($role) . '.');
                gars_redirect($back('users'));
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
        }
        gars_flash($err, 'error');
        gars_redirect($back('users'));
    }

    if ($action === 'update_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $stmt = gars_db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $role     = trim((string) ($_POST['role'] ?? ''));
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $newPass  = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');
        $roleIds  = array_values(array_unique(array_map('intval', (array) ($_POST['roles'] ?? []))));
        $groupIds = array_values(array_unique(array_map('intval', (array) ($_POST['groups'] ?? []))));
        $err = null;
        if (!$target) {
            $err = 'That account no longer exists.';
        } elseif ($fullName === '') {
            $err = 'Full name is required.';
        } elseif (!in_array($role, array_keys(gars_roles()), true)) {
            $err = 'Please choose a valid role.';
        } elseif ($newPass !== '' && strlen($newPass) < 8) {
            $err = 'Password must be at least 8 characters long.';
        } elseif ($newPass !== $confirm) {
            $err = 'Password and confirm password do not match.';
        }
        if ($err === null) {
            try {
                $willDeactivate = $active === 0 && (int) $target['is_active'] === 1;
                gars_assert_can_change_target($user, $target, $willDeactivate, $role);
                $db = gars_db();
                if ($newPass !== '') {
                    $db->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ?')
                        ->execute([$fullName, $email !== '' ? $email : null, $role, $active,
                                   password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]), $targetId]);
                    gars_clear_login_failures((string) $target['username']);
                } else {
                    $db->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?')
                        ->execute([$fullName, $email !== '' ? $email : null, $role, $active, $targetId]);
                }
                if ($willDeactivate) {
                    $db->prepare('UPDATE user_sessions SET logout_at = ? WHERE user_id = ? AND logout_at IS NULL')
                        ->execute([date('Y-m-d H:i:s'), $targetId]);
                }
                // mobile API tokens die with a deactivation or a password reset
                $revokedTokens = ($willDeactivate || $newPass !== '')
                    ? gars_api_revoke_user_tokens($targetId)
                    : 0;
                // sync direct roles
                $currentRoles = array_map('intval', array_column(gars_user_direct_roles($targetId), 'id'));
                foreach ($roleIds as $rid) {
                    if (!in_array($rid, $currentRoles, true)) {
                        gars_grant_role($targetId, $rid, (int) $user['id']);
                    }
                }
                foreach ($currentRoles as $rid) {
                    if (!in_array($rid, $roleIds, true)) {
                        gars_revoke_role($targetId, $rid);
                    }
                }
                // sync group membership
                $currentGroups = array_map('intval', array_column(gars_user_groups($targetId), 'id'));
                foreach ($groupIds as $gid) {
                    if (!in_array($gid, $currentGroups, true)) {
                        gars_assign_group($targetId, $gid, (int) $user['id']);
                    }
                }
                foreach ($currentGroups as $gid) {
                    if (!in_array($gid, $groupIds, true)) {
                        gars_unassign_group($targetId, $gid);
                    }
                }
                $bits = ['base role ' . $role];
                $bits[] = count($roleIds) . ' direct role(s)';
                $bits[] = count($groupIds) . ' group(s)';
                if ($newPass !== '') {
                    $bits[] = 'password reset';
                }
                $bits[] = $active ? 'active' : 'disabled';
                if ($revokedTokens > 0) {
                    $bits[] = $revokedTokens . ' API token(s) revoked';
                }
                gars_log_activity('user_update', 'itadmin', 'Updated ' . $target['username'] . ' (' . implode(', ', $bits) . ')');
                gars_flash($target['username'] . ' saved.');
                gars_redirect($back('users'));
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
        }
        gars_flash($err, 'error');
        gars_redirect($back('users', ['edit' => $targetId]));
    }

    // ---- roles & groups -----------------------------------------------------
    if ($action === 'create_role') {
        try {
            $name = gars_create_role(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            gars_log_activity('role_create', 'itadmin', 'Created role "' . strtolower(trim((string) ($_POST['name'] ?? ''))) . '" (#' . $name . ')');
            gars_flash('Role created.');
        } catch (RuntimeException $e) {
            gars_flash($e->getMessage(), 'error');
        }
        gars_redirect($back('roles'));
    }
    if ($action === 'update_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            gars_flash('Role label is required.', 'error');
        } else {
            gars_update_role($roleId, $label, (string) ($_POST['description'] ?? ''));
            gars_log_activity('role_update', 'itadmin', 'Updated role #' . $roleId . ' label to "' . $label . '"');
            gars_flash('Role saved.');
        }
        gars_redirect($back('roles'));
    }
    if ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        try {
            gars_delete_role($roleId);
            gars_log_activity('role_delete', 'itadmin', 'Deleted role #' . $roleId . ' (grants removed)');
            gars_flash('Role deleted — it was removed from every user and group that had it.');
        } catch (RuntimeException $e) {
            gars_flash($e->getMessage(), 'error');
        }
        gars_redirect($back('roles'));
    }
    if ($action === 'create_group') {
        try {
            $gid = gars_create_group((string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
            gars_log_activity('group_create', 'itadmin', 'Created role group "' . trim((string) ($_POST['name'] ?? '')) . '" (#' . $gid . ')');
            gars_flash('Group created — now give it some roles below.');
        } catch (RuntimeException $e) {
            gars_flash($e->getMessage(), 'error');
        }
        gars_redirect($back('roles'));
    }
    if ($action === 'update_group') {
        $gid = (int) ($_POST['group_id'] ?? 0);
        try {
            gars_update_group($gid, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
            gars_log_activity('group_update', 'itadmin', 'Updated role group #' . $gid);
            gars_flash('Group saved.');
        } catch (RuntimeException $e) {
            gars_flash($e->getMessage(), 'error');
        }
        gars_redirect($back('roles', ['edit_group' => $gid]));
    }
    if ($action === 'delete_group') {
        $gid = (int) ($_POST['group_id'] ?? 0);
        gars_delete_group($gid);
        gars_log_activity('group_delete', 'itadmin', 'Deleted role group #' . $gid . ' (members unassigned)');
        gars_flash('Group deleted — its members keep their own roles.');
        gars_redirect($back('roles'));
    }
    if ($action === 'set_group_roles') {
        $gid = (int) ($_POST['group_id'] ?? 0);
        gars_set_group_roles($gid, array_map('intval', (array) ($_POST['roles'] ?? [])));
        gars_log_activity('group_roles', 'itadmin', 'Updated the role set of group #' . $gid);
        gars_flash('Group roles saved.');
        gars_redirect($back('roles', ['edit_group' => $gid]));
    }

    gars_redirect($back('overview'));
}

/* ------------------------------------------------------------------- data */

$db = gars_db();

$editUserId = (int) ($_GET['edit'] ?? 0);
$editRoleId = (int) ($_GET['edit_role'] ?? 0);
$editGroupId = (int) ($_GET['edit_group'] ?? 0);

$stats = [
    'users'      => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'     => (int) $db->query('SELECT COUNT(*) FROM user_sessions WHERE logout_at IS NULL')->fetchColumn(),
    'sessions'   => (int) $db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(),
    'activities' => (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];

$sessions = [];
$activities = [];
$userRows = [];
$roleRows = [];
$groupRows = [];
$editUser = null;
$editRole = null;
$editGroup = null;
$allRoles = [];
$allGroups = [];

if ($tab === 'overview') {
    $expired = SESSION_TIMEOUT > 0 ? date('Y-m-d H:i:s', time() - SESSION_TIMEOUT) : null;
    if ($expired) {
        // auto-close sessions idle for longer than SESSION_TIMEOUT (keeps the trail accurate)
        $db->prepare("UPDATE user_sessions SET logout_at = COALESCE(logout_at, ?)
                      WHERE logout_at IS NULL AND last_activity_at < ?")->execute([$expired, $expired]);
    }
    $sessions = $db->query(
        'SELECT s.*, u.username, u.role FROM user_sessions s
         LEFT JOIN users u ON u.id = s.user_id
         ORDER BY (s.logout_at IS NULL) DESC, s.last_activity_at DESC LIMIT 30'
    )->fetchAll();
}

if ($tab === 'users') {
    $allRoles = gars_all_roles();
    $allGroups = gars_groups();
    $userRows = $db->query('SELECT id, username, full_name, email, role, is_active, last_login_at
                            FROM users ORDER BY role, username')->fetchAll();
    if ($editUserId > 0) {
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$editUserId]);
        $editUser = $stmt->fetch() ?: null;
        if (!$editUser) {
            gars_flash('That account no longer exists.', 'error');
            gars_redirect($tabUrl('users'));
        }
    }
}

if ($tab === 'roles') {
    $allRoles = gars_all_roles();
    $allGroups = gars_groups();
    if ($editRoleId > 0) {
        foreach ($allRoles as $r) {
            if ((int) $r['id'] === $editRoleId) {
                $editRole = $r;
            }
        }
    }
    if ($editGroupId > 0) {
        foreach ($allGroups as $g) {
            if ((int) $g['id'] === $editGroupId) {
                $editGroup = $g;
            }
        }
        if (!$editGroup) {
            gars_flash('That group no longer exists.', 'error');
            gars_redirect($tabUrl('roles'));
        }
    }
}

/* ---- logs tab: filters + pagination (same params as /activity) ---------- */
$logTotal = 0;
$logRows = [];
$logPage = 1;
$logPages = 1;
$logFromN = 0;
$logToN = 0;
$fUser = $fAction = $fFrom = $fTo = $fQ = '';
$logQs = static fn (array $extra = []): string => '';
if ($tab === 'logs') {
    $allUsers = $db->query('SELECT username FROM users ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
    $actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

    $fUser = trim((string) ($_GET['u'] ?? ''));
    if (!in_array($fUser, $allUsers, true)) {
        $fUser = '';
    }
    $fAction = trim((string) ($_GET['action'] ?? ''));
    if (!in_array($fAction, $actions, true)) {
        $fAction = '';
    }
    $fFrom = trim((string) ($_GET['from'] ?? ''));
    $d = $fFrom !== '' ? DateTime::createFromFormat('!Y-m-d', $fFrom) : false;
    if ($fFrom !== '' && ($d === false || $d->format('Y-m-d') !== $fFrom)) {
        $fFrom = '';
    }
    $fTo = trim((string) ($_GET['to'] ?? ''));
    $d = $fTo !== '' ? DateTime::createFromFormat('!Y-m-d', $fTo) : false;
    if ($fTo !== '' && ($d === false || $d->format('Y-m-d') !== $fTo)) {
        $fTo = '';
    }
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($fQ) > 100) {
        $fQ = mb_substr($fQ, 0, 100);
    }

    $where = [];
    $args = [];
    if ($fUser !== '') {
        $where[] = 'username = ?';
        $args[] = $fUser;
    }
    if ($fAction !== '') {
        $where[] = 'action = ?';
        $args[] = $fAction;
    }
    if ($fFrom !== '') {
        $where[] = 'created_at >= ?';
        $args[] = $fFrom . ' 00:00:00';
    }
    if ($fTo !== '') {
        $where[] = 'created_at <= ?';
        $args[] = $fTo . ' 23:59:59';
    }
    if ($fQ !== '') {
        $like = '%' . $fQ . '%';
        $where[] = '(details LIKE ? OR username LIKE ? OR page LIKE ? OR action LIKE ? OR ip_address LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $cnt = $db->prepare('SELECT COUNT(*) FROM activity_logs' . $sqlWhere);
    $cnt->execute($args);
    $logTotal = (int) $cnt->fetchColumn();
    $perPage = 50;
    $logPages = max(1, (int) ceil($logTotal / $perPage));
    $logPage = (int) ($_GET['p'] ?? 1);
    $logPage = max(1, min($logPage, $logPages));
    $offset = ($logPage - 1) * $perPage;
    $rows = $db->prepare('SELECT * FROM activity_logs' . $sqlWhere
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $rows->execute($args);
    $logRows = $rows->fetchAll();
    $logFromN = $logTotal > 0 ? $offset + 1 : 0;
    $logToN = min($offset + $perPage, $logTotal);
    $logQs = static function (array $extra = []) use ($fUser, $fAction, $fFrom, $fTo, $fQ): string {
        return gars_url('itadmin') . '?' . http_build_query(array_merge(
            ['tab' => 'logs', 'u' => $fUser, 'action' => $fAction, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ],
            $extra
        ));
    };
}

/* --------------------------------------------------------------- rendering */

gars_header('IT Admin', 'itadmin');
?>
<div class="page-head">
    <h1>IT administration</h1>
    <p class="sub">Accounts, roles, group roles, the activity trail and login security —
        all in one console. Database: MySQL on <strong><?php echo gars_e(DB_HOST); ?></strong>,
        credentials read from the .envmb-index file.</p>
</div>

<div class="tabs" style="display:flex; gap:8px; flex-wrap:wrap; margin:2px 0 26px;">
    <?php foreach ($visibleTabs as $key => $label): ?>
        <a class="btn <?php echo $key === $tab ? '' : 'ghost'; ?>" style="padding:9px 16px; font-size:.92rem;"
           href="<?php echo gars_e($tabUrl($key)); ?>"><?php echo gars_e($label); ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>

<div class="stats">
    <div class="stat"><span class="s-n"><?php echo $stats['users']; ?></span><span class="s-l">User accounts</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['active']; ?></span><span class="s-l">Active sessions</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['sessions']; ?></span><span class="s-l">Total sessions</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['activities']; ?></span><span class="s-l">Activity entries</span></div>
</div>

<p class="muted" style="margin-top:12px;">
    Sessions idle for over <?php echo (int) (SESSION_TIMEOUT / 60); ?> minutes are closed automatically.
    Manage accounts on the <a class="a-link" href="<?php echo gars_e($tabUrl('users')); ?>">Users</a> tab;
    the full trail lives under <a class="a-link" href="<?php echo gars_e($tabUrl('logs')); ?>">Activity logs</a>.
</p>

<div class="section">
    <h2>Login sessions</h2>
    <p class="muted" style="margin-bottom:12px;">One row per login: the page it is on now, the page it came from, and how it ended.
        <a class="a-link" href="<?php echo gars_url('sessions'); ?>">Open sessions by user →</a></p>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th>User</th><th>Role</th><th>Current page</th><th>Came from</th><th class="n">Page views</th><th>Logged in</th><th>Last activity</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><strong><?php echo gars_e($s['username'] ?? ('user #' . $s['user_id'])); ?></strong></td>
                <td><?php echo $s['role'] ? '<span class="badge ' . gars_e($s['role']) . '">' . gars_e(gars_role_label($s['role'])) . '</span>' : '-'; ?></td>
                <td><?php echo gars_e($s['current_page'] ?? '-'); ?></td>
                <td><?php echo gars_e($s['prev_page'] ?? '—'); ?></td>
                <td class="n"><?php echo (int) $s['page_views']; ?></td>
                <td><?php echo gars_e($s['login_at']); ?></td>
                <td><?php echo gars_e($s['last_activity_at']); ?></td>
                <td><span class="badge <?php echo $s['logout_at'] ? 'off' : 'on'; ?>"><?php echo $s['logout_at'] ? 'Closed' : 'Active'; ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($tab === 'users'): ?>

<div class="section" style="border-top:none; padding-top:0;">
    <h2>Create user</h2>
    <form method="post" action="<?php echo gars_e($tabUrl('users')); ?>" autocomplete="off">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="create_user">
        <div class="form-row">
            <div class="form-group">
                <label for="new_username">Username <span class="req">*</span></label>
                <input type="text" id="new_username" name="new_username" required
                       pattern="[a-zA-Z0-9._-]{3,60}" title="3–60 characters: letters, numbers, dots, dashes">
            </div>
            <div class="form-group">
                <label for="full_name">Full name <span class="req">*</span></label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div class="form-group">
                <label for="email">Email (optional)</label>
                <input type="email" id="email" name="email">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="role">Base role <span class="req">*</span></label>
                <select id="role" name="role" required>
                    <option value="">Select a role</option>
                    <?php foreach (gars_roles() as $value => $label): ?>
                        <option value="<?php echo gars_e($value); ?>"><?php echo gars_e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="new_password">Password <span class="req">*</span> <span class="muted" style="font-weight:400;">(at least 8 characters)</span></label>
                <input type="password" id="new_password" name="new_password" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="confirm">Confirm password <span class="req">*</span></label>
                <input type="password" id="confirm" name="confirm" minlength="8" required>
            </div>
        </div>
        <button type="submit" class="btn">Create user</button>
        <span class="muted" style="margin-left:12px; font-size:.88rem;">Roles and group memberships are assigned under Manage below.</span>
    </form>
</div>

<?php if ($editUser): ?>
<div class="section">
    <h2>Manage <?php echo gars_e($editUser['username']); ?></h2>
    <form method="post" action="<?php echo gars_e($tabUrl('users')); ?>" autocomplete="off">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" value="<?php echo (int) $editUser['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="m_username">Username</label>
                <input type="text" id="m_username" value="<?php echo gars_e($editUser['username']); ?>" disabled>
            </div>
            <div class="form-group">
                <label for="m_full_name">Full name <span class="req">*</span></label>
                <input type="text" id="m_full_name" name="full_name" required
                       value="<?php echo gars_e($editUser['full_name']); ?>">
            </div>
            <div class="form-group">
                <label for="m_email">Email</label>
                <input type="email" id="m_email" name="email" value="<?php echo gars_e($editUser['email'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="m_role">Base role <span class="req">*</span></label>
                <select id="m_role" name="role" required>
                    <?php foreach (gars_roles() as $value => $label): ?>
                        <option value="<?php echo gars_e($value); ?>"<?php echo $editUser['role'] === $value ? ' selected' : ''; ?>><?php echo gars_e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="m_new_password">New password <span class="muted" style="font-weight:400;">(leave blank to keep)</span></label>
                <input type="password" id="m_new_password" name="new_password" minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="m_confirm">Confirm new password</label>
                <input type="password" id="m_confirm" name="confirm" minlength="8" autocomplete="new-password">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:8px; margin-top:26px;">
                    <input type="checkbox" name="is_active" value="1" style="width:auto;"
                        <?php echo (int) $editUser['is_active'] === 1 ? 'checked' : ''; ?>>
                    Account is active (can sign in)
                </label>
            </div>
        </div>

        <h2 style="margin-top:22px;">Direct roles</h2>
        <p class="muted" style="margin-bottom:10px;">
            Granted to this account on top of the base role. Effective access = base role + these + roles from groups.
        </p>
        <div class="form-row">
            <?php $directIds = array_map('intval', array_column(gars_user_direct_roles((int) $editUser['id']), 'id')); ?>
            <?php foreach ($allRoles as $r): ?>
                <label style="display:flex; align-items:center; gap:8px; font-size:.94rem;">
                    <input type="checkbox" name="roles[]" value="<?php echo (int) $r['id']; ?>" style="width:auto;"
                        <?php echo in_array((int) $r['id'], $directIds, true) ? 'checked' : ''; ?>>
                    <?php echo gars_e($r['label']); ?> <span class="muted" style="font-size:.82rem;">(<?php echo gars_e($r['name']); ?>)</span>
                </label>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top:22px;">Group roles</h2>
        <p class="muted" style="margin-bottom:10px;">
            A group carries its own set of roles — assign the group once and every role inside it applies.
        </p>
        <div class="form-row">
            <?php $memberIds = array_map('intval', array_column(gars_user_groups((int) $editUser['id']), 'id')); ?>
            <?php foreach ($allGroups as $g): ?>
                <label style="display:flex; align-items:center; gap:8px; font-size:.94rem;">
                    <input type="checkbox" name="groups[]" value="<?php echo (int) $g['id']; ?>" style="width:auto;"
                        <?php echo in_array((int) $g['id'], $memberIds, true) ? 'checked' : ''; ?>>
                    <?php echo gars_e($g['name']); ?>
                    <span class="muted" style="font-size:.82rem;">
                        (<?php $gr = array_column(gars_group_roles((int) $g['id']), 'label');
                            echo $gr ? gars_e(implode(', ', $gr)) : 'no roles yet'; ?>)
                    </span>
                </label>
            <?php endforeach; ?>
            <?php if (!$allGroups): ?>
                <p class="muted">No groups yet — create one on the Roles &amp; groups tab.</p>
            <?php endif; ?>
        </div>

        <div class="actions">
            <button type="submit" class="btn">Save account</button>
            <a class="btn ghost" href="<?php echo gars_e($tabUrl('users')); ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="section">
    <h2>All accounts</h2>
    <div class="tablewrap">
    <table class="data">
        <thead><tr>
            <th>User</th><th>Full name</th><th>Base role</th><th>Direct roles</th><th>Group roles</th>
            <th>Status</th><th>Last login</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($userRows as $row): ?>
            <?php
            $direct = gars_user_direct_roles((int) $row['id']);
            $groups = gars_user_groups((int) $row['id']);
            ?>
            <tr>
                <td><strong><?php echo gars_e($row['username']); ?></strong><br><span class="muted" style="font-size:.82rem;"><?php echo gars_e($row['email'] ?? ''); ?></span></td>
                <td><?php echo gars_e($row['full_name']); ?></td>
                <td><span class="badge <?php echo gars_e($row['role']); ?>"><?php echo gars_e(gars_role_label($row['role'])); ?></span></td>
                <td>
                    <?php if ($direct): ?>
                        <?php foreach ($direct as $r): ?>
                            <span class="badge" style="margin:1px 3px 1px 0;"><?php echo gars_e($r['label']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($groups): ?>
                        <?php foreach ($groups as $g): ?>
                            <span class="badge on" style="margin:1px 3px 1px 0;"><?php echo gars_e($g['name']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?php echo $row['is_active'] ? 'on' : 'off'; ?>"><?php echo $row['is_active'] ? 'Active' : 'Disabled'; ?></span></td>
                <td class="n"><?php echo $row['last_login_at'] ? gars_e($row['last_login_at']) : 'never'; ?></td>
                <td><a class="a-link" href="<?php echo gars_e($tabUrl('users') . '&edit=' . (int) $row['id']); ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-top:10px; font-size:.88rem;">
        The legacy <a class="a-link" href="<?php echo gars_url('register'); ?>">Create user</a> page still works;
        this tab replaces it for day-to-day management.
    </p>
</div>

<?php elseif ($tab === 'roles'): ?>

<?php if ($editRole): ?>
<div class="section" style="border-top:none; padding-top:0;">
    <h2>Edit role: <?php echo gars_e($editRole['label']); ?></h2>
    <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="update_role">
        <input type="hidden" name="role_id" value="<?php echo (int) $editRole['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="r_name">Name (slug)</label>
                <input type="text" id="r_name" value="<?php echo gars_e($editRole['name']); ?>" disabled>
            </div>
            <div class="form-group">
                <label for="r_label">Label <span class="req">*</span></label>
                <input type="text" id="r_label" name="label" required value="<?php echo gars_e($editRole['label']); ?>">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
            <label for="r_desc">Description</label>
            <input type="text" id="r_desc" name="description" value="<?php echo gars_e($editRole['description'] ?? ''); ?>">
        </div>
        <div class="actions" style="margin-top:0;">
            <button class="btn" type="submit">Save role</button>
            <a class="btn ghost" href="<?php echo gars_e($tabUrl('roles')); ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="section" style="<?php echo $editRole ? '' : 'border-top:none; padding-top:0;'; ?>">
    <h2>Create role</h2>
    <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="create_role">
        <div class="form-row">
            <div class="form-group">
                <label for="c_name">Name (slug) <span class="req">*</span></label>
                <input type="text" id="c_name" name="name" required pattern="[a-z][a-z0-9_]{1,39}"
                       title="lowercase letters, numbers, underscores" placeholder="reviewer">
            </div>
            <div class="form-group">
                <label for="c_label">Label <span class="req">*</span></label>
                <input type="text" id="c_label" name="label" required placeholder="Reviewer">
            </div>
            <div class="form-group">
                <label for="c_desc">Description</label>
                <input type="text" id="c_desc" name="description" placeholder="What this role can do">
            </div>
        </div>
        <button class="btn" type="submit">Create role</button>
        <span class="muted" style="margin-left:12px; font-size:.88rem;">Custom roles can be granted directly or through a group.</span>
    </form>
</div>

<div class="section">
    <h2>Roles</h2>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th>Role</th><th>Slug</th><th>Description</th><th>Used by</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($allRoles as $r): ?>
            <?php $usage = gars_role_usage((int) $r['id']); ?>
            <tr>
                <td><strong><?php echo gars_e($r['label']); ?></strong>
                    <?php if ((int) $r['is_system'] === 1): ?>
                        <span class="badge admin" style="margin-left:6px;">Built-in</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?php echo gars_e($r['name']); ?></td>
                <td class="muted"><?php echo gars_e($r['description'] ?? ''); ?></td>
                <td class="n"><?php echo $usage['direct']; ?> direct · <?php echo $usage['via_groups']; ?> via groups</td>
                <td style="white-space:nowrap;">
                    <a class="a-link" href="<?php echo gars_e($tabUrl('roles') . '&edit_role=' . (int) $r['id']); ?>">Edit</a>
                    <?php if ((int) $r['is_system'] !== 1): ?>
                        <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>" style="display:inline; margin-left:8px;"
                              onsubmit="return confirm('Delete this role from every user and group that has it?');">
                            <?php echo gars_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_role">
                            <input type="hidden" name="role_id" value="<?php echo (int) $r['id']; ?>">
                            <button class="a-link" type="submit" style="border:none; background:none; color:var(--brick); cursor:pointer; padding:0; font:inherit;">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($editGroup): ?>
<div class="section">
    <h2>Manage group: <?php echo gars_e($editGroup['name']); ?></h2>
    <div class="form-row">
        <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>" style="display:contents;">
            <?php echo gars_csrf_field(); ?>
            <input type="hidden" name="action" value="update_group">
            <input type="hidden" name="group_id" value="<?php echo (int) $editGroup['id']; ?>">
            <div class="form-group">
                <label for="g_name">Group name <span class="req">*</span></label>
                <input type="text" id="g_name" name="name" required value="<?php echo gars_e($editGroup['name']); ?>">
            </div>
            <div class="form-group">
                <label for="g_desc">Description</label>
                <input type="text" id="g_desc" name="description" value="<?php echo gars_e($editGroup['description'] ?? ''); ?>">
            </div>
            <div class="form-group" style="align-self:end;">
                <button class="btn" type="submit">Save group</button>
            </div>
        </form>
    </div>

    <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>" style="margin-top:16px;">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="set_group_roles">
        <input type="hidden" name="group_id" value="<?php echo (int) $editGroup['id']; ?>">
        <p class="muted" style="margin-bottom:10px;">Roles this group carries — everyone in the group gets all of them.</p>
        <div class="form-row">
            <?php $groupRoleIds = array_map('intval', array_column(gars_group_roles((int) $editGroup['id']), 'id')); ?>
            <?php foreach ($allRoles as $r): ?>
                <label style="display:flex; align-items:center; gap:8px; font-size:.94rem;">
                    <input type="checkbox" name="roles[]" value="<?php echo (int) $r['id']; ?>" style="width:auto;"
                        <?php echo in_array((int) $r['id'], $groupRoleIds, true) ? 'checked' : ''; ?>>
                    <?php echo gars_e($r['label']); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="actions" style="margin-top:0;">
            <button class="btn" type="submit">Save group roles</button>
        </div>
    </form>

    <?php
    $members = $db->prepare('SELECT u.username FROM user_role_groups ug JOIN users u ON u.id = ug.user_id WHERE ug.group_id = ? ORDER BY u.username');
    $members->execute([(int) $editGroup['id']]);
    $memberNames = $members->fetchAll(PDO::FETCH_COLUMN);
    ?>
    <p class="muted" style="font-size:.9rem;">
        Members (<?php echo count($memberNames); ?>):<?php echo $memberNames ? ' ' . gars_e(implode(', ', $memberNames)) : ' none yet — assign the group from the Users tab.'; ?>
    </p>
    <div class="actions">
        <a class="btn ghost" href="<?php echo gars_e($tabUrl('roles')); ?>">Close</a>
        <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>" style="display:inline;"
              onsubmit="return confirm('Delete this group? Members keep their own roles.');">
            <?php echo gars_csrf_field(); ?>
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="group_id" value="<?php echo (int) $editGroup['id']; ?>">
            <button class="btn red" type="submit">Delete group</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="section">
    <h2>Group roles</h2>
    <p class="muted" style="margin-bottom:14px;">A group bundles roles so you can assign them to a user in one step.
        Assign groups from the <a class="a-link" href="<?php echo gars_e($tabUrl('users')); ?>">Users</a> tab.</p>

    <form method="post" action="<?php echo gars_e($tabUrl('roles')); ?>" style="margin-bottom:20px;">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="create_group">
        <div class="form-row">
            <div class="form-group">
                <label for="cg_name">Group name <span class="req">*</span></label>
                <input type="text" id="cg_name" name="name" required placeholder="Senior clinicians">
            </div>
            <div class="form-group">
                <label for="cg_desc">Description</label>
                <input type="text" id="cg_desc" name="description" placeholder="Optional">
            </div>
            <div class="form-group" style="align-self:end;">
                <button class="btn" type="submit">Create group</button>
            </div>
        </div>
    </form>

    <div class="tablewrap">
    <table class="data">
        <thead><tr><th>Group</th><th>Description</th><th>Roles carried</th><th class="n">Members</th><th></th></tr></thead>
        <tbody>
        <?php if (!$allGroups): ?>
            <tr><td colspan="5" class="muted">No groups yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($allGroups as $g): ?>
            <?php $gr = gars_group_roles((int) $g['id']); ?>
            <tr>
                <td><strong><?php echo gars_e($g['name']); ?></strong></td>
                <td class="muted"><?php echo gars_e($g['description'] ?? ''); ?></td>
                <td>
                    <?php if ($gr): ?>
                        <?php foreach ($gr as $r): ?>
                            <span class="badge" style="margin:1px 3px 1px 0;"><?php echo gars_e($r['label']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="muted">no roles yet</span>
                    <?php endif; ?>
                </td>
                <td class="n"><?php echo gars_group_members((int) $g['id']); ?></td>
                <td><a class="a-link" href="<?php echo gars_e($tabUrl('roles') . '&edit_group=' . (int) $g['id']); ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($tab === 'logs'): ?>

<div class="section" style="border-top:none; padding-top:0;">
    <h2>Filters</h2>
    <form method="get" action="<?php echo gars_url('itadmin'); ?>">
        <input type="hidden" name="tab" value="logs">
        <div class="form-row">
            <div class="form-group">
                <label for="f-u">User</label>
                <select id="f-u" name="u">
                    <option value="">All users</option>
                    <?php foreach ($allUsers as $un): ?>
                        <option value="<?php echo gars_e($un); ?>"<?php echo $un === $fUser ? ' selected' : ''; ?>><?php echo gars_e($un); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="f-action">Action</label>
                <select id="f-action" name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo gars_e($a); ?>"<?php echo $a === $fAction ? ' selected' : ''; ?>><?php echo gars_e($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="f-from">From</label>
                <input id="f-from" type="date" name="from" value="<?php echo gars_e($fFrom); ?>">
            </div>
            <div class="form-group">
                <label for="f-to">To</label>
                <input id="f-to" type="date" name="to" value="<?php echo gars_e($fTo); ?>">
            </div>
            <div class="form-group">
                <label for="f-q">Search text</label>
                <input id="f-q" type="text" name="q" maxlength="100" placeholder="name, details, IP…"
                       value="<?php echo gars_e($fQ); ?>">
            </div>
        </div>
        <div class="actions" style="margin-top:0;">
            <button class="btn" type="submit">Apply filters</button>
            <?php if ($fUser !== '' || $fAction !== '' || $fFrom !== '' || $fTo !== '' || $fQ !== ''): ?>
                <a class="btn ghost" href="<?php echo gars_e($tabUrl('logs')); ?>">Clear filters</a>
            <?php endif; ?>
            <a class="btn ghost" href="<?php echo gars_url('activity'); ?>">Classic activity page</a>
        </div>
    </form>
</div>

<div class="section">
    <h2>Entries</h2>
    <p class="muted" style="margin-bottom:10px;">
        Showing <?php echo $logFromN; ?>–<?php echo $logToN; ?> of <?php echo $logTotal; ?> entries
        (page <?php echo $logPage; ?> of <?php echo $logPages; ?>).
    </p>
    <?php if (!$logRows): ?>
        <p class="empty"><b>No entries match the current filters.</b> Clear some filters and try again.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="data">
        <thead><tr>
            <th>When</th><th>User</th><th>Role</th><th>Action</th><th>Page</th><th>Details</th><th>IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logRows as $row): ?>
            <tr>
                <td style="white-space:nowrap;"><?php echo gars_e($row['created_at']); ?></td>
                <td><strong><?php echo gars_e($row['username'] ?? 'guest'); ?></strong></td>
                <td><?php echo $row['role'] ? '<span class="badge ' . gars_e($row['role']) . '">' . gars_e(gars_role_label($row['role'])) . '</span>' : '-'; ?></td>
                <td><?php echo gars_e($row['action']); ?></td>
                <td><?php echo gars_e($row['page'] ?? '-'); ?></td>
                <td class="muted"><?php echo gars_e($row['details'] ?? ''); ?></td>
                <td class="muted"><?php echo gars_e($row['ip_address'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($logPages > 1): ?>
    <div class="actions">
        <?php if ($logPage > 1): ?>
            <a class="btn ghost" href="<?php echo gars_e($logQs(['p' => $logPage - 1])); ?>">‹ Previous</a>
        <?php endif; ?>
        <?php if ($logPage < $logPages): ?>
            <a class="btn" href="<?php echo gars_e($logQs(['p' => $logPage + 1])); ?>">Next ›</a>
        <?php endif; ?>
        <span class="muted" style="align-self:center;">Page <?php echo $logPage; ?> of <?php echo $logPages; ?></span>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'settings'): ?>

<div class="section" style="border-top:none; padding-top:0;">
    <h2>Login lockout</h2>
    <p class="muted" style="margin-bottom:14px;">
        Brute-force protection: after this many failed sign-ins the account (or everyone sharing the
        network address) is locked for the chosen number of minutes. Saved values override the
        <code>.envmb-index</code> defaults (<code>LOGIN_USER_LIMIT</code>, <code>LOGIN_IP_LIMIT</code>,
        <code>LOGIN_LOCK_MINUTES</code>). Counting window: 15 minutes.
        <?php if (array_filter(array_keys(GARS_LOCK_LIMITS), fn ($k) => gars_login_setting_info($k)['source'] === 'database')): ?>
            <strong>Custom values are currently saved.</strong>
        <?php endif; ?>
    </p>
    <form method="post" action="<?php echo gars_e($tabUrl('settings')); ?>">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="save_lockout">
        <div class="form-row">
            <div class="form-group">
                <label for="login_user_limit">Failed sign-ins per account</label>
                <input type="number" id="login_user_limit" name="login_user_limit" value="<?php echo (int) gars_login_user_limit(); ?>" min="1" max="100" required>
            </div>
            <div class="form-group">
                <label for="login_ip_limit">Failed sign-ins per IP address</label>
                <input type="number" id="login_ip_limit" name="login_ip_limit" value="<?php echo (int) gars_login_ip_limit(); ?>" min="1" max="500" required>
            </div>
            <div class="form-group">
                <label for="login_lock_minutes">Lock duration (minutes)</label>
                <input type="number" id="login_lock_minutes" name="login_lock_minutes" value="<?php echo (int) (gars_login_lock_seconds() / 60); ?>" min="1" max="1440" required>
            </div>
        </div>
        <button class="btn" type="submit">Save limits</button>
    </form>
    <form method="post" action="<?php echo gars_e($tabUrl('settings')); ?>" style="margin-top:10px;"
          onsubmit="return confirm('Reset the lockout limits to the defaults?');">
        <?php echo gars_csrf_field(); ?>
        <input type="hidden" name="action" value="reset_lockout">
        <button class="btn ghost" type="submit">Reset to defaults</button>
    </form>
</div>

<div class="section">
    <h2>Session policy</h2>
    <p class="muted">
        Signed-in users are logged out server-side after
        <strong><?php echo (int) (SESSION_TIMEOUT / 60); ?> minutes</strong> without a request
        (<code>SESSION_TIMEOUT=<?php echo (int) SESSION_TIMEOUT; ?></code> seconds, <code>0</code> = never).
        Cookies are HttpOnly + SameSite=Lax, HTTPS is forced outside local development, and every
        password is stored as bcrypt cost 12.
    </p>
</div>

<?php endif; ?>
<?php gars_footer(); ?>
