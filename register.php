<?php
/**
 * register.php — create user accounts (therapist / admin / IT system administrator).
 * Route: /register — only admins and IT admins may create accounts.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'register');

gars_require_role(['admin', 'it_admin']);

$error = null;
$labels = [
    'new_username' => 'Username',
    'new_password' => 'Password',
    'confirm'      => 'Confirm password',
    'full_name'    => 'Full name',
    'role'         => 'Role',
];
$posted = [];
$missing = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = [
        'new_username' => trim($_POST['new_username'] ?? ''),
        'new_password' => $_POST['new_password'] ?? '',
        'confirm'      => $_POST['confirm'] ?? '',
        'full_name'    => trim($_POST['full_name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'role'         => trim($_POST['role'] ?? ''),
    ];
    $missing = gars_validate_required(
        array_intersect_key($posted, ['new_username' => 1, 'new_password' => 1, 'confirm' => 1, 'full_name' => 1, 'role' => 1]),
        $labels
    );

    if ($missing) {
        $error = 'Data cannot be saved — required fields missing: ' . implode(', ', $missing) . '.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $posted['new_username'])) {
        $error = 'Username must be 3–60 characters (letters, numbers, dots, dashes).';
    } elseif (strlen($posted['new_password']) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($posted['new_password'] !== $posted['confirm']) {
        $error = 'Password and confirm password do not match.';
    } elseif (!in_array($posted['role'], array_keys(gars_roles()), true)) {
        $error = 'Please choose a valid role.';
    } else {
        try {
            $id = gars_create_user(
                $posted['new_username'],
                $posted['new_password'],
                $posted['role'],
                $posted['full_name'],
                $posted['email'],
                true
            );
            gars_log_activity(
                'user_create',
                'register',
                'Created ' . $posted['role'] . " \"{$posted['new_username']}\" (user #$id)"
            );
            gars_flash('User "' . $posted['new_username'] . '" created as ' . gars_role_label($posted['role']) . '.');
            gars_track_page('page_visit', 'User management');
            gars_redirect(gars_url('register'));
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
gars_track_page('page_visit', 'User management');

gars_header('Create user', 'register');
?>
<div class="section">
    <h2>Create user account</h2>
    <?php if ($error): ?><div class="notice notice-error"><?php echo gars_e($error); ?></div><?php endif; ?>
    <form method="post" action="<?php echo gars_url('register'); ?>" autocomplete="off">
        <div class="form-row">
            <div class="form-group">
                <label for="new_username">Username <span class="req">*</span></label>
                <input type="text" id="new_username" name="new_username" required
                       value="<?php echo gars_e($posted['new_username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="full_name">Full name <span class="req">*</span></label>
                <input type="text" id="full_name" name="full_name" required
                       value="<?php echo gars_e($posted['full_name'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="email">Email (optional)</label>
                <input type="email" id="email" name="email" value="<?php echo gars_e($posted['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="role">Role <span class="req">*</span></label>
                <select id="role" name="role" required>
                    <option value="">— Select role —</option>
                    <?php foreach (gars_roles() as $value => $label): ?>
                        <option value="<?php echo gars_e($value); ?>" <?php echo ($posted['role'] ?? '') === $value ? 'selected' : ''; ?>>
                            <?php echo gars_e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="new_password">Password <span class="req">*</span> <span class="muted">(min 8 characters)</span></label>
                <input type="password" id="new_password" name="new_password" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="confirm">Confirm password <span class="req">*</span></label>
                <input type="password" id="confirm" name="confirm" minlength="8" required>
            </div>
        </div>
        <button type="submit" class="btn green">Create user</button>
    </form>
</div>

<div class="section">
    <h2>Existing accounts</h2>
    <table class="data">
        <thead><tr><th>User</th><th>Full name</th><th>Role</th><th>Status</th><th>Last login</th></tr></thead>
        <tbody>
        <?php foreach (gars_db()->query('SELECT username, full_name, role, is_active, last_login_at FROM users ORDER BY role, username') as $row): ?>
            <tr>
                <td><?php echo gars_e($row['username']); ?></td>
                <td><?php echo gars_e($row['full_name']); ?></td>
                <td><span class="badge <?php echo gars_e($row['role']); ?>"><?php echo gars_e(gars_role_label($row['role'])); ?></span></td>
                <td><span class="badge <?php echo $row['is_active'] ? 'on' : 'off'; ?>"><?php echo $row['is_active'] ? 'Active' : 'Disabled'; ?></span></td>
                <td><?php echo $row['last_login_at'] ? gars_e($row['last_login_at']) : 'never'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php gars_footer(); ?>
