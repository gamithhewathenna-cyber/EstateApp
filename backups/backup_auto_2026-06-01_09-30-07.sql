-- TeaEstate Pro Auto Backup
-- Generated: 2026-06-01 09:30:07
-- Label: Auto Backup - June 2026

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','supervisor') NOT NULL DEFAULT 'supervisor',
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `username`, `password`, `role`, `email`, `phone`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
('1', 'Gamith Hewathenna', 'admin', '$2y$10$P/66AARKAtwlmXwBNKyO3uMHLMBXDKdXnODPxZ.Ji91YNFsBbwpj6', 'admin', 'gamithhewathenna@gmail.com', '+94777130597', '1', '2026-05-31 13:07:53', '2026-05-24 03:34:27', '2026-05-31 13:07:53'),
('2', 'Rangika Madhumali', 'supervisor', '$2y$10$4HyQxi3AaYpufNW7X8V.Nel1tZ86jpOIj9vBgFrxBwm2.A3PTnb7.', 'supervisor', 'rangika.infogate@gmail.com', '', '1', '2026-05-25 23:36:31', '2026-05-24 03:34:27', '2026-05-25 23:36:31');

DROP TABLE IF EXISTS `workers`;
CREATE TABLE `workers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `nic` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_workers_estate` (`estate_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `workers` (`id`, `estate_id`, `full_name`, `phone`, `nic`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
('1', '1', 'Pathirage', '0718861036', '892345678V', 'Plucking Worker', '1', '2026-05-24 03:34:27', '2026-05-24 04:41:51'),
('2', '1', 'Somasiri Wife', '', '', 'Reliable worker', '1', '2026-05-24 03:34:27', '2026-05-26 05:30:43'),
('3', '1', 'Shantha', '0713232333', '901234567V', 'Available for all work types', '1', '2026-05-24 03:34:27', '2026-05-24 04:38:54'),
('4', '1', 'Somasiri', '0773456789', '', 'Available for all work types', '1', '2026-05-24 03:34:27', '2026-05-26 05:30:50'),
('5', '1', 'Sumane', '', '', 'Experienced in clearing work', '1', '2026-05-24 03:34:27', '2026-05-24 04:42:06'),
('6', '1', 'Maama Daluwaththa', '', '', 'Available for all work types', '1', '2026-05-25 06:36:15', '2026-05-25 06:36:15'),
('7', '5', 'Shantha', '', '', '', '1', '2026-05-29 10:29:47', '2026-05-29 10:29:47'),
('8', '5', 'Asoka', '', '', '', '1', '2026-05-29 10:29:52', '2026-05-29 10:29:52'),
('9', '5', 'Sumane', '', '', '', '1', '2026-05-29 10:29:58', '2026-05-29 10:29:58'),
('10', '5', 'Pathirage', '', '', '', '1', '2026-05-29 10:30:03', '2026-05-29 10:30:03'),
('11', '5', 'Somasiri', '', '', '', '1', '2026-05-29 10:30:11', '2026-05-29 10:30:11'),
('12', '1', 'Ajith', '', '', '', '0', '2026-05-31 00:22:49', '2026-05-31 12:54:54');

DROP TABLE IF EXISTS `plantations`;
CREATE TABLE `plantations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `area_hectares` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_plantations_estate` (`estate_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `plantations` (`id`, `estate_id`, `name`, `location`, `area_hectares`, `notes`, `is_active`, `created_at`) VALUES
('1', '1', 'Plantation A', 'North Block', '12.50', NULL, '1', '2026-05-24 03:34:27'),
('2', '1', 'Plantation B', 'South Block', '9.75', NULL, '1', '2026-05-24 03:34:27'),
('3', '1', 'Plantation C', 'East Block', '8.00', NULL, '1', '2026-05-24 03:34:27'),
('4', '1', 'Plantation M', 'Well Block', '8.00', '', '1', '2026-05-24 06:00:09'),
('5', '5', 'Daluwaththa A', '', NULL, '4049 Clone', '1', '2026-05-29 10:28:47'),
('6', '5', 'Daluwaththa B', '', NULL, '2026', '1', '2026-05-29 10:52:24'),
('7', '5', 'Daluwaththa M', '', NULL, '', '1', '2026-05-29 10:52:35');

DROP TABLE IF EXISTS `work_types`;
CREATE TABLE `work_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `unit_label` varchar(50) NOT NULL DEFAULT 'Unit',
  `rate_per_unit` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_worktypes_estate` (`estate_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `work_types` (`id`, `estate_id`, `name`, `unit_label`, `rate_per_unit`, `description`, `is_active`) VALUES
('1', '1', 'Tea Plucking', 'KG', '50.00', 'Tea leaf plucking - Rs.50 per KG', '1'),
('2', '1', 'Clearing Work', 'Unit', '2000.00', 'Full day clearing (8AM-3PM) - Rs.2000 per unit', '1'),
('3', '1', 'Tank Spraying', 'Tank', '200.00', 'Chemical spraying per tank - Rs.200 per tank', '1'),
('4', '1', 'Helper', 'Day', '1000.00', 'General helper work - Rs.1000 per day', '1'),
('5', '1', 'Basic / Support Work', 'Unit', '2000.00', 'Basic support work - Rs.2000 per unit', '1'),
('6', '1', 'Tea Cuttting', 'Unit', '2800.00', '', '1'),
('23', '5', 'Tea Plucking', 'KG', '50.00', 'Daily tea leaf plucking', '1'),
('24', '5', 'Clearing Work', 'Unit', '2000.00', 'Clearing and weeding', '1'),
('25', '5', 'Tank Spraying', 'Tank', '200.00', 'Pesticide spraying', '1'),
('26', '5', 'Helper', 'Day', '1000.00', 'General helper', '1'),
('27', '5', 'Basic / Support Work', 'Unit', '2000.00', 'Basic support work', '1');

DROP TABLE IF EXISTS `daily_assignments`;
CREATE TABLE `daily_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `assignment_date` date NOT NULL,
  `worker_id` int(11) DEFAULT NULL,
  `plantation_id` int(11) NOT NULL,
  `work_type_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(10,2) NOT NULL,
  `payment` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_type_id` (`work_type_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_assignments_date` (`assignment_date`),
  KEY `idx_assignments_worker` (`worker_id`),
  KEY `idx_assignments_plantation` (`plantation_id`),
  KEY `idx_assignments_estate` (`estate_id`),
  CONSTRAINT `daily_assignments_ibfk_2` FOREIGN KEY (`plantation_id`) REFERENCES `plantations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_assignments_ibfk_3` FOREIGN KEY (`work_type_id`) REFERENCES `work_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_assignments_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `daily_assignments` (`id`, `estate_id`, `assignment_date`, `worker_id`, `plantation_id`, `work_type_id`, `quantity`, `rate`, `payment`, `notes`, `created_by`, `created_at`, `updated_at`, `payment_status`, `approval_status`, `approved_by`, `approved_at`, `rejection_note`) VALUES
('15', '1', '2026-05-02', '3', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:19', '2026-05-25 06:07:02', 'paid', 'approved', NULL, NULL, NULL),
('16', '1', '2026-05-02', '4', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:19', '2026-05-25 06:06:59', 'paid', 'approved', NULL, NULL, NULL),
('17', '1', '2026-05-03', '3', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:44', '2026-05-25 06:07:15', 'paid', 'approved', NULL, NULL, NULL),
('18', '1', '2026-05-03', '4', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:44', '2026-05-25 06:07:13', 'paid', 'approved', NULL, NULL, NULL),
('19', '1', '2026-05-03', '2', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:44', '2026-05-25 06:07:12', 'paid', 'approved', NULL, NULL, NULL),
('20', '1', '2026-05-03', '5', '2', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:49:44', '2026-05-25 06:07:10', 'paid', 'approved', NULL, NULL, NULL),
('21', '1', '2026-05-04', '2', '1', '1', '116.00', '50.00', '5800.00', '', '1', '2026-05-24 04:50:10', '2026-05-25 06:14:56', 'paid', 'approved', NULL, NULL, NULL),
('23', '1', '2026-05-05', '3', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:50:41', '2026-05-25 06:15:33', 'paid', 'approved', NULL, NULL, NULL),
('24', '1', '2026-05-05', '4', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-24 04:50:41', '2026-05-25 06:15:29', 'paid', 'approved', NULL, NULL, NULL),
('27', '1', '2026-05-05', '2', '1', '1', '18.00', '50.00', '900.00', '', '1', '2026-05-24 05:37:00', '2026-05-25 06:15:09', 'paid', 'approved', NULL, NULL, NULL),
('28', '1', '2026-05-11', '2', '1', '1', '73.00', '50.00', '3650.00', '', '1', '2026-05-24 05:42:33', '2026-05-25 06:16:05', 'paid', 'approved', NULL, NULL, NULL),
('29', '1', '2026-05-12', '2', '1', '1', '72.00', '50.00', '3600.00', '', '1', '2026-05-24 05:42:53', '2026-05-25 06:16:11', 'paid', 'approved', NULL, NULL, NULL),
('30', '1', '2026-05-14', '2', '1', '1', '43.00', '50.00', '2150.00', '', '1', '2026-05-24 05:43:24', '2026-05-25 06:16:15', 'paid', 'approved', NULL, NULL, NULL),
('31', '1', '2026-05-19', '1', '1', '1', '39.00', '50.00', '1950.00', '', '1', '2026-05-24 05:47:52', '2026-05-25 06:24:52', 'paid', 'approved', NULL, NULL, NULL),
('32', '1', '2026-05-19', '4', '1', '1', '40.00', '50.00', '2000.00', '', '1', '2026-05-24 05:47:52', '2026-05-25 06:24:49', 'paid', 'approved', NULL, NULL, NULL),
('33', '1', '2026-05-19', '2', '1', '1', '40.00', '50.00', '2000.00', '', '1', '2026-05-24 05:47:52', '2026-05-25 06:24:48', 'paid', 'approved', NULL, NULL, NULL),
('35', '1', '2026-05-14', '2', '4', '1', '32.00', '50.00', '1600.00', '', '1', '2026-05-24 06:02:08', '2026-05-25 06:16:18', 'paid', 'approved', NULL, NULL, NULL),
('36', '1', '2026-05-25', '5', '1', '1', '19.00', '50.00', '950.00', '', '1', '2026-05-25 04:11:20', '2026-05-31 12:44:51', 'paid', 'approved', NULL, NULL, NULL),
('37', '1', '2026-05-25', '1', '1', '1', '17.00', '50.00', '850.00', '', '1', '2026-05-25 04:11:30', '2026-05-31 12:44:53', 'paid', 'approved', NULL, NULL, NULL),
('38', '1', '2026-05-25', '4', '1', '1', '15.00', '50.00', '750.00', '', '1', '2026-05-25 04:11:38', '2026-05-31 12:44:54', 'paid', 'approved', NULL, NULL, NULL),
('44', '1', '2026-05-04', '1', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:20:20', '2026-05-25 06:20:25', 'paid', 'approved', NULL, NULL, NULL),
('45', '1', '2026-05-04', '3', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:20:20', '2026-05-25 06:20:26', 'paid', 'approved', NULL, NULL, NULL),
('46', '1', '2026-05-04', '4', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:20:20', '2026-05-25 06:20:27', 'paid', 'approved', NULL, NULL, NULL),
('47', '1', '2026-05-04', '2', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:20:20', '2026-05-25 06:20:28', 'paid', 'approved', NULL, NULL, NULL),
('48', '1', '2026-05-04', '5', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:20:20', '2026-05-25 06:20:29', 'paid', 'approved', NULL, NULL, NULL),
('49', '1', '2026-05-06', '3', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:21:32', '2026-05-25 06:42:42', 'paid', 'approved', NULL, NULL, NULL),
('50', '1', '2026-05-06', '4', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-25 06:21:32', '2026-05-25 06:42:38', 'paid', 'approved', NULL, NULL, NULL),
('52', '1', '2026-05-22', '2', '1', '1', '21.00', '50.00', '1050.00', '', '1', '2026-05-25 06:29:38', '2026-05-25 06:29:40', 'paid', 'approved', NULL, NULL, NULL),
('53', '1', '2026-05-22', '3', '1', '5', '1.00', '2000.00', '2000.00', 'පැල අඩුක් කිරීම', '1', '2026-05-25 06:30:49', '2026-05-27 09:04:53', 'paid', 'approved', NULL, NULL, NULL),
('54', '1', '2026-05-22', '3', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:35:10', '2026-05-27 09:05:39', 'paid', 'approved', NULL, NULL, NULL),
('55', '1', '2026-05-22', '4', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:35:10', '2026-05-27 09:05:56', 'paid', 'approved', NULL, NULL, NULL),
('56', '1', '2026-05-22', '2', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:35:10', '2026-05-27 09:06:03', 'paid', 'approved', NULL, NULL, NULL),
('57', '1', '2026-05-22', '5', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:35:10', '2026-05-27 09:06:08', 'paid', 'approved', NULL, NULL, NULL),
('58', '1', '2026-05-22', '6', '2', '5', '1.00', '2000.00', '2000.00', 'ගස් සුද්ද කිරීම', '1', '2026-05-25 06:36:56', '2026-05-27 09:05:45', 'paid', 'approved', NULL, NULL, NULL),
('59', '1', '2026-05-23', '6', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:37:35', '2026-05-27 09:05:33', 'paid', 'approved', NULL, NULL, NULL),
('60', '1', '2026-05-23', '3', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:37:35', '2026-05-27 09:04:57', 'paid', 'approved', NULL, NULL, NULL),
('61', '1', '2026-05-23', '4', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:37:35', '2026-05-27 09:05:22', 'paid', 'approved', NULL, NULL, NULL),
('62', '1', '2026-05-23', '5', '3', '5', '1.00', '2000.00', '2000.00', 'නෙත්තිය සෑදීම', '1', '2026-05-25 06:37:35', '2026-05-27 09:05:18', 'paid', 'approved', NULL, NULL, NULL),
('63', '1', '2026-05-26', '6', '2', '1', '10.00', '50.00', '500.00', 'Testing For Month Suprivoer', '2', '2026-05-25 23:37:40', '2026-05-25 23:53:17', 'pending', 'rejected', '1', '2026-05-25 23:53:17', 'Testing'),
('64', '1', '2026-05-26', '1', '2', '1', '15.00', '50.00', '750.00', 'Testing For Month Suprivoer', '2', '2026-05-25 23:37:40', '2026-05-25 23:53:11', 'pending', 'rejected', '1', '2026-05-25 23:53:11', ''),
('80', '5', '2026-05-31', '7', '5', '23', '73.00', '50.00', '3650.00', 'Lokke', '1', '2026-05-30 23:47:55', '2026-05-30 23:47:55', 'pending', 'approved', '1', '2026-05-31 09:17:55', NULL),
('86', '1', '2026-05-31', '6', '2', '5', '1.00', '2000.00', '2000.00', 'වල් සූරියකාන්ත කැපීම', '1', '2026-05-31 12:58:51', '2026-05-31 12:59:08', 'paid', 'approved', '1', '2026-05-31 22:28:51', NULL),
('87', '1', '2026-05-31', '3', '1', '2', '1.00', '2000.00', '2000.00', '', '1', '2026-05-31 12:59:04', '2026-05-31 12:59:15', 'paid', 'approved', '1', '2026-05-31 22:29:04', NULL),
('88', '1', '2026-05-31', '0', '4', '1', '25.00', '50.00', '1250.00', 'TEMP:Ajith', '1', '2026-05-31 13:09:37', '2026-05-31 13:09:43', 'paid', 'approved', '1', '2026-05-31 22:39:37', NULL);

DROP TABLE IF EXISTS `fertilizer_cycles`;
CREATE TABLE `fertilizer_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `plantation_id` int(11) NOT NULL,
  `applied_date` date NOT NULL,
  `fertilizer_type` varchar(100) NOT NULL,
  `amount_kg` decimal(10,2) DEFAULT NULL,
  `next_cycle_days` int(11) DEFAULT 30,
  `next_due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_fertilizer_plantation` (`plantation_id`),
  KEY `idx_fertilizer_due` (`next_due_date`),
  KEY `idx_fertilizer_estate` (`estate_id`),
  CONSTRAINT `fertilizer_cycles_ibfk_1` FOREIGN KEY (`plantation_id`) REFERENCES `plantations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fertilizer_cycles_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `fertilizer_cycles` (`id`, `estate_id`, `plantation_id`, `applied_date`, `fertilizer_type`, `amount_kg`, `next_cycle_days`, `next_due_date`, `notes`, `created_by`, `created_at`) VALUES
('1', '1', '2', '2026-05-04', 'T200', '100.00', '50', '2026-06-23', '', '1', '2026-05-25 07:41:31');

DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `expense_date` date NOT NULL,
  `plantation_id` int(11) DEFAULT NULL,
  `expense_type` enum('Spray Can','Pohora','Dolomite','Food','Transport','Equipment','Miscellaneous') NOT NULL DEFAULT 'Miscellaneous',
  `amount` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `plantation_id` (`plantation_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_estate` (`estate_id`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`plantation_id`) REFERENCES `plantations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `expenses` (`id`, `estate_id`, `expense_date`, `plantation_id`, `expense_type`, `amount`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
('1', '1', '2026-05-02', NULL, 'Food', '750.00', 'Breads for Lucnh', '1', '2026-05-24 06:11:21', '2026-05-24 06:11:21'),
('2', '1', '2026-05-31', '2', 'Food', '2865.00', 'Food - Suda Kade', '1', '2026-05-31 12:43:09', '2026-05-31 12:43:09');

DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `key` varchar(100) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`,`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `app_settings` (`key`, `estate_id`, `value`, `updated_at`) VALUES
('app_name', '1', 'Tea Estate Pro', '2026-05-29 10:57:29'),
('app_name', '5', 'TeaEstate Pro', '2026-05-29 10:47:37'),
('app_sub', '1', 'Modarawila Estate Management', '2026-05-29 10:57:29'),
('app_sub', '5', 'Estate Management', '2026-05-29 10:47:37'),
('logo_file', '1', 'logo.png', '2026-05-25 08:04:28'),
('logo_updated', '1', '1779710668', '2026-05-25 08:04:28'),
('theme_accent', '1', '#4caf50', '2026-05-29 10:57:29'),
('theme_accent', '5', '#42a5f5', '2026-05-29 10:47:37'),
('theme_bg', '1', '#0d2b0a', '2026-05-29 10:57:29'),
('theme_bg', '5', '#0a1a2b', '2026-05-29 10:47:37'),
('theme_color', '1', '#2e6b12', '2026-05-29 10:57:29'),
('theme_color', '5', '#1565c0', '2026-05-29 10:47:37');

SET FOREIGN_KEY_CHECKS = 1;
