<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Backup & Restore';
Auth::requireAdmin();

// Backup directory
$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

// Protect backup dir with .htaccess
$htaccess = $backupDir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
}

// Tables to backup
$tables = ['users','workers','plantations','work_types','daily_assignments',
           'fertilizer_cycles','expenses','app_settings'];

// ── GENERATE SQL DUMP ──────────────────────────────────────────
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

// ── CLEANUP OLD BACKUPS — keep only last 2 months ──────────────
function cleanOldBackups($backupDir) {
    $files = glob($backupDir . 'backup_*.sql');
    if (!$files || count($files) <= 2) return 0;

    // Sort by modification time descending
    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));

    // Keep latest 2 per type (manual + auto separately)
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

// ── HANDLE ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Manual backup
    if ($action === 'create_backup') {
        $label    = trim($_POST['label'] ?? 'Manual Backup');
        $sql      = generateSQLDump($tables, $label);
        $filename = 'backup_manual_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;
        file_put_contents($filepath, $sql);
        cleanOldBackups($backupDir);
        flash('success', 'Backup created: ' . $filename);
        redirect('/backup.php');
    }

    // Download existing backup
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
        redirect('/backup.php');
    }

    // Delete backup
    if ($action === 'delete_backup') {
        $file = basename($_POST['file'] ?? '');
        $path = $backupDir . $file;
        if ($file && file_exists($path)) {
            unlink($path);
            flash('success', 'Backup deleted.');
        }
        redirect('/backup.php');
    }

    // Export now (direct download without saving)
    if ($action === 'export_now') {
        $sql      = generateSQLDump($tables, 'Direct Export');
        $filename = 'teaestate_export_' . date('Y-m-d_H-i-s') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }

    // Auto backup trigger (called by cron or monthly check)
    if ($action === 'run_autobackup') {
        $lastAutoBackup = '';
        $autoFiles = glob($backupDir . 'backup_auto_*.sql');
        if ($autoFiles) {
            usort($autoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
            $lastAutoBackup = date('Y-m', filemtime($autoFiles[0]));
        }
        $currentMonth = date('Y-m');
        if ($lastAutoBackup !== $currentMonth) {
            $sql      = generateSQLDump($tables, 'Auto Backup - ' . date('F Y'));
            $filename = 'backup_auto_' . date('Y-m-d_H-i-s') . '.sql';
            file_put_contents($backupDir . $filename, $sql);
            $deleted  = cleanOldBackups($backupDir);
            flash('success', 'Auto backup created for ' . date('F Y') . '. Cleaned ' . $deleted . ' old backup(s).');
        } else {
            flash('success', 'Auto backup already exists for this month.');
        }
        redirect('/backup.php');
    }

    // Import / Restore
    if ($action === 'import') {
        if (empty($_FILES['backup_file']['name'])) {
            flash('error', 'Please select a backup file to import.');
            redirect('/backup.php');
        }
        $file = $_FILES['backup_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            flash('error', 'Only .sql files are allowed.');
            redirect('/backup.php');
        }
        if ($file['size'] > 50 * 1024 * 1024) {
            flash('error', 'File too large. Max 50MB.');
            redirect('/backup.php');
        }

        $content = file_get_contents($file['tmp_name']);
        if (!$content) {
            flash('error', 'Could not read the backup file.');
            redirect('/backup.php');
        }

        // Safety check — must be a TeaEstate backup
        if (strpos($content, 'TeaEstate Pro') === false) {
            flash('error', 'This does not appear to be a valid TeaEstate Pro backup file.');
            redirect('/backup.php');
        }

        // Save a pre-restore backup first
        $preRestoreSQL  = generateSQLDump($tables, 'Pre-Restore Backup');
        $preRestoreFile = 'backup_manual_pre-restore_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($backupDir . $preRestoreFile, $preRestoreSQL);

        // Execute SQL statements
        try {
            $pdo = DB::connect();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Split SQL into statements
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
        redirect('/backup.php');
    }
}

// ── AUTO BACKUP CHECK — trigger if new month ──────────────────
$autoFiles = glob($backupDir . 'backup_auto_*.sql') ?: [];
usort($autoFiles, fn($a,$b) => filemtime($b) - filemtime($a));
$lastAutoMonth  = $autoFiles ? date('Y-m', filemtime($autoFiles[0])) : null;
$autoBackupDue  = ($lastAutoMonth !== date('Y-m'));

// ── LIST ALL BACKUPS ──────────────────────────────────────────
$allBackups = glob($backupDir . 'backup_*.sql') ?: [];
usort($allBackups, fn($a,$b) => filemtime($b) - filemtime($a));

// Calculate DB size
$dbSize = 0;
try {
    $sizeRow = DB::fetchOne("SELECT SUM(data_length + index_length) as size
        FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
    $dbSize = $sizeRow['size'] ?? 0;
} catch (Exception $e) {}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 2) . ' MB';
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
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
</style>

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
<div style="background:#fff;border:1px solid #e8ede5;border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
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
<div class="card" style="margin-bottom:16px">
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

<script>
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
