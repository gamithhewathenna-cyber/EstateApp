-- Adds an optional payment-receipt image to each monthly factory price
-- entry. The image is compressed client-side (in the browser, to ~500KB)
-- before upload, so this just stores the resulting filename.
--
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin) — it does NOT replace the earlier factory_management
-- migrations, which must already be applied.

ALTER TABLE `factory_prices`
  ADD COLUMN `receipt_file` varchar(255) DEFAULT NULL AFTER `price_per_kg`;
