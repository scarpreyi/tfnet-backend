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
    echo "MAC  : " . ($c['mac']         ?? 'n/a') . "\n";
    echo "IP   : " . ($c['ip']          ?? 'n/a') . "\n";
    echo "Down : " . ($c['trafficDown'] ?? 0)     . " bytes\n";
    echo "Up   : " . ($c['trafficUp']   ?? 0)     . " bytes\n";
}
echo "\n";

// ─── TEST 2: Voucher groups ───────────────────────────────────────────────────
echo "=== TEST 2: Voucher groups ===\n";
$groups = $omada->getVoucherGroups();
echo "Groups: " . count($groups) . "\n\n";
foreach ($groups as $g) {
    $price  = isset($g['unitPrice']) ? '$' . $g['unitPrice'] . ' ' . ($g['currency'] ?? '') : 'free';
    $data   = isset($g['trafficLimit']) ? round($g['trafficLimit'] / 1024) . ' GB' : 'Unlimited';
    $days   = round(($g['duration'] ?? 0) / 60 / 24) . 'd';
    $unused = $g['unusedCount'] ?? 0;
    echo $g['name'] . " | $data | $days | $price | unused:$unused\n";
}
echo "\n";

// ─── TEST 3: Get unused voucher from 25 GB group ──────────────────────────────
echo "=== TEST 3: Get unused voucher from 25 GB group ===\n";
$groupId25 = '68eeaf93eecf4a0dc59cb2c7';
$vouchers  = $omada->getUnusedVoucherFromGroup($groupId25, 1);
echo "Unused fetched: " . count($vouchers) . "\n";
if (!empty($vouchers)) {
    echo "Code   : " . ($vouchers[0]['code']   ?? 'n/a') . "\n";
    echo "Status : " . ($vouchers[0]['status'] ?? 'n/a') . " (0=unused)\n";
} else {
    echo "None found — all may be status 1 or 2.\n";
}
echo "\n";

// ─── TEST 3b: Claim voucher (get code directly) ───────────────────────────────
echo "=== TEST 3b: Claim voucher from 10 GB group ===\n";
$groupId10 = '69464c2057dc3c7c5a2c4c0e';
$code      = $omada->claimVoucher($groupId10);
echo "Claimed code: " . ($code ?? 'none available') . "\n\n";

// ─── TEST 4: Voucher status by code ──────────────────────────────────────────
echo "=== TEST 4: Voucher status for Lo2i9Q ===\n";
$status = $omada->getVoucherStatus('Lo2i9Q');
if ($status) {
    echo "Code       : " . $status['code']                        . "\n";
    echo "Used       : " . ($status['used'] ? 'YES' : 'NO')       . "\n";
    echo "Valid      : " . ($status['valid'] ? 'YES' : 'NO')      . "\n";
    echo "Duration   : " . round($status['duration'] / 60 / 24)  . " days\n";
    echo "Data limit : " . round($status['trafficLimit'] / 1024) . " GB\n";
    echo "Data used  : " . $status['trafficUsed']                 . " MB\n";
    echo "Price      : $" . $status['unitPrice'] . " " . $status['currency'] . "\n";
    echo "Portal     : " . implode(', ', $status['portalNames'])  . "\n";
} else {
    echo "Voucher not found.\n";
}

echo "\n=== ALL TESTS COMPLETE ===\n";
echo "</pre>";
?>