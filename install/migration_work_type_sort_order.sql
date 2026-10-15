-- Adds manual ordering for Work Types (Settings → Work Types "Move Up/Down").
-- Existing rows are backfilled to their current id-based order so nothing
-- visibly reshuffles until an admin actually moves something.
--
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin).

ALTER TABLE `work_types`
  ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `payment_type`;

UPDATE `work_types` SET `sort_order` = `id`;
