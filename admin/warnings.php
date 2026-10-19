<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Warnings & Compliance';
$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   ENSURE TABLES EXIST
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS account_warnings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            admin_user_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            severity ENUM('low','medium','high') DEFAULT 'medium',
            status ENUM('pending','appealed','resolved','escalated') DEFAULT 'pending',
            seeker_response TEXT DEFAULT NULL,
            evidence_file VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}

/* ============================================================
   HANDLE ACTIONS (Resolve Warning)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_warning'])) {
    $warning_id = intval($_POST['warning_id']);
    $note = trim($_POST['admin_note'] ?? 'Resolved by admin.');

    try {
        $pdo->prepare("UPDATE account_warnings SET status = 'resolved' WHERE id = ?")->execute([$warning_id]);
        
        // Log the action
        logAdminAction($pdo, $my_id, 'warning.resolved', 'warning', $warning_id, $note);
        
        // Optional: Notify user (if you have a notifications table)
        $stmt = $pdo->prepare("SELECT user_id FROM account_warnings WHERE id = ?");
        $stmt->execute([$warning_id]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, 'Warning Resolved', ?, 0)")
                ->execute([$uid, 'Your warning appeal has been reviewed and resolved.']);
        }

    } catch (PDOException $e) { }
    
    header('Location: warnings.php?msg=resolved');
    exit;
}

/* ============================================================
   FETCH WARNINGS
   ============================================================ */
$warnings = [];
try {
    $stmt = $pdo->query("
        SELECT w.*, 
               u.email AS user_email, 
               u.role AS user_role,
               u.status AS user_status
        FROM account_warnings w
        JOIN users u ON w.user_id = u.id
        ORDER BY 
            CASE w.status 
                WHEN 'appealed' THEN 1 
                WHEN 'pending' THEN 2 
                WHEN 'resolved' THEN 3 
                ELSE 4 
            END,
            w.created_at DESC
    ");
    $warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $warnings = []; }

/* ============================================================
   STATS
   ============================================================ */
$total_warnings = count($warnings);
$pending_warnings = 0;
$appealed_warnings = 0;
$resolved_warnings = 0;

foreach ($warnings as $w) {
    if ($w['status'] === 'pending') $pending_warnings++;
    elseif ($w['status'] === 'appealed') $appealed_warnings++;
    elseif ($w['status'] === 'resolved') $resolved_warnings++;
}

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Review formal warnings issued to users and handle appeals.';
$breadcrumb     = 'Admin / Warnings';
$extra_css      = <<<'CSS'
<style>
.warning-card {
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.warning-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
}
.warning-user {
    display: flex;
    align-items: center;
    gap: 12px;
}
.warning-avatar {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
}
.warning-title {
    font-size: 1rem; font-weight: 800; color: #fff;
    margin-bottom: 4px;
}
.warning-meta {
    font-size: 0.75rem; color: rgba(255,255,255,0.6); font-weight: 600;
}
.warning-body {
    background: rgba(10, 19, 48, 0.5);
    border-radius: 12px;
    padding: 16px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    line-height: 1.6;
    margin-bottom: 16px;
}
.warning-appeal {
    background: rgba(96, 165, 250, 0.08);
    border: 1px solid rgba(96, 165, 250, 0.25);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}
.warning-appeal-title {
    font-size: 0.75rem; font-weight: 800; color: #93C5FD;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
}
.warning-appeal-text {
    font-size: 0.85rem; color: #fff; line-height: 1.6; margin-bottom: 10px;
}
.warning-actions {
    display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px;
}
</style>
CSS;

include '_header.php';
?>

<div class="admin-stats">
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="admin-stat-label">Total Warnings</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_warnings); ?></div>
        <div class="admin-stat-sub">All-time issued</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-label">Pending Appeal</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($appealed_warnings); ?></div>
        <div class="admin-stat-sub">Needs review</div>
    </div>
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-paper-plane"></i></div>
            <div class="admin-stat-label">Awaiting Response</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($pending_warnings); ?></div>
        <div class="admin-stat-sub">User has not replied</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Resolved</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($resolved_warnings); ?></div>
        <div class="admin-stat-sub">Closed cases</div>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'resolved'): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> Warning marked as resolved.</div>
