-- Parking gate database schema (MySQL 5.7+ / MariaDB 10.4+)

CREATE DATABASE IF NOT EXISTS parking
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE parking;

-- Customers (regular subscribers + occasional totem visitors who left contact info)
CREATE TABLE IF NOT EXISTS customers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subscription plans (weekly / monthly / annual)
CREATE TABLE IF NOT EXISTS subscription_plans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Active subscriptions tied to a customer; key_code = electronic key the customer presents
CREATE TABLE IF NOT EXISTS subscriptions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment schedule per subscription period (one row per due period)
CREATE TABLE IF NOT EXISTS subscription_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Runtime settings overrides (dot-notation keys: textmebot.api_key,
-- mailer.smtp_host, tariff.hourly_cents, app.default_lang, etc.).
-- Read at request start to overlay config/config.php defaults so admins
-- can change WhatsApp/Email/Tariff/App settings without editing PHP files.
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(80) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Editable WhatsApp / Email message templates with placeholder substitution
-- (e.g. {pin}, {entry_time}, {customer_name}). One row per channel+event.
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('whatsapp','email') NOT NULL,
    event_key VARCHAR(60) NOT NULL,
    subject VARCHAR(200) NULL,
    body TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_channel_event (channel, event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin / operator users for the dashboard
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NULL,
    role ENUM('admin','operator') NOT NULL DEFAULT 'admin',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Occasional sessions (entry-PIN flow + totem flow). Now also tracks linked
-- customer (for totem repeat visitors) and entry/delivery channel.
CREATE TABLE IF NOT EXISTS parking_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pin CHAR(6) NOT NULL,
    entered_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    exited_at DATETIME NULL,
    amount_cents INT UNSIGNED NULL,
    cashmatic_transaction_id INT NULL,
    customer_id INT UNSIGNED NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(160) NULL,
    entry_channel ENUM('gate','totem','api') NOT NULL DEFAULT 'gate',
    delivery_channel ENUM('print','whatsapp','email','none') NOT NULL DEFAULT 'print',
    status ENUM('active','paid','exited','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pin_status (pin, status),
    KEY idx_status (status),
    KEY idx_entered (entered_at),
    KEY idx_paid (paid_at),
    KEY idx_session_customer (customer_id),
    CONSTRAINT fk_session_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gate_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NULL,
    subscription_id INT UNSIGNED NULL,
    pin CHAR(6) NULL,
    event_type ENUM(
        'entry',
        'scan_at_pay',
        'payment_start',
        'payment_ok',
        'payment_fail',
        'scan_at_exit',
        'gate_open',
        'denied',
        'whatsapp_sent',
        'whatsapp_fail',
        'email_sent',
        'email_fail',
        'subscription_entry',
        'subscription_exit',
        'subscription_payment',
        'admin_login',
        'admin_logout',
        'admin_action'
    ) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_session (session_id),
    KEY idx_subscription (subscription_id),
    KEY idx_event (event_type),
    KEY idx_created (created_at),
    CONSTRAINT fk_event_session FOREIGN KEY (session_id)
        REFERENCES parking_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_sub FOREIGN KEY (subscription_id)
        REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
