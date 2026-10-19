<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Verify Employers';

$my_id = $_SESSION['user_id'] ?? 0;

/* ---------- Ensure employer rows exist ---------- */
try {
    $stmt = $pdo->query("SELECT id, email, role FROM users WHERE role = 'employer'");
    $employer_users = $stmt->fetchAll();
    foreach ($employer_users as $user) {
        $check = $pdo->prepare("SELECT id FROM employers WHERE user_id = ?");
        $check->execute([$user['id']]);
        if (!$check->fetch()) {
            $company_name = explode('@', $user['email'])[0];
            $company_name = preg_replace('/[^a-zA-Z0-9 _-]+/', ' ', $company_name);
            $company_name = trim($company_name) ?: 'Employer';

            $insert = $pdo->prepare("INSERT INTO employers (user_id, company_name, email, verification_status) VALUES (?, ?, ?, 'pending')");
            $insert->execute([$user['id'], $company_name, $user['email']]);
        }
    }
} catch (PDOException $e) { }

/* ---------- Handle actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_employer'])) {
        $user_id = intval($_POST['user_id']);
        $stmt = $pdo->prepare("UPDATE employers SET verification_status = 'approved' WHERE user_id = ?");
        $stmt->execute([$user_id]);
        logAdminAction($pdo, $my_id, 'employer.approved', 'user', $user_id);
        header('Location: employers.php');
        exit;
    }
}

/* ---------- Fetch employers ---------- */
$stmt = $pdo->prepare("
    SELECT u.id AS user_id,
           u.email,
           u.status,
           u.created_at,
           e.id AS employer_id,
           e.company_name,
           e.verification_status,
           e.logo,
           (SELECT COUNT(*) FROM jobs WHERE employer_id = e.id) AS job_count
    FROM users u
    LEFT JOIN employers e ON e.user_id = u.id
    WHERE u.role = 'employer'
    ORDER BY
        CASE WHEN e.verification_status = 'pending' THEN 0
             WHEN e.verification_status = 'approved' THEN 1
             ELSE 2 END,
        u.created_at DESC
");
$stmt->execute();
$employers = $stmt->fetchAll();

$total_employers = count($employers);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
foreach ($employers as $emp) {
    $status = strtolower($emp['verification_status'] ?? 'pending');
    if ($status === 'approved') $approved_count++;
    elseif ($status === 'rejected') $rejected_count++;
    else $pending_count++;
}

/* ---------- Header vars ---------- */
$page_subtitle  = 'Review and verify employer accounts.';
$page_icon      = 'fa-building';
$page_theme     = 'violet';
$header_script  = 'Verify employers ✨';
$breadcrumb     = 'Admin / Employers';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<!-- ============ STATS ============ -->
<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-building"></i></div>
            <div class="admin-stat-label">Total Employers</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_employers); ?></div>
        <div class="admin-stat-sub">Registered companies</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-label">Pending</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($pending_count); ?></div>
        <div class="admin-stat-sub">Awaiting approval</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Approved</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($approved_count); ?></div>
        <div class="admin-stat-sub">Verified employers</div>
    </div>
    <div class="admin-stat red">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="admin-stat-label">Rejected</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($rejected_count); ?></div>
        <div class="admin-stat-sub">Rejected accounts</div>
    </div>
</div>

