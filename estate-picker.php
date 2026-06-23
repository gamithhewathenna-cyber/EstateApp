<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check();
$user = Auth::user();

// Clear previous estate session to force fresh load on this page
unset($_SESSION['active_estate_id'], $_SESSION['active_estate_name'], $_SESSION['active_estate_role']);

// Get estates this user has access to
$estates = DB::fetchAll("SELECT e.*, ue.role as estate_role, ue.is_default
    FROM estates e
    JOIN user_estates ue ON e.id = ue.estate_id
    WHERE ue.user_id = ? AND e.is_active = 1
    ORDER BY ue.is_default DESC, e.name ASC", [$user['id']]);

// If only one estate — go straight to dashboard
if (count($estates) === 1) {
    $_SESSION['active_estate_id']   = (int)$estates[0]['id'];
    $_SESSION['active_estate_name'] = $estates[0]['name'];
    $_SESSION['active_estate_role'] = $estates[0]['estate_role'];
    session_write_close();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Handle estate selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estateId = (int)($_POST['estate_id'] ?? 0);
    // Verify user has access
    $valid = DB::fetchOne("SELECT e.id, e.name, ue.role as estate_role
        FROM estates e JOIN user_estates ue ON e.id=ue.estate_id
        WHERE ue.user_id=? AND e.id=? AND e.is_active=1", [$user['id'], $estateId]);
    if ($valid) {
        // Clear any old estate data from session
        unset($_SESSION['active_estate_id'], $_SESSION['active_estate_name'], $_SESSION['active_estate_role']);
        // Set new estate
        $_SESSION['active_estate_id']   = (int)$valid['id'];
        $_SESSION['active_estate_name'] = $valid['name'];
        $_SESSION['active_estate_role'] = $valid['estate_role'];
        // Force session write before redirect
        session_write_close();
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

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
<title>Select Estate — <?= sanitize($appName) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
body { background: var(--green-900); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.picker-wrap { width:100%; max-width:520px; }
.picker-header { text-align:center; margin-bottom:28px; }
.picker-logo { width:56px;height:56px;background:var(--green-400);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;overflow:hidden; }
.picker-logo img { width:100%;height:100%;object-fit:contain;padding:6px; }
.picker-logo i { color:#fff;font-size:30px; }
.picker-title { color:#fff;font-size:22px;font-weight:700;margin-bottom:4px; }
.picker-sub   { color:rgba(255,255,255,0.5);font-size:13px; }
.picker-user  { display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.1);border-radius:20px;padding:6px 14px;margin-top:10px; }
.picker-user .av { width:24px;height:24px;border-radius:50%;background:var(--green-400);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff; }
.picker-user span { color:rgba(255,255,255,0.8);font-size:12px; }
.estate-grid  { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px; }
.estate-card  { background:#fff;border-radius:14px;padding:20px;cursor:pointer;transition:transform .15s,box-shadow .15s;border:2px solid transparent;text-align:left;width:100%; }
.estate-card:hover { transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,0.25);border-color:var(--green-400); }
.estate-card.default-estate { border-color:var(--green-200); }
.estate-icon  { width:44px;height:44px;border-radius:10px;background:var(--green-50);display:flex;align-items:center;justify-content:center;margin-bottom:12px; }
.estate-icon i { font-size:22px;color:var(--green-600); }
.estate-name  { font-size:15px;font-weight:700;color:var(--green-900);margin-bottom:4px; }
.estate-loc   { font-size:12px;color:var(--gray-400); }
.estate-role  { display:inline-flex;align-items:center;gap:4px;margin-top:8px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px; }
.role-admin   { background:var(--green-50);color:var(--green-700); }
.role-sup     { background:var(--teal-50);color:var(--teal-700); }
.estate-default-badge { float:right;font-size:10px;font-weight:700;background:var(--green-100);color:var(--green-800);padding:2px 7px;border-radius:10px; }
.logout-link  { text-align:center; }
.logout-link a { color:rgba(255,255,255,0.4);font-size:12px;text-decoration:none; }
.logout-link a:hover { color:rgba(255,255,255,0.7); }
@media(max-width:480px){ .estate-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="picker-wrap">
  <div class="picker-header">
    <div class="picker-logo">
      <?php if($logoUrl): ?><img src="<?= $logoUrl ?>" alt="Logo">
      <?php else: ?><i class="ti ti-leaf"></i><?php endif; ?>
    </div>
    <div class="picker-title"><?= sanitize($appName) ?></div>
    <div class="picker-sub">Select an estate to manage</div>
    <div class="picker-user">
      <div class="av"><?= initials($user['name']) ?></div>
      <span><?= sanitize($user['name']) ?> · <?= ucfirst($user['role']) ?></span>
    </div>
  </div>

  <form method="POST">
    <div class="estate-grid">
      <?php foreach ($estates as $e): ?>
      <button type="submit" name="estate_id" value="<?= $e['id'] ?>"
              class="estate-card <?= $e['is_default']?'default-estate':'' ?>">
        <?php if($e['is_default']): ?>
          <span class="estate-default-badge">Default</span>
        <?php endif; ?>
        <div class="estate-icon">
          <?php if(!empty($e['logo_file'])): ?>
            <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($e['logo_file']) ?>" style="width:100%;height:100%;object-fit:contain;padding:4px;border-radius:8px">
          <?php else: ?>
            <i class="ti ti-trees"></i>
          <?php endif; ?>
        </div>
        <div class="estate-name"><?= sanitize($e['name']) ?></div>
        <?php if($e['location']): ?>
          <div class="estate-loc"><i class="ti ti-map-pin" style="font-size:11px;vertical-align:-1px"></i> <?= sanitize($e['location']) ?></div>
        <?php endif; ?>
        <div class="estate-role <?= $e['estate_role']==='admin'?'role-admin':'role-sup' ?>">
          <i class="ti ti-<?= $e['estate_role']==='admin'?'shield-check':'user' ?>" style="font-size:12px"></i>
          <?= ucfirst($e['estate_role']) ?>
        </div>
      </button>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="logout-link">
    <a href="logout.php"><i class="ti ti-logout" style="font-size:12px;vertical-align:-1px"></i> Logout</a>
  </div>
</div>
</body>
</html>
