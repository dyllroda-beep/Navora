<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Support Center';

/* ============================================================
   AUTO-CREATE TABLES
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contact_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            subject VARCHAR(180) DEFAULT NULL,
            message TEXT NOT NULL,
            status ENUM('unread','read','replied','archived') DEFAULT 'unread',
            replied_by INT DEFAULT NULL,
            replied_at TIMESTAMP NULL DEFAULT NULL,
            reply_message TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
            status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
            assigned_to INT DEFAULT NULL,
            last_reply TEXT DEFAULT NULL,
            last_reply_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { /* ignore */ }

/* ============================================================
   HANDLE ACTIONS
   ============================================================ */
$success = '';
$errors  = [];
$my_id   = $_SESSION['user_id'] ?? 0;

/* ---- Contact: mark as read ---- */
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    try {
        $stmt = $pdo->prepare("UPDATE contact_messages SET status='read' WHERE id=? AND status='unread'");
        $stmt->execute([intval($_GET['mark_read'])]);
        header('Location: support.php?tab=messages'); exit;
    } catch (PDOException $e) { $errors[] = 'Could not update message.'; }
}

/* ---- Contact: archive ---- */
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    try {
        $stmt = $pdo->prepare("UPDATE contact_messages SET status='archived' WHERE id=?");
        $stmt->execute([intval($_GET['archive'])]);
        header('Location: support.php?tab=messages'); exit;
    } catch (PDOException $e) { $errors[] = 'Could not archive message.'; }
}

