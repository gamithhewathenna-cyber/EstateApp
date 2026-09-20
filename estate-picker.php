<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check();
$user = Auth::user();

// Get estates this user has access to
$estates = DB::fetchAll("SELECT e.*, ue.role as estate_role, ue.is_default
    FROM estates e
    JOIN user_estates ue ON e.id = ue.estate_id
    WHERE ue.user_id = ? AND e.is_active = 1
    ORDER BY ue.is_default DESC, e.name ASC", [$user['id']]);

// Explicit switch, submitted by the "Switch Estate" dropdown in the topbar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estateId = (int)($_POST['estate_id'] ?? 0);
    // Verify user has access
    $valid = DB::fetchOne("SELECT e.id, e.name, ue.role as estate_role
        FROM estates e JOIN user_estates ue ON e.id=ue.estate_id
        WHERE ue.user_id=? AND e.id=? AND e.is_active=1", [$user['id'], $estateId]);
    if ($valid) {
        unset($_SESSION['active_estate_id'], $_SESSION['active_estate_name'], $_SESSION['active_estate_role']);
        $_SESSION['active_estate_id']   = (int)$valid['id'];
        $_SESSION['active_estate_name'] = $valid['name'];
        $_SESSION['active_estate_role'] = $valid['estate_role'];
        session_write_close();
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

// No manual selection screen: automatically open the primary (default) estate.
// $estates is already ordered is_default DESC, so $estates[0] is the primary
// estate, or the alphabetically-first one if none is flagged as default.
if (count($estates) >= 1) {
    $_SESSION['active_estate_id']   = (int)$estates[0]['id'];
    $_SESSION['active_estate_name'] = $estates[0]['name'];
    $_SESSION['active_estate_role'] = $estates[0]['estate_role'];
    session_write_close();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// No estate assigned to this user at all — show a minimal fallback message.
unset($_SESSION['active_estate_id'], $_SESSION['active_estate_name'], $_SESSION['active_estate_role']);

// Load app settings
try {
    $sr = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=1", []);
    $as = array_column($sr,'value','key');
} catch(Exception $e){ $as=[]; }
$appName = $as['app_name'] ?? APP_NAME;
$logoFile= $as['logo_file'] ?? '';
$logoUrl = $logoFile ? BASE_URL.'/assets/img/'.$logoFile.'?v=1' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>No Estate Assigned — <?= sanitize($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
body { background: var(--green-900); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.picker-wrap { width:100%; max-width:420px; animation:fadeInPage .5s ease-out; text-align:center; }
.picker-logo { width:56px;height:56px;background:var(--green-400);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;overflow:hidden; }
.picker-logo img { width:100%;height:100%;object-fit:contain;padding:6px; }
.picker-logo i { color:#fff;font-size:30px; }
.picker-title { color:#fff;font-size:20px;font-weight:700;margin-bottom:8px; }
.picker-sub   { color:rgba(255,255,255,0.6);font-size:13px;line-height:1.6;margin-bottom:24px; }
.logout-link a { color:rgba(255,255,255,0.6);font-size:13px;text-decoration:none;background:rgba(255,255,255,0.1);padding:8px 18px;border-radius:8px; }
.logout-link a:hover { color:#fff;background:rgba(255,255,255,0.18); }
</style>
</head>
<body>
<div class="picker-wrap">
  <div class="picker-logo">
    <?php if($logoUrl): ?><img src="<?= $logoUrl ?>" alt="Logo">
    <?php else: ?><i class="ti ti-leaf"></i><?php endif; ?>
  </div>
  <div class="picker-title">No Estate Assigned</div>
  <div class="picker-sub">Hi <?= sanitize($user['name']) ?>, your account isn't linked to any estate yet.<br>Please contact an administrator to get access.</div>
  <div class="logout-link">
    <a href="logout.php"><i class="ti ti-logout" style="font-size:12px;vertical-align:-1px"></i> Logout</a>
  </div>
</div>
</body>
</html>
