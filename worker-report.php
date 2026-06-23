<?php
// ============================================
// Worker Report - PUBLIC PAGE (No Login Required)
// Share this URL with all workers
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Estate selection — use session if logged in, or GET param, default to 1
Auth::start();
$estateId = (int)($_GET['estate'] ?? ($_SESSION['active_estate_id'] ?? 1));
if ($estateId < 1) $estateId = 1;

// Load estate info for header
$estateRow = DB::fetchOne("SELECT * FROM estates WHERE id=? AND is_active=1", [$estateId]);
if (!$estateRow) { $estateId = 1; $estateRow = DB::fetchOne("SELECT * FROM estates WHERE id=1", []); }

// Load app settings for this estate
try {
    $settingsRows = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=?", [$estateId]);
    $appSettings  = array_column($settingsRows, 'value', 'key');
} catch (Exception $e) { $appSettings = []; }

// App brand name (from app_settings)
$appName    = $appSettings['app_name'] ?? APP_NAME;
// Estate-specific name (the actual estate being viewed)
$estateName = $estateRow['name'] ?? $appName;
$estateLocation = $estateRow['location'] ?: '';
// Logo: estate logo takes priority over app logo
$logoFile = $estateRow['logo_file'] ?? ($appSettings['logo_file'] ?? '');
$logoTs   = $appSettings['logo_updated'] ?? '1';
$logoUrl  = $logoFile ? BASE_URL . '/assets/img/' . $logoFile . '?v=' . $logoTs : '';

// Always show current month only
$selMonth   = date('Y-m');
$monthDate  = $selMonth . '-01';
$monthLabel = date('F Y');

// ── DATA QUERIES ──────────────────────────────

