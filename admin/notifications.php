<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$user_id = $_SESSION['user_id'];

if (isset($_GET['read']) && $_GET['read'] === 'all') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: /navora3/admin/notifications.php');
    exit;
}

if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([intval($_GET['read']), $user_id]);
    header('Location: /navora3/admin/notifications.php');
    exit;
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([intval($_GET['delete']), $user_id]);
    header('Location: /navora3/admin/notifications.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$where = 'user_id = ?';
$params = [$user_id];

if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($filter === 'read') {
    $where .= ' AND is_read = 1';
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_count = (int) $stmt->fetchColumn();

$page_title = 'Notifications';
$page_subtitle = 'Stay updated with admin alerts and system notices.';
$page_icon = 'fa-bell';
$page_theme = 'blue';
$header_script = 'Latest updates<br>for you';
$breadcrumb = 'Admin / Notifications';

/* ✨ Custom hero background */
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<div class="notification-page">
    <div class="card-panel notification-panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">
                    <i class="fas fa-bell"></i> Notifications
                </div>
                <div class="panel-sub">
                    <?php if ($unread_count > 0): ?>
                        You have <strong><?php echo $unread_count; ?></strong> unread notification<?php echo $unread_count === 1 ? '' : 's'; ?>
                    <?php else: ?>
                        You're all caught up! 🎉
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-actions">
                <?php if ($unread_count > 0): ?>
                    <a href="/navora3/admin/notifications.php?read=all"
                       class="btn-soft primary"
                       onclick="return confirm('Mark all notifications as read?')">
                        <i class="fas fa-check-double"></i> Mark all read
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-row">
            <a href="/navora3/admin/notifications.php?filter=all"
               class="filter-link <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-inbox"></i> All
                <span class="filter-count"><?php echo $total_count; ?></span>
            </a>
            <a href="/navora3/admin/notifications.php?filter=unread"
               class="filter-link <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                <i class="fas fa-circle"></i> Unread
                <?php if ($unread_count > 0): ?>
                    <span class="filter-count alert"><?php echo $unread_count; ?></span>
                <?php else: ?>
                    <span class="filter-count">0</span>
                <?php endif; ?>
            </a>
            <a href="/navora3/admin/notifications.php?filter=read"
               class="filter-link <?php echo $filter === 'read' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Read
                <span class="filter-count"><?php echo $total_count - $unread_count; ?></span>
            </a>
        </div>

        <?php if (count($notifications) > 0): ?>
            <div class="notification-list">
                <?php foreach ($notifications as $n):
                    $is_unread = empty($n['is_read']);
                ?>
                    <div class="notification-item <?php echo $is_unread ? 'unread' : ''; ?>">
                        <div class="notif-icon">
                            <i class="fas fa-bell"></i>
                            <?php if ($is_unread): ?>
                                <span class="notif-pulse"></span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-body">
                            <div class="notif-header">
                                <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                                <?php if ($is_unread): ?>
                                    <span class="notif-dot"></span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
                            <div class="notif-meta">
                                <span>
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($n['created_at'])); ?>
                                </span>
                                <span class="notif-status">
                                    <?php if ($is_unread): ?>
                                        <i class="fas fa-circle" style="color:#93C5FD;font-size:0.5rem;"></i> Unread
                                    <?php else: ?>
                                        <i class="fas fa-check" style="color:#6EE7B7;font-size:0.6rem;"></i> Read
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="notif-actions">
                            <?php if ($is_unread): ?>
                                <a href="/navora3/admin/notifications.php?read=<?php echo (int)$n['id']; ?>"
                                   class="btn-soft small" title="Mark as read">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php endif; ?>
                            <a href="/navora3/admin/notifications.php?delete=<?php echo (int)$n['id']; ?>"
                               class="btn-soft small danger"
                               title="Delete"
                               onclick="return confirm('Delete this notification?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h4>No notifications found</h4>
                <p>
                    <?php if ($filter === 'unread'): ?>
                        You have no unread notifications.
                    <?php elseif ($filter === 'read'): ?>
                        You haven't read any notifications yet.
                    <?php else: ?>
                        There are no notifications in your inbox yet.
                    <?php endif; ?>
                </p>
                <?php if ($filter !== 'all'): ?>
                    <a href="/navora3/admin/notifications.php?filter=all" class="btn-soft">
                        <i class="fas fa-arrow-left"></i> View all notifications
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ============================================================
   NOTIFICATIONS PAGE — COMPACT + FLAT DARK GLASS
   ============================================================ */

.notification-page { padding: 0; }

/* ---------- Panel head ---------- */
.notification-panel {
    overflow: hidden;
}

.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    padding: 16px 22px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    background: rgba(120, 160, 255, 0.04);
}

.panel-title {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: 1.05rem !important;
    font-weight: 700;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.2px;
}
.panel-title i {
    width: 32px !important;
    height: 32px !important;
    border-radius: 9px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem !important;
    box-shadow: none;
}
.panel-sub {
    margin-top: 4px;
    font-size: 0.76rem !important;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
}
.panel-sub strong {
    color: #93C5FD;
    font-weight: 800;
}

