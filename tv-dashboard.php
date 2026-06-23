<?php
// ============================================
// TeaEstate Pro — TV Dashboard
// Public page — No login required
// Designed for 16:9 displays (1920x1080)
// Auto-refreshes every 30 minutes
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check();
// Allow ?estate=N param to override session (for direct TV links)
$estateId = isset($_GET['estate']) ? (int)$_GET['estate'] : Auth::estateId();
if ($estateId < 1) $estateId = Auth::estateId();
// Verify user has access to this estate
$estateAccess = DB::fetchOne("SELECT estate_id FROM user_estates WHERE user_id=? AND estate_id=?",
    [Auth::user()['id'], $estateId]);
if (!$estateAccess) $estateId = Auth::estateId(); // fallback to active estate

$month      = date('Y-m');
$monthLabel = date('F Y');
$today      = today();

// App settings (name/logo)
try {
    $settingsRows = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=?", [$estateId]);
    $appSettings  = array_column($settingsRows, 'value', 'key');
} catch (Exception $e) { $appSettings = []; }
$estateRow = DB::fetchOne("SELECT * FROM estates WHERE id=?", [$estateId]);
$appName   = $estateRow['name'] ?? ($appSettings['app_name'] ?? APP_NAME);
$estateLogoFile = $estateRow['logo_file'] ?? '';
$logoFile = $estateLogoFile ?: ($appSettings['logo_file'] ?? '');
$logoTs   = $appSettings['logo_updated'] ?? '1';
$logoUrl  = $logoFile ? BASE_URL . '/assets/img/' . $logoFile . '?v=' . $logoTs : '';

// ── DATA ──────────────────────────────────────

// Monthly total KG
$monthKg = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total
    FROM daily_assignments
    WHERE estate_id=? AND approval_status='approved' AND work_type_id=1
    AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, $month]);

// Today KG
$todayKg = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total
    FROM daily_assignments
    WHERE estate_id=? AND approval_status='approved' AND work_type_id=1
    AND assignment_date=?", [$estateId, $today]);

