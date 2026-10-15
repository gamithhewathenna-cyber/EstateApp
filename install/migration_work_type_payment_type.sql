-- Adds support for "Fixed Amount" work types (e.g. Tea Plucking paid a flat
-- Rs. 1500 per worker regardless of exact KG, while the KG itself is still
-- recorded for production reports).
--
-- When payment_type='fixed', `rate_per_unit` is reinterpreted as the fixed
-- payment amount per worker per assignment, instead of a rate multiplied by
-- quantity. `quantity`/`unit_label` keep working exactly as before, so
-- production/KG reports are unaffected.
--
-- Run this once against the existing production database (e.g. via
-- phpMyAdmin).

ALTER TABLE `work_types`
  ADD COLUMN `payment_type` ENUM('per_unit','fixed') NOT NULL DEFAULT 'per_unit' AFTER `rate_per_unit`;
