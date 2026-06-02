<?php
define('DB_HOST', 'sql313.infinityfree.com');
define('DB_USER', 'if0_42077755');
define('DB_PASS', 'Chipochidzawo3!');
define('DB_NAME', 'if0_42077755_tfnet_db');
define('DB_PORT', 3306);

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode([
            'error'   => 'Database connection failed',
            'details' => $conn->connect_error
        ]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
