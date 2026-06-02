<?php
// api/admin/auth_admin.php
// Admin authentication helper
require_once __DIR__ . '/../auth/auth_helper.php';

function requireAdmin() {
    $userId = requireAuth();
    $db     = getDB();

    $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close();
    $db->close();

    if (!$user || !$user['is_admin']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. Admin only.']);
        exit;
    }

    return $userId;
}