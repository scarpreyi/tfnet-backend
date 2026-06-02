<?php
// ─── JWT Token Helper ─────────────────────────────────────────────────────────
// Simple JWT-like token using base64 + HMAC
define('JWT_SECRET', 'tfnet_secret_key_change_this_2024');

function generateToken($userId) {
    $payload = base64_encode(json_encode([
        'user_id' => $userId,
        'exp'     => time() + (7 * 24 * 60 * 60), // 7 days
    ]));
    $sig = hash_hmac('sha256', $payload, JWT_SECRET);
    return $payload . '.' . $sig;
}

function verifyToken($token) {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;

    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, JWT_SECRET);
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(base64_decode($payload), true);
    if (!$data || $data['exp'] < time()) return null;

    return $data['user_id'];
}

function getAuthUser() {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token   = str_replace('Bearer ', '', $auth);
    return verifyToken($token);
}

function requireAuth() {
    $userId = getAuthUser();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please login.']);
        exit;
    }
    return $userId;
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function respondError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
?>