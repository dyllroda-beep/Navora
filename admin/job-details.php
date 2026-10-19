<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Job Details';
$my_id = $_SESSION['user_id'] ?? 0;

$job_id = intval($_GET['id'] ?? 0);
if (!$job_id) {
    header('Location: /navora3/admin/jobs.php');
    exit;
}

// Fetch Job + Employer + Location + Category
$stmt = $pdo->prepare("
    SELECT j.*, 
           e.company_name, e.logo AS company_logo, e.user_id AS employer_user_id,
           l.municipality, l.province,
           c.category_name,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id) AS app_count,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND status = 'pending') AS pending_apps,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND status = 'shortlisted') AS shortlisted_apps,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND status = 'accepted') AS hired_apps,
           (SELECT COUNT(*) FROM reports WHERE job_id = j.id) AS report_count
    FROM jobs j
    JOIN employers e ON j.employer_id = e.id
    LEFT JOIN locations l ON j.location_id = l.id
    LEFT JOIN categories c ON j.category_id = c.id
    WHERE j.id = ?
");
$stmt->execute([$job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo "Job not found.";
    exit;
}

$page_subtitle = 'View full details, stats, and employer information for this job post.';
$breadcrumb = 'Admin / Job Management / Details';

$extra_css = <<<'CSS'
<style>
/* ============================================================
   ADMIN JOB DETAILS PAGE — FIXED LAYOUT & CENTERED TEXT
   ============================================================ */
.job-layout { 
    display: grid; 
    grid-template-columns: minmax(0, 1.8fr) minmax(0, 1fr); 
    gap: 18px; 
    align-items: start; 
}
@media(max-width: 992px) { 
    .job-layout { grid-template-columns: 1fr; } 
}

/* Fix spacing for cards */
.job-layout .card-panel { 
    padding: 20px 22px !important; 
    margin-bottom: 18px; 
}
.job-layout .card-panel:last-child { 
    margin-bottom: 0; 
}
.job-layout .card-panel-head {
    padding: 0 0 14px 0 !important;
    margin-bottom: 14px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
}

.jd-header {
    display: flex; align-items: flex-start; gap: 16px;
    margin-bottom: 20px; padding-bottom: 16px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
}
.jd-header-logo {
    width: 64px; height: 64px; border-radius: 14px;
    background: rgba(96, 165, 250, 0.10); border: 1px solid rgba(120, 160, 255, 0.20);
    display: flex; align-items: center; justify-content: center;
    color: var(--blue-soft); font-size: 1.6rem; flex-shrink: 0;
    overflow: hidden;
}
.jd-header-logo img { width: 100%; height: 100%; object-fit: cover; }
.jd-header-info { flex: 1; min-width: 0; }
.jd-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 1.4rem; font-weight: 700; color: #fff;
    line-height: 1.2; margin-bottom: 6px; letter-spacing: -0.3px;
}
.jd-company {
    font-size: 0.82rem; color: rgba(255, 255, 255, 0.7); font-weight: 600;
    display: flex; align-items: center; gap: 6px; margin-bottom: 6px;
}
.jd-company i { color: var(--blue-soft); }
.jd-meta {
    display: flex; flex-wrap: wrap; gap: 12px;
    font-size: 11.5px; color: rgba(255, 255, 255, 0.6); font-weight: 600;
}
.jd-meta span { display: inline-flex; align-items: center; gap: 5px; }
.jd-meta i { color: var(--blue-soft); font-size: 10px; }

