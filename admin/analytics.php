<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Analytics & Reports';

/* ============================================================
   FILTERS
   ============================================================ */
$range = $_GET['range'] ?? '6m';
$allowed_ranges = ['30d' => 30, '90d' => 90, '6m' => 180, '12m' => 365];
if (!isset($allowed_ranges[$range])) $range = '6m';
$days = $allowed_ranges[$range];

/* ============================================================
   GROWTH
   ============================================================ */
$months_labels = [];
$seekers_growth = [];
$employers_growth = [];

$month_count = $range === '30d' ? 1 : ($range === '90d' ? 3 : ($range === '6m' ? 6 : 12));
for ($i = $month_count - 1; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $months_labels[] = date('M Y', strtotime($month . '-01'));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='seeker' AND DATE_FORMAT(created_at,'%Y-%m')=?");
    $stmt->execute([$month]);
    $seekers_growth[] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='employer' AND DATE_FORMAT(created_at,'%Y-%m')=?");
    $stmt->execute([$month]);
    $employers_growth[] = (int) $stmt->fetchColumn();
}

/* ============================================================
   JOBS TREND
   ============================================================ */
$jobs_trend = [];
for ($i = $month_count - 1; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE DATE_FORMAT(created_at,'%Y-%m')=?");
    $stmt->execute([$month]);
    $jobs_trend[] = (int) $stmt->fetchColumn();
}

/* ============================================================
   APPLICATIONS TREND
   ============================================================ */
$apps_trend = [];
for ($i = $month_count - 1; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE DATE_FORMAT(applied_at,'%Y-%m')=?");
    $stmt->execute([$month]);
    $apps_trend[] = (int) $stmt->fetchColumn();
}

/* ============================================================
   HIRING FUNNEL
   ============================================================ */
