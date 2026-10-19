<?php
// admin/api/incomplete-profiles.php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$incomplete_count = 0;

if (isset($pdo)) {
    try {
        // Check Job Seekers missing first name, last name, or phone
        $stmt1 = $pdo->query("
            SELECT COUNT(*) FROM users u
            JOIN job_seekers s ON u.id = s.user_id
            WHERE u.status = 'active' 
            AND (s.first_name IS NULL OR s.first_name = '' 
                 OR s.last_name IS NULL OR s.last_name = '' 
                 OR s.phone IS NULL OR s.phone = '')
        ");
        $incomplete_count += (int) $stmt1->fetchColumn();

        // Check Employers missing company name, phone, or address
        $stmt2 = $pdo->query("
            SELECT COUNT(*) FROM users u
            JOIN employers e ON u.id = e.user_id
            WHERE u.status = 'active' 
            AND (e.company_name IS NULL OR e.company_name = '' 
                 OR e.phone IS NULL OR e.phone = '' 
                 OR e.address IS NULL OR e.address = '')
        ");
        $incomplete_count += (int) $stmt2->fetchColumn();

    } catch (PDOException $e) { 
        $incomplete_count = 0; 
    }
}

echo json_encode(['count' => $incomplete_count]);