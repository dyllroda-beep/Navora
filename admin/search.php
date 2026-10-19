<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$page_title = 'Search Results';
$q = trim($_GET['q'] ?? '');

$users = $jobs = $apps = [];
if ($q !== '') {
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare("SELECT id, email, role, created_at FROM users
                           WHERE role != 'admin' AND email LIKE ?
                           ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$like]);
    $users = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT j.id, j.job_title, j.status, e.company_name
                           FROM jobs j JOIN employers e ON j.employer_id = e.id
                           WHERE j.job_title LIKE ? OR e.company_name LIKE ?
                           ORDER BY j.created_at DESC LIMIT 10");
    $stmt->execute([$like, $like]);
    $jobs = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT a.id, a.status, a.applied_at, j.job_title,
                                  s.first_name, s.last_name
                           FROM applications a
                           JOIN jobs j ON a.job_id = j.id
                           JOIN job_seekers s ON a.seeker_id = s.id
                           WHERE j.job_title LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?
                           ORDER BY a.applied_at DESC LIMIT 10");
    $stmt->execute([$like, $like, $like]);
    $apps = $stmt->fetchAll();
}

$page_subtitle = $q !== '' ? 'Showing results for "' . htmlspecialchars($q) . '"' : 'Enter a query to search.';
$page_icon     = 'fa-search';
$page_theme    = 'blue';
$header_script = 'Find<br>anything 🔍';
$breadcrumb    = 'Admin / Search';

include '_header.php';
?>

<?php
$total_results = count($users) + count($jobs) + count($apps);
?>

