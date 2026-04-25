<?php
/**
 * Idempotent migrator for the parking database.
 * Run from the project root:   php bin/migrate.php
 *
 * Safe to re-run any time: every step is guarded against existing state.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $cfg['db']['host'], $cfg['db']['name'], $cfg['db']['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function colExists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $col]);
    return (bool) $stmt->fetchColumn();
}

function fkExists(PDO $pdo, string $table, string $name): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_TYPE = "FOREIGN KEY" AND CONSTRAINT_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $name]);
    return (bool) $stmt->fetchColumn();
}

function step(string $msg): void { echo $msg . "\n"; }

step('-- creating new tables (IF NOT EXISTS)');

$pdo->exec(
"CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(160) NULL,
    phone VARCHAR(32) NULL,
    plate VARCHAR(20) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cust_email (email),
    KEY idx_cust_phone (phone),
    KEY idx_cust_plate (plate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec(
"CREATE TABLE IF NOT EXISTS subscription_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    period ENUM('weekly','monthly','annual') NOT NULL,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_plan_code (code),
    KEY idx_plan_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec(
"CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    key_code VARCHAR(40) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('active','suspended','expired','cancelled') NOT NULL DEFAULT 'active',
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sub_key (key_code),
    KEY idx_sub_customer (customer_id),
    KEY idx_sub_plan (plan_id),
    KEY idx_sub_status (status),
    KEY idx_sub_ends (ends_on),
    CONSTRAINT fk_sub_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec(
"CREATE TABLE IF NOT EXISTS subscription_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    due_on DATE NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    paid_at DATETIME NULL,
    method ENUM('cash','card','bank','other') NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_subpay_sub (subscription_id),
    KEY idx_subpay_due (due_on),
    KEY idx_subpay_paid (paid_at),
    CONSTRAINT fk_subpay_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec(
"CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NULL,
    role ENUM('admin','operator') NOT NULL DEFAULT 'admin',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

step('-- patching parking_sessions');

if (!colExists($pdo, 'parking_sessions', 'customer_id')) {
    $pdo->exec("ALTER TABLE parking_sessions ADD COLUMN customer_id INT UNSIGNED NULL AFTER cashmatic_transaction_id");
    $pdo->exec("ALTER TABLE parking_sessions ADD KEY idx_session_customer (customer_id)");
    if (!fkExists($pdo, 'parking_sessions', 'fk_session_customer')) {
        $pdo->exec("ALTER TABLE parking_sessions ADD CONSTRAINT fk_session_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL");
    }
    step('   added customer_id');
}
if (!colExists($pdo, 'parking_sessions', 'entry_channel')) {
    $pdo->exec("ALTER TABLE parking_sessions ADD COLUMN entry_channel ENUM('gate','totem','api') NOT NULL DEFAULT 'gate' AFTER customer_email");
    step('   added entry_channel');
}
if (!colExists($pdo, 'parking_sessions', 'delivery_channel')) {
    $pdo->exec("ALTER TABLE parking_sessions ADD COLUMN delivery_channel ENUM('print','whatsapp','email','none') NOT NULL DEFAULT 'print' AFTER entry_channel");
    step('   added delivery_channel');
}

// Widen contact columns to match new schema (was 20/120)
$pdo->exec("ALTER TABLE parking_sessions MODIFY COLUMN customer_phone VARCHAR(32) NULL");
$pdo->exec("ALTER TABLE parking_sessions MODIFY COLUMN customer_email VARCHAR(160) NULL");

step('-- patching gate_events');

if (!colExists($pdo, 'gate_events', 'subscription_id')) {
    $pdo->exec("ALTER TABLE gate_events ADD COLUMN subscription_id INT UNSIGNED NULL AFTER session_id");
    $pdo->exec("ALTER TABLE gate_events ADD KEY idx_subscription (subscription_id)");
    if (!fkExists($pdo, 'gate_events', 'fk_event_sub')) {
        $pdo->exec("ALTER TABLE gate_events ADD CONSTRAINT fk_event_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL");
    }
    step('   added subscription_id');
}

$pdo->exec(
"ALTER TABLE gate_events MODIFY COLUMN event_type ENUM(
    'entry','scan_at_pay','payment_start','payment_ok','payment_fail',
    'scan_at_exit','gate_open','denied','whatsapp_sent','whatsapp_fail',
    'email_sent','email_fail','subscription_entry','subscription_exit',
    'subscription_payment','admin_login','admin_logout','admin_action'
) NOT NULL");
step('   widened event_type ENUM');

step("\nDONE. tables now:");
foreach ($pdo->query("SHOW TABLES") as $r) echo "  - " . $r[0] . "\n";
