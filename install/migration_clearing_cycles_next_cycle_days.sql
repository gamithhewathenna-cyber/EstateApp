-- Adds the "Next Cycle (days)" field to clearing_cycles, so it works the
-- same way as fertilizer_cycles.next_cycle_days: pick a number of days,
-- and Next Due Date is calculated automatically from Date Cleared.
--
-- Only run this if you already created the clearing_cycles table using the
-- earlier install/migration_clearing_cycles.sql (that file has since been
-- updated to include this column from the start, so a fresh install
-- doesn't need this file).

ALTER TABLE `clearing_cycles`
  ADD COLUMN `next_cycle_days` int(11) DEFAULT 30 AFTER `date_cleared`;
