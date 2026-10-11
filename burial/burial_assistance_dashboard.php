<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login/login.php?role=admin");
    exit();
}


$year = isset($_GET['year']) ? $_GET['year'] : date("Y");
$year = ui_year($year);   // only a real year reaches the query and the page

/* ===========================
   GET APPLICANTS PER MONTH
=========================== */

$stmt = $pdo->prepare("
SELECT MONTH(created_at) as month, COUNT(*) as total
FROM applicant
WHERE YEAR(created_at) = ?
AND assistance_type_id = 2 AND status <> 'Disapproved'
GROUP BY MONTH(created_at)
");

$stmt->execute([$year]);

$monthlyData = array_fill(1,12,0);

while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
    $monthlyData[$row['month']] = $row['total'];
}

/* ===========================
   DASHBOARD STATISTICS
=========================== */

// Disapproved applications were discarded: they are not counted anywhere
$total = $pdo->query("SELECT COUNT(*) FROM applicant WHERE assistance_type_id = 2 AND status <> 'Disapproved'")->fetchColumn();

$approved = $pdo->query("SELECT COUNT(*) FROM applicant
WHERE assistance_type_id = 2 AND status='Approved'")->fetchColumn();

$pending = $pdo->query("SELECT COUNT(*) FROM applicant
WHERE assistance_type_id = 2 AND status='Pending'")->fetchColumn();

/* ===========================
   LATEST APPLICANTS
=========================== */

$stmt = $pdo->prepare("
    SELECT a.id, a.first_name, a.last_name, a.barangay, a.status, a.created_at, at.name as assistance_type
    FROM applicant a
    JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.assistance_type_id = 2 AND a.status <> 'Disapproved'
    ORDER BY a.created_at DESC
    LIMIT 10
");
$stmt->execute();
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_layout_start(['title' => 'Burial Assistance', 'section' => 'burial']);
echo ui_type_overview(2, compact('total', 'pending', 'approved'), $applicants, $monthlyData, $year);
admin_layout_end();
