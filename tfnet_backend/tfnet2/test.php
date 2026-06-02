<?php
require_once 'omada/OmadaClient.php';
$omada = new OmadaClient();

// Use a voucher you know EXACTLY how much it used
// Check Omada dashboard first, note the exact GB used, then run this
$v = $omada->getVoucherByCode('xxueDG'); // Galaxy A06s voucher — 182.86MB used per dashboard
echo json_encode([
    'trafficUsed'  => $v['trafficUsed'],
    'trafficLimit' => $v['trafficLimit'],
    'trafficLeft'  => $v['trafficLeft'],
], JSON_PRETTY_PRINT);
?>