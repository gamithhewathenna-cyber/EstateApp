<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check();
if (!Auth::isAdmin()) { jsonResponse(['error'=>'Admin only'], 403); }
$estateId = Auth::estateId();

$id   = (int)($_POST['id'] ?? 0);
$rate = (float)($_POST['rate'] ?? 0);

if (!$id || $rate < 0) { jsonResponse(['error'=>'Invalid data'], 400); }

// ONLY updates the default rate for future assignments
// Existing daily_assignments.rate values are NOT touched
DB::execute("UPDATE work_types SET rate_per_unit=? WHERE id=? AND estate_id=?", [$rate, $id, $estateId]);

jsonResponse(['success' => true, 'new_rate' => $rate]);
