<?php
// ============================================
// TeaEstate Pro - Login Diagnose Tool
// Upload to your website root, visit it once, then DELETE it!
// ============================================

// ---- CHANGE THESE ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');    // e.g. cpses_teaestate
define('DB_USER', 'your_database_user');    // e.g. cpses_youruser
define('DB_PASS', 'your_database_password');// your DB password
// ----------------------

$steps = [];

// Step 1: DB Connection
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $steps[] = ['ok', 'Database connected successfully to: <strong>'.DB_NAME.'</strong>'];
} catch (PDOException $e) {
    $steps[] = ['fail', 'Database connection FAILED: ' . $e->getMessage()];
    goto render;
}

// Step 2: Users table exists
try {
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $steps[] = ['ok', "Users table found. Total users: <strong>$count</strong>"];
} catch (Exception $e) {
    $steps[] = ['fail', 'Users table missing or error: ' . $e->getMessage()];
    goto render;
}

// Step 3: Show all users
$users = $pdo->query("SELECT id, name, username, role, is_active, LEFT(password,20) as pass_preview FROM users")->fetchAll(PDO::FETCH_ASSOC);
if ($users) {
    $rows = '';
    foreach ($users as $u) {
        $isHashed = str_starts_with($pdo->query("SELECT password FROM users WHERE id=".$u['id'])->fetchColumn(), '$2y$');
        $hashStatus = $isHashed ? '<span style="color:green">✔ Properly hashed</span>' : '<span style="color:red">✘ NOT hashed (plain text!)</span>';
        $activeStatus = $u['is_active'] ? '<span style="color:green">Active</span>' : '<span style="color:red">Inactive</span>';
        $rows .= "<tr><td>{$u['id']}</td><td>{$u['name']}</td><td><strong>{$u['username']}</strong></td><td>{$u['role']}</td><td>$activeStatus</td><td>$hashStatus</td></tr>";
    }
    $steps[] = ['info', "Users found:<br><table border='1' cellpadding='6' style='border-collapse:collapse;margin-top:8px;font-size:13px'>
        <tr style='background:#f0f0f0'><th>ID</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Password</th></tr>
        $rows</table>"];
} else {
    $steps[] = ['fail', 'No users found in the users table! You need to insert users.'];
}

// Step 4: Fix passwords - set both to: Login@2026
$newPass = password_hash('Login@2026', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password=?, is_active=1 WHERE username='admin'")->execute([$newPass]);
$pdo->prepare("UPDATE users SET password=?, is_active=1 WHERE username='supervisor'")->execute([$newPass]);
$steps[] = ['ok', 'Passwords AUTO-FIXED for admin and supervisor → New password: <strong>Login@2026</strong>'];

// Step 5: Verify the hash works
$user = $pdo->query("SELECT password FROM users WHERE username='admin'")->fetchColumn();
if (password_verify('Login@2026', $user)) {
    $steps[] = ['ok', 'Password verification test PASSED ✔ — You can now login!'];
} else {
    $steps[] = ['fail', 'Password verification FAILED — something is wrong with PHP password_hash'];
}

// Step 6: Check config.php
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) {
    $steps[] = ['ok', 'includes/config.php found'];
    $configContent = file_get_contents($configPath);
    if (strpos($configContent, DB_NAME) !== false) {
        $steps[] = ['ok', 'config.php contains the correct DB name'];
    } else {
        $steps[] = ['warn', 'config.php may have WRONG DB name — please check it matches: <strong>'.DB_NAME.'</strong>'];
    }
} else {
    $steps[] = ['fail', 'includes/config.php NOT FOUND — wrong upload location?'];
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TeaEstate – Diagnose</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #173404; min-height: 100vh; padding: 30px 16px; }
.wrap { max-width: 680px; margin: 0 auto; }
h1 { color: #fff; font-size: 20px; margin-bottom: 6px; }
.sub { color: rgba(255,255,255,0.5); font-size: 13px; margin-bottom: 24px; }
.step { background: #fff; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; display: flex; gap: 12px; align-items: flex-start; font-size: 13px; line-height: 1.6; }
.icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.ok   { border-left: 4px solid #22c55e; }
.fail { border-left: 4px solid #ef4444; }
.warn { border-left: 4px solid #f59e0b; }
.info { border-left: 4px solid #3b82f6; }
.result-box { background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 12px; padding: 20px; margin-top: 20px; text-align: center; }
.result-box h2 { color: #065f46; font-size: 18px; margin-bottom: 8px; }
.result-box .creds { font-size: 22px; font-weight: 700; color: #047857; margin: 10px 0; letter-spacing: 1px; }
.result-box p { font-size: 13px; color: #065f46; }
.delete-warn { background: #fee2e2; border: 2px solid #fca5a5; border-radius: 10px; padding: 14px 16px; margin-top: 16px; color: #991b1b; font-size: 13px; font-weight: 600; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🔍 TeaEstate – Login Diagnose & Fix</h1>
  <div class="sub">Running checks and auto-fixing login issues...</div>

  <?php foreach ($steps as [$type, $msg]): ?>
  <div class="step <?= $type ?>">
    <div class="icon"><?= $type==='ok'?'✅':($type==='fail'?'❌':($type==='warn'?'⚠️':'ℹ️')) ?></div>
    <div><?= $msg ?></div>
  </div>
  <?php endforeach; ?>

  <div class="result-box">
    <h2>🎉 Try logging in now!</h2>
    <div class="creds">Username: admin</div>
    <div class="creds">Password: Login@2026</div>
    <p>After logging in, go to <strong>User Management</strong> and change your password.</p>
  </div>

  <div class="delete-warn">
    🗑️ DELETE this file from your server right now!<br>
    cPanel File Manager → find diagnose.php → Delete
  </div>
</div>
</body>
</html>
