-- =====================================================================
-- Vacman Enterprise Inventory System — database.sql
-- Business: Yellowman Ventures
--
-- This is the schema from vacman_enterprise_system.sql, unchanged,
-- plus seed data so the application is usable immediately after import.
--
-- Import in phpMyAdmin, or from a terminal:
--   mysql -u root -p < database.sql
--
-- Safe to re-import on top of itself — table creation uses
-- IF NOT EXISTS, seed rows use ON DUPLICATE KEY UPDATE, and the foreign
-- key section at the bottom checks information_schema before adding
-- each constraint, so running this file twice will not error.
--
-- Demo logins created below (change these after first login):
--   admin      / Admin@123     (role: admin)
--   manager1   / Manager@123   (role: manager)
--   minimanager1 / MiniManager@123 (role: mini_manager)
--   cashier1   / Cashier@123   (role: cashier)
--   warehouse1 / Warehouse@123 (role: warehouse)
--   clerk1     / Clerk@123     (role: sales_clerk)
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `vacman_enterprise_system`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `vacman_enterprise_system`;

-- --------------------------------------------------------
-- Table structure for table `audit_log`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_timestamp` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `categories`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_category_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `categories` (`id`, `name`, `description`, `parent_category_id`) VALUES
(1, 'Building Materials', 'Construction and building supplies', NULL),
(2, 'Electrical Materials & Appliances', 'Wiring, sockets, bulbs, fans, etc.', NULL),
(3, 'Household Appliances', 'Kitchen and home appliances', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- --------------------------------------------------------
-- Table structure for table `stores`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `stores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `location` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `manager_id` (`manager_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `stores` (`id`, `name`, `location`, `address`, `contact_phone`, `manager_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Yellowman Main Store', 'Sogakope Zongo', 'Police Station Road, Sogakope, Ghana', '+233 246526228', NULL, 'active', '2026-01-11 18:12:02', '2026-01-11 18:12:02')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `role` enum('admin','manager','mini_manager','cashier','warehouse','sales_clerk','staff') NOT NULL DEFAULT 'sales_clerk',
  `roles` varchar(255) DEFAULT NULL,
  `store_id` int(11) DEFAULT NULL,
  `status` enum('active','frozen') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `store_id` (`store_id`),
  KEY `idx_user_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One-time migration: older installs of this file created `users` without
-- a `photo` column. Add it in place so an upgrade doesn't need a manual
-- ALTER TABLE and existing user rows/data are left untouched.
DELIMITER $$
CREATE PROCEDURE `_add_users_photo_column`()
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo';
  IF col_exists = 0 THEN
    ALTER TABLE `users` ADD COLUMN `photo` varchar(255) DEFAULT NULL AFTER `phone`;
  END IF;
END$$
DELIMITER ;
CALL _add_users_photo_column();
DROP PROCEDURE `_add_users_photo_column`;

-- Add account status to existing installations without changing current users.
DELIMITER $$
CREATE PROCEDURE `_add_users_status_column`()
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status';
  IF col_exists = 0 THEN
    ALTER TABLE `users`
      ADD COLUMN `status` enum('active','frozen') NOT NULL DEFAULT 'active' AFTER `role`,
      ADD KEY `idx_user_status` (`status`);
  END IF;
END$$
DELIMITER ;
CALL _add_users_status_column();
DROP PROCEDURE `_add_users_status_column`;

-- Add the cashier role to existing installations.
ALTER TABLE `users` MODIFY COLUMN `role`
  enum('admin','manager','mini_manager','cashier','warehouse','sales_clerk','staff') NOT NULL DEFAULT 'sales_clerk';

-- Store every assigned role as a comma-separated set while retaining
-- `role` as the primary/legacy role for compatibility with older installs.
DELIMITER $$
CREATE PROCEDURE `_add_users_roles_column`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'roles') THEN
    ALTER TABLE `users` ADD COLUMN `roles` varchar(255) DEFAULT NULL AFTER `role`;
  END IF;
  UPDATE `users` SET `roles` = `role` WHERE `roles` IS NULL OR `roles` = '';
END$$
DELIMITER ;
CALL _add_users_roles_column();
DROP PROCEDURE `_add_users_roles_column`;

-- Assign operational users to a store. Existing non-management users are
-- assigned to the first active store and can be corrected in User Management.
DELIMITER $$
CREATE PROCEDURE `_add_users_store_column`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'store_id') THEN
    ALTER TABLE `users` ADD COLUMN `store_id` int(11) DEFAULT NULL AFTER `role`, ADD KEY `store_id` (`store_id`);
    UPDATE `users`
      SET `store_id` = (SELECT `id` FROM `stores` WHERE `status` = 'active' ORDER BY `id` LIMIT 1)
      WHERE `role` IN ('mini_manager','cashier','warehouse','sales_clerk');
  END IF;
END$$
DELIMITER ;
CALL _add_users_store_column();
DROP PROCEDURE `_add_users_store_column`;

-- Seed users. Passwords below are bcrypt hashes of the demo passwords
-- shown on the login page. CHANGE THESE after first login in production.
--   admin      / Admin@123     (role: admin)
--   manager1   / Manager@123   (role: manager)
--   minimanager1 / MiniManager@123 (role: mini_manager)
--   cashier1   / Cashier@123   (role: cashier)
--   warehouse1 / Warehouse@123 (role: warehouse)
--   clerk1     / Clerk@123     (role: sales_clerk)
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `roles`, `store_id`) VALUES
(1, 'admin', '$2y$10$Ho0DwqZTd28N9.wW7UN8QeZwRlus3rHR76R8ZgWMT2Ie17d3zlRZC', 'System Administrator', 'admin@yellowman.com.gh', '+233 246526228', 'admin', 'admin', NULL),
(2, 'manager1', '$2y$10$Ztlsk2egTzioVxXvEqkLWOBLepYGYFKMWMpWqHrmDdA1Uoy0AYvSu', 'Kwame Mensah', 'manager1@yellowman.com.gh', '+233 246000001', 'manager', 'manager', NULL),
(3, 'warehouse1', '$2y$10$sXkNFV5eVKpwZLXhlqCtDug.XqoRHdU0sTSsPWaWVGs40YmIfPXIy', 'Ama Boateng', 'warehouse1@yellowman.com.gh', '+233 246000002', 'warehouse', 'warehouse', 1),
(4, 'clerk1', '$2y$10$5O/fqle5B0wJ7g/PMIxb7upmFnlMKiARH34aGMZ.jjDsDsk7a8JPK', 'Yaw Owusu', 'clerk1@yellowman.com.gh', '+233 246000003', 'sales_clerk', 'sales_clerk', 1),
(5, 'cashier1', '$2y$10$xQ5J5a9krrnfhflbazXPFOjWfR7YPjBGgDCNm4aJvFZgSiEXai06a', 'Akosua Asare', 'cashier1@yellowman.com.gh', '+233 246000004', 'cashier', 'cashier', 1),
(6, 'minimanager1', '$2y$10$tK2xbrFol1JHfy8kqEFbAuQwyCemRI7ZpPmeJfDlyFJds7ucPODFe', 'Store Two Supervisor', 'minimanager1@yellowman.com.gh', '+233 246000005', 'mini_manager', 'mini_manager', 2)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), roles = COALESCE(NULLIF(roles, ''), VALUES(roles));

UPDATE `stores` SET `manager_id` = 2 WHERE `id` = 1;

-- --------------------------------------------------------
-- Table structure for table `products`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL COMMENT 'Stock Keeping Unit',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `supplier_name` varchar(100) DEFAULT NULL,
  `supplier_contact` varchar(100) DEFAULT NULL,
  `reorder_level` int(11) NOT NULL DEFAULT 10,
  `min_stock` int(11) NOT NULL DEFAULT 5,
  `max_stock` int(11) NOT NULL DEFAULT 100,
  `warranty_months` int(11) DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('active','discontinued','out_of_stock') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `idx_sku` (`sku`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `products` (`id`, `sku`, `name`, `description`, `category_id`, `barcode`, `unit_price`, `cost_price`, `supplier_name`, `reorder_level`, `min_stock`, `max_stock`, `warranty_months`, `status`) VALUES
(1, 'YB-CM-001', 'Cement (50kg)', 'Dangote 50kg cement bag - Building Materials', 1, '6001234500011', 65.00, 52.00, 'ABC Building Supplies', 15, 10, 300, 0, 'active'),
(2, 'YB-IR-001', 'Iron Rod (12mm)', '12mm reinforcement iron rod - Building Materials', 1, '6001234500028', 95.00, 78.00, 'ABC Building Supplies', 20, 10, 200, 0, 'active'),
(3, 'YB-EW-001', 'Electrical Wire (50m roll)', '2.5mm PVC electrical wire, 50m roll - Electrical', 2, '6001234500035', 340.00, 280.00, 'PowerLine Distributors', 10, 5, 60, 12, 'active'),
(4, 'YB-SO-001', 'Socket Outlet (13A)', '13A single socket outlet - Electrical', 2, '6001234500042', 22.00, 15.00, 'PowerLine Distributors', 15, 8, 150, 6, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- --------------------------------------------------------
-- Table structure for table `inventory`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `reserved_quantity` int(11) NOT NULL DEFAULT 0,
  `last_received_date` date DEFAULT NULL,
  `last_sold_date` date DEFAULT NULL,
  `last_physical_count` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_store` (`product_id`,`store_id`),
  KEY `store_id` (`store_id`),
  KEY `idx_quantity` (`quantity`),
  KEY `idx_last_activity` (`last_received_date`,`last_sold_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory` (`product_id`, `store_id`, `quantity`, `last_received_date`) VALUES
(1, 1, 120, '2026-08-01'),
(2, 1, 8, '2026-07-20'),
(3, 1, 25, '2026-08-15'),
(4, 1, 6, '2026-08-15')
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','warning','danger','success') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `purchases`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `purchase_order_number` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL,
  `total_cost` decimal(10,2) NOT NULL,
  `received_quantity` int(11) NOT NULL DEFAULT 0,
  `purchase_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `status` enum('ordered','delivered','partial','cancelled') DEFAULT 'ordered',
  `received_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `store_id` (`store_id`),
  KEY `received_by` (`received_by`),
  KEY `idx_purchase_date` (`purchase_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `sales`
-- --------------------------------------------------------

-- `sales` is the transaction header — one row per checkout, whether it
-- covers one product or a whole cart of them. The line items themselves
-- (which products, how many, at what price) live in `sale_items` below.
CREATE TABLE IF NOT EXISTS `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `subtotal_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `balance_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','mobile_money','card','credit') NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_status` enum('pending','approved') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `corrected_by` int(11) DEFAULT NULL,
  `corrected_at` datetime DEFAULT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_transaction` (`transaction_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `approved_by` (`approved_by`),
  KEY `corrected_by` (`corrected_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add payment approval tracking while preserving existing sales as approved.
DELIMITER $$
CREATE PROCEDURE `_add_sales_payment_approval`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'payment_status') THEN
    ALTER TABLE `sales`
      ADD COLUMN `payment_status` enum('pending','approved') NOT NULL DEFAULT 'approved' AFTER `transaction_id`,
      ADD COLUMN `approved_by` int(11) DEFAULT NULL AFTER `payment_status`,
      ADD COLUMN `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
      ADD KEY `idx_payment_status` (`payment_status`),
      ADD KEY `approved_by` (`approved_by`);
    ALTER TABLE `sales` MODIFY COLUMN `payment_status` enum('pending','approved') NOT NULL DEFAULT 'pending';
  END IF;
END$$
DELIMITER ;
CALL _add_sales_payment_approval();
DROP PROCEDURE `_add_sales_payment_approval`;

-- Track the administrator or manager responsible for receipt corrections.
DELIMITER $$
CREATE PROCEDURE `_add_sales_correction_tracking`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'corrected_by') THEN
    ALTER TABLE `sales`
      ADD COLUMN `corrected_by` int(11) DEFAULT NULL AFTER `approved_at`,
      ADD COLUMN `corrected_at` datetime DEFAULT NULL AFTER `corrected_by`,
      ADD KEY `corrected_by` (`corrected_by`);
  END IF;
END$$
DELIMITER ;
CALL _add_sales_correction_tracking();
DROP PROCEDURE `_add_sales_correction_tracking`;

-- Add checkout totals to existing installations. Existing sales retain
-- their original total and have no outstanding balance.
DELIMITER $$
CREATE PROCEDURE `_add_sales_checkout_totals`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'subtotal_amount') THEN
    ALTER TABLE `sales`
      ADD COLUMN `subtotal_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `customer_phone`,
      ADD COLUMN `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `subtotal_amount`,
      ADD COLUMN `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`,
      ADD COLUMN `balance_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;
    UPDATE `sales` SET `subtotal_amount` = `total_amount`;
  END IF;
END$$
DELIMITER ;
CALL _add_sales_checkout_totals();
DROP PROCEDURE `_add_sales_checkout_totals`;

-- Older versions briefly stored an amount-paid field. Payment approval now
-- represents full payment, so the separate field is no longer needed.
DELIMITER $$
CREATE PROCEDURE `_remove_sales_amount_paid`()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'amount_paid') THEN
    ALTER TABLE `sales` DROP COLUMN `amount_paid`;
  END IF;
END$$
DELIMITER ;
CALL _remove_sales_amount_paid();
DROP PROCEDURE `_remove_sales_amount_paid`;

-- --------------------------------------------------------
-- Table structure for table `sale_items`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- One-time migration: older installs of this file created `sales` with
-- product_id/quantity/unit_price directly on the header (one product per
-- sale). If that shape is still present, move each row into `sale_items`
-- as a single line item, then drop the now-redundant columns, so a
-- database that already has real sales history upgrades in place instead
-- of losing data.
-- --------------------------------------------------------

DELIMITER $$
CREATE PROCEDURE `_migrate_sales_to_line_items`()
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  DECLARE old_fk VARCHAR(64) DEFAULT NULL;

  SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'product_id';

  IF col_exists > 0 THEN
    INSERT INTO `sale_items` (`sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`)
      SELECT `id`, `product_id`, `quantity`, `unit_price`, `total_amount` FROM `sales`;

    SELECT CONSTRAINT_NAME INTO old_fk FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sales'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'sales_ibfk_1'
      LIMIT 1;
    IF old_fk IS NOT NULL THEN
      ALTER TABLE `sales` DROP FOREIGN KEY `sales_ibfk_1`;
    END IF;

    ALTER TABLE `sales` DROP COLUMN `product_id`, DROP COLUMN `quantity`, DROP COLUMN `unit_price`;
  END IF;
END$$
DELIMITER ;

CALL _migrate_sales_to_line_items();
DROP PROCEDURE `_migrate_sales_to_line_items`;

-- --------------------------------------------------------
-- Table structure for table `machine_fee_payments`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `machine_fee_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `collected_by` int(11) NOT NULL,
  `services` varchar(255) NOT NULL,
  `payer_name` varchar(100) NOT NULL,
  `payer_phone` varchar(20) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','mobile_money','card') NOT NULL,
  `transaction_id` varchar(100) NOT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `store_id` (`store_id`),
  KEY `collected_by` (`collected_by`),
  KEY `idx_machine_fee_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `attendance`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `store_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_attendance_date` (`user_id`,`attendance_date`),
  KEY `store_id` (`store_id`),
  KEY `idx_attendance_date` (`attendance_date`),
  KEY `idx_clock_out` (`clock_out`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `deposits`
-- --------------------------------------------------------

-- Replace the short-lived sales-payment deposit model when it is still
-- empty. Deposit orders now exist before a sale and move stock on collection.
DELIMITER $$
CREATE PROCEDURE `_upgrade_empty_legacy_deposits`()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deposits') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deposits' AND COLUMN_NAME = 'customer_name')
       AND (SELECT COUNT(*) FROM `deposits`) = 0 THEN
      DROP TABLE `deposits`;
    END IF;
  END IF;
END$$
DELIMITER ;
CALL _upgrade_empty_legacy_deposits();
DROP PROCEDURE `_upgrade_empty_legacy_deposits`;

CREATE TABLE IF NOT EXISTS `deposits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `completed_sale_id` int(11) DEFAULT NULL,
  `deposit_number` varchar(40) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','collected','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `collected_at` datetime DEFAULT NULL,
  `collected_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deposit_number` (`deposit_number`),
  KEY `store_id` (`store_id`),
  KEY `created_by` (`created_by`),
  KEY `completed_sale_id` (`completed_sale_id`),
  KEY `collected_by` (`collected_by`),
  KEY `idx_deposit_status` (`status`),
  KEY `idx_deposit_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deposit_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deposit_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deposit_product` (`deposit_id`,`product_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deposit_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deposit_id` int(11) NOT NULL,
  `received_by` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','mobile_money','card','bank_transfer','cheque') NOT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `deposit_id` (`deposit_id`),
  KEY `received_by` (`received_by`),
  KEY `idx_deposit_payment_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `transfers`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `from_store_id` int(11) NOT NULL,
  `to_store_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `transfer_date` date DEFAULT curdate(),
  `status` enum('pending','approved','in_transit','completed','cancelled') DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `delivered_by` int(11) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `from_store_id` (`from_store_id`),
  KEY `to_store_id` (`to_store_id`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`),
  KEY `delivered_by` (`delivered_by`),
  KEY `received_by` (`received_by`),
  KEY `idx_status` (`status`),
  KEY `idx_transfer_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Foreign key constraints (added last, after all tables exist)
--
-- Wrapped in a check against information_schema so this file can be
-- re-imported on top of itself without erroring — constraint names are
-- unique per schema, so a plain ADD CONSTRAINT fails the second time
-- it's run against a database that already has them.
-- --------------------------------------------------------

DELIMITER $$
CREATE PROCEDURE `_add_fk_if_missing`(IN tbl VARCHAR(64), IN cname VARCHAR(64), IN ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND CONSTRAINT_NAME = cname
  ) THEN
    SET @ddl_sql = ddl;
    PREPARE stmt FROM @ddl_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL _add_fk_if_missing('audit_log', 'audit_log_ibfk_1',
  'ALTER TABLE `audit_log` ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');

CALL _add_fk_if_missing('users', 'users_ibfk_1',
  'ALTER TABLE `users` ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT');

CALL _add_fk_if_missing('categories', 'categories_ibfk_1',
  'ALTER TABLE `categories` ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE');

CALL _add_fk_if_missing('inventory', 'inventory_ibfk_1',
  'ALTER TABLE `inventory` ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('inventory', 'inventory_ibfk_2',
  'ALTER TABLE `inventory` ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE');

CALL _add_fk_if_missing('notifications', 'notifications_ibfk_1',
  'ALTER TABLE `notifications` ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE');

CALL _add_fk_if_missing('products', 'products_ibfk_1',
  'ALTER TABLE `products` ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE');

CALL _add_fk_if_missing('purchases', 'purchases_ibfk_1',
  'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('purchases', 'purchases_ibfk_2',
  'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('purchases', 'purchases_ibfk_3',
  'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

CALL _add_fk_if_missing('sales', 'sales_ibfk_2',
  'ALTER TABLE `sales` ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('sales', 'sales_ibfk_3',
  'ALTER TABLE `sales` ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('sales', 'sales_ibfk_4',
  'ALTER TABLE `sales` ADD CONSTRAINT `sales_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
CALL _add_fk_if_missing('sales', 'sales_ibfk_5',
  'ALTER TABLE `sales` ADD CONSTRAINT `sales_ibfk_5` FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

CALL _add_fk_if_missing('sale_items', 'sale_items_ibfk_1',
  'ALTER TABLE `sale_items` ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('sale_items', 'sale_items_ibfk_2',
  'ALTER TABLE `sale_items` ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE');

CALL _add_fk_if_missing('machine_fee_payments', 'machine_fee_payments_ibfk_1',
  'ALTER TABLE `machine_fee_payments` ADD CONSTRAINT `machine_fee_payments_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT');
CALL _add_fk_if_missing('machine_fee_payments', 'machine_fee_payments_ibfk_2',
  'ALTER TABLE `machine_fee_payments` ADD CONSTRAINT `machine_fee_payments_ibfk_2` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT');

CALL _add_fk_if_missing('attendance', 'attendance_ibfk_1',
  'ALTER TABLE `attendance` ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('attendance', 'attendance_ibfk_2',
  'ALTER TABLE `attendance` ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL');

CALL _add_fk_if_missing('deposits', 'deposits_ibfk_1',
  'ALTER TABLE `deposits` ADD CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT');
CALL _add_fk_if_missing('deposits', 'deposits_ibfk_2',
  'ALTER TABLE `deposits` ADD CONSTRAINT `deposits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT');
CALL _add_fk_if_missing('deposits', 'deposits_ibfk_3',
  'ALTER TABLE `deposits` ADD CONSTRAINT `deposits_ibfk_3` FOREIGN KEY (`completed_sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL');
CALL _add_fk_if_missing('deposits', 'deposits_ibfk_4',
  'ALTER TABLE `deposits` ADD CONSTRAINT `deposits_ibfk_4` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
CALL _add_fk_if_missing('deposit_items', 'deposit_items_ibfk_1',
  'ALTER TABLE `deposit_items` ADD CONSTRAINT `deposit_items_ibfk_1` FOREIGN KEY (`deposit_id`) REFERENCES `deposits` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('deposit_items', 'deposit_items_ibfk_2',
  'ALTER TABLE `deposit_items` ADD CONSTRAINT `deposit_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT');
CALL _add_fk_if_missing('deposit_payments', 'deposit_payments_ibfk_1',
  'ALTER TABLE `deposit_payments` ADD CONSTRAINT `deposit_payments_ibfk_1` FOREIGN KEY (`deposit_id`) REFERENCES `deposits` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('deposit_payments', 'deposit_payments_ibfk_2',
  'ALTER TABLE `deposit_payments` ADD CONSTRAINT `deposit_payments_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT');

CALL _add_fk_if_missing('stores', 'stores_ibfk_1',
  'ALTER TABLE `stores` ADD CONSTRAINT `stores_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');

CALL _add_fk_if_missing('transfers', 'transfers_ibfk_1',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_2',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_2` FOREIGN KEY (`from_store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_3',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_3` FOREIGN KEY (`to_store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_4',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_5',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_6',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_6` FOREIGN KEY (`delivered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
CALL _add_fk_if_missing('transfers', 'transfers_ibfk_7',
  'ALTER TABLE `transfers` ADD CONSTRAINT `transfers_ibfk_7` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

DROP PROCEDURE `_add_fk_if_missing`;

COMMIT;
