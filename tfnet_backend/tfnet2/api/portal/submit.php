<?php
// POST /portal/submit
// Called by the app when customer connects to WiFi
// Submits voucher code to Omada captive portal automatically
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$body   = getBody();

$clientMac = trim($body['client_mac'] ?? '');
$apMac     = trim($body['ap_mac']     ?? '');
$ssidName  = trim($body['ssid_name']  ?? 'Mapondera Wifi');

if (!$clientMac) {
    respondError('Device MAC address is required.');
}

$db = getDB();

// Find the most recent reserved voucher for this user
$stmt = $db->prepare("
    SELECT id, code, plan, duration_mins, data_limit_mb, expires_at
    FROM vouchers
    WHERE user_id = ?
    AND status = 'reserved'
    AND expires_at > NOW()
    ORDER BY reserved_at DESC
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$voucher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$voucher) {
    respond([
        'success'  => false,
        'code'     => 'NO_VOUCHER',
        'message'  => 'No reserved voucher found. Please purchase a plan first.',
    ]);
}

// Submit to Omada captive portal
$portalUrl = 'https://127.0.0.1:8043/2163d67547fcf33c287f7c12b1850bdc/portal/auth';

$authData  = http_build_query([
    'clientMac'   => $clientMac,
    'apMac'       => $apMac,
    'ssidName'    => $ssidName,
    'voucherCode' => $voucher['code'],
]);

$ch = curl_init($portalUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $authData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
]);

$authResult = curl_exec($ch);
$authCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

if ($curlError) {
    respondError('Could not reach the portal. Please try again.', 500);
}

$authResponse = json_decode($authResult, true);
$errorCode    = $authResponse['errorCode'] ?? -1;

if ($authCode === 200 && $errorCode === 0) {
    // Success — update voucher status to active
    $db->query("
        UPDATE vouchers
        SET status = 'active'
        WHERE id = {$voucher['id']}
    ");

    // Update order status
    $db->query("
        UPDATE orders
        SET status = 'confirmed'
        WHERE voucher_id = {$voucher['id']}
    ");

    $db->close();

    respond([
        'success'      => true,
        'code'         => 'AUTHENTICATED',
        'message'      => 'Internet activated successfully!',
        'voucher_code' => $voucher['code'],
        'plan'         => $voucher['plan'],
        'expires_at'   => $voucher['expires_at'],
    ]);
} else {
    $db->close();

    // Map Omada error codes to friendly messages
    $errorMessages = [
        -41502 => 'Voucher code is incorrect.',
        -41503 => 'Voucher has expired.',
        -41504 => 'Data limit reached.',
        -41505 => 'Maximum users reached.',
        -41538 => 'Voucher not yet effective.',
    ];

    $message = $errorMessages[$errorCode]
      ?? 'Authentication failed. Please try again.';

    respond([
        'success'    => false,
        'code'       => 'AUTH_FAILED',
        'error_code' => $errorCode,
        'message'    => $message,
        'raw'        => $authResponse,
    ]);
}
?>