-- Idempotent upgrade: run on an existing parking DB to add the new tables
-- and columns introduced by the totem + subscription + admin features.
-- Safe to re-run; uses INFORMATION_SCHEMA guards via stored procedures.

USE parking;

-- New tables (no IF NOT EXISTS issues — bare CREATE if missing) ---------------

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

-- parking_sessions: add customer_id, entry_channel, delivery_channel ----------

DROP PROCEDURE IF EXISTS parking_apply_upgrade;
DELIMITER //
CREATE PROCEDURE parking_apply_upgrade()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parking_sessions' AND COLUMN_NAME = 'customer_id'
    ) THEN
        ALTER TABLE parking_sessions
            ADD COLUMN customer_id INT UNSIGNED NULL AFTER cashmatic_transaction_id,
            ADD KEY idx_session_customer (customer_id),
            ADD CONSTRAINT fk_session_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parking_sessions' AND COLUMN_NAME = 'entry_channel'
    ) THEN
        ALTER TABLE parking_sessions
            ADD COLUMN entry_channel ENUM('gate','totem','api') NOT NULL DEFAULT 'gate' AFTER customer_email;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parking_sessions' AND COLUMN_NAME = 'delivery_channel'
    ) THEN
        ALTER TABLE parking_sessions
            ADD COLUMN delivery_channel ENUM('print','whatsapp','email','none') NOT NULL DEFAULT 'print' AFTER entry_channel;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gate_events' AND COLUMN_NAME = 'subscription_id'
    ) THEN
        ALTER TABLE gate_events
            ADD COLUMN subscription_id INT UNSIGNED NULL AFTER session_id,
            ADD KEY idx_subscription (subscription_id),
            ADD CONSTRAINT fk_event_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;
    END IF;

    -- Expand the event_type ENUM to include new values
    ALTER TABLE gate_events MODIFY COLUMN event_type ENUM(
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
    ) NOT NULL;
END //
DELIMITER ;

CALL parking_apply_upgrade();
DROP PROCEDURE parking_apply_upgrade;
