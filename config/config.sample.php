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

    // EFT-POS via RTS Web DoReMi POS 2.0 middleware
    // (http://www.rtseng.it/) — a Windows service that wraps Italian
    // "Protocollo 17" (ECR17) and exposes a simple HTTP API to the LAN.
    //
    //   PHP on VPS ──HTTPS──► Tailscale ──HTTP──► RTS service on LAN PC
    //                                              └─Protocollo 17──► Move/3500
    //
    // Setup steps on the LAN host:
    //   1. Install RTS Web DoReMi POS 2.0 (WebDoremiposSetup.msi)
    //   2. Edit C:\Program Files (x86)\Rtseng\WebDoremipos\WebDoremipos.exe.config
    //      and add a <terminal> entry under <terminals>:
    //        <terminal name="Ingenico-93740816" Mode="Tcp"
    //                  TerminalAddress="192.164.1.26"
    //                  TerminalPort="5040"
    //                  Password="<RTS-activation-key>" />
    //      (Without the activation password, RTS clamps every amount to 2
    //      cents — fine for testing, not for production.)
    //   3. Change BaseAddress to bind to the LAN IP, e.g.
    //      http://192.164.1.21:80/WebDoremiposWS/ — and open Windows
    //      Firewall TCP 80 inbound.
    //   4. net stop WebDoremipos && net start WebDoremipos
    //   5. Verify locally: http://127.0.0.1/WebDoremiposWS/api/Status
    //      should return "Operative".
    //
    // base_url is what the VPS hits (via Tailscale-routed LAN IP).
    // terminal_name matches the name= attribute in the RTS XML config.
    'pos' => [
        'base_url'        => 'http://192.164.1.21/WebDoremiposWS',
        'terminal_name'   => 'Ingenico-93740816',
        'protocol_type'   => '0',     // '0'=auto / '1'=credit / '2'=debit
        'connect_timeout' => 5,       // seconds — cURL connect
        'read_timeout'    => 90,      // seconds — covers card-tap + acquirer auth
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
