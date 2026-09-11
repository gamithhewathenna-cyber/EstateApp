<?php
Auth::check();
$user = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$nav = [
    ['key'=>'index',       'label'=>'Dashboard',          'icon'=>'ti-layout-dashboard', 'roles'=>['admin']],
    ['key'=>'workers',     'label'=>'Workers',             'icon'=>'ti-users',            'roles'=>['admin']],
    ['key'=>'assignments', 'label'=>'Daily Assignments',   'icon'=>'ti-clipboard-list',   'roles'=>['admin','supervisor']],
    ['key'=>'payroll',     'label'=>'Payroll',             'icon'=>'ti-cash',             'roles'=>['admin']],
    ['key'=>'expenses',    'label'=>'Expenses',            'icon'=>'ti-receipt',          'roles'=>['admin']],
    ['key'=>'production',  'label'=>'Tea Production',      'icon'=>'ti-plant-2',          'roles'=>['admin']],
    ['key'=>'factory-management', 'label'=>'Factory Management', 'icon'=>'ti-building-factory-2', 'roles'=>['admin']],
    ['key'=>'fertilizer',  'label'=>'Fertilizer Cycles',   'icon'=>'ti-droplet',          'roles'=>['admin']],
    ['key'=>'clearing',    'label'=>'Clearing Cycles',     'icon'=>'ti-scissors',         'roles'=>['admin']],
    ['key'=>'plantations', 'label'=>'Plantation Sections', 'icon'=>'ti-trees',            'roles'=>['admin']],
    ['key'=>'estates',        'label'=>'Manage Estates',       'icon'=>'ti-trees',            'roles'=>['admin']],
    ['key'=>'reports',        'label'=>'Reports',             'icon'=>'ti-chart-bar',        'roles'=>['admin']],
    ['key'=>'users',          'label'=>'User Management',     'icon'=>'ti-shield-lock',      'roles'=>['admin']],
    ['key'=>'settings',       'label'=>'Settings',            'icon'=>'ti-settings',         'roles'=>['admin']],
    // Hidden for now — re-enable by uncommenting: ['key'=>'tv-dashboard', 'label'=>'TV Dashboard', 'icon'=>'ti-device-tv', 'roles'=>['admin']],
];

// ── ESTATE CONTEXT ──────────────────────────
$activeEstateId   = Auth::estateId();
$activeEstateName = Auth::estateName();

// Estates this user can switch between (for the "Switch Estate" popup) —
// wrapped in try/catch since this runs on every page via header.php.
try {
    $switchableEstates = DB::fetchAll("SELECT e.*, ue.role as estate_role, ue.is_default
        FROM estates e JOIN user_estates ue ON e.id = ue.estate_id
        WHERE ue.user_id = ? AND e.is_active = 1
        ORDER BY ue.is_default DESC, e.name ASC", [$user['id']]);
} catch (Exception $e) { $switchableEstates = []; }

// Load estate-specific app settings
try {
    $settingsRows = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=?", [$activeEstateId]);
    $appSettings  = array_column($settingsRows, 'value', 'key');
    // Fallback to estate 1 settings if empty
    if (empty($appSettings)) {
        $settingsRows = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=1", []);
        $appSettings  = array_column($settingsRows, 'value', 'key');
    }
} catch (Exception $e) { $appSettings = []; }

// Fertilizer due count for badge
$fertDue = DB::fetchOne("SELECT COUNT(*) as cnt FROM fertilizer_cycles fc
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles WHERE estate_id=? GROUP BY plantation_id) latest
    ON fc.id = latest.max_id
    WHERE fc.estate_id=? AND fc.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)", [$activeEstateId, $activeEstateId]);
$fertCount = $fertDue['cnt'] ?? 0;

