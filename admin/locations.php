<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$page_title = 'Manage Locations';

$errors = [];
$success = '';

/* ---------- Add location ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
    $municipality = trim($_POST['municipality'] ?? '');
    $province = trim($_POST['province'] ?? 'Lanao del Norte');
    if (!in_array($province, ['Lanao del Norte', 'Lanao del Sur'])) $province = 'Lanao del Norte';

    if (empty($municipality)) {
        $errors[] = 'Municipality name is required.';
    } elseif (mb_strlen($municipality) > 120) {
        $errors[] = 'Municipality name must be 120 characters or less.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO locations (municipality, province) VALUES (?, ?)");
            $stmt->execute([$municipality, $province]);
            $success = 'Location added successfully!';
        } catch (PDOException $e) {
            $errors[] = 'Location already exists.';
        }
    }
}

/* ---------- Delete location ---------- */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE location_id = ?");
        $stmt->execute([$id]);
        $in_use = (int) $stmt->fetchColumn();

        if ($in_use > 0) {
            $errors[] = "Cannot delete — {$in_use} job(s) are using this location.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: locations.php');
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Cannot delete location. It may be in use.';
    }
}

/* ---------- Fetch locations ---------- */
$locations = [];
try {
    $locations = $pdo->query("
        SELECT l.*,
               (SELECT COUNT(*) FROM jobs WHERE location_id = l.id) AS job_count
        FROM locations l
        ORDER BY l.province, l.municipality
    ")->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Could not load locations: ' . $e->getMessage();
}

/* ---------- Stats ---------- */
$total_locations = count($locations);
$used_locations  = 0;
$empty_locations = 0;
$total_jobs      = 0;

$province_counts = [];
foreach ($locations as $loc) {
    $total_jobs += (int) $loc['job_count'];
    if ((int) $loc['job_count'] > 0) $used_locations++;
    else $empty_locations++;

    $p = $loc['province'] ?? 'Unknown';
    $province_counts[$p] = ($province_counts[$p] ?? 0) + 1;
}

/* ---------- Header vars ---------- */
$page_subtitle  = 'Add or remove municipalities in Lanao del Norte & Lanao del Sur.';
$page_icon      = 'fa-map-marker-alt';
$page_theme     = 'violet';
$header_script  = 'Manage locations 📍';
$breadcrumb     = 'Admin / Locations';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<!-- ============ ALERTS ============ -->
<?php if ($success): ?>
    <div class="alert-soft success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert-soft danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div class="admin-stat-label">Total Locations</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_locations); ?></div>
        <div class="admin-stat-sub">All municipalities</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">In Use</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($used_locations); ?></div>
        <div class="admin-stat-sub">With active jobs</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-inbox"></i></div>
            <div class="admin-stat-label">Unused</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($empty_locations); ?></div>
        <div class="admin-stat-sub">No jobs yet</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-briefcase"></i></div>
            <div class="admin-stat-label">Total Jobs</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_jobs); ?></div>
        <div class="admin-stat-sub">Across all locations</div>
    </div>
</div>

