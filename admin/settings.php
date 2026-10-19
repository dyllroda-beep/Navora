<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$page_title = 'System Settings';

/* ============================================================
   AUTO-CREATE settings TABLE (safe, IF NOT EXISTS)
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) { /* ignore */ }

/* ============================================================
   DEFAULT VALUES (used when a key is missing from the table)
   ============================================================ */
$defaults = [
    // Platform
    'site_name'              => 'NAVORA',
    'site_tagline'           => 'Connecting talent with opportunity in Lanao.',
    'contact_email'          => 'admin@navora.local',
    'support_email'          => 'support@navora.local',
    'default_timezone'       => 'Asia/Manila',

    // Registration
    'allow_seeker_signup'    => '1',
    'allow_employer_signup'  => '1',
    'require_email_verify'   => '0',
    'auto_approve_employers' => '0',

    // Job Rules
    'max_jobs_per_employer'  => '10',
    'auto_approve_jobs'      => '0',
    'max_job_title_length'   => '120',
    'max_job_desc_length'    => '5000',

    // Notifications
    'email_on_new_app'       => '1',
    'email_on_new_job'       => '0',
    'email_on_new_user'      => '0',
    'digest_frequency'       => 'daily',

    // Privacy / Terms
    'privacy_url'            => '/navora3/privacy.php',
    'terms_url'              => '/navora3/terms.php',
    'maintenance_mode'       => '0',
];

/* ============================================================
   LOAD CURRENT SETTINGS
   ============================================================ */
$current_settings = $defaults;
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) { /* keep defaults */ }

/* ============================================================
   HANDLE SAVE
   ============================================================ */
$success = '';
$errors  = [];

$tabs = [
    'platform'      => 'Platform',
    'registration'  => 'Registration',
    'jobs'          => 'Job Rules',
    'notifications' => 'Notifications',
    'privacy'       => 'Privacy & Terms',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $active_tab = $_POST['active_tab'] ?? 'platform';
    if (!isset($tabs[$active_tab])) $active_tab = 'platform';

    $tab_keys = [
        'platform'      => ['site_name', 'site_tagline', 'contact_email', 'support_email', 'default_timezone'],
        'registration'  => ['allow_seeker_signup', 'allow_employer_signup', 'require_email_verify', 'auto_approve_employers'],
        'jobs'          => ['max_jobs_per_employer', 'auto_approve_jobs', 'max_job_title_length', 'max_job_desc_length'],
        'notifications' => ['email_on_new_app', 'email_on_new_job', 'email_on_new_user', 'digest_frequency'],
        'privacy'       => ['privacy_url', 'terms_url', 'maintenance_mode'],
    ];

    $allowed_keys = $tab_keys[$active_tab] ?? [];

    if ($active_tab === 'platform' && trim($_POST['site_name'] ?? '') === '') {
        $errors[] = 'Site name is required.';
    }
    if ($active_tab === 'platform' && !filter_var(trim($_POST['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email must be a valid email address.';
    }
    if ($active_tab === 'jobs' && (int)($_POST['max_jobs_per_employer'] ?? 0) < 1) {
        $errors[] = 'Max jobs per employer must be at least 1.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            foreach ($allowed_keys as $key) {
                $value = isset($_POST[$key]) ? trim($_POST[$key]) : '0';

                if ($key === 'max_jobs_per_employer')       $value = (string) max(1, (int)$value);
                if ($key === 'max_job_title_length')        $value = (string) max(10, (int)$value);
                if ($key === 'max_job_desc_length')         $value = (string) max(100, (int)$value);

                $stmt->execute([$key, $value]);
                $current_settings[$key] = $value;
            }

            $success = 'Settings saved successfully!';
        } catch (PDOException $e) {
            $errors[] = 'Could not save settings: ' . $e->getMessage();
        }
    }
}

/* ============================================================
   ACTIVE TAB
   ============================================================ */
$active_tab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'platform');
if (!isset($tabs[$active_tab])) $active_tab = 'platform';

/* ============================================================
   HEADER VARS
   ============================================================ */
$page_subtitle  = 'Configure how NAVORA behaves for users and employers.';
$page_icon      = 'fa-cog';
$page_theme     = 'violet';
$header_script  = 'Fine-tune<br>your platform ⚙️';
$breadcrumb     = 'Admin / Settings';
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

