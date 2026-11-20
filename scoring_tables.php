<?php
/**
 * scoring_tables.php — edit Table A, Table B and the interpretation bands.
 * Route: /scoring-tables
 * Roles: admin, it_admin
 *
 * A change never applies immediately. It is stored as a PENDING revision and
 * takes effect globally only after ANOTHER admin / IT admin approves it —
 * an editor can never approve their own change. The newest approved revision
 * of each table is what every scoring run uses (see includes/scoring.php).
 *
 * Versions: the built-in published table is v1.0; every approved revision
 * takes the next number in approval order (v1.1, v1.2, …) and any past
 * version can be brought back with Restore (as a new pending revision that
 * again needs a second admin's approval).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/scoring.php';
define('GARS_ROUTE', 'scoring-tables');

$user = gars_require_role(['admin', 'it_admin']);
gars_track_page('page_visit', 'Scoring tables');

$db = gars_db();

$kindLabels = [
    'table_a'        => 'Table A — raw subscale score → scaled score & percentile rank',
    'table_b'        => 'Table B — sum of scaled scores → percentile rank & Autism Index',
    'interpretation' => 'Interpretation bands — Autism Index → probability & severity',
];
$kindShort = ['table_a' => 'Table A', 'table_b' => 'Table B', 'interpretation' => 'Interpretation'];

/* ------------------------------------------------------------- POST actions */

