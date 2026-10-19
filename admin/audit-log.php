<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Audit Logs';
$my_id = $_SESSION['user_id'] ?? 0;

/* ============================================================
   HANDLE EXPORT
   ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = '';
    $filter = $_GET['filter'] ?? 'all';
    if ($filter === 'today')     $where = "WHERE DATE(l.created_at) = CURDATE()";
    elseif ($filter === 'week')  $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    elseif ($filter === 'month') $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="navora-audit-log-' . date('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Admin', 'Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address', 'Date']);

    try {
        $stmt = $pdo->query("
            SELECT l.*, u.email AS admin_email
            FROM admin_activity_log l
            LEFT JOIN users u ON u.id = l.user_id
            $where
            ORDER BY l.created_at DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['id'],
                $row['admin_email'] ?? 'System',
                $row['action'],
                $row['entity_type'] ?? '',
                $row['entity_id'] ?? '',
                $row['details'] ?? '',
                $row['ip_address'] ?? '',
                $row['created_at'],
            ]);
        }
    } catch (PDOException $e) { }

    fclose($out);
    exit;
}

/* ============================================================
   HANDLE CLEAR
   ============================================================ */
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    try {
        $pdo->exec("TRUNCATE TABLE admin_activity_log");
        $success = 'Audit log cleared.';
    } catch (PDOException $e) {
        $success = 'Could not clear log.';
    }
}

/* ============================================================
   FETCH LOG
   ============================================================ */
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$where_parts = [];
$params = [];

if ($filter === 'today') {
    $where_parts[] = "DATE(l.created_at) = CURDATE()";
} elseif ($filter === 'week') {
    $where_parts[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter === 'month') {
    $where_parts[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

if ($search !== '') {
    $where_parts[] = "(l.action LIKE ? OR l.details LIKE ? OR u.email LIKE ? OR l.entity_type LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$log_entries = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.email AS admin_email
        FROM admin_activity_log l
        LEFT JOIN users u ON u.id = l.user_id
        $where
        ORDER BY l.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $log_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $log_entries = []; }

/* ============================================================
   STATS
   ============================================================ */
$stats = ['today' => 0, 'week' => 0, 'total' => 0, 'admins' => 0];
try {
    $stats['today']  = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['week']   = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['total']  = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log")->fetchColumn();
    $stats['admins'] = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM admin_activity_log")->fetchColumn();
} catch (PDOException $e) { }

/* Header vars */
$page_subtitle  = 'Every admin action recorded for transparency and accountability.';
$page_icon      = 'fa-history';
$page_theme     = 'violet';
$header_script  = 'Who did<br>what, when 🔍';
$breadcrumb     = 'Admin / Audit Logs';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<?php if ($success): ?>
    <div class="alert-soft success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-history"></i></div>
            <div class="admin-stat-label">Total Entries</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="admin-stat-sub">All-time log</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="admin-stat-label">Today</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['today']); ?></div>
        <div class="admin-stat-sub">Actions today</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-calendar-week"></i></div>
            <div class="admin-stat-label">This Week</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['week']); ?></div>
        <div class="admin-stat-sub">Last 7 days</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-user-shield"></i></div>
            <div class="admin-stat-label">Active Admins</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($stats['admins']); ?></div>
        <div class="admin-stat-sub">Recorded actions</div>
    </div>
</div>

<!-- ============ TOOLBAR ============ -->
<div class="card-panel audit-toolbar">
    <form method="GET" class="audit-filters">
        <div class="filter-field">
            <label class="form-label-sm">Search</label>
            <input type="text" name="q" class="form-input-sm"
                   value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Action, admin, details...">
        </div>
        <div class="filter-field">
            <label class="form-label-sm">Date Range</label>
            <select name="filter" class="form-select-sm-custom" onchange="this.form.submit()">
                <option value="all"   <?php echo $filter === 'all'   ? 'selected' : ''; ?>>All Time</option>
                <option value="today" <?php echo $filter === 'today' ? 'selected' : ''; ?>>Today</option>
                <option value="week"  <?php echo $filter === 'week'  ? 'selected' : ''; ?>>Last 7 Days</option>
                <option value="month" <?php echo $filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-soft primary"><i class="fas fa-search"></i> Filter</button>
            <?php if ($search || $filter !== 'all'): ?>
                <a href="audit-log.php" class="btn-soft"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
            <a href="?filter=<?php echo htmlspecialchars($filter); ?>&export=csv" class="btn-soft success">
                <i class="fas fa-download"></i> Export CSV
            </a>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Clear the entire audit log? This cannot be undone.');">
                <button type="submit" name="clear_log" class="btn-soft danger">
                    <i class="fas fa-eraser"></i> Clear
                </button>
            </form>
        </div>
    </form>
</div>

<!-- ============ LOG TABLE ============ -->
<div class="table-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-list-alt"></i></span>
            Activity Log
            <span class="count-chip"><?php echo count($log_entries); ?></span>
        </div>
        <div class="table-panel-sub">
            Showing <?php echo count($log_entries); ?> entr<?php echo count($log_entries) === 1 ? 'y' : 'ies'; ?>
        </div>
    </div>

    <?php if (count($log_entries) > 0): ?>
        <div class="log-table-wrap">
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:200px;">Admin</th>
                        <th style="width:180px;">Action</th>
                        <th style="width:120px;">Entity</th>
                        <th>Details</th>
                        <th style="width:170px;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log_entries as $entry):
                        $action_class = 'default';
                        if (strpos($entry['action'], 'deleted') !== false)      $action_class = 'danger';
                        elseif (strpos($entry['action'], 'created') !== false)  $action_class = 'success';
                        elseif (strpos($entry['action'], 'status') !== false)   $action_class = 'warning';
                        elseif (strpos($entry['action'], 'viewed') !== false)   $action_class = 'muted';
                        $action_label = ucwords(str_replace(['.', '_'], [' · ', ' '], $entry['action']));
                    ?>
                        <tr>
                            <td><span class="log-id">#<?php echo (int)$entry['id']; ?></span></td>
                            <td>
                                <div class="log-admin">
                                    <div class="log-admin-avatar">
                                        <?php echo strtoupper(substr($entry['admin_email'] ?? 'S', 0, 1)); ?>
                                    </div>
                                    <span class="log-admin-email"><?php echo htmlspecialchars($entry['admin_email'] ?? 'System'); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="log-action-chip <?php echo $action_class; ?>">
                                    <span class="log-action-dot"></span>
                                    <?php echo htmlspecialchars($action_label); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($entry['entity_type'])): ?>
                                    <span class="log-entity">
                                        <?php echo htmlspecialchars($entry['entity_type']); ?>
                                        <?php if (!empty($entry['entity_id'])): ?>#<?php echo (int)$entry['entity_id']; ?><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="empty-cell">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="log-details-cell">
                                    <?php echo htmlspecialchars($entry['details'] ?: '—'); ?>
                                </div>
                            </td>
                            <td>
                                <span class="log-date">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y', strtotime($entry['created_at'])); ?>
                                    <span class="log-time"><?php echo date('g:i A', strtotime($entry['created_at'])); ?></span>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-history"></i>
            <h4>No log entries</h4>
            <p>No admin actions match your filters yet.</p>
        </div>
    <?php endif; ?>
