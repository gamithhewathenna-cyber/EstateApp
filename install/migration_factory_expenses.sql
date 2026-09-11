-- Adds the "Factory Expenses" tab: one-time costs charged or provided by
-- a tea factory in a given month (e.g. fertilizer supplied by the
-- factory, deducted from what's owed). These are separate from the
-- estate-side `expenses` table and are netted against factory delivery
-- value to get the factory's monthly profit/income.
--
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin) — it does NOT replace the earlier factory_management /
-- factory_deliveries_daily migrations, which must already be applied.

CREATE TABLE `factory_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `factory_id` int(11) NOT NULL,
  `expense_month` date NOT NULL COMMENT 'Always stored as the 1st of the month this expense counts against',
  `expense_date` date NOT NULL COMMENT 'The actual date the expense was incurred/recorded',
  `category` varchar(100) NOT NULL DEFAULT 'Miscellaneous',
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_factory_expenses_estate` (`estate_id`),
  KEY `idx_factory_expenses_month` (`expense_month`),
  KEY `idx_factory_expenses_factory` (`factory_id`),
  CONSTRAINT `factory_expenses_ibfk_1` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `factory_expenses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