$formErrors = [];
$editorPrefill = null; // kind => payload, kept when validation fails (no redirect)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gars_csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'submit') {
        $kind = (string) ($_POST['kind'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        $payloadJson = (string) ($_POST['payload'] ?? '');
        $raw = json_decode($payloadJson, true);
        if (!array_key_exists($kind, $kindLabels)) {
            gars_flash('Unknown table.', 'error');
            gars_redirect(gars_url('scoring-tables'));
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            $formErrors[] = 'The table could not be read: ' . json_last_error_msg() . '.';
            $editorPrefill = [$kind => []];
        } else {
            [$errors, $normalized] = gars_prepare_scoring_payload($kind, $raw);
            if ($errors) {
                $formErrors = $errors;
                $editorPrefill = [$kind => is_array($raw) ? $raw : []];
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO scoring_revisions (kind, payload, change_note, status, created_by)
                     VALUES (?, ?, ?, "pending", ?)'
                );
                $stmt->execute([$kind, json_encode($normalized), $note !== '' ? $note : null, $user['id']]);
                $revId = (int) $db->lastInsertId();
                gars_log_activity(
                    'scoring_submit',
                    'scoring-tables',
                    "Proposed change to {$kindShort[$kind]} as revision #$revId"
                    . ($note !== '' ? ' — ' . $note : '')
                );
                gars_flash(
                    "Change to {$kindShort[$kind]} saved as revision #$revId. "
                    . 'It applies everywhere only after another admin approves it.',
                    'success'
                );
                gars_redirect(gars_url('scoring-tables'));
            }
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        $revId = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM scoring_revisions WHERE id = ?');
        $stmt->execute([$revId]);
        $rev = $stmt->fetch();
        $label = $kindShort[$rev['kind'] ?? ''] ?? 'table';

        if (!$rev || $rev['status'] !== 'pending') {
            gars_flash('That revision is not pending any more.', 'error');
        } elseif ((int) $rev['created_by'] === (int) $user['id']) {
            // four-eyes principle: the editor may never decide their own change
            gars_flash("This is your own change to $label — another admin must approve or reject it.", 'error');
        } elseif ($action === 'approve') {
            try {
                $db->beginTransaction();
                // supersede every other live/pending revision of the same table
                $db->prepare(
                    "UPDATE scoring_revisions SET status = 'superseded'
                     WHERE kind = ? AND status IN ('pending','approved') AND id <> ?"
                )->execute([$rev['kind'], $revId]);
                $db->prepare(
                    "UPDATE scoring_revisions SET status = 'approved', decided_by = ?, decided_at = NOW()
                     WHERE id = ?"
                )->execute([$user['id'], $revId]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                gars_flash('Approval failed: ' . $e->getMessage(), 'error');
                gars_redirect(gars_url('scoring-tables'));
            }
            gars_reset_scoring_cache();
            gars_log_activity(
                'scoring_approve',
                'scoring-tables',
                "Approved revision #$revId ($label) — now active for every scoring run"
            );
            gars_flash("Revision #$revId ($label) approved — it is now used by every scoring run.", 'success');
        } else { // reject
            $db->prepare(
                "UPDATE scoring_revisions SET status = 'rejected', decided_by = ?, decided_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            )->execute([$user['id'], $revId]);
            gars_log_activity('scoring_reject', 'scoring-tables', "Rejected revision #$revId ($label)");
            gars_flash("Revision #$revId ($label) rejected.", 'success');
        }
        gars_redirect(gars_url('scoring-tables'));
    } elseif ($action === 'restore') {
        $revId = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM scoring_revisions WHERE id = ?');
        $stmt->execute([$revId]);
        $rev = $stmt->fetch();
        if (!$rev) {
            gars_flash('Revision not found.', 'error');
        } elseif ($rev['status'] === 'pending') {
            gars_flash('That change is already waiting for approval — approve or reject it instead.', 'error');
        } else {
            $note = 'Restored from #' . $revId;
            if ($rev['change_note'] !== null && $rev['change_note'] !== '') {
                $note .= ': ' . $rev['change_note'];
            }
            $db->prepare(
                'INSERT INTO scoring_revisions (kind, payload, change_note, status, created_by)
                 VALUES (?, ?, ?, "pending", ?)'
            )->execute([$rev['kind'], $rev['payload'], $note, $user['id']]);
            $newId = (int) $db->lastInsertId();
            $label = $kindShort[$rev['kind']] ?? $rev['kind'];
            gars_log_activity('scoring_restore', 'scoring-tables', "Restored revision #$revId ($label) as pending revision #$newId");
            gars_flash("Revision #$newId created from #$revId — it applies only after another admin approves it.", 'success');
        }
        gars_redirect(gars_url('scoring-tables'));
    }

    // validation-failure fall-through: render the page with $formErrors below
}

/* ---------------------------------------------------------------- GET data */

// Version numbering: built-in published table = v1.0; every approved revision
// gets the next number in approval order per table (v1.1, v1.2, …).
$activeInfo = [];
foreach (array_keys($kindLabels) as $kind) {
    $stmt = $db->prepare(
        "SELECT r.*, u.username,
           (SELECT COUNT(*) FROM scoring_revisions r2
             WHERE r2.kind = r.kind AND r2.status IN ('approved','superseded') AND r2.id <= r.id) AS approval_seq
         FROM scoring_revisions r
         LEFT JOIN users u ON u.id = r.decided_by
         WHERE r.kind = ? AND r.status = 'approved' ORDER BY r.id DESC LIMIT 1"
    );
    $stmt->execute([$kind]);
    $rev = $stmt->fetch();
    $activeInfo[$kind] = $rev ?: null;
}

$pending = $db->query(
    "SELECT r.*, u.username AS author,
       (SELECT COUNT(*) FROM scoring_revisions r2
         WHERE r2.kind = r.kind AND r2.status IN ('approved','superseded')) AS approved_count
     FROM scoring_revisions r
     LEFT JOIN users u ON u.id = r.created_by
     WHERE r.status = 'pending' ORDER BY r.id ASC"
)->fetchAll();

$recent = $db->query(
    "SELECT r.*, ua.username AS author, ud.username AS decider,
       (SELECT COUNT(*) FROM scoring_revisions r2
         WHERE r2.kind = r.kind AND r2.status IN ('approved','superseded') AND r2.id <= r.id) AS approval_seq
     FROM scoring_revisions r
     LEFT JOIN users ua ON ua.id = r.created_by
     LEFT JOIN users ud ON ud.id = r.decided_by
     ORDER BY r.id DESC LIMIT 20"
)->fetchAll();

/** Version label of a decided revision: v1.1, v1.2, … (pending/rejected = —). */
function gars_version_of(array $rev): string
{
    return in_array($rev['status'], ['approved', 'superseded'], true)
        ? 'v1.' . (int) ($rev['approval_seq'] ?? 0)
        : '—';
}

/** Rows used to prefill an editor: the submitted payload, else the active table. */
function gars_editor_rows(string $kind, ?array $prefill): array
{
    if ($prefill !== null && array_key_exists($kind, $prefill)) {
        $rows = $prefill[$kind];
        return is_array($rows) ? array_values($rows) : [];
    }
    $active = gars_active_revision($kind);
    if ($active !== null) {
        return array_values($active);
    }
    return match ($kind) {
        'table_a'        => GARS_TABLE_A,
        'table_b'        => GARS_TABLE_B,
        'interpretation' => gars_default_interpretation_bands(),
        default          => [],
    };
}

function gars_range_cell(mixed $v): string
{
    if (!is_array($v) || count($v) !== 2) {
        return '';
    }
    return (string) $v[0] . '-' . (string) $v[1];
}

function gars_editor_cell(mixed $v): string
{
    return gars_e((string) ($v ?? ''));
}

gars_header('Scoring tables', 'scoring-tables');
?>
<div class="page-head">
    <h1>Scoring tables</h1>
    <p class="sub">Table A, Table B and the interpretation bands drive every scoring run.
        Propose a change here; it goes live for everyone only after <strong>another</strong> admin approves it.</p>
</div>

<?php if ($formErrors): ?>
<div class="notice notice-error">
    <strong>The change was NOT saved — fix these first:</strong>
    <ul style="margin:8px 0 0 18px;">
        <?php foreach (array_slice($formErrors, 0, 12) as $err): ?>
            <li><?php echo gars_e($err); ?></li>
        <?php endforeach; ?>
        <?php if (count($formErrors) > 12): ?>
            <li>… and <?php echo count($formErrors) - 12; ?> more.</li>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<!-- what is live right now -->
<div class="section" style="border-top:none; padding-top:0;">
    <h2>Currently active</h2>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th>Table</th><th>Source</th><th>Rows</th><th>Approved by</th><th>Approved at</th></tr></thead>
        <tbody>
        <?php foreach ($kindLabels as $kind => $label): $rev = $activeInfo[$kind]; ?>
            <tr>
                <td><strong><?php echo gars_e($label); ?></strong></td>
                <td><?php echo $rev
                    ? 'Revision #' . (int) $rev['id'] . ' <span class="badge on">v1.' . (int) $rev['approval_seq'] . '</span>'
                    : '<span class="badge warn">Built-in published table</span> <span class="badge on">v1.0</span>'; ?></td>
                <td class="n"><?php echo count(gars_active_revision($kind) ?? match ($kind) {
                    'table_a' => GARS_TABLE_A, 'table_b' => GARS_TABLE_B,
                    'interpretation' => gars_default_interpretation_bands(), default => [],
                }); ?></td>
                <td><?php echo $rev ? gars_e($rev['username'] ?? ('user #' . $rev['decided_by'])) : '—'; ?></td>
                <td><?php echo $rev ? gars_e($rev['decided_at']) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- pending approvals -->
<div class="section">
    <h2>Waiting for approval</h2>
    <?php if (!$pending): ?>
        <p class="empty"><b>Nothing pending.</b> Propose a change below and a second admin will see it here.</p>
    <?php else: ?>
    <p class="muted" style="margin-bottom:12px;">You can only decide a change made by someone else — your own proposals stay pending until another admin approves or rejects them.</p>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th class="n">#</th><th>Table</th><th>Proposed by</th><th>When</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $rev):
            $own = (int) $rev['created_by'] === (int) $user['id'];
            $rowCount = count(json_decode((string) $rev['payload'], true) ?: []);
        ?>
            <tr>
                <td class="n">#<?php echo (int) $rev['id']; ?></td>
                <td><strong><?php echo gars_e($kindShort[$rev['kind']] ?? $rev['kind']); ?></strong>
                    <small class="muted"> <?php echo $rowCount; ?> row<?php echo $rowCount === 1 ? '' : 's'; ?>
                    — would become <b>v1.<?php echo 1 + (int) $rev['approved_count']; ?></b></small></td>
                <td><?php echo gars_e($rev['author'] ?? ('user #' . $rev['created_by'])); ?>
                    <?php if ($own): ?><span class="badge warn">you</span><?php endif; ?></td>
                <td><?php echo gars_e($rev['created_at']); ?></td>
                <td class="muted"><?php echo gars_e($rev['change_note'] ?? '—'); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <form method="post" style="display:inline;">
                        <?php echo gars_csrf_field(); ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?php echo (int) $rev['id']; ?>">
                        <button class="btn" type="submit" <?php echo $own ? 'disabled title="Your own change — another admin must approve it"' : ''; ?>>Approve</button>
                    </form>
                    <form method="post" style="display:inline; margin-left:6px;">
                        <?php echo gars_csrf_field(); ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" value="<?php echo (int) $rev['id']; ?>">
                        <button class="btn red" type="submit" <?php echo $own ? 'disabled title="Your own change — another admin must reject it"' : ''; ?>>Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- editors -->
<div class="section">
    <h2>Propose a change</h2>
    <p class="muted" style="margin-bottom:16px;">Ranges are written <code>low–high</code> (e.g. <code>0-3</code>); leave a range empty for “no score maps here”. Changes are validated before they are saved.</p>

    <?php foreach ($kindLabels as $kind => $label): ?>
    <details class="scoring-details" <?php echo ($editorPrefill && isset($editorPrefill[$kind])) || (!$editorPrefill && $kind === 'table_a') ? 'open' : ''; ?>>
        <summary><strong><?php echo gars_e($label); ?></strong></summary>
        <form method="post" class="scoring-form" data-kind="<?php echo gars_e($kind); ?>" style="margin-top:14px;">
            <?php echo gars_csrf_field(); ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="kind" value="<?php echo gars_e($kind); ?>">
            <input type="hidden" name="payload" value="">

            <?php if ($kind === 'table_a'): ?>
            <div class="tablewrap">
            <table class="data grid" id="grid-table_a">
                <thead><tr>
                    <th class="n">Scaled</th><th>Percentile</th>
                    <th>RB range</th><th>SI range</th><th>SC range</th><th>ER range</th><th>CS range</th><th>MS range</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach (gars_editor_rows('table_a', $editorPrefill) as $row): ?>
                    <?php $row = array_values((array) $row); ?>
                    <tr>
                        <td><input class="grid-in n" data-f="scaled" type="number" min="1" max="30" value="<?php echo gars_editor_cell($row[0] ?? ''); ?>"></td>
                        <td><input class="grid-in" data-f="pct" value="<?php echo gars_editor_cell($row[1] ?? ''); ?>"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[2] ?? null)); ?>" placeholder="0-3"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[3] ?? null)); ?>" placeholder="0-0"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[4] ?? null)); ?>" placeholder="0-1"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[5] ?? null)); ?>" placeholder="0-1"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[6] ?? null)); ?>" placeholder="0-3"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[7] ?? null)); ?>" placeholder="0-3"></td>
                        <td><button type="button" class="btn ghost rm" title="Remove row">×</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php elseif ($kind === 'table_b'): ?>
            <div class="tablewrap">
            <table class="data grid" id="grid-table_b">
                <thead><tr>
                    <th>Percentile</th><th>4-score range</th><th>6-score range</th><th class="n">Autism Index</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach (gars_editor_rows('table_b', $editorPrefill) as $row): ?>
                    <?php $row = array_values((array) $row); ?>
                    <tr>
                        <td><input class="grid-in" data-f="pct" value="<?php echo gars_editor_cell($row[0] ?? ''); ?>"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[1] ?? null)); ?>" placeholder="40-40"></td>
                        <td><input class="grid-in range" data-f="r" value="<?php echo gars_e(gars_range_cell($row[2] ?? null)); ?>" placeholder="60-60"></td>
                        <td><input class="grid-in n" data-f="ai" type="number" min="0" max="200" value="<?php echo gars_editor_cell($row[3] ?? ''); ?>"></td>
                        <td><button type="button" class="btn ghost rm" title="Remove row">×</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php else: ?>
            <div class="tablewrap">
            <table class="data grid" id="grid-interpretation">
                <thead><tr>
                    <th>Autism Index up to</th><th>Probability of ASD</th><th>DSM-5 severity</th><th>Descriptor</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach (gars_editor_rows('interpretation', $editorPrefill) as $band): ?>
                    <?php $band = (array) $band; ?>
                    <tr>
                        <td><input class="grid-in n" data-f="max" type="number" min="0" value="<?php echo gars_editor_cell($band['max'] ?? ''); ?>" placeholder="empty = open-ended"></td>
                        <td><input class="grid-in" data-f="probability" value="<?php echo gars_editor_cell($band['probability'] ?? ''); ?>"></td>
                        <td><input class="grid-in" data-f="severity" value="<?php echo gars_editor_cell($band['severity'] ?? ''); ?>"></td>
                        <td><input class="grid-in" data-f="descriptor" value="<?php echo gars_editor_cell($band['descriptor'] ?? ''); ?>"></td>
                        <td><button type="button" class="btn ghost rm" title="Remove row">×</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <div class="form-row" style="margin-top:14px;">
                <div class="form-group">
                    <label for="note-<?php echo gars_e($kind); ?>">What changed and why</label>
                    <input type="text" id="note-<?php echo gars_e($kind); ?>" name="note" maxlength="250"
                           placeholder="e.g. corrected Table B percentile for sum 70">
                </div>
            </div>
            <div class="actions">
                <button type="button" class="btn ghost add-row">Add row</button>
                <button type="submit" class="btn">Submit for approval</button>
                <span class="hint muted" style="align-self:center;">Applies globally only after another admin approves it.</span>
            </div>
        </form>
    </details>
    <?php endforeach; ?>