$funnel = [
    'applied'     => (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
    'reviewed'    => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('reviewed','shortlisted','interview','accepted')")->fetchColumn(),
    'shortlisted' => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('shortlisted','interview','accepted')")->fetchColumn(),
    'interview'   => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('interview','accepted')")->fetchColumn(),
    'hired'       => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'accepted'")->fetchColumn(),
];

$conversion = $funnel['applied'] > 0
    ? round(($funnel['hired'] / $funnel['applied']) * 100, 1)
    : 0;

/* ============================================================
   POPULAR CATEGORIES
   ============================================================ */
$popular_categories = [];
try {
    $popular_categories = $pdo->query("
        SELECT c.category_name, COUNT(j.id) AS job_count
        FROM categories c
        LEFT JOIN jobs j ON j.category_id = c.id
        GROUP BY c.id, c.category_name
        HAVING job_count > 0
        ORDER BY job_count DESC
        LIMIT 6
    ")->fetchAll();
} catch (PDOException $e) { $popular_categories = []; }

/* ============================================================
   POPULAR LOCATIONS
   ============================================================ */
$popular_locations = [];
try {
    $popular_locations = $pdo->query("
        SELECT l.municipality, l.province, COUNT(j.id) AS job_count
        FROM locations l
        LEFT JOIN jobs j ON j.location_id = l.id
        GROUP BY l.id, l.municipality, l.province
        HAVING job_count > 0
        ORDER BY job_count DESC
        LIMIT 6
    ")->fetchAll();
} catch (PDOException $e) { $popular_locations = []; }

/* ============================================================
   TOP EMPLOYERS
   ============================================================ */
$top_employers = [];
try {
    $top_employers = $pdo->query("
        SELECT e.company_name, COUNT(j.id) AS job_count
        FROM employers e
        LEFT JOIN jobs j ON j.employer_id = e.id
        GROUP BY e.id, e.company_name
        HAVING job_count > 0
        ORDER BY job_count DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) { $top_employers = []; }

/* ============================================================
   CSV EXPORT
   ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $type = $_GET['type'] ?? 'overview';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="navora-analytics-' . $type . '-' . date('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');

    if ($type === 'overview') {
        fputcsv($out, ['Month', 'Seekers', 'Employers', 'Jobs Posted', 'Applications']);
        for ($i = 0; $i < count($months_labels); $i++) {
            fputcsv($out, [
                $months_labels[$i],
                $seekers_growth[$i],
                $employers_growth[$i],
                $jobs_trend[$i],
                $apps_trend[$i],
            ]);
        }
    } elseif ($type === 'funnel') {
        fputcsv($out, ['Stage', 'Count']);
        foreach ($funnel as $stage => $count) {
            fputcsv($out, [ucfirst($stage), $count]);
        }
        fputcsv($out, ['Conversion Rate', $conversion . '%']);
    } elseif ($type === 'categories') {
        fputcsv($out, ['Category', 'Job Count']);
        foreach ($popular_categories as $c) {
            fputcsv($out, [$c['category_name'], $c['job_count']]);
        }
    } elseif ($type === 'locations') {
        fputcsv($out, ['Municipality', 'Province', 'Job Count']);
        foreach ($popular_locations as $l) {
            fputcsv($out, [$l['municipality'], $l['province'], $l['job_count']]);
        }
    }

    fclose($out);
    exit;
}

/* ============================================================
   STATS
   ============================================================ */
$stats = [
    'total_users'      => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
    'total_jobs'       => (int) $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn(),
    'total_apps'       => (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
    'total_hired'      => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'accepted'")->fetchColumn(),
];

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Growth trends, hiring funnel, and performance insights.';
$page_icon      = 'fa-chart-pie';
$page_theme     = 'violet';
$header_script  = 'Data tells the story 📈';
$breadcrumb     = 'Admin / Analytics';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

$extra_css = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';

include '_header.php';
?>

<!-- ============ TOOLBAR ============ -->
<div class="card-panel analytics-toolbar">
    <div class="toolbar-left">
        <div class="toolbar-label">
            <i class="fas fa-clock"></i>
            Time Range
        </div>
        <div class="range-pills">
            <?php
            $range_labels = [
                '30d' => '30 Days',
                '90d' => '90 Days',
                '6m'  => '6 Months',
                '12m' => '12 Months',
            ];
            foreach ($range_labels as $key => $label): ?>
                <a href="?range=<?php echo $key; ?>"
                   class="range-pill <?php echo $range === $key ? 'active' : ''; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="toolbar-right">
        <button type="button" class="btn-soft primary" onclick="toggleExportMenu(event)">
            <i class="fas fa-download"></i> Export CSV
            <i class="fas fa-chevron-down" style="font-size:0.6rem;"></i>
        </button>
        <div class="export-menu" id="exportMenu">
            <a href="?range=<?php echo $range; ?>&export=csv&type=overview" class="export-item">
                <i class="fas fa-chart-line"></i>
                <div>
                    <strong>Growth Overview</strong>
                    <span>Month-by-month users, jobs, applications</span>
                </div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=funnel" class="export-item">
                <i class="fas fa-filter"></i>
                <div>
                    <strong>Hiring Funnel</strong>
                    <span>Stage-by-stage application counts</span>
                </div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=categories" class="export-item">
                <i class="fas fa-tags"></i>
                <div>
                    <strong>Popular Categories</strong>
                    <span>Jobs per category</span>
                </div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=locations" class="export-item">
                <i class="fas fa-map-marker-alt"></i>
                <div>
                    <strong>Popular Locations</strong>
                    <span>Jobs per municipality</span>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- ============ KPI CARDS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
            <div class="admin-stat-label">Total Users</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_users']); ?></div>
        <div class="admin-stat-sub">All non-admin accounts</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-briefcase"></i></div>
            <div class="admin-stat-label">Jobs Posted</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_jobs']); ?></div>
        <div class="admin-stat-sub">All-time</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
            <div class="admin-stat-label">Applications</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total_apps']); ?></div>
        <div class="admin-stat-sub">All-time submissions</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-trophy"></i></div>
            <div class="admin-stat-label">Conversion Rate</div>
        </div>
        <div class="admin-stat-value"><?php echo $conversion; ?>%</div>
        <div class="admin-stat-sub"><?php echo number_format($stats['total_hired']); ?> hires from apps</div>
    </div>
</div>

<!-- ============ ROW: GROWTH + FUNNEL ============ -->
<div class="analytics-row analytics-row-2">

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-user-plus"></i></span>
                User Growth
            </div>
            <div class="panel-hint">Seekers vs Employers</div>
        </div>
        <div class="chart-wrap" style="height: 260px;">
            <canvas id="growthChart"></canvas>
        </div>
    </div>

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-filter"></i></span>
                Hiring Funnel
            </div>
            <div class="panel-hint"><?php echo $conversion; ?>% conversion</div>
        </div>

        <div class="funnel-list">
            <?php
            $funnel_stages = [
                'applied'     => ['Applied',      '#2563eb', 'fa-paper-plane'],
                'reviewed'    => ['Reviewed',     '#3b82f6', 'fa-eye'],
                'shortlisted' => ['Shortlisted',  '#60a5fa', 'fa-list-check'],
                'interview'   => ['Interview',    '#1d4ed8', 'fa-comments'],
                'hired'       => ['Hired',        '#0a2472', 'fa-trophy'],
            ];
            $max_count = max(1, $funnel['applied']);
            foreach ($funnel_stages as $key => $meta):
                $count = $funnel[$key];
                $pct = round(($count / $max_count) * 100, 1);
            ?>
                <div class="funnel-stage">
                    <div class="funnel-stage-head">
                        <div class="funnel-stage-label">
                            <span class="funnel-stage-icon" style="background: <?php echo $meta[1]; ?>1a; color: <?php echo $meta[1]; ?>;">
                                <i class="fas <?php echo $meta[2]; ?>"></i>
                            </span>
                            <?php echo $meta[0]; ?>
                        </div>
                        <div class="funnel-stage-count">
                            <strong><?php echo number_format($count); ?></strong>
                            <span class="funnel-stage-pct"><?php echo $pct; ?>%</span>
                        </div>
                    </div>
                    <div class="funnel-bar">
                        <div class="funnel-bar-fill" style="width: <?php echo $pct; ?>%; background: linear-gradient(90deg, <?php echo $meta[1]; ?>, <?php echo $meta[1]; ?>cc); box-shadow: 0 0 12px <?php echo $meta[1]; ?>55;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============ ROW: JOBS + APPLICATIONS TREND ============ -->
<div class="analytics-row analytics-row-2">

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-briefcase"></i></span>
                Job Posting Trend
            </div>
            <div class="panel-hint">Last <?php echo count($months_labels); ?> months</div>
        </div>
        <div class="chart-wrap" style="height: 220px;">
            <canvas id="jobsChart"></canvas>
        </div>
    </div>

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-file-alt"></i></span>
                Applications Trend
            </div>
            <div class="panel-hint">Last <?php echo count($months_labels); ?> months</div>
        </div>
        <div class="chart-wrap" style="height: 220px;">
            <canvas id="appsChart"></canvas>
        </div>
    </div>
</div>

<!-- ============ ROW: POPULAR CATEGORIES + LOCATIONS ============ -->
<div class="analytics-row analytics-row-2">

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-tags"></i></span>
                Popular Categories
            </div>
            <a href="?range=<?php echo $range; ?>&export=csv&type=categories" class="panel-link">
                Export <i class="fas fa-download"></i>
            </a>
        </div>

        <?php if (count($popular_categories) > 0): ?>
            <div class="leaderboard-list">
                <?php
                $max_cat = max(array_map(fn($c) => (int)$c['job_count'], $popular_categories));
                foreach ($popular_categories as $i => $c):
                    $pct = $max_cat > 0 ? round(((int)$c['job_count'] / $max_cat) * 100) : 0;
                ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-rank">#<?php echo $i + 1; ?></div>
                        <div class="leaderboard-body">
                            <div class="leaderboard-name">
                                <?php echo htmlspecialchars($c['category_name']); ?>
                            </div>
                            <div class="leaderboard-bar">
                                <div class="leaderboard-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <div class="leaderboard-count">
                            <strong><?php echo (int)$c['job_count']; ?></strong>
                            <span>jobs</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding: 32px 16px;">
                <i class="fas fa-tags"></i>
                <h4>No category data</h4>
                <p>Add categories and post jobs to see trends here.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-map-marker-alt"></i></span>
                Popular Locations
            </div>
            <a href="?range=<?php echo $range; ?>&export=csv&type=locations" class="panel-link">
                Export <i class="fas fa-download"></i>
            </a>
        </div>

        <?php if (count($popular_locations) > 0): ?>
            <div class="leaderboard-list">
                <?php
                $max_loc = max(array_map(fn($l) => (int)$l['job_count'], $popular_locations));
                foreach ($popular_locations as $i => $l):
                    $pct = $max_loc > 0 ? round(((int)$l['job_count'] / $max_loc) * 100) : 0;
                ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-rank is-loc">#<?php echo $i + 1; ?></div>
                        <div class="leaderboard-body">
                            <div class="leaderboard-name">
                                <?php echo htmlspecialchars($l['municipality']); ?>
                                <span class="leaderboard-sub">· <?php echo htmlspecialchars($l['province']); ?></span>
                            </div>
                            <div class="leaderboard-bar">
                                <div class="leaderboard-bar-fill is-loc" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <div class="leaderboard-count">
                            <strong><?php echo (int)$l['job_count']; ?></strong>
                            <span>jobs</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding: 32px 16px;">
                <i class="fas fa-map-marker-alt"></i>
                <h4>No location data</h4>
                <p>Add locations and post jobs to see trends here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ ROW: TOP EMPLOYERS + EXPORT ============ -->
<div class="analytics-row analytics-row-2">

    <div class="card-panel card-panel--analytics">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-building"></i></span>
                Most Active Employers
            </div>
            <a href="/navora3/admin/employers.php" class="panel-link">
                View all <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <?php if (count($top_employers) > 0): ?>
            <div class="leaderboard-list">
                <?php
                $max_emp = max(array_map(fn($e) => (int)$e['job_count'], $top_employers));
                foreach ($top_employers as $i => $e):
                    $pct = $max_emp > 0 ? round(((int)$e['job_count'] / $max_emp) * 100) : 0;
                ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-rank is-emp">#<?php echo $i + 1; ?></div>
                        <div class="leaderboard-body">
                            <div class="leaderboard-name">
                                <?php echo htmlspecialchars($e['company_name']); ?>
                            </div>
                            <div class="leaderboard-bar">
                                <div class="leaderboard-bar-fill is-emp" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <div class="leaderboard-count">
                            <strong><?php echo (int)$e['job_count']; ?></strong>
                            <span>jobs</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding: 32px 16px;">
                <i class="fas fa-building"></i>
                <h4>No employer data</h4>
                <p>Employers with posted jobs will appear here.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-panel card-panel--analytics export-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-file-export"></i></span>
                Download Reports
            </div>
        </div>

        <div class="export-grid">
            <a href="?range=<?php echo $range; ?>&export=csv&type=overview" class="export-card">
                <div class="export-card-icon"><i class="fas fa-chart-line"></i></div>
                <div class="export-card-title">Growth Overview</div>
                <div class="export-card-sub">Users · Jobs · Apps</div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=funnel" class="export-card">
                <div class="export-card-icon is-violet"><i class="fas fa-filter"></i></div>
                <div class="export-card-title">Hiring Funnel</div>
                <div class="export-card-sub">Stage counts</div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=categories" class="export-card">
                <div class="export-card-icon is-cyan"><i class="fas fa-tags"></i></div>
                <div class="export-card-title">Categories</div>
                <div class="export-card-sub">Jobs per category</div>
            </a>
            <a href="?range=<?php echo $range; ?>&export=csv&type=locations" class="export-card">
                <div class="export-card-icon is-deep"><i class="fas fa-map-marker-alt"></i></div>
                <div class="export-card-title">Locations</div>
                <div class="export-card-sub">Jobs per location</div>
            </a>
        </div>

        <div class="export-hint">
            <i class="fas fa-info-circle"></i>
            CSV exports include all data for the selected time range.
        </div>
    </div>
</div>

<!-- ============ ANALYTICS PAGE CSS ============ -->
<style>
/* ============================================================
   ANALYTICS PAGE — COMPACT + FLAT DARK GLASS
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

/* ---------- Toolbar ---------- */
.analytics-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    padding: 14px 20px !important;
    margin-bottom: 16px !important;
    position: relative;
    z-index: 600;
    overflow: visible !important;
}
.toolbar-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.toolbar-label {
    font-size: 0.72rem !important; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.7px;
    display: flex; align-items: center; gap: 5px;
}
.toolbar-label i { color: #60A5FA; }
.range-pills { display: flex; gap: 5px; flex-wrap: wrap; }
.range-pill {
    padding: 6px 12px !important;
    border-radius: 999px;
    border: 1px solid rgba(96, 165, 250, 0.35);
    background: rgba(20, 32, 68, 0.90);
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.72rem !important; font-weight: 700;
    transition: 0.2s;
}
.range-pill:hover {
    border-color: rgba(96, 165, 250, 0.55);
    color: #93C5FD;
    background: rgba(96, 165, 250, 0.12);
}
.range-pill.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    border-color: transparent;
    color: #fff;
    box-shadow: none;
}

.toolbar-right { position: relative; z-index: 700; }
.toolbar-right .btn-soft {
    padding: 7px 14px !important;
    font-size: 0.74rem !important;
}

.export-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 300px;
    max-width: calc(100vw - 40px);
    background: #0E1938;
    border: 1.5px solid rgba(96, 165, 250, 0.35);
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    padding: 6px;
    opacity: 0; visibility: hidden;
    transform: translateY(-6px);
    transition: 0.2s;
    z-index: 9999;
}
.export-menu.open { opacity: 1; visibility: visible; transform: translateY(0); }
.export-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px !important; border-radius: 9px;
    transition: 0.15s;
}
.export-item:hover { background: rgba(96, 165, 250, 0.10); }
.export-item i {
    width: 30px !important; height: 30px !important;
    border-radius: 9px !important;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem !important; flex-shrink: 0;
}
.export-item div { min-width: 0; }
.export-item strong { display: block; font-size: 0.78rem !important; color: #FFFFFF; font-weight: 700; margin-bottom: 2px; }
.export-item span { display: block; font-size: 0.68rem !important; color: rgba(255, 255, 255, 0.6); font-weight: 600; }

/* ---------- Row layout ---------- */
.analytics-row {
    display: grid; gap: 16px !important;
    margin-bottom: 16px !important; align-items: stretch;
    position: relative; z-index: 1;
}
.analytics-row-2 { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }

@media (max-width: 1100px) {
    .analytics-row-2 { grid-template-columns: 1fr; }
}

.chart-wrap { position: relative; width: 100%; }
.chart-wrap canvas { max-width: 100% !important; }

.panel-hint {
    font-size: 0.68rem !important; font-weight: 700;
    color: #93C5FD;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.25);
    border-radius: 999px;
}
.panel-link {
    font-size: 0.72rem !important; font-weight: 700;
    color: #93C5FD;
    display: inline-flex; align-items: center; gap: 5px;
    transition: 0.2s;
}
.panel-link:hover { color: #60A5FA; gap: 8px; }

/* ---------- Analytics cards ---------- */
.card-panel--analytics {
    position: relative;
    background: #0E1938 !important;
    border: 1.5px solid rgba(96, 165, 250, 0.35) !important;
    border-radius: 16px !important;
    overflow: hidden;
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    transition: none !important;
}
.card-panel--analytics:hover,
.card-panel--analytics:focus,
.card-panel--analytics:focus-within,
.card-panel--analytics:active {
    background: #0E1938 !important;
    transform: none !important;
    filter: none !important;
    backdrop-filter: none !important;
    transition: none !important;
    border-color: rgba(96, 165, 250, 0.35) !important;
}

.card-panel--analytics .card-panel-head {
    padding: 14px 20px !important;
}

/* ---------- Funnel ---------- */
.funnel-list { display: flex; flex-direction: column; gap: 12px !important; }
.funnel-stage-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px !important; }
.funnel-stage-label { display: flex; align-items: center; gap: 8px; font-size: 0.8rem !important; font-weight: 700; color: #FFFFFF; }
.funnel-stage-icon {
    width: 26px !important; height: 26px !important;
    border-radius: 8px !important;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.68rem !important; flex-shrink: 0;
}
.funnel-stage-count { display: flex; align-items: baseline; gap: 7px; }
.funnel-stage-count strong { font-size: 0.88rem !important; color: #FFFFFF; font-weight: 800; }
.funnel-stage-pct { font-size: 0.66rem !important; color: rgba(255, 255, 255, 0.55); font-weight: 700; }
.funnel-bar {
    height: 7px !important;
    background: rgba(148, 163, 184, 0.12);
    border-radius: 999px;
    overflow: hidden;
}
.funnel-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ---------- Leaderboard ---------- */
.leaderboard-list { display: flex; flex-direction: column; gap: 11px !important; }
.leaderboard-item { display: flex; align-items: center; gap: 12px; }
.leaderboard-rank {
    width: 32px !important; height: 32px !important;
    border-radius: 9px !important;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.74rem !important; flex-shrink: 0;
}
.leaderboard-rank.is-loc { background: linear-gradient(135deg, #3b82f6, #1e40af); }
.leaderboard-rank.is-emp { background: linear-gradient(135deg, #1d4ed8, #0a2472); }
.leaderboard-body { flex: 1; min-width: 0; }
.leaderboard-name {
    font-size: 0.8rem !important; font-weight: 700; color: #FFFFFF;
    margin-bottom: 5px !important;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.leaderboard-sub { font-size: 0.68rem !important; color: rgba(255, 255, 255, 0.55); font-weight: 600; }
.leaderboard-bar {
    height: 5px !important;
    background: rgba(148, 163, 184, 0.12);
    border-radius: 999px;
    overflow: hidden;
}
.leaderboard-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #2563eb, #60a5fa);
    border-radius: 999px;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.leaderboard-bar-fill.is-loc { background: linear-gradient(90deg, #3b82f6, #93c5fd); }
.leaderboard-bar-fill.is-emp { background: linear-gradient(90deg, #1d4ed8, #3b82f6); }
.leaderboard-count { text-align: right; flex-shrink: 0; min-width: 44px; }
.leaderboard-count strong { display: block; font-size: 0.88rem !important; font-weight: 800; color: #FFFFFF; line-height: 1.1; }
.leaderboard-count span { font-size: 0.62rem !important; color: rgba(255, 255, 255, 0.55); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

/* ---------- Export panel ---------- */
.export-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px !important; margin-bottom: 14px !important; }
.export-card {
    display: flex; flex-direction: column; gap: 6px;
    padding: 12px 12px !important;
    background: rgba(20, 32, 68, 0.60);
    border: 1.5px solid rgba(96, 165, 250, 0.25);
    border-radius: 12px;
    transition: 0.25s;
}
.export-card:hover {
    border-color: rgba(96, 165, 250, 0.55);
    background: rgba(96, 165, 250, 0.08);
    transform: translateY(-2px);
}
.export-card-icon {
    width: 32px !important; height: 32px !important;
    border-radius: 9px !important;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem !important;
}
.export-card-icon.is-violet { background: linear-gradient(135deg, #3b82f6, #1e40af); }
.export-card-icon.is-cyan { background: linear-gradient(135deg, #60a5fa, #2563eb); }
.export-card-icon.is-deep { background: linear-gradient(135deg, #1d4ed8, #0a2472); }
.export-card-title { font-size: 0.78rem !important; font-weight: 800; color: #FFFFFF; }
.export-card-sub { font-size: 0.66rem !important; color: rgba(255, 255, 255, 0.55); font-weight: 600; }
.export-hint {
    display: flex; align-items: center; gap: 7px;
    padding: 9px 12px !important;
    background: rgba(96, 165, 250, 0.08);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 9px;
    font-size: 0.72rem !important; font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
}
.export-hint i { color: #93C5FD; font-size: 0.68rem; }

/* ---------- Empty state ---------- */
.empty-state {
    padding: 40px 16px !important;
}
.empty-state i { font-size: 24px !important; }
.empty-state h4 { font-size: 0.88rem !important; }
.empty-state p { font-size: 0.72rem !important; }

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
    .analytics-toolbar { padding: 12px 16px !important; }
    .toolbar-left { gap: 10px; width: 100%; }
    .range-pills { width: 100%; }
    .range-pill { flex: 1; text-align: center; padding: 6px 10px !important; font-size: 0.68rem !important; }
    .toolbar-right { width: 100%; }
    .toolbar-right .btn-soft { width: 100%; justify-content: center; }
    .export-grid { grid-template-columns: 1fr; }
    .export-menu { width: calc(100vw - 32px); right: 0; }
}
</style>

<!-- ============ CHART SCRIPTS ============ -->
<script>
Chart.defaults.font.family = "'Source Sans 3', -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = 'rgba(255, 255, 255, 0.55)';

/* 1) User Growth */
(function () {
    const el = document.getElementById('growthChart');
    if (!el) return;
    const ctx = el.getContext('2d');
    const g1 = ctx.createLinearGradient(0, 0, 0, 260);
    g1.addColorStop(0, 'rgba(59, 130, 246, 0.45)');
    g1.addColorStop(1, 'rgba(59, 130, 246, 0.02)');
    const g2 = ctx.createLinearGradient(0, 0, 0, 260);
    g2.addColorStop(0, 'rgba(96, 165, 250, 0.35)');
    g2.addColorStop(1, 'rgba(96, 165, 250, 0.02)');

    new Chart(el, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months_labels); ?>,
            datasets: [
                { label: 'Seekers', data: <?php echo json_encode($seekers_growth); ?>,
                  borderColor: '#3B82F6', backgroundColor: g1, borderWidth: 2.5, tension: 0.4, fill: true,
                  pointBackgroundColor: '#060B1F', pointBorderColor: '#3B82F6', pointBorderWidth: 2.5, pointRadius: 4, pointHoverRadius: 7 },
                { label: 'Employers', data: <?php echo json_encode($employers_growth); ?>,
                  borderColor: '#60A5FA', backgroundColor: g2, borderWidth: 2.5, tension: 0.4, fill: true,
                  pointBackgroundColor: '#060B1F', pointBorderColor: '#60A5FA', pointBorderWidth: 2.5, pointRadius: 4, pointHoverRadius: 7 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: '#fff', boxWidth: 10, padding: 12, font: { size: 10 } } } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(120,160,255,0.08)' }, ticks: { color: 'rgba(255,255,255,0.55)', precision: 0, font: { size: 10 } } },
                      x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.55)', font: { size: 10 } } } } }
    });
})();

/* 2) Jobs Trend */
(function () {
    const el = document.getElementById('jobsChart');
    if (!el) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($months_labels); ?>,
            datasets: [{ label: 'Jobs', data: <?php echo json_encode($jobs_trend); ?>,
                backgroundColor: (ctx) => {
                    const { ctx: c, chartArea } = ctx.chart;
                    if (!chartArea) return '#2563eb';
                    const g = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    g.addColorStop(0, 'rgba(37, 99, 235, 0.6)');
                    g.addColorStop(1, 'rgba(96, 165, 250, 1)');
                    return g;
                },
                hoverBackgroundColor: '#1d4ed8', borderRadius: 8, borderSkipped: false, maxBarThickness: 40 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(120,160,255,0.08)' }, ticks: { color: 'rgba(255,255,255,0.55)', precision: 0, font: { size: 10 } } },
                      x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.55)', font: { size: 10 } } } } }
    });
})();

/* 3) Applications Trend */
(function () {
    const el = document.getElementById('appsChart');
    if (!el) return;
    const ctx = el.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 220);
    g.addColorStop(0, 'rgba(59, 130, 246, 0.35)');
    g.addColorStop(1, 'rgba(59, 130, 246, 0.02)');

    new Chart(el, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months_labels); ?>,
            datasets: [{ label: 'Applications', data: <?php echo json_encode($apps_trend); ?>,
                borderColor: '#3B82F6', backgroundColor: g, borderWidth: 2.5, tension: 0.4, fill: true,
                pointBackgroundColor: '#060B1F', pointBorderColor: '#3B82F6', pointBorderWidth: 2.5, pointRadius: 4, pointHoverRadius: 7 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(120,160,255,0.08)' }, ticks: { color: 'rgba(255,255,255,0.55)', precision: 0, font: { size: 10 } } },
                      x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.55)', font: { size: 10 } } } } }
    });
})();

/* Export dropdown */
function toggleExportMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('exportMenu');
    if (menu) menu.classList.toggle('open');
}
document.addEventListener('click', (e) => {
    const menu = document.getElementById('exportMenu');
    if (menu && menu.classList.contains('open') && !menu.contains(e.target)) {
        menu.classList.remove('open');
    }
});
</script>

<?php include '_footer.php'; ?>