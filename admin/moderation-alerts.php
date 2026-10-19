<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Moderation Alerts';
$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   VALID REASON CATEGORIES (shared with approved-accounts.php)
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
   AUTO-MIGRATE: moderation tables (safety net)
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS moderation_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            alert_type VARCHAR(60) NOT NULL,
            severity ENUM('low','medium','high','critical') DEFAULT 'medium',
            title VARCHAR(180) NOT NULL,
            details TEXT DEFAULT NULL,
            context_json TEXT DEFAULT NULL,
            is_reviewed TINYINT(1) DEFAULT 0,
            reviewed_by INT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            review_note TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_severity (severity),
            INDEX idx_reviewed (is_reviewed),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) { }

/* ============================================================
   HANDLE: Mark single alert as reviewed
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_reviewed'])) {
    $alert_id = intval($_POST['alert_id']);
    $note     = trim($_POST['review_note'] ?? '');

    try {
        $pdo->prepare("
            UPDATE moderation_alerts
            SET is_reviewed = 1,
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_note = ?
            WHERE id = ?
        ")->execute([$my_id, $note ?: null, $alert_id]);

        logAdminAction($pdo, $my_id, 'moderation.alert_reviewed',
            'moderation_alert', $alert_id, $note ?: 'Reviewed');
    } catch (PDOException $e) { }
    header('Location: moderation-alerts.php?msg=reviewed');
    exit;
}

/* ============================================================
   HANDLE: Mark ALL as reviewed
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_reviewed'])) {
    try {
        $pdo->prepare("
            UPDATE moderation_alerts
            SET is_reviewed = 1, reviewed_by = ?, reviewed_at = NOW()
            WHERE is_reviewed = 0
        ")->execute([$my_id]);

        logAdminAction($pdo, $my_id, 'moderation.all_reviewed', null, null);
    } catch (PDOException $e) { }
    header('Location: moderation-alerts.php?msg=all_reviewed');
    exit;
}

/* ============================================================
   HANDLE: Delete alert
   ============================================================ */
if (isset($_GET['delete'])) {
    $alert_id = intval($_GET['delete']);
    try {
        $pdo->prepare("DELETE FROM moderation_alerts WHERE id = ?")
            ->execute([$alert_id]);
        logAdminAction($pdo, $my_id, 'moderation.alert_deleted', 'moderation_alert', $alert_id);
    } catch (PDOException $e) { }
    header('Location: moderation-alerts.php');
    exit;
}

/* ============================================================
   HANDLE: Moderation action (from the investigation modal)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['moderation_action'])) {
    $target_user_id = intval($_POST['user_id']);
    $action         = $_POST['moderation_action'];
    $category       = trim($_POST['category'] ?? '');
    $desc           = trim($_POST['description'] ?? '');
    $evidence       = trim($_POST['evidence'] ?? '');
    $confirmed      = !empty($_POST['confirm_investigation']);
    $alert_id       = intval($_POST['source_alert_id'] ?? 0);

    $errors = [];

    if (!in_array($action, ['warning','suspend','deactivate'], true)) {
        $errors[] = 'Invalid action.';
    }
    if ($category === '' || !isset($VALID_CATEGORIES[$category])) {
        $errors[] = 'A valid reason category is required.';
    }
    if (mb_strlen($desc) < 20) {
        $errors[] = 'Please provide a detailed explanation (at least 20 characters).';
    }
    if (in_array($action, ['suspend','deactivate'], true) && !$confirmed) {
        $errors[] = 'You must confirm you have investigated this account before applying a restriction.';
    }

    $user = null;
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id, email, role, status FROM users
            WHERE id = ? AND role != 'admin' AND status = 'active'
        ");
        $stmt->execute([$target_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errors[] = 'Account not found or is not currently active.';
        }
    }

    if (empty($errors) && $user) {
        try {
            $new_status = $user['status'];
            if ($action === 'suspend')    $new_status = 'suspended';
            if ($action === 'deactivate') $new_status = 'inactive';

            if ($action === 'warning') {
                $pdo->prepare("
                    UPDATE users
                    SET investigation_category = ?,
                        investigation_notes = ?,
                        investigation_evidence = ?,
                        investigation_warning_sent = 1
                    WHERE id = ?
                ")->execute([$category, $desc, $evidence ?: null, $target_user_id]);
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
                    $new_status, $desc, $my_id,
                    $category, $desc, $evidence ?: null,
                    $target_user_id
                ]);
            }

            $pdo->prepare("
                INSERT INTO account_moderation_log
                    (user_id, admin_id, action, category, description, evidence)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $target_user_id, $my_id, $action, $category, $desc, $evidence ?: null
            ]);

            /* Notify the user */
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
                       . "If you believe this was a mistake, please contact our support team to appeal.";
            } else {
                $title = 'Account Deactivated';
                $body  = "Your NAVORA account has been deactivated.\n\n"
                       . "Category: {$category_label}\n"
                       . "Details: {$desc}\n\n"
                       . "If you believe this was a mistake, please contact our support team to appeal.";
            }

            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([$target_user_id, $title, $body]);

            logAdminAction($pdo, $my_id, "user.{$action}", 'user', $target_user_id,
                "[{$category}] {$desc}" . ($evidence ? " | Evidence: {$evidence}" : ''));

            /* Auto-mark the source alert as reviewed */
            if ($alert_id > 0) {
                $pdo->prepare("
                    UPDATE moderation_alerts
                    SET is_reviewed = 1,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_note = ?
                    WHERE id = ?
                ")->execute([$my_id, "Escalated: {$action} applied.", $alert_id]);
            }

            header('Location: moderation-alerts.php?msg=' . $action);
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    $_SESSION['moderation_errors'] = $errors;
    header('Location: moderation-alerts.php');
    exit;
}

