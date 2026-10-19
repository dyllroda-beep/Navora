<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$my_id = $_SESSION['user_id'] ?? 0;
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id === 0) { header('Location: users.php'); exit; }

/* ============================================================
   HANDLE ACTIONS (Approve / Reject / Suspend / Reactivate / ID)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['approve_account'])) {
        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$user_id]);
        $pdo->prepare("UPDATE employers SET verification_status = 'approved' WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, 'Account Approved', 'Your account has been reviewed and approved.', 0)")->execute([$user_id]);
        logAdminAction($pdo, $my_id, 'user.approved', 'user', $user_id, 'Account approved');
        header("Location: user-details.php?id=$user_id&msg=approved"); exit;
    }

    if (isset($_POST['reject_account'])) {
        $reason = trim($_POST['reason'] ?? 'Incomplete profile requirements.');
        $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$user_id]);
        $pdo->prepare("UPDATE employers SET verification_status = 'rejected' WHERE user_id = ?")->execute([$user_id]);
        $msg = "Your account application was rejected.\n\nReason: $reason";
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, 'Account Rejected', ?, 0)")->execute([$user_id, $msg]);
        logAdminAction($pdo, $my_id, 'user.rejected', 'user', $user_id, "Rejected: $reason");
        header("Location: user-details.php?id=$user_id&msg=rejected"); exit;
    }

    if (isset($_POST['action_type'])) {
        $action_type = $_POST['action_type'];
        $reason = trim($_POST['reason'] ?? 'Action taken by admin');
        if ($action_type === 'suspend' || $action_type === 'reactivate') {
            $new_status = ($action_type === 'suspend') ? 'suspended' : 'active';
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $user_id]);
            $pdo->prepare("INSERT INTO account_moderation_log (user_id, admin_id, action, description) VALUES (?, ?, ?, ?)")->execute([$user_id, $my_id, $action_type, $reason]);
            $title = ($action_type === 'suspend') ? 'Account Suspended' : 'Account Reactivated';
            $msg = ($action_type === 'suspend') ? "Your account has been suspended. Reason: $reason" : "Your account has been reactivated.";
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)")->execute([$user_id, $title, $msg]);
            header("Location: user-details.php?id=$user_id&msg=updated"); exit;
        }
    }

    // ID ACTIONS
    if (isset($_POST['verify_id'])) {
        $table = isset($_POST['is_employer']) ? 'employers' : 'job_seekers';
        $pdo->prepare("UPDATE $table SET id_status = 'verified' WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, 'ID Verified', 'Your identity has been verified successfully.', 0)")->execute([$user_id]);
        logAdminAction($pdo, $my_id, 'user.id_verified', 'user', $user_id, 'Admin verified ID');
        header("Location: user-details.php?id=$user_id&msg=id_verified"); exit;
    }

    if (isset($_POST['reject_id'])) {
        $table = isset($_POST['is_employer']) ? 'employers' : 'job_seekers';
        $reason = trim($_POST['reason'] ?? 'Invalid ID document.');
        $pdo->prepare("UPDATE $table SET id_status = 'rejected' WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, 'ID Verification Failed', ?, 0)")->execute([$user_id, "Your ID was rejected. Reason: $reason"]);
        logAdminAction($pdo, $my_id, 'user.id_rejected', 'user', $user_id, "Rejected ID: $reason");
        header("Location: user-details.php?id=$user_id&msg=id_rejected"); exit;
    }
}

/* ============================================================
   FETCH USER DATA
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role != 'admin'");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { echo "User not found."; exit; }

$is_employer = ($user['role'] === 'employer');
$profile = [];

if ($is_employer) {
    $st = $pdo->prepare("SELECT * FROM employers WHERE user_id = ? LIMIT 1");
    $st->execute([$user_id]);
    $profile = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} else {
    $st = $pdo->prepare("SELECT * FROM job_seekers WHERE user_id = ? LIMIT 1");
    $st->execute([$user_id]);
    $profile = $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

$seeker_id = ($is_employer) ? 0 : ($profile['id'] ?? 0);
$displayName = $is_employer ? ($profile['company_name'] ?? $user['email']) : (trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: $user['email']);
$photo = $is_employer ? ($profile['logo'] ?? null) : ($profile['profile_photo'] ?? null);
$initial = strtoupper(substr($displayName, 0, 1));

$about    = $is_employer ? ($profile['company_description'] ?? null) : ($profile['career_objective'] ?? $profile['bio'] ?? null);
$phone    = $is_employer ? ($profile['phone'] ?? null) : ($profile['phone'] ?? null);
$location = $is_employer ? ($profile['address'] ?? null) : ($profile['municipality'] ?? null);
$address  = $is_employer ? ($profile['address'] ?? null) : ($profile['address'] ?? null);
$gender   = $is_employer ? null : ($profile['gender'] ?? null);
$bdate    = $is_employer ? null : ($profile['birthdate'] ?? null);

// ID Logic
$id_status = $profile['id_status'] ?? 'unverified';
$id_front  = $profile['id_front'] ?? null;
$id_back   = $profile['id_back'] ?? null;

// Clean paths
$id_front_clean = $id_front ? ltrim($id_front, '/\\') : null;
$id_back_clean  = $id_back ? ltrim($id_back, '/\\') : null;
$base_dir = dirname(__DIR__); 

$front_exists = $id_front_clean && file_exists($base_dir . '/' . $id_front_clean);
$back_exists  = $id_back_clean && file_exists($base_dir . '/' . $id_back_clean);

/* ============================================================
   EXTRA DATA
   ============================================================ */
