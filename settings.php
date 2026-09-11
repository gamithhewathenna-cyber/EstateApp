<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Settings';
Auth::requireAdmin();
$estateId = Auth::estateId();

// Ensure settings table exists
try {
    DB::execute("CREATE TABLE IF NOT EXISTS app_settings (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", []);
} catch (Exception $e) {}

// Ensure logo upload dir exists
$logoDir = __DIR__ . '/assets/img/';
if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);

// ── BACKUP & RESTORE ────────────────────────────────────────────
$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$backupHtaccess = $backupDir . '.htaccess';
if (!file_exists($backupHtaccess)) {
    file_put_contents($backupHtaccess, "Order Deny,Allow\nDeny from all\n");
}
$backupTables = ['users','workers','plantations','work_types','daily_assignments',
           'fertilizer_cycles','clearing_cycles','expenses','app_settings'];

function generateSQLDump($tables, $label = '') {
    $sql  = "-- ============================================\n";
    $sql .= "-- TeaEstate Pro Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    if ($label) $sql .= "-- Label: " . $label . "\n";
    $sql .= "-- ============================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        try {
            // Table structure
            $createRow = DB::fetchOne("SHOW CREATE TABLE `$table`", []);
            if (!$createRow) continue;
            $createSQL = array_values($createRow)[1];
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $createSQL . ";\n\n";

            // Table data
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
            $sql .= "-- WARNING: Could not backup table $table: " . $e->getMessage() . "\n\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

// Keep only last 2 auto + last 2 manual backups
function cleanOldBackups($backupDir) {
    $files = glob($backupDir . 'backup_*.sql');
    if (!$files || count($files) <= 2) return 0;

    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));

    $autoFiles   = array_filter($files, fn($f) => strpos(basename($f), 'backup_auto_') === 0);
    $manualFiles = array_filter($files, fn($f) => strpos(basename($f), 'backup_manual_') === 0);

    $deleted = 0;
    foreach ([$autoFiles, $manualFiles] as $group) {
        $group = array_values($group);
        foreach (array_slice($group, 2) as $old) {
            if (unlink($old)) $deleted++;
        }
    }
    return $deleted;
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 2) . ' MB';
}

function getSetting($key, $default = '', $estateId = 1) {
    $row = DB::fetchOne("SELECT value FROM app_settings WHERE `key`=? AND estate_id=?", [$key, $estateId]);
    return $row ? $row['value'] : $default;
}

