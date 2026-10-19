<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/warnings.php';
requireAdmin();

$page_title = 'User Management';
$my_id = $_SESSION['user_id'] ?? 0;

navora_ensure_warning_tables($pdo);

/* ============================================================
   DETECT AVAILABLE COLUMNS
   ============================================================ */
$seeker_cols = [];
$employer_cols = [];
try {
    $seeker_cols = $pdo->query("SHOW COLUMNS FROM job_seekers")->fetchAll(PDO::FETCH_COLUMN);
    $employer_cols = $pdo->query("SHOW COLUMNS FROM employers")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

function safe_subquery($table, $column, $user_id_alias = 'u.id') {
    global $seeker_cols, $employer_cols;
    if ($table === 'job_seekers' && in_array($column, $seeker_cols)) {
        return "(SELECT $column FROM job_seekers WHERE user_id = $user_id_alias LIMIT 1) AS seeker_$column,";
    }
    if ($table === 'employers' && in_array($column, $employer_cols)) {
        return "(SELECT $column FROM employers WHERE user_id = $user_id_alias LIMIT 1) AS employer_$column,";
    }
    return "";
}

$seeker_phone = safe_subquery('job_seekers', 'phone');
$seeker_bio   = safe_subquery('job_seekers', 'bio');
$seeker_loc   = safe_subquery('job_seekers', 'municipality');
$emp_phone    = safe_subquery('employers', 'phone');
$emp_address  = safe_subquery('employers', 'address');
$emp_desc     = safe_subquery('employers', 'company_description');

/* ============================================================
   APPROVE USER (Manual Button)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {
    $user_id = intval($_POST['user_id']);

    try {
        $stmt = $pdo->prepare("SELECT id, email, role, status FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['status'] === 'pending') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$user_id]);

            if ($user['role'] === 'employer') {
                $pdo->prepare("UPDATE employers SET verification_status = 'approved' WHERE user_id = ?")->execute([$user_id]);
            }

            $title = ($user['role'] === 'employer') ? 'Account Verified — Welcome to NAVORA' : 'Account Approved — Welcome to NAVORA';
            $message = 'Your account has been verified and is now active. You can now ' . ($user['role'] === 'employer' ? 'post jobs.' : 'apply for jobs.');
            
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)")->execute([$user_id, $title, $message]);

            logAdminAction($pdo, $my_id, 'user.approved', 'user', $user_id, ucfirst($user['role']) . ' account approved manually');
        }
    } catch (PDOException $e) { }
    header('Location: users.php');
    exit;
}

/* ============================================================
   REJECT USER
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_user'])) {
    $user_id = intval($_POST['user_id']);
    $reason  = trim($_POST['reason'] ?? '');

    try {
        $stmt = $pdo->prepare("SELECT id, email, role, status FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['status'] === 'pending') {
            $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$user_id]);

            if ($user['role'] === 'employer') {
                $pdo->prepare("UPDATE employers SET verification_status = 'rejected' WHERE user_id = ?")->execute([$user_id]);
            }

            $title   = 'Account Application Update';
            $message = 'Your account application has been reviewed and was not approved at this time.';
            if ($reason !== '') $message .= "\n\nReason: {$reason}";
            $message .= "\n\nPlease complete your profile details and contact support if you wish to appeal.";

            $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)")->execute([$user_id, $title, $message]);

            logAdminAction($pdo, $my_id, 'user.rejected', 'user', $user_id, ucfirst($user['role']) . ' account rejected' . ($reason ? ": {$reason}" : ''));
        }
    } catch (PDOException $e) { }
    header('Location: users.php');
    exit;
}

/* ============================================================
   DELETE USER
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);

    try {
        $stmt = $pdo->prepare("SELECT email, role, status FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$user_id]);
        $userRow = $stmt->fetch();

        if ($userRow && in_array($userRow['status'], ['pending', 'rejected', 'inactive'], true)) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->execute([$user_id]);
            logAdminAction($pdo, $my_id, 'user.deleted', 'user', $user_id, "Deleted {$userRow['role']}: {$userRow['email']}");
        }
    } catch (PDOException $e) { }
    header('Location: users.php');
    exit;
}

/* ============================================================
   FILTERS & FETCH
   ============================================================ */
$role_filter   = $_GET['role']   ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');

$where  = ["u.role != 'admin'"];
$params = [];

if ($role_filter !== '' && in_array($role_filter, ['seeker','employer'], true)) {
    $where[]  = "u.role = ?";
    $params[] = $role_filter;
}
if ($status_filter !== '' && in_array($status_filter, ['active','pending','inactive', 'rejected'], true)) {
    $where[]  = "u.status = ?";
    $params[] = $status_filter;
}
if ($search !== '') {
    $where[] = "(u.email LIKE ? OR
                 EXISTS (SELECT 1 FROM job_seekers s WHERE s.user_id = u.id AND CONCAT(s.first_name,' ',s.last_name) LIKE ?) OR
                 EXISTS (SELECT 1 FROM employers e WHERE e.user_id = u.id AND e.company_name LIKE ?))";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT CONCAT(first_name, ' ', last_name) FROM job_seekers WHERE user_id = u.id LIMIT 1) AS seeker_name,
           (SELECT company_name FROM employers WHERE user_id = u.id LIMIT 1) AS employer_name,
           (SELECT profile_photo FROM job_seekers WHERE user_id = u.id LIMIT 1) AS seeker_photo,
           (SELECT logo FROM employers WHERE user_id = u.id LIMIT 1) AS employer_logo,
           (SELECT COUNT(*) FROM account_moderation_log WHERE user_id = u.id AND action = 'warning') AS warning_count,
           $seeker_phone
           $seeker_bio
           $seeker_loc
           $emp_phone
           $emp_address
           $emp_desc
           (SELECT 1) AS _dummy
    FROM users u
    $where_sql
    ORDER BY
        CASE u.status
            WHEN 'pending'  THEN 1
            WHEN 'rejected' THEN 2
            WHEN 'active'   THEN 3
            ELSE 4
        END,
        u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

/* ============================================================
   STATS & HEADER
   ============================================================ */
$total_users     = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$total_seekers   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seeker'")->fetchColumn();
$total_employers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employer'")->fetchColumn();
$total_active    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'")->fetchColumn();

$page_subtitle  = 'View, approve, or reject pending user accounts.';
$page_icon      = 'fa-users';
$page_theme     = 'blue';
$header_script  = 'Community of talent 💙';
$breadcrumb     = 'Admin / User Management';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = <<<'CSS'
<style>
.admin-stat { min-height: 92px !important; padding: 14px 12px !important; gap: 4px !important; }
.admin-stat-icon { width: 26px !important; height: 26px !important; font-size: 0.75rem !important; border-radius: 8px !important; }
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value { font-size: 1.5rem !important; letter-spacing: -0.3px !important; }
.admin-stat-sub { font-size: 0.62rem !important; margin-top: 1px !important; }
.admin-stats { gap: 12px !important; margin-bottom: 16px !important; }

.users-filter-panel { padding: 14px 18px !important; margin-bottom: 14px !important; }
.users-filter {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}
.filter-field { min-width: 0; }
.filter-field .form-label-sm { font-size: 11.5px; margin-bottom: 5px; color: rgba(255,255,255,0.7); font-weight: 700; }

.form-select-sm-custom {
    appearance: none;
    -webkit-appearance: none;
    width: 100%;
    padding: 9px 36px 9px 14px;
    background-color: #0E1938;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 10px;
    color: #FFFFFF;
    font-size: 13px !important;
    font-weight: 600;
    font-family: 'Source Sans 3', sans-serif;
    outline: none;
    cursor: pointer;
    transition: 0.2s;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2393C5FD' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
}
.form-select-sm-custom:focus {
    border-color: #60A5FA;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.form-select-sm-custom option {
    background-color: #0E1938;
    color: #FFFFFF;
    font-size: 13px;
    padding: 10px;
}

.form-input-sm {
    width: 100%;
    padding: 9px 14px;
    background-color: #0E1938;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 10px;
    color: #FFFFFF;
    font-size: 13px !important;
    font-weight: 600;
    outline: none;
    transition: 0.2s;
}
.form-input-sm:focus {
    border-color: #60A5FA;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}

.filter-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.filter-actions .btn-soft { padding: 9px 16px !important; font-size: 13px !important; }

@media (max-width: 900px) {
    .users-filter { grid-template-columns: 1fr 1fr; }
    .filter-search { grid-column: 1 / -1; }
    .filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
    .users-filter { grid-template-columns: 1fr; }
}

.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 20px; padding: 0 9px;
    background: rgba(96, 165, 250, 0.15); color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem; font-weight: 800; border-radius: 999px;
    margin-left: 6px;
}
.table-panel-sub { font-size: 0.72rem; font-weight: 700; color: rgba(255, 255, 255, 0.55); }
.users-panel { overflow: hidden; }
.users-panel .table-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    padding: 14px 20px !important;
}

