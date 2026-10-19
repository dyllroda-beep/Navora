<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Application Monitoring';

$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   AUTO-CREATE NOTES TABLE
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_application_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            admin_id INT NOT NULL,
            note TEXT NOT NULL,
            is_flag TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_app (application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { }

/* ============================================================
   POLICY: Admin does NOT change applicant status.
   Employers own the hiring workflow. Admin can only:
     - add monitoring notes
     - flag technical issues
   ============================================================ */

/* ---------- Add monitoring note ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $application_id = intval($_POST['application_id']);
    $note = trim($_POST['admin_note'] ?? '');

    if ($note !== '') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admin_application_notes (application_id, admin_id, note, is_flag)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$application_id, $my_id, $note]);
            logAdminAction($pdo, $my_id, 'application.note_added', 'application', $application_id, $note);
        } catch (PDOException $e) { }
    }
    header('Location: applications.php');
    exit;
}

/* ---------- Flag technical issue ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flag_issue'])) {
    $application_id = intval($_POST['application_id']);
    $issue = trim($_POST['issue'] ?? '');

    if ($issue !== '') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admin_application_notes (application_id, admin_id, note, is_flag)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$application_id, $my_id, $issue]);
            logAdminAction($pdo, $my_id, 'application.flagged', 'application', $application_id, $issue);
        } catch (PDOException $e) { }
    }
    header('Location: applications.php');
    exit;
}

/* ---------- Delete note ---------- */
if (isset($_GET['delete_note'])) {
    $note_id = intval($_GET['delete_note']);
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_application_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        logAdminAction($pdo, $my_id, 'application.note_deleted', 'application_note', $note_id);
    } catch (PDOException $e) { }
    header('Location: applications.php');
    exit;
}

/* ---------- Delete application (with confirmation) ---------- */
if (isset($_GET['delete'])) {
    $application_id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
    $stmt->execute([$application_id]);
    logAdminAction($pdo, $my_id, 'application.deleted', 'application', $application_id);
    header('Location: applications.php');
    exit;
}

/* ---------- Fetch applications with notes ---------- */
$stmt = $pdo->prepare("
    SELECT a.*, j.job_title, j.id AS job_id, j.employment_type,
           e.company_name,
           s.first_name, s.last_name, s.phone,
           s.municipality, s.profile_photo
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    JOIN employers e ON j.employer_id = e.id
    JOIN job_seekers s ON a.seeker_id = s.id
    ORDER BY a.applied_at DESC
");
$stmt->execute();
$applications = $stmt->fetchAll();

/* ---------- Load notes for each application (single query) ---------- */
$notes_by_app = [];
if (count($applications) > 0) {
    $ids = array_column($applications, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT n.*, u.email AS admin_email
            FROM admin_application_notes n
            LEFT JOIN users u ON u.id = n.admin_id
            WHERE n.application_id IN ($placeholders)
            ORDER BY n.created_at DESC
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notes_by_app[$row['application_id']][] = $row;
        }
    } catch (PDOException $e) { }
}

