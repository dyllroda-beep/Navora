<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Messages';
$user_id = $_SESSION['user_id'];

/* ---------- Mark read (single user thread) ---------- */
$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($selected_user_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $stmt->execute([$selected_user_id, $user_id]);
    } catch (Exception $e) {}
}

/* ---------- Fetch all conversations ---------- */
$conversations = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id AS other_user_id,
            COALESCE(
                (SELECT CONCAT(first_name, ' ', last_name) FROM job_seekers WHERE user_id = u.id),
                (SELECT company_name FROM employers WHERE user_id = u.id),
                u.email
            ) AS display_name,
            (SELECT profile_photo FROM job_seekers WHERE user_id = u.id) AS seeker_photo,
            (SELECT logo FROM employers WHERE user_id = u.id) AS employer_logo,
            u.role,
            (SELECT MAX(created_at) FROM messages
             WHERE (sender_id = ? AND receiver_id = u.id)
                OR (sender_id = u.id AND receiver_id = ?)) AS last_message_at,
            (SELECT message FROM messages
             WHERE (sender_id = ? AND receiver_id = u.id)
                OR (sender_id = u.id AND receiver_id = ?)
             ORDER BY created_at DESC LIMIT 1) AS last_message,
            (SELECT COUNT(*) FROM messages
             WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) AS unread_count
        FROM users u
        WHERE u.id != ?
          AND EXISTS (
            SELECT 1 FROM messages m
            WHERE (m.sender_id = ? AND m.receiver_id = u.id)
               OR (m.sender_id = u.id AND m.receiver_id = ?)
          )
        ORDER BY last_message_at DESC
    ");
    $stmt->execute([
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id,
        $user_id,
        $user_id, $user_id
    ]);
    $conversations = $stmt->fetchAll();
} catch (PDOException $e) {
    $conversations = [];
}

/* ---------- Auto-select first conversation ---------- */
if ($selected_user_id === 0 && count($conversations) > 0) {
    $selected_user_id = (int)$conversations[0]['other_user_id'];
    try {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $stmt->execute([$selected_user_id, $user_id]);
    } catch (Exception $e) {}
}

/* ---------- Send reply ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $message_text = trim($_POST['message'] ?? '');

    if (!empty($message_text) && $receiver_id > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
            $stmt->execute([$user_id, $receiver_id, $message_text]);

            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
            $stmt->execute([
                $receiver_id,
                'New Message',
                'You have a new message from an administrator.'
            ]);
        } catch (Exception $e) {}
    }
    header('Location: /navora3/admin/messages.php?user_id=' . $receiver_id);
    exit;
}

/* ---------- Load thread ---------- */
$thread = [];
$selected_user = null;
if ($selected_user_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, u.id AS sender_user_id
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user_id, $selected_user_id, $selected_user_id, $user_id]);
        $thread = $stmt->fetchAll();
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.role,
                   COALESCE(
                       (SELECT CONCAT(first_name, ' ', last_name) FROM job_seekers WHERE user_id = u.id),
                       (SELECT company_name FROM employers WHERE user_id = u.id),
                       u.email
                   ) AS display_name,
                   (SELECT profile_photo FROM job_seekers WHERE user_id = u.id) AS seeker_photo,
                   (SELECT logo FROM employers WHERE user_id = u.id) AS employer_logo,
                   (SELECT municipality FROM job_seekers WHERE user_id = u.id) AS municipality
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->execute([$selected_user_id]);
        $selected_user = $stmt->fetch();
    } catch (Exception $e) {}
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$notif_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$total_unread = (int)$stmt->fetchColumn();
$unread_messages = $total_unread;

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    return date('M d', strtotime($datetime));
}

