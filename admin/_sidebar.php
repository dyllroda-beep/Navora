<?php
$current_page = basename($_SERVER['PHP_SELF']);
$avatar_letter = strtoupper(substr($admin_name ?? 'A', 0, 1));
$notif_badge = $notif_count ?? 0;

/* ---------- Sidebar messages preview ---------- */
$sidebar_messages = [];
$unread_support = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $me = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("
            SELECT
                u.id AS other_user_id,
                COALESCE(
                    (SELECT CONCAT(first_name, ' ', last_name) FROM job_seekers WHERE user_id = u.id),
                    (SELECT company_name FROM employers WHERE user_id = u.id),
                    u.email
                ) AS display_name,
                (SELECT profile_photo FROM job_seekers WHERE user_id = u.id) AS seeker_photo,
                (SELECT logo FROM employers WHERE user_id = u.id) AS employer_logo,
                (SELECT message FROM messages
                 WHERE (sender_id = ? AND receiver_id = u.id)
                    OR (sender_id = u.id AND receiver_id = ?)
                 ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT MAX(created_at) FROM messages
                 WHERE (sender_id = ? AND receiver_id = u.id)
                    OR (sender_id = u.id AND receiver_id = ?)) AS last_at,
                (SELECT COUNT(*) FROM messages
                 WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) AS unread_count
            FROM users u
            WHERE u.id != ?
              AND EXISTS (
                SELECT 1 FROM messages m
                WHERE (m.sender_id = ? AND m.receiver_id = u.id)
                   OR (m.sender_id = u.id AND m.receiver_id = ?)
              )
            ORDER BY last_at DESC
            LIMIT 6
        ");
        $stmt->execute([$me, $me, $me, $me, $me, $me, $me, $me]);
        $sidebar_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$me]);
        $unread_support = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $sidebar_messages = [];
        $unread_support = 0;
    }
}

