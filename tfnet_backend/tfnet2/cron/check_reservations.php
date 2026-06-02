<?php
// cron/check_reservations.php
// Run every hour via Windows Task Scheduler
// Checks reserved vouchers and expires them if not activated

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../omada/OmadaClient.php';

$db      = getDB();
$omada   = new OmadaClient();
$expired = 0;
$active  = 0;

echo "[" . date('Y-m-d H:i:s') . "] Starting reservation check...\n";

// Find all reserved vouchers that have passed their expiry time
$result = $db->query("
    SELECT
        v.id,
        v.code,
        v.user_id,
        v.expires_at,
        u.phone AS customer_phone,
        u.name  AS customer_name
    FROM vouchers v
    JOIN users u ON v.user_id = u.id
    WHERE v.status = 'reserved'
    AND v.expires_at < NOW()
");

$expiredVouchers = [];
while ($row = $result->fetch_assoc()) {
    $expiredVouchers[] = $row;
}

echo "Found " . count($expiredVouchers) . " expired reservations to check.\n";

foreach ($expiredVouchers as $voucher) {
    echo "Checking voucher: {$voucher['code']}...\n";

    // Ask Omada if this voucher has been used
    $voucherStatus = $omada->getVoucherByCode($voucher['code']);
    $isUsed        = ($voucherStatus['used'] ?? 0) == 1;

    if ($isUsed) {
        // Customer activated it — mark as active
        $db->query("
            UPDATE vouchers
            SET status = 'active'
            WHERE id = {$voucher['id']}
        ");
        echo "  → Activated (customer used it)\n";
        $active++;
    } else {
        // Not used — expire the reservation
        $db->query("
            UPDATE vouchers
            SET status = 'expired_reservation'
            WHERE id = {$voucher['id']}
        ");

        // Also update the order status
        $db->query("
            UPDATE orders
            SET status = 'expired'
            WHERE voucher_id = {$voucher['id']}
        ");

        echo "  → Expired (not activated within window)\n";
        $expired++;

        // Build WhatsApp expiry notification
        $customerPhone = preg_replace('/[^0-9]/', '', $voucher['customer_phone']);
        if (strlen($customerPhone) === 10 && $customerPhone[0] === '0') {
            $customerPhone = '263' . substr($customerPhone, 1);
        }

        $msg = urlencode(
            "Hi {$voucher['customer_name']}! ⚠️\n\n" .
            "Your TF Net voucher *{$voucher['code']}* has expired " .
            "because it was not activated within the required time.\n\n" .
            "Please place a new order to get internet access.\n\n" .
            "Thank you for choosing TF Net! 🙏"
        );

        // Log the WhatsApp URL — admin can send manually
        echo "  → WhatsApp: https://wa.me/$customerPhone?text=$msg\n";
    }
}

$db->close();

echo "\n[" . date('Y-m-d H:i:s') . "] Done.\n";
echo "  Activated : $active\n";
echo "  Expired   : $expired\n";
?>