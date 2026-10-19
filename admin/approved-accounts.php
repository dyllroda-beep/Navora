<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Approved Accounts';

$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   AUTO-MIGRATE: investigation + suspension columns
   ============================================================ */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('suspended_at', $cols, true)) {
        $pdo->exec("ALTER TABLE users
                    ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL,
                    ADD COLUMN suspended_reason TEXT DEFAULT NULL,
                    ADD COLUMN suspended_by INT DEFAULT NULL");
    }
    if (!in_array('investigation_category', $cols, true)) {
        $pdo->exec("ALTER TABLE users
                    ADD COLUMN investigation_category VARCHAR(80) DEFAULT NULL,
                    ADD COLUMN investigation_notes TEXT DEFAULT NULL,
                    ADD COLUMN investigation_evidence TEXT DEFAULT NULL,
                    ADD COLUMN investigation_warning_sent TINYINT(1) DEFAULT 0");
    }
} catch (PDOException $e) { }

/* ============================================================
   AUTO-MIGRATE: moderation history table
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS account_moderation_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            admin_id INT NOT NULL,
            action ENUM('warning','suspend','deactivate','reactivate','note') NOT NULL,
            category VARCHAR(80) DEFAULT NULL,
            description TEXT NOT NULL,
            evidence TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { }

/* ============================================================
   VALID REASON CATEGORIES (server-side whitelist)
   ============================================================ */
$VALID_CATEGORIES = [
    'fake_account'      => 'Fake or impersonated account',
    'spam'              => 'Spam or excessive self-promotion',
    'harassment'        => 'Harassment or abusive behavior',
    'fraud'             => 'Fraud or scam activity',
    'misleading'        => 'Misleading information in profile/jobs',
    'multiple_reports'  => 'Multiple confirmed user reports',
    'policy_violation'  => 'Repeated violation of platform policy',
    'inappropriate'     => 'Inappropriate content',
    'security'          => 'Security concern (compromised account)',
    'other'             => 'Other (explained in description)',
];

/* ============================================================
   HANDLE MODERATION ACTION — suspend / deactivate / warn
   Requires: category + description (min 20 chars) + confirmation
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['moderation_action'])) {
    $user_id    = intval($_POST['user_id']);
    $action     = $_POST['moderation_action'];   // warning | suspend | deactivate
    $category   = trim($_POST['category'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $evidence   = trim($_POST['evidence'] ?? '');
    $confirmed  = !empty($_POST['confirm_investigation']);

    $errors = [];

    /* Validate action */
    if (!in_array($action, ['warning','suspend','deactivate'], true)) {
        $errors[] = 'Invalid action.';
    }

    /* Validate category */
    if ($category === '' || !isset($VALID_CATEGORIES[$category])) {
        $errors[] = 'A valid reason category is required.';
    }

    /* Validate description */
    if (mb_strlen($desc) < 20) {
        $errors[] = 'Please provide a detailed explanation (at least 20 characters).';
    }

    /* Require investigation confirmation for restrictive actions */
    if (in_array($action, ['suspend','deactivate'], true) && !$confirmed) {
        $errors[] = 'You must confirm that you have investigated this account before restricting it.';
    }

    /* Verify user is currently active */
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id, email, role, status FROM users
            WHERE id = ? AND role != 'admin' AND status = 'active'
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errors[] = 'Account not found or is not currently active.';
        }
    }

    /* Apply the action */
    if (empty($errors) && !empty($user)) {
        try {
            $new_status = $user['status'];
            if ($action === 'suspend')    $new_status = 'suspended';
            if ($action === 'deactivate') $new_status = 'inactive';

            /* Update user record */
            if ($action === 'warning') {
                $pdo->prepare("
                    UPDATE users
                    SET investigation_category = ?,
                        investigation_notes = ?,
                        investigation_evidence = ?,
                        investigation_warning_sent = 1
                    WHERE id = ?
                ")->execute([$category, $desc, $evidence ?: null, $user_id]);
            } else {
                $pdo->prepare("
                    UPDATE users
                    SET status = ?,
                        suspended_at = NOW(),
                        suspended_reason = ?,
                        suspended_by = ?,
                        investigation_category = ?,
                        investigation_notes = ?,
                        investigation_evidence = ?
                    WHERE id = ?
                ")->execute([
                    $new_status,
                    $desc,
                    $my_id,
                    $category,
                    $desc,
                    $evidence ?: null,
                    $user_id
                ]);
            }

            /* Log to moderation history */
            $pdo->prepare("
                INSERT INTO account_moderation_log
                    (user_id, admin_id, action, category, description, evidence)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $user_id,
                $my_id,
                $action,
                $category,
                $desc,
                $evidence ?: null,
            ]);

            /* Notify user */
            $category_label = $VALID_CATEGORIES[$category] ?? 'Policy violation';

            if ($action === 'warning') {
                $title = 'Formal Warning Issued';
                $body  = "Your NAVORA account has received a formal warning.\n\n"
                       . "Category: {$category_label}\n"
                       . "Details: {$desc}\n\n"
                       . "Please review our community guidelines. Repeated violations may "
                       . "result in account restriction.";
            } elseif ($action === 'suspend') {
                $title = 'Account Suspended';
                $body  = "Your NAVORA account has been temporarily suspended.\n\n"
                       . "Category: {$category_label}\n"
                       . "Details: {$desc}\n\n"
                       . "If you believe this was a mistake, please contact our support team "
                       . "to appeal this decision.";
            } else {
                $title = 'Account Deactivated';
                $body  = "Your NAVORA account has been deactivated.\n\n"
                       . "Category: {$category_label}\n"
                       . "Details: {$desc}\n\n"
                       . "If you believe this was a mistake, please contact our support team "
                       . "to appeal this decision.";
            }

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([$user_id, $title, $body]);

            /* Audit log */
            logAdminAction($pdo, $my_id, "user.{$action}", 'user', $user_id,
                "[{$category}] {$desc}" . ($evidence ? " | Evidence: {$evidence}" : ''));

            header('Location: approved-accounts.php?msg=' . $action);
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    /* On error, store and redirect back with flash */
    $_SESSION['moderation_errors'] = $errors;
    header('Location: approved-accounts.php');
    exit;
}