/* ---- Contact: delete ---- */
if (isset($_GET['delete_msg']) && is_numeric($_GET['delete_msg'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id=?");
        $stmt->execute([intval($_GET['delete_msg'])]);
        header('Location: support.php?tab=messages'); exit;
    } catch (PDOException $e) { $errors[] = 'Could not delete message.'; }
}

/* ---- Contact: reply ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    $msg_id  = intval($_POST['message_id']);
    $reply   = trim($_POST['reply_text'] ?? '');

    if (empty($reply)) {
        $errors[] = 'Reply cannot be empty.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE contact_messages
                SET reply_message = ?, status = 'replied', replied_by = ?, replied_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reply, $my_id, $msg_id]);
            $success = 'Reply saved. (Email delivery not configured — copy the text to send manually.)';
        } catch (PDOException $e) {
            $errors[] = 'Could not save reply.';
        }
    }
}

/* ---- Ticket: change status ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket_status'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $new_status = $_POST['status'] ?? 'open';
    if (in_array($new_status, ['open', 'in_progress', 'resolved', 'closed'])) {
        try {
            $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $ticket_id]);
            $success = 'Ticket status updated.';
        } catch (PDOException $e) { $errors[] = 'Could not update ticket.'; }
    }
}

/* ---- Ticket: delete ---- */
if (isset($_GET['delete_ticket']) && is_numeric($_GET['delete_ticket'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM support_tickets WHERE id=?");
        $stmt->execute([intval($_GET['delete_ticket'])]);
        header('Location: support.php?tab=tickets'); exit;
    } catch (PDOException $e) { $errors[] = 'Could not delete ticket.'; }
}

/* ============================================================
   FETCH CONTACT MESSAGES
   ============================================================ */
$msg_filter = $_GET['msg_filter'] ?? 'all';
$contact_messages = [];
try {
    $where = '';
    if ($msg_filter === 'unread')    $where = "WHERE status = 'unread'";
    elseif ($msg_filter === 'read')  $where = "WHERE status = 'read'";
    elseif ($msg_filter === 'replied') $where = "WHERE status = 'replied'";

    $stmt = $pdo->query("
        SELECT * FROM contact_messages
        $where
        ORDER BY
            CASE status WHEN 'unread' THEN 1 WHEN 'read' THEN 2 ELSE 3 END,
            created_at DESC
    ");
    $contact_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $contact_messages = []; }

/* ============================================================
   FETCH TICKETS
   ============================================================ */
$ticket_filter = $_GET['ticket_filter'] ?? 'all';
$tickets = [];
try {
    $where = '';
    if ($ticket_filter === 'open')        $where = "WHERE status = 'open'";
    elseif ($ticket_filter === 'in_progress') $where = "WHERE status = 'in_progress'";
    elseif ($ticket_filter === 'resolved') $where = "WHERE status = 'resolved'";
    elseif ($ticket_filter === 'closed')   $where = "WHERE status = 'closed'";

    $stmt = $pdo->query("
        SELECT t.*, u.email AS user_email
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        $where
        ORDER BY
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
            END,
            t.created_at DESC
    ");
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $tickets = []; }

/* ============================================================
   STATS
   ============================================================ */
$stats = [
    'total_messages'   => 0,
    'unread_messages'  => 0,
    'replied_messages' => 0,
    'open_tickets'     => 0,
    'urgent_tickets'   => 0,
];
try {
    $stats['total_messages']   = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();
    $stats['unread_messages']  = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status='unread'")->fetchColumn();
    $stats['replied_messages'] = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status='replied'")->fetchColumn();
    $stats['open_tickets']     = (int) $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
    $stats['urgent_tickets']   = (int) $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE priority IN ('urgent','high') AND status IN ('open','in_progress')")->fetchColumn();
} catch (PDOException $e) {}

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Contact messages and support tickets from users.';
$page_icon      = 'fa-headset';
$page_theme     = 'violet';
$header_script  = 'We\'ve got<br>your back 🎧';
$breadcrumb     = 'Admin / Support';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

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
            <div class="admin-stat-icon"><i class="fas fa-envelope"></i></div>
            <div class="admin-stat-label">Contact Messages</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_messages']); ?></div>
        <div class="admin-stat-sub">All-time</div>
    </div>
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-envelope-open-text"></i></div>
            <div class="admin-stat-label">Unread</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['unread_messages']); ?></div>
        <div class="admin-stat-sub">Needs attention</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-reply"></i></div>
            <div class="admin-stat-label">Replied</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['replied_messages']); ?></div>
        <div class="admin-stat-sub">Answered</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-ticket-alt"></i></div>
            <div class="admin-stat-label">Open Tickets</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['open_tickets']); ?></div>
        <div class="admin-stat-sub"><?php echo number_format($stats['urgent_tickets']); ?> urgent</div>
    </div>
</div>

<!-- ============ TABS ============ -->
<div class="support-tabs">
    <button class="support-tab active" data-tab="messages" type="button">
        <i class="fas fa-envelope"></i> Contact Messages
        <?php if ($stats['unread_messages'] > 0): ?>
            <span class="tab-badge alert"><?php echo $stats['unread_messages']; ?></span>
        <?php else: ?>
            <span class="tab-badge"><?php echo count($contact_messages); ?></span>
        <?php endif; ?>
    </button>
    <button class="support-tab" data-tab="tickets" type="button">
        <i class="fas fa-ticket-alt"></i> Support Tickets
        <span class="tab-badge"><?php echo count($tickets); ?></span>
    </button>
</div>

<!-- ============ TAB: MESSAGES ============ -->
<div class="support-pane active" id="pane-messages">
    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-inbox"></i></span>
                Inbox
                <span class="count-chip"><?php echo count($contact_messages); ?></span>
            </div>

            <div class="filter-pills">
                <a href="?tab=messages&msg_filter=all"     class="filter-pill <?php echo $msg_filter === 'all'     ? 'active' : ''; ?>">All</a>
                <a href="?tab=messages&msg_filter=unread"  class="filter-pill <?php echo $msg_filter === 'unread'  ? 'active' : ''; ?>">Unread</a>
                <a href="?tab=messages&msg_filter=read"    class="filter-pill <?php echo $msg_filter === 'read'    ? 'active' : ''; ?>">Read</a>
                <a href="?tab=messages&msg_filter=replied" class="filter-pill <?php echo $msg_filter === 'replied' ? 'active' : ''; ?>">Replied</a>
            </div>
        </div>

        <?php if (count($contact_messages) > 0): ?>
            <div class="message-list">
                <?php foreach ($contact_messages as $m):
                    $is_unread = $m['status'] === 'unread';
                    $is_replied = $m['status'] === 'replied';
                    $initial = strtoupper(substr($m['name'] ?: $m['email'], 0, 1));
                ?>
                    <div class="message-card <?php echo $is_unread ? 'is-unread' : ''; ?>">
                        <div class="message-head">
                            <div class="message-avatar <?php echo $is_unread ? 'is-unread' : ''; ?>">
                                <?php echo htmlspecialchars($initial); ?>
                            </div>
                            <div class="message-info">
                                <div class="message-name">
                                    <?php echo htmlspecialchars($m['name'] ?: 'Anonymous'); ?>
                                    <?php if ($is_unread): ?>
                                        <span class="unread-tag">New</span>
                                    <?php endif; ?>
                                </div>
                                <div class="message-meta">
                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($m['email']); ?></span>
                                    <span><i class="far fa-clock"></i> <?php echo date('M d, Y · g:i A', strtotime($m['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="message-badges">
                                <span class="status-badge <?php echo $is_replied ? 'active' : ($is_unread ? 'pending' : 'info'); ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($m['status']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="message-subject">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($m['subject'] ?: '(No subject)'); ?>
                        </div>

                        <div class="message-body">
                            <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                        </div>

                        <?php if ($is_replied && !empty($m['reply_message'])): ?>
                            <div class="message-reply">
                                <div class="reply-label">
                                    <i class="fas fa-reply"></i> Admin reply
                                    · <?php echo date('M d, Y · g:i A', strtotime($m['replied_at'])); ?>
                                </div>
                                <div class="reply-body">
                                    <?php echo nl2br(htmlspecialchars($m['reply_message'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="message-actions">
                            <?php if (!$is_replied): ?>
                                <button type="button" class="btn-soft" onclick="toggleReply(<?php echo (int)$m['id']; ?>)">
                                    <i class="fas fa-reply"></i> Reply
                                </button>
                            <?php endif; ?>
                            <?php if ($is_unread): ?>
                                <a href="?mark_read=<?php echo (int)$m['id']; ?>" class="btn-soft">
                                    <i class="fas fa-check"></i> Mark Read
                                </a>
                            <?php endif; ?>
                            <?php if ($m['status'] !== 'archived'): ?>
                                <a href="?archive=<?php echo (int)$m['id']; ?>" class="btn-soft">
                                    <i class="fas fa-box-archive"></i> Archive
                                </a>
                            <?php endif; ?>
                            <a href="?delete_msg=<?php echo (int)$m['id']; ?>"
                               class="btn-icon-sm danger"
                               onclick="return confirm('Delete this message?')"
                               title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>

                        <!-- Reply form -->
                        <?php if (!$is_replied): ?>
                            <form method="POST" class="reply-form" id="reply-<?php echo (int)$m['id']; ?>" style="display:none;">
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                <textarea name="reply_text"
                                          class="form-input-sm reply-textarea"
                                          placeholder="Type your reply here..."
                                          rows="4"
                                          required></textarea>
                                <div class="reply-form-actions">
                                    <button type="button" class="btn-soft" onclick="toggleReply(<?php echo (int)$m['id']; ?>)">
                                        Cancel
                                    </button>
                                    <button type="submit" name="reply_message" class="btn-soft primary">
                                        <i class="fas fa-paper-plane"></i> Send Reply
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No messages</h4>
                <p>
                    <?php if ($msg_filter === 'unread'): ?>
                        You have no unread messages.
                    <?php elseif ($msg_filter === 'replied'): ?>
                        You haven't replied to any messages yet.
                    <?php else: ?>
                        Contact form submissions will appear here.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ TAB: TICKETS ============ -->
<div class="support-pane" id="pane-tickets">
    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-ticket-alt"></i></span>
                Support Tickets
                <span class="count-chip"><?php echo count($tickets); ?></span>
            </div>

            <div class="filter-pills">
                <a href="?tab=tickets&ticket_filter=all"         class="filter-pill <?php echo $ticket_filter === 'all'         ? 'active' : ''; ?>">All</a>
                <a href="?tab=tickets&ticket_filter=open"        class="filter-pill <?php echo $ticket_filter === 'open'        ? 'active' : ''; ?>">Open</a>
                <a href="?tab=tickets&ticket_filter=in_progress" class="filter-pill <?php echo $ticket_filter === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
                <a href="?tab=tickets&ticket_filter=resolved"    class="filter-pill <?php echo $ticket_filter === 'resolved'    ? 'active' : ''; ?>">Resolved</a>
                <a href="?tab=tickets&ticket_filter=closed"      class="filter-pill <?php echo $ticket_filter === 'closed'      ? 'active' : ''; ?>">Closed</a>
            </div>
        </div>

        <?php if (count($tickets) > 0): ?>
            <div class="ticket-list">
                <?php foreach ($tickets as $t):
                    $priority_class = $t['priority'] === 'urgent' ? 'inactive' : ($t['priority'] === 'high' ? 'pending' : ($t['priority'] === 'normal' ? 'info' : 'closed'));
                    $status_class   = $t['status'] === 'open' ? 'inactive' : ($t['status'] === 'in_progress' ? 'pending' : ($t['status'] === 'resolved' ? 'active' : 'closed'));
                ?>
                    <div class="ticket-row">
                        <div class="ticket-left">
                            <span class="ticket-id">#<?php echo (int)$t['id']; ?></span>
                            <span class="status-badge <?php echo $priority_class; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst($t['priority']); ?>
                            </span>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?>
                            </span>
                        </div>
                        <div class="ticket-body">
                            <div class="ticket-subject"><?php echo htmlspecialchars($t['subject']); ?></div>
                            <div class="ticket-meta">
                                <span>
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($t['user_email'] ?: $t['email']); ?>
                                </span>
                                <span>
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y', strtotime($t['created_at'])); ?>
                                </span>
                            </div>
                            <div class="ticket-message">
                                <?php echo nl2br(htmlspecialchars(mb_strimwidth($t['message'], 0, 220, '…'))); ?>
                            </div>
                        </div>
                        <div class="ticket-actions">
                            <form method="POST" class="ticket-status-form">
                                <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
                                <input type="hidden" name="update_ticket_status" value="1">
                                <select name="status" class="table-select" onchange="this.form.submit()">
                                    <option value="open"        <?php echo $t['status'] === 'open'        ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo $t['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="resolved"    <?php echo $t['status'] === 'resolved'    ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed"      <?php echo $t['status'] === 'closed'      ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </form>
                            <a href="?delete_ticket=<?php echo (int)$t['id']; ?>"
                               class="btn-icon-sm danger"
                               onclick="return confirm('Delete this ticket?')"
                               title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-ticket-alt"></i>
                <h4>No tickets</h4>
                <p>Support tickets submitted by users will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ============================================================
   SUPPORT PAGE — COMPACT + FLAT DARK GLASS
   ============================================================ */

/* ---------- Compact stat tiles ---------- */
.admin-stat {
    min-height: 92px !important;
    padding: 14px 12px !important;
    gap: 4px !important;
}
.admin-stat-icon {
    width: 26px !important;
    height: 26px !important;
    font-size: 0.75rem !important;
    border-radius: 8px !important;
}
.admin-stat-label { font-size: 0.64rem !important; }
.admin-stat-value {
    font-size: 1.5rem !important;
    letter-spacing: -0.3px !important;
}
.admin-stat-sub {
    font-size: 0.62rem !important;
    margin-top: 1px !important;
}
.admin-stats {
    gap: 12px !important;
    margin-bottom: 16px !important;
}

/* ---------- Count chip ---------- */
.count-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px !important;
    height: 20px !important;
    padding: 0 9px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem !important;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 6px;
}

/* ---------- Tabs ---------- */
.support-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
    padding: 5px;
    background: rgba(20, 32, 68, 0.35);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    box-shadow: none;
    width: fit-content;
    max-width: 100%;
    overflow-x: auto;
    scrollbar-width: none;
}
.support-tabs::-webkit-scrollbar { display: none; }

.support-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px !important;
    border: none;
    background: transparent;
    border-radius: 9px;
    font-family: inherit;
    font-size: 0.78rem !important;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    cursor: pointer;
    transition: 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.support-tab i {
    font-size: 0.78rem !important;
    color: rgba(255, 255, 255, 0.5);
    transition: 0.2s;
}
.support-tab:hover {
    background: rgba(96, 165, 250, 0.12);
    color: #FFFFFF;
}
.support-tab:hover i { color: #93C5FD; }
.support-tab.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #FFFFFF;
    box-shadow: none;
}
.support-tab.active i { color: #FFFFFF; }

.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px !important;
    height: 18px !important;
    padding: 0 6px !important;
    background: rgba(255, 255, 255, 0.20);
    font-size: 0.62rem !important;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 3px;
    color: #FFFFFF;
}
.support-tab:not(.active) .tab-badge {
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
}
.support-tab .tab-badge.alert {
    background: #EF4444;
    color: #FFFFFF;
}

/* Panes */
.support-pane { display: none; }
.support-pane.active { display: block; }

/* ---------- Panel head ---------- */
.card-panel .card-panel-head {
    padding: 14px 20px !important;
}

/* ---------- Filter pills ---------- */
.filter-pills {
    display: flex;
    gap: 3px;
    flex-wrap: wrap;
    padding: 3px;
    background: rgba(20, 32, 68, 0.40);
    border-radius: 999px;
    border: 1px solid rgba(120, 160, 255, 0.12);
}

.filter-pill {
    padding: 5px 12px !important;
    border-radius: 999px;
    font-size: 0.72rem !important;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.65);
    transition: 0.2s;
    background: transparent;
}

.filter-pill:hover {
    color: #FFFFFF;
    background: rgba(96, 165, 250, 0.12);
}

.filter-pill.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #FFFFFF;
    box-shadow: none;
}

/* ---------- Message list ---------- */
.message-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px 20px 18px;
}

.message-card {
    background: rgba(20, 32, 68, 0.30);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    padding: 14px 16px !important;
    transition: 0.18s;
    position: relative;
}
.message-card:hover {
    background: rgba(20, 32, 68, 0.45);
    border-color: rgba(96, 165, 250, 0.30);
}
.message-card.is-unread {
    border-color: rgba(96, 165, 250, 0.25);
    background: rgba(96, 165, 250, 0.06);
}
.message-card.is-unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 12px;
    bottom: 12px;
    width: 3px;
    background: linear-gradient(180deg, #3B82F6, #60A5FA);
    border-radius: 0 3px 3px 0;
}

.message-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.message-avatar {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.8rem !important;
    flex-shrink: 0;
}
.message-avatar.is-unread {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #FFFFFF;
}
.message-info { flex: 1; min-width: 0; }
.message-name {
    font-weight: 800;
    font-size: 0.84rem !important;
    color: #FFFFFF;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.unread-tag {
    display: inline-flex;
    padding: 2px 7px !important;
    background: #EF4444;
    color: #FFFFFF;
    font-size: 0.56rem !important;
    font-weight: 800;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.message-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
}
.message-meta span { display: inline-flex; align-items: center; gap: 4px; }
.message-meta i { color: #93C5FD; font-size: 0.66rem !important; }

.message-subject {
    font-size: 0.82rem !important;
    font-weight: 800;
    color: #FFFFFF;
    margin-bottom: 6px !important;
    display: flex;
    align-items: center;
    gap: 6px;
}
.message-subject i { color: #93C5FD; font-size: 0.74rem !important; }

.message-body {
    font-size: 0.78rem !important;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.75);
    font-weight: 500;
    padding: 12px 14px !important;
    background: rgba(20, 32, 68, 0.35);
    border-radius: 10px;
    margin-bottom: 12px !important;
    overflow-wrap: break-word;
    word-break: break-word;
}

.message-reply {
    margin-bottom: 12px !important;
    padding: 12px 14px !important;
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.25);
    border-radius: 10px;
}
.reply-label {
    font-size: 0.68rem !important;
    font-weight: 800;
    color: #6EE7B7;
    margin-bottom: 6px !important;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.reply-label i { font-size: 0.64rem !important; }
.reply-body {
    font-size: 0.78rem !important;
    line-height: 1.55;
    color: #FFFFFF;
    font-weight: 500;
}

.message-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.message-actions .btn-soft {
    padding: 6px 12px !important;
    font-size: 0.72rem !important;
}
.message-actions .btn-icon-sm {
    width: 28px !important;
    height: 28px !important;
    font-size: 0.68rem !important;
}

/* Reply form */
.reply-form {
    margin-top: 12px !important;
    padding-top: 12px !important;
    border-top: 1px solid rgba(120, 160, 255, 0.10);
}
.reply-textarea {
    width: 100%;
    resize: vertical;
    font-family: inherit;
    font-size: 0.78rem !important;
    line-height: 1.6;
    padding: 10px 12px !important;
    margin-bottom: 8px !important;
    background: rgba(20, 32, 68, 0.50);
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 10px;
    color: #FFFFFF;
    outline: none;
}
.reply-textarea::placeholder { color: rgba(255, 255, 255, 0.45); }
.reply-textarea:focus {
    border-color: rgba(96, 165, 250, 0.55);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.reply-form-actions {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
}
.reply-form-actions .btn-soft {
    padding: 6px 12px !important;
    font-size: 0.72rem !important;
}

/* ---------- Status badge (shared style) ---------- */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 4px 9px !important;
    border-radius: 999px;
    font-size: 0.62rem !important;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    border: 1px solid;
}
.status-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    flex-shrink: 0;
}
.status-badge.active {
    background: rgba(16, 185, 129, 0.15);
    color: #6EE7B7;
    border-color: rgba(16, 185, 129, 0.35);
}
.status-badge.active .status-dot { background: #10B981; }
.status-badge.pending {
    background: rgba(245, 158, 11, 0.15);
    color: #FCD34D;
    border-color: rgba(245, 158, 11, 0.35);
}
.status-badge.pending .status-dot { background: #F59E0B; }
.status-badge.inactive {
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
    border-color: rgba(239, 68, 68, 0.35);
}
.status-badge.inactive .status-dot { background: #EF4444; }
.status-badge.info {
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    border-color: rgba(96, 165, 250, 0.35);
}
.status-badge.info .status-dot { background: #3B82F6; }
.status-badge.closed {
    background: rgba(148, 163, 184, 0.15);
    color: #CBD5E1;
    border-color: rgba(148, 163, 184, 0.35);
}
.status-badge.closed .status-dot { background: #94A3B8; }

/* ---------- Ticket list ---------- */
.ticket-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px 20px 18px;
}
.ticket-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    background: rgba(20, 32, 68, 0.30);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 12px;
    padding: 14px 16px !important;
    transition: 0.18s;
}
.ticket-row:hover {
    background: rgba(20, 32, 68, 0.45);
    border-color: rgba(96, 165, 250, 0.30);
}
.ticket-left {
    display: flex;
    flex-direction: column;
    gap: 5px;
    align-items: flex-start;
    flex-shrink: 0;
}
.ticket-id {
    font-size: 0.68rem !important;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.5);
    letter-spacing: 0.3px;
}
.ticket-body { min-width: 0; }
.ticket-subject {
    font-size: 0.84rem !important;
    font-weight: 800;
    color: #FFFFFF;
    margin-bottom: 5px !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ticket-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 0.68rem !important;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
    margin-bottom: 6px !important;
}
.ticket-meta span { display: inline-flex; align-items: center; gap: 4px; }
.ticket-meta i { color: #93C5FD; font-size: 0.64rem !important; }
.ticket-message {
    font-size: 0.74rem !important;
    color: rgba(255, 255, 255, 0.72);
    font-weight: 500;
    line-height: 1.5;
}
.ticket-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.ticket-status-form { display: inline-block; }
.ticket-actions .table-select {
    padding: 5px 24px 5px 10px !important;
    font-size: 0.7rem !important;
    background-position: right 8px center !important;
}
.ticket-actions .btn-icon-sm {
    width: 28px !important;
    height: 28px !important;
    font-size: 0.68rem !important;
}

/* ---------- Empty state compact ---------- */
.empty-state {
    padding: 50px 20px !important;
}
.empty-state i {
    font-size: 26px !important;
    margin-bottom: 12px !important;
}
.empty-state h4 {
    font-size: 0.95rem !important;
    margin-bottom: 5px !important;
}
.empty-state p {
    font-size: 0.78rem !important;
}

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
    .support-tabs { padding: 4px; }
    .support-tab { padding: 7px 12px !important; font-size: 0.72rem !important; }
    .card-panel-head { flex-direction: column; align-items: stretch; gap: 10px; }
    .filter-pills { overflow-x: auto; padding: 3px; }
    .filter-pill { flex-shrink: 0; }

    .message-card { padding: 12px !important; }
    .message-body, .message-reply { padding: 10px !important; }
    .message-list { padding: 12px 16px 16px; }

    .ticket-row {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 12px !important;
    }
    .ticket-left { flex-direction: row; }
    .ticket-actions { justify-content: space-between; }
    .ticket-status-form { flex: 1; }
    .ticket-status-form select { width: 100%; }
    .ticket-list { padding: 12px 16px 16px; }
}

/* ---------- Force white text ---------- */
.support-pane,
.support-pane *,
.support-tabs,
.support-tabs *,
.filter-pills,
.filter-pills * {
    color: #FFFFFF;
}
.message-body,
.ticket-message,
.message-meta,
.ticket-meta {
    color: rgba(255, 255, 255, 0.75) !important;
}
</style>

<script>
/* Tabs */
(function() {
    const tabs = document.querySelectorAll('.support-tab');
    const panes = {
        'messages': document.getElementById('pane-messages'),
        'tickets':  document.getElementById('pane-tickets'),
    };

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            Object.values(panes).forEach(p => p && p.classList.remove('active'));
            const target = panes[tab.dataset.tab];
            if (target) target.classList.add('active');
        });
    });

    /* Deep link via ?tab=tickets */
    const urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && panes[urlTab]) {
        tabs.forEach(t => t.classList.remove('active'));
        Object.values(panes).forEach(p => p && p.classList.remove('active'));
        document.querySelector('.support-tab[data-tab="' + urlTab + '"]')?.classList.add('active');
        panes[urlTab].classList.add('active');
    }
})();

/* Reply form toggle */
function toggleReply(id) {
    const el = document.getElementById('reply-' + id);
    if (!el) return;
    el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
}
</script>

<?php include '_footer.php'; ?>