<?php
// GET /customer/status
// Called by DashboardScreen every 60s via ApiService().getCustomerStatus()
// Returns live voucher data for the dashboard status card.

require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$db     = getDB();

// ── 1. Get user's most recent active/unused voucher from DB ───────────────────
$stmt = $db->prepare("
    SELECT code, status
    FROM vouchers
    WHERE user_id = ?
      AND status IN ('active', 'reserved', 'unused')
    ORDER BY
      CASE status
        WHEN 'active'   THEN 1
        WHEN 'reserved' THEN 2
        WHEN 'unused'   THEN 3
        ELSE 4
      END,
      id DESC
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$row) {
    respond(['success' => true, 'connection' => ['connected' => false], 'voucher' => null]);
    exit;
}

$code = $row['code'];

// ── 2. Get live data from Omada ───────────────────────────────────────────────
$omada  = new OmadaClient();
$v      = $omada->getVoucherStatus($code);

if (!$v) {
    respond(['success' => true, 'connection' => ['connected' => false], 'voucher' => null]);
    exit;
}

$statusStr = $v['statusStr'] ?? 'unknown';

// Expired — return null voucher so dashboard shows "No Active Plan"
if ($statusStr === 'expired') {
    respond(['success' => true, 'connection' => ['connected' => false], 'voucher' => null]);
    exit;
}

// ── 3. Traffic — Omada returns BYTES ─────────────────────────────────────────
$usedBytes  = (float)($v['trafficUsed']  ?? 0);
$limitBytes = (float)($v['trafficLimit'] ?? 0);
$leftBytes  = (float)($v['trafficLeft']  ?? 0);
if ($leftBytes === 0.0 && $limitBytes > 0) {
    $leftBytes = max(0, $limitBytes - $usedBytes);
}

$usedMb   = round($usedBytes  / 1048576,    2);  // bytes -> MB
$limitMb  = round($limitBytes / 1048576,    2);  // bytes -> MB
$limitGb  = round($limitBytes / 1073741824, 1);  // bytes -> GB
$leftGb   = round($leftBytes  / 1073741824, 2);
$dataStr  = $limitBytes > 0 ? "{$limitGb} GB" : 'Unlimited';

// ── 4. Expiry ─────────────────────────────────────────────────────────────────
$endTime = (int)($v['endTime'] ?? 0);
if ($endTime > 1_000_000_000_000) $endTime = intval($endTime / 1000);
$startTime = (int)($v['startTime'] ?? 0);
if ($startTime > 1_000_000_000_000) $startTime = intval($startTime / 1000);

$now      = time();
$secsLeft = max(0, $endTime > 0 ? $endTime - $now : 0);
$expired  = $endTime > 0 && $secsLeft === 0;
$daysLeft = (int)floor($secsLeft / 86400);
$hoursLeft= (int)floor(($secsLeft % 86400) / 3600);
$dateStr  = $endTime > 0 ? date('M d, Y g:i A', $endTime) : 'n/a';
$startStr = $startTime > 0 ? date('M d, Y g:i A', $startTime) : 'n/a';

// ── 5. Plan name ──────────────────────────────────────────────────────────────
$planName = $v['name'] ?? '';
if (empty($planName)) {
    $durationMins = (int)($v['duration'] ?? 0);
    $durationDays = $durationMins > 0 ? round($durationMins / 60 / 24) : 0;
    $planName = $limitGb > 0
        ? "{$limitGb} GB / {$durationDays} day" . ($durationDays > 1 ? 's' : '')
        : 'Internet Plan';
}

respond([
    'success'    => true,
    'connection' => ['connected' => false], // MAC-based check removed (randomized MACs)
    'voucher'    => [
        'code'          => $v['code'] ?? $code,
        'status'        => $statusStr,
        'plan'          => $planName,
        'data'          => $dataStr,
        'data_used_mb'  => $usedMb,
        'data_limit_mb' => $limitMb,
        'data_left_gb'  => $leftGb,
        'start_date'    => $startStr,
        'expiry' => [
            'date_str'   => $dateStr,
            'days_left'  => $daysLeft,
            'hours_left' => $hoursLeft,
            'expired'    => $expired,
        ],
    ],
]);