/* ============================================================
   REACTIVATE (also logged)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_user'])) {
    $user_id = intval($_POST['user_id']);
    $note    = trim($_POST['reactivate_note'] ?? '');

    try {
        $stmt = $pdo->prepare("
            SELECT id, email, role FROM users
            WHERE id = ? AND role != 'admin' AND status IN ('suspended','inactive')
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $pdo->prepare("
                UPDATE users
                SET status = 'active',
                    suspended_at = NULL,
                    suspended_reason = NULL,
                    suspended_by = NULL,
                    investigation_category = NULL,
                    investigation_notes = NULL,
                    investigation_evidence = NULL,
                    investigation_warning_sent = 0
                WHERE id = ?
            ")->execute([$user_id]);

            if ($user['role'] === 'employer') {
                $pdo->prepare("UPDATE employers SET verification_status = 'approved' WHERE user_id = ?")
                    ->execute([$user_id]);
            }

            $pdo->prepare("
                INSERT INTO account_moderation_log
                    (user_id, admin_id, action, description)
                VALUES (?, ?, 'reactivate', ?)
            ")->execute([$user_id, $my_id, $note ?: 'Account reactivated by admin']);

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, 'Account Reactivated', ?, 0)
            ")->execute([
                $user_id,
                'Your NAVORA account has been reactivated. You can log in and use all features again.'
            ]);

            logAdminAction($pdo, $my_id, 'user.reactivated', 'user', $user_id,
                $note ?: 'Reactivated');
        }
    } catch (PDOException $e) { }
    header('Location: approved-accounts.php?msg=reactivated');
    exit;
}

/* ============================================================
   FILTERS
   ============================================================ */
$role_filter = $_GET['role'] ?? '';
$search      = trim($_GET['q'] ?? '');
$filter      = $_GET['filter'] ?? '';

$where  = ["u.role != 'admin'", "u.status = 'active'"];
$params = [];