.users-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.users-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem; min-width: 720px;
}
.users-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.users-table tbody td {
    padding: 10px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle; color: #fff;
}
.users-table tbody tr:last-child td { border-bottom: none; }
.users-table tbody tr { transition: background 0.15s; }
.users-table tbody tr:hover { background: rgba(96, 165, 250, 0.05); }
.users-table tbody tr.is-pending  { background: rgba(245, 158, 11, 0.03); }
.users-table tbody tr.is-rejected { background: rgba(239, 68, 68, 0.04); }

.user-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
.user-avatar {
    width: 36px !important; height: 36px !important;
    border-radius: 10px !important;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.78rem !important;
    flex-shrink: 0; overflow: hidden;
    transition: transform 0.2s;
}
.user-avatar.is-seeker   { background: linear-gradient(135deg, #3B82F6, #60A5FA); }
.user-avatar.is-employer { background: linear-gradient(135deg, #8B5CF6, #A78BFA); }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.users-table tbody tr:hover .user-avatar { transform: scale(1.05); }

.user-info { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.user-name {
    font-weight: 800; font-size: 0.84rem !important;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center;
}
.user-email {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.user-id {
    font-size: 0.65rem !important;
    color: #93C5FD;
    font-weight: 700;
    display: block;
}

.user-joined {
    font-size: 0.75rem !important;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 700;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.user-joined i { font-size: 0.68rem; color: #93C5FD; }

.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px !important;
    border-radius: 999px;
    font-size: 0.64rem !important; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap; border: 1px solid;
}
.role-badge i { font-size: 0.62rem !important; }
.role-badge.seeker   { background: rgba(96, 165, 250, 0.15); color: #93C5FD; border-color: rgba(96, 165, 250, 0.35); }
.role-badge.employer { background: rgba(139, 92, 246, 0.15); color: #C4B5FD; border-color: rgba(139, 92, 246, 0.35); }

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
.status-badge.rejected { background: rgba(239, 68, 68, 0.15);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }
.status-badge.rejected .status-dot { background: #EF4444; }
.status-badge.inactive { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }
.status-badge.inactive .status-dot { background: #94A3B8; }

.warning-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(245, 158, 11, 0.15); color: #FCD34D;
    border: 1px solid rgba(245, 158, 11, 0.35);
    padding: 1px 6px; border-radius: 6px;
    font-size: 0.6rem; font-weight: 800;
    margin-left: 6px;
}

.incomplete-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(239, 68, 68, 0.15); color: #FCA5A5;
    border: 1px solid rgba(239, 68, 68, 0.35);
    padding: 2px 6px; border-radius: 6px;
    font-size: 0.6rem; font-weight: 800;
    margin-left: 6px;
    cursor: help;
}

.user-actions-row {
    display: flex; 
    align-items: center; 
    gap: 8px;
    justify-content: flex-end; 
    flex-wrap: nowrap;
    min-width: 200px;
}
.action-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px !important;
    border-radius: 999px;
    font-size: 0.7rem !important; font-weight: 800;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: 0.15s;
    white-space: nowrap;
    text-decoration: none;
    background: transparent;
    line-height: 1.2;
    height: 28px;
}
.action-btn i { font-size: 0.66rem !important; }
.action-btn.approve { background: rgba(16, 185, 129, 0.12); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.35); }
.action-btn.approve:hover { background: rgba(16, 185, 129, 0.22); }
.action-btn.approve:disabled { opacity: 0.4; cursor: not-allowed; }
.action-btn.reject  { background: rgba(239, 68, 68, 0.10); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.30); }
.action-btn.reject:hover  { background: rgba(239, 68, 68, 0.20); }
.action-btn.deactivate { background: rgba(245, 158, 11, 0.12); color: #FCD34D; border-color: rgba(245, 158, 11, 0.35); }
.action-btn.deactivate:hover { background: rgba(245, 158, 11, 0.22); }

.view-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 8px;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.28);
    color: #93C5FD;
    font-size: 0.68rem !important;
    transition: 0.15s;
    cursor: pointer;
    text-decoration: none;
    flex-shrink: 0;
}
.view-btn:hover {
    background: rgba(96, 165, 250, 0.22);
    border-color: rgba(96, 165, 250, 0.50);
    color: #fff;
}

.icon-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.10);
    border: 1px solid rgba(239, 68, 68, 0.28);
    color: #FCA5A5;
    font-size: 0.68rem !important;
    transition: 0.15s;
    cursor: pointer;
    text-decoration: none;
    flex-shrink: 0;
}
.icon-action:hover {
    background: rgba(239, 68, 68, 0.22);
    border-color: rgba(239, 68, 68, 0.50);
    color: #fff;
}

.finalized-note {
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
    font-style: italic;
    padding-right: 4px;
}

/* Warning Banner */
.warn-banner {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #FCA5A5;
    padding: 12px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.users-cards { display: none; }
@media (max-width: 720px) {
    .users-table-wrap { display: none; }
    .users-cards {
        display: flex; flex-direction: column;
        gap: 10px; padding: 14px;
    }
    .user-card {
        background: rgba(20, 32, 68, 0.55);
        border: 1px solid rgba(120, 160, 255, 0.15);
        border-radius: 12px; padding: 14px;
    }
    .user-card-head {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 10px; padding-bottom: 10px;
        border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    }
    .user-card-badges {
        display: flex; gap: 6px; flex-wrap: wrap;
        margin-bottom: 10px;
    }
    .user-card-meta {
        font-size: 0.72rem;
        color: rgba(255, 255, 255, 0.6);
        font-weight: 600;
        margin-bottom: 12px;
        display: flex; align-items: center; gap: 5px;
    }
    .user-card-meta i { color: #93C5FD; }
    .user-card-actions {
        display: flex; gap: 6px; flex-wrap: wrap;
        align-items: center;
    }
    .user-card-actions .action-btn { flex: 1; justify-content: center; min-width: 90px; }
}
</style>
CSS;

include '_header.php';
?>

<div class="warn-banner">
    <i class="fas fa-shield-alt" style="font-size: 1.2rem;"></i>
    <div>
        <strong>Manual Approval Required.</strong> New accounts are no longer auto-approved. You must review the profile and click "Accept" to activate them.
    </div>
</div>

<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
            <div class="admin-stat-label">Total Users</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_users); ?></div>
        <div class="admin-stat-sub">All accounts</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user"></i></div>
            <div class="admin-stat-label">Seekers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_seekers); ?></div>
        <div class="admin-stat-sub">Looking for jobs</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-building"></i></div>
            <div class="admin-stat-label">Employers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_employers); ?></div>
        <div class="admin-stat-sub">Posting jobs</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="admin-stat-label">Active</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_active); ?></div>
        <div class="admin-stat-sub">Currently active</div>
    </div>
</div>

<div class="card-panel users-filter-panel">
    <form method="GET" class="users-filter">
        <div class="filter-field filter-search">
            <label class="form-label-sm">Search</label>
            <input type="text" name="q" class="form-input-sm"
                   value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Email, name, company...">
        </div>
        <div class="filter-field">
            <label class="form-label-sm">Role</label>
            <select name="role" class="form-select-sm-custom" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <option value="seeker"   <?php echo $role_filter === 'seeker'   ? 'selected' : ''; ?>>Seeker</option>
                <option value="employer" <?php echo $role_filter === 'employer' ? 'selected' : ''; ?>>Employer</option>
            </select>
        </div>
        <div class="filter-field">
            <label class="form-label-sm">Status</label>
            <select name="status" class="form-select-sm-custom" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending"  <?php echo $status_filter === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Deactivated</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-soft primary">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($search || $role_filter || $status_filter): ?>
                <a href="users.php" class="btn-soft">
                    <i class="fas fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="table-panel users-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-users"></i></span>
            All Users
            <span class="count-chip"><?php echo count($users); ?></span>
        </div>
        <div class="table-panel-sub">
            Showing <?php echo count($users); ?> of <?php echo $total_users; ?> users
        </div>
    </div>

    <?php if (count($users) > 0): ?>

        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th style="width:35%;">User</th>
                        <th style="width:14%;">Role</th>
                        <th style="width:15%;">Status</th>
                        <th style="width:15%;">Joined</th>
                        <th style="width:21%; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $row_number = 1;
                    foreach ($users as $u):
                        $is_employer = ($u['role'] === 'employer');
                        $roleClass   = $is_employer ? 'employer' : 'seeker';
                        $statusVal   = $u['status'] ?? 'pending';
                        $statusClass = 'pending';
                        if ($statusVal === 'active')        $statusClass = 'active';
                        elseif ($statusVal === 'rejected')  $statusClass = 'rejected';
                        elseif ($statusVal === 'inactive')  $statusClass = 'inactive';

                        $warning_count = (int)($u['warning_count'] ?? 0);
                        $is_heavy_warned = ($warning_count >= 3);

                        // Check completeness
                        $missing_fields = [];
                        if ($is_employer) {
                            if (isset($u['employer_phone']) && empty($u['employer_phone'])) $missing_fields[] = 'Phone';
                            if (isset($u['employer_address']) && empty($u['employer_address'])) $missing_fields[] = 'Address';
                            if (isset($u['employer_company_description']) && empty($u['employer_company_description'])) $missing_fields[] = 'Description';
                        } else {
                            if (isset($u['seeker_phone']) && empty($u['seeker_phone'])) $missing_fields[] = 'Phone';
                            if (isset($u['seeker_bio']) && empty($u['seeker_bio'])) $missing_fields[] = 'Bio';
                            if (isset($u['seeker_municipality']) && empty($u['seeker_municipality'])) $missing_fields[] = 'Location';
                        }
                        $is_complete = empty($missing_fields);

                        $rowClass = '';
                        if ($statusVal === 'pending')  $rowClass = 'is-pending';
                        if ($statusVal === 'rejected') $rowClass = 'is-rejected';

                        $displayName = $u['email'];
                        $photo = null;
                        $subtitle = '';
                        if ($u['role'] === 'seeker' && !empty($u['seeker_name'])) {
                            $displayName = $u['seeker_name'];
                            $photo = $u['seeker_photo'] ?? null;
                            $subtitle = $u['email'];
                        } elseif ($u['role'] === 'employer' && !empty($u['employer_name'])) {
                            $displayName = $u['employer_name'];
                            $photo = $u['employer_logo'] ?? null;
                            $subtitle = $u['email'];
                        }
                        $initial = strtoupper(substr($displayName, 0, 1));
                    ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar <?php echo $is_employer ? 'is-employer' : 'is-seeker'; ?>">
                                        <?php if (!empty($photo)): ?>
                                            <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($initial); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-info">
                                        <div class="user-name">
                                            <?php echo htmlspecialchars($displayName); ?>
                                            <?php if ($warning_count > 0): ?>
                                                <span class="warning-chip" title="<?php echo $warning_count; ?> warnings issued">
                                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $warning_count; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!$is_complete && $statusVal === 'pending'): ?>
                                                <span class="incomplete-badge" title="Missing: <?php echo implode(', ', $missing_fields); ?>">
                                                    <i class="fas fa-user-edit"></i> Incomplete
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-email"><?php echo htmlspecialchars($subtitle); ?></div>
                                        <div class="user-id">ID: #<?php echo $row_number++; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge <?php echo $roleClass; ?>">
                                    <i class="fas <?php echo $is_employer ? 'fa-building' : 'fa-user'; ?>"></i>
                                    <?php echo ucfirst($u['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <span class="status-dot"></span>
                                    <?php 
                                        if ($statusVal === 'active') echo 'Approved';
                                        elseif ($statusVal === 'inactive') echo 'Deactivated';
                                        else echo ucfirst($statusVal);
                                    ?>
                                </span>
                            </td>
                            <td>
                                <span class="user-joined">
                                    <i class="far fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="user-actions-row">
                                    <!-- View Profile Button -->
                                    <a href="/navora3/admin/user-details.php?id=<?php echo (int)$u['id']; ?>" class="view-btn" title="View Full Profile & Verification">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <?php if ($statusVal === 'pending'): ?>
                                        
                                        <!-- Accept / Approve Button -->
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" name="approve_user" class="action-btn approve"
                                                    <?php echo !$is_complete ? 'disabled title="Profile is incomplete. You must reject or ask user to complete before approving."' : 'onclick="return confirm(\'Approve this account? The user will be notified.\');"'; ?>>
                                                <i class="fas fa-check"></i> Accept
                                            </button>
                                        </form>

                                        <!-- Reject Button -->
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return setReason(this, 'reason', 'Reason for rejection? (e.g., Incomplete Profile)');">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="reason" value="">
                                            <button type="submit" name="reject_user" class="action-btn reject">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                        
                                        <!-- Delete Button -->
                                        <?php if ($is_heavy_warned): ?>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return setReason(this, 'reason', 'Reason for deactivation?');">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" name="deactivate_user" class="action-btn deactivate">
                                                    <i class="fas fa-ban"></i> Deactivate
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('Delete this user permanently? This cannot be undone.');">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <button type="submit" name="delete_user" class="icon-action" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    <?php elseif ($statusVal === 'active'): ?>
                                        <?php if ($is_heavy_warned): ?>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return setReason(this, 'reason', 'Reason for deactivation?');">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" name="deactivate_user" class="action-btn deactivate">
                                                    <i class="fas fa-ban"></i> Deactivate
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="finalized-note">Approved</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="finalized-note" style="margin-right: 8px;">
                                            <?php 
                                                if ($statusVal === 'inactive') echo 'Deactivated';
                                                else echo ucfirst($statusVal);
                                            ?>
                                        </span>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Delete this user permanently? This cannot be undone.');">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" name="delete_user" class="icon-action" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="users-cards">
            <?php 
            $row_number = 1;
            foreach ($users as $u):
                $is_employer = ($u['role'] === 'employer');
                $roleClass   = $is_employer ? 'employer' : 'seeker';
                $statusVal   = $u['status'] ?? 'pending';
                $statusClass = 'pending';
                if ($statusVal === 'active')        $statusClass = 'active';
                elseif ($statusVal === 'rejected')  $statusClass = 'rejected';
                elseif ($statusVal === 'inactive')  $statusClass = 'inactive';

                $warning_count = (int)($u['warning_count'] ?? 0);
                $is_heavy_warned = ($warning_count >= 3);

                $missing_fields = [];
                if ($is_employer) {
                    if (isset($u['employer_phone']) && empty($u['employer_phone'])) $missing_fields[] = 'Phone';
                    if (isset($u['employer_address']) && empty($u['employer_address'])) $missing_fields[] = 'Address';
                    if (isset($u['employer_company_description']) && empty($u['employer_company_description'])) $missing_fields[] = 'Description';
                } else {
                    if (isset($u['seeker_phone']) && empty($u['seeker_phone'])) $missing_fields[] = 'Phone';
                    if (isset($u['seeker_bio']) && empty($u['seeker_bio'])) $missing_fields[] = 'Bio';
                    if (isset($u['seeker_municipality']) && empty($u['seeker_municipality'])) $missing_fields[] = 'Location';
                }
                $is_complete = empty($missing_fields);

                $displayName = $u['email'];
                $photo = null;
                if ($u['role'] === 'seeker' && !empty($u['seeker_name'])) {
                    $displayName = $u['seeker_name'];
                    $photo = $u['seeker_photo'] ?? null;
                } elseif ($u['role'] === 'employer' && !empty($u['employer_name'])) {
                    $displayName = $u['employer_name'];
                    $photo = $u['employer_logo'] ?? null;
                }
                $initial = strtoupper(substr($displayName, 0, 1));
            ?>
                <div class="user-card">
                    <div class="user-card-head">
                        <div class="user-avatar <?php echo $is_employer ? 'is-employer' : 'is-seeker'; ?>">
                            <?php if (!empty($photo)): ?>
                                <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($initial); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name">
                                <?php echo htmlspecialchars($displayName); ?>
                                <?php if ($warning_count > 0): ?>
                                    <span class="warning-chip">
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo $warning_count; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!$is_complete && $statusVal === 'pending'): ?>
                                    <span class="incomplete-badge" title="Missing: <?php echo implode(', ', $missing_fields); ?>">
                                        <i class="fas fa-user-edit"></i> Incomplete
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="user-email"><?php echo htmlspecialchars($u['email']); ?></div>
                            <div class="user-id">ID: #<?php echo $row_number++; ?></div>
                        </div>
                    </div>
                    <div class="user-card-badges">
                        <span class="role-badge <?php echo $roleClass; ?>">
                            <i class="fas <?php echo $is_employer ? 'fa-building' : 'fa-user'; ?>"></i>
                            <?php echo ucfirst($u['role']); ?>
                        </span>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <span class="status-dot"></span>
                            <?php 
                                if ($statusVal === 'active') echo 'Approved';
                                elseif ($statusVal === 'inactive') echo 'Deactivated';
                                else echo ucfirst($statusVal);
                            ?>
                        </span>
                    </div>
                    <div class="user-card-meta">
                        <i class="far fa-calendar"></i> Joined <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                    </div>
                    <div class="user-card-actions">
                        <a href="/navora3/admin/user-details.php?id=<?php echo (int)$u['id']; ?>" class="action-btn" style="background: rgba(96, 165, 250, 0.1); color: #93C5FD; border-color: rgba(96, 165, 250, 0.3);">
                            <i class="fas fa-eye"></i> View
                        </a>

                        <?php if ($statusVal === 'pending'): ?>
                            
                            <form method="POST">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <button type="submit" name="approve_user" class="action-btn approve"
                                        <?php echo !$is_complete ? 'disabled title="Profile is incomplete"' : 'onclick="return confirm(\'Approve this account? The user will be notified.\');"'; ?>>
                                    <i class="fas fa-check"></i> Accept
                                </button>
                            </form>

                            <form method="POST"
                                  onsubmit="return setReason(this, 'reason', 'Reason for rejection?');">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <input type="hidden" name="reason" value="">
                                <button type="submit" name="reject_user" class="action-btn reject">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </form>
                            
                            <?php if ($is_heavy_warned): ?>
                                <form method="POST"
                                      onsubmit="return setReason(this, 'reason', 'Reason for deactivation?');">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="reason" value="">
                                    <button type="submit" name="deactivate_user" class="action-btn deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST"
                                      onsubmit="return confirm('Delete this user permanently? This cannot be undone.');">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <button type="submit" name="delete_user" class="icon-action" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                        <?php elseif ($statusVal === 'active'): ?>
                            <?php if ($is_heavy_warned): ?>
                                <form method="POST"
                                      onsubmit="return setReason(this, 'reason', 'Reason for deactivation?');">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="reason" value="">
                                    <button type="submit" name="deactivate_user" class="action-btn deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="finalized-note">Approved</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="finalized-note" style="margin-right: 8px;">
                                <?php 
                                    if ($statusVal === 'inactive') echo 'Deactivated';
                                    else echo ucfirst($statusVal);
                                ?>
                            </span>
                            <form method="POST"
                                  onsubmit="return confirm('Delete this user permanently? This cannot be undone.');">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <button type="submit" name="delete_user" class="icon-action" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h4>No users found</h4>
            <p>Try adjusting your filters<?php echo $search ? ' or search term' : ''; ?>.</p>
            <a href="users.php" class="btn-soft primary"><i class="fas fa-times"></i> Clear Filters</a>
        </div>
    <?php endif; ?>
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