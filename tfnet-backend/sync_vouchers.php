<?php
// sync_vouchers.php
// Run every minute via Windows Task Scheduler
// Pulls ALL vouchers from Omada and upserts into the vouchers table.
// Also resolves any PENDING_ placeholder codes created when Omada was offline.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/omada/OmadaClient.php';

$log = function (string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

// ── 1. Connect to Omada ───────────────────────────────────────────────────────
$omada = new OmadaClient();

// Fetch all voucher groups first
$groups = $omada->getVoucherGroups();
if (empty($groups)) {
    $log('No voucher groups found on Omada. Exiting.');
    exit;
}

$log('Found ' . count($groups) . ' voucher group(s).');

// ── 2. Fetch every voucher from every group ───────────────────────────────────
$allOmadaVouchers = [];

foreach ($groups as $group) {
    $groupId   = $group['id']   ?? null;
    $groupName = $group['name'] ?? 'Unknown';

    if (!$groupId) continue;

    // Fetch up to 1000 vouchers per group (adjust pageSize if needed)
    $omada->ensureWebSessionPublic(); // see note below
    $url = $omada->buildGroupVouchersUrl($groupId, 1, 1000);
    $res = $omada->fetchGroupVouchers($groupId, 1000);

    foreach ($res as $v) {
        $allOmadaVouchers[] = [
            'group_name'    => $groupName,
            'group_id'      => $groupId,
            'omada_voucher' => $v,
        ];
    }
}

$log('Total vouchers fetched from Omada: ' . count($allOmadaVouchers));

if (empty($allOmadaVouchers)) {
    $log('Nothing to sync.');
    exit;
}

// ── 3. Upsert into DB ─────────────────────────────────────────────────────────
$db      = getDB();
$synced  = 0;
$created = 0;

// Status map: Omada int → our enum
$statusMap = [
    0 => 'unused',
    1 => 'active',
    2 => 'expired',
];

foreach ($allOmadaVouchers as $entry) {
    $v         = $entry['omada_voucher'];
    $groupName = $entry['group_name'];

    $code = trim($v['code'] ?? '');
    if (!$code) continue;

    // Omada status int
    $omadaStatus = (int)($v['status'] ?? 0);
    $statusStr   = $statusMap[$omadaStatus] ?? 'unused';

    // Traffic limits — Omada stores in KB
    $trafficLimitKb = (int)($v['trafficLimit'] ?? 0);
    $dataLimitMb    = $trafficLimitKb > 0 ? intval($trafficLimitKb / 1024) : 0;

    // Duration — Omada stores in minutes
    $durationMins = (int)($v['duration'] ?? 0);

    // Price
    $price = (float)($v['unitPrice'] ?? 0);

    // Expiry — Omada gives endTime in milliseconds
    $endTimeMs  = (int)($v['endTime'] ?? 0);
    $expiresAt  = null;
    if ($endTimeMs > 0) {
        $endTimeSec = intval($endTimeMs / 1000);
        if ($endTimeSec < 9999999999) {
            $expiresAt = date('Y-m-d H:i:s', $endTimeSec);
        }
    }

    // Start time
    $startTimeMs = (int)($v['startTime'] ?? 0);
    $reservedAt  = $startTimeMs > 0
        ? date('Y-m-d H:i:s', intval($startTimeMs / 1000))
        : null;

    // Plan name — use group name as fallback
    $planName = $v['name'] ?? $groupName;

    // Check if this code already exists in DB
    $stmtCheck = $db->prepare("SELECT id, user_id FROM vouchers WHERE code = ? LIMIT 1");
    $stmtCheck->bind_param('s', $code);
    $stmtCheck->execute();
    $existing = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if ($existing) {
        // UPDATE — preserve user_id if already assigned
        $stmtUp = $db->prepare("
            UPDATE vouchers SET
                plan          = ?,
                duration_mins = ?,
                data_limit_mb = ?,
                price         = ?,
                status        = ?,
                reserved_at   = COALESCE(?, reserved_at),
                expires_at    = COALESCE(?, expires_at)
            WHERE code = ?
        ");
        $stmtUp->bind_param(
            'siidssss',
            $planName,
            $durationMins,
            $dataLimitMb,
            $price,
            $statusStr,
            $reservedAt,
            $expiresAt,
            $code
        );
        $stmtUp->execute();
        $stmtUp->close();
        $synced++;
    } else {
        // INSERT — new voucher not yet in DB
        $stmtIns = $db->prepare("
            INSERT INTO vouchers
                (code, plan, duration_mins, data_limit_mb, price, status, reserved_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->bind_param(
            'ssiidss s',
            $code,
            $planName,
            $durationMins,
            $dataLimitMb,
            $price,
            $statusStr,
            $reservedAt,
            $expiresAt
        );
        $stmtIns->execute();
        $stmtIns->close();
        $created++;
    }
}

$log("Synced (updated): $synced | Created (new): $created");

// ── 4. Resolve PENDING_ placeholders ─────────────────────────────────────────
// These were created when Omada was offline during admin confirm.
// Now that Omada is back, find unassigned vouchers and link them to pending orders.

$pendingOrders = $db->query("
    SELECT o.id AS order_id, o.user_id, o.plan_id, o.plan_name,
           o.duration_mins, o.data_mb, o.amount, v.id AS voucher_id
    FROM orders o
    JOIN vouchers v ON v.id = o.voucher_id
    WHERE o.status = 'confirmed'
      AND v.code LIKE 'PENDING_%'
    ORDER BY o.id ASC
");

$resolved = 0;

while ($order = $pendingOrders->fetch_assoc()) {
    // Find an unused DB voucher that matches the plan and has no user yet
    $stmtFind = $db->prepare("
        SELECT id, code FROM vouchers
        WHERE status   = 'unused'
          AND user_id  IS NULL
          AND plan     = ?
          AND code NOT LIKE 'PENDING_%'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtFind->bind_param('s', $order['plan_name']);
    $stmtFind->execute();
    $realVoucher = $stmtFind->get_result()->fetch_assoc();
    $stmtFind->close();

    if (!$realVoucher) continue; // No matching voucher available yet

    $newVoucherId = $realVoucher['id'];
    $newCode      = $realVoucher['code'];

    // Assign voucher to user
    $stmtAssign = $db->prepare("
        UPDATE vouchers SET
            user_id     = ?,
            status      = 'reserved',
            reserved_at = NOW()
        WHERE id = ?
    ");
    $stmtAssign->bind_param('ii', $order['user_id'], $newVoucherId);
    $stmtAssign->execute();
    $stmtAssign->close();

    // Update order to point to real voucher
    $stmtOrder = $db->prepare("
        UPDATE orders SET voucher_id = ? WHERE id = ?
    ");
    $stmtOrder->bind_param('ii', $newVoucherId, $order['order_id']);
    $stmtOrder->execute();
    $stmtOrder->close();

    // Update transaction too
    $stmtTx = $db->prepare("
        UPDATE transactions SET voucher_id = ?
        WHERE voucher_id = ?
    ");
    $stmtTx->bind_param('ii', $newVoucherId, $order['voucher_id']);
    $stmtTx->execute();
    $stmtTx->close();

    // Delete the old PENDING_ placeholder voucher
    $stmtDel = $db->prepare("DELETE FROM vouchers WHERE id = ?");
    $stmtDel->bind_param('i', $order['voucher_id']);
    $stmtDel->execute();
    $stmtDel->close();

    $log("Resolved PENDING order #{$order['order_id']} → voucher $newCode");
    $resolved++;
}

$log("Resolved $resolved pending order(s).");
$db->close();
$log('Sync complete.');