/* ============================================================
   FETCH ALERTS
   ============================================================ */
$severity = $_GET['severity'] ?? '';
$status   = $_GET['status']   ?? 'unreviewed';
$search   = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if (in_array($severity, ['low','medium','high','critical'], true)) {
    $where[]  = "a.severity = ?";
    $params[] = $severity;
}
if ($status === 'reviewed') {
    $where[] = "a.is_reviewed = 1";
} elseif ($status === 'unreviewed') {
    $where[] = "a.is_reviewed = 0";
}

if ($search !== '') {
    $where[]  = "(u.email LIKE ? OR a.title LIKE ? OR a.details LIKE ? OR a.alert_type LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$alerts = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*,
               u.email AS user_email,
               u.role  AS user_role,
               u.status AS user_status,
               r.email AS reviewer_email
        FROM moderation_alerts a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users r ON r.id = a.reviewed_by
        $where_sql
        ORDER BY
            FIELD(a.severity, 'critical','high','medium','low'),
            a.is_reviewed ASC,
            a.created_at DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $alerts = []; }

/* ============================================================
   STATS
   ============================================================ */
$stats = ['total' => 0, 'unreviewed' => 0, 'critical' => 0, 'today' => 0];
try {
    $stats['total']      = (int) $pdo->query("SELECT COUNT(*) FROM moderation_alerts")->fetchColumn();
    $stats['unreviewed'] = (int) $pdo->query("SELECT COUNT(*) FROM moderation_alerts WHERE is_reviewed = 0")->fetchColumn();
    $stats['critical']   = (int) $pdo->query("SELECT COUNT(*) FROM moderation_alerts WHERE severity IN ('critical','high') AND is_reviewed = 0")->fetchColumn();
    $stats['today']      = (int) $pdo->query("SELECT COUNT(*) FROM moderation_alerts WHERE DATE(created_at) = CURDATE()")->fetchColumn();
} catch (PDOException $e) { }

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Automatic alerts when users show abnormal behavior.';
$page_icon      = 'fa-exclamation-triangle';
$page_theme     = 'red';
$header_script  = 'Watch the signals 🚨';
$breadcrumb     = 'Admin / Moderation Alerts';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = <<<'CSS'
<style>
/* ============================================================
   MODERATION ALERTS PAGE
   ============================================================ */

