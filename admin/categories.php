<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$page_title = 'Job Categories';

$errors = [];
$success = '';

/* ---------- Add category ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name'] ?? '');

    if (empty($category_name)) {
        $errors[] = 'Category name is required.';
    } elseif (mb_strlen($category_name) > 120) {
        $errors[] = 'Category name must be 120 characters or less.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->execute([$category_name]);
            $success = 'Category added successfully!';
        } catch (PDOException $e) {
            $errors[] = 'Category already exists.';
        }
    }
}

/* ---------- Update category ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $category_id   = intval($_POST['category_id']);
    $category_name = trim($_POST['category_name'] ?? '');

    if (empty($category_name)) {
        $errors[] = 'Category name is required.';
    } elseif (mb_strlen($category_name) > 120) {
        $errors[] = 'Category name must be 120 characters or less.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE categories SET category_name = ? WHERE id = ?");
            $stmt->execute([$category_name, $category_id]);
            $success = 'Category updated successfully!';
        } catch (PDOException $e) {
            $errors[] = 'Category name already in use.';
        }
    }
}

/* ---------- Delete category ---------- */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE category_id = ?");
        $stmt->execute([$id]);
        $in_use = (int) $stmt->fetchColumn();

        if ($in_use > 0) {
            $errors[] = "Cannot delete — {$in_use} job(s) are using this category.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: categories.php');
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Cannot delete category. It may be in use.';
    }
}

/* ---------- Fetch categories with job counts ---------- */
$categories = [];
try {
    $categories = $pdo->query("
        SELECT c.id, c.category_name,
               (SELECT COUNT(*) FROM jobs WHERE category_id = c.id) AS job_count
        FROM categories c
        ORDER BY c.category_name
    ")->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    $errors[] = 'Could not load categories: ' . $e->getMessage();
}

$total_categories = count($categories);
$used_categories  = 0;
$empty_categories = 0;
$total_jobs       = 0;

foreach ($categories as $c) {
    $total_jobs += (int) $c['job_count'];
    if ((int) $c['job_count'] > 0) $used_categories++;
    else $empty_categories++;
}

/* ---------- Header vars ---------- */
$page_subtitle  = 'Manage job categories used across the platform.';
$page_icon      = 'fa-tags';
$page_theme     = 'violet';
$header_script  = 'Organize<br>your jobs 🏷️';
$breadcrumb     = 'Admin / Categories';
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
            <div class="admin-stat-icon"><i class="fas fa-tags"></i></div>
            <div class="admin-stat-label">Total Categories</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_categories); ?></div>
        <div class="admin-stat-sub">All categories</div>
    </div>
    <div class="admin-stat green">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="admin-stat-label">In Use</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($used_categories); ?></div>
        <div class="admin-stat-sub">With active jobs</div>
    </div>
    <div class="admin-stat amber">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-inbox"></i></div>
            <div class="admin-stat-label">Unused</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($empty_categories); ?></div>
        <div class="admin-stat-sub">No jobs yet</div>
    </div>
    <div class="admin-stat violet">
        <div class="admin-stat-top">
            <div class="admin-stat-icon"><i class="fas fa-briefcase"></i></div>
            <div class="admin-stat-label">Total Jobs</div>
        </div>
        <div class="admin-stat-value"><?php echo number_format($total_jobs); ?></div>
        <div class="admin-stat-sub">Across all categories</div>
    </div>
</div>

