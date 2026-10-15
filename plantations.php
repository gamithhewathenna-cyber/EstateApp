<?php
// Plantation Sections now lives inside the Estate Management page.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
Auth::requireAdmin();
redirect('/estates.php?tab=plantations');