.admin-stat { min-height: 92px !important; padding: 14px 12px !important; gap: 4px !important; }
.admin-stat-icon { width: 26px !important; height: 26px !important; font-size: 0.75rem !important; border-radius: 8px !important; }
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value { font-size: 1.5rem !important; letter-spacing: -0.3px !important; }
.admin-stat-sub { font-size: 0.62rem !important; margin-top: 1px !important; }
.admin-stats { gap: 12px !important; margin-bottom: 16px !important; }

/* Info banner */
.info-banner {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px;
    background: rgba(239, 68, 68, 0.08);
    border: 1.5px solid rgba(239, 68, 68, 0.22);
    border-radius: 12px;
    margin-bottom: 14px;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.78);
    font-weight: 500; line-height: 1.5;
}
.info-banner i { color: #FCA5A5; font-size: 1rem; flex-shrink: 0; }
.info-banner strong { color: #fff; font-weight: 800; }

/* Toolbar */
.aa-toolbar { padding: 14px 18px !important; margin-bottom: 14px !important; }
.aa-filters {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
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

/* Panel */
.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 20px; padding: 0 9px;
    background: rgba(96, 165, 250, 0.15); color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem; font-weight: 800; border-radius: 999px;
    margin-left: 6px;
}
.table-panel-sub { font-size: 0.72rem; font-weight: 700; color: rgba(255, 255, 255, 0.55); }
.alert-panel .table-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    padding: 14px 20px !important;
}

/* Alert list */
.alert-list {
    display: flex; flex-direction: column;
    gap: 10px; padding: 14px 20px 18px;
}
.alert-item {
    display: flex; gap: 14px;
    padding: 14px 16px;
    background: rgba(20, 32, 68, 0.5);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-left: 4px solid #3B82F6;
    border-radius: 12px;
    transition: 0.15s;
    position: relative;
}
.alert-item:hover { background: rgba(20, 32, 68, 0.7); }
.alert-item.is-reviewed { opacity: 0.55; }

.alert-item.sev-low      { border-left-color: #60A5FA; }
.alert-item.sev-medium   { border-left-color: #F59E0B; }
.alert-item.sev-high     { border-left-color: #F97316; }
.alert-item.sev-critical {
    border-left-color: #EF4444;
    background: rgba(239, 68, 68, 0.06);
}

.alert-icon {
    width: 40px; height: 40px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; color: #fff; flex-shrink: 0;
}
.sev-low      .alert-icon { background: linear-gradient(135deg, #3B82F6, #60A5FA); }
.sev-medium   .alert-icon { background: linear-gradient(135deg, #F59E0B, #FBBF24); }
.sev-high     .alert-icon { background: linear-gradient(135deg, #F97316, #FB923C); }
.sev-critical .alert-icon {
    background: linear-gradient(135deg, #EF4444, #F87171);
    animation: pulse 1.6s infinite;
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); }
    50%      { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
}

.alert-body { flex: 1; min-width: 0; }
.alert-head {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 6px;
}
.alert-title {
    font-size: 0.85rem; font-weight: 800; color: #fff;
    letter-spacing: -0.1px;
}

.sev-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.58rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    border: 1px solid;
}
.sev-pill.low      { background: rgba(96, 165, 250, 0.15); color: #93C5FD; border-color: rgba(96, 165, 250, 0.35); }
.sev-pill.medium   { background: rgba(245, 158, 11, 0.15); color: #FCD34D; border-color: rgba(245, 158, 11, 0.35); }
.sev-pill.high     { background: rgba(249, 115, 22, 0.15); color: #FDBA74; border-color: rgba(249, 115, 22, 0.35); }
.sev-pill.critical { background: rgba(239, 68, 68, 0.15);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }

.alert-user {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.72rem; font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    padding: 2px 8px;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 999px;
}
.alert-user i { color: #93C5FD; font-size: 0.6rem; }

.alert-details {
    font-size: 0.76rem; line-height: 1.55;
    color: rgba(255, 255, 255, 0.75);
    margin-bottom: 8px; font-weight: 500;
    overflow-wrap: anywhere;
}
.alert-details code {
    background: rgba(96, 165, 250, 0.12);
    padding: 1px 6px;
    border-radius: 5px;
    font-size: 0.7rem;
    color: #93C5FD;
    font-family: 'Source Sans 3', monospace;
}

.alert-meta {
    display: flex; flex-wrap: wrap; gap: 12px;
    font-size: 0.66rem;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
}
.alert-meta span { display: inline-flex; align-items: center; gap: 4px; }
.alert-meta i { color: #93C5FD; font-size: 0.62rem; }

.alert-note {
    display: flex; gap: 6px; align-items: flex-start;
    margin-top: 8px;
    padding: 8px 10px;
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.20);
    border-radius: 8px;
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    line-height: 1.5;
}
.alert-note i { color: #6EE7B7; font-size: 0.68rem; margin-top: 2px; flex-shrink: 0; }

.alert-actions {
    display: flex; flex-direction: column; gap: 6px;
    flex-shrink: 0; align-self: flex-start;
    min-width: 130px;
}

.act-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 11px;
    border-radius: 999px;
    font-size: 0.68rem; font-weight: 800;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    white-space: nowrap;
    transition: 0.15s;
    justify-content: center;
}
.act-btn i { font-size: 0.62rem; }
.act-btn.review { background: rgba(16, 185, 129, 0.12); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.35); }
.act-btn.review:hover { background: rgba(16, 185, 129, 0.22); color: #fff; }
.act-btn.view   { background: rgba(96, 165, 250, 0.12); color: #93C5FD; border-color: rgba(96, 165, 250, 0.30); }
.act-btn.view:hover { background: rgba(96, 165, 250, 0.22); color: #fff; }
.act-btn.gavel  { background: rgba(245, 158, 11, 0.12); color: #FCD34D; border-color: rgba(245, 158, 11, 0.32); }
.act-btn.gavel:hover { background: rgba(245, 158, 11, 0.22); color: #fff; }
.act-btn.trash  { background: rgba(239, 68, 68, 0.10); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.30); }
.act-btn.trash:hover { background: rgba(239, 68, 68, 0.22); color: #fff; }

/* Empty state */
.empty-state {
    padding: 60px 20px !important;
    text-align: center;
}
.empty-state i {
    font-size: 30px !important; margin-bottom: 14px !important;
    display: block; color: #93C5FD; opacity: 0.5;
}
.empty-state h4 {
    font-size: 0.95rem !important; margin-bottom: 5px !important;
    color: #fff; font-weight: 800;
}
.empty-state p {
    font-size: 0.78rem !important;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 16px;
}

/* ============================================================
   INVESTIGATION MODAL (matches approved-accounts.php)
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
    display: flex; flex-direction: column;
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
    font-size: 0.85rem; color: #fff; flex-shrink: 0;
}
.inv-modal-title-icon.warn   { background: linear-gradient(135deg, #F59E0B, #FBBF24); }
.inv-modal-title-icon.danger { background: linear-gradient(135deg, #EF4444, #F87171); }
.inv-modal-close {
    width: 32px; height: 32px; border-radius: 9px;
    background: transparent;
    border: 1px solid rgba(120, 160, 255, 0.20);
    color: rgba(255, 255, 255, 0.7);
    cursor: pointer;
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
    overflow-y: auto; flex: 1;
    scrollbar-width: thin;
    scrollbar-color: rgba(96, 165, 250, 0.25) transparent;
}
.inv-modal-body::-webkit-scrollbar { width: 6px; }
.inv-modal-body::-webkit-scrollbar-thumb {
    background: rgba(96, 165, 250, 0.25);
    border-radius: 6px;
}
.inv-target {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: rgba(120, 160, 255, 0.06);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    margin-bottom: 18px;
}
.inv-target-avatar {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.85rem;
    flex-shrink: 0;
}
.inv-target-info { min-width: 0; }
.inv-target-name {
    font-weight: 800; font-size: 0.86rem; color: #fff;
    margin-bottom: 3px;
}
.inv-target-email {
    font-size: 0.74rem; color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
}
.inv-step { margin-bottom: 18px; }
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
    font-size: 0.62rem; font-weight: 800; flex-shrink: 0;
}
.inv-field { margin-bottom: 12px; }
.inv-label {
    display: block;
    font-size: 0.74rem; font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 6px;
}
.inv-label .req { color: #FCA5A5; margin-left: 2px; }
.inv-input,
.inv-select,
.inv-textarea {
    width: 100%; padding: 10px 12px;
    background: rgba(20, 32, 68, 0.7);
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 10px;
    color: #fff;
    font-family: inherit;
    font-size: 0.8rem;
    outline: none; transition: 0.2s;
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
    transition: 0.15s; text-align: center;
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
.inv-choice input[value="warning"]:checked ~ .inv-choice-icon { background: rgba(245, 158, 11, 0.20); color: #FCD34D; }
.inv-choice input[value="suspend"]:checked ~ .inv-choice-icon { background: rgba(245, 158, 11, 0.20); color: #FCD34D; }
.inv-choice input[value="deactivate"]:checked ~ .inv-choice-icon { background: rgba(239, 68, 68, 0.20); color: #FCA5A5; }
.inv-choice-label {
    font-size: 0.72rem; font-weight: 800; color: #fff;
}
.inv-choice-desc {
    font-size: 0.6rem; color: rgba(255, 255, 255, 0.55);
    font-weight: 600; line-height: 1.4;
}
.inv-confirm {
    display: flex; gap: 10px;
    padding: 12px 14px;
    background: rgba(239, 68, 68, 0.08);
    border: 1.5px solid rgba(239, 68, 68, 0.28);
    border-radius: 12px;
    cursor: pointer; transition: 0.15s;
}
.inv-confirm:hover { background: rgba(239, 68, 68, 0.12); }
.inv-confirm input {
    width: 18px; height: 18px; margin-top: 2px;
    accent-color: #EF4444; cursor: pointer; flex-shrink: 0;
}
.inv-confirm-text {
    font-size: 0.74rem; color: rgba(255, 255, 255, 0.85);
    font-weight: 600; line-height: 1.5;
}
.inv-confirm-text strong { color: #FCA5A5; font-weight: 800; }
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
    font-size: 0.78rem; font-weight: 800;
    border: 1px solid; cursor: pointer;
    font-family: inherit; transition: 0.15s;
    text-decoration: none;
}
.inv-btn.cancel {
    background: rgba(20, 32, 68, 0.7);
    border-color: rgba(120, 160, 255, 0.25);
    color: rgba(255, 255, 255, 0.75);
}
.inv-btn.cancel:hover { background: rgba(120, 160, 255, 0.12); color: #fff; }
.inv-btn.submit {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    border-color: transparent; color: #fff;
}
.inv-btn.submit:hover { background: linear-gradient(135deg, #2563EB, #3B82F6); }

/* Review modal (smaller) */
.rev-modal-panel { max-width: 480px; }
.rev-modal-panel .inv-modal-body { padding: 20px; }

@media (max-width: 720px) {
    .alert-item { flex-direction: column; gap: 12px; }
    .alert-actions { align-self: stretch; flex-direction: row; flex-wrap: wrap; }
    .alert-actions .act-btn { flex: 1; min-width: 110px; }
    .inv-modal-panel { max-height: calc(100vh - 20px); border-radius: 14px; }
    .inv-modal-body { padding: 18px; }
    .inv-modal-head { padding: 14px 18px; }
    .inv-modal-footer { padding: 14px 18px; flex-direction: column-reverse; }
    .inv-modal-footer .inv-btn { width: 100%; justify-content: center; }
    .inv-choices { grid-template-columns: 1fr; }
    .inv-choice { flex-direction: row; text-align: left; gap: 10px; align-items: center; }
    .inv-choice-icon { margin: 0; }
}
</style>
CSS;

include '_header.php';

$flash = $_GET['msg'] ?? '';
$moderation_errors = $_SESSION['moderation_errors'] ?? [];
unset($_SESSION['moderation_errors']);
?>

<?php if ($flash === 'reviewed'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Alert marked as reviewed.</div>
<?php elseif ($flash === 'all_reviewed'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> All alerts marked as reviewed.</div>
<?php elseif ($flash === 'warning'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Formal warning issued and logged.</div>
<?php elseif ($flash === 'suspend'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Account suspended and user notified.</div>
<?php elseif ($flash === 'deactivate'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Account deactivated and user notified.</div>
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
        <strong>Automatic anomaly detection.</strong>
        These alerts fire when a user shows abnormal behavior (repeated failed logins, message flooding, job spamming, etc.).
        Review each alert, then either dismiss it or escalate to the full investigation workflow.
    </div>
</div>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="admin-stat-label">Unreviewed</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['unreviewed']); ?></div>
        <div class="admin-stat-sub">Needs your attention</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-fire"></i></div>
            <div class="admin-stat-label">High / Critical</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['critical']); ?></div>
        <div class="admin-stat-sub">Serious alerts</div>
    </div>
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="admin-stat-label">Today</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['today']); ?></div>
        <div class="admin-stat-sub">New today</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-list"></i></div>
            <div class="admin-stat-label">All Alerts</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="admin-stat-sub">Lifetime</div>
    </div>
</div>

<!-- ============ FILTERS ============ -->
<div class="card-panel aa-toolbar">
    <form method="GET" class="aa-filters">
        <div class="filter-field filter-search">
            <label class="form-label-sm">Search</label>
            <input type="text" name="q" class="form-input-sm"
                   value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="User email, title, or details...">
        </div>
        <div class="filter-field">
            <label class="form-label-sm">Severity</label>
            <select name="severity" class="form-select-sm-custom" onchange="this.form.submit()">
                <option value="">All Severities</option>
                <option value="critical" <?php echo $severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
                <option value="high"     <?php echo $severity === 'high'     ? 'selected' : ''; ?>>High</option>
                <option value="medium"   <?php echo $severity === 'medium'   ? 'selected' : ''; ?>>Medium</option>
                <option value="low"      <?php echo $severity === 'low'      ? 'selected' : ''; ?>>Low</option>
            </select>
        </div>
        <div class="filter-field">
            <label class="form-label-sm">Status</label>
            <select name="status" class="form-select-sm-custom" onchange="this.form.submit()">
                <option value="unreviewed" <?php echo $status === 'unreviewed' ? 'selected' : ''; ?>>Unreviewed</option>
                <option value="reviewed"   <?php echo $status === 'reviewed'   ? 'selected' : ''; ?>>Reviewed</option>
                <option value="all"        <?php echo $status === 'all'        ? 'selected' : ''; ?>>All</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-soft primary">
                <i class="fas fa-search"></i> Filter
            </button>
            <?php if ($search || $severity || $status !== 'unreviewed'): ?>
                <a href="moderation-alerts.php" class="btn-soft"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
            <?php if ($stats['unreviewed'] > 0): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Mark all alerts as reviewed?');">
                    <button type="submit" name="mark_all_reviewed" class="btn-soft success">
                        <i class="fas fa-check-double"></i> Mark All
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============ ALERTS ============ -->
<div class="table-panel alert-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-exclamation-triangle"></i></span>
            Moderation Alerts
            <span class="count-chip"><?php echo count($alerts); ?></span>
        </div>
        <div class="table-panel-sub">
            Showing <?php echo count($alerts); ?> of <?php echo $stats['total']; ?> alerts
        </div>
    </div>

    <?php if (count($alerts) > 0): ?>
        <div class="alert-list">
            <?php foreach ($alerts as $a):
                $ctx = $a['context_json'] ? json_decode($a['context_json'], true) : [];
                $is_reviewed = !empty($a['is_reviewed']);
                $sev = htmlspecialchars($a['severity']);
                $user_label = $a['user_email'] ?: "User #{$a['user_id']}";
                $role_label = $a['user_role'] ? ucfirst($a['user_role']) : 'Unknown';
            ?>
                <div class="alert-item sev-<?php echo $sev; ?> <?php echo $is_reviewed ? 'is-reviewed' : ''; ?>">

                    <!-- Icon -->
                    <div class="alert-icon">
                        <i class="fas <?php
                            switch ($a['severity']) {
                                case 'critical': echo 'fa-fire';       break;
                                case 'high':     echo 'fa-exclamation'; break;
                                case 'medium':   echo 'fa-exclamation-triangle'; break;
                                default:         echo 'fa-info-circle'; break;
                            }
                        ?>"></i>
                    </div>

                    <!-- Body -->
                    <div class="alert-body">
                        <div class="alert-head">
                            <span class="alert-title"><?php echo htmlspecialchars($a['title']); ?></span>
                            <span class="sev-pill <?php echo $sev; ?>">
                                <?php echo strtoupper($sev); ?>
                            </span>
                            <span class="alert-user">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($user_label); ?>
                                <?php if ($role_label !== 'Unknown'): ?>
                                    · <?php echo htmlspecialchars($role_label); ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if (!empty($a['details'])): ?>
                            <div class="alert-details">
                                <?php echo htmlspecialchars($a['details']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="alert-meta">
                            <span>
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($a['alert_type']); ?>
                            </span>
                            <span>
                                <i class="far fa-clock"></i>
                                <?php echo timeAgo($a['created_at']); ?>
                            </span>
                            <?php if (!empty($ctx['ip'])): ?>
                                <span>
                                    <i class="fas fa-network-wired"></i>
                                    <?php echo htmlspecialchars($ctx['ip']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ctx['count'])): ?>
                                <span>
                                    <i class="fas fa-hashtag"></i>
                                    <?php echo (int)$ctx['count']; ?>× in 1h
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ctx['keyword'])): ?>
                                <span>
                                    <i class="fas fa-search"></i>
                                    keyword: <code><?php echo htmlspecialchars($ctx['keyword']); ?></code>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_reviewed && !empty($a['review_note'])): ?>
                            <div class="alert-note">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <strong>Reviewed</strong>
                                    <?php if (!empty($a['reviewer_email'])): ?>
                                        by <?php echo htmlspecialchars($a['reviewer_email']); ?>
                                    <?php endif; ?>
                                    — <?php echo htmlspecialchars($a['review_note']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="alert-actions">
                        <?php if (!$is_reviewed): ?>
                            <button type="button" class="act-btn review"
                                    onclick="openReview(<?php echo (int)$a['id']; ?>, <?php echo htmlspecialchars(json_encode($a['title']), ENT_QUOTES); ?>)">
                                <i class="fas fa-check"></i> Review
                            </button>
                        <?php endif; ?>

                        <?php if (!empty($a['user_id'])): ?>
                            <button type="button" class="act-btn gavel"
                                    onclick="openInvestigation(<?php echo (int)$a['id']; ?>, <?php echo (int)$a['user_id']; ?>, <?php echo htmlspecialchars(json_encode($user_label), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($a['user_role'] ?? ''), ENT_QUOTES); ?>)">
                                <i class="fas fa-gavel"></i> Take Action
                            </button>
                            <a href="/navora3/admin/user-details.php?id=<?php echo (int)$a['user_id']; ?>"
                               class="act-btn view" target="_blank">
                                <i class="fas fa-eye"></i> View User
                            </a>
                        <?php endif; ?>

                        <a href="?delete=<?php echo (int)$a['id']; ?>"
                           class="act-btn trash"
                           onclick="return confirm('Delete this alert?')">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-shield-alt"></i>
            <h4>No alerts
                <?php echo $status === 'unreviewed' ? 'to review' : 'found'; ?>
            </h4>
            <p>
                <?php if ($status === 'unreviewed'): ?>
                    Great — the platform is quiet. New alerts will appear here automatically.
                <?php else: ?>
                    Try adjusting your filters to see more results.
                <?php endif; ?>
            </p>
            <?php if ($status !== 'unreviewed' || $severity || $search): ?>
                <a href="moderation-alerts.php" class="btn-soft primary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ============ REVIEW MODAL ============ -->
<div class="inv-modal" id="revModal">
    <div class="inv-modal-backdrop" onclick="closeReview()"></div>
    <form method="POST" class="inv-modal-panel rev-modal-panel">
        <div class="inv-modal-head">
            <div class="inv-modal-title">
                <span class="inv-modal-title-icon warn">
                    <i class="fas fa-check"></i>
                </span>
                <span>Mark Alert as Reviewed</span>
            </div>
            <button type="button" class="inv-modal-close" onclick="closeReview()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="inv-modal-body">
            <input type="hidden" name="alert_id" id="revAlertId">
            <p style="font-size:0.78rem; color: rgba(255,255,255,0.7); margin-bottom:14px; line-height:1.55;">
                <strong id="revAlertTitle" style="color:#fff;"></strong>
                <br>
                You're about to mark this alert as reviewed. Add an optional note explaining your decision.
            </p>
            <div class="inv-field">
                <label class="inv-label">Review note <span style="color:rgba(255,255,255,0.45); font-weight:500;">(optional)</span></label>
                <textarea name="review_note" class="inv-textarea"
                          placeholder="e.g. Confirmed as normal user activity, no further action needed."></textarea>
            </div>
        </div>
        <div class="inv-modal-footer">
            <button type="button" class="inv-btn cancel" onclick="closeReview()">Cancel</button>
            <button type="submit" name="mark_reviewed" class="inv-btn submit">
                <i class="fas fa-check"></i> Mark Reviewed
            </button>
        </div>
    </form>
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
            <div class="inv-target">
                <div class="inv-target-avatar" id="invInitial">A</div>
                <div class="inv-target-info">
                    <div class="inv-target-name" id="invName">—</div>
                    <div class="inv-target-email" id="invEmail">—</div>
                </div>
            </div>

            <input type="hidden" name="user_id" id="invUserId">
            <input type="hidden" name="source_alert_id" id="invAlertId">

            <!-- Step 1 -->
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
                    <span class="inv-hint">Recorded in the audit log and shown to the user.</span>
                </div>

                <div class="inv-field">
                    <label class="inv-label">Evidence / Reference <span style="color: rgba(255,255,255,0.5); font-weight: 500;">(optional)</span></label>
                    <input type="text" name="evidence" class="inv-input"
                           placeholder="e.g. Alert #42, report #17, message ID">
                    <span class="inv-hint">Links to reports, messages, or alerts that support this decision.</span>
                </div>
            </div>

            <!-- Step 2 -->
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
                        <div class="inv-choice-desc">Permanent disable. Serious violations only.</div>
                    </label>
                </div>
            </div>

            <!-- Step 3 -->
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
            <button type="button" class="inv-btn cancel" onclick="closeInvestigation()">Cancel</button>
            <button type="submit" class="inv-btn submit">
                <i class="fas fa-gavel"></i> Apply Decision
            </button>
        </div>
    </form>
</div>

<script>
/* ============================================================
   Review modal
   ============================================================ */
function openReview(alertId, title) {
    document.getElementById('revAlertId').value = alertId;
    document.getElementById('revAlertTitle').textContent = title;
    document.getElementById('revModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeReview() {
    document.getElementById('revModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* ============================================================
   Investigation modal
   ============================================================ */
function openInvestigation(alertId, userId, userLabel, userRole) {
    document.getElementById('invAlertId').value = alertId;
    document.getElementById('invUserId').value = userId;
    document.getElementById('invName').textContent  = userLabel;
    document.getElementById('invEmail').textContent = userRole ? ('Role: ' + userRole) : '';
    document.getElementById('invInitial').textContent = (userLabel || 'U').trim().charAt(0).toUpperCase();

    const form = document.getElementById('invForm');
    form.reset();
    document.getElementById('invUserId').value  = userId;
    document.getElementById('invAlertId').value = alertId;

    /* Auto-check the "confirm" for suspend/deactivate later; the checkbox is required for those */
    const icon = document.getElementById('invIcon');
    icon.className = 'inv-modal-title-icon warn';
    icon.innerHTML = '<i class="fas fa-gavel"></i>';

    document.getElementById('invModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeInvestigation() {
    document.getElementById('invModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* Update modal icon when action is chosen */
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
    const action   = form.querySelector('input[name="moderation_action"]:checked');
    const category = form.querySelector('select[name="category"]').value;
    const desc     = form.querySelector('textarea[name="description"]').value.trim();
    const confirm  = form.querySelector('#invConfirm').checked;

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

    const actionLabel = action.value === 'warning' ? 'issue a warning'
                      : action.value === 'suspend' ? 'suspend this account'
                      : 'deactivate this account';
    return confirm(
        'Are you sure you want to ' + actionLabel + '?\n\n'
        + 'This will be logged and the user will be notified with appeal instructions.'
    );
}

/* ESC closes both modals */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeReview();
        closeInvestigation();
    }
});
</script>

<?php include '_footer.php'; ?>