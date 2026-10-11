<?php
require_once '../config.php';
require_once '../includes/admin_layout.php';

// Get applicant ID from URL
$applicant_id = isset($_GET['applicant_id']) ? (int)$_GET['applicant_id'] : 0;

if ($applicant_id <= 0) {
    header("Location: applicants.php");
    exit();
}

// Fetch applicant details
$stmt = $pdo->prepare("
    SELECT a.*, at.name as assistance_type
    FROM applicant a
    LEFT JOIN assistance_types at ON a.assistance_type_id = at.id
    WHERE a.id = ?
");
$stmt->execute([$applicant_id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    header("Location: applicants.php");
    exit();
}

// Fetch documents for this applicant
$docStmt = $pdo->prepare("
    SELECT * FROM applicant_documents
    WHERE applicant_id = ?
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$applicant_id]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

[$backHref, $backLabel] = ui_back_link('dashboard.php', 'Back to the dashboard');
admin_layout_start(['title' => 'Applicant record', 'section' => 'dashboard']);
echo ui_applicant_record($applicant, $documents, $backHref, $backLabel);
admin_layout_end();
