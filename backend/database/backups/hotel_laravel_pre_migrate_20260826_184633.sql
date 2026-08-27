-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: hotel_laravel
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `add_on_links`
--

DROP TABLE IF EXISTS `add_on_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `add_on_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `add_on_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` bigint(20) unsigned DEFAULT NULL,
  `menu_category_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `add_on_links_add_on_id_menu_item_id_menu_category_id_unique` (`add_on_id`,`menu_item_id`,`menu_category_id`),
  KEY `add_on_links_menu_item_id_index` (`menu_item_id`),
  KEY `add_on_links_menu_category_id_index` (`menu_category_id`),
  KEY `add_on_links_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `add_on_links_add_on_id_foreign` FOREIGN KEY (`add_on_id`) REFERENCES `add_ons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `add_on_links_menu_category_id_foreign` FOREIGN KEY (`menu_category_id`) REFERENCES `pos_menu_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `add_on_links_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `pos_menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `add_on_links_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `add_on_links`
--

LOCK TABLES `add_on_links` WRITE;
/*!40000 ALTER TABLE `add_on_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `add_on_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `add_ons`
--

DROP TABLE IF EXISTS `add_ons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `add_ons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `price` int(10) unsigned NOT NULL COMMENT 'LKR cents',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `stock_ingredient_id` bigint(20) unsigned DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `add_ons_stock_ingredient_id_foreign` (`stock_ingredient_id`),
  KEY `add_ons_created_by_foreign` (`created_by`),
  KEY `add_ons_updated_by_foreign` (`updated_by`),
  KEY `addon_active_idx` (`tenant_id`,`active`),
  KEY `addon_name_search_idx` (`tenant_id`,`name`),
  CONSTRAINT `add_ons_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `add_ons_stock_ingredient_id_foreign` FOREIGN KEY (`stock_ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `add_ons_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `add_ons_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `add_ons`
--

LOCK TABLES `add_ons` WRITE;
/*!40000 ALTER TABLE `add_ons` DISABLE KEYS */;
/*!40000 ALTER TABLE `add_ons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_bookings`
--

DROP TABLE IF EXISTS `apartment_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `booking_status_id` bigint(20) unsigned NOT NULL,
  `channel_id` bigint(20) unsigned NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `adults` int(10) unsigned NOT NULL DEFAULT 1,
  `children` int(10) unsigned NOT NULL DEFAULT 0,
  `nightly_rate` int(10) unsigned NOT NULL COMMENT 'LKR cents/night actually charged — snapshot, independent of later unit-type rate changes',
  `rate_basis` varchar(10) NOT NULL DEFAULT 'nightly' COMMENT 'nightly | weekly | monthly — which tier nightly_rate was resolved from',
  `deposit_due` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents — security/booking deposit',
  `notes` text DEFAULT NULL,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `checked_out_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_bookings_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `apartment_bookings_customer_id_foreign` (`customer_id`),
  KEY `apartment_bookings_channel_id_foreign` (`channel_id`),
  KEY `apartment_bookings_created_by_foreign` (`created_by`),
  KEY `apartment_bookings_updated_by_foreign` (`updated_by`),
  KEY `apartment_bookings_booking_status_id_index` (`booking_status_id`),
  KEY `apartment_bookings_unit_id_check_in_check_out_index` (`unit_id`,`check_in`,`check_out`),
  CONSTRAINT `apartment_bookings_booking_status_id_foreign` FOREIGN KEY (`booking_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_bookings_channel_id_foreign` FOREIGN KEY (`channel_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_bookings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `apartment_customers` (`id`),
  CONSTRAINT `apartment_bookings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_bookings_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `apartment_units` (`id`),
  CONSTRAINT `apartment_bookings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_bookings`
--

LOCK TABLES `apartment_bookings` WRITE;
/*!40000 ALTER TABLE `apartment_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_customers`
--

DROP TABLE IF EXISTS `apartment_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `id_number` varchar(255) DEFAULT NULL COMMENT 'NIC/passport',
  `nationality` varchar(255) DEFAULT NULL,
  `is_company` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Corporate tenant/buyer — company_name/company_reg_no apply',
  `company_name` varchar(255) DEFAULT NULL,
  `company_reg_no` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `apartment_customers_created_by_foreign` (`created_by`),
  KEY `apartment_customers_updated_by_foreign` (`updated_by`),
  KEY `apartment_customers_name_index` (`name`),
  KEY `apartment_customers_phone_index` (`phone`),
  KEY `apartment_customers_email_index` (`email`),
  KEY `apartment_customers_id_number_index` (`id_number`),
  KEY `apartment_customers_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_customers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_customers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_customers`
--

LOCK TABLES `apartment_customers` WRITE;
/*!40000 ALTER TABLE `apartment_customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_housekeeping_tasks`
--

DROP TABLE IF EXISTS `apartment_housekeeping_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_housekeeping_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `assigned_to_id` bigint(20) unsigned DEFAULT NULL,
  `task_status_id` bigint(20) unsigned NOT NULL,
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '[{item, done}] from the unit type cleaning_checklist template' CHECK (json_valid(`checklist`)),
  `notes` text DEFAULT NULL,
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `lease_id` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `apartment_housekeeping_tasks_unit_id_foreign` (`unit_id`),
  KEY `apartment_housekeeping_tasks_assigned_to_id_foreign` (`assigned_to_id`),
  KEY `apartment_housekeeping_tasks_task_status_id_foreign` (`task_status_id`),
  KEY `apartment_housekeeping_tasks_booking_id_foreign` (`booking_id`),
  KEY `apartment_housekeeping_tasks_lease_id_foreign` (`lease_id`),
  KEY `apartment_housekeeping_tasks_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_housekeeping_tasks_assigned_to_id_foreign` FOREIGN KEY (`assigned_to_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_housekeeping_tasks_booking_id_foreign` FOREIGN KEY (`booking_id`) REFERENCES `apartment_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_housekeeping_tasks_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `apartment_leases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_housekeeping_tasks_task_status_id_foreign` FOREIGN KEY (`task_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_housekeeping_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_housekeeping_tasks_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `apartment_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_housekeeping_tasks`
--

LOCK TABLES `apartment_housekeeping_tasks` WRITE;
/*!40000 ALTER TABLE `apartment_housekeeping_tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_housekeeping_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_lease_rent_charges`
--

DROP TABLE IF EXISTS `apartment_lease_rent_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_lease_rent_charges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `lease_id` bigint(20) unsigned NOT NULL,
  `period_month` date NOT NULL COMMENT 'First-of-month date identifying the billing period',
  `ledger_line_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_lease_rent_charges_lease_id_period_month_unique` (`lease_id`,`period_month`),
  KEY `apartment_lease_rent_charges_ledger_line_id_foreign` (`ledger_line_id`),
  KEY `apartment_lease_rent_charges_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_lease_rent_charges_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `apartment_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apartment_lease_rent_charges_ledger_line_id_foreign` FOREIGN KEY (`ledger_line_id`) REFERENCES `apartment_ledger_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apartment_lease_rent_charges_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_lease_rent_charges`
--

LOCK TABLES `apartment_lease_rent_charges` WRITE;
/*!40000 ALTER TABLE `apartment_lease_rent_charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_lease_rent_charges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_leases`
--

DROP TABLE IF EXISTS `apartment_leases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_leases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `lease_status_id` bigint(20) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'null = open-ended / month-to-month, runs until terminated',
  `monthly_rent` int(10) unsigned NOT NULL COMMENT 'LKR cents/month',
  `rent_due_day` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'Day of month rent is charged, 1-28',
  `security_deposit` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents',
  `notice_period_days` int(10) unsigned NOT NULL DEFAULT 30,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `signed_at` timestamp NULL DEFAULT NULL,
  `terminated_at` timestamp NULL DEFAULT NULL,
  `termination_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_leases_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `apartment_leases_customer_id_foreign` (`customer_id`),
  KEY `apartment_leases_created_by_foreign` (`created_by`),
  KEY `apartment_leases_updated_by_foreign` (`updated_by`),
  KEY `apartment_leases_lease_status_id_index` (`lease_status_id`),
  KEY `apartment_leases_unit_id_start_date_end_date_index` (`unit_id`,`start_date`,`end_date`),
  CONSTRAINT `apartment_leases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_leases_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `apartment_customers` (`id`),
  CONSTRAINT `apartment_leases_lease_status_id_foreign` FOREIGN KEY (`lease_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_leases_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_leases_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `apartment_units` (`id`),
  CONSTRAINT `apartment_leases_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_leases`
--

LOCK TABLES `apartment_leases` WRITE;
/*!40000 ALTER TABLE `apartment_leases` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_leases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_ledger_lines`
--

DROP TABLE IF EXISTS `apartment_ledger_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_ledger_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `ledger_id` bigint(20) unsigned NOT NULL,
  `line_source_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` int(11) NOT NULL COMMENT 'LKR cents — negative for discounts',
  `amount` int(11) NOT NULL COMMENT 'qty * unit_price, LKR cents — negative for discounts',
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `voided` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `apartment_ledger_lines_line_source_id_foreign` (`line_source_id`),
  KEY `apartment_ledger_lines_staff_id_foreign` (`staff_id`),
  KEY `apartment_ledger_lines_ledger_id_index` (`ledger_id`),
  KEY `apartment_ledger_lines_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_ledger_lines_ledger_id_foreign` FOREIGN KEY (`ledger_id`) REFERENCES `apartment_ledgers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apartment_ledger_lines_line_source_id_foreign` FOREIGN KEY (`line_source_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_ledger_lines_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `apartment_ledger_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_ledger_lines`
--

LOCK TABLES `apartment_ledger_lines` WRITE;
/*!40000 ALTER TABLE `apartment_ledger_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_ledger_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_ledgers`
--

DROP TABLE IF EXISTS `apartment_ledgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_ledgers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `ledger_status_id` bigint(20) unsigned NOT NULL,
  `invoice_no` varchar(255) DEFAULT NULL COMMENT 'assigned at settlement, e.g. APT-INV-2026-0012',
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `lease_id` bigint(20) unsigned DEFAULT NULL,
  `sale_id` bigint(20) unsigned DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_ledgers_booking_id_unique` (`booking_id`),
  UNIQUE KEY `apartment_ledgers_lease_id_unique` (`lease_id`),
  UNIQUE KEY `apartment_ledgers_sale_id_unique` (`sale_id`),
  UNIQUE KEY `apartment_ledgers_tenant_id_invoice_no_unique` (`tenant_id`,`invoice_no`),
  KEY `apartment_ledgers_ledger_status_id_foreign` (`ledger_status_id`),
  CONSTRAINT `apartment_ledgers_booking_id_foreign` FOREIGN KEY (`booking_id`) REFERENCES `apartment_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_ledgers_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `apartment_leases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_ledgers_ledger_status_id_foreign` FOREIGN KEY (`ledger_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_ledgers_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `apartment_sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_ledgers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_ledgers`
--

LOCK TABLES `apartment_ledgers` WRITE;
/*!40000 ALTER TABLE `apartment_ledgers` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_ledgers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_maintenance_issues`
--

DROP TABLE IF EXISTS `apartment_maintenance_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_maintenance_issues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `description` text NOT NULL,
  `maintenance_status_id` bigint(20) unsigned NOT NULL,
  `logged_by_id` bigint(20) unsigned NOT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `apartment_maintenance_issues_unit_id_foreign` (`unit_id`),
  KEY `apartment_maintenance_issues_maintenance_status_id_foreign` (`maintenance_status_id`),
  KEY `apartment_maintenance_issues_logged_by_id_foreign` (`logged_by_id`),
  KEY `apartment_maintenance_issues_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_maintenance_issues_logged_by_id_foreign` FOREIGN KEY (`logged_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `apartment_maintenance_issues_maintenance_status_id_foreign` FOREIGN KEY (`maintenance_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_maintenance_issues_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_maintenance_issues_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `apartment_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_maintenance_issues`
--

LOCK TABLES `apartment_maintenance_issues` WRITE;
/*!40000 ALTER TABLE `apartment_maintenance_issues` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_maintenance_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_payments`
--

DROP TABLE IF EXISTS `apartment_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL COMMENT 'offline-replay safety',
  `payment_kind_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` bigint(20) unsigned NOT NULL,
  `amount` int(10) unsigned NOT NULL COMMENT 'LKR cents; refunds stored positive with kind=refund',
  `reference` varchar(255) DEFAULT NULL COMMENT 'card slip no, bank ref, QR txn id',
  `reason` text DEFAULT NULL COMMENT 'mandatory for refunds — enforced in ApartmentBillingService',
  `ledger_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `till_session_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_payments_tenant_id_idempotency_key_unique` (`tenant_id`,`idempotency_key`),
  KEY `apartment_payments_payment_kind_id_foreign` (`payment_kind_id`),
  KEY `apartment_payments_payment_method_id_foreign` (`payment_method_id`),
  KEY `apartment_payments_ledger_id_foreign` (`ledger_id`),
  KEY `apartment_payments_staff_id_foreign` (`staff_id`),
  KEY `apartment_payments_created_at_index` (`created_at`),
  KEY `apartment_payments_till_session_id_foreign` (`till_session_id`),
  CONSTRAINT `apartment_payments_ledger_id_foreign` FOREIGN KEY (`ledger_id`) REFERENCES `apartment_ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_payments_payment_kind_id_foreign` FOREIGN KEY (`payment_kind_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_payments_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `apartment_payments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_payments_till_session_id_foreign` FOREIGN KEY (`till_session_id`) REFERENCES `till_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_payments`
--

LOCK TABLES `apartment_payments` WRITE;
/*!40000 ALTER TABLE `apartment_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_properties`
--

DROP TABLE IF EXISTS `apartment_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_properties_branch_id_name_unique` (`branch_id`,`name`),
  KEY `apartment_properties_created_by_foreign` (`created_by`),
  KEY `apartment_properties_updated_by_foreign` (`updated_by`),
  KEY `apartment_properties_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_properties_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_properties_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_properties_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_properties_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_properties`
--

LOCK TABLES `apartment_properties` WRITE;
/*!40000 ALTER TABLE `apartment_properties` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_properties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_sales`
--

DROP TABLE IF EXISTS `apartment_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `sale_status_id` bigint(20) unsigned NOT NULL,
  `agreed_price` int(10) unsigned NOT NULL COMMENT 'LKR cents — the full ledger total from creation',
  `reserved_until` date DEFAULT NULL COMMENT 'Option-hold expiry — auto-released if no agreement signed by then',
  `agreement_signed_at` timestamp NULL DEFAULT NULL,
  `handover_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_sales_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `apartment_sales_customer_id_foreign` (`customer_id`),
  KEY `apartment_sales_created_by_foreign` (`created_by`),
  KEY `apartment_sales_updated_by_foreign` (`updated_by`),
  KEY `apartment_sales_sale_status_id_index` (`sale_status_id`),
  KEY `apartment_sales_unit_id_index` (`unit_id`),
  CONSTRAINT `apartment_sales_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `apartment_customers` (`id`),
  CONSTRAINT `apartment_sales_sale_status_id_foreign` FOREIGN KEY (`sale_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_sales_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_sales_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `apartment_units` (`id`),
  CONSTRAINT `apartment_sales_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_sales`
--

LOCK TABLES `apartment_sales` WRITE;
/*!40000 ALTER TABLE `apartment_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_seasonal_rates`
--

DROP TABLE IF EXISTS `apartment_seasonal_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_seasonal_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `unit_type_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `rate` int(10) unsigned NOT NULL COMMENT 'Flat LKR cents/night for every date in range',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `apartment_seasonal_rates_created_by_foreign` (`created_by`),
  KEY `apartment_seasonal_rates_updated_by_foreign` (`updated_by`),
  KEY `apartment_seasonal_rates_unit_type_id_start_date_end_date_index` (`unit_type_id`,`start_date`,`end_date`),
  KEY `apartment_seasonal_rates_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_seasonal_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_seasonal_rates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_seasonal_rates_unit_type_id_foreign` FOREIGN KEY (`unit_type_id`) REFERENCES `apartment_unit_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apartment_seasonal_rates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_seasonal_rates`
--

LOCK TABLES `apartment_seasonal_rates` WRITE;
/*!40000 ALTER TABLE `apartment_seasonal_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_seasonal_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_unit_types`
--

DROP TABLE IF EXISTS `apartment_unit_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_unit_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `max_occupancy` int(10) unsigned NOT NULL DEFAULT 2,
  `bedrooms` int(10) unsigned NOT NULL DEFAULT 1,
  `bathrooms` int(10) unsigned NOT NULL DEFAULT 1,
  `size_sqft` int(10) unsigned DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `nightly_rate` int(10) unsigned DEFAULT NULL COMMENT 'LKR cents/night — null when the type is sale-only',
  `weekly_rate` int(10) unsigned DEFAULT NULL COMMENT 'LKR cents/week — falls back to nightly_rate x 7 when null',
  `monthly_rate` int(10) unsigned DEFAULT NULL COMMENT 'LKR cents/month — also the base long-term lease rent',
  `min_nights` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Minimum stay length for a short-term booking of this type',
  `cleaning_fee` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents, flat per short-term booking',
  `extra_guest_fee` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents/night per guest over max_occupancy',
  `item_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Move-in/move-out item verification template' CHECK (json_valid(`item_checklist`)),
  `cleaning_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Turnover housekeeping task template' CHECK (json_valid(`cleaning_checklist`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_unit_types_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `apartment_unit_types_created_by_foreign` (`created_by`),
  KEY `apartment_unit_types_updated_by_foreign` (`updated_by`),
  CONSTRAINT `apartment_unit_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_unit_types_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_unit_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_unit_types`
--

LOCK TABLES `apartment_unit_types` WRITE;
/*!40000 ALTER TABLE `apartment_unit_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_unit_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_units`
--

DROP TABLE IF EXISTS `apartment_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `unit_no` varchar(255) NOT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `unit_type_id` bigint(20) unsigned NOT NULL,
  `floor` varchar(255) DEFAULT NULL,
  `view` varchar(255) DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `listing_type_id` bigint(20) unsigned NOT NULL,
  `unit_status_id` bigint(20) unsigned NOT NULL,
  `sale_price` int(10) unsigned DEFAULT NULL COMMENT 'LKR cents — listing price when listing_type = sale',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_units_tenant_id_unit_no_unique` (`tenant_id`,`unit_no`),
  KEY `apartment_units_property_id_foreign` (`property_id`),
  KEY `apartment_units_unit_type_id_foreign` (`unit_type_id`),
  KEY `apartment_units_created_by_foreign` (`created_by`),
  KEY `apartment_units_updated_by_foreign` (`updated_by`),
  KEY `apartment_units_unit_status_id_index` (`unit_status_id`),
  KEY `apartment_units_listing_type_id_index` (`listing_type_id`),
  CONSTRAINT `apartment_units_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_units_listing_type_id_foreign` FOREIGN KEY (`listing_type_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_units_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `apartment_properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_units_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_units_unit_status_id_foreign` FOREIGN KEY (`unit_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `apartment_units_unit_type_id_foreign` FOREIGN KEY (`unit_type_id`) REFERENCES `apartment_unit_types` (`id`),
  CONSTRAINT `apartment_units_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_units`
--

LOCK TABLES `apartment_units` WRITE;
/*!40000 ALTER TABLE `apartment_units` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `apartment_utility_readings`
--

DROP TABLE IF EXISTS `apartment_utility_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartment_utility_readings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `lease_id` bigint(20) unsigned NOT NULL,
  `utility_type_id` bigint(20) unsigned NOT NULL,
  `period_month` date NOT NULL COMMENT 'First-of-month date identifying the billing period',
  `previous_reading` decimal(12,2) NOT NULL,
  `current_reading` decimal(12,2) NOT NULL,
  `rate_per_unit` int(10) unsigned NOT NULL COMMENT 'LKR cents per meter unit',
  `amount` int(10) unsigned NOT NULL COMMENT 'LKR cents — (current - previous) * rate_per_unit',
  `ledger_line_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apartment_utility_readings_unique_period` (`lease_id`,`utility_type_id`,`period_month`),
  KEY `apartment_utility_readings_utility_type_id_foreign` (`utility_type_id`),
  KEY `apartment_utility_readings_ledger_line_id_foreign` (`ledger_line_id`),
  KEY `apartment_utility_readings_staff_id_foreign` (`staff_id`),
  KEY `apartment_utility_readings_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `apartment_utility_readings_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `apartment_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apartment_utility_readings_ledger_line_id_foreign` FOREIGN KEY (`ledger_line_id`) REFERENCES `apartment_ledger_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_utility_readings_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `apartment_utility_readings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apartment_utility_readings_utility_type_id_foreign` FOREIGN KEY (`utility_type_id`) REFERENCES `lookups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apartment_utility_readings`
--

LOCK TABLES `apartment_utility_readings` WRITE;
/*!40000 ALTER TABLE `apartment_utility_readings` DISABLE KEYS */;
/*!40000 ALTER TABLE `apartment_utility_readings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendances`
--

DROP TABLE IF EXISTS `attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `clock_in` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `clock_out` timestamp NULL DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendances_user_id_clock_in_index` (`user_id`,`clock_in`),
  KEY `attendances_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `attendances_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendances`
--

LOCK TABLES `attendances` WRITE;
/*!40000 ALTER TABLE `attendances` DISABLE KEYS */;
INSERT INTO `attendances` VALUES (1,2,7,'2026-08-25 06:25:02','2026-08-25 15:55:02',NULL);
/*!40000 ALTER TABLE `attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `description` text DEFAULT NULL,
  `subject_type` varchar(120) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`context`)),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `route` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_actor_id_created_at_index` (`actor_id`,`created_at`),
  KEY `audit_logs_subject_type_subject_id_created_at_index` (`subject_type`,`subject_id`,`created_at`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,2,NULL,'tenant.provisioned','Tenant - Provisioned on Tenant#2.','App\\Models\\Tenant',2,'{\"admin_email\":\"admin@mountview.com\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/central/tenants','2026-08-24 14:15:24',NULL),(2,2,NULL,'tenant.admin_password_reset','Tenant - Admin Password Reset on Tenant#2.','App\\Models\\Tenant',2,'{\"email\":\"admin@mountview.com\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/central/tenants/2/reset-admin-password','2026-08-24 14:15:31',NULL),(3,2,7,'user.login','mountview Admin signed in successfully.','App\\Models\\User',7,'{\"ip\":\"205.147.17.30\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-24 19:47:19',NULL),(4,2,NULL,'branch.created','Branch - Created on Tenant#2.','App\\Models\\Tenant',2,'{\"name\":\"main\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/central/tenants/2/branches','2026-08-24 14:17:52',NULL),(5,NULL,NULL,'tenant_setting.changed','Tenant Setting - Changed on Tenant#2.','App\\Models\\Tenant',2,'{\"key\":\"theme.primary\",\"from\":\"\\\"#0462d3\\\"\",\"to\":\"\\\"#059669\\\"\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/central/tenants/2/settings/theme.primary','2026-08-24 14:20:54',NULL),(6,NULL,NULL,'tenant_setting.changed','Tenant Setting - Changed on Tenant#2.','App\\Models\\Tenant',2,'{\"key\":\"theme.secondary\",\"from\":\"\\\"#3783f0\\\"\",\"to\":\"\\\"#10b981\\\"\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/central/tenants/2/settings/theme.secondary','2026-08-24 14:20:55',NULL),(7,NULL,NULL,'tenant_setting.changed','Tenant Setting - Changed on Tenant#2.','App\\Models\\Tenant',2,'{\"key\":\"theme.sidebar\",\"from\":\"\\\"#0c182a\\\"\",\"to\":\"\\\"#064e3b\\\"\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/central/tenants/2/settings/theme.sidebar','2026-08-24 14:20:56',NULL),(8,NULL,NULL,'tenant_setting.changed','Tenant Setting - Changed on Tenant#2.','App\\Models\\Tenant',2,'{\"key\":\"hotel.tax_reg_no\",\"from\":\"\\\"\\\\u26a0 confirm with owner\\\"\",\"to\":\"null\",\"central_admin\":\"admin@vellix.com\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/central/tenants/2/settings/hotel.tax_reg_no','2026-08-24 14:21:16',NULL),(9,2,7,'room.created','mountview Admin created room \"rm 1\".','App\\Models\\Hotel\\Room',14,'{\"number\":\"rm 1\"}','205.147.17.30','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-24 19:52:21',NULL),(10,2,7,'user.login','mountview Admin signed in successfully.','App\\Models\\User',7,'{\"ip\":\"175.157.43.185\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36 Edg\\/151.0.0.0\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/login','2026-08-25 02:26:18',NULL),(11,2,7,'room.deleted','Room - Deleted on Room#14.','App\\Models\\Hotel\\Room',14,'{\"number\":\"rm 1\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','DELETE api/rooms/14','2026-08-25 02:26:32',NULL),(12,2,NULL,'tenant.admin_password_reset','Tenant - Admin Password Reset on Tenant#2.','App\\Models\\Tenant',2,'{\"email\":\"admin@mountview.com\",\"central_admin\":\"admin@vellix.com\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/central/tenants/2/reset-admin-password','2026-08-24 21:41:22',NULL),(13,2,NULL,'user.login_failed','Failed login attempt for email \"admin@mountview.com\". Attempt ? of ?.','App\\Models\\User',7,'{\"email\":\"admin@mountview.com\",\"ip\":\"212.104.228.157\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 03:14:11',NULL),(14,2,7,'user.login','mountview Admin signed in successfully.','App\\Models\\User',7,'{\"ip\":\"212.104.228.157\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 03:14:21',NULL),(15,2,7,'ingredient.created','mountview Admin added ingredient \"sugar\".','App\\Models\\Hotel\\Ingredient',1,'{\"name\":\"sugar\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/ingredients','2026-08-25 05:26:59',NULL),(16,2,7,'menu_category.created','mountview Admin created menu category \"tea\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"tea\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/categories','2026-08-25 05:27:19',NULL),(17,2,7,'menu_item.created','mountview Admin created menu item \"tea\\\" (#1).','App\\Models\\Hotel\\MenuItem',1,'{\"item_no\":1,\"name\":\"tea\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/items','2026-08-25 05:27:45',NULL),(18,2,7,'dining_table.created','Dining Table - Created on DiningTable#1.','App\\Models\\Hotel\\DiningTable',1,'{\"table_no\":\"1\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/dining-tables','2026-08-25 05:29:29',NULL),(19,2,7,'dining_table.deleted','Dining Table - Deleted on DiningTable#1.','App\\Models\\Hotel\\DiningTable',1,'{\"table_no\":\"1\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','DELETE api/dining-tables/1','2026-08-25 05:29:34',NULL),(20,2,7,'order.created','mountview Admin created order #1 (walkin).','App\\Models\\Hotel\\Order',1,'{\"order_no\":1,\"type\":\"walkin\"}','212.104.228.157','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 05:30:39',NULL),(21,2,NULL,'user.login_failed','Failed login attempt for email \"admin@mountview.com\". Attempt ? of ?.','App\\Models\\User',7,'{\"email\":\"admin@mountview.com\",\"ip\":\"175.157.43.185\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36 Edg\\/151.0.0.0\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/login','2026-08-25 14:50:58',NULL),(22,2,NULL,'user.login_failed','Failed login attempt for email \"admin@mountview.com\". Attempt ? of ?.','App\\Models\\User',7,'{\"email\":\"admin@mountview.com\",\"ip\":\"175.157.43.185\",\"user_agent\":\"Mozilla\\/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit\\/605.1.15 (KHTML, like Gecko) Version\\/18.7.5 Mobile\\/15E148 Safari\\/604.1\"}','175.157.43.185','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.7.5 Mobile/15E148 Safari/604.1','POST api/login','2026-08-25 15:17:29',NULL),(23,2,NULL,'tenant.admin_password_reset','Tenant - Admin Password Reset on Tenant#2.','App\\Models\\Tenant',2,'{\"email\":\"admin@mountview.com\",\"central_admin\":\"admin@vellix.com\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/central/tenants/2/reset-admin-password','2026-08-25 09:54:32',NULL),(24,2,7,'user.login','mountview Admin signed in successfully.','App\\Models\\User',7,'{\"ip\":\"212.104.228.36\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 15:25:11',NULL),(25,2,7,'room.created','mountview Admin created room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:27:28',NULL),(26,2,7,'user.login','mountview Admin signed in successfully.','App\\Models\\User',7,'{\"ip\":\"175.157.43.185\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36 Edg\\/151.0.0.0\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/login','2026-08-25 15:28:53',NULL),(27,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/15','2026-08-25 15:29:31',NULL),(28,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/15','2026-08-25 15:33:35',NULL),(29,2,7,'till.opened','Till - Opened on TillSession#1.','App\\Models\\TillSession',1,'{\"till\":\"Mount View Cashier Till\",\"opening_balance\":500000}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/till/open','2026-08-25 15:36:05',NULL),(30,2,7,'payment.recorded','mountview Admin recorded a cash payment of LKR 500.00.','App\\Models\\Hotel\\Payment',1,'{\"method\":\"cash\",\"amount\":50000,\"reason\":null}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations','2026-08-25 15:36:23',NULL),(31,2,7,'reservation.created','mountview Admin created reservation \"RSV-0001\\\" — stay total LKR 2,500.00, deposit due LKR 500.00.','App\\Models\\Hotel\\Reservation',2,'{\"code\":\"RSV-0001\",\"stay_total\":250000,\"deposit_due\":50000}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations','2026-08-25 15:36:23',NULL),(32,2,7,'reservation.checked_in','mountview Admin checked in reservation \"RSV-0001\".','App\\Models\\Hotel\\Reservation',2,'{\"code\":\"RSV-0001\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations/2/check-in','2026-08-25 15:36:34',NULL),(33,2,7,'folio.line_added','mountview Admin added a damage charge of LKR 200.00 to FolioLine#2.','App\\Models\\Hotel\\FolioLine',2,'{\"source\":\"damage\",\"amount\":20000}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/folios/2/lines','2026-08-25 15:37:11',NULL),(34,2,7,'room.created','mountview Admin created room \"1102\".','App\\Models\\Hotel\\Room',16,'{\"number\":\"1102\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:37:15',NULL),(35,2,7,'folio.line_voided','mountview Admin voided a folio line (LKR 200.00) on FolioLine#2. Reason: hh.','App\\Models\\Hotel\\FolioLine',2,'{\"reason\":\"hh\",\"amount\":20000}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/folios/lines/2/void','2026-08-25 15:37:16',NULL),(36,2,7,'payment.recorded','mountview Admin recorded a cash payment of LKR 2,000.00.','App\\Models\\Hotel\\Payment',2,'{\"method\":\"cash\",\"amount\":200000,\"reason\":null}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/folios/2/payments','2026-08-25 15:37:23',NULL),(37,2,7,'reservation.checked_out','mountview Admin checked out reservation Reservation#2 — invoice INV-2026-0001, total LKR 2,500.00.','App\\Models\\Hotel\\Reservation',2,'{\"invoice_no\":\"INV-2026-0001\",\"total\":250000,\"loyalty_earned\":0}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations/2/checkout','2026-08-25 15:37:30',NULL),(38,2,7,'payment.recorded','mountview Admin recorded a cash payment of LKR 4,800.00.','App\\Models\\Hotel\\Payment',3,'{\"method\":\"cash\",\"amount\":480000,\"reason\":null}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations','2026-08-25 15:38:38',NULL),(39,2,7,'reservation.created','mountview Admin created reservation \"RSV-0002\\\" — stay total LKR 24,000.00, deposit due LKR 4,800.00.','App\\Models\\Hotel\\Reservation',3,'{\"code\":\"RSV-0002\",\"stay_total\":2400000,\"deposit_due\":480000}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations','2026-08-25 15:38:38',NULL),(40,2,7,'reservation.checked_in','mountview Admin checked in reservation \"RSV-0002\".','App\\Models\\Hotel\\Reservation',3,'{\"code\":\"RSV-0002\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations/3/check-in','2026-08-25 15:38:53',NULL),(41,2,7,'room.status_changed','mountview Admin changed room Room#15 status from dirty to maintenance.','App\\Models\\Hotel\\Room',15,'{\"from\":\"dirty\",\"to\":\"maintenance\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/15/status','2026-08-25 15:39:01',NULL),(42,2,7,'room.status_changed','mountview Admin changed room Room#15 status from maintenance to available.','App\\Models\\Hotel\\Room',15,'{\"from\":\"maintenance\",\"to\":\"available\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/15/status','2026-08-25 15:39:04',NULL),(43,2,7,'order.created','mountview Admin created order #2 (room_guest).','App\\Models\\Hotel\\Order',2,'{\"order_no\":2,\"type\":\"room_guest\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/orders','2026-08-25 15:39:18',NULL),(44,2,7,'order.charged_to_room','mountview Admin charged Order#2 to room folio for reservation \"RSV-0002\".','App\\Models\\Hotel\\Order',2,'{\"reservation\":\"RSV-0002\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/orders/2/charge-to-room','2026-08-25 15:39:40',NULL),(45,2,7,'room.created','mountview Admin created room \"1103\".','App\\Models\\Hotel\\Room',17,'{\"number\":\"1103\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:39:45',NULL),(46,2,7,'payment.recorded','mountview Admin recorded a cash payment of LKR 19,950.00.','App\\Models\\Hotel\\Payment',4,'{\"method\":\"cash\",\"amount\":1995000,\"reason\":null}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations/3/checkout','2026-08-25 15:40:19',NULL),(47,2,7,'reservation.checked_out','mountview Admin checked out reservation Reservation#3 — invoice INV-2026-0002, total LKR 24,750.00.','App\\Models\\Hotel\\Reservation',3,'{\"invoice_no\":\"INV-2026-0002\",\"total\":2475000,\"loyalty_earned\":0}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/reservations/3/checkout','2026-08-25 15:40:19',NULL),(48,2,7,'room.status_changed','mountview Admin changed room Room#16 status from dirty to maintenance.','App\\Models\\Hotel\\Room',16,'{\"from\":\"dirty\",\"to\":\"maintenance\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/16/status','2026-08-25 15:40:29',NULL),(49,2,7,'room.status_changed','mountview Admin changed room Room#16 status from maintenance to available.','App\\Models\\Hotel\\Room',16,'{\"from\":\"maintenance\",\"to\":\"available\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/rooms/16/status','2026-08-25 15:40:31',NULL),(50,2,7,'room.updated','mountview Admin updated room \"1102\".','App\\Models\\Hotel\\Room',16,'{\"number\":\"1102\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/16','2026-08-25 15:40:48',NULL),(51,2,7,'room.created','mountview Admin created room \"1104\".','App\\Models\\Hotel\\Room',18,'{\"number\":\"1104\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:42:05',NULL),(52,2,7,'room.created','mountview Admin created room \"1105\".','App\\Models\\Hotel\\Room',19,'{\"number\":\"1105\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:44:06',NULL),(53,2,7,'room.created','mountview Admin created room \"1106\".','App\\Models\\Hotel\\Room',20,'{\"number\":\"1106\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:46:27',NULL),(54,2,7,'room.created','mountview Admin created room \"1107\".','App\\Models\\Hotel\\Room',21,'{\"number\":\"1107\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:47:39',NULL),(55,2,7,'room.created','mountview Admin created room \"1110\".','App\\Models\\Hotel\\Room',22,'{\"number\":\"1110\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:49:32',NULL),(56,2,7,'room.created','mountview Admin created room \"1111\".','App\\Models\\Hotel\\Room',23,'{\"number\":\"1111\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:50:59',NULL),(57,2,7,'room.created','mountview Admin created room \"1112\".','App\\Models\\Hotel\\Room',24,'{\"number\":\"1112\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:51:58',NULL),(58,2,7,'room.created','mountview Admin created room \"1114\".','App\\Models\\Hotel\\Room',25,'{\"number\":\"1114\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:53:51',NULL),(59,2,7,'room.created','mountview Admin created room \"1115\".','App\\Models\\Hotel\\Room',26,'{\"number\":\"1115\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:55:19',NULL),(60,2,7,'housekeeping.completed','mountview Admin completed the cleaning checklist for room 1102.','App\\Models\\Hotel\\HousekeepingTask',2,'{\"room\":\"1102\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/housekeeping/tasks/2/complete','2026-08-25 15:55:37',NULL),(61,2,7,'housekeeping.completed','mountview Admin completed the cleaning checklist for room 1101.','App\\Models\\Hotel\\HousekeepingTask',1,'{\"room\":\"1101\"}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/housekeeping/tasks/1/complete','2026-08-25 15:55:45',NULL),(62,2,7,'room.created','mountview Admin created room \"1116\".','App\\Models\\Hotel\\Room',27,'{\"number\":\"1116\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 15:56:22',NULL),(63,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15','2026-08-25 16:03:14',NULL),(64,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15','2026-08-25 16:03:24',NULL),(65,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15','2026-08-25 16:03:42',NULL),(66,2,7,'room.updated','mountview Admin updated room \"1101\".','App\\Models\\Hotel\\Room',15,'{\"number\":\"1101\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15','2026-08-25 16:03:58',NULL),(67,2,7,'room.updated','mountview Admin updated room \"1102\".','App\\Models\\Hotel\\Room',16,'{\"number\":\"1102\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/16','2026-08-25 16:04:25',NULL),(68,2,7,'room.updated','mountview Admin updated room \"1103\".','App\\Models\\Hotel\\Room',17,'{\"number\":\"1103\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/17','2026-08-25 16:04:53',NULL),(69,2,7,'room.updated','mountview Admin updated room \"1104\".','App\\Models\\Hotel\\Room',18,'{\"number\":\"1104\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/18','2026-08-25 16:05:05',NULL),(70,2,7,'room.updated','mountview Admin updated room \"1105\".','App\\Models\\Hotel\\Room',19,'{\"number\":\"1105\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/19','2026-08-25 16:05:19',NULL),(71,2,7,'user.created','mountview Admin created a new user account: User#8.','App\\Models\\User',8,'{\"roles\":[\"Owner\"],\"allow_overrides\":[\"hotel_notifications.test\"],\"deny_overrides\":[\"hotel_menu_categories.access\",\"hotel_menu_categories.create\",\"hotel_menu_categories.edit\",\"hotel_menu_categories.delete\",\"hotel_menu_items.access\",\"hotel_menu_items.create\",\"hotel_menu_items.edit\",\"hotel_menu_items.delete\",\"hotel_menu_items.sold_out\",\"hotel_dining_tables.access\",\"hotel_dining_tables.create\",\"hotel_dining_tables.edit\",\"hotel_dining_tables.edit_status\",\"hotel_dining_tables.delete\",\"apartment_properties.access\",\"apartment_properties.create\",\"apartment_properties.edit\",\"apartment_unit_types.access\",\"apartment_unit_types.create\",\"apartment_unit_types.edit\",\"apartment_units.access\",\"apartment_units.create\",\"apartment_units.edit\",\"apartment_units.edit_status\",\"apartment_customers.access\",\"apartment_customers.view\",\"apartment_customers.create\",\"apartment_customers.edit\",\"apartment_bookings.access\",\"apartment_bookings.view\",\"apartment_bookings.create\",\"apartment_bookings.check_in\",\"apartment_bookings.checkout\",\"apartment_bookings.cancel\",\"apartment_leases.access\",\"apartment_leases.view\",\"apartment_leases.create\",\"apartment_leases.renew\",\"apartment_leases.terminate\",\"apartment_leases.utility_reading\",\"apartment_sales.access\",\"apartment_sales.view\",\"apartment_sales.create\",\"apartment_sales.reserve\",\"apartment_sales.sign_agreement\",\"apartment_sales.complete\",\"apartment_sales.cancel\",\"apartment_ledgers.view\",\"apartment_ledgers.add_line\",\"apartment_ledgers.void_line\",\"apartment_ledgers.payment\",\"apartment_ledgers.refund\",\"apartment_housekeeping.access\",\"apartment_housekeeping.create\",\"apartment_housekeeping.assign\",\"apartment_housekeeping.checklist\",\"apartment_housekeeping.complete\",\"apartment_maintenance.access\",\"apartment_maintenance.create\",\"apartment_maintenance.edit\",\"apartment_reports.dashboard\",\"apartment_reports.occupancy_trend\",\"apartment_reports.revenue_channel\",\"apartment_reports.rent_roll\",\"apartment_reports.sales_pipeline\",\"apartment_reports.utilities\",\"apartment_reports.ops_sla\"]}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/user-management/users','2026-08-25 16:05:21',NULL),(72,2,7,'room.updated','mountview Admin updated room \"1106\".','App\\Models\\Hotel\\Room',20,'{\"number\":\"1106\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/20','2026-08-25 16:05:47',NULL),(73,2,7,'room.updated','mountview Admin updated room \"1107\".','App\\Models\\Hotel\\Room',21,'{\"number\":\"1107\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/21','2026-08-25 16:06:00',NULL),(74,2,7,'staff.pin_set','mountview Admin set a PIN unlock code for User#8.','App\\Models\\User',8,'[]','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/staff/8/pin','2026-08-25 16:06:45',NULL),(75,2,7,'room.created','mountview Admin created room \"1108\".','App\\Models\\Hotel\\Room',28,'{\"number\":\"1108\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/rooms','2026-08-25 16:07:19',NULL),(76,2,7,'room.updated','mountview Admin updated room \"1110\".','App\\Models\\Hotel\\Room',22,'{\"number\":\"1110\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/22','2026-08-25 16:07:48',NULL),(77,2,7,'room.updated','mountview Admin updated room \"1111\".','App\\Models\\Hotel\\Room',23,'{\"number\":\"1111\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/23','2026-08-25 16:08:13',NULL),(78,2,7,'room.updated','mountview Admin updated room \"1112\".','App\\Models\\Hotel\\Room',24,'{\"number\":\"1112\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/24','2026-08-25 16:08:25',NULL),(79,2,7,'room.updated','mountview Admin updated room \"1114\".','App\\Models\\Hotel\\Room',25,'{\"number\":\"1114\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/25','2026-08-25 16:08:46',NULL),(80,2,7,'room.updated','mountview Admin updated room \"1115\".','App\\Models\\Hotel\\Room',26,'{\"number\":\"1115\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/26','2026-08-25 16:08:57',NULL),(81,2,7,'room.updated','mountview Admin updated room \"1116\".','App\\Models\\Hotel\\Room',27,'{\"number\":\"1116\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/27','2026-08-25 16:09:09',NULL),(82,2,7,'user.created','mountview Admin created a new user account: User#9.','App\\Models\\User',9,'{\"roles\":[\"Manager\"],\"allow_overrides\":[\"hotel_notifications.test\",\"hotel_reports.payroll_cost\",\"hotel_qr_ordering.access\",\"hotel_qr_ordering.create\",\"hotel_qr_ordering.edit\",\"hotel_qr_ordering.regenerate\",\"hotel_payroll.manage_pay\",\"hotel_payroll.view\",\"hotel_payroll.generate\",\"hotel_payroll.adjust_line\",\"hotel_payroll.finalize\",\"hotel_payroll.delete_run\",\"hotel_payroll.mark_paid\",\"hotel_payroll.export\",\"hotel_payroll.payslip\"],\"deny_overrides\":[\"dashboard.access\",\"user_management_users.access\",\"user_management_users.view\",\"user_management_users.create\",\"user_management_users.edit\",\"user_management_roles.access\",\"user_management_roles.view\",\"audit_logs.access\",\"audit_logs.view\",\"apartment_properties.access\",\"apartment_properties.create\",\"apartment_properties.edit\",\"apartment_unit_types.access\",\"apartment_unit_types.create\",\"apartment_unit_types.edit\",\"apartment_units.access\",\"apartment_units.create\",\"apartment_units.edit\",\"apartment_units.edit_status\",\"apartment_customers.access\",\"apartment_customers.view\",\"apartment_customers.create\",\"apartment_customers.edit\",\"apartment_bookings.access\",\"apartment_bookings.view\",\"apartment_bookings.create\",\"apartment_bookings.check_in\",\"apartment_bookings.checkout\",\"apartment_bookings.cancel\",\"apartment_leases.access\",\"apartment_leases.view\",\"apartment_leases.create\",\"apartment_leases.renew\",\"apartment_leases.terminate\",\"apartment_leases.utility_reading\",\"apartment_sales.access\",\"apartment_sales.view\",\"apartment_sales.create\",\"apartment_sales.reserve\",\"apartment_sales.sign_agreement\",\"apartment_sales.complete\",\"apartment_sales.cancel\",\"apartment_ledgers.view\",\"apartment_ledgers.add_line\",\"apartment_ledgers.void_line\",\"apartment_ledgers.payment\",\"apartment_ledgers.refund\",\"apartment_housekeeping.access\",\"apartment_housekeeping.create\",\"apartment_housekeeping.assign\",\"apartment_housekeeping.checklist\",\"apartment_housekeeping.complete\",\"apartment_maintenance.access\",\"apartment_maintenance.create\",\"apartment_maintenance.edit\",\"apartment_reports.dashboard\",\"apartment_reports.occupancy_trend\",\"apartment_reports.revenue_channel\",\"apartment_reports.rent_roll\",\"apartment_reports.sales_pipeline\",\"apartment_reports.utilities\",\"apartment_reports.ops_sla\"]}','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/user-management/users','2026-08-25 16:09:23',NULL),(83,2,7,'staff.pin_set','mountview Admin set a PIN unlock code for User#9.','App\\Models\\User',9,'[]','175.157.43.185','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','PUT api/staff/9/pin','2026-08-25 16:09:40',NULL),(84,2,7,'payroll_run.created','mountview Admin generated the payroll run for 2026-08.','App\\Models\\Hotel\\PayrollRun',1,'{\"month\":\"2026-08\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/payroll/runs','2026-08-25 16:13:05',NULL),(85,NULL,NULL,'tenant_module.toggled','Tenant Module - Toggled on Tenant#2.','App\\Models\\Tenant',2,'{\"module_key\":\"apartments\",\"enabled\":false,\"central_admin\":\"admin@vellix.com\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/central/tenants/2/modules/apartments','2026-08-25 10:43:48',NULL),(86,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 16:38:38',NULL),(87,2,9,'till.opened','Till - Opened on TillSession#2.','App\\Models\\TillSession',2,'{\"till\":\"Mount View Cashier Till\",\"opening_balance\":5500000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/till/open','2026-08-25 16:46:14',NULL),(88,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 1,900.00.','App\\Models\\Hotel\\Payment',5,'{\"method\":\"cash\",\"amount\":190000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 16:52:27',NULL),(89,2,9,'reservation.created','Miss. Himansa created reservation \"RSV-0003\\\" — stay total LKR 9,500.00, deposit due LKR 1,900.00.','App\\Models\\Hotel\\Reservation',6,'{\"code\":\"RSV-0003\",\"stay_total\":950000,\"deposit_due\":190000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 16:52:27',NULL),(90,2,9,'reservation.checked_in','Miss. Himansa checked in reservation \"RSV-0003\".','App\\Models\\Hotel\\Reservation',6,'{\"code\":\"RSV-0003\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/6/check-in','2026-08-25 16:54:24',NULL),(91,2,9,'order.created','Miss. Himansa created order #3 (room_guest).','App\\Models\\Hotel\\Order',3,'{\"order_no\":3,\"type\":\"room_guest\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 16:56:27',NULL),(92,2,9,'order.charged_to_room','Miss. Himansa charged Order#3 to room folio for reservation \"RSV-0003\".','App\\Models\\Hotel\\Order',3,'{\"reservation\":\"RSV-0003\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders/3/charge-to-room','2026-08-25 17:01:30',NULL),(93,2,9,'order.created','Miss. Himansa created order #4 (walkin).','App\\Models\\Hotel\\Order',4,'{\"order_no\":4,\"type\":\"walkin\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 17:11:57',NULL),(94,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 7,750.00.','App\\Models\\Hotel\\Payment',6,'{\"method\":\"cash\",\"amount\":775000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/6/checkout','2026-08-25 17:15:35',NULL),(95,2,9,'reservation.checked_out','Miss. Himansa checked out reservation Reservation#6 — invoice INV-2026-0003, total LKR 9,650.00.','App\\Models\\Hotel\\Reservation',6,'{\"invoice_no\":\"INV-2026-0003\",\"total\":965000,\"loyalty_earned\":0}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/6/checkout','2026-08-25 17:15:35',NULL),(96,2,9,'ingredient.created','Miss. Himansa added ingredient \"red rice\".','App\\Models\\Hotel\\Ingredient',3,'{\"name\":\"red rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/ingredients','2026-08-25 17:22:03',NULL),(97,2,7,'order.created','mountview Admin created order #5 (walkin).','App\\Models\\Hotel\\Order',5,'{\"order_no\":5,\"type\":\"walkin\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 17:22:03',NULL),(98,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"red rice\".','App\\Models\\Hotel\\Ingredient',3,'{\"name\":\"red rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/ingredients/3','2026-08-25 17:23:33',NULL),(99,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"red rice\".','App\\Models\\Hotel\\Ingredient',3,'{\"name\":\"red rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/ingredients/3','2026-08-25 17:23:52',NULL),(100,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"red rice\".','App\\Models\\Hotel\\Ingredient',3,'{\"name\":\"red rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/ingredients/3','2026-08-25 17:23:52',NULL),(101,2,9,'ingredient.stock_adjusted','Miss. Himansa adjusted stock for Ingredient#3 by 5 — reason: new stock.','App\\Models\\Hotel\\Ingredient',3,'{\"delta\":5,\"reason\":\"new stock\",\"expiry_date\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/ingredients/3/adjust','2026-08-25 17:23:58',NULL),(102,2,9,'ingredient.created','Miss. Himansa added ingredient \"chicken\".','App\\Models\\Hotel\\Ingredient',4,'{\"name\":\"chicken\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/ingredients','2026-08-25 17:25:10',NULL),(103,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"chicken\".','App\\Models\\Hotel\\Ingredient',4,'{\"name\":\"chicken\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/ingredients/4','2026-08-25 17:25:29',NULL),(104,2,9,'ingredient.stock_adjusted','Miss. Himansa adjusted stock for Ingredient#4 by 5 — reason: new stock.','App\\Models\\Hotel\\Ingredient',4,'{\"delta\":5,\"reason\":\"new stock\",\"expiry_date\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/ingredients/4/adjust','2026-08-25 17:25:32',NULL),(105,2,9,'ingredient.created','Miss. Himansa added ingredient \"water bottle 500ml\".','App\\Models\\Hotel\\Ingredient',5,'{\"name\":\"water bottle 500ml\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/products','2026-08-25 17:26:38',NULL),(106,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"water bottle 500ml\".','App\\Models\\Hotel\\Ingredient',5,'{\"name\":\"water bottle 500ml\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/products/5','2026-08-25 17:27:34',NULL),(107,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"water bottle 500ml\".','App\\Models\\Hotel\\Ingredient',5,'{\"name\":\"water bottle 500ml\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/products/5','2026-08-25 17:27:52',NULL),(108,2,9,'ingredient.stock_adjusted','Miss. Himansa adjusted stock for Ingredient#5 by 50 — reason: new stock.','App\\Models\\Hotel\\Ingredient',5,'{\"delta\":50,\"reason\":\"new stock\",\"expiry_date\":\"2026-08-30\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/products/5/adjust','2026-08-25 17:27:52',NULL),(109,2,9,'ingredient.created','Miss. Himansa added ingredient \"water bottle 1l\".','App\\Models\\Hotel\\Ingredient',6,'{\"name\":\"water bottle 1l\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/products','2026-08-25 17:30:00',NULL),(110,2,9,'ingredient.updated','Miss. Himansa updated ingredient \"water bottle 1l\".','App\\Models\\Hotel\\Ingredient',6,'{\"name\":\"water bottle 1l\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/products/6','2026-08-25 17:30:30',NULL),(111,2,9,'ingredient.stock_adjusted','Miss. Himansa adjusted stock for Ingredient#6 by 50 — reason: new stock.','App\\Models\\Hotel\\Ingredient',6,'{\"delta\":50,\"reason\":\"new stock\",\"expiry_date\":\"20206-08-31\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/products/6/adjust','2026-08-25 17:30:33',NULL),(112,2,9,'order.created','Miss. Himansa created order #6 (walkin).','App\\Models\\Hotel\\Order',6,'{\"order_no\":6,\"type\":\"walkin\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 17:31:12',NULL),(113,2,9,'room.status_changed','Miss. Himansa changed room Room#15 status from dirty to maintenance.','App\\Models\\Hotel\\Room',15,'{\"from\":\"dirty\",\"to\":\"maintenance\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15/status','2026-08-25 17:33:03',NULL),(114,2,9,'room.status_changed','Miss. Himansa changed room Room#15 status from maintenance to available.','App\\Models\\Hotel\\Room',15,'{\"from\":\"maintenance\",\"to\":\"available\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/15/status','2026-08-25 17:33:52',NULL),(115,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 1,000.00.','App\\Models\\Hotel\\Payment',7,'{\"method\":\"cash\",\"amount\":100000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:34:57',NULL),(116,2,9,'reservation.created','Miss. Himansa created reservation \"RSV-0004\\\" — stay total LKR 5,000.00, deposit due LKR 1,000.00.','App\\Models\\Hotel\\Reservation',7,'{\"code\":\"RSV-0004\",\"stay_total\":500000,\"deposit_due\":100000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:34:57',NULL),(117,2,9,'reservation.checked_in','Miss. Himansa checked in reservation \"RSV-0004\".','App\\Models\\Hotel\\Reservation',7,'{\"code\":\"RSV-0004\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/7/check-in','2026-08-25 17:35:09',NULL),(118,2,9,'reservation.discount_applied','Miss. Himansa applied a FIXED discount of 270000 (LKR 2,700.00) to reservation Reservation#7. Reason: single rate.','App\\Models\\Hotel\\Reservation',7,'{\"mode\":\"FIXED\",\"value\":270000,\"discount\":270000,\"reason\":\"single rate\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/reservations/7/discount','2026-08-25 17:36:17',NULL),(119,2,9,'folio.line_voided','Miss. Himansa voided a folio line (LKR -2,700.00) on FolioLine#9. Reason: mistake.','App\\Models\\Hotel\\FolioLine',9,'{\"reason\":\"mistake\",\"amount\":-270000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/folios/lines/9/void','2026-08-25 17:36:41',NULL),(120,2,9,'reservation.discount_applied','Miss. Himansa applied a FIXED discount of 170000 (LKR 1,700.00) to reservation Reservation#7. Reason: single room.','App\\Models\\Hotel\\Reservation',7,'{\"mode\":\"FIXED\",\"value\":170000,\"discount\":170000,\"reason\":\"single room\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/reservations/7/discount','2026-08-25 17:36:59',NULL),(121,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 2,300.00.','App\\Models\\Hotel\\Payment',8,'{\"method\":\"cash\",\"amount\":230000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/7/checkout','2026-08-25 17:37:33',NULL),(122,2,9,'reservation.checked_out','Miss. Himansa checked out reservation Reservation#7 — invoice INV-2026-0004, total LKR 3,300.00.','App\\Models\\Hotel\\Reservation',7,'{\"invoice_no\":\"INV-2026-0004\",\"total\":330000,\"loyalty_earned\":0}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/7/checkout','2026-08-25 17:37:33',NULL),(123,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 1,000.00.','App\\Models\\Hotel\\Payment',9,'{\"method\":\"cash\",\"amount\":100000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:42:34',NULL),(124,2,9,'reservation.created','Miss. Himansa created reservation \"RSV-0005\\\" — stay total LKR 5,000.00, deposit due LKR 1,000.00.','App\\Models\\Hotel\\Reservation',8,'{\"code\":\"RSV-0005\",\"stay_total\":500000,\"deposit_due\":100000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:42:34',NULL),(125,2,9,'reservation.cancelled','Miss. Himansa cancelled reservation Reservation#8 — 0% refund (LKR 0.00), LKR 1,000.00 cancellation fee applied. Reason: customer request.','App\\Models\\Hotel\\Reservation',8,'{\"reason\":\"customer request\",\"refund_pct\":0,\"refunded\":0,\"fee\":100000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/8/cancel','2026-08-25 17:43:17',NULL),(126,2,9,'room.status_changed','Miss. Himansa changed room Room#16 status from dirty to maintenance.','App\\Models\\Hotel\\Room',16,'{\"from\":\"dirty\",\"to\":\"maintenance\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/16/status','2026-08-25 17:43:42',NULL),(127,2,9,'room.status_changed','Miss. Himansa changed room Room#16 status from maintenance to available.','App\\Models\\Hotel\\Room',16,'{\"from\":\"maintenance\",\"to\":\"available\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/rooms/16/status','2026-08-25 17:43:46',NULL),(128,2,9,'payment.recorded','Miss. Himansa recorded a cash payment of LKR 1,000.00.','App\\Models\\Hotel\\Payment',10,'{\"method\":\"cash\",\"amount\":100000,\"reason\":null}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:44:52',NULL),(129,2,9,'reservation.created','Miss. Himansa created reservation \"RSV-0006\\\" — stay total LKR 5,000.00, deposit due LKR 1,000.00.','App\\Models\\Hotel\\Reservation',9,'{\"code\":\"RSV-0006\",\"stay_total\":500000,\"deposit_due\":100000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 17:44:52',NULL),(130,2,9,'payment.refunded','Miss. Himansa issued a bank_transfer refund of LKR 800.00. Reason: booking cancel.','App\\Models\\Hotel\\Payment',11,'{\"method\":\"bank_transfer\",\"amount\":80000,\"reason\":\"booking cancel\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/folios/9/refund','2026-08-25 17:45:37',NULL),(131,2,9,'reservation.cancelled','Miss. Himansa cancelled reservation Reservation#9 — 0% refund (LKR 0.00), LKR 200.00 cancellation fee applied. Reason: not comming.','App\\Models\\Hotel\\Reservation',9,'{\"reason\":\"not comming\",\"refund_pct\":0,\"refunded\":0,\"fee\":20000}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/9/cancel','2026-08-25 17:46:39',NULL),(132,2,9,'till.closed','Till - Closed on TillSession#2.','App\\Models\\TillSession',2,'{\"expected_cash\":6995000,\"closing_cash\":6995000,\"variance\":0}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/till/2/close','2026-08-25 17:50:05',NULL),(133,2,9,'menu_category.updated','Miss. Himansa updated menu category \"rice\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:00:46',NULL),(134,2,9,'menu_category.updated','Miss. Himansa updated menu category \"rice\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:00:49',NULL),(135,2,9,'menu_category.updated','Miss. Himansa updated menu category \"Breverages\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"Breverages\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:01:18',NULL),(136,2,9,'menu_category.created','Miss. Himansa created menu category \"Tea\".','App\\Models\\Hotel\\MenuCategory',2,'{\"name\":\"Tea\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/categories','2026-08-25 18:01:23',NULL),(137,2,9,'menu_category.updated','Miss. Himansa updated menu category \"rice & curry\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"rice & curry\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:02:03',NULL),(138,2,9,'menu_category.updated','Miss. Himansa updated menu category \"Chicken\".','App\\Models\\Hotel\\MenuCategory',2,'{\"name\":\"Chicken\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/2','2026-08-25 18:02:12',NULL),(139,2,9,'menu_category.deleted','Miss. Himansa removed menu category \"Chicken\".','App\\Models\\Hotel\\MenuCategory',2,'{\"name\":\"Chicken\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','DELETE api/menu/categories/2','2026-08-25 18:02:42',NULL),(140,2,9,'menu_category.updated','Miss. Himansa updated menu category \"fish\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"fish\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:02:42',NULL),(141,2,9,'menu_category.created','Miss. Himansa created menu category \"rice & curry\".','App\\Models\\Hotel\\MenuCategory',3,'{\"name\":\"rice & curry\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/categories','2026-08-25 18:02:46',NULL),(142,2,9,'menu_category.deleted','Miss. Himansa removed menu category \"rice & curry\".','App\\Models\\Hotel\\MenuCategory',3,'{\"name\":\"rice & curry\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','DELETE api/menu/categories/3','2026-08-25 18:02:55',NULL),(143,2,9,'menu_category.created','Miss. Himansa created menu category \"Chinese item\".','App\\Models\\Hotel\\MenuCategory',4,'{\"name\":\"Chinese item\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/categories','2026-08-25 18:03:24',NULL),(144,2,9,'menu_category.updated','Miss. Himansa updated menu category \"rice\".','App\\Models\\Hotel\\MenuCategory',1,'{\"name\":\"rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/menu/categories/1','2026-08-25 18:03:24',NULL),(145,2,9,'menu_item.created','Miss. Himansa created menu item \"rice full\\\" (#2).','App\\Models\\Hotel\\MenuItem',2,'{\"item_no\":2,\"name\":\"rice full\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/items','2026-08-25 18:07:58',NULL),(146,2,9,'menu_item.created','Miss. Himansa created menu item \"fried rice\\\" (#3).','App\\Models\\Hotel\\MenuItem',3,'{\"item_no\":3,\"name\":\"fried rice\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/menu/items','2026-08-25 18:15:18',NULL),(147,2,9,'user.logout','Miss. Himansa signed out.','App\\Models\\User',9,'[]','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 18:24:07',NULL),(148,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/pin-login','2026-08-25 18:24:15',NULL),(149,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/pin-login','2026-08-25 18:26:22',NULL),(150,2,9,'user.logout','Miss. Himansa signed out.','App\\Models\\User',9,'[]','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 18:31:32',NULL),(151,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36 Edg\\/151.0.0.0\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','POST api/login','2026-08-25 18:35:35',NULL),(152,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 18:41:59',NULL),(153,2,7,'user.logout','mountview Admin signed out.','App\\Models\\User',7,'[]','212.104.224.170','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 18:42:01',NULL),(154,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"212.104.224.170\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.224.170','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 18:42:43',NULL),(155,2,9,'user.logout','Miss. Himansa signed out.','App\\Models\\User',9,'[]','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 18:42:49',NULL),(156,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/pin-login','2026-08-25 18:43:36',NULL),(157,2,9,'user.logout','Miss. Himansa signed out.','App\\Models\\User',9,'[]','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 18:44:04',NULL),(158,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.100\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.100','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/pin-login','2026-08-25 18:44:11',NULL),(159,2,9,'user.logout','Miss. Himansa signed out.','App\\Models\\User',9,'[]','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/logout','2026-08-25 19:40:08',NULL),(160,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"112.134.141.147\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/pin-login','2026-08-25 19:40:22',NULL),(161,2,9,'reservation.created','Miss. Himansa created reservation \"RSV-0007\\\" — stay total LKR 9,500.00, deposit due LKR 1,900.00.','App\\Models\\Hotel\\Reservation',10,'{\"code\":\"RSV-0007\",\"stay_total\":950000,\"deposit_due\":190000}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations','2026-08-25 19:43:03',NULL),(162,2,9,'reservation.checked_in','Miss. Himansa checked in reservation \"RSV-0007\".','App\\Models\\Hotel\\Reservation',10,'{\"code\":\"RSV-0007\"}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/reservations/10/check-in','2026-08-25 19:43:32',NULL),(163,2,9,'order.created','Miss. Himansa created order #7 (room_guest).','App\\Models\\Hotel\\Order',7,'{\"order_no\":7,\"type\":\"room_guest\"}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders','2026-08-25 19:45:32',NULL),(164,2,9,'order.charged_to_room','Miss. Himansa charged Order#7 to room folio for reservation \"RSV-0007\".','App\\Models\\Hotel\\Order',7,'{\"reservation\":\"RSV-0007\"}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/orders/7/charge-to-room','2026-08-25 19:46:51',NULL),(165,2,9,'reservation.discount_applied','Miss. Himansa applied a FIXED discount of 620000 (LKR 6,200.00) to reservation Reservation#10. Reason: single room.','App\\Models\\Hotel\\Reservation',10,'{\"mode\":\"FIXED\",\"value\":620000,\"discount\":620000,\"reason\":\"single room\"}','112.134.141.147','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','PUT api/reservations/10/discount','2026-08-25 19:54:19',NULL),(166,2,NULL,'user.login_failed','Failed login attempt for email \"admin@vellix.com\". Attempt ? of ?.',NULL,NULL,'{\"email\":\"admin@vellix.com\",\"ip\":\"212.104.228.36\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 23:40:49',NULL),(167,2,9,'user.login','Miss. Himansa signed in successfully.','App\\Models\\User',9,'{\"ip\":\"212.104.228.36\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-25 23:43:59',NULL),(168,2,NULL,'user.login_failed','Failed login attempt for email \"admin@mountview.com\". Attempt ? of ?.','App\\Models\\User',7,'{\"email\":\"admin@mountview.com\",\"ip\":\"45.121.88.137\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/151.0.0.0 Safari\\/537.36\"}','45.121.88.137','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','POST api/login','2026-08-26 22:14:23',NULL);
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('01829b23fc802b2c4c5b506a0576d6228544f5f2','i:1;',1787728652),('01829b23fc802b2c4c5b506a0576d6228544f5f2:timer','i:1787728652;',1787728652),('0ade7c2cf97f75d009975f4d720d1fa6c19f4897','i:1;',1787648855),('0ade7c2cf97f75d009975f4d720d1fa6c19f4897:timer','i:1787648855;',1787648855),('5393ed89390b0e85ca5ebbdcc8395187600b04c3','i:2;',1787649275),('5393ed89390b0e85ca5ebbdcc8395187600b04c3:timer','i:1787649275;',1787649275),('569d89d8a9bb89120995eb17997b1c72d021f35e','i:1;',1787652682),('569d89d8a9bb89120995eb17997b1c72d021f35e:timer','i:1787652682;',1787652682),('ad8ffe815133ad0f970f13626933dab3e37b49e1','i:1;',1787566558),('ad8ffe815133ad0f970f13626933dab3e37b49e1:timer','i:1787566558;',1787566558),('e65e3999bca1e6ca152dff834595cca0e89f314f','i:1;',1787637326),('e65e3999bca1e6ca152dff834595cca0e89f314f:timer','i:1787637326;',1787637326),('f03c8c12209c33734f6815428232cbf0b8c35630','i:1;',1787593333),('f03c8c12209c33734f6815428232cbf0b8c35630:timer','i:1787593333;',1787593333),('geoip:212.104.224.170','s:36:\"Colombo, Western Province, Sri Lanka\";',1787735504),('login:burst:admin@mountview.com|45.121.88.137','i:1;',1787748323),('login:burst:admin@mountview.com|45.121.88.137:timer','i:1787748323;',1787748323),('login:burst:admin@vellix.com|212.104.228.36','i:1;',1787667109),('login:burst:admin@vellix.com|212.104.228.36:timer','i:1787667109;',1787667109),('login:lockout:admin@mountview.com','i:1;',1787834663),('login:lockout:admin@mountview.com:timer','i:1787834663;',1787834663),('login:lockout:admin@vellix.com','i:1;',1787753449),('login:lockout:admin@vellix.com:timer','i:1787753449;',1787753449),('lookup:booking_channel:phone','i:72;',2103005554),('lookup:booking_channel:walkin','i:73;',2102997983),('lookup:check_kind:check_in','i:77;',2102997994),('lookup:dining_mode:dine_in','i:79;',2102961639),('lookup:folio_status:open','i:24;',2102997983),('lookup:folio_status:settled','i:25;',2102998050),('lookup:folio_status:void','i:26;',2103005597),('lookup:folio_type:guest','i:27;',2102997983),('lookup:inventory_kind:ingredient','i:1;',2102961419),('lookup:inventory_kind:product','i:2;',2102950757),('lookup:kitchen_station:kitchen','i:92;',2103006649),('lookup:kot_status:new','i:20;',2102961639),('lookup:kot_status:preparing','i:21;',2102998166),('lookup:kot_status:ready','i:22;',2102998171),('lookup:kot_status:served','i:23;',2102998172),('lookup:line_source:cancellation_fee','i:69;',2103005597),('lookup:line_source:damage','i:66;',2102998031),('lookup:line_source:discount','i:65;',2103005177),('lookup:line_source:restaurant','i:58;',2102998180),('lookup:line_source:room','i:56;',2102997994),('lookup:line_source:service_charge','i:63;',2102998050),('lookup:line_source:surcharge','i:62;',2102998050),('lookup:line_source:vat','i:64;',2102998050),('lookup:order_status:charged_to_room','i:16;',2102998180),('lookup:order_status:open','i:13;',2102961639),('lookup:order_type:room_guest','i:81;',2102998158),('lookup:order_type:walkin','i:82;',2102961639),('lookup:payment_kind:deposit','i:54;',2102997983),('lookup:payment_kind:payment','i:53;',2102998043),('lookup:payment_kind:refund','i:55;',2102998050),('lookup:payment_method:bank_transfer','i:50;',2103005737),('lookup:payment_method:cash','i:47;',2102997983),('lookup:payment_method:corporate_credit','i:51;',2102926964),('lookup:payroll_status:draft','i:29;',2103000185),('lookup:reservation_status:cancelled','i:7;',2103005597),('lookup:reservation_status:checked_in','i:5;',2102997994),('lookup:reservation_status:checked_out','i:6;',2102998050),('lookup:reservation_status:confirmed','i:4;',2102997983),('lookup:room_status:available','i:9;',2102924803),('lookup:room_status:dirty','i:11;',2102998050),('lookup:room_status:maintenance','i:12;',2102926964),('lookup:room_status:occupied','i:10;',2102997994),('lookup:stock_movement_type:adjustment','i:156;',2103004438),('lookup:stock_movement_type:sale','i:157;',2103004872),('lookup:table_status:free','i:84;',2102961569),('lookup:task_status:done','i:40;',2102999137),('lookup:task_status:in_progress','i:39;',2102999131),('lookup:task_status:pending','i:38;',2102998050),('lookup:till_movement_type:cash_in','i:141;',2102997983),('lookup:till_movement_type:opening_balance','i:140;',2102997965),('lookup:till_session_status:closed','i:139;',2103006005),('lookup:till_session_status:open','i:138;',2102997965),('menu_items.index.64a75ec2aa055cb15788017ad71dc24f','a:2:{s:10:\"menu_items\";O:42:\"Illuminate\\Pagination\\LengthAwarePaginator\":12:{s:8:\"\0*\0items\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:3:{i:0;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:1;s:4:\"name\";s:3:\"tea\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";i:1;s:5:\"price\";i:15000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:7;s:10:\"created_at\";s:19:\"2026-08-25 01:27:45\";s:10:\"updated_at\";s:19:\"2026-08-25 01:27:45\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:1;s:4:\"name\";s:3:\"tea\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";i:1;s:5:\"price\";i:15000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:7;s:10:\"created_at\";s:19:\"2026-08-25 01:27:45\";s:10:\"updated_at\";s:19:\"2026-08-25 01:27:45\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";O:29:\"App\\Models\\Hotel\\MenuCategory\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:19:\"pos_menu_categories\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:12:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;}s:11:\"\0*\0original\";a:12:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:10:\"deleted_at\";s:8:\"datetime\";s:10:\"sort_order\";s:7:\"integer\";s:10:\"is_minibar\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:8:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:10:\"sort_order\";i:3;s:10:\"is_minibar\";i:4;s:18:\"kitchen_station_id\";i:5;s:6:\"active\";i:6;s:10:\"created_by\";i:7;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:1;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:2;s:4:\"name\";s:9:\"rice full\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:50000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:2;s:4:\"name\";s:9:\"rice full\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:50000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";r:64;s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:2:{i:0;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:4;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:4;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";O:27:\"App\\Models\\Hotel\\Ingredient\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:11:\"ingredients\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:17:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:7:\"chicken\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"1.000\";s:9:\"unit_cost\";i:125000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:25:10\";s:10:\"updated_at\";s:19:\"2026-08-25 13:25:32\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:11:\"\0*\0original\";a:17:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:7:\"chicken\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"1.000\";s:9:\"unit_cost\";i:125000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:25:10\";s:10:\"updated_at\";s:19:\"2026-08-25 13:25:32\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:6:{s:10:\"deleted_at\";s:8:\"datetime\";s:9:\"stock_qty\";s:5:\"float\";s:19:\"low_stock_threshold\";s:5:\"float\";s:9:\"unit_cost\";s:7:\"integer\";s:13:\"selling_price\";s:7:\"integer\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:13:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:4:\"unit\";i:3;s:9:\"stock_qty\";i:4;s:19:\"low_stock_threshold\";i:5;s:9:\"unit_cost\";i:6;s:17:\"inventory_kind_id\";i:7;s:13:\"selling_price\";i:8;s:16:\"menu_category_id\";i:9;s:5:\"image\";i:10;s:6:\"active\";i:11;s:10:\"created_by\";i:12;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:1;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.300\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.300\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";O:27:\"App\\Models\\Hotel\\Ingredient\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:11:\"ingredients\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:17:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:8:\"red rice\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"2.000\";s:9:\"unit_cost\";i:26000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:22:03\";s:10:\"updated_at\";s:19:\"2026-08-25 13:23:58\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:11:\"\0*\0original\";a:17:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:8:\"red rice\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"2.000\";s:9:\"unit_cost\";i:26000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:22:03\";s:10:\"updated_at\";s:19:\"2026-08-25 13:23:58\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:6:{s:10:\"deleted_at\";s:8:\"datetime\";s:9:\"stock_qty\";s:5:\"float\";s:19:\"low_stock_threshold\";s:5:\"float\";s:9:\"unit_cost\";s:7:\"integer\";s:13:\"selling_price\";s:7:\"integer\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:13:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:4:\"unit\";i:3;s:9:\"stock_qty\";i:4;s:19:\"low_stock_threshold\";i:5;s:9:\"unit_cost\";i:6;s:17:\"inventory_kind_id\";i:7;s:13:\"selling_price\";i:8;s:16:\"menu_category_id\";i:9;s:5:\"image\";i:10;s:6:\"active\";i:11;s:10:\"created_by\";i:12;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:2;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:3;s:4:\"name\";s:10:\"fried rice\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:100000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:3;s:4:\"name\";s:10:\"fried rice\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:100000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";r:64;s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:1:{i:0;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:3;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:3;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";r:409;}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:10:\"\0*\0perPage\";i:10;s:14:\"\0*\0currentPage\";i:1;s:7:\"\0*\0path\";s:53:\"https://mountview.hms.vellixglobal.com/api/menu/items\";s:8:\"\0*\0query\";a:4:{s:6:\"active\";s:4:\"true\";s:11:\"category_id\";N;s:1:\"q\";N;s:9:\"page_size\";s:2:\"10\";}s:11:\"\0*\0fragment\";N;s:11:\"\0*\0pageName\";s:4:\"page\";s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:10:\"onEachSide\";i:3;s:10:\"\0*\0options\";a:2:{s:4:\"path\";s:53:\"https://mountview.hms.vellixglobal.com/api/menu/items\";s:8:\"pageName\";s:4:\"page\";}s:8:\"\0*\0total\";i:3;s:11:\"\0*\0lastPage\";i:1;}s:5:\"stats\";a:3:{s:7:\"on_menu\";i:3;s:8:\"sold_out\";i:0;s:8:\"archived\";i:0;}}',1787649074),('menu_items.index.64f35521cedc67b0ae6a80b9ff07606b','a:2:{s:10:\"menu_items\";O:42:\"Illuminate\\Pagination\\LengthAwarePaginator\":12:{s:8:\"\0*\0items\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:3:{i:0;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:1;s:4:\"name\";s:3:\"tea\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";i:1;s:5:\"price\";i:15000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:7;s:10:\"created_at\";s:19:\"2026-08-25 01:27:45\";s:10:\"updated_at\";s:19:\"2026-08-25 01:27:45\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:1;s:4:\"name\";s:3:\"tea\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";i:1;s:5:\"price\";i:15000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:7;s:10:\"created_at\";s:19:\"2026-08-25 01:27:45\";s:10:\"updated_at\";s:19:\"2026-08-25 01:27:45\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";O:29:\"App\\Models\\Hotel\\MenuCategory\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:19:\"pos_menu_categories\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:12:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;}s:11:\"\0*\0original\";a:12:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:10:\"deleted_at\";s:8:\"datetime\";s:10:\"sort_order\";s:7:\"integer\";s:10:\"is_minibar\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:8:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:10:\"sort_order\";i:3;s:10:\"is_minibar\";i:4;s:18:\"kitchen_station_id\";i:5;s:6:\"active\";i:6;s:10:\"created_by\";i:7;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:1;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:2;s:4:\"name\";s:9:\"rice full\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:50000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:2;s:4:\"name\";s:9:\"rice full\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:50000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";r:64;s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:2:{i:0;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:4;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:4;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";O:27:\"App\\Models\\Hotel\\Ingredient\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:11:\"ingredients\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:17:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:7:\"chicken\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"1.000\";s:9:\"unit_cost\";i:125000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:25:10\";s:10:\"updated_at\";s:19:\"2026-08-25 13:25:32\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:11:\"\0*\0original\";a:17:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:7:\"chicken\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"1.000\";s:9:\"unit_cost\";i:125000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:25:10\";s:10:\"updated_at\";s:19:\"2026-08-25 13:25:32\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:6:{s:10:\"deleted_at\";s:8:\"datetime\";s:9:\"stock_qty\";s:5:\"float\";s:19:\"low_stock_threshold\";s:5:\"float\";s:9:\"unit_cost\";s:7:\"integer\";s:13:\"selling_price\";s:7:\"integer\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:13:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:4:\"unit\";i:3;s:9:\"stock_qty\";i:4;s:19:\"low_stock_threshold\";i:5;s:9:\"unit_cost\";i:6;s:17:\"inventory_kind_id\";i:7;s:13:\"selling_price\";i:8;s:16:\"menu_category_id\";i:9;s:5:\"image\";i:10;s:6:\"active\";i:11;s:10:\"created_by\";i:12;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:1;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.300\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:2;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.300\";s:10:\"created_at\";s:19:\"2026-08-25 14:07:58\";s:10:\"updated_at\";s:19:\"2026-08-25 14:07:58\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";O:27:\"App\\Models\\Hotel\\Ingredient\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:11:\"ingredients\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:17:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:8:\"red rice\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"2.000\";s:9:\"unit_cost\";i:26000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:22:03\";s:10:\"updated_at\";s:19:\"2026-08-25 13:23:58\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:11:\"\0*\0original\";a:17:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:17:\"inventory_kind_id\";i:1;s:4:\"name\";s:8:\"red rice\";s:4:\"unit\";s:2:\"kg\";s:9:\"stock_qty\";s:5:\"5.000\";s:19:\"low_stock_threshold\";s:5:\"2.000\";s:9:\"unit_cost\";i:26000;s:13:\"selling_price\";N;s:16:\"menu_category_id\";N;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 13:22:03\";s:10:\"updated_at\";s:19:\"2026-08-25 13:23:58\";s:10:\"deleted_at\";N;s:5:\"image\";N;s:6:\"active\";i:1;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:6:{s:10:\"deleted_at\";s:8:\"datetime\";s:9:\"stock_qty\";s:5:\"float\";s:19:\"low_stock_threshold\";s:5:\"float\";s:9:\"unit_cost\";s:7:\"integer\";s:13:\"selling_price\";s:7:\"integer\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:13:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:4:\"unit\";i:3;s:9:\"stock_qty\";i:4;s:19:\"low_stock_threshold\";i:5;s:9:\"unit_cost\";i:6;s:17:\"inventory_kind_id\";i:7;s:13:\"selling_price\";i:8;s:16:\"menu_category_id\";i:9;s:5:\"image\";i:10;s:6:\"active\";i:11;s:10:\"created_by\";i:12;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}i:2;O:25:\"App\\Models\\Hotel\\MenuItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:14:\"pos_menu_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:15:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:3;s:4:\"name\";s:10:\"fried rice\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:100000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:11:\"\0*\0original\";a:15:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:7:\"item_no\";i:3;s:4:\"name\";s:10:\"fried rice\";s:16:\"menu_category_id\";i:1;s:19:\"stock_ingredient_id\";N;s:5:\"price\";i:100000;s:11:\"description\";s:0:\"\";s:5:\"image\";N;s:8:\"sold_out\";i:0;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:7:\"item_no\";s:7:\"integer\";s:5:\"price\";s:7:\"integer\";s:8:\"sold_out\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:3:{s:8:\"category\";r:64;s:6:\"recipe\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:1:{i:0;O:27:\"App\\Models\\Hotel\\RecipeItem\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:12:\"recipe_items\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:7:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:3;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:11:\"\0*\0original\";a:7:{s:2:\"id\";i:3;s:9:\"tenant_id\";i:2;s:12:\"menu_item_id\";i:3;s:13:\"ingredient_id\";i:3;s:3:\"qty\";s:5:\"0.500\";s:10:\"created_at\";s:19:\"2026-08-25 14:15:18\";s:10:\"updated_at\";s:19:\"2026-08-25 14:15:18\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:1:{s:3:\"qty\";s:5:\"float\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:10:\"ingredient\";r:409;}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:4:{i:0;s:9:\"tenant_id\";i:1;s:12:\"menu_item_id\";i:2;s:13:\"ingredient_id\";i:3;s:3:\"qty\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:14:\"modifierGroups\";O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:0:{}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:12:{i:0;s:9:\"tenant_id\";i:1;s:7:\"item_no\";i:2;s:4:\"name\";i:3;s:16:\"menu_category_id\";i:4;s:5:\"price\";i:5;s:11:\"description\";i:6;s:5:\"image\";i:7;s:8:\"sold_out\";i:8;s:6:\"active\";i:9;s:19:\"stock_ingredient_id\";i:10;s:10:\"created_by\";i:11;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:10:\"\0*\0perPage\";i:10;s:14:\"\0*\0currentPage\";i:1;s:7:\"\0*\0path\";s:53:\"https://mountview.hms.vellixglobal.com/api/menu/items\";s:8:\"\0*\0query\";a:4:{s:6:\"active\";s:4:\"true\";s:11:\"category_id\";s:1:\"1\";s:1:\"q\";N;s:9:\"page_size\";s:2:\"10\";}s:11:\"\0*\0fragment\";N;s:11:\"\0*\0pageName\";s:4:\"page\";s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:10:\"onEachSide\";i:3;s:10:\"\0*\0options\";a:2:{s:4:\"path\";s:53:\"https://mountview.hms.vellixglobal.com/api/menu/items\";s:8:\"pageName\";s:4:\"page\";}s:8:\"\0*\0total\";i:3;s:11:\"\0*\0lastPage\";i:1;}s:5:\"stats\";a:3:{s:7:\"on_menu\";i:3;s:8:\"sold_out\";i:0;s:8:\"archived\";i:0;}}',1787647819),('pos.menu_categories','O:39:\"Illuminate\\Database\\Eloquent\\Collection\":2:{s:8:\"\0*\0items\";a:2:{i:0;O:29:\"App\\Models\\Hotel\\MenuCategory\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:19:\"pos_menu_categories\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:13:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;s:11:\"items_count\";i:3;}s:11:\"\0*\0original\";a:13:{s:2:\"id\";i:1;s:9:\"tenant_id\";i:2;s:4:\"name\";s:4:\"rice\";s:10:\"sort_order\";i:1;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";i:92;s:6:\"active\";i:1;s:10:\"created_by\";i:7;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 01:27:19\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;s:11:\"items_count\";i:3;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:10:\"deleted_at\";s:8:\"datetime\";s:10:\"sort_order\";s:7:\"integer\";s:10:\"is_minibar\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:14:\"kitchenStation\";O:17:\"App\\Models\\Lookup\":33:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:7:\"lookups\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:12:{s:2:\"id\";i:92;s:4:\"type\";s:15:\"kitchen_station\";s:4:\"code\";s:7:\"kitchen\";s:4:\"name\";s:7:\"Kitchen\";s:5:\"color\";s:6:\"orange\";s:10:\"sort_order\";i:0;s:9:\"is_active\";i:1;s:4:\"meta\";N;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:19:\"2026-08-24 00:16:42\";s:10:\"updated_at\";s:19:\"2026-08-24 00:16:42\";}s:11:\"\0*\0original\";a:12:{s:2:\"id\";i:92;s:4:\"type\";s:15:\"kitchen_station\";s:4:\"code\";s:7:\"kitchen\";s:4:\"name\";s:7:\"Kitchen\";s:5:\"color\";s:6:\"orange\";s:10:\"sort_order\";i:0;s:9:\"is_active\";i:1;s:4:\"meta\";N;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:19:\"2026-08-24 00:16:42\";s:10:\"updated_at\";s:19:\"2026-08-24 00:16:42\";}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:3:{s:9:\"is_active\";s:7:\"boolean\";s:4:\"meta\";s:5:\"array\";s:10:\"sort_order\";s:7:\"integer\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:0:{}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:9:{i:0;s:4:\"type\";i:1;s:4:\"code\";i:2;s:4:\"name\";i:3;s:5:\"color\";i:4;s:10:\"sort_order\";i:5;s:9:\"is_active\";i:6;s:4:\"meta\";i:7;s:10:\"created_by\";i:8;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}}}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:8:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:10:\"sort_order\";i:3;s:10:\"is_minibar\";i:4;s:18:\"kitchen_station_id\";i:5;s:6:\"active\";i:6;s:10:\"created_by\";i:7;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}i:1;O:29:\"App\\Models\\Hotel\\MenuCategory\":34:{s:13:\"\0*\0connection\";s:5:\"mysql\";s:8:\"\0*\0table\";s:19:\"pos_menu_categories\";s:13:\"\0*\0primaryKey\";s:2:\"id\";s:10:\"\0*\0keyType\";s:3:\"int\";s:12:\"incrementing\";b:1;s:7:\"\0*\0with\";a:0:{}s:12:\"\0*\0withCount\";a:0:{}s:19:\"preventsLazyLoading\";b:0;s:10:\"\0*\0perPage\";i:15;s:6:\"exists\";b:1;s:18:\"wasRecentlyCreated\";b:0;s:28:\"\0*\0escapeWhenCastingToString\";b:0;s:13:\"\0*\0attributes\";a:13:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:4:\"name\";s:12:\"Chinese item\";s:10:\"sort_order\";i:2;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";N;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;s:11:\"items_count\";i:0;}s:11:\"\0*\0original\";a:13:{s:2:\"id\";i:4;s:9:\"tenant_id\";i:2;s:4:\"name\";s:12:\"Chinese item\";s:10:\"sort_order\";i:2;s:10:\"is_minibar\";i:0;s:18:\"kitchen_station_id\";N;s:6:\"active\";i:1;s:10:\"created_by\";i:9;s:10:\"updated_by\";i:9;s:10:\"created_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"updated_at\";s:19:\"2026-08-25 14:03:24\";s:10:\"deleted_at\";N;s:11:\"items_count\";i:0;}s:10:\"\0*\0changes\";a:0:{}s:11:\"\0*\0previous\";a:0:{}s:8:\"\0*\0casts\";a:4:{s:10:\"deleted_at\";s:8:\"datetime\";s:10:\"sort_order\";s:7:\"integer\";s:10:\"is_minibar\";s:7:\"boolean\";s:6:\"active\";s:7:\"boolean\";}s:17:\"\0*\0classCastCache\";a:0:{}s:21:\"\0*\0attributeCastCache\";a:0:{}s:13:\"\0*\0dateFormat\";N;s:10:\"\0*\0appends\";a:0:{}s:19:\"\0*\0dispatchesEvents\";a:0:{}s:14:\"\0*\0observables\";a:0:{}s:12:\"\0*\0relations\";a:1:{s:14:\"kitchenStation\";N;}s:10:\"\0*\0touches\";a:0:{}s:27:\"\0*\0relationAutoloadCallback\";N;s:26:\"\0*\0relationAutoloadContext\";N;s:10:\"timestamps\";b:1;s:13:\"usesUniqueIds\";b:0;s:9:\"\0*\0hidden\";a:0:{}s:10:\"\0*\0visible\";a:0:{}s:11:\"\0*\0fillable\";a:8:{i:0;s:9:\"tenant_id\";i:1;s:4:\"name\";i:2;s:10:\"sort_order\";i:3;s:10:\"is_minibar\";i:4;s:18:\"kitchen_station_id\";i:5;s:6:\"active\";i:6;s:10:\"created_by\";i:7;s:10:\"updated_by\";}s:10:\"\0*\0guarded\";a:1:{i:0;s:1:\"*\";}s:16:\"\0*\0forceDeleting\";b:0;}}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}',1787656442),('settings:all:2','a:79:{s:10:\"hotel.name\";s:16:\"Mount View Hotel\";s:13:\"hotel.tagline\";s:29:\"Hospitality Management System\";s:19:\"hotel.login_tagline\";s:29:\"Hospitality Management System\";s:13:\"hotel.address\";s:22:\"⚠ confirm with owner\";s:11:\"hotel.phone\";s:22:\"⚠ confirm with owner\";s:11:\"hotel.email\";s:22:\"⚠ confirm with owner\";s:16:\"hotel.tax_reg_no\";N;s:13:\"hotel.website\";s:0:\"\";s:14:\"hotel.logo_url\";s:0:\"\";s:14:\"hotel.timezone\";s:12:\"Asia/Colombo\";s:12:\"hotel.locale\";s:2:\"en\";s:19:\"hotel.currency_code\";s:3:\"LKR\";s:21:\"hotel.currency_symbol\";s:3:\"Rs.\";s:13:\"theme.primary\";s:7:\"#059669\";s:15:\"theme.secondary\";s:7:\"#10b981\";s:13:\"theme.sidebar\";s:7:\"#064e3b\";s:23:\"frontdesk.check_in_time\";s:5:\"14:00\";s:24:\"frontdesk.check_out_time\";s:5:\"12:00\";s:31:\"billing.early_checkin_surcharge\";i:0;s:31:\"billing.late_checkout_surcharge\";i:0;s:15:\"billing.vat_pct\";i:0;s:26:\"billing.service_charge_pct\";i:0;s:25:\"billing.room_deposit_mode\";s:10:\"percentage\";s:24:\"billing.room_deposit_pct\";i:20;s:26:\"billing.room_deposit_fixed\";i:0;s:26:\"billing.venue_deposit_mode\";s:10:\"percentage\";s:25:\"billing.venue_deposit_pct\";i:25;s:27:\"billing.venue_deposit_fixed\";i:0;s:17:\"currency.usd_rate\";i:300;s:32:\"policies.children_free_under_age\";i:4;s:25:\"policies.parking_capacity\";i:10;s:20:\"policies.wifi_policy\";s:0:\"\";s:28:\"policies.cancellation_policy\";s:0:\"\";s:27:\"policies.cancellation_rules\";a:3:{i:0;a:2:{s:10:\"daysBefore\";i:7;s:9:\"refundPct\";i:100;}i:1;a:2:{s:10:\"daysBefore\";i:3;s:9:\"refundPct\";i:50;}i:2;a:2:{s:10:\"daysBefore\";i:0;s:9:\"refundPct\";i:0;}}s:20:\"pricing.weekend_days\";a:2:{i:0;i:0;i:1;i:6;}s:23:\"pricing.public_holidays\";a:0:{}s:26:\"loyalty.points_per_1000lkr\";i:0;s:25:\"loyalty.point_value_cents\";i:100;s:26:\"loyalty.redemption_catalog\";a:0:{}s:30:\"notifications.pre_arrival_days\";i:1;s:22:\"notifications.channels\";a:3:{i:0;s:5:\"email\";i:1;s:8:\"whatsapp\";i:2;s:3:\"sms\";}s:24:\"payroll.epf_employee_pct\";i:8;s:24:\"payroll.epf_employer_pct\";i:12;s:15:\"payroll.etf_pct\";i:3;s:30:\"payroll.standard_monthly_hours\";i:200;s:21:\"payroll.apit_brackets\";a:6:{i:0;a:2:{s:5:\"width\";i:15000000;s:4:\"rate\";i:0;}i:1;a:2:{s:5:\"width\";d:8333333.333333333;s:4:\"rate\";i:6;}i:2;a:2:{s:5:\"width\";d:4166666.6666666665;s:4:\"rate\";i:18;}i:3;a:2:{s:5:\"width\";d:4166666.6666666665;s:4:\"rate\";i:24;}i:4;a:2:{s:5:\"width\";d:4166666.6666666665;s:4:\"rate\";i:30;}i:5;a:2:{s:5:\"width\";N;s:4:\"rate\";i:36;}}s:19:\"qr_ordering.enabled\";b:1;s:27:\"qr_ordering.welcome_message\";s:20:\"Scan. Browse. Order.\";s:24:\"qr_ordering.accent_color\";s:7:\"#0462d3\";s:24:\"qr_ordering.banner_image\";s:0:\"\";s:28:\"qr_ordering.show_item_images\";b:1;s:29:\"qr_ordering.show_descriptions\";b:1;s:33:\"qr_ordering.collect_customer_name\";b:1;s:34:\"qr_ordering.collect_customer_phone\";b:0;s:23:\"qr_ordering.footer_note\";s:41:\"Prices are inclusive of applicable taxes.\";s:26:\"inventory.expiry_warn_days\";i:3;s:22:\"apartment.deposit_mode\";s:10:\"percentage\";s:21:\"apartment.deposit_pct\";i:20;s:23:\"apartment.deposit_fixed\";i:0;s:17:\"apartment.vat_pct\";i:0;s:28:\"apartment.service_charge_pct\";i:0;s:33:\"apartment.late_checkout_surcharge\";i:0;s:38:\"apartment.weekly_stay_threshold_nights\";i:7;s:39:\"apartment.monthly_stay_threshold_nights\";i:28;s:36:\"apartment.sale_reservation_hold_days\";i:14;s:34:\"apartment.sale_deposit_forfeit_pct\";i:100;s:28:\"apartment.cancellation_rules\";a:3:{i:0;a:2:{s:10:\"daysBefore\";i:7;s:9:\"refundPct\";i:100;}i:1;a:2:{s:10:\"daysBefore\";i:3;s:9:\"refundPct\";i:50;}i:2;a:2:{s:10:\"daysBefore\";i:0;s:9:\"refundPct\";i:0;}}s:29:\"integrations.whatsapp_enabled\";b:0;s:29:\"integrations.whatsapp_api_url\";s:0:\"\";s:31:\"integrations.whatsapp_api_token\";s:0:\"\";s:24:\"integrations.sms_enabled\";b:0;s:24:\"integrations.sms_api_url\";s:0:\"\";s:24:\"integrations.sms_api_key\";s:0:\"\";s:26:\"integrations.sms_sender_id\";s:9:\"MountView\";s:32:\"integrations.bookingcom_hotel_id\";s:0:\"\";s:31:\"integrations.bookingcom_api_key\";s:0:\"\";s:29:\"integrations.gateway_provider\";s:7:\"payhere\";s:32:\"integrations.gateway_merchant_id\";s:0:\"\";s:27:\"integrations.gateway_secret\";s:0:\"\";}',2102926913),('tenant:2:enabled_modules','O:29:\"Illuminate\\Support\\Collection\":2:{s:8:\"\0*\0items\";a:4:{i:0;s:16:\"hotel_operations\";i:1;s:7:\"payroll\";i:2;s:14:\"restaurant_pos\";i:3;s:4:\"till\";}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}',1787670839),('user:7:full_admin','b:1;',1787652626),('user:7:permissions','O:29:\"Illuminate\\Support\\Collection\":2:{s:8:\"\0*\0items\";a:225:{i:0;s:16:\"dashboard.access\";i:1;s:28:\"user_management_users.access\";i:2;s:26:\"user_management_users.view\";i:3;s:28:\"user_management_users.create\";i:4;s:26:\"user_management_users.edit\";i:5;s:28:\"user_management_users.delete\";i:6;s:33:\"user_management_users.bulk_delete\";i:7;s:28:\"user_management_users.unlock\";i:8;s:36:\"user_management_users.reset_password\";i:9;s:28:\"user_management_roles.access\";i:10;s:26:\"user_management_roles.view\";i:11;s:28:\"user_management_roles.create\";i:12;s:26:\"user_management_roles.edit\";i:13;s:28:\"user_management_roles.delete\";i:14;s:31:\"user_management_roles.duplicate\";i:15;s:35:\"user_management_roles.toggle_active\";i:16;s:17:\"audit_logs.access\";i:17;s:15:\"audit_logs.view\";i:18;s:17:\"audit_logs.export\";i:19;s:18:\"hotel_rooms.access\";i:20;s:18:\"hotel_rooms.create\";i:21;s:16:\"hotel_rooms.edit\";i:22;s:23:\"hotel_rooms.edit_status\";i:23;s:18:\"hotel_rooms.delete\";i:24;s:21:\"hotel_packages.access\";i:25;s:19:\"hotel_packages.edit\";i:26;s:19:\"hotel_guests.access\";i:27;s:17:\"hotel_guests.view\";i:28;s:19:\"hotel_guests.create\";i:29;s:17:\"hotel_guests.edit\";i:30;s:27:\"hotel_guests.loyalty_adjust\";i:31;s:22:\"hotel_corporate.access\";i:32;s:22:\"hotel_corporate.create\";i:33;s:20:\"hotel_corporate.edit\";i:34;s:25:\"hotel_reservations.access\";i:35;s:23:\"hotel_reservations.view\";i:36;s:25:\"hotel_reservations.create\";i:37;s:23:\"hotel_reservations.edit\";i:38;s:27:\"hotel_reservations.check_in\";i:39;s:27:\"hotel_reservations.checkout\";i:40;s:25:\"hotel_reservations.cancel\";i:41;s:27:\"hotel_reservations.discount\";i:42;s:34:\"hotel_reservations.early_departure\";i:43;s:17:\"hotel_folios.view\";i:44;s:21:\"hotel_folios.add_line\";i:45;s:22:\"hotel_folios.void_line\";i:46;s:20:\"hotel_folios.payment\";i:47;s:19:\"hotel_folios.refund\";i:48;s:20:\"hotel_folios.invoice\";i:49;s:28:\"hotel_menu_categories.access\";i:50;s:28:\"hotel_menu_categories.create\";i:51;s:26:\"hotel_menu_categories.edit\";i:52;s:28:\"hotel_menu_categories.delete\";i:53;s:23:\"hotel_menu_items.access\";i:54;s:23:\"hotel_menu_items.create\";i:55;s:21:\"hotel_menu_items.edit\";i:56;s:23:\"hotel_menu_items.delete\";i:57;s:25:\"hotel_menu_items.sold_out\";i:58;s:24:\"hotel_ingredients.access\";i:59;s:24:\"hotel_ingredients.create\";i:60;s:22:\"hotel_ingredients.edit\";i:61;s:24:\"hotel_ingredients.delete\";i:62;s:30:\"hotel_ingredients.adjust_stock\";i:63;s:27:\"hotel_ingredients.write_off\";i:64;s:21:\"hotel_products.access\";i:65;s:21:\"hotel_products.create\";i:66;s:19:\"hotel_products.edit\";i:67;s:21:\"hotel_products.delete\";i:68;s:27:\"hotel_products.adjust_stock\";i:69;s:16:\"hotel_grn.access\";i:70;s:14:\"hotel_grn.view\";i:71;s:16:\"hotel_grn.create\";i:72;s:14:\"hotel_grn.edit\";i:73;s:16:\"hotel_grn.delete\";i:74;s:17:\"hotel_grn.receive\";i:75;s:19:\"hotel_orders.access\";i:76;s:17:\"hotel_orders.view\";i:77;s:19:\"hotel_orders.create\";i:78;s:16:\"hotel_orders.kot\";i:79;s:22:\"hotel_orders.void_item\";i:80;s:17:\"hotel_orders.hold\";i:81;s:21:\"hotel_orders.discount\";i:82;s:19:\"hotel_orders.settle\";i:83;s:27:\"hotel_orders.charge_to_room\";i:84;s:17:\"hotel_orders.void\";i:85;s:19:\"hotel_orders.refund\";i:86;s:20:\"hotel_orders.receipt\";i:87;s:17:\"hotel_orders.slip\";i:88;s:23:\"hotel_orders.kot_ticket\";i:89;s:18:\"hotel_orders.split\";i:90;s:18:\"hotel_orders.merge\";i:91;s:30:\"hotel_orders.delivery_dispatch\";i:92;s:26:\"hotel_dining_tables.access\";i:93;s:26:\"hotel_dining_tables.create\";i:94;s:24:\"hotel_dining_tables.edit\";i:95;s:31:\"hotel_dining_tables.edit_status\";i:96;s:26:\"hotel_dining_tables.delete\";i:97;s:24:\"hotel_qr_ordering.access\";i:98;s:24:\"hotel_qr_ordering.create\";i:99;s:22:\"hotel_qr_ordering.edit\";i:100;s:28:\"hotel_qr_ordering.regenerate\";i:101;s:25:\"hotel_housekeeping.access\";i:102;s:25:\"hotel_housekeeping.create\";i:103;s:25:\"hotel_housekeeping.assign\";i:104;s:28:\"hotel_housekeeping.checklist\";i:105;s:27:\"hotel_housekeeping.complete\";i:106;s:24:\"hotel_maintenance.access\";i:107;s:24:\"hotel_maintenance.create\";i:108;s:22:\"hotel_maintenance.edit\";i:109;s:20:\"hotel_laundry.access\";i:110;s:20:\"hotel_laundry.create\";i:111;s:18:\"hotel_laundry.edit\";i:112;s:20:\"hotel_laundry.charge\";i:113;s:19:\"hotel_venues.access\";i:114;s:17:\"hotel_venues.edit\";i:115;s:27:\"hotel_venue_bookings.access\";i:116;s:25:\"hotel_venue_bookings.view\";i:117;s:27:\"hotel_venue_bookings.create\";i:118;s:25:\"hotel_venue_bookings.edit\";i:119;s:28:\"hotel_venue_bookings.confirm\";i:120;s:29:\"hotel_venue_bookings.complete\";i:121;s:27:\"hotel_venue_bookings.cancel\";i:122;s:11:\"till.access\";i:123;s:9:\"till.open\";i:124;s:10:\"till.close\";i:125;s:14:\"till.close_any\";i:126;s:12:\"till.cash_in\";i:127;s:13:\"till.cash_out\";i:128;s:11:\"till.manage\";i:129;s:23:\"hotel_attendance.access\";i:130;s:24:\"hotel_attendance.on_duty\";i:131;s:25:\"hotel_attendance.view_all\";i:132;s:23:\"hotel_attendance.export\";i:133;s:24:\"hotel_payroll.manage_pay\";i:134;s:18:\"hotel_payroll.view\";i:135;s:22:\"hotel_payroll.generate\";i:136;s:25:\"hotel_payroll.adjust_line\";i:137;s:22:\"hotel_payroll.finalize\";i:138;s:24:\"hotel_payroll.delete_run\";i:139;s:23:\"hotel_payroll.mark_paid\";i:140;s:20:\"hotel_payroll.export\";i:141;s:21:\"hotel_payroll.payslip\";i:142;s:21:\"hotel_visitors.access\";i:143;s:21:\"hotel_visitors.create\";i:144;s:23:\"hotel_visitors.sign_out\";i:145;s:26:\"hotel_notifications.access\";i:146;s:24:\"hotel_notifications.test\";i:147;s:33:\"hotel_notifications.run_scheduled\";i:148;s:23:\"hotel_reports.dashboard\";i:149;s:19:\"hotel_reports.daily\";i:150;s:21:\"hotel_reports.monthly\";i:151;s:29:\"hotel_reports.night_audit_run\";i:152;s:30:\"hotel_reports.night_audit_view\";i:153;s:20:\"hotel_reports.revpar\";i:154;s:25:\"hotel_reports.channel_mix\";i:155;s:27:\"hotel_reports.cancellations\";i:156;s:27:\"hotel_reports.guest_loyalty\";i:157;s:26:\"hotel_reports.corporate_ar\";i:158;s:21:\"hotel_reports.ops_sla\";i:159;s:26:\"hotel_reports.payroll_cost\";i:160;s:20:\"hotel_reports.venues\";i:161;s:21:\"hotel_reports.laundry\";i:162;s:22:\"restaurant_reports.pos\";i:163;s:35:\"restaurant_reports.menu_performance\";i:164;s:28:\"restaurant_reports.modifiers\";i:165;s:34:\"restaurant_reports.discounts_voids\";i:166;s:31:\"restaurant_reports.table_server\";i:167;s:39:\"restaurant_reports.delivery_performance\";i:168;s:38:\"restaurant_reports.kitchen_ticket_time\";i:169;s:30:\"restaurant_reports.shift_sales\";i:170;s:28:\"restaurant_reports.food_cost\";i:171;s:19:\"hotel_staff.set_pin\";i:172;s:27:\"apartment_properties.access\";i:173;s:27:\"apartment_properties.create\";i:174;s:25:\"apartment_properties.edit\";i:175;s:27:\"apartment_unit_types.access\";i:176;s:27:\"apartment_unit_types.create\";i:177;s:25:\"apartment_unit_types.edit\";i:178;s:22:\"apartment_units.access\";i:179;s:22:\"apartment_units.create\";i:180;s:20:\"apartment_units.edit\";i:181;s:27:\"apartment_units.edit_status\";i:182;s:26:\"apartment_customers.access\";i:183;s:24:\"apartment_customers.view\";i:184;s:26:\"apartment_customers.create\";i:185;s:24:\"apartment_customers.edit\";i:186;s:25:\"apartment_bookings.access\";i:187;s:23:\"apartment_bookings.view\";i:188;s:25:\"apartment_bookings.create\";i:189;s:27:\"apartment_bookings.check_in\";i:190;s:27:\"apartment_bookings.checkout\";i:191;s:25:\"apartment_bookings.cancel\";i:192;s:23:\"apartment_leases.access\";i:193;s:21:\"apartment_leases.view\";i:194;s:23:\"apartment_leases.create\";i:195;s:22:\"apartment_leases.renew\";i:196;s:26:\"apartment_leases.terminate\";i:197;s:32:\"apartment_leases.utility_reading\";i:198;s:22:\"apartment_sales.access\";i:199;s:20:\"apartment_sales.view\";i:200;s:22:\"apartment_sales.create\";i:201;s:23:\"apartment_sales.reserve\";i:202;s:30:\"apartment_sales.sign_agreement\";i:203;s:24:\"apartment_sales.complete\";i:204;s:22:\"apartment_sales.cancel\";i:205;s:22:\"apartment_ledgers.view\";i:206;s:26:\"apartment_ledgers.add_line\";i:207;s:27:\"apartment_ledgers.void_line\";i:208;s:25:\"apartment_ledgers.payment\";i:209;s:24:\"apartment_ledgers.refund\";i:210;s:29:\"apartment_housekeeping.access\";i:211;s:29:\"apartment_housekeeping.create\";i:212;s:29:\"apartment_housekeeping.assign\";i:213;s:32:\"apartment_housekeeping.checklist\";i:214;s:31:\"apartment_housekeeping.complete\";i:215;s:28:\"apartment_maintenance.access\";i:216;s:28:\"apartment_maintenance.create\";i:217;s:26:\"apartment_maintenance.edit\";i:218;s:27:\"apartment_reports.dashboard\";i:219;s:33:\"apartment_reports.occupancy_trend\";i:220;s:33:\"apartment_reports.revenue_channel\";i:221;s:27:\"apartment_reports.rent_roll\";i:222;s:32:\"apartment_reports.sales_pipeline\";i:223;s:27:\"apartment_reports.utilities\";i:224;s:25:\"apartment_reports.ops_sla\";}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}',1787652626),('user:9:full_admin','b:0;',1787670839),('user:9:permissions','O:29:\"Illuminate\\Support\\Collection\":2:{s:8:\"\0*\0items\";a:153:{i:0;s:18:\"hotel_rooms.access\";i:1;s:18:\"hotel_rooms.create\";i:2;s:16:\"hotel_rooms.edit\";i:3;s:23:\"hotel_rooms.edit_status\";i:4;s:18:\"hotel_rooms.delete\";i:5;s:21:\"hotel_packages.access\";i:6;s:19:\"hotel_packages.edit\";i:7;s:19:\"hotel_guests.access\";i:8;s:17:\"hotel_guests.view\";i:9;s:19:\"hotel_guests.create\";i:10;s:17:\"hotel_guests.edit\";i:11;s:27:\"hotel_guests.loyalty_adjust\";i:12;s:22:\"hotel_corporate.access\";i:13;s:22:\"hotel_corporate.create\";i:14;s:20:\"hotel_corporate.edit\";i:15;s:25:\"hotel_reservations.access\";i:16;s:23:\"hotel_reservations.view\";i:17;s:25:\"hotel_reservations.create\";i:18;s:23:\"hotel_reservations.edit\";i:19;s:27:\"hotel_reservations.check_in\";i:20;s:27:\"hotel_reservations.checkout\";i:21;s:25:\"hotel_reservations.cancel\";i:22;s:27:\"hotel_reservations.discount\";i:23;s:34:\"hotel_reservations.early_departure\";i:24;s:17:\"hotel_folios.view\";i:25;s:21:\"hotel_folios.add_line\";i:26;s:22:\"hotel_folios.void_line\";i:27;s:20:\"hotel_folios.payment\";i:28;s:19:\"hotel_folios.refund\";i:29;s:20:\"hotel_folios.invoice\";i:30;s:28:\"hotel_menu_categories.access\";i:31;s:28:\"hotel_menu_categories.create\";i:32;s:26:\"hotel_menu_categories.edit\";i:33;s:28:\"hotel_menu_categories.delete\";i:34;s:23:\"hotel_menu_items.access\";i:35;s:23:\"hotel_menu_items.create\";i:36;s:21:\"hotel_menu_items.edit\";i:37;s:23:\"hotel_menu_items.delete\";i:38;s:25:\"hotel_menu_items.sold_out\";i:39;s:24:\"hotel_ingredients.access\";i:40;s:24:\"hotel_ingredients.create\";i:41;s:22:\"hotel_ingredients.edit\";i:42;s:24:\"hotel_ingredients.delete\";i:43;s:30:\"hotel_ingredients.adjust_stock\";i:44;s:27:\"hotel_ingredients.write_off\";i:45;s:21:\"hotel_products.access\";i:46;s:21:\"hotel_products.create\";i:47;s:19:\"hotel_products.edit\";i:48;s:21:\"hotel_products.delete\";i:49;s:27:\"hotel_products.adjust_stock\";i:50;s:16:\"hotel_grn.access\";i:51;s:14:\"hotel_grn.view\";i:52;s:16:\"hotel_grn.create\";i:53;s:14:\"hotel_grn.edit\";i:54;s:16:\"hotel_grn.delete\";i:55;s:17:\"hotel_grn.receive\";i:56;s:19:\"hotel_orders.access\";i:57;s:17:\"hotel_orders.view\";i:58;s:19:\"hotel_orders.create\";i:59;s:16:\"hotel_orders.kot\";i:60;s:22:\"hotel_orders.void_item\";i:61;s:17:\"hotel_orders.hold\";i:62;s:21:\"hotel_orders.discount\";i:63;s:19:\"hotel_orders.settle\";i:64;s:27:\"hotel_orders.charge_to_room\";i:65;s:17:\"hotel_orders.void\";i:66;s:19:\"hotel_orders.refund\";i:67;s:20:\"hotel_orders.receipt\";i:68;s:17:\"hotel_orders.slip\";i:69;s:23:\"hotel_orders.kot_ticket\";i:70;s:18:\"hotel_orders.split\";i:71;s:18:\"hotel_orders.merge\";i:72;s:30:\"hotel_orders.delivery_dispatch\";i:73;s:26:\"hotel_dining_tables.access\";i:74;s:26:\"hotel_dining_tables.create\";i:75;s:24:\"hotel_dining_tables.edit\";i:76;s:31:\"hotel_dining_tables.edit_status\";i:77;s:26:\"hotel_dining_tables.delete\";i:78;s:25:\"hotel_housekeeping.access\";i:79;s:25:\"hotel_housekeeping.create\";i:80;s:25:\"hotel_housekeeping.assign\";i:81;s:28:\"hotel_housekeeping.checklist\";i:82;s:27:\"hotel_housekeeping.complete\";i:83;s:24:\"hotel_maintenance.access\";i:84;s:24:\"hotel_maintenance.create\";i:85;s:22:\"hotel_maintenance.edit\";i:86;s:20:\"hotel_laundry.access\";i:87;s:20:\"hotel_laundry.create\";i:88;s:18:\"hotel_laundry.edit\";i:89;s:20:\"hotel_laundry.charge\";i:90;s:19:\"hotel_venues.access\";i:91;s:17:\"hotel_venues.edit\";i:92;s:27:\"hotel_venue_bookings.access\";i:93;s:25:\"hotel_venue_bookings.view\";i:94;s:27:\"hotel_venue_bookings.create\";i:95;s:25:\"hotel_venue_bookings.edit\";i:96;s:28:\"hotel_venue_bookings.confirm\";i:97;s:29:\"hotel_venue_bookings.complete\";i:98;s:27:\"hotel_venue_bookings.cancel\";i:99;s:11:\"till.access\";i:100;s:9:\"till.open\";i:101;s:10:\"till.close\";i:102;s:14:\"till.close_any\";i:103;s:12:\"till.cash_in\";i:104;s:13:\"till.cash_out\";i:105;s:11:\"till.manage\";i:106;s:23:\"hotel_attendance.access\";i:107;s:24:\"hotel_attendance.on_duty\";i:108;s:25:\"hotel_attendance.view_all\";i:109;s:23:\"hotel_attendance.export\";i:110;s:21:\"hotel_visitors.access\";i:111;s:21:\"hotel_visitors.create\";i:112;s:23:\"hotel_visitors.sign_out\";i:113;s:26:\"hotel_notifications.access\";i:114;s:33:\"hotel_notifications.run_scheduled\";i:115;s:23:\"hotel_reports.dashboard\";i:116;s:19:\"hotel_reports.daily\";i:117;s:21:\"hotel_reports.monthly\";i:118;s:29:\"hotel_reports.night_audit_run\";i:119;s:30:\"hotel_reports.night_audit_view\";i:120;s:20:\"hotel_reports.revpar\";i:121;s:25:\"hotel_reports.channel_mix\";i:122;s:27:\"hotel_reports.cancellations\";i:123;s:27:\"hotel_reports.guest_loyalty\";i:124;s:26:\"hotel_reports.corporate_ar\";i:125;s:21:\"hotel_reports.ops_sla\";i:126;s:20:\"hotel_reports.venues\";i:127;s:21:\"hotel_reports.laundry\";i:128;s:22:\"restaurant_reports.pos\";i:129;s:35:\"restaurant_reports.menu_performance\";i:130;s:28:\"restaurant_reports.modifiers\";i:131;s:34:\"restaurant_reports.discounts_voids\";i:132;s:31:\"restaurant_reports.table_server\";i:133;s:39:\"restaurant_reports.delivery_performance\";i:134;s:38:\"restaurant_reports.kitchen_ticket_time\";i:135;s:30:\"restaurant_reports.shift_sales\";i:136;s:28:\"restaurant_reports.food_cost\";i:137;s:19:\"hotel_staff.set_pin\";i:138;s:24:\"hotel_qr_ordering.access\";i:139;s:24:\"hotel_qr_ordering.create\";i:140;s:22:\"hotel_qr_ordering.edit\";i:141;s:28:\"hotel_qr_ordering.regenerate\";i:142;s:24:\"hotel_payroll.manage_pay\";i:143;s:18:\"hotel_payroll.view\";i:144;s:22:\"hotel_payroll.generate\";i:145;s:25:\"hotel_payroll.adjust_line\";i:146;s:22:\"hotel_payroll.finalize\";i:147;s:24:\"hotel_payroll.delete_run\";i:148;s:23:\"hotel_payroll.mark_paid\";i:149;s:20:\"hotel_payroll.export\";i:150;s:21:\"hotel_payroll.payslip\";i:151;s:24:\"hotel_notifications.test\";i:152;s:26:\"hotel_reports.payroll_cost\";}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}',1787670839);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `central_admins`
--

DROP TABLE IF EXISTS `central_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `central_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `central_admins_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `central_admins`
--

LOCK TABLES `central_admins` WRITE;
/*!40000 ALTER TABLE `central_admins` DISABLE KEYS */;
INSERT INTO `central_admins` VALUES (1,'Platform Operator','admin@vellix.com','$2y$12$mzOAr0gJRU6cCMR7czCb7.kuryBEWpZ65KtuTk9s4U7UeYsu2h2NS',1,NULL,'2026-08-24 04:16:33','2026-08-24 04:16:33');
/*!40000 ALTER TABLE `central_admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `corporate_accounts`
--

DROP TABLE IF EXISTS `corporate_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `corporate_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '% off room rates',
  `credit_limit` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents, 0 = unlimited',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `corporate_accounts_created_by_foreign` (`created_by`),
  KEY `corporate_accounts_updated_by_foreign` (`updated_by`),
  KEY `corporate_accounts_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `corporate_accounts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `corporate_accounts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `corporate_accounts_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `corporate_accounts`
--

LOCK TABLES `corporate_accounts` WRITE;
/*!40000 ALTER TABLE `corporate_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `corporate_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_tokens`
--

DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_token_hash_unique` (`token_hash`),
  KEY `device_tokens_user_id_index` (`user_id`),
  KEY `device_tokens_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `device_tokens_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `device_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_tokens`
--

LOCK TABLES `device_tokens` WRITE;
/*!40000 ALTER TABLE `device_tokens` DISABLE KEYS */;
INSERT INTO `device_tokens` VALUES (1,2,7,'a7434e8abe61f67bc1555fd5251848f484987aad7e5aa4a52b2268fdd6e017a2','2026-10-23 19:47:23','2026-08-24 19:47:23'),(2,2,7,'fa4f0493fde15afe1ec6dbcf4a8419da81137f11236d4c6a89ff89e94fea5868','2026-10-24 02:26:20','2026-08-25 02:26:20'),(3,2,7,'bf5b2625d9d425a764f9609f442cb97e682c55820f7f90f1c220c589b390a6e5','2026-10-24 03:14:22','2026-08-25 03:14:22'),(4,2,7,'cd4e469927f11f59f37fabd48fe93d0d69d6b1b6601770433a67e2f06905590a','2026-10-24 15:25:12','2026-08-25 15:25:12'),(5,2,7,'1cf735b4c6b481b24b4ba5c458938cc254efae17e4e0d91c636644e3b12e7a73','2026-10-24 15:28:54','2026-08-25 15:28:54'),(6,2,9,'25be8a1863d142a0aaf9007ac99b0fce81f1d37e7bff75a0f58b0b26300335c8','2026-10-24 16:38:39','2026-08-25 16:38:39'),(7,2,7,'04fc6d102ff8905e326c3ce58003d0d5217f58d82b9848f5478acc6b0b0b0053','2026-10-24 17:21:47','2026-08-25 17:21:47'),(8,2,9,'f58a6717f94b36738ef139b260bb08273146e503d44ba9ebb4adaa7ba6abb5d5','2026-10-24 18:35:36','2026-08-25 18:35:36'),(9,2,7,'7cd05db11e78195b97643a9cef9e0ffdfc3340b3a5f739aa9091809c62ce2780','2026-10-24 18:41:07','2026-08-25 18:41:07'),(10,2,9,'ab2625fe734e0573130ee7fb06ee5e1307c9bed8c67391ef0c01480d33572718','2026-10-24 18:42:00','2026-08-25 18:42:00'),(11,2,9,'020764a3c5e220b924f2699bb5c558ab9c976b16eb53d14a6e88c6b57fabde8c','2026-10-24 18:42:44','2026-08-25 18:42:44'),(12,2,9,'ad4c43cde1cb7ee9b8d56aa9a750d748489de8ea3b8f995f3ed21e7dfe8e0bab','2026-10-24 23:44:00','2026-08-25 23:44:00');
/*!40000 ALTER TABLE `device_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dining_areas`
--

DROP TABLE IF EXISTS `dining_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dining_areas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dining_areas_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `dining_areas_created_by_foreign` (`created_by`),
  KEY `dining_areas_updated_by_foreign` (`updated_by`),
  CONSTRAINT `dining_areas_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dining_areas_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dining_areas_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dining_areas`
--

LOCK TABLES `dining_areas` WRITE;
/*!40000 ALTER TABLE `dining_areas` DISABLE KEYS */;
/*!40000 ALTER TABLE `dining_areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dining_tables`
--

DROP TABLE IF EXISTS `dining_tables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dining_tables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `table_no` varchar(255) NOT NULL,
  `dining_area_id` bigint(20) unsigned DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 2,
  `table_status_id` bigint(20) unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dining_tables_tenant_id_table_no_unique` (`tenant_id`,`table_no`),
  KEY `dining_tables_dining_area_id_foreign` (`dining_area_id`),
  KEY `dining_tables_created_by_foreign` (`created_by`),
  KEY `dining_tables_updated_by_foreign` (`updated_by`),
  KEY `dining_tables_table_status_id_index` (`table_status_id`),
  KEY `table_area_status_idx` (`tenant_id`,`dining_area_id`,`table_status_id`),
  CONSTRAINT `dining_tables_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dining_tables_dining_area_id_foreign` FOREIGN KEY (`dining_area_id`) REFERENCES `dining_areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dining_tables_table_status_id_foreign` FOREIGN KEY (`table_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `dining_tables_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dining_tables_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dining_tables`
--

LOCK TABLES `dining_tables` WRITE;
/*!40000 ALTER TABLE `dining_tables` DISABLE KEYS */;
INSERT INTO `dining_tables` VALUES (1,2,'1',NULL,4,84,NULL,7,7,'2026-08-25 05:29:29','2026-08-25 05:29:34','2026-08-25 05:29:34');
/*!40000 ALTER TABLE `dining_tables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `folio_lines`
--

DROP TABLE IF EXISTS `folio_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `folio_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `folio_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `line_source_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` int(11) NOT NULL COMMENT 'LKR cents — negative for discounts/redemptions',
  `amount` int(11) NOT NULL COMMENT 'qty * unit_price, LKR cents — negative for discounts/redemptions',
  `staff_id` bigint(20) unsigned NOT NULL,
  `voided` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `folio_lines_line_source_id_foreign` (`line_source_id`),
  KEY `folio_lines_staff_id_foreign` (`staff_id`),
  KEY `folio_lines_folio_id_index` (`folio_id`),
  KEY `folio_lines_order_id_foreign` (`order_id`),
  KEY `folio_lines_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `folio_lines_folio_id_foreign` FOREIGN KEY (`folio_id`) REFERENCES `folios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folio_lines_line_source_id_foreign` FOREIGN KEY (`line_source_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `folio_lines_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `folio_lines_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `folio_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `folio_lines`
--

LOCK TABLES `folio_lines` WRITE;
/*!40000 ALTER TABLE `folio_lines` DISABLE KEYS */;
INSERT INTO `folio_lines` VALUES (1,2,2,NULL,56,'Room 1101 — 2026-08-25',1.00,250000,250000,7,0,NULL,'2026-08-25 15:36:34','2026-08-25 15:36:34'),(2,2,2,NULL,66,'gghj',1.00,20000,20000,7,1,'hh','2026-08-25 15:37:11','2026-08-25 15:37:16'),(3,2,3,NULL,56,'Room 1102 — 2026-08-25',1.00,1200000,1200000,7,0,NULL,'2026-08-25 15:38:53','2026-08-25 15:38:53'),(4,2,3,NULL,56,'Room 1102 — 2026-08-26',1.00,1200000,1200000,7,0,NULL,'2026-08-25 15:38:53','2026-08-25 15:38:53'),(5,2,3,2,58,'Restaurant Order #2',1.00,75000,75000,7,0,NULL,'2026-08-25 15:39:40','2026-08-25 15:39:40'),(6,2,6,NULL,56,'Room 1101 — 2026-08-25',1.00,950000,950000,9,0,NULL,'2026-08-25 16:54:24','2026-08-25 16:54:24'),(7,2,6,3,58,'Restaurant Order #3',1.00,15000,15000,9,0,NULL,'2026-08-25 17:01:30','2026-08-25 17:01:30'),(8,2,7,NULL,56,'Room 1102 — 2026-08-25',1.00,500000,500000,9,0,NULL,'2026-08-25 17:35:09','2026-08-25 17:35:09'),(9,2,7,NULL,65,'Discount — single rate',1.00,-270000,-270000,9,1,'mistake','2026-08-25 17:36:17','2026-08-25 17:36:41'),(10,2,7,NULL,65,'Discount — single room',1.00,-170000,-170000,9,0,NULL,'2026-08-25 17:36:59','2026-08-25 17:36:59'),(11,2,8,NULL,69,'Cancellation fee — 100% forfeited per policy (0 days before check-in)',1.00,100000,100000,9,0,NULL,'2026-08-25 17:43:17','2026-08-25 17:43:17'),(12,2,9,NULL,69,'Cancellation fee — 100% forfeited per policy (0 days before check-in)',1.00,20000,20000,9,0,NULL,'2026-08-25 17:46:39','2026-08-25 17:46:39'),(13,2,10,NULL,56,'Room 1101 — 2026-08-25',1.00,950000,950000,9,0,NULL,'2026-08-25 19:43:32','2026-08-25 19:43:32'),(14,2,10,7,58,'Restaurant Order #7',1.00,15000,15000,9,0,NULL,'2026-08-25 19:46:51','2026-08-25 19:46:51'),(15,2,10,NULL,65,'Discount — single room',1.00,-620000,-620000,9,0,NULL,'2026-08-25 19:54:19','2026-08-25 19:54:19');
/*!40000 ALTER TABLE `folio_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `folios`
--

DROP TABLE IF EXISTS `folios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `folios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `folio_type_id` bigint(20) unsigned NOT NULL,
  `folio_status_id` bigint(20) unsigned NOT NULL,
  `invoice_no` varchar(255) DEFAULT NULL COMMENT 'assigned at settlement, e.g. INV-2026-0012',
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `venue_booking_id` bigint(20) unsigned DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folios_reservation_id_unique` (`reservation_id`),
  UNIQUE KEY `folios_venue_booking_id_unique` (`venue_booking_id`),
  UNIQUE KEY `folios_tenant_id_invoice_no_unique` (`tenant_id`,`invoice_no`),
  KEY `folios_folio_type_id_foreign` (`folio_type_id`),
  KEY `folios_folio_status_id_foreign` (`folio_status_id`),
  CONSTRAINT `folios_folio_status_id_foreign` FOREIGN KEY (`folio_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `folios_folio_type_id_foreign` FOREIGN KEY (`folio_type_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `folios_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `folios_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `folios_venue_booking_id_foreign` FOREIGN KEY (`venue_booking_id`) REFERENCES `venue_bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `folios`
--

LOCK TABLES `folios` WRITE;
/*!40000 ALTER TABLE `folios` DISABLE KEYS */;
INSERT INTO `folios` VALUES (2,2,27,25,'INV-2026-0001',2,NULL,'2026-08-25 15:37:30','2026-08-25 15:36:23','2026-08-25 15:37:30'),(3,2,27,25,'INV-2026-0002',3,NULL,'2026-08-25 15:40:19','2026-08-25 15:38:38','2026-08-25 15:40:19'),(6,2,27,25,'INV-2026-0003',6,NULL,'2026-08-25 17:15:35','2026-08-25 16:52:27','2026-08-25 17:15:35'),(7,2,27,25,'INV-2026-0004',7,NULL,'2026-08-25 17:37:33','2026-08-25 17:34:57','2026-08-25 17:37:33'),(8,2,27,26,NULL,8,NULL,NULL,'2026-08-25 17:42:34','2026-08-25 17:43:17'),(9,2,27,26,NULL,9,NULL,NULL,'2026-08-25 17:44:52','2026-08-25 17:46:39'),(10,2,27,24,NULL,10,NULL,NULL,'2026-08-25 19:43:03','2026-08-25 19:43:03');
/*!40000 ALTER TABLE `folios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grn_lines`
--

DROP TABLE IF EXISTS `grn_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grn_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `grn_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL,
  `unit_cost` int(11) NOT NULL COMMENT 'cents',
  `line_total` int(11) NOT NULL COMMENT 'cents',
  `batch_no` varchar(255) DEFAULT NULL,
  `manufactured_at` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `grn_lines_tenant_id_foreign` (`tenant_id`),
  KEY `grn_lines_grn_id_index` (`grn_id`),
  KEY `grn_lines_ingredient_id_index` (`ingredient_id`),
  CONSTRAINT `grn_lines_grn_id_foreign` FOREIGN KEY (`grn_id`) REFERENCES `grns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grn_lines_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `grn_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grn_lines`
--

LOCK TABLES `grn_lines` WRITE;
/*!40000 ALTER TABLE `grn_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `grn_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grns`
--

DROP TABLE IF EXISTS `grns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `grn_no` varchar(255) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `grn_status_id` bigint(20) unsigned NOT NULL,
  `received_at` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `total_cost` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'cents, denormalised from lines',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grns_tenant_id_grn_no_unique` (`tenant_id`,`grn_no`),
  KEY `grns_grn_status_id_foreign` (`grn_status_id`),
  KEY `grns_created_by_foreign` (`created_by`),
  KEY `grns_updated_by_foreign` (`updated_by`),
  CONSTRAINT `grns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_grn_status_id_foreign` FOREIGN KEY (`grn_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `grns_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grns`
--

LOCK TABLES `grns` WRITE;
/*!40000 ALTER TABLE `grns` DISABLE KEYS */;
/*!40000 ALTER TABLE `grns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `group_bookings`
--

DROP TABLE IF EXISTS `group_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `group_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_bookings_tenant_id_reference_unique` (`tenant_id`,`reference`),
  KEY `group_bookings_created_by_foreign` (`created_by`),
  KEY `group_bookings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `group_bookings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `group_bookings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `group_bookings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `group_bookings`
--

LOCK TABLES `group_bookings` WRITE;
/*!40000 ALTER TABLE `group_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `guests`
--

DROP TABLE IF EXISTS `guests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `id_number` varchar(255) DEFAULT NULL COMMENT 'NIC/passport',
  `nationality` varchar(255) DEFAULT NULL,
  `preferences` text DEFAULT NULL,
  `loyalty_points` int(11) NOT NULL DEFAULT 0 COMMENT 'Denormalized running total — kept in sync with loyalty_transactions',
  `lifetime_spend` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents, denormalized running total',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guests_created_by_foreign` (`created_by`),
  KEY `guests_updated_by_foreign` (`updated_by`),
  KEY `guests_name_index` (`name`),
  KEY `guests_phone_index` (`phone`),
  KEY `guests_email_index` (`email`),
  KEY `guests_id_number_index` (`id_number`),
  KEY `guest_name_search_idx` (`tenant_id`,`name`),
  KEY `guest_phone_search_idx` (`tenant_id`,`phone`),
  KEY `guest_email_search_idx` (`tenant_id`,`email`),
  CONSTRAINT `guests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `guests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `guests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `guests`
--

LOCK TABLES `guests` WRITE;
/*!40000 ALTER TABLE `guests` DISABLE KEYS */;
INSERT INTO `guests` VALUES (2,2,'Mohomed Shahmee Jhan','Rimaz123@gmail.com',NULL,'200423701357',NULL,NULL,0,250000,7,7,'2026-08-25 15:36:23','2026-08-25 15:37:30',NULL),(3,2,'Mohomed Shahmee Jhan','Rimaz123@gmail.com',NULL,'200423710254',NULL,NULL,0,2475000,7,7,'2026-08-25 15:38:38','2026-08-25 15:40:19',NULL),(6,2,'m.m kavindu','hotelbadullamountview@gmail.com','0740775656','935902363',NULL,NULL,0,965000,9,9,'2026-08-25 16:52:27','2026-08-25 17:15:35',NULL),(7,2,'m.m kavindu','hotelbadullamountview@gmail.com','0740775656','103561684645',NULL,NULL,0,330000,9,9,'2026-08-25 17:34:57','2026-08-25 17:37:33',NULL),(8,2,'m.m kavindu','hotelbadullamountview@gmail.com','0740775656','935902369',NULL,NULL,0,0,9,9,'2026-08-25 17:44:52','2026-08-25 17:44:52',NULL),(9,2,'mr himansa',NULL,'0712271841','935902363',NULL,NULL,0,0,9,9,'2026-08-25 19:43:03','2026-08-25 19:43:03',NULL);
/*!40000 ALTER TABLE `guests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `housekeeping_tasks`
--

DROP TABLE IF EXISTS `housekeeping_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `housekeeping_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `assigned_to_id` bigint(20) unsigned DEFAULT NULL,
  `task_status_id` bigint(20) unsigned NOT NULL,
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '[{item, done}] from the room type template' CHECK (json_valid(`checklist`)),
  `notes` text DEFAULT NULL,
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `housekeeping_tasks_room_id_foreign` (`room_id`),
  KEY `housekeeping_tasks_assigned_to_id_foreign` (`assigned_to_id`),
  KEY `housekeeping_tasks_task_status_id_foreign` (`task_status_id`),
  KEY `housekeeping_tasks_reservation_id_foreign` (`reservation_id`),
  KEY `housekeeping_tasks_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `housekeeping_tasks_assigned_to_id_foreign` FOREIGN KEY (`assigned_to_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `housekeeping_tasks_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `housekeeping_tasks_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `housekeeping_tasks_task_status_id_foreign` FOREIGN KEY (`task_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `housekeeping_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `housekeeping_tasks`
--

LOCK TABLES `housekeeping_tasks` WRITE;
/*!40000 ALTER TABLE `housekeeping_tasks` DISABLE KEYS */;
INSERT INTO `housekeeping_tasks` VALUES (1,2,15,7,40,'[{\"item\":\"Strip used linen and remake bed with fresh linen,\",\"done\":true},{\"item\":\"Replace used towels with fresh ones,\",\"done\":true},{\"item\":\"Dust all surfaces, furniture, and fittings,\",\"done\":true},{\"item\":\"Vacuum \\/mop the floor,\",\"done\":true},{\"item\":\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"done\":true},{\"item\":\"Restock toiletries and guest amenities,\",\"done\":true},{\"item\":\"Empty and reline trash bins,\",\"done\":true},{\"item\":\"Restock\\/check minibar items,\",\"done\":true},{\"item\":\"Clean windows, mirrors, and glass surfaces,\",\"done\":true},{\"item\":\"Check AC, TV, and lights are functioning,\",\"done\":true},{\"item\":\"Check for and log any damage or maintenance issue found,\",\"done\":true},{\"item\":\"Final inspection and mark room status as Clean\\/Ready in the system\",\"done\":true}]',NULL,2,'2026-08-25 15:55:45','2026-08-25 15:37:30','2026-08-25 15:55:45'),(2,2,16,7,40,'[{\"item\":\"Strip used linen and remake bed with fresh linen,\",\"done\":true},{\"item\":\"Replace used towels with fresh ones,\",\"done\":true},{\"item\":\"Dust all surfaces, furniture, and fittings,\",\"done\":true},{\"item\":\"Vacuum \\/mop the floor,\",\"done\":true},{\"item\":\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"done\":true},{\"item\":\"Restock toiletries and guest amenities,\",\"done\":true},{\"item\":\"Empty and reline trash bins,\",\"done\":true},{\"item\":\"Restock\\/check minibar items,\",\"done\":true},{\"item\":\"Clean windows, mirrors, and glass surfaces,\",\"done\":true},{\"item\":\"Check AC, TV, and lights are functioning,\",\"done\":true},{\"item\":\"Check for and log any damage or maintenance issue found,\",\"done\":true},{\"item\":\"Final inspection and mark room status as Clean\\/Ready in the system\",\"done\":true}]',NULL,3,'2026-08-25 15:55:37','2026-08-25 15:40:19','2026-08-25 15:55:37'),(3,2,15,NULL,38,'[{\"item\":\"Strip used linen and remake bed with fresh linen,\",\"done\":false},{\"item\":\"Replace used towels with fresh ones,\",\"done\":false},{\"item\":\"Dust all surfaces, furniture, and fittings,\",\"done\":false},{\"item\":\"Vacuum \\/mop the floor,\",\"done\":false},{\"item\":\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"done\":false},{\"item\":\"Restock toiletries and guest amenities,\",\"done\":false},{\"item\":\"Empty and reline trash bins,\",\"done\":false},{\"item\":\"Restock\\/check minibar items,\",\"done\":false},{\"item\":\"Clean windows, mirrors, and glass surfaces,\",\"done\":false},{\"item\":\"Check AC, TV, and lights are functioning,\",\"done\":false},{\"item\":\"Check for and log any damage or maintenance issue found,\",\"done\":false},{\"item\":\"Final inspection and mark room status as Clean\\/Ready in the system\",\"done\":false}]',NULL,6,NULL,'2026-08-25 17:15:35','2026-08-25 17:15:35'),(4,2,16,NULL,38,'[{\"item\":\"Strip used linen and remake bed with fresh linen,\",\"done\":false},{\"item\":\"Replace used towels with fresh ones,\",\"done\":false},{\"item\":\"Dust all surfaces, furniture, and fittings,\",\"done\":false},{\"item\":\"Vacuum \\/mop the floor,\",\"done\":false},{\"item\":\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"done\":false},{\"item\":\"Restock toiletries and guest amenities,\",\"done\":false},{\"item\":\"Empty and reline trash bins,\",\"done\":false},{\"item\":\"Restock\\/check minibar items,\",\"done\":false},{\"item\":\"Clean windows, mirrors, and glass surfaces,\",\"done\":false},{\"item\":\"Check AC, TV, and lights are functioning,\",\"done\":false},{\"item\":\"Check for and log any damage or maintenance issue found,\",\"done\":false},{\"item\":\"Final inspection and mark room status as Clean\\/Ready in the system\",\"done\":false}]',NULL,7,NULL,'2026-08-25 17:37:33','2026-08-25 17:37:33');
/*!40000 ALTER TABLE `housekeeping_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `impersonation_tokens`
--

DROP TABLE IF EXISTS `impersonation_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `impersonation_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `central_admin_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `impersonation_tokens_token_hash_unique` (`token_hash`),
  KEY `impersonation_tokens_tenant_id_foreign` (`tenant_id`),
  KEY `impersonation_tokens_user_id_foreign` (`user_id`),
  KEY `impersonation_tokens_central_admin_id_foreign` (`central_admin_id`),
  CONSTRAINT `impersonation_tokens_central_admin_id_foreign` FOREIGN KEY (`central_admin_id`) REFERENCES `central_admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `impersonation_tokens_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `impersonation_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impersonation_tokens`
--

LOCK TABLES `impersonation_tokens` WRITE;
/*!40000 ALTER TABLE `impersonation_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `impersonation_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredient_batches`
--

DROP TABLE IF EXISTS `ingredient_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredient_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL COMMENT 'remaining',
  `initial_qty` decimal(12,3) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL,
  `grn_line_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unit_cost` int(11) DEFAULT NULL COMMENT 'cents per unit, from the GRN line',
  `manufactured_at` date DEFAULT NULL,
  `batch_no` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ingredient_batches_ingredient_id_expiry_date_index` (`ingredient_id`,`expiry_date`),
  KEY `ingredient_batches_tenant_id_foreign` (`tenant_id`),
  KEY `ingredient_batches_grn_line_id_foreign` (`grn_line_id`),
  KEY `ingredient_batches_ingredient_id_expiry_date_received_at_index` (`ingredient_id`,`expiry_date`,`received_at`),
  KEY `batch_fifo_idx` (`ingredient_id`,`qty`,`expiry_date`),
  KEY `batch_expiry_idx` (`expiry_date`,`qty`),
  CONSTRAINT `ingredient_batches_grn_line_id_foreign` FOREIGN KEY (`grn_line_id`) REFERENCES `grn_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredient_batches_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ingredient_batches_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredient_batches`
--

LOCK TABLES `ingredient_batches` WRITE;
/*!40000 ALTER TABLE `ingredient_batches` DISABLE KEYS */;
INSERT INTO `ingredient_batches` VALUES (1,2,3,5.000,5.000,NULL,'2026-08-25 07:53:58','new stock',NULL,'2026-08-25 17:23:58','2026-08-25 17:23:58',NULL,NULL,NULL),(2,2,4,5.000,5.000,NULL,'2026-08-25 07:55:32','new stock',NULL,'2026-08-25 17:25:32','2026-08-25 17:25:32',NULL,NULL,NULL),(3,2,5,50.000,50.000,'2026-08-30','2026-08-25 07:57:52','new stock',NULL,'2026-08-25 17:27:52','2026-08-25 17:27:52',NULL,NULL,NULL),(4,2,6,49.000,50.000,'2006-08-31','2026-08-25 08:00:33','new stock',NULL,'2026-08-25 17:30:33','2026-08-25 17:31:12',NULL,NULL,NULL);
/*!40000 ALTER TABLE `ingredient_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredients`
--

DROP TABLE IF EXISTS `ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_kind_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(255) NOT NULL COMMENT 'g | ml | pcs | kg ...',
  `stock_qty` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'authoritative running total',
  `low_stock_threshold` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost` int(11) DEFAULT NULL,
  `selling_price` int(10) unsigned DEFAULT NULL,
  `menu_category_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `image` longtext DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ingredients_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `ingredients_created_by_foreign` (`created_by`),
  KEY `ingredients_updated_by_foreign` (`updated_by`),
  KEY `ingredients_inventory_kind_id_foreign` (`inventory_kind_id`),
  KEY `ingredients_menu_category_id_foreign` (`menu_category_id`),
  KEY `ing_cat_stock_idx` (`tenant_id`,`menu_category_id`,`active`,`stock_qty`),
  KEY `ing_name_search_idx` (`tenant_id`,`name`),
  CONSTRAINT `ingredients_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredients_inventory_kind_id_foreign` FOREIGN KEY (`inventory_kind_id`) REFERENCES `lookups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredients_menu_category_id_foreign` FOREIGN KEY (`menu_category_id`) REFERENCES `pos_menu_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredients_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredients_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredients`
--

LOCK TABLES `ingredients` WRITE;
/*!40000 ALTER TABLE `ingredients` DISABLE KEYS */;
INSERT INTO `ingredients` VALUES (1,2,1,'sugar','g',186.000,0.000,NULL,NULL,NULL,7,7,'2026-08-25 05:26:59','2026-08-25 19:45:32',NULL,NULL,1),(3,2,1,'red rice','kg',5.000,2.000,26000,NULL,NULL,9,9,'2026-08-25 17:22:03','2026-08-25 17:23:58',NULL,NULL,1),(4,2,1,'chicken','kg',5.000,1.000,125000,NULL,NULL,9,9,'2026-08-25 17:25:10','2026-08-25 17:25:32',NULL,NULL,1),(5,2,2,'water bottle 500ml','pcs',50.000,5.000,7500,10000,NULL,9,9,'2026-08-25 17:26:38','2026-08-25 17:27:52',NULL,NULL,1),(6,2,2,'water bottle 1l','pcs',49.000,5.000,10000,15000,NULL,9,9,'2026-08-25 17:30:00','2026-08-25 17:31:12',NULL,NULL,1);
/*!40000 ALTER TABLE `ingredients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `laundry_items`
--

DROP TABLE IF EXISTS `laundry_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `laundry_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `price` int(10) unsigned NOT NULL COMMENT 'LKR cents per piece',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laundry_items_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `laundry_items_created_by_foreign` (`created_by`),
  KEY `laundry_items_updated_by_foreign` (`updated_by`),
  CONSTRAINT `laundry_items_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `laundry_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `laundry_items_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `laundry_items`
--

LOCK TABLES `laundry_items` WRITE;
/*!40000 ALTER TABLE `laundry_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `laundry_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lookups`
--

DROP TABLE IF EXISTS `lookups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lookups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lookups_type_code_unique` (`type`,`code`),
  KEY `lookups_created_by_foreign` (`created_by`),
  KEY `lookups_updated_by_foreign` (`updated_by`),
  KEY `lookups_type_index` (`type`),
  CONSTRAINT `lookups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lookups_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lookups`
--

LOCK TABLES `lookups` WRITE;
/*!40000 ALTER TABLE `lookups` DISABLE KEYS */;
INSERT INTO `lookups` VALUES (1,'inventory_kind','ingredient','Ingredient','orange',0,1,NULL,NULL,NULL,'2026-08-24 04:16:29','2026-08-24 04:16:29'),(2,'inventory_kind','product','Product','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:29','2026-08-24 04:16:29'),(3,'reservation_status','pending','Pending','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(4,'reservation_status','confirmed','Confirmed','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(5,'reservation_status','checked_in','Checked In','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(6,'reservation_status','checked_out','Checked Out','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(7,'reservation_status','cancelled','Cancelled','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(8,'reservation_status','no_show','No Show','orange',5,1,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(9,'room_status','available','Available','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(10,'room_status','occupied','Occupied','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(11,'room_status','dirty','Dirty','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(12,'room_status','maintenance','Maintenance','red',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(13,'order_status','open','Open','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(14,'order_status','parked','Parked','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(15,'order_status','settled','Settled','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(16,'order_status','charged_to_room','Charged to Room','purple',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(17,'order_status','void','Void','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(18,'order_status','split','Split','slate',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(19,'order_status','merged','Merged','slate',6,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(20,'kot_status','new','New','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(21,'kot_status','preparing','Preparing','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(22,'kot_status','ready','Ready','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(23,'kot_status','served','Served','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(24,'folio_status','open','Open','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(25,'folio_status','settled','Settled','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(26,'folio_status','void','Void','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(27,'folio_type','guest','Guest','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(28,'folio_type','venue','Venue','purple',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(29,'payroll_status','draft','Draft','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(30,'payroll_status','finalized','Finalized','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(31,'venue_booking_status','inquiry','Inquiry','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(32,'venue_booking_status','confirmed','Confirmed','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(33,'venue_booking_status','completed','Completed','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(34,'venue_booking_status','cancelled','Cancelled','red',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(35,'maintenance_status','open','Open','red',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(36,'maintenance_status','in_progress','In Progress','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(37,'maintenance_status','resolved','Resolved','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(38,'task_status','pending','Pending','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(39,'task_status','in_progress','In Progress','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(40,'task_status','done','Done','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(41,'notification_status','queued','Queued','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(42,'notification_status','sent','Sent','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(43,'notification_status','failed','Failed','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(44,'notification_channel','email','Email','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(45,'notification_channel','whatsapp','WhatsApp','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(46,'notification_channel','sms','SMS','purple',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(47,'payment_method','cash','Cash','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(48,'payment_method','card','Card','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(49,'payment_method','lankaqr','LankaQR','purple',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(50,'payment_method','bank_transfer','Bank Transfer','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(51,'payment_method','corporate_credit','Corporate Credit','orange',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(52,'payment_method','loyalty_points','Loyalty Points','pink',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(53,'payment_kind','payment','Payment','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(54,'payment_kind','deposit','Deposit','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(55,'payment_kind','refund','Refund','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(56,'line_source','room','Room','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(57,'line_source','package','Package','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(58,'line_source','restaurant','Restaurant','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(59,'line_source','minibar','Minibar','orange',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(60,'line_source','venue','Venue','purple',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(61,'line_source','laundry','Laundry','slate',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(62,'line_source','surcharge','Surcharge','gray',6,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(63,'line_source','service_charge','Service Charge','gray',7,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(64,'line_source','vat','VAT','gray',8,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(65,'line_source','discount','Discount','green',9,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(66,'line_source','damage','Damage','red',10,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(67,'line_source','adjustment','Adjustment','gray',11,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(68,'line_source','loyalty_redemption','Loyalty Redemption','pink',12,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(69,'line_source','cancellation_fee','Cancellation Fee','red',13,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(70,'booking_channel','booking_com','Booking.com','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(71,'booking_channel','website','Website','purple',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(72,'booking_channel','phone','Phone','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(73,'booking_channel','walkin','Walk-in','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(74,'duration_type','hourly','Hourly','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(75,'duration_type','half_day','Half Day','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(76,'duration_type','full_day','Full Day','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(77,'check_kind','check_in','Check In','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(78,'check_kind','check_out','Check Out','slate',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(79,'dining_mode','dine_in','Dine In','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(80,'dining_mode','takeaway','Takeaway','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(81,'order_type','room_guest','Room Guest','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(82,'order_type','walkin','Walk-in','slate',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(83,'order_type','delivery','Delivery','purple',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(84,'table_status','free','Free','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(85,'table_status','occupied','Occupied','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(86,'table_status','reserved','Reserved','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(87,'table_status','cleaning','Cleaning','gray',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(88,'delivery_status','pending','Pending','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(89,'delivery_status','out_for_delivery','Out for Delivery','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(90,'delivery_status','delivered','Delivered','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(91,'delivery_status','failed','Failed','red',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(92,'kitchen_station','kitchen','Kitchen','orange',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(93,'kitchen_station','bar','Bar','purple',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(94,'kitchen_station','dessert','Dessert','pink',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(95,'apartment_listing_type','rental','Rental','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(96,'apartment_listing_type','sale','For Sale','purple',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(97,'apartment_unit_status','available','Available','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(98,'apartment_unit_status','occupied','Occupied','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(99,'apartment_unit_status','dirty','Dirty','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(100,'apartment_unit_status','reserved','Reserved','orange',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(101,'apartment_unit_status','maintenance','Maintenance','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(102,'apartment_unit_status','blocked','Blocked','gray',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(103,'apartment_unit_status','sold','Sold','slate',6,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(104,'apartment_unit_status','off_market','Off Market','gray',7,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(105,'apartment_ledger_status','open','Open','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(106,'apartment_ledger_status','settled','Settled','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(107,'apartment_ledger_status','void','Void','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(108,'apartment_line_source','rent','Rent','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(109,'apartment_line_source','cleaning_fee','Cleaning Fee','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(110,'apartment_line_source','extra_guest_fee','Extra Guest Fee','orange',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(111,'apartment_line_source','utility','Utility','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(112,'apartment_line_source','surcharge','Surcharge','gray',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(113,'apartment_line_source','service_charge','Service Charge','gray',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(114,'apartment_line_source','vat','VAT','gray',6,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(115,'apartment_line_source','discount','Discount','green',7,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(116,'apartment_line_source','damage','Damage','red',8,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(117,'apartment_line_source','adjustment','Adjustment','gray',9,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(118,'apartment_line_source','installment','Installment','purple',10,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(119,'apartment_booking_status','pending','Pending','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(120,'apartment_booking_status','confirmed','Confirmed','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(121,'apartment_booking_status','checked_in','Checked In','green',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(122,'apartment_booking_status','checked_out','Checked Out','slate',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(123,'apartment_booking_status','cancelled','Cancelled','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(124,'apartment_booking_status','no_show','No Show','orange',5,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(125,'apartment_booking_channel','direct','Direct','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(126,'apartment_booking_channel','airbnb','Airbnb','red',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(127,'apartment_booking_channel','booking_com','Booking.com','blue',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(128,'apartment_booking_channel','agent','Agent','purple',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(129,'apartment_booking_channel','walk_in','Walk-in','slate',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(130,'apartment_lease_status','draft','Draft','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(131,'apartment_lease_status','active','Active','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(132,'apartment_lease_status','renewed','Renewed','blue',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(133,'apartment_lease_status','terminated','Terminated','red',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(134,'apartment_lease_status','ended','Ended','slate',4,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(135,'apartment_utility_type','electricity','Electricity','orange',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(136,'apartment_utility_type','water','Water','blue',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(137,'apartment_utility_type','gas','Gas','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(138,'till_session_status','open','Open','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(139,'till_session_status','closed','Closed','slate',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(140,'till_movement_type','opening_balance','Opening Balance','blue',0,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(141,'till_movement_type','cash_in','Cash In','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(142,'till_movement_type','cash_out','Cash Out','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(143,'till_movement_type','refund','Refund','orange',3,1,NULL,NULL,NULL,'2026-08-24 04:16:42','2026-08-24 04:16:42'),(144,'till_movement_type','expense','Expense','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(145,'till_movement_type','transfer','Transfer','purple',5,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(146,'till_movement_type','closing_adjustment','Closing Adjustment','gray',6,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(147,'apartment_sale_status','inquiry','Inquiry','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(148,'apartment_sale_status','reserved','Reserved','orange',1,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(149,'apartment_sale_status','agreement_signed','Agreement Signed','blue',2,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(150,'apartment_sale_status','completed','Completed','green',3,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(151,'apartment_sale_status','cancelled','Cancelled','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(152,'grn_status','draft','Draft','gray',0,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(153,'grn_status','received','Received','green',1,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(154,'grn_status','cancelled','Cancelled','red',2,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(155,'stock_movement_type','grn_receipt','GRN Receipt','green',0,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(156,'stock_movement_type','adjustment','Adjustment','gray',1,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(157,'stock_movement_type','sale','Sale','blue',2,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(158,'stock_movement_type','sale_reversal','Sale Reversal','orange',3,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(159,'stock_movement_type','write_off','Write Off','red',4,1,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43');
/*!40000 ALTER TABLE `lookups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loyalty_transactions`
--

DROP TABLE IF EXISTS `loyalty_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `guest_id` bigint(20) unsigned NOT NULL,
  `points` int(11) NOT NULL COMMENT 'Positive = earn, negative = redeem',
  `reason` varchar(255) NOT NULL,
  `ref_type` varchar(255) DEFAULT NULL COMMENT 'Loose reference, e.g. folio/order/venue — not a real FK, matches the source system',
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `loyalty_transactions_staff_id_foreign` (`staff_id`),
  KEY `loyalty_transactions_guest_id_created_at_index` (`guest_id`,`created_at`),
  KEY `loyalty_transactions_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `loyalty_transactions_guest_id_foreign` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `loyalty_transactions_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loyalty_transactions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loyalty_transactions`
--

LOCK TABLES `loyalty_transactions` WRITE;
/*!40000 ALTER TABLE `loyalty_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `loyalty_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_issues`
--

DROP TABLE IF EXISTS `maintenance_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_issues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `venue_id` bigint(20) unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `maintenance_status_id` bigint(20) unsigned NOT NULL,
  `logged_by_id` bigint(20) unsigned NOT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_issues_room_id_foreign` (`room_id`),
  KEY `maintenance_issues_maintenance_status_id_foreign` (`maintenance_status_id`),
  KEY `maintenance_issues_logged_by_id_foreign` (`logged_by_id`),
  KEY `maintenance_issues_venue_id_foreign` (`venue_id`),
  KEY `maintenance_issues_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `maintenance_issues_logged_by_id_foreign` FOREIGN KEY (`logged_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `maintenance_issues_maintenance_status_id_foreign` FOREIGN KEY (`maintenance_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `maintenance_issues_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_issues_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_issues_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_issues`
--

LOCK TABLES `maintenance_issues` WRITE;
/*!40000 ALTER TABLE `maintenance_issues` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_item_modifier_groups`
--

DROP TABLE IF EXISTS `menu_item_modifier_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_item_modifier_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `max_select` int(10) unsigned NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_item_modifier_groups_menu_item_id_foreign` (`menu_item_id`),
  KEY `menu_item_modifier_groups_created_by_foreign` (`created_by`),
  KEY `menu_item_modifier_groups_updated_by_foreign` (`updated_by`),
  KEY `menu_item_modifier_groups_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `menu_item_modifier_groups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `menu_item_modifier_groups_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `pos_menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_item_modifier_groups_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `menu_item_modifier_groups_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_item_modifier_groups`
--

LOCK TABLES `menu_item_modifier_groups` WRITE;
/*!40000 ALTER TABLE `menu_item_modifier_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `menu_item_modifier_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_item_modifiers`
--

DROP TABLE IF EXISTS `menu_item_modifiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_item_modifiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `modifier_group_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `price_delta` int(11) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_item_modifiers_modifier_group_id_foreign` (`modifier_group_id`),
  KEY `menu_item_modifiers_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `menu_item_modifiers_modifier_group_id_foreign` FOREIGN KEY (`modifier_group_id`) REFERENCES `menu_item_modifier_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_item_modifiers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_item_modifiers`
--

LOCK TABLES `menu_item_modifiers` WRITE;
/*!40000 ALTER TABLE `menu_item_modifiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `menu_item_modifiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_items`
--

DROP TABLE IF EXISTS `menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `route_name` varchar(120) DEFAULT NULL,
  `module_key` varchar(80) DEFAULT NULL,
  `actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actions`)),
  `order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_items_parent_id_order_index` (`parent_id`,`order`),
  KEY `menu_items_module_key_index` (`module_key`),
  CONSTRAINT `menu_items_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_items`
--

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (1,NULL,'Dashboard','layout-dashboard','dashboard','dashboard','[\"access\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(2,NULL,'Administration','shield-check',NULL,NULL,'[]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(3,2,'User Management',NULL,'user-management.users.index','user_management_users','[\"access\",\"view\",\"create\",\"edit\",\"delete\",\"bulk_delete\",\"unlock\",\"reset_password\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(4,2,'Roles & Permissions',NULL,'user-management.roles.index','user_management_roles','[\"access\",\"view\",\"create\",\"edit\",\"delete\",\"duplicate\",\"toggle_active\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(5,NULL,'Audit Logs','history','audit-logs.index','audit_logs','[\"access\",\"view\",\"export\"]',2,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(6,NULL,'Rooms','bed-double',NULL,NULL,'[]',3,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(7,6,'Rooms',NULL,'hotel.rooms.index','hotel_rooms','[\"access\",\"create\",\"edit\",\"edit_status\",\"delete\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(8,6,'Packages',NULL,'hotel.packages.index','hotel_packages','[\"access\",\"edit\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(9,NULL,'Guests','users-round','hotel.guests.index','hotel_guests','[\"access\",\"view\",\"create\",\"edit\",\"loyalty_adjust\"]',4,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(10,NULL,'Corporate Accounts','building-2','hotel.corporate.index','hotel_corporate','[\"access\",\"create\",\"edit\"]',5,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(11,NULL,'Reservations','calendar-check',NULL,NULL,'[]',6,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(12,11,'Reservations',NULL,'hotel.reservations.index','hotel_reservations','[\"access\",\"view\",\"create\",\"edit\",\"check_in\",\"checkout\",\"cancel\",\"discount\",\"early_departure\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(13,11,'Folios',NULL,'hotel.folios.show','hotel_folios','[\"view\",\"add_line\",\"void_line\",\"payment\",\"refund\",\"invoice\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(14,NULL,'Restaurant Menu','utensils',NULL,NULL,'[]',7,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(15,14,'Categories',NULL,'hotel.menu.categories.index','hotel_menu_categories','[\"access\",\"create\",\"edit\",\"delete\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(16,14,'Items',NULL,'hotel.menu.items.index','hotel_menu_items','[\"access\",\"create\",\"edit\",\"delete\",\"sold_out\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(17,NULL,'Inventory','boxes',NULL,NULL,'[]',8,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(18,17,'Inventory',NULL,'hotel.ingredients.index','hotel_ingredients','[\"access\",\"create\",\"edit\",\"delete\",\"adjust_stock\",\"write_off\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(19,17,'Products',NULL,'hotel.products.index','hotel_products','[\"access\",\"create\",\"edit\",\"delete\",\"adjust_stock\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(20,17,'Goods Received Notes',NULL,'hotel.grns.index','hotel_grn','[\"access\",\"view\",\"create\",\"edit\",\"delete\",\"receive\"]',2,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(21,NULL,'POS Orders','shopping-cart','hotel.orders.index','hotel_orders','[\"access\",\"view\",\"create\",\"kot\",\"void_item\",\"hold\",\"discount\",\"settle\",\"charge_to_room\",\"void\",\"refund\",\"receipt\",\"slip\",\"kot_ticket\",\"split\",\"merge\",\"delivery_dispatch\"]',9,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(22,NULL,'Dining Tables','grid-2x2','hotel.dining-tables.index','hotel_dining_tables','[\"access\",\"create\",\"edit\",\"edit_status\",\"delete\"]',10,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(23,NULL,'QR Ordering','qr-code','hotel.qr-ordering.index','hotel_qr_ordering','[\"access\",\"create\",\"edit\",\"regenerate\"]',11,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(24,NULL,'Housekeeping','sparkles','hotel.housekeeping.tasks.index','hotel_housekeeping','[\"access\",\"create\",\"assign\",\"checklist\",\"complete\"]',12,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(25,NULL,'Maintenance','wrench','hotel.maintenance.index','hotel_maintenance','[\"access\",\"create\",\"edit\"]',13,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(26,NULL,'Laundry','shirt','hotel.laundry.items.index','hotel_laundry','[\"access\",\"create\",\"edit\",\"charge\"]',14,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(27,NULL,'Venues','party-popper',NULL,NULL,'[]',15,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(28,27,'Venues',NULL,'hotel.venues.index','hotel_venues','[\"access\",\"edit\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(29,27,'Bookings',NULL,'hotel.venues.bookings.index','hotel_venue_bookings','[\"access\",\"view\",\"create\",\"edit\",\"confirm\",\"complete\",\"cancel\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(30,NULL,'Till','clock','till.current','till','[\"access\",\"open\",\"close\",\"close_any\",\"cash_in\",\"cash_out\",\"manage\"]',16,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(31,NULL,'Attendance','calendar-check-2','hotel.attendance.index','hotel_attendance','[\"access\",\"on_duty\",\"view_all\",\"export\"]',17,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(32,NULL,'Payroll','banknote','hotel.payroll.runs.index','hotel_payroll','[\"manage_pay\",\"view\",\"generate\",\"adjust_line\",\"finalize\",\"delete_run\",\"mark_paid\",\"export\",\"payslip\"]',18,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(33,NULL,'Visitors','log-in','hotel.visitors.index','hotel_visitors','[\"access\",\"create\",\"sign_out\"]',19,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(34,NULL,'Notifications','bell','hotel.notifications.index','hotel_notifications','[\"access\",\"test\",\"run_scheduled\"]',20,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(35,NULL,'Reports','bar-chart-3','hotel.reports.dashboard','hotel_reports','[\"dashboard\",\"daily\",\"monthly\",\"night_audit_run\",\"night_audit_view\",\"revpar\",\"channel_mix\",\"cancellations\",\"guest_loyalty\",\"corporate_ar\",\"ops_sla\",\"payroll_cost\",\"venues\",\"laundry\"]',21,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(36,NULL,'Restaurant Reports','chart-line','hotel.reports.pos','restaurant_reports','[\"pos\",\"menu_performance\",\"modifiers\",\"discounts_voids\",\"table_server\",\"delivery_performance\",\"kitchen_ticket_time\",\"shift_sales\",\"food_cost\"]',22,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(37,NULL,'Staff PIN Unlock','key-round','hotel.staff.pin.update','hotel_staff','[\"set_pin\"]',23,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(38,NULL,'Apartments','building',NULL,NULL,'[]',24,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(39,38,'Properties',NULL,'apartments.properties.index','apartment_properties','[\"access\",\"create\",\"edit\"]',0,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(40,38,'Unit Types',NULL,'apartments.unit-types.index','apartment_unit_types','[\"access\",\"create\",\"edit\"]',1,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(41,38,'Units',NULL,'apartments.units.index','apartment_units','[\"access\",\"create\",\"edit\",\"edit_status\"]',2,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(42,38,'Customers',NULL,'apartments.customers.index','apartment_customers','[\"access\",\"view\",\"create\",\"edit\"]',3,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(43,38,'Bookings',NULL,'apartments.bookings.index','apartment_bookings','[\"access\",\"view\",\"create\",\"check_in\",\"checkout\",\"cancel\"]',4,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(44,38,'Leases',NULL,'apartments.leases.index','apartment_leases','[\"access\",\"view\",\"create\",\"renew\",\"terminate\",\"utility_reading\"]',5,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(45,38,'Sales',NULL,'apartments.sales.index','apartment_sales','[\"access\",\"view\",\"create\",\"reserve\",\"sign_agreement\",\"complete\",\"cancel\"]',6,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(46,38,'Ledgers',NULL,'apartments.ledgers.show','apartment_ledgers','[\"view\",\"add_line\",\"void_line\",\"payment\",\"refund\"]',7,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(47,38,'Housekeeping',NULL,'apartments.housekeeping.tasks.index','apartment_housekeeping','[\"access\",\"create\",\"assign\",\"checklist\",\"complete\"]',8,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(48,38,'Maintenance',NULL,'apartments.maintenance.index','apartment_maintenance','[\"access\",\"create\",\"edit\"]',9,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(49,38,'Reports',NULL,'apartments.reports.dashboard','apartment_reports','[\"dashboard\",\"occupancy_trend\",\"revenue_channel\",\"rent_roll\",\"sales_pipeline\",\"utilities\",\"ops_sla\"]',10,1,'2026-08-24 04:16:34','2026-08-24 04:16:34',NULL);
/*!40000 ALTER TABLE `menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_05_26_191037_add_two_factor_columns_to_users_table',1),(5,'2026_05_26_191038_create_passkeys_table',1),(6,'2026_05_27_000000_create_warehouses_table',1),(7,'2026_05_27_000001_extend_users_table_for_rbac',1),(8,'2026_05_27_000002_create_permissions_table',1),(9,'2026_05_27_000003_create_roles_table',1),(10,'2026_05_27_000004_create_role_permissions_table',1),(11,'2026_05_27_000005_create_user_permissions_table',1),(12,'2026_05_27_000006_create_user_warehouse_access_table',1),(13,'2026_05_27_000007_create_audit_logs_table',1),(14,'2026_05_27_000008_create_menu_items_table',1),(15,'2026_05_29_000009_add_description_to_audit_logs_table',1),(16,'2026_05_29_111919_add_soft_deletes_to_users_table',1),(17,'2026_05_29_111924_add_soft_deletes_to_roles_table',1),(18,'2026_05_29_111926_add_soft_deletes_to_permissions_table',1),(19,'2026_05_29_111928_add_soft_deletes_to_warehouses_table',1),(20,'2026_05_29_111929_add_soft_deletes_to_menu_items_table',1),(21,'2026_05_29_111930_add_soft_deletes_to_audit_logs_table',1),(22,'2026_05_30_000010_extend_warehouses_as_branch',1),(23,'2026_06_03_200001_create_user_roles_table',1),(24,'2026_06_03_200002_create_user_permission_overrides_table',1),(25,'2026_06_11_050846_add_password_change_tracking_to_users_table',1),(26,'2026_06_11_051516_add_two_factor_required_to_users_table',1),(27,'2026_06_11_051912_add_login_otp_columns_to_users_table',1),(28,'2026_07_13_190716_create_personal_access_tokens_table',1),(29,'2026_07_14_000001_create_lookups_table',1),(30,'2026_07_14_000002_create_settings_table',1),(31,'2026_07_14_000010_create_room_types_table',1),(32,'2026_07_14_000011_create_rooms_table',1),(33,'2026_07_14_000012_create_seasonal_rates_table',1),(34,'2026_07_14_000013_create_packages_table',1),(35,'2026_07_14_000020_create_guests_table',1),(36,'2026_07_14_000021_create_loyalty_transactions_table',1),(37,'2026_07_14_000030_create_corporate_accounts_table',1),(38,'2026_07_14_000040_create_group_bookings_table',1),(39,'2026_07_14_000041_create_reservations_table',1),(40,'2026_07_14_000042_create_reservation_rooms_table',1),(41,'2026_07_14_000043_create_folios_table',1),(42,'2026_07_14_000044_create_folio_lines_table',1),(43,'2026_07_14_000045_create_payments_table',1),(44,'2026_07_14_000046_create_room_item_checks_table',1),(45,'2026_07_14_000047_create_housekeeping_tasks_table',1),(46,'2026_07_14_000050_create_pos_menu_categories_table',1),(47,'2026_07_14_000051_create_pos_menu_items_table',1),(48,'2026_07_14_000052_create_ingredients_table',1),(49,'2026_07_14_000053_create_ingredient_batches_table',1),(50,'2026_07_14_000054_create_recipe_items_table',1),(51,'2026_07_14_000060_create_orders_table',1),(52,'2026_07_14_000061_create_order_items_table',1),(53,'2026_07_14_000062_add_order_id_to_folio_lines_and_payments_tables',1),(54,'2026_07_14_000070_create_maintenance_issues_table',1),(55,'2026_07_14_000080_create_laundry_items_table',1),(56,'2026_07_14_000090_create_venues_table',1),(57,'2026_07_14_000091_create_venue_bookings_table',1),(58,'2026_07_14_000092_add_venue_booking_id_to_folios_table',1),(59,'2026_07_14_000100_create_shifts_table',1),(60,'2026_07_14_000101_add_shift_id_to_payments_table',1),(61,'2026_07_14_000110_create_attendances_table',1),(62,'2026_07_14_000120_add_payroll_fields_to_users_table',1),(63,'2026_07_14_000121_create_payroll_runs_table',1),(64,'2026_07_14_000122_create_payroll_lines_table',1),(65,'2026_07_14_000130_create_visitor_logs_table',1),(66,'2026_07_14_000140_create_notifications_table',1),(67,'2026_07_14_000150_create_night_audits_table',1),(68,'2026_07_14_000160_add_pin_hash_to_users_table',1),(69,'2026_07_14_000161_create_device_tokens_table',1),(70,'2026_07_15_214958_add_venue_id_to_maintenance_issues_table',1),(71,'2026_07_15_215458_add_user_agent_and_route_to_audit_logs_table',1),(72,'2026_07_18_053420_add_hotel_branding_settings',1),(73,'2026_07_19_035515_add_apit_and_deduction_breakdown_to_payroll_lines_table',1),(74,'2026_07_19_050644_widen_settings_hint_column',1),(75,'2026_07_19_052709_split_hotel_tagline_for_login_screen',1),(76,'2026_07_19_090000_add_image_to_pos_menu_items_table',1),(77,'2026_07_19_100000_add_theming_settings',1),(78,'2026_07_23_000001_create_apartment_properties_table',1),(79,'2026_07_23_000002_create_apartment_unit_types_table',1),(80,'2026_07_23_000003_create_apartment_units_table',1),(81,'2026_07_23_000004_create_apartment_seasonal_rates_table',1),(82,'2026_07_23_000005_create_apartment_customers_table',1),(83,'2026_07_23_000006_create_apartment_bookings_table',1),(84,'2026_07_23_000007_create_apartment_ledgers_table',1),(85,'2026_07_23_000008_create_apartment_ledger_lines_table',1),(86,'2026_07_23_000009_create_apartment_payments_table',1),(87,'2026_07_23_000010_create_apartment_leases_table',1),(88,'2026_07_23_000011_add_lease_id_to_apartment_ledgers_table',1),(89,'2026_07_23_000012_create_apartment_utility_readings_table',1),(90,'2026_07_23_000013_create_apartment_lease_rent_charges_table',1),(91,'2026_07_23_000014_make_staff_id_nullable_on_apartment_ledger_lines_table',1),(92,'2026_07_23_000015_create_apartment_sales_table',1),(93,'2026_07_23_000016_add_sale_id_to_apartment_ledgers_table',1),(94,'2026_07_23_000017_make_staff_id_nullable_on_apartment_payments_table',1),(95,'2026_07_23_000018_create_apartment_housekeeping_tasks_table',1),(96,'2026_07_23_000019_create_apartment_maintenance_issues_table',1),(97,'2026_07_24_000001_create_dining_areas_table',1),(98,'2026_07_24_000002_create_dining_tables_table',1),(99,'2026_07_24_000003_add_dining_table_id_to_orders_table',1),(100,'2026_07_24_000004_add_delivery_fields_to_orders_table',1),(101,'2026_07_24_000005_add_parent_order_id_to_orders_table',1),(102,'2026_07_24_000006_create_menu_item_modifier_groups_table',1),(103,'2026_07_24_000007_create_menu_item_modifiers_table',1),(104,'2026_07_24_000008_create_order_item_modifiers_table',1),(105,'2026_07_24_000009_add_kitchen_station_id_to_pos_menu_categories_table',1),(106,'2026_07_25_000001_add_kot_timestamps_to_orders_table',1),(107,'2026_07_25_000002_add_delivery_timestamps_to_orders_table',1),(108,'2026_07_25_000003_add_unit_cost_to_ingredients_table',1),(109,'2026_07_26_000001_create_tills_table',1),(110,'2026_07_26_000002_create_till_sessions_table',1),(111,'2026_07_26_000003_create_till_movements_table',1),(112,'2026_07_26_000004_replace_shift_id_with_till_session_id_on_payments_table',1),(113,'2026_07_26_000005_add_till_session_id_to_apartment_payments_table',1),(114,'2026_07_26_000006_drop_shifts_table',1),(115,'2026_07_27_000001_create_qr_ordering_points_table',1),(116,'2026_07_27_000002_make_staff_id_nullable_on_orders_table',1),(117,'2026_07_27_000003_add_qr_ordering_fields_to_orders_table',1),(118,'2026_07_30_072023_create_central_admins_table',1),(119,'2026_07_30_072024_create_tenants_table',1),(120,'2026_07_30_072025_add_tenant_id_to_core_tables',1),(121,'2026_07_30_072026_add_surrogate_key_to_settings_table',1),(122,'2026_07_30_072027_add_tenant_scoped_uniques',1),(123,'2026_07_30_073434_create_tenant_modules_table',1),(124,'2026_07_30_073436_create_impersonation_tokens_table',1),(125,'2026_08_15_130129_add_send_to_kot_and_stock_to_pos_menu_items_table',1),(126,'2026_08_15_130129_create_add_ons_table',1),(127,'2026_08_15_130130_add_add_on_routing_to_order_items_table',1),(128,'2026_08_15_130130_create_add_on_links_table',1),(129,'2026_08_16_000001_add_tenant_id_to_domain_tables',1),(130,'2026_08_16_164917_add_test_instance_columns_to_tenants_table',1),(131,'2026_08_21_000001_add_inventory_kind_and_product_fields_to_ingredients_table',1),(132,'2026_08_21_000002_create_grns_table',1),(133,'2026_08_21_000003_create_grn_lines_table',1),(134,'2026_08_21_000004_add_cost_and_provenance_to_ingredient_batches_table',1),(135,'2026_08_21_000005_create_stock_movements_table',1),(136,'2026_08_21_000006_add_product_id_to_order_items_table',1),(137,'2026_08_21_000007_convert_no_kot_menu_items_to_products',1),(138,'2026_08_22_181710_add_performance_indexes_to_pos_tables',1),(139,'2026_08_23_000001_flatten_room_types_into_rooms',1),(140,'2026_08_24_060000_drop_must_change_password_from_users_table',1),(141,'2026_08_24_061000_make_description_nullable_on_pos_menu_items_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `night_audits`
--

DROP TABLE IF EXISTS `night_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `night_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `business_date` date NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `run_by_id` bigint(20) unsigned NOT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `night_audits_tenant_id_business_date_unique` (`tenant_id`,`business_date`),
  KEY `night_audits_run_by_id_foreign` (`run_by_id`),
  CONSTRAINT `night_audits_run_by_id_foreign` FOREIGN KEY (`run_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `night_audits_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `night_audits`
--

LOCK TABLES `night_audits` WRITE;
/*!40000 ALTER TABLE `night_audits` DISABLE KEYS */;
/*!40000 ALTER TABLE `night_audits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) NOT NULL COMMENT 'BOOKING_CONFIRMATION | PRE_ARRIVAL | FEEDBACK_REQUEST | VENUE_CONFIRMATION | VENUE_PAYMENT_REMINDER | VENUE_PRE_EVENT | LOW_STOCK | FOOD_EXPIRY | INTEGRATION_TEST',
  `notification_channel_id` bigint(20) unsigned NOT NULL,
  `to` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `notification_status_id` bigint(20) unsigned NOT NULL,
  `ref_type` varchar(255) DEFAULT NULL COMMENT 'loose reference, not a real FK — matches source system',
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notification_channel_id_foreign` (`notification_channel_id`),
  KEY `notifications_notification_status_id_foreign` (`notification_status_id`),
  KEY `notifications_type_ref_id_index` (`type`,`ref_id`),
  KEY `notifications_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `notifications_notification_channel_id_foreign` FOREIGN KEY (`notification_channel_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `notifications_notification_status_id_foreign` FOREIGN KEY (`notification_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `notifications_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_item_modifiers`
--

DROP TABLE IF EXISTS `order_item_modifiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_item_modifiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `menu_item_modifier_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `price_delta` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_item_modifiers_order_item_id_foreign` (`order_item_id`),
  KEY `order_item_modifiers_menu_item_modifier_id_foreign` (`menu_item_modifier_id`),
  KEY `order_item_modifiers_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `order_item_modifiers_menu_item_modifier_id_foreign` FOREIGN KEY (`menu_item_modifier_id`) REFERENCES `menu_item_modifiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_item_modifiers_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_item_modifiers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_item_modifiers`
--

LOCK TABLES `order_item_modifiers` WRITE;
/*!40000 ALTER TABLE `order_item_modifiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_item_modifiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` bigint(20) unsigned DEFAULT NULL,
  `add_on_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL COMMENT 'snapshot at order time',
  `send_to_kot` tinyint(1) NOT NULL DEFAULT 1,
  `qty` int(10) unsigned NOT NULL,
  `unit_price` int(10) unsigned NOT NULL COMMENT 'snapshot, LKR cents',
  `amount` int(10) unsigned NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `voided` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_tenant_id_foreign` (`tenant_id`),
  KEY `order_item_voided_idx` (`order_id`,`voided`),
  KEY `order_item_menu_voided_idx` (`menu_item_id`,`voided`),
  KEY `order_item_product_voided_idx` (`product_id`,`voided`),
  KEY `order_item_addon_voided_idx` (`add_on_id`,`voided`),
  KEY `order_item_kot_voided_idx` (`order_id`,`send_to_kot`,`voided`),
  CONSTRAINT `order_items_add_on_id_foreign` FOREIGN KEY (`add_on_id`) REFERENCES `add_ons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `pos_menu_items` (`id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `ingredients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (1,2,1,1,NULL,NULL,'tea',1,3,15000,45000,NULL,0,NULL,'2026-08-25 05:30:39','2026-08-25 05:30:39'),(2,2,2,1,NULL,NULL,'tea',1,5,15000,75000,NULL,0,NULL,'2026-08-25 15:39:18','2026-08-25 15:39:18'),(3,2,3,1,NULL,NULL,'tea',1,1,15000,15000,NULL,0,NULL,'2026-08-25 16:56:27','2026-08-25 16:56:27'),(4,2,4,1,NULL,NULL,'tea',1,2,15000,30000,NULL,0,NULL,'2026-08-25 17:11:57','2026-08-25 17:11:57'),(5,2,5,1,NULL,NULL,'tea',1,2,15000,30000,NULL,0,NULL,'2026-08-25 17:22:03','2026-08-25 17:22:03'),(6,2,6,NULL,NULL,6,'water bottle 1l',0,1,15000,15000,NULL,0,NULL,'2026-08-25 17:31:12','2026-08-25 17:31:12'),(7,2,7,1,NULL,NULL,'tea',1,1,15000,15000,NULL,0,NULL,'2026-08-25 19:45:32','2026-08-25 19:45:32');
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `parent_order_id` bigint(20) unsigned DEFAULT NULL,
  `client_key` varchar(255) DEFAULT NULL COMMENT 'offline-POS idempotency key',
  `order_type_id` bigint(20) unsigned NOT NULL,
  `dining_mode_id` bigint(20) unsigned NOT NULL,
  `order_status_id` bigint(20) unsigned NOT NULL,
  `kot_status_id` bigint(20) unsigned NOT NULL,
  `kot_started_at` timestamp NULL DEFAULT NULL,
  `kot_ready_at` timestamp NULL DEFAULT NULL,
  `served_at` timestamp NULL DEFAULT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `dining_table_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_address` varchar(255) DEFAULT NULL,
  `delivery_phone` varchar(255) DEFAULT NULL,
  `delivery_rider_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_status_id` bigint(20) unsigned DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL COMMENT 'walk-in label',
  `customer_phone` varchar(255) DEFAULT NULL,
  `placed_via_qr` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `subtotal` int(11) NOT NULL DEFAULT 0,
  `discount` int(11) NOT NULL DEFAULT 0 COMMENT 'LKR cents, positive number, subtracted',
  `discount_reason` varchar(255) DEFAULT NULL,
  `discount_by_id` bigint(20) unsigned DEFAULT NULL,
  `service_charge` int(11) NOT NULL DEFAULT 0,
  `vat` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `staff_id` bigint(20) unsigned DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_tenant_id_client_key_unique` (`tenant_id`,`client_key`),
  KEY `orders_order_type_id_foreign` (`order_type_id`),
  KEY `orders_dining_mode_id_foreign` (`dining_mode_id`),
  KEY `orders_kot_status_id_foreign` (`kot_status_id`),
  KEY `orders_room_id_foreign` (`room_id`),
  KEY `orders_reservation_id_foreign` (`reservation_id`),
  KEY `orders_discount_by_id_foreign` (`discount_by_id`),
  KEY `orders_staff_id_foreign` (`staff_id`),
  KEY `orders_order_status_id_created_at_index` (`order_status_id`,`created_at`),
  KEY `orders_dining_table_id_foreign` (`dining_table_id`),
  KEY `orders_delivery_rider_id_foreign` (`delivery_rider_id`),
  KEY `orders_delivery_status_id_foreign` (`delivery_status_id`),
  KEY `orders_parent_order_id_foreign` (`parent_order_id`),
  KEY `order_status_created_idx` (`tenant_id`,`order_status_id`,`created_at`),
  KEY `order_type_status_created_idx` (`tenant_id`,`order_type_id`,`order_status_id`,`created_at`),
  KEY `order_kot_created_idx` (`tenant_id`,`kot_status_id`,`created_at`),
  KEY `order_table_status_idx` (`tenant_id`,`dining_table_id`,`order_status_id`),
  KEY `order_room_status_idx` (`tenant_id`,`room_id`,`order_status_id`),
  KEY `order_delivery_status_idx` (`tenant_id`,`delivery_status_id`,`order_status_id`),
  KEY `order_client_key_idx` (`client_key`),
  CONSTRAINT `orders_delivery_rider_id_foreign` FOREIGN KEY (`delivery_rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_delivery_status_id_foreign` FOREIGN KEY (`delivery_status_id`) REFERENCES `lookups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_dining_mode_id_foreign` FOREIGN KEY (`dining_mode_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `orders_dining_table_id_foreign` FOREIGN KEY (`dining_table_id`) REFERENCES `dining_tables` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_discount_by_id_foreign` FOREIGN KEY (`discount_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_kot_status_id_foreign` FOREIGN KEY (`kot_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `orders_order_status_id_foreign` FOREIGN KEY (`order_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `orders_order_type_id_foreign` FOREIGN KEY (`order_type_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `orders_parent_order_id_foreign` FOREIGN KEY (`parent_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `orders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (1,2,NULL,'8da837ea-5fe5-4fb4-9372-c25788253c86',82,79,13,21,'2026-08-25 15:39:26',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,45000,0,NULL,NULL,0,0,45000,7,NULL,NULL,'2026-08-25 05:30:39','2026-08-25 15:39:26'),(2,2,NULL,'68302857-e232-4bc7-9239-7a0afef19bb4',81,79,16,23,'2026-08-25 15:39:29','2026-08-25 15:39:31','2026-08-25 15:39:32',16,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,NULL,NULL,0,NULL,75000,0,NULL,NULL,0,0,75000,7,'2026-08-25 15:39:40',NULL,'2026-08-25 15:39:18','2026-08-25 15:39:40'),(3,2,NULL,'c16347fb-771e-4688-a95d-5e15dacdab86',81,79,16,23,'2026-08-25 16:57:25','2026-08-25 16:58:39','2026-08-25 16:59:10',15,NULL,NULL,NULL,NULL,NULL,NULL,NULL,6,NULL,NULL,0,NULL,15000,0,NULL,NULL,0,0,15000,9,'2026-08-25 17:01:30',NULL,'2026-08-25 16:56:27','2026-08-25 17:01:30'),(4,2,NULL,'d97876e3-7550-4297-91ce-58db97791b2e',82,79,13,20,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,30000,0,NULL,NULL,0,0,30000,9,NULL,NULL,'2026-08-25 17:11:57','2026-08-25 17:11:57'),(5,2,NULL,'d579805f-3086-4baa-af32-c8c91e31321e',82,79,13,20,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,30000,0,NULL,NULL,0,0,30000,7,NULL,NULL,'2026-08-25 17:22:03','2026-08-25 17:22:03'),(6,2,NULL,'02a76d78-54a1-47cd-8f69-5da32104019c',82,79,13,20,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,15000,0,NULL,NULL,0,0,15000,9,NULL,NULL,'2026-08-25 17:31:12','2026-08-25 17:31:12'),(7,2,NULL,'cdae892d-cee1-48f2-885c-66b52fbdbddb',81,79,16,23,'2026-08-25 19:45:59','2026-08-25 19:46:19','2026-08-25 19:46:34',15,NULL,NULL,NULL,NULL,NULL,NULL,NULL,10,NULL,NULL,0,NULL,15000,0,NULL,NULL,0,0,15000,9,'2026-08-25 19:46:51',NULL,'2026-08-25 19:45:32','2026-08-25 19:46:51');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `packages`
--

DROP TABLE IF EXISTS `packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_person_per_night` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents',
  `meal_inclusions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meal_inclusions`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `packages_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `packages_created_by_foreign` (`created_by`),
  KEY `packages_updated_by_foreign` (`updated_by`),
  CONSTRAINT `packages_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `packages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `packages_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `packages`
--

LOCK TABLES `packages` WRITE;
/*!40000 ALTER TABLE `packages` DISABLE KEYS */;
INSERT INTO `packages` VALUES (1,1,'RO','Room Only',NULL,0,'[]',1,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(2,1,'BB','Bed & Breakfast',NULL,150000,'[\"Breakfast\"]',1,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(3,1,'HB','Half Board',NULL,350000,'[\"Breakfast\",\"Dinner\"]',1,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(4,1,'FB','Full Board',NULL,500000,'[\"Breakfast\",\"Lunch\",\"Dinner\"]',1,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL);
/*!40000 ALTER TABLE `packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passkeys`
--

DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`credential`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  KEY `passkeys_user_id_index` (`user_id`),
  CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passkeys`
--

LOCK TABLES `passkeys` WRITE;
/*!40000 ALTER TABLE `passkeys` DISABLE KEYS */;
/*!40000 ALTER TABLE `passkeys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL COMMENT 'offline-POS replay safety',
  `payment_kind_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` bigint(20) unsigned NOT NULL,
  `amount` int(10) unsigned NOT NULL COMMENT 'LKR cents; refunds stored positive with kind=refund',
  `reference` varchar(255) DEFAULT NULL COMMENT 'card slip no, bank ref, QR txn id',
  `reason` text DEFAULT NULL COMMENT 'mandatory for refunds — enforced in BillingService',
  `folio_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `corporate_account_id` bigint(20) unsigned DEFAULT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `till_session_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_tenant_id_idempotency_key_unique` (`tenant_id`,`idempotency_key`),
  KEY `payments_payment_kind_id_foreign` (`payment_kind_id`),
  KEY `payments_payment_method_id_foreign` (`payment_method_id`),
  KEY `payments_folio_id_foreign` (`folio_id`),
  KEY `payments_corporate_account_id_foreign` (`corporate_account_id`),
  KEY `payments_staff_id_foreign` (`staff_id`),
  KEY `payments_created_at_index` (`created_at`),
  KEY `payments_till_session_id_foreign` (`till_session_id`),
  KEY `payment_order_kind_idx` (`order_id`,`payment_kind_id`),
  KEY `payment_order_idem_idx` (`order_id`,`idempotency_key`),
  CONSTRAINT `payments_corporate_account_id_foreign` FOREIGN KEY (`corporate_account_id`) REFERENCES `corporate_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_folio_id_foreign` FOREIGN KEY (`folio_id`) REFERENCES `folios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_payment_kind_id_foreign` FOREIGN KEY (`payment_kind_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `payments_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `payments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_till_session_id_foreign` FOREIGN KEY (`till_session_id`) REFERENCES `till_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,2,NULL,54,47,50000,NULL,NULL,2,NULL,NULL,7,1,'2026-08-25 15:36:23','2026-08-25 15:36:23'),(2,2,'3e67d992-bedd-45c5-8003-58198c0c6b22',53,47,200000,NULL,NULL,2,NULL,NULL,7,1,'2026-08-25 15:37:23','2026-08-25 15:37:23'),(3,2,NULL,54,47,480000,NULL,NULL,3,NULL,NULL,7,1,'2026-08-25 15:38:38','2026-08-25 15:38:38'),(4,2,NULL,53,47,1995000,NULL,NULL,3,NULL,NULL,7,1,'2026-08-25 15:40:19','2026-08-25 15:40:19'),(5,2,NULL,54,47,190000,NULL,NULL,6,NULL,NULL,9,2,'2026-08-25 16:52:27','2026-08-25 16:52:27'),(6,2,NULL,53,47,775000,NULL,NULL,6,NULL,NULL,9,2,'2026-08-25 17:15:35','2026-08-25 17:15:35'),(7,2,NULL,54,47,100000,NULL,NULL,7,NULL,NULL,9,2,'2026-08-25 17:34:57','2026-08-25 17:34:57'),(8,2,NULL,53,47,230000,NULL,NULL,7,NULL,NULL,9,2,'2026-08-25 17:37:33','2026-08-25 17:37:33'),(9,2,NULL,54,47,100000,NULL,NULL,8,NULL,NULL,9,2,'2026-08-25 17:42:34','2026-08-25 17:42:34'),(10,2,NULL,54,47,100000,NULL,NULL,9,NULL,NULL,9,2,'2026-08-25 17:44:52','2026-08-25 17:44:52'),(11,2,NULL,55,50,80000,NULL,'booking cancel',9,NULL,NULL,9,2,'2026-08-25 17:45:37','2026-08-25 17:45:37');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_lines`
--

DROP TABLE IF EXISTS `payroll_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `run_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `base_salary` int(10) unsigned NOT NULL COMMENT 'snapshot at generation',
  `worked_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `ot_hours` decimal(8,2) NOT NULL DEFAULT 0.00 COMMENT 'auto: hours beyond standard, editable pre-finalize',
  `ot_pay` int(10) unsigned NOT NULL DEFAULT 0,
  `allowance` int(10) unsigned NOT NULL DEFAULT 0,
  `bonus` int(10) unsigned NOT NULL DEFAULT 0,
  `unpaid_leave_deduction` int(10) unsigned NOT NULL DEFAULT 0,
  `other_deduction` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'advances, no-pay etc.',
  `loan` int(10) unsigned NOT NULL DEFAULT 0,
  `advance` int(10) unsigned NOT NULL DEFAULT 0,
  `other_deduction_note` varchar(255) DEFAULT NULL,
  `gross` int(10) unsigned NOT NULL DEFAULT 0,
  `epf_employee` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'deducted from employee',
  `apit` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Sri Lanka APIT, deducted from employee',
  `epf_employer` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'employer contribution, not deducted',
  `etf` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'employer contribution',
  `net_pay` int(10) unsigned NOT NULL DEFAULT 0,
  `employer_cost` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'gross + epf_employer + etf, not deducted',
  `paid` tinyint(1) NOT NULL DEFAULT 0,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_lines_run_id_user_id_unique` (`run_id`,`user_id`),
  KEY `payroll_lines_user_id_foreign` (`user_id`),
  KEY `payroll_lines_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `payroll_lines_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_lines_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_lines`
--

LOCK TABLES `payroll_lines` WRITE;
/*!40000 ALTER TABLE `payroll_lines` DISABLE KEYS */;
INSERT INTO `payroll_lines` VALUES (1,2,1,7,0,9.50,0.00,0,0,0,0,0,0,0,NULL,0,0,0,0,0,0,0,0,NULL,'2026-08-25 16:13:05','2026-08-25 16:13:05'),(2,2,1,8,0,0.00,0.00,0,0,0,0,0,0,0,NULL,0,0,0,0,0,0,0,0,NULL,'2026-08-25 16:13:05','2026-08-25 16:13:05'),(3,2,1,9,0,0.00,0.00,0,0,0,0,0,0,0,NULL,0,0,0,0,0,0,0,0,NULL,'2026-08-25 16:13:05','2026-08-25 16:13:05');
/*!40000 ALTER TABLE `payroll_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `month` varchar(255) NOT NULL COMMENT '"2026-07"',
  `payroll_status_id` bigint(20) unsigned NOT NULL,
  `run_by_id` bigint(20) unsigned NOT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_runs_tenant_id_month_unique` (`tenant_id`,`month`),
  KEY `payroll_runs_payroll_status_id_foreign` (`payroll_status_id`),
  KEY `payroll_runs_run_by_id_foreign` (`run_by_id`),
  CONSTRAINT `payroll_runs_payroll_status_id_foreign` FOREIGN KEY (`payroll_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `payroll_runs_run_by_id_foreign` FOREIGN KEY (`run_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_runs`
--

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
INSERT INTO `payroll_runs` VALUES (1,2,'2026-08',29,7,NULL,'2026-08-25 16:13:05','2026-08-25 16:13:05');
/*!40000 ALTER TABLE `payroll_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'dashboard.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(2,'user_management_users.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(3,'user_management_users.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(4,'user_management_users.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(5,'user_management_users.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(6,'user_management_users.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(7,'user_management_users.bulk_delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(8,'user_management_users.unlock','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(9,'user_management_users.reset_password','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(10,'user_management_roles.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(11,'user_management_roles.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(12,'user_management_roles.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(13,'user_management_roles.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(14,'user_management_roles.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(15,'user_management_roles.duplicate','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(16,'user_management_roles.toggle_active','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(17,'audit_logs.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(18,'audit_logs.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(19,'audit_logs.export','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(20,'hotel_rooms.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(21,'hotel_rooms.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(22,'hotel_rooms.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(23,'hotel_rooms.edit_status','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(24,'hotel_rooms.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(25,'hotel_packages.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(26,'hotel_packages.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(27,'hotel_guests.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(28,'hotel_guests.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(29,'hotel_guests.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(30,'hotel_guests.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(31,'hotel_guests.loyalty_adjust','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(32,'hotel_corporate.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(33,'hotel_corporate.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(34,'hotel_corporate.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(35,'hotel_reservations.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(36,'hotel_reservations.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(37,'hotel_reservations.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(38,'hotel_reservations.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(39,'hotel_reservations.check_in','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(40,'hotel_reservations.checkout','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(41,'hotel_reservations.cancel','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(42,'hotel_reservations.discount','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(43,'hotel_reservations.early_departure','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(44,'hotel_folios.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(45,'hotel_folios.add_line','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(46,'hotel_folios.void_line','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(47,'hotel_folios.payment','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(48,'hotel_folios.refund','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(49,'hotel_folios.invoice','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(50,'hotel_menu_categories.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(51,'hotel_menu_categories.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(52,'hotel_menu_categories.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(53,'hotel_menu_categories.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(54,'hotel_menu_items.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(55,'hotel_menu_items.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(56,'hotel_menu_items.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(57,'hotel_menu_items.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(58,'hotel_menu_items.sold_out','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(59,'hotel_ingredients.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(60,'hotel_ingredients.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(61,'hotel_ingredients.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(62,'hotel_ingredients.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(63,'hotel_ingredients.adjust_stock','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(64,'hotel_ingredients.write_off','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(65,'hotel_products.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(66,'hotel_products.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(67,'hotel_products.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(68,'hotel_products.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(69,'hotel_products.adjust_stock','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(70,'hotel_grn.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(71,'hotel_grn.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(72,'hotel_grn.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(73,'hotel_grn.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(74,'hotel_grn.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(75,'hotel_grn.receive','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(76,'hotel_orders.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(77,'hotel_orders.view','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(78,'hotel_orders.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(79,'hotel_orders.kot','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(80,'hotel_orders.void_item','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(81,'hotel_orders.hold','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(82,'hotel_orders.discount','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(83,'hotel_orders.settle','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(84,'hotel_orders.charge_to_room','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(85,'hotel_orders.void','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(86,'hotel_orders.refund','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(87,'hotel_orders.receipt','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(88,'hotel_orders.slip','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(89,'hotel_orders.kot_ticket','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(90,'hotel_orders.split','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(91,'hotel_orders.merge','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(92,'hotel_orders.delivery_dispatch','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(93,'hotel_dining_tables.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(94,'hotel_dining_tables.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(95,'hotel_dining_tables.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(96,'hotel_dining_tables.edit_status','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(97,'hotel_dining_tables.delete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(98,'hotel_qr_ordering.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(99,'hotel_qr_ordering.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(100,'hotel_qr_ordering.edit','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(101,'hotel_qr_ordering.regenerate','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(102,'hotel_housekeeping.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(103,'hotel_housekeeping.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(104,'hotel_housekeeping.assign','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(105,'hotel_housekeeping.checklist','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(106,'hotel_housekeeping.complete','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(107,'hotel_maintenance.access','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(108,'hotel_maintenance.create','2026-08-24 04:16:34','2026-08-24 04:16:34',NULL),(109,'hotel_maintenance.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(110,'hotel_laundry.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(111,'hotel_laundry.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(112,'hotel_laundry.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(113,'hotel_laundry.charge','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(114,'hotel_venues.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(115,'hotel_venues.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(116,'hotel_venue_bookings.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(117,'hotel_venue_bookings.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(118,'hotel_venue_bookings.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(119,'hotel_venue_bookings.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(120,'hotel_venue_bookings.confirm','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(121,'hotel_venue_bookings.complete','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(122,'hotel_venue_bookings.cancel','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(123,'till.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(124,'till.open','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(125,'till.close','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(126,'till.close_any','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(127,'till.cash_in','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(128,'till.cash_out','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(129,'till.manage','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(130,'hotel_attendance.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(131,'hotel_attendance.on_duty','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(132,'hotel_attendance.view_all','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(133,'hotel_attendance.export','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(134,'hotel_payroll.manage_pay','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(135,'hotel_payroll.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(136,'hotel_payroll.generate','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(137,'hotel_payroll.adjust_line','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(138,'hotel_payroll.finalize','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(139,'hotel_payroll.delete_run','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(140,'hotel_payroll.mark_paid','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(141,'hotel_payroll.export','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(142,'hotel_payroll.payslip','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(143,'hotel_visitors.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(144,'hotel_visitors.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(145,'hotel_visitors.sign_out','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(146,'hotel_notifications.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(147,'hotel_notifications.test','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(148,'hotel_notifications.run_scheduled','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(149,'hotel_reports.dashboard','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(150,'hotel_reports.daily','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(151,'hotel_reports.monthly','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(152,'hotel_reports.night_audit_run','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(153,'hotel_reports.night_audit_view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(154,'hotel_reports.revpar','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(155,'hotel_reports.channel_mix','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(156,'hotel_reports.cancellations','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(157,'hotel_reports.guest_loyalty','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(158,'hotel_reports.corporate_ar','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(159,'hotel_reports.ops_sla','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(160,'hotel_reports.payroll_cost','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(161,'hotel_reports.venues','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(162,'hotel_reports.laundry','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(163,'restaurant_reports.pos','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(164,'restaurant_reports.menu_performance','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(165,'restaurant_reports.modifiers','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(166,'restaurant_reports.discounts_voids','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(167,'restaurant_reports.table_server','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(168,'restaurant_reports.delivery_performance','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(169,'restaurant_reports.kitchen_ticket_time','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(170,'restaurant_reports.shift_sales','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(171,'restaurant_reports.food_cost','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(172,'hotel_staff.set_pin','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(173,'apartment_properties.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(174,'apartment_properties.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(175,'apartment_properties.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(176,'apartment_unit_types.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(177,'apartment_unit_types.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(178,'apartment_unit_types.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(179,'apartment_units.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(180,'apartment_units.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(181,'apartment_units.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(182,'apartment_units.edit_status','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(183,'apartment_customers.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(184,'apartment_customers.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(185,'apartment_customers.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(186,'apartment_customers.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(187,'apartment_bookings.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(188,'apartment_bookings.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(189,'apartment_bookings.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(190,'apartment_bookings.check_in','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(191,'apartment_bookings.checkout','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(192,'apartment_bookings.cancel','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(193,'apartment_leases.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(194,'apartment_leases.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(195,'apartment_leases.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(196,'apartment_leases.renew','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(197,'apartment_leases.terminate','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(198,'apartment_leases.utility_reading','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(199,'apartment_sales.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(200,'apartment_sales.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(201,'apartment_sales.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(202,'apartment_sales.reserve','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(203,'apartment_sales.sign_agreement','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(204,'apartment_sales.complete','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(205,'apartment_sales.cancel','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(206,'apartment_ledgers.view','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(207,'apartment_ledgers.add_line','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(208,'apartment_ledgers.void_line','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(209,'apartment_ledgers.payment','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(210,'apartment_ledgers.refund','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(211,'apartment_housekeeping.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(212,'apartment_housekeeping.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(213,'apartment_housekeeping.assign','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(214,'apartment_housekeeping.checklist','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(215,'apartment_housekeeping.complete','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(216,'apartment_maintenance.access','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(217,'apartment_maintenance.create','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(218,'apartment_maintenance.edit','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(219,'apartment_reports.dashboard','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(220,'apartment_reports.occupancy_trend','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(221,'apartment_reports.revenue_channel','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(222,'apartment_reports.rent_roll','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(223,'apartment_reports.sales_pipeline','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(224,'apartment_reports.utilities','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL),(225,'apartment_reports.ops_sla','2026-08-24 04:16:35','2026-08-24 04:16:35',NULL);
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_menu_categories`
--

DROP TABLE IF EXISTS `pos_menu_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_menu_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_minibar` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'minibar items post to the folio as MINIBAR, not RESTAURANT',
  `kitchen_station_id` bigint(20) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_menu_categories_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `pos_menu_categories_created_by_foreign` (`created_by`),
  KEY `pos_menu_categories_updated_by_foreign` (`updated_by`),
  KEY `pos_menu_categories_kitchen_station_id_foreign` (`kitchen_station_id`),
  CONSTRAINT `pos_menu_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_categories_kitchen_station_id_foreign` FOREIGN KEY (`kitchen_station_id`) REFERENCES `lookups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_categories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_menu_categories`
--

LOCK TABLES `pos_menu_categories` WRITE;
/*!40000 ALTER TABLE `pos_menu_categories` DISABLE KEYS */;
INSERT INTO `pos_menu_categories` VALUES (1,2,'rice',1,0,92,1,7,9,'2026-08-25 05:27:19','2026-08-25 18:03:24',NULL),(2,2,'Chicken',2,0,NULL,1,9,9,'2026-08-25 18:01:23','2026-08-25 18:02:42','2026-08-25 18:02:42'),(3,2,'rice & curry',2,0,NULL,1,9,9,'2026-08-25 18:02:46','2026-08-25 18:02:55','2026-08-25 18:02:55'),(4,2,'Chinese item',2,0,NULL,1,9,9,'2026-08-25 18:03:24','2026-08-25 18:03:24',NULL);
/*!40000 ALTER TABLE `pos_menu_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_menu_items`
--

DROP TABLE IF EXISTS `pos_menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_menu_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `item_no` int(10) unsigned DEFAULT NULL COMMENT 'printed menu number — quick POS entry ("#12")',
  `name` varchar(255) NOT NULL,
  `menu_category_id` bigint(20) unsigned NOT NULL,
  `stock_ingredient_id` bigint(20) unsigned DEFAULT NULL,
  `price` int(10) unsigned NOT NULL COMMENT 'LKR cents',
  `description` varchar(255) DEFAULT NULL,
  `image` longtext DEFAULT NULL,
  `sold_out` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_menu_items_tenant_id_item_no_unique` (`tenant_id`,`item_no`),
  KEY `pos_menu_items_created_by_foreign` (`created_by`),
  KEY `pos_menu_items_updated_by_foreign` (`updated_by`),
  KEY `1` (`stock_ingredient_id`),
  KEY `menu_cat_active_sold_idx` (`menu_category_id`,`active`,`sold_out`),
  KEY `menu_active_sold_no_idx` (`active`,`sold_out`,`item_no`),
  KEY `menu_name_search_idx` (`name`),
  KEY `menu_item_no_idx` (`item_no`),
  CONSTRAINT `1` FOREIGN KEY (`stock_ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_items_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_items_menu_category_id_foreign` FOREIGN KEY (`menu_category_id`) REFERENCES `pos_menu_categories` (`id`),
  CONSTRAINT `pos_menu_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_menu_items_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_menu_items`
--

LOCK TABLES `pos_menu_items` WRITE;
/*!40000 ALTER TABLE `pos_menu_items` DISABLE KEYS */;
INSERT INTO `pos_menu_items` VALUES (1,2,1,'tea',1,1,15000,'',NULL,0,1,7,7,'2026-08-25 05:27:45','2026-08-25 05:27:45'),(2,2,2,'rice full',1,NULL,50000,'',NULL,0,1,9,9,'2026-08-25 18:07:58','2026-08-25 18:07:58'),(3,2,3,'fried rice',1,NULL,100000,'',NULL,0,1,9,9,'2026-08-25 18:15:18','2026-08-25 18:15:18');
/*!40000 ALTER TABLE `pos_menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_ordering_points`
--

DROP TABLE IF EXISTS `qr_ordering_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_ordering_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `dining_table_id` bigint(20) unsigned DEFAULT NULL,
  `token` varchar(40) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_ordering_points_token_unique` (`token`),
  UNIQUE KEY `qr_ordering_points_room_id_unique` (`room_id`),
  UNIQUE KEY `qr_ordering_points_dining_table_id_unique` (`dining_table_id`),
  KEY `qr_ordering_points_created_by_foreign` (`created_by`),
  KEY `qr_ordering_points_updated_by_foreign` (`updated_by`),
  KEY `qr_ordering_points_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `qr_ordering_points_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `qr_ordering_points_dining_table_id_foreign` FOREIGN KEY (`dining_table_id`) REFERENCES `dining_tables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qr_ordering_points_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qr_ordering_points_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `qr_ordering_points_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_ordering_points`
--

LOCK TABLES `qr_ordering_points` WRITE;
/*!40000 ALTER TABLE `qr_ordering_points` DISABLE KEYS */;
/*!40000 ALTER TABLE `qr_ordering_points` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recipe_items`
--

DROP TABLE IF EXISTS `recipe_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recipe_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL COMMENT 'per 1 portion',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipe_items_menu_item_id_ingredient_id_unique` (`menu_item_id`,`ingredient_id`),
  KEY `recipe_items_ingredient_id_foreign` (`ingredient_id`),
  KEY `recipe_items_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `recipe_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_items_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `pos_menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipe_items`
--

LOCK TABLES `recipe_items` WRITE;
/*!40000 ALTER TABLE `recipe_items` DISABLE KEYS */;
INSERT INTO `recipe_items` VALUES (1,2,2,4,0.500,'2026-08-25 18:07:58','2026-08-25 18:07:58'),(2,2,2,3,0.300,'2026-08-25 18:07:58','2026-08-25 18:07:58'),(3,2,3,3,0.500,'2026-08-25 18:15:18','2026-08-25 18:15:18');
/*!40000 ALTER TABLE `recipe_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservation_rooms`
--

DROP TABLE IF EXISTS `reservation_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `nightly_rate` int(10) unsigned NOT NULL COMMENT 'LKR cents — locked at booking, after any corporate discount',
  `bill_to_guest_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reservation_rooms_reservation_id_room_id_unique` (`reservation_id`,`room_id`),
  KEY `reservation_rooms_room_id_foreign` (`room_id`),
  KEY `reservation_rooms_bill_to_guest_id_foreign` (`bill_to_guest_id`),
  KEY `reservation_rooms_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `reservation_rooms_bill_to_guest_id_foreign` FOREIGN KEY (`bill_to_guest_id`) REFERENCES `guests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservation_rooms_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reservation_rooms_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `reservation_rooms_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservation_rooms`
--

LOCK TABLES `reservation_rooms` WRITE;
/*!40000 ALTER TABLE `reservation_rooms` DISABLE KEYS */;
INSERT INTO `reservation_rooms` VALUES (2,2,2,15,250000,NULL,'2026-08-25 15:36:23','2026-08-25 15:36:23'),(3,2,3,16,1200000,NULL,'2026-08-25 15:38:38','2026-08-25 15:38:38'),(6,2,6,15,950000,NULL,'2026-08-25 16:52:27','2026-08-25 16:52:27'),(7,2,7,16,500000,NULL,'2026-08-25 17:34:57','2026-08-25 17:34:57'),(8,2,8,17,500000,NULL,'2026-08-25 17:42:34','2026-08-25 17:42:34'),(9,2,9,19,500000,NULL,'2026-08-25 17:44:52','2026-08-25 17:44:52'),(10,2,10,15,950000,NULL,'2026-08-25 19:43:03','2026-08-25 19:43:03');
/*!40000 ALTER TABLE `reservation_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservations`
--

DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `guest_id` bigint(20) unsigned NOT NULL,
  `booking_channel_id` bigint(20) unsigned NOT NULL,
  `reservation_status_id` bigint(20) unsigned NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `adults` int(10) unsigned NOT NULL DEFAULT 1,
  `children` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'under free-age per policy setting — not charged',
  `package_id` bigint(20) unsigned DEFAULT NULL,
  `group_booking_id` bigint(20) unsigned DEFAULT NULL,
  `corporate_account_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deposit_due` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents, from Setting % at booking',
  `pre_check_in` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'guest-submitted pre-arrival form' CHECK (json_valid(`pre_check_in`)),
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `checked_out_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reservations_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `reservations_guest_id_foreign` (`guest_id`),
  KEY `reservations_booking_channel_id_foreign` (`booking_channel_id`),
  KEY `reservations_reservation_status_id_foreign` (`reservation_status_id`),
  KEY `reservations_package_id_foreign` (`package_id`),
  KEY `reservations_group_booking_id_foreign` (`group_booking_id`),
  KEY `reservations_corporate_account_id_foreign` (`corporate_account_id`),
  KEY `reservations_created_by_foreign` (`created_by`),
  KEY `reservations_updated_by_foreign` (`updated_by`),
  KEY `reservations_check_in_check_out_index` (`check_in`,`check_out`),
  CONSTRAINT `reservations_booking_channel_id_foreign` FOREIGN KEY (`booking_channel_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `reservations_corporate_account_id_foreign` FOREIGN KEY (`corporate_account_id`) REFERENCES `corporate_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservations_group_booking_id_foreign` FOREIGN KEY (`group_booking_id`) REFERENCES `group_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservations_guest_id_foreign` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `reservations_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservations_reservation_status_id_foreign` FOREIGN KEY (`reservation_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `reservations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reservations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservations`
--

LOCK TABLES `reservations` WRITE;
/*!40000 ALTER TABLE `reservations` DISABLE KEYS */;
INSERT INTO `reservations` VALUES (2,2,'RSV-0001',2,73,6,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,50000,NULL,'2026-08-25 15:36:34','2026-08-25 15:37:30',NULL,NULL,7,7,'2026-08-25 15:36:23','2026-08-25 15:37:30',NULL),(3,2,'RSV-0002',3,73,6,'2026-08-25','2026-08-27',2,0,NULL,NULL,NULL,NULL,480000,NULL,'2026-08-25 15:38:53','2026-08-25 15:40:19',NULL,NULL,7,7,'2026-08-25 15:38:38','2026-08-25 15:40:19',NULL),(6,2,'RSV-0003',6,73,6,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,190000,NULL,'2026-08-25 16:54:24','2026-08-25 17:15:35',NULL,NULL,9,9,'2026-08-25 16:52:27','2026-08-25 17:15:35',NULL),(7,2,'RSV-0004',7,73,6,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,100000,NULL,'2026-08-25 17:35:09','2026-08-25 17:37:33',NULL,NULL,9,9,'2026-08-25 17:34:57','2026-08-25 17:37:33',NULL),(8,2,'RSV-0005',7,72,7,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,100000,NULL,NULL,NULL,'2026-08-25 17:43:17','customer request',9,9,'2026-08-25 17:42:34','2026-08-25 17:43:17',NULL),(9,2,'RSV-0006',8,73,7,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,100000,NULL,NULL,NULL,'2026-08-25 17:46:39','not comming',9,9,'2026-08-25 17:44:52','2026-08-25 17:46:39',NULL),(10,2,'RSV-0007',9,72,5,'2026-08-25','2026-08-26',2,0,NULL,NULL,NULL,NULL,190000,NULL,'2026-08-25 19:43:32',NULL,NULL,NULL,9,9,'2026-08-25 19:43:03','2026-08-25 19:43:32',NULL);
/*!40000 ALTER TABLE `reservations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),(1,11),(1,12),(1,13),(1,14),(1,15),(1,16),(1,17),(1,18),(1,19),(1,20),(1,21),(1,22),(1,23),(1,24),(1,25),(1,26),(1,27),(1,28),(1,29),(1,30),(1,31),(1,32),(1,33),(1,34),(1,35),(1,36),(1,37),(1,38),(1,39),(1,40),(1,41),(1,42),(1,43),(1,44),(1,45),(1,46),(1,47),(1,48),(1,49),(1,50),(1,51),(1,52),(1,53),(1,54),(1,55),(1,56),(1,57),(1,58),(1,59),(1,60),(1,61),(1,62),(1,63),(1,64),(1,65),(1,66),(1,67),(1,68),(1,69),(1,70),(1,71),(1,72),(1,73),(1,74),(1,75),(1,76),(1,77),(1,78),(1,79),(1,80),(1,81),(1,82),(1,83),(1,84),(1,85),(1,86),(1,87),(1,88),(1,89),(1,90),(1,91),(1,92),(1,93),(1,94),(1,95),(1,96),(1,97),(1,98),(1,99),(1,100),(1,101),(1,102),(1,103),(1,104),(1,105),(1,106),(1,107),(1,108),(1,109),(1,110),(1,111),(1,112),(1,113),(1,114),(1,115),(1,116),(1,117),(1,118),(1,119),(1,120),(1,121),(1,122),(1,123),(1,124),(1,125),(1,126),(1,127),(1,128),(1,129),(1,130),(1,131),(1,132),(1,133),(1,134),(1,135),(1,136),(1,137),(1,138),(1,139),(1,140),(1,141),(1,142),(1,143),(1,144),(1,145),(1,146),(1,147),(1,148),(1,149),(1,150),(1,151),(1,152),(1,153),(1,154),(1,155),(1,156),(1,157),(1,158),(1,159),(1,160),(1,161),(1,162),(1,163),(1,164),(1,165),(1,166),(1,167),(1,168),(1,169),(1,170),(1,171),(1,172),(1,173),(1,174),(1,175),(1,176),(1,177),(1,178),(1,179),(1,180),(1,181),(1,182),(1,183),(1,184),(1,185),(1,186),(1,187),(1,188),(1,189),(1,190),(1,191),(1,192),(1,193),(1,194),(1,195),(1,196),(1,197),(1,198),(1,199),(1,200),(1,201),(1,202),(1,203),(1,204),(1,205),(1,206),(1,207),(1,208),(1,209),(1,210),(1,211),(1,212),(1,213),(1,214),(1,215),(1,216),(1,217),(1,218),(1,219),(1,220),(1,221),(1,222),(1,223),(1,224),(1,225),(2,1),(2,2),(2,3),(2,4),(2,5),(2,10),(2,11),(2,17),(2,18),(2,20),(2,21),(2,22),(2,23),(2,24),(2,25),(2,26),(2,27),(2,28),(2,29),(2,30),(2,31),(2,32),(2,33),(2,34),(2,35),(2,36),(2,37),(2,38),(2,39),(2,40),(2,41),(2,42),(2,43),(2,44),(2,45),(2,46),(2,47),(2,48),(2,49),(2,50),(2,51),(2,52),(2,53),(2,54),(2,55),(2,56),(2,57),(2,58),(2,59),(2,60),(2,61),(2,62),(2,63),(2,64),(2,65),(2,66),(2,67),(2,68),(2,69),(2,70),(2,71),(2,72),(2,73),(2,74),(2,75),(2,76),(2,77),(2,78),(2,79),(2,80),(2,81),(2,82),(2,83),(2,84),(2,85),(2,86),(2,87),(2,88),(2,89),(2,90),(2,91),(2,92),(2,93),(2,94),(2,95),(2,96),(2,97),(2,102),(2,103),(2,104),(2,105),(2,106),(2,107),(2,108),(2,109),(2,110),(2,111),(2,112),(2,113),(2,114),(2,115),(2,116),(2,117),(2,118),(2,119),(2,120),(2,121),(2,122),(2,123),(2,124),(2,125),(2,126),(2,127),(2,128),(2,129),(2,130),(2,131),(2,132),(2,133),(2,143),(2,144),(2,145),(2,146),(2,148),(2,149),(2,150),(2,151),(2,152),(2,153),(2,154),(2,155),(2,156),(2,157),(2,158),(2,159),(2,161),(2,162),(2,163),(2,164),(2,165),(2,166),(2,167),(2,168),(2,169),(2,170),(2,171),(2,172),(2,173),(2,174),(2,175),(2,176),(2,177),(2,178),(2,179),(2,180),(2,181),(2,182),(2,183),(2,184),(2,185),(2,186),(2,187),(2,188),(2,189),(2,190),(2,191),(2,192),(2,193),(2,194),(2,195),(2,196),(2,197),(2,198),(2,199),(2,200),(2,201),(2,202),(2,203),(2,204),(2,205),(2,206),(2,207),(2,208),(2,209),(2,210),(2,211),(2,212),(2,213),(2,214),(2,215),(2,216),(2,217),(2,218),(2,219),(2,220),(2,221),(2,222),(2,223),(2,224),(2,225),(3,1),(3,17),(3,18),(3,19),(4,1),(4,20),(4,21),(4,22),(4,23),(4,24),(4,25),(4,26),(4,27),(4,28),(4,29),(4,30),(4,31),(4,32),(4,33),(4,34),(4,35),(4,36),(4,37),(4,38),(4,39),(4,40),(4,41),(4,42),(4,43),(4,44),(4,45),(4,46),(4,47),(4,48),(4,49),(4,50),(4,51),(4,52),(4,53),(4,54),(4,55),(4,56),(4,57),(4,58),(4,59),(4,60),(4,61),(4,62),(4,63),(4,64),(4,65),(4,66),(4,67),(4,68),(4,69),(4,70),(4,71),(4,72),(4,73),(4,74),(4,75),(4,76),(4,77),(4,78),(4,79),(4,80),(4,81),(4,82),(4,83),(4,84),(4,85),(4,86),(4,87),(4,88),(4,89),(4,90),(4,91),(4,92),(4,93),(4,94),(4,95),(4,96),(4,97),(4,102),(4,103),(4,104),(4,105),(4,106),(4,107),(4,108),(4,109),(4,110),(4,111),(4,112),(4,113),(4,114),(4,115),(4,116),(4,117),(4,118),(4,119),(4,120),(4,121),(4,122),(4,123),(4,124),(4,125),(4,126),(4,127),(4,128),(4,129),(4,130),(4,131),(4,132),(4,133),(4,134),(4,135),(4,136),(4,137),(4,138),(4,139),(4,140),(4,141),(4,142),(4,143),(4,144),(4,145),(4,146),(4,148),(4,149),(4,150),(4,151),(4,152),(4,153),(4,154),(4,155),(4,156),(4,157),(4,158),(4,159),(4,160),(4,161),(4,162),(4,163),(4,164),(4,165),(4,166),(4,167),(4,168),(4,169),(4,170),(4,171),(4,172),(4,173),(4,174),(4,175),(4,176),(4,177),(4,178),(4,179),(4,180),(4,181),(4,182),(4,183),(4,184),(4,185),(4,186),(4,187),(4,188),(4,189),(4,190),(4,191),(4,192),(4,193),(4,194),(4,195),(4,196),(4,197),(4,198),(4,199),(4,200),(4,201),(4,202),(4,203),(4,204),(4,205),(4,206),(4,207),(4,208),(4,209),(4,210),(4,211),(4,212),(4,213),(4,214),(4,215),(4,216),(4,217),(4,218),(4,219),(4,220),(4,221),(4,222),(4,223),(4,224),(4,225),(5,1),(5,20),(5,23),(5,25),(5,50),(5,54),(5,102),(5,105),(5,106),(5,107),(5,108),(5,109),(5,110),(5,113),(5,130),(5,179),(5,211),(5,214),(5,215),(5,216),(5,217),(5,218),(6,1),(6,20),(6,25),(6,50),(6,54),(6,58),(6,59),(6,60),(6,61),(6,63),(6,64),(6,65),(6,66),(6,67),(6,69),(6,70),(6,71),(6,72),(6,73),(6,75),(6,76),(6,77),(6,79),(6,87),(6,88),(6,89),(6,93),(6,107),(6,108),(6,109),(6,130),(7,1),(7,20),(7,25),(7,50),(7,54),(7,107),(7,108),(7,109),(7,130),(7,143),(7,144),(7,145),(8,1),(8,2),(8,3),(8,4),(8,5),(8,6),(8,7),(8,8),(8,9),(8,10),(8,11),(8,12),(8,13),(8,14),(8,15),(8,16),(8,17),(8,18),(8,19),(8,20),(8,21),(8,22),(8,23),(8,24),(8,25),(8,26),(8,27),(8,28),(8,29),(8,30),(8,31),(8,32),(8,33),(8,34),(8,35),(8,36),(8,37),(8,38),(8,39),(8,40),(8,41),(8,42),(8,43),(8,44),(8,45),(8,46),(8,47),(8,48),(8,49),(8,50),(8,51),(8,52),(8,53),(8,54),(8,55),(8,56),(8,57),(8,58),(8,59),(8,60),(8,61),(8,62),(8,63),(8,64),(8,65),(8,66),(8,67),(8,68),(8,69),(8,70),(8,71),(8,72),(8,73),(8,74),(8,75),(8,76),(8,77),(8,78),(8,79),(8,80),(8,81),(8,82),(8,83),(8,84),(8,85),(8,86),(8,87),(8,88),(8,89),(8,90),(8,91),(8,92),(8,93),(8,94),(8,95),(8,96),(8,97),(8,98),(8,99),(8,100),(8,101),(8,102),(8,103),(8,104),(8,105),(8,106),(8,107),(8,108),(8,109),(8,110),(8,111),(8,112),(8,113),(8,114),(8,115),(8,116),(8,117),(8,118),(8,119),(8,120),(8,121),(8,122),(8,123),(8,124),(8,125),(8,126),(8,127),(8,128),(8,129),(8,130),(8,131),(8,132),(8,133),(8,134),(8,135),(8,136),(8,137),(8,138),(8,139),(8,140),(8,141),(8,142),(8,143),(8,144),(8,145),(8,146),(8,147),(8,148),(8,149),(8,150),(8,151),(8,152),(8,153),(8,154),(8,155),(8,156),(8,157),(8,158),(8,159),(8,160),(8,161),(8,162),(8,163),(8,164),(8,165),(8,166),(8,167),(8,168),(8,169),(8,170),(8,171),(8,172),(8,173),(8,174),(8,175),(8,176),(8,177),(8,178),(8,179),(8,180),(8,181),(8,182),(8,183),(8,184),(8,185),(8,186),(8,187),(8,188),(8,189),(8,190),(8,191),(8,192),(8,193),(8,194),(8,195),(8,196),(8,197),(8,198),(8,199),(8,200),(8,201),(8,202),(8,203),(8,204),(8,205),(8,206),(8,207),(8,208),(8,209),(8,210),(8,211),(8,212),(8,213),(8,214),(8,215),(8,216),(8,217),(8,218),(8,219),(8,220),(8,221),(8,222),(8,223),(8,224),(8,225),(9,1),(9,2),(9,3),(9,4),(9,5),(9,10),(9,11),(9,17),(9,18),(9,20),(9,21),(9,22),(9,23),(9,24),(9,25),(9,26),(9,27),(9,28),(9,29),(9,30),(9,31),(9,32),(9,33),(9,34),(9,35),(9,36),(9,37),(9,38),(9,39),(9,40),(9,41),(9,42),(9,43),(9,44),(9,45),(9,46),(9,47),(9,48),(9,49),(9,50),(9,51),(9,52),(9,53),(9,54),(9,55),(9,56),(9,57),(9,58),(9,59),(9,60),(9,61),(9,62),(9,63),(9,64),(9,65),(9,66),(9,67),(9,68),(9,69),(9,70),(9,71),(9,72),(9,73),(9,74),(9,75),(9,76),(9,77),(9,78),(9,79),(9,80),(9,81),(9,82),(9,83),(9,84),(9,85),(9,86),(9,87),(9,88),(9,89),(9,90),(9,91),(9,92),(9,93),(9,94),(9,95),(9,96),(9,97),(9,102),(9,103),(9,104),(9,105),(9,106),(9,107),(9,108),(9,109),(9,110),(9,111),(9,112),(9,113),(9,114),(9,115),(9,116),(9,117),(9,118),(9,119),(9,120),(9,121),(9,122),(9,123),(9,124),(9,125),(9,126),(9,127),(9,128),(9,129),(9,130),(9,131),(9,132),(9,133),(9,143),(9,144),(9,145),(9,146),(9,148),(9,149),(9,150),(9,151),(9,152),(9,153),(9,154),(9,155),(9,156),(9,157),(9,158),(9,159),(9,161),(9,162),(9,163),(9,164),(9,165),(9,166),(9,167),(9,168),(9,169),(9,170),(9,171),(9,172),(9,173),(9,174),(9,175),(9,176),(9,177),(9,178),(9,179),(9,180),(9,181),(9,182),(9,183),(9,184),(9,185),(9,186),(9,187),(9,188),(9,189),(9,190),(9,191),(9,192),(9,193),(9,194),(9,195),(9,196),(9,197),(9,198),(9,199),(9,200),(9,201),(9,202),(9,203),(9,204),(9,205),(9,206),(9,207),(9,208),(9,209),(9,210),(9,211),(9,212),(9,213),(9,214),(9,215),(9,216),(9,217),(9,218),(9,219),(9,220),(9,221),(9,222),(9,223),(9,224),(9,225),(10,1),(10,17),(10,18),(10,19),(11,1),(11,20),(11,21),(11,22),(11,23),(11,24),(11,25),(11,26),(11,27),(11,28),(11,29),(11,30),(11,31),(11,32),(11,33),(11,34),(11,35),(11,36),(11,37),(11,38),(11,39),(11,40),(11,41),(11,42),(11,43),(11,44),(11,45),(11,46),(11,47),(11,48),(11,49),(11,50),(11,51),(11,52),(11,53),(11,54),(11,55),(11,56),(11,57),(11,58),(11,59),(11,60),(11,61),(11,62),(11,63),(11,64),(11,65),(11,66),(11,67),(11,68),(11,69),(11,70),(11,71),(11,72),(11,73),(11,74),(11,75),(11,76),(11,77),(11,78),(11,79),(11,80),(11,81),(11,82),(11,83),(11,84),(11,85),(11,86),(11,87),(11,88),(11,89),(11,90),(11,91),(11,92),(11,93),(11,94),(11,95),(11,96),(11,97),(11,102),(11,103),(11,104),(11,105),(11,106),(11,107),(11,108),(11,109),(11,110),(11,111),(11,112),(11,113),(11,114),(11,115),(11,116),(11,117),(11,118),(11,119),(11,120),(11,121),(11,122),(11,123),(11,124),(11,125),(11,126),(11,127),(11,128),(11,129),(11,130),(11,131),(11,132),(11,133),(11,134),(11,135),(11,136),(11,137),(11,138),(11,139),(11,140),(11,141),(11,142),(11,143),(11,144),(11,145),(11,146),(11,148),(11,149),(11,150),(11,151),(11,152),(11,153),(11,154),(11,155),(11,156),(11,157),(11,158),(11,159),(11,160),(11,161),(11,162),(11,163),(11,164),(11,165),(11,166),(11,167),(11,168),(11,169),(11,170),(11,171),(11,172),(11,173),(11,174),(11,175),(11,176),(11,177),(11,178),(11,179),(11,180),(11,181),(11,182),(11,183),(11,184),(11,185),(11,186),(11,187),(11,188),(11,189),(11,190),(11,191),(11,192),(11,193),(11,194),(11,195),(11,196),(11,197),(11,198),(11,199),(11,200),(11,201),(11,202),(11,203),(11,204),(11,205),(11,206),(11,207),(11,208),(11,209),(11,210),(11,211),(11,212),(11,213),(11,214),(11,215),(11,216),(11,217),(11,218),(11,219),(11,220),(11,221),(11,222),(11,223),(11,224),(11,225),(12,1),(12,20),(12,23),(12,25),(12,50),(12,54),(12,102),(12,105),(12,106),(12,107),(12,108),(12,109),(12,110),(12,113),(12,130),(12,179),(12,211),(12,214),(12,215),(12,216),(12,217),(12,218),(13,1),(13,20),(13,25),(13,50),(13,54),(13,58),(13,59),(13,60),(13,61),(13,63),(13,64),(13,65),(13,66),(13,67),(13,69),(13,70),(13,71),(13,72),(13,73),(13,75),(13,76),(13,77),(13,79),(13,87),(13,88),(13,89),(13,93),(13,107),(13,108),(13,109),(13,130),(14,1),(14,20),(14,25),(14,50),(14,54),(14,107),(14,108),(14,109),(14,130),(14,143),(14,144),(14,145);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_full_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `roles_created_by_foreign` (`created_by`),
  KEY `roles_updated_by_foreign` (`updated_by`),
  CONSTRAINT `roles_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `roles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `roles_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,1,'Full Administrator','Full, unrestricted access to every module.',1,1,1,NULL,NULL,'2026-08-24 04:16:36','2026-08-24 04:16:36',NULL),(2,1,'Manager','Manages users and roles, with dashboard access.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(3,1,'Auditor','Read-only access to the dashboard and audit logs.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(4,1,'Owner','Hotel owner — full operational access; integrations remain System-Admin-only.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(5,1,'Housekeeper','Room cleaning tasks and status.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(6,1,'Chef','Kitchen order tickets and menu/inventory.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(7,1,'Security','Visitor log and maintenance reporting.',1,0,1,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(8,2,'Full Administrator','Full, unrestricted access to every module.',1,1,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(9,2,'Manager','Manages users and roles, with dashboard access.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(10,2,'Auditor','Read-only access to the dashboard and audit logs.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(11,2,'Owner','Hotel owner — full operational access; integrations remain System-Admin-only.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(12,2,'Housekeeper','Room cleaning tasks and status.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(13,2,'Chef','Kitchen order tickets and menu/inventory.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL),(14,2,'Security','Visitor log and maintenance reporting.',1,0,1,NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL);
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `room_item_checks`
--

DROP TABLE IF EXISTS `room_item_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_item_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `check_kind_id` bigint(20) unsigned NOT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '[{item, ok, note?}] — room item verification at check-in/out' CHECK (json_valid(`items`)),
  `staff_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `room_item_checks_reservation_id_foreign` (`reservation_id`),
  KEY `room_item_checks_room_id_foreign` (`room_id`),
  KEY `room_item_checks_check_kind_id_foreign` (`check_kind_id`),
  KEY `room_item_checks_staff_id_foreign` (`staff_id`),
  KEY `room_item_checks_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `room_item_checks_check_kind_id_foreign` FOREIGN KEY (`check_kind_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `room_item_checks_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_item_checks_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `room_item_checks_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `room_item_checks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `room_item_checks`
--

LOCK TABLES `room_item_checks` WRITE;
/*!40000 ALTER TABLE `room_item_checks` DISABLE KEYS */;
INSERT INTO `room_item_checks` VALUES (1,2,2,15,77,'[{\"item\":\"Bed linen, pillows & cushions,\",\"ok\":true},{\"item\":\"Bath towels, face towels & hand towels,\",\"ok\":true},{\"item\":\"TV & remote control,\",\"ok\":true},{\"item\":\"AC unit & remote control,\",\"ok\":true},{\"item\":\"Hangers,\",\"ok\":true},{\"item\":\"Electric Kettle & cups\\/glasses,\",\"ok\":true},{\"item\":\"Toiletries (soap, shampoo, toilet paper),\",\"ok\":true},{\"item\":\"Slippers,\",\"ok\":true},{\"item\":\"Minibar contents (if applicable),\",\"ok\":true},{\"item\":\"In-room safe (if applicable),\",\"ok\":true},{\"item\":\"curtains & window fittings,\",\"ok\":true},{\"item\":\"Light bulbs\\/ lamps functioning,\",\"ok\":true},{\"item\":\"Bathroom fittings (shower, tap, flush) in working order,\",\"ok\":true},{\"item\":\"WiFi info card,\",\"ok\":true},{\"item\":\"Do Not Disturb sign\",\"ok\":true}]',7,'2026-08-25 15:36:34','2026-08-25 15:36:34'),(2,2,3,16,77,'[{\"item\":\"Bed linen, pillows & cushions,\",\"ok\":true},{\"item\":\"Bath towels, face towels & hand towels,\",\"ok\":true},{\"item\":\"TV & remote control,\",\"ok\":true},{\"item\":\"AC unit & remote control,\",\"ok\":true},{\"item\":\"Hangers,\",\"ok\":true},{\"item\":\"Electric Kettle & cups\\/glasses,\",\"ok\":true},{\"item\":\"Toiletries (soap, shampoo, toilet paper),\",\"ok\":true},{\"item\":\"Slippers,\",\"ok\":true},{\"item\":\"Minibar contents (if applicable),\",\"ok\":true},{\"item\":\"In-room safe (if applicable),\",\"ok\":true},{\"item\":\"curtains & window fittings,\",\"ok\":true},{\"item\":\"Light bulbs\\/ lamps functioning,\",\"ok\":true},{\"item\":\"Bathroom fittings (shower, tap, flush) in working order,\",\"ok\":true},{\"item\":\"WiFi info card,\",\"ok\":true},{\"item\":\"Do Not Disturb sign\",\"ok\":true}]',7,'2026-08-25 15:38:53','2026-08-25 15:38:53'),(3,2,6,15,77,'[{\"item\":\"Bed linen, pillows & cushions,\",\"ok\":true},{\"item\":\"Bath towels, face towels & hand towels,\",\"ok\":true},{\"item\":\"TV & remote control,\",\"ok\":true},{\"item\":\"AC unit & remote control,\",\"ok\":false},{\"item\":\"Hangers,\",\"ok\":true},{\"item\":\"Electric Kettle & cups\\/glasses,\",\"ok\":true},{\"item\":\"Toiletries (soap, shampoo, toilet paper),\",\"ok\":true},{\"item\":\"Slippers,\",\"ok\":true},{\"item\":\"Minibar contents (if applicable),\",\"ok\":true},{\"item\":\"In-room safe (if applicable),\",\"ok\":true},{\"item\":\"curtains & window fittings,\",\"ok\":true},{\"item\":\"Light bulbs\\/ lamps functioning,\",\"ok\":true},{\"item\":\"Bathroom fittings (shower, tap, flush) in working order,\",\"ok\":true},{\"item\":\"WiFi info card,\",\"ok\":true},{\"item\":\"Do Not Disturb sign\",\"ok\":true}]',9,'2026-08-25 16:54:24','2026-08-25 16:54:24'),(4,2,7,16,77,'[{\"item\":\"Bed linen, pillows & cushions,\",\"ok\":true},{\"item\":\"Bath towels, face towels & hand towels,\",\"ok\":true},{\"item\":\"TV & remote control,\",\"ok\":true},{\"item\":\"AC unit & remote control,\",\"ok\":true},{\"item\":\"Hangers,\",\"ok\":true},{\"item\":\"Electric Kettle & cups\\/glasses,\",\"ok\":true},{\"item\":\"Toiletries (soap, shampoo, toilet paper),\",\"ok\":true},{\"item\":\"Slippers,\",\"ok\":true},{\"item\":\"Minibar contents (if applicable),\",\"ok\":true},{\"item\":\"In-room safe (if applicable),\",\"ok\":true},{\"item\":\"curtains & window fittings,\",\"ok\":true},{\"item\":\"Light bulbs\\/ lamps functioning,\",\"ok\":true},{\"item\":\"Bathroom fittings (shower, tap, flush) in working order,\",\"ok\":true},{\"item\":\"WiFi info card,\",\"ok\":true},{\"item\":\"Do Not Disturb sign\",\"ok\":true}]',9,'2026-08-25 17:35:09','2026-08-25 17:35:09'),(5,2,10,15,77,'[{\"item\":\"Bed linen, pillows & cushions,\",\"ok\":true},{\"item\":\"Bath towels, face towels & hand towels,\",\"ok\":true},{\"item\":\"TV & remote control,\",\"ok\":true},{\"item\":\"AC unit & remote control,\",\"ok\":true},{\"item\":\"Hangers,\",\"ok\":true},{\"item\":\"Electric Kettle & cups\\/glasses,\",\"ok\":true},{\"item\":\"Toiletries (soap, shampoo, toilet paper),\",\"ok\":true},{\"item\":\"Slippers,\",\"ok\":true},{\"item\":\"Minibar contents (if applicable),\",\"ok\":true},{\"item\":\"In-room safe (if applicable),\",\"ok\":true},{\"item\":\"curtains & window fittings,\",\"ok\":true},{\"item\":\"Light bulbs\\/ lamps functioning,\",\"ok\":true},{\"item\":\"Bathroom fittings (shower, tap, flush) in working order,\",\"ok\":true},{\"item\":\"WiFi info card,\",\"ok\":true},{\"item\":\"Do Not Disturb sign\",\"ok\":true}]',9,'2026-08-25 19:43:32','2026-08-25 19:43:32');
/*!40000 ALTER TABLE `room_item_checks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `room_types`
--

DROP TABLE IF EXISTS `room_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `max_occupancy` int(10) unsigned NOT NULL DEFAULT 2,
  `bed_config` varchar(255) DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `weekday_rate` int(10) unsigned NOT NULL COMMENT 'LKR cents/night',
  `weekend_rate` int(10) unsigned NOT NULL COMMENT 'LKR cents/night',
  `item_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Check-in/out item verification template' CHECK (json_valid(`item_checklist`)),
  `cleaning_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Housekeeping task template' CHECK (json_valid(`cleaning_checklist`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_types_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `room_types_created_by_foreign` (`created_by`),
  KEY `room_types_updated_by_foreign` (`updated_by`),
  CONSTRAINT `room_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `room_types_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `room_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `room_types`
--

LOCK TABLES `room_types` WRITE;
/*!40000 ALTER TABLE `room_types` DISABLE KEYS */;
INSERT INTO `room_types` VALUES (1,1,'Family 4-Person',4,'TBC — pending from owner','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]',1800000,2200000,'[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(2,1,'Family Special',5,'TBC — pending from owner','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]',2500000,3000000,'[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(3,1,'Two-Person Room',2,'TBC — pending from owner','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]',1200000,1500000,'[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(4,1,'Special Couple Room',2,'TBC — pending from owner','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]',1600000,2000000,'[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(5,1,'Triple Room',3,'TBC — pending from owner','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]',1500000,1800000,'[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL);
/*!40000 ALTER TABLE `room_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rooms`
--

DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL COMMENT 'Room category / type label (e.g. Deluxe, Family)',
  `max_occupancy` int(10) unsigned NOT NULL DEFAULT 2,
  `bed_config` varchar(255) DEFAULT NULL,
  `weekday_rate` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents/night',
  `weekend_rate` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'LKR cents/night',
  `room_type_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `floor` varchar(255) DEFAULT NULL,
  `view` varchar(255) DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `item_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Check-in/out item verification template (per-room)' CHECK (json_valid(`item_checklist`)),
  `cleaning_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Housekeeping task template (per-room)' CHECK (json_valid(`cleaning_checklist`)),
  `room_status_id` bigint(20) unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rooms_branch_id_number_unique` (`branch_id`,`number`),
  KEY `rooms_created_by_foreign` (`created_by`),
  KEY `rooms_updated_by_foreign` (`updated_by`),
  KEY `rooms_room_status_id_index` (`room_status_id`),
  KEY `rooms_tenant_id_foreign` (`tenant_id`),
  KEY `rooms_room_type_id_foreign` (`room_type_id`),
  CONSTRAINT `rooms_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `rooms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rooms_room_status_id_foreign` FOREIGN KEY (`room_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `rooms_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rooms_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rooms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rooms`
--

LOCK TABLES `rooms` WRITE;
/*!40000 ALTER TABLE `rooms` DISABLE KEYS */;
INSERT INTO `rooms` VALUES (1,NULL,'110','Family 4-Person',4,'TBC — pending from owner',1800000,2200000,1,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(2,NULL,'111','Family 4-Person',4,'TBC — pending from owner',1800000,2200000,1,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(3,NULL,'112','Family 4-Person',4,'TBC — pending from owner',1800000,2200000,1,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(4,NULL,'101','Family Special',5,'TBC — pending from owner',2500000,3000000,2,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43',NULL),(5,NULL,'102','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(6,NULL,'103','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(7,NULL,'104','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(8,NULL,'105','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(9,NULL,'115','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(10,NULL,'116','Two-Person Room',2,'TBC — pending from owner',1200000,1500000,3,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(11,NULL,'114','Special Couple Room',2,'TBC — pending from owner',1600000,2000000,4,1,'Upper','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(12,NULL,'106','Triple Room',3,'TBC — pending from owner',1500000,1800000,5,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(13,NULL,'107','Triple Room',3,'TBC — pending from owner',1500000,1800000,5,1,'Ground','Hill view','[\"AC\",\"TV\",\"WiFi\",\"Hot water\"]','[\"Bed linen, pillows & cushions\",\"Bath towels, hand towels & face towels\",\"TV & remote control\",\"AC unit & remote control\",\"Hangers\",\"Electric kettle & cups\\/glasses\",\"Toiletries (soap, shampoo, toilet paper)\",\"Slippers\",\"Minibar contents (if applicable)\",\"In-room safe (if applicable)\",\"Curtains & window fittings\",\"Light bulbs \\/ lamps functioning\",\"Bathroom fittings (shower, tap, flush) in working order\",\"WiFi info card \\/ Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen\",\"Replace used towels with fresh ones\",\"Dust all surfaces, furniture, and fittings\",\"Vacuum\\/mop the floor\",\"Clean bathroom \\u2014 toilet, shower\\/tub, sink, mirror\",\"Restock toiletries and guest amenities\",\"Empty and reline trash bins\",\"Restock\\/check minibar items\",\"Clean windows, mirrors, and glass surfaces\",\"Check AC, TV, and lights are functioning\",\"Check for and log any damage or maintenance issue found\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(14,NULL,'rm 1',NULL,2,NULL,1200000,1500000,NULL,2,NULL,NULL,'[]','[]','[]',9,NULL,7,7,'2026-08-24 19:52:21','2026-08-25 02:26:32','2026-08-25 02:26:32'),(15,NULL,'1101','Family Special',5,NULL,950000,950000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"Wifi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',10,NULL,7,9,'2026-08-25 15:27:28','2026-08-25 19:43:32',NULL),(16,NULL,'1102','Two-Person Room',2,NULL,500000,500000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,9,'2026-08-25 15:37:15','2026-08-25 17:43:46',NULL),(17,NULL,'1103','Two-Person Room',2,NULL,500000,500000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:39:45','2026-08-25 16:04:53',NULL),(18,NULL,'1104','Two-Person Room',2,NULL,500000,500000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:42:05','2026-08-25 16:05:05',NULL),(19,NULL,'1105','Two-Person Room',2,NULL,500000,500000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:44:06','2026-08-25 16:05:19',NULL),(20,NULL,'1106','Triple Room',3,NULL,600000,600000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:46:27','2026-08-25 16:05:47',NULL),(21,NULL,'1107','Triple Room',3,NULL,600000,600000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:47:39','2026-08-25 16:06:00',NULL),(22,NULL,'1110','Family 4-Person',4,NULL,770000,770000,NULL,2,'Upper','Hill View','[\"Ac\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:49:32','2026-08-25 16:07:48',NULL),(23,NULL,'1111','Family 4-Person',4,NULL,770000,770000,NULL,2,'Upper','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:50:59','2026-08-25 16:08:13',NULL),(24,NULL,'1112','Family 4-Person',4,NULL,770000,770000,NULL,2,'Upper','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:51:58','2026-08-25 16:08:25',NULL),(25,NULL,'1114','Special Couple Room',2,NULL,500000,500000,NULL,2,'Upper','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:53:51','2026-08-25 16:08:46',NULL),(26,NULL,'1115','Two-Person Room',2,NULL,500000,500000,NULL,2,'Upper','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:55:19','2026-08-25 16:08:57',NULL),(27,NULL,'1116','Two-Person Room',2,NULL,500000,500000,NULL,2,'Upper','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 15:56:22','2026-08-25 16:09:09',NULL),(28,NULL,'1108','Single',1,NULL,330000,330000,NULL,2,'Ground','Hill View','[\"AC\",\"TV\",\"WiFi\",\"Hot Water\"]','[\"Bed linen, pillows & cushions,\",\"Bath towels, face towels & hand towels,\",\"TV & remote control,\",\"AC unit & remote control,\",\"Hangers,\",\"Electric Kettle & cups\\/glasses,\",\"Toiletries (soap, shampoo, toilet paper),\",\"Slippers,\",\"Minibar contents (if applicable),\",\"In-room safe (if applicable),\",\"curtains & window fittings,\",\"Light bulbs\\/ lamps functioning,\",\"Bathroom fittings (shower, tap, flush) in working order,\",\"WiFi info card,\",\"Do Not Disturb sign\"]','[\"Strip used linen and remake bed with fresh linen,\",\"Replace used towels with fresh ones,\",\"Dust all surfaces, furniture, and fittings,\",\"Vacuum \\/mop the floor,\",\"Clean bathroom u2014 toilet, shower\\/tub, sink, mirror,\",\"Restock toiletries and guest amenities,\",\"Empty and reline trash bins,\",\"Restock\\/check minibar items,\",\"Clean windows, mirrors, and glass surfaces,\",\"Check AC, TV, and lights are functioning,\",\"Check for and log any damage or maintenance issue found,\",\"Final inspection and mark room status as Clean\\/Ready in the system\"]',9,NULL,7,7,'2026-08-25 16:07:19','2026-08-25 16:07:19',NULL);
/*!40000 ALTER TABLE `rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seasonal_rates`
--

DROP TABLE IF EXISTS `seasonal_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seasonal_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `room_type_id` bigint(20) unsigned DEFAULT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `rate` int(10) unsigned NOT NULL COMMENT 'Flat LKR cents/night for every date in range',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seasonal_rates_created_by_foreign` (`created_by`),
  KEY `seasonal_rates_updated_by_foreign` (`updated_by`),
  KEY `seasonal_rates_room_type_id_start_date_end_date_index` (`room_type_id`,`start_date`,`end_date`),
  KEY `seasonal_rates_tenant_id_foreign` (`tenant_id`),
  KEY `seasonal_rates_room_id_start_date_end_date_index` (`room_id`,`start_date`,`end_date`),
  CONSTRAINT `seasonal_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seasonal_rates_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seasonal_rates_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seasonal_rates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seasonal_rates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seasonal_rates`
--

LOCK TABLES `seasonal_rates` WRITE;
/*!40000 ALTER TABLE `seasonal_rates` DISABLE KEYS */;
INSERT INTO `seasonal_rates` VALUES (1,1,1,NULL,'December Peak','2026-12-15','2027-01-05',2640000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(2,1,NULL,1,'December Peak','2026-12-15','2027-01-05',2640000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(3,1,NULL,2,'December Peak','2026-12-15','2027-01-05',2640000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(4,1,NULL,3,'December Peak','2026-12-15','2027-01-05',2640000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(5,1,2,NULL,'December Peak','2026-12-15','2027-01-05',3600000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(6,1,NULL,4,'December Peak','2026-12-15','2027-01-05',3600000,NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(7,1,3,NULL,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(8,1,NULL,5,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(9,1,NULL,6,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(10,1,NULL,7,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(11,1,NULL,8,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(12,1,NULL,9,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(13,1,NULL,10,'December Peak','2026-12-15','2027-01-05',1800000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(14,1,4,NULL,'December Peak','2026-12-15','2027-01-05',2400000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(15,1,NULL,11,'December Peak','2026-12-15','2027-01-05',2400000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(16,1,5,NULL,'December Peak','2026-12-15','2027-01-05',2160000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(17,1,NULL,12,'December Peak','2026-12-15','2027-01-05',2160000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44'),(18,1,NULL,13,'December Peak','2026-12-15','2027-01-05',2160000,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44');
/*!40000 ALTER TABLE `seasonal_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('BHm7L72E1d25GupYkm3KUqeomvQSbcDaTuwCfqyi',NULL,'175.157.9.9','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','YTozOntzOjY6Il90b2tlbiI7czo0MDoiTnZ0VEdtU3JzbjJXdGt0YzhzMUVXMThLbHh6Z2FIREFNVVRoRHJ3NSI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NDU6Imh0dHBzOi8vbW91bnR2aWV3Lmhtcy52ZWxsaXhnbG9iYWwuY29tL2FwaS9tZSI7czo1OiJyb3V0ZSI7czoyOiJtZSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=',1787668480),('BWO7uOz8GdCy6BLYovaMy7DwWeQrXVBzQc6roaKt',9,'212.104.228.36','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiWnpFSVFFWUdXWEw1TmUza2x6SG9XVG9aczdWeXJ3VlNMS3RCRjU1ViI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NDg6Imh0dHBzOi8vbW91bnR2aWV3Lmhtcy52ZWxsaXhnbG9iYWwuY29tL2FwaS9yb29tcyI7czo1OiJyb3V0ZSI7czoxNzoiaG90ZWwucm9vbXMuaW5kZXgiO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX1zOjUwOiJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI7aTo5O30=',1787667300),('HUBVIEdY9zZrFBi0bCMXzy27mHsRXicj1NLqwo5v',NULL,'45.121.88.137','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','YTozOntzOjY6Il90b2tlbiI7czo0MDoiMVpiNTl3WTJtNGRJaERmdUI1WU1FbURlaVNDTzl0VjlicEprNWNrTCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NDU6Imh0dHBzOi8vbW91bnR2aWV3Lmhtcy52ZWxsaXhnbG9iYWwuY29tL2FwaS9tZSI7czo1OiJyb3V0ZSI7czoyOiJtZSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=',1787748263),('zSGI9DSFkT1cNaKMCWQg5fRfpBw2Zfu161wIh4Rb',NULL,'112.134.143.153','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','YTozOntzOjY6Il90b2tlbiI7czo0MDoiV2I1RFJ1Vjh4bWlsWVkwMFRGU1NhVlg5Y3F4M3lzZEk4Q2dxbk40RCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NDU6Imh0dHBzOi8vbW91bnR2aWV3Lmhtcy52ZWxsaXhnbG9iYWwuY29tL2FwaS9tZSI7czo1OiJyb3V0ZSI7czoyOiJtZSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=',1787728592);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `key` varchar(255) NOT NULL,
  `value` longtext DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'text',
  `category` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `hint` text DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_new_tenant_id_key_unique` (`tenant_id`,`key`),
  KEY `settings_new_updated_by_foreign` (`updated_by`),
  KEY `settings_new_category_index` (`category`),
  CONSTRAINT `settings_new_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `settings_new_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=161 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,1,'hotel.login_tagline','\"Hospitality Management System\"','text','hotel','Login Screen Tagline / Short Description','The small line shown under the hotel name on the login screen.',NULL,'2026-08-24 04:16:05','2026-08-24 04:16:05'),(2,1,'hotel.tagline','\"Hospitality Management System\"','text','hotel','Sidebar Tagline / Short Description','The small line shown under the hotel name in the sidebar.',NULL,'2026-08-24 04:16:05','2026-08-24 04:16:05'),(3,1,'theme.primary','\"#0462d3\"','color','hotel','Primary color','Buttons, links, focus rings and active states across the whole app.',NULL,'2026-08-24 04:16:05','2026-08-24 04:16:05'),(4,1,'theme.secondary','\"#3783f0\"','color','hotel','Secondary color','Accent highlight for the active menu item in the sidebar.',NULL,'2026-08-24 04:16:05','2026-08-24 04:16:05'),(5,1,'theme.sidebar','\"#0c182a\"','color','hotel','Sidebar color','Base color the sidebar\'s dark background, borders and text are shaded from.',NULL,'2026-08-24 04:16:05','2026-08-24 04:16:05'),(8,1,'hotel.name','\"Mount View Hotel\"','text','hotel','Hotel Name','Shown in the sidebar, login screen, printed documents and guest pages.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(9,1,'hotel.address','\"\\u26a0 confirm with owner\"','text','hotel','Address',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(10,1,'hotel.phone','\"\\u26a0 confirm with owner\"','text','hotel','Phone',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(11,1,'hotel.email','\"\\u26a0 confirm with owner\"','text','hotel','Email','Also receives low-stock/venue-inquiry alerts.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(12,1,'hotel.tax_reg_no','\"\\u26a0 confirm with owner\"','text','hotel','Tax Registration No.',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(13,1,'hotel.website','\"\"','text','hotel','Website',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(14,1,'hotel.logo_url','\"\"','image','hotel','Logo','Shown in the sidebar, on the login screen and printed documents. Drag & drop, paste, or choose an image.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(15,1,'hotel.timezone','\"Asia\\/Colombo\"','text','hotel','Timezone','Applied to the tenant\'s whole request pipeline — dates and times render in this zone.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(16,1,'hotel.locale','\"en\"','text','hotel','Locale / Language',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(17,1,'hotel.currency_code','\"LKR\"','text','hotel','Currency Code',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(18,1,'hotel.currency_symbol','\"Rs.\"','text','hotel','Currency Symbol',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(19,1,'frontdesk.check_in_time','\"14:00\"','time','frontdesk','Check-in Time',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(20,1,'frontdesk.check_out_time','\"12:00\"','time','frontdesk','Check-out Time',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(21,1,'billing.early_checkin_surcharge','0','money','billing','Early Check-in Surcharge (LKR cents)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(22,1,'billing.late_checkout_surcharge','0','money','billing','Late Check-out Surcharge (LKR cents)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(23,1,'billing.vat_pct','0','percent','billing','VAT %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(24,1,'billing.service_charge_pct','0','percent','billing','Service Charge %','Waived on takeaway POS orders.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(25,1,'billing.room_deposit_mode','\"percentage\"','text','billing','Room Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(26,1,'billing.room_deposit_pct','20','percent','billing','Room Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(27,1,'billing.room_deposit_fixed','0','money','billing','Room Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the stay total.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(28,1,'billing.venue_deposit_mode','\"percentage\"','text','billing','Venue Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(29,1,'billing.venue_deposit_pct','25','percent','billing','Venue Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(30,1,'billing.venue_deposit_fixed','0','money','billing','Venue Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the rental total.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(31,1,'currency.usd_rate','300','number','currency','LKR per 1 USD (display only)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(32,1,'policies.children_free_under_age','4','number','policies','Children Free Under Age',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(33,1,'policies.parking_capacity','10','number','policies','Parking Capacity',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(34,1,'policies.wifi_policy','\"\"','text','policies','WiFi Policy',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(35,1,'policies.cancellation_policy','\"\"','text','policies','Cancellation Policy (guest-facing text)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(36,1,'policies.cancellation_rules','[{\"daysBefore\":7,\"refundPct\":100},{\"daysBefore\":3,\"refundPct\":50},{\"daysBefore\":0,\"refundPct\":0}]','json','policies','Cancellation Refund Tiers','Most-generous rule the booking still qualifies for wins.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(37,1,'pricing.weekend_days','[0,6]','json','pricing','Weekend Days (0=Sun..6=Sat)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(38,1,'pricing.public_holidays','[]','json','pricing','Public Holidays','ISO date strings; priced as weekend rate.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(39,1,'loyalty.points_per_1000lkr','0','number','loyalty','Points Earned per 1,000 LKR Spent','0 disables loyalty accrual.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(40,1,'loyalty.point_value_cents','100','money','loyalty','Point Redemption Value (LKR cents)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(41,1,'loyalty.redemption_catalog','[]','json','loyalty','Redemption Catalog',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(42,1,'notifications.pre_arrival_days','1','number','notifications','Pre-arrival Reminder (days before check-in)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(43,1,'notifications.channels','[\"email\",\"whatsapp\",\"sms\"]','json','notifications','Enabled Guest Notification Channels',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(44,1,'payroll.epf_employee_pct','8','percent','payroll','EPF — Employee %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(45,1,'payroll.epf_employer_pct','12','percent','payroll','EPF — Employer %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(46,1,'payroll.etf_pct','3','percent','payroll','ETF — Employer %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(47,1,'payroll.standard_monthly_hours','200','number','payroll','Standard Monthly Hours','Hours beyond this count as overtime.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(48,1,'payroll.apit_brackets','[{\"width\":15000000,\"rate\":0},{\"width\":8333333.333333333,\"rate\":6},{\"width\":4166666.6666666665,\"rate\":18},{\"width\":4166666.6666666665,\"rate\":24},{\"width\":4166666.6666666665,\"rate\":30},{\"width\":null,\"rate\":36}]','json','payroll','APIT Tax Brackets','Sri Lanka monthly APIT bands (Y/A 2025/2026): each band\'s \"width\" is LKR cents taxed at \"rate\" %, consumed in order; the last band (width=null) is unbounded. Derived from the Rs. 1,800,000/yr personal relief + progressive schedule, so widths are precise twelfths rather than the IRD\'s rounded monthly display figures.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(49,1,'qr_ordering.enabled','true','boolean','qr_ordering','Enable QR Ordering','Master switch — turns off ordering on every room/table QR link at once.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(50,1,'qr_ordering.welcome_message','\"Scan. Browse. Order.\"','text','qr_ordering','Welcome Message','Headline shown at the top of the guest ordering page.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(51,1,'qr_ordering.accent_color','\"#0462d3\"','color','qr_ordering','Accent Color','Buttons and highlights on the guest ordering page.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(52,1,'qr_ordering.banner_image','\"\"','image','qr_ordering','Banner Image','Optional hero image shown at the top of the guest menu.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(53,1,'qr_ordering.show_item_images','true','boolean','qr_ordering','Show Item Photos',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(54,1,'qr_ordering.show_descriptions','true','boolean','qr_ordering','Show Item Descriptions',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(55,1,'qr_ordering.collect_customer_name','true','boolean','qr_ordering','Ask Table Guests for Their Name','Room orders already know the guest from the reservation — this only affects restaurant table orders.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(56,1,'qr_ordering.collect_customer_phone','false','boolean','qr_ordering','Ask Table Guests for Phone Number',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(57,1,'qr_ordering.footer_note','\"Prices are inclusive of applicable taxes.\"','text','qr_ordering','Footer Note',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(58,1,'inventory.expiry_warn_days','3','number','inventory','Expiry Warning Window (days)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(59,1,'apartment.deposit_mode','\"percentage\"','text','apartments','Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(60,1,'apartment.deposit_pct','20','percent','apartments','Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(61,1,'apartment.deposit_fixed','0','money','apartments','Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the stay total.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(62,1,'apartment.vat_pct','0','percent','apartments','VAT %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(63,1,'apartment.service_charge_pct','0','percent','apartments','Service Charge %',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(64,1,'apartment.late_checkout_surcharge','0','money','apartments','Late Check-out Surcharge (LKR cents)',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(65,1,'apartment.weekly_stay_threshold_nights','7','number','apartments','Weekly Rate Threshold (nights)','Stays at or beyond this length are priced off the unit type\'s weekly rate.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(66,1,'apartment.monthly_stay_threshold_nights','28','number','apartments','Monthly Rate Threshold (nights)','Stays at or beyond this length are priced off the unit type\'s monthly rate.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(67,1,'apartment.sale_reservation_hold_days','14','number','apartments','Sale Reservation Hold (days)','Default option-hold length before an unsigned reservation auto-releases.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(68,1,'apartment.sale_deposit_forfeit_pct','100','percent','apartments','Sale Cancellation Deposit Forfeit %','Portion of the reservation deposit kept by the business if the buyer cancels.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(69,1,'apartment.cancellation_rules','[{\"daysBefore\":7,\"refundPct\":100},{\"daysBefore\":3,\"refundPct\":50},{\"daysBefore\":0,\"refundPct\":0}]','json','apartments','Cancellation Refund Tiers','Most-generous rule the booking still qualifies for wins.',NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(70,1,'integrations.whatsapp_enabled','false','boolean','integrations','WhatsApp Enabled',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(71,1,'integrations.whatsapp_api_url','\"\"','text','integrations','WhatsApp Cloud API URL',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(72,1,'integrations.whatsapp_api_token','\"\"','text','integrations','WhatsApp Cloud API Token',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(73,1,'integrations.sms_enabled','false','boolean','integrations','SMS Enabled',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(74,1,'integrations.sms_api_url','\"\"','text','integrations','SMS Gateway URL',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(75,1,'integrations.sms_api_key','\"\"','text','integrations','SMS Gateway API Key',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(76,1,'integrations.sms_sender_id','\"MountView\"','text','integrations','SMS Sender ID',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(77,1,'integrations.bookingcom_hotel_id','\"\"','text','integrations','Booking.com Hotel ID',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(78,1,'integrations.bookingcom_api_key','\"\"','text','integrations','Booking.com API Key',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(79,1,'integrations.gateway_provider','\"payhere\"','text','integrations','Payment Gateway Provider',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(80,1,'integrations.gateway_merchant_id','\"\"','text','integrations','Payment Gateway Merchant ID',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(81,1,'integrations.gateway_secret','\"\"','text','integrations','Payment Gateway Secret',NULL,NULL,'2026-08-24 04:16:43','2026-08-24 04:16:43'),(82,2,'hotel.name','\"Mount View Hotel\"','text','hotel','Hotel Name','Shown in the sidebar, login screen, printed documents and guest pages.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(83,2,'hotel.tagline','\"Hospitality Management System\"','text','hotel','Sidebar Tagline / Short Description','The small line shown under the hotel name in the sidebar.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(84,2,'hotel.login_tagline','\"Hospitality Management System\"','text','hotel','Login Screen Tagline / Short Description','The small line shown under the hotel name on the login screen.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(85,2,'hotel.address','\"\\u26a0 confirm with owner\"','text','hotel','Address',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(86,2,'hotel.phone','\"\\u26a0 confirm with owner\"','text','hotel','Phone',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(87,2,'hotel.email','\"\\u26a0 confirm with owner\"','text','hotel','Email','Also receives low-stock/venue-inquiry alerts.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(88,2,'hotel.tax_reg_no','null','text','hotel','Tax Registration No.',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:21:16'),(89,2,'hotel.website','\"\"','text','hotel','Website',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(90,2,'hotel.logo_url','\"\"','image','hotel','Logo','Shown in the sidebar, on the login screen and printed documents. Drag & drop, paste, or choose an image.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(91,2,'hotel.timezone','\"Asia\\/Colombo\"','text','hotel','Timezone','Applied to the tenant\'s whole request pipeline — dates and times render in this zone.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(92,2,'hotel.locale','\"en\"','text','hotel','Locale / Language',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(93,2,'hotel.currency_code','\"LKR\"','text','hotel','Currency Code',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(94,2,'hotel.currency_symbol','\"Rs.\"','text','hotel','Currency Symbol',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(95,2,'theme.primary','\"#059669\"','color','hotel','Primary color','Buttons, links, focus rings and active states across the whole app.',NULL,'2026-08-24 14:15:24','2026-08-24 14:20:54'),(96,2,'theme.secondary','\"#10b981\"','color','hotel','Secondary color','Accent highlight for the active menu item in the sidebar.',NULL,'2026-08-24 14:15:24','2026-08-24 14:20:55'),(97,2,'theme.sidebar','\"#064e3b\"','color','hotel','Sidebar color','Base color the sidebar\'s dark background, borders and text are shaded from.',NULL,'2026-08-24 14:15:24','2026-08-24 14:20:56'),(98,2,'frontdesk.check_in_time','\"14:00\"','time','frontdesk','Check-in Time',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(99,2,'frontdesk.check_out_time','\"12:00\"','time','frontdesk','Check-out Time',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(100,2,'billing.early_checkin_surcharge','0','money','billing','Early Check-in Surcharge (LKR cents)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(101,2,'billing.late_checkout_surcharge','0','money','billing','Late Check-out Surcharge (LKR cents)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(102,2,'billing.vat_pct','0','percent','billing','VAT %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(103,2,'billing.service_charge_pct','0','percent','billing','Service Charge %','Waived on takeaway POS orders.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(104,2,'billing.room_deposit_mode','\"percentage\"','text','billing','Room Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(105,2,'billing.room_deposit_pct','20','percent','billing','Room Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(106,2,'billing.room_deposit_fixed','0','money','billing','Room Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the stay total.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(107,2,'billing.venue_deposit_mode','\"percentage\"','text','billing','Venue Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(108,2,'billing.venue_deposit_pct','25','percent','billing','Venue Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(109,2,'billing.venue_deposit_fixed','0','money','billing','Venue Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the rental total.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(110,2,'currency.usd_rate','300','number','currency','LKR per 1 USD (display only)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(111,2,'policies.children_free_under_age','4','number','policies','Children Free Under Age',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(112,2,'policies.parking_capacity','10','number','policies','Parking Capacity',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(113,2,'policies.wifi_policy','\"\"','text','policies','WiFi Policy',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(114,2,'policies.cancellation_policy','\"\"','text','policies','Cancellation Policy (guest-facing text)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(115,2,'policies.cancellation_rules','[{\"daysBefore\":7,\"refundPct\":100},{\"daysBefore\":3,\"refundPct\":50},{\"daysBefore\":0,\"refundPct\":0}]','json','policies','Cancellation Refund Tiers','Most-generous rule the booking still qualifies for wins.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(116,2,'pricing.weekend_days','[0,6]','json','pricing','Weekend Days (0=Sun..6=Sat)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(117,2,'pricing.public_holidays','[]','json','pricing','Public Holidays','ISO date strings; priced as weekend rate.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(118,2,'loyalty.points_per_1000lkr','0','number','loyalty','Points Earned per 1,000 LKR Spent','0 disables loyalty accrual.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(119,2,'loyalty.point_value_cents','100','money','loyalty','Point Redemption Value (LKR cents)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(120,2,'loyalty.redemption_catalog','[]','json','loyalty','Redemption Catalog',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(121,2,'notifications.pre_arrival_days','1','number','notifications','Pre-arrival Reminder (days before check-in)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(122,2,'notifications.channels','[\"email\",\"whatsapp\",\"sms\"]','json','notifications','Enabled Guest Notification Channels',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(123,2,'payroll.epf_employee_pct','8','percent','payroll','EPF — Employee %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(124,2,'payroll.epf_employer_pct','12','percent','payroll','EPF — Employer %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(125,2,'payroll.etf_pct','3','percent','payroll','ETF — Employer %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(126,2,'payroll.standard_monthly_hours','200','number','payroll','Standard Monthly Hours','Hours beyond this count as overtime.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(127,2,'payroll.apit_brackets','[{\"width\":15000000,\"rate\":0},{\"width\":8333333.333333333,\"rate\":6},{\"width\":4166666.6666666665,\"rate\":18},{\"width\":4166666.6666666665,\"rate\":24},{\"width\":4166666.6666666665,\"rate\":30},{\"width\":null,\"rate\":36}]','json','payroll','APIT Tax Brackets','Sri Lanka monthly APIT bands (Y/A 2025/2026): each band\'s \"width\" is LKR cents taxed at \"rate\" %, consumed in order; the last band (width=null) is unbounded. Derived from the Rs. 1,800,000/yr personal relief + progressive schedule, so widths are precise twelfths rather than the IRD\'s rounded monthly display figures.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(128,2,'qr_ordering.enabled','true','boolean','qr_ordering','Enable QR Ordering','Master switch — turns off ordering on every room/table QR link at once.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(129,2,'qr_ordering.welcome_message','\"Scan. Browse. Order.\"','text','qr_ordering','Welcome Message','Headline shown at the top of the guest ordering page.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(130,2,'qr_ordering.accent_color','\"#0462d3\"','color','qr_ordering','Accent Color','Buttons and highlights on the guest ordering page.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(131,2,'qr_ordering.banner_image','\"\"','image','qr_ordering','Banner Image','Optional hero image shown at the top of the guest menu.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(132,2,'qr_ordering.show_item_images','true','boolean','qr_ordering','Show Item Photos',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(133,2,'qr_ordering.show_descriptions','true','boolean','qr_ordering','Show Item Descriptions',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(134,2,'qr_ordering.collect_customer_name','true','boolean','qr_ordering','Ask Table Guests for Their Name','Room orders already know the guest from the reservation — this only affects restaurant table orders.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(135,2,'qr_ordering.collect_customer_phone','false','boolean','qr_ordering','Ask Table Guests for Phone Number',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(136,2,'qr_ordering.footer_note','\"Prices are inclusive of applicable taxes.\"','text','qr_ordering','Footer Note',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(137,2,'inventory.expiry_warn_days','3','number','inventory','Expiry Warning Window (days)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(138,2,'apartment.deposit_mode','\"percentage\"','text','apartments','Booking Deposit Mode','\"percentage\" or \"fixed\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(139,2,'apartment.deposit_pct','20','percent','apartments','Booking Deposit %','Used when the deposit mode is \"percentage\".',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(140,2,'apartment.deposit_fixed','0','money','apartments','Booking Deposit — Fixed Amount (LKR cents)','Used when the deposit mode is \"fixed\"; capped to the stay total.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(141,2,'apartment.vat_pct','0','percent','apartments','VAT %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(142,2,'apartment.service_charge_pct','0','percent','apartments','Service Charge %',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(143,2,'apartment.late_checkout_surcharge','0','money','apartments','Late Check-out Surcharge (LKR cents)',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(144,2,'apartment.weekly_stay_threshold_nights','7','number','apartments','Weekly Rate Threshold (nights)','Stays at or beyond this length are priced off the unit type\'s weekly rate.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(145,2,'apartment.monthly_stay_threshold_nights','28','number','apartments','Monthly Rate Threshold (nights)','Stays at or beyond this length are priced off the unit type\'s monthly rate.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(146,2,'apartment.sale_reservation_hold_days','14','number','apartments','Sale Reservation Hold (days)','Default option-hold length before an unsigned reservation auto-releases.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(147,2,'apartment.sale_deposit_forfeit_pct','100','percent','apartments','Sale Cancellation Deposit Forfeit %','Portion of the reservation deposit kept by the business if the buyer cancels.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(148,2,'apartment.cancellation_rules','[{\"daysBefore\":7,\"refundPct\":100},{\"daysBefore\":3,\"refundPct\":50},{\"daysBefore\":0,\"refundPct\":0}]','json','apartments','Cancellation Refund Tiers','Most-generous rule the booking still qualifies for wins.',NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(149,2,'integrations.whatsapp_enabled','false','boolean','integrations','WhatsApp Enabled',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(150,2,'integrations.whatsapp_api_url','\"\"','text','integrations','WhatsApp Cloud API URL',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(151,2,'integrations.whatsapp_api_token','\"\"','text','integrations','WhatsApp Cloud API Token',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(152,2,'integrations.sms_enabled','false','boolean','integrations','SMS Enabled',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(153,2,'integrations.sms_api_url','\"\"','text','integrations','SMS Gateway URL',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(154,2,'integrations.sms_api_key','\"\"','text','integrations','SMS Gateway API Key',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(155,2,'integrations.sms_sender_id','\"MountView\"','text','integrations','SMS Sender ID',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(156,2,'integrations.bookingcom_hotel_id','\"\"','text','integrations','Booking.com Hotel ID',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(157,2,'integrations.bookingcom_api_key','\"\"','text','integrations','Booking.com API Key',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(158,2,'integrations.gateway_provider','\"payhere\"','text','integrations','Payment Gateway Provider',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(159,2,'integrations.gateway_merchant_id','\"\"','text','integrations','Payment Gateway Merchant ID',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(160,2,'integrations.gateway_secret','\"\"','text','integrations','Payment Gateway Secret',NULL,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `ingredient_batch_id` bigint(20) unsigned DEFAULT NULL,
  `movement_type_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL COMMENT 'signed: positive in, negative out',
  `unit_cost` int(11) DEFAULT NULL COMMENT 'cents — snapshot of the batch cost consumed',
  `reference_type` varchar(255) DEFAULT NULL COMMENT 'order_item | grn_line | adjustment | write_off',
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_movements_ingredient_batch_id_foreign` (`ingredient_batch_id`),
  KEY `stock_movements_movement_type_id_foreign` (`movement_type_id`),
  KEY `stock_movements_created_by_foreign` (`created_by`),
  KEY `stock_movements_ingredient_id_created_at_index` (`ingredient_id`,`created_at`),
  KEY `stock_movements_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  KEY `stock_mov_ing_created_idx` (`ingredient_id`,`created_at`),
  KEY `stock_mov_type_created_idx` (`tenant_id`,`movement_type_id`,`created_at`),
  KEY `stock_mov_ref_idx` (`reference_type`,`reference_id`),
  CONSTRAINT `stock_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_ingredient_batch_id_foreign` FOREIGN KEY (`ingredient_batch_id`) REFERENCES `ingredient_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_movement_type_id_foreign` FOREIGN KEY (`movement_type_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `stock_movements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES (1,2,3,1,156,5.000,NULL,'adjustment',NULL,'new stock',NULL,'2026-08-25 17:23:58','2026-08-25 17:23:58'),(2,2,4,2,156,5.000,NULL,'adjustment',NULL,'new stock',NULL,'2026-08-25 17:25:32','2026-08-25 17:25:32'),(3,2,5,3,156,50.000,NULL,'adjustment',NULL,'new stock',NULL,'2026-08-25 17:27:52','2026-08-25 17:27:52'),(4,2,6,4,156,50.000,NULL,'adjustment',NULL,'new stock',NULL,'2026-08-25 17:30:33','2026-08-25 17:30:33'),(5,2,6,4,157,-1.000,NULL,'order_item',6,NULL,NULL,'2026-08-25 17:31:12','2026-08-25 17:31:12');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenant_modules`
--

DROP TABLE IF EXISTS `tenant_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenant_modules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `module_key` varchar(60) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_modules_tenant_id_module_key_unique` (`tenant_id`,`module_key`),
  KEY `tenant_modules_granted_by_foreign` (`granted_by`),
  CONSTRAINT `tenant_modules_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `central_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_modules_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant_modules`
--

LOCK TABLES `tenant_modules` WRITE;
/*!40000 ALTER TABLE `tenant_modules` DISABLE KEYS */;
INSERT INTO `tenant_modules` VALUES (1,1,'hotel_operations',1,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37'),(2,1,'restaurant_pos',1,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37'),(3,1,'apartments',1,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37'),(4,1,'payroll',1,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37'),(5,1,'till',1,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37'),(6,2,'hotel_operations',1,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(7,2,'restaurant_pos',1,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(8,2,'apartments',0,1,'2026-08-24 14:15:24','2026-08-25 10:43:48'),(9,2,'payroll',1,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(10,2,'till',1,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24');
/*!40000 ALTER TABLE `tenant_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenants`
--

DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(63) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'trial',
  `environment` varchar(10) NOT NULL DEFAULT 'live',
  `parent_tenant_id` bigint(20) unsigned DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_synced_by` bigint(20) unsigned DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  KEY `tenants_created_by_foreign` (`created_by`),
  KEY `tenants_updated_by_foreign` (`updated_by`),
  KEY `tenants_status_index` (`status`),
  KEY `tenants_parent_tenant_id_foreign` (`parent_tenant_id`),
  KEY `tenants_last_synced_by_foreign` (`last_synced_by`),
  KEY `tenants_environment_index` (`environment`),
  CONSTRAINT `tenants_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `central_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenants_last_synced_by_foreign` FOREIGN KEY (`last_synced_by`) REFERENCES `central_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenants_parent_tenant_id_foreign` FOREIGN KEY (`parent_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenants_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `central_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenants`
--

LOCK TABLES `tenants` WRITE;
/*!40000 ALTER TABLE `tenants` DISABLE KEYS */;
INSERT INTO `tenants` VALUES (1,'Default Tenant','default','active','live',NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:18','2026-08-24 04:16:18',NULL),(2,'mountview','mountview','active','live',NULL,NULL,NULL,NULL,1,NULL,'2026-08-24 14:15:24','2026-08-24 14:15:24',NULL);
/*!40000 ALTER TABLE `tenants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `till_movements`
--

DROP TABLE IF EXISTS `till_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `till_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `till_session_id` bigint(20) unsigned NOT NULL,
  `type_id` bigint(20) unsigned NOT NULL COMMENT 'till_movement_type lookup',
  `amount` int(11) NOT NULL COMMENT 'LKR cents, signed: positive = cash in, negative = cash out',
  `reason` text DEFAULT NULL COMMENT 'mandatory for cash_out/expense/transfer/refund — enforced in TillService',
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source_type` varchar(255) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `performed_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `till_movements_type_id_foreign` (`type_id`),
  KEY `till_movements_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `till_movements_performed_by_foreign` (`performed_by`),
  KEY `till_movements_approved_by_foreign` (`approved_by`),
  KEY `till_movements_till_session_id_created_at_index` (`till_session_id`,`created_at`),
  KEY `till_movements_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `till_movements_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `till_movements_performed_by_foreign` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `till_movements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `till_movements_till_session_id_foreign` FOREIGN KEY (`till_session_id`) REFERENCES `till_sessions` (`id`),
  CONSTRAINT `till_movements_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `lookups` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `till_movements`
--

LOCK TABLES `till_movements` WRITE;
/*!40000 ALTER TABLE `till_movements` DISABLE KEYS */;
INSERT INTO `till_movements` VALUES (1,2,1,140,500000,'Till opened',NULL,NULL,NULL,NULL,7,NULL,'2026-08-25 15:36:05','2026-08-25 15:36:05'),(2,2,1,141,50000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',1,7,NULL,'2026-08-25 15:36:23','2026-08-25 15:36:23'),(3,2,1,141,200000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',2,7,NULL,'2026-08-25 15:37:23','2026-08-25 15:37:23'),(4,2,1,141,480000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',3,7,NULL,'2026-08-25 15:38:38','2026-08-25 15:38:38'),(5,2,1,141,1995000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',4,7,NULL,'2026-08-25 15:40:19','2026-08-25 15:40:19'),(6,2,2,140,5500000,'Till opened',NULL,NULL,NULL,NULL,9,NULL,'2026-08-25 16:46:14','2026-08-25 16:46:14'),(7,2,2,141,190000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',5,9,NULL,'2026-08-25 16:52:27','2026-08-25 16:52:27'),(8,2,2,141,775000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',6,9,NULL,'2026-08-25 17:15:35','2026-08-25 17:15:35'),(9,2,2,141,100000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',7,9,NULL,'2026-08-25 17:34:57','2026-08-25 17:34:57'),(10,2,2,141,230000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',8,9,NULL,'2026-08-25 17:37:33','2026-08-25 17:37:33'),(11,2,2,141,100000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',9,9,NULL,'2026-08-25 17:42:34','2026-08-25 17:42:34'),(12,2,2,141,100000,NULL,NULL,NULL,'App\\Models\\Hotel\\Payment',10,9,NULL,'2026-08-25 17:44:52','2026-08-25 17:44:52');
/*!40000 ALTER TABLE `till_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `till_sessions`
--

DROP TABLE IF EXISTS `till_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `till_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `till_id` bigint(20) unsigned NOT NULL,
  `status_id` bigint(20) unsigned NOT NULL COMMENT 'till_session_status lookup: open/closed',
  `opened_by` bigint(20) unsigned NOT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `opening_cash` int(10) unsigned NOT NULL COMMENT 'LKR cents counted at open',
  `closing_cash` int(10) unsigned DEFAULT NULL COMMENT 'counted at close',
  `expected_cash` int(11) DEFAULT NULL COMMENT 'sum of till_movements at close time',
  `variance` int(11) DEFAULT NULL COMMENT 'closing_cash - expected_cash',
  `notes` text DEFAULT NULL,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `till_sessions_status_id_foreign` (`status_id`),
  KEY `till_sessions_closed_by_foreign` (`closed_by`),
  KEY `till_sessions_till_id_status_id_index` (`till_id`,`status_id`),
  KEY `till_sessions_opened_by_status_id_index` (`opened_by`,`status_id`),
  KEY `till_sessions_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `till_sessions_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `till_sessions_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`),
  CONSTRAINT `till_sessions_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `till_sessions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `till_sessions_till_id_foreign` FOREIGN KEY (`till_id`) REFERENCES `tills` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `till_sessions`
--

LOCK TABLES `till_sessions` WRITE;
/*!40000 ALTER TABLE `till_sessions` DISABLE KEYS */;
INSERT INTO `till_sessions` VALUES (1,2,2,138,7,NULL,500000,NULL,NULL,NULL,NULL,'2026-08-25 06:06:05',NULL,'2026-08-25 15:36:05','2026-08-25 15:36:05'),(2,2,2,139,9,9,5500000,6995000,6995000,0,NULL,'2026-08-25 07:16:14','2026-08-25 17:50:05','2026-08-25 16:46:14','2026-08-25 17:50:05');
/*!40000 ALTER TABLE `till_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tills`
--

DROP TABLE IF EXISTS `tills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tills_branch_id_name_unique` (`branch_id`,`name`),
  KEY `tills_created_by_foreign` (`created_by`),
  KEY `tills_updated_by_foreign` (`updated_by`),
  KEY `tills_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `tills_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `tills_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tills_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tills_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tills`
--

LOCK TABLES `tills` WRITE;
/*!40000 ALTER TABLE `tills` DISABLE KEYS */;
INSERT INTO `tills` VALUES (1,NULL,1,'Main Till',1,NULL,NULL,'2026-08-24 04:16:44','2026-08-24 04:16:44',NULL),(2,NULL,2,'Mount View Cashier Till',1,7,7,'2026-08-25 02:30:09','2026-08-25 02:30:09',NULL);
/*!40000 ALTER TABLE `tills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permission_overrides`
--

DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission_overrides` (
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `type` varchar(10) NOT NULL,
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`permission_id`),
  KEY `user_permission_overrides_permission_id_foreign` (`permission_id`),
  KEY `user_permission_overrides_granted_by_foreign` (`granted_by`),
  KEY `user_permission_overrides_user_id_type_index` (`user_id`,`type`),
  CONSTRAINT `user_permission_overrides_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_permission_overrides_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permission_overrides_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permission_overrides`
--

LOCK TABLES `user_permission_overrides` WRITE;
/*!40000 ALTER TABLE `user_permission_overrides` DISABLE KEYS */;
INSERT INTO `user_permission_overrides` VALUES (8,50,'deny',7,'2026-08-25 16:05:21'),(8,51,'deny',7,'2026-08-25 16:05:21'),(8,52,'deny',7,'2026-08-25 16:05:21'),(8,53,'deny',7,'2026-08-25 16:05:21'),(8,54,'deny',7,'2026-08-25 16:05:21'),(8,55,'deny',7,'2026-08-25 16:05:21'),(8,56,'deny',7,'2026-08-25 16:05:21'),(8,57,'deny',7,'2026-08-25 16:05:21'),(8,58,'deny',7,'2026-08-25 16:05:21'),(8,93,'deny',7,'2026-08-25 16:05:21'),(8,94,'deny',7,'2026-08-25 16:05:21'),(8,95,'deny',7,'2026-08-25 16:05:21'),(8,96,'deny',7,'2026-08-25 16:05:21'),(8,97,'deny',7,'2026-08-25 16:05:21'),(8,147,'allow',7,'2026-08-25 16:05:21'),(8,173,'deny',7,'2026-08-25 16:05:21'),(8,174,'deny',7,'2026-08-25 16:05:21'),(8,175,'deny',7,'2026-08-25 16:05:21'),(8,176,'deny',7,'2026-08-25 16:05:21'),(8,177,'deny',7,'2026-08-25 16:05:21'),(8,178,'deny',7,'2026-08-25 16:05:21'),(8,179,'deny',7,'2026-08-25 16:05:21'),(8,180,'deny',7,'2026-08-25 16:05:21'),(8,181,'deny',7,'2026-08-25 16:05:21'),(8,182,'deny',7,'2026-08-25 16:05:21'),(8,183,'deny',7,'2026-08-25 16:05:21'),(8,184,'deny',7,'2026-08-25 16:05:21'),(8,185,'deny',7,'2026-08-25 16:05:21'),(8,186,'deny',7,'2026-08-25 16:05:21'),(8,187,'deny',7,'2026-08-25 16:05:21'),(8,188,'deny',7,'2026-08-25 16:05:21'),(8,189,'deny',7,'2026-08-25 16:05:21'),(8,190,'deny',7,'2026-08-25 16:05:21'),(8,191,'deny',7,'2026-08-25 16:05:21'),(8,192,'deny',7,'2026-08-25 16:05:21'),(8,193,'deny',7,'2026-08-25 16:05:21'),(8,194,'deny',7,'2026-08-25 16:05:21'),(8,195,'deny',7,'2026-08-25 16:05:21'),(8,196,'deny',7,'2026-08-25 16:05:21'),(8,197,'deny',7,'2026-08-25 16:05:21'),(8,198,'deny',7,'2026-08-25 16:05:21'),(8,199,'deny',7,'2026-08-25 16:05:21'),(8,200,'deny',7,'2026-08-25 16:05:21'),(8,201,'deny',7,'2026-08-25 16:05:21'),(8,202,'deny',7,'2026-08-25 16:05:21'),(8,203,'deny',7,'2026-08-25 16:05:21'),(8,204,'deny',7,'2026-08-25 16:05:21'),(8,205,'deny',7,'2026-08-25 16:05:21'),(8,206,'deny',7,'2026-08-25 16:05:21'),(8,207,'deny',7,'2026-08-25 16:05:21'),(8,208,'deny',7,'2026-08-25 16:05:21'),(8,209,'deny',7,'2026-08-25 16:05:21'),(8,210,'deny',7,'2026-08-25 16:05:21'),(8,211,'deny',7,'2026-08-25 16:05:21'),(8,212,'deny',7,'2026-08-25 16:05:21'),(8,213,'deny',7,'2026-08-25 16:05:21'),(8,214,'deny',7,'2026-08-25 16:05:21'),(8,215,'deny',7,'2026-08-25 16:05:21'),(8,216,'deny',7,'2026-08-25 16:05:21'),(8,217,'deny',7,'2026-08-25 16:05:21'),(8,218,'deny',7,'2026-08-25 16:05:21'),(8,219,'deny',7,'2026-08-25 16:05:21'),(8,220,'deny',7,'2026-08-25 16:05:21'),(8,221,'deny',7,'2026-08-25 16:05:21'),(8,222,'deny',7,'2026-08-25 16:05:21'),(8,223,'deny',7,'2026-08-25 16:05:21'),(8,224,'deny',7,'2026-08-25 16:05:21'),(8,225,'deny',7,'2026-08-25 16:05:21'),(9,1,'deny',7,'2026-08-25 16:09:23'),(9,2,'deny',7,'2026-08-25 16:09:23'),(9,3,'deny',7,'2026-08-25 16:09:23'),(9,4,'deny',7,'2026-08-25 16:09:23'),(9,5,'deny',7,'2026-08-25 16:09:23'),(9,10,'deny',7,'2026-08-25 16:09:23'),(9,11,'deny',7,'2026-08-25 16:09:23'),(9,17,'deny',7,'2026-08-25 16:09:23'),(9,18,'deny',7,'2026-08-25 16:09:23'),(9,98,'allow',7,'2026-08-25 16:09:23'),(9,99,'allow',7,'2026-08-25 16:09:23'),(9,100,'allow',7,'2026-08-25 16:09:23'),(9,101,'allow',7,'2026-08-25 16:09:23'),(9,134,'allow',7,'2026-08-25 16:09:23'),(9,135,'allow',7,'2026-08-25 16:09:23'),(9,136,'allow',7,'2026-08-25 16:09:23'),(9,137,'allow',7,'2026-08-25 16:09:23'),(9,138,'allow',7,'2026-08-25 16:09:23'),(9,139,'allow',7,'2026-08-25 16:09:23'),(9,140,'allow',7,'2026-08-25 16:09:23'),(9,141,'allow',7,'2026-08-25 16:09:23'),(9,142,'allow',7,'2026-08-25 16:09:23'),(9,147,'allow',7,'2026-08-25 16:09:23'),(9,160,'allow',7,'2026-08-25 16:09:23'),(9,173,'deny',7,'2026-08-25 16:09:23'),(9,174,'deny',7,'2026-08-25 16:09:23'),(9,175,'deny',7,'2026-08-25 16:09:23'),(9,176,'deny',7,'2026-08-25 16:09:23'),(9,177,'deny',7,'2026-08-25 16:09:23'),(9,178,'deny',7,'2026-08-25 16:09:23'),(9,179,'deny',7,'2026-08-25 16:09:23'),(9,180,'deny',7,'2026-08-25 16:09:23'),(9,181,'deny',7,'2026-08-25 16:09:23'),(9,182,'deny',7,'2026-08-25 16:09:23'),(9,183,'deny',7,'2026-08-25 16:09:23'),(9,184,'deny',7,'2026-08-25 16:09:23'),(9,185,'deny',7,'2026-08-25 16:09:23'),(9,186,'deny',7,'2026-08-25 16:09:23'),(9,187,'deny',7,'2026-08-25 16:09:23'),(9,188,'deny',7,'2026-08-25 16:09:23'),(9,189,'deny',7,'2026-08-25 16:09:23'),(9,190,'deny',7,'2026-08-25 16:09:23'),(9,191,'deny',7,'2026-08-25 16:09:23'),(9,192,'deny',7,'2026-08-25 16:09:23'),(9,193,'deny',7,'2026-08-25 16:09:23'),(9,194,'deny',7,'2026-08-25 16:09:23'),(9,195,'deny',7,'2026-08-25 16:09:23'),(9,196,'deny',7,'2026-08-25 16:09:23'),(9,197,'deny',7,'2026-08-25 16:09:23'),(9,198,'deny',7,'2026-08-25 16:09:23'),(9,199,'deny',7,'2026-08-25 16:09:23'),(9,200,'deny',7,'2026-08-25 16:09:23'),(9,201,'deny',7,'2026-08-25 16:09:23'),(9,202,'deny',7,'2026-08-25 16:09:23'),(9,203,'deny',7,'2026-08-25 16:09:23'),(9,204,'deny',7,'2026-08-25 16:09:23'),(9,205,'deny',7,'2026-08-25 16:09:23'),(9,206,'deny',7,'2026-08-25 16:09:23'),(9,207,'deny',7,'2026-08-25 16:09:23'),(9,208,'deny',7,'2026-08-25 16:09:23'),(9,209,'deny',7,'2026-08-25 16:09:23'),(9,210,'deny',7,'2026-08-25 16:09:23'),(9,211,'deny',7,'2026-08-25 16:09:23'),(9,212,'deny',7,'2026-08-25 16:09:23'),(9,213,'deny',7,'2026-08-25 16:09:23'),(9,214,'deny',7,'2026-08-25 16:09:23'),(9,215,'deny',7,'2026-08-25 16:09:23'),(9,216,'deny',7,'2026-08-25 16:09:23'),(9,217,'deny',7,'2026-08-25 16:09:23'),(9,218,'deny',7,'2026-08-25 16:09:23'),(9,219,'deny',7,'2026-08-25 16:09:23'),(9,220,'deny',7,'2026-08-25 16:09:23'),(9,221,'deny',7,'2026-08-25 16:09:23'),(9,222,'deny',7,'2026-08-25 16:09:23'),(9,223,'deny',7,'2026-08-25 16:09:23'),(9,224,'deny',7,'2026-08-25 16:09:23'),(9,225,'deny',7,'2026-08-25 16:09:23');
/*!40000 ALTER TABLE `user_permission_overrides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `user_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (1,1,'2026-08-24 04:16:38','2026-08-24 04:16:38'),(2,2,'2026-08-24 04:16:39','2026-08-24 04:16:39'),(3,4,'2026-08-24 04:16:40','2026-08-24 04:16:40'),(4,5,'2026-08-24 04:16:40','2026-08-24 04:16:40'),(5,6,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(6,7,'2026-08-24 04:16:41','2026-08-24 04:16:41'),(7,8,'2026-08-24 14:15:24','2026-08-24 14:15:24'),(8,11,'2026-08-25 16:05:21','2026-08-25 16:05:21'),(9,9,'2026-08-25 16:09:23','2026-08-25 16:09:23');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_warehouse_access`
--

DROP TABLE IF EXISTS `user_warehouse_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_warehouse_access` (
  `user_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`warehouse_id`),
  KEY `user_warehouse_access_warehouse_id_foreign` (`warehouse_id`),
  CONSTRAINT `user_warehouse_access_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_warehouse_access_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_warehouse_access`
--

LOCK TABLES `user_warehouse_access` WRITE;
/*!40000 ALTER TABLE `user_warehouse_access` DISABLE KEYS */;
INSERT INTO `user_warehouse_access` VALUES (8,2),(9,2);
/*!40000 ALTER TABLE `user_warehouse_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `base_salary` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'monthly basic, LKR cents',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `failed_login_count` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `two_factor_required` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` timestamp NULL DEFAULT NULL,
  `otp_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_otp_sent_at` timestamp NULL DEFAULT NULL,
  `password_reset_otp_hash` varchar(255) DEFAULT NULL,
  `password_reset_otp_expires_at` timestamp NULL DEFAULT NULL,
  `role_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `ot_hourly_rate` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'overtime per hour, LKR cents',
  `monthly_allowance` int(10) unsigned NOT NULL DEFAULT 0,
  `epf_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `epf_number` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_tenant_id_email_unique` (`tenant_id`,`email`),
  KEY `users_status_index` (`status`),
  KEY `users_role_id_index` (`role_id`),
  KEY `users_locked_until_index` (`locked_until`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,1,'Admin User','admin@vellix.com',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:38',0,0,NULL,NULL,0,NULL,NULL,NULL,1,NULL,NULL,'2026-08-24 04:16:38','$2y$12$XJrG97283Waw2R8ijzejyeVU9oZvR4FXRAO.oxAZQmMfOVuHxW1o6',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:38','2026-08-24 04:16:38',NULL,0,0,1,NULL),(2,1,'Operations Manager','manager@vellix.lk',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:39',0,0,NULL,NULL,0,NULL,NULL,NULL,2,NULL,NULL,'2026-08-24 04:16:39','$2y$12$Nbq3uGjX5UTrBrgQeJpwNuRhmUXP.X.LM3hMvwc6F67H9jEOoSzeq',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:39','2026-08-24 04:16:39',NULL,0,0,1,NULL),(3,1,'Owner Account','owner@vellix.lk',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:40',0,0,NULL,NULL,0,NULL,NULL,NULL,4,NULL,NULL,'2026-08-24 04:16:40','$2y$12$ITnkNH5Q0tbMQwqSilHdXOF29qbyWFeVJe7apQkqlQGzpFdGfx79y',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:40','2026-08-24 04:16:40',NULL,0,0,1,NULL),(4,1,'Housekeeping Staff','housekeeper@vellix.lk',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:40',0,0,NULL,NULL,0,NULL,NULL,NULL,5,NULL,NULL,'2026-08-24 04:16:40','$2y$12$T5IsueYFsx7SDWQmGFKxeuI7TUAVv6yWwJj3pAoMr0X32p1T3cHYa',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:40','2026-08-24 04:16:40',NULL,0,0,1,NULL),(5,1,'Head Chef','chef@vellix.lk',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:41',0,0,NULL,NULL,0,NULL,NULL,NULL,6,NULL,NULL,'2026-08-24 04:16:41','$2y$12$mhat5AD/52BWzzpL6U0yBO5nUxbpmebtsFgUq8YCuOpIHkDv97X06',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41',NULL,0,0,1,NULL),(6,1,'Security Officer','security@vellix.lk',NULL,NULL,'active',0,NULL,NULL,0,NULL,'2026-08-24 04:16:41',0,0,NULL,NULL,0,NULL,NULL,NULL,7,NULL,NULL,'2026-08-24 04:16:41','$2y$12$4Kup6xMai24GTr6WjKkM1eWLRJZPa5Mg/NdR.gMMcTfwyb2TFQfM6',NULL,NULL,NULL,NULL,NULL,'2026-08-24 04:16:41','2026-08-24 04:16:41',NULL,0,0,1,NULL),(7,2,'mountview Admin','admin@mountview.com',NULL,NULL,'active',0,'2026-08-25 15:28:53','175.157.43.185',1,NULL,'2026-08-25 09:54:32',0,0,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,7,'2026-08-24 14:15:24','$2y$12$8JjZOgWdCVHWKQNuayMxweo6vWCV5qMYIw.DnahkzjzuG7SLTPpSi',NULL,NULL,NULL,NULL,NULL,'2026-08-24 14:15:24','2026-08-26 22:14:23',NULL,0,0,1,NULL),(8,2,'Mr.Sethruwan','boss@mountview.com','+94 76 144 5887',NULL,'active',0,NULL,NULL,0,NULL,'2026-08-25 16:05:21',0,0,NULL,NULL,0,NULL,NULL,NULL,11,7,7,'2026-08-25 16:05:21','$2y$12$CAPUvAeveHCdO68ClE3jwu2x.sSlqbddmIKqDqJeqBsXT7scPOKT.','$2y$12$iEr2hLyE.0iFKmmR9089LOnzCrmG7EzZe/xeY00O/uapBVAdudeZa',NULL,NULL,NULL,NULL,'2026-08-25 16:05:21','2026-08-25 16:06:45',NULL,0,0,1,NULL),(9,2,'Miss. Himansa','manager@mountview.com','0766937186',NULL,'active',0,'2026-08-25 23:43:59','212.104.228.36',0,NULL,'2026-08-25 16:09:23',0,0,NULL,NULL,0,NULL,NULL,NULL,9,7,9,'2026-08-25 16:09:23','$2y$12$sF5EO5k3yZmZSvQ5/7ChAOH/9PAurKCw4MGJlYXcAIFARZqWSos0a','$2y$12$j8XQ6BYqbiogMw19MXrxXeaZO2P19URnECr1opNUH6WgOT/ZNhhkW',NULL,NULL,NULL,NULL,'2026-08-25 16:09:23','2026-08-25 23:43:59',NULL,0,0,1,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venue_bookings`
--

DROP TABLE IF EXISTS `venue_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venue_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `venue_id` bigint(20) unsigned NOT NULL,
  `guest_id` bigint(20) unsigned DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `client_phone` varchar(255) DEFAULT NULL,
  `client_email` varchar(255) DEFAULT NULL,
  `event_type` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `start_time` varchar(255) DEFAULT NULL,
  `end_time` varchar(255) DEFAULT NULL,
  `duration_type_id` bigint(20) unsigned NOT NULL,
  `hours` decimal(5,2) DEFAULT NULL,
  `guest_count` int(10) unsigned NOT NULL DEFAULT 0,
  `seating` text DEFAULT NULL,
  `av_needs` text DEFAULT NULL,
  `decoration` text DEFAULT NULL,
  `catering_by_hotel` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `venue_booking_status_id` bigint(20) unsigned NOT NULL,
  `deposit_due` int(10) unsigned NOT NULL DEFAULT 0,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `venue_bookings_tenant_id_code_unique` (`tenant_id`,`code`),
  KEY `venue_bookings_guest_id_foreign` (`guest_id`),
  KEY `venue_bookings_duration_type_id_foreign` (`duration_type_id`),
  KEY `venue_bookings_venue_booking_status_id_foreign` (`venue_booking_status_id`),
  KEY `venue_bookings_created_by_foreign` (`created_by`),
  KEY `venue_bookings_updated_by_foreign` (`updated_by`),
  KEY `venue_bookings_venue_id_date_index` (`venue_id`,`date`),
  CONSTRAINT `venue_bookings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venue_bookings_duration_type_id_foreign` FOREIGN KEY (`duration_type_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `venue_bookings_guest_id_foreign` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venue_bookings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venue_bookings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venue_bookings_venue_booking_status_id_foreign` FOREIGN KEY (`venue_booking_status_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `venue_bookings_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venue_bookings`
--

LOCK TABLES `venue_bookings` WRITE;
/*!40000 ALTER TABLE `venue_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `venue_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venues`
--

DROP TABLE IF EXISTS `venues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `max_capacity` int(10) unsigned NOT NULL,
  `facilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`facilities`)),
  `hourly_rate` int(10) unsigned NOT NULL COMMENT 'LKR cents — editable, not fixed',
  `half_day_rate` int(10) unsigned NOT NULL,
  `full_day_rate` int(10) unsigned NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `branch_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `venues_branch_id_name_unique` (`branch_id`,`name`),
  KEY `venues_created_by_foreign` (`created_by`),
  KEY `venues_updated_by_foreign` (`updated_by`),
  KEY `venues_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `venues_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `venues_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venues_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venues_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venues`
--

LOCK TABLES `venues` WRITE;
/*!40000 ALTER TABLE `venues` DISABLE KEYS */;
/*!40000 ALTER TABLE `venues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visitor_logs`
--

DROP TABLE IF EXISTS `visitor_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visitor_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `vehicle_no` varchar(255) DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `time_in` timestamp NOT NULL DEFAULT current_timestamp(),
  `time_out` timestamp NULL DEFAULT NULL,
  `logged_by_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `visitor_logs_logged_by_id_foreign` (`logged_by_id`),
  KEY `visitor_logs_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `visitor_logs_logged_by_id_foreign` FOREIGN KEY (`logged_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `visitor_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visitor_logs`
--

LOCK TABLES `visitor_logs` WRITE;
/*!40000 ALTER TABLE `visitor_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `visitor_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warehouses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `manager_user_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `warehouses_created_by_foreign` (`created_by`),
  KEY `warehouses_updated_by_foreign` (`updated_by`),
  KEY `warehouses_manager_user_id_foreign` (`manager_user_id`),
  KEY `warehouses_deleted_by_foreign` (`deleted_by`),
  KEY `warehouses_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `warehouses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_manager_user_id_foreign` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES (1,1,'Main Branch',NULL,NULL,NULL,NULL,'Sri Lanka',NULL,1,NULL,NULL,NULL,'2026-08-24 04:16:37','2026-08-24 04:16:37',NULL),(2,2,'main',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,'2026-08-24 14:17:52','2026-08-24 14:17:52',NULL);
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'hotel_laravel'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-26 18:46:34
