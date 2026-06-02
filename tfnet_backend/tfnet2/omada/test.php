<?php
require_once 'OmadaClient.php';

$omada = new OmadaClient();

echo "<pre>";

// ─── TEST 1: Active clients ───────────────────────────────────────────────────
echo "=== TEST 1: Active clients ===\n";
$clients = $omada->getActiveClients();
echo "Active clients: " . count($clients) . "\n";
if (!empty($clients)) {
    $c = $clients[0];
    echo "First MAC  : " . ($c['mac']         ?? 'n/a') . "\n";
    echo "First IP   : " . ($c['ip']          ?? 'n/a') . "\n";
    echo "First SSID : " . ($c['ssid']        ?? 'n/a') . "\n";
    echo "Down       : " . ($c['trafficDown'] ?? 0)     . " bytes\n";
    echo "Up         : " . ($c['trafficUp']   ?? 0)     . " bytes\n";
}
echo "\n";

// ─── TEST 2: Voucher groups ───────────────────────────────────────────────────
echo "=== TEST 2: Voucher groups ===\n";
$groups = $omada->getVoucherGroups();
echo "Groups found: " . count($groups) . "\n\n";
foreach ($groups as $g) {
    $price    = isset($g['unitPrice']) ? '$' . $g['unitPrice'] . ' ' . ($g['currency'] ?? '') : 'free';
    $dataMb   = $g['trafficLimit'] ?? 0;
    $dataStr  = $dataMb > 0 ? round($dataMb / 1024) . ' GB' : 'Unlimited';
    $days     = round(($g['duration'] ?? 0) / 60 / 24) . ' days';
    $unused   = $g['unusedCount'] ?? 0;
    echo $g['name'] . " | $dataStr | $days | $price | unused:$unused | id:" . $g['id'] . "\n";
}
echo "\n";

// ─── TEST 3: Get unused voucher from 25 GB group ──────────────────────────────
echo "=== TEST 3: Get unused voucher from 25 GB group ===\n";
$groupId25 = '68eeaf93eecf4a0dc59cb2c7';
$vouchers  = $omada->getUnusedVoucherFromGroup($groupId25, 1);
echo "Unused vouchers fetched: " . count($vouchers) . "\n";
if (!empty($vouchers)) {
    echo "Code     : " . ($vouchers[0]['code']         ?? 'n/a') . "\n";
    echo "Used     : " . ($vouchers[0]['used']         ?? 'n/a') . "\n";
    echo "Duration : " . ($vouchers[0]['duration']     ?? 'n/a') . " mins\n";
    echo "Data     : " . ($vouchers[0]['trafficLimit'] ?? 'n/a') . " MB\n";
} else {
    echo "No unused vouchers found in this group.\n";
}
echo "\n";

// ─── TEST 3b: Get unused voucher from 10 GB group ────────────────────────────
echo "=== TEST 3b: Get unused voucher from 10 GB group ===\n";
$groupId10 = '69464c2057dc3c7c5a2c4c0e';
$vouchers2 = $omada->getUnusedVoucherFromGroup($groupId10, 1);
echo "Unused vouchers fetched: " . count($vouchers2) . "\n";
if (!empty($vouchers2)) {
    echo "Code     : " . ($vouchers2[0]['code']         ?? 'n/a') . "\n";
    echo "Used     : " . ($vouchers2[0]['used']         ?? 'n/a') . "\n";
} else {
    echo "No unused vouchers found.\n";
}
echo "\n";

// ─── TEST 4: Voucher status by code ──────────────────────────────────────────
echo "=== TEST 4: Voucher status for Lo2i9Q ===\n";
$status = $omada->getVoucherStatus('Lo2i9Q');
if ($status) {
    echo "Code       : " . $status['code']                         . "\n";
    echo "Used       : " . ($status['used'] ? 'YES' : 'NO')        . "\n";
    echo "Valid      : " . ($status['valid'] ? 'YES' : 'NO')       . "\n";
    echo "Duration   : " . round($status['duration'] / 60 / 24)   . " days\n";
    echo "Data limit : " . round($status['trafficLimit'] / 1024)  . " GB\n";
    echo "Data used  : " . $status['trafficUsed']                  . " MB\n";
    echo "Price      : $" . $status['unitPrice'] . " " . $status['currency'] . "\n";
    echo "Portal     : " . implode(', ', $status['portalNames'])   . "\n";
} else {
    echo "Voucher not found.\n";
}
echo "\n";

// ─── TEST 5: Get voucher group by ID (raw data) ───────────────────────────────
echo "=== TEST 5: Raw voucherGroups/ID endpoint for 25 GB group ===\n";
$raw = $omada->getVoucherGroupById($groupId25);
echo "Items returned: " . count($raw) . "\n";
if (!empty($raw)) {
    echo "First item raw:\n";
    echo json_encode($raw[0], JSON_PRETTY_PRINT) . "\n";
}

echo "</pre>";
?>