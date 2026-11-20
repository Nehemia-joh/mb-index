<?php
/**
 * activity.php — the audit trail with filters: trace exactly what a user did.
 * Filters: user, action, page, date range (from/to) and free text — paginated.
 * Routes: /activity (alias /logs)   Roles: admin, it_admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
define('GARS_ROUTE', 'activity');

$user = gars_require_role(['admin', 'it_admin']);
gars_track_page('page_visit', 'Activity log');

$db = gars_db();

/** Strict Y-m-d check — anything else is dropped. */
function gars_filter_date(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d !== false && $d->format('Y-m-d') === $v) ? $v : '';
}

// ---- option sources (filters outside these lists are ignored)
$allUsers = $db->query('SELECT username FROM users ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$pageList = $db->query(
    'SELECT DISTINCT page FROM activity_logs WHERE page IS NOT NULL AND page <> "" ORDER BY page'
)->fetchAll(PDO::FETCH_COLUMN);

// ---- read + whitelist the filters
$fUser = trim((string) ($_GET['u'] ?? ''));
if (!in_array($fUser, $allUsers, true)) {
    $fUser = '';
}
$fAction = trim((string) ($_GET['action'] ?? ''));
if (!in_array($fAction, $actions, true)) {
    $fAction = '';
}
$fPage = trim((string) ($_GET['page'] ?? ''));
if (!in_array($fPage, $pageList, true)) {
    $fPage = '';
}
$fFrom = gars_filter_date((string) ($_GET['from'] ?? ''));
$fTo = gars_filter_date((string) ($_GET['to'] ?? ''));
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
if ($fPage !== '') {
    $where[] = 'page = ?';
    $args[] = $fPage;
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
$total = (int) $cnt->fetchColumn();

$perPage = 100;
$pageCount = max(1, (int) ceil($total / $perPage));
$p = (int) ($_GET['p'] ?? 1);
if ($p < 1) {
    $p = 1;
}
if ($p > $pageCount) {
    $p = $pageCount;
}
$offset = ($p - 1) * $perPage;

$rows = $db->prepare(
    'SELECT * FROM activity_logs' . $sqlWhere
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$rows->execute($args);
$rows = $rows->fetchAll();

$hasFilters = ($fUser !== '' || $fAction !== '' || $fPage !== '' || $fFrom !== '' || $fTo !== '' || $fQ !== '');
$fromN = $total > 0 ? $offset + 1 : 0;
$toN = min($offset + $perPage, $total);

/** Rebuild the filter URL with extras (e.g. the page number). */
$qs = function (array $extra = []) use ($fUser, $fAction, $fPage, $fFrom, $fTo, $fQ): string {
    return gars_url('activity') . '?' . http_build_query(array_merge(
        ['u' => $fUser, 'action' => $fAction, 'page' => $fPage, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ],
        $extra
    ));
};

gars_header('Activity log', 'activity');
?>
<div class="page-head">
    <h1>Activity log</h1>
    <p class="sub">Every move in the system — page visits, logins, records, scoring-table changes —
        with the source IP. Filter it to trace exactly what one user did and when.</p>
</div>

<div class="section" style="border-top:none; padding-top:0;">
    <h2>Filters</h2>
    <form method="get" action="<?php echo gars_url('activity'); ?>">
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
                <label for="f-page">Page</label>
                <select id="f-page" name="page">
                    <option value="">All pages</option>
                    <?php foreach ($pageList as $pg): ?>
                        <option value="<?php echo gars_e($pg); ?>"<?php echo $pg === $fPage ? ' selected' : ''; ?>><?php echo gars_e($pg); ?></option>
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
            <?php if ($hasFilters): ?>
                <a class="btn ghost" href="<?php echo gars_url('activity'); ?>">Clear filters</a>
            <?php endif; ?>
            <?php if ($fUser !== ''): ?>
                <a class="btn ghost" href="<?php echo gars_url('sessions') . '?u=' . urlencode($fUser); ?>">This user's sessions</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="section">
    <h2>Entries</h2>
    <p class="muted" style="margin-bottom:10px;">
        Showing <?php echo $fromN; ?>–<?php echo $toN; ?> of <?php echo $total; ?> entries
        (page <?php echo $p; ?> of <?php echo $pageCount; ?>).
    </p>
    <?php if (!$rows): ?>
        <p class="empty"><b>No entries match the current filters.</b> Clear some filters and try again.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="data">
        <thead><tr>
            <th>When</th><th>User</th><th>Role</th><th>Action</th><th>Page</th><th>Details</th><th>IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
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

    <?php if ($pageCount > 1): ?>
    <div class="actions">
        <?php if ($p > 1): ?>
            <a class="btn ghost" href="<?php echo gars_e($qs(['p' => $p - 1])); ?>">‹ Previous</a>
        <?php endif; ?>
        <?php if ($p < $pageCount): ?>
            <a class="btn" href="<?php echo gars_e($qs(['p' => $p + 1])); ?>">Next ›</a>
        <?php endif; ?>
        <span class="muted" style="align-self:center;">Page <?php echo $p; ?> of <?php echo $pageCount; ?></span>
    </div>
    <?php endif; ?>
</div>
<?php gars_footer(); ?>
