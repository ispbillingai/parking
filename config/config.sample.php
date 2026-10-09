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

    // MQTT broker that drives the gate relays and carries scan events.
    // All three relay cards (parkingOUT / parkingIN / parkingSemaforo)
    // connect to this same broker.
    'mqtt' => [
        'host'      => '127.0.0.1',
        'port'      => 1883,
        'username'  => null,
        'password'  => null,                    // must match the relay cards' Broker Password
        'client_id' => 'parking-php',
        'use_tls'   => false,
        'topics' => [
            'relay_open' => 'parking/gate/relay',   // publish to open the gate
            'scan'       => 'parking/gate/scan',    // gate reader publishes PINs here
            'pin_add'    => '',                     // optional paid-PIN cache

            // --- Barrier control (admin Barriers page) -----------------
            // Each relay card carries an MFR prefix set in its web UI;
            // "Head slash" is enabled so topics start with "/". The
            // relayXXXXX device-id segment is auto-discovered by
            // bin/mqtt-listener.php, which watches each card's traffic and
            // stores the resolved *_control topics as settings. You only
            // set the three prefixes below.
            'exit_prefix'      => '/parkingOUT',        // exit barrier card (+ Wiegand reader)
            'entrance_prefix'  => '/parkingIN',         // entrance barrier card
            'semaforo_prefix'  => '/parkingSemaforo',   // Free/Full traffic-light card
            // Resolved control topics. The listener still re-discovers and
            // updates these as settings, but they are pre-seeded here from the
            // known device IDs (OUT=relay47041, IN=relay47040, Semaforo=relay47053)
            // so the Barriers page works before the listener has run.
            'exit_control'     => '/parkingOUT/relay47041/in/control',
            'entrance_control' => '/parkingIN/relay47040/in/control',
            'semaforo_control' => '/parkingSemaforo/relay47053/in/control',
        ],
        'relay_payload' => '1',
    ],

    // Network thermal receipt printer driven over raw TCP (port 9100).
    // entrance.php pushes the ESC/POS ticket straight here, so the
    // operator never sees a browser print dialog. Leave 'host' blank to
    // disable and fall back to the on-screen ticket only.
    'printer' => [
        'host'     => '10.1.1.52',
        'port'     => 9100,
        'timeout'  => 5,        // seconds
        'width'    => 32,       // characters per printed line
        'codepage' => 2,        // 2 = CP850 Multilingual (è à ò)
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

    // Email delivery for the totem (and future admin notifications).
    // transport=mail uses PHP mail() (works on XAMPP if sendmail is configured).
    // transport=smtp uses raw socket SMTP (no PHPMailer dependency).
    'mailer' => [
        'transport'    => 'mail',          // 'mail' | 'smtp'
        'from_email'   => 'no-reply@your-domain.example',
        'from_name'    => 'Parking',
        // smtp_* only used when transport='smtp'
        'smtp_host'    => 'smtp.example.com',
        'smtp_port'    => 587,
        'smtp_secure'  => 'tls',           // ''|'tls' (STARTTLS)|'ssl' (implicit)
        'smtp_user'    => '',
        'smtp_pass'    => '',
        'smtp_timeout' => 15,
    ],

    // Admin dashboard.
    // The first time admin_users is empty, this account is auto-provisioned.
    // Sign in once, then change/remove the bootstrap before going to production.
    'admin' => [
        'bootstrap' => [
            'username'  => 'admin',
            'password'  => 'admin',           // CHANGE_ME after first login
            'full_name' => 'Administrator',
        ],
    ],

    // ---- Card gateways (same settings and rules as the Focacciami POS) ----
    // Managed from Admin > Payment gateways: those values (settings table) win
    // over the ones below; a blank field there falls back to this file.
    // The page also picks the ACTIVE card gateway (payment.card_gateway):
    // 'pos' (Ingenico), 'dojo', 'both' (default) or 'none'. A kiosk button
    // only shows when its gateway is active AND configured.
    //
    // MONEY: amounts go to the terminals as integer CENTS.

    // ---- Ingenico card terminal -----------------------------------------
    // "Pay by card" button. Two connection modes, decided by base_url:
    //   http://…/WebDoremiposWS  mode 'rts': RTS Web DoReMi POS 2.0 service on
    //       a LAN PC wraps Protocol 17 to the terminal (http://www.rtseng.it/).
    //       terminal_name = the <terminal name="…"> in WebDoremipos.exe.config
    //       (without the RTS activation password every amount is clamped to
    //       2 cents). Check: <base_url>/api/Status answers "Operative".
    //   tcp://<terminal ip>:<port>  mode 'p17': no RTS PC, the server speaks
    //       Protocol 17 straight to the terminal (ECR line set to TCP/IP).
    //       terminal_name = terminal ID (8 digits, 00000000 = any),
    //       ecr_id = till ID. If the line drops after the terminal accepted
    //       the payment, the outcome is recovered with the G command.
    // Leave base_url empty to hide the "Pay by card" button.
    'pos' => [
        'mode'            => 'rts',     // 'rts' | 'p17' (base_url decides when it has a scheme)
        'base_url'        => 'http://192.164.1.21/WebDoremiposWS',
        'terminal_name'   => 'Ingenico-93740816',
        'ecr_id'          => '00000001', // p17 only
        'protocol_type'   => '0',       // 0 auto / 1 credit / 2 debit
        'connect_timeout' => 5,
        'read_timeout'    => 90,        // covers card tap + acquirer auth
    ],

    // ---- Dojo card terminal (Dojo Cloud API "Pay at Counter") ------------
    // "Pay by Dojo" button. Talks to Dojo's CLOUD API (api.dojo.tech), no
    // local device. Get secret_key + terminal_id from the Dojo Developer
    // Portal (NOT the dashboard login): sk_sandbox_ to test, sk_prod_ to go
    // live. reseller_id / software_house_id are REQUIRED on terminal calls
    // (sandbox: reseller1 / softwareHouse1; production values come from Dojo).
    // The secret key is write-only in the admin page (blank = keep).
    // Leave secret_key or terminal_id empty to hide the "Pay by Dojo" button.
    'dojo' => [
        'base_url'          => 'https://api.dojo.tech',
        'secret_key'        => '',                 // sk_sandbox_… / sk_prod_…
        'terminal_id'       => '',                 // Dojo terminalId (tm_…)
        'version'           => '2026-02-27',       // Dojo API version header
        'capture_mode'      => 'Auto',             // Auto = capture immediately
        'reseller_id'       => '',                 // sandbox: reseller1
        'software_house_id' => '',                 // sandbox: softwareHouse1
        'connect_timeout'   => 5,
        'read_timeout'      => 20,                 // per HTTP call; the tap itself is polled
        'poll_interval_ms'  => 1500,
        'verify_ssl'        => true,
    ],

    'app' => [
        'base_url'                  => 'https://your-domain.example',
        'pin_ttl_after_pay_minutes' => 15,
        'cashier_auto_reset_seconds' => 8,
        // Default UI language (en|it). Users can switch; cookie remembers.
        'default_lang' => 'en',
        // If 1, subscriber-entry refuses an electronic key when the related
        // subscription has any unpaid installment whose due date has passed.
        // If 0, the gate opens but the overdue is shown in the admin panel.
        'subscription_block_overdue' => 1,
    ],
];