<!-- ============ EMPLOYERS ============ -->
<div class="table-panel">
    <div class="table-panel-head">
        <div class="table-panel-title">
            <span class="table-panel-title-icon"><i class="fas fa-building"></i></span>
            Employer Verification List
            <span class="count-chip"><?php echo count($employers); ?></span>
        </div>
        <div class="table-panel-sub">
            <?php echo $pending_count; ?> awaiting verification
        </div>
    </div>

    <?php if (count($employers) > 0): ?>

        <!-- ============ DESKTOP TABLE ============ -->
        <div class="employers-table-wrap">
            <table class="employers-table">
                <thead>
                    <tr>
                        <th style="width:32%;">Company</th>
                        <th style="width:22%;">Email</th>
                        <th style="width:14%;">Joined</th>
                        <th style="width:12%;">Jobs</th>
                        <th style="width:12%;">Verification</th>
                        <th style="width:8%; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employers as $emp):
                        $verification = strtolower($emp['verification_status'] ?? 'pending');
                        $verificationClass = 'pending';
                        if ($verification === 'approved') $verificationClass = 'active';
                        elseif ($verification === 'rejected') $verificationClass = 'inactive';

                        $company_name = $emp['company_name'] ?: 'Employer';
                        $initial = strtoupper(substr($company_name, 0, 1));
                        $job_count = (int)($emp['job_count'] ?? 0);
                    ?>
                        <tr>
                            <td>
                                <div class="employer-cell">
                                    <div class="employer-avatar <?php echo $verification === 'approved' ? 'is-verified' : ($verification === 'rejected' ? 'is-rejected' : 'is-pending'); ?>">
                                        <?php if (!empty($emp['logo'])): ?>
                                            <img src="/navora3/<?php echo htmlspecialchars($emp['logo']); ?>" alt="">
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($initial); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="employer-info">
                                        <div class="employer-name">
                                            <?php echo htmlspecialchars($company_name); ?>
                                            <?php if ($verification === 'approved'): ?>
                                                <i class="fas fa-check-circle verified-icon" title="Verified"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="employer-meta">ID #<?php echo (int)$emp['user_id']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="email-cell">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo htmlspecialchars($emp['email'] ?? ''); ?>
                                </span>
                            </td>
                            <td>
                                <span class="date-cell">
                                    <i class="far fa-calendar"></i>
                                    <?php echo htmlspecialchars(date('M d, Y', strtotime($emp['created_at']))); ?>
                                </span>
                            </td>
                            <td>
                                <span class="job-count-chip">
                                    <i class="fas fa-briefcase"></i>
                                    <?php echo $job_count; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $verificationClass; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($verification); ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <div class="employer-actions">
                                    <?php if ($verification !== 'approved'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$emp['user_id']; ?>">
                                            <button type="submit" name="approve_employer"
                                                    class="btn-icon-sm success"
                                                    title="Approve"
                                                    onclick="return confirm('Approve this employer?');">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <!-- REMOVED REJECT BUTTON -->
                                    
                                    <?php if ($verification === 'approved'): ?>
                                        <span class="verified-note">
                                            <i class="fas fa-shield-alt"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ============ MOBILE CARDS ============ -->
        <div class="employers-cards">
            <?php foreach ($employers as $emp):
                $verification = strtolower($emp['verification_status'] ?? 'pending');
                $verificationClass = 'pending';
                if ($verification === 'approved') $verificationClass = 'active';
                elseif ($verification === 'rejected') $verificationClass = 'inactive';

                $company_name = $emp['company_name'] ?: 'Employer';
                $initial = strtoupper(substr($company_name, 0, 1));
                $job_count = (int)($emp['job_count'] ?? 0);
            ?>
                <div class="employer-card">
                    <div class="employer-card-head">
                        <div class="employer-avatar <?php echo $verification === 'approved' ? 'is-verified' : ($verification === 'rejected' ? 'is-rejected' : 'is-pending'); ?>">
                            <?php if (!empty($emp['logo'])): ?>
                                <img src="/navora3/<?php echo htmlspecialchars($emp['logo']); ?>" alt="">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($initial); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="employer-info">
                            <div class="employer-name">
                                <?php echo htmlspecialchars($company_name); ?>
                                <?php if ($verification === 'approved'): ?>
                                    <i class="fas fa-check-circle verified-icon"></i>
                                <?php endif; ?>
                            </div>
                            <div class="employer-meta">ID #<?php echo (int)$emp['user_id']; ?></div>
                        </div>
                        <span class="status-badge <?php echo $verificationClass; ?>">
                            <span class="status-dot"></span>
                            <?php echo ucfirst($verification); ?>
                        </span>
                    </div>

                    <div class="employer-card-meta">
                        <div class="meta-row">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($emp['email'] ?? ''); ?>
                        </div>
                        <div class="meta-row">
                            <i class="far fa-calendar"></i>
                            Joined <?php echo date('M d, Y', strtotime($emp['created_at'])); ?>
                        </div>
                        <div class="meta-row">
                            <i class="fas fa-briefcase"></i>
                            <?php echo $job_count; ?> job<?php echo $job_count === 1 ? '' : 's'; ?> posted
                        </div>
                    </div>

                    <?php if ($verification === 'approved'): ?>
                        <div class="verified-lock">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Verified</strong>
                                <span>Status is final</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="employer-card-actions">
                            <?php if ($verification !== 'approved'): ?>
                                <form method="POST">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$emp['user_id']; ?>">
                                    <button type="submit" name="approve_employer"
                                            class="btn-soft success"
                                            onclick="return confirm('Approve this employer?');"
                                            style="flex:1;justify-content:center;">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- REMOVED REJECT BUTTON -->
                            
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h4>No employers found</h4>
            <p>Employer accounts will appear here for verification.</p>
        </div>
    <?php endif; ?>
</div>

<style>
/* ============================================================
   EMPLOYERS PAGE — COMPACT + FLAT DARK GLASS
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

/* ---------- Panel header ---------- */
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
.table-panel-sub {
    font-size: 0.72rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.55);
}
.table-panel .table-panel-head {
    padding: 14px 20px !important;
}

/* ---------- Desktop table ---------- */
.employers-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.employers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    min-width: 860px;
}
.employers-table thead th {
    text-align: left;
    padding: 11px 18px !important;
    font-size: 0.63rem;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    background: rgba(120, 160, 255, 0.05);
    border-bottom: 1px solid rgba(120, 160, 255, 0.12);
    white-space: nowrap;
}
.employers-table tbody td {
    padding: 10px 18px !important;
    border-bottom: 1px solid rgba(120, 160, 255, 0.06);
    vertical-align: middle;
    color: #fff;
}
.employers-table tbody tr { transition: background 0.15s; }
.employers-table tbody tr:last-child td { border-bottom: none; }
.employers-table tbody tr:hover {
    background: rgba(96, 165, 250, 0.05);
}