<?php if ($q === ''): ?>

    <!-- ============ EMPTY QUERY STATE ============ -->
    <div class="card-panel search-empty-panel">
        <div class="search-empty">
            <div class="search-empty-icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Start typing to search</h3>
            <p>Search across users, jobs, and applications from the top bar. Press <kbd>/</kbd> anywhere on the page to focus the search box instantly.</p>
            <div class="search-hints">
                <div class="search-hint-chip">
                    <i class="fas fa-users"></i> Search by user email
                </div>
                <div class="search-hint-chip">
                    <i class="fas fa-briefcase"></i> Search by job or company
                </div>
                <div class="search-hint-chip">
                    <i class="fas fa-file-alt"></i> Search by applicant name
                </div>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- ============ RESULT SUMMARY ============ -->
    <div class="card-panel search-summary-panel">
        <div class="search-summary">
            <div class="search-summary-left">
                <div class="search-summary-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div>
                    <div class="search-summary-title">
                        Results for <span class="search-query">"<?php echo htmlspecialchars($q); ?>"</span>
                    </div>
                    <div class="search-summary-meta">
                        <strong><?php echo $total_results; ?></strong> total result<?php echo $total_results === 1 ? '' : 's'; ?>
                        · <?php echo count($users); ?> users
                        · <?php echo count($jobs); ?> jobs
                        · <?php echo count($apps); ?> applications
                    </div>
                </div>
            </div>
            <a href="/navora3/admin/search.php" class="btn-soft">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>

    <!-- ============ USERS ============ -->
    <div class="card-panel search-result-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-users"></i></span>
                Users
                <span class="count-chip"><?php echo count($users); ?></span>
            </div>
            <?php if (count($users) > 0): ?>
                <a href="/navora3/admin/users.php?q=<?php echo urlencode($q); ?>" class="panel-link">
                    View all in Users <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($users): ?>
            <div class="search-result-list">
                <?php foreach ($users as $u):
                    $initial = strtoupper(substr($u['email'], 0, 1));
                    $roleBadge = $u['role'] === 'employer' ? 'violet' : 'info';
                ?>
                    <div class="search-result-row">
                        <div class="search-avatar <?php echo $u['role'] === 'employer' ? 'is-employer' : ''; ?>">
                            <?php echo htmlspecialchars($initial); ?>
                        </div>
                        <div class="search-result-body">
                            <div class="search-result-title"><?php echo htmlspecialchars($u['email']); ?></div>
                            <div class="search-result-meta">
                                <i class="far fa-calendar"></i>
                                Joined <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                            </div>
                        </div>
                        <span class="badge-soft <?php echo $roleBadge; ?>"><?php echo ucfirst($u['role']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="search-no-match">
                <i class="fas fa-user-slash"></i>
                <span>No matching users</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ JOBS ============ -->
    <div class="card-panel search-result-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-briefcase"></i></span>
                Jobs
                <span class="count-chip"><?php echo count($jobs); ?></span>
            </div>
            <?php if (count($jobs) > 0): ?>
                <a href="/navora3/admin/jobs.php" class="panel-link">
                    View all jobs <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($jobs): ?>
            <div class="search-result-list">
                <?php foreach ($jobs as $j):
                    $sBadge = 'warning';
                    if ($j['status'] === 'approved') $sBadge = 'success';
                    elseif ($j['status'] === 'rejected') $sBadge = 'danger';
                    elseif ($j['status'] === 'closed') $sBadge = 'secondary';
                ?>
                    <div class="search-result-row">
                        <div class="search-avatar is-job">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="search-result-body">
                            <div class="search-result-title"><?php echo htmlspecialchars($j['job_title']); ?></div>
                            <div class="search-result-meta">
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($j['company_name']); ?>
                            </div>
                        </div>
                        <span class="badge-soft <?php echo $sBadge; ?>"><?php echo ucfirst($j['status']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="search-no-match">
                <i class="fas fa-briefcase"></i>
                <span>No matching jobs</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ APPLICATIONS ============ -->
    <div class="card-panel search-result-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-file-alt"></i></span>
                Applications
                <span class="count-chip"><?php echo count($apps); ?></span>
            </div>
            <?php if (count($apps) > 0): ?>
                <a href="/navora3/admin/applications.php" class="panel-link">
                    View all applications <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($apps): ?>
            <div class="search-result-list">
                <?php foreach ($apps as $a):
                    $sBadge = 'warning';
                    if ($a['status'] === 'accepted') $sBadge = 'success';
                    elseif ($a['status'] === 'rejected') $sBadge = 'danger';
                    elseif ($a['status'] === 'shortlisted') $sBadge = 'violet';
                    elseif ($a['status'] === 'interview' || $a['status'] === 'reviewed') $sBadge = 'info';

                    $initials = strtoupper(substr($a['first_name'], 0, 1) . substr($a['last_name'], 0, 1));
                ?>
                    <div class="search-result-row">
                        <div class="search-avatar is-applicant">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <div class="search-result-body">
                            <div class="search-result-title">
                                <?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?>
                            </div>
                            <div class="search-result-meta">
                                <i class="fas fa-briefcase"></i>
                                <?php echo htmlspecialchars($a['job_title']); ?>
                                <span class="dot-sep">·</span>
                                <?php echo date('M d, Y', strtotime($a['applied_at'])); ?>
                            </div>
                        </div>
                        <span class="badge-soft <?php echo $sBadge; ?>"><?php echo ucfirst($a['status']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="search-no-match">
                <i class="fas fa-file-alt"></i>
                <span>No matching applications</span>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<!-- ============ SEARCH PAGE CSS ============ -->
<style>
/* ============================================================
   SEARCH PAGE — brand-aligned
   ============================================================ */

.count-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 26px; height: 22px;
    padding: 0 10px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 0.72rem;
    font-weight: 800;
    border-radius: 999px;
    margin-left: 6px;
}
.panel-link {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--accent);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: 0.2s;
}
.panel-link:hover { color: var(--caribbean-green); gap: 8px; }
.panel-link i { font-size: 0.7rem; }

/* ---------- Empty query state ---------- */
.search-empty-panel {
    padding: 0;
    border: none;
    background: transparent;
    box-shadow: none;
}
.search-empty {
    text-align: center;
    padding: 64px 32px;
    background: #fff;
    border: 1px dashed var(--border);
    border-radius: var(--radius-lg);
}
.search-empty-icon {
    width: 72px; height: 72px;
    margin: 0 auto 20px;
    border-radius: 22px;
    background: linear-gradient(135deg, var(--accent), var(--caribbean-green));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem;
    box-shadow: 0 12px 28px rgba(37, 99, 235, 0.25);
}
.search-empty h3 {
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--rich-black);
    margin-bottom: 8px;
    letter-spacing: -0.3px;
}
.search-empty p {
    font-size: 0.85rem;
    color: var(--text-secondary);
    font-weight: 500;
    max-width: 480px;
    margin: 0 auto 24px;
    line-height: 1.6;
}
.search-empty p kbd {
    display: inline-block;
    background: #f0f4fc;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 1px 8px;
    font-family: inherit;
    font-size: 0.75rem;
    font-weight: 800;
    color: var(--accent);
    margin: 0 2px;
    vertical-align: middle;
}
.search-hints {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}
.search-hint-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #f8faff;
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-secondary);
}
.search-hint-chip i {
    color: var(--accent);
    font-size: 0.72rem;
}

