-- Adds a manual "Units to Clear" field to clearing_cycles. This is a
-- free-entry number typed in on the Clearing Cycles form each time — it is
-- NOT pulled from the plantation's own unit/area data, so it stays
-- independent of whatever "unit" is configured elsewhere (e.g. work type
-- unit labels or plantation size).
--
-- Only run this if you already created the clearing_cycles table using an
-- earlier migration_clearing_cycles.sql. That base file has since been
-- updated to include this column from the start, so a fresh install
-- doesn't need this file.

ALTER TABLE `clearing_cycles`
  ADD COLUMN `units_to_clear` decimal(10,2) DEFAULT NULL AFTER `next_due_date`;