// Clearing due count for badge
// Wrapped in try/catch: this runs on every page via header.php, so if the
// clearing_cycles migration hasn't been applied yet, the whole site must
// keep working (just without the badge) rather than 500 everywhere.
try {
    $clearDue = DB::fetchOne("SELECT COUNT(*) as cnt FROM clearing_cycles cc
        JOIN (SELECT plantation_id, MAX(id) as max_id FROM clearing_cycles WHERE estate_id=? GROUP BY plantation_id) latest
        ON cc.id = latest.max_id
        WHERE cc.estate_id=? AND cc.next_due_date IS NOT NULL AND cc.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)", [$activeEstateId, $activeEstateId]);
    $clearCount = $clearDue['cnt'] ?? 0;
} catch (Exception $e) { $clearCount = 0; }

// Use estate-specific settings (already loaded above)
// Also check estate table for per-estate logo/name override
$_estateRow = DB::fetchOne("SELECT * FROM estates WHERE id=?", [$activeEstateId]);

// Estate display name: app_settings takes priority (user sets this in Settings page)
// Falls back to estates table name, then session name
$_estateName = $appSettings['app_name'] ?? $_estateRow['name'] ?? $activeEstateName ?? APP_NAME;

// Logo: estate logo takes priority, then app_settings logo, then default
$_estateLogo = $_estateRow['logo_file'] ?? '';
$_settingsLogo = $appSettings['logo_file'] ?? '';
$_logoFile = $_estateLogo ?: $_settingsLogo;
$_logoTs   = time(); // bust cache on switch
$_logoUrl  = $_logoFile ? BASE_URL . '/assets/img/' . $_logoFile . '?v=' . $_logoTs : '';

// App name & sub from settings
$_appName = $appSettings['app_name'] ?? $_estateName ?? APP_NAME;
$_appSub  = $appSettings['app_sub']  ?? $_estateRow['location'] ?? 'Estate Management';

// Theme colour for this estate
$_themeColor    = $appSettings['theme_color']    ?? '#2E6B12';
$_themeBg       = $appSettings['theme_bg']       ?? '#0D2B0A';
$_themeAccent   = $appSettings['theme_accent']   ?? '#4CAF50';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sanitize($_estateName) ?> – <?= $pageTitle ?? 'Dashboard' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
/* Per-estate theme override */
:root {
  --estate-primary: <?= htmlspecialchars($_themeColor) ?>;
  --estate-bg:      <?= htmlspecialchars($_themeBg) ?>;
  --estate-accent:  <?= htmlspecialchars($_themeAccent) ?>;
}
.sidebar { background: var(--estate-bg, var(--green-900)) !important; }
.sidebar-logo .logo-mark { background: var(--estate-accent, var(--green-400)) !important; }
.nav-item.active, .nav-item:hover { background: rgba(255,255,255,0.12) !important; }
.topbar { border-bottom-color: var(--estate-accent, var(--green-400)) !important; }
</style>
</head>
<body>