if ($role_filter !== '' && in_array($role_filter, ['seeker','employer'], true)) {
    $where[]  = "u.role = ?";
    $params[] = $role_filter;
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

// Filter for incomplete profiles
if ($filter === 'incomplete') {
    $where[] = "(
        (u.role = 'seeker' AND (SELECT phone FROM job_seekers WHERE user_id = u.id LIMIT 1) IN ('', NULL)) OR
        (u.role = 'employer' AND (SELECT phone FROM employers WHERE user_id = u.id LIMIT 1) IN ('', NULL))
    )";
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

/* ============================================================
   FETCH APPROVED ACCOUNTS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT CONCAT(first_name, ' ', last_name) FROM job_seekers WHERE user_id = u.id LIMIT 1) AS seeker_name,
           (SELECT company_name FROM employers WHERE user_id = u.id LIMIT 1) AS employer_name,
           (SELECT profile_photo FROM job_seekers WHERE user_id = u.id LIMIT 1) AS seeker_photo,
           (SELECT logo FROM employers WHERE user_id = u.id LIMIT 1) AS employer_logo,
           (SELECT verification_status FROM employers WHERE user_id = u.id LIMIT 1) AS verification_status,
           (SELECT COUNT(*) FROM applications a JOIN job_seekers s ON a.seeker_id = s.id WHERE s.user_id = u.id) AS app_count,
           (SELECT COUNT(*) FROM jobs WHERE employer_id = (SELECT id FROM employers WHERE user_id = u.id LIMIT 1)) AS job_count,
           (SELECT COUNT(*) FROM reports WHERE reported_user_id = u.id) AS report_count,
           (SELECT COUNT(*) FROM account_moderation_log WHERE user_id = u.id) AS moderation_count
    FROM users u
    $where_sql
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$accounts = $stmt->fetchAll();

/* ============================================================
   STATS
   ============================================================ */
$total_approved     = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'")->fetchColumn();
$approved_seekers   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seeker' AND status = 'active'")->fetchColumn();
$approved_employers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employer' AND status = 'active'")->fetchColumn();
$verified_employers = (int) $pdo->query("SELECT COUNT(*) FROM employers WHERE verification_status = 'approved'")->fetchColumn();

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Manage verified employers and active job seekers.';
$page_icon      = 'fa-user-check';
$page_theme     = 'green';
$header_script  = 'Verified & active ✨';
$breadcrumb     = 'Admin / Approved Accounts';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = <<<'CSS'
<style>
/* ============================================================
   APPROVED ACCOUNTS — with investigation workflow
   ============================================================ */

.admin-stat { min-height: 92px !important; padding: 14px 12px !important; gap: 4px !important; }
.admin-stat-icon { width: 26px !important; height: 26px !important; font-size: 0.75rem !important; border-radius: 8px !important; }
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value { font-size: 1.5rem !important; letter-spacing: -0.3px !important; }
.admin-stat-sub { font-size: 0.62rem !important; margin-top: 1px !important; }
.admin-stats { gap: 12px !important; margin-bottom: 16px !important; }

.info-banner {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px;
    background: rgba(16, 185, 129, 0.08);
    border: 1.5px solid rgba(16, 185, 129, 0.22);
    border-radius: 12px;
    margin-bottom: 14px;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.78);
    font-weight: 500; line-height: 1.5;
}
.info-banner i { color: #6EE7B7; font-size: 1rem; flex-shrink: 0; }
.info-banner strong { color: #fff; font-weight: 800; }

.aa-toolbar { padding: 14px 18px !important; margin-bottom: 14px !important; }
.aa-filters {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 12px; align-items: end;
}
.filter-field { min-width: 0; }
.filter-field .form-label-sm { font-size: 11.5px; margin-bottom: 5px; }
.filter-field .form-input-sm,
.filter-field .form-select-sm-custom { padding: 9px 12px !important; font-size: 13px !important; }
.filter-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.filter-actions .btn-soft { padding: 8px 14px !important; font-size: 12.5px !important; }
@media (max-width: 900px) {
    .aa-filters { grid-template-columns: 1fr 1fr; }
    .filter-search { grid-column: 1 / -1; }
    .filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 520px) { .aa-filters { grid-template-columns: 1fr; } }

.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 20px; padding: 0 9px;
    background: rgba(96, 165, 250, 0.15); color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem; font-weight: 800; border-radius: 999px;
    margin-left: 6px;
}
.table-panel-sub { font-size: 0.72rem; font-weight: 700; color: rgba(255, 255, 255, 0.55); }
.aa-panel { overflow: visible; }
.aa-panel .table-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    padding: 14px 20px !important;
}

.aa-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.aa-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem; min-width: 900px;
}
.aa-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.aa-table tbody td {
    padding: 12px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle; color: #fff;
}
.aa-table tbody tr:last-child td { border-bottom: none; }
.aa-table tbody tr { transition: background 0.15s; }
.aa-table tbody tr:hover { background: rgba(96, 165, 250, 0.05); }