<!-- ============ GRID: LIST + ADD ============ -->
<div class="grid-2 locations-grid">

    <!-- LOCATIONS LIST -->
    <div class="card-panel locations-list-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-map-marker-alt"></i></span>
                All Locations
                <span class="count-chip"><?php echo count($locations); ?></span>
            </div>
            <div class="panel-sub">
                <?php
                $province_summary = [];
                foreach ($province_counts as $p => $c) {
                    $province_summary[] = $c . ' in ' . htmlspecialchars($p);
                }
                echo implode(' · ', $province_summary);
                ?>
            </div>
        </div>

        <?php if (count($locations) > 0): ?>
            <div class="locations-list">
                <?php foreach ($locations as $loc): ?>
                    <div class="location-item">
                        <div class="location-icon">
                            <i class="fas fa-map-pin"></i>
                        </div>
                        <div class="location-body">
                            <div class="location-name"><?php echo htmlspecialchars($loc['municipality']); ?></div>
                            <div class="location-meta">
                                <i class="fas fa-map"></i>
                                <?php echo htmlspecialchars($loc['province']); ?>
                                <span class="dot-sep">·</span>
                                <span class="location-id">#<?php echo $loc['id']; ?></span>
                                <?php if ((int)$loc['job_count'] > 0): ?>
                                    <span class="location-jobs">
                                        <i class="fas fa-briefcase"></i>
                                        <?php echo (int)$loc['job_count']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="?delete=<?php echo $loc['id']; ?>"
                           class="btn-icon-sm danger"
                           onclick="return confirm('Delete this location?')"
                           title="Delete">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-map-marker-alt"></i>
                <h4>No locations yet</h4>
                <p>Add your first municipality to get started.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADD LOCATION — sticky wrapper -->
    <div class="add-location-sticky-wrap">
        <div class="card-panel add-location-panel">
            <div class="card-panel-head">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon"><i class="fas fa-plus"></i></span>
                    Add New Location
                </div>
            </div>

            <form method="POST" class="add-location-form">
                <div class="form-group">
                    <label class="form-label-sm">
                        Municipality <span class="required">*</span>
                    </label>
                    <input type="text"
                           name="municipality"
                           class="form-input-sm"
                           placeholder="e.g. Iligan City"
                           maxlength="120"
                           required>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i>
                        Enter the official municipality or city name.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Province</label>
                    <select name="province" class="form-select-sm-custom">
                        <option value="Lanao del Norte">Lanao del Norte</option>
                        <option value="Lanao del Sur">Lanao del Sur</option>
                    </select>
                </div>

                <button type="submit" name="add_location" class="btn-soft primary btn-block">
                    <i class="fas fa-plus"></i> Add Location
                </button>
            </form>

            <div class="add-location-tip">
                <div class="tip-icon"><i class="fas fa-lightbulb"></i></div>
                <div class="tip-text">
                    <strong>Tip:</strong> Locations are used when employers post jobs and when seekers search for work nearby.
                </div>
            </div>
        </div>
    </div>

</div>

<style>
/* ============================================================
   LOCATIONS PAGE — COMPACT + FLAT DARK GLASS
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

/* ---------- Panel head ---------- */
.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 20px;
    padding: 0 9px;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    border: 1px solid rgba(96, 165, 250, 0.25);
    font-size: 0.68rem;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 6px;
}
.panel-sub {
    font-size: 0.72rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.55);
    max-width: 260px;
    text-align: right;
    line-height: 1.4;
}
.locations-grid {
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 20px;
    align-items: start !important;
}
@media (max-width: 992px) {
    .locations-grid { grid-template-columns: 1fr; }
    .panel-sub { text-align: left; max-width: 100%; }
}

.locations-list-panel .card-panel-head {
    flex-wrap: wrap;
    align-items: flex-start;
    padding: 14px 20px !important;
}

/* ============================================================
   LOCATIONS LIST
   ============================================================ */
.locations-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 620px;
    overflow-y: auto;
    padding: 8px;
}
.locations-list::-webkit-scrollbar { width: 6px; }
.locations-list::-webkit-scrollbar-track { background: transparent; }
.locations-list::-webkit-scrollbar-thumb {
    background: rgba(120, 160, 255, 0.20);
    border-radius: 6px;
}
.locations-list::-webkit-scrollbar-thumb:hover {
    background: rgba(120, 160, 255, 0.35);
}

/* Location item */
.location-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px !important;
    border-radius: 10px;
    transition: background 0.15s;
    min-width: 0;
}
.location-item:hover { background: rgba(96, 165, 250, 0.06); }