<?php endif; ?>

<div class="card-panel">
    <div class="card-panel-head">
        <div class="card-panel-title">
            <span class="card-panel-title-icon" style="background: linear-gradient(135deg, #EF4444, #F87171);"><i class="fas fa-gavel"></i></span>
            Compliance & Warnings
        </div>
    </div>
    
    <div style="padding: 20px;">
        <?php if (count($warnings) > 0): ?>
            <?php foreach ($warnings as $w): 
                $severity_color = '#FCD34D';
                if ($w['severity'] === 'high') $severity_color = '#FCA5A5';
                if ($w['severity'] === 'low') $severity_color = '#93C5FD';
                
                $status_class = 'pending';
                if ($w['status'] === 'resolved') $status_class = 'active';
                if ($w['status'] === 'appealed') $status_class = 'reviewed';
            ?>
                <div class="warning-card">
                    <div class="warning-header">
                        <div class="warning-user">
                            <div class="warning-avatar"><i class="fas fa-user-shield"></i></div>
                            <div>
                                <div class="warning-title"><?php echo htmlspecialchars($w['title']); ?></div>
                                <div class="warning-meta">
                                    Issued to: <strong><?php echo htmlspecialchars($w['user_email']); ?></strong> 
                                    (<?php echo ucfirst($w['user_role']); ?>) 
                                    · <?php echo date('M d, Y', strtotime($w['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span class="badge-soft" style="background: <?php echo $severity_color; ?>15; color: <?php echo $severity_color; ?>; border-color: <?php echo $severity_color; ?>35;">
                                <?php echo ucfirst($w['severity']); ?> Severity
                            </span>
                            <span class="badge-soft <?php echo $status_class; ?>">
                                <?php echo ucfirst($w['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="warning-body">
                        <?php echo nl2br(htmlspecialchars($w['message'])); ?>
                    </div>

                    <?php if (!empty($w['seeker_response']) && in_array($w['status'], ['appealed', 'resolved'])): ?>
                        <div class="warning-appeal">
                            <div class="warning-appeal-title">
                                <i class="fas fa-reply"></i> User's Appeal / Response
                            </div>
                            <div class="warning-appeal-text">
                                "<?php echo nl2br(htmlspecialchars($w['seeker_response'])); ?>"
                            </div>
                            <?php if (!empty($w['evidence_file']) && file_exists('../' . $w['evidence_file'])): ?>
                                <a href="/navora3/<?php echo htmlspecialchars($w['evidence_file']); ?>" target="_blank" class="btn-soft" style="font-size: 0.75rem; padding: 4px 10px;">
                                    <i class="fas fa-paperclip"></i> View Attached Evidence
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="warning-actions">
                        <?php if ($w['status'] !== 'resolved'): ?>
                            <form method="POST" onsubmit="return confirm('Mark this warning as resolved? This will close the case.');">
                                <input type="hidden" name="warning_id" value="<?php echo (int)$w['id']; ?>">
                                <input type="hidden" name="admin_note" value="Resolved from warnings page.">
                                <button type="submit" name="resolve_warning" class="btn-soft success">
                                    <i class="fas fa-check-double"></i> Mark as Resolved
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="font-size: 0.8rem; color: #6EE7B7; font-weight: 600;">
                                <i class="fas fa-check-circle"></i> Case Closed
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-shield-alt" style="color: #6EE7B7; opacity: 0.5; font-size: 40px; margin-bottom: 16px; display: block;"></i>
                <h4>No Warnings Issued</h4>
                <p>When you issue warnings from the User Management or Approved Accounts pages, they will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '_footer.php'; ?>