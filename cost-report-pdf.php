<?php
// ============================================
// Harvest Pro — Weekly Cost Report (Print/PDF)
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAdmin();
$estateId = Auth::estateId();

// Parameters
$selSectionRaw = $_GET['section'] ?? 'all';
$selSection = ($selSectionRaw === 'all') ? 'all' : (int)$selSectionRaw;
$selMonth   = $_GET['month']   ?? date('Y-m');
$selWeek    = (int)($_GET['week'] ?? 0); // 0 = all weeks

if (!$selSection && $selSection !== 'all') {
    echo '<p style="font-family:sans-serif;padding:40px">Please select a section. <a href="assignments.php?tab=costs">Go back</a></p>';
    exit;
}

// Estate info
try {
    $sr = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=?", [$estateId]);
    $appSettings = array_column($sr, 'value', 'key');
} catch(Exception $e){ $appSettings=[]; }
$estateRow  = DB::fetchOne("SELECT * FROM estates WHERE id=?", [$estateId]);
$appName    = $appSettings['app_name'] ?? APP_NAME;
$estateName = $estateRow['name'] ?? $appName;
$logoFile   = $estateRow['logo_file'] ?? ($appSettings['logo_file'] ?? '');
$logoUrl    = $logoFile ? BASE_URL.'/assets/img/'.$logoFile : '';

// Language
$useSi = isset($_GET['lang']) && $_GET['lang'] === 'si';

$sinhala = [
    'Tea Plucking'        => 'දලු කිලෝ',
    'Clearing Work'       => 'නෙලීම',
    'Tank Spraying'       => 'ඉසින කාර්ය',
    'Helper'              => 'ආධාර',
    'Basic / Support Work'=> 'සාමාන්‍ය කාර්ය',
    'Basic / Support'     => 'සාමාන්‍ය කාර්ය',
    'KG'                  => 'කිලෝ',
    'Unit'                => 'අත්තම්',
    'Day'                 => 'දිනය',
    'Tank'                => 'ටැංකිය',
    'Food'                => 'ආහාර පාන',
    'Transport'           => 'ප්‍රවාහන',
    'Spray Can'           => 'ඉසින බඳුන',
    'Equipment'           => 'උපකරණ',
    'Miscellaneous'       => 'විවිධ',
];
function siLabel($en, $si_map, $useSi) {
    $si = $si_map[trim($en ?? '')] ?? null;
    if ($useSi && $si) return $si.' <span style="font-size:9px;color:#6b7c61;font-weight:400">('.$en.')</span>';
    return htmlspecialchars($en ?? '');
}

// Section info
if ($selSection === 'all') {
    $section = ['name' => 'All Sections', 'id' => 0];
} else {
    $section = DB::fetchOne("SELECT * FROM plantations WHERE id=? AND estate_id=?", [$selSection, $estateId]);
    if (!$section) { echo '<p>Section not found.</p>'; exit; }
}

$monthLabel = date('F Y', strtotime($selMonth.'-01'));

// Compute ISO-week-aligned date range so that weeks spanning a month boundary
// (e.g. Mon 29 Jun – Sun 05 Jul when July starts on Wednesday) are fully included.
$monthFirstTs = strtotime($selMonth . '-01');
$monthLastTs  = strtotime(date('Y-m-t', $monthFirstTs));  // last calendar day of month
$dowFirst     = (int)date('N', $monthFirstTs);             // Mon=1 … Sun=7
$dowLast      = (int)date('N', $monthLastTs);
$rangeStart   = date('Y-m-d', $monthFirstTs - ($dowFirst - 1) * 86400); // Monday of first week
$rangeEnd     = date('Y-m-d', $monthLastTs  + (7 - $dowLast) * 86400);  // Sunday of last week

$secClause  = ($selSection === 'all') ? '' : 'AND da.plantation_id=?';
$secParams  = ($selSection === 'all') ? [] : [$selSection];

