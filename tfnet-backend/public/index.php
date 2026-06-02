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

// Health check endpoint
if (empty($module) || $route === '/') {
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'message' => 'TFNET Backend API is running',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => [
            'config/app',
            'auth/register',
            'auth/login',
            'user/update-mac',
            'plans/list',
            'orders/create',
            'vouchers/purchase',
            'vouchers/status',
            'session/status',
            'customer/status',
            'transactions/history',
            'portal/submit',
            'admin/orders',
            'admin/confirm',
            'admin/reject',
            'admin/sync',
            'admin/stats',
            'admin/new-orders',
            'customer/new-confirmations',
            'customer/active-voucher'
        ]
    ]);
    exit;
}

// Router with file existence check
$routes = [
    'config/app'              => __DIR__ . '/../api/config/app_config.php',
    'auth/register'           => __DIR__ . '/../api/auth/register.php',
    'auth/login'              => __DIR__ . '/../api/auth/login.php',
    'user/update-mac'         => __DIR__ . '/../api/user/update_mac.php',
    'plans/list'              => __DIR__ . '/../api/plans/list.php',
    'orders/create'           => __DIR__ . '/../api/orders/create.php',
    'vouchers/purchase'       => __DIR__ . '/../api/vouchers/purchase.php',
    'vouchers/status'         => __DIR__ . '/../api/vouchers/status.php',
    'session/status'          => __DIR__ . '/../api/session/status.php',
    'customer/status'         => __DIR__ . '/../api/customer/status.php',
    'transactions/history'    => __DIR__ . '/../api/transactions/history.php',
    'portal/submit'           => __DIR__ . '/../api/portal/submit.php',
    'admin/orders'            => __DIR__ . '/../api/admin/orders.php',
    'admin/confirm'           => __DIR__ . '/../api/admin/admin_confirm.php',
    'admin/reject'            => __DIR__ . '/../api/admin/reject.php',
    'admin/sync'              => __DIR__ . '/../api/admin/sync.php',
    'admin/stats'             => __DIR__ . '/../api/admin/stats.php',
    'admin/new-orders'        => __DIR__ . '/../api/admin/new_orders.php',
    'customer/new-confirmations' => __DIR__ . '/../api/customer/new_confirmations.php',
    'customer/active-voucher'    => __DIR__ . '/../api/customer/active_voucher.php',
];

if (array_key_exists($route, $routes)) {
    $filePath = $routes[$route];
    if (file_exists($filePath)) {
        require $filePath;
    } else {
        http_response_code(503);
        echo json_encode([
            'error' => 'Endpoint not implemented',
            'route' => $route,
            'message' => 'This endpoint is being developed'
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        'error' => 'Endpoint not found',
        'route' => $route,
        'available_endpoints' => array_keys($routes)
    ]);
}
?>
