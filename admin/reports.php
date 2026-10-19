<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Reported Accounts';

$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   Safe migration: resolution columns
   ============================================================ */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('decision_note', $cols, true)) {
        $pdo->exec("ALTER TABLE reports
                    ADD COLUMN decision_note TEXT DEFAULT NULL,
                    ADD COLUMN resolved_by INT DEFAULT NULL,
                    ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL");
    }
} catch (PDOException $e) { }

/* ============================================================
   RESOLVE REPORT — record decision + notify reporter + audit
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_report'])) {
    $report_id  = intval($_POST['report_id']);
    $new_status = $_POST['status'] ?? 'resolved';
    $decision   = trim($_POST['decision_note'] ?? '');

    $allowed = ['open', 'resolved', 'dismissed'];
    if (!in_array($new_status, $allowed, true)) {
        $new_status = 'resolved';
    }

    try {
        /* 1. Fetch report + reporter */
        $stmt = $pdo->prepare("
            SELECT r.*, ru.email AS reporter_email
            FROM reports r
            LEFT JOIN users ru ON ru.id = r.reporter_id
            WHERE r.id = ?
        ");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($report) {
            /* 2. Update status + decision */
            $pdo->prepare("
                UPDATE reports
                SET status = ?, decision_note = ?, resolved_by = ?, resolved_at = NOW()
                WHERE id = ?
            ")->execute([$new_status, $decision ?: null, $my_id, $report_id]);

            /* 3. Notify reporter (only if status is resolved/dismissed) */
            if (in_array($new_status, ['resolved', 'dismissed'], true) && !empty($report['reporter_id'])) {
                if ($new_status === 'resolved') {
                    $title   = 'Your Report Has Been Reviewed';
                    $message = 'Thank you for submitting a report. Our team has reviewed it and taken '
                             . 'appropriate action. Your vigilance helps keep NAVORA safe.';
                } else {
                    $title   = 'Your Report Has Been Reviewed';
                    $message = 'After review, no policy violation was found for your submitted report. '
                             . 'Thank you for helping us maintain a trusted community.';
                }

                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, is_read)
                    VALUES (?, ?, ?, 0)
                ")->execute([$report['reporter_id'], $title, $message]);
            }

            /* 4. Audit log */
            logAdminAction($pdo, $my_id, 'report.status_changed', 'report', $report_id,
                "Report {$new_status}" . ($decision ? " — {$decision}" : ''));
        }
    } catch (PDOException $e) { }
    header('Location: reports.php');
    exit;
}

/* ============================================================
   DELETE REPORT
   ============================================================ */
if (isset($_GET['delete'])) {
    $report_id = intval($_GET['delete']);
    try {
        $pdo->prepare("DELETE FROM reports WHERE id = ?")->execute([$report_id]);
        logAdminAction($pdo, $my_id, 'report.deleted', 'report', $report_id);
    } catch (PDOException $e) { }
    header('Location: reports.php');
    exit;
}

/* ============================================================
   FETCH REPORTS
   ============================================================ */