.aa-user { display: flex; align-items: center; gap: 12px; min-width: 0; }
.aa-avatar {
    width: 40px; height: 40px;
    border-radius: 12px;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.82rem;
    flex-shrink: 0; overflow: hidden;
    position: relative; transition: transform 0.2s;
}
.aa-avatar.is-seeker   { background: linear-gradient(135deg, #3B82F6, #60A5FA); }
.aa-avatar.is-employer { background: linear-gradient(135deg, #8B5CF6, #A78BFA); }
.aa-avatar img { width: 100%; height: 100%; object-fit: cover; }
.aa-table tbody tr:hover .aa-avatar { transform: scale(1.05); }

.aa-avatar-dot {
    position: absolute;
    bottom: -2px; right: -2px;
    width: 14px; height: 14px;
    border-radius: 50%;
    background: #10B981;
    border: 2px solid #0A1330;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.5rem; color: #fff;
}

.aa-info { min-width: 0; }
.aa-name {
    font-weight: 800; font-size: 0.85rem; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 3px;
}
.aa-email {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; gap: 5px;
}
.aa-email i { font-size: 0.64rem; color: #93C5FD; flex-shrink: 0; }

.role-badge-container {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}

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

.verified-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 6px !important;
    background: rgba(16, 185, 129, 0.15);
    color: #6EE7B7;
    border: 1px solid rgba(16, 185, 129, 0.35);
    border-radius: 999px;
    font-size: 0.55rem !important; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    margin-top: 0;
}
.verified-badge i { font-size: 0.5rem; }

.stat-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 999px;
    font-size: 0.68rem; font-weight: 800;
    color: #fff; white-space: nowrap;
}
.stat-pill i { color: #93C5FD; font-size: 0.62rem; }
.stat-pill.warn {
    background: rgba(245, 158, 11, 0.12);
    border-color: rgba(245, 158, 11, 0.28);
    color: #FCD34D;
}
.stat-pill.warn i { color: #FCD34D; }
.stat-pill.danger {
    background: rgba(239, 68, 68, 0.12);
    border-color: rgba(239, 68, 68, 0.28);
    color: #FCA5A5;
}
.stat-pill.danger i { color: #FCA5A5; }

.stat-group { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }

.aa-actions {
    display: flex; 
    align-items: center; 
    justify-content: flex-end;
    gap: 5px; 
    flex-wrap: nowrap; /* Forces them onto one line */
}

.icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 9px;
    background: rgba(20, 32, 68, 0.85);
    border: 1px solid rgba(120, 160, 255, 0.20);
    color: #93C5FD;
    font-size: 0.68rem;
    cursor: pointer;
    transition: 0.15s;
    text-decoration: none;
    flex-shrink: 0;
    position: relative;
}
.icon-btn:hover {
    background: rgba(96, 165, 250, 0.15);
    border-color: rgba(96, 165, 250, 0.45);
    color: #fff;
}
.icon-btn.danger {
    color: #FCA5A5;
    border-color: rgba(239, 68, 68, 0.28);
    background: rgba(239, 68, 68, 0.08);
}
.icon-btn.danger:hover {
    background: rgba(239, 68, 68, 0.20);
    border-color: rgba(239, 68, 68, 0.50);
    color: #fff;
}
.icon-btn.warn {
    color: #FCD34D;
    border-color: rgba(245, 158, 11, 0.28);
    background: rgba(245, 158, 11, 0.08);
}
.icon-btn.warn:hover {
    background: rgba(245, 158, 11, 0.20);
    border-color: rgba(245, 158, 11, 0.50);
    color: #fff;
}

/* ============================================================
   INVESTIGATION MODAL
   ============================================================ */
.inv-modal {
    position: fixed; inset: 0;
    z-index: 9000;
    display: none;
    align-items: center; justify-content: center;
    padding: 20px;
}
.inv-modal.open { display: flex; }
.inv-modal-backdrop {
    position: absolute; inset: 0;
    background: rgba(0, 0, 0, 0.72);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}
.inv-modal-panel {
    position: relative;
    background: #0E1938;
    border: 1.5px solid rgba(96, 165, 250, 0.35);
    border-radius: 18px;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.7);
    width: 100%;
    max-width: 600px;
    max-height: calc(100vh - 40px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalIn 0.24s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95) translateY(12px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.inv-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 22px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    background: rgba(120, 160, 255, 0.05);
    flex-shrink: 0;
}
.inv-modal-title {
    display: flex; align-items: center; gap: 10px;
    font-weight: 800; font-size: 0.95rem; color: #fff;
}
.inv-modal-title-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem;
    color: #fff;
    flex-shrink: 0;
}
.inv-modal-title-icon.warn   { background: linear-gradient(135deg, #F59E0B, #FBBF24); }
.inv-modal-title-icon.danger { background: linear-gradient(135deg, #EF4444, #F87171); }
.inv-modal-close {
    width: 32px; height: 32px; border-radius: 9px;
    background: transparent; border: 1px solid rgba(120, 160, 255, 0.20);
    color: rgba(255, 255, 255, 0.7); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; transition: 0.15s;
}
.inv-modal-close:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.35);
    color: #FCA5A5;
}

.inv-modal-body {
    padding: 22px;
    overflow-y: auto;
    flex: 1;
    scrollbar-width: thin;
    scrollbar-color: rgba(96, 165, 250, 0.25) transparent;
}
.inv-modal-body::-webkit-scrollbar { width: 6px; }
.inv-modal-body::-webkit-scrollbar-thumb { background: rgba(96, 165, 250, 0.25); border-radius: 6px; }

/* Target account summary */
.inv-target {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: rgba(120, 160, 255, 0.06);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    margin-bottom: 18px;
}
.inv-target .aa-avatar { width: 42px !important; height: 42px !important; }
.inv-target-info { min-width: 0; }
.inv-target-name {
    font-weight: 800; font-size: 0.86rem; color: #fff;
    margin-bottom: 3px;
}
.inv-target-email {
    font-size: 0.74rem; color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
}

/* Steps */
.inv-step {
    margin-bottom: 18px;
}
.inv-step-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.7rem; font-weight: 800;
    color: rgba(147, 197, 253, 0.75);
    text-transform: uppercase; letter-spacing: 0.7px;
    margin-bottom: 10px;
}
.inv-step-num {
    width: 20px; height: 20px;
    border-radius: 50%;
    background: rgba(96, 165, 250, 0.20);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.62rem;
    font-weight: 800;
    flex-shrink: 0;
}
.inv-field { margin-bottom: 12px; }
.inv-label {
    display: block;
    font-size: 0.74rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 6px;
}
.inv-label .req { color: #FCA5A5; margin-left: 2px; }
.inv-input,
.inv-select,
.inv-textarea {
    width: 100%;
    padding: 10px 12px;
    background: rgba(20, 32, 68, 0.7);
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 10px;
    color: #fff;
    font-family: inherit;
    font-size: 0.8rem;
    outline: none;
    transition: 0.2s;
    resize: vertical;
}
.inv-input::placeholder,
.inv-textarea::placeholder { color: rgba(255, 255, 255, 0.4); }
.inv-input:focus,
.inv-select:focus,
.inv-textarea:focus {
    border-color: rgba(96, 165, 250, 0.55);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
    background: rgba(20, 32, 68, 0.85);
}
.inv-select option { background: #0A1330; color: #fff; }
.inv-textarea { min-height: 90px; line-height: 1.5; }
.inv-hint {
    display: block;
    font-size: 0.66rem;
    color: rgba(255, 255, 255, 0.5);
    margin-top: 5px;
    font-weight: 500;
}

/* Restriction choices */
.inv-choices {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.inv-choice {
    display: flex; flex-direction: column; gap: 6px;
    padding: 12px 10px;
    background: rgba(20, 32, 68, 0.5);
    border: 1.5px solid rgba(120, 160, 255, 0.20);
    border-radius: 12px;
    cursor: pointer;
    transition: 0.15s;
    text-align: center;
}
.inv-choice:hover {
    border-color: rgba(96, 165, 250, 0.45);
    background: rgba(96, 165, 250, 0.06);
}
.inv-choice input { display: none; }
.inv-choice:has(input:checked) {
    border-color: rgba(96, 165, 250, 0.65);
    background: rgba(96, 165, 250, 0.12);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.10);
}
.inv-choice-icon {
    width: 32px; height: 32px;
    border-radius: 9px;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem;
    margin: 0 auto;
}
.inv-choice input[value="warn"]:checked ~ .inv-choice-icon { background: rgba(245, 158, 11, 0.20); color: #FCD34D; }
.inv-choice input[value="suspend"]:checked ~ .inv-choice-icon { background: rgba(245, 158, 11, 0.20); color: #FCD34D; }
.inv-choice input[value="deactivate"]:checked ~ .inv-choice-icon { background: rgba(239, 68, 68, 0.20); color: #FCA5A5; }

.inv-choice-label {
    font-size: 0.72rem;
    font-weight: 800;
    color: #fff;
}
.inv-choice-desc {
    font-size: 0.6rem;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
    line-height: 1.4;
}

/* Confirmation checkbox */
.inv-confirm {
    display: flex;
    gap: 10px;
    padding: 12px 14px;
    background: rgba(239, 68, 68, 0.08);
    border: 1.5px solid rgba(239, 68, 68, 0.28);
    border-radius: 12px;
    cursor: pointer;
    transition: 0.15s;
}
.inv-confirm:hover { background: rgba(239, 68, 68, 0.12); }
.inv-confirm input {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: #EF4444;
    cursor: pointer;
    flex-shrink: 0;
}
.inv-confirm-text {
    font-size: 0.74rem;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 600;
    line-height: 1.5;
}
.inv-confirm-text strong { color: #FCA5A5; font-weight: 800; }

/* Modal footer */
.inv-modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 16px 22px;
    border-top: 1px solid rgba(120, 160, 255, 0.12);
    background: rgba(120, 160, 255, 0.03);
    flex-shrink: 0;
}
.inv-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: 0.15s;
    text-decoration: none;
}
.inv-btn.cancel {
    background: rgba(20, 32, 68, 0.7);
    border-color: rgba(120, 160, 255, 0.25);
    color: rgba(255, 255, 255, 0.75);
}
.inv-btn.cancel:hover {
    background: rgba(120, 160, 255, 0.12);
    color: #fff;
}
.inv-btn.submit {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    border-color: transparent;
    color: #fff;
}
.inv-btn.submit:hover {
    background: linear-gradient(135deg, #2563EB, #3B82F6);
}

/* Errors */
.inv-errors {
    background: rgba(239, 68, 68, 0.10);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #FCA5A5;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 0.76rem;
    font-weight: 600;
    line-height: 1.5;
}
.inv-errors ul { margin: 6px 0 0; padding-left: 20px; }
.inv-errors li { margin-bottom: 3px; }

/* Modal responsive */
@media (max-width: 640px) {
    .inv-modal-panel { max-height: calc(100vh - 20px); border-radius: 14px; }
    .inv-modal-body { padding: 18px; }
    .inv-modal-head { padding: 14px 18px; }
    .inv-modal-footer { padding: 14px 18px; flex-direction: column-reverse; }
    .inv-modal-footer .inv-btn { width: 100%; justify-content: center; }
    .inv-choices { grid-template-columns: 1fr; }
    .inv-choice { flex-direction: row; text-align: left; gap: 10px; align-items: center; }
    .inv-choice-icon { margin: 0; }
    .inv-choice-label { text-align: left; }
}

/* Empty state */
.empty-state { padding: 50px 20px !important; text-align: center; }
.empty-state i { font-size: 26px !important; margin-bottom: 12px !important; display: block; color: #93C5FD; opacity: 0.5; }
.empty-state h4 { font-size: 0.95rem !important; margin-bottom: 5px !important; color: #fff; font-weight: 800; }
.empty-state p { font-size: 0.78rem !important; color: rgba(255, 255, 255, 0.6); margin-bottom: 16px; }
</style>
CSS;

include '_header.php';

$flash = $_GET['msg'] ?? '';
$moderation_errors = $_SESSION['moderation_errors'] ?? [];
unset($_SESSION['moderation_errors']);
?>

<?php if ($flash === 'warning'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Formal warning issued and logged.</div>
<?php elseif ($flash === 'suspend'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Account suspended and user notified.</div>
<?php elseif ($flash === 'deactivate'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Account deactivated and user notified.</div>
<?php elseif ($flash === 'reactivated'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Account reactivated.</div>
<?php endif; ?>

<?php if (!empty($moderation_errors)): ?>
    <div class="alert-soft danger">
        <i class="fas fa-exclamation-circle"></i>
        <strong>The action was not applied:</strong>
        <ul style="margin: 6px 0 0; padding-left: 20px;">
            <?php foreach ($moderation_errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ============ INFO BANNER ============ -->
<div class="info-banner">
    <i class="fas fa-shield-alt"></i>
    <div>
        <strong>Account moderation requires investigation.</strong>
        Suspending or deactivating an account requires a documented reason, category, and confirmation.
        All actions are logged and the user is notified with appeal instructions.
    </div>
</div>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="admin-stat-label">Approved Accounts</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_approved); ?></div>
        <div class="admin-stat-sub">Verified & active</div>
    </div>
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user"></i></div>
            <div class="admin-stat-label">Active Seekers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($approved_seekers); ?></div>
        <div class="admin-stat-sub">Looking for jobs</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-building"></i></div>
            <div class="admin-stat-label">Active Employers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($approved_employers); ?></div>
        <div class="admin-stat-sub">Posting jobs</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-certificate"></i></div>
            <div class="admin-stat-label">Verified</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($verified_employers); ?></div>
        <div class="admin-stat-sub">Employer businesses</div>
    </div>
</div>

<!-- ============ FILTERS ============ -->
<div class="card-panel aa-toolbar">
    <form method="GET" class="aa-filters">
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
        <div class="filter-actions">
            <button type="submit" class="btn-soft primary"><i class="fas fa-search"></i> Search</button>
            <?php if ($search || $role_filter): ?>
                <a href="approved-accounts.php" class="btn-soft"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============ ACCOUNTS TABLE ============ -->
<div class="table-panel aa-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-user-check"></i></span>
            Approved Accounts
            <span class="count-chip"><?php echo count($accounts); ?></span>
        </div>
        <div class="table-panel-sub">
            Showing <?php echo count($accounts); ?> of <?php echo $total_approved; ?> accounts
        </div>
    </div>

    <?php if (count($accounts) > 0): ?>
        <div class="aa-table-wrap">
            <table class="aa-table">
                <thead>
                    <tr>
                        <th style="width:32%;">Account</th>
                        <th style="width:13%;">Role</th>
                        <th style="width:22%;">Activity</th>
                        <th style="width:13%;">Moderation</th>
                        <th style="width:20%; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $u):
                        $is_employer = ($u['role'] === 'employer');
                        $roleLabel   = $is_employer ? 'Employer' : 'Job Seeker';
                        $photo = $is_employer ? $u['employer_logo'] : $u['seeker_photo'];
                        $displayName = $is_employer
                            ? ($u['employer_name'] ?: $u['email'])
                            : ($u['seeker_name'] ?: $u['email']);
                        $initial = strtoupper(substr($displayName, 0, 1));
                        $is_verified = $is_employer && ($u['verification_status'] ?? '') === 'approved';
                        $app_count    = (int)($u['app_count'] ?? 0);
                        $job_count    = (int)($u['job_count'] ?? 0);
                        $report_count = (int)($u['report_count'] ?? 0);
                        $mod_count    = (int)($u['moderation_count'] ?? 0);
                    ?>
                        <tr>
                            <td>
                                <div class="aa-user">
                                    <div class="aa-avatar <?php echo $is_employer ? 'is-employer' : 'is-seeker'; ?>">
                                        <?php if (!empty($photo)): ?>
                                            <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($initial); ?>
                                        <?php endif; ?>
                                        <span class="aa-avatar-dot"><i class="fas fa-check"></i></span>
                                    </div>
                                    <div class="aa-info">
                                        <div class="aa-name"><?php echo htmlspecialchars($displayName); ?></div>
                                        <div class="aa-email">
                                            <i class="fas fa-envelope"></i>
                                            <?php echo htmlspecialchars($u['email']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="role-badge-container">
                                    <span class="role-badge <?php echo $is_employer ? 'employer' : 'seeker'; ?>">
                                        <i class="fas <?php echo $is_employer ? 'fa-building' : 'fa-user'; ?>"></i>
                                        <?php echo $roleLabel; ?>
                                    </span>
                                    <?php if ($is_verified): ?>
                                        <span class="verified-badge">
                                            <i class="fas fa-certificate"></i> Verified
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="stat-group">
                                    <?php if ($is_employer): ?>
                                        <span class="stat-pill">
                                            <i class="fas fa-briefcase"></i>
                                            <?php echo number_format($job_count); ?> jobs
                                        </span>
                                    <?php else: ?>
                                        <span class="stat-pill">
                                            <i class="fas fa-file-alt"></i>
                                            <?php echo number_format($app_count); ?> applied
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="stat-group">
                                    <?php if ($report_count > 0): ?>
                                        <a href="/navora3/admin/reports.php?user_id=<?php echo (int)$u['id']; ?>"
                                           class="stat-pill warn" style="text-decoration:none;" title="<?php echo $report_count; ?> active report(s)">
                                            <i class="fas fa-flag"></i>
                                            <?php echo $report_count; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="stat-pill" style="opacity:0.3; background: transparent; border-color: transparent; color: rgba(255,255,255,0.4); font-size: 0.6rem; padding: 0;">
                                            —
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="aa-actions">
                                    <a href="/navora3/admin/user-details.php?id=<?php echo (int)$u['id']; ?>"
                                       class="icon-btn" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <!-- INVESTIGATE & SUSPEND -->
                                    <button type="button" class="icon-btn warn"
                                            title="Investigate & Restrict"
                                            onclick="openInvestigation(<?php echo (int)$u['id']; ?>, <?php echo htmlspecialchars(json_encode($displayName), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($u['email']), ENT_QUOTES); ?>, <?php echo $is_employer ? 'true' : 'false'; ?>, <?php echo htmlspecialchars(json_encode($photo ?? ''), ENT_QUOTES); ?>)">
                                        <i class="fas fa-gavel"></i>
                                    </button>
                                    
                                    <!-- REMOVED DELETE BUTTON: Approved accounts cannot be deleted directly to prevent accidental data loss. You must suspend them first. -->
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-user-check"></i>
            <h4>No approved accounts yet</h4>
            <p>Once users are approved in User Management, they'll appear here for ongoing management.</p>
            <a href="/navora3/admin/users.php" class="btn-soft primary">
                <i class="fas fa-arrow-right"></i> Go to User Management
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ============ INVESTIGATION MODAL ============ -->
<div class="inv-modal" id="invModal">
    <div class="inv-modal-backdrop" onclick="closeInvestigation()"></div>
    <form method="POST" class="inv-modal-panel" id="invForm" onsubmit="return validateInvestigation(this);">
        <div class="inv-modal-head">
            <div class="inv-modal-title">
                <span class="inv-modal-title-icon warn" id="invIcon">
                    <i class="fas fa-gavel"></i>
                </span>
                <span>Investigate &amp; Moderate</span>
            </div>
            <button type="button" class="inv-modal-close" onclick="closeInvestigation()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="inv-modal-body">

            <!-- Target -->
            <div class="inv-target">
                <div class="aa-avatar" id="invAvatar">
                    <span id="invInitial">A</span>
                </div>
                <div class="inv-target-info">
                    <div class="inv-target-name" id="invName">—</div>
                    <div class="inv-target-email" id="invEmail">—</div>
                </div>
            </div>

            <input type="hidden" name="user_id" id="invUserId">

            <!-- Step 1: Reason -->
            <div class="inv-step">
                <div class="inv-step-label">
                    <span class="inv-step-num">1</span>
                    Document the violation
                </div>

                <div class="inv-field">
                    <label class="inv-label">Reason Category <span class="req">*</span></label>
                    <select name="category" class="inv-select" required>
                        <option value="">— Select a category —</option>
                        <?php foreach ($VALID_CATEGORIES as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>">
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="inv-field">
                    <label class="inv-label">Detailed Explanation <span class="req">*</span></label>
                    <textarea name="description" class="inv-textarea" required
                              placeholder="Describe specifically what the user did, when it happened, and why it violates NAVORA policy. Minimum 20 characters."></textarea>
                    <span class="inv-hint">This will be recorded in the audit log and shown to the user.</span>
                </div>

                <div class="inv-field">
                    <label class="inv-label">Evidence / Reference <span style="color: rgba(255,255,255,0.5); font-weight: 500;">(optional)</span></label>
                    <input type="text" name="evidence" class="inv-input"
                           placeholder="e.g. Report #42, screenshot link, message ID">
                    <span class="inv-hint">Links to reports, messages, or attachments that support this decision.</span>
                </div>
            </div>

            <!-- Step 2: Action -->
            <div class="inv-step">
                <div class="inv-step-label">
                    <span class="inv-step-num">2</span>
                    Choose the appropriate action
                </div>

                <div class="inv-choices">
                    <label class="inv-choice">
                        <input type="radio" name="moderation_action" value="warning" required>
                        <div class="inv-choice-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="inv-choice-label">Warning</div>
                        <div class="inv-choice-desc">No restriction. Formal notice sent.</div>
                    </label>

                    <label class="inv-choice">
                        <input type="radio" name="moderation_action" value="suspend">
                        <div class="inv-choice-icon"><i class="fas fa-pause"></i></div>
                        <div class="inv-choice-label">Suspend</div>
                        <div class="inv-choice-desc">Temporary restriction. Reversible.</div>
                    </label>

                    <label class="inv-choice">
                        <input type="radio" name="moderation_action" value="deactivate">
                        <div class="inv-choice-icon"><i class="fas fa-ban"></i></div>
                        <div class="inv-choice-label">Deactivate</div>
                        <div class="inv-choice-desc">Permanent disable. Used for serious violations.</div>
                    </label>
                </div>
            </div>

            <!-- Step 3: Confirm -->
            <div class="inv-step">
                <div class="inv-step-label">
                    <span class="inv-step-num">3</span>
                    Confirm investigation
                </div>

                <label class="inv-confirm">
                    <input type="checkbox" name="confirm_investigation" value="1" id="invConfirm">
                    <div class="inv-confirm-text">
                        I confirm I have <strong>investigated this account</strong> thoroughly, reviewed the
                        evidence, and this action is <strong>fair, proportionate, and justified</strong>.
                        I understand this decision will be logged and the user will be notified.
                    </div>
                </label>
            </div>
        </div>

        <div class="inv-modal-footer">
            <button type="button" class="inv-btn cancel" onclick="closeInvestigation()">
                Cancel
            </button>
            <button type="submit" class="inv-btn submit">
                <i class="fas fa-gavel"></i> Apply Decision
            </button>
        </div>
    </form>
</div>

<script>
/* ============================================================
   Investigation modal
   ============================================================ */
function openInvestigation(userId, name, email, isEmployer, photo) {
    document.getElementById('invUserId').value = userId;
    document.getElementById('invName').textContent = name;
    document.getElementById('invEmail').textContent = email;

    const initials = (name || 'U').trim().charAt(0).toUpperCase();
    const avatarEl = document.getElementById('invAvatar');
    avatarEl.className = 'aa-avatar ' + (isEmployer ? 'is-employer' : 'is-seeker');
    if (photo && photo !== '') {
        avatarEl.innerHTML = '<img src="/navora3/' + photo.replace(/"/g, '&quot;') + '" alt="">';
    } else {
        avatarEl.innerHTML = '<span>' + initials + '</span>';
    }

    /* Reset form */
    const form = document.getElementById('invForm');
    form.reset();
    document.getElementById('invUserId').value = userId;

    /* Icon refresh */
    document.getElementById('invIcon').className = 'inv-modal-title-icon warn';
    document.getElementById('invIcon').innerHTML = '<i class="fas fa-gavel"></i>';

    document.getElementById('invModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeInvestigation() {
    document.getElementById('invModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* Update icon color when action chosen */
document.querySelectorAll('input[name="moderation_action"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const icon = document.getElementById('invIcon');
        if (this.value === 'deactivate') {
            icon.className = 'inv-modal-title-icon danger';
            icon.innerHTML = '<i class="fas fa-ban"></i>';
        } else if (this.value === 'suspend') {
            icon.className = 'inv-modal-title-icon warn';
            icon.innerHTML = '<i class="fas fa-pause"></i>';
        } else {
            icon.className = 'inv-modal-title-icon warn';
            icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
        }
    });
});

/* Client-side guard */
function validateInvestigation(form) {
    const action = form.querySelector('input[name="moderation_action"]:checked');
    const category = form.querySelector('select[name="category"]').value;
    const desc = form.querySelector('textarea[name="description"]').value.trim();
    const confirm = form.querySelector('#invConfirm').checked;

    if (!category) {
        alert('Please select a reason category.');
        return false;
    }
    if (desc.length < 20) {
        alert('Please provide at least 20 characters of explanation.');
        return false;
    }
    if (!action) {
        alert('Please choose an action: Warning, Suspend, or Deactivate.');
        return false;
    }
    if ((action.value === 'suspend' || action.value === 'deactivate') && !confirm) {
        alert('You must confirm you have investigated this account before applying a restriction.');
        return false;
    }

    /* Final confirmation */
    const actionLabel = action.value === 'warning' ? 'issue a warning'
                      : action.value === 'suspend' ? 'suspend this account'
                      : 'deactivate this account';
    const finalMsg = 'Are you sure you want to ' + actionLabel +
                     '?\n\nThis will be logged and the user will be notified with appeal instructions.';
    return confirm(finalMsg);
}

/* ESC to close */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeInvestigation();
    }
});
</script>

<?php include '_footer.php'; ?>