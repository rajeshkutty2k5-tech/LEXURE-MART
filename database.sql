-- ============================================================
-- LUXE MART — MySQL Database Schema
-- Run this file: mysql -u root -p < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS luxemart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE luxemart;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(150) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    phone        VARCHAR(20),
    role         ENUM('customer','admin','vendor') DEFAULT 'customer',
    avatar       VARCHAR(255),
    address      JSON,
    token        VARCHAR(100),
    token_expires DATETIME,
    status       ENUM('active','blocked') DEFAULT 'active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_token (token)
);

-- Seed admin user (password: admin123)
INSERT INTO users (name, email, password, role) VALUES (
    'Super Admin', 'admin@luxemart.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);

-- ============================================================
-- CATEGORIES
-- ============================================================
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    icon        VARCHAR(10),
    image       VARCHAR(255),
    description TEXT,
    sort_order  INT DEFAULT 0,
    status      ENUM('active','hidden') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO categories (name, slug, icon, sort_order) VALUES
    ('Electronics', 'electronics', '📱', 1),
    ('Fashion', 'fashion', '👔', 2),
    ('Home & Living', 'home', '🏠', 3),
    ('Beauty', 'beauty', '💄', 4),
    ('Sports', 'sports', '⚽', 5),
    ('Books', 'books', '📚', 6),
    ('Toys', 'toys', '🧸', 7),
    ('Food', 'food', '🍕', 8);

-- ============================================================
-- PRODUCTS
-- ============================================================
CREATE TABLE products (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    slug           VARCHAR(200) UNIQUE,
    description    TEXT,
    price          DECIMAL(10,2) NOT NULL,
    original_price DECIMAL(10,2),
    category_id    INT UNSIGNED,
    stock          INT DEFAULT 0,
    sku            VARCHAR(50) UNIQUE,
    badge          ENUM('new','sale','hot','featured') DEFAULT NULL,
    thumbnail      VARCHAR(255),
    status         ENUM('active','draft','deleted') DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_price (price),
    FULLTEXT idx_search (name, description)
);

CREATE TABLE product_images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    url        VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_variants (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    name       VARCHAR(50) NOT NULL,  -- e.g. Size, Color
    value      VARCHAR(50) NOT NULL,  -- e.g. XL, Red
    price_diff DECIMAL(10,2) DEFAULT 0,
    stock      INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Seed sample products
INSERT INTO products (name, slug, description, price, original_price, category_id, stock, badge, thumbnail) VALUES
    ('Premium Wireless Headphones', 'wireless-headphones', 'High-quality sound with active noise cancellation', 3999, 6999, 1, 50, 'hot', NULL),
    ('Designer Silk Kurta', 'silk-kurta', 'Elegant traditional wear for all occasions', 2499, 4999, 2, 30, 'new', NULL),
    ('Yoga Mat Premium', 'yoga-mat', 'Non-slip premium yoga mat for all exercises', 1299, 1999, 5, 100, NULL, NULL),
    ('Smart LED Lamp', 'smart-led-lamp', 'App-controlled LED lamp with 16M colors', 999, 1599, 3, 75, 'sale', NULL),
    ('Natural Face Serum', 'face-serum', '100% natural ingredients for glowing skin', 1799, 2499, 4, 45, 'hot', NULL),
    ('JavaScript Mastery Book', 'js-book', 'Complete guide to modern JavaScript', 599, 899, 6, 200, 'new', NULL),
    ('Running Shoes Pro', 'running-shoes', 'Lightweight performance running shoes', 4999, 7999, 5, 60, 'sale', NULL),
    ('Ceramic Coffee Mug Set', 'coffee-mug-set', 'Set of 4 handcrafted ceramic mugs', 899, 1299, 3, 80, NULL, NULL);

-- ============================================================
-- ORDERS
-- ============================================================
CREATE TABLE orders (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    order_number     VARCHAR(30) UNIQUE NOT NULL,
    subtotal         DECIMAL(10,2) NOT NULL,
    shipping         DECIMAL(10,2) DEFAULT 0,
    tax              DECIMAL(10,2) DEFAULT 0,
    total            DECIMAL(10,2) NOT NULL,
    discount         DECIMAL(10,2) DEFAULT 0,
    coupon_code      VARCHAR(30),
    status           ENUM('pending','processing','shipped','delivered','cancelled','refunded') DEFAULT 'pending',
    payment_method   ENUM('card','upi','netbanking','cod','wallet') DEFAULT 'card',
    payment_status   ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    shipping_address JSON,
    notes            TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

CREATE TABLE order_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id   INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty        INT NOT NULL,
    price      DECIMAL(10,2) NOT NULL,
    variant    VARCHAR(100),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE order_tracking (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id   INT UNSIGNED NOT NULL,
    status     VARCHAR(100) NOT NULL,
    message    TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

-- ============================================================
-- CART (server-side)
-- ============================================================
CREATE TABLE cart (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty        INT DEFAULT 1,
    variant    VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cart_item (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- WISHLIST
-- ============================================================
CREATE TABLE wishlist (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_wish (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- REVIEWS
-- ============================================================
CREATE TABLE reviews (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    rating     TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title      VARCHAR(200),
    body       TEXT,
    status     ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_review (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- COUPONS
-- ============================================================
CREATE TABLE coupons (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(30) UNIQUE NOT NULL,
    type       ENUM('percent','fixed') NOT NULL,
    value      DECIMAL(10,2) NOT NULL,
    min_order  DECIMAL(10,2) DEFAULT 0,
    max_uses   INT,
    used_count INT DEFAULT 0,
    expiry     DATETIME,
    status     ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO coupons (code, type, value, min_order, max_uses, expiry) VALUES
    ('SAVE20', 'percent', 20, 500, 500, '2025-12-31'),
    ('FIRST50', 'fixed', 50, 299, 200, '2025-12-31'),
    ('WELCOME10', 'percent', 10, 0, NULL, '2025-12-31');

-- ============================================================
-- NEWSLETTER
-- ============================================================
CREATE TABLE newsletter (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(150) UNIQUE NOT NULL,
    status     ENUM('subscribed','unsubscribed') DEFAULT 'subscribed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SETTINGS
-- ============================================================
CREATE TABLE settings (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) UNIQUE NOT NULL,
    value TEXT
);

INSERT INTO settings (`key`, value) VALUES
    ('store_name', 'Luxe Mart'),
    ('store_email', 'admin@luxemart.com'),
    ('currency', 'INR'),
    ('currency_symbol', '₹'),
    ('free_shipping_above', '499'),
    ('tax_rate', '18'),
    ('maintenance_mode', '0');