</div>

<style>
/* ============================================================
   AUDIT LOG PAGE
   ============================================================ */
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
.table-panel-sub { font-size: 0.72rem; font-weight: 700; color: rgba(255, 255, 255, 0.55); }

/* Toolbar */
.audit-toolbar { padding: 14px 18px !important; margin-bottom: 14px !important; }
.audit-filters {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 12px;
    align-items: end;
}
.filter-field { min-width: 0; }
.filter-field .form-label-sm { font-size: 11.5px; margin-bottom: 5px; }
.filter-field .form-input-sm,
.filter-field .form-select-sm-custom { padding: 9px 12px !important; font-size: 13px !important; }
.filter-actions {
    display: flex; gap: 6px;
    align-items: center; flex-wrap: wrap;
}
.filter-actions .btn-soft { padding: 8px 14px !important; font-size: 12.5px !important; }
@media (max-width: 900px) {
    .audit-filters { grid-template-columns: 1fr 1fr; }
    .filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
    .audit-filters { grid-template-columns: 1fr; }
}

/* Table */
.log-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.log-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem; min-width: 900px;
}
.log-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem; font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase; letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.log-table tbody td {
    padding: 10px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle; color: #fff;
}
.log-table tbody tr:last-child td { border-bottom: none; }
.log-table tbody tr:hover { background: rgba(96, 165, 250, 0.05); }

.log-id {
    display: inline-flex; align-items: center;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12); color: #93C5FD;
    font-weight: 800; font-size: 0.68rem !important;
    border-radius: 8px; letter-spacing: 0.3px;
    border: 1px solid rgba(96, 165, 250, 0.20);
}
.log-admin { display: flex; align-items: center; gap: 8px; min-width: 0; }
.log-admin-avatar {
    width: 28px; height: 28px; border-radius: 8px;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.7rem !important;
    flex-shrink: 0;
}
.log-admin-email {
    font-size: 0.76rem !important; font-weight: 700; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.log-action-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px 4px 9px !important;
    border-radius: 999px;
    font-size: 0.64rem !important; font-weight: 800;
    letter-spacing: 0.3px; border: 1px solid;
    text-transform: capitalize;
    background: rgba(96, 165, 250, 0.12);
    color: #93C5FD;
    border-color: rgba(96, 165, 250, 0.30);
}
.log-action-dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: currentColor; flex-shrink: 0;
}
.log-action-chip.danger  { background: rgba(239, 68, 68, 0.15);  color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35); }
.log-action-chip.success { background: rgba(16, 185, 129, 0.15); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.35); }
.log-action-chip.warning { background: rgba(245, 158, 11, 0.15); color: #FCD34D; border-color: rgba(245, 158, 11, 0.35); }
.log-action-chip.muted   { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }

.log-entity {
    display: inline-flex; align-items: center;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12); color: #93C5FD;
    font-size: 0.64rem !important; font-weight: 800;
    border-radius: 999px; text-transform: capitalize;
    border: 1px solid rgba(96, 165, 250, 0.22);
}
.empty-cell { color: rgba(255, 255, 255, 0.5); font-weight: 700; }

.log-details-cell {
    font-size: 0.74rem !important;
    color: rgba(255, 255, 255, 0.75);
    font-weight: 500; line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.log-date {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.74rem !important; color: #fff;
    font-weight: 600; white-space: nowrap;
}
.log-date i { color: #93C5FD; font-size: 0.68rem !important; }
.log-time { color: rgba(255, 255, 255, 0.5); font-weight: 700; font-size: 0.68rem; }
</style>

<?php include '_footer.php'; ?>