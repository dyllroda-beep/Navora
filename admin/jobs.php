<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Manage Jobs';

$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   Safe migration: flagged_reason column
   ============================================================ */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM jobs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('flagged_reason', $cols, true)) {
        $pdo->exec("ALTER TABLE jobs ADD COLUMN flagged_reason TEXT DEFAULT NULL");
    }
} catch (PDOException $e) { }

/* ============================================================
   APPROVE JOB — publish + notify employer + audit
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_job'])) {
    $job_id = intval($_POST['job_id']);

    try {
        $stmt = $pdo->prepare("
            SELECT j.id, j.job_title, j.status, e.user_id AS employer_user_id,
                   e.company_name
            FROM jobs j
            JOIN employers e ON j.employer_id = e.id
            WHERE j.id = ?
        ");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job && in_array($job['status'], ['pending', 'flagged'], true)) {
            /* Publish — visible on Find Job page */
            $pdo->prepare("
                UPDATE jobs SET status = 'approved', flagged_reason = NULL
                WHERE id = ?
            ")->execute([$job_id]);

            /* Notify employer */
            $title   = 'Job Posting Approved';
            $message = 'Your job posting "' . $job['job_title'] . '" has been approved and is now '
                     . 'published on NAVORA. Job seekers can view the listing and submit applications.';

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([$job['employer_user_id'], $title, $message]);

            /* Audit log */
            logAdminAction($pdo, $my_id, 'job.approved', 'job', $job_id,
                "Approved & published: {$job['job_title']} (by {$job['company_name']})");
        }
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   REJECT JOB — with reason + notify employer
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_job'])) {
    $job_id = intval($_POST['job_id']);
    $reason = trim($_POST['reject_reason'] ?? '');

    try {
        $stmt = $pdo->prepare("
            SELECT j.id, j.job_title, j.status, e.user_id AS employer_user_id,
                   e.company_name
            FROM jobs j
            JOIN employers e ON j.employer_id = e.id
            WHERE j.id = ?
        ");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job && in_array($job['status'], ['pending', 'flagged'], true)) {
            $pdo->prepare("
                UPDATE jobs SET status = 'rejected', flagged_reason = ?
                WHERE id = ?
            ")->execute([$reason ?: null, $job_id]);

            $title   = 'Job Posting Not Approved';
            $message = 'Your job posting "' . $job['job_title'] . '" was not approved.';
            if ($reason !== '') {
                $message .= "\n\nReason: {$reason}";
            }
            $message .= "\n\nYou can edit and resubmit the posting.";

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([$job['employer_user_id'], $title, $message]);

            logAdminAction($pdo, $my_id, 'job.rejected', 'job', $job_id,
                "Rejected: {$job['job_title']}" . ($reason ? " — {$reason}" : ''));
        }
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   CLOSE JOB
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_job'])) {
    $job_id = intval($_POST['job_id']);

    try {
        $stmt = $pdo->prepare("
            SELECT j.id, j.job_title, j.status, e.user_id AS employer_user_id
            FROM jobs j
            JOIN employers e ON j.employer_id = e.id
            WHERE j.id = ?
        ");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job && $job['status'] === 'approved') {
            $pdo->prepare("UPDATE jobs SET status = 'closed' WHERE id = ?")
                ->execute([$job_id]);

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([
                $job['employer_user_id'],
                'Job Posting Closed',
                'Your job posting "' . $job['job_title'] . '" has been closed. It is no longer visible to job seekers.'
            ]);

            logAdminAction($pdo, $my_id, 'job.closed', 'job', $job_id,
                "Closed: {$job['job_title']}");
        }
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   FLAG JOB
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flag_job'])) {
    $job_id = intval($_POST['job_id']);
    $reason = trim($_POST['flag_reason'] ?? 'Flagged for review');

    try {
        $stmt = $pdo->prepare("
            SELECT j.id, j.job_title, e.user_id AS employer_user_id
            FROM jobs j
            JOIN employers e ON j.employer_id = e.id
            WHERE j.id = ?
        ");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            $pdo->prepare("
                UPDATE jobs SET status = 'flagged', flagged_reason = ?
                WHERE id = ?
            ")->execute([$reason, $job_id]);

            logAdminAction($pdo, $my_id, 'job.flagged', 'job', $job_id,
                "Flagged: {$job['job_title']} — {$reason}");
        }
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   UNFLAG JOB
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unflag_job'])) {
    $job_id = intval($_POST['job_id']);

    try {
        $pdo->prepare("
            UPDATE jobs SET status = 'pending', flagged_reason = NULL
            WHERE id = ?
        ")->execute([$job_id]);

        logAdminAction($pdo, $my_id, 'job.unflagged', 'job', $job_id, 'Returned to pending review');
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   DELETE JOB
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_job'])) {
    $job_id = intval($_POST['job_id']);

    try {
        $snap = $pdo->prepare("SELECT job_title FROM jobs WHERE id = ?");
        $snap->execute([$job_id]);
        $title = $snap->fetchColumn();

        if ($title) {
            $pdo->prepare("DELETE FROM jobs WHERE id = ?")->execute([$job_id]);
            logAdminAction($pdo, $my_id, 'job.deleted', 'job', $job_id,
                "Deleted: {$title}");
        }
    } catch (PDOException $e) { }
    header('Location: jobs.php');
    exit;
}

/* ============================================================
   FETCH JOBS
   ============================================================ */
$stmt = $pdo->query("
    SELECT j.*, e.company_name, l.municipality, c.category_name,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id) AS app_count
    FROM jobs j
    JOIN employers e ON j.employer_id = e.id
    JOIN locations l ON j.location_id = l.id
    LEFT JOIN categories c ON j.category_id = c.id
    ORDER BY
        CASE j.status
            WHEN 'pending' THEN 1
            WHEN 'flagged' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'rejected' THEN 4
            WHEN 'closed' THEN 5
            ELSE 6
        END,
        j.created_at DESC
");
$jobs = $stmt->fetchAll();

/* ============================================================
   STATS
   ============================================================ */
$total_jobs     = count($jobs);
$approved_jobs  = 0;
$pending_jobs   = 0;
$rejected_jobs  = 0;
$closed_jobs    = 0;
$flagged_jobs   = 0;
foreach ($jobs as $j) {
    if ($j['status'] === 'approved') $approved_jobs++;
    elseif ($j['status'] === 'pending') $pending_jobs++;
    elseif ($j['status'] === 'rejected') $rejected_jobs++;
    elseif ($j['status'] === 'closed') $closed_jobs++;
    elseif ($j['status'] === 'flagged') $flagged_jobs++;
}

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Approve, reject, flag, close, or delete job postings.';
$page_icon      = 'fa-briefcase';
$page_theme     = 'amber';
$header_script  = 'Quality jobs matter 💙';
$breadcrumb     = 'Admin / Job Management';
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

.jobs-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.jobs-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem; min-width: 1000px;
}
.jobs-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.jobs-table tbody td {
    padding: 10px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle; color: #fff;
}
.jobs-table tbody tr { transition: background 0.15s; }
.jobs-table tbody tr:last-child td { border-bottom: none; }
.jobs-table tbody tr:hover { background: rgba(96, 165, 250, 0.05); }
.jobs-table tbody tr.is-flagged { background: rgba(239, 68, 68, 0.04); }
.jobs-table tbody tr.is-flagged:hover { background: rgba(239, 68, 68, 0.08); }

.job-id {
    display: inline-flex; align-items: center;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12); color: #93C5FD;
    font-weight: 800; font-size: 0.68rem !important;
    border-radius: 8px; letter-spacing: 0.3px;
    border: 1px solid rgba(96, 165, 250, 0.20);
}

.job-title-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
.job-title-icon {
    width: 36px !important; height: 36px !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem !important; flex-shrink: 0;
    transition: transform 0.2s;
}
.jobs-table tbody tr:hover .job-title-icon { transform: scale(1.05); }
.job-title-text { min-width: 0; }
.job-title-name {
    font-weight: 800; font-size: 0.84rem !important;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 2px !important; max-width: 320px;
}
.job-title-meta {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 600;
    display: inline-flex; align-items: center; gap: 4px;
}
.job-title-meta i { color: #93C5FD; font-size: 0.64rem !important; }

.company-cell, .location-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.75rem !important; color: #fff; font-weight: 600;
}
.company-cell i, .location-cell i { color: #93C5FD; font-size: 0.68rem !important; }

.app-count-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 999px;
    font-size: 0.7rem !important; font-weight: 800; color: #fff;
}
.app-count-chip i { color: #93C5FD; font-size: 0.62rem !important; }

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
.status-badge.pending  { background: rgba(245, 158, 11, 0.15); color: #FCD34D; border-color: rgba(245, 158, 11, 0.35); }
.status-badge.pending .status-dot  { background: #F59E0B; }
.status-badge.inactive { background: rgba(239, 68, 68, 0.15);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }
.status-badge.inactive .status-dot { background: #EF4444; }
.status-badge.closed   { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }
.status-badge.closed .status-dot   { background: #94A3B8; }
.status-badge.flagged  { background: rgba(239, 68, 68, 0.20);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.45); }
.status-badge.flagged .status-dot  { background: #EF4444; animation: flagPulse 1.4s infinite; }

@keyframes flagPulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.35; }
}

.jobs-table .table-actions {
    display: flex; align-items: center;
    gap: 6px; flex-wrap: wrap;
}
.jobs-table .btn-compact {
    padding: 5px 12px !important;
    font-size: 0.72rem !important;
    border-radius: 999px !important;
}
.jobs-table .btn-compact i { font-size: 0.68rem; }
.jobs-table .btn-icon-sm {
    width: 28px !important; height: 28px !important;
    font-size: 0.68rem !important;
}

.flag-note-inline {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.66rem !important;
    color: #FCA5A5;
    font-weight: 700;
    margin-top: 2px;
}

@media (max-width: 720px) {
    .jobs-table { font-size: 0.78rem; min-width: 800px; }
    .jobs-table thead th, .jobs-table tbody td { padding: 9px 12px !important; }
    .job-title-name { max-width: 180px; font-size: 0.8rem !important; }
    .job-title-icon { width: 30px !important; height: 30px !important; font-size: 0.7rem !important; }
}
</style>
CSS;

include '_header.php';
?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-briefcase"></i></div>
            <div class="admin-stat-label">Total Jobs</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_jobs); ?></div>
        <div class="admin-stat-sub">All job posts</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Approved</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($approved_jobs); ?></div>
        <div class="admin-stat-sub">Live &amp; active</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-label">Pending</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($pending_jobs); ?></div>
        <div class="admin-stat-sub">Awaiting approval</div>
    </div>
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-flag"></i></div>
            <div class="admin-stat-label">Flagged</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($flagged_jobs); ?></div>
        <div class="admin-stat-sub">Needs review</div>
    </div>
</div>

<!-- ============ TABLE ============ -->
<div class="table-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-briefcase"></i></span>
            All Jobs
            <span class="count-chip"><?php echo count($jobs); ?></span>
        </div>
        <div class="table-panel-sub">
            <?php echo $pending_jobs; ?> pending ·
            <?php echo $flagged_jobs; ?> flagged ·
            <?php echo $rejected_jobs; ?> rejected
        </div>
    </div>

    <div class="jobs-table-wrap">
        <table class="jobs-table">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Job Title</th>
                    <th>Company</th>
                    <th>Location</th>
                    <th style="width:130px;">Status</th>
                    <th style="width:110px;">Applications</th>
                    <th style="width:340px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($jobs) > 0): ?>
                    <?php foreach($jobs as $job):
                        $statusVal = $job['status'];
                        $statusClass = 'active';
                        $statusLabel = 'Approved';
                        if ($statusVal === 'pending')  { $statusClass = 'pending';  $statusLabel = 'Pending'; }
                        elseif ($statusVal === 'rejected') { $statusClass = 'inactive'; $statusLabel = 'Rejected'; }
                        elseif ($statusVal === 'closed')   { $statusClass = 'closed';   $statusLabel = 'Closed'; }
                        elseif ($statusVal === 'flagged')  { $statusClass = 'flagged';  $statusLabel = 'Flagged'; }

                        $is_flagged = ($statusVal === 'flagged');
                        $flag_reason = $job['flagged_reason'] ?? '';
                    ?>
                        <tr class="<?php echo $is_flagged ? 'is-flagged' : ''; ?>">
                            <td><span class="job-id">#<?php echo $job['id']; ?></span></td>
                            <td>
                                <div class="job-title-cell">
                                    <div class="job-title-icon">
                                        <i class="fas <?php echo $is_flagged ? 'fa-flag' : 'fa-briefcase'; ?>"></i>
                                    </div>
                                    <div class="job-title-text">
                                        <div class="job-title-name"><?php echo htmlspecialchars($job['job_title']); ?></div>
                                        <?php if (!empty($job['employment_type'])): ?>
                                            <div class="job-title-meta">
                                                <i class="far fa-clock"></i>
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $job['employment_type']))); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($is_flagged && $flag_reason): ?>
                                            <div class="flag-note-inline">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <?php echo htmlspecialchars(mb_strimwidth($flag_reason, 0, 50, '…')); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="company-cell">
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($job['company_name']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="location-cell">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($job['municipality']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo $statusLabel; ?>
                                </span>
                            </td>
                            <td>
                                <span class="app-count-chip">
                                    <i class="fas fa-file-alt"></i>
                                    <?php echo (int)$job['app_count']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($job['status'] == 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="approve_job" class="btn-soft success btn-compact"
                                                    onclick="return confirm('Approve and publish this job? The employer will be notified.');">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return setReason(this, 'reject_reason', 'Reason for rejection?');">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <input type="hidden" name="reject_reason" value="">
                                            <button type="submit" name="reject_job" class="btn-soft danger btn-compact">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                        <button type="button" class="btn-soft warning btn-compact"
                                                onclick="flagJob(<?php echo $job['id']; ?>)">
                                            <i class="fas fa-flag"></i> Flag
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($job['status'] == 'approved'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="close_job" class="btn-soft warning btn-compact"
                                                    onclick="return confirm('Close this job? It will no longer be visible to seekers.');">
                                                <i class="fas fa-lock"></i> Close
                                            </button>
                                        </form>
                                        <button type="button" class="btn-soft danger btn-compact"
                                                onclick="flagJob(<?php echo $job['id']; ?>)">
                                            <i class="fas fa-flag"></i> Flag
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($is_flagged): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="unflag_job" class="btn-soft btn-compact"
                                                    onclick="return confirm('Remove flag and send back to pending review?');">
                                                <i class="fas fa-undo"></i> Unflag
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="approve_job" class="btn-soft success btn-compact"
                                                    onclick="return confirm('Approve and publish this job?');">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return setReason(this, 'reject_reason', 'Reason for rejection?');">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <input type="hidden" name="reject_reason" value="">
                                            <button type="submit" name="reject_job" class="btn-soft danger btn-compact">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($job['status'] == 'rejected' || $job['status'] == 'closed'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="approve_job" class="btn-soft success btn-compact"
                                                    onclick="return confirm('Re-approve and publish this job?');">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- UPDATED LINK TO NEW ADMIN JOB DETAILS PAGE -->
                                    <a href="/navora3/admin/job-details.php?id=<?php echo $job['id']; ?>"
                                       class="btn-icon-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete this job permanently? This cannot be undone.');">
                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                        <button type="submit" name="delete_job" class="btn-icon-sm danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fas fa-briefcase"></i>
                                <h4>No jobs yet</h4>
                                <p>Job posts from employers will appear here for review.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function setReason(form, fieldName, promptText) {
    const reason = prompt(promptText);
    if (reason === null) return false;
    const field = form.querySelector('input[name="' + fieldName + '"]');
    if (field) field.value = reason;
    return true;
}

function flagJob(id) {
    const reason = prompt('Reason for flagging this job?');
    if (reason === null) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    const fields = { job_id: id, flag_reason: reason, flag_job: '1' };
    for (const k in fields) {
        const input = document.createElement('input');
        input.name = k;
        input.value = fields[k];
        f.appendChild(input);
    }
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php include '_footer.php'; ?>