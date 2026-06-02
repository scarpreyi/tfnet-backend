<?php
// GET /admin/orders
// Returns all orders filtered by status
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

requireAdmin();

$status = $_GET['status'] ?? 'pending';
$db     = getDB();

$validStatuses = ['pending', 'confirmed', 'rejected', 'expired', 'all'];
if (!in_array($status, $validStatuses)) {
    $status = 'pending';
}

$whereClause = $status === 'all' ? '' : "WHERE o.status = '$status'";

$result = $db->query("
    SELECT
        o.id,
        o.plan_name,
        o.data_mb,
        o.duration_mins,
        o.amount,
        o.method,
        o.phone_used,
        o.status,
        o.approval_code,
        o.payer_name,
        o.created_at,
        o.confirmed_at,
        u.name       AS customer_name,
        u.phone      AS customer_phone,
        u.device_mac AS customer_mac,
        v.code       AS voucher_code
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN vouchers v ON o.voucher_id = v.id
    $whereClause
    ORDER BY o.created_at DESC
    LIMIT 100
");

$orders = [];
while ($row = $result->fetch_assoc()) {
    $dataMb  = $row['data_mb']      ?? 0;
    $dataStr = $dataMb > 0 ? round($dataMb / 1024) . ' GB' : 'Unlimited';
    $days    = round(($row['duration_mins'] ?? 0) / 60 / 24);

    $orders[] = [
        'id'            => $row['id'],
        'plan'          => $row['plan_name'],
        'data'          => $dataStr,
        'duration'      => $days . ' days',
        'amount'        => $row['amount'],
        'method'        => $row['method'],
        'phone_used'    => $row['phone_used'],
        'status'        => $row['status'],
        'approval_code' => $row['approval_code'],
        'payer_name'    => $row['payer_name'],
        'created_at'    => $row['created_at'],
        'confirmed_at'  => $row['confirmed_at'],
        'customer'      => [
            'name'  => $row['customer_name'],
            'phone' => $row['customer_phone'],
            'mac'   => $row['customer_mac'],
        ],
        'voucher_code'  => $row['voucher_code'],
    ];
}

$db->close();

respond([
    'success' => true,
    'orders'  => $orders,
    'total'   => count($orders),
    'status'  => $status,
]);
?><?php
// GET /admin/new-orders?since=TIMESTAMP
// Returns count of new pending orders since the given timestamp
// Used for polling-based notifications
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

requireAdmin();

$since = $_GET['since'] ?? date('Y-m-d H:i:s', time() - 30);

$db   = getDB();
$stmt = $db->prepare("
    SELECT id, plan_name, amount, method,
           u.name AS customer_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.status = 'pending'
    AND o.created_at > ?
    ORDER BY o.created_at DESC
");
$stmt->bind_param('s', $since);
$stmt->execute();
$result = $stmt->get_result();
$orders = [];
while ($row = $result->fetch_assoc()) {
    $orders[] = [
        'id'            => $row['id'],
        'plan'          => $row['plan_name'],
        'amount'        => $row['amount'],
        'method'        => $row['method'],
        'customer_name' => $row['customer_name'],
    ];
}
$stmt->close();
$db->close();

respond([
    'success'    => true,
    'new_orders' => $orders,
    'count'      => count($orders),
]);
?>