<?php
// Backup & Restore now lives inside the Settings page.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
Auth::requireAdmin();
redirect('/settings.php#backup');