// Section-wise KG this month
$sections = DB::fetchAll("SELECT p.name, p.id,
    COALESCE(SUM(da.quantity),0) as kg,
    COUNT(DISTINCT da.worker_id) as workers,
    COUNT(DISTINCT da.assignment_date) as days
    FROM plantations p
    LEFT JOIN daily_assignments da ON p.id=da.plantation_id
        AND da.approval_status='approved'
        AND da.work_type_id=1
        AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    WHERE p.estate_id=? AND p.is_active=1
    GROUP BY p.id ORDER BY kg DESC", [$estateId, $month]);

$totalSections = count($sections);
$maxSectionKg  = max(array_column($sections,'kg') ?: [1]);

// Person-wise KG this month (top 20)
$workers = DB::fetchAll("SELECT w.full_name,
    COALESCE(SUM(da.quantity),0) as total_kg,
    COUNT(DISTINCT da.assignment_date) as days,
    MAX(p.name) as last_section
    FROM workers w
    JOIN daily_assignments da ON w.id=da.worker_id
    JOIN plantations p ON da.plantation_id=p.id
    WHERE da.estate_id=? AND da.approval_status='approved' AND da.work_type_id=1
    AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    GROUP BY w.id
    ORDER BY total_kg DESC
    LIMIT 20", [$estateId, $month]);

$maxWorkerKg = max(array_column($workers,'total_kg') ?: [1]);
$totalWorkers = count($workers);

// Daily KG trend (this month)
$dailyTrend = DB::fetchAll("SELECT assignment_date, SUM(quantity) as kg
    FROM daily_assignments
    WHERE estate_id=? AND approval_status='approved' AND work_type_id=1
    AND DATE_FORMAT(assignment_date,'%Y-%m')=?
    GROUP BY assignment_date ORDER BY assignment_date", [$estateId, $month]);
$maxDailyKg  = max(array_column($dailyTrend,'kg') ?: [1]);

// Fertilizer reminders
$fertReminders = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc
    JOIN plantations p ON fc.plantation_id=p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles GROUP BY plantation_id) latest
        ON fc.id=latest.max_id
    WHERE fc.estate_id=? AND p.estate_id=? AND p.is_active=1 AND fc.next_due_date IS NOT NULL
    ORDER BY fc.next_due_date ASC", [$estateId, $estateId]);

// Color palette for sections
$sectionColors = ['#4CAF50','#2196F3','#FF9800','#9C27B0','#F44336','#00BCD4'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sanitize($appName) ?> — TV Dashboard</title>
<!-- Auto-refresh every 30 minutes -->
<meta http-equiv="refresh" content="1800">
<style>
/* ============================================
   TV DASHBOARD — 16:9 Full Screen
   ============================================ */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800;900&display=swap');

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
  --green-900: #0D2B0A;
  --green-800: #173404;
  --green-600: #2E6B12;
  --green-400: #4CAF50;
  --green-200: #A5D6A7;
  --green-50:  #E8F5E9;
  --amber:     #FFC107;
  --red:       #F44336;
  --teal:      #26C6DA;
  --blue:      #42A5F5;
  --card-bg:   rgba(255,255,255,0.06);
  --card-border: rgba(255,255,255,0.10);
  --text:      #FFFFFF;
  --text-dim:  rgba(255,255,255,0.55);
  --text-faint:rgba(255,255,255,0.25);
}

html, body {
  width: 100vw;
  height: 100vh;
  overflow: hidden;
  background: #0a1f07;
  font-family: 'Inter', system-ui, sans-serif;
  color: var(--text);
}

/* Animated gradient background */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 80% 50% at 20% 20%, rgba(46,107,18,0.4) 0%, transparent 60%),
    radial-gradient(ellipse 60% 40% at 80% 80%, rgba(13,43,10,0.6) 0%, transparent 60%),
    linear-gradient(160deg, #0a1f07 0%, #122b0e 50%, #0a1f07 100%);
  z-index: 0;
}

.tv-wrap {
  position: relative;
  z-index: 1;
  width: 100vw;
  height: 100vh;
  display: grid;
  grid-template-rows: 72px 1fr;
  grid-template-columns: 1fr;
  gap: 0;
  padding: 14px 18px 14px;
}

/* ── TOPBAR ── */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 6px;
  margin-bottom: 14px;
}
.topbar-left { display:flex; align-items:center; gap:14px; }
.topbar-logo {
  width: 46px; height: 46px;
  background: var(--green-600);
  border-radius: 12px;
  display: flex; align-items:center; justify-content:center;
  flex-shrink: 0;
  overflow: hidden;
}
.topbar-logo img { width:100%; height:100%; object-fit:contain; padding:5px; }
.topbar-logo i   { font-size:26px; color:#fff; }
.app-name  { font-size: 22px; font-weight: 800; color:#fff; letter-spacing:-0.5px; }
.app-sub   { font-size: 12px; color: var(--text-dim); margin-top:1px; }
.topbar-center {
  position: absolute; left: 50%; transform: translateX(-50%);
  text-align: center;
}
.topbar-month  { font-size: 26px; font-weight: 800; color: var(--green-200); letter-spacing:-0.5px; }
.topbar-date   { font-size: 13px; color: var(--text-dim); margin-top:2px; }
.topbar-right  { display:flex; flex-direction:column; align-items:flex-end; gap:2px; }
.topbar-time   { font-size: 32px; font-weight: 800; font-variant-numeric: tabular-nums; color:#fff; }
.topbar-refresh{ font-size: 11px; color: var(--text-faint); display:flex; align-items:center; gap:4px; }
.refresh-dot   { width:6px; height:6px; border-radius:50%; background:var(--green-400); animation: pulse 2s infinite; }
@keyframes pulse{ 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── MAIN GRID ── */
.main-grid {
  display: grid;
  grid-template-columns: 1.15fr 2fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 14px;
  height: 100%;
}

/* ── CARDS ── */
.card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 16px;
  padding: 18px 20px;
  backdrop-filter: blur(10px);
  overflow: hidden;
  position: relative;
}
.card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
}
.card-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-dim);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 7px;
}
.card-label i { font-size: 15px; }

