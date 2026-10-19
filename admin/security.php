<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Security & Access';

/* ============================================================
   DETECT users TABLE COLUMNS
   ============================================================ */
$user_columns = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $user_columns[] = $col['Field'];
    }
} catch (PDOException $e) { }

$has_name       = in_array('name', $user_columns);
$has_first      = in_array('first_name', $user_columns);
$has_last       = in_array('last_name', $user_columns);
$has_password   = in_array('password', $user_columns);
$has_password_h = in_array('password_hash', $user_columns);
$has_status     = in_array('status', $user_columns);
$has_created    = in_array('created_at', $user_columns);

$password_col = $has_password ? 'password' : ($has_password_h ? 'password_hash' : 'password');

/* ============================================================
   HANDLE ACTIONS
   ============================================================ */
$success = '';
$errors  = [];
$my_id   = $_SESSION['user_id'] ?? 0;

/* ---- Add new admin ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $email    = trim($_POST['email'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'That email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $cols  = ['email', $password_col, 'role'];
                $vals  = [$email, $hash, 'admin'];
                $place = ['?', '?', '?'];

                if ($has_name && $name !== '') {
                    $cols[] = 'name'; $vals[] = $name; $place[] = '?';
                } elseif ($has_first) {
                    $cols[] = 'first_name'; $vals[] = ($name !== '' ? $name : 'Admin'); $place[] = '?';
                    if ($has_last) { $cols[] = 'last_name'; $vals[] = ''; $place[] = '?'; }
                }

                if ($has_status)  { $cols[] = 'status'; $vals[] = 'active'; $place[] = '?'; }
                if ($has_created) { $cols[] = 'created_at'; $place[] = 'NOW()'; }

                $sql = "INSERT INTO users (" . implode(',', $cols) . ") VALUES (" . implode(',', $place) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($vals);
                $new_id = (int) $pdo->lastInsertId();

                logAdminAction($pdo, $my_id, 'admin.created', 'user', $new_id, "Created admin: {$email}");
                $success = "Admin account created for {$email}.";
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not create admin: ' . $e->getMessage();
        }
    }
}

/* ---- Toggle admin status ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_admin_status'])) {
    $target_id  = (int) $_POST['user_id'];
    $new_status = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

    if ($target_id === $my_id) {
        $errors[] = "You can't change your own status.";
    } elseif (!$has_status) {
        $errors[] = "Your users table doesn't have a 'status' column.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'admin'");
            $stmt->execute([$new_status, $target_id]);
            logAdminAction($pdo, $my_id, 'admin.status_changed', 'user', $target_id, "Set to {$new_status}");
            $success = "Admin status updated to {$new_status}.";
        } catch (PDOException $e) {
            $errors[] = 'Could not update status.';
        }
    }
}

/* ---- Delete admin ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $target_id = (int) $_POST['user_id'];

    if ($target_id === $my_id) {
        $errors[] = "You can't delete your own account.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admin_count = (int) $stmt->fetchColumn();

            if ($admin_count <= 1) {
                $errors[] = "You can't delete the last admin.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
                $stmt->execute([$target_id]);
                logAdminAction($pdo, $my_id, 'admin.deleted', 'user', $target_id, "Deleted admin");
                $success = 'Admin account deleted.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not delete admin.';
        }
    }
}

/* ---- Clear audit log ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    try {
        $pdo->exec("TRUNCATE TABLE admin_activity_log");
        $success = 'Audit log cleared.';
    } catch (PDOException $e) {
        $errors[] = 'Could not clear log.';
    }
}

/* ============================================================
   FETCH ADMINS
   ============================================================ */