/* ---------- Stats ---------- */
$total_applications    = (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
$pending_applications  = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();
$accepted_applications = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'accepted'")->fetchColumn();
$rejected_applications = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'rejected'")->fetchColumn();

/* ---------- Header vars ---------- */
$page_subtitle  = 'Monitor application activity across the platform.';
$page_icon      = 'fa-file-alt';
$page_theme     = 'blue';
$header_script  = 'Every application counts 💙';
$breadcrumb     = 'Admin / Application Monitoring';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = <<<'CSS'
<style>
/* ============================================================
   APPLICATION MONITORING PAGE
   ============================================================ */

/* ---------- Compact stat tiles ---------- */
.admin-stat { min-height: 92px !important; padding: 14px 12px !important; gap: 4px !important; }
.admin-stat-icon { width: 26px !important; height: 26px !important; font-size: 0.75rem !important; border-radius: 8px !important; }
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value { font-size: 1.5rem !important; letter-spacing: -0.3px !important; }
.admin-stat-sub { font-size: 0.62rem !important; margin-top: 1px !important; }
.admin-stats { gap: 12px !important; margin-bottom: 16px !important; }

/* ---------- Policy banner ---------- */
.policy-banner {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px !important;
    background: rgba(96, 165, 250, 0.08);
    border: 1.5px solid rgba(96, 165, 250, 0.25);
    border-radius: 14px;
    margin-bottom: 16px;
}
.policy-banner-icon {
    width: 38px; height: 38px;
    border-radius: 11px;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.92rem; flex-shrink: 0;
}
.policy-banner-body { flex: 1; min-width: 0; }
.policy-banner-title {
    font-size: 0.84rem !important; font-weight: 800;
    color: #FFFFFF; margin-bottom: 3px;
    display: flex; align-items: center; gap: 7px;
}
.policy-banner-title i {
    color: #93C5FD; font-size: 0.72rem;
}
.policy-banner-text {
    font-size: 0.74rem !important;
    color: rgba(255, 255, 255, 0.75);
    line-height: 1.5; font-weight: 500;
}

/* ---------- Application card ---------- */
.app-card {
    padding: 16px 20px !important;
    margin-bottom: 10px !important;
    border-radius: 14px !important;
    transition: border-color 0.2s;
}
.app-card:last-child { margin-bottom: 0 !important; }
.app-card:hover { border-color: rgba(96, 165, 250, 0.30) !important; }
.app-card.has-flag { border-color: rgba(239, 68, 68, 0.35) !important; }

/* ---------- Main layout ---------- */
.app-main {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1.1fr) auto;
    gap: 20px;
    align-items: center;
}

/* ---------- Applicant column ---------- */
.app-col-applicant {
    display: flex; gap: 14px; align-items: center; min-width: 0;
}
.app-avatar {
    width: 44px !important; height: 44px !important;
    border-radius: 12px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.82rem !important;
    flex-shrink: 0; overflow: hidden;
    transition: transform 0.2s;
}
.app-card:hover .app-avatar { transform: scale(1.05); }
.app-avatar img { width: 100%; height: 100%; object-fit: cover; }

.app-applicant-info { min-width: 0; }
.app-name {
    font-weight: 800; font-size: 0.85rem !important;
    color: #fff; margin-bottom: 5px !important;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    letter-spacing: -0.1px;
}
.app-meta {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.65);
    font-weight: 600; margin-bottom: 3px !important;
    display: flex; align-items: center; gap: 6px;
    overflow-wrap: break-word;
}
.app-meta i {
    color: #93C5FD; font-size: 0.68rem !important;
    width: 12px; text-align: center; flex-shrink: 0;
}

