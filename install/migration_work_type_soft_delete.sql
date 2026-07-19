-- Adds soft-delete support to work types.
-- work_types.id is referenced by daily_assignments.work_type_id with
-- ON DELETE CASCADE, so a real DELETE would wipe out every historical
-- assignment/payment record for that work type. This flag lets Settings
-- "delete" a work type (hide it from the list and from new assignments)
-- while keeping all past records exactly as they were.
-- Run this once against the existing production database (e.g. via phpMyAdmin)
-- before deploying the updated settings.php / assignments.php.

ALTER TABLE `work_types`
  ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
