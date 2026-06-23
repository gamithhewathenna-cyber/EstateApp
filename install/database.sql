-- ============================================================
-- TEA ESTATE MANAGEMENT SYSTEM — DATABASE SCHEMA
-- Import this file in phpMyAdmin → Import tab
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Database: teaestate (create manually in cPanel first)
-- --------------------------------------------------------

-- Users / Login
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','supervisor') NOT NULL DEFAULT 'supervisor',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user: admin / admin123 (change after first login!)
INSERT INTO `users` (`full_name`, `username`, `email`, `password_hash`, `role`) VALUES
('Administrator', 'admin', 'admin@teaestate.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Supervisor One', 'supervisor', 'supervisor@teaestate.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supervisor');

-- Plantations
CREATE TABLE `plantations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `plantations` (`name`) VALUES ('Plantation A'), ('Plantation B'), ('Plantation C');

-- Workers
CREATE TABLE `workers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `nic` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `workers` (`full_name`, `phone`, `nic`, `status`, `notes`) VALUES
('Kamala Perera', '071-234-5678', '892345678V', 'active', 'Best plucking worker'),
('Suresh Silva', '077-345-6789', '', 'active', ''),
('Amara Ranasinghe', '076-456-7890', '901234567V', 'active', 'Senior worker'),
('Malini De Silva', '', '', 'active', ''),
('Nimal Fernando', '070-567-8901', '', 'inactive', 'On leave'),
('Priya Kumari', '075-678-9012', '951234567V', 'active', ''),
('Ruwan Bandara', '072-789-0123', '', 'active', ''),
('Saman Jayasinghe', '078-890-1234', '881234567V', 'active', '');

-- Work Types (reference)
CREATE TABLE `work_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(30) NOT NULL UNIQUE,
  `rate` decimal(10,2) NOT NULL,
  `unit_label` varchar(30) NOT NULL DEFAULT 'unit',
  `description` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `work_types` (`name`, `code`, `rate`, `unit_label`, `description`) VALUES
('Tea Plucking', 'plucking', 50.00, 'kg', 'Rs. 50 per KG'),
('Clearing Work', 'clearing', 2000.00, 'unit', 'Rs. 2,000 per unit (full day)'),
('Tank Spraying', 'spraying', 200.00, 'tank', 'Rs. 200 per tank'),
('Helper', 'helper', 1000.00, 'day', 'Rs. 1,000 per day'),
('Basic / Support Work', 'basic', 2000.00, 'unit', 'Rs. 2,000 per unit');

-- Daily Work Assignments
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `work_date` date NOT NULL,
  `plantation_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `work_type_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0,
  `rate` decimal(10,2) NOT NULL,
  `total_payment` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `work_date` (`work_date`),
  KEY `worker_id` (`worker_id`),
  KEY `plantation_id` (`plantation_id`),
  FOREIGN KEY (`plantation_id`) REFERENCES `plantations`(`id`),
  FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`),
  FOREIGN KEY (`work_type_id`) REFERENCES `work_types`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fertilizer Cycles
CREATE TABLE `fertilizer_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plantation_id` int(11) NOT NULL,
  `applied_date` date NOT NULL,
  `fertilizer_type` varchar(100) NOT NULL,
  `amount_kg` decimal(10,2) DEFAULT NULL,
  `next_cycle_days` int(11) NOT NULL DEFAULT 30,
  `next_due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `plantation_id` (`plantation_id`),
  FOREIGN KEY (`plantation_id`) REFERENCES `plantations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `fertilizer_records` (`plantation_id`, `applied_date`, `fertilizer_type`, `amount_kg`, `next_cycle_days`, `next_due_date`, `notes`) VALUES
(1, '2026-04-12', 'Urea', 50, 45, '2026-05-27', 'Applied after heavy rain'),
(2, '2026-04-29', 'Urea', 45, 45, '2026-06-13', ''),
(3, '2026-04-08', 'MOP (Muriate of Potash)', 40, 45, '2026-05-23', 'Soil test done');

-- Expenses
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `plantation_id` int(11) DEFAULT NULL,
  `expense_type` enum('food','transport','equipment','miscellaneous') NOT NULL DEFAULT 'miscellaneous',
  `amount` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `expense_date` (`expense_date`),
  FOREIGN KEY (`plantation_id`) REFERENCES `plantations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `expenses` (`expense_date`, `plantation_id`, `expense_type`, `amount`, `notes`) VALUES
('2026-05-22', 2, 'equipment', 4500.00, 'Pruning shears replacement'),
('2026-05-21', NULL, 'transport', 2800.00, 'Worker transport May 21'),
('2026-05-20', NULL, 'food', 3200.00, 'Monthly food supplies'),
('2026-05-18', 1, 'equipment', 1800.00, 'Spray pump repair'),
('2026-05-15', NULL, 'miscellaneous', 1200.00, 'Office supplies');

COMMIT;
