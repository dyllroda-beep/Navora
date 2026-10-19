<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Send Announcement';
$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   AUTO-CREATE announcements TABLE
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            audience ENUM('all','seekers','employers','admins') DEFAULT 'all',
            sent_by INT DEFAULT NULL,
            recipient_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_audience (audience)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { }

/* ============================================================
   HANDLE SEND
   ============================================================ */
$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_announcement'])) {
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $audience = $_POST['audience'] ?? 'all';

    if ($title === '')  $errors[] = 'Title is required.';
    if ($body === '')   $errors[] = 'Message body is required.';
    if (!in_array($audience, ['all','seekers','employers','admins'], true)) $audience = 'all';

    if (empty($errors)) {
        try {
            /* Build recipient query */
            $sql = "SELECT id FROM users WHERE 1=1";
            $params = [];
            if ($audience === 'seekers') {
                $sql .= " AND role = 'seeker'";
            } elseif ($audience === 'employers') {
                $sql .= " AND role = 'employer'";
            } elseif ($audience === 'admins') {
                $sql .= " AND role = 'admin'";
            } else {
                $sql .= " AND role != 'admin'";
            }

            $stmt = $pdo->query($sql);
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

            /* Insert notifications for all recipients */
            if (count($recipients) > 0) {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                foreach ($recipients as $uid) {
                    $ins->execute([$uid, $title, $body]);
                }
                $pdo->commit();
            }

            /* Log the announcement itself */
            $stmt = $pdo->prepare("
                INSERT INTO announcements (title, body, audience, sent_by, recipient_count)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $body, $audience, $my_id, count($recipients)]);

            logAdminAction($pdo, $my_id, 'announcement.sent', 'announcement', (int)$pdo->lastInsertId(),
                "Sent to {$audience} (" . count($recipients) . " recipients)");

            $success = "Announcement sent to " . count($recipients) . " recipient(s).";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not send announcement: ' . $e->getMessage();
        }
    }
}

/* ============================================================
   FETCH PREVIOUS ANNOUNCEMENTS
   ============================================================ */