</div>

<!-- history -->
<div class="section">
    <h2>Revision history</h2>
    <?php if (!$recent): ?>
        <p class="empty">No changes have been proposed yet.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="data">
        <thead><tr><th class="n">#</th><th>Table</th><th>Version</th><th>Status</th><th>Proposed by</th><th>Decided by</th><th>Proposed</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $rev): ?>
            <tr>
                <td class="n">#<?php echo (int) $rev['id']; ?></td>
                <td><?php echo gars_e($kindShort[$rev['kind']] ?? $rev['kind']); ?></td>
                <td><span class="badge <?php echo $rev['status'] === 'approved' ? 'on' : ''; ?>"><?php echo gars_e(gars_version_of($rev)); ?></span></td>
                <td><span class="badge <?php echo $rev['status'] === 'approved' ? 'on' : ($rev['status'] === 'rejected' ? 'off' : 'warn'); ?>"><?php echo gars_e($rev['status']); ?></span></td>
                <td><?php echo gars_e($rev['author'] ?? ('user #' . $rev['created_by'])); ?></td>
                <td><?php echo $rev['decided_by'] ? gars_e($rev['decider'] ?? ('user #' . $rev['decided_by'])) : '—'; ?></td>
                <td><?php echo gars_e($rev['created_at']); ?></td>
                <td class="muted"><?php echo gars_e($rev['change_note'] ?? '—'); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                <?php if ($rev['status'] === 'pending'): ?>
                    <span class="muted" style="font-size:.85rem;">decide above</span>
                <?php else: ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Restore revision #<?php echo (int) $rev['id']; ?> as a NEW pending revision? It only becomes active after another admin approves it.');">
                        <?php echo gars_csrf_field(); ?>
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="id" value="<?php echo (int) $rev['id']; ?>">
                        <button class="btn ghost" type="submit">Restore</button>
                    </form>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<style>
    .scoring-details{ border:1px solid var(--rule); border-radius:4px; background:var(--surface); padding:0 18px 18px; margin-bottom:14px; }
    .scoring-details summary{ cursor:pointer; padding:15px 0; font-family:var(--display); font-size:1.12rem; color:var(--brand); list-style:none; display:flex; align-items:center; gap:10px; }
    .scoring-details summary::before{ content:'▸'; color:var(--gold); font-size:1rem; }
    .scoring-details[open] summary::before{ content:'▾'; }
    .scoring-details[open] summary{ border-bottom:1px solid var(--rule); margin-bottom:14px; }
    table.grid td{ padding:6px 8px; }
    .grid-in{ width:100%; min-width:66px; padding:7px 9px; background:var(--brand-soft); border:1px solid var(--rule); border-radius:3px; font:inherit; font-size:.88rem; color:var(--ink); }
    .grid-in:focus{ background:var(--surface); border-color:var(--brand); outline:none; box-shadow:0 0 0 3px rgba(11,92,175,.13); }
    .grid-in.range{ min-width:92px; font-variant-numeric:tabular-nums; }
    .grid-in.n{ text-align:right; font-variant-numeric:tabular-nums; }
    table.grid .rm{ padding:6px 11px; font-size:1rem; line-height:1; }
    code{ background:var(--brand-soft); padding:1px 5px; border-radius:3px; font-size:.85em; }