/* ---------- Job column ---------- */
.app-col-job { min-width: 0; }
.app-job-title {
    font-weight: 700; font-size: 0.84rem !important;
    color: #fff; margin-bottom: 6px !important;
    display: flex; align-items: center; gap: 8px;
    overflow-wrap: break-word;
}
.app-job-title i { color: #93C5FD; font-size: 0.72rem !important; flex-shrink: 0; }

/* ---------- Actions column ---------- */
.app-col-actions {
    display: flex; flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    flex-shrink: 0 !important;
    flex-wrap: nowrap !important;
}
.app-btn-row {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.app-col-actions .badge-soft {
    flex-shrink: 0 !important;
    padding: 4px 10px !important;
    font-size: 0.62rem !important;
}
.app-col-actions .btn-icon-sm {
    width: 28px !important; height: 28px !important;
    font-size: 0.68rem !important;
}
.app-col-actions .btn-soft {
    padding: 6px 12px !important;
    font-size: 0.72rem !important;
}

/* ---------- Notes section ---------- */
.app-notes-wrap {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid rgba(120, 160, 255, 0.10);
}
.app-notes-label {
    font-size: 0.66rem !important;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
}
.app-notes-label i { color: #93C5FD; font-size: 0.62rem; }
.app-notes-list {
    display: flex; flex-direction: column; gap: 6px;
    margin-bottom: 10px;
}
.note-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 8px 12px;
    background: rgba(20, 32, 68, 0.5);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-left: 3px solid #60A5FA;
    border-radius: 9px;
    font-size: 0.74rem !important;
    color: rgba(255, 255, 255, 0.85);
    line-height: 1.5;
}
.note-item.is-flag {
    background: rgba(239, 68, 68, 0.08);
    border-color: rgba(239, 68, 68, 0.30);
    border-left-color: #EF4444;
}
.note-icon {
    width: 22px; height: 22px;
    border-radius: 6px;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.6rem !important;
    flex-shrink: 0;
}
.note-item.is-flag .note-icon {
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
}
.note-body { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.note-body strong { color: #fff; font-weight: 800; }
.note-meta {
    font-size: 0.62rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700; margin-top: 3px;
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.note-meta i { font-size: 0.58rem; color: #93C5FD; }
.note-delete {
    background: transparent; border: none; color: rgba(255,255,255,0.4);
    cursor: pointer; font-size: 0.7rem; padding: 2px 4px;
    transition: color 0.15s; flex-shrink: 0;
}
.note-delete:hover { color: #FCA5A5; }

/* Notes form */
.notes-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.notes-form-row {
    display: flex; gap: 8px; align-items: stretch;
}
.notes-form-row input[type="text"] {
    flex: 1;
    padding: 8px 12px !important;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 9px;
    background: rgba(20, 32, 68, 0.60);
    color: #fff;
    font-family: inherit;
    font-size: 0.76rem !important;
    outline: none;
    transition: 0.2s;
}
.notes-form-row input[type="text"]::placeholder { color: rgba(255, 255, 255, 0.45); }
.notes-form-row input[type="text"]:focus {
    border-color: rgba(96, 165, 250, 0.55);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.notes-form-row .btn-soft {
    padding: 8px 14px !important;
    font-size: 0.72rem !important;
    white-space: nowrap;
}

/* ============================================================
   COVER LETTER PANEL
   ============================================================ */
.cover-letter-wrap {
    margin-top: 16px;
    animation: clSlideDown 0.3s ease-out;
}
@keyframes clSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.cover-letter {
    background: rgba(10, 19, 48, 0.6);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 14px;
    overflow: hidden;
    max-width: 100%;
}
.cover-letter-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 14px 18px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
}
.cover-letter-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.cover-letter-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; flex-shrink: 0;
}
.cover-letter-title {
    font-weight: 800; font-size: 0.85rem;
    color: #fff; letter-spacing: -0.1px; line-height: 1.2;
}
.cover-letter-subtitle {
    font-size: 0.72rem; color: rgba(255, 255, 255, 0.6);
    font-weight: 600; margin-top: 3px;
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.cover-letter-subtitle i { color: #93C5FD; font-size: 0.66rem; }
.cover-letter-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: rgba(96, 165, 250, 0.15);
    border: 1px solid rgba(96, 165, 250, 0.25);
    border-radius: 999px;
    font-size: 0.62rem; font-weight: 800;
    color: #93C5FD; text-transform: uppercase;
    letter-spacing: 0.4px; flex-shrink: 0;
}
.cover-letter-badge i { font-size: 0.6rem; }
.cover-letter-body {
    padding: 16px 20px;
    max-height: 380px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(96, 165, 250, 0.3) transparent;
}
.cover-letter-body::-webkit-scrollbar { width: 6px; }
.cover-letter-body::-webkit-scrollbar-track { background: transparent; }
.cover-letter-body::-webkit-scrollbar-thumb {
    background: rgba(96, 165, 250, 0.25);
    border-radius: 6px;
}
.cover-letter-content {
    font-size: 0.82rem; line-height: 1.7;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 500; white-space: pre-wrap;
    overflow-wrap: break-word; word-break: break-word;
}
.cover-letter-foot {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 12px 20px;
    background: rgba(120, 160, 255, 0.04);
    border-top: 1px solid rgba(120, 160, 255, 0.10);
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 700; flex-wrap: wrap;
}
.cover-letter-foot-item { display: inline-flex; align-items: center; gap: 5px; }
.cover-letter-foot-item i { color: #93C5FD; font-size: 0.66rem; }

/* ---------- Responsive ---------- */
@media (max-width: 900px) {
    .app-main {
        grid-template-columns: 1fr 1fr !important;
        align-items: start !important;
    }
    .app-col-actions {
        grid-column: 1 / -1 !important;
        justify-content: flex-start !important;
        flex-wrap: wrap !important;
    }
}
@media (max-width: 600px) {
    .app-main { grid-template-columns: 1fr !important; }
    .app-col-actions { flex-wrap: wrap !important; justify-content: flex-start !important; }
    .cover-letter-head {
        padding: 12px 14px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .cover-letter-badge { align-self: flex-start; }
    .cover-letter-body { padding: 14px 16px; max-height: 300px; }
    .cover-letter-foot {
        padding: 10px 14px;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    .cover-letter-icon { width: 32px; height: 32px; font-size: 0.78rem; }
    .notes-form-row { flex-direction: column; }
}
</style>
CSS;

include '_header.php';
?>

<!-- ============ POLICY BANNER ============ -->
<div class="policy-banner">
    <div class="policy-banner-icon"><i class="fas fa-shield-alt"></i></div>
    <div class="policy-banner-body">
        <div class="policy-banner-title">
            <i class="fas fa-info-circle"></i> Monitoring Mode
        </div>
        <div class="policy-banner-text">
            Admins <strong>monitor</strong> applications but do not change applicant decisions. Employers own the hiring workflow.
            You may add internal notes or flag technical issues for investigation.
        </div>
    </div>
</div>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
            <div class="admin-stat-label">Total Applications</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_applications); ?></div>
        <div class="admin-stat-sub">All-time</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-label">Pending</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($pending_applications); ?></div>
        <div class="admin-stat-sub">Awaiting review</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Accepted</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($accepted_applications); ?></div>
        <div class="admin-stat-sub">Successful hires</div>
    </div>
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="admin-stat-label">Rejected</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($rejected_applications); ?></div>
        <div class="admin-stat-sub">Not selected</div>
    </div>
</div>

<!-- ============ APPLICATIONS LIST ============ -->
<?php if (count($applications) > 0): ?>
    <?php foreach ($applications as $app):
        $sBadge = 'warning';
        if ($app['status'] === 'accepted') $sBadge = 'success';
        elseif ($app['status'] === 'rejected') $sBadge = 'danger';
        elseif ($app['status'] === 'shortlisted') $sBadge = 'violet';
        elseif ($app['status'] === 'interview' || $app['status'] === 'reviewed') $sBadge = 'info';

        $initials = strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1));
        $cl_word_count = !empty($app['cover_letter']) ? str_word_count(strip_tags($app['cover_letter'])) : 0;
        $cl_char_count = !empty($app['cover_letter']) ? strlen($app['cover_letter']) : 0;

        $app_notes = $notes_by_app[$app['id']] ?? [];
        $has_flag = false;
        foreach ($app_notes as $n) { if (!empty($n['is_flag'])) { $has_flag = true; break; } }
    ?>
        <div class="card-panel app-card <?php echo $has_flag ? 'has-flag' : ''; ?>">
            <div class="app-main">

                <!-- Applicant -->
                <div class="app-col app-col-applicant">
                    <div class="app-avatar">
                        <?php if (!empty($app['profile_photo'])): ?>
                            <img src="../<?php echo htmlspecialchars($app['profile_photo']); ?>" alt="">
                        <?php else: ?>
                            <?php echo htmlspecialchars($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="app-applicant-info">
                        <div class="app-name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                        <div class="app-meta">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($app['phone'] ?? 'No phone'); ?>
                        </div>
                        <div class="app-meta">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($app['municipality'] ?? 'N/A'); ?>
                        </div>
                    </div>
                </div>

                <!-- Job -->
                <div class="app-col app-col-job">
                    <div class="app-job-title">
                        <i class="fas fa-briefcase"></i>
                        <?php echo htmlspecialchars($app['job_title']); ?>
                    </div>
                    <div class="app-meta">
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($app['company_name']); ?>
                    </div>
                    <div class="app-meta">
                        <i class="far fa-clock"></i>
                        Applied <?php echo timeAgo($app['applied_at']); ?>
                    </div>
                </div>

                <!-- Actions -->
                <div class="app-col app-col-actions">
                    <span class="badge-soft <?php echo $sBadge; ?>"><?php echo ucfirst($app['status']); ?></span>
                    <div class="app-btn-row">
                        <?php if (!empty($app['cover_letter'])): ?>
                            <button class="btn-soft" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#cover-<?php echo $app['id']; ?>"
                                    aria-expanded="false">
                                <i class="fas fa-envelope-open-text"></i> Cover Letter
                            </button>
                        <?php endif; ?>
                        <a href="?delete=<?php echo $app['id']; ?>" class="btn-icon-sm danger"
                           onclick="return confirm('Delete this application and all its notes? This cannot be undone.')"
                           title="Delete">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- ============ COVER LETTER ============ -->
            <?php if (!empty($app['cover_letter'])): ?>
                <div class="collapse" id="cover-<?php echo $app['id']; ?>">
                    <div class="cover-letter-wrap">
                        <div class="cover-letter">
                            <div class="cover-letter-head">
                                <div class="cover-letter-head-left">
                                    <div class="cover-letter-icon">
                                        <i class="fas fa-envelope-open-text"></i>
                                    </div>
                                    <div>
                                        <div class="cover-letter-title">Cover Letter</div>
                                        <div class="cover-letter-subtitle">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                                            <span style="color: rgba(255,255,255,0.35);">·</span>
                                            <i class="fas fa-briefcase"></i>
                                            <?php echo htmlspecialchars(mb_strimwidth($app['job_title'], 0, 40, '…')); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="cover-letter-badge">
                                    <i class="fas fa-file-alt"></i>
                                    <?php echo $cl_word_count; ?> words
                                </div>
                            </div>
                            <div class="cover-letter-body">
                                <div class="cover-letter-content"><?php echo htmlspecialchars($app['cover_letter']); ?></div>
                            </div>
                            <div class="cover-letter-foot">
                                <div class="cover-letter-foot-item">
                                    <i class="far fa-calendar-alt"></i>
                                    Submitted <?php echo date('M d, Y · g:i A', strtotime($app['applied_at'])); ?>
                                </div>
                                <div class="cover-letter-foot-item">
                                    <i class="fas fa-align-left"></i>
                                    <?php echo number_format($cl_char_count); ?> characters
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ ADMIN NOTES ============ -->
            <div class="app-notes-wrap">
                <div class="app-notes-label">
                    <i class="fas fa-sticky-note"></i>
                    Admin Monitoring Notes
                    <?php if (count($app_notes) > 0): ?>
                        · <?php echo count($app_notes); ?>
                    <?php endif; ?>
                </div>

                <?php if (count($app_notes) > 0): ?>
                    <div class="app-notes-list">
                        <?php foreach ($app_notes as $note): ?>
                            <div class="note-item <?php echo !empty($note['is_flag']) ? 'is-flag' : ''; ?>">
                                <div class="note-icon">
                                    <i class="fas <?php echo !empty($note['is_flag']) ? 'fa-flag' : 'fa-comment'; ?>"></i>
                                </div>
                                <div class="note-body">
                                    <?php if (!empty($note['is_flag'])): ?>
                                        <strong>Flagged:</strong>
                                    <?php endif; ?>
                                    <?php echo nl2br(htmlspecialchars($note['note'])); ?>
                                    <div class="note-meta">
                                        <span><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($note['admin_email'] ?? 'Admin'); ?></span>
                                        <span><i class="far fa-clock"></i> <?php echo date('M d, Y · g:i A', strtotime($note['created_at'])); ?></span>
                                    </div>
                                </div>
                                <a href="?delete_note=<?php echo (int)$note['id']; ?>"
                                   class="note-delete"
                                   onclick="return confirm('Delete this note?')"
                                   title="Delete note">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="notes-form">
                    <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                    <div class="notes-form-row">
                        <input type="text" name="admin_note"
                               placeholder="Add an internal monitoring note..."
                               maxlength="500">
                        <button type="submit" name="add_note" class="btn-soft">
                            <i class="fas fa-plus"></i> Note
                        </button>
                        <button type="submit" name="flag_issue" class="btn-soft warning"
                                onclick="this.form.issue.value = this.form.admin_note.value; return this.form.admin_note.value.trim() !== '';">
                            <i class="fas fa-flag"></i> Flag
                        </button>
                        <input type="hidden" name="issue" value="">
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <div class="card-panel">
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <h4>No applications yet</h4>
            <p>Applications from job seekers will appear here.</p>
        </div>
    </div>
<?php endif; ?>

<?php include '_footer.php'; ?>