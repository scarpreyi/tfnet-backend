<?php
// api/config/app_config.php
// Returns app configuration for white labeling
// No auth required — called on app startup

require_once __DIR__ . '/../../config/bootstrap.php';

// ─── App Configuration ────────────────────────────────────────────────────────
// Edit these values to customize the app for each ISP customer

$config = [
    // App identity
    'app_name'    => 'TF Net',
    'app_tagline' => 'Your WiFi, Your Way',
    'app_version' => '1.0.0',

    // Network
    'wifi_name'   => 'Mapondera Wifi',

    // Brand colors
    'colors' => [
        'primary'    => '#1EDB82',  // green
        'secondary'  => '#38AAFF',  // blue
        'accent'     => '#FFB830',  // amber
        'background' => '#0A1628',  // dark blue
        'card'       => '#0F2A4A',  // card dark
    ],

    // Payment methods available
    'payment_methods' => [
        ['id' => 'ecocash',  'label' => 'EcoCash',  'icon' => 'phone'],
        ['id' => 'innbucks', 'label' => 'InnBucks', 'icon' => 'credit_card'],
        ['id' => 'usd_cash', 'label' => 'USD Cash', 'icon' => 'attach_money'],
    ],

    // Currency
    'currency'        => 'USD',
    'currency_symbol' => '$',

    // Contact
    'support_phone'    => '+263771234567',
    'support_whatsapp' => '+263771234567',

    // Features
    'features' => [
        'auto_auth'    => true,
        'data_sharing' => false,
        'sms_verify'   => false,
    ],

    // Reservation window in hours
    'reservation_hours_short' => 48,   // daily/weekly plans
    'reservation_hours_long'  => 168,  // monthly plans
];

echo json_encode([
    'success' => true,
    'config'  => $config,
]);
?>
