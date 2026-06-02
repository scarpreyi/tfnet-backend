<?php
// POST /admin/confirm
// Confirms payment, reserves voucher, auto-auths if on WiFi
// Has fallback mode if Omada is unreachable
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$adminId      = requireAdmin();
$body         = getBody();
$orderId      = intval($body['order_id']      ?? 0);
$approvalCode = trim($body['approval_code']   ?? '');
$payerName    = trim($body['payer_name']      ?? '');

if (!$orderId) {
    respondError('Order ID is required.');
}

$db = getDB();

// Get the order
$stmt = $db->prepare("
    SELECT o.*, u.device_mac, u.phone AS customer_phone, u.name AS customer_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ? AND o.status = 'pending'
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    respondError('Order not found or already processed.');
}

// â”€â”€â”€ Try to get voucher from Omada â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$code        = null;
$omadaOnline = false;

try {
    $omada       = new OmadaClient();
    $code        = $omada->claimVoucher($order['plan_id']);
    $omadaOnline = true;
} catch (\Exception $e) {
    $omadaOnline = false;
}

// â”€â”€â”€ Fallback: if Omada offline generate a placeholder â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// The voucher will be claimed from Omada when admin syncs later
$pendingSync = false;
if (!$code) {
    // Generate a temporary reference code
    $code        = 'PENDING_' . strtoupper(substr(md5($orderId . time()), 0, 6));
    $pendingSync = true;
}

// â”€â”€â”€ Calculate expiry â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$durationMins = $order['duration_mins'] ?? 0;
$durationDays = $durationMins / 60 / 24;
$reserveHours = $durationDays <= 7 ? 48 : 168;
$expiresAt    = date('Y-m-d H:i:s', time() + ($reserveHours * 3600));

// â”€â”€â”€ Save voucher to database â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$voucherId = null;

