<?php
// ============================================
// TeaEstate Pro - Configuration
// Edit DB credentials before uploading
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'creaeina_database');
define('DB_USER', 'creaeina_modarawiladmin');   // Change this
define('DB_PASS', 'ds]CkjUPF5?8gxB@');   // Change this
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'TeaEstate Pro');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '');  // Leave empty if in root, or set e.g. '/teaestate'

define('SESSION_TIMEOUT', 3600); // 1 hour

// Timezone
date_default_timezone_set('Asia/Colombo');

// Error reporting — off in production to prevent info leakage
error_reporting(0);
ini_set('display_errors', 0);
