<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$page_title    = $page_title    ?? 'Admin';
$page_subtitle = $page_subtitle ?? '';
$breadcrumb    = $breadcrumb    ?? 'Admin';
$extra_css     = $extra_css     ?? '';

$admin_name    = $_SESSION['name'] ?? $_SESSION['email'] ?? 'Admin';
$avatar_letter = strtoupper(substr($admin_name, 0, 1));
$current_page  = basename($_SERVER['PHP_SELF']);

$notif_count              = $notif_count              ?? 0;
$unread_messages          = $unread_messages          ?? 0;
$pending_users_count      = $pending_users_count      ?? 0;
$incomplete_profiles_count = $incomplete_profiles_count ?? 0;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $notif_count = (int) $stmt->fetchColumn();
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unread_messages = (int) $stmt->fetchColumn();
    } catch (Exception $e) {}

    /* Pending user registrations — alerts admin to approve / warn */
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role != 'admin'");
        $pending_users_count = (int) $stmt->fetchColumn();
    } catch (Exception $e) { $pending_users_count = 0; }

    /* Check for incomplete profiles */
    try {
        $incomplete_count = 0;
        // Check Job Seekers
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM users u
            JOIN job_seekers s ON u.id = s.user_id
            WHERE u.status = 'active' 
            AND (s.first_name = '' OR s.first_name IS NULL 
                 OR s.last_name = '' OR s.last_name IS NULL 
                 OR s.phone = '' OR s.phone IS NULL)
        ");
        $incomplete_count += (int) $stmt->fetchColumn();
        
        // Check Employers
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM users u
            JOIN employers e ON u.id = e.user_id
            WHERE u.status = 'active' 
            AND (e.company_name = '' OR e.company_name IS NULL 
                 OR e.phone = '' OR e.phone IS NULL)
        ");
        $incomplete_count += (int) $stmt->fetchColumn();
        
        $incomplete_profiles_count = $incomplete_count;
    } catch (Exception $e) { 
        $incomplete_profiles_count = 0; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo htmlspecialchars($page_title); ?> - NAVORA Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&family=Source+Sans+3:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   NAVORA ADMIN — MASTER CSS (matches EMPLOYER panel)
   ============================================================ */
:root {
    --sidebar-width: 248px;
    --bg-deep:        #060B1F;
    --bg-mid:         #0A1330;
    --card-bg:        #0B1A33;

    --blue:           #3B82F6;
    --blue-light:     #60A5FA;
    --blue-soft:      #93C5FD;
    --cyan:           #38BDF8;
    --green:          #10B981;
    --amber:          #F59E0B;
    --violet:         #8B5CF6;
    --pink:           #EC4899;
    --red:            #EF4444;

    --text-primary:   #FFFFFF;
    --text-secondary: rgba(255, 255, 255, 0.75);
    --text-muted:     rgba(255, 255, 255, 0.55);
    --text-dim:       rgba(255, 255, 255, 0.35);

    --radius-lg: 16px;
    --radius-md: 12px;
    --radius-sm: 10px;

    --topbar-h:  64px;
    --content-max: 1600px;

    --fs-hero:       2rem;
    --fs-card-title: 1rem;
    --fs-body:       0.875rem;
    --fs-sm:         0.8125rem;
    --fs-label:      0.6875rem;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
*, *::before, *::after { min-width: 0; }

html { background: var(--bg-deep); }

body {
    font-family: 'Source Sans 3', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: var(--fs-body);
    line-height: 1.5;
    color: var(--text-primary);
    background: var(--bg-deep);
    position: relative;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-color: #060B1F;
    background-image:
        radial-gradient(ellipse 900px 600px at 15% 10%, rgba(74, 127, 255, 0.18), transparent 60%),
        radial-gradient(ellipse 800px 700px at 85% 20%, rgba(107, 78, 255, 0.14), transparent 60%),
        radial-gradient(ellipse 700px 500px at 50% 100%, rgba(56, 189, 248, 0.10), transparent 60%),
        linear-gradient(180deg, #060B1F 0%, #0A1330 50%, #060B1F 100%);
    z-index: -2;
    pointer-events: none;
}

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
        radial-gradient(1px 1px at 12% 20%, rgba(255,255,255,0.85), transparent),
        radial-gradient(1px 1px at 28% 68%, rgba(255,255,255,0.6), transparent),
        radial-gradient(1.5px 1.5px at 45% 12%, rgba(255,255,255,0.8), transparent),
        radial-gradient(1px 1px at 62% 45%, rgba(255,255,255,0.5), transparent),
        radial-gradient(1px 1px at 78% 82%, rgba(255,255,255,0.7), transparent),
        radial-gradient(1.5px 1.5px at 88% 28%, rgba(255,255,255,0.85), transparent),
        radial-gradient(1px 1px at 35% 92%, rgba(255,255,255,0.6), transparent),
        radial-gradient(1px 1px at 55% 30%, rgba(255,255,255,0.55), transparent),
        radial-gradient(1px 1px at 72% 62%, rgba(255,255,255,0.7), transparent),
        radial-gradient(1px 1px at 20% 45%, rgba(255,255,255,0.5), transparent);
    background-repeat: repeat;
    background-size: 1400px 900px;
    z-index: -1;
    pointer-events: none;
    opacity: 0.9;
}

a { text-decoration: none; color: inherit; }
img { max-width: 100%; height: auto; }

.layout { display: flex; min-height: 100vh; }

.main {
    flex: 1;
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    min-width: 0;
    display: flex;
    flex-direction: column;
    padding-bottom: 32px;
}

/* TOPBAR */
.topbar {
    background: transparent;
    border-bottom: 1px solid rgba(120, 160, 255, 0.08);
    padding: 0 24px;
    height: var(--topbar-h);
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px;
    position: sticky; top: 0; z-index: 150;
    max-width: 100%;
    box-shadow: none;
}
.topbar-left { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
.topbar-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.topbar-search { flex: 1; max-width: 420px; min-width: 0; position: relative; }
.topbar-search input {
    width: 100%; height: 40px;
    padding: 0 40px 0 40px;
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 40px;
    font-size: var(--fs-body);
    font-family: inherit;
    background: rgba(20, 32, 68, 0.90);
    color: #fff;
    outline: none;
    transition: 0.2s;
}
.topbar-search input::placeholder { color: rgba(255, 255, 255, 0.45); }
.topbar-search input:focus {
    border-color: rgba(96, 165, 250, 0.55);
    background: rgba(20, 32, 68, 0.95);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.topbar-search > i {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.85rem;
    pointer-events: none;
}

.icon-btn {
    position: relative;
    width: 40px; height: 40px; flex-shrink: 0;
    border-radius: 50%;
    background: rgba(20, 32, 68, 0.90);
    border: 1px solid rgba(120, 160, 255, 0.15);
    display: flex; align-items: center; justify-content: center;
    color: rgba(255, 255, 255, 0.80);
    font-size: 0.92rem;
    transition: 0.2s; cursor: pointer;
}
.icon-btn:hover {
    background: rgba(20, 32, 68, 0.95);
    border-color: rgba(96, 165, 250, 0.30);
    color: #fff;
}
.icon-btn .count-badge {
    position: absolute; top: -4px; right: -4px;
    background: #EF4444; color: #fff;
    font-size: 10px; font-weight: 800;
    padding: 2px 6px; border-radius: 20px;
    min-width: 18px; text-align: center;
    border: 2px solid #060B1F;
}
/* Special blue badge for messages */
.icon-btn .count-badge.badge-blue {
    background: #3B82F6;
}
/* Amber badge for pending users awaiting approval */
.icon-btn .count-badge.badge-amber {
    background: #F59E0B;
    color: #1E293B;
    animation: pendingPulse 1.8s ease-in-out infinite;
}
@keyframes pendingPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6); }
    50%      { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
}

/* Pending-users button (amber accent so it stands out from the bell) */
.icon-btn.pending-users-btn {
    background: rgba(245, 158, 11, 0.10);
    border-color: rgba(245, 158, 11, 0.35);
    color: #FCD34D;
}
.icon-btn.pending-users-btn:hover {
    background: rgba(245, 158, 11, 0.18);
    border-color: rgba(245, 158, 11, 0.55);
    color: #fff;
}

.topbar-user {
    display: flex; align-items: center; gap: 8px;
    padding: 3px 14px 3px 3px;
    background: rgba(20, 32, 68, 0.90);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: 40px;
    font-size: var(--fs-sm);
    max-width: 220px;
    cursor: pointer; font-family: inherit;
    transition: 0.2s;
    color: #fff;
}
.topbar-user:hover {
    background: rgba(20, 32, 68, 0.95);
    border-color: rgba(96, 165, 250, 0.30);
}
.topbar-user-avatar {
    width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.76rem;
    overflow: hidden;
}
.topbar-user-name {
    font-weight: 700; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* PAGE CONTENT + HERO */
.page-content {
    padding: 24px 28px 0;
    flex: 1;
    width: 100%;
    max-width: 100%;
}
.page-inner {
    max-width: var(--content-max);
    margin: 0 auto;
    width: 100%;
}

.page-header {
    position: relative;
    border-radius: var(--radius-lg);
    padding: 28px 36px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 20px;
    overflow: hidden;
    background-color: #0B1F44;
    background-image: url('https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: none;
    min-height: 140px;
}
.ph-content {
    display: flex;
    align-items: center;
    gap: 20px;
    min-width: 0;
    width: 100%;
    position: relative;
    z-index: 2;
}
.ph-text { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
.ph-text h1 {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: var(--fs-hero);
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
    margin-bottom: 6px;
    letter-spacing: -0.4px;
    text-shadow:
        0 2px 12px rgba(0, 0, 0, 0.85),
        0 1px 3px rgba(0, 0, 0, 0.9),
        0 0 1px rgba(0, 0, 0, 1);
}
.ph-text p {
    color: #FFFFFF;
    font-size: var(--fs-body);
    font-weight: 500;
    line-height: 1.5;
    max-width: 620px;
    text-shadow:
        0 1px 8px rgba(0, 0, 0, 0.85),
        0 1px 2px rgba(0, 0, 0, 0.9);
}
.ph-breadcrumb {
    font-size: var(--fs-label);
    font-weight: 700;
    color: var(--blue-soft);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    text-shadow:
        0 1px 8px rgba(0, 0, 0, 0.85),
        0 1px 2px rgba(0, 0, 0, 0.9);
}
.ph-breadcrumb i { color: var(--blue-light); font-size: 0.62rem; }

/* STAT TILES */
.stats-grid, .admin-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.stat-tile, .admin-stat {
    position: relative;
    padding: 20px 14px !important;
    min-height: 108px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    text-decoration: none !important;
    border-radius: 16px !important;
    overflow: hidden;
    border: 1px solid rgba(147, 197, 253, 0.55) !important;
    background:
        radial-gradient(ellipse 70% 50% at 85% 20%, rgba(255,255,255,0.95) 0%, rgba(219,234,254,0.60) 30%, transparent 70%),
        radial-gradient(ellipse 80% 60% at 50% 55%, rgba(255,255,255,0.55) 0%, rgba(191,219,254,0.35) 40%, transparent 75%),
        radial-gradient(ellipse 60% 70% at 5% 100%, rgba(96,165,250,0.60) 0%, rgba(147,197,253,0.30) 40%, transparent 80%),
        linear-gradient(135deg, #93C5FD 0%, #BFDBFE 20%, #E0F2FE 45%, #BFDBFE 65%, #93C5FD 85%, #60A5FA 100%) !important;
    box-shadow: 0 4px 20px rgba(96, 165, 250, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.60) !important;
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    transition: none !important;
    cursor: pointer;
}
.stat-tile:hover, .stat-tile:active, .stat-tile:focus,
.admin-stat:hover, .admin-stat:active, .admin-stat:focus {
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    transition: none !important;
    border-color: rgba(147, 197, 253, 0.55) !important;
    box-shadow: 0 4px 20px rgba(96, 165, 250, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.60) !important;
}
.stat-tile-label, .admin-stat-label {
    font-size: 0.7rem;
    font-weight: 800;
    color: rgba(30, 58, 138, 0.80) !important;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    line-height: 1.2;
    text-align: center;
    width: 100%;
    text-shadow: 0 1px 1px rgba(255, 255, 255, 0.60);
    position: relative;
    z-index: 2;
}
.stat-tile-value, .admin-stat-value {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: 1.85rem;
    font-weight: 700;
    color: #1E3A8A !important;
    letter-spacing: -0.5px;
    line-height: 1;
    text-align: center;
    text-shadow: 0 1px 2px rgba(255, 255, 255, 0.75);
    position: relative;
    z-index: 2;
}
.stat-tile-sub, .admin-stat-sub {
    font-size: 0.65rem;
    font-weight: 600;
    color: rgba(30, 58, 138, 0.70) !important;
    text-align: center;
    position: relative;
    z-index: 2;
    margin-top: 2px;
    text-shadow: 0 1px 1px rgba(255, 255, 255, 0.55);
}
.admin-stat-top {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    position: relative;
    z-index: 2;
    width: 100%;
}
.admin-stat-icon {
    width: 30px; height: 30px;
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.85);
    color: #1E3A8A;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    flex-shrink: 0;
    box-shadow: 0 2px 4px rgba(15, 27, 51, 0.08);
}

/* CARDS */
.card, .card-panel, .table-panel {
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: var(--radius-lg);
    padding: 22px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-bottom: 18px;
    position: relative;
    overflow: hidden;
}
.card:last-child, .card-panel:last-child, .table-panel:last-child { margin-bottom: 0; }
.card-panel, .table-panel { padding: 0; }

.card-panel-head, .table-panel-head, .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    background: rgba(120, 160, 255, 0.04);
    margin: 0;
    flex-wrap: wrap;
}
.card-panel-title, .table-panel-title {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: var(--fs-card-title);
    font-weight: 700;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.1px;
    line-height: 1.2;
}
.card-panel-title-icon, .table-panel-title-icon, .card-head-icon {
    width: 32px; height: 32px;
    border-radius: 9px;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    flex-shrink: 0;
}
.card-panel-sub, .table-panel-sub {
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.55);
}
.card-panel > *:not(.card-panel-head):not(.table-panel-head) {
    padding-left: 22px;
    padding-right: 22px;
}
.card-panel > *:first-child:not(.card-panel-head) { padding-top: 18px; }
.card-panel > *:last-child:not(.card-panel-head) { padding-bottom: 22px; }
.card-panel > .card-panel-head + * { padding-top: 18px; }
.card-panel > .table-wrap,
.card-panel > .users-table-wrap,
.card-panel > .employers-table-wrap {
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* BADGES */
.badge-soft {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    border: 1px solid;
}
.badge-soft::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.badge-soft.pending     { background: rgba(245,158,11,0.15); color: #FCD34D; border-color: rgba(245,158,11,0.35); }
.badge-soft.reviewed    { background: rgba(96,165,250,0.15); color: #93C5FD; border-color: rgba(96,165,250,0.35); }
.badge-soft.shortlisted { background: rgba(139,92,246,0.15); color: #C4B5FD; border-color: rgba(139,92,246,0.35); }
.badge-soft.interview   { background: rgba(6,182,212,0.15);  color: #67E8F9; border-color: rgba(6,182,212,0.35); }
.badge-soft.accepted,
.badge-soft.success     { background: rgba(16,185,129,0.15); color: #6EE7B7; border-color: rgba(16,185,129,0.35); }
.badge-soft.rejected,
.badge-soft.danger      { background: rgba(239,68,68,0.15);  color: #FCA5A5; border-color: rgba(239,68,68,0.35); }
.badge-soft.warning     { background: rgba(245,158,11,0.15); color: #FCD34D; border-color: rgba(245,158,11,0.35); }
.badge-soft.info        { background: rgba(96,165,250,0.15); color: #93C5FD; border-color: rgba(96,165,250,0.35); }
.badge-soft.violet      { background: rgba(139,92,246,0.15); color: #C4B5FD; border-color: rgba(139,92,246,0.35); }
.badge-soft.secondary   { background: rgba(148,163,184,0.15); color: #CBD5E1; border-color: rgba(148,163,184,0.35); }

/* BUTTONS */
.btn-soft {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: var(--fs-sm);
    font-weight: 700;
    border: 1px solid rgba(120, 160, 255, 0.20);
    background: rgba(20, 32, 68, 0.90);
    color: #fff;
    cursor: pointer;
    font-family: inherit;
    transition: 0.2s;
    text-decoration: none;
    white-space: nowrap;
}
.btn-soft:hover {
    border-color: rgba(96, 165, 250, 0.40);
    background: rgba(20, 32, 68, 0.95);
    transform: translateY(-1px);
}
.btn-soft.primary {
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff;
    border-color: transparent;
}
.btn-soft.primary:hover {
    background: linear-gradient(135deg, #2563EB, var(--blue));
    color: #fff;
}
.btn-soft.danger { color: #FCA5A5; border-color: rgba(239,68,68,0.30); background: rgba(239,68,68,0.10); }
.btn-soft.danger:hover { background: rgba(239,68,68,0.18); }
.btn-soft.success { color: #6EE7B7; border-color: rgba(16,185,129,0.30); background: rgba(16,185,129,0.10); }
.btn-soft.success:hover { background: rgba(16,185,129,0.18); }
.btn-soft.warning { color: #FCD34D; border-color: rgba(245,158,11,0.30); background: rgba(245,158,11,0.10); }
.btn-soft.warning:hover { background: rgba(245,158,11,0.18); }

.btn-icon-sm {
    width: 30px; height: 30px;
    border-radius: 9px;
    background: rgba(20, 32, 68, 0.90);
    border: 1px solid rgba(120, 160, 255, 0.18);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: 0.2s;
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.72rem;
    text-decoration: none;
}
.btn-icon-sm:hover {
    border-color: rgba(96, 165, 250, 0.5);
    color: var(--blue-soft);
    background: rgba(96, 165, 250, 0.12);
}
.btn-icon-sm.danger:hover { border-color: #EF4444; color: #FCA5A5; background: rgba(239,68,68,0.10); }
.btn-icon-sm.success:hover { border-color: #10B981; color: #6EE7B7; background: rgba(16,185,129,0.10); }

/* FORMS */
.form-label, .form-label-sm {
    display: block;
    font-weight: 700;
    font-size: 12.5px;
    color: rgba(255, 255, 255, 0.75);
    margin-bottom: 7px;
}
.form-input, .form-select, .form-textarea, .form-input-sm, .form-select-sm-custom {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: var(--radius-sm);
    font-size: 13.5px;
    color: #fff;
    font-family: inherit;
    outline: none;
    background: rgba(20, 32, 68, 0.90);
    transition: 0.2s;
}
.form-input::placeholder, .form-textarea::placeholder, .form-input-sm::placeholder {
    color: rgba(255, 255, 255, 0.45);
}
.form-input:focus, .form-select:focus, .form-textarea:focus,
.form-input-sm:focus, .form-select-sm-custom:focus {
    border-color: rgba(96, 165, 250, 0.55);
    background: rgba(20, 32, 68, 0.95);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.form-textarea { resize: vertical; min-height: 90px; line-height: 1.55; }
.form-select option, .form-select-sm-custom option { background: #0A1330; color: #fff; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { margin-bottom: 16px; }
.form-hint {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.55);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}
.form-hint i { color: #93C5FD; font-size: 0.68rem; }

/* TABLES */
.table-wrap { overflow-x: auto; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    min-width: 720px;
}
.data-table thead th {
    text-align: left;
    padding: 12px 18px;
    font-size: 0.65rem;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.7px;
    background: rgba(120, 160, 255, 0.04);
    border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    white-space: nowrap;
}
.data-table tbody td {
    padding: 14px 18px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.08);
    vertical-align: middle;
    color: rgba(255, 255, 255, 0.85);
}
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(96, 165, 250, 0.04); }

.table-select {
    padding: 7px 26px 7px 12px;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 700;
    font-family: inherit;
    border: 1px solid rgba(120, 160, 255, 0.20);
    background-color: rgba(20, 32, 68, 0.90);
    cursor: pointer;
    appearance: none;
    outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%2393C5FD' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    color: #fff;
}
.table-select:focus { border-color: rgba(96, 165, 250, 0.5); box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15); }
.table-select option { background: #0A1330; color: #fff; }

/* FILTER BAR */
.filter-bar {
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: var(--radius-lg);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.filter-icon {
    width: 34px; height: 34px;
    border-radius: 9px;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.filter-label { font-size: 13px; font-weight: 700; color: #fff; white-space: nowrap; }
.filter-select {
    padding: 8px 32px 8px 14px;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 30px;
    font-size: 12.5px;
    font-weight: 600;
    font-family: inherit;
    color: #fff;
    background-color: rgba(20, 32, 68, 0.90);
    cursor: pointer;
    outline: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2393C5FD' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    min-width: 160px;
}
.filter-select:focus { border-color: rgba(96, 165, 250, 0.55); box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15); }
.filter-select option { background: #0A1330; color: #fff; }

.filter-pill {
    padding: 7px 14px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.65);
    background: rgba(20, 32, 68, 0.90);
    border: 1px solid rgba(120, 160, 255, 0.15);
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    white-space: nowrap;
}
.filter-pill:hover {
    color: #fff;
    border-color: rgba(96, 165, 250, 0.35);
    background: rgba(96, 165, 250, 0.10);
}
.filter-pill.active {
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff;
    border-color: transparent;
}
.filter-pill .pill-count {
    font-weight: 800;
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.15);
}
.filter-search { position: relative; flex: 1; min-width: 200px; }
.filter-search input {
    width: 100%;
    padding: 9px 14px 9px 36px;
    border: 1px solid rgba(120, 160, 255, 0.20);
    border-radius: 30px;
    font-size: 12.5px;
    font-family: inherit;
    background: rgba(20, 32, 68, 0.90);
    color: #fff;
    outline: none;
    transition: 0.2s;
}
.filter-search input::placeholder { color: rgba(255, 255, 255, 0.45); }
.filter-search input:focus {
    border-color: rgba(96, 165, 250, 0.55);
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
}
.filter-search i {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.55);
    font-size: 12px;
    pointer-events: none;
}

/* ALERTS */
.flash-msg, .alert-soft.success {
    background: rgba(16, 185, 129, 0.10);
    border: 1px solid rgba(16, 185, 129, 0.30);
    color: #6EE7B7;
    padding: 12px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}
.alert-error, .alert-soft.danger {
    background: rgba(239, 68, 68, 0.10);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #FCA5A5;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-size: 13px;
    font-weight: 500;
}
.alert-error ul, .alert-soft.danger ul { margin: 0; padding-left: 20px; }

/* EMPTY STATE */
.empty-state { text-align: center; padding: 60px 20px; }
.empty-icon {
    width: 70px; height: 70px;
    border-radius: 50%;
    background: rgba(96, 165, 250, 0.12);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: var(--blue-soft);
    margin-bottom: 14px;
}
.empty-state h4 {
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 6px;
}
.empty-state p {
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 18px;
    line-height: 1.5;
}

/* UTILITY */
.count-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 22px;
    padding: 0 10px;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.72rem;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 6px;
}
.grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
    margin-bottom: 18px;
}
.panel-link {
    color: #93C5FD;
    font-size: 0.74rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: 0.2s;
    text-decoration: none;
}
.panel-link:hover { color: #60A5FA; gap: 8px; }

/* RESPONSIVE */
@media (max-width: 1100px) {
    .grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 992px) {
    .main { margin-left: 0 !important; }
    .page-content { padding: 18px 18px 0; }
    .topbar { padding: 0 18px 0 68px; }
    .topbar-search { max-width: 240px; }
    .topbar-user { max-width: 150px; }
    .stats-grid, .admin-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    .page-content { padding: 16px 16px 0; }
    .topbar { padding: 0 14px 0 62px; }
    .topbar-search { display: none; }
    .topbar-user-name { display: none; }
    .topbar-user { padding: 3px; }
    .ph-text h1 { font-size: 1.5rem; }
    .page-header { padding: 20px; gap: 14px; min-height: 120px; }
    .form-row-2 { grid-template-columns: 1fr; gap: 0; }
    .card { padding: 18px; }
    .filter-bar { flex-direction: column; align-items: stretch; }
}
@media (max-width: 480px) {
    .page-content { padding: 14px 14px 0; }
    .page-header { min-height: 110px; padding: 16px; }
    .ph-text h1 { font-size: 1.25rem; }
    .ph-text p { font-size: var(--fs-sm); }
    .topbar-user { max-width: 42px; padding: 3px; }
    .topbar-user-avatar { width: 32px; height: 32px; font-size: 0.78rem; }
    .icon-btn { width: 38px; height: 38px; }
    .topbar { height: 60px; padding: 0 14px 0 58px; }
    .stats-grid, .admin-stats { grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-tile, .admin-stat { min-height: 92px; padding: 14px 10px !important; }
    .stat-tile-value, .admin-stat-value { font-size: 1.4rem; }
    .card { padding: 16px; }
}
</style>
<?php echo $extra_css; ?>
</head>
<body>

<div class="layout">

    <?php include '_sidebar.php'; ?>

    <div class="main">

        <div class="topbar">
            <div class="topbar-left">
                <form class="topbar-search" method="GET" action="/navora3/admin/search.php">
                    <i class="fas fa-search"></i>
                    <input type="text"
                           name="q"
                           id="topSearch"
                           placeholder="Search users, jobs, applications..."
                           autocomplete="off">
                </form>
            </div>
            <div class="topbar-actions">

                <?php if ($pending_users_count > 0): ?>
                    <a href="/navora3/admin/users.php?status=pending"
                       class="icon-btn pending-users-btn"
                       id="pendingUsersBtn"
                       title="<?php echo $pending_users_count; ?> user(s) awaiting approval">
                        <i class="fas fa-user-clock"></i>
                        <span class="count-badge badge-amber" id="pendingUsersBadge">
                            <?php echo $pending_users_count > 99 ? '99+' : $pending_users_count; ?>
                        </span>
                    </a>
                <?php endif; ?>

                <!-- ✅ NEW: Incomplete Profiles Alert -->
                <?php if ($incomplete_profiles_count > 0): ?>
                    <a href="/navora3/admin/approved-accounts.php?filter=incomplete"
                       class="icon-btn pending-users-btn"
                       id="incompleteProfilesBtn"
                       style="background: rgba(236, 72, 153, 0.1); border-color: rgba(236, 72, 153, 0.35); color: #F472B6;"
                       title="<?php echo $incomplete_profiles_count; ?> user(s) with incomplete profiles">
                        <i class="fas fa-user-edit"></i>
                        <span class="count-badge" style="background: #EC4899; color: #fff;">
                            <?php echo $incomplete_profiles_count > 99 ? '99+' : $incomplete_profiles_count; ?>
                        </span>
                    </a>
                <?php endif; ?>

                <a href="/navora3/admin/messages.php" class="icon-btn" title="Messages">
                    <i class="fas fa-comment-dots"></i>
                    <?php if (!empty($unread_messages) && $unread_messages > 0): ?>
                        <span class="count-badge badge-blue"><?php echo $unread_messages > 99 ? '99+' : $unread_messages; ?></span>
                    <?php endif; ?>
                </a>

                <a href="/navora3/admin/notifications.php" class="icon-btn" id="notifToggle" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($notif_count > 0): ?>
                        <span class="count-badge"><?php echo $notif_count > 99 ? '99+' : $notif_count; ?></span>
                    <?php endif; ?>
                </a>

                <a href="/navora3/admin/settings.php" class="topbar-user">
                    <div class="topbar-user-avatar"><?php echo $avatar_letter; ?></div>
                    <span class="topbar-user-name"><?php echo htmlspecialchars($admin_name); ?></span>
                </a>
            </div>
        </div>

        <div class="page-content">
            <div class="page-inner">

                <div class="page-header">
                    <div class="ph-content">
                        <div class="ph-text">
                            <div class="ph-breadcrumb">
                                <i class="fas fa-home"></i> <?php echo htmlspecialchars($breadcrumb); ?>
                            </div>
                            <h1><?php echo htmlspecialchars($page_title); ?></h1>
                            <?php if ($page_subtitle): ?>
                                <p><?php echo htmlspecialchars($page_subtitle); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>