/* ---------- Badge counts for sidebar items ---------- */
$sb_pending_employers = 0;
$sb_pending_jobs      = 0;
$sb_open_reports      = 0;
$sb_pending_users     = 0;
$sb_approved_accounts = 0;
$sb_pending_warnings  = 0;
if (isset($pdo)) {
    try { $sb_pending_employers = (int)$pdo->query("SELECT COUNT(*) FROM employers WHERE verification_status='pending'")->fetchColumn(); } catch (Exception $e) {}
    try { $sb_pending_jobs      = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='pending'")->fetchColumn(); } catch (Exception $e) {}
    try { $sb_open_reports      = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status='open'")->fetchColumn(); } catch (Exception $e) {}
    try { $sb_pending_users     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'pending'")->fetchColumn(); } catch (Exception $e) {}
    try { $sb_approved_accounts = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'")->fetchColumn(); } catch (Exception $e) {}
    try { $sb_pending_warnings  = (int)$pdo->query("SELECT COUNT(*) FROM account_warnings WHERE status='pending'")->fetchColumn(); } catch (Exception $e) {}
}

/* Helper: active if page matches OR belongs to the same group */
function sb_active($pages, $current) {
    if (is_array($pages)) return in_array($current, $pages, true);
    return $current === $pages;
}
?>
<style>
/* ============================================================
   NAVORA ADMIN SIDEBAR — Recommended structure
   ============================================================ */
:root {
    --sidebar-width: 248px;
    --sb-bg:           rgba(11, 18, 36, 0.98);
    --sb-border:       rgba(96, 165, 250, 0.08);
    --sb-text:         rgba(255, 255, 255, 0.72);
    --sb-text-hover:   #FFFFFF;
    --sb-muted:        rgba(255, 255, 255, 0.55);
    --sb-blue:         #3B82F6;
    --sb-blue-light:   #60A5FA;
    --sb-blue-soft:    #93C5FD;
}

.sidebar-wrap {
    width: var(--sidebar-width);
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    padding: 14px 10px 12px;
    display: flex;
    flex-direction: column;
    z-index: 200;
    overflow-y: auto;
    overflow-x: hidden;
    background: var(--sb-bg);
    border-right: 1px solid var(--sb-border);
    color: var(--sb-text);
    transition: transform 240ms cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 8px 0 40px rgba(0, 0, 0, 0.35);
    scrollbar-width: thin;
    scrollbar-color: rgba(96, 165, 250, 0.25) transparent;
    overscroll-behavior: contain;
}
.sidebar-wrap::-webkit-scrollbar { width: 5px; }
.sidebar-wrap::-webkit-scrollbar-track { background: transparent; }
.sidebar-wrap::-webkit-scrollbar-thumb {
    background: rgba(96, 165, 250, 0.20);
    border-radius: 4px;
}
.sidebar-wrap::-webkit-scrollbar-thumb:hover {
    background: rgba(96, 165, 250, 0.40);
}

.sidebar-brand {
    display: flex; align-items: center; gap: 10px;
    padding: 0 8px 12px 8px;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--sb-border);
    flex-shrink: 0;
    text-decoration: none; color: inherit;
}
.sidebar-brand-logo {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--sb-blue), var(--sb-blue-light));
    color: #FFFFFF;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Libre Baskerville', Georgia, serif;
    font-weight: 700; font-size: 1rem;
    flex-shrink: 0;
}
.sidebar-brand-text { display: flex; flex-direction: column; min-width: 0; overflow: hidden; }
.sidebar-brand-name {
    font-weight: 700; font-size: 1rem; color: #FFFFFF;
    line-height: 1.15; letter-spacing: -0.3px;
    font-family: 'Libre Baskerville', Georgia, serif;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-brand-sub {
    font-size: 0.6rem;
    color: rgba(147, 197, 253, 0.75);
    font-weight: 700; letter-spacing: 1.4px;
    text-transform: uppercase; margin-top: 2px;
    font-family: 'Source Sans 3', sans-serif;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.sidebar-section-label {
    font-size: 0.68rem;
    font-weight: 800;
    color: rgba(147, 197, 253, 0.60);
    text-transform: uppercase;
    letter-spacing: 1.3px;
    padding: 12px 12px 6px 12px;
    flex-shrink: 0;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 1px;
    flex-shrink: 0;
}
.nav-item {
    display: flex; align-items: center; gap: 11px;
    padding: 8px 11px;
    color: var(--sb-text);
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.8125rem;
    transition: 0.18s;
    min-height: 36px;
    position: relative;
    letter-spacing: 0.1px;
    flex-shrink: 0;
    line-height: 1.2;
    text-decoration: none;
}
.nav-item i {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    color: rgba(147, 197, 253, 0.80);
    background: rgba(96, 165, 250, 0.08);
    border-radius: 7px;
    flex-shrink: 0;
    transition: 0.18s;
}
.nav-item span.nav-label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.nav-item:hover {
    background: rgba(96, 165, 250, 0.10);
    color: var(--sb-text-hover);
}
.nav-item:hover i {
    background: rgba(96, 165, 250, 0.20);
    color: var(--sb-blue-soft);
}
.nav-item.active {
    background: linear-gradient(135deg, var(--sb-blue) 0%, var(--sb-blue-light) 100%);
    color: #FFFFFF;
    font-weight: 700;
}
.nav-item.active i {
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF;
}
.nav-badge {
    margin-left: auto;
    font-size: 0.6rem;
    font-weight: 800;
    padding: 2px 6px; border-radius: 20px;
    min-width: 18px; text-align: center;
    color: #FFFFFF;
    background: var(--sb-blue);
    line-height: 1.4;
    flex-shrink: 0;
}
.nav-badge.alert { background: #EF4444; }
.nav-badge.warn  { background: #F59E0B; }
.nav-badge.success { background: #10B981; }

.sidebar-messages {
    margin-top: 12px;
    padding: 10px 8px 8px;
    background: rgba(96, 165, 250, 0.06);
    border: 1px solid rgba(96, 165, 250, 0.12);
    border-radius: 12px;
    flex-shrink: 0;
    overflow: hidden;
}
.sidebar-messages-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 6px 8px;
}
.sidebar-messages-title {
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(147, 197, 253, 0.60);
}
.sidebar-messages-arrows { display: flex; gap: 4px; }
.sidebar-messages-arrows button {
    width: 20px; height: 20px;
    border-radius: 50%;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.15);
    color: var(--sb-blue-soft);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.5rem;
    transition: 0.2s; padding: 0;
}
.sidebar-messages-arrows button:hover {
    background: rgba(96, 165, 250, 0.22);
    color: #fff;
}
.sidebar-messages-arrows button:disabled { opacity: 0.4; cursor: not-allowed; }

.sidebar-msg-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-height: 60px;
}
.sidebar-msg-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 8px;
    border-radius: 9px;
    transition: 0.18s;
    text-decoration: none; color: inherit;
}
.sidebar-msg-item:hover { background: rgba(96, 165, 250, 0.10); }
.sidebar-msg-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--sb-blue), var(--sb-blue-light));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.68rem;
    flex-shrink: 0; overflow: hidden;
}
.sidebar-msg-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sidebar-msg-body { flex: 1; min-width: 0; }
.sidebar-msg-name {
    font-size: 0.75rem; font-weight: 700; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 1px;
}
.sidebar-msg-preview {
    font-size: 0.65rem;
    color: rgba(255, 255, 255, 0.55);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-msg-badge {
    background: var(--sb-blue);
    color: #fff;
    font-size: 0.58rem; font-weight: 800;
    min-width: 16px; height: 16px; padding: 0 5px;
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sidebar-msg-empty {
    text-align: center;
    padding: 14px 8px;
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
}
.sidebar-msg-empty i {
    display: block;
    font-size: 1.1rem;
    color: var(--sb-blue-soft);
    opacity: 0.5;
    margin-bottom: 6px;
}
.sidebar-all-messages {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    margin: 8px 2px 0;
    padding: 9px 14px;
    background: linear-gradient(135deg, var(--sb-blue), var(--sb-blue-light));
    color: #fff;
    border-radius: 30px;
    font-size: 0.72rem;
    font-weight: 700;
    text-decoration: none;
    transition: 0.2s;
    flex-shrink: 0;
}
.sidebar-all-messages:hover {
    background: linear-gradient(135deg, #2563EB, var(--sb-blue));
    color: #fff;
    transform: translateY(-1px);
}

.sidebar-spacer { flex: 1 0 12px; min-height: 12px; }
.sidebar-user-footer {
    display: flex; align-items: center; gap: 9px;
    padding: 10px 10px 8px;
    border-top: 1px solid var(--sb-border);
    margin-top: 8px;
    border-radius: 10px;
    flex-shrink: 0;
    transition: background 0.2s;
    text-decoration: none; color: inherit;
}
.sidebar-user-footer:hover { background: rgba(96, 165, 250, 0.06); }
.sidebar-user-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, var(--sb-blue), var(--sb-blue-light));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.75rem;
    flex-shrink: 0; overflow: hidden;
}
.sidebar-user-info { flex: 1; min-width: 0; }
.sidebar-user-name {
    font-size: 0.75rem; font-weight: 700; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-user-role {
    font-size: 0.6rem;
    color: rgba(147, 197, 253, 0.75);
    margin-top: 1px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}

.sidebar-logout {
    padding-top: 4px;
    flex-shrink: 0;
    margin-bottom: 4px;
}
.sidebar-logout .nav-item {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.8125rem;
    padding: 8px 11px;
    min-height: 36px;
}
.sidebar-logout .nav-item i {
    background: rgba(96, 165, 250, 0.06);
    color: rgba(147, 197, 253, 0.70);
}
.sidebar-logout .nav-item:hover {
    background: rgba(239, 68, 68, 0.12);
    color: #FCA5A5;
}
.sidebar-logout .nav-item:hover i {
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
}

.sidebar-toggle {
    display: none;
    position: fixed; top: 14px; left: 14px;
    z-index: 210;
    background: var(--sb-bg);
    border: 1px solid var(--sb-border);
    color: #FFFFFF;
    width: 40px; height: 40px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.95rem;
    align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
}
.sidebar-toggle:hover {
    background: rgba(96, 165, 250, 0.12);
    border-color: rgba(96, 165, 250, 0.30);
    color: var(--sb-blue-soft);
}
.sidebar-toggle.is-hidden { display: none; }

.sidebar-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 190;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}
.sidebar-backdrop.show {
    display: block;
    opacity: 1;
    pointer-events: auto;
}

@media (max-width: 992px) {
    .sidebar-wrap {
        width: min(82vw, 300px);
        transform: translateX(-110%);
        z-index: 200;
        padding: 20px 10px 16px;
    }
    .sidebar-wrap.open { transform: translateX(0); }
    .sidebar-wrap .nav-item {
        font-size: 0.9rem;
        min-height: 42px;
        padding: 10px 12px;
    }
    .sidebar-wrap .nav-item i {
        width: 28px; height: 28px; font-size: 0.82rem;
    }
    .sidebar-wrap .sidebar-nav { gap: 4px; }
    .sidebar-wrap .sidebar-messages { margin-top: 16px; }
    .sidebar-toggle { display: flex; }
    .sidebar-backdrop { z-index: 190; }
}
@media (max-width: 480px) {
    .sidebar-wrap { width: min(290px, 82vw) !important; padding: 14px 10px; }
    .sidebar-brand-name { font-size: 0.9rem; }
    .sidebar-brand-logo { width: 34px; height: 34px; font-size: 0.95rem; }
    .nav-item { padding: 8px 10px; font-size: 0.85rem; min-height: 38px; }
}
@media (min-width: 993px) {
    .sidebar-backdrop { display: none !important; pointer-events: none !important; }
}
</style>

<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar-backdrop" id="adminSidebarBackdrop" onclick="toggleSidebar()"></div>

<aside class="sidebar-wrap" id="adminSidebar">

    <a href="/navora3/admin/dashboard.php" class="sidebar-brand">
        <span class="sidebar-brand-logo">N</span>
        <span class="sidebar-brand-text">
            <span class="sidebar-brand-name">NAVORA</span>
            <span class="sidebar-brand-sub">ADMIN PANEL</span>
        </span>
    </a>

    <!-- ============ OVERVIEW ============ -->
    <div class="sidebar-section-label">Overview</div>
    <nav class="sidebar-nav">
        <a href="/navora3/admin/dashboard.php"
           class="nav-item <?php echo sb_active('dashboard.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i>
            <span class="nav-label">Dashboard</span>
        </a>
        <a href="/navora3/admin/analytics.php"
           class="nav-item <?php echo sb_active('analytics.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i>
            <span class="nav-label">Reports &amp; Analytics</span>
        </a>
    </nav>

    <!-- ============ MANAGEMENT ============ -->
    <div class="sidebar-section-label">Management</div>
    <nav class="sidebar-nav">
        <a href="/navora3/admin/users.php"
           class="nav-item <?php echo sb_active('users.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span class="nav-label">User Management</span>
            <?php if ($sb_pending_users > 0): ?>
                <span class="nav-badge warn"><?php echo $sb_pending_users; ?></span>
            <?php endif; ?>
        </a>

        <a href="/navora3/admin/warnings.php"
           class="nav-item <?php echo sb_active('warnings.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-exclamation-triangle"></i>
            <span class="nav-label">Warnings &amp; Compliance</span>
            <?php if ($sb_pending_warnings > 0): ?>
                <span class="nav-badge alert"><?php echo $sb_pending_warnings; ?></span>
            <?php endif; ?>
        </a>

        <a href="/navora3/admin/approved-accounts.php"
           class="nav-item <?php echo sb_active('approved-accounts.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i>
            <span class="nav-label">Approved Accounts</span>
            <?php if ($sb_approved_accounts > 0): ?>
                <span class="nav-badge success"><?php echo $sb_approved_accounts; ?></span>
            <?php endif; ?>
        </a>
        <a href="/navora3/admin/employers.php"
           class="nav-item <?php echo sb_active('employers.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-building"></i>
            <span class="nav-label">Employer Verification</span>
            <?php if ($sb_pending_employers > 0): ?>
                <span class="nav-badge warn"><?php echo $sb_pending_employers; ?></span>
            <?php endif; ?>
        </a>
        <a href="/navora3/admin/jobs.php"
           class="nav-item <?php echo sb_active('jobs.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-briefcase"></i>
            <span class="nav-label">Job Management</span>
            <?php if ($sb_pending_jobs > 0): ?>
                <span class="nav-badge"><?php echo $sb_pending_jobs; ?></span>
            <?php endif; ?>
        </a>
        <a href="/navora3/admin/applications.php"
           class="nav-item <?php echo sb_active('applications.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-file-alt"></i>
            <span class="nav-label">Application Monitoring</span>
        </a>
        <a href="/navora3/admin/reports.php"
           class="nav-item <?php echo sb_active('reports.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-flag"></i>
            <span class="nav-label">Reports &amp; Complaints</span>
            <?php if ($sb_open_reports > 0): ?>
                <span class="nav-badge alert"><?php echo $sb_open_reports; ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <!-- ============ JOB DATA ============ -->
    <div class="sidebar-section-label">Job Data</div>
    <nav class="sidebar-nav">
        <a href="/navora3/admin/categories.php"
           class="nav-item <?php echo sb_active('categories.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-tags"></i>
            <span class="nav-label">Categories</span>
        </a>
        <a href="/navora3/admin/locations.php"
           class="nav-item <?php echo sb_active('locations.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-map-marker-alt"></i>
            <span class="nav-label">Locations</span>
        </a>
        <a href="/navora3/admin/skills.php"
           class="nav-item <?php echo sb_active('skills.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-lightbulb"></i>
            <span class="nav-label">Skills</span>
        </a>
    </nav>

    <!-- ============ COMMUNICATION ============ -->
    <div class="sidebar-section-label">Communication</div>
    <nav class="sidebar-nav">
        <a href="/navora3/admin/notifications.php"
           class="nav-item <?php echo sb_active('notifications.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i>
            <span class="nav-label">Notifications</span>
            <?php if ($notif_badge > 0): ?>
                <span class="nav-badge"><?php echo $notif_badge; ?></span>
            <?php endif; ?>
        </a>
        <a href="/navora3/admin/announcements.php"
           class="nav-item <?php echo sb_active('announcements.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-bullhorn"></i>
            <span class="nav-label">Send Announcement</span>
        </a>
        <a href="/navora3/admin/messages.php"
           class="nav-item <?php echo sb_active('messages.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-comments"></i>
            <span class="nav-label">Messages</span>
            <?php if ($unread_support > 0): ?>
                <span class="nav-badge"><?php echo $unread_support; ?></span>
            <?php endif; ?>
        </a>
        <a href="/navora3/admin/support.php"
           class="nav-item <?php echo sb_active('support.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-headset"></i>
            <span class="nav-label">Support Tickets</span>
        </a>
    </nav>

    <!-- ============ SYSTEM ============ -->
    <div class="sidebar-section-label">System</div>
    <nav class="sidebar-nav">
        <a href="/navora3/admin/audit-log.php"
           class="nav-item <?php echo sb_active('audit-log.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            <span class="nav-label">Audit Logs</span>
        </a>
        <a href="/navora3/admin/security.php"
           class="nav-item <?php echo sb_active('security.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-shield-alt"></i>
            <span class="nav-label">Security &amp; Access</span>
        </a>
        <a href="/navora3/admin/settings.php"
           class="nav-item <?php echo sb_active('settings.php', $current_page) ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i>
            <span class="nav-label">System Settings</span>
        </a>
    </nav>

    <!-- ============ MESSAGES PREVIEW ============ -->
    <div class="sidebar-messages">
        <div class="sidebar-messages-header">
            <span class="sidebar-messages-title">Messages</span>
            <div class="sidebar-messages-arrows">
                <button type="button" id="msgPrev" aria-label="Previous" onclick="slideMessages(-1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button type="button" id="msgNext" aria-label="Next" onclick="slideMessages(1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>

        <div class="sidebar-msg-list" id="sidebarMsgList">
            <?php if (count($sidebar_messages) > 0): ?>
                <?php foreach ($sidebar_messages as $msg):
                    $display = $msg['display_name'] ?: 'User';
                    $photo = $msg['seeker_photo'] ?: $msg['employer_logo'];
                ?>
                    <a href="/navora3/admin/messages.php?user_id=<?php echo (int)$msg['other_user_id']; ?>" class="sidebar-msg-item">
                        <div class="sidebar-msg-avatar">
                            <?php if ($photo && file_exists('../' . $photo)): ?>
                                <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                            <?php else: ?>
                                <?php echo strtoupper(substr($display, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="sidebar-msg-body">
                            <div class="sidebar-msg-name"><?php echo htmlspecialchars($display); ?></div>
                            <div class="sidebar-msg-preview">
                                <?php echo htmlspecialchars(mb_strimwidth($msg['last_message'] ?: 'No messages yet', 0, 22, '…')); ?>
                            </div>
                        </div>
                        <?php if (($msg['unread_count'] ?? 0) > 0): ?>
                            <span class="sidebar-msg-badge"><?php echo $msg['unread_count']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sidebar-msg-empty">
                    <i class="fas fa-comment-dots"></i>
                    No messages yet
                </div>
            <?php endif; ?>
        </div>

        <a href="/navora3/admin/messages.php" class="sidebar-all-messages">
            <i class="fas fa-comments"></i> All Messages
        </a>
    </div>

    <div class="sidebar-spacer"></div>

    <a href="/navora3/admin/settings.php" class="sidebar-user-footer">
        <div class="sidebar-user-avatar"><?php echo $avatar_letter; ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($admin_name ?? 'Admin'); ?></div>
            <div class="sidebar-user-role">Administrator</div>
        </div>
    </a>

    <div class="sidebar-logout">
        <a href="/navora3/admin/logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt"></i>
            <span class="nav-label">Logout</span>
        </a>
    </div>

</aside>

<script>
(function() {
    const SIDEBAR_ID  = 'adminSidebar';
    const BACKDROP_ID = 'adminSidebarBackdrop';
    const MOBILE_BREAKPOINT = 992;
    const SCROLL_KEY = 'navora_admin_sidebar_scroll';

    function closeSidebar() {
        const sidebar  = document.getElementById(SIDEBAR_ID);
        const backdrop = document.getElementById(BACKDROP_ID);
        const toggle   = document.querySelector('.sidebar-toggle');
        if (sidebar)  sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        if (toggle)   toggle.classList.remove('is-hidden');
        document.body.style.overflow = '';
    }

    window.toggleSidebar = function() {
        const sidebar  = document.getElementById(SIDEBAR_ID);
        const backdrop = document.getElementById(BACKDROP_ID);
        const toggle   = document.querySelector('.sidebar-toggle');
        if (!sidebar || !backdrop) return;

        const isOpen = sidebar.classList.toggle('open');
        backdrop.classList.toggle('show', isOpen);
        if (toggle) toggle.classList.toggle('is-hidden', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
    };

    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href === '' || href === 'javascript:void(0)') {
            e.preventDefault();
            return;
        }
        if (href.startsWith('#')) {
            const targetId = href.slice(1);
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target) e.preventDefault();
        }
    }, true);

    const sidebarEl = document.getElementById(SIDEBAR_ID);
    if (sidebarEl) {
        try {
            const saved = sessionStorage.getItem(SCROLL_KEY);
            if (saved !== null) sidebarEl.scrollTop = parseInt(saved, 10) || 0;
        } catch (err) {}
        sidebarEl.addEventListener('scroll', function() {
            try { sessionStorage.setItem(SCROLL_KEY, String(sidebarEl.scrollTop)); } catch (err) {}
        }, { passive: true });
    }

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth > MOBILE_BREAKPOINT) closeSidebar();
        }, 100);
    });

    if (window.innerWidth > MOBILE_BREAKPOINT) closeSidebar();

    document.querySelectorAll('.sidebar-wrap a').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= MOBILE_BREAKPOINT) {
                document.body.style.overflow = '';
            }
        });
    });

    window.addEventListener('pageshow', function() {
        document.body.style.overflow = '';
        closeSidebar();
    });

    /* Messages slideshow */
    const msgList = document.getElementById('sidebarMsgList');
    const messages = msgList ? Array.from(msgList.querySelectorAll('.sidebar-msg-item')) : [];
    let currentMsgIndex = 0;
    const MSG_PER_PAGE = 2;

    function renderMessages() {
        if (!msgList || messages.length === 0) return;
        messages.forEach((m, i) => {
            m.style.display = (i >= currentMsgIndex && i < currentMsgIndex + MSG_PER_PAGE) ? '' : 'none';
        });
        const prev = document.getElementById('msgPrev');
        const next = document.getElementById('msgNext');
        if (prev) prev.disabled = (currentMsgIndex <= 0);
        if (next) next.disabled = (currentMsgIndex + MSG_PER_PAGE >= messages.length);
    }

    window.slideMessages = function(dir) {
        const maxStart = Math.max(0, messages.length - MSG_PER_PAGE);
        currentMsgIndex = Math.min(Math.max(currentMsgIndex + dir, 0), maxStart);
        renderMessages();
    };

    renderMessages();
})();
</script>