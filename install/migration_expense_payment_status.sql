-- Adds a payment status to expenses so a Food expense can be marked "Paid"
-- from the Expenses page. Once marked Paid, it is automatically reflected
-- as paid in the Weekly Cost Report (counted under "Paid" instead of
-- "Outstanding Balance").
-- Run this once against the existing production database (e.g. via phpMyAdmin)
-- before deploying the updated expenses.php / cost-report-pdf.php.

ALTER TABLE `expenses`
  ADD COLUMN `payment_status` ENUM('pending','paid') NOT NULL DEFAULT 'pending' AFTER `amount`;