// 1. Tea Plucking — worker-wise
$plucking = DB::fetchAll("SELECT
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    SUM(da.quantity) as total_kg,
    COUNT(DISTINCT da.assignment_date) as days,
    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as sections
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN plantations p ON da.plantation_id = p.id
    JOIN work_types wt1 ON da.work_type_id = wt1.id
    WHERE da.estate_id = ? AND da.approval_status = 'approved'
    AND wt1.estate_id = ? AND LOWER(wt1.unit_label) = 'kg'
    AND DATE_FORMAT(da.assignment_date, '%Y-%m') = ?
    GROUP BY da.worker_id, SUBSTRING_INDEX(da.notes,'|',1)
    ORDER BY total_kg DESC", [$estateId, $estateId, $selMonth]);

$totalKg    = array_sum(array_column($plucking, 'total_kg'));
$maxKg      = max(array_column($plucking, 'total_kg') ?: [1]);
$totalDays  = count(DB::fetchAll("SELECT DISTINCT da.assignment_date FROM daily_assignments da JOIN work_types wt ON da.work_type_id=wt.id WHERE da.estate_id=? AND wt.estate_id=? AND LOWER(wt.unit_label)='kg' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?", [$estateId, $estateId, $selMonth]));

// 2. Clearing Work — per record with date and payment
$clearing = DB::fetchAll("SELECT da.id, da.assignment_date, da.quantity, da.payment, da.rate, da.notes,
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    p.name as section
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN plantations p ON da.plantation_id = p.id
    JOIN work_types wt2 ON da.work_type_id = wt2.id
    WHERE da.estate_id = ? AND da.approval_status = 'approved'
    AND wt2.estate_id = ? AND LOWER(wt2.name) LIKE '%clear%'
    AND DATE_FORMAT(da.assignment_date, '%Y-%m') = ?
    ORDER BY da.assignment_date DESC, full_name ASC", [$estateId, $estateId, $selMonth]);

$totalClearingUnits   = array_sum(array_column($clearing, 'quantity'));
$totalClearingPayment = array_sum(array_column($clearing, 'payment'));

// 3. Tank Spraying (work_type_id = 3)
$spraying = DB::fetchAll("SELECT
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    SUM(da.quantity) as total_tanks,
    COUNT(DISTINCT da.assignment_date) as days,
    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as sections
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN plantations p ON da.plantation_id = p.id
    JOIN work_types wt3 ON da.work_type_id = wt3.id
    WHERE da.estate_id = ? AND da.approval_status = 'approved'
    AND wt3.estate_id = ? AND LOWER(wt3.unit_label) IN ('tank','tanks')
    AND DATE_FORMAT(da.assignment_date, '%Y-%m') = ?
    GROUP BY da.worker_id, SUBSTRING_INDEX(da.notes,'|',1)
    ORDER BY total_tanks DESC", [$estateId, $estateId, $selMonth]);

$totalTanks = array_sum(array_column($spraying, 'total_tanks'));

// 4. Helpers — per record with date (same structure as clearing/basic)
$helpers = DB::fetchAll("SELECT da.id, da.assignment_date, da.quantity, da.payment, da.rate, da.notes,
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    p.name as section
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN plantations p ON da.plantation_id = p.id
    JOIN work_types wt4 ON da.work_type_id = wt4.id
    WHERE da.estate_id = ? AND da.approval_status = 'approved'
    AND wt4.estate_id = ? AND LOWER(wt4.unit_label) = 'day'
    AND DATE_FORMAT(da.assignment_date, '%Y-%m') = ?
    ORDER BY da.assignment_date DESC, full_name ASC", [$estateId, $estateId, $selMonth]);

$totalHelperDays = array_sum(array_column($helpers, 'quantity'));

// 5. Basic / Support Work — per record with date and payment
$basicWork = DB::fetchAll("SELECT da.id, da.assignment_date, da.quantity, da.payment, da.rate, da.notes,
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    p.name as section
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN plantations p ON da.plantation_id = p.id
    JOIN work_types wt5 ON da.work_type_id = wt5.id
    WHERE da.estate_id = ? AND da.approval_status = 'approved'
    AND wt5.estate_id = ? AND (LOWER(wt5.name) LIKE '%basic%' OR LOWER(wt5.name) LIKE '%support%')
    AND DATE_FORMAT(da.assignment_date, '%Y-%m') = ?
    ORDER BY da.assignment_date DESC, full_name ASC", [$estateId, $estateId, $selMonth]);

$totalBasicUnits   = array_sum(array_column($basicWork, 'quantity'));
$totalBasicPayment = array_sum(array_column($basicWork, 'payment'));

// 6. Fertilizer Cycles this month
$fertilizer = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc
    JOIN plantations p ON fc.plantation_id = p.id
    WHERE DATE_FORMAT(fc.applied_date, '%Y-%m') = ?
    AND fc.estate_id=? ORDER BY fc.applied_date ASC", [$selMonth, $estateId]);

// 6b. Fertilizer reminders — latest cycle per section with next due date
$fertReminders = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc
    JOIN plantations p ON fc.plantation_id = p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles WHERE estate_id=? GROUP BY plantation_id) latest
        ON fc.id = latest.max_id
    WHERE fc.estate_id = ? AND p.estate_id = ? AND p.is_active = 1
    ORDER BY fc.next_due_date ASC", [$estateId, $estateId, $estateId]);

// 7. Summary: total workers active this month
$totalWorkers = DB::fetchOne("SELECT COUNT(DISTINCT worker_id) as cnt FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, $selMonth]);

// 8. Daily KG trend
$dailyKg = DB::fetchAll("SELECT assignment_date, SUM(quantity) as kg FROM daily_assignments da JOIN work_types wt ON da.work_type_id=wt.id WHERE da.estate_id=? AND wt.estate_id=? AND LOWER(wt.unit_label)='kg' AND da.approval_status='approved' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=? GROUP BY da.assignment_date ORDER BY da.assignment_date", [$estateId, $estateId, $selMonth]);
$maxDailyKg = max(array_column($dailyKg, 'kg') ?: [1]);

// 9. Section-wise KG
$sectionKg = DB::fetchAll("SELECT p.name, COALESCE(SUM(da.quantity),0) as kg
    FROM plantations p LEFT JOIN daily_assignments da ON p.id=da.plantation_id AND da.estate_id=? AND da.approval_status='approved' LEFT JOIN work_types wt_s ON da.work_type_id=wt_s.id AND LOWER(wt_s.unit_label)='kg' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    WHERE p.estate_id=? AND p.is_active=1 GROUP BY p.id ORDER BY kg DESC", [$estateId, $estateId, $selMonth]);
$maxSectionKg = max(array_column($sectionKg,'kg') ?: [1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sanitize($estateName) ?> – Worker Report <?= $monthLabel ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
body { background: #f0f4ec; margin: 0; padding: 0; }
.pub-wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 40px; }

/* Header */
.pub-header { background: var(--green-900); border-radius: var(--radius-lg); padding: 24px 28px; margin-bottom: 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; }
.pub-logo { display:flex; align-items:center; gap:12px; }
.pub-logo-icon { width:44px; height:44px; background:var(--green-400); border-radius:10px; display:flex; align-items:center; justify-content:center; }
.pub-logo-icon i { color:#fff; font-size:24px; }
.pub-title { color:#fff; font-size:20px; font-weight:700; }
.pub-sub { color:rgba(255,255,255,0.5); font-size:12px; margin-top:2px; }
.pub-month { color:#fff; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }

/* Month selector */
/* Month selector removed — shows current month only */

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
.sum-card { background:#fff; border-radius:var(--radius-lg); border:1px solid #e8ede5; padding:14px 16px; border-top:4px solid var(--green-400); text-align:center; }
.sum-card.teal { border-top-color:var(--teal-400); }
.sum-card.amber { border-top-color:var(--amber-400); }
.sum-card.purple { border-top-color:#7C3AED; }
.sum-card.blue { border-top-color:#2563EB; }
.sum-num { font-size:26px; font-weight:800; color:var(--green-900); line-height:1.1; margin:6px 0 4px; }
.sum-lbl { font-size:11px; color:var(--gray-400); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
.sum-icon { font-size:22px; color:var(--green-400); }

/* Section title */
.sec-title { display:flex; align-items:center; gap:10px; font-size:16px; font-weight:800; color:var(--green-900); margin:24px 0 14px; padding-bottom:8px; border-bottom:2px solid var(--green-100); }
.sec-title i { font-size:20px; color:var(--green-600); }
.sec-title .sec-total { margin-left:auto; font-size:13px; font-weight:700; color:var(--green-600); background:var(--green-50); padding:4px 12px; border-radius:20px; }

/* Worker cards */
.worker-card { background:#fff; border-radius:var(--radius-lg); border:1px solid #e8ede5; padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; gap:12px; }
.worker-card .rank { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; flex-shrink:0; }
.rank-1 { background:var(--amber-50); color:var(--amber-600); border:2px solid var(--amber-200); }
.rank-2 { background:var(--gray-50); color:var(--gray-600); border:2px solid var(--gray-200); }
.rank-3 { background:var(--green-50); color:var(--green-600); border:2px solid var(--green-200); }
.rank-n { background:var(--gray-50); color:var(--gray-400); border:1px solid var(--gray-100); }
.worker-card .winfo { flex:1; min-width:0; }
.worker-card .wname { font-size:14px; font-weight:700; color:var(--green-900); }
.worker-card .wsub { font-size:11px; color:var(--gray-400); margin-top:2px; }
.worker-card .wnote { font-size:11px; color:var(--amber-600); margin-top:3px; display:flex; align-items:center; gap:4px; }
.worker-card .wval { font-size:18px; font-weight:800; color:var(--green-800); white-space:nowrap; }
.worker-card .wval-sub { font-size:11px; color:var(--gray-400); text-align:right; }
.bar-wrap { flex:1; margin:0 12px; min-width:60px; }
.bar-bg { height:8px; background:var(--gray-50); border-radius:4px; overflow:hidden; }
.bar-fill { height:100%; border-radius:4px; background:var(--green-400); }

/* Fertilizer items */
.fert-card { background:#fff; border-radius:var(--radius-lg); border:1px solid #e8ede5; padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; gap:12px; }
.fert-badge { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; background:var(--teal-50); color:var(--teal-600); white-space:nowrap; }

/* KG trend chart */
.trend-wrap { background:#fff; border-radius:var(--radius-lg); border:1px solid #e8ede5; padding:16px 18px; margin-bottom:20px; }
.trend-bars { display:flex; align-items:flex-end; gap:3px; height:70px; margin:12px 0 6px; }
.trend-bar { flex:1; border-radius:3px 3px 0 0; background:var(--green-200); min-width:4px; transition:background .15s; cursor:default; }
.trend-bar:hover { background:var(--green-600); }

/* Empty state */
.pub-empty { text-align:center; padding:30px; color:var(--gray-400); background:#fff; border-radius:var(--radius-lg); border:1px solid #e8ede5; }
.pub-empty i { font-size:36px; display:block; margin-bottom:8px; }
.pub-empty p { font-size:13px; }

/* Footer */
.pub-footer { text-align:center; font-size:12px; color:var(--gray-400); margin-top:32px; padding-top:16px; border-top:1px solid #e8ede5; }

@media (max-width:600px) {
  .pub-header { padding:16px; }
  .pub-month { font-size:16px; }
  .summary-grid { grid-template-columns:1fr 1fr; }
  .worker-card .bar-wrap { display:none; }

}
/* ── SINHALA FONT + TOGGLE ──────────────── */
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Sinhala:wght@400;600;700&display=swap');
.si-text { font-family: 'Noto Sans Sinhala', sans-serif; font-size:13px; font-weight:600; }
.lang-toggle { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:20px; border:none; cursor:pointer; font-size:12px; font-weight:700; transition:all .15s; }
.lang-toggle.active { background:var(--green-600); color:#fff; }
.lang-toggle.inactive { background:rgba(255,255,255,0.1); color:rgba(255,255,255,0.7); border:1px solid rgba(255,255,255,0.2); }
.lang-toggle:hover { opacity:.85; }

/* ── RESPONSIVE ─────────────────────────── */
@media (max-width: 700px) {
  .pub-wrap         { padding: 12px 10px 30px; }
  .pub-header       { padding: 14px 16px; flex-direction: column; gap: 10px; }
  .pub-logo         { gap: 10px; }
  .pub-logo-icon    { width: 38px; height: 38px; flex-shrink: 0; }
  .pub-header > div:last-child { text-align: left !important; }
  .pub-month        { font-size: 18px; }
  .summary-grid     { grid-template-columns: 1fr 1fr !important; gap: 8px; }
  .sum-num          { font-size: 20px; }
  .sec-title        { font-size: 14px; }
  .worker-card      { gap: 8px; }
  .bar-wrap         { width: 100%; }
  .wval             { font-size: 14px; }
  .fert-card        { flex-direction: column; }
  .fert-badge       { width: 100%; text-align: center; }
  table             { font-size: 11px; min-width: 400px; }
  table th, table td { padding: 6px 8px !important; }
}
@media (max-width: 420px) {
  .summary-grid { grid-template-columns: 1fr 1fr !important; }
  .sum-num      { font-size: 18px; }
  .pub-month    { font-size: 15px; }
}
</style>
</head>
<body>
<div class="pub-wrap">

  <!-- HEADER -->
  <div class="pub-header">
    <div class="pub-logo">
      <div class="pub-logo-icon" style="<?= !empty($estateRow['logo_file']) ? 'background:#fff;padding:4px;' : '' ?>">
        <?php if (!empty($estateRow['logo_file'])): ?>
          <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($estateRow['logo_file'] ?? '') ?>"
               style="width:100%;height:100%;object-fit:contain;border-radius:6px">
        <?php elseif (!empty($logoUrl)): ?>
          <img src="<?= $logoUrl ?>" style="width:100%;height:100%;object-fit:contain;border-radius:6px">
        <?php else: ?>
          <i class="ti ti-leaf"></i>
        <?php endif; ?>
      </div>
      <div>
        <!-- Estate name — BIGGEST, most prominent -->
        <div style="font-size:28px;font-weight:900;letter-spacing:-0.5px;line-height:1;color:#fff;margin-bottom:4px">
          <?= sanitize($estateName) ?>
        </div>
        <!-- App brand + location — tiny below -->
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <div style="font-size:10px;color:rgba(255,255,255,0.45);display:flex;align-items:center;gap:3px">
            <i class="ti ti-leaf" style="font-size:10px"></i>
            <?= sanitize($appName) ?>
          </div>
          <?php if ($estateLocation): ?>
          <span style="color:rgba(255,255,255,0.25);font-size:10px">·</span>
          <div style="font-size:10px;color:rgba(255,255,255,0.45);display:flex;align-items:center;gap:3px">
            <i class="ti ti-map-pin" style="font-size:10px"></i>
            <?= sanitize($estateLocation) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div style="text-align:right">
      <div class="pub-month">
        <i class="ti ti-calendar" style="color:var(--green-200);font-size:20px"></i>
        <?= $monthLabel ?>
      </div>
      <div style="font-size:11px;color:rgba(255,255,255,0.4);margin-top:4px">Worker Monthly Report</div>
      <!-- Language toggle -->
      <div style="margin-top:8px;display:flex;gap:6px;justify-content:flex-end">
        <a href="?estate=<?= $estateId ?>&lang=en<?= isset($_GET['month'])?'&month='.$_GET['month']:'' ?>"
           class="lang-toggle <?= !$useSi?'active':'inactive' ?>" style="text-decoration:none">
          🇬🇧 English
        </a>
        <a href="?estate=<?= $estateId ?>&lang=si<?= isset($_GET['month'])?'&month='.$_GET['month']:'' ?>"
           class="lang-toggle <?= $useSi?'active':'inactive' ?>" style="text-decoration:none">
          🇱🇰 සිංහල
        </a>
      </div>
    </div>
  </div>



  <!-- ESTATE LINK BAR -->
  <?php
  $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host      = $_SERVER['HTTP_HOST'];
  $reportUrl = $protocol . '://' . $host . BASE_URL . '/worker-report.php?estate=' . $estateId;
  ?>
  <div style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <i class="ti ti-link" style="color:var(--green-300);font-size:16px;flex-shrink:0"></i>
    <span style="font-size:11px;color:rgba(255,255,255,0.5);flex-shrink:0">This estate's report link:</span>
    <code style="font-size:11px;color:var(--green-200);flex:1;word-break:break-all;min-width:0"><?= htmlspecialchars($reportUrl) ?></code>
    <button onclick="copyReportLink('<?= htmlspecialchars($reportUrl) ?>')"
            style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:5px"
            id="copy-report-btn">
      <i class="ti ti-copy" style="font-size:12px"></i> Copy Link
    </button>
  </div>

  <!-- SUMMARY CARDS -->
  <div class="summary-grid">
    <div class="sum-card">
      <div class="sum-icon"><i class="ti ti-weight"></i></div>
      <div class="sum-num"><?= number_format($totalKg, 1) ?></div>
      <div class="sum-lbl">Total KG Plucked</div>
    </div>
    <div class="sum-card">
      <div class="sum-icon"><i class="ti ti-users"></i></div>
      <div class="sum-num"><?= $totalWorkers['cnt'] ?? 0 ?></div>
      <div class="sum-lbl">Active Workers</div>
    </div>
    <div class="sum-card teal">
      <div class="sum-icon" style="color:var(--teal-400)"><i class="ti ti-plant-2"></i></div>
      <div class="sum-num"><?= number_format($totalClearingUnits, 1) ?></div>
      <div class="sum-lbl">Clearing Units</div>
    </div>
    <div class="sum-card amber">
      <div class="sum-icon" style="color:var(--amber-400)"><i class="ti ti-spray"></i></div>
      <div class="sum-num"><?= number_format($totalTanks, 0) ?></div>
      <div class="sum-lbl">Tanks Sprayed</div>
    </div>
    <div class="sum-card purple">
      <div class="sum-icon" style="color:#7C3AED"><i class="ti ti-hand-stop"></i></div>
      <div class="sum-num"><?= number_format($totalHelperDays, 0) ?></div>
      <div class="sum-lbl">Helper Days</div>
    </div>
  </div>

  <!-- FERTILIZER REMINDERS — shown right after summary cards -->
  <!-- Reminder cards per section -->
  <?php if ($fertReminders): ?>
  <div style="margin-bottom:18px">
    <div style="font-size:12px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;display:flex;align-items:center;gap:6px">
      <i class="ti ti-bell" style="color:var(--amber-400);font-size:15px"></i> Next Cycle Reminders — By Section
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
      <?php foreach ($fertReminders as $r):
        $days = !empty($r['next_due_date']) ? daysUntil($r['next_due_date']) : null;
        if ($days === null) continue;
        if ($days <= 0)       { $urgency='overdue';  $bg='#fee2e2'; $border='#fca5a5'; $dot='#ef4444'; $textc='#991b1b'; $lbl='OVERDUE'; $icon='ti-alert-circle'; }
        elseif ($days <= 5)   { $urgency='urgent';   $bg='#fff1f2'; $border='#fda4af'; $dot='#f43f5e'; $textc='#9f1239'; $lbl='In '.$days.' day'.($days!=1?'s':''); $icon='ti-alert-triangle'; }
        elseif ($days <= 14)  { $urgency='soon';     $bg='#fffbeb'; $border='#fcd34d'; $dot='#f59e0b'; $textc='#92400e'; $lbl='In '.$days.' days'; $icon='ti-clock'; }
        else                  { $urgency='ok';       $bg='#f0fdf4'; $border='#86efac'; $dot='#22c55e'; $textc='#166534'; $lbl='In '.$days.' days'; $icon='ti-circle-check'; }
      ?>
      <div style="background:<?= $bg ?>;border:1.5px solid <?= $border ?>;border-radius:12px;padding:14px 16px">
        <!-- Section name + urgency -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $dot ?>;flex-shrink:0"></div>
            <span style="font-size:14px;font-weight:800;color:var(--green-900)"><?= sanitize($r['plantation_name']) ?></span>
          </div>
          <span style="font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px;background:<?= $dot ?>;color:#fff;display:flex;align-items:center;gap:4px">
            <i class="ti <?= $icon ?>" style="font-size:12px"></i> <?= $lbl ?>
          </span>
        </div>
        <!-- Details grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <div style="background:rgba(255,255,255,0.7);border-radius:8px;padding:8px 10px">
            <div style="font-size:10px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Last Applied</div>
            <div style="font-size:12px;font-weight:700;color:var(--green-900)"><?= fmtDate($r['applied_date']) ?></div>
          </div>
          <div style="background:rgba(255,255,255,0.7);border-radius:8px;padding:8px 10px">
            <div style="font-size:10px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Next Due</div>
            <div style="font-size:12px;font-weight:700;color:<?= $textc ?>"><?= fmtDate($r['next_due_date']) ?></div>
          </div>
          <div style="background:rgba(255,255,255,0.7);border-radius:8px;padding:8px 10px">
            <div style="font-size:10px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Fertilizer</div>
            <div style="font-size:12px;font-weight:700;color:var(--green-900)"><?= sanitize($r['fertilizer_type']) ?></div>
          </div>
          <div style="background:rgba(255,255,255,0.7);border-radius:8px;padding:8px 10px">
            <div style="font-size:10px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Amount</div>
            <div style="font-size:12px;font-weight:700;color:var(--green-900)"><?= $r['amount_kg'] ? $r['amount_kg'].' kg' : '—' ?></div>
          </div>
        </div>
        <?php if (!empty($r['notes'])): ?>
        <div style="margin-top:8px;font-size:11px;color:var(--gray-600);display:flex;align-items:flex-start;gap:5px;background:rgba(255,255,255,0.6);border-radius:6px;padding:6px 10px">
          <i class="ti ti-notes" style="font-size:13px;flex-shrink:0;margin-top:1px;color:var(--amber-500)"></i>
          <?= sanitize($r['notes']) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- KG TREND CHART -->
  <?php if ($dailyKg): ?>
  <div class="trend-wrap">
    <div style="font-size:13px;font-weight:700;color:var(--green-900);display:flex;align-items:center;gap:8px">
      <i class="ti ti-chart-bar" style="color:var(--green-600)"></i>
      Daily KG Trend — <?= $monthLabel ?>
      <span style="margin-left:auto;font-size:12px;color:var(--gray-400)">Avg: <?= count($dailyKg)>0?number_format($totalKg/count($dailyKg),1):0 ?> kg/day</span>
    </div>
    <div class="trend-bars">
      <?php foreach ($dailyKg as $d): ?>
      <div class="trend-bar"
           style="height:<?= $maxDailyKg>0?round($d['kg']/$maxDailyKg*100):0 ?>%"
           title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'],1) ?> kg">
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray-400)">
      <span>Day 1</span>
      <span><?= count($dailyKg) ?> working days</span>
      <span>Day <?= count($dailyKg) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 1. TEA PLUCKING ─────────────────────── -->
  <div class="sec-title">
    <i class="ti ti-leaf"></i> Tea Plucking
    <span class="sec-total"><?= number_format($totalKg, 1) ?> kg total · <?= $totalDays ?> days</span>
  </div>

  <?php if ($plucking): ?>
    <!-- Section-wise KG bars -->
    <div style="background:#fff;border:1px solid #e8ede5;border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:14px">
      <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">KG by Section</div>
      <?php foreach ($sectionKg as $s): if($s['kg']==0) continue; ?>
      <div style="margin-bottom:8px">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
          <span style="font-weight:600"><?= sanitize($s['name']) ?></span>
          <span style="font-weight:700;color:var(--green-600)"><?= number_format($s['kg'],1) ?> kg</span>
        </div>
        <div class="bar-bg"><div class="bar-fill" style="width:<?= $maxSectionKg>0?round($s['kg']/$maxSectionKg*100):0 ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Worker-wise plucking -->
    <?php foreach ($plucking as $i => $w): ?>
    <div class="worker-card">
      <div class="rank <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-n')) ?>">
        <?= $i < 3 ? ($i+1) : initials($w['full_name']) ?>
      </div>
      <div class="winfo">
        <div class="wname"><?= sanitize($w['full_name']) ?> <?php if (!empty($w['is_temp'])): ?><span style="font-size:9px;font-weight:700;background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200);padding:1px 6px;border-radius:8px;margin-left:4px">TEMP</span><?php endif; ?></div>
        <div class="wsub">
          <?= $w['days'] ?> day<?= $w['days']>1?'s':'' ?> worked
          · <?= sanitize($w['sections']) ?>
        </div>
      </div>
      <div class="bar-wrap">
        <div class="bar-bg">
          <div class="bar-fill" style="width:<?= $maxKg>0?round($w['total_kg']/$maxKg*100):0 ?>%"></div>
        </div>
      </div>
      <div>
        <div class="wval"><?= number_format($w['total_kg'], 1) ?> kg</div>
        <div class="wval-sub"><?= $totalKg>0?round($w['total_kg']/$totalKg*100):0 ?>% of total</div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="pub-empty"><i class="ti ti-leaf-off"></i><p>No plucking records for <?= $monthLabel ?></p></div>
  <?php endif; ?>

  <!-- ── 2-5. OTHER WORK — grouped by week ─── -->
  <?php
  $otherWork = [];
  foreach ($clearing as $r) {
    $otherWork[] = ['date'=>$r['assignment_date'],'full_name'=>$r['full_name'],'is_temp'=>$r['is_temp']??0,
      'work_type'=>'Clearing Work','icon'=>'ti-plant-2','color'=>'var(--teal-600)','bg'=>'var(--teal-50)',
      'section'=>$r['section'],'quantity'=>$r['quantity'],'unit'=>'Unit','payment'=>$r['payment'],'notes'=>$r['notes']??''];
  }
  foreach ($spraying as $r) {
    $otherWork[] = ['date'=>'','full_name'=>$r['full_name'],'is_temp'=>$r['is_temp']??0,
      'work_type'=>'Tank Spraying','icon'=>'ti-spray','color'=>'var(--amber-600)','bg'=>'var(--amber-50)',
      'section'=>$r['sections'],'quantity'=>$r['total_tanks'],'unit'=>'Tank','payment'=>0,'notes'=>''];
  }
  foreach ($helpers as $r) {
    $otherWork[] = ['date'=>$r['assignment_date'],'full_name'=>$r['full_name'],'is_temp'=>$r['is_temp']??0,
      'work_type'=>'Helper','icon'=>'ti-hand-stop','color'=>'#6D28D9','bg'=>'#EDE9FE',
      'section'=>$r['section'],'quantity'=>$r['quantity'],'unit'=>'Day','payment'=>$r['payment'],'notes'=>$r['notes']??''];
  }
  foreach ($basicWork as $r) {
    $otherWork[] = ['date'=>$r['assignment_date'],'full_name'=>$r['full_name'],'is_temp'=>$r['is_temp']??0,
      'work_type'=>'Basic / Support','icon'=>'ti-tools','color'=>'#1e40af','bg'=>'#dbeafe',
      'section'=>$r['section'],'quantity'=>$r['quantity'],'unit'=>'Unit','payment'=>$r['payment'],'notes'=>$r['notes']??''];
  }

  // Sort by date descending; no-date rows go to end
  usort($otherWork, function($a, $b) {
    if (!$a['date'] && !$b['date']) return 0;
    if (!$a['date']) return 1;
    if (!$b['date']) return -1;
    return strcmp($b['date'], $a['date']);
  });

  // Group into weeks — week label = "Week N: dd Mon – dd Mon"
  $byWeek = [];
  foreach ($otherWork as $r) {
    if ($r['date']) {
      $ts      = strtotime($r['date']);
      $weekNum = (int)date('W', $ts);
      // Get Monday and Sunday of this ISO week
      $mon = date('d M', strtotime('monday this week', $ts));
      $sun = date('d M', strtotime('sunday this week', $ts));
      $wkey = $weekNum;
      $wlbl = $mon . ' – ' . $sun;
    } else {
      $wkey = 0;
      $wlbl = 'Summary (no specific date)';
    }
    if (!isset($byWeek[$wkey])) $byWeek[$wkey] = ['label'=>$wlbl,'rows'=>[],'total'=>0];
    $byWeek[$wkey]['rows'][] = $r;
    $byWeek[$wkey]['total'] += $r['payment'];
  }
  // Sort weeks descending (newest first)
  krsort($byWeek);

  $otherWorkTotal = array_sum(array_column($otherWork, 'payment'));
  ?>

  <div class="sec-title">
    <i class="ti ti-briefcase"></i> <?= $useSi ? '<span class="si-text">වෙනත් කාර්ය</span> <span style="font-size:11px;font-weight:400;opacity:.7">(Other Work)</span>' : 'Other Work' ?>
    <?php if (count($otherWork)>0): ?>
    <span class="sec-total" style="background:var(--gray-50);color:var(--gray-600)">
      <?= count($otherWork) ?> record(s)<?= $otherWorkTotal>0?' · '.money($otherWorkTotal):'' ?>
    </span>
    <?php endif; ?>
  </div>

  <?php if ($otherWork): ?>

  <?php foreach ($byWeek as $weekNum => $week): ?>
  <!-- Week block -->
  <div style="margin-bottom:18px">
    <!-- Week header -->
    <div style="display:flex;align-items:center;justify-content:space-between;background:var(--green-900);border-radius:10px 10px 0 0;padding:10px 16px">
      <div style="display:flex;align-items:center;gap:8px">
        <i class="ti ti-calendar-week" style="color:var(--green-300);font-size:16px"></i>
        <span style="font-size:13px;font-weight:700;color:#fff">
          <?php if ($weekNum > 0): ?>
            Week <?= date('W', strtotime($week['rows'][0]['date'])) ?> &nbsp;·&nbsp;
            <span style="font-weight:400;color:rgba(255,255,255,0.65)"><?= $week['label'] ?></span>
          <?php else: ?>
            <span style="color:rgba(255,255,255,0.8)"><?= $week['label'] ?></span>
          <?php endif; ?>
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:11px;color:rgba(255,255,255,0.5)"><?= count($week['rows']) ?> record(s)</span>
        <?php if ($week['total'] > 0): ?>
        <span style="font-size:13px;font-weight:800;color:var(--green-300)"><?= money($week['total']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <!-- Week table -->
    <div style="background:#fff;border:1px solid #e8ede5;border-top:none;border-radius:0 0 10px 10px;overflow:hidden">
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8faf5">
              <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5;white-space:nowrap">Work Type</th>
              <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5">Worker</th>
              <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5">Section</th>
              <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5;white-space:nowrap">Date</th>
              <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5">Qty</th>
              <th style="padding:8px 14px;text-align:right;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5">Payment</th>
              <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e8ede5">Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($week['rows'] as $r): ?>
            <tr style="border-bottom:1px solid #f5f5f0" onmouseover="this.style.background='#fafaf8'" onmouseout="this.style.background=''">
              <td style="padding:9px 14px;white-space:nowrap">
                <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $r['bg'] ?>;color:<?= $r['color'] ?>;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700">
                  <i class="ti <?= $r['icon'] ?>" style="font-size:12px"></i>
                  <?= si($r['work_type'], $sinhala, $useSi) ?>
                </span>
              </td>
              <td style="padding:9px 14px">
                <div style="display:flex;align-items:center;gap:7px">
                  <div class="avatar" style="width:26px;height:26px;font-size:10px;background:<?= $r['bg'] ?>;color:<?= $r['color'] ?>;flex-shrink:0"><?= initials($r['full_name']) ?></div>
                  <span style="font-weight:700;color:var(--green-900);white-space:nowrap"><?= sanitize($r['full_name']) ?></span>
                  <?php if (!empty($r['is_temp'])): ?><span style="font-size:9px;font-weight:700;background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200);padding:1px 5px;border-radius:8px">TEMP</span><?php endif; ?>
                </div>
              </td>
              <td style="padding:9px 14px;color:var(--gray-600);font-size:12px"><?= sanitize($r['section']) ?></td>
              <td style="padding:9px 14px;font-size:12px;color:var(--gray-500);white-space:nowrap">
                <?php if ($r['date']): ?>
                  <span style="font-weight:600"><?= date('d M', strtotime($r['date'])) ?></span>
                  <span style="color:var(--gray-400);font-size:11px"> <?= date('D', strtotime($r['date'])) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td style="padding:9px 14px;text-align:center">
                <span style="background:<?= $r['bg'] ?>;color:<?= $r['color'] ?>;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap">
                  <?= number_format((float)$r['quantity'],0) ?> <?= $useSi && isset($sinhala[$r['unit']]) ? '<span class="si-text">'.$sinhala[$r['unit']].'</span>' : htmlspecialchars($r['unit']) ?>
                </span>
              </td>
              <td style="padding:9px 14px;text-align:right;font-weight:800;color:var(--green-700);font-size:13px">
                <?= $r['payment']>0 ? money($r['payment']) : '—' ?>
              </td>
              <td style="padding:9px 14px;font-size:11px;color:var(--amber-600);max-width:140px">
                <?php $note = preg_replace('/^TEMP:[^|]+\|?\s*/u', '', $r['notes']??''); ?>
                <?php if (trim($note)): ?>
                  <span style="display:flex;align-items:flex-start;gap:4px">
                    <i class="ti ti-notes" style="font-size:12px;flex-shrink:0;margin-top:1px"></i>
                    <?= sanitize(substr(trim($note),0,60)) ?>
                  </span>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($week['total'] > 0): ?>
          <tfoot>
            <tr style="background:var(--green-50)">
              <td colspan="5" style="padding:9px 14px;font-size:12px;font-weight:700;color:var(--green-800)">
                Week Total
              </td>
              <td style="padding:9px 14px;text-align:right;font-size:14px;font-weight:800;color:var(--green-700)"><?= money($week['total']) ?></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Grand Total -->
  <?php if ($otherWorkTotal > 0): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;background:var(--green-900);border-radius:10px;padding:12px 18px;margin-bottom:10px">
    <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.7)">
      <i class="ti ti-calculator" style="vertical-align:-2px"></i> Total Other Work Payments — <?= $monthLabel ?>
    </span>
    <span style="font-size:17px;font-weight:900;color:var(--green-300)"><?= money($otherWorkTotal) ?></span>
  </div>
  <?php endif; ?>

  <?php else: ?>
    <div class="pub-empty"><i class="ti ti-briefcase-off"></i><p>No other work records for <?= $monthLabel ?></p></div>
  <?php endif; ?>

  <!-- ── 6. FERTILIZER CYCLES ──────────────── -->
  <div class="sec-title">
    <i class="ti ti-droplet"></i> <?= $useSi ? '<span class="si-text">පොහොර චක්‍ර</span> <span style="font-size:11px;font-weight:400;opacity:.6">(Fertilizer Cycles)</span>' : 'Fertilizer Cycles' ?>
    <?php if (count($fertilizer)>0): ?>
    <span class="sec-total" style="background:var(--teal-50);color:var(--teal-600)"><?= count($fertilizer) ?> application<?= count($fertilizer)>1?'s':'' ?> this month</span>
    <?php endif; ?>
  </div>



  <!-- This month's applications -->
  <?php if ($fertilizer): ?>
  <div style="font-size:12px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;display:flex;align-items:center;gap:6px">
    <i class="ti ti-history" style="color:var(--teal-600);font-size:15px"></i> <?= $useSi ? '<span class="si-text">මෙම මාසේ යෙදීම්</span> <span style="font-size:11px;font-weight:400;opacity:.6">(Applications This Month)</span>' : 'Applications This Month' ?>
  </div>
  <div style="background:#fff;border:1px solid #e8ede5;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:10px">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--teal-50)">
          <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--teal-700);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--teal-100)">Date</th>
          <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--teal-700);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--teal-100)">Section</th>
          <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--teal-700);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--teal-100)">Fertilizer</th>
          <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;color:var(--teal-700);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--teal-100)">Amount</th>
          <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--teal-700);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--teal-100)">Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fertilizer as $f): ?>
        <tr style="border-bottom:1px solid #f0f0eb" onmouseover="this.style.background='var(--teal-50)'" onmouseout="this.style.background=''">
          <td style="padding:10px 14px">
            <div style="font-size:13px;font-weight:700;color:var(--green-900)"><?= fmtDate($f['applied_date']) ?></div>
            <div style="font-size:11px;color:var(--gray-400)"><?= date('l', strtotime($f['applied_date'])) ?></div>
          </td>
          <td style="padding:10px 14px">
            <div style="display:flex;align-items:center;gap:7px">
              <div style="width:8px;height:8px;border-radius:50%;background:var(--teal-400);flex-shrink:0"></div>
              <span style="font-size:13px;font-weight:600;color:var(--green-900)"><?= sanitize($f['plantation_name']) ?></span>
            </div>
          </td>
          <td style="padding:10px 14px">
            <span style="background:var(--teal-50);color:var(--teal-700);padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid var(--teal-100)"><?= sanitize($f['fertilizer_type']) ?></span>
          </td>
          <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--teal-700)"><?= $f['amount_kg'] ? $f['amount_kg'].' kg' : '—' ?></td>
          <td style="padding:10px 14px;font-size:12px;color:var(--gray-500)"><?= !empty($f['notes']) ? sanitize($f['notes']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="pub-empty"><i class="ti ti-droplet-off"></i><p>No fertilizer applications recorded for <?= $monthLabel ?></p></div>
  <?php endif; ?>

  <!-- FOOTER -->
  <div class="pub-footer">
    <i class="ti ti-leaf" style="font-size:16px;vertical-align:-2px;color:var(--green-400)"></i>
    <?= APP_NAME ?> · Worker Report · <?= $monthLabel ?>
    · Generated on <?= date('d M Y, H:i') ?>
    <br><br>
    <a href="worker-report.php" style="color:var(--green-600);text-decoration:none;font-size:12px">
      <i class="ti ti-refresh" style="font-size:12px"></i> Refresh
    </a>
  </div>

</div>
<script>
function copyReportLink(url) {
  navigator.clipboard.writeText(url).then(function() {
    var btn = document.getElementById('copy-report-btn');
    if (btn) {
      btn.innerHTML = '<i class="ti ti-check" style="font-size:12px"></i> Copied!';
      btn.style.background = 'rgba(76,175,80,0.3)';
      setTimeout(function() {
        btn.innerHTML = '<i class="ti ti-copy" style="font-size:12px"></i> Copy Link';
        btn.style.background = 'rgba(255,255,255,0.15)';
      }, 2500);
    }
  }).catch(function() {
    // Fallback for older browsers
    var el = document.createElement('textarea');
    el.value = url;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
  });
}
</script>
</body>
</html>
