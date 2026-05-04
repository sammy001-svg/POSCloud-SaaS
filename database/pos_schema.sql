-- ============================================================
-- MULTI-TENANT POS SAAS PLATFORM — DATABASE SCHEMA
-- ============================================================
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Database setup (Omitted for cPanel compatibility. Please create database via cPanel interface first)

-- ============================================================
-- PLATFORM LAYER
-- ============================================================

CREATE TABLE `platform_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `platform_settings` (`setting_key`, `setting_value`) VALUES
('platform_name', 'POSCloud'),
('platform_email', 'admin@poscloud.com'),
('currency', 'KES'),
('currency_symbol', 'KSh'),
('timezone', 'Africa/Nairobi'),
('logo_path', ''),
('grace_period_days', '7'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from', '');

CREATE TABLE `subscription_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(100) NOT NULL,
  `plan_type` enum('reseller','client') NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `max_branches` int DEFAULT NULL COMMENT 'NULL = unlimited',
  `max_terminals` int DEFAULT NULL,
  `max_users` int DEFAULT NULL,
  `max_products` int DEFAULT NULL,
  `features` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `subscription_plans` (`plan_name`,`plan_type`,`price`,`billing_cycle`,`max_branches`,`max_terminals`,`max_users`,`max_products`) VALUES
('Starter','client',2500.00,'monthly',1,2,5,500),
('Growth','client',5000.00,'monthly',3,10,20,2000),
('Enterprise','client',12000.00,'monthly',NULL,NULL,NULL,NULL),
('Reseller Basic','reseller',8000.00,'monthly',NULL,NULL,50,NULL),
('Reseller Pro','reseller',20000.00,'monthly',NULL,NULL,200,NULL);

-- ============================================================
-- SUPER ADMIN
-- ============================================================

