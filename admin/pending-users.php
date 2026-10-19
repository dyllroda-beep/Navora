<?php
// admin/api/pending-users.php
// Returns { "count": N } for pending user registrations.
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$count = 0;
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role != 'admin'");
        $count = (int) $stmt->fetchColumn();
    } catch (PDOException $e) { $count = 0; }
}

echo json_encode(['count' => $count]);