<!-- ============ GRID: LIST + ADD ============ -->
<div class="grid-2 categories-grid">

    <div class="card-panel categories-list-panel">
        <div class="card-panel-head">
            <div class="card-panel-title">
                <span class="card-panel-title-icon"><i class="fas fa-tags"></i></span>
                All Categories
                <span class="count-chip"><?php echo count($categories); ?></span>
            </div>
            <div class="panel-sub">
                <?php echo $used_categories; ?> in use · <?php echo $empty_categories; ?> unused
            </div>
        </div>

        <?php if (count($categories) > 0): ?>
            <div class="categories-list">
                <?php foreach($categories as $cat):
                    $has_jobs = (int)$cat['job_count'] > 0;
                ?>
                    <div class="category-item">
                        <div class="category-icon <?php echo $has_jobs ? 'is-active' : ''; ?>">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div class="category-body">
                            <div class="category-name">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </div>
                            <div class="category-meta">
                                <span class="category-jobs">
                                    <i class="fas fa-briefcase"></i>
                                    <?php echo (int)$cat['job_count']; ?> job<?php echo (int)$cat['job_count'] === 1 ? '' : 's'; ?>
                                </span>
                                <span class="dot-sep">·</span>
                                <span class="category-id">#<?php echo $cat['id']; ?></span>
                            </div>
                        </div>
                        <div class="category-actions">
                            <button type="button"
                                    class="btn-icon-sm"
                                    title="Edit"
                                    onclick='editCategory(<?php echo $cat['id']; ?>, <?php echo json_encode($cat['category_name']); ?>)'>
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php if (!$has_jobs): ?>
                                <a href="?delete=<?php echo $cat['id']; ?>"
                                   class="btn-icon-sm danger"
                                   onclick="return confirm('Delete this category?')"
                                   title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                            <?php else: ?>
                                <button type="button"
                                        class="btn-icon-sm"
                                        disabled
                                        title="Cannot delete — in use"
                                        style="opacity:0.4;cursor:not-allowed;">
                                    <i class="fas fa-lock"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-tags"></i>
                <h4>No categories yet</h4>
                <p>Add your first job category to get started.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADD CATEGORY — sticky wrapper -->
    <div class="add-category-sticky-wrap">
        <div class="card-panel add-category-panel">
            <div class="card-panel-head">
                <div class="card-panel-title">
                    <span class="card-panel-title-icon"><i class="fas fa-plus" id="formIcon"></i></span>
                    <span id="formTitle">Add New Category</span>
                </div>
                <button type="button"
                        class="btn-soft"
                        id="cancelEditBtn"
                        onclick="resetForm()"
                        style="display:none;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>

            <form method="POST" class="add-category-form" id="categoryForm">
                <input type="hidden" name="category_id" id="categoryIdField" value="">
                <input type="hidden" name="add_category" id="addFlag" value="1">
                <input type="hidden" name="update_category" id="updateFlag" value="">

                <div class="form-group">
                    <label class="form-label-sm">
                        Category Name <span class="required">*</span>
                    </label>
                    <input type="text"
                           name="category_name"
                           id="categoryNameField"
                           class="form-input-sm"
                           placeholder="e.g. Information Technology"
                           maxlength="120"
                           required>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i>
                        Use clear, descriptive names. Max 120 characters.
                    </div>
                </div>

                <button type="submit" class="btn-soft primary btn-block" id="submitBtn">
                    <i class="fas fa-plus"></i> <span id="submitText">Add Category</span>
                </button>
            </form>

            <div class="add-category-tip">
                <div class="tip-icon"><i class="fas fa-lightbulb"></i></div>
                <div class="tip-text">
                    <strong>Tip:</strong> Categories help job seekers filter listings. Popular examples: IT, Healthcare, Education, Sales, Engineering, Hospitality.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ============================================================
   CATEGORIES PAGE — COMPACT + FLAT DARK GLASS
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
.categories-grid {
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 20px;
    align-items: start !important;
}
@media (max-width: 992px) {
    .categories-grid { grid-template-columns: 1fr; }
    .panel-sub { text-align: left; max-width: 100%; }
}
.categories-list-panel .card-panel-head {
    flex-wrap: wrap;
    align-items: flex-start;
    padding: 14px 20px !important;
}

/* ============================================================
   CATEGORIES LIST
   ============================================================ */
.categories-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 620px;
    overflow-y: auto;
    padding: 8px;
}
.categories-list::-webkit-scrollbar { width: 6px; }
.categories-list::-webkit-scrollbar-track { background: transparent; }
.categories-list::-webkit-scrollbar-thumb {
    background: rgba(120, 160, 255, 0.20);
    border-radius: 6px;
}
.categories-list::-webkit-scrollbar-thumb:hover {
    background: rgba(120, 160, 255, 0.35);
}

/* Category item */
.category-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px !important;
    border-radius: 10px;
    transition: background 0.15s;
    min-width: 0;
}
.category-item:hover { background: rgba(96, 165, 250, 0.06); }

