<?php
// POST /admin/reject
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

requireAdmin();
$body    = getBody();
$orderId = intval($body['order_id'] ?? 0);

if (!$orderId) respondError('Order ID is required.');

$db   = getDB();
$stmt = $db->prepare("UPDATE orders SET status = 'rejected' WHERE id = ? AND status = 'pending'");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$stmt->close();
$db->close();

respond(['success' => true, 'message' => 'Order rejected.']);
?>