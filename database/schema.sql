
-- =========================================================
-- ANGanWADI STOCK MANAGEMENT SYSTEM
-- Database: anganwadi_stock
-- Compatible with MySQL / XAMPP
-- =========================================================

CREATE DATABASE IF NOT EXISTS anganwadi_stock
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE anganwadi_stock;

-- =========================================================
-- 1. ROLES
-- =========================================================

CREATE TABLE IF NOT EXISTS roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(30) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (role_name, description) VALUES
('WORKER', 'Anganwadi Worker'),
('HELPER', 'Anganwadi Helper'),
('SUPERVISOR', 'Supervises assigned Anganwadi centres'),
('CDPO', 'Child Development Project Officer'),
('ADMIN', 'System Administrator');


-- =========================================================
-- 2. PROJECTS
-- =========================================================

CREATE TABLE IF NOT EXISTS projects (
    project_id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(150) NOT NULL,
    project_code VARCHAR(50) NOT NULL UNIQUE,
    district VARCHAR(100) NOT NULL DEFAULT 'Anand',
    taluka VARCHAR(100),
    address VARCHAR(255),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- =========================================================
-- 3. ANGANWADI CENTRES
-- =========================================================

CREATE TABLE IF NOT EXISTS anganwadi_centres (
    centre_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    centre_code VARCHAR(50) NOT NULL UNIQUE,
    centre_name VARCHAR(150) NOT NULL,
    village VARCHAR(100) NOT NULL,
    address VARCHAR(255),
    contact_number VARCHAR(20),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_centre_project
        FOREIGN KEY (project_id)
        REFERENCES projects(project_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_centres_project (project_id),
    INDEX idx_centres_village (village)
) ENGINE=InnoDB;


-- =========================================================
-- 4. USERS
-- =========================================================

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    project_id INT NULL,
    centre_id INT NULL,

    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    mobile_number VARCHAR(20) UNIQUE,
    email VARCHAR(150) UNIQUE,

    password_hash VARCHAR(255) NOT NULL,

    preferred_language ENUM('en', 'gu') NOT NULL DEFAULT 'gu',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_user_role
        FOREIGN KEY (role_id)
        REFERENCES roles(role_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_user_project
        FOREIGN KEY (project_id)
        REFERENCES projects(project_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_user_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_users_role (role_id),
    INDEX idx_users_project (project_id),
    INDEX idx_users_centre (centre_id),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB;


-- =========================================================
-- 5. CENTRE ASSIGNMENTS
-- Allows supervisors and other users to access assigned
-- centres. Users may have more than one assignment.
-- =========================================================

CREATE TABLE IF NOT EXISTS centre_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    centre_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    CONSTRAINT fk_assignment_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_assignment_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_assignment_admin
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    UNIQUE KEY uq_user_centre (user_id, centre_id),
    INDEX idx_assignment_centre_active (centre_id, is_active)
) ENGINE=InnoDB;


-- =========================================================
-- 6. FOOD ITEMS
-- =========================================================

CREATE TABLE IF NOT EXISTS food_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL UNIQUE,
    item_name_en VARCHAR(150) NOT NULL,
    item_name_gu VARCHAR(150) NOT NULL,
    unit ENUM('kg', 'gram', 'litre', 'ml', 'piece', 'packet')
        NOT NULL DEFAULT 'kg',

    default_min_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    expiry_tracking TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_food_min_stock
        CHECK (default_min_stock >= 0),

    INDEX idx_food_active (is_active),
    INDEX idx_food_name_en (item_name_en),
    INDEX idx_food_name_gu (item_name_gu)
) ENGINE=InnoDB;

INSERT IGNORE INTO food_items
(item_code, item_name_en, item_name_gu, unit, default_min_stock, expiry_tracking)
VALUES
('RICE', 'Rice', 'ચોખા', 'kg', 10.000, 1),
('WHEAT', 'Wheat', 'ઘઉં', 'kg', 10.000, 1),
('TOOR_DAL', 'Toor Dal', 'તુવેર દાળ', 'kg', 5.000, 1),
('OIL', 'Cooking Oil', 'ખાદ્ય તેલ', 'litre', 3.000, 1);


-- =========================================================
-- 7. CENTRE-SPECIFIC MINIMUM STOCK SETTINGS
-- =========================================================

CREATE TABLE IF NOT EXISTS centre_item_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    centre_id INT NOT NULL,
    item_id INT NOT NULL,
    minimum_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_item_setting_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_item_setting_food
        FOREIGN KEY (item_id)
        REFERENCES food_items(item_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_item_setting_user
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_centre_minimum_stock
        CHECK (minimum_stock >= 0),

    UNIQUE KEY uq_centre_food_item (centre_id, item_id)
) ENGINE=InnoDB;


-- =========================================================
-- 8. STOCK BATCHES
-- Each batch belongs to one centre and one food item.
-- =========================================================

CREATE TABLE IF NOT EXISTS stock_batches (
    batch_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    centre_id INT NOT NULL,
    item_id INT NOT NULL,
    batch_number VARCHAR(100) NOT NULL,

    received_date DATE NOT NULL,
    expiry_date DATE NULL,
    supplier_name VARCHAR(150),

    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_batch_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_batch_item
        FOREIGN KEY (item_id)
        REFERENCES food_items(item_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_batch_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    UNIQUE KEY uq_batch_centre_item_number
        (centre_id, item_id, batch_number),

    INDEX idx_batch_expiry (expiry_date),
    INDEX idx_batch_centre_item (centre_id, item_id)
) ENGINE=InnoDB;


-- =========================================================
-- 9. STOCK TRANSACTIONS
-- All receipts, consumption and distribution are recorded
-- here. Do not store a manually editable stock balance.
-- =========================================================

CREATE TABLE IF NOT EXISTS stock_transactions (
    transaction_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Supports retry-safe offline/PWA synchronization
    client_transaction_id CHAR(36) NULL UNIQUE,

    centre_id INT NOT NULL,
    item_id INT NOT NULL,
    batch_id BIGINT NULL,
    created_by INT NOT NULL,

    transaction_type ENUM(
        'RECEIPT',
        'CONSUMPTION',
        'DISTRIBUTION',
        'ADJUSTMENT'
    ) NOT NULL,

    quantity DECIMAL(12,3) NOT NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    beneficiary_type ENUM(
        'CHILD',
        'PREGNANT_WOMAN',
        'LACTATING_MOTHER',
        'OTHER'
    ) NULL,

    notes TEXT,

    status ENUM('PENDING', 'APPROVED', 'REJECTED')
        NOT NULL DEFAULT 'PENDING',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_transaction_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_transaction_item
        FOREIGN KEY (item_id)
        REFERENCES food_items(item_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_transaction_batch
        FOREIGN KEY (batch_id)
        REFERENCES stock_batches(batch_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_transaction_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_transaction_quantity
        CHECK (quantity > 0),

    INDEX idx_transaction_centre_date (centre_id, transaction_date),
    INDEX idx_transaction_item (item_id),
    INDEX idx_transaction_batch (batch_id),
    INDEX idx_transaction_status_date (status, transaction_date),
    INDEX idx_transaction_creator (created_by)
) ENGINE=InnoDB;


-- =========================================================
-- 10. STOCK VERIFICATIONS
-- Preserves verification decisions and comments.
-- =========================================================

CREATE TABLE IF NOT EXISTS stock_verifications (
    verification_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NOT NULL,
    verified_by INT NOT NULL,

    decision ENUM('APPROVED', 'REJECTED') NOT NULL,
    comments TEXT,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_verification_transaction
        FOREIGN KEY (transaction_id)
        REFERENCES stock_transactions(transaction_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_verification_user
        FOREIGN KEY (verified_by)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_verification_transaction (transaction_id),
    INDEX idx_verification_user_date (verified_by, verified_at)
) ENGINE=InnoDB;


-- =========================================================
-- 11. NOTIFICATIONS
-- Low-stock, near-expiry and expired-stock alerts.
-- =========================================================

CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    centre_id INT NULL,
    item_id INT NULL,
    batch_id BIGINT NULL,
    user_id INT NULL,

    notification_type ENUM(
        'LOW_STOCK',
        'NEAR_EXPIRY',
        'EXPIRED',
        'PENDING_VERIFICATION',
        'SYSTEM'
    ) NOT NULL,

    title_en VARCHAR(200) NOT NULL,
    title_gu VARCHAR(200) NOT NULL,
    message_en TEXT,
    message_gu TEXT,

    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_centre
        FOREIGN KEY (centre_id)
        REFERENCES anganwadi_centres(centre_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_notification_item
        FOREIGN KEY (item_id)
        REFERENCES food_items(item_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_notification_batch
        FOREIGN KEY (batch_id)
        REFERENCES stock_batches(batch_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_notification_user_read (user_id, is_read, created_at),
    INDEX idx_notification_centre (centre_id, created_at)
) ENGINE=InnoDB;


-- =========================================================
-- 12. AUDIT LOGS
-- Tracks important actions performed by users.
-- =========================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,

    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id VARCHAR(100),

    old_values JSON NULL,
    new_values JSON NULL,

    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    INDEX idx_audit_user_date (user_id, created_at),
    INDEX idx_audit_table_record (table_name, record_id)
) ENGINE=InnoDB;


-- =========================================================
-- SCHEMA CREATION COMPLETE
-- Create your first ADMIN account separately using PHP
-- password_hash(). Never store a plain-text password.
-- =========================================================