/* ── TOTAL KG CARD ── */
.card-total-kg {
  grid-column: 1;
  grid-row: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  background: linear-gradient(135deg, rgba(76,175,80,0.2) 0%, rgba(46,107,18,0.15) 100%);
  border-color: rgba(76,175,80,0.3);
}
.kg-big     { font-size: 64px; font-weight: 900; color:#fff; line-height:1; letter-spacing:-3px; }
.kg-unit    { font-size: 22px; font-weight: 600; color: var(--green-200); margin-left:4px; }
.kg-today   { font-size: 14px; color: var(--text-dim); margin-top: 10px; display:flex; align-items:center; gap:6px; }
.kg-today strong { color: var(--green-200); }
.kg-progress-wrap { margin-top:14px; }
.kg-progress-label { display:flex; justify-content:space-between; font-size:11px; color:var(--text-dim); margin-bottom:5px; }
.kg-progress-bar   { height: 8px; background: rgba(255,255,255,0.1); border-radius:4px; overflow:hidden; }
.kg-progress-fill  { height:100%; background: linear-gradient(90deg,var(--green-400),var(--green-200)); border-radius:4px; transition:width .5s; }

/* ── SECTION STATUS (top middle) ── */
.card-sections {
  grid-column: 2;
  grid-row: 1;
}
.sections-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  height: calc(100% - 30px);
}
.section-item {
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 14px 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  position: relative;
  overflow: hidden;
  transition: border-color .3s;
}
.section-item::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 3px;
  border-radius: 0 0 12px 12px;
}
.section-name { font-size: 13px; font-weight: 700; color:var(--text-dim); margin-bottom: 6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.section-kg   { font-size: 28px; font-weight: 900; color:#fff; line-height:1; }
.section-kg-unit { font-size:13px; font-weight:500; color:var(--text-dim); }
.section-meta { font-size: 11px; color: var(--text-faint); margin-top:8px; }
.section-bar-wrap { margin-top:8px; }
.section-bar-bg   { height:5px; background:rgba(255,255,255,0.08); border-radius:3px; overflow:hidden; }
.section-bar-fill { height:100%; border-radius:3px; }

/* ── FERTILIZER REMINDERS (top right) ── */
.card-fertilizer {
  grid-column: 3;
  grid-row: 1;
}
.fert-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.fert-item:last-child { border-bottom: none; }
.fert-dot  { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.fert-info { flex:1; min-width:0; }
.fert-section { font-size:13px; font-weight:700; }
.fert-type    { font-size:11px; color:var(--text-dim); margin-top:1px; }
.fert-badge   { font-size:11px; font-weight:800; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.badge-overdue{ background:rgba(244,67,54,0.2);  color:#ff8a80; border:1px solid rgba(244,67,54,0.4); }
.badge-urgent { background:rgba(255,82,82,0.15); color:#ff6e6e; border:1px solid rgba(255,82,82,0.3); }
.badge-soon   { background:rgba(255,193,7,0.15); color:#ffd54f; border:1px solid rgba(255,193,7,0.3); }
.badge-ok     { background:rgba(76,175,80,0.15); color:#a5d6a7; border:1px solid rgba(76,175,80,0.3); }

/* ── WORKER KG (bottom left + middle) ── */
.card-workers {
  grid-column: 1 / 3;
  grid-row: 2;
}
.workers-scroll {
  display: flex;
  flex-direction: column;
  gap: 7px;
  height: calc(100% - 30px);
  overflow: hidden;
}
.worker-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.worker-rank {
  width: 24px;
  font-size: 12px;
  font-weight: 700;
  color: var(--text-faint);
  text-align: center;
  flex-shrink: 0;
}
.worker-rank.top1 { color: #FFD700; }
.worker-rank.top2 { color: #C0C0C0; }
.worker-rank.top3 { color: #CD7F32; }
.worker-avatar {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: rgba(76,175,80,0.25);
  display: flex; align-items:center; justify-content:center;
  font-size: 11px; font-weight: 700;
  flex-shrink: 0;
  color: var(--green-200);
}
.worker-name  { font-size: 13px; font-weight: 600; width: 140px; flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.worker-bar-wrap { flex: 1; }
.worker-bar-bg   { height: 8px; background: rgba(255,255,255,0.07); border-radius: 4px; overflow: hidden; }
.worker-bar-fill { height: 100%; border-radius:4px; background: linear-gradient(90deg, var(--green-600), var(--green-400)); }
.worker-kg-val   { font-size: 13px; font-weight: 800; color: var(--green-200); width: 65px; text-align:right; flex-shrink:0; }
.worker-days     { font-size: 11px; color: var(--text-faint); width: 40px; text-align:right; flex-shrink:0; }

/* ── DAILY TREND (bottom right) ── */
.card-trend {
  grid-column: 3;
  grid-row: 2;
}
.trend-chart {
  display: flex;
  align-items: flex-end;
  gap: 4px;
  height: calc(100% - 52px);
  padding-top: 8px;
}
.trend-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; height:100%; justify-content:flex-end; }
.trend-bar { width:100%; border-radius:3px 3px 0 0; background: linear-gradient(180deg, var(--green-400), var(--green-600)); min-height:2px; transition:height .5s; }
.trend-bar.today { background: linear-gradient(180deg, #fff, var(--green-200)); }
.trend-day { font-size:8px; color:var(--text-faint); text-align:center; white-space:nowrap; }
.trend-axis { display:flex; justify-content:space-between; font-size:10px; color:var(--text-faint); margin-top:4px; }

/* ── TICKER AT BOTTOM ── */
.tv-ticker {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  height: 32px;
  background: rgba(76,175,80,0.15);
  border-top: 1px solid rgba(76,175,80,0.25);
  display: flex;
  align-items: center;
  overflow: hidden;
  z-index: 10;
}
.ticker-label {
  background: var(--green-600);
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  padding: 0 14px;
  height: 100%;
  display: flex;
  align-items: center;
  white-space: nowrap;
  flex-shrink: 0;
  text-transform: uppercase;
  letter-spacing: .08em;
}
.ticker-scroll {
  flex: 1;
  overflow: hidden;
  position: relative;
}
.ticker-inner {
  display: flex;
  gap: 60px;
  white-space: nowrap;
  font-size: 12px;
  color: var(--green-200);
  font-weight: 600;
  animation: ticker 30s linear infinite;
}
@keyframes ticker {
  0%   { transform: translateX(100vw); }
  100% { transform: translateX(-100%); }
}
.ticker-sep { color: var(--text-faint); }
</style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
</head>
<body>

<div class="tv-wrap">

  <!-- ── TOPBAR ── -->
  <div class="topbar">
    <div class="topbar-left">
      <div class="topbar-logo">
        <?php if ($logoUrl): ?>
        <img src="<?= $logoUrl ?>" alt="Logo">
        <?php else: ?>
        <i class="ti ti-leaf"></i>
        <?php endif; ?>
      </div>
      <div>
        <div class="app-name"><?= sanitize($appName) ?></div>
        <div class="app-sub">Tea Estate Management</div>
      </div>
    </div>
    <div class="topbar-center">
      <div class="topbar-month"><?= $monthLabel ?></div>
      <div class="topbar-date"><?= date('l, d F Y') ?></div>
    </div>
    <div class="topbar-right">
      <div class="topbar-time" id="live-clock">--:--:--</div>
      <div class="topbar-refresh">
        <div class="refresh-dot"></div>
        Auto-refresh every 30 min
      </div>
    </div>
  </div>

  <!-- ── MAIN GRID ── -->
  <div class="main-grid">

    <!-- CARD 1: Total Monthly KG -->
    <div class="card card-total-kg">
      <div class="card-label">
        <i class="ti ti-weight"></i> Total KG This Month
      </div>
      <div>
        <span class="kg-big"><?= number_format((float)$monthKg['total'], 0) ?></span>
        <span class="kg-unit">kg</span>
      </div>
      <div class="kg-today">
        <i class="ti ti-sun" style="font-size:14px;color:var(--amber)"></i>
        Today: <strong><?= number_format((float)$todayKg['total'], 1) ?> kg</strong>
      </div>
      <div class="kg-progress-wrap">
        <?php
        $daysInMonth   = (int)date('t');
        $dayOfMonth    = (int)date('j');
        $monthProgress = round($dayOfMonth / $daysInMonth * 100);
        $avgDaily      = $dayOfMonth > 0 ? (float)$monthKg['total'] / $dayOfMonth : 0;
        $projected     = round($avgDaily * $daysInMonth);
        ?>
        <div class="kg-progress-label">
          <span>Month Progress</span>
          <span><?= $dayOfMonth ?>/<?= $daysInMonth ?> days</span>
        </div>
        <div class="kg-progress-bar">
          <div class="kg-progress-fill" style="width:<?= $monthProgress ?>%"></div>
        </div>
        <div style="font-size:11px;color:var(--text-faint);margin-top:5px">
          Avg <?= number_format($avgDaily,1) ?> kg/day · Projected <?= number_format($projected,0) ?> kg
        </div>
      </div>
      <?php if ($totalWorkers > 0): ?>
      <div style="margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.08);font-size:12px;color:var(--text-dim);display:flex;gap:16px">
        <span><i class="ti ti-users" style="font-size:13px;vertical-align:-2px"></i> <?= $totalWorkers ?> workers</span>
        <span><i class="ti ti-trees" style="font-size:13px;vertical-align:-2px"></i> <?= $totalSections ?> sections</span>
      </div>
      <?php endif; ?>
    </div>

    <!-- CARD 2: Section Status -->
    <div class="card card-sections">
      <div class="card-label">
        <i class="ti ti-map-pin"></i> Section Performance — <?= $monthLabel ?>
      </div>
      <div class="sections-grid" style="grid-template-columns:repeat(<?= min(count($sections),4) ?>,1fr)">
        <?php foreach ($sections as $i => $s):
          $color   = $sectionColors[$i % count($sectionColors)];
          $pct     = $maxSectionKg > 0 ? round($s['kg'] / $maxSectionKg * 100) : 0;
        ?>
        <div class="section-item" style="border-color:<?= $color ?>33">
          <div style="position:absolute;inset:0;background:linear-gradient(135deg,<?= $color ?>15,transparent);border-radius:12px;pointer-events:none"></div>
          <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:<?= $color ?>;border-radius:0 0 12px 12px;opacity:.7"></div>
          <div style="position:relative">
            <div class="section-name" style="color:<?= $color ?>"><?= sanitize($s['name']) ?></div>
            <div style="margin-top:6px">
              <span class="section-kg"><?= number_format((float)$s['kg'],0) ?></span>
              <span class="section-kg-unit"> kg</span>
            </div>
            <div class="section-bar-wrap">
              <div class="section-bar-bg">
                <div class="section-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;opacity:.8"></div>
              </div>
            </div>
            <div class="section-meta">
              <?= $s['workers'] ?> workers · <?= $s['days'] ?> days
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$sections): ?>
        <div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:var(--text-faint);font-size:14px">
          <i class="ti ti-leaf-off" style="font-size:28px;display:block;text-align:center;margin-bottom:8px"></i>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- CARD 3: Fertilizer Reminders -->
    <div class="card card-fertilizer">
      <div class="card-label">
        <i class="ti ti-droplet"></i> Fertilizer Cycle Status
      </div>
      <?php if ($fertReminders): ?>
        <?php foreach ($fertReminders as $f):
          $days  = daysUntil($f['next_due_date']);
          if ($days <= 0)      { $cls='badge-overdue'; $dot='#F44336'; $lbl='OVERDUE'; }
          elseif ($days <= 5)  { $cls='badge-urgent';  $dot='#FF5252'; $lbl='In '.$days.'d'; }
          elseif ($days <= 14) { $cls='badge-soon';    $dot='#FFC107'; $lbl='In '.$days.' days'; }
          else                 { $cls='badge-ok';      $dot='#4CAF50'; $lbl='In '.$days.' days'; }
        ?>
        <div class="fert-item">
          <div class="fert-dot" style="background:<?= $dot ?>"></div>
          <div class="fert-info">
            <div class="fert-section"><?= sanitize($f['plantation_name']) ?></div>
            <div class="fert-type"><?= sanitize($f['fertilizer_type']) ?>
              <?= $f['amount_kg'] ? ' · '.$f['amount_kg'].' kg' : '' ?>
              · Applied <?= fmtDate($f['applied_date']) ?>
            </div>
          </div>
          <div class="fert-badge <?= $cls ?>"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--text-faint)">
          <i class="ti ti-check" style="font-size:28px;display:block;margin-bottom:8px;color:var(--green-400)"></i>
          <div style="font-size:13px">All cycles up to date</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- CARD 4: Worker KG Leaderboard -->
    <div class="card card-workers">
      <div class="card-label" style="justify-content:space-between">
        <span><i class="ti ti-trophy"></i> Worker Plucking — <?= $monthLabel ?></span>
        <span style="font-size:11px;color:var(--text-faint)"><?= $totalWorkers ?> workers · <?= number_format((float)$monthKg['total'],0) ?> kg total</span>
      </div>
      <?php if ($workers): ?>
      <?php
      // Calculate how many workers fit given available height
      // Approx: 52px header + N*35px rows = fits ~11-13 workers
      // We'll show max 12
      $showWorkers = array_slice($workers, 0, 12);
      $cols = count($showWorkers) > 8 ? 2 : 1;
      ?>
      <div style="display:grid;grid-template-columns:repeat(<?= $cols ?>,1fr);gap:<?= $cols>1?'0 20px':'0' ?>;height:calc(100% - 30px);align-content:start">
        <?php foreach ($showWorkers as $i => $w):
          $pct  = $maxWorkerKg > 0 ? round($w['total_kg'] / $maxWorkerKg * 100) : 0;
          $rnkCls = $i===0?'top1':($i===1?'top2':($i===2?'top3':''));
          $barColor = $i===0?'linear-gradient(90deg,#FFD700,#FFF176)':($i===1?'linear-gradient(90deg,#9E9E9E,#E0E0E0)':($i===2?'linear-gradient(90deg,#8D6E63,#BCAAA4)':'linear-gradient(90deg,var(--green-600),var(--green-400))'));
          $initials = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', trim($w['full_name'])), 0, 2)));
        ?>
        <div class="worker-row">
          <div class="worker-rank <?= $rnkCls ?>"><?= $i < 3 ? ($i+1) : ($i+1) ?></div>
          <div class="worker-avatar"><?= $initials ?></div>
          <div class="worker-name"><?= sanitize($w['full_name']) ?></div>
          <div class="worker-bar-wrap">
            <div class="worker-bar-bg">
              <div class="worker-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
            </div>
          </div>
          <div class="worker-kg-val"><?= number_format((float)$w['total_kg'],1) ?> kg</div>
          <div class="worker-days"><?= $w['days'] ?>d</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div style="text-align:center;padding:30px;color:var(--text-faint);font-size:14px">No plucking records this month</div>
      <?php endif; ?>
    </div>

    <!-- CARD 5: Daily KG Trend -->
    <div class="card card-trend">
      <div class="card-label" style="justify-content:space-between">
        <span><i class="ti ti-chart-bar"></i> Daily KG Trend</span>
        <span style="font-size:11px;color:var(--text-faint)"><?= count($dailyTrend) ?> days recorded</span>
      </div>
      <?php if ($dailyTrend): ?>
      <div class="trend-chart">
        <?php foreach ($dailyTrend as $d):
          $h     = $maxDailyKg > 0 ? max(2, round($d['kg'] / $maxDailyKg * 100)) : 2;
          $isToday = ($d['assignment_date'] === $today);
          $dayLbl  = date('d', strtotime($d['assignment_date']));
        ?>
        <div class="trend-bar-wrap">
          <div class="trend-bar <?= $isToday?'today':'' ?>" style="height:<?= $h ?>%"
               title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'],1) ?> kg"></div>
          <div class="trend-day"><?= $dayLbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="trend-axis">
        <span>0 kg</span>
        <span><?= number_format($maxDailyKg/2,0) ?> kg</span>
        <span><?= number_format($maxDailyKg,0) ?> kg</span>
      </div>
      <?php else: ?>
        <div style="text-align:center;padding:30px;color:var(--text-faint);font-size:14px">No data this month</div>
      <?php endif; ?>
    </div>

  </div><!-- .main-grid -->
</div><!-- .tv-wrap -->

<!-- TICKER -->
<div class="tv-ticker">
  <div class="ticker-label"><i class="ti ti-leaf"></i> &nbsp;Live</div>
  <div class="ticker-scroll">
    <div class="ticker-inner">
      <span>📊 Monthly Total: <strong><?= number_format((float)$monthKg['total'],1) ?> kg</strong></span>
      <span class="ticker-sep">·</span>
      <span>☀️ Today: <strong><?= number_format((float)$todayKg['total'],1) ?> kg</strong></span>
      <span class="ticker-sep">·</span>
      <?php foreach ($sections as $s): ?>
      <span>🌿 <?= sanitize($s['name']) ?>: <strong><?= number_format((float)$s['kg'],0) ?> kg</strong></span>
      <span class="ticker-sep">·</span>
      <?php endforeach; ?>
      <?php if ($workers): ?>
      <span>🏆 Top: <strong><?= sanitize($workers[0]['full_name']) ?></strong> — <?= number_format((float)$workers[0]['total_kg'],1) ?> kg</span>
      <span class="ticker-sep">·</span>
      <?php endif; ?>
      <?php foreach ($fertReminders as $f):
        $days = daysUntil($f['next_due_date']);
        if ($days <= 14):
      ?>
      <span>💧 <?= sanitize($f['plantation_name']) ?> fertilizer <?= $days<=0?'OVERDUE':'due in '.$days.' days' ?></span>
      <span class="ticker-sep">·</span>
      <?php endif; endforeach; ?>
      <span>⏱ Auto-refreshes every 30 minutes</span>
      <span class="ticker-sep">·</span>
    </div>
  </div>
</div>

<script>
// Live clock
function updateClock() {
  var now  = new Date();
  var h    = String(now.getHours()).padStart(2,'0');
  var m    = String(now.getMinutes()).padStart(2,'0');
  var s    = String(now.getSeconds()).padStart(2,'0');
  var el   = document.getElementById('live-clock');
  if (el) el.textContent = h + ':' + m + ':' + s;
}
updateClock();
setInterval(updateClock, 1000);

// Countdown to next refresh
var refreshIn = 1800; // 30 minutes in seconds
setInterval(function() {
  refreshIn--;
  if (refreshIn <= 0) window.location.reload();
  var m = Math.floor(refreshIn / 60);
  var s = refreshIn % 60;
  var el = document.querySelector('.topbar-refresh');
  if (el) el.innerHTML = '<div class="refresh-dot"></div> Refreshing in ' + m + ':' + String(s).padStart(2,'0');
}, 1000);
</script>

</body>
</html>