CREATE TABLE `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `admins` (`name`,`email`,`password`) VALUES
('Super Admin','admin@poscloud.com','$2y$12$eRpzRKlz8X3Q6NuJHwVOAe9Vl5TFcBZxmQsIoU7DPfhKWnzLpZT6i');
-- Default password: Admin@1234

-- ============================================================
-- RESELLER LAYER
-- ============================================================

CREATE TABLE `resellers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `company_name` varchar(191) NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `brand_color_primary` varchar(20) DEFAULT '#10b981',
  `brand_color_secondary` varchar(20) DEFAULT '#1e3a8a',
  `brand_sidebar_color` varchar(20) DEFAULT '#111827',
  `brand_text_color` varchar(20) DEFAULT '#ffffff',
  `smtp_host` varchar(191) DEFAULT NULL,
  `smtp_port` int DEFAULT NULL,
  `smtp_user` varchar(191) DEFAULT NULL,
  `smtp_pass` varchar(191) DEFAULT NULL,
  `smtp_from_email` varchar(191) DEFAULT NULL,
  `smtp_from_name` varchar(191) DEFAULT NULL,
  `custom_domain` varchar(191) DEFAULT NULL,
  `slug` varchar(100) NOT NULL,
  `plan_id` int DEFAULT NULL,
  `subscription_status` enum('active','suspended','trial','expired') DEFAULT 'trial',
  `trial_ends_at` date DEFAULT NULL,
  `subscription_ends_at` date DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `commission_rate` decimal(5,2) DEFAULT 0.00 COMMENT 'platform commission %',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `slug` (`slug`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `resellers_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `reseller_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reseller_id` int NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `billing_cycle` enum('monthly','yearly') DEFAULT 'monthly',
  `max_branches` int DEFAULT NULL,
  `max_terminals` int DEFAULT NULL,
  `max_users` int DEFAULT NULL,
  `max_products` int DEFAULT NULL,
  `features` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `reseller_id` (`reseller_id`),
  CONSTRAINT `reseller_plans_reseller` FOREIGN KEY (`reseller_id`) REFERENCES `resellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CLIENT / TENANT LAYER
-- ============================================================

CREATE TABLE `tenants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `reseller_id` int DEFAULT NULL COMMENT 'NULL = direct platform client',
  `business_name` varchar(191) NOT NULL,
  `business_type` enum('supermarket','pharmacy','wine_shop','retail','restaurant','wholesale','other') DEFAULT 'retail',
  `contact_name` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `country` varchar(60) DEFAULT 'Kenya',
  `currency` varchar(10) DEFAULT 'KES',
  `currency_symbol` varchar(5) DEFAULT 'KSh',
  `logo_path` varchar(255) DEFAULT NULL,
  `receipt_header` text DEFAULT NULL,
  `receipt_footer` text DEFAULT NULL,
  `tax_name` varchar(50) DEFAULT 'VAT',
  `tax_rate` decimal(5,2) DEFAULT 16.00,
  `plan_id` int DEFAULT NULL,
  `reseller_plan_id` int DEFAULT NULL,
  `subscription_status` enum('active','suspended','trial','expired') DEFAULT 'trial',
  `trial_ends_at` date DEFAULT NULL,
  `subscription_ends_at` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `email` (`email`),
  KEY `reseller_id` (`reseller_id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `tenants_reseller` FOREIGN KEY (`reseller_id`) REFERENCES `resellers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenants_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tenant_branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `manager_user_id` int DEFAULT NULL,
  `is_main` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  CONSTRAINT `branches_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tenant_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int DEFAULT NULL COMMENT 'NULL = all branches',
  `name` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('owner','manager','cashier','inventory') NOT NULL DEFAULT 'cashier',
  `avatar` varchar(255) DEFAULT NULL,
  `pin` varchar(10) DEFAULT NULL COMMENT 'Quick PIN for POS',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `tenant_id` (`tenant_id`),
  KEY `branch_id` (`branch_id`),
  CONSTRAINT `tenant_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `tenant_branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BILLING
-- ============================================================

CREATE TABLE `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_type` enum('platform_to_reseller','platform_to_client','reseller_to_client') NOT NULL,
  `from_entity_type` enum('platform','reseller') NOT NULL,
  `from_entity_id` int DEFAULT NULL,
  `to_entity_type` enum('reseller','client') NOT NULL,
  `to_entity_id` int NOT NULL,
  `plan_id` int DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'KES',
  `status` enum('draft','sent','paid','overdue','cancelled') DEFAULT 'sent',
  `due_date` date NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('mpesa','bank','cash','card','other') DEFAULT 'cash',
  `reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PRODUCT SYSTEM (GENERIC)
-- ============================================================

CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sort_order` int DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `units_of_measure` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `abbreviation` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `unit_id` int DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `buying_price` decimal(12,2) DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` decimal(12,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT NULL COMMENT 'NULL = use tenant default',
  `discount_rate` decimal(5,2) DEFAULT 0.00,
  `track_stock` tinyint(1) DEFAULT 1,
  `allow_negative_stock` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `products_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_barcodes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `barcode` varchar(100) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `barcode` (`barcode`),
  CONSTRAINT `barcodes_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_variants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `variant_name` varchar(100) NOT NULL COMMENT 'e.g. 500ml, Red, 10mg',
  `sku` varchar(100) DEFAULT NULL,
  `buying_price` decimal(12,2) DEFAULT NULL,
  `selling_price` decimal(12,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_attributes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `attr_key` varchar(100) NOT NULL COMMENT 'e.g. expiry_date, alcohol_pct, dosage',
  `attr_value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `attributes_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INVENTORY
-- ============================================================

CREATE TABLE `stock_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity` decimal(12,3) DEFAULT 0.000,
  `low_stock_threshold` decimal(12,3) DEFAULT 5.000,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_product_variant` (`branch_id`,`product_id`,`variant_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `movement_type` enum('purchase','sale','transfer_in','transfer_out','adjustment','return','opening') NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `reference_id` int DEFAULT NULL COMMENT 'sale_id or po_id',
  `reference_type` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `product_id` (`product_id`),
  KEY `branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(191) NOT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00 COMMENT 'amount owed to supplier',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `po_number` varchar(50) NOT NULL,
  `status` enum('draft','ordered','received','partial','cancelled') DEFAULT 'draft',
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `ordered_at` date DEFAULT NULL,
  `received_at` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `branch_id` (`branch_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `purchase_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `po_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity_ordered` decimal(12,3) NOT NULL,
  `quantity_received` decimal(12,3) DEFAULT 0.000,
  `unit_cost` decimal(12,2) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `po_items_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CUSTOMERS & LOYALTY
-- ============================================================

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `credit_balance` decimal(12,2) DEFAULT 0.00,
  `loyalty_points` decimal(12,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `credit_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `sale_id` int DEFAULT NULL,
  `transaction_type` enum('credit_sale','payment','adjustment') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- POS TERMINAL & SALES
-- ============================================================

CREATE TABLE `pos_terminals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `terminal_name` varchar(100) NOT NULL,
  `terminal_code` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_terminal_code` (`tenant_id`,`terminal_code`),
  KEY `branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sales_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `terminal_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `opening_cash` decimal(12,2) DEFAULT 0.00,
  `closing_cash` decimal(12,2) DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `cash_variance` decimal(12,2) DEFAULT NULL,
  `total_sales` decimal(12,2) DEFAULT 0.00,
  `total_transactions` int DEFAULT 0,
  `status` enum('open','closed') DEFAULT 'open',
  `opened_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `closed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `terminal_id` int DEFAULT NULL,
  `session_id` int DEFAULT NULL,
  `sale_number` varchar(50) NOT NULL,
  `customer_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) DEFAULT 0.00,
  `change_given` decimal(12,2) DEFAULT 0.00,
  `sale_type` enum('sale','refund','credit_sale','layaway') DEFAULT 'sale',
  `status` enum('completed','pending','voided','on_hold') DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `receipt_printed` tinyint(1) DEFAULT 0,
  `synced` tinyint(1) DEFAULT 1 COMMENT '0 = from offline, not yet synced',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_sale_number` (`tenant_id`,`sale_number`),
  KEY `branch_id` (`branch_id`),
  KEY `customer_id` (`customer_id`),
  KEY `session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sale_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `product_name` varchar(255) NOT NULL COMMENT 'snapshot at time of sale',
  `sku` varchar(100) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_rate` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_price` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sale_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `payment_method` enum('cash','mpesa','card','bank','credit','loyalty','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference` varchar(191) DEFAULT NULL COMMENT 'M-Pesa code, card last4, etc.',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `sale_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DISCOUNTS & TAXES
-- ============================================================

CREATE TABLE `discount_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
  `value` decimal(10,2) NOT NULL,
  `applies_to` enum('all','category','product','customer_group') DEFAULT 'all',
  `reference_id` int DEFAULT NULL,
  `min_purchase` decimal(12,2) DEFAULT 0.00,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
