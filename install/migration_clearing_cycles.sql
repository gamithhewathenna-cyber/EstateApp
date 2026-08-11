-- Adds the "Clearing Cycles" feature (tracks when each section was last
-- cleared and when the next clearing is due) — same idea as
-- fertilizer_cycles, but without a "type" field.
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin) before deploying the new clearing.php page.

CREATE TABLE `clearing_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `plantation_id` int(11) NOT NULL,
  `date_cleared` date NOT NULL,
  `next_due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_clearing_plantation` (`plantation_id`),
  KEY `idx_clearing_due` (`next_due_date`),
  KEY `idx_clearing_estate` (`estate_id`),
  CONSTRAINT `clearing_cycles_ibfk_1` FOREIGN KEY (`plantation_id`) REFERENCES `plantations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clearing_cycles_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
