<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Admin Dashboard';
$user_id = $_SESSION['user_id'];

/* ============ STATS ============ */
$stats = [
    'total_users'             => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
    'total_employers'         => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employer'")->fetchColumn(),
    'active_jobs'             => (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'approved'")->fetchColumn(),
    'pending_jobs'            => (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn(),
    'pending_verifications'   => (int) $pdo->query("SELECT COUNT(*) FROM employers WHERE verification_status = 'pending'")->fetchColumn(),
    'total_applications'      => (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
    'reported_accounts'       => 0,
    'reported_jobs'           => 0,
];
try {
    $stats['reported_accounts'] = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE reported_user_id IS NOT NULL AND status = 'open'")->fetchColumn();
    $stats['reported_jobs']     = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE job_id IS NOT NULL AND status = 'open'")->fetchColumn();
} catch (PDOException $e) { }

/* ============ CHART DATA ============ */
$chart_labels = [];
$chart_apps   = [];
$chart_jobs   = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chart_labels[] = date('M', strtotime($month . '-01'));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE DATE_FORMAT(applied_at,'%Y-%m') = ?");
    $stmt->execute([$month]);
    $chart_apps[] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE DATE_FORMAT(created_at,'%Y-%m') = ?");
    $stmt->execute([$month]);
    $chart_jobs[] = (int) $stmt->fetchColumn();
}

/* ============ STATUS BREAKDOWN ============ */
$status_data = [];
foreach (['pending','reviewed','shortlisted','interview','accepted','rejected'] as $st) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE status = ?");
    $stmt->execute([$st]);
    $status_data[$st] = (int) $stmt->fetchColumn();
}

/* ============ RECENT JOBS ============ */
$recent_jobs = $pdo->query("
    SELECT j.id, j.job_title, j.status, j.created_at, e.company_name
    FROM jobs j
    JOIN employers e ON j.employer_id = e.id
    ORDER BY j.created_at DESC
    LIMIT 5
")->fetchAll();

/* ============ PENDING EMPLOYERS ============ */
$pending_employers = $pdo->query("
    SELECT u.id AS user_id, u.email, u.created_at, e.company_name
    FROM users u
    LEFT JOIN employers e ON e.user_id = u.id
    WHERE u.role = 'employer' AND e.verification_status = 'pending'
    ORDER BY u.created_at DESC
    LIMIT 5
")->fetchAll();

/* ============ HEADER VARS ============ */
$page_subtitle  = 'Overview of everything happening on NAVORA.';
$page_icon      = 'fa-chart-line';
$page_theme     = 'violet';
$header_script  = 'Admin at a glance 📊';
$breadcrumb     = 'Admin / Dashboard';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';

include '_header.php';
?>

<!-- ============ ROW 1: 8 MINI STAT CARDS ============ -->
<div class="mini-stats-grid">

    <a href="/navora3/admin/users.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Users</div>
        <div class="mini-tile-value"><?php echo number_format($stats['total_users']); ?></div>
    </a>

    <a href="/navora3/admin/employers.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Employers</div>
        <div class="mini-tile-value"><?php echo number_format($stats['total_employers']); ?></div>
    </a>

    <a href="/navora3/admin/jobs.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Active Jobs</div>
        <div class="mini-tile-value"><?php echo number_format($stats['active_jobs']); ?></div>
    </a>

    <a href="/navora3/admin/jobs.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Pending Jobs</div>
        <div class="mini-tile-value"><?php echo number_format($stats['pending_jobs']); ?></div>
    </a>

    <a href="/navora3/admin/employers.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Verifications</div>
        <div class="mini-tile-value"><?php echo number_format($stats['pending_verifications']); ?></div>
    </a>

    <a href="/navora3/admin/applications.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Applications</div>
        <div class="mini-tile-value"><?php echo number_format($stats['total_applications']); ?></div>
    </a>

    <a href="/navora3/admin/reports.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Reports</div>
        <div class="mini-tile-value"><?php echo number_format($stats['reported_accounts']); ?></div>
    </a>

    <a href="/navora3/admin/reports.php" class="admin-stat mini-tile">
        <div class="mini-tile-label">Flagged</div>
        <div class="mini-tile-value"><?php echo number_format($stats['reported_jobs']); ?></div>
    </a>

</div>

<!-- ============ ROW 2: CHARTS ============ -->
<div class="dash-charts-row">
    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-chart-line"></i></span>
                Applications &amp; Jobs Trend
            </div>
            <div class="panel-hint">Last 6 months</div>
        </div>
        <div class="chart-wrap" style="height: 220px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-chart-pie"></i></span>
                Application Status
            </div>
        </div>
        <div class="chart-wrap" style="height: 160px;">
            <canvas id="statusChart"></canvas>
        </div>
        <div class="status-legend" style="margin-top: 12px;">
            <?php
            $status_blue_shades = [
                'pending'     => '#3B82F6',
                'reviewed'    => '#60A5FA',
                'shortlisted' => '#93C5FD',
                'interview'   => '#2563EB',
                'accepted'    => '#10B981',
                'rejected'    => '#EF4444',
            ];
            foreach ($status_data as $st => $count): ?>
                <div class="status-legend-item">
                    <span class="status-legend-dot" style="background: <?php echo $status_blue_shades[$st]; ?>;"></span>
                    <span class="status-legend-label"><?php echo ucfirst($st); ?></span>
                    <span class="status-legend-count"><?php echo $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============ ROW 3: RECENT JOB POSTS + PENDING EMPLOYERS ============ -->
<div class="dash-row-6-6">
    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-briefcase"></i></span>
                Recent Job Posts
            </div>
            <a href="/navora3/admin/jobs.php" class="panel-link">
                View All <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <?php if (count($recent_jobs) > 0): ?>
            <div class="crypto-list">
                <?php foreach ($recent_jobs as $job):
                    $sBadge = 'pending';
                    if ($job['status'] === 'approved') $sBadge = 'active';
                    elseif ($job['status'] === 'rejected') $sBadge = 'inactive';
                    elseif ($job['status'] === 'closed') $sBadge = 'closed';
                ?>
                    <div class="crypto-list-item">
                        <div class="crypto-list-icon" style="background: linear-gradient(135deg, #3B82F6, #60A5FA);">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="crypto-list-body">
                            <div class="crypto-list-title"><?php echo htmlspecialchars($job['job_title']); ?></div>
                            <div class="crypto-list-meta">
                                <?php echo htmlspecialchars($job['company_name']); ?>
                                <span class="dot-sep">·</span>
                                <?php echo timeAgo($job['created_at']); ?>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $sBadge; ?>">
                            <span class="status-dot"></span>
                            <?php echo ucfirst($job['status']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:32px 16px;">
                <i class="fas fa-briefcase"></i>
                <h4>No jobs yet</h4>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-user-check"></i></span>
                Pending Employers
            </div>
            <a href="/navora3/admin/employers.php" class="panel-link">
                View All <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <?php if (count($pending_employers) > 0): ?>
            <div class="crypto-list">
                <?php foreach ($pending_employers as $emp):
                    $initial = strtoupper(substr($emp['company_name'] ?: $emp['email'], 0, 1));
                ?>
                    <div class="crypto-list-item">
                        <div class="crypto-list-icon" style="background: linear-gradient(135deg, #8B5CF6, #A78BFA);">
                            <?php echo htmlspecialchars($initial); ?>
                        </div>
                        <div class="crypto-list-body">
                            <div class="crypto-list-title"><?php echo htmlspecialchars($emp['company_name'] ?: 'Employer'); ?></div>
                            <div class="crypto-list-meta">
                                <?php echo htmlspecialchars($emp['email']); ?>
                                <span class="dot-sep">·</span>
                                <?php echo timeAgo($emp['created_at']); ?>
                            </div>
                        </div>
                        <a href="/navora3/admin/employers.php" class="crypto-list-action">
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:32px 16px;">
                <i class="fas fa-user-check"></i>
                <h4>All caught up</h4>
                <p>No pending employer verifications.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ DASHBOARD LAYOUT CSS ============ -->
<style>
/* ============================================================
   DASHBOARD LAYOUT
   ============================================================ */

.mini-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-auto-rows: 1fr;
    gap: 12px !important;
    margin-bottom: 16px !important;
}

/* ============================================================
   MINI TILE — LIGHT BLUE GRADIENT (compact)
   ============================================================ */
.mini-tile {
    position: relative;
    padding: 14px 12px !important;
    min-height: 82px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    text-decoration: none !important;
    border-radius: 14px !important;
    overflow: hidden;
    border: 1px solid rgba(147, 197, 253, 0.55) !important;

    background:
        radial-gradient(ellipse 70% 50% at 85% 20%, rgba(255,255,255,0.95) 0%, rgba(219,234,254,0.60) 30%, transparent 70%),
        radial-gradient(ellipse 80% 60% at 50% 55%, rgba(255,255,255,0.55) 0%, rgba(191,219,254,0.35) 40%, transparent 75%),
        radial-gradient(ellipse 60% 70% at 5% 100%, rgba(96,165,250,0.60) 0%, rgba(147,197,253,0.30) 40%, transparent 80%),
        linear-gradient(135deg, #93C5FD 0%, #BFDBFE 20%, #E0F2FE 45%, #BFDBFE 65%, #93C5FD 85%, #60A5FA 100%) !important;

    box-shadow: 0 4px 16px rgba(96,165,250,0.22), inset 0 1px 0 rgba(255,255,255,0.60) !important;
    transition: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    cursor: pointer;
}

.mini-tile:hover,
.mini-tile:active,
.mini-tile:focus,
.mini-tile:focus-within,
.admin-stat.mini-tile:hover,
.admin-stat.mini-tile:active,
.admin-stat.mini-tile:focus,
.admin-stat.mini-tile:focus-within {
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    transition: none !important;
    border-color: rgba(147, 197, 253, 0.55) !important;
    box-shadow: 0 4px 16px rgba(96,165,250,0.22), inset 0 1px 0 rgba(255,255,255,0.60) !important;
    background:
        radial-gradient(ellipse 70% 50% at 85% 20%, rgba(255,255,255,0.95) 0%, rgba(219,234,254,0.60) 30%, transparent 70%),
        radial-gradient(ellipse 80% 60% at 50% 55%, rgba(255,255,255,0.55) 0%, rgba(191,219,254,0.35) 40%, transparent 75%),
        radial-gradient(ellipse 60% 70% at 5% 100%, rgba(96,165,250,0.60) 0%, rgba(147,197,253,0.30) 40%, transparent 80%),
        linear-gradient(135deg, #93C5FD 0%, #BFDBFE 20%, #E0F2FE 45%, #BFDBFE 65%, #93C5FD 85%, #60A5FA 100%) !important;
}

.mini-tile-label {
    font-size: 0.6rem !important;
    font-weight: 800;
    color: rgba(30, 58, 138, 0.80) !important;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    line-height: 1;
    text-align: center;
    width: 100%;
    text-shadow: 0 1px 1px rgba(255, 255, 255, 0.60);
    position: relative;
    z-index: 2;
}

.mini-tile-value {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: 1.5rem !important;
    font-weight: 700;
    color: #1E3A8A !important;
    letter-spacing: -0.4px;
    line-height: 1;
    text-align: center;
    text-shadow: 0 1px 2px rgba(255, 255, 255, 0.75);
    position: relative;
    z-index: 2;
}

.mini-tile,
.mini-tile * { color: inherit; }

/* ============================================================
   Kill hover/focus effects on all cards
   ============================================================ */
.card-panel {
    transition: none !important;
    background: rgba(20, 32, 68, 0.65);
    border: 1px solid rgba(120, 160, 255, 0.15);
    border-radius: var(--radius-lg);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    overflow: hidden;
    cursor: default;
}

.card-panel:hover,
.card-panel:active,
.card-panel:focus,
.card-panel:focus-within {
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    background: rgba(20, 32, 68, 0.65) !important;
    border-color: rgba(120, 160, 255, 0.15) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

.card-panel .card-panel-head {
    padding: 14px 20px !important;
}

/* Remove blur from chart canvas parent */
.chart-wrap,
.chart-wrap canvas {
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}

/* Charts row (2 columns) */
.dash-charts-row {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
    gap: 12px !important;
    margin-bottom: 16px !important;
}

/* Bottom row (2 columns) */
.dash-row-6-6 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px !important;
    margin-bottom: 16px !important;
}

/* ---------- Chart helpers ---------- */
.chart-wrap { position: relative; width: 100%; }
.chart-wrap canvas { max-width: 100% !important; }

.panel-hint {
    font-size: 0.66rem !important;
    font-weight: 700;
    color: #93C5FD;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.25);
    border-radius: 999px;
}
.panel-link {
    font-size: 0.72rem !important;
    font-weight: 700;
    color: #93C5FD;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: 0.2s;
}
.panel-link:hover { color: #60A5FA; gap: 8px; }
.panel-link i { font-size: 0.66rem; }

/* Status legend */
.status-legend { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
.status-legend-item {
    display: flex; align-items: center; gap: 7px;
    font-size: 0.7rem !important;
    color: rgba(255,255,255,0.95);
    font-weight: 700;
}
.status-legend-dot {
    width: 9px !important;
    height: 9px !important;
    border-radius: 3px;
    flex-shrink: 0;
}
.status-legend-label { flex: 1; }
.status-legend-count { margin-left: auto; font-weight: 800; color: #fff; font-size: 0.72rem; }

/* Crypto list items */
.crypto-list { display: flex; flex-direction: column; gap: 2px; }
.crypto-list-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px !important;
    border-radius: 10px;
    transition: background 0.15s;
}
.crypto-list-item:hover { background: rgba(96, 165, 250, 0.06); }
.crypto-list-icon {
    width: 32px !important;
    height: 32px !important;
    border-radius: 9px !important;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.74rem !important;
    font-weight: 800;
    flex-shrink: 0;
}
.crypto-list-body { flex: 1; min-width: 0; }
.crypto-list-title {
    font-size: 0.78rem !important;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px !important;
}
.crypto-list-meta {
    font-size: 0.66rem !important;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}
.crypto-list-action {
    width: 28px !important;
    height: 28px !important;
    border-radius: 8px !important;
    background: rgba(120, 160, 255, 0.08);
    border: 1px solid rgba(120, 160, 255, 0.12);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,0.75);
    font-size: 0.68rem !important;
    transition: 0.2s;
    flex-shrink: 0;
}
.crypto-list-action:hover {
    background: rgba(96, 165, 250, 0.15);
    border-color: rgba(96, 165, 250, 0.40);
    color: #93C5FD;
}
.dot-sep { color: rgba(255,255,255,0.35); font-weight: 900; }

/* ============================================================
   Status badge (shared style)
   ============================================================ */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 4px 9px !important;
    border-radius: 999px;
    font-size: 0.6rem !important;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    border: 1px solid;
    flex-shrink: 0;
}
.status-dot {
    width: 5px; height: 5px;
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
.status-badge.closed {
    background: rgba(148, 163, 184, 0.15);
    color: #CBD5E1;
    border-color: rgba(148, 163, 184, 0.35);
}
.status-badge.closed .status-dot { background: #94A3B8; }

/* ---------- Empty state compact ---------- */
.empty-state {
    padding: 40px 16px !important;
}
.empty-state i {
    font-size: 24px !important;
    margin-bottom: 10px !important;
}
.empty-state h4 {
    font-size: 0.88rem !important;
    margin-bottom: 4px !important;
}
.empty-state p {
    font-size: 0.72rem !important;
}

/* ---------- Responsive ---------- */
@media (max-width: 1100px) {
    .dash-charts-row { grid-template-columns: 1fr; }
    .dash-row-6-6 { grid-template-columns: 1fr; }
}
@media (max-width: 992px) {
    .mini-stats-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px !important;
    }
    .mini-tile {
        min-height: 78px !important;
        padding: 12px 10px !important;
    }
    .mini-tile-value { font-size: 1.35rem !important; }
}
@media (max-width: 640px) {
    .mini-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
    }
    .mini-tile {
        min-height: 76px !important;
        padding: 12px 10px !important;
    }
    .mini-tile-value { font-size: 1.25rem !important; }
    .mini-tile-label { font-size: 0.58rem !important; }
    .status-legend { grid-template-columns: 1fr; }
}
</style>

<!-- ============ CHART SCRIPTS ============ -->
<script>
Chart.defaults.font.family = "'Source Sans 3', -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = 'rgba(255, 255, 255, 0.55)';

/* 1) Applications & Jobs Trend */
(function () {
    const el = document.getElementById('trendChart');
    if (!el) return;
    const ctx = el.getContext('2d');

    const gradApps = ctx.createLinearGradient(0, 0, 0, 220);
    gradApps.addColorStop(0, 'rgba(59, 130, 246, 0.35)');
    gradApps.addColorStop(1, 'rgba(59, 130, 246, 0.02)');

    const gradJobs = ctx.createLinearGradient(0, 0, 0, 220);
    gradJobs.addColorStop(0, 'rgba(96, 165, 250, 0.28)');
    gradJobs.addColorStop(1, 'rgba(96, 165, 250, 0.02)');

    new Chart(el, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                {
                    label: 'Applications',
                    data: <?php echo json_encode($chart_apps); ?>,
                    borderColor: '#3B82F6',
                    backgroundColor: gradApps,
                    borderWidth: 2.5,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#060B1F',
                    pointBorderColor: '#3B82F6',
                    pointBorderWidth: 2.5,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                },
                {
                    label: 'Jobs Posted',
                    data: <?php echo json_encode($chart_jobs); ?>,
                    borderColor: '#60A5FA',
                    backgroundColor: gradJobs,
                    borderWidth: 2.5,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#060B1F',
                    pointBorderColor: '#60A5FA',
                    pointBorderWidth: 2.5,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8,
                        padding: 10,
                        font: { family: "'Source Sans 3', sans-serif", size: 10, weight: '700' },
                        color: '#fff'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(10, 19, 48, 0.98)',
                    padding: 10,
                    cornerRadius: 8,
                    borderColor: 'rgba(96, 165, 250, 0.30)',
                    borderWidth: 1,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true,
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(120, 160, 255, 0.08)', drawBorder: false },
                    ticks: { color: 'rgba(255,255,255,0.55)', precision: 0, font: { size: 10 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: 'rgba(255,255,255,0.55)', font: { size: 10 } }
                }
            }
        }
    });
})();

/* 2) Application Status doughnut */
(function () {
    const el = document.getElementById('statusChart');
    if (!el) return;

    const labels = ['Pending','Reviewed','Shortlisted','Interview','Accepted','Rejected'];
    const values = <?php echo json_encode(array_values($status_data)); ?>;
    const colors = ['#3B82F6', '#60A5FA', '#93C5FD', '#2563EB', '#10B981', '#EF4444'];
    const total  = values.reduce((a, b) => a + b, 0);

    const centerText = {
        id: 'centerText',
        afterDraw(chart) {
            const { ctx, chartArea: { left, right, top, bottom } } = chart;
            const cx = (left + right) / 2;
            const cy = (top + bottom) / 2;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = "700 20px 'Libre Baskerville', Georgia, serif";
            ctx.fillStyle = '#ffffff';
            ctx.fillText(total, cx, cy - 5);
            ctx.font = "700 9px 'Source Sans 3', sans-serif";
            ctx.fillStyle = 'rgba(255,255,255,0.55)';
            ctx.fillText('TOTAL', cx, cy + 13);
            ctx.restore();
        }
    };

    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: total === 0 ? [1] : values,
                backgroundColor: total === 0 ? ['rgba(120, 160, 255, 0.15)'] : colors,
                borderWidth: 0,
                hoverOffset: total === 0 ? 0 : 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: total > 0,
                    backgroundColor: 'rgba(10, 19, 48, 0.98)',
                    padding: 10,
                    cornerRadius: 8,
                    borderColor: 'rgba(96, 165, 250, 0.30)',
                    borderWidth: 1,
                }
            }
        },
        plugins: [centerText]
    });
})();
</script>

<?php include '_footer.php'; ?>