$allRecords = DB::fetchAll("SELECT
    da.assignment_date,
    WEEK(da.assignment_date, 1) as week_num,
    DATE_FORMAT(DATE_SUB(da.assignment_date, INTERVAL (WEEKDAY(da.assignment_date)) DAY), '%Y-%m-%d') as week_monday,
    DATE_FORMAT(DATE_ADD(DATE_SUB(da.assignment_date, INTERVAL (WEEKDAY(da.assignment_date)) DAY), INTERVAL 6 DAY), '%Y-%m-%d') as week_sunday,
    wt.name as work_type,
    wt.unit_label,
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), 'Temp') as worker_name,
    CASE WHEN (da.worker_id IS NULL OR da.worker_id = 0) THEN 1 ELSE 0 END as is_temp,
    da.quantity,
    da.payment,
    da.payment_status,
    da.notes,
    LOWER(wt.unit_label) as unit_lower
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id = w.id
    JOIN work_types wt ON da.work_type_id = wt.id
    WHERE da.estate_id=? $secClause AND da.approval_status='approved'
    AND da.assignment_date BETWEEN ? AND ?
    ORDER BY da.assignment_date ASC, wt.name ASC, worker_name ASC",
    array_merge([$estateId], $secParams, [$rangeStart, $rangeEnd]));

