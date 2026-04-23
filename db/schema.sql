-- Parking gate database schema (MySQL 5.7+ / MariaDB 10.4+)

CREATE DATABASE IF NOT EXISTS parking
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE parking;

CREATE TABLE IF NOT EXISTS parking_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pin CHAR(6) NOT NULL,
    entered_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    exited_at DATETIME NULL,
    amount_cents INT UNSIGNED NULL,
    cashmatic_transaction_id INT NULL,
    customer_phone VARCHAR(20) NULL,
    customer_email VARCHAR(120) NULL,
    status ENUM('active','paid','exited','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pin_status (pin, status),
    KEY idx_status (status),
    KEY idx_entered (entered_at),
    KEY idx_paid (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gate_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NULL,
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
        'whatsapp_fail'
    ) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_session (session_id),
    KEY idx_event (event_type),
    KEY idx_created (created_at),
    CONSTRAINT fk_event_session FOREIGN KEY (session_id)
        REFERENCES parking_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
