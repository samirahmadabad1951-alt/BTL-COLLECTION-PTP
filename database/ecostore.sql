-- =====================================================
-- ECOSTORE DATABASE — COMPLETE SCHEMA (v3 - Seller Flow Fully Fixed)
-- =====================================================

CREATE DATABASE IF NOT EXISTS ecostore;
USE ecostore;

-- =====================================================
-- 1. USERS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role ENUM('user', 'admin', 'seller') DEFAULT 'user',
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    reset_token VARCHAR(255),
    reset_expires DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- =====================================================
-- 2. PRODUCTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NULL,
    submitted_by_role ENUM('admin', 'seller') DEFAULT 'admin',
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(200) UNIQUE NOT NULL,
    tagline VARCHAR(300),
    description TEXT,
    currency ENUM('TZS', 'USD', 'EUR', 'KES') DEFAULT 'TZS',
    price DECIMAL(10,2) NOT NULL COMMENT 'Final price (seller_price + admin_markup)',
    seller_price DECIMAL(10,2) NULL,
    admin_markup DECIMAL(10,2) DEFAULT 0,
    image VARCHAR(500),
    images JSON NULL,
    video_url VARCHAR(500) NULL,
    category VARCHAR(100),
    eco_score INT DEFAULT 0 CHECK (eco_score BETWEEN 0 AND 10),
    carbon_saved DECIMAL(10,2) DEFAULT 0,
    labels JSON,
    seller VARCHAR(200) NOT NULL,
    certified BOOLEAN DEFAULT TRUE,
    rating DECIMAL(3,2) DEFAULT 0,
    reviews_count INT DEFAULT 0,
    materials JSON,
    impact TEXT,
    stock INT DEFAULT 0,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_eco_score (eco_score),
    INDEX idx_status (status),
    INDEX idx_seller (seller_id),
    FULLTEXT INDEX idx_search (name, tagline, description)
);

-- =====================================================
-- 3. CART TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    session_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart (user_id, product_id),
    INDEX idx_user (user_id),
    INDEX idx_session (session_id)
);

-- =====================================================
-- 4. ORDERS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    user_id INT,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    customer_address TEXT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    total_carbon_saved DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50),
    tax_amount DECIMAL(10,2) DEFAULT 0,
    admin_commission DECIMAL(10,2) DEFAULT 0,
    seller_amount DECIMAL(10,2) DEFAULT 0,
    payment_transaction_id VARCHAR(100) NULL,
    paid_at TIMESTAMP NULL,
    status ENUM('pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded') DEFAULT 'pending',
    tracking_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_number (order_number),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

-- =====================================================
-- 5. ORDER ITEMS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    carbon_saved DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_order (order_id)
);

-- =====================================================
-- 6. WISHLIST TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_wishlist (user_id, product_id),
    INDEX idx_user (user_id)
);

-- =====================================================
-- 7. REVIEWS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title VARCHAR(200),
    comment TEXT,
    verified_purchase BOOLEAN DEFAULT FALSE,
    helpful_count INT DEFAULT 0,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
);

-- =====================================================
-- 8. CONTACT MESSAGES TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    status ENUM('unread', 'read', 'replied') DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- =====================================================
-- 9. NEWSLETTER SUBSCRIBERS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unsubscribe_token VARCHAR(255),
    INDEX idx_email (email)
);

-- =====================================================
-- 10. ACTIVITY LOGS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);

-- =====================================================
-- 11. SELLER APPLICATIONS TABLE
-- Holds PENDING and APPROVED applications.
-- When rejected:  row moves to seller_rejected_history (deleted here).
-- When revoked:   row moves to seller_rejected_history (deleted here).
-- attempt_count   = how many times this user has ever submitted an application
--                   (incremented on each new submission, including re-applications).
-- =====================================================
CREATE TABLE IF NOT EXISTS seller_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    brand_name VARCHAR(200) NOT NULL,
    email VARCHAR(100) NOT NULL,
    country VARCHAR(100),
    website VARCHAR(255),
    categories JSON,
    sustainability_description TEXT,
    certification_file VARCHAR(500),
    rejection_reason TEXT NULL,
    attempt_count INT DEFAULT 1 COMMENT 'Total application attempts by this user (ever)',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    submitted_by INT NULL,
    reviewed_by INT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- =====================================================
-- 12. PRODUCT MEDIA TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS product_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255),
    file_size INT,
    mime_type VARCHAR(100),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_type (media_type)
);

-- =====================================================
-- 13. PAYMENT TRANSACTIONS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(100) UNIQUE NOT NULL,
    order_id INT NULL,
    user_id INT NULL,
    currency ENUM('TZS', 'USD', 'EUR', 'KES') DEFAULT 'TZS',
    amount DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50) NOT NULL,
    payment_reference VARCHAR(100),
    phone_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'pending',
    payment_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL
);

-- =====================================================
-- 14. SELLER PAYOUTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS seller_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    order_id INT NULL,
    currency ENUM('TZS', 'USD', 'EUR', 'KES') DEFAULT 'TZS',
    amount DECIMAL(10,2) NOT NULL,
    admin_commission DECIMAL(10,2) DEFAULT 0,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50),
    payout_reference VARCHAR(100),
    status VARCHAR(20) DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
);