$reports = [];
try {
    $stmt = $pdo->query("
        SELECT r.id, r.reporter_id, r.reported_user_id, r.job_id,
               r.reason, r.status, r.created_at,
               r.decision_note, r.resolved_at,
               ru.email AS reporter_email,
               ru.role  AS reporter_role,
               tu.email AS reported_email,
               tu.role  AS reported_role,
               j.job_title
        FROM reports r
        LEFT JOIN users ru ON r.reporter_id = ru.id
        LEFT JOIN users tu ON r.reported_user_id = tu.id
        LEFT JOIN jobs  j  ON r.job_id = j.id
        ORDER BY
            CASE r.status
                WHEN 'open' THEN 1
                WHEN 'resolved' THEN 2
                WHEN 'dismissed' THEN 3
                ELSE 4
            END,
            r.created_at DESC
    ");
    $reports = $stmt->fetchAll();
} catch (PDOException $e) {
    $reports = [];
    $db_error = $e->getMessage();
}

/* ============================================================
   STATS
   ============================================================ */
$total_reports     = (int) $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
$open_reports      = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
$resolved_reports  = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'resolved'")->fetchColumn();
$dismissed_reports = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'dismissed'")->fetchColumn();

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle = 'Review and act on reported accounts and content.';
$page_icon     = 'fa-flag';
$page_theme    = 'violet';
$header_script = 'Keep it safe 🚩';
$breadcrumb    = 'Admin / Reports';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = <<<'CSS'
<style>
.admin-stat { min-height: 92px !important; padding: 14px 12px !important; gap: 4px !important; }
.admin-stat-icon { width: 26px !important; height: 26px !important; font-size: 0.75rem !important; border-radius: 8px !important; }
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value { font-size: 1.5rem !important; letter-spacing: -0.3px !important; }
.admin-stat-sub { font-size: 0.62rem !important; margin-top: 1px !important; }
.admin-stats { gap: 12px !important; margin-bottom: 16px !important; }

.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 20px; padding: 0 9px;
    background: rgba(96, 165, 250, 0.15); color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem; font-weight: 800; border-radius: 999px;
    margin-left: 6px;
}
.table-panel-sub { font-size: 0.72rem; font-weight: 700; color: rgba(255, 255, 255, 0.55); }
.table-panel .table-panel-head { padding: 14px 20px !important; }

.reports-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.reports-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem; min-width: 1080px;
}
.reports-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.reports-table tbody td {
    padding: 10px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle; color: #fff;
}
.reports-table tbody tr { transition: background 0.15s; }
.reports-table tbody tr:last-child td { border-bottom: none; }
.reports-table tbody tr:hover { background: rgba(96, 165, 250, 0.05); }

.report-id {
    display: inline-flex; align-items: center;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12); color: #93C5FD;
    font-weight: 800; font-size: 0.68rem !important;
    border-radius: 8px; letter-spacing: 0.3px;
    border: 1px solid rgba(96, 165, 250, 0.20);
}

