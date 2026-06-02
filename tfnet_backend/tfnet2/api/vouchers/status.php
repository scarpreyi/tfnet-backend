<?php
// GET /vouchers/status?code=XXXXXX
require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    respond(['success' => false, 'error' => 'No voucher code provided.']);
    exit;
}

$omada = new OmadaClient();
$v     = $omada->getVoucherByCode($code);

if (!$v) {
    respond(['success' => false, 'error' => 'Voucher not found. Check the code and try again.']);
    exit;
}

// ─── Traffic ──────────────────────────────────────────────────────────────────
// Confirmed from raw Omada API response:
//   trafficLimit  → MB   (e.g. 2048 = 2 GB)
//   trafficUsed   → BYTES (e.g. 804903843 = ~767 MB)
//   trafficLeft   → BOOLEAN (true/false) — NOT a number, ignore it

$limitMb   = round((float)($v['trafficLimit'] ?? 0), 2); // already MB
$usedBytes = (float)($v['trafficUsed'] ?? 0);             // bytes
$usedMb    = round($usedBytes / 1048576, 2);              // convert to MB
$leftMb    = round(max(0, $limitMb - $usedMb), 2);        // compute remaining

// Convert to GB
$limitGb = round($limitMb / 1024, 2);
$leftGb  = round($leftMb  / 1024, 2);
$usedGb  = round($usedMb  / 1024, 2);

$limitStr = $limitMb > 0 ? "{$limitGb} GB" : 'Unlimited';
$pct      = $limitMb > 0 ? min(100, round(($usedMb / $limitMb) * 100, 1)) : 0;

// ─── Expiry ───────────────────────────────────────────────────────────────────
// Omada returns timestamps in milliseconds
$endTime   = (int)($v['endTime']   ?? 0);
$startTime = (int)($v['startTime'] ?? 0);
if ($endTime   > 1_000_000_000_000) $endTime   = intval($endTime   / 1000);
if ($startTime > 1_000_000_000_000) $startTime = intval($startTime / 1000);

$now      = time();
$secsLeft = max(0, $endTime > 0 ? $endTime - $now : 0);
$expired  = $endTime > 0 && $secsLeft === 0;
$daysLeft = (int)floor($secsLeft / 86400);
$dateStr  = $endTime  > 0 ? date('M d, Y g:i A', $endTime)  : 'n/a';
$startStr = $startTime > 0 ? date('M d, Y g:i A', $startTime) : 'n/a';

// ─── Status ───────────────────────────────────────────────────────────────────
// Omada's 'valid' flag = current session active, NOT whether voucher is still usable
// Trust endTime only — if endTime is in the future the voucher is still active
$usedFlag = (int)($v['used'] ?? 0);

if ($usedFlag === 0) {
    $statusStr = 'unused';
} elseif ($endTime > 0 && $endTime > $now) {
    $statusStr = 'active';   // time period still valid
} elseif ($endTime > 0 && $endTime <= $now) {
    $statusStr = 'expired';  // time period finished
} else {
    $statusStr = 'expired';  // used but no endTime — treat as expired
}

// ─── Duration ─────────────────────────────────────────────────────────────────
$durationMins = (int)($v['duration'] ?? 0);
$durationDays = $durationMins > 0 ? round($durationMins / 60 / 24) : 0;
$durationStr  = $durationDays > 0
    ? "{$durationDays} day" . ($durationDays > 1 ? 's' : '')
    : 'n/a';

// ─── Plan name ────────────────────────────────────────────────────────────────
$planName = $limitGb > 0 ? "{$limitGb} GB / {$durationStr}" : 'Internet Plan';

respond([
    'success' => true,
    'voucher' => [
        'code'          => $v['code'] ?? $code,
        'status'        => $statusStr,
        'data_limit'    => $limitStr,
        'data_limit_mb' => $limitMb,
        'data_used_mb'  => $usedMb,
        'data_used_gb'  => $usedGb,
        'data_left_gb'  => $leftGb,
        'data_left_mb'  => $leftMb,
        'usage_pct'     => $pct,
        'price'         => $v['unitPrice'] ?? '0',
        'duration'      => $durationStr,
        'start_date'    => $startStr,
        'expiry'        => [
            'date_str'   => $dateStr,
            'days_left'  => $daysLeft,
            'expired'    => $expired,
            'start_date' => $startStr,
        ],
        'name' => $planName,
    ],
]);
?>