/* STATUS BADGES */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 30px;
    font-size: 10.5px; font-weight: 800; text-transform: uppercase;
    border: 1px solid;
}
.status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-badge.active { background: rgba(16, 185, 129, 0.15); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.35); }
.status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #FCD34D; border-color: rgba(245, 158, 11, 0.35); }
.status-badge.closed { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }
.status-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }
.status-badge.flagged { background: rgba(239, 68, 68, 0.20); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.45); }
.status-badge.flagged::before { animation: pulse 1.5s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

/* DESCRIPTION */
.jd-section-title {
    font-size: 0.85rem; font-weight: 800; color: var(--blue-soft);
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
}
.jd-text {
    font-size: 13px; color: rgba(255, 255, 255, 0.8); line-height: 1.65;
}
.jd-text ul { padding-left: 20px; margin-top: 8px; }
.jd-text li { margin-bottom: 6px; }

/* SIDEBAR STATS */
.stat-grid { 
    display: grid; 
    grid-template-columns: 1fr 1fr; 
    gap: 10px; 
    margin-bottom: 16px; 
}
.stat-box {
    background: rgba(96, 165, 250, 0.04); 
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 10px; 
    padding: 12px; 
    text-align: center;
}
.stat-box-val { 
    font-family: 'Libre Baskerville', serif; 
    font-size: 1.4rem; 
    font-weight: 700; 
    color: #fff; 
    margin-bottom: 2px; 
}
.stat-box-lbl { 
    font-size: 9.5px; 
    font-weight: 800; 
    color: rgba(255, 255, 255, 0.55); 
    text-transform: uppercase; 
}

/* SIDEBAR INFO ROWS */
.sidebar-info-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 0; border-bottom: 1px solid rgba(120, 160, 255, 0.08);
    font-size: 12.5px;
}
.sidebar-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.sidebar-label { 
    color: rgba(255, 255, 255, 0.6); 
    font-weight: 600; 
    display: flex; 
    align-items: center; 
    gap: 8px; 
}
.sidebar-label i { 
    color: var(--blue-soft); 
    width: 16px; 
    text-align: center; 
    font-size: 11px; 
}
.sidebar-value { 
    font-weight: 700; 
    color: #fff; 
    text-align: right; 
    max-width: 60%; 
    font-size: 12.5px;
}

/* ACTION BUTTONS */
.btn-action {
    height: 42px; 
    border-radius: 10px;
    font-size: 13px; 
    font-weight: 700;
    display: flex; 
    align-items: center; 
    justify-content: center; 
    gap: 8px;
    text-align: center; /* Centered text */
    text-decoration: none; 
    transition: 0.2s; 
    margin-bottom: 10px; 
    width: 100%;
    border: none;
    cursor: pointer;
    font-family: inherit;
}

.btn-action:last-child { 
    margin-bottom: 0; 
}