// Expenses — same ISO-week-aligned range.
if ($selSection === 'all') {
    $expenseRecords = DB::fetchAll("SELECT
        expense_date, expense_type, amount, notes,
        WEEK(expense_date, 1) as week_num,
        CASE WHEN plantation_id IS NULL OR plantation_id=0 THEN 'General' ELSE 'Section' END as scope
        FROM expenses
        WHERE estate_id=?
        AND expense_date BETWEEN ? AND ?
        ORDER BY expense_date ASC, expense_type ASC", [$estateId, $rangeStart, $rangeEnd]);
} else {
    $expenseRecords = DB::fetchAll("SELECT
        expense_date, expense_type, amount, notes,
        WEEK(expense_date, 1) as week_num,
        CASE WHEN plantation_id IS NULL OR plantation_id=0 THEN 'General' ELSE 'Section' END as scope
        FROM expenses
        WHERE estate_id=?
        AND (plantation_id=? OR plantation_id IS NULL OR plantation_id=0)
        AND expense_date BETWEEN ? AND ?
        ORDER BY expense_date ASC, expense_type ASC", [$estateId, $selSection, $rangeStart, $rangeEnd]);
}

$totalExpensesAmt = array_sum(array_column($expenseRecords, 'amount'));

// Group by week → date
$expByWeek = [];
foreach ($expenseRecords as $ex) {
    $expByWeek[$ex['week_num']][$ex['expense_date']][] = $ex;
}

// Group by week → date → work_type
$byWeek = [];
foreach ($allRecords as $r) {
    $wn   = $r['week_num'];
    $date = $r['assignment_date'];
    if (!isset($byWeek[$wn])) {
        // Always store Monday-Sunday boundaries
        $byWeek[$wn] = [
            'week_monday' => $r['week_monday'],
            'week_sunday' => $r['week_sunday'],
            'dates'       => [],
            'total'       => 0
        ];
    }
    if (!isset($byWeek[$wn]['dates'][$date])) {
        $byWeek[$wn]['dates'][$date] = ['rows'=>[], 'total'=>0];
    }
    $byWeek[$wn]['dates'][$date]['rows'][]  = $r;
    $byWeek[$wn]['dates'][$date]['total']  += $r['payment'];
    $byWeek[$wn]['total']                  += $r['payment'];
}

// Build week labels from ALL weeks BEFORE filtering, so "Week 2" stays "Week 2"
// even when only that week is being displayed.
$allWeekKeys = array_keys($byWeek);
$weekLabels  = [];
foreach ($allWeekKeys as $i => $wk) {
    $weekLabels[$wk] = 'Week ' . ($i + 1);
}

// Keep the full set for the toolbar navigation buttons
$allByWeekForToolbar = $byWeek;

// Filter to specific week if requested
if ($selWeek && isset($byWeek[$selWeek])) {
    $byWeek = [$selWeek => $byWeek[$selWeek]];
    // Re-scope expense records to the selected week only
    $filteredExpenses = array_filter($expenseRecords, fn($ex) => $ex['week_num'] == $selWeek);
    $expByWeek = [];
    foreach ($filteredExpenses as $ex) {
        $expByWeek[$ex['week_num']][$ex['expense_date']][] = $ex;
    }
} else {
    $filteredExpenses = $expenseRecords;
}

// Calculate all totals from the FILTERED (possibly week-scoped) data
$filteredRecords = [];
foreach ($byWeek as $wdata) {
    foreach ($wdata['dates'] as $dayData) {
        $filteredRecords = array_merge($filteredRecords, $dayData['rows']);
    }
}
$grandTotal       = array_sum(array_column($filteredRecords, 'payment'));
$grandKg          = array_sum(array_map(fn($r) => $r['unit_lower'] === 'kg' ? $r['quantity'] : 0, $filteredRecords));
$totalExpensesAmt = array_sum(array_column(array_values($filteredExpenses), 'amount'));
$grandPaid        = array_sum(array_map(fn($r) => $r['payment_status'] === 'paid' ? $r['payment'] : 0, $filteredRecords));
$grandOutstanding = $grandTotal - $grandPaid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= sanitize($estateName) ?> — Weekly Cost Report — <?= $monthLabel ?></title>
<style>
/* ── PRINT STYLES ── */
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Sinhala:wght@400;600;700&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Noto Sans Sinhala', 'Segoe UI', Arial, sans-serif;
  font-size: 12px;
  color: #1a2e0a;
  background: #fff;
  padding: 0;
}

/* Print page setup */
@page {
  size: A4 portrait;
  margin: 15mm 12mm 15mm 12mm;
}

/* Screen preview */
@media screen {
  body { background: #e8ede5; padding: 20px; }
  .page { background: #fff; max-width: 794px; margin: 0 auto 20px; padding: 24px 28px; box-shadow: 0 4px 20px rgba(0,0,0,.12); border-radius: 6px; }
  .no-print { display: block; }
}

@media print {
  body { background: #fff; padding: 0; }
  .page { padding: 0; box-shadow: none; }
  .no-print { display: none !important; }
  .week-block { page-break-inside: avoid; }
}

/* ── PRINT BUTTON BAR ── */
.no-print {
  background: #0D2B0A;
  padding: 12px 20px;
  max-width: 794px;
  margin: 0 auto 16px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.btn-print {
  background: #4CAF50;
  color: #fff;
  border: none;
  padding: 8px 20px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn-back {
  background: rgba(255,255,255,.15);
  color: #fff;
  border: 1px solid rgba(255,255,255,.3);
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 12px;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.btn-print:hover { background: #388E3C; }

/* ── REPORT HEADER ── */
.report-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 3px solid #0D2B0A;
}
.header-left { display: flex; align-items: center; gap: 12px; }
.logo-box {
  width: 48px; height: 48px;
  background: #0D2B0A;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; flex-shrink: 0;
}
.logo-box img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
.logo-box .leaf { font-size: 24px; color: #4CAF50; }
.estate-name  { font-size: 18px; font-weight: 800; color: #0D2B0A; line-height: 1.1; }
.app-brand    { font-size: 10px; color: #6b7c61; font-weight: 500; margin-top: 2px; }
.header-right { text-align: right; }
.report-title { font-size: 15px; font-weight: 700; color: #0D2B0A; }
.report-sub   { font-size: 11px; color: #6b7c61; margin-top: 3px; }
.report-meta  { font-size: 10px; color: #9aab8a; margin-top: 2px; }

/* ── SUMMARY BAR ── */
.summary-bar {
  display: flex;
  gap: 0;
  border: 1.5px solid #0D2B0A;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 22px;
}
.sum-item {
  flex: 1;
  padding: 10px 14px;
  border-right: 1px solid #cfddc8;
  background: #f0f7ec;
}
.sum-item:last-child { border-right: none; }
.sum-label { font-size: 9px; font-weight: 700; color: #6b7c61; text-transform: uppercase; letter-spacing: .06em; }
.sum-value { font-size: 14px; font-weight: 800; color: #0D2B0A; margin-top: 2px; }

/* ── WEEK BLOCK ── */
.week-block { margin-bottom: 22px; }
.week-header {
  background: #0D2B0A;
  color: #fff;
  padding: 8px 14px;
  border-radius: 6px 6px 0 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.week-title   { font-size: 13px; font-weight: 800; }
.week-dates   { font-size: 10px; color: rgba(255,255,255,.6); margin-top: 2px; }
.week-total   { font-size: 14px; font-weight: 800; color: #A5D6A7; }

/* ── DATE SECTION ── */
.date-section { border: 1px solid #dce8d4; border-top: none; }
.date-header {
  background: #f0f7ec;
  padding: 6px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #dce8d4;
}
.date-label { font-size: 11px; font-weight: 700; color: #2E6B12; }
.date-total { font-size: 11px; font-weight: 700; color: #0D2B0A; }

/* ── WORK ROW ── */
.work-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 7px 14px;
  border-bottom: 1px solid #eef4e8;
}
.work-row:last-child { border-bottom: none; }
.work-bullet {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #4CAF50;
  flex-shrink: 0;
  margin-top: 4px;
}
.work-type  { font-size: 12px; font-weight: 700; color: #1a2e0a; min-width: 120px; }
.work-details { flex: 1; }
.work-qty   { font-size: 11px; color: #4a5e3a; margin-top: 1px; }
.work-worker{ font-size: 10px; color: #8a9e78; margin-top: 1px; }
.work-note  { font-size: 10px; color: #b08040; margin-top: 1px; font-style: italic; }
.work-cost  { font-size: 13px; font-weight: 800; color: #2E6B12; flex-shrink: 0; white-space: nowrap; }
.temp-badge { font-size: 8px; font-weight: 700; background: #FFF3CD; color: #856404; border: 1px solid #FFC107; padding: 1px 5px; border-radius: 8px; margin-left: 4px; vertical-align: middle; }
.paid-badge        { font-size: 8px; font-weight: 700; background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; padding: 1px 6px; border-radius: 8px; display: inline-block; white-space: nowrap; }
.outstanding-badge { font-size: 8px; font-weight: 700; background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; padding: 1px 6px; border-radius: 8px; display: inline-block; white-space: nowrap; }
.work-cost-col { text-align: right; flex-shrink: 0; min-width: 90px; }
.work-cost-col .work-cost { margin-bottom: 3px; }
.work-cost-col .status-tags { display: flex; gap: 4px; justify-content: flex-end; flex-wrap: wrap; }

/* ── WEEK SUBTOTAL ── */
.week-subtotal {
  background: #eaf4e4;
  padding: 8px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border: 1px solid #dce8d4;
  border-top: none;
  border-radius: 0 0 6px 6px;
}
.week-sub-label { font-size: 11px; font-weight: 700; color: #2E6B12; }
.week-sub-items { display: flex; gap: 18px; }
.week-sub-item  { text-align: right; }
.week-sub-item-label { font-size: 9px; color: #6b7c61; text-transform: uppercase; }
.week-sub-item-val   { font-size: 12px; font-weight: 800; color: #0D2B0A; }

/* ── GRAND TOTAL ── */
.grand-total {
  background: #0D2B0A;
  color: #fff;
  padding: 12px 16px;
  border-radius: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
}
.grand-label { font-size: 13px; font-weight: 700; }
.grand-breakdown { display: flex; gap: 20px; }
.grand-item { text-align: center; }
.grand-item-label { font-size: 9px; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: .05em; }
.grand-item-val   { font-size: 14px; font-weight: 800; color: #A5D6A7; margin-top: 2px; }

/* ── FOOTER ── */
.report-footer {
  margin-top: 20px;
  padding-top: 10px;
  border-top: 1px solid #dce8d4;
  display: flex;
  justify-content: space-between;
  font-size: 9px;
  color: #9aab8a;
}
</style>
</head>
<body>

<!-- ── SCREEN TOOLBAR ── -->
<div class="no-print">
  <a href="assignments.php?tab=costs&section=<?= $selSection ?>&cmonth=<?= $selMonth ?>" class="btn-back">← Back</a>
  <button onclick="window.print()" class="btn-print">🖨 Print / Save as PDF</button>
  <span style="color:rgba(255,255,255,.5);font-size:12px">Tip: In the print dialog, choose "Save as PDF" to download</span>
  <!-- Language toggle -->
  <div style="display:flex;gap:6px;margin-left:8px">
    <a href="?section=<?= $selSection ?>&month=<?= $selMonth ?>&week=<?= $selWeek ?>&lang=en"
       style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;<?= !$useSi?'background:#4CAF50;color:#fff':'background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2)' ?>">
      🇬🇧 English
    </a>
    <a href="?section=<?= $selSection ?>&month=<?= $selMonth ?>&week=<?= $selWeek ?>&lang=si"
       style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;<?= $useSi?'background:#4CAF50;color:#fff':'background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2)' ?>">
      🇱🇰 සිංහල
    </a>
  </div>
  <!-- Week filter buttons -->
  <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
    <a href="cost-report-pdf.php?section=<?= $selSection ?>&month=<?= $selMonth ?>"
       style="color:<?= !$selWeek?'#A5D6A7':'rgba(255,255,255,.5)' ?>;font-size:11px;padding:4px 10px;border-radius:12px;text-decoration:none;border:1px solid rgba(255,255,255,<?= !$selWeek?.4:.2 ?>)">All Weeks</a>
    <?php foreach ($allByWeekForToolbar as $wk => $wdata): ?>
    <a href="cost-report-pdf.php?section=<?= $selSection ?>&month=<?= $selMonth ?>&week=<?= $wk ?>"
       style="color:<?= $selWeek==$wk?'#A5D6A7':'rgba(255,255,255,.5)' ?>;font-size:11px;padding:4px 10px;border-radius:12px;text-decoration:none;border:1px solid rgba(255,255,255,<?= $selWeek==$wk?.4:.2 ?>)">
      <?= $weekLabels[$wk] ?> (Mon <?= date('d M', strtotime($wdata['week_monday'])) ?> – Sun <?= date('d M', strtotime($wdata['week_sunday'])) ?>)
    </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="page">

  <!-- ── REPORT HEADER ── -->
  <div class="report-header">
    <div class="header-left">
      <div class="logo-box">
        <?php if ($logoUrl): ?>
          <img src="<?= $logoUrl ?>" alt="Logo">
        <?php else: ?>
          <span class="leaf">🌿</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="estate-name"><?= sanitize($estateName) ?></div>
        <div class="app-brand"><?= sanitize($appName) ?> · <?= sanitize($section['name']) ?></div>
      </div>
    </div>
    <div class="header-right">
      <div class="report-title">Weekly Cost Report</div>
      <div class="report-sub"><?= $monthLabel ?><?= $selWeek ? ' · '.$weekLabels[$selWeek] : '' ?></div>
      <div class="report-meta">Generated: <?= date('d M Y, H:i') ?></div>
    </div>
  </div>

  <!-- ── SUMMARY BAR ── -->
  <?php
  $totalPlucking = array_sum(array_map(fn($r) => strtolower($r['unit_label']) === 'kg' ? $r['payment'] : 0, $filteredRecords));
  $totalOther    = $grandTotal - $totalPlucking;
  ?>
  <div class="summary-bar">
    <div class="sum-item">
      <div class="sum-label">Total Labor Cost</div>
      <div class="sum-value"><?= money($grandTotal) ?></div>
    </div>
    <?php if ($grandKg > 0): ?>
    <div class="sum-item">
      <div class="sum-label">Total KG</div>
      <div class="sum-value"><?= number_format($grandKg, 1) ?> kg</div>
    </div>
    <?php endif; ?>
    <?php if ($grandPaid > 0): ?>
    <div class="sum-item">
      <div class="sum-label">Paid</div>
      <div class="sum-value" style="color:#065F46"><?= money($grandPaid) ?></div>
    </div>
    <?php endif; ?>
    <div class="sum-item" style="background:#FFF1F2">
      <div class="sum-label" style="color:#991B1B">Outstanding Balance</div>
      <div class="sum-value" style="color:#991B1B"><?= money($grandOutstanding + $totalExpensesAmt) ?></div>
    </div>
    <?php if ($totalExpensesAmt > 0): ?>
    <div class="sum-item">
      <div class="sum-label">Expenses</div>
      <div class="sum-value" style="color:#B45309"><?= money($totalExpensesAmt) ?></div>
    </div>
    <?php endif; ?>
    <div class="sum-item">
      <div class="sum-label">Section</div>
      <div class="sum-value" style="font-size:12px"><?= sanitize($section['name']) ?></div>
    </div>
  </div>

  <!-- ── WEEK BLOCKS ── -->
  <?php if (empty($byWeek)): ?>
  <p style="text-align:center;padding:40px;color:#6b7c61">No records found for this period.</p>
  <?php endif; ?>

  <?php foreach ($byWeek as $wk => $wdata):
    $wLabel     = $weekLabels[$wk];
    $dates      = $wdata['dates'];
    $weekTotalV = $wdata['total'];
    $wkAllRows  = array_merge(...array_column(array_values($dates), 'rows'));
    $wkKg       = array_sum(array_map(fn($r)=>strtolower($r['unit_label'])==='kg'?$r['quantity']:0, $wkAllRows));
    $wkPluck    = array_sum(array_map(fn($r)=>strtolower($r['unit_label'])==='kg'?$r['payment']:0, $wkAllRows));
    $wkOther    = $weekTotalV - $wkPluck;
    $wkPaid     = array_sum(array_map(fn($r)=>$r['payment_status']==='paid'?$r['payment']:0, $wkAllRows));
    $wkOutstanding = $weekTotalV - $wkPaid;
    // Calculate week expenses early so the header can include them
    $wkExpDates = $expByWeek[$wk] ?? [];
    $wkExpTotal = 0;
    foreach ($wkExpDates as $expDate => $exps) {
        foreach ($exps as $ex) $wkExpTotal += $ex['amount'];
    }
    // Week date range
    $wkDates    = array_keys($dates);
    $wkMonday   = $wdata['week_monday'];
    $wkSunday   = $wdata['week_sunday'];
    $wkStart    = date('d M', strtotime($wkMonday));
    $wkEnd      = date('d M', strtotime($wkSunday));
  ?>
  <div class="week-block">
    <!-- Week header -->
    <div class="week-header">
      <div>
        <div class="week-title"><?= $wLabel ?> &nbsp;·&nbsp; <?= date('F Y', strtotime($wkMonday)) ?></div>
        <div class="week-dates">Mon <?= $wkStart ?> – Sun <?= $wkEnd ?> &nbsp;·&nbsp; <?= count($dates) ?> day(s) &nbsp;·&nbsp; <?= count($wkAllRows) ?> record(s)</div>
      </div>
      <div class="week-total"><?= money($weekTotalV + $wkExpTotal) ?></div>
    </div>

    <!-- Dates within week -->
    <div class="date-section">
      <?php foreach ($dates as $date => $dayData): ?>
      <?php
        $dayPaid = array_sum(array_map(fn($r) => $r['payment_status']==='paid'?$r['payment']:0, $dayData['rows']));
        $dayPending = $dayData['total'] - $dayPaid;
      ?>
      <div class="date-header">
        <div class="date-label">📅 <?= date('l, d F Y', strtotime($date)) ?></div>
        <div class="date-total" style="display:flex;align-items:center;gap:6px">
          <?= money($dayData['total']) ?>
          <?php if ($dayPaid > 0 && $dayPending == 0): ?>
            <span class="paid-badge">✓ All Paid</span>
          <?php elseif ($dayPaid > 0): ?>
            <span class="paid-badge">✓ <?= money($dayPaid) ?></span>
            <span class="outstanding-badge">Due <?= money($dayPending) ?></span>
          <?php elseif ($dayPending > 0): ?>
            <span class="outstanding-badge">Due <?= money($dayPending) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php
      // Group rows by work type for cleaner display
      $byWT = [];
      foreach ($dayData['rows'] as $r) {
        $byWT[$r['work_type']][] = $r;
      }
      ?>

      <?php foreach ($byWT as $wtName => $wtRows): ?>
      <?php
        $wtTotal   = array_sum(array_column($wtRows, 'payment'));
        $wtPaid    = array_sum(array_map(fn($r) => $r['payment_status']==='paid'?$r['payment']:0, $wtRows));
        $wtPending = $wtTotal - $wtPaid;
        $wtQty     = array_sum(array_column($wtRows, 'quantity'));
        $unit      = $wtRows[0]['unit_label'];
        $isKg      = strtolower($unit) === 'kg';
        $allPaid   = ($wtPending == 0 && $wtPaid > 0);
        $nonePaid  = ($wtPaid == 0);
      ?>
      <div class="work-row">
        <div class="work-bullet" style="background:<?= $isKg?'#2E6B12':'#F59E0B' ?>"></div>
        <div class="work-details">
          <div class="work-type"><?= siLabel($wtName, $sinhala, $useSi) ?></div>
          <div class="work-qty"><?= number_format($wtQty, $isKg?1:0) ?> <?= siLabel($unit, $sinhala, $useSi) ?> &nbsp;·&nbsp; <?= count($wtRows) ?> worker(s)</div>
          <?php
          // List workers with individual paid status
          $workerParts = [];
          foreach ($wtRows as $r) {
            $name = sanitize($r['worker_name']) . ($r['is_temp'] ? ' [TEMP]' : '');
            $isPaidRow = $r['payment_status'] === 'paid';
            $workerParts[] = $name . ($isPaidRow ? ' ✓' : '');
          }
          $uniqueParts = array_unique($workerParts);
          ?>
          <div class="work-worker"><?= implode(', ', $uniqueParts) ?></div>
          <?php
          // Show notes
          foreach ($wtRows as $r) {
            $note = $r['notes'] ?? '';
            $note = preg_replace('/^TEMP:[^|]+\|\s*/u', '', $note);
            if (trim($note)) {
              echo '<div class="work-note">📝 '.sanitize(trim($note)).'</div>';
              break;
            }
          }
          ?>
        </div>
        <div class="work-cost-col">
          <div class="work-cost"><?= money($wtTotal) ?></div>
          <div class="status-tags">
            <?php if ($allPaid): ?>
              <span class="paid-badge">✓ Paid</span>
            <?php elseif (!$nonePaid): ?>
              <span class="paid-badge">✓ <?= money($wtPaid) ?></span>
              <span class="outstanding-badge">Due <?= money($wtPending) ?></span>
            <?php else: ?>
              <span class="outstanding-badge">Outstanding</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>

      <!-- Expenses for this week -->
      <?php if ($wkExpDates): ?>
      <?php foreach ($wkExpDates as $expDate => $exps): ?>
      <div class="date-header" style="background:#FEF3C7;border-color:#FCD34D">
        <div class="date-label" style="color:#92400E">🧾 <?= date('l, d F Y', strtotime($expDate)) ?> — Expenses</div>
        <div class="date-total" style="color:#92400E"><?= money(array_sum(array_column($exps,'amount'))) ?></div>
      </div>
      <?php foreach ($exps as $ex): ?>
      <div class="work-row" style="background:#FFFBEB">
        <div class="work-bullet" style="background:#F59E0B"></div>
        <div class="work-details">
          <div class="work-type" style="color:#92400E"><?= siLabel($ex['expense_type'], $sinhala, $useSi) ?></div>
          <?php if ($ex['notes']): ?><div class="work-note"><?= sanitize($ex['notes']) ?></div><?php endif; ?>
        </div>
        <div class="work-cost" style="color:#92400E"><?= money($ex['amount']) ?></div>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Week subtotal -->
    <div class="week-subtotal">
      <div class="week-sub-label">Week Total</div>
      <div class="week-sub-items">
        <?php if ($wkKg > 0): ?>
        <div class="week-sub-item">
          <div class="week-sub-item-label">Total KG</div>
          <div class="week-sub-item-val"><?= number_format($wkKg, 1) ?> kg</div>
        </div>
        <?php endif; ?>
        <div class="week-sub-item">
          <div class="week-sub-item-label">Labor Cost</div>
          <div class="week-sub-item-val"><?= money($weekTotalV) ?></div>
        </div>
        <?php if ($wkPaid > 0): ?>
        <div class="week-sub-item">
          <div class="week-sub-item-label">Paid</div>
          <div class="week-sub-item-val" style="color:#2E6B12"><?= money($wkPaid) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($wkExpTotal > 0): ?>
        <div class="week-sub-item">
          <div class="week-sub-item-label">Expenses</div>
          <div class="week-sub-item-val" style="color:#B45309"><?= money($wkExpTotal) ?></div>
        </div>
        <?php endif; ?>
        <div class="week-sub-item" style="border-left:2px solid #dce8d4;padding-left:12px">
          <div class="week-sub-item-label" style="color:#991B1B">Outstanding</div>
          <div class="week-sub-item-val" style="color:#991B1B;font-size:15px"><?= money($wkOutstanding + $wkExpTotal) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- ── GRAND TOTAL ── -->
  <div class="grand-total">
    <div class="grand-label"><?= count($byWeek) > 1 ? 'Grand Total — '.$monthLabel : $weekLabels[$selWeek].' Total — '.$monthLabel ?></div>
    <div class="grand-breakdown">
      <?php if ($grandKg > 0): ?>
      <div class="grand-item">
        <div class="grand-item-label">Total KG</div>
        <div class="grand-item-val"><?= number_format($grandKg,1) ?> kg</div>
      </div>
      <?php endif; ?>
      <div class="grand-item">
        <div class="grand-item-label">Total Labor</div>
        <div class="grand-item-val"><?= money($grandTotal) ?></div>
      </div>
      <?php if ($grandPaid > 0): ?>
      <div class="grand-item">
        <div class="grand-item-label">Paid</div>
        <div class="grand-item-val" style="color:#A5D6A7"><?= money($grandPaid) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($totalExpensesAmt > 0): ?>
      <div class="grand-item">
        <div class="grand-item-label">Expenses</div>
        <div class="grand-item-val" style="color:#FCD34D"><?= money($totalExpensesAmt) ?></div>
      </div>
      <?php endif; ?>
      <div class="grand-item" style="border-left:1px solid rgba(255,255,255,.2);padding-left:16px">
        <div class="grand-item-label" style="color:#FCA5A5">Outstanding Balance</div>
        <div class="grand-item-val" style="font-size:18px;color:#FCA5A5"><?= money($grandOutstanding + $totalExpensesAmt) ?></div>
      </div>
    </div>
  </div>

  <!-- ── FOOTER ── -->
  <div class="report-footer">
    <span><?= sanitize($estateName) ?> · <?= sanitize($section['name']) ?></span>
    <span>Generated by <?= sanitize($appName) ?> on <?= date('d M Y \a\t H:i') ?></span>
  </div>

</div><!-- .page -->
</body>
</html>
