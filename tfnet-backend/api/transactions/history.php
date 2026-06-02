<?php
// GET  /transactions/history        — returns confirmed, non-hidden orders
// POST /transactions/history        — soft deletes an order (hidden_by_user = 1)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

$userId = requireAuth();
$db     = getDB();

// ─── POST: soft delete ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = getBody();
    $orderId = intval($body['order_id'] ?? 0);
    if (!$orderId) respondError('Order ID is required.');

    $stmt = $db->prepare("
        UPDATE orders SET hidden_by_user = 1
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param('ii', $orderId, $userId);
    $stmt->execute();
    $stmt->close();
    $db->close();
    respond(['success' => true, 'message' => 'Order removed from history.']);
}

// ─── GET: fetch history ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$stmt = $db->prepare("
    SELECT
        o.id,
        o.plan_name    AS plan,
        o.data_mb,
        o.duration_mins,
        o.amount,
        o.method,
        o.phone_used,
        o.status,
        o.created_at,
        o.confirmed_at,
        v.code         AS voucher_code,
        v.status       AS voucher_status
    FROM orders o
    LEFT JOIN vouchers v ON o.voucher_id = v.id
    WHERE o.user_id = ?
    AND o.status = 'confirmed'
    AND (o.hidden_by_user IS NULL OR o.hidden_by_user = 0)
    ORDER BY o.created_at DESC
    LIMIT 50
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$rows   = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$db->close();

$transactions = array_map(function($row) {
    $dataMb  = $row['data_mb']      ?? 0;
    $dataStr = $dataMb > 0 ? round($dataMb / 1024) . ' GB' : 'Unlimited';
    $days    = round(($row['duration_mins'] ?? 0) / 60 / 24);
    return [
        'id'             => $row['id'],
        'voucher_code'   => $row['voucher_code'] ?? null,
        'plan'           => $row['plan'],
        'data'           => $dataStr,
        'duration'       => $days . ' day' . ($days !== 1 ? 's' : ''),
        'amount'         => $row['amount'],
        'method'         => $row['method'],
        'phone_used'     => $row['phone_used'],
        'status'         => $row['status'],
        'voucher_status' => $row['voucher_status'] ?? 'active',
        'created_at'     => $row['created_at'],
        'confirmed_at'   => $row['confirmed_at'],
    ];
}, $rows);

respond([
    'success'      => true,
    'transactions' => $transactions,
    'total'        => count($transactions),
]);
?>
