<?php
/**
 * sessions.php — login sessions grouped by user: who is in the system, since
 * when, what they are doing right now — with per-user and per-status filters.
 * Routes: /sessions   Roles: admin, it_admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'sessions');

$user = gars_require_role(['admin', 'it_admin']);
gars_track_page('page_visit', 'Sessions by user');

$db = gars_db();

// Same auto-close rule as the dashboards: idle sessions become "Closed".
$expired = SESSION_TIMEOUT > 0 ? date('Y-m-d H:i:s', time() - SESSION_TIMEOUT) : null;
if ($expired) {
    $db->prepare(
        'UPDATE user_sessions SET logout_at = COALESCE(logout_at, ?)
          WHERE logout_at IS NULL AND last_activity_at < ?'
    )->execute([$expired, $expired]);
}

$allUsers = $db->query('SELECT username FROM users ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);

// ---- filters (whitelisted, bound as parameters)
$fUser = trim((string) ($_GET['u'] ?? ''));
if ($fUser !== '' && !in_array($fUser, $allUsers, true)) {
    $fUser = '';
}
$fStatus = (string) ($_GET['status'] ?? '');
if (!in_array($fStatus, ['active', 'closed'], true)) {
    $fStatus = '';
}

// ---- summary: one row per user (respecting the user filter)
$sumSql = 'SELECT u.username, u.role,
                  COALESCE(SUM(s.logout_at IS NULL), 0) AS active_count,
                  COUNT(s.id) AS total_count,
                  MAX(s.last_activity_at) AS last_seen,
                  MAX(CASE WHEN s.logout_at IS NULL THEN s.current_page END) AS on_page
             FROM users u
             LEFT JOIN user_sessions s ON s.user_id = u.id';
$sumArgs = [];
if ($fUser !== '') {
    $sumSql .= ' WHERE u.username = ?';
    $sumArgs[] = $fUser;
}
$sumSql .= ' GROUP BY u.id, u.username, u.role
             ORDER BY active_count DESC, last_seen DESC, u.username';
$sumStmt = $db->prepare($sumSql);
$sumStmt->execute($sumArgs);
$summary = $sumStmt->fetchAll();

// ---- individual sessions (respecting user + status filter)
$sSql = 'SELECT s.*, u.username, u.role
           FROM user_sessions s
           LEFT JOIN users u ON u.id = s.user_id';
$where = [];
$sArgs = [];
if ($fUser !== '') {
    $where[] = 'u.username = ?';
    $sArgs[] = $fUser;
}
if ($fStatus === 'active') {
    $where[] = 's.logout_at IS NULL';
}
if ($fStatus === 'closed') {
    $where[] = 's.logout_at IS NOT NULL';
}
if ($where) {
    $sSql .= ' WHERE ' . implode(' AND ', $where);
}
$sSql .= ' ORDER BY s.last_activity_at DESC, s.id DESC LIMIT 200';
$sStmt = $db->prepare($sSql);
$sStmt->execute($sArgs);
$sessions = $sStmt->fetchAll();

$stats = [
    'accounts' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'   => (int) $db->query('SELECT COUNT(*) FROM user_sessions WHERE logout_at IS NULL')->fetchColumn(),
    'all'      => (int) $db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(),
];

gars_header('Sessions', 'sessions');
?>
<div class="page-head">
    <h1>Sessions by user</h1>
    <p class="sub">Every login, grouped by account: what is open right now, what has closed,
        and a shortcut to trace each user's full activity.</p>
</div>

<div class="stats">
    <div class="stat"><span class="s-n"><?php echo $stats['accounts']; ?></span><span class="s-l">User accounts</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['active']; ?></span><span class="s-l">Active sessions now</span></div>
    <div class="stat"><span class="s-n"><?php echo $stats['all']; ?></span><span class="s-l">Total sessions ever</span></div>
</div>

<div class="section">
    <h2>Filter</h2>
    <form method="get" action="<?php echo gars_url('sessions'); ?>">
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
                <label for="f-status">Status</label>
                <select id="f-status" name="status">
                    <option value="">All sessions</option>
                    <option value="active"<?php echo $fStatus === 'active' ? ' selected' : ''; ?>>Active now</option>
                    <option value="closed"<?php echo $fStatus === 'closed' ? ' selected' : ''; ?>>Closed</option>
                </select>
            </div>
        </div>
        <div class="actions" style="margin-top:0;">
            <button class="btn" type="submit">Apply filters</button>
            <?php if ($fUser !== '' || $fStatus !== ''): ?>
                <a class="btn ghost" href="<?php echo gars_url('sessions'); ?>">Clear filters</a>
            <?php endif; ?>
            <?php if ($fUser !== ''): ?>
                <a class="btn ghost" href="<?php echo gars_url('activity') . '?u=' . urlencode($fUser); ?>">Trace this user's activity</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="section">
    <h2>Sessions by user</h2>
    <p class="muted" style="margin-bottom:12px;">Accounts shown: <?php echo count($summary); ?>.
        One row per account — click “Trace” to open the full activity log for that user.</p>
    <div class="tablewrap">
    <table class="data">
        <thead><tr>
            <th>User</th><th>Role</th><th class="n">Active now</th><th class="n">Sessions</th>
            <th>Last seen</th><th>Currently on</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($summary as $row): ?>
            <tr>
                <td><strong><?php echo gars_e($row['username']); ?></strong></td>
                <td><span class="badge <?php echo gars_e($row['role']); ?>"><?php echo gars_e(gars_role_label($row['role'])); ?></span></td>
                <td class="n"><?php echo (int) $row['active_count']; ?><?php echo (int) $row['active_count'] > 0 ? ' <span class="badge on">live</span>' : ''; ?></td>
                <td class="n"><?php echo (int) $row['total_count']; ?></td>
                <td><?php echo gars_e($row['last_seen'] ?? '—'); ?></td>
                <td><?php echo gars_e($row['on_page'] ?? '—'); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <?php if ((int) $row['total_count'] > 0): ?>
                        <a class="a-link" href="<?php echo gars_url('sessions') . '?u=' . urlencode($row['username']); ?>">Sessions</a>
                        &nbsp;·&nbsp;
                    <?php endif; ?>
                    <a class="a-link" href="<?php echo gars_url('activity') . '?u=' . urlencode($row['username']); ?>">Trace</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="section">
    <h2>Individual sessions</h2>
    <p class="muted" style="margin-bottom:10px;">Sessions found: <?php echo count($sessions); ?><?php echo count($sessions) === 200 ? ' (latest 200)' : ''; ?>.
        Sessions idle for over <?php echo (int) (SESSION_TIMEOUT / 60); ?> minutes are closed automatically.</p>
    <?php if (!$sessions): ?>
        <p class="empty"><b>No sessions match the current filters.</b> Clear the filters to see everything.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="data">
        <thead><tr>
            <th>User</th><th>Role</th><th>Logged in</th><th>Last activity</th>
            <th class="n">Views</th><th>Current page</th><th>IP</th><th>Device</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><strong><?php echo gars_e($s['username'] ?? ('user #' . $s['user_id'])); ?></strong></td>
                <td><?php echo $s['role'] ? '<span class="badge ' . gars_e($s['role']) . '">' . gars_e(gars_role_label($s['role'])) . '</span>' : '-'; ?></td>
                <td><?php echo gars_e($s['login_at']); ?></td>
                <td><?php echo gars_e($s['last_activity_at']); ?></td>
                <td class="n"><?php echo (int) $s['page_views']; ?></td>
                <td><?php echo gars_e($s['current_page'] ?? '—'); ?></td>
                <td class="muted"><?php echo gars_e($s['ip_address'] ?? '—'); ?></td>
                <td class="muted"><?php echo gars_e(mb_substr((string) ($s['user_agent'] ?? ''), 0, 60)); ?></td>
                <td><span class="badge <?php echo $s['logout_at'] ? 'off' : 'on'; ?>"><?php echo $s['logout_at'] ? 'Closed' : 'Active'; ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php gars_footer(); ?>
