<?php
// POST /user/update-mac
// Updates the device MAC address for the authenticated user
// Called automatically by the app when WiFi is detected
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$userId = requireAuth();
$body   = getBody();
$mac    = trim($body['mac'] ?? '');

if (!$mac) {
    respondError('MAC address is required.');
}

// Basic MAC format validation
if (!preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $mac)) {
    respondError('Invalid MAC address format.');
}

$db   = getDB();
$stmt = $db->prepare("UPDATE users SET device_mac = ? WHERE id = ?");
$stmt->bind_param('si', $mac, $userId);
$stmt->execute();
$stmt->close();
$db->close();

respond([
    'success' => true,
    'message' => 'Device MAC updated.',
    'mac'     => $mac,
]);
?>