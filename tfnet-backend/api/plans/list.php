<?php
// GET /plans/list
// Returns all available voucher groups as plans
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$omada  = new OmadaClient();
$groups = $omada->getVoucherGroups();

if (empty($groups)) {
    respondError('Could not load plans. Please try again.', 500);
}

$plans = [];
foreach ($groups as $g) {
    // Skip groups with no unused vouchers
    if (($g['unusedCount'] ?? 0) <= 0) continue;

    // Skip free/internal groups
    if (!isset($g['unitPrice'])) continue;

    $dataMb   = $g['trafficLimit'] ?? 0;
    $dataStr  = $dataMb > 0 ? round($dataMb / 1024) . ' GB' : 'Unlimited';
    $days     = round(($g['duration'] ?? 0) / 60 / 24);
    $daysStr  = $days . ($days === 1 ? ' day' : ' days');

    $plans[] = [
        'id'           => $g['id'],
        'name'         => $g['name'],
        'data'         => $dataStr,
        'data_mb'      => $dataMb,
        'duration'     => $daysStr,
        'duration_mins'=> $g['duration'] ?? 0,
        'price'        => $g['unitPrice'],
        'currency'     => $g['currency'] ?? 'USD',
        'available'    => $g['unusedCount'] ?? 0,
    ];
}

// Sort by price ascending
usort($plans, fn($a, $b) => floatval($a['price']) <=> floatval($b['price']));

respond([
    'success' => true,
    'plans'   => $plans,
]);
?>