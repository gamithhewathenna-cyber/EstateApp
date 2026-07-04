-- Adds "who provided the food" tracking to expenses.
-- Run this once against the existing production database (e.g. via phpMyAdmin)
-- before deploying the updated expenses.php.

ALTER TABLE `expenses`
  ADD COLUMN `food_provided_by` ENUM('us','owner') NOT NULL DEFAULT 'us' AFTER `amount`;