/* ---------- Employer cell ---------- */
.employer-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.employer-avatar {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 0.78rem !important;
    flex-shrink: 0;
    overflow: hidden;
    transition: transform 0.2s;
}
.employer-avatar.is-verified {
    background: linear-gradient(135deg, #10B981, #34D399);
}
.employer-avatar.is-rejected {
    background: linear-gradient(135deg, #EF4444, #F87171);
}
.employer-avatar.is-pending {
    background: linear-gradient(135deg, #F59E0B, #FBBF24);
}
.employer-avatar img { width: 100%; height: 100%; object-fit: cover; }
.employers-table tbody tr:hover .employer-avatar { transform: scale(1.05); }

.employer-info { min-width: 0; }
.employer-name {
    font-weight: 800;
    font-size: 0.84rem !important;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 2px !important;
    max-width: 260px;
}
.verified-icon {
    color: #6EE7B7;
    font-size: 0.72rem;
    flex-shrink: 0;
}
.employer-meta {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 600;
}

/* ---------- Compact cells ---------- */
.email-cell,
.date-cell {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem !important;
    color: #fff;
    font-weight: 600;
    white-space: nowrap;
}
.email-cell i,
.date-cell i { color: #93C5FD; font-size: 0.68rem !important; }

.job-count-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px !important;
    background: rgba(96, 165, 250, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.20);
    border-radius: 999px;
    font-size: 0.7rem !important;
    font-weight: 800;
    color: #fff;
}
.job-count-chip i { color: #93C5FD; font-size: 0.62rem !important; }

/* ---------- Compact status badge ---------- */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 4px 9px !important;
    border-radius: 999px;
    font-size: 0.64rem !important;
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

/* ---------- Action buttons ---------- */
.employer-actions {
    display: flex;
    align-items: center;
    gap: 5px;
    justify-content: flex-end;
}
.employer-actions .btn-icon-sm {
    width: 28px !important;
    height: 28px !important;
    font-size: 0.68rem !important;
}
.verified-note {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 9px;
    background: rgba(16, 185, 129, 0.15);
    color: #6EE7B7;
    font-size: 0.7rem;
    border: 1px solid rgba(16, 185, 129, 0.28);
}

/* ---------- Mobile cards ---------- */
.employers-cards { display: none; }

@media (max-width: 720px) {
    .employers-table-wrap { display: none; }
    .employers-cards {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 14px;
    }
    .employer-card {
        background: rgba(20, 32, 68, 0.55);
        border: 1px solid rgba(120, 160, 255, 0.15);
        border-radius: 12px;
        padding: 14px;
        transition: border-color 0.2s;
    }
    .employer-card:hover {
        border-color: rgba(96, 165, 250, 0.30);
    }
    .employer-card-head {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(120, 160, 255, 0.10);
    }
    .employer-card-head .employer-info { flex: 1; }
    .employer-card-head .status-badge { flex-shrink: 0; }

    .employer-card-meta {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 12px;
    }
    .meta-row {
        font-size: 0.74rem;
        color: rgba(255, 255, 255, 0.7);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 7px;
        overflow-wrap: anywhere;
    }
    .meta-row i {
        color: #93C5FD;
        font-size: 0.72rem;
        width: 14px;
        text-align: center;
        flex-shrink: 0;
    }

    .employer-card-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .employer-card-actions form { display: flex; flex: 1; min-width: 100px; }

    .verified-lock {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        background: rgba(16, 185, 129, 0.10);
        border: 1.5px solid rgba(16, 185, 129, 0.28);
        border-radius: 10px;
        color: #6EE7B7;
    }
    .verified-lock i {
        width: 28px; height: 28px;
        border-radius: 8px;
        background: linear-gradient(135deg, #10B981, #34D399);
        display: flex; align-items: center; justify-content: center;
        font-size: 0.72rem;
        color: #fff;
        flex-shrink: 0;
    }
    .verified-lock strong {
        display: block;
        font-size: 0.78rem;
        font-weight: 800;
        margin-bottom: 1px;
        color: #6EE7B7;
    }
    .verified-lock span {
        font-size: 0.66rem;
        font-weight: 600;
        opacity: 0.8;
        color: rgba(110, 231, 183, 0.75);
    }
}

/* ---------- Small phones ---------- */
@media (max-width: 420px) {
    .employers-cards { padding: 12px; }
    .employer-card { padding: 12px; }
    .employer-avatar { width: 32px !important; height: 32px !important; font-size: 0.72rem !important; }
    .employer-name { font-size: 0.8rem !important; max-width: 180px; }
    .meta-row { font-size: 0.7rem; }
    .employer-card-actions form { min-width: 100%; }
    .employer-card-actions .btn-soft {
        padding: 7px 12px;
        font-size: 0.72rem;
    }
}
</style>

<?php include '_footer.php'; ?>