<?php
// GET /session/status
// DB-first approach:
//   1. Look up voucher in DB (works even when Omada is offline)
//   2. Enrich with live session data from Omada if available
//   3. Return full response to Flutter app

require_once __DIR__ . '/../../omada/OmadaClient.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$code   = trim($_GET['code'] ?? '');
$mac    = trim($_GET['mac']  ?? '');
$db     = getDB();

// ── 1. Look up voucher in DB ──────────────────────────────────────────────────
// If a specific code was given, find it (even if it belongs to another user —
// the user might be entering a code they received via WhatsApp).
// If no code, find the user's most recent active/reserved/unused voucher.

if ($code) {
    $stmt = $db->prepare("
        SELECT id, user_id, code, plan, duration_mins, data_limit_mb,
               price, status, reserved_at, expires_at
        FROM vouchers
        WHERE code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $code);
} else {
    $stmt = $db->prepare("
        SELECT id, user_id, code, plan, duration_mins, data_limit_mb,
               price, status, reserved_at, expires_at
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
}

$stmt->execute();
$dbVoucher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

// ── 2. If not in DB at all, try Omada directly ────────────────────────────────
// This handles vouchers that exist on Omada but haven't been synced yet.
if (!$dbVoucher && $code) {
    try {
        $omada     = new OmadaClient();
        $omadaData = $omada->getVoucherForDb($code);

        if ($omadaData) {
            // Build a synthetic DB row from Omada data so the rest of the code works
            $dbVoucher = [
                'id'            => null,
                'user_id'       => null,
                'code'          => $omadaData['code'],
                'plan'          => $omadaData['plan'],
                'duration_mins' => $omadaData['duration_mins'],
                'data_limit_mb' => $omadaData['data_limit_mb'],
                'price'         => $omadaData['price'],
                'status'        => $omadaData['status'],
                'reserved_at'   => $omadaData['reserved_at'],
                'expires_at'    => $omadaData['expires_at'],
            ];
        }
    } catch (\Exception $e) {
        error_log('Omada fallback lookup error: ' . $e->getMessage());
    }
}

if (!$dbVoucher) {
    respond(['success' => false, 'error' => 'No voucher found. Please enter your code or purchase a plan.']);
    exit;
}

// ── 3. Build base voucher response from DB data ───────────────────────────────
$voucherCode  = $dbVoucher['code'];
$dbStatus     = $dbVoucher['status'];   // unused | reserved | active | expired
$dataLimitMb  = (float)($dbVoucher['data_limit_mb'] ?? 0);
$dataLimitGb  = $dataLimitMb > 0 ? round($dataLimitMb / 1024, 1) : 0;
$durationMins = (int)($dbVoucher['duration_mins'] ?? 0);
$price        = (float)($dbVoucher['price'] ?? 0);
$planName     = $dbVoucher['plan'] ?? 'Internet Plan';

// Expiry from DB
$expiresAt  = $dbVoucher['expires_at'];
$reservedAt = $dbVoucher['reserved_at'];

$now      = time();
$endTime  = $expiresAt  ? strtotime($expiresAt)  : 0;
$startTime = $reservedAt ? strtotime($reservedAt) : 0;

$secsLeft  = $endTime > 0 ? max(0, $endTime - $now) : 0;
$daysLeft  = (int)floor($secsLeft / 86400);
$hoursLeft = (int)floor(($secsLeft % 86400) / 3600);

$dateStr   = $endTime  > 0 ? date('M d, Y g:i A', $endTime)  : 'No expiry';
$startStr  = $startTime > 0 ? date('M d, Y g:i A', $startTime) : 'n/a';
$noExpiry  = ($endTime === 0);

// Map DB status → Flutter-friendly status string
$statusMap = [
    'unused'   => 'unused',
    'reserved' => 'unused',  // reserved = bought but not used yet, show as unused
    'active'   => 'active',
    'expired'  => 'expired',
];
$flutterStatus = $statusMap[$dbStatus] ?? 'unused';

$baseVoucher = [
    'code'         => $voucherCode,
    'status'       => $flutterStatus,
    'data_limit'   => $noExpiry || $dataLimitMb === 0.0 ? 'Unlimited' : "{$dataLimitGb} GB",
    'data_used_mb' => 0,
    'data_left_gb' => $dataLimitGb,
    'data_limit_mb'=> $dataLimitMb,
    'plan'         => $planName,
    'price'        => number_format($price, 2),
    'duration'     => $durationMins > 0 ? round($durationMins / 60 / 24) . ' days' : 'n/a',
    'start_date'   => $startStr,
    'expiry'       => [
        'date_str'   => $dateStr,
        'days_left'  => $daysLeft,
        'hours_left' => $hoursLeft,
        'expired'    => ($dbStatus === 'expired'),
    ],
];

// ── 4. Try to enrich with live Omada data ─────────────────────────────────────
$omadaOnline    = false;
$liveSession    = null;
$liveDataInfo   = null;
$connected      = false;

try {
    $omada      = new OmadaClient();
    $allClients = $omada->getAuthorizedClients();
    $omadaOnline = true;

    // Match by voucher code and/or MAC
    $matched = null;
    foreach ($allClients as $client) {
        $clientCode = $client['voucherCode'] ?? '';
        $clientMac  = strtolower(str_replace('-', ':', $client['mac'] ?? ''));

        $codeMatch = $voucherCode && strtolower($clientCode) === strtolower($voucherCode);
        $macMatch  = $mac && strtolower($mac) === $clientMac;

        if ($codeMatch || $macMatch) {
            if (!$matched || ($client['start'] ?? 0) > ($matched['start'] ?? 0)) {
                $matched = $client;
            }
        }
    }

    if ($matched) {
        $connected   = (bool)($matched['valid'] ?? false);
        $isPermanent = (bool)($matched['permanent'] ?? false);

        // Traffic — bytes
        $downloadBytes = (float)($matched['download'] ?? 0);
        $uploadBytes   = (float)($matched['upload']   ?? 0);
        $usedBytes     = $downloadBytes + $uploadBytes;
        $usedMb        = round($usedBytes / 1048576, 2);

        $limitBytes = $dataLimitMb > 0 ? $dataLimitMb * 1048576 : 0;
        $leftBytes  = $limitBytes  > 0 ? max(0, $limitBytes - $usedBytes) : 0;
        $leftGb     = $limitBytes  > 0 ? round($leftBytes / 1073741824, 2) : 0;
        $usagePct   = $limitBytes  > 0 ? round(($usedBytes / $limitBytes) * 100, 1) : 0;

        // Times from Omada (more accurate than DB)
        $omadaStartMs = (int)($matched['start'] ?? 0);
        $omadaEndMs   = (int)($matched['end']   ?? 0);
        $omadaStart   = $omadaStartMs > 0 ? intval($omadaStartMs / 1000) : 0;
        $omadaEnd     = 0;
        if (!$isPermanent && $omadaEndMs > 0) {
            $tmp = intval($omadaEndMs / 1000);
            if ($tmp < 9999999999) $omadaEnd = $tmp;
        }

        $omadaSecsLeft  = $omadaEnd > 0 ? max(0, $omadaEnd - $now) : 0;
        $omadaDaysLeft  = (int)floor($omadaSecsLeft / 86400);
        $omadaHoursLeft = (int)floor(($omadaSecsLeft % 86400) / 3600);
        $omadaDateStr   = $omadaEnd   > 0 ? date('M d, Y g:i A', $omadaEnd)   : 'No expiry';
        $omadaStartStr  = $omadaStart > 0 ? date('M d, Y g:i A', $omadaStart) : $startStr;

        $durationSecs = (int)($matched['duration'] ?? 0);
        $uptimeH      = (int)floor($durationSecs / 3600);
        $uptimeM      = (int)floor(($durationSecs % 3600) / 60);
        $uptimeStr    = $uptimeH > 0 ? "{$uptimeH}h {$uptimeM}m" : "{$uptimeM}m";

        // Overwrite base voucher with live Omada data
        $liveStatus = ($matched['valid'] ?? false) ? 'active' : 'expired';

        $baseVoucher = array_merge($baseVoucher, [
            'status'       => $liveStatus,
            'data_limit'   => $isPermanent || $dataLimitMb === 0.0 ? 'Unlimited' : "{$dataLimitGb} GB",
            'data_used_mb' => $usedMb,
            'data_left_gb' => $leftGb,
            'start_date'   => $omadaStartStr,
            'expiry'       => [
                'date_str'   => $omadaDateStr,
                'days_left'  => $omadaDaysLeft,
                'hours_left' => $omadaHoursLeft,
                'expired'    => !($matched['valid'] ?? false) && !$isPermanent,
            ],
        ]);

        if ($connected) {
            $liveSession = [
                'ssid'         => $matched['ssid']  ?? 'Mapondera Wifi',
                'ip'           => $matched['ip']     ?? 'n/a',
                'download_str' => _bytesToStr($downloadBytes),
                'upload_str'   => _bytesToStr($uploadBytes),
                'total_str'    => _bytesToStr($usedBytes),
                'start_time'   => $omadaStartStr,
                'uptime'       => $uptimeStr,
            ];

            if ($limitBytes > 0) {
                $liveDataInfo = [
                    'plan'          => $planName,
                    'data_used_mb'  => $usedMb,
                    'data_limit_gb' => $dataLimitGb,
                    'usage_pct'     => $usagePct,
                ];
            }
        }
    }

} catch (\Exception $e) {
    // Omada is offline — that's fine, we still return DB data
    error_log('Omada enrichment failed: ' . $e->getMessage());
    $omadaOnline = false;
}

// ── 5. Respond ────────────────────────────────────────────────────────────────
respond([
    'success'      => true,
    'connected'    => $connected,
    'omada_online' => $omadaOnline,
    'session'      => $liveSession,
    'data_info'    => $liveDataInfo,
    'voucher'      => $baseVoucher,
]);

// ── Helper ────────────────────────────────────────────────────────────────────
function _bytesToStr(float $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576,    2) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}