.panel-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.panel-actions .btn-soft {
    padding: 7px 14px !important;
    font-size: 0.76rem !important;
}

/* ---------- Filter row ---------- */
.filter-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding: 12px 22px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.08);
}

.filter-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 32px !important;
    padding: 0 12px !important;
    border-radius: 999px;
    border: 1px solid rgba(120, 160, 255, 0.18);
    background: rgba(20, 32, 68, 0.40);
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.74rem !important;
    font-weight: 700;
    transition: 0.2s;
}
.filter-link i {
    font-size: 0.68rem !important;
    color: rgba(255, 255, 255, 0.55);
    transition: 0.2s;
}
.filter-link:hover {
    border-color: rgba(96, 165, 250, 0.40);
    background: rgba(96, 165, 250, 0.10);
    color: #ffffff;
}
.filter-link:hover i { color: #93C5FD; }

.filter-link.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    border-color: transparent;
    color: #ffffff;
    box-shadow: none;
}
.filter-link.active i { color: #ffffff; }

.filter-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px !important;
    height: 18px !important;
    padding: 0 6px !important;
    background: rgba(120, 160, 255, 0.15);
    color: #ffffff;
    font-size: 0.62rem !important;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 2px;
}
.filter-link.active .filter-count {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}
.filter-count.alert {
    background: #EF4444;
    color: #ffffff;
}
.filter-link.active .filter-count.alert {
    background: rgba(255, 255, 255, 0.30);
    color: #ffffff;
}

/* ---------- Notification list ---------- */
.notification-list {
    padding: 12px 22px 20px !important;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.notification-item {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    background: rgba(20, 32, 68, 0.30);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    padding: 12px 14px !important;
    transition: 0.18s;
    position: relative;
}
.notification-item:hover {
    border-color: rgba(96, 165, 250, 0.30);
    background: rgba(20, 32, 68, 0.45);
}
.notification-item.unread {
    border-color: rgba(96, 165, 250, 0.25);
    background: rgba(96, 165, 250, 0.06);
}
.notification-item.unread::before {
    content: '';
    position: absolute;
    left: 0; top: 10px; bottom: 10px;
    width: 3px;
    background: linear-gradient(180deg, #3B82F6, #60A5FA);
    border-radius: 0 3px 3px 0;
}

/* Notif icon */
.notif-icon {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem !important;
    flex-shrink: 0;
    position: relative;
    box-shadow: none;
}
.notification-item:not(.unread) .notif-icon {
    background: rgba(96, 165, 250, 0.15);
    color: rgba(255, 255, 255, 0.75);
}
.notif-icon i { color: inherit !important; }

.notif-pulse {
    position: absolute;
    top: -3px; right: -3px;
    width: 10px; height: 10px;
    background: #EF4444;
    border-radius: 50%;
    border: 2px solid #060B1F;
}

/* Body */
.notif-body { flex: 1; min-width: 0; }
.notif-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.notif-title {
    font-size: 0.84rem !important;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.1px;
}
.notif-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #3B82F6;
    display: inline-block;
}
.notif-message {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.76rem !important;
    line-height: 1.55;
    margin-bottom: 8px;
    font-weight: 500;
}
.notif-meta {
    font-size: 0.68rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.notif-meta span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.notif-meta i {
    color: #93C5FD;
    font-size: 0.64rem !important;
}
.notif-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Actions */
.notif-actions {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-shrink: 0;
}
.btn-soft.small {
    padding: 5px 10px !important;
    font-size: 0.68rem !important;
    min-width: auto;
    min-height: 28px;
}

/* ---------- Empty state ---------- */
.empty-state {
    padding: 50px 24px 40px !important;
    text-align: center;
    color: rgba(255, 255, 255, 0.55);
}
.empty-state i {
    font-size: 28px !important;
    margin-bottom: 14px !important;
    display: block;
    color: #93C5FD;
    opacity: 0.5;
}
.empty-state h4 {
    font-size: 0.95rem !important;
    color: #ffffff;
    margin-bottom: 6px !important;
    font-weight: 800;
}
.empty-state p {
    font-size: 0.78rem !important;
    margin-bottom: 16px !important;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
}
.empty-state .btn-soft {
    padding: 8px 16px !important;
    font-size: 0.78rem !important;
}

/* ---------- Responsive ---------- */
@media (max-width: 640px) {
    .panel-head { padding: 14px 16px !important; }
    .filter-row { padding: 10px 16px !important; }
    .notification-list { padding: 10px 16px 16px !important; gap: 8px; }

    .notification-item {
        flex-direction: column;
        padding: 12px !important;
        gap: 10px;
    }
    .notification-item.unread::before {
        top: 0; left: 0; right: 0;
        bottom: auto;
        width: auto; height: 3px;
        border-radius: 12px 12px 0 0;
    }
    .notif-actions {
        width: 100%;
        justify-content: flex-end;
        padding-top: 8px;
        border-top: 1px solid rgba(120, 160, 255, 0.08);
    }
    .notif-meta { font-size: 0.64rem !important; gap: 10px; }
    .panel-actions { width: 100%; }
    .panel-actions .btn-soft { width: 100%; justify-content: center; }
}
</style>

<?php include '_footer.php'; ?>