$education = $work_exp = $skills = [];
if (!$is_employer && $seeker_id > 0) {
    try { $st = $pdo->prepare("SELECT * FROM education WHERE seeker_id = ? ORDER BY end_year DESC"); $st->execute([$seeker_id]); $education = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    try { $st = $pdo->prepare("SELECT * FROM work_experience WHERE seeker_id = ? ORDER BY end_date DESC"); $st->execute([$seeker_id]); $work_exp = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    try { $st = $pdo->prepare("SELECT s.skill_name FROM seeker_skills ss JOIN skills s ON ss.skill_id = s.id WHERE ss.seeker_id = ?"); $st->execute([$seeker_id]); $skills = $st->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
}

$missing_fields = [];
if ($is_employer) {
    if (empty($phone))    $missing_fields[] = 'Phone Number';
    if (empty($location)) $missing_fields[] = 'Address / Location';
    if (empty($about))    $missing_fields[] = 'Company Description';
} else {
    if (empty($phone))    $missing_fields[] = 'Phone Number';
    if (empty($location)) $missing_fields[] = 'Location / Municipality';
    if (empty($about))    $missing_fields[] = 'Bio / Career Objective';
}
$is_complete = empty($missing_fields);

$stmt = $pdo->prepare("SELECT l.*, u.email AS admin_email FROM account_moderation_log l LEFT JOIN users u ON u.id = l.admin_id WHERE l.user_id = ? ORDER BY l.created_at DESC");
$stmt->execute([$user_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'User Profile: ' . $displayName;
$breadcrumb = 'Admin / User Details';
include '_header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if (in_array($msg, ['approved', 'updated', 'id_verified'])): ?>
    <div class="alert-soft success" style="margin-bottom: 20px;"><i class="fas fa-check-circle"></i> Action completed successfully.</div>
<?php elseif (in_array($msg, ['rejected', 'id_rejected'])): ?>
    <div class="alert-soft danger" style="margin-bottom: 20px;"><i class="fas fa-times-circle"></i> Action processed.</div>
<?php endif; ?>

<div class="profile-layout">
    <div class="profile-sidebar">
        <div class="card-panel profile-card">
            <div class="profile-header">
                <div class="profile-avatar <?php echo $is_employer ? 'is-employer' : 'is-seeker'; ?>">
                    <?php if (!empty($photo) && file_exists($base_dir . '/' . $photo)): ?>
                        <img src="/navora3/<?php echo htmlspecialchars($photo); ?>" alt="">
                    <?php else: ?>
                        <?php echo htmlspecialchars($initial); ?>
                    <?php endif; ?>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($displayName); ?></h2>
                <div class="profile-email"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                
                <div class="profile-badges">
                    <span class="status-badge <?php echo $user['status'] === 'active' ? 'active' : ($user['status'] === 'pending' ? 'pending' : 'inactive'); ?>">
                        <span class="status-dot"></span> <?php echo ucfirst($user['status']); ?>
                    </span>
                    <span class="role-badge <?php echo $is_employer ? 'employer' : 'seeker'; ?>">
                        <i class="fas <?php echo $is_employer ? 'fa-building' : 'fa-user'; ?>"></i> <?php echo ucfirst($user['role']); ?>
                    </span>
                </div>
            </div>
            
            <div class="profile-stats">
                <div class="p-stat">
                    <?php
                    $count = 0;
                    if ($is_employer) {
                        $st = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE employer_id = (SELECT id FROM employers WHERE user_id = ? LIMIT 1)"); 
                        $st->execute([$user_id]); $count = $st->fetchColumn();
                    } else {
                        $st = $pdo->prepare("SELECT COUNT(*) FROM applications a JOIN job_seekers s ON a.seeker_id = s.id WHERE s.user_id = ?"); 
                        $st->execute([$user_id]); $count = $st->fetchColumn();
                    }
                    ?>
                    <div class="p-stat-val"><?php echo number_format($count); ?></div>
                    <div class="p-stat-label"><?php echo $is_employer ? 'Jobs Posted' : 'Applications'; ?></div>
                </div>
                <div class="p-stat">
                    <?php
                    $st = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE reported_user_id = ?"); 
                    $st->execute([$user_id]); $reports = $st->fetchColumn();
                    ?>
                    <a href="/navora3/admin/reports.php?user_id=<?php echo $user_id; ?>" style="text-decoration: none;">
                        <div class="p-stat-val" style="color: <?php echo $reports > 0 ? '#FCA5A5' : '#fff'; ?>;"><?php echo $reports; ?></div>
                        <div class="p-stat-label">Reports</div>
                    </a>
                </div>
                <div class="p-stat">
                    <div class="p-stat-val"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                    <div class="p-stat-label">Joined</div>
                </div>
            </div>

            <div class="profile-actions">
                <a href="users.php" class="btn-soft" style="width: 100%; justify-content: center;"><i class="fas fa-arrow-left"></i> Back to List</a>
                
                <?php if ($user['status'] === 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Approve this account?');" style="margin-top:8px;">
                        <button type="submit" name="approve_account" value="1" class="btn-soft success" style="width: 100%; justify-content: center;" <?= !$is_complete ? 'disabled style="opacity:0.5; cursor:not-allowed; width:100%; justify-content:center;" title="Profile is incomplete."' : '' ?>>
                            <i class="fas fa-check"></i> Approve Account
                        </button>
                    </form>
                    <form method="POST" onsubmit="return setReason(this, 'reason', 'Reason for rejection?');" style="margin-top:8px;">
                        <input type="hidden" name="reason" value="Incomplete profile">
                        <button type="submit" name="reject_account" value="1" class="btn-soft danger" style="width: 100%; justify-content: center;">
                            <i class="fas fa-times"></i> Reject Account
                        </button>
                    </form>
                <?php elseif ($user['status'] === 'active'): ?>
                    <form method="POST" onsubmit="return confirm('Suspend this account?');">
                        <input type="hidden" name="action_type" value="suspend">
                        <input type="hidden" name="reason" value="Suspended by admin">
                        <button type="submit" class="btn-soft danger" style="width: 100%; justify-content: center; margin-top: 8px;">
                            <i class="fas fa-ban"></i> Suspend Account
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Reactivate this account?');">
                        <input type="hidden" name="action_type" value="reactivate">
                        <input type="hidden" name="reason" value="Reactivated by admin">
                        <button type="submit" class="btn-soft success" style="width: 100%; justify-content: center; margin-top: 8px;">
                            <i class="fas fa-check-circle"></i> Reactivate Account
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="profile-main">
        
        <!-- ID VERIFICATION FOR BOTH SEEKERS AND EMPLOYERS -->
        <div class="card-panel" style="border-color: rgba(139, 92, 246, 0.3); background: rgba(139, 92, 246, 0.05);">
            <div class="card-panel-head" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; margin-bottom: 16px;">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon" style="background: linear-gradient(135deg, #8B5CF6, #A78BFA);"><i class="fas fa-id-card"></i></span>
                    ID Verification
                </div>
                <span class="status-badge <?php echo $id_status === 'verified' ? 'active' : ($id_status === 'pending' ? 'pending' : 'inactive'); ?>">
                    <span class="status-dot"></span> <?= ucfirst($id_status) ?>
                </span>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 0 20px 20px;">
                <!-- Front ID -->
                <div style="background: rgba(20,32,68,0.6); border: 1px solid rgba(120,160,255,0.15); border-radius: 12px; padding: 12px; text-align: center;">
                    <div style="font-size: 0.7rem; font-weight: 800; color: #93C5FD; text-transform: uppercase; margin-bottom: 8px;">Front of ID</div>
                    <?php if ($front_exists): ?>
                        <?php $ext = strtolower(pathinfo($id_front_clean, PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($ext, ['jpg','jpeg','png','webp'])): ?>
                            <a href="/navora3/<?= htmlspecialchars($id_front_clean) ?>" target="_blank">
                                <img src="/navora3/<?= htmlspecialchars($id_front_clean) ?>" style="width: 100%; height: 140px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(120,160,255,0.3);">
                            </a>
                        <?php else: ?>
                            <a href="/navora3/<?= htmlspecialchars($id_front_clean) ?>" target="_blank" style="display: inline-flex; flex-direction: column; align-items: center; justify-content: center; height: 140px; width: 100%; background: rgba(239,68,68,0.1); border-radius: 8px; color: #FCA5A5; text-decoration: none;">
                                <i class="fas fa-file-pdf" style="font-size: 32px; margin-bottom: 8px;"></i> View PDF Document
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="height: 140px; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2); border-radius: 8px; color: rgba(255,255,255,0.4); font-size: 0.8rem;">Not Uploaded</div>
                    <?php endif; ?>
                </div>

                <!-- Back ID -->
                <div style="background: rgba(20,32,68,0.6); border: 1px solid rgba(120,160,255,0.15); border-radius: 12px; padding: 12px; text-align: center;">
                    <div style="font-size: 0.7rem; font-weight: 800; color: #93C5FD; text-transform: uppercase; margin-bottom: 8px;">Back of ID</div>
                    <?php if ($back_exists): ?>
                        <?php $ext = strtolower(pathinfo($id_back_clean, PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($ext, ['jpg','jpeg','png','webp'])): ?>
                            <a href="/navora3/<?= htmlspecialchars($id_back_clean) ?>" target="_blank">
                                <img src="/navora3/<?= htmlspecialchars($id_back_clean) ?>" style="width: 100%; height: 140px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(120,160,255,0.3);">
                            </a>
                        <?php else: ?>
                            <a href="/navora3/<?= htmlspecialchars($id_back_clean) ?>" target="_blank" style="display: inline-flex; flex-direction: column; align-items: center; justify-content: center; height: 140px; width: 100%; background: rgba(239,68,68,0.1); border-radius: 8px; color: #FCA5A5; text-decoration: none;">
                                <i class="fas fa-file-pdf" style="font-size: 32px; margin-bottom: 8px;"></i> View PDF Document
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="height: 140px; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2); border-radius: 8px; color: rgba(255,255,255,0.4); font-size: 0.8rem;">Not Uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ID Actions -->
            <?php if ($front_exists || $back_exists): ?>
            <div style="padding: 0 20px 20px; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid rgba(120,160,255,0.1); padding-top: 16px;">
                <?php if ($id_status !== 'verified'): ?>
                    <form method="POST" onsubmit="return confirm('Approve this ID document?');">
                        <input type="hidden" name="is_employer" value="<?= $is_employer ? 1 : 0 ?>">
                        <button type="submit" name="verify_id" value="1" class="btn-soft success"><i class="fas fa-check-circle"></i> Approve ID</button>
                    </form>
                <?php endif; ?>
                
                <?php if ($id_status !== 'rejected'): ?>
                    <form method="POST" onsubmit="return setReason(this, 'reason', 'Reason for rejecting this ID?');">
                        <input type="hidden" name="is_employer" value="<?= $is_employer ? 1 : 0 ?>">
                        <input type="hidden" name="reason" value="Invalid or unclear ID">
                        <button type="submit" name="reject_id" value="1" class="btn-soft danger"><i class="fas fa-times-circle"></i> Reject ID</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-panel">
            <div class="card-panel-head" style="display: flex; justify-content: space-between; align-items: center;">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon"><i class="fas fa-info-circle"></i></span> Account Details
                </div>
                <?php if ($user['status'] === 'pending' && !$is_complete): ?>
                    <span class="status-badge inactive" style="background: rgba(239, 68, 68, 0.15); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.35);">
                        <i class="fas fa-exclamation-triangle"></i> INCOMPLETE PROFILE
                    </span>
                <?php endif; ?>
            </div>
            <div class="detail-list">
                <div class="detail-row"><span class="detail-label">User Database ID</span><span class="detail-value">#<?php echo $user['id']; ?></span></div>
                <div class="detail-row"><span class="detail-label">Registered Email</span><span class="detail-value"><?php echo htmlspecialchars($user['email']); ?></span></div>
                <div class="detail-row">
                    <span class="detail-label">Phone Number</span>
                    <span class="detail-value" style="<?= empty($phone) ? 'color: #FCA5A5;' : 'color: #6EE7B7;' ?>">
                        <i class="fas fa-<?= empty($phone) ? 'times' : 'check' ?>-circle" style="font-size: 0.8rem; margin-right: 4px;"></i> <?php echo htmlspecialchars($phone ?: 'Not provided'); ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Location / Municipality</span>
                    <span class="detail-value" style="<?= empty($location) ? 'color: #FCA5A5;' : 'color: #6EE7B7;' ?>">
                        <i class="fas fa-<?= empty($location) ? 'times' : 'check' ?>-circle" style="font-size: 0.8rem; margin-right: 4px;"></i> <?php echo htmlspecialchars($location ?: 'Not provided'); ?>
                    </span>
                </div>
                <?php if (!$is_employer): ?>
                    <div class="detail-row"><span class="detail-label">Full Address</span><span class="detail-value"><?= htmlspecialchars($address ?: 'Not provided') ?></span></div>
                    <div class="detail-row"><span class="detail-label">Gender / Birthdate</span><span class="detail-value"><?= htmlspecialchars(($gender ?: 'N/A') . ' / ' . ($bdate ? date('M j, Y', strtotime($bdate)) : 'N/A')) ?></span></div>
                <?php endif; ?>
                <div class="detail-row"><span class="detail-label">Date Registered</span><span class="detail-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user['created_at'])); ?></span></div>
                
                <?php if (!$is_complete && $user['status'] === 'pending'): ?>
                    <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.20); padding: 14px; border-radius: 10px; margin-top: 14px;">
                        <div style="font-size: 0.75rem; font-weight: 800; color: #FCA5A5; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px;">Missing Requirements</div>
                        <div style="font-size: 0.85rem; color: #fff; font-weight: 600;"><?php echo implode(', ', $missing_fields); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($about)): ?>
                    <div class="detail-bio">
                        <div class="detail-label" style="margin-bottom: 6px;"><?= $is_employer ? 'Company Description' : 'About / Bio' ?></div>
                        <div style="font-size: 0.82rem; color: rgba(255,255,255,0.75); line-height: 1.5; background: rgba(20,32,68,0.5); padding: 12px; border-radius: 8px;">
                            <?php echo nl2br(htmlspecialchars($about)); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SEEKER EXTRA DATA -->
        <?php if (!$is_employer && count($skills) > 0): ?>
            <div class="card-panel">
                <div class="card-panel-head"><div class="card-panel-title"><span class="card-panel-title-icon" style="background: linear-gradient(135deg, #F59E0B, #FBBF24);"><i class="fas fa-star"></i></span>Skills</div></div>
                <div style="padding: 0 20px 20px; display: flex; flex-wrap: wrap; gap: 8px;">
                    <?php foreach ($skills as $skill): ?>
                        <span style="padding: 6px 14px; background: rgba(96, 165, 250, 0.12); color: #93C5FD; border: 1px solid rgba(96, 165, 250, 0.25); border-radius: 20px; font-size: 0.75rem; font-weight: 700;"><?= htmlspecialchars($skill) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$is_employer && count($work_exp) > 0): ?>
            <div class="card-panel">
                <div class="card-panel-head"><div class="card-panel-title"><span class="card-panel-title-icon" style="background: linear-gradient(135deg, #3B82F6, #60A5FA);"><i class="fas fa-briefcase"></i></span>Work Experience</div></div>
                <div style="padding: 0 20px 20px;">
                    <?php foreach ($work_exp as $w): ?>
                        <div style="padding: 12px 0; border-bottom: 1px solid rgba(120,160,255,0.08);">
                            <div style="font-size: 0.85rem; font-weight: 800; color: #fff;"><?= htmlspecialchars($w['job_title']) ?></div>
                            <div style="font-size: 0.75rem; color: #93C5FD; font-weight: 700; margin-bottom: 4px;"><?= htmlspecialchars($w['company_name']) ?></div>
                            <div style="font-size: 0.7rem; color: rgba(255,255,255,0.5); font-weight: 600;"><i class="far fa-calendar"></i> <?= !empty($w['start_date']) ? date('M Y', strtotime($w['start_date'])) : 'N/A' ?> — <?= !empty($w['end_date']) ? date('M Y', strtotime($w['end_date'])) : 'Present' ?></div>
                            <?php if (!empty($w['description'])): ?><div style="font-size: 0.75rem; color: rgba(255,255,255,0.7); margin-top: 6px; line-height: 1.5;"><?= nl2br(htmlspecialchars($w['description'])) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$is_employer && count($education) > 0): ?>
            <div class="card-panel">
                <div class="card-panel-head"><div class="card-panel-title"><span class="card-panel-title-icon" style="background: linear-gradient(135deg, #10B981, #34D399);"><i class="fas fa-graduation-cap"></i></span>Education</div></div>
                <div style="padding: 0 20px 20px;">
                    <?php foreach ($education as $e): ?>
                        <div style="padding: 12px 0; border-bottom: 1px solid rgba(120,160,255,0.08);">
                            <div style="font-size: 0.85rem; font-weight: 800; color: #fff;"><?= htmlspecialchars($e['degree'] ?? $e['level'] ?? 'Degree') ?></div>
                            <div style="font-size: 0.75rem; color: #93C5FD; font-weight: 700; margin-bottom: 4px;"><?= htmlspecialchars($e['school_name'] ?? 'School') ?></div>
                            <div style="font-size: 0.7rem; color: rgba(255,255,255,0.5); font-weight: 600;"><i class="far fa-calendar"></i> <?= htmlspecialchars($e['start_year'] ?? '') ?> — <?= htmlspecialchars($e['end_year'] ?? 'Present') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- MODERATION HISTORY -->
        <div class="card-panel">
            <div class="card-panel-head"><div class="card-panel-title"><span class="card-panel-title-icon" style="background: linear-gradient(135deg, #F59E0B, #FBBF24);"><i class="fas fa-gavel"></i></span>Moderation History</div></div>
            <?php if (count($history) > 0): ?>
                <div class="history-timeline">
                    <?php foreach ($history as $log): 
                        $icon = 'fa-info-circle'; $color = '#93C5FD';
                        if ($log['action'] === 'warning') { $icon = 'fa-exclamation-triangle'; $color = '#FCD34D'; }
                        if ($log['action'] === 'suspend') { $icon = 'fa-pause'; $color = '#F87171'; }
                        if ($log['action'] === 'reactivate') { $icon = 'fa-play'; $color = '#6EE7B7'; }
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-icon" style="color: <?php echo $color; ?>; background: <?php echo $color; ?>1a;"><i class="fas <?php echo $icon; ?>"></i></div>
                            <div class="timeline-content">
                                <div class="timeline-title"><strong><?php echo ucfirst($log['action']); ?></strong><span class="timeline-date"><?php echo date('M d, Y · g:i A', strtotime($log['created_at'])); ?></span></div>
                                <div class="timeline-desc"><?php echo nl2br(htmlspecialchars($log['description'])); ?></div>
                                <div class="timeline-admin"><i class="fas fa-user-shield"></i> Handled by: <?php echo htmlspecialchars($log['admin_email'] ?? 'System'); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="padding: 40px 20px;"><i class="fas fa-shield-alt" style="color: #6EE7B7; opacity: 0.5; font-size: 32px; margin-bottom: 12px; display: block;"></i><h4>Clean Record</h4><p>This user has no prior moderation history.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function setReason(form, fieldName, promptText) {
    const reason = prompt(promptText);
    if (reason === null) return false;
    const field = form.querySelector('input[name="' + fieldName + '"]');
    if (field) field.value = reason;
    return true;
}
</script>

<style>
.profile-layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 900px) { .profile-layout { grid-template-columns: 1fr; } }
.profile-card { padding: 0 !important; overflow: hidden; }
.profile-header { text-align: center; padding: 30px 20px 20px; background: linear-gradient(180deg, rgba(96, 165, 250, 0.1) 0%, transparent 100%); border-bottom: 1px solid rgba(120, 160, 255, 0.1); }
.profile-avatar { width: 80px; height: 80px; border-radius: 20px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.3); overflow: hidden; }
.profile-avatar.is-seeker { background: linear-gradient(135deg, #3B82F6, #60A5FA); }
.profile-avatar.is-employer { background: linear-gradient(135deg, #8B5CF6, #A78BFA); }
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-name { font-family: 'Libre Baskerville', serif; font-size: 1.2rem; font-weight: 700; color: #fff; margin-bottom: 6px; line-height: 1.2; }
.profile-email { font-size: 0.78rem; color: rgba(255,255,255,0.6); font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; justify-content: center; gap: 6px; }
.profile-email i { color: #93C5FD; }
.profile-badges { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; border: 1px solid; }
.status-badge.active { background: rgba(16,185,129,0.15); color: #6EE7B7; border-color: rgba(16,185,129,0.35); }
.status-badge.pending { background: rgba(245,158,11,0.15); color: #FCD34D; border-color: rgba(245,158,11,0.35); }
.status-badge.inactive { background: rgba(239,68,68,0.15); color: #FCA5A5; border-color: rgba(239,68,68,0.35); }
.status-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.role-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; border: 1px solid; }
.role-badge.seeker { background: rgba(96,165,250,0.15); color: #93C5FD; border-color: rgba(96,165,250,0.35); }
.role-badge.employer { background: rgba(139,92,246,0.15); color: #C4B5FD; border-color: rgba(139,92,246,0.35); }
.profile-stats { display: grid; grid-template-columns: repeat(3, 1fr); padding: 16px 10px; border-bottom: 1px solid rgba(120, 160, 255, 0.1); text-align: center; }
.p-stat-val { font-size: 1rem; font-weight: 800; color: #fff; font-family: 'Libre Baskerville', serif; margin-bottom: 2px; }
.p-stat-label { font-size: 0.6rem; color: rgba(255,255,255,0.5); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.profile-actions { padding: 20px; }
.detail-list { padding: 20px; display: flex; flex-direction: column; gap: 12px; }
.detail-row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid rgba(120,160,255,0.08); }
.detail-row:last-child { border-bottom: none; padding-bottom: 0; }
.detail-label { font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.55); text-transform: uppercase; letter-spacing: 0.5px; }
.detail-value { font-size: 0.85rem; font-weight: 600; color: #fff; text-align: right; }
.detail-bio { margin-top: 10px; }
.two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 768px) { .two-col-grid { grid-template-columns: 1fr; } }
.two-col-grid .card-panel { margin-bottom: 20px; }
.mini-list { padding: 15px 20px; display: flex; flex-direction: column; gap: 12px; }
.mini-item { display: flex; gap: 12px; align-items: flex-start; }
.mini-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(96,165,250,0.15); color: #93C5FD; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; flex-shrink: 0; }
.mini-content { flex: 1; min-width: 0; }
.mini-title { font-size: 0.8rem; font-weight: 800; color: #fff; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mini-desc { font-size: 0.72rem; color: rgba(255,255,255,0.65); font-weight: 500; line-height: 1.4; margin-bottom: 4px; }
.mini-date { font-size: 0.65rem; color: rgba(255,255,255,0.4); font-weight: 700; }
.empty-mini { text-align: center; padding: 30px 20px; font-size: 0.78rem; color: rgba(255,255,255,0.4); font-weight: 600; background: rgba(20,32,68,0.3); border-radius: 10px; border: 1px dashed rgba(120,160,255,0.15); }
.history-timeline { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.timeline-item { display: flex; gap: 14px; position: relative; }
.timeline-item::before { content: ''; position: absolute; left: 17px; top: 36px; bottom: -16px; width: 2px; background: rgba(120, 160, 255, 0.1); }
.timeline-item:last-child::before { display: none; }
.timeline-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.1); z-index: 2; }
.timeline-content { flex: 1; min-width: 0; background: rgba(20,32,68,0.4); padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(120,160,255,0.1); }
.timeline-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px; }
.timeline-title strong { font-size: 0.85rem; color: #fff; font-weight: 800; }
.timeline-date { font-size: 0.68rem; color: rgba(255,255,255,0.5); font-weight: 600; }
.timeline-desc { font-size: 0.78rem; color: rgba(255,255,255,0.75); line-height: 1.5; margin-bottom: 8px; }
.timeline-admin { font-size: 0.65rem; color: rgba(255,255,255,0.4); font-weight: 700; display: flex; align-items: center; gap: 5px; }
</style>

<?php include '_footer.php'; ?>