.btn-action.primary { 
    background: linear-gradient(135deg, #3B82F6, #60A5FA); 
    color: #fff; 
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
}

.btn-action.primary:hover { 
    background: linear-gradient(135deg, #2563EB, #3B82F6); 
    transform: translateY(-1px); 
    color: #fff;
}

.btn-action.outline { 
    background: rgba(20, 32, 68, 0.90); 
    color: #fff; 
    border: 1px solid rgba(120, 160, 255, 0.20); 
}

.btn-action.outline:hover { 
    border-color: rgba(96, 165, 250, 0.45); 
    background: rgba(96, 165, 250, 0.10); 
    color: #fff;
}
</style>
CSS;

include '_header.php';
?>

<!-- ACTION BANNER -->
<div class="info-banner" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: rgba(20, 32, 68, 0.65); border: 1px solid rgba(120, 160, 255, 0.15); border-radius: 14px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <a href="/navora3/admin/jobs.php" class="btn-soft" style="padding: 8px 16px; border-radius: 20px; background: transparent; border: 1px solid rgba(120,160,255,0.2); color: #fff; font-weight: 700; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Jobs
        </a>
    </div>
    <div style="display: flex; gap: 8px;">
        <?php if ($job['status'] === 'pending'): ?>
            <form method="POST" action="/navora3/admin/jobs.php" style="margin:0;">
                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                <button type="submit" name="approve_job" class="btn-soft success" style="padding: 8px 16px; border-radius: 20px; background: rgba(16,185,129,0.15); color:#6EE7B7; border:1px solid rgba(16,185,129,0.3); font-weight:700; cursor:pointer;" onclick="return confirm('Approve this job?');">
                    <i class="fas fa-check"></i> Approve Job
                </button>
            </form>
            <form method="POST" action="/navora3/admin/jobs.php" style="margin:0;" onsubmit="return setReason(this, 'reject_reason', 'Reason for rejection?');">
                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                <input type="hidden" name="reject_reason" value="">
                <button type="submit" name="reject_job" class="btn-soft danger" style="padding: 8px 16px; border-radius: 20px; background: rgba(239,68,68,0.15); color:#FCA5A5; border:1px solid rgba(239,68,68,0.3); font-weight:700; cursor:pointer;">
                    <i class="fas fa-times"></i> Reject
                </button>
            </form>
        <?php elseif ($job['status'] === 'approved'): ?>
            <form method="POST" action="/navora3/admin/jobs.php" style="margin:0;">
                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                <button type="submit" name="close_job" class="btn-soft warning" style="padding: 8px 16px; border-radius: 20px; background: rgba(245,158,11,0.15); color:#FCD34D; border:1px solid rgba(245,158,11,0.3); font-weight:700; cursor:pointer;" onclick="return confirm('Close this job?');">
                    <i class="fas fa-lock"></i> Close Job
                </button>
            </form>
        <?php endif; ?>
        <form method="POST" action="/navora3/admin/jobs.php" style="margin:0;" onsubmit="return confirm('Delete this job permanently?');">
            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
            <button type="submit" name="delete_job" class="btn-soft danger" style="padding: 8px 16px; border-radius: 20px; background: transparent; color:#FCA5A5; border:1px solid rgba(239,68,68,0.3); font-weight:700; cursor:pointer;">
                <i class="fas fa-trash"></i> Delete
            </button>
        </form>
    </div>
</div>

<div class="job-layout">
    
    <!-- LEFT COLUMN -->
    <div class="card-panel">
        <!-- Job Header -->
        <div class="jd-header">
            <div class="jd-header-logo">
                <?php if ($job['company_logo'] && file_exists('../' . $job['company_logo'])): ?>
                    <img src="/navora3/<?php echo htmlspecialchars($job['company_logo']); ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-building"></i>
                <?php endif; ?>
            </div>
            <div class="jd-header-info">
                <div class="jd-title"><?php echo htmlspecialchars($job['job_title']); ?></div>
                <div class="jd-company"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
                <div class="jd-meta">
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['municipality'] . ', ' . $job['province']); ?></span>
                    <span><i class="far fa-clock"></i> Posted <?php echo timeAgo($job['created_at']); ?></span>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                </div>
            </div>
            <div>
                <?php 
                $s_class = 'pending'; $s_text = 'Pending';
                if ($job['status'] === 'approved') { $s_class = 'active'; $s_text = 'Approved'; }
                elseif ($job['status'] === 'closed') { $s_class = 'closed'; $s_text = 'Closed'; }
                elseif ($job['status'] === 'rejected') { $s_class = 'rejected'; $s_text = 'Rejected'; }
                elseif ($job['status'] === 'flagged') { $s_class = 'flagged'; $s_text = 'Flagged'; }
                ?>
                <span class="status-badge <?php echo $s_class; ?>"><?php echo $s_text; ?></span>
            </div>
        </div>

        <!-- Job Info Grid -->
        <div class="card-panel" style="padding: 16px; background: rgba(96, 165, 250, 0.04); border: 1px solid rgba(120, 160, 255, 0.10); margin-bottom: 24px;">
            <div class="stat-grid" style="margin-bottom: 0; grid-template-columns: repeat(4, 1fr);">
                <div class="stat-box" style="background: rgba(20, 32, 68, 0.6);">
                    <div class="stat-box-val"><?php echo htmlspecialchars($job['employment_type']); ?></div>
                    <div class="stat-box-lbl">Employment Type</div>
                </div>
                <div class="stat-box" style="background: rgba(20, 32, 68, 0.6);">
                    <div class="stat-box-val"><?php echo htmlspecialchars($job['experience_level']); ?></div>
                    <div class="stat-box-lbl">Experience</div>
                </div>
                <div class="stat-box" style="background: rgba(20, 32, 68, 0.6);">
                    <div class="stat-box-val"><?php echo htmlspecialchars($job['education_level'] ?: 'Any'); ?></div>
                    <div class="stat-box-lbl">Education</div>
                </div>
                <div class="stat-box" style="background: rgba(20, 32, 68, 0.6);">
                    <div class="stat-box-val"><?php echo (int)$job['vacancies']; ?></div>
                    <div class="stat-box-lbl">Vacancies</div>
                </div>
            </div>
        </div>

        <!-- Description -->
        <div style="margin-bottom: 24px;">
            <div class="jd-section-title"><i class="fas fa-file-alt"></i> Job Description</div>
            <div class="jd-text"><?php echo nl2br(htmlspecialchars($job['description'])); ?></div>
        </div>

        <!-- Responsibilities -->
        <?php if (!empty($job['responsibilities'])): ?>
        <div style="margin-bottom: 24px;">
            <div class="jd-section-title"><i class="fas fa-bullseye"></i> Responsibilities</div>
            <div class="jd-text">
                <?php 
                $resp = explode("\n", str_replace("\r", "", $job['responsibilities']));
                echo "<ul>";
                foreach($resp as $line) { if(trim($line) !== '') echo "<li>" . htmlspecialchars(trim($line)) . "</li>"; }
                echo "</ul>";
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Qualifications -->
        <?php if (!empty($job['qualifications'])): ?>
        <div>
            <div class="jd-section-title"><i class="fas fa-user-graduate"></i> Qualifications</div>
            <div class="jd-text">
                <?php 
                $qual = explode("\n", str_replace("\r", "", $job['qualifications']));
                echo "<ul>";
                foreach($qual as $line) { if(trim($line) !== '') echo "<li>" . htmlspecialchars(trim($line)) . "</li>"; }
                echo "</ul>";
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT COLUMN -->
    <div>
        <!-- Stats Panel -->
        <div class="card-panel">
            <div class="card-panel-head" style="padding-bottom: 12px; margin-bottom: 12px;">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon" style="background: linear-gradient(135deg, #3B82F6, #60A5FA);"><i class="fas fa-chart-pie"></i></span>
                    Applicant Stats
                </div>
            </div>
            
            <div class="stat-grid" style="grid-template-columns: 1fr 1fr; gap: 8px;">
                <div class="stat-box" style="background: rgba(59, 130, 246, 0.08); border-color: rgba(59, 130, 246, 0.20);">
                    <div class="stat-box-val" style="color: #93C5FD;"><?php echo $job['app_count']; ?></div>
                    <div class="stat-box-lbl">Total Applied</div>
                </div>
                <div class="stat-box" style="background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.20);">
                    <div class="stat-box-val" style="color: #FCD34D;"><?php echo $job['pending_apps']; ?></div>
                    <div class="stat-box-lbl">Pending</div>
                </div>
                <div class="stat-box" style="background: rgba(139, 92, 246, 0.08); border-color: rgba(139, 92, 246, 0.20);">
                    <div class="stat-box-val" style="color: #C4B5FD;"><?php echo $job['shortlisted_apps']; ?></div>
                    <div class="stat-box-lbl">Shortlisted</div>
                </div>
                <div class="stat-box" style="background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.20);">
                    <div class="stat-box-val" style="color: #6EE7B7;"><?php echo $job['hired_apps']; ?></div>
                    <div class="stat-box-lbl">Hired</div>
                </div>
            </div>

            <a href="/navora3/admin/applications.php?job_id=<?php echo $job['id']; ?>" class="btn-action primary" style="margin-top: 16px;">
                <i class="fas fa-users"></i> View All Applicants
            </a>
        </div>

        <!-- Employer Info -->
        <div class="card-panel">
            <div class="card-panel-head" style="padding-bottom: 12px; margin-bottom: 12px;">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon" style="background: linear-gradient(135deg, #8B5CF6, #A78BFA);"><i class="fas fa-building"></i></span>
                    Employer Info
                </div>
            </div>
            
            <div class="sidebar-info-row">
                <span class="sidebar-label"><i class="fas fa-briefcase"></i> Company</span>
                <span class="sidebar-value"><?php echo htmlspecialchars($job['company_name']); ?></span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                <span class="sidebar-value"><?php echo htmlspecialchars($job['municipality'] . ', ' . $job['province']); ?></span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-label"><i class="fas fa-calendar"></i> Deadline</span>
                <span class="sidebar-value"><?php echo !empty($job['deadline']) ? date('M d, Y', strtotime($job['deadline'])) : 'Open'; ?></span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-label"><i class="fas fa-peso-sign"></i> Salary</span>
                <span class="sidebar-value" style="color: #6EE7B7;">
                    <?php if (!empty($job['salary_min'])): ?>
                        ₱<?php echo number_format($job['salary_min']); ?><?php if (!empty($job['salary_max'])): ?> - <?php echo number_format($job['salary_max']); ?><?php endif; ?>
                    <?php else: ?>
                        Negotiable
                    <?php endif; ?>
                </span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-label"><i class="fas fa-flag"></i> Reports</span>
                <span class="sidebar-value" style="color: <?php echo $job['report_count'] > 0 ? '#FCA5A5' : '#6EE7B7'; ?>;">
                    <?php echo $job['report_count']; ?>
                </span>
            </div>

            <a href="/navora3/admin/user-details.php?id=<?php echo $job['employer_user_id']; ?>" class="btn-action outline" style="margin-top: 16px;">
                <i class="fas fa-eye"></i> View Employer Profile
            </a>
        </div>
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
</script>

<?php include '_footer.php'; ?>