try {
    // Save voucher to database
    // Reuse an existing voucher row if the code already exists in our DB (inventory model).
    // If the code exists but isn't available, fall back to a PENDING_ placeholder.

    $stmtFind = $db->prepare("SELECT id, status FROM vouchers WHERE code = ? LIMIT 1");
    $stmtFind->bind_param('s', $code);
    $stmtFind->execute();
    $existingVoucher = $stmtFind->get_result()->fetch_assoc();
    $stmtFind->close();

    if ($existingVoucher) {
        $voucherId     = (int) ($existingVoucher['id'] ?? 0);
        $voucherStatus = $existingVoucher['status'] ?? 'unused';

        if (!$pendingSync && $voucherStatus !== 'unused') {
            $pendingSync = true;
            $code        = 'PENDING_' . strtoupper(substr(md5($orderId . time() . rand()), 0, 6));
            $voucherId   = null;
        } else {
            $stmtUpdateV = $db->prepare("
                UPDATE vouchers SET
                    user_id       = ?,
                    plan          = ?,
                    duration_mins = ?,
                    data_limit_mb = ?,
                    price         = ?,
                    status        = 'reserved',
                    reserved_at   = NOW(),
                    expires_at    = ?
                WHERE id = ?
            ");
            $stmtUpdateV->bind_param('isiidsi',
                $order['user_id'],
                $order['plan_name'],
                $order['duration_mins'],
                $order['data_mb'],
                $order['amount'],
                $expiresAt,
                $voucherId
            );
            $stmtUpdateV->execute();
            $stmtUpdateV->close();
        }
    }

    if (!$voucherId) {
        // Ensure placeholder code is unique
        if ($pendingSync) {
            $tries = 0;
            while ($tries < 10) {
                $stmtCheck = $db->prepare("SELECT id FROM vouchers WHERE code = ? LIMIT 1");
                $stmtCheck->bind_param('s', $code);
                $stmtCheck->execute();
                $exists = $stmtCheck->get_result()->fetch_assoc();
                $stmtCheck->close();

                if (!$exists) break;
                $code = 'PENDING_' . strtoupper(substr(md5($orderId . time() . rand()), 0, 6));
                $tries++;
            }
        }

        $stmt2 = $db->prepare("
            INSERT INTO vouchers
            (user_id, code, plan, duration_mins, data_limit_mb, price, status, reserved_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, 'reserved', NOW(), ?)
        ");
        $stmt2->bind_param('issiids',
            $order['user_id'],
            $code,
            $order['plan_name'],
            $order['duration_mins'],
            $order['data_mb'],
            $order['amount'],
            $expiresAt
        );
        $stmt2->execute();
        $voucherId = $db->insert_id;
        $stmt2->close();
    }

    // Update order
    $stmt3 = $db->prepare("
        UPDATE orders SET
            status        = 'confirmed',
            voucher_id    = ?,
            approval_code = ?,
            payer_name    = ?,
            confirmed_at  = NOW()
        WHERE id = ?
    ");
    $stmt3->bind_param('issi', $voucherId, $approvalCode, $payerName, $orderId);
    $stmt3->execute();
    $stmt3->close();

    // Save transaction
    $stmt4 = $db->prepare("
        INSERT INTO transactions
        (user_id, voucher_id, amount, method, phone_used, approval_code, payer_name, status, confirmed_at, confirmed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'success', NOW(), ?)
    ");
    $stmt4->bind_param('iidssssi',
        $order['user_id'],
        $voucherId,
        $order['amount'],
        $order['method'],
        $order['phone_used'],
        $approvalCode,
        $payerName,
        $adminId
    );
    $stmt4->execute();
    $stmt4->close();

} catch (mysqli_sql_exception $e) {
    error_log('Admin confirm DB error: ' . $e->getMessage());
    $db->close();
    respondError('Server database error. Please try again.', 500);
}
// â”€â”€â”€ Try auto-auth if Omada is online â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$autoAuthed  = false;
$customerMac = $order['device_mac'] ?? null;

if ($omadaOnline && $customerMac && !$pendingSync) {
    try {
        $activeClients = $omada->getActiveClients();
        $isOnWifi      = false;

        foreach ($activeClients as $client) {
            if (strtolower($client['mac']) === strtolower($customerMac)) {
                $isOnWifi = true;
                break;
            }
        }

        if ($isOnWifi) {
            $portalUrl = 'https://127.0.0.1:8043/'
              . '2163d67547fcf33c287f7c12b1850bdc'
              . '/portal/auth';

            $authData = http_build_query([
                'clientMac'   => $customerMac,
                'apMac'       => '',
                'ssidName'    => 'Mapondera Wifi',
                'voucherCode' => $code,
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

            $authResult   = curl_exec($ch);
            $authCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $authResponse = json_decode($authResult, true);
            if ($authCode === 200 &&
                ($authResponse['errorCode'] ?? -1) === 0) {
                $autoAuthed = true;
                $db->query("
                    UPDATE vouchers SET status = 'active'
                    WHERE id = $voucherId
                ");
            }
        }
    } catch (\Exception $e) {
        // Auto-auth failed silently
    }
}

$db->close();

// â”€â”€â”€ Build WhatsApp message â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$customerPhone = preg_replace('/[^0-9]/', '',
    $order['customer_phone'] ?? '');
if (strlen($customerPhone) === 10 && $customerPhone[0] === '0') {
    $customerPhone = '263' . substr($customerPhone, 1);
}

$voucherLine = $pendingSync
    ? "Your internet access has been approved and will be activated shortly."
    : "Your voucher code is:\n*$code*\n\nðŸ“¶ Connect to *Mapondera Wifi*\nðŸ”‘ Enter the code in the portal\nðŸŒ Enjoy your internet!";

$whatsappMsg = urlencode(
    "Hi {$order['customer_name']}! ðŸ‘‹\n\n" .
    "Your TF Net payment has been confirmed. âœ…\n\n" .
    $voucherLine . "\n\n" .
    "Plan: {$order['plan_name']}\n" .
    "Valid for: " . round($order['duration_mins'] / 60 / 24) . " days\n\n" .
    "Thank you for choosing TF Net! ðŸ™"
);

$whatsappUrl = "https://wa.me/$customerPhone?text=$whatsappMsg";

// â”€â”€â”€ Build response â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$message = 'Payment confirmed!';
if ($pendingSync) {
    $message = 'Payment confirmed! Omada was offline â€” voucher will be assigned when connection is restored.';
} elseif ($autoAuthed) {
    $message = 'Payment confirmed! Customer internet activated automatically.';
} else {
    $message = 'Payment confirmed! Send voucher code via WhatsApp.';
}

respond([
    'success'      => true,
    'auto_authed'  => $autoAuthed,
    'pending_sync' => $pendingSync,
    'omada_online' => $omadaOnline,
    'voucher_code' => $pendingSync ? null : $code,
    'expires_at'   => $expiresAt,
    'message'      => $message,
    'whatsapp_url' => $whatsappUrl,
    'customer'     => [
        'name'  => $order['customer_name'],
        'phone' => $order['customer_phone'],
        'mac'   => $customerMac,
    ],
]);
?>