<div class="app-wrapper">
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark" style="<?= $_logoUrl ? 'background:none;padding:0;overflow:hidden' : '' ?>">
      <?php if ($_logoUrl): ?>
        <img src="<?= $_logoUrl ?>" alt="Logo" style="width:36px;height:36px;object-fit:contain;border-radius:8px">
      <?php else: ?>
        <i class="ti ti-leaf"></i>
      <?php endif; ?>
    </div>
    <div>
      <div class="app-name" style="font-size:15px;font-weight:800"><?= sanitize($_estateName) ?></div>
      <div class="app-sub" style="font-size:10px;opacity:.55"><?= sanitize($_appSub) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <?php foreach($nav as $item): ?>
      <?php if(in_array($user['role'], $item['roles'])): ?>
        <a href="<?= BASE_URL ?>/<?= $item['key'] ?>.php<?= $item['key']==='tv-dashboard' ? '?estate='.$activeEstateId : '' ?>"
           class="nav-item <?= $currentPage===$item['key']?'active':'' ?>">
          <i class="ti <?= $item['icon'] ?>"></i>
          <span><?= $item['label'] ?></span>
          <?php if($item['key']==='fertilizer' && $fertCount>0): ?>
            <span class="nav-badge"><?= $fertCount ?></span>
          <?php endif; ?>
          <?php if($item['key']==='clearing' && $clearCount>0): ?>
            <span class="nav-badge"><?= $clearCount ?></span>
          <?php endif; ?>
        </a>
        <?php if($item['key']==='workers'): ?><div class="nav-section">Finance</div><?php endif; ?>
        <?php if($item['key']==='expenses'): ?><div class="nav-section">Plantation</div><?php endif; ?>
        <?php if($item['key']==='estates'): ?><div class="nav-section">Admin</div><?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <!-- Active estate (read-only — switch it from the dropdown in the topbar) -->
    <div style="padding:8px 10px;margin-bottom:4px">
      <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Active Estate</div>
      <div style="display:flex;align-items:center;gap:7px;min-width:0">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--green-400);flex-shrink:0"></div>
        <span style="font-size:12px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($activeEstateName) ?></span>
      </div>
    </div>
    <div class="user-info">
      <div class="user-avatar"><?= initials($user['name']) ?></div>
      <div>
        <div class="user-name"><?= sanitize($user['name']) ?></div>
        <div class="user-role"><?= ucfirst($user['role']) ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="nav-item logout-btn">
      <i class="ti ti-logout"></i><span>Logout</span>
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main-wrap">
  <header class="topbar">
    <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">
      <i class="ti ti-menu-2"></i>
    </button>
    <div>
      <h1 class="page-title" style="margin:0;line-height:1.1"><?= $pageTitle ?? 'Dashboard' ?></h1>
      <div class="estate-switch" id="estate-switch">
        <button type="button" class="estate-switch-trigger" onclick="toggleEstateDropdown(event)">
          <i class="ti ti-trees"></i>
          <span><?= sanitize($_estateName) ?></span>
          <i class="ti ti-chevron-down estate-switch-caret"></i>
        </button>
        <div class="estate-switch-menu">
          <?php foreach ($switchableEstates as $se): $isCurrent = ((int)$se['id'] === (int)$activeEstateId); ?>
          <form method="POST" action="<?= BASE_URL ?>/estate-picker.php" onsubmit="return fmSwitchEstate(this)">
            <input type="hidden" name="estate_id" value="<?= $se['id'] ?>">
            <button type="submit" class="estate-switch-option <?= $isCurrent ? 'current' : '' ?>" <?= $isCurrent ? 'disabled' : '' ?>>
              <div class="eso-icon">
                <?php if (!empty($se['logo_file'])): ?>
                <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($se['logo_file']) ?>" alt="">
                <?php else: ?>
                <i class="ti ti-trees" style="color:var(--green-600);font-size:15px"></i>
                <?php endif; ?>
              </div>
              <div class="eso-info">
                <div class="eso-name">
                  <?= sanitize($se['name']) ?>
                  <?php if ($se['is_default']): ?><span class="eso-badge">DEFAULT</span><?php endif; ?>
                </div>
                <?php if ($se['location']): ?><div class="eso-loc"><?= sanitize($se['location']) ?></div><?php endif; ?>
              </div>
              <?php if ($isCurrent): ?>
              <i class="ti ti-check"></i>
              <?php else: ?>
              <i class="ti ti-chevron-right"></i>
              <?php endif; ?>
            </button>
          </form>
          <?php endforeach; ?>
          <?php if (!$switchableEstates): ?>
          <div class="empty-state"><i class="ti ti-trees-off"></i><p>No estates available</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="topbar-right">
      <span class="topbar-date"><i class="ti ti-calendar"></i><?= date('D, d M Y') ?></span>
    </div>
  </header>

  <main class="content">
<?php
$flashSuccess = flash('success');
$flashError   = flash('error');
if ($flashSuccess): ?>
  <div class="alert alert-success"><i class="ti ti-check"></i> <?= sanitize($flashSuccess) ?></div>
<?php endif;
if ($flashError): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= sanitize($flashError) ?></div>
<?php endif; ?>
