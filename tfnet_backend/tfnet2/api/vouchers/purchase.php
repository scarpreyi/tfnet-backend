<?php
// GET /session/status?code=Lo2i9Q
// OR  /session/status?mac=XX:XX:XX:XX:XX:XX  (legacy fallback)
//
// Returns session info derived from voucher status on Omada.
// Using voucher code avoids the Android randomized MAC problem.

require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$omada = new OmadaClient();

// ── 1. Try voucher code first (primary method) ────────────────────────────────
$code = trim($_GET['code'] ?? '');

if ($code) {
    $status = $omada->getVoucherStatus($code);

    if (!$status) {
        respond([
            'connected' => false,
            'session'   => null,
            'data_info' => null,
        ]);
        exit;
    }

    // Voucher is "connected/active" if it's been used and is still valid
    $connected = (bool)($status['used'] ?? false) && (bool)($status['valid'] ?? false);

    if (!$connected) {
        respond([
            'connected' => false,
            'session'   => null,
            'data_info' => null,
        ]);
        exit;
    }

    // ── Build session data from voucher status ────────────────────────────
    $trafficUsedBytes = ($status['trafficUsed']  ?? 0); // bytes
    $trafficLimitMb   = ($status['trafficLimit'] ?? 0); // MB
    $trafficUsedMb    = round($trafficUsedBytes / 1024 / 1024, 2);
    $trafficLimitGb   = $trafficLimitMb > 0 ? round($trafficLimitMb / 1024, 1) : 0;

    // Format bytes into human-readable string
    function formatBytes(float $mb): string {
        if ($mb >= 1024) return round($mb / 1024, 2) . ' GB';
        return round($mb, 1)  . ' MB';
    }

    $startTime = $status['startTime'] ?? 0;
    $endTime   = $status['endTime']   ?? 0;

    // Omada returns timestamps in milliseconds
    if ($startTime > 1_000_000_000_000) $startTime = intval($startTime / 1000);
    if ($endTime   > 1_000_000_000_000) $endTime   = intval($endTime   / 1000);

    $startDateStr = $startTime > 0 ? date('M d, Y g:i A', $startTime) : 'Just now';

    $usagePct = $trafficLimitMb > 0
        ? round(($trafficUsedMb / $trafficLimitMb) * 100, 1)
        : 0;

    respond([
        'connected' => true,
        'session'   => [
            'ssid'         => implode(', ', $status['portalNames'] ?? [AppConfig_wifiName()]),
            'download_str' => formatBytes($trafficUsedMb), // Omada doesn't split dl/ul separately in voucher API
            'upload_str'   => '0 MB',
            'total_str'    => formatBytes($trafficUsedMb),
            'ip'           => 'n/a',  // Omada voucher API doesn't expose per-client IP
            'mac'          => 'n/a',
            'start_time'   => $startDateStr,
        ],
        'data_info' => [
            'plan'          => $status['name'] ?? 'Internet Plan',
            'data_limit_gb' => $trafficLimitGb,
            'usage_pct'     => $usagePct,
        ],
    ]);
    exit;
}

// ── 2. Legacy fallback: MAC-based lookup ──────────────────────────────────────
// Kept for any older clients still sending ?mac= param.
$mac = strtoupper(trim($_GET['mac'] ?? ''));

if (!$mac || $mac === '00:00:00:00:00:00' || $mac === '02:00:00:00:00:00') {
    respond([
        'connected' => false,
        'session'   => null,
        'data_info' => null,
        'error'     => 'No code or valid MAC provided.',
    ]);
    exit;
}

// Try to get session from Omada by MAC
$session = $omada->getClientSession($mac); // your existing method

if (!$session) {
    respond(['connected' => false, 'session' => null, 'data_info' => null]);
    exit;
}

respond([
    'connected' => true,
    'session'   => [
        'ssid'         => $session['ssid']         ?? 'Mapondera Wifi',
        'download_str' => $session['download_str'] ?? '0 MB',
        'upload_str'   => $session['upload_str']   ?? '0 MB',
        'total_str'    => $session['total_str']    ?? '0 MB',
        'ip'           => $session['ip']           ?? 'n/a',
        'mac'          => $mac,
        'start_time'   => $session['start_time']  ?? 'n/a',
    ],
    'data_info' => $session['data_info'] ?? null,
]);

// ── Helper ────────────────────────────────────────────────────────────────────
function AppConfig_wifiName(): string {
    return 'Mapondera Wifi'; // Keep in sync with AppConfig.wifiName
}
?>