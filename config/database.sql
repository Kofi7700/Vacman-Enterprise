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
  `role` enum('admin','warehouse','sales_clerk','manager') NOT NULL DEFAULT 'sales_clerk',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`)
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

-- Seed users. Passwords below are bcrypt hashes of the demo passwords
-- shown on the login page. CHANGE THESE after first login in production.
--   admin      / Admin@123     (role: admin)
--   manager1   / Manager@123   (role: manager)
--   warehouse1 / Warehouse@123 (role: warehouse)
--   clerk1     / Clerk@123     (role: sales_clerk)
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`) VALUES
(1, 'admin', '$2y$10$Ho0DwqZTd28N9.wW7UN8QeZwRlus3rHR76R8ZgWMT2Ie17d3zlRZC', 'System Administrator', 'admin@yellowman.com.gh', '+233 246526228', 'admin'),
(2, 'manager1', '$2y$10$Ztlsk2egTzioVxXvEqkLWOBLepYGYFKMWMpWqHrmDdA1Uoy0AYvSu', 'Kwame Mensah', 'manager1@yellowman.com.gh', '+233 246000001', 'manager'),
(3, 'warehouse1', '$2y$10$sXkNFV5eVKpwZLXhlqCtDug.XqoRHdU0sTSsPWaWVGs40YmIfPXIy', 'Ama Boateng', 'warehouse1@yellowman.com.gh', '+233 246000002', 'warehouse'),
(4, 'clerk1', '$2y$10$5O/fqle5B0wJ7g/PMIxb7upmFnlMKiARH34aGMZ.jjDsDsk7a8JPK', 'Yaw Owusu', 'clerk1@yellowman.com.gh', '+233 246000003', 'sales_clerk')
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

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
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','mobile_money','card','credit') NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CALL _add_fk_if_missing('sale_items', 'sale_items_ibfk_1',
  'ALTER TABLE `sale_items` ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE');
CALL _add_fk_if_missing('sale_items', 'sale_items_ibfk_2',
  'ALTER TABLE `sale_items` ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE');

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
