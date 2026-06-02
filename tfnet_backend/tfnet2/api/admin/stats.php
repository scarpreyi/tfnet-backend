<?php
// GET /admin/stats?period=month|week|today
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';
require_once __DIR__ . '/auth_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

requireAdmin();

$period = $_GET['period'] ?? 'month';

$db = getDB();

// ─── Date range ───────────────────────────────────────────────────────────────
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d 00:00:00');
        $dateTo   = date('Y-m-d 23:59:59');
        $label    = 'Today';
        break;
    case 'week':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $dateTo   = date('Y-m-d 23:59:59');
        $label    = 'This Week';
        break;
    default: // month
        $dateFrom = date('Y-m-01 00:00:00');
        $dateTo   = date('Y-m-t 23:59:59');
        $label    = 'This Month';
        break;
}

// ─── Total revenue ────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM orders
    WHERE status = 'confirmed'
    AND confirmed_at BETWEEN ? AND ?
");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$totalRevenue = (float) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ─── Number of confirmed orders ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM orders
    WHERE status = 'confirmed'
    AND confirmed_at BETWEEN ? AND ?
");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$totalOrders = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ─── Pending orders count ─────────────────────────────────────────────────────
$stmt = $db->query("SELECT COUNT(*) AS total FROM orders WHERE status = 'pending'");
$pendingOrders = (int) $stmt->fetch_assoc()['total'];

// ─── Most popular plan ────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT plan_name, COUNT(*) AS count
    FROM orders
    WHERE status = 'confirmed'
    AND confirmed_at BETWEEN ? AND ?
    GROUP BY plan_name
    ORDER BY count DESC
    LIMIT 1
");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$popularRow  = $stmt->get_result()->fetch_assoc();
$popularPlan = $popularRow ? $popularRow['plan_name'] : 'N/A';
$popularCount = $popularRow ? (int) $popularRow['count'] : 0;
$stmt->close();

// ─── Active vouchers ──────────────────────────────────────────────────────────
$stmt = $db->query("SELECT COUNT(*) AS total FROM vouchers WHERE status = 'active'");
$activeVouchers = (int) $stmt->fetch_assoc()['total'];

// ─── Reserved vouchers ────────────────────────────────────────────────────────
$stmt = $db->query("SELECT COUNT(*) AS total FROM vouchers WHERE status = 'reserved'");
$reservedVouchers = (int) $stmt->fetch_assoc()['total'];

// ─── Revenue breakdown by plan ────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT plan_name, COUNT(*) AS orders, SUM(amount) AS revenue
    FROM orders
    WHERE status = 'confirmed'
    AND confirmed_at BETWEEN ? AND ?
    GROUP BY plan_name
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$result    = $stmt->get_result();
$breakdown = [];
while ($row = $result->fetch_assoc()) {
    $breakdown[] = [
        'plan'    => $row['plan_name'],
        'orders'  => (int) $row['orders'],
        'revenue' => (float) $row['revenue'],
    ];
}
$stmt->close();

// ─── Daily revenue for chart (last 7 days or days in month) ──────────────────
$stmt = $db->prepare("
    SELECT DATE(confirmed_at) AS day, SUM(amount) AS revenue, COUNT(*) AS orders
    FROM orders
    WHERE status = 'confirmed'
    AND confirmed_at BETWEEN ? AND ?
    GROUP BY DATE(confirmed_at)
    ORDER BY day ASC
");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$result    = $stmt->get_result();
$chartData = [];
while ($row = $result->fetch_assoc()) {
    $chartData[] = [
        'day'     => $row['day'],
        'revenue' => (float) $row['revenue'],
        'orders'  => (int) $row['orders'],
    ];
}
$stmt->close();

$db->close();

respond([
    'success'          => true,
    'period'           => $period,
    'label'            => $label,
    'date_from'        => $dateFrom,
    'date_to'          => $dateTo,
    'total_revenue'    => $totalRevenue,
    'total_orders'     => $totalOrders,
    'pending_orders'   => $pendingOrders,
    'popular_plan'     => $popularPlan,
    'popular_count'    => $popularCount,
    'active_vouchers'  => $activeVouchers,
    'reserved_vouchers'=> $reservedVouchers,
    'breakdown'        => $breakdown,
    'chart_data'       => $chartData,
]);
?>