$announcements = [];
try {
    $stmt = $pdo->query("
        SELECT a.*, u.email AS sender_email
        FROM announcements a
        LEFT JOIN users u ON u.id = a.sent_by
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $announcements = []; }

/* ============================================================
   STATS
   ============================================================ */
$stats = [
    'total_seekers'   => 0,
    'total_employers' => 0,
    'total_announced' => 0,
    'last_sent'       => null,
];
try {
    $stats['total_seekers']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='seeker'")->fetchColumn();
    $stats['total_employers'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='employer'")->fetchColumn();
    $stats['total_announced'] = (int)$pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    $last = $pdo->query("SELECT created_at FROM announcements ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $stats['last_sent'] = $last ?: null;
} catch (PDOException $e) { }

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

.announcement-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    gap: 18px;
    align-items: start;
}
@media (max-width: 992px) {
    .announcement-layout { grid-template-columns: 1fr; }
}

.compose-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px 20px 20px;
}
.form-group { display: flex; flex-direction: column; }
.form-group .required { color: #FCA5A5; font-weight: 800; margin-left: 2px; }
.form-hint {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.55);
    margin-top: 5px;
    display: flex; align-items: center; gap: 5px;
    font-weight: 600;
}
.form-hint i { color: #93C5FD; font-size: 0.66rem !important; }
.compose-textarea { resize: vertical; min-height: 140px; line-height: 1.55; }
.btn-block { width: 100%; justify-content: center; padding: 11px 16px !important; font-size: 0.82rem !important; }

/* Announcement list */
.announcement-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 16px 16px;
    max-height: 620px;
    overflow-y: auto;
}
.announcement-list::-webkit-scrollbar { width: 5px; }
.announcement-list::-webkit-scrollbar-thumb { background: rgba(96, 165, 250, 0.25); border-radius: 4px; }

.announcement-item {
    background: rgba(20, 32, 68, 0.35);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    padding: 12px 14px;
    transition: 0.18s;
}
.announcement-item:hover {
    background: rgba(20, 32, 68, 0.50);
    border-color: rgba(96, 165, 250, 0.28);
}
.ann-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}
.ann-title {
    font-size: 0.84rem !important;
    font-weight: 800;
    color: #FFFFFF;
    line-height: 1.3;
    flex: 1; min-width: 0;
    overflow-wrap: anywhere;
}
.ann-body {
    font-size: 0.74rem !important;
    color: rgba(255, 255, 255, 0.7);
    line-height: 1.55;
    font-weight: 500;
    margin-bottom: 8px;
    overflow-wrap: anywhere;
}
.ann-meta {
    display: flex; gap: 12px; flex-wrap: wrap;
    font-size: 0.66rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
}
.ann-meta span { display: inline-flex; align-items: center; gap: 4px; }
.ann-meta i { color: #93C5FD; font-size: 0.62rem !important; }
</style>
CSS;

include '_header.php';
?>

<?php if ($success): ?>
    <div class="alert-soft success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert-soft danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user"></i></div>
            <div class="admin-stat-label">Job Seekers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_seekers']); ?></div>
        <div class="admin-stat-sub">Potential recipients</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-building"></i></div>
            <div class="admin-stat-label">Employers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_employers']); ?></div>
        <div class="admin-stat-sub">Potential recipients</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-bullhorn"></i></div>
            <div class="admin-stat-label">Announcements Sent</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_announced']); ?></div>
        <div class="admin-stat-sub">All-time</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-label">Last Sent</div>
        </div>
        <div class="admin-stat-value" style="font-size: 1rem !important;">
            <?php echo $stats['last_sent'] ? date('M d, Y', strtotime($stats['last_sent'])) : '—'; ?>
        </div>
        <div class="admin-stat-sub"><?php echo $stats['last_sent'] ? date('g:i A', strtotime($stats['last_sent'])) : 'No announcements yet'; ?></div>
    </div>
</div>

<!-- ============ LAYOUT ============ -->
<div class="announcement-layout">

    <!-- COMPOSE -->
    <div class="card-panel compose-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-paper-plane"></i></span>
                Compose Announcement
            </div>
        </div>

        <form method="POST" class="compose-form">
            <div class="form-group">
                <label class="form-label-sm">Title <span class="required">*</span></label>
                <input type="text" name="title" class="form-input-sm"
                       placeholder="e.g. Scheduled Maintenance on Dec 15"
                       maxlength="180" required>
            </div>

            <div class="form-group">
                <label class="form-label-sm">Audience <span class="required">*</span></label>
                <select name="audience" class="form-select-sm-custom" required>
                    <option value="all">Everyone (Seekers + Employers)</option>
                    <option value="seekers">Job Seekers Only</option>
                    <option value="employers">Employers Only</option>
                    <option value="admins">Admins Only</option>
                </select>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i>
                    Recipients get a notification instantly.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label-sm">Message <span class="required">*</span></label>
                <textarea name="body" class="form-input-sm compose-textarea"
                          placeholder="Write your announcement here..."
                          rows="8" required></textarea>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i>
                    Keep it short, clear, and actionable.
                </div>
            </div>

            <button type="submit" name="send_announcement" class="btn-soft primary btn-block"
                    onclick="return confirm('Send this announcement?');">
                <i class="fas fa-bullhorn"></i> Send Announcement
            </button>
        </form>
    </div>

    <!-- HISTORY -->
    <div class="card-panel history-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-history"></i></span>
                Recent Announcements
                <span class="count-chip"><?php echo count($announcements); ?></span>
            </div>
        </div>

        <?php if (count($announcements) > 0): ?>
            <div class="announcement-list">
                <?php foreach ($announcements as $a):
                    $audience_badge = 'info';
                    if ($a['audience'] === 'seekers')   $audience_badge = 'violet';
                    elseif ($a['audience'] === 'employers') $audience_badge = 'success';
                    elseif ($a['audience'] === 'admins')    $audience_badge = 'danger';
                ?>
                    <div class="announcement-item">
                        <div class="ann-head">
                            <div class="ann-title"><?php echo htmlspecialchars($a['title']); ?></div>
                            <span class="badge-soft <?php echo $audience_badge; ?>">
                                <?php echo ucfirst($a['audience']); ?>
                            </span>
                        </div>
                        <div class="ann-body"><?php echo nl2br(htmlspecialchars(mb_strimwidth($a['body'], 0, 200, '…'))); ?></div>
                        <div class="ann-meta">
                            <span><i class="fas fa-users"></i> <?php echo (int)$a['recipient_count']; ?> recipient(s)</span>
                            <span><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($a['sender_email'] ?? 'System'); ?></span>
                            <span><i class="far fa-clock"></i> <?php echo date('M d, Y · g:i A', strtotime($a['created_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bullhorn"></i>
                <h4>No announcements yet</h4>
                <p>Your sent announcements will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '_footer.php'; ?>