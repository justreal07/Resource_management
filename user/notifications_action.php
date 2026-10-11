<?php
// AJAX endpoint: mark all notifications as read
require_once '../config.php';
require_once 'account_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['applicant']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

if (($_POST['action'] ?? '') === 'mark_all_read') {
    mark_notifications_read($pdo, (int)$_SESSION['applicant']['id']);
    echo json_encode(['ok' => true, 'unread' => 0]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false]);
