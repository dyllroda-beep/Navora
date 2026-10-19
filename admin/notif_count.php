<?php
// admin/api/notif-count.php
// Returns JSON with:
//   { "count": N, "unread_notifs": N, "pending_users": N, "pending_employers": N, "pending_jobs": N, "open_reports": N }
//
// The "count" field is the TOTAL the bell badge should display
// (unread system notifications + pending users awaiting approval).
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$unread_notifs     = 0;
$pending_users     = 0;
$pending_employers = 0;
$pending_jobs      = 0;
$open_reports      = 0;

if (isset($pdo, $_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];

    // 1. Unread system notifications for this admin
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$uid]);
        $unread_notifs = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $unread_notifs = 0; }

    // 2. Pending user registrations (the thing that should alert the admin)
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role != 'admin'");
        $pending_users = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $pending_users = 0; }

    // 3. Pending employer verifications
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM employers WHERE verification_status = 'pending'");
        $pending_employers = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $pending_employers = 0; }

    // 4. Pending job posts
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'");
        $pending_jobs = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $pending_jobs = 0; }

    // 5. Open reports
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'open'");
        $open_reports = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $open_reports = 0; }
}

// Total badge = unread notifications + pending users
$total = $unread_notifs + $pending_users;

echo json_encode([
    'count'             => $total,
    'unread_notifs'     => $unread_notifs,
    'pending_users'     => $pending_users,
    'pending_employers' => $pending_employers,
    'pending_jobs'      => $pending_jobs,
    'open_reports'      => $open_reports,
]);