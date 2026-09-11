-- Adds the "Factory Management" feature: tea factories, a monthly price
-- per KG per factory, and links each plucking (KG) assignment to the
-- factory it was sent to plus the factory-confirmed final weight.
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin) before deploying factory-management.php / the updated
-- assignments.php.

CREATE TABLE `factories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_factories_estate` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per factory per calendar month. price_month is always stored
-- as the 1st of the month so a factory can have a different price every
-- month (looked up by matching the month of the plucking/assignment date).
CREATE TABLE `factory_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `factory_id` int(11) NOT NULL,
  `price_month` date NOT NULL,
  `price_per_kg` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_factory_month` (`factory_id`,`price_month`),
  KEY `idx_factory_prices_estate` (`estate_id`),
  CONSTRAINT `factory_prices_ibfk_1` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `factory_prices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link plucking records to a factory. factory_id uses ON DELETE SET NULL
-- (not CASCADE) so deleting a factory never wipes historical assignment /
-- payroll records — matches the soft-delete convention already used for
-- work_types in this project.
ALTER TABLE `daily_assignments`
  ADD COLUMN `factory_id` int(11) DEFAULT NULL AFTER `work_type_id`,
  ADD COLUMN `factory_weight` decimal(10,2) DEFAULT NULL AFTER `quantity`,
  ADD KEY `idx_da_factory` (`factory_id`),
  ADD CONSTRAINT `daily_assignments_factory_fk` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE SET NULL;
