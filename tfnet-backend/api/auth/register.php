<?php
// POST /auth/register
// Body: { name, phone, password }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$body     = getBody();
$name     = trim($body['name']     ?? '');
$phone    = trim($body['phone']    ?? '');
$password = trim($body['password'] ?? '');

if (!$name || !$phone || !$password) {
    respondError('Name, phone and password are required.');
}

if (strlen($password) < 6) {
    respondError('Password must be at least 6 characters.');
}

$db = getDB();

// Check if phone already exists
$stmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
$stmt->bind_param('s', $phone);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    respondError('Phone number already registered.');
}
$stmt->close();

// Create user
$hashed = password_hash($password, PASSWORD_BCRYPT);
$stmt2  = $db->prepare("INSERT INTO users (name, phone, password) VALUES (?, ?, ?)");
$stmt2->bind_param('sss', $name, $phone, $hashed);

if (!$stmt2->execute()) {
    respondError('Registration failed. Please try again.', 500);
}

$userId = $db->insert_id;
$stmt2->close();
$db->close();

$token = generateToken($userId);

respond([
    'success' => true,
    'message' => 'Registration successful.',
    'token'   => $token,
    'user'    => [
        'id'    => $userId,
        'name'  => $name,
        'phone' => $phone,
    ],
]);
?>