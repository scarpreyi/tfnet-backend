<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$fullUri  = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts    = explode('/', $fullUri);
$skip     = ['tfnet', 'public', ''];
$filtered = [];
foreach ($parts as $part) {
    if (!in_array($part, $skip)) $filtered[] = $part;
}
$module = $filtered[0] ?? '';
$action = $filtered[1] ?? '';
$route  = "$module/$action";

switch ($route) {
    case 'config/app':              require __DIR__ . '/../api/config/app_config.php';             break;
    case 'auth/register':           require __DIR__ . '/../api/auth/register.php';                 break;
    case 'auth/login':              require __DIR__ . '/../api/auth/login.php';                    break;
    case 'user/update-mac':         require __DIR__ . '/../api/user/update_mac.php';               break;
    case 'plans/list':              require __DIR__ . '/../api/plans/list.php';                    break;
    case 'orders/create':           require __DIR__ . '/../api/orders/create.php';                 break;
    case 'vouchers/purchase':       require __DIR__ . '/../api/vouchers/purchase.php';             break;
    case 'vouchers/status':         require __DIR__ . '/../api/vouchers/status.php';               break;
    case 'session/status':          require __DIR__ . '/../api/session/status.php';                break;
    case 'customer/status':         require __DIR__ . '/../api/customer/status.php';               break;
    case 'transactions/history':    require __DIR__ . '/../api/transactions/history.php';          break;
    case 'portal/submit':           require __DIR__ . '/../api/portal/submit.php';                 break;
    case 'admin/orders':            require __DIR__ . '/../api/admin/orders.php';                  break;
    case 'admin/confirm':           require __DIR__ . '/../api/admin/admin_confirm.php';           break;
    case 'admin/reject':            require __DIR__ . '/../api/admin/reject.php';                  break;
    case 'admin/sync':              require __DIR__ . '/../api/admin/sync.php';                    break;
    case 'admin/stats':             require __DIR__ . '/../api/admin/stats.php';                   break;
    case 'admin/new-orders':        require __DIR__ . '/../api/admin/new_orders.php';              break;
    case 'customer/new-confirmations': require __DIR__ . '/../api/customer/new_confirmations.php'; break;
    case 'customer/active-voucher':    require __DIR__ . '/../api/customer/active_voucher.php';    break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found', 'route' => $route]);
}