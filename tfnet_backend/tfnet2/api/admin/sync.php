<?php
// POST /admin/sync
// Syncs PENDING_xxx vouchers with real Omada vouchers
// Run this after Omada comes back online
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

requireAdmin();

$db     = getDB();
$omada  = new OmadaClient();
$synced = 0;
$failed = 0;
$errors = [];

// Find all vouchers with PENDING_ codes
$result = $db->query("
    SELECT v.id, v.user_id, v.plan, v.data_limit_mb,
           v.duration_mins, v.price, v.expires_at,
           o.plan_id, o.id AS order_id
    FROM vouchers v
    JOIN orders o ON o.voucher_id = v.id
    WHERE v.code LIKE 'PENDING_%'
    AND v.status = 'reserved'
");

$pending = [];
while ($row = $result->fetch_assoc()) {
    $pending[] = $row;
}

echo json_encode([
    'found' => count($pending),
    'syncing' => true,
]);

foreach ($pending as $v) {
    // Try to claim real voucher from Omada
    $realCode = null;
    try {
        $realCode = $omada->claimVoucher($v['plan_id']);
    } catch (\Exception $e) {
        $failed++;
        $errors[] = "Order #{$v['order_id']}: Omada unreachable";
        continue;
    }

    if (!$realCode) {
        $failed++;
        $errors[] = "Order #{$v['order_id']}: No vouchers available";
        continue;
    }

    // Update voucher with real code
    $stmt = $db->prepare("
        UPDATE vouchers SET code = ? WHERE id = ?
    ");
    $stmt->bind_param('si', $realCode, $v['id']);
    $stmt->execute();
    $stmt->close();

    // Build WhatsApp message for customer
    $userStmt = $db->prepare(
        "SELECT name, phone FROM users WHERE id = ?");
    $userStmt->bind_param('i', $v['user_id']);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    $customerPhone = preg_replace('/[^0-9]/', '',
        $user['phone'] ?? '');
    if (strlen($customerPhone) === 10 &&
        $customerPhone[0] === '0') {
        $customerPhone = '263' . substr($customerPhone, 1);
    }

    $msg = urlencode(
        "Hi {$user['name']}! 👋\n\n" .
        "Your TF Net voucher is ready! ✅\n\n" .
        "Your voucher code is:\n*$realCode*\n\n" .
        "📶 Connect to *Mapondera Wifi*\n" .
        "🔑 Enter the code in the portal\n" .
        "🌐 Enjoy your internet!\n\n" .
        "Thank you for choosing TF Net! 🙏"
    );

    $synced++;
}

$db->close();

respond([
    'success' => true,
    'synced'  => $synced,
    'failed'  => $failed,
    'errors'  => $errors,
    'message' => "$synced vouchers synced successfully.",
]);
?>