/* Pin icon — RED (matches earlier request) */
.location-icon {
    width: 34px !important;
    height: 34px !important;
    border-radius: 10px !important;
    background: rgba(239, 68, 68, 0.15);
    color: #F87171;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82rem !important;
    flex-shrink: 0;
    transition: 0.2s;
}
.location-item:hover .location-icon {
    background: linear-gradient(135deg, #EF4444, #F87171);
    color: #fff;
}

.location-body { flex: 1; min-width: 0; }
.location-name {
    font-weight: 700;
    font-size: 0.84rem !important;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px !important;
}
.location-meta {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.location-meta i {
    color: #93C5FD;
    font-size: 0.66rem !important;
}
.location-id {
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
    letter-spacing: 0.3px;
}
.dot-sep { color: rgba(255, 255, 255, 0.3); font-weight: 900; }

.location-jobs {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px !important;
    background: rgba(96, 165, 250, 0.10);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 20px;
    font-size: 0.66rem !important;
    font-weight: 700;
    color: #93C5FD;
}
.location-jobs i { font-size: 0.58rem !important; }

.location-item .btn-icon-sm {
    width: 28px !important;
    height: 28px !important;
    font-size: 0.68rem !important;
}

/* ============================================================
   ADD LOCATION — sticky wrapper
   ============================================================ */
.add-location-sticky-wrap {
    position: sticky;
    top: 90px;
    align-self: start;
    z-index: 5;
}

.add-location-panel {
    position: relative;
    background: rgba(20, 32, 68, 0.65) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 16px !important;
    overflow: hidden;
    margin-bottom: 0 !important;
}

.add-location-panel:hover,
.add-location-panel:focus,
.add-location-panel:focus-within,
.add-location-panel:active {
    background: rgba(20, 32, 68, 0.65) !important;
    border-color: rgba(120, 160, 255, 0.15) !important;
    transform: none !important;
}

.add-location-panel .card-panel-head {
    padding: 14px 20px !important;
}

@media (min-height: 700px) {
    .add-location-sticky-wrap {
        max-height: calc(100vh - 110px);
    }
    .add-location-panel {
        max-height: 100%;
        overflow-y: auto;
    }
    .add-location-panel::-webkit-scrollbar { width: 6px; }
    .add-location-panel::-webkit-scrollbar-track { background: transparent; }
    .add-location-panel::-webkit-scrollbar-thumb {
        background: rgba(120, 160, 255, 0.20);
        border-radius: 6px;
    }
}

/* Form inside panel */
.add-location-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px 20px 18px;
}
.form-group { display: flex; flex-direction: column; }
.form-group .required {
    color: #FCA5A5;
    font-weight: 800;
    margin-left: 2px;
}
.form-hint {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.55);
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}
.form-hint i { color: #93C5FD; font-size: 0.66rem !important; }

.btn-block {
    width: 100%;
    justify-content: center;
    padding: 10px 16px !important;
    font-size: 0.82rem !important;
}

/* Tip box */
.add-location-tip {
    display: flex;
    gap: 12px;
    margin: 0 20px 18px;
    padding: 12px;
    background: rgba(96, 165, 250, 0.08);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 12px;
}
.tip-icon {
    width: 28px !important;
    height: 28px !important;
    border-radius: 8px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem !important;
    flex-shrink: 0;
}
.tip-icon i { color: #93C5FD; }
.tip-text {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.75);
    line-height: 1.5;
    font-weight: 500;
}
.tip-text strong { color: #fff; font-weight: 800; }

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
@media (max-width: 992px) {
    .add-location-sticky-wrap {
        position: static !important;
        max-height: none !important;
    }
    .add-location-panel {
        max-height: none !important;
        overflow: hidden;
    }
}
@media (max-width: 480px) {
    .location-item { padding: 8px 10px !important; gap: 10px; }
    .location-icon { width: 30px !important; height: 30px !important; font-size: 0.72rem !important; }
    .location-name { font-size: 0.8rem !important; }
    .location-meta { font-size: 0.68rem !important; }
}
</style>

<?php include '_footer.php'; ?>