-- =====================================================
-- 15. TAX SETTINGS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS tax_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tax_name VARCHAR(100) DEFAULT 'VAT',
    tax_rate DECIMAL(5,2) DEFAULT 18.00,
    is_active TINYINT(1) DEFAULT 1,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
INSERT IGNORE INTO tax_settings (tax_name, tax_rate, is_active) VALUES ('VAT', 18.00, 1);

-- =====================================================
-- 16. SELLER ACCOUNTS TABLE
-- A seller exists here ONLY while approved.
-- Removed = no longer a seller (role reverted to 'user').
-- =====================================================
CREATE TABLE IF NOT EXISTS seller_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    application_id INT NULL,
    email VARCHAR(100) NOT NULL DEFAULT '',
    brand_name VARCHAR(200),
    country VARCHAR(100),
    website VARCHAR(255),
    categories JSON,
    sustainability_description TEXT,
    certification_file VARCHAR(500),
    approved_at TIMESTAMP NULL,
    approved_by INT NULL,
    status ENUM('active', 'suspended', 'banned') DEFAULT 'active',
    currency ENUM('TZS', 'USD', 'EUR', 'KES') DEFAULT 'TZS',
    mpesa_phone VARCHAR(20),
    tigo_phone VARCHAR(20),
    airtel_phone VARCHAR(20),
    bank_account VARCHAR(100),
    bank_name VARCHAR(100),
    pending_balance DECIMAL(10,2) DEFAULT 0,
    available_balance DECIMAL(10,2) DEFAULT 0,
    total_earned DECIMAL(10,2) DEFAULT 0,
    total_withdrawn DECIMAL(10,2) DEFAULT 0,
    currency_withdrawal ENUM('TZS', 'USD', 'EUR', 'KES') DEFAULT 'TZS',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status)
);

-- =====================================================
-- 17. SELLER REJECTED HISTORY TABLE
-- ONE row per user (UNIQUE on user_id).
-- rejection_count    = how many times rejected OR revoked (hard limit: 5 = blocked).
-- total_applications = total times the user has ever submitted an application.
-- is_blocked         = TRUE when rejection_count >= 5 (cannot re-apply through normal flow).
-- Admin can always re-approve regardless of is_blocked.
-- When admin re-approves from rejected: row is KEPT for history (not deleted).
-- =====================================================
CREATE TABLE IF NOT EXISTS seller_rejected_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    application_id INT NULL,
    email VARCHAR(100) NOT NULL,
    brand_name VARCHAR(200),
    country VARCHAR(100),
    website VARCHAR(255),
    categories JSON,
    sustainability_description TEXT,
    certification_file VARCHAR(500),
    rejection_reason TEXT,
    rejection_count INT DEFAULT 1 COMMENT 'Times rejected or revoked (max 5 = blocked)',
    total_applications INT DEFAULT 1 COMMENT 'Total times user has ever submitted an application',
    is_blocked BOOLEAN DEFAULT FALSE COMMENT 'TRUE when rejection_count >= 5',
    first_rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_reapplied_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_rejection (user_id),
    INDEX idx_email (email),
    INDEX idx_blocked (is_blocked)
);

-- =====================================================
-- 18. SITE SETTINGS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS site_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- INSERT ADMIN USER
-- Password: Theflash@1950
-- =====================================================
DELETE FROM users WHERE email = 'samirahmadabad1950@gmail.com';
INSERT INTO users (name, email, password, phone, address, role, email_verified) VALUES
('Admin User', 'samirahmadabad1950@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+255764005707', 'Dar es Salaam, Tanzania', 'admin', TRUE);

-- Insert default site settings
INSERT IGNORE INTO site_settings (`key`, `value`) VALUES
('admin_email',   'samirahmadabad1950@gmail.com'),
('admin_phone',   '+255764005707'),
('admin_address', 'Dar es Salaam, Tanzania'),
('support_hours', 'Mon-Fri, 9am-6pm EAT'),
('site_name',     'EcoStore');




---later


-- =====================================================
-- MIGRATION: Add currency column to products table
-- Run this ONCE in phpMyAdmin → SQL tab, or MySQL terminal
-- Safe for MySQL 5.7 and MySQL 8.0
-- =====================================================

-- Step 1: Add the column only if it doesn't already exist
-- (Uses a safe procedure approach for MySQL 5.7 compatibility)

SET @dbname = DATABASE();
SET @tablename = 'products';
SET @columnname = 'currency';

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME   = @tablename
    AND COLUMN_NAME  = @columnname
);

SET @query := IF(
  @exist = 0,
  'ALTER TABLE products ADD COLUMN currency ENUM(''TZS'',''USD'',''KES'') NOT NULL DEFAULT ''TZS'' AFTER price',
  'SELECT ''currency column already exists, nothing to do'' AS message'
);

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Set all existing rows to TZS
UPDATE products SET currency = 'TZS' WHERE currency IS NULL OR currency = '';

-- Step 3: Confirm
SELECT 'Migration complete.' AS status;
SELECT id, name, price, currency FROM products LIMIT 10;