.party-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
.party-avatar {
    width: 32px !important; height: 32px !important;
    border-radius: 9px !important;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.72rem !important;
    flex-shrink: 0; overflow: hidden;
}
.party-avatar.is-reporter { background: linear-gradient(135deg, #3B82F6, #60A5FA); }
.party-avatar.is-reported { background: linear-gradient(135deg, #EF4444, #F87171); }
.party-info { min-width: 0; }
.party-name {
    font-weight: 700; font-size: 0.78rem !important;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 3px !important; max-width: 180px;
}

.mini-role {
    display: inline-flex; align-items: center;
    padding: 2px 8px !important;
    border-radius: 999px;
    font-size: 0.58rem !important; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    border: 1px solid;
}
.mini-role.seeker   { background: rgba(96, 165, 250, 0.15); color: #93C5FD; border-color: rgba(96, 165, 250, 0.30); }
.mini-role.employer { background: rgba(139, 92, 246, 0.15); color: #C4B5FD; border-color: rgba(139, 92, 246, 0.30); }

.job-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: #93C5FD; font-weight: 700;
    font-size: 0.74rem !important;
    padding: 4px 10px;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 8px;
    transition: 0.2s;
    max-width: 220px; overflow: hidden;
}
.job-link:hover {
    background: rgba(96, 165, 250, 0.20);
    border-color: rgba(96, 165, 250, 0.40);
    color: #fff;
}
.job-link i { font-size: 0.68rem; flex-shrink: 0; }
.job-link span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.job-link strong { font-weight: 800; }

.reason-text {
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.74rem !important;
    line-height: 1.5; font-weight: 500;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.date-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.74rem !important;
    color: #fff; font-weight: 600;
    white-space: nowrap;
}
.date-cell i { color: #93C5FD; font-size: 0.68rem !important; }

.empty-cell { color: rgba(255, 255, 255, 0.5); font-weight: 700; }

.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px 4px 9px !important;
    border-radius: 999px;
    font-size: 0.64rem !important; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap; border: 1px solid;
}
.status-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.status-badge.active   { background: rgba(16, 185, 129, 0.15); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.35); }
.status-badge.active .status-dot   { background: #10B981; }
.status-badge.inactive { background: rgba(239, 68, 68, 0.15);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }
.status-badge.inactive .status-dot { background: #EF4444; }
.status-badge.closed   { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }
.status-badge.closed .status-dot   { background: #94A3B8; }

.reports-table .table-actions {
    display: flex; align-items: center;
    gap: 6px; flex-wrap: wrap;
}
.report-action-form { display: inline-flex; gap: 6px; align-items: center; }
.reports-table .table-select {
    padding: 5px 24px 5px 10px !important;
    font-size: 0.7rem !important;
    background-position: right 8px center !important;
}
.reports-table .btn-icon-sm {
    width: 28px !important; height: 28px !important;
    font-size: 0.68rem !important;
}
.btn-save-sm {
    padding: 5px 12px !important;
    font-size: 0.7rem !important;
    border-radius: 999px !important;
}

.decision-note {
    display: block;
    margin-top: 4px;
    font-size: 0.66rem;
    color: rgba(255, 255, 255, 0.55);
    font-style: italic;
    font-weight: 500;
    max-width: 220px;
    overflow-wrap: anywhere;
}

.empty-state { padding: 50px 20px !important; }
.empty-state i { font-size: 26px !important; margin-bottom: 12px !important; }
.empty-state h4 { font-size: 0.95rem !important; margin-bottom: 5px !important; }
.empty-state p { font-size: 0.78rem !important; }

@media (max-width: 900px) {
    .reports-table { font-size: 0.78rem; min-width: 900px; }
    .reports-table thead th, .reports-table tbody td { padding: 9px 12px !important; }
    .party-avatar { width: 28px !important; height: 28px !important; font-size: 0.68rem !important; }
    .party-name { max-width: 140px; font-size: 0.74rem !important; }
    .job-link { max-width: 160px; font-size: 0.7rem !important; }
    .reason-text { font-size: 0.7rem !important; }
}
</style>
CSS;

include '_header.php';
?>

<?php if (!empty($db_error)): ?>
    <div class="alert-soft danger">
        <strong>Database error:</strong> <?php echo htmlspecialchars($db_error); ?>
    </div>
<?php endif; ?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-flag"></i></div>
            <div class="admin-stat-label">Total Reports</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_reports); ?></div>
        <div class="admin-stat-sub">All-time</div>
    </div>
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="admin-stat-label">Open</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($open_reports); ?></div>
        <div class="admin-stat-sub">Needs attention</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Resolved</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($resolved_reports); ?></div>
        <div class="admin-stat-sub">Action taken</div>
    </div>
    <div class="admin-stat gray">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-ban"></i></div>
            <div class="admin-stat-label">Dismissed</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($dismissed_reports); ?></div>
        <div class="admin-stat-sub">No action needed</div>
    </div>
</div>

<!-- ============ TABLE ============ -->
<div class="table-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-flag"></i></span>
            All Reports
            <span class="count-chip"><?php echo count($reports); ?></span>
        </div>
        <div class="table-panel-sub">
            <?php echo $open_reports; ?> open · <?php echo $resolved_reports; ?> resolved
        </div>
    </div>

    <?php if (count($reports) > 0): ?>
        <div class="reports-table-wrap">
            <table class="reports-table">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>Reporter</th>
                        <th>Reported User</th>
                        <th>Related Job</th>
                        <th style="width:280px;">Reason</th>
                        <th style="width:130px;">Status</th>
                        <th style="width:130px;">Date</th>
                        <th style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r):
                        $status = $r['status'] ?? 'open';
                        $statusClass = 'inactive';
                        if ($status === 'resolved')       $statusClass = 'active';
                        elseif ($status === 'dismissed')  $statusClass = 'closed';
                    ?>
                        <tr>
                            <td><span class="report-id">#<?php echo $r['id']; ?></span></td>
                            <td>
                                <div class="party-cell">
                                    <div class="party-avatar is-reporter">
                                        <?php echo strtoupper(substr($r['reporter_email'] ?? '?', 0, 1)); ?>
                                    </div>
                                    <div class="party-info">
                                        <div class="party-name"><?php echo htmlspecialchars($r['reporter_email'] ?? 'Unknown'); ?></div>
                                        <?php if (!empty($r['reporter_role'])): ?>
                                            <span class="mini-role <?php echo $r['reporter_role'] === 'employer' ? 'employer' : 'seeker'; ?>">
                                                <?php echo ucfirst($r['reporter_role']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="party-cell">
                                    <div class="party-avatar is-reported">
                                        <?php echo strtoupper(substr($r['reported_email'] ?? '?', 0, 1)); ?>
                                    </div>
                                    <div class="party-info">
                                        <div class="party-name"><?php echo htmlspecialchars($r['reported_email'] ?? 'Unknown'); ?></div>
                                        <?php if (!empty($r['reported_role'])): ?>
                                            <span class="mini-role <?php echo $r['reported_role'] === 'employer' ? 'employer' : 'seeker'; ?>">
                                                <?php echo ucfirst($r['reported_role']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($r['job_id'])): ?>
                                    <a href="../job-details.php?id=<?php echo $r['job_id']; ?>" target="_blank" class="job-link">
                                        <i class="fas fa-briefcase"></i>
                                        <span>
                                            <strong>#<?php echo $r['job_id']; ?></strong>
                                            <?php if (!empty($r['job_title'])): ?>
                                                — <?php echo htmlspecialchars(mb_strimwidth($r['job_title'], 0, 30, '…')); ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                <?php else: ?>
                                    <span class="empty-cell">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="reason-text">
                                    <?php echo htmlspecialchars(mb_strimwidth($r['reason'] ?? '—', 0, 120, '…')); ?>
                                </div>
                                <?php if (!empty($r['decision_note'])): ?>
                                    <span class="decision-note">
                                        <i class="fas fa-gavel"></i>
                                        <?php echo htmlspecialchars(mb_strimwidth($r['decision_note'], 0, 80, '…')); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                            <td>
                                <span class="date-cell">
                                    <i class="far fa-calendar"></i>
                                    <?php echo !empty($r['created_at']) ? date('M d, Y', strtotime($r['created_at'])) : '—'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <form method="POST" class="report-action-form"
                                          onsubmit="return prepareResolve(this);">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="resolve_report" value="1">
                                        <input type="hidden" name="decision_note" value="">
                                        <select name="status" class="table-select">
                                            <option value="open"      <?php echo $status === 'open'      ? 'selected' : ''; ?>>Open</option>
                                            <option value="resolved"  <?php echo $status === 'resolved'  ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="dismissed" <?php echo $status === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                                        </select>
                                        <button type="submit" class="btn-soft primary btn-save-sm">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                    </form>
                                    <a href="?delete=<?php echo $r['id']; ?>" class="btn-icon-sm danger"
                                       onclick="return confirm('Delete this report?')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-flag"></i>
            <h4>No reports yet</h4>
            <p>When users flag accounts or content, they'll appear here.</p>
        </div>
    <?php endif; ?>
</div>

<script>
function prepareResolve(form) {
    const status = form.querySelector('select[name="status"]').value;
    const noteField = form.querySelector('input[name="decision_note"]');

    if (status === 'open') {
        noteField.value = '';
        return confirm('Reopen this report?');
    }

    const promptText = status === 'resolved'
        ? 'Describe the action taken (visible in audit log only):'
        : 'Reason for dismissal (visible in audit log only):';

    const note = prompt(promptText);
    if (note === null) return false;

    noteField.value = note;
    return confirm(
        status === 'resolved'
            ? 'Mark this report as resolved? The reporter will be notified.'
            : 'Dismiss this report? The reporter will be notified.'
    );
}
</script>

<?php include '_footer.php'; ?>