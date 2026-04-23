<?php
// Copy this file to config/config.php and edit for your environment.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'parking',
        'user'    => 'parking',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Cashmatic local REST server, running on the cashier kiosk PC.
    // The browser (on the kiosk) talks to it directly over HTTPS.
    // Install the shipped server.pem as a trusted cert on the kiosk.
    'cashmatic' => [
        'base_url'   => 'https://127.0.0.1:50301',
        'username'   => 'cashmatic',
        'password'   => 'admin',
        'verify_ssl' => false,
    ],

    // MQTT broker that drives the gate relay and carries scan events.
    'mqtt' => [
        'host'      => 'broker.example.com',
        'port'      => 1883,
        'username'  => null,
        'password'  => null,
        'client_id' => 'parking-php',
        'use_tls'   => false,
        'topics' => [
            'relay_open' => 'parking/gate/relay',   // publish here to open the gate
            'scan'       => 'parking/gate/scan',    // gate QR reader publishes scanned PINs here
            'pin_add'    => '',                     // optional: push paid PINs to gate-side cache
        ],
        'relay_payload' => '1',
    ],

    // Tariff. Amounts in euro cents.
    'tariff' => [
        'grace_minutes'   => 10,
        'hourly_cents'    => 150,
        'daily_cap_cents' => 1500,
    ],

    // TextMeBot WhatsApp gateway.
    'textmebot' => [
        'api_key'  => 'YOUR_TEXTMEBOT_API_KEY',
        'endpoint' => 'https://api.textmebot.com/send.php',
    ],

    'app' => [
        'base_url' => 'https://your-domain.example',
        'pin_ttl_after_pay_minutes' => 15,
        // Default UI language for new visitors. Users can still switch via the
        // IT/EN pills; their choice is remembered in the `parking_lang` cookie.
        // Supported: 'en', 'it'.
        'default_lang' => 'en',
    ],
];
