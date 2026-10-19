<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$page_title = 'Manage Skills';

$errors = [];
$success = '';

/* Auto-create skills table */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS skills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            skill_name VARCHAR(120) NOT NULL UNIQUE,
            category_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { }

/* Add skill */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_skill'])) {
    $name = trim($_POST['skill_name'] ?? '');
    if ($name === '') $errors[] = 'Skill name is required.';
    elseif (mb_strlen($name) > 120) $errors[] = 'Skill name must be 120 characters or less.';
    else {
        try {
            $stmt = $pdo->prepare("INSERT INTO skills (skill_name) VALUES (?)");
            $stmt->execute([$name]);
            $success = 'Skill added successfully!';
        } catch (PDOException $e) {
            $errors[] = 'Skill already exists.';
        }
    }
}

/* Delete */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM skills WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: skills.php'); exit;
    } catch (PDOException $e) { $errors[] = 'Cannot delete skill.'; }
}

/* Fetch */
$skills = [];
try { $skills = $pdo->query("SELECT * FROM skills ORDER BY skill_name")->fetchAll(); } catch (PDOException $e) {}

$total_skills = count($skills);

$page_subtitle  = 'Manage the skill tags used by job seekers and employers.';
$page_icon      = 'fa-lightbulb';
$page_theme     = 'violet';
$header_script  = 'Define skills 💡';
$breadcrumb     = 'Admin / Skills';
$page_banner_bg = 'https://i.pinimg.com/1200x/b6/43/f5/b643f5f2ab1f46cea14ee9bc6f193eff.jpg';

include '_header.php';
?>

<?php if ($success): ?>
    <div class="alert-soft success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert-soft danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="admin-stats">
    <div class="admin-stat blue">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-lightbulb"></i></div>
            <div class="admin-stat-label">Total Skills</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_skills); ?></div>
        <div class="admin-stat-sub">All skills</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">Active</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_skills); ?></div>
        <div class="admin-stat-sub">Available for use</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-tags"></i></div>
            <div class="admin-stat-label">Categories</div>
        </div>
        <div class="admin-stat-value">—</div>
        <div class="admin-stat-sub">Optional grouping</div>
    </div>
    <div class="admin-stat cyan">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-info-circle"></i></div>
            <div class="admin-stat-label">Tip</div>
        </div>
        <div class="admin-stat-value" style="font-size: 0.95rem !important;">Skills Help Matching</div>
        <div class="admin-stat-sub">Seekers filter by skills</div>
    </div>
</div>

<div class="grid-2 locations-grid">
    <div class="card-panel locations-list-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-lightbulb"></i></span>
                All Skills
                <span class="count-chip"><?php echo count($skills); ?></span>
            </div>
        </div>
        <?php if (count($skills) > 0): ?>
            <div class="locations-list">
                <?php foreach ($skills as $s): ?>
                    <div class="location-item">
                        <div class="location-icon" style="background: rgba(139, 92, 246, 0.15); color: #C4B5FD;">
                            <i class="fas fa-lightbulb"></i>
                        </div>
                        <div class="location-body">
                            <div class="location-name"><?php echo htmlspecialchars($s['skill_name']); ?></div>
                            <div class="location-meta">
                                <span class="location-id">#<?php echo $s['id']; ?></span>
                            </div>
                        </div>
                        <a href="?delete=<?php echo $s['id']; ?>" class="btn-icon-sm danger"
                           onclick="return confirm('Delete this skill?')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-lightbulb"></i>
                <h4>No skills yet</h4>
                <p>Add your first skill to get started.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="add-location-sticky-wrap">
        <div class="card-panel add-location-panel">
            <div class="card-panel-head">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon"><i class="fas fa-plus"></i></span>
                    Add New Skill
                </div>
            </div>
            <form method="POST" class="add-location-form">
                <div class="form-group">
                    <label class="form-label-sm">Skill Name <span class="required">*</span></label>
                    <input type="text" name="skill_name" class="form-input-sm"
                           placeholder="e.g. Customer Service" maxlength="120" required>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i>
                        Use short, clear skill tags.
                    </div>
                </div>
                <button type="submit" name="add_skill" class="btn-soft primary btn-block">
                    <i class="fas fa-plus"></i> Add Skill
                </button>
            </form>
            <div class="add-location-tip">
                <div class="tip-icon"><i class="fas fa-lightbulb"></i></div>
                <div class="tip-text">
                    <strong>Tip:</strong> Skills like "Customer Service", "Welding", "Excel", "Driving" help match seekers to jobs.
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '_footer.php'; ?>