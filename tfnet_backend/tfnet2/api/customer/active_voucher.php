<?php
// GET /customer/active-voucher
// Returns the most recent active voucher for the logged-in user
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$db     = getDB();

$stmt = $db->prepare("
    SELECT v.code, v.status, v.plan, v.data_limit_mb
    FROM vouchers v
    JOIN orders o ON o.voucher_id = v.id
    WHERE o.user_id = ?
    AND o.status = 'confirmed'
    AND v.status IN ('active', 'reserved')
    ORDER BY o.confirmed_at DESC
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$row) {
    respond(['success' => false, 'code' => null, 'message' => 'No active voucher found.']);
    exit;
}

respond([
    'success' => true,
    'code'    => $row['code'],
    'plan'    => $row['plan'],
    'status'  => $row['status'],
]);
?>