$admins = [];
try {
    $name_select = "'Admin' AS display_name";
    if ($has_name) {
        $name_select = "COALESCE(name, 'Admin') AS display_name";
    } elseif ($has_first) {
        $name_select = $has_last
            ? "TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS display_name"
            : "COALESCE(first_name, 'Admin') AS display_name";
    }

    $status_select = $has_status ? "status" : "'active' AS status";
    $created_select = $has_created ? "created_at" : "NOW() AS created_at";

    $stmt = $pdo->query("
        SELECT id, email, $name_select, $status_select, $created_select
        FROM users
        WHERE role = 'admin'
        ORDER BY id ASC
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $admins = []; }

/* ============================================================
   FETCH AUDIT LOG
   ============================================================ */
$log_filter  = $_GET['log_filter'] ?? 'all';
$log_entries = [];
try {
    $where = '';
    if ($log_filter === 'today')      $where = "WHERE DATE(l.created_at) = CURDATE()";
    elseif ($log_filter === 'week')   $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    elseif ($log_filter === 'month')  $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $stmt = $pdo->query("
        SELECT l.*, u.email AS admin_email
        FROM admin_activity_log l
        LEFT JOIN users u ON u.id = l.user_id
        $where
        ORDER BY l.created_at DESC
        LIMIT 100
    ");
    $log_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $log_entries = []; }

/* ============================================================
   STATS
   ============================================================ */
$total_admins    = count($admins);
$active_admins   = 0;
$inactive_admins = 0;
foreach ($admins as $a) {
    if (($a['status'] ?? 'active') === 'active') $active_admins++;
    else $inactive_admins++;
}

$log_count_today = 0;
$log_count_week  = 0;
try {
    $log_count_today = (int) $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $log_count_week  = (int) $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
} catch (PDOException $e) { }

logAdminAction($pdo, $my_id, 'security.viewed', null, null, null);

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Manage admin accounts, roles, and audit trail.';
$page_icon      = 'fa-shield-alt';
$page_theme     = 'violet';
$header_script  = 'Guard the<br>platform 🛡️';
$breadcrumb     = 'Admin / Security';

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
            <div class="admin-stat-icon"><i class="fas fa-user-shield"></i></div>
            <div class="admin-stat-label">Total Admins</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_admins); ?></div>
        <div class="admin-stat-sub">All admin accounts</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Active</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($active_admins); ?></div>
        <div class="admin-stat-sub">Can log in</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-pause-circle"></i></div>
            <div class="admin-stat-label">Inactive</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($inactive_admins); ?></div>
        <div class="admin-stat-sub">Suspended</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-history"></i></div>
            <div class="admin-stat-label">Actions Today</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($log_count_today); ?></div>
        <div class="admin-stat-sub"><?php echo number_format($log_count_week); ?> this week</div>
    </div>
</div>

<!-- ============ TABS ============ -->
<div class="security-tabs">
    <button class="security-tab active" data-tab="accounts" type="button">
        <i class="fas fa-user-shield"></i> Admin Accounts
    </button>
    <button class="security-tab" data-tab="log" type="button">
        <i class="fas fa-history"></i> Audit Log
        <span class="tab-badge"><?php echo count($log_entries); ?></span>
    </button>
</div>

<!-- ============ TAB: ACCOUNTS ============ -->
<div class="security-pane active" id="pane-accounts">
    <div class="security-layout">

        <div class="card-panel">
            <div class="card-panel-head">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon"><i class="fas fa-users-cog"></i></span>
                    Admin Accounts
                    <span class="count-chip"><?php echo count($admins); ?></span>
                </div>
            </div>

            <?php if (count($admins) > 0): ?>
                <div class="admin-list">
                    <?php foreach ($admins as $a):
                        $is_me     = ($a['id'] == $my_id);
                        $initial   = strtoupper(substr($a['email'], 0, 1));
                        $is_active = ($a['status'] ?? 'active') === 'active';
                    ?>
                        <div class="admin-row <?php echo $is_me ? 'is-me' : ''; ?>">
                            <div class="admin-avatar <?php echo $is_active ? '' : 'is-inactive'; ?>">
                                <?php echo htmlspecialchars($initial); ?>
                            </div>
                            <div class="admin-body">
                                <div class="admin-name">
                                    <?php echo htmlspecialchars($a['display_name'] ?? 'Admin'); ?>
                                    <?php if ($is_me): ?>
                                        <span class="you-badge">You</span>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-meta">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo htmlspecialchars($a['email']); ?>
                                    <span class="dot-sep">·</span>
                                    <i class="far fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($a['created_at'])); ?>
                                </div>
                            </div>
                            <div class="admin-actions">
                                <span class="status-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($a['status'] ?? 'active'); ?>
                                </span>

                                <?php if (!$is_me): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$a['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $is_active ? 'inactive' : 'active'; ?>">
                                        <button type="submit" name="toggle_admin_status"
                                                class="btn-icon-sm"
                                                title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>"
                                                onclick="return confirm('<?php echo $is_active ? 'Deactivate' : 'Activate'; ?> this admin?');">
                                            <i class="fas <?php echo $is_active ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" name="delete_admin"
                                                class="btn-icon-sm danger"
                                                title="Delete"
                                                onclick="return confirm('Delete this admin account? This cannot be undone.');">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="self-note">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-shield"></i>
                    <h4>No admin accounts</h4>
                    <p>Something's wrong — there should always be at least one admin.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ADD ADMIN — sticky wrapper -->
        <div class="add-admin-sticky-wrap">
            <div class="card-panel add-admin-panel">
                <div class="card-panel-head">
                    <div class="card-panel-title">
                        <span class="card-panel-title-icon"><i class="fas fa-user-plus"></i></span>
                        Add New Admin
                    </div>
                </div>

                <form method="POST" class="add-admin-form">
                    <div class="form-group">
                        <label class="form-label-sm">Full Name</label>
                        <input type="text" name="name" class="form-input-sm" placeholder="e.g. Maria Santos" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label-sm">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-input-sm" placeholder="admin@navora.local" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label-sm">Password <span class="required">*</span></label>
                        <input type="password" name="password" class="form-input-sm"
                               placeholder="Min. 8 characters"
                               minlength="8" required>
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            Share this password securely — the admin can change it after logging in.
                        </div>
                    </div>

                    <button type="submit" name="add_admin" class="btn-soft primary btn-block">
                        <i class="fas fa-plus"></i> Create Admin
                    </button>
                </form>

                <div class="security-tip">
                    <div class="tip-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="tip-text">
                        <strong>Security tip:</strong> Only create admin accounts for trusted personnel. Each action is logged in the Audit Log.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ TAB: AUDIT LOG ============ -->
<div class="security-pane" id="pane-log">
    <div class="card-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-history"></i></span>
                Audit Log
                <span class="count-chip"><?php echo count($log_entries); ?></span>
            </div>
            <div class="log-actions">
                <div class="log-filters">
                    <a href="?log_filter=all"   class="log-filter <?php echo $log_filter === 'all'   ? 'active' : ''; ?>">All</a>
                    <a href="?log_filter=today" class="log-filter <?php echo $log_filter === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="?log_filter=week"  class="log-filter <?php echo $log_filter === 'week'  ? 'active' : ''; ?>">Week</a>
                    <a href="?log_filter=month" class="log-filter <?php echo $log_filter === 'month' ? 'active' : ''; ?>">Month</a>
                </div>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Clear all audit log entries? This cannot be undone.');">
                    <button type="submit" name="clear_log" class="btn-soft danger">
                        <i class="fas fa-eraser"></i> Clear Log
                    </button>
                </form>
            </div>
        </div>

        <?php if (count($log_entries) > 0): ?>
            <div class="log-list">
                <?php foreach ($log_entries as $entry):
                    $action_class = 'default';
                    if (strpos($entry['action'], 'deleted') !== false)     $action_class = 'danger';
                    elseif (strpos($entry['action'], 'created') !== false) $action_class = 'success';
                    elseif (strpos($entry['action'], 'status') !== false)  $action_class = 'warning';
                    elseif (strpos($entry['action'], 'viewed') !== false)  $action_class = 'muted';

                    $action_label = ucwords(str_replace(['.', '_'], [' · ', ' '], $entry['action']));
                ?>
                    <div class="log-row">
                        <div class="log-dot <?php echo $action_class; ?>"></div>
                        <div class="log-body">
                            <div class="log-title">
                                <span class="log-action"><?php echo htmlspecialchars($action_label); ?></span>
                                <?php if (!empty($entry['entity_type'])): ?>
                                    <span class="log-entity">
                                        <?php echo htmlspecialchars($entry['entity_type']); ?>
                                        <?php if (!empty($entry['entity_id'])): ?>#<?php echo (int)$entry['entity_id']; ?><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($entry['details'])): ?>
                                <div class="log-details"><?php echo htmlspecialchars($entry['details']); ?></div>
                            <?php endif; ?>
                            <div class="log-meta">
                                <span>
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($entry['admin_email'] ?? 'System'); ?>
                                </span>
                                <span>
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y · g:i A', strtotime($entry['created_at'])); ?>
                                </span>
                                <?php if (!empty($entry['ip_address'])): ?>
                                    <span>
                                        <i class="fas fa-network-wired"></i>
                                        <?php echo htmlspecialchars($entry['ip_address']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h4>No activity yet</h4>
                <p>Admin actions will be logged here as they happen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ============================================================
   SECURITY PAGE — COMPACT + FLAT DARK GLASS
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

/* ---------- Tabs ---------- */
.security-tabs {
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
.security-tabs::-webkit-scrollbar { display: none; }

.security-tab {
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
.security-tab i {
    font-size: 0.78rem !important;
    color: rgba(255, 255, 255, 0.5);
    transition: 0.2s;
}
.security-tab:hover {
    background: rgba(96, 165, 250, 0.12);
    color: #FFFFFF;
}
.security-tab:hover i { color: #93C5FD; }

.security-tab.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #FFFFFF;
    box-shadow: none;
}
.security-tab.active i { color: #FFFFFF; }

.tab-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px !important; height: 18px !important;
    padding: 0 6px !important;
    background: rgba(255, 255, 255, 0.20);
    font-size: 0.62rem !important;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 3px;
    color: #FFFFFF;
}
.security-tab:not(.active) .tab-badge {
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
}

.security-pane { display: none; }
.security-pane.active { display: block; }

.security-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
@media (max-width: 992px) {
    .security-layout { grid-template-columns: 1fr; }
}

/* ---------- Panel head padding compact ---------- */
.card-panel .card-panel-head {
    padding: 14px 20px !important;
}

/* ---------- Admin list ---------- */
.admin-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px;
}

.admin-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px !important;
    border-radius: 10px;
    transition: background 0.15s;
}
.admin-row:hover { background: rgba(96, 165, 250, 0.06); }
.admin-row.is-me { background: rgba(96, 165, 250, 0.08); }

.admin-avatar {
    width: 36px !important; height: 36px !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.78rem !important;
    flex-shrink: 0;
}
.admin-avatar.is-inactive {
    background: rgba(148, 163, 184, 0.30);
    color: rgba(255,255,255,0.7);
}

.admin-body { flex: 1; min-width: 0; }
.admin-name {
    font-weight: 700; font-size: 0.84rem !important;
    color: #FFFFFF;
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 3px !important;
}
.you-badge {
    display: inline-flex;
    padding: 2px 7px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    font-size: 0.58rem !important; font-weight: 800;
    letter-spacing: 0.4px;
    border-radius: 999px;
    text-transform: uppercase;
    border: 1px solid rgba(96, 165, 250, 0.25);
}
.admin-meta {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    flex-wrap: wrap;
}
.admin-meta i { color: #93C5FD; font-size: 0.64rem !important; }
.dot-sep { color: rgba(255,255,255,0.3); font-weight: 900; }

.admin-actions {
    display: flex; align-items: center; gap: 5px; flex-shrink: 0;
}
.admin-actions .btn-icon-sm {
    width: 28px !important; height: 28px !important;
    font-size: 0.68rem !important;
}
.self-note {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px !important; height: 28px !important;
    border-radius: 9px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    font-size: 0.68rem !important;
}

/* Status badge */
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
    width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0;
}
.status-badge.active {
    background: rgba(16, 185, 129, 0.15);
    color: #6EE7B7;
    border-color: rgba(16, 185, 129, 0.35);
}
.status-badge.active .status-dot { background: #10B981; }
.status-badge.inactive {
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
    border-color: rgba(239, 68, 68, 0.35);
}
.status-badge.inactive .status-dot { background: #EF4444; }

/* ---------- Add admin form ---------- */
.add-admin-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px 20px 18px;
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
.btn-block {
    width: 100%;
    justify-content: center;
    padding: 10px 16px !important;
    font-size: 0.82rem !important;
}

/* ---------- Security tip ---------- */
.security-tip {
    display: flex; gap: 12px;
    margin: 0 20px 18px;
    padding: 12px;
    background: rgba(96, 165, 250, 0.08);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 12px;
}
.security-tip .tip-icon {
    width: 28px !important; height: 28px !important;
    border-radius: 8px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem !important;
    flex-shrink: 0;
}
.security-tip .tip-icon i { color: #93C5FD; }
.security-tip .tip-text {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.75);
    line-height: 1.5;
    font-weight: 500;
}
.security-tip .tip-text strong { color: #FFFFFF; font-weight: 800; }

/* ---------- Log actions ---------- */
.log-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.log-filters {
    display: flex; gap: 3px;
    padding: 3px;
    background: rgba(20, 32, 68, 0.40);
    border-radius: 999px;
    border: 1px solid rgba(120, 160, 255, 0.12);
}
.log-filter {
    padding: 5px 12px !important;
    border-radius: 999px;
    font-size: 0.72rem !important;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.65);
    transition: 0.2s;
}
.log-filter:hover {
    color: #FFFFFF;
    background: rgba(96, 165, 250, 0.12);
}
.log-filter.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #FFFFFF;
    box-shadow: none;
}
.log-actions .btn-soft {
    padding: 6px 12px !important;
    font-size: 0.74rem !important;
}

/* ---------- Audit log list ---------- */
.log-list {
    display: flex; flex-direction: column; gap: 2px;
    max-height: 720px;
    overflow-y: auto;
    padding: 8px;
}
.log-list::-webkit-scrollbar { width: 6px; }
.log-list::-webkit-scrollbar-track { background: transparent; }
.log-list::-webkit-scrollbar-thumb { background: rgba(120, 160, 255, 0.20); border-radius: 6px; }

.log-row {
    display: flex; gap: 12px;
    padding: 10px 12px !important;
    border-radius: 10px;
    transition: 0.15s;
}
.log-row:hover { background: rgba(96, 165, 250, 0.06); }

.log-dot {
    width: 8px !important; height: 8px !important;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 6px;
    background: #3B82F6;
}
.log-dot.danger  { background: #EF4444; }
.log-dot.success { background: #10B981; }
.log-dot.warning { background: #F59E0B; }
.log-dot.muted   { background: rgba(148, 163, 184, 0.60); }

.log-body { flex: 1; min-width: 0; }
.log-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.log-action { font-size: 0.78rem !important; font-weight: 700; color: #FFFFFF; }
.log-entity {
    display: inline-flex; align-items: center;
    padding: 2px 8px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    font-size: 0.6rem !important; font-weight: 800;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border: 1px solid rgba(96, 165, 250, 0.20);
}
.log-details {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.7);
    font-weight: 500;
    line-height: 1.5;
    margin-bottom: 6px !important;
}
.log-meta {
    display: flex; gap: 14px;
    flex-wrap: wrap;
    font-size: 0.66rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 600;
}
.log-meta span { display: inline-flex; align-items: center; gap: 4px; }
.log-meta i { color: #93C5FD; font-size: 0.6rem !important; }

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

/* ============================================================
   ADD NEW ADMIN — sticky WRAPPER
   ============================================================ */
.add-admin-sticky-wrap {
    position: sticky;
    top: 90px;
    align-self: start;
    z-index: 5;
}

.add-admin-panel {
    position: relative;
    background: rgba(20, 32, 68, 0.65) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 16px !important;
    overflow: hidden;
    margin-bottom: 0 !important;
}

.add-admin-panel:hover,
.add-admin-panel:focus,
.add-admin-panel:focus-within,
.add-admin-panel:active {
    background: rgba(20, 32, 68, 0.65) !important;
    border-color: rgba(120, 160, 255, 0.15) !important;
    transform: none !important;
}

@media (min-height: 700px) {
    .add-admin-sticky-wrap {
        max-height: calc(100vh - 110px);
    }
    .add-admin-panel {
        max-height: 100%;
        overflow-y: auto;
    }
    .add-admin-panel::-webkit-scrollbar { width: 6px; }
    .add-admin-panel::-webkit-scrollbar-track { background: transparent; }
    .add-admin-panel::-webkit-scrollbar-thumb {
        background: rgba(120, 160, 255, 0.20);
        border-radius: 6px;
    }
}

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
    .security-tabs { padding: 4px; }
    .security-tab { padding: 7px 12px !important; font-size: 0.72rem !important; }
    .add-admin-sticky-wrap { position: static !important; max-height: none !important; }
    .add-admin-panel { max-height: none !important; overflow: hidden; }
    .log-actions { width: 100%; flex-direction: column; align-items: stretch; }
    .log-actions form { display: block; width: 100%; }
    .log-actions .btn-soft { width: 100%; justify-content: center; }
    .log-meta { gap: 10px; }
}
</style>

<script>
/* Tabs */
(function() {
    const tabs = document.querySelectorAll('.security-tab');
    const panes = {
        'accounts': document.getElementById('pane-accounts'),
        'log':      document.getElementById('pane-log'),
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

    /* Deep link via ?tab=log */
    const urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && panes[urlTab]) {
        tabs.forEach(t => t.classList.remove('active'));
        Object.values(panes).forEach(p => p && p.classList.remove('active'));
        document.querySelector('.security-tab[data-tab="' + urlTab + '"]')?.classList.add('active');
        panes[urlTab].classList.add('active');
    }
})();
</script>

<?php include '_footer.php'; ?>