$page_subtitle = 'View and reply to messages from users on the platform.';
$breadcrumb = 'Admin / Messages';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<div class="messages-shell">

    <!-- CONVERSATIONS -->
    <div class="convo-panel">
        <div class="convo-header">
            <h3><i class="fas fa-comments"></i> Conversations</h3>
            <div class="sub">
                <?php echo count($conversations); ?> total
                <?php if ($total_unread > 0): ?>
                    · <strong><?php echo $total_unread; ?> unread</strong>
                <?php endif; ?>
            </div>
        </div>
        <div class="convo-list">
            <?php if (count($conversations) > 0): ?>
                <?php foreach ($conversations as $convo):
                    $other_name = $convo['display_name'] ?: 'User';
                    $is_active = ($selected_user_id === (int)$convo['other_user_id']);
                    $photo = $convo['seeker_photo'] ?: $convo['employer_logo'];
                ?>
                    <a href="?user_id=<?php echo $convo['other_user_id']; ?>" class="convo-item <?php echo $is_active ? 'active' : ''; ?>">
                        <div class="convo-avatar">
                            <?php if ($photo && file_exists('../' . $photo)): ?>
                                <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                            <?php else: ?>
                                <?php echo strtoupper(substr($other_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="convo-body">
                            <div class="convo-name"><?php echo htmlspecialchars($other_name); ?></div>
                            <div class="convo-preview">
                                <?php echo htmlspecialchars(substr($convo['last_message'] ?? '', 0, 32)); ?><?php echo strlen($convo['last_message'] ?? '') > 32 ? '…' : ''; ?>
                            </div>
                        </div>
                        <div class="convo-meta">
                            <div class="convo-time"><?php echo timeAgo($convo['last_message_at']); ?></div>
                            <?php if ($convo['unread_count'] > 0): ?>
                                <div class="convo-badge"><?php echo $convo['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="convo-empty">
                    <i class="fas fa-inbox"></i>
                    No conversations yet
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CHAT -->
    <div class="chat-panel">
        <?php if ($selected_user && count($thread) > 0): ?>
            <?php
                $other_photo = $selected_user['seeker_photo'] ?: $selected_user['employer_logo'];
                $other_name = $selected_user['display_name'] ?: $selected_user['email'];
            ?>
            <div class="chat-header">
                <div class="chat-header-avatar">
                    <?php if ($other_photo && file_exists('../' . $other_photo)): ?>
                        <img src="/navora3/<?php echo htmlspecialchars($other_photo); ?>" alt="">
                    <?php else: ?>
                        <?php echo strtoupper(substr($other_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="chat-header-info">
                    <div class="chat-header-name"><?php echo htmlspecialchars($other_name); ?></div>
                    <div class="chat-header-status">
                        <span class="dot-online"></span>
                        <?php echo htmlspecialchars(ucfirst($selected_user['role'])); ?>
                        <?php if (!empty($selected_user['municipality'])): ?>
                            · <?php echo htmlspecialchars($selected_user['municipality']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="chat-thread" id="chatThread">
                <?php
                $last_date = '';
                foreach ($thread as $msg):
                    $is_mine = ($msg['sender_user_id'] == $user_id);
                    $msg_date = date('Y-m-d', strtotime($msg['created_at']));
                    if ($msg_date !== $last_date):
                        $last_date = $msg_date;
                        $date_label = date('Y-m-d') === $msg_date ? 'Today'
                                    : (date('Y-m-d', strtotime('-1 day')) === $msg_date ? 'Yesterday'
                                    : date('F j, Y', strtotime($msg_date)));
                ?>
                    <div class="chat-date-divider"><?php echo $date_label; ?></div>
                <?php endif; ?>

                    <div class="chat-bubble <?php echo $is_mine ? 'sent' : 'received'; ?>">
                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                        <span class="chat-bubble-time"><?php echo date('g:i A', strtotime($msg['created_at'])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <form class="chat-form" method="POST">
                <input type="hidden" name="receiver_id" value="<?php echo $selected_user_id; ?>">
                <input type="text" name="message" placeholder="Type your message..." autocomplete="off" required>
                <button type="submit" name="send_message" class="chat-send-btn" title="Send">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        <?php else: ?>
            <div class="chat-empty">
                <i class="fas fa-comment-dots"></i>
                <h4>No conversation selected</h4>
                <p>Choose a conversation from the list on the left, or wait for users to message you.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<style>
/* ============================================================
   ADMIN MESSAGES PAGE — matches employer messages page
   ============================================================ */

.messages-shell {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 18px;
    height: calc(100vh - 280px);
    min-height: 520px;
    max-height: 820px;
    margin-bottom: 20px;
}

/* Conversations panel */
.convo-panel {
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 16px;
    display: flex; flex-direction: column;
    overflow: hidden;
}
.convo-header {
    padding: 14px 18px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    flex-shrink: 0;
    background: rgba(120, 160, 255, 0.04);
}
.convo-header h3 {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: 1rem; font-weight: 700;
    color: #fff; margin: 0 0 4px;
    display: flex; align-items: center; gap: 8px;
}
.convo-header h3 i { color: #93C5FD; font-size: 13px; }
.convo-header .sub {
    font-size: 11.5px;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
}
.convo-header .sub strong { color: #93C5FD; font-weight: 800; }

.convo-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}
.convo-list::-webkit-scrollbar { width: 4px; }
.convo-list::-webkit-scrollbar-thumb {
    background: rgba(96, 165, 250, 0.20);
    border-radius: 4px;
}

.convo-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    transition: 0.15s;
    cursor: pointer;
    color: inherit;
    text-decoration: none;
    margin-bottom: 2px;
    border: 1px solid transparent;
}
.convo-item:hover {
    background: rgba(96, 165, 250, 0.06);
    border-color: rgba(96, 165, 250, 0.15);
}
.convo-item.active {
    background: rgba(96, 165, 250, 0.12);
    border-color: rgba(96, 165, 250, 0.30);
}
.convo-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px;
    overflow: hidden;
    border: 2px solid rgba(147, 197, 253, 0.30);
}
.convo-avatar img { width: 100%; height: 100%; object-fit: cover; }
.convo-body { flex: 1; min-width: 0; }
.convo-name {
    font-weight: 700; font-size: 13px;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 3px;
}
.convo-preview {
    font-size: 11.5px;
    color: rgba(255, 255, 255, 0.55);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-weight: 500;
}
.convo-meta {
    display: flex; flex-direction: column;
    align-items: flex-end; gap: 4px; flex-shrink: 0;
}
.convo-time {
    font-size: 10.5px;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
}
.convo-badge {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    min-width: 18px; height: 18px;
    padding: 0 6px;
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800;
}
.convo-empty {
    text-align: center;
    padding: 40px 16px;
    color: rgba(255, 255, 255, 0.5);
    font-size: 12.5px;
}
.convo-empty i {
    font-size: 26px;
    opacity: 0.4;
    display: block;
    margin-bottom: 10px;
    color: #93C5FD;
}

/* Chat panel */
.chat-panel {
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 16px;
    display: flex; flex-direction: column;
    overflow: hidden;
}
.chat-header {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    display: flex; align-items: center; gap: 12px;
    background: rgba(120, 160, 255, 0.04);
    flex-shrink: 0;
}
.chat-header-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px;
    overflow: hidden;
    flex-shrink: 0;
    border: 2px solid rgba(147, 197, 253, 0.30);
}
.chat-header-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-name {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-weight: 700;
    font-size: 14px;
    color: #fff;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-header-status {
    font-size: 11.5px;
    color: rgba(255, 255, 255, 0.55);
    display: flex; align-items: center; gap: 5px;
    font-weight: 600;
}
.chat-header-status .dot-online {
    width: 6px; height: 6px; border-radius: 50%;
    background: #10B981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.20);
}

.chat-thread {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: rgba(10, 19, 48, 0.50);
}
.chat-thread::-webkit-scrollbar { width: 5px; }
.chat-thread::-webkit-scrollbar-thumb {
    background: rgba(96, 165, 250, 0.20);
    border-radius: 4px;
}

.chat-bubble {
    max-width: 65%;
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 13px;
    line-height: 1.5;
    word-wrap: break-word;
    font-weight: 500;
}
.chat-bubble.sent {
    align-self: flex-end;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-bubble.received {
    align-self: flex-start;
    background: rgba(20, 32, 68, 0.90);
    color: #fff;
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-bottom-left-radius: 4px;
}
.chat-bubble-time {
    font-size: 10px;
    opacity: 0.75;
    margin-top: 4px;
    display: block;
    text-align: right;
    font-weight: 600;
}
.chat-bubble.sent .chat-bubble-time { color: rgba(255,255,255,0.85); }
.chat-bubble.received .chat-bubble-time { color: rgba(255, 255, 255, 0.55); }

.chat-date-divider {
    align-self: center;
    background: rgba(96, 165, 250, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.20);
    color: #93C5FD;
    font-size: 10px;
    font-weight: 800;
    padding: 4px 12px;
    border-radius: 20px;
    margin: 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.chat-empty {
    flex: 1;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    text-align: center;
    padding: 40px 20px;
    color: rgba(255, 255, 255, 0.55);
}
.chat-empty i {
    font-size: 2.4rem;
    opacity: 0.4;
    margin-bottom: 14px;
    color: #93C5FD;
}
.chat-empty h4 {
    font-family: 'Libre Baskerville', Georgia, serif;
    color: #fff;
    font-weight: 700;
    margin-bottom: 6px;
    font-size: 1rem;
}
.chat-empty p {
    font-size: 12.5px;
    max-width: 320px;
    line-height: 1.55;
    font-weight: 500;
}

.chat-form {
    padding: 14px 20px;
    border-top: 1px solid rgba(120, 160, 255, 0.10);
    background: rgba(120, 160, 255, 0.04);
    display: flex;
    gap: 10px;
    align-items: center;
    flex-shrink: 0;
}
.chat-form input[type="text"] {
    flex: 1;
    height: 44px;
    padding: 0 18px;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 30px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: 0.2s;
    color: #fff;
    background: rgba(20, 32, 68, 0.90);
}
.chat-form input[type="text"]::placeholder { color: rgba(255, 255, 255, 0.45); }
.chat-form input[type="text"]:focus {
    border-color: rgba(96, 165, 250, 0.55);
    background: rgba(20, 32, 68, 0.95);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.chat-send-btn {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: 0.2s;
    flex-shrink: 0;
}
.chat-send-btn:hover {
    background: linear-gradient(135deg, #2563EB, #3B82F6);
    transform: translateY(-1px);
}

/* Responsive */
@media (max-width: 992px) {
    .messages-shell {
        grid-template-columns: 280px 1fr;
        height: calc(100vh - 260px);
    }
}
@media (max-width: 768px) {
    .messages-shell {
        grid-template-columns: 1fr;
        height: auto;
        max-height: none;
        gap: 14px;
    }
    .convo-panel { max-height: 260px; }
    .chat-panel {
        height: calc(100vh - 260px);
        min-height: 440px;
    }
}
@media (max-width: 480px) {
    .chat-bubble { max-width: 85%; font-size: 12.5px; padding: 9px 13px; }
    .chat-thread { padding: 14px; }
    .chat-form { padding: 12px 14px; }
    .chat-send-btn { width: 42px; height: 42px; }
    .chat-form input[type="text"] { height: 42px; padding: 0 16px; font-size: 12.5px; }
}
</style>

<script>
const thread = document.getElementById('chatThread');
if (thread) {
    thread.scrollTop = thread.scrollHeight;
}
</script>

<?php include '_footer.php'; ?>