.category-icon {
    width: 34px !important;
    height: 34px !important;
    border-radius: 10px !important;
    background: rgba(96, 165, 250, 0.15);
    color: #93C5FD;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82rem !important;
    flex-shrink: 0;
    transition: 0.2s;
}
.category-icon.is-active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA);
    color: #fff;
}

.category-body { flex: 1; min-width: 0; }
.category-name {
    font-weight: 700;
    font-size: 0.84rem !important;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px !important;
}
.category-meta {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.category-jobs {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.category-jobs i { color: #93C5FD; font-size: 0.66rem !important; }
.category-id {
    color: rgba(255, 255, 255, 0.5);
    font-weight: 700;
    letter-spacing: 0.3px;
}
.dot-sep { color: rgba(255, 255, 255, 0.3); font-weight: 900; }

.category-actions {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-shrink: 0;
}
.category-actions .btn-icon-sm {
    width: 28px !important;
    height: 28px !important;
    font-size: 0.68rem !important;
}

/* ============================================================
   ADD CATEGORY — sticky wrapper
   ============================================================ */
.add-category-sticky-wrap {
    position: sticky;
    top: 90px;
    align-self: start;
    z-index: 5;
}

.add-category-panel {
    position: relative;
    background: rgba(20, 32, 68, 0.65) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 16px !important;
    overflow: hidden;
    margin-bottom: 0 !important;
}

.add-category-panel:hover,
.add-category-panel:focus,
.add-category-panel:focus-within,
.add-category-panel:active {
    background: rgba(20, 32, 68, 0.65) !important;
    border-color: rgba(120, 160, 255, 0.15) !important;
    transform: none !important;
}

.add-category-panel .card-panel-head {
    padding: 14px 20px !important;
}

@media (min-height: 700px) {
    .add-category-sticky-wrap {
        max-height: calc(100vh - 110px);
    }
    .add-category-panel {
        max-height: 100%;
        overflow-y: auto;
    }
    .add-category-panel::-webkit-scrollbar { width: 6px; }
    .add-category-panel::-webkit-scrollbar-track { background: transparent; }
    .add-category-panel::-webkit-scrollbar-thumb {
        background: rgba(120, 160, 255, 0.20);
        border-radius: 6px;
    }
}

/* Form inside panel (matches .card-panel inner padding) */
.add-category-form {
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

/* Tip box */
.add-category-tip {
    display: flex; gap: 12px;
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
    .add-category-sticky-wrap {
        position: static !important;
        max-height: none !important;
    }
    .add-category-panel {
        max-height: none !important;
        overflow: hidden;
    }
}
@media (max-width: 480px) {
    .category-item { padding: 8px 10px !important; gap: 10px; }
    .category-icon { width: 30px !important; height: 30px !important; font-size: 0.72rem !important; }
    .category-name { font-size: 0.8rem !important; }
    .category-meta { font-size: 0.68rem !important; }
}
</style>

<script>
function editCategory(id, name) {
    document.getElementById('categoryIdField').value = id;
    document.getElementById('categoryNameField').value = name;
    document.getElementById('formTitle').textContent = 'Edit Category';
    document.getElementById('formIcon').className = 'fas fa-pen';
    document.getElementById('submitText').textContent = 'Update Category';
    document.getElementById('submitBtn').classList.remove('primary');
    document.getElementById('submitBtn').classList.add('success');
    document.getElementById('cancelEditBtn').style.display = 'inline-flex';

    document.getElementById('addFlag').value = '';
    document.getElementById('updateFlag').value = '1';

    document.getElementById('categoryNameField').focus();
    document.getElementById('categoryForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function resetForm() {
    document.getElementById('categoryIdField').value = '';
    document.getElementById('categoryNameField').value = '';
    document.getElementById('formTitle').textContent = 'Add New Category';
    document.getElementById('formIcon').className = 'fas fa-plus';
    document.getElementById('submitText').textContent = 'Add Category';
    document.getElementById('submitBtn').classList.add('primary');
    document.getElementById('submitBtn').classList.remove('success');
    document.getElementById('cancelEditBtn').style.display = 'none';

    document.getElementById('addFlag').value = '1';
    document.getElementById('updateFlag').value = '';
}
</script>

<?php include '_footer.php'; ?>