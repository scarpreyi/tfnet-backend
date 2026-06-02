<?php
// api_test.php - Test all endpoints
// Place in C:\xampp\htdocs\tfnet\public\api_test.php

echo "<pre>";
$base = 'http://localhost/tfnet/public';

function callApi($url, $method = 'GET', $body = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($result, true)];
}

// ─── TEST 1: Register ─────────────────────────────────────────────────────────
echo "=== TEST 1: Register ===\n";
$reg = callApi("$base/auth/register", 'POST', [
    'name'     => 'Test User',
    'phone'    => '0771234567',
    'password' => 'test123',
]);
echo "HTTP: " . $reg['code'] . "\n";
echo json_encode($reg['body'], JSON_PRETTY_PRINT) . "\n\n";
$token = $reg['body']['token'] ?? null;

// ─── TEST 2: Login ────────────────────────────────────────────────────────────
echo "=== TEST 2: Login ===\n";
$login = callApi("$base/auth/login", 'POST', [
    'phone'    => '0771234567',
    'password' => 'test123',
]);
echo "HTTP: " . $login['code'] . "\n";
echo json_encode($login['body'], JSON_PRETTY_PRINT) . "\n\n";
if ($login['body']['token'] ?? null) $token = $login['body']['token'];

// ─── TEST 3: Plans list ───────────────────────────────────────────────────────
echo "=== TEST 3: Plans list ===\n";
$plans = callApi("$base/plans/list");
echo "HTTP: " . $plans['code'] . "\n";
echo "Plans found: " . count($plans['body']['plans'] ?? []) . "\n";
foreach (($plans['body']['plans'] ?? []) as $p) {
    echo "  " . $p['name'] . " | " . $p['data'] . " | " . $p['duration'] . " | $" . $p['price'] . " | available:" . $p['available'] . "\n";
}
echo "\n";

// ─── TEST 4: Voucher status ───────────────────────────────────────────────────
echo "=== TEST 4: Voucher status (Lo2i9Q) ===\n";
$vs = callApi("$base/vouchers/status?code=Lo2i9Q");
echo "HTTP: " . $vs['code'] . "\n";
echo json_encode($vs['body'], JSON_PRETTY_PRINT) . "\n\n";
echo "Token being used: " . ($token ?? 'NULL') . "\n";
echo "=== TEST 5: Session status (raw) ===\n";

$ch = curl_init("$base/session/status?mac=AE-EF-67-99-7F-CB");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP : $code\n";
echo "Raw  : $raw\n\n";
echo "</pre>";
?>