<?php
// POST /auth/login
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$body     = getBody();
$phone    = trim($body['phone']    ?? '');
$password = trim($body['password'] ?? '');

if (!$phone || !$password) {
    respondError('Phone and password are required.');
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT id, name, phone, password, device_mac, is_admin
    FROM users
    WHERE phone = ?
");
$stmt->bind_param('s', $phone);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();
$db->close();

if (!$user) {
    respondError('Phone number not found.');
}

if (!password_verify($password, $user['password'])) {
    respondError('Incorrect password.');
}

$token = generateToken($user['id']);

respond([
    'success' => true,
    'token'   => $token,
    'user'    => [
        'id'         => $user['id'],
        'name'       => $user['name'],
        'phone'      => $user['phone'],
        'device_mac' => $user['device_mac'],
        'is_admin'   => (int) $user['is_admin'],
    ],
]);
?>