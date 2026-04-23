<?php
// Copy this file to config/config.php and edit for your environment.
// config/config.php is gitignored — never commit real secrets.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'parking',
        'user'    => 'parking',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Cashmatic local REST server. The VPS talks to it server-side (PHP),
    // so base_url must be a hostname/IP the VPS can reach — typically via
    // a Tailscale/VPN address, an ngrok tunnel, or a port-forward.
    // The kiosk browser never calls this URL directly anymore.
    'cashmatic' => [
        'base_url'   => 'https://KIOSK_HOST_OR_TUNNEL:50301',
        'username'   => 'cashmatic',
        'password'   => 'CHANGE_ME',       // match the kiosk's users.json
        'verify_ssl' => false,             // true once you trust the kiosk cert
    ],

    // MQTT broker that drives the gate relay and carries scan events.
    'mqtt' => [
        'host'      => '127.0.0.1',
        'port'      => 1883,
        'username'  => null,
        'password'  => null,
        'client_id' => 'parking-php',
        'use_tls'   => false,
        'topics' => [
            'relay_open' => 'parking/gate/relay',   // publish to open the gate
            'scan'       => 'parking/gate/scan',    // gate reader publishes PINs here
            'pin_add'    => '',                     // optional paid-PIN cache
        ],
        'relay_payload' => '1',
    ],

    // Tariff. Amounts in cents of the display currency.
    'tariff' => [
        'currency'        => 'EUR',
        'currency_symbol' => '€',
        'grace_minutes'   => 0,
        'minimum_cents'   => 100,   // €1.00 minimum
        'hourly_cents'    => 100,   // €1.00/hour (partial hours round up)
        'daily_cap_cents' => 0,     // 0 = no cap
    ],

    // TextMeBot WhatsApp gateway (optional). Leave api_key empty to disable.
    'textmebot' => [
        'api_key'  => '',
        'endpoint' => 'https://api.textmebot.com/send.php',
    ],

    'app' => [
        'base_url'                  => 'https://your-domain.example',
        'pin_ttl_after_pay_minutes' => 15,
        'cashier_auto_reset_seconds' => 8,
        // Default UI language (en|it). Users can switch; cookie remembers.
        'default_lang' => 'en',
    ],
];