function saveSetting($key, $value, $estateId = 1) {
    DB::execute("INSERT INTO app_settings (`key`,`estate_id`,`value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=?, updated_at=NOW()",
        [$key, $estateId, $value, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── APP SETTINGS ──────────────────────────────
    if ($action === 'save_app') {
        $appName = trim($_POST['app_name'] ?? '');
        $appSub  = trim($_POST['app_sub'] ?? '');
        if (!$appName) { flash('error', 'App name cannot be empty.'); redirect('/settings.php'); }

        saveSetting('app_name', $appName, $estateId);
        saveSetting('app_sub', $appSub, $estateId);
        // Theme colours
        $themeColor  = trim($_POST['theme_color']  ?? '#2E6B12');
        $themeBg     = trim($_POST['theme_bg']     ?? '#0D2B0A');
        $themeAccent = trim($_POST['theme_accent'] ?? '#4CAF50');
        saveSetting('theme_color', $themeColor, $estateId);
        saveSetting('theme_bg', $themeBg, $estateId);
        saveSetting('theme_accent', $themeAccent, $estateId);

        // Handle logo upload
        if (!empty($_FILES['logo']['name'])) {
            $file     = $_FILES['logo'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['png','jpg','jpeg','gif','svg','webp'];
            $maxSize  = 2 * 1024 * 1024; // 2MB

            if (!in_array($ext, $allowed)) {
                flash('error', 'Logo must be PNG, JPG, GIF, SVG or WEBP.');
                redirect('/settings.php');
            }
            if ($file['size'] > $maxSize) {
                flash('error', 'Logo must be under 2MB.');
                redirect('/settings.php');
            }
            $filename = 'logo.' . $ext;
            // Remove old logos
            foreach ($allowed as $e) {
                $old = $logoDir . 'logo.' . $e;
                if (file_exists($old)) unlink($old);
            }
            if (move_uploaded_file($file['tmp_name'], $logoDir . $filename)) {
                saveSetting('logo_file', $filename, $estateId);
                saveSetting('logo_updated', time());
            } else {
                flash('error', 'Failed to upload logo. Check folder permissions.');
                redirect('/settings.php');
            }
        }

        // Remove logo
        if (!empty($_POST['remove_logo'])) {
            $logoFile = getSetting('logo_file', '', $estateId);
            if ($logoFile && file_exists($logoDir . $logoFile)) unlink($logoDir . $logoFile);
            saveSetting('logo_file', '', $estateId);
        }

        flash('success', 'App settings saved.');
        redirect('/settings.php');
    }

    // ── MY PROFILE ────────────────────────────────
    if ($action === 'save_profile') {
        $uid   = (int)(Auth::user()['id']);
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (!$name) { flash('error', 'Name cannot be empty.'); redirect('/settings.php#profile'); }
        DB::execute("UPDATE users SET name=?, email=?, phone=?, updated_at=NOW() WHERE id=?",
            [$name, $email, $phone, $uid]);
        // Update session name
        $_SESSION['user_name'] = $name;
        flash('success', 'Profile updated.');
        redirect('/settings.php#profile');
    }

    // ── CHANGE PASSWORD ───────────────────────────
    if ($action === 'change_password') {
        $uid      = (int)(Auth::user()['id']);
        $current  = $_POST['current_password'] ?? '';
        $new1     = $_POST['new_password']     ?? '';
        $new2     = $_POST['confirm_password'] ?? '';

        $user = DB::fetchOne("SELECT password FROM users WHERE id=?", [$uid]);

        if (!password_verify($current, $user['password'])) {
            flash('error', 'Current password is incorrect.');
            redirect('/settings.php#password');
        }
        if (strlen($new1) < 6) {
            flash('error', 'New password must be at least 6 characters.');
            redirect('/settings.php#password');
        }
        if ($new1 !== $new2) {
            flash('error', 'New passwords do not match.');
            redirect('/settings.php#password');
        }
        $hash = password_hash($new1, PASSWORD_BCRYPT);
        DB::execute("UPDATE users SET password=?, updated_at=NOW() WHERE id=?", [$hash, $uid]);
        flash('success', 'Password changed successfully.');
        redirect('/settings.php#password');
    }

    // ── ADD WORK TYPE ────────────────────────────
    if ($action === 'add_work_type') {
        $name  = trim($_POST['wt_name']  ?? '');
        $unit  = trim($_POST['wt_unit']  ?? 'Unit');
        $rate  = (float)($_POST['wt_rate']  ?? 0);
        $desc  = trim($_POST['wt_desc']  ?? '');
        if (!$name || $rate < 0) { flash('error','Work type name and rate are required.'); redirect('/settings.php#worktypes'); }
        DB::insert("INSERT INTO work_types (estate_id, name, unit_label, rate_per_unit, description, is_active) VALUES (?,?,?,?,?,1)",
            [$estateId, $name, $unit ?: 'Unit', $rate, $desc]);
        flash('success', 'Work type "' . $name . '" added.');
        redirect('/settings.php#worktypes');
    }

    // ── UPDATE WORK TYPE RATE ─────────────────────
    if ($action === 'update_rate') {
        $id   = (int)($_POST['wt_id']   ?? 0);
        $rate = (float)($_POST['wt_rate'] ?? 0);
        $name = trim($_POST['wt_name']  ?? '');
        $unit = trim($_POST['wt_unit']  ?? '');
        if (!$id) { flash('error','Invalid work type.'); redirect('/settings.php#worktypes'); }
        DB::execute("UPDATE work_types SET rate_per_unit=?, name=?, unit_label=? WHERE id=? AND estate_id=?", [$rate, $name, $unit, $id, $estateId]);
        flash('success', 'Work type updated. New rate applies to future assignments only.');
        redirect('/settings.php#worktypes');
    }

    // ── TOGGLE WORK TYPE STATUS ───────────────────
    if ($action === 'toggle_work_type') {
        $id      = (int)($_POST['wt_id']      ?? 0);
        $current = (int)($_POST['wt_current'] ?? 1);
        DB::execute("UPDATE work_types SET is_active=? WHERE id=? AND estate_id=?", [$current ? 0 : 1, $id, $estateId]);
        flash('success', 'Work type status updated.');
        redirect('/settings.php#worktypes');
    }

    // ── DELETE WORK TYPE (soft delete — keeps existing assignment records) ──
    if ($action === 'delete_work_type') {
        $id = (int)($_POST['wt_id'] ?? 0);
        if (!$id) { flash('error','Invalid work type.'); redirect('/settings.php#worktypes'); }
        // Never a hard DELETE: work_type_id is referenced by daily_assignments
        // with ON DELETE CASCADE, which would erase historical records.
        DB::execute("UPDATE work_types SET is_active=0, is_deleted=1 WHERE id=? AND estate_id=?", [$id, $estateId]);
        flash('success', 'Work type deleted. Existing assignment records are unaffected.');
        redirect('/settings.php#worktypes');
    }

    // ── RESET OTHER USER PASSWORD (admin) ─────────
    if ($action === 'reset_user_password') {
        Auth::requireAdmin();
        $uid     = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 6) {
            flash('error', 'Password must be at least 6 characters.');
            redirect('/settings.php#users');
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        DB::execute("UPDATE users SET password=?, updated_at=NOW() WHERE id=?", [$hash, $uid]);
        flash('success', 'User password reset successfully.');
        redirect('/settings.php#users');
    }

    // ── BACKUP: manual backup ─────────────────────
    if ($action === 'create_backup') {
        $label    = trim($_POST['label'] ?? 'Manual Backup');
        $sql      = generateSQLDump($backupTables, $label);
        $filename = 'backup_manual_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($backupDir . $filename, $sql);
        cleanOldBackups($backupDir);
        flash('success', 'Backup created: ' . $filename);
        redirect('/settings.php#backup');
    }

    // ── BACKUP: download existing backup ──────────
    if ($action === 'download') {
        $file = basename($_POST['file'] ?? '');
        $path = $backupDir . $file;
        if ($file && file_exists($path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        flash('error', 'Backup file not found.');
        redirect('/settings.php#backup');
    }

    // ── BACKUP: delete backup ──────────────────────
    if ($action === 'delete_backup') {
        $file = basename($_POST['file'] ?? '');
        $path = $backupDir . $file;
        if ($file && file_exists($path)) {
            unlink($path);
            flash('success', 'Backup deleted.');
        }
        redirect('/settings.php#backup');
    }

    // ── BACKUP: export now (direct download, no save) ──
    if ($action === 'export_now') {
        $sql      = generateSQLDump($backupTables, 'Direct Export');
        $filename = 'teaestate_export_' . date('Y-m-d_H-i-s') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }

    // ── BACKUP: run auto backup now ───────────────
    if ($action === 'run_autobackup') {
        $lastAutoBackup = '';
        $autoFiles = glob($backupDir . 'backup_auto_*.sql');
        if ($autoFiles) {
            usort($autoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
            $lastAutoBackup = date('Y-m', filemtime($autoFiles[0]));
        }
        $currentMonth = date('Y-m');
        if ($lastAutoBackup !== $currentMonth) {
            $sql      = generateSQLDump($backupTables, 'Auto Backup - ' . date('F Y'));
            $filename = 'backup_auto_' . date('Y-m-d_H-i-s') . '.sql';
            file_put_contents($backupDir . $filename, $sql);
            $deleted  = cleanOldBackups($backupDir);
            flash('success', 'Auto backup created for ' . date('F Y') . '. Cleaned ' . $deleted . ' old backup(s).');
        } else {
            flash('success', 'Auto backup already exists for this month.');
        }
        redirect('/settings.php#backup');
    }

    // ── BACKUP: import / restore ───────────────────
    if ($action === 'import') {
        if (empty($_FILES['backup_file']['name'])) {
            flash('error', 'Please select a backup file to import.');
            redirect('/settings.php#backup');
        }
        $file = $_FILES['backup_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            flash('error', 'Only .sql files are allowed.');
            redirect('/settings.php#backup');
        }
        if ($file['size'] > 50 * 1024 * 1024) {
            flash('error', 'File too large. Max 50MB.');
            redirect('/settings.php#backup');
        }

        $content = file_get_contents($file['tmp_name']);
        if (!$content) {
            flash('error', 'Could not read the backup file.');
            redirect('/settings.php#backup');
        }

        // Safety check — must be a TeaEstate backup
        if (strpos($content, 'TeaEstate Pro') === false) {
            flash('error', 'This does not appear to be a valid TeaEstate Pro backup file.');
            redirect('/settings.php#backup');
        }

        // Save a pre-restore backup first
        $preRestoreSQL  = generateSQLDump($backupTables, 'Pre-Restore Backup');
        $preRestoreFile = 'backup_manual_pre-restore_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($backupDir . $preRestoreFile, $preRestoreSQL);

        // Execute SQL statements
        try {
            $pdo = DB::connect();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $statements = array_filter(
                array_map('trim', preg_split('/;\s*\n/', $content)),
                fn($s) => strlen($s) > 3 && !str_starts_with($s, '--')
            );

            $errors = 0;
            foreach ($statements as $stmt) {
                try {
                    if (trim($stmt)) $pdo->exec($stmt);
                } catch (Exception $e) {
                    $errors++;
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            if ($errors > 0) {
                flash('error', 'Import completed with ' . $errors . ' warnings. A pre-restore backup was saved as: ' . $preRestoreFile);
            } else {
                flash('success', 'Database restored successfully! A pre-restore backup was saved as: ' . $preRestoreFile);
            }
        } catch (Exception $e) {
            flash('error', 'Import failed: ' . $e->getMessage());
        }
        redirect('/settings.php#backup');
    }
}

// Load current settings
$appName    = getSetting('app_name', APP_NAME, $estateId);
$appSub     = getSetting('app_sub', 'Estate Management', $estateId);
$logoFile   = getSetting('logo_file', '', $estateId);
$logoTs       = getSetting('logo_updated', '1', $estateId);
$themeColor   = getSetting('theme_color', '#2E6B12', $estateId);
$themeBg      = getSetting('theme_bg', '#0D2B0A', $estateId);
$themeAccent  = getSetting('theme_accent', '#4CAF50', $estateId);
$logoUrl    = $logoFile ? BASE_URL . '/assets/img/' . $logoFile . '?v=' . $logoTs : '';

$currentUser = DB::fetchOne("SELECT * FROM users WHERE id=?", [Auth::user()['id']]);
$allUsers    = DB::fetchAll("SELECT id, name, username, role, email, is_active FROM users ORDER BY role, name");

// Load work types
$workTypes = DB::fetchAll("SELECT * FROM work_types WHERE estate_id=? AND is_deleted=0 ORDER BY is_active DESC, id ASC", [$estateId]);

// ── BACKUP: auto backup status + saved backups list ──
$backupAutoFiles = glob($backupDir . 'backup_auto_*.sql') ?: [];
usort($backupAutoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
$lastAutoMonth  = $backupAutoFiles ? date('Y-m', filemtime($backupAutoFiles[0])) : null;
$autoBackupDue  = ($lastAutoMonth !== date('Y-m'));

$allBackups = glob($backupDir . 'backup_*.sql') ?: [];
usort($allBackups, fn($a,$b) => filemtime($b) - filemtime($a));

$dbSize = 0;
try {
    $sizeRow = DB::fetchOne("SELECT SUM(data_length + index_length) as size
        FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
    $dbSize = $sizeRow['size'] ?? 0;
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── SETTINGS PAGE STYLES ── */
.settings-grid {
  display: block;
}
.settings-nav {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  align-items: center;
  gap: 2px;
  background: #fff;
  border: 1px solid #e8ede5;
  border-radius: var(--radius-lg);
  padding: 6px;
  margin-bottom: 20px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  position: sticky;
  top: 80px;
  z-index: 5;
}
.settings-nav::-webkit-scrollbar { display: none; }
.snav-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 13px;
  font-weight: 500;
  color: var(--gray-600);
  cursor: pointer;
  border-bottom: 3px solid transparent;
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all .15s;
  white-space: nowrap;
  flex-shrink: 0;
}
.snav-item:hover  { background: var(--green-50); color: var(--green-800); }
.snav-item.active { background: var(--green-50); color: var(--green-800); border-bottom-color: var(--green-600); font-weight: 700; }
.snav-item i      { font-size: 17px; flex-shrink: 0; }
.snav-divider     { display: none; }
.settings-section {
  background: #fff;
  border: 1px solid #e8ede5;
  border-radius: var(--radius-lg);
  padding: 24px;
  margin-bottom: 20px;
}
.settings-section-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--green-900);
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid #f0f0eb;
}
.settings-section-title i { font-size: 20px; color: var(--green-600); }
.logo-preview { width:80px;height:80px;border-radius:12px;border:2px dashed #d8ddd5;display:flex;align-items:center;justify-content:center;overflow:hidden;background:var(--green-900); }
.logo-preview img { width:100%;height:100%;object-fit:contain;padding:6px; }
.logo-preview .no-logo { color:rgba(255,255,255,0.4);font-size:28px; }
.logo-current { display:flex;align-items:center;gap:16px;padding:14px;background:var(--gray-50);border-radius:var(--radius-md);margin-bottom:14px; }
.user-reset-row { display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f0f0eb;flex-wrap:wrap; }
.user-reset-row:last-child { border-bottom:none; }
.pw-input-wrap { position:relative; }
.pw-input-wrap input { padding-right:44px; }
.pw-toggle { position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:18px; }
.pw-toggle:hover { color:var(--green-600); }
.strength-bar { height:4px;background:#e8e8e8;border-radius:2px;margin-top:5px;overflow:hidden; }
.strength-fill { height:100%;border-radius:2px;transition:width .3s,background .3s;width:0; }
.badge-admin { background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700; }
.badge-supervisor { background:var(--teal-50);color:var(--teal-700);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700; }

/* ── BACKUP & RESTORE ── */
.backup-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:22px; }
.backup-card { background:#fff; border:1px solid #e8ede5; border-radius:var(--radius-lg); padding:20px; }
.backup-card-title { font-size:14px; font-weight:700; color:var(--green-900); display:flex; align-items:center; gap:8px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #f0f0eb; }
.backup-card-title i { font-size:18px; color:var(--green-600); }
.backup-row { display:flex; align-items:center; gap:10px; padding:10px; border-radius:var(--radius-md); border:1px solid #e8ede5; margin-bottom:8px; background:#fafaf8; }
.backup-row:hover { background:var(--green-50); border-color:var(--green-200); }
.backup-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:18px; }
.backup-info { flex:1; min-width:0; }
.backup-name { font-size:12px; font-weight:700; color:var(--green-900); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.backup-meta { font-size:11px; color:var(--gray-400); margin-top:2px; }
.tag-auto   { background:#dbeafe; color:#1e40af; padding:2px 7px; border-radius:10px; font-size:10px; font-weight:700; }
.tag-manual { background:var(--green-50); color:var(--green-700); padding:2px 7px; border-radius:10px; font-size:10px; font-weight:700; }
.tag-restore{ background:var(--amber-50); color:var(--amber-700); padding:2px 7px; border-radius:10px; font-size:10px; font-weight:700; }
.stat-mini { text-align:center; padding:12px; background:var(--gray-50); border-radius:var(--radius-md); }
.stat-mini .sval { font-size:18px; font-weight:700; color:var(--green-900); }
.stat-mini .slbl { font-size:11px; color:var(--gray-400); margin-top:2px; }
.auto-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.auto-due { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
.auto-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.drop-zone { border:2px dashed #d8ddd5; border-radius:var(--radius-lg); padding:28px; text-align:center; cursor:pointer; transition:all .15s; }
.drop-zone:hover, .drop-zone.dragover { border-color:var(--green-400); background:var(--green-50); }
.drop-zone i { font-size:36px; color:var(--green-400); display:block; margin-bottom:8px; }
.drop-zone p { font-size:13px; color:var(--gray-600); margin-bottom:10px; }

@media (max-width: 900px) {
  .backup-grid { grid-template-columns: 1fr !important; }
}

/* ── RESPONSIVE: Tablet (≤900px) ── */
@media (max-width: 900px) {
  .settings-section { padding: 16px; }
  .logo-current { flex-direction: column; align-items: flex-start; gap: 12px; }
}

/* ── RESPONSIVE: Phone (≤600px) ── */
@media (max-width: 600px) {
  .settings-nav { padding: 4px; margin-bottom: 12px; }
  .snav-item {
    padding: 8px 10px;
    font-size: 11px;
  }
  .snav-item i { font-size: 15px; }
  .settings-section { padding: 14px; margin-bottom: 14px; }
  .settings-section-title { font-size: 14px; padding-bottom: 10px; margin-bottom: 14px; }

  /* Stack all form rows */
  .settings-section .grid-form { grid-template-columns: 1fr !important; }

  /* Work type rows on mobile */
  .settings-section form { flex-wrap: wrap; gap: 8px; }
  .settings-section form > div { min-width: 100%; }

  /* User reset rows */
  .user-reset-row { gap: 8px; }
  .user-reset-row .btn { width: 100%; justify-content: center; margin-left: 0 !important; }

  /* Password section */
  .settings-section .pw-input-wrap { width: 100%; }
}
</style>

<div class="settings-grid">

<!-- HORIZONTAL TABS -->
<div class="settings-nav">
  <a href="#app"       class="snav-item active" onclick="return showTab('app',this)"       title="App Settings">
    <i class="ti ti-settings-2"></i><span> App Settings</span>
  </a>
  <a href="#profile"   class="snav-item" onclick="return showTab('profile',this)"          title="My Profile">
    <i class="ti ti-user-circle"></i><span> My Profile</span>
  </a>
  <a href="#password"  class="snav-item" onclick="return showTab('password',this)"         title="Change Password">
    <i class="ti ti-lock"></i><span> Change Password</span>
  </a>
  <a href="#worktypes" class="snav-item" onclick="return showTab('worktypes',this)"        title="Work Types & Prices">
    <i class="ti ti-tools"></i><span> Work Types</span>
  </a>
  <a href="#users"     class="snav-item" onclick="return showTab('users',this)"            title="Reset User Passwords">
    <i class="ti ti-users"></i><span> User Passwords</span>
  </a>
  <a href="#backup"    class="snav-item" onclick="return showTab('backup',this)"           title="Backup & Restore">
    <i class="ti ti-database-export"></i><span> Backup & Restore</span>
  </a>
</div>

<!-- RIGHT CONTENT -->
<div>

  <!-- ── APP SETTINGS ── -->
  <div class="settings-section" id="app">
    <div class="settings-section-title">
      <i class="ti ti-settings-2"></i> App Settings
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_app">

      <!-- Current logo preview -->
      <div style="margin-bottom:18px">
        <label style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:10px">App Logo</label>
        <div class="logo-current">
          <div class="logo-preview">
            <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" alt="Logo" id="logo-preview-img">
            <?php else: ?>
            <i class="ti ti-leaf no-logo" id="logo-default-icon"></i>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--green-900);margin-bottom:4px">
              <?= $logoUrl ? 'Logo uploaded' : 'No logo — using default leaf icon' ?>
            </div>
            <div style="font-size:12px;color:var(--gray-400);margin-bottom:10px">PNG, JPG, SVG or WEBP · Max 2MB · Recommended: 200×200px</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <label style="cursor:pointer" class="btn btn-secondary btn-sm">
                <i class="ti ti-upload"></i> Upload Logo
                <input type="file" name="logo" accept="image/*" style="display:none" onchange="previewLogo(this)">
              </label>
              <?php if ($logoUrl): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;color:var(--red-400)">
                <input type="checkbox" name="remove_logo" value="1" style="width:auto">
                Remove logo
              </label>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="grid-form" style="margin-bottom:20px">
        <div class="form-group">
          <label>App Name *</label>
          <input type="text" name="app_name" value="<?= sanitize($appName) ?>"
                 placeholder="e.g. TeaEstate Pro" required>
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Shown in browser title, sidebar and header</div>
        </div>
        <div class="form-group">
          <label>App Sub-title</label>
          <input type="text" name="app_sub" value="<?= sanitize($appSub) ?>"
                 placeholder="e.g. Estate Management">
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Shown below the app name in the sidebar</div>
        </div>
      </div>

      <!-- Live preview -->
      <div style="background:var(--green-900);border-radius:12px;padding:16px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
        <div id="preview-logo-wrap" style="width:40px;height:40px;background:var(--green-400);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden">
          <?php if ($logoUrl): ?>
          <img src="<?= $logoUrl ?>" style="width:100%;height:100%;object-fit:contain;padding:4px" id="sidebar-preview-img">
          <?php else: ?>
          <i class="ti ti-leaf" style="color:#fff;font-size:22px" id="sidebar-preview-icon"></i>
          <?php endif; ?>
        </div>
        <div>
          <div style="color:#fff;font-size:15px;font-weight:700" id="preview-appname"><?= sanitize($appName) ?></div>
          <div style="color:rgba(255,255,255,0.45);font-size:11px" id="preview-appsub"><?= sanitize($appSub) ?></div>
        </div>
        <div style="margin-left:auto;color:rgba(255,255,255,0.3);font-size:12px">Preview</div>
      </div>

      <!-- Estate Theme Colours -->
      <div style="margin-bottom:20px;padding:16px;background:var(--gray-50);border-radius:var(--radius-md);border:1px solid #e8ede5">
        <div style="font-size:13px;font-weight:700;color:var(--green-900);margin-bottom:12px;display:flex;align-items:center;gap:8px">
          <i class="ti ti-palette" style="color:var(--green-600)"></i> Estate Theme Colours
          <span style="font-weight:400;font-size:11px;color:var(--gray-400)">Customise this estate's sidebar and accent colours</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
          <div class="form-group" style="margin-bottom:0">
            <label>Sidebar Background</label>
            <div style="display:flex;align-items:center;gap:8px">
              <input type="color" name="theme_bg" value="<?= htmlspecialchars($themeBg) ?>"
                     style="width:44px;height:36px;padding:2px;border:1px solid #d8ddd5;border-radius:6px;cursor:pointer"
                     oninput="updateThemePreview()">
              <input type="text" value="<?= htmlspecialchars($themeBg) ?>" id="theme_bg_txt"
                     style="flex:1;font-size:12px;font-family:monospace"
                     oninput="document.querySelector('[name=theme_bg]').value=this.value;updateThemePreview()">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label>Primary / Accent</label>
            <div style="display:flex;align-items:center;gap:8px">
              <input type="color" name="theme_color" value="<?= htmlspecialchars($themeColor) ?>"
                     style="width:44px;height:36px;padding:2px;border:1px solid #d8ddd5;border-radius:6px;cursor:pointer"
                     oninput="updateThemePreview()">
              <input type="text" value="<?= htmlspecialchars($themeColor) ?>" id="theme_color_txt"
                     style="flex:1;font-size:12px;font-family:monospace"
                     oninput="document.querySelector('[name=theme_color]').value=this.value;updateThemePreview()">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label>Logo Background</label>
            <div style="display:flex;align-items:center;gap:8px">
              <input type="color" name="theme_accent" value="<?= htmlspecialchars($themeAccent) ?>"
                     style="width:44px;height:36px;padding:2px;border:1px solid #d8ddd5;border-radius:6px;cursor:pointer"
                     oninput="updateThemePreview()">
              <input type="text" value="<?= htmlspecialchars($themeAccent) ?>" id="theme_accent_txt"
                     style="flex:1;font-size:12px;font-family:monospace"
                     oninput="document.querySelector('[name=theme_accent]').value=this.value;updateThemePreview()">
            </div>
          </div>
        </div>
        <!-- Presets -->
        <div style="margin-top:12px">
          <div style="font-size:11px;font-weight:600;color:var(--gray-500);margin-bottom:8px">Quick Presets</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach([
              ['🌿 Forest',  '#0D2B0A','#2E6B12','#4CAF50'],
              ['🌊 Ocean',   '#0A1A2B','#1565C0','#42A5F5'],
              ['🌅 Amber',   '#1A0F00','#B45309','#F59E0B'],
              ['🌺 Rose',    '#1A0508','#9D174D','#F43F5E'],
              ['🏔 Slate',   '#0F172A','#334155','#64748B'],
              ['🌸 Purple',  '#160B1A','#6D28D9','#A78BFA'],
            ] as [$lbl,$bg,$pri,$acc]): ?>
            <button type="button"
              onclick="setTheme('<?= $bg ?>','<?= $pri ?>','<?= $acc ?>')"
              style="padding:5px 12px;border-radius:20px;border:1px solid #e8ede5;background:#fff;font-size:12px;cursor:pointer;transition:all .15s"
              onmouseover="this.style.background='var(--green-50)'" onmouseout="this.style.background='#fff'">
              <?= $lbl ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Save App Settings</button>
    </form>
  </div>

  <!-- ── MY PROFILE ── -->
  <div class="settings-section" id="profile" hidden>
    <div class="settings-section-title">
      <i class="ti ti-user-circle"></i> My Profile
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_profile">
      <div class="grid-form" style="margin-bottom:20px">
        <div class="form-group">
          <label>Display Name *</label>
          <input type="text" name="name" value="<?= sanitize($currentUser['name']) ?>"
                 placeholder="Your full name" required>
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Shown in the sidebar and throughout the app</div>
        </div>
        <div class="form-group">
          <label>Username</label>
          <input type="text" value="<?= sanitize($currentUser['username']) ?>" disabled
                 style="background:var(--gray-50);color:var(--gray-400)">
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Username cannot be changed</div>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" value="<?= sanitize($currentUser['email'] ?? '') ?>"
                 placeholder="your@email.com">
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="text" name="phone" value="<?= sanitize($currentUser['phone'] ?? '') ?>"
                 placeholder="+94 7X XXX XXXX">
        </div>
      </div>
      <!-- Profile preview -->
      <div style="background:var(--gray-50);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:10px">
        <div class="user-avatar" style="width:38px;height:38px;border-radius:50%;background:var(--green-600);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700">
          <?= initials($currentUser['name']) ?>
        </div>
        <div>
          <div style="font-size:13px;font-weight:700" id="profile-name-preview"><?= sanitize($currentUser['name']) ?></div>
          <div style="font-size:11px;color:var(--gray-400)"><?= sanitize($currentUser['email'] ?? 'No email set') ?> · <?= ucfirst($currentUser['role']) ?></div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Save Profile</button>
    </form>
  </div>

  <!-- ── CHANGE PASSWORD ── -->
  <div class="settings-section" id="password" hidden>
    <div class="settings-section-title">
      <i class="ti ti-lock"></i> Change My Password
    </div>
    <form method="POST" id="pw-form" style="max-width:400px">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label>Current Password *</label>
        <div class="pw-input-wrap">
          <input type="password" name="current_password" id="pw-current" placeholder="Enter current password" required>
          <button type="button" class="pw-toggle" onclick="togglePw('pw-current',this)"><i class="ti ti-eye"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label>New Password *</label>
        <div class="pw-input-wrap">
          <input type="password" name="new_password" id="pw-new" placeholder="Min. 6 characters"
                 required minlength="6" oninput="checkStrength(this.value,'sf1','sl1');checkMatch()">
          <button type="button" class="pw-toggle" onclick="togglePw('pw-new',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="sf1"></div></div>
        <div style="font-size:11px;margin-top:3px;color:var(--gray-400)" id="sl1"></div>
      </div>
      <div class="form-group">
        <label>Confirm New Password *</label>
        <div class="pw-input-wrap">
          <input type="password" name="confirm_password" id="pw-confirm" placeholder="Repeat new password"
                 required oninput="checkMatch()">
          <button type="button" class="pw-toggle" onclick="togglePw('pw-confirm',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div style="font-size:11px;margin-top:4px" id="match-label"></div>
      </div>
      <button type="submit" class="btn btn-primary" id="pw-submit">
        <i class="ti ti-lock-check"></i> Update Password
      </button>
    </form>
  </div>

  <!-- ── WORK TYPES & PRICES ── -->
  <div class="settings-section" id="worktypes" hidden>
    <div class="settings-section-title">
      <i class="ti ti-tools"></i> Work Types & Prices
      <span style="font-size:12px;font-weight:400;color:var(--gray-400);margin-left:4px">Price changes only affect future assignments</span>
    </div>

    <!-- Safety note -->
    <div style="background:var(--amber-50);border:1px solid var(--amber-200);border-radius:var(--radius-md);padding:11px 14px;margin-bottom:18px;font-size:13px;color:var(--amber-800);display:flex;align-items:center;gap:8px">
      <i class="ti ti-shield-check" style="font-size:16px;flex-shrink:0"></i>
      <span>Updating prices here <strong>never changes existing records</strong>. All past assignment payments stay exactly as they were recorded.</span>
    </div>

    <!-- Existing work types -->
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Current Work Types</div>
      <?php foreach ($workTypes as $wt): ?>
      <form method="POST" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border:1px solid #e8ede5;border-radius:var(--radius-md);margin-bottom:8px;background:<?= $wt['is_active']?'#fff':'var(--gray-50)' ?>;flex-wrap:wrap">
        <input type="hidden" name="action" value="update_rate">
        <input type="hidden" name="wt_id" value="<?= $wt['id'] ?>">
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:180px">
          <div style="width:34px;height:34px;border-radius:8px;background:<?= $wt['is_active']?'var(--green-50)':'var(--gray-50)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="ti ti-briefcase" style="color:<?= $wt['is_active']?'var(--green-600)':'var(--gray-400)' ?>;font-size:16px"></i>
          </div>
          <div style="flex:1;min-width:120px">
            <input type="text" name="wt_name" value="<?= sanitize($wt['name']) ?>" required
                   style="font-size:13px;font-weight:700;padding:5px 8px;border:1px solid #e8ede5;border-radius:6px;width:100%;<?= !$wt['is_active']?'color:var(--gray-400)':'' ?>">
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <input type="text" name="wt_unit" value="<?= sanitize($wt['unit_label']) ?>"
                 style="width:70px;font-size:12px;padding:5px 8px;border:1px solid #e8ede5;border-radius:6px;text-align:center" title="Unit label">
          <span style="font-size:12px;color:var(--gray-400)">Rs.</span>
          <input type="number" name="wt_rate" value="<?= $wt['rate_per_unit'] ?>" min="0" step="0.01" required
                 style="width:100px;font-size:14px;font-weight:700;padding:6px 10px;border:1.5px solid var(--amber-200);border-radius:var(--radius-md);background:var(--amber-50);color:var(--amber-800);text-align:right">
          <span style="font-size:11px;color:var(--gray-400);white-space:nowrap">/ <?= sanitize($wt['unit_label']) ?></span>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button type="submit" class="btn btn-primary btn-sm" title="Save changes">
            <i class="ti ti-check"></i> Save
          </button>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $wt['is_active']?'Deactivate':'Activate' ?> this work type?')">
            <input type="hidden" name="action" value="toggle_work_type">
            <input type="hidden" name="wt_id" value="<?= $wt['id'] ?>">
            <input type="hidden" name="wt_current" value="<?= $wt['is_active'] ?>">
            <button type="submit" class="btn btn-outline btn-sm" style="color:<?= $wt['is_active']?'var(--amber-600)':'var(--green-600)' ?>">
              <i class="ti ti-<?= $wt['is_active']?'eye-off':'eye' ?>"></i>
              <?= $wt['is_active']?'Disable':'Enable' ?>
            </button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this work type? It will be removed from this list, but all existing assignment records that use it are kept exactly as they are.')">
            <input type="hidden" name="action" value="delete_work_type">
            <input type="hidden" name="wt_id" value="<?= $wt['id'] ?>">
            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-600)" title="Delete work type (keeps existing records)">
              <i class="ti ti-trash"></i> Delete
            </button>
          </form>
        </div>
      </form>
      <?php endforeach; ?>
    </div>

    <!-- Add new work type -->
    <div style="border:2px dashed var(--green-200);border-radius:var(--radius-lg);padding:18px;background:var(--green-50)">
      <div style="font-size:13px;font-weight:700;color:var(--green-900);margin-bottom:14px;display:flex;align-items:center;gap:8px">
        <i class="ti ti-plus" style="color:var(--green-600)"></i> Add New Work Type
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_work_type">
        <div class="grid-form" style="margin-bottom:14px">
          <div class="form-group">
            <label>Work Type Name *</label>
            <input type="text" name="wt_name" placeholder="e.g. Weeding, Pruning..." required>
          </div>
          <div class="form-group">
            <label>Unit Label *</label>
            <input type="text" name="wt_unit" placeholder="KG / Day / Unit / Tank / Tree" value="Unit">
            <div style="font-size:11px;color:var(--gray-400);margin-top:3px">What you measure (KG, Day, Unit, Tank, Tree...). Tank/Tree types get a manual per-worker quantity input, like Tank Spraying.</div>
          </div>
          <div class="form-group">
            <label>Rate per Unit (Rs.) *</label>
            <input type="number" name="wt_rate" placeholder="e.g. 500" min="0" step="0.01" required>
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" name="wt_desc" placeholder="Optional description">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-plus"></i> Add Work Type
        </button>
      </form>
    </div>
  </div>

  <!-- ── RESET USER PASSWORDS ── -->
  <div class="settings-section" id="users" hidden>
    <div class="settings-section-title">
      <i class="ti ti-users"></i> Reset User Passwords
      <span style="font-size:12px;font-weight:400;color:var(--gray-400);margin-left:4px">Admin only</span>
    </div>
    <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px">Reset the password for any user account. The user should change it after logging in.</p>

    <?php foreach ($allUsers as $u): ?>
    <div class="user-reset-row">
      <div class="avatar" style="flex-shrink:0"><?= initials($u['name']) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:700"><?= sanitize($u['name']) ?></div>
        <div style="font-size:11px;color:var(--gray-400)">
          @<?= sanitize($u['username']) ?> ·
          <span class="<?= $u['role']==='admin'?'badge-admin':'badge-supervisor' ?>"><?= ucfirst($u['role']) ?></span>
          <?php if (!$u['is_active']): ?> · <span style="color:var(--red-400);font-size:11px">Inactive</span><?php endif; ?>
        </div>
      </div>
      <button type="button" class="btn btn-outline btn-sm"
              onclick="openResetModal(<?= $u['id'] ?>,'<?= sanitize($u['name']) ?>')">
        <i class="ti ti-key"></i> Reset Password
      </button>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── BACKUP & RESTORE ── -->
  <div class="settings-section" id="backup" hidden>
    <div class="settings-section-title">
      <i class="ti ti-database-export"></i> Backup & Restore
    </div>

    <!-- TOP STATS -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px">
      <div class="stat-mini">
        <div class="sval"><?= count($allBackups) ?></div>
        <div class="slbl">Total Backups</div>
      </div>
      <div class="stat-mini">
        <div class="sval"><?= count(array_filter($allBackups, fn($f)=>strpos(basename($f),'auto')!==false)) ?></div>
        <div class="slbl">Auto Backups</div>
      </div>
      <div class="stat-mini">
        <div class="sval"><?= count(array_filter($allBackups, fn($f)=>strpos(basename($f),'manual')!==false)) ?></div>
        <div class="slbl">Manual Backups</div>
      </div>
      <div class="stat-mini">
        <div class="sval"><?= formatBytes($dbSize) ?></div>
        <div class="slbl">Database Size</div>
      </div>
    </div>

    <!-- AUTO BACKUP STATUS -->
    <div style="background:var(--gray-50);border:1px solid #e8ede5;border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="flex:1">
        <div style="font-size:14px;font-weight:700;color:var(--green-900);margin-bottom:4px;display:flex;align-items:center;gap:8px">
          <i class="ti ti-calendar-repeat" style="color:var(--green-600)"></i> Monthly Auto Backup
        </div>
        <div style="font-size:12px;color:var(--gray-500)">
          Automatically backs up your database once per month. Keeps only the last 2 auto backups.
          <?php if ($lastAutoMonth): ?>
          Last auto backup: <strong><?= date('F Y', strtotime($lastAutoMonth . '-01')) ?></strong>.
          <?php else: ?>
          <strong>No auto backup yet.</strong>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="auto-badge <?= $autoBackupDue ? 'auto-due' : 'auto-ok' ?>">
          <i class="ti ti-<?= $autoBackupDue ? 'clock' : 'circle-check' ?>"></i>
          <?= $autoBackupDue ? 'Backup due for ' . date('F Y') : 'Up to date for ' . date('F Y') ?>
        </span>
        <?php if ($autoBackupDue): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="run_autobackup">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="ti ti-player-play"></i> Run Now
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="backup-grid">

      <!-- LEFT: CREATE & EXPORT -->
      <div>
        <!-- Create Manual Backup -->
        <div class="backup-card" style="margin-bottom:18px">
          <div class="backup-card-title"><i class="ti ti-device-floppy"></i> Create Manual Backup</div>
          <form method="POST">
            <input type="hidden" name="action" value="create_backup">
            <div class="form-group" style="margin-bottom:14px">
              <label>Backup Label</label>
              <input type="text" name="label" value="Manual Backup <?= date('d M Y') ?>"
                     placeholder="e.g. Before payroll update">
              <div style="font-size:11px;color:var(--gray-400);margin-top:3px">Optional note for this backup</div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:10px">
              <i class="ti ti-device-floppy"></i> Save Backup to Server
            </button>
          </form>
          <form method="POST">
            <input type="hidden" name="action" value="export_now">
            <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center">
              <i class="ti ti-download"></i> Download Backup Now (no save)
            </button>
          </form>
        </div>

        <!-- Import / Restore -->
        <div class="backup-card">
          <div class="backup-card-title" style="color:var(--red-600)">
            <i class="ti ti-upload" style="color:var(--red-400)"></i> Restore from Backup
          </div>
          <div style="background:var(--red-50);border:1px solid #fca5a5;border-radius:var(--radius-md);padding:10px 12px;margin-bottom:14px;font-size:12px;color:var(--red-600)">
            <i class="ti ti-alert-triangle"></i> <strong>Warning:</strong> Restoring will overwrite your current data. A pre-restore backup is automatically saved before any import.
          </div>
          <form method="POST" enctype="multipart/form-data" id="import-form">
            <input type="hidden" name="action" value="import">
            <div class="drop-zone" onclick="document.getElementById('backup-file').click()"
                 ondragover="this.classList.add('dragover');event.preventDefault()"
                 ondragleave="this.classList.remove('dragover')"
                 ondrop="handleDrop(event)">
              <i class="ti ti-file-type-sql"></i>
              <p id="drop-label">Drag & drop your .sql backup file here<br>or click to browse</p>
              <input type="file" name="backup_file" id="backup-file" accept=".sql" style="display:none"
                     onchange="updateDropLabel(this)">
            </div>
            <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center;margin-top:12px"
                    onclick="return confirm('⚠️ This will overwrite ALL current data with the backup. A pre-restore backup will be saved first. Continue?')">
              <i class="ti ti-restore"></i> Restore Database
            </button>
          </form>
        </div>
      </div>

      <!-- RIGHT: BACKUP LIST -->
      <div class="backup-card">
        <div class="backup-card-title">
          <i class="ti ti-history"></i> Saved Backups
          <span style="margin-left:auto;font-size:12px;font-weight:400;color:var(--gray-400)"><?= count($allBackups) ?> file(s)</span>
        </div>

        <?php if (!$allBackups): ?>
        <div class="empty-state">
          <i class="ti ti-database-off"></i>
          <p>No backups yet.<br>Create your first backup using the form.</p>
        </div>
        <?php else: ?>

        <div style="max-height:520px;overflow-y:auto">
          <?php foreach ($allBackups as $f):
            $fname    = basename($f);
            $fsize    = filesize($f);
            $ftime    = filemtime($f);
            $isAuto   = strpos($fname, '_auto_')    !== false;
            $isManual = strpos($fname, '_manual_')  !== false;
            $isRestore= strpos($fname, 'pre-restore') !== false;
          ?>
          <div class="backup-row">
            <div class="backup-icon" style="background:<?= $isAuto?'#dbeafe':($isRestore?'var(--amber-50)':'var(--green-50)') ?>">
              <i class="ti ti-<?= $isAuto?'calendar-repeat':($isRestore?'shield-check':'device-floppy') ?>"
                 style="color:<?= $isAuto?'#2563EB':($isRestore?'var(--amber-600)':'var(--green-600)') ?>"></i>
            </div>
            <div class="backup-info">
              <div class="backup-name"><?= sanitize($fname) ?></div>
              <div class="backup-meta">
                <?= date('d M Y, H:i', $ftime) ?> ·
                <?= formatBytes($fsize) ?> ·
                <?php if ($isAuto): ?><span class="tag-auto">AUTO</span>
                <?php elseif ($isRestore): ?><span class="tag-restore">PRE-RESTORE</span>
                <?php else: ?><span class="tag-manual">MANUAL</span><?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0">
              <!-- Download -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="download">
                <input type="hidden" name="file" value="<?= sanitize($fname) ?>">
                <button type="submit" class="btn btn-outline btn-sm" title="Download">
                  <i class="ti ti-download"></i>
                </button>
              </form>
              <!-- Delete -->
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Delete this backup permanently?')">
                <input type="hidden" name="action" value="delete_backup">
                <input type="hidden" name="file" value="<?= sanitize($fname) ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)" title="Delete">
                  <i class="ti ti-trash"></i>
                </button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f0f0eb;font-size:12px;color:var(--gray-400)">
          <i class="ti ti-info-circle" style="font-size:13px;vertical-align:-1px"></i>
          Keeps last 2 auto backups and last 2 manual backups automatically.
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- CRON SETUP INFO -->
    <div class="card" style="margin-bottom:0">
      <div class="card-header">
        <div class="card-title"><i class="ti ti-terminal"></i> Setup Monthly Auto Backup (cPanel Cron Job)</div>
      </div>
      <p style="font-size:13px;color:var(--gray-500);margin-bottom:14px">
        To run auto backup automatically every month, set up a cron job in cPanel. This runs silently in the background on the 1st of every month.
      </p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Step 1 — cPanel Cron Job Settings</div>
          <table style="width:100%;font-size:13px;border-collapse:collapse">
            <tr><td style="padding:4px 8px 4px 0;color:var(--gray-500);width:80px">Minute</td><td><code style="background:var(--gray-50);padding:2px 8px;border-radius:4px">0</code></td></tr>
            <tr><td style="padding:4px 8px 4px 0;color:var(--gray-500)">Hour</td><td><code style="background:var(--gray-50);padding:2px 8px;border-radius:4px">2</code></td></tr>
            <tr><td style="padding:4px 8px 4px 0;color:var(--gray-500)">Day</td><td><code style="background:var(--gray-50);padding:2px 8px;border-radius:4px">1</code></td></tr>
            <tr><td style="padding:4px 8px 4px 0;color:var(--gray-500)">Month</td><td><code style="background:var(--gray-50);padding:2px 8px;border-radius:4px">*</code></td></tr>
            <tr><td style="padding:4px 8px 4px 0;color:var(--gray-500)">Weekday</td><td><code style="background:var(--gray-50);padding:2px 8px;border-radius:4px">*</code></td></tr>
          </table>
        </div>
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Step 2 — Command</div>
          <code id="cron-cmd" style="display:block;background:var(--green-900);color:var(--green-100);padding:12px;border-radius:var(--radius-md);font-size:12px;word-break:break-all;margin-bottom:8px">
            /usr/bin/php <?= __DIR__ ?>/cron_backup.php
          </code>
          <button onclick="copyCron()" class="btn btn-outline btn-sm">
            <i class="ti ti-copy" id="cron-copy-icon"></i> <span id="cron-copy-label">Copy Command</span>
          </button>
        </div>
      </div>
    </div>
  </div>

</div><!-- right content -->
</div><!-- settings-grid -->

<!-- RESET USER PASSWORD MODAL -->
<div class="modal-overlay" id="modal-reset-user" onclick="if(event.target===this)closeModal('modal-reset-user')">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-title"><i class="ti ti-key"></i> Reset Password</div>
    <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px">
      Setting new password for: <strong id="reset-user-name"></strong>
    </p>
    <form method="POST" id="reset-user-form">
      <input type="hidden" name="action" value="reset_user_password">
      <input type="hidden" name="user_id" id="reset-user-id">
      <div class="form-group">
        <label>New Password *</label>
        <div class="pw-input-wrap">
          <input type="password" name="new_password" id="ru-pw" placeholder="Min. 6 characters"
                 required minlength="6" oninput="checkStrength(this.value,'sf2','sl2')">
          <button type="button" class="pw-toggle" onclick="togglePw('ru-pw',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="sf2"></div></div>
        <div style="font-size:11px;margin-top:3px;color:var(--gray-400)" id="sl2"></div>
      </div>
      <div class="btn-group" style="margin-top:8px">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Set Password</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-reset-user')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// Theme preview update
function updateThemePreview() {
  var bg  = document.querySelector('[name=theme_bg]')?.value    || '#0D2B0A';
  var pri = document.querySelector('[name=theme_color]')?.value || '#2E6B12';
  var acc = document.querySelector('[name=theme_accent]')?.value|| '#4CAF50';
  // Update sidebar preview box
  var prev = document.getElementById('preview-logo-wrap');
  if (prev) prev.style.background = acc;
  var previewBox = prev?.closest('[style*="background:var(--green-900)"]');
  if (previewBox) previewBox.style.background = bg;
  // Update text boxes
  var bgT = document.getElementById('theme_bg_txt');
  var priT= document.getElementById('theme_color_txt');
  var accT= document.getElementById('theme_accent_txt');
  if(bgT)  bgT.value  = bg;
  if(priT) priT.value = pri;
  if(accT) accT.value = acc;
}
function setTheme(bg, pri, acc) {
  document.querySelector('[name=theme_bg]').value     = bg;
  document.querySelector('[name=theme_color]').value  = pri;
  document.querySelector('[name=theme_accent]').value = acc;
  updateThemePreview();
}

// Live preview: app name & sub
document.querySelector('[name=app_name]')?.addEventListener('input', function() {
  document.getElementById('preview-appname').textContent = this.value || 'App Name';
});
document.querySelector('[name=app_sub]')?.addEventListener('input', function() {
  document.getElementById('preview-appsub').textContent = this.value;
});

// Live preview logo
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    // Update logo preview box
    var prevBox = document.querySelector('.logo-preview');
    prevBox.innerHTML = '<img src="'+e.target.result+'" alt="Logo" style="width:100%;height:100%;object-fit:contain;padding:6px">';
    // Update sidebar preview
    var wrap = document.getElementById('preview-logo-wrap');
    wrap.innerHTML = '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:contain;padding:4px">';
  };
  reader.readAsDataURL(input.files[0]);
}

// Toggle password visibility
function togglePw(id, btn) {
  var inp  = document.getElementById(id);
  var icon = btn.querySelector('i');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-off';
}

// Password strength
function checkStrength(val, fillId, labelId) {
  var fill  = document.getElementById(fillId);
  var label = document.getElementById(labelId);
  if (!fill) return;
  var score = 0;
  if (val.length >= 6)  score++;
  if (val.length >= 10) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  var pct   = (score / 5) * 100;
  var color = score <= 1 ? '#ef4444' : score <= 2 ? '#f59e0b' : score <= 3 ? '#3b82f6' : '#22c55e';
  var text  = score <= 1 ? 'Weak' : score <= 2 ? 'Fair' : score <= 3 ? 'Good' : 'Strong';
  fill.style.width      = pct + '%';
  fill.style.background = color;
  label.textContent     = val.length > 0 ? text : '';
  label.style.color     = color;
}

// Password match
function checkMatch() {
  var p1    = document.getElementById('pw-new');
  var p2    = document.getElementById('pw-confirm');
  var label = document.getElementById('match-label');
  var btn   = document.getElementById('pw-submit');
  if (!p2 || !p2.value) { if(label) label.textContent=''; return; }
  var match = p1.value === p2.value;
  label.textContent = match ? '✔ Passwords match' : '✘ Passwords do not match';
  label.style.color = match ? '#22c55e' : '#ef4444';
  if (btn) btn.disabled = !match;
}

// Open reset modal
function openResetModal(uid, name) {
  document.getElementById('reset-user-id').value   = uid;
  document.getElementById('reset-user-name').textContent = name;
  document.getElementById('ru-pw').value = '';
  document.getElementById('sf2').style.width = '0';
  document.getElementById('sl2').textContent = '';
  openModal('modal-reset-user');
}

// Tab switching
function showTab(id, el) {
  document.querySelectorAll('.settings-section').forEach(s => { s.hidden = (s.id !== id); });
  document.querySelectorAll('.snav-item').forEach(i => i.classList.remove('active'));
  if (!el) el = document.querySelector('.snav-item[href="#' + id + '"]');
  if (el) el.classList.add('active');
  if (history.replaceState) history.replaceState(null, '', '#' + id);
  return false;
}

// Backup: file drop zone
function updateDropLabel(input) {
  if (input.files && input.files[0]) {
    document.getElementById('drop-label').textContent = '✔ ' + input.files[0].name;
  }
}
function handleDrop(e) {
  e.preventDefault();
  document.querySelector('.drop-zone').classList.remove('dragover');
  var file = e.dataTransfer.files[0];
  if (file) {
    document.getElementById('backup-file').files = e.dataTransfer.files;
    document.getElementById('drop-label').textContent = '✔ ' + file.name;
  }
}
function copyCron() {
  var cmd = document.getElementById('cron-cmd').textContent.trim();
  navigator.clipboard.writeText(cmd).then(function() {
    document.getElementById('cron-copy-icon').className  = 'ti ti-check';
    document.getElementById('cron-copy-label').textContent = 'Copied!';
    setTimeout(function() {
      document.getElementById('cron-copy-icon').className  = 'ti ti-copy';
      document.getElementById('cron-copy-label').textContent = 'Copy Command';
    }, 2500);
  });
}

// Open the tab named in the URL hash on load, else default to the first tab
(function() {
  var sections = ['app','profile','password','worktypes','users','backup'];
  var id = window.location.hash ? window.location.hash.slice(1) : '';
  if (sections.indexOf(id) === -1) id = 'app';
  showTab(id);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
