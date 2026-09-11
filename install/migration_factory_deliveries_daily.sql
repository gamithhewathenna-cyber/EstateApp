-- Switches Factory Management from per-worker factory assignment to a
-- single daily total per estate: each day's total Tea Plucking (KG) across
-- all workers/sections is assigned to ONE factory, with one factory-
-- confirmed weight — matching how tea is actually bulked and shipped.
--
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin) — it does NOT replace migration_factory_management.sql,
-- which must already have been applied (this only adds one more table).
--
-- The `daily_assignments.factory_id` / `factory_weight` columns added by
-- that earlier migration are no longer written to or read by the app —
-- they're left in place (harmless) rather than dropped, to avoid data loss
-- for anyone who already used the old per-worker assignment flow.

CREATE TABLE `factory_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `delivery_date` date NOT NULL,
  `factory_id` int(11) DEFAULT NULL,
  `factory_weight` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_estate_date` (`estate_id`,`delivery_date`),
  KEY `idx_factory_deliveries_factory` (`factory_id`),
  CONSTRAINT `factory_deliveries_ibfk_1` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `factory_deliveries_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
