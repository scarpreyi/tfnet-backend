<?php
// GET /customer/new-confirmations?since=TIMESTAMP
// Returns newly confirmed orders for the logged-in customer
// Used for polling-based notifications
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$since  = $_GET['since'] ?? date('Y-m-d H:i:s', time() - 30);

$db   = getDB();
$stmt = $db->prepare("
    SELECT o.id, o.plan_name, o.amount, v.code AS voucher_code
    FROM orders o
    LEFT JOIN vouchers v ON o.voucher_id = v.id
    WHERE o.user_id = ?
    AND o.status = 'confirmed'
    AND o.confirmed_at > ?
    ORDER BY o.confirmed_at DESC
");
$stmt->bind_param('is', $userId, $since);
$stmt->execute();
$result        = $stmt->get_result();
$confirmations = [];
while ($row = $result->fetch_assoc()) {
    $confirmations[] = [
        'id'           => $row['id'],
        'plan'         => $row['plan_name'],
        'amount'       => $row['amount'],
        'voucher_code' => $row['voucher_code'],
    ];
}
$stmt->close();
$db->close();

respond([
    'success'       => true,
    'confirmations' => $confirmations,
    'count'         => count($confirmations),
]);
?>