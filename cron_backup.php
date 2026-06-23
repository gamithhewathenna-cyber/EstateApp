<?php
// ============================================
// TeaEstate Pro - Monthly Auto Backup Cron
// Add to cPanel Cron Jobs:
//   0 2 1 * * /usr/bin/php /path/to/cron_backup.php
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

$htaccess = $backupDir . '.htaccess';
if (!file_exists($htaccess)) file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");

$tables = ['users','workers','plantations','work_types','daily_assignments',
           'fertilizer_cycles','expenses','app_settings'];

// Check if backup already exists for this month
$autoFiles = glob($backupDir . 'backup_auto_*.sql') ?: [];
usort($autoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
$lastAutoMonth = $autoFiles ? date('Y-m', filemtime($autoFiles[0])) : null;

if ($lastAutoMonth === date('Y-m')) {
    echo "[" . date('Y-m-d H:i:s') . "] Auto backup already exists for " . date('F Y') . ". Skipping.\n";
    exit;
}

// Generate SQL dump
$sql  = "-- TeaEstate Pro Auto Backup\n";
$sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- Label: Auto Backup - " . date('F Y') . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    try {
        $createRow = DB::fetchOne("SHOW CREATE TABLE `$table`", []);
        if (!$createRow) continue;
        $createSQL = array_values($createRow)[1];
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $createSQL . ";\n\n";
        $rows = DB::fetchAll("SELECT * FROM `$table`", []);
        if ($rows) {
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $sql .= "INSERT INTO `$table` ($cols) VALUES\n";
            $values = [];
            foreach ($rows as $row) {
                $escaped = array_map(function($v) {
                    if ($v === null) return 'NULL';
                    return "'" . addslashes($v) . "'";
                }, array_values($row));
                $values[] = '(' . implode(', ', $escaped) . ')';
            }
            $sql .= implode(",\n", $values) . ";\n\n";
        }
    } catch (Exception $e) {
        $sql .= "-- WARNING: $table - " . $e->getMessage() . "\n\n";
    }
}
$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// Save backup
$filename = 'backup_auto_' . date('Y-m-d_H-i-s') . '.sql';
file_put_contents($backupDir . $filename, $sql);
echo "[" . date('Y-m-d H:i:s') . "] Auto backup created: $filename (" . round(strlen($sql)/1024, 1) . " KB)\n";

// Cleanup — keep only last 2 auto backups
$autoFiles = glob($backupDir . 'backup_auto_*.sql') ?: [];
usort($autoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
foreach (array_slice($autoFiles, 2) as $old) {
    unlink($old);
    echo "[" . date('Y-m-d H:i:s') . "] Deleted old auto backup: " . basename($old) . "\n";
}

// Keep only last 2 manual backups too
$manualFiles = glob($backupDir . 'backup_manual_*.sql') ?: [];
usort($manualFiles, fn($a,$b) => filemtime($b) - filemtime($a));
foreach (array_slice($manualFiles, 2) as $old) {
    unlink($old);
    echo "[" . date('Y-m-d H:i:s') . "] Deleted old manual backup: " . basename($old) . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