</style>

<script>
(function () {
    const RANGE_RE = /^(\d+)\s*[-–]\s*(\d+)$/;

    function rangeVal(input) {
        const v = input.value.trim();
        if (v === '') return null;
        const m = v.match(RANGE_RE);
        if (!m) return { bad: input.value };
        return [parseInt(m[1], 10), parseInt(m[2], 10)];
    }

    function serialize(form) {
        const kind = form.dataset.kind;
        const rows = [];
        for (const tr of form.querySelectorAll('tbody tr')) {
            const q = f => tr.querySelector('[data-f="' + f + '"]');
            if (kind === 'table_a') {
                const cells = [
                    parseInt(q('scaled').value, 10),
                    q('pct').value.trim(),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[0]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[1]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[2]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[3]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[4]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[5]),
                ];
                if (cells.some(c => c && c.bad)) return { error: 'A range must look like 0-3 (low-high).' };
                if (isNaN(cells[0]) || cells[1] === '') return { error: 'Every row needs a scaled score and a percentile rank.' };
                rows.push(cells);
            } else if (kind === 'table_b') {
                const cells = [
                    q('pct').value.trim(),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[0]),
                    rangeVal(tr.querySelectorAll('[data-f="r"]')[1]),
                    parseInt(q('ai').value, 10),
                ];
                if (cells.some(c => c && c.bad)) return { error: 'A range must look like 60-60 (low-high).' };
                if (cells[0] === '' || isNaN(cells[3])) return { error: 'Every row needs a percentile rank and an Autism Index.' };
                rows.push(cells);
            } else {
                const maxRaw = q('max').value.trim();
                rows.push({
                    max: maxRaw === '' ? null : parseInt(maxRaw, 10),
                    probability: q('probability').value.trim(),
                    severity: q('severity').value.trim(),
                    descriptor: q('descriptor').value.trim(),
                });
                if (maxRaw !== '' && isNaN(rows[rows.length - 1].max)) return { error: 'Upper bounds must be numbers or empty.' };
            }
        }
        if (!rows.length) return { error: 'Add at least one row before submitting.' };
        return { payload: JSON.stringify(rows) };
    }

    document.querySelectorAll('form.scoring-form').forEach(form => {
        form.addEventListener('submit', e => {
            const res = serialize(form);
            if (res.error) { e.preventDefault(); alert(res.error); return; }
            form.querySelector('[name="payload"]').value = res.payload;
        });

        form.querySelector('.add-row').addEventListener('click', () => {
            const tbody = form.querySelector('tbody');
            const last = tbody.querySelector('tr');
            const row = last.cloneNode(true);
            row.querySelectorAll('input').forEach(i => { i.value = ''; });
            tbody.appendChild(row);
            row.querySelector('input').focus();
        });

        form.addEventListener('click', e => {
            if (e.target.classList.contains('rm')) {
                const tbody = form.querySelector('tbody');
                if (tbody.querySelectorAll('tr').length > 1) e.target.closest('tr').remove();
                else alert('A table needs at least one row.');
            }
        });
    });
})();
</script>
<?php gars_footer(); ?>
