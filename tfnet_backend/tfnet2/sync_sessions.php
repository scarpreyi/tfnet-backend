<?php
// sync_sessions.php
// Run every minute via Windows Task Scheduler
// Pulls live data from Omada and updates sessions table

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/omada/OmadaClient.php';

$omada   = new OmadaClient();
$clients = $omada->getActiveClients();
$db      = getDB();
$synced  = 0;

foreach ($clients as $client) {
    $mac      = $db->real_escape_string($client['mac']         ?? '');
    $ssid     = $db->real_escape_string($client['ssid']        ?? 'Mapondera Wifi');
    $ip       = $db->real_escape_string($client['ip']          ?? '');
    $download = intval($client['trafficDown'] ?? 0);
    $upload   = intval($client['trafficUp']   ?? 0);

    if (!$mac) continue;

    // Check if active session exists for this MAC
    $res = $db->query("
        SELECT id FROM sessions
        WHERE device_mac = '$mac'
        AND status = 'active'
        LIMIT 1
    ");

    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $db->query("
            UPDATE sessions SET
                download_bytes = $download,
                upload_bytes   = $upload,
                ip_address     = '$ip',
                last_sync      = NOW()
            WHERE id = {$row['id']}
        ");
        $synced++;
    }
}

$db->close();
echo "[" . date('Y-m-d H:i:s') . "] Synced $synced active sessions.\n";
?>