/* ---------- Result summary ---------- */
.search-summary-panel {
    background: linear-gradient(135deg, #f8faff 0%, #fbfcff 100%);
    border: 1px solid rgba(37, 99, 235, 0.15);
}
.search-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.search-summary-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
.search-summary-icon {
    width: 44px; height: 44px;
    border-radius: 13px;
    background: linear-gradient(135deg, var(--accent), var(--caribbean-green));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
}
.search-summary-title {
    font-size: 0.98rem;
    font-weight: 700;
    color: var(--rich-black);
    margin-bottom: 4px;
}
.search-query {
    color: var(--accent);
    font-weight: 800;
}
.search-summary-meta {
    font-size: 0.78rem;
    color: var(--text-secondary);
    font-weight: 600;
}
.search-summary-meta strong {
    color: var(--rich-black);
    font-weight: 800;
}

/* ---------- Result panels ---------- */
.search-result-panel { padding: 0; overflow: hidden; }
.search-result-panel .card-panel-head {
    padding: 18px 22px;
    margin-bottom: 0;
    border-bottom: 1px solid var(--border);
    background: #fff;
}

/* ---------- Result list ---------- */
.search-result-list {
    display: flex;
    flex-direction: column;
}
.search-result-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 22px;
    border-bottom: 1px solid var(--border-soft);
    transition: 0.15s;
}
.search-result-row:last-child { border-bottom: none; }
.search-result-row:hover {
    background: #f8faff;
}
.search-avatar {
    width: 40px; height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--accent), var(--caribbean-green));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 0.85rem;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(37, 99, 235, 0.20);
}
.search-avatar.is-employer {
    background: linear-gradient(135deg, #10B981, #34D399);
    box-shadow: 0 3px 8px rgba(16, 185, 129, 0.22);
}
.search-avatar.is-job {
    background: linear-gradient(135deg, #F59E0B, #FBBF24);
    box-shadow: 0 3px 8px rgba(245, 158, 11, 0.22);
    font-size: 0.9rem;
}
.search-avatar.is-applicant {
    background: linear-gradient(135deg, #7C3AED, #A78BFA);
    box-shadow: 0 3px 8px rgba(124, 58, 237, 0.22);
}

.search-result-body { flex: 1; min-width: 0; }
.search-result-title {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--rich-black);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px;
}
.search-result-meta {
    font-size: 0.75rem;
    color: var(--muted);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.search-result-meta i {
    color: var(--accent);
    font-size: 0.68rem;
}
.search-result-meta .dot-sep {
    color: #cbd5e1;
    font-weight: 900;
}

/* ---------- No-match empty ---------- */
.search-no-match {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 20px 22px;
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 600;
}
.search-no-match i {
    color: var(--accent);
    opacity: 0.4;
    font-size: 1rem;
}

/* ---------- Responsive ---------- */
@media (max-width: 640px) {
    .search-empty { padding: 44px 20px; }
    .search-empty h3 { font-size: 1.1rem; }
    .search-empty p { font-size: 0.8rem; }
    .search-empty-icon { width: 60px; height: 60px; font-size: 1.5rem; }
    .search-hints { flex-direction: column; align-items: stretch; }
    .search-hint-chip { justify-content: center; }

    .search-summary { flex-direction: column; align-items: stretch; }
    .search-summary-left { gap: 12px; }
    .search-summary-icon { width: 40px; height: 40px; font-size: 0.9rem; }
    .search-summary-title { font-size: 0.88rem; }
    .search-summary-meta { font-size: 0.72rem; }

    .search-result-row { padding: 12px 16px; gap: 12px; }
    .search-avatar { width: 36px; height: 36px; font-size: 0.78rem; }
    .search-result-title { font-size: 0.8rem; }
    .search-result-meta { font-size: 0.7rem; }
    .search-result-panel .card-panel-head { padding: 14px 16px; }
}
</style>

<?php include '_footer.php'; ?>