<!-- ============ SETTINGS LAYOUT ============ -->
<div class="settings-layout">

    <!-- ============ TABS (sidebar) ============ -->
    <aside class="settings-tabs">
        <?php foreach ($tabs as $key => $label):
            $icons = [
                'platform'      => 'fa-globe',
                'registration'  => 'fa-user-plus',
                'jobs'          => 'fa-briefcase',
                'notifications' => 'fa-bell',
                'privacy'       => 'fa-shield-alt',
            ];
        ?>
            <a href="?tab=<?php echo $key; ?>"
               class="settings-tab <?php echo $active_tab === $key ? 'active' : ''; ?>">
                <i class="fas <?php echo $icons[$key]; ?>"></i>
                <span><?php echo htmlspecialchars($label); ?></span>
                <i class="fas fa-chevron-right tab-caret"></i>
            </a>
        <?php endforeach; ?>

        <div class="settings-tab-info">
            <div class="tab-info-icon"><i class="fas fa-lightbulb"></i></div>
            <div class="tab-info-text">
                <strong>Heads up:</strong> Changes take effect immediately for new users and posts.
            </div>
        </div>
    </aside>

    <!-- ============ PANEL ============ -->
    <main class="settings-panel">

        <form method="POST" class="settings-form">
            <input type="hidden" name="save_settings" value="1">
            <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($active_tab); ?>">

            <!-- ============ PLATFORM ============ -->
            <?php if ($active_tab === 'platform'): ?>
                <div class="settings-header">
                    <div class="settings-header-icon"><i class="fas fa-globe"></i></div>
                    <div>
                        <h2>Platform Info</h2>
                        <p>Basic information shown across the admin panel and public site.</p>
                    </div>
                </div>

                <div class="settings-grid">
                    <div class="form-group">
                        <label class="form-label-sm">Site Name <span class="required">*</span></label>
                        <input type="text" name="site_name" class="form-input-sm"
                               value="<?php echo htmlspecialchars($current_settings['site_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label-sm">Timezone</label>
                        <select name="default_timezone" class="form-select-sm-custom">
                            <?php
                            $timezones = ['Asia/Manila', 'Asia/Singapore', 'UTC', 'America/New_York', 'Europe/London'];
                            foreach ($timezones as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php echo $current_settings['default_timezone'] === $tz ? 'selected' : ''; ?>>
                                    <?php echo $tz; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Tagline</label>
                    <input type="text" name="site_tagline" class="form-input-sm"
                           value="<?php echo htmlspecialchars($current_settings['site_tagline']); ?>">
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i>
                        Short description shown on the homepage.
                    </div>
                </div>

                <div class="settings-grid">
                    <div class="form-group">
                        <label class="form-label-sm">Contact Email <span class="required">*</span></label>
                        <input type="email" name="contact_email" class="form-input-sm"
                               value="<?php echo htmlspecialchars($current_settings['contact_email']); ?>" required>
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Public-facing contact address.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label-sm">Support Email</label>
                        <input type="email" name="support_email" class="form-input-sm"
                               value="<?php echo htmlspecialchars($current_settings['support_email']); ?>">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Used for support ticket notifications.</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ REGISTRATION ============ -->
            <?php if ($active_tab === 'registration'): ?>
                <div class="settings-header">
                    <div class="settings-header-icon"><i class="fas fa-user-plus"></i></div>
                    <div>
                        <h2>Registration</h2>
                        <p>Control who can sign up and how accounts are approved.</p>
                    </div>
                </div>

                <div class="toggle-list">
                    <?php
                    $registration_toggles = [
                        ['allow_seeker_signup',   'Allow Job Seeker Signup',  'Job seekers can create accounts on the public site.'],
                        ['allow_employer_signup', 'Allow Employer Signup',    'Employers can register their companies.'],
                        ['require_email_verify',  'Require Email Verification', 'New users must verify their email before accessing the platform.'],
                        ['auto_approve_employers','Auto-Approve Employers',    'Skip the manual verification step for new employer accounts.'],
                    ];
                    foreach ($registration_toggles as $t):
                        [$key, $title, $desc] = $t;
                        $checked = $current_settings[$key] === '1';
                    ?>
                        <label class="toggle-row">
                            <div class="toggle-body">
                                <div class="toggle-title"><?php echo htmlspecialchars($title); ?></div>
                                <div class="toggle-desc"><?php echo htmlspecialchars($desc); ?></div>
                            </div>
                            <div class="toggle-switch">
                                <input type="hidden" name="<?php echo $key; ?>" value="0">
                                <input type="checkbox" name="<?php echo $key; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- ============ JOB RULES ============ -->
            <?php if ($active_tab === 'jobs'): ?>
                <div class="settings-header">
                    <div class="settings-header-icon"><i class="fas fa-briefcase"></i></div>
                    <div>
                        <h2>Job Posting Rules</h2>
                        <p>Limits and approval behavior for job listings.</p>
                    </div>
                </div>

                <div class="settings-grid">
                    <div class="form-group">
                        <label class="form-label-sm">Max Jobs per Employer</label>
                        <input type="number" name="max_jobs_per_employer" class="form-input-sm"
                               min="1" max="999"
                               value="<?php echo (int)$current_settings['max_jobs_per_employer']; ?>">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Active jobs a single employer can have at once.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label-sm">Max Job Title Length</label>
                        <input type="number" name="max_job_title_length" class="form-input-sm"
                               min="10" max="255"
                               value="<?php echo (int)$current_settings['max_job_title_length']; ?>">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Characters allowed in job titles.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Max Job Description Length</label>
                    <input type="number" name="max_job_desc_length" class="form-input-sm"
                           min="100" max="20000"
                           value="<?php echo (int)$current_settings['max_job_desc_length']; ?>">
                    <div class="form-hint"><i class="fas fa-info-circle"></i> Characters allowed in job descriptions.</div>
                </div>

                <div class="toggle-list">
                    <label class="toggle-row">
                        <div class="toggle-body">
                            <div class="toggle-title">Auto-Approve Jobs</div>
                            <div class="toggle-desc">Skip manual review — new job posts go live immediately.</div>
                        </div>
                        <div class="toggle-switch">
                            <input type="hidden" name="auto_approve_jobs" value="0">
                            <input type="checkbox" name="auto_approve_jobs" value="1" <?php echo $current_settings['auto_approve_jobs'] === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </div>
                    </label>
                </div>
            <?php endif; ?>

            <!-- ============ NOTIFICATIONS ============ -->
            <?php if ($active_tab === 'notifications'): ?>
                <div class="settings-header">
                    <div class="settings-header-icon"><i class="fas fa-bell"></i></div>
                    <div>
                        <h2>Notifications</h2>
                        <p>Which events send emails to administrators.</p>
                    </div>
                </div>

                <div class="toggle-list">
                    <?php
                    $notif_toggles = [
                        ['email_on_new_app',  'Email on New Application', 'Send an email whenever a new application is submitted.'],
                        ['email_on_new_job',  'Email on New Job Post',    'Notify when an employer posts a new job.'],
                        ['email_on_new_user', 'Email on New User',        'Notify when a new user registers.'],
                    ];
                    foreach ($notif_toggles as $t):
                        [$key, $title, $desc] = $t;
                        $checked = $current_settings[$key] === '1';
                    ?>
                        <label class="toggle-row">
                            <div class="toggle-body">
                                <div class="toggle-title"><?php echo htmlspecialchars($title); ?></div>
                                <div class="toggle-desc"><?php echo htmlspecialchars($desc); ?></div>
                            </div>
                            <div class="toggle-switch">
                                <input type="hidden" name="<?php echo $key; ?>" value="0">
                                <input type="checkbox" name="<?php echo $key; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Digest Frequency</label>
                    <select name="digest_frequency" class="form-select-sm-custom">
                        <?php
                        $freq = ['never' => 'Never', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
                        foreach ($freq as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $current_settings['digest_frequency'] === $val ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint"><i class="fas fa-info-circle"></i> How often to send a summary of platform activity.</div>
                </div>
            <?php endif; ?>

            <!-- ============ PRIVACY & TERMS ============ -->
            <?php if ($active_tab === 'privacy'): ?>
                <div class="settings-header">
                    <div class="settings-header-icon"><i class="fas fa-shield-alt"></i></div>
                    <div>
                        <h2>Privacy & Terms</h2>
                        <p>Links and legal content settings.</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Privacy Policy URL</label>
                    <input type="text" name="privacy_url" class="form-input-sm"
                           value="<?php echo htmlspecialchars($current_settings['privacy_url']); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Terms of Service URL</label>
                    <input type="text" name="terms_url" class="form-input-sm"
                           value="<?php echo htmlspecialchars($current_settings['terms_url']); ?>">
                </div>

                <div class="toggle-list">
                    <label class="toggle-row is-danger">
                        <div class="toggle-body">
                            <div class="toggle-title">Maintenance Mode</div>
                            <div class="toggle-desc">Only admins can access the public site. All visitors see a maintenance page.</div>
                        </div>
                        <div class="toggle-switch">
                            <input type="hidden" name="maintenance_mode" value="0">
                            <input type="checkbox" name="maintenance_mode" value="1" <?php echo $current_settings['maintenance_mode'] === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider is-danger"></span>
                        </div>
                    </label>
                </div>
            <?php endif; ?>

            <!-- ============ SAVE BAR ============ -->
            <div class="settings-save-bar">
                <div class="save-hint">
                    <i class="fas fa-info-circle"></i>
                    Save to apply changes to <strong><?php echo htmlspecialchars($tabs[$active_tab]); ?></strong> settings.
                </div>
                <button type="submit" class="btn-soft primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </main>
</div>

<style>
/* ============================================================
   SETTINGS PAGE — COMPACT + FLAT DARK GLASS
   ============================================================ */

/* ---------- Tabs sidebar ---------- */
.settings-layout {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
@media (max-width: 900px) {
    .settings-layout { grid-template-columns: 1fr; }
}

.settings-tabs {
    background: rgba(20, 32, 68, 0.35) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 16px;
    padding: 10px;
    box-shadow: none !important;
    position: sticky;
    top: 90px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
@media (max-width: 900px) {
    .settings-tabs {
        position: static;
        flex-direction: row;
        overflow-x: auto;
        padding: 8px;
        scrollbar-width: none;
    }
    .settings-tabs::-webkit-scrollbar { display: none; }
    .settings-tab { flex-shrink: 0; }
    .tab-caret { display: none; }
    .settings-tab-info { display: none; }
}

.settings-tab {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px !important;
    border-radius: 9px;
    color: rgba(255, 255, 255, 0.75) !important;
    background: transparent !important;
    font-size: 0.78rem !important;
    font-weight: 600;
    transition: 0.18s;
    border: none !important;
}
.settings-tab i:first-child {
    width: 16px;
    text-align: center;
    color: rgba(255, 255, 255, 0.55) !important;
    font-size: 0.82rem !important;
    transition: 0.18s;
}
.settings-tab:hover {
    background: rgba(96, 165, 250, 0.10) !important;
    color: #FFFFFF !important;
}
.settings-tab:hover i:first-child {
    color: #93C5FD !important;
}
.settings-tab.active {
    background: linear-gradient(135deg, #3B82F6, #60A5FA) !important;
    color: #FFFFFF !important;
    font-weight: 700;
    box-shadow: none !important;
}
.settings-tab.active i { color: #FFFFFF !important; }
.tab-caret {
    margin-left: auto;
    font-size: 0.6rem !important;
    opacity: 0.5;
}

/* Info box */
.settings-tab-info {
    margin-top: 10px;
    padding: 12px;
    background: rgba(96, 165, 250, 0.10) !important;
    border: 1px solid rgba(96, 165, 250, 0.20) !important;
    border-radius: 12px;
    display: flex;
    gap: 10px;
}
.tab-info-icon {
    width: 26px !important;
    height: 26px !important;
    border-radius: 8px !important;
    background: rgba(96, 165, 250, 0.15) !important;
    color: #93C5FD !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem !important;
    flex-shrink: 0;
}
.tab-info-icon i { color: #93C5FD !important; }
.tab-info-text {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.75) !important;
    line-height: 1.5;
    font-weight: 500;
}
.tab-info-text strong { color: #FFFFFF !important; font-weight: 800; }

/* ---------- Settings panel ---------- */
.settings-panel { min-width: 0; }

.settings-form {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.settings-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(120, 160, 255, 0.10) !important;
    margin-bottom: 4px;
}
.settings-header-icon {
    width: 46px !important;
    height: 46px !important;
    border-radius: 13px !important;
    background: linear-gradient(135deg, #3B82F6, #60A5FA) !important;
    color: #FFFFFF !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem !important;
    flex-shrink: 0;
    box-shadow: none !important;
}
.settings-header-icon i { color: #FFFFFF !important; }
.settings-header h2 {
    font-family: 'Libre Baskerville', Georgia, serif;
    font-size: 1.15rem !important;
    font-weight: 700;
    color: #FFFFFF !important;
    margin-bottom: 3px;
    letter-spacing: -0.3px;
}
.settings-header p {
    font-size: 0.8rem !important;
    color: rgba(255, 255, 255, 0.65) !important;
    font-weight: 500;
}

.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 640px) {
    .settings-grid { grid-template-columns: 1fr; }
}

.form-group { display: flex; flex-direction: column; }
.form-group .required {
    color: #FCA5A5 !important;
    font-weight: 800;
    margin-left: 2px;
}
.form-hint {
    font-size: 0.7rem !important;
    color: rgba(255, 255, 255, 0.55) !important;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}
.form-hint i { color: #93C5FD !important; font-size: 0.66rem !important; }

/* ---------- Toggle rows ---------- */
.toggle-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    background: rgba(20, 32, 68, 0.30) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 12px;
    padding: 6px;
}
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 11px 14px !important;
    border-radius: 9px;
    cursor: pointer;
    transition: background 0.15s;
    background: transparent !important;
}
.toggle-row:hover { background: rgba(96, 165, 250, 0.08) !important; }

.toggle-body { flex: 1; min-width: 0; }
.toggle-title {
    font-size: 0.84rem !important;
    font-weight: 700;
    color: #FFFFFF !important;
    margin-bottom: 2px;
}
.toggle-desc {
    font-size: 0.72rem !important;
    color: rgba(255, 255, 255, 0.65) !important;
    font-weight: 500;
    line-height: 1.4;
}

/* Toggle switch */
.toggle-switch {
    position: relative;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
}
.toggle-switch input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.toggle-slider {
    width: 40px !important;
    height: 24px !important;
    background: rgba(120, 160, 255, 0.20) !important;
    border-radius: 999px;
    position: relative;
    transition: 0.25s;
    cursor: pointer;
}
.toggle-slider::after {
    content: '';
    position: absolute;
    top: 3px; left: 3px;
    width: 18px !important; height: 18px !important;
    background: #FFFFFF;
    border-radius: 50%;
    transition: 0.25s;
}
.toggle-switch input:checked + .toggle-slider {
    background: #3B82F6 !important;
}
.toggle-switch input:checked + .toggle-slider::after {
    transform: translateX(16px);
}
.toggle-switch input:focus + .toggle-slider {
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.20);
}

/* Danger variant */
.toggle-row.is-danger .toggle-title { color: #FCA5A5 !important; }
.toggle-switch input:checked + .toggle-slider.is-danger {
    background: #EF4444 !important;
}

/* ---------- Save bar ---------- */
.settings-save-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 18px !important;
    background: rgba(20, 32, 68, 0.35) !important;
    border: 1px solid rgba(120, 160, 255, 0.15) !important;
    border-radius: 12px;
    margin-top: 6px;
    flex-wrap: wrap;
}
.save-hint {
    font-size: 0.75rem !important;
    color: rgba(255, 255, 255, 0.8) !important;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 7px;
}
.save-hint i { color: #93C5FD !important; font-size: 0.72rem; }
.save-hint strong { color: #FFFFFF !important; font-weight: 800; }

.settings-save-bar .btn-soft {
    padding: 9px 18px !important;
    font-size: 0.78rem !important;
}

/* ---------- Responsive ---------- */
@media (max-width: 640px) {
    .settings-header { gap: 12px; }
    .settings-header-icon { width: 40px !important; height: 40px !important; font-size: 0.9rem !important; }
    .settings-header h2 { font-size: 1rem !important; }
    .settings-header p { font-size: 0.74rem !important; }
    .settings-save-bar { flex-direction: column; align-items: stretch; }
    .settings-save-bar .btn-soft { width: 100%; justify-content: center; }
}

/* ---------- Force dark theme ---------- */
.settings-tabs,
.settings-tab-info,
.toggle-list,
.toggle-row,
.settings-save-bar {
    color: #FFFFFF !important;
}
.settings-tabs *,
.settings-tab-info *,
.toggle-list *,
.settings-save-bar * {
    text-shadow: none !important;
}
</style>

<?php include '_footer.php'; ?>