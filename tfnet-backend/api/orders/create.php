<?php
// POST /orders/create
// Customer places a new order (pending payment confirmation)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$body   = getBody();

$planId  = trim($body['plan_id']        ?? '');
$method  = trim($body['payment_method'] ?? '');
$phone   = trim($body['phone_used']     ?? '');
$mac     = trim($body['device_mac']     ?? '');

if (!$planId || !$method) {
    respondError('Plan ID and payment method are required.');
}

$validMethods = ['ecocash', 'innbucks', 'usd_cash'];
if (!in_array($method, $validMethods)) {
    respondError('Invalid payment method.');
}

// Get plan details from Omada
$omada  = new OmadaClient();
$groups = $omada->getVoucherGroups();
$plan   = null;

foreach ($groups as $g) {
    if ($g['id'] === $planId) {
        $plan = $g;
        break;
    }
}

if (!$plan) {
    respondError('Plan not found.');
}

if (($plan['unusedCount'] ?? 0) <= 0) {
    respondError('This plan is sold out. Please choose another plan.');
}

$db       = getDB();
$planName = $plan['name']         ?? '';
$dataMb   = $plan['trafficLimit'] ?? 0;
$duration = $plan['duration']     ?? 0;
$amount   = $plan['unitPrice']    ?? '0';

// Create pending order
$stmt = $db->prepare("
    INSERT INTO orders
    (user_id, plan_id, plan_name, data_mb, duration_mins, amount, method, phone_used)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param('issiidss',
    $userId, $planId, $planName, $dataMb,
    $duration, $amount, $method, $phone);

if (!$stmt->execute()) {
    respondError('Failed to create order. Please try again.', 500);
}

$orderId = $db->insert_id;
$stmt->close();

// Update device MAC if provided
if ($mac) {
    $stmt2 = $db->prepare("UPDATE users SET device_mac = ? WHERE id = ?");
    $stmt2->bind_param('si', $mac, $userId);
    $stmt2->execute();
    $stmt2->close();
}

$db->close();

// Payment instructions
$methodLabels = [
    'ecocash'  => 'EcoCash',
    'innbucks' => 'InnBucks',
    'usd_cash' => 'USD Cash',
];

respond([
    'success'      => true,
    'order_id'     => $orderId,
    'message'      => 'Order placed successfully! Please complete payment.',
    'instructions' => [
        'step1' => 'Send $' . $amount . ' via ' . ($methodLabels[$method] ?? $method),
        'step2' => 'Send payment confirmation to the admin',
        'step3' => 'Your voucher code will be sent to you once confirmed',
        'step4' => 'Connect to ' . 'Mapondera Wifi' . ' and enter the code',
    ],
    'order' => [
        'id'       => $orderId,
        'plan'     => $planName,
        'amount'   => $amount,
        'method'   => $method,
        'status'   => 'pending',
    ],
]);
?>