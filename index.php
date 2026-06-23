<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Dashboard';
$estateId = Auth::estateId();

// Date range — default to current month
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? today();
$thisMonth = date('Y-m');
$today     = today();

// Validate dates
if ($dateFrom > $dateTo) $dateFrom = date('Y-m-01');

// Label for range
$isToday     = ($dateFrom === $today && $dateTo === $today);
$isThisMonth = ($dateFrom === date('Y-m-01') && $dateTo === $today);
$isLastMonth = ($dateFrom === date('Y-m-01', strtotime('first day of last month')) && $dateTo === date('Y-m-t', strtotime('last day of last month')));
if ($isToday)          $rangeLabel = 'Today — ' . fmtDate($today);
elseif ($isThisMonth)  $rangeLabel = 'This Month — ' . date('F Y');
elseif ($isLastMonth)  $rangeLabel = 'Last Month — ' . date('F Y', strtotime('last month'));
else                   $rangeLabel = fmtDate($dateFrom) . ' → ' . fmtDate($dateTo);

// ── STATS ──────────────────────────────────────────────
// Workers in range
$workersRange  = DB::fetchOne("SELECT COUNT(DISTINCT worker_id) as cnt FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND assignment_date BETWEEN ? AND ?", [$estateId,$dateFrom,$dateTo]);
$totalActive   = DB::fetchOne("SELECT COUNT(*) as cnt FROM workers WHERE estate_id=? AND is_active=1", [$estateId]);

// KG in range (plucking only)
$kgRange  = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND assignment_date BETWEEN ? AND ?", [$estateId,$estateId,$dateFrom,$dateTo]);
$kgToday  = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND assignment_date=?", [$estateId,$estateId,$today]);

// Payroll in range
$payRange = DB::fetchOne("SELECT COALESCE(SUM(payment),0) as total FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND assignment_date BETWEEN ? AND ?", [$estateId,$dateFrom,$dateTo]);

// Expenses in range
$expRange = DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE estate_id=? AND expense_date BETWEEN ? AND ?", [$estateId,$dateFrom,$dateTo]);

// Expenses by category in range
$expByCategory = DB::fetchAll("SELECT expense_type, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt 
    FROM expenses WHERE estate_id=? AND expense_date BETWEEN ? AND ? 
    GROUP BY expense_type ORDER BY total DESC", [$estateId,$dateFrom,$dateTo]);
$maxExpCat = max(array_column($expByCategory,'total') ?: [1]);

// Workers today
$workersToday = DB::fetchOne("SELECT COUNT(DISTINCT worker_id) as cnt FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND assignment_date=?", [$estateId, $today]);
$presentToday = (int)($workersToday['cnt'] ?? 0);
$totalWorkers = (int)($totalActive['cnt'] ?? 0);
$absentToday  = max(0, $totalWorkers - $presentToday);

// Plantation KG in range
$plantKg = DB::fetchAll("SELECT p.name, COALESCE(SUM(da.quantity),0) as kg
    FROM plantations p 
    LEFT JOIN daily_assignments da ON p.id=da.plantation_id AND da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND da.assignment_date BETWEEN ? AND ?
    WHERE p.estate_id=? AND p.is_active=1 GROUP BY p.id ORDER BY kg DESC", [$estateId,$dateFrom,$dateTo,$estateId]);
$maxKg = max(array_column($plantKg,'kg') ?: [1]);

// Top workers in range
$topWorkers = DB::fetchAll("SELECT 
    COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(da.notes,'|',1),'TEMP:',''))) as full_name,
    CASE WHEN (da.worker_id IS NULL OR da.worker_id = 0) THEN 1 ELSE 0 END as is_temp,
    SUM(da.quantity) as total_kg,
    SUM(da.payment) as total_pay,
    COUNT(DISTINCT da.assignment_date) as days
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id=w.id AND da.worker_id > 0
    WHERE da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND da.assignment_date BETWEEN ? AND ?
    GROUP BY da.worker_id, SUBSTRING_INDEX(da.notes,'|',1)
    ORDER BY total_kg DESC LIMIT 5", [$estateId,$dateFrom,$dateTo]);

// Fertilizer upcoming reminders (all active sections, sorted by urgency)
$fertDue = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc JOIN plantations p ON fc.plantation_id=p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles WHERE estate_id=? GROUP BY plantation_id) latest ON fc.id=latest.max_id
    WHERE fc.estate_id=? AND p.estate_id=? AND p.is_active=1 AND fc.next_due_date IS NOT NULL AND fc.next_due_date != ''
    ORDER BY fc.next_due_date ASC", [$estateId,$estateId,$estateId]);

// Pre-group fertilizer cycles by urgency (avoids arrow-function edge cases)
$fertOverdue = []; $fertUrgent = []; $fertSoon = []; $fertOk = [];
foreach ($fertDue as $fc) {
    $fd = daysUntil($fc['next_due_date']);
    if ($fd <= 0)       $fertOverdue[] = $fc;
    elseif ($fd <= 7)   $fertUrgent[]  = $fc;
    elseif ($fd <= 21)  $fertSoon[]    = $fc;
    else                $fertOk[]      = $fc;
}

// Recent expenses in range
$recentExp = DB::fetchAll("SELECT e.*, p.name as plantation_name 
    FROM expenses e LEFT JOIN plantations p ON e.plantation_id=p.id 
    WHERE e.estate_id=? AND e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC LIMIT 5", [$estateId,$dateFrom,$dateTo]);

// Daily KG trend in range (for mini chart)
$dailyKg = DB::fetchAll("SELECT assignment_date, SUM(quantity) as kg 
    FROM daily_assignments WHERE estate_id=? AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND assignment_date BETWEEN ? AND ? 
    GROUP BY assignment_date ORDER BY assignment_date", [$estateId,$estateId,$dateFrom,$dateTo]);
$maxDailyKg = max(array_column($dailyKg,'kg') ?: [1]);

// ── FIXED-PERIOD METRICS (always current year / month / week) ──────────
$yearlyKg = DB::fetchOne("SELECT COALESCE(SUM(da.quantity),0) as total
    FROM daily_assignments da
    JOIN work_types wt ON da.work_type_id=wt.id
    WHERE da.estate_id=? AND da.approval_status='approved'
    AND LOWER(wt.unit_label)='kg' AND YEAR(da.assignment_date)=YEAR(CURDATE())", [$estateId]);

$currentMonthKg = DB::fetchOne("SELECT COALESCE(SUM(da.quantity),0) as total
    FROM daily_assignments da
    JOIN work_types wt ON da.work_type_id=wt.id
    WHERE da.estate_id=? AND da.approval_status='approved'
    AND LOWER(wt.unit_label)='kg' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?",
    [$estateId, date('Y-m')]);

$weekStart    = date('Y-m-d', strtotime('monday this week'));
$weeklyExpAmt = DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total
    FROM expenses WHERE estate_id=? AND expense_date BETWEEN ? AND ?",
    [$estateId, $weekStart, $today]);

$currentMonthPayroll = DB::fetchOne("SELECT COALESCE(SUM(payment),0) as total
    FROM daily_assignments WHERE estate_id=? AND approval_status='approved'
    AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, date('Y-m')]);

// Section cost + KG for the selected date range
$sectionCosts    = DB::fetchAll("SELECT p.name,
    COALESCE(SUM(da.payment),0) as cost,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.quantity ELSE 0 END),0) as kg
    FROM plantations p
    LEFT JOIN daily_assignments da ON p.id=da.plantation_id
        AND da.estate_id=? AND da.approval_status='approved'
        AND da.assignment_date BETWEEN ? AND ?
    LEFT JOIN work_types wt ON da.work_type_id=wt.id
    WHERE p.estate_id=? AND p.is_active=1
    GROUP BY p.id, p.name ORDER BY cost DESC", [$estateId, $dateFrom, $dateTo, $estateId]);
$maxSectionCost = max(array_column($sectionCosts,'cost') ?: [1]);
$maxSectionKg2  = max(array_column($sectionCosts,'kg')   ?: [1]);

// Expense icons
$expIcons  = ['Spray Can'=>'ti-spray','Pohora'=>'ti-leaf','Dolomite'=>'ti-mountain','Food'=>'ti-salad','Transport'=>'ti-truck','Equipment'=>'ti-tool','Miscellaneous'=>'ti-dots-circle-horizontal'];
$expColors = ['Spray Can'=>'var(--teal-50)','Pohora'=>'var(--green-50)','Dolomite'=>'#EDE9FE','Food'=>'var(--amber-50)','Transport'=>'var(--teal-50)','Equipment'=>'var(--green-50)','Miscellaneous'=>'var(--gray-50)'];
$expText   = ['Spray Can'=>'var(--teal-600)','Pohora'=>'var(--green-600)','Dolomite'=>'#6D28D9','Food'=>'var(--amber-600)','Transport'=>'var(--teal-400)','Equipment'=>'var(--green-600)','Miscellaneous'=>'var(--gray-600)'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
.range-bar{background:#fff;border:1px solid #e8ede5;border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.range-label{font-size:14px;font-weight:700;color:var(--green-900);display:flex;align-items:center;gap:7px;flex:1;min-width:180px}
.shortcut-btn{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid #e8ede5;background:#fff;cursor:pointer;color:var(--gray-600);text-decoration:none;white-space:nowrap;transition:all .15s}
.shortcut-btn:hover{background:var(--green-50);border-color:var(--green-200);color:var(--green-800)}
.shortcut-btn.active{background:var(--green-600);border-color:var(--green-600);color:#fff}
.range-inputs{display:flex;align-items:center;gap:6px}
.range-inputs input[type=date]{font-size:12px;padding:5px 8px;border:1px solid #d8ddd5;border-radius:var(--radius-md);color:var(--gray-800)}
</style>

<!-- ── DATE RANGE BAR ─────────────────────────────── -->
<div class="range-bar">
  <div class="range-label">
    <i class="ti ti-calendar-stats" style="color:var(--green-400)"></i>
    <?= $rangeLabel ?>
  </div>
  <!-- Shortcuts -->
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php
    $shortcuts = [
      'Today'      => [$today, $today],
      'This Week'  => [date('Y-m-d',strtotime('monday this week')), $today],
      'This Month' => [date('Y-m-01'), $today],
      'Last Month' => [date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month'))],
      'This Year'  => [date('Y-01-01'), $today],
    ];
    foreach ($shortcuts as $label => [$f,$t]):
      $active = ($dateFrom===$f && $dateTo===$t);
    ?>
    <a href="index.php?from=<?= $f ?>&to=<?= $t ?>"
       class="shortcut-btn <?= $active?'active':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <!-- Custom range -->
  <form method="GET" class="range-inputs">
    <input type="date" name="from" value="<?= $dateFrom ?>" title="From date">
    <span style="color:var(--gray-400);font-size:12px">→</span>
    <input type="date" name="to" value="<?= $dateTo ?>" title="To date">
    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-search"></i></button>
  </form>
</div>

<!-- ── QUICK ACTIONS ─────────────────────────────── -->
<div class="quick-actions" style="margin-bottom:20px">
  <a href="assignments.php" class="quick-btn"><i class="ti ti-plus"></i> New Assignment</a>
  <a href="workers.php?action=add" class="quick-btn"><i class="ti ti-user-plus"></i> Add Worker</a>
  <a href="expenses.php?action=add" class="quick-btn"><i class="ti ti-receipt-2"></i> Add Expense</a>
  <a href="fertilizer.php?action=add" class="quick-btn"><i class="ti ti-droplet-plus"></i> Log Fertilizer</a>
</div>

<!-- ── STAT CARDS ─────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card teal">
    <div class="stat-label"><i class="ti ti-weight"></i> KG Plucked</div>
    <div class="stat-value"><?= number_format((float)$kgRange['total'],0) ?> kg</div>
    <div class="stat-sub">Today: <?= number_format((float)$kgToday['total'],1) ?> kg</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="ti ti-cash"></i> Payroll</div>
    <div class="stat-value"><?= moneyShort($payRange['total']) ?></div>
    <div class="stat-sub">Total for period</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label"><i class="ti ti-receipt"></i> Expenses</div>
    <div class="stat-value"><?= moneyShort($expRange['total']) ?></div>
    <div class="stat-sub">Total for period</div>
  </div>
</div>

<!-- ── FIXED-PERIOD STAT CARDS ────────────────────────── -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card teal" style="border-left:3px solid var(--teal-400)">
    <div class="stat-label"><i class="ti ti-leaf"></i> Plucked KG (This Month)</div>
    <div class="stat-value"><?= number_format((float)$currentMonthKg['total'],0) ?> <span style="font-size:14px;font-weight:500">kg</span></div>
    <div class="stat-sub"><?= date('F Y') ?></div>
  </div>
  <div class="stat-card amber" style="border-left:3px solid var(--amber-400)">
    <div class="stat-label"><i class="ti ti-receipt"></i> Weekly Expenses</div>
    <div class="stat-value"><?= moneyShort($weeklyExpAmt['total']) ?></div>
    <div class="stat-sub">Mon <?= date('d M', strtotime($weekStart)) ?> – today</div>
  </div>
  <div class="stat-card" style="border-left:3px solid var(--green-600)">
    <div class="stat-label"><i class="ti ti-cash"></i> Assignment Cost</div>
    <div class="stat-value"><?= moneyShort($currentMonthPayroll['total']) ?></div>
    <div class="stat-sub"><?= date('F Y') ?> payroll</div>
  </div>
</div>

<!-- ── UPCOMING FERTILIZER REMINDERS ── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header" style="margin-bottom:14px">
    <div class="card-title"><i class="ti ti-bell-ringing" style="color:var(--amber-500)"></i> Upcoming Fertilizer Reminders</div>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if (count($fertOverdue)): ?>
        <span style="font-size:11px;font-weight:700;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:2px 8px;border-radius:20px"><?= count($fertOverdue) ?> Overdue</span>
      <?php endif; ?>
      <?php if (count($fertUrgent)): ?>
        <span style="font-size:11px;font-weight:700;background:#fffbeb;color:#d97706;border:1px solid #fcd34d;padding:2px 8px;border-radius:20px"><?= count($fertUrgent) ?> Urgent</span>
      <?php endif; ?>
      <a href="fertilizer.php" class="card-action">View all</a>
    </div>
  </div>

  <?php if ($fertDue): ?>
  <div style="display:flex;flex-direction:column;gap:8px">

  <?php
  $groups = [
    ['items'=>$fertOverdue, 'label'=>'Overdue',       'border'=>'#ef4444', 'bg'=>'#fef2f2', 'badge_bg'=>'#dc2626', 'icon'=>'ti-alert-triangle'],
    ['items'=>$fertUrgent,  'label'=>'Due This Week',  'border'=>'#f59e0b', 'bg'=>'#fffbeb', 'badge_bg'=>'#d97706', 'icon'=>'ti-clock-exclamation'],
    ['items'=>$fertSoon,    'label'=>'Due in 3 Weeks', 'border'=>'#3b82f6', 'bg'=>'#eff6ff', 'badge_bg'=>'#2563eb', 'icon'=>'ti-calendar-event'],
    ['items'=>$fertOk,      'label'=>'Upcoming',       'border'=>'#22c55e', 'bg'=>'#f0fdf4', 'badge_bg'=>'#16a34a', 'icon'=>'ti-calendar-check'],
  ];
  foreach ($groups as $g):
    if (!count($g['items'])) continue;
  ?>
  <div style="margin-bottom:4px">
    <div style="font-size:10px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;display:flex;align-items:center;gap:5px">
      <i class="ti <?= $g['icon'] ?>" style="font-size:12px;color:<?= $g['border'] ?>"></i>
      <?= $g['label'] ?>
    </div>
    <?php foreach ($g['items'] as $f):
      $days    = daysUntil($f['next_due_date']);
      $dueText = $days < 0  ? abs($days).' day'.( abs($days)>1?'s':'').' overdue'
               : ($days === 0 ? 'Due today'
               : 'In '.$days.' day'.($days>1?'s':''));
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:<?= $g['bg'] ?>;border-left:3px solid <?= $g['border'] ?>;border-radius:0 8px 8px 0;margin-bottom:6px">
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:700;color:var(--green-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= sanitize($f['plantation_name']) ?>
        </div>
        <div style="font-size:11px;color:var(--gray-500);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span><i class="ti ti-droplet" style="font-size:11px"></i> <?= sanitize($f['fertilizer_type']) ?></span>
          <?php if (!empty($f['amount_kg'])): ?>
          <span>· <?= sanitize($f['amount_kg']) ?> kg</span>
          <?php endif; ?>
          <span>· Last applied <?= fmtDate($f['applied_date']) ?></span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:11px;font-weight:800;color:#fff;background:<?= $g['badge_bg'] ?>;padding:3px 10px;border-radius:20px;white-space:nowrap"><?= $dueText ?></div>
        <div style="font-size:10px;color:var(--gray-400);margin-top:3px"><?= fmtDate($f['next_due_date']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  </div>
  <?php else: ?>
    <div class="empty-state"><i class="ti ti-circle-check" style="color:var(--green-400)"></i><p>All fertilizer cycles are up to date</p></div>
  <?php endif; ?>
</div>

<!-- ── SECTION COST + KG BY SECTION ────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">

  <!-- Section Cost -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-building-estate"></i> Section Cost</div>
      <span style="font-size:11px;color:var(--gray-400)"><?= fmtDate($dateFrom) ?> → <?= fmtDate($dateTo) ?></span>
    </div>
    <?php if ($sectionCosts && max(array_column($sectionCosts,'cost')) > 0): ?>
      <?php foreach ($sectionCosts as $sc): ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
          <span style="font-size:13px;font-weight:600;color:var(--green-900)"><?= sanitize($sc['name']) ?></span>
          <span style="font-size:13px;font-weight:700;color:var(--green-700)"><?= money($sc['cost']) ?></span>
        </div>
        <div style="height:7px;background:var(--gray-50);border-radius:4px;overflow:hidden;margin-bottom:3px">
          <div style="width:<?= $maxSectionCost>0?round($sc['cost']/$maxSectionCost*100):0 ?>%;height:100%;background:linear-gradient(90deg,var(--green-400),var(--green-600));border-radius:4px"></div>
        </div>
        <div style="font-size:11px;color:var(--gray-400)"><?= number_format((float)$sc['kg'],1) ?> kg plucked</div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state"><i class="ti ti-building-off"></i><p>No section data for this period</p></div>
    <?php endif; ?>
  </div>

  <!-- KG by Section (with mini trend) -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-weight"></i> KG by Section</div>
      <span style="font-size:11px;color:var(--gray-400)"><?= fmtDate($dateFrom) ?> → <?= fmtDate($dateTo) ?></span>
    </div>
    <?php if ($sectionCosts && max(array_column($sectionCosts,'kg')) > 0): ?>
      <?php foreach ($sectionCosts as $sc): ?>
      <div class="bar-row">
        <div class="bar-label"><?= sanitize($sc['name']) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $maxSectionKg2>0?round((float)$sc['kg']/$maxSectionKg2*100):0 ?>%"></div></div>
        <div class="bar-val"><?= number_format((float)$sc['kg'],0) ?> kg</div>
      </div>
      <?php endforeach; ?>
      <?php if (count($dailyKg) > 1): ?>
      <div style="margin-top:12px;padding-top:10px;border-top:1px solid #f0f0eb">
        <div style="font-size:11px;color:var(--gray-400);margin-bottom:6px">Daily KG trend</div>
        <div class="mini-chart" style="height:40px;gap:2px">
          <?php foreach ($dailyKg as $d): ?>
          <div class="mini-bar" style="height:<?= $maxDailyKg>0?round((float)$d['kg']/$maxDailyKg*100):0 ?>%"
               title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'],1) ?> kg"></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty-state"><i class="ti ti-leaf-off"></i><p>No KG data for this period</p></div>
    <?php endif; ?>
  </div>

</div>

<!-- ── PERIOD SUMMARY BAR ─────────────────────────── -->
<?php if ($payRange['total'] > 0 || $expRange['total'] > 0): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--green-400)">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
    <i class="ti ti-calculator" style="color:var(--green-600);font-size:18px"></i>
    <span style="font-size:14px;font-weight:700;color:var(--green-900)">Period Cost Summary</span>
    <span style="font-size:12px;color:var(--gray-400);margin-left:4px"><?= fmtDate($dateFrom) ?> → <?= fmtDate($dateTo) ?></span>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
    <div style="background:var(--green-50);border-radius:var(--radius-md);padding:14px 16px">
      <div style="font-size:11px;font-weight:700;color:var(--green-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">
        <i class="ti ti-cash"></i> Payroll
      </div>
      <div style="font-size:20px;font-weight:700;color:var(--green-800)"><?= money($payRange['total']) ?></div>
    </div>
    <div style="background:var(--amber-50);border-radius:var(--radius-md);padding:14px 16px">
      <div style="font-size:11px;font-weight:700;color:var(--amber-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">
        <i class="ti ti-receipt"></i> Expenses
      </div>
      <div style="font-size:20px;font-weight:700;color:var(--amber-600)"><?= money($expRange['total']) ?></div>
    </div>
    <div style="background:var(--red-50);border-radius:var(--radius-md);padding:14px 16px;border:1px solid #fca5a5">
      <div style="font-size:11px;font-weight:700;color:var(--red-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">
        <i class="ti ti-sum"></i> Total Cost
      </div>
      <div style="font-size:20px;font-weight:700;color:var(--red-600)"><?= money($payRange['total'] + $expRange['total']) ?></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── ROW 3: TOP WORKERS + TODAY ATTENDANCE + RECENT EXPENSES ── -->
<div class="grid-2" style="margin-bottom:20px">

  <!-- Top Workers by KG -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-trophy"></i> Top Workers by KG</div>
      <a href="production.php?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="card-action">See all</a>
    </div>
    <?php if ($topWorkers): ?>
      <?php foreach ($topWorkers as $i => $w):
        $isTemp = !empty($w['is_temp']);
        $avatarBg = $isTemp ? 'var(--amber-50)' : ($i===0?'var(--amber-50)':($i===1?'var(--gray-50)':'var(--green-50)'));
        $avatarClr= $isTemp ? 'var(--amber-700)' : ($i===0?'var(--amber-600)':($i===1?'var(--gray-600)':'var(--green-800)'));
      ?>
      <div class="worker-row">
        <div class="avatar" style="background:<?= $avatarBg ?>;color:<?= $avatarClr ?>">
          <?= $i<3 ? ($i+1) : initials($w['full_name']) ?>
        </div>
        <div class="worker-info">
          <div class="worker-name" style="display:flex;align-items:center;gap:5px">
            <?= sanitize($w['full_name']) ?>
            <?php if ($isTemp): ?>
            <span style="font-size:9px;font-weight:700;background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200);padding:1px 5px;border-radius:8px">TEMP</span>
            <?php endif; ?>
          </div>
          <div class="worker-sub"><?= $w['days'] ?> day<?= $w['days']>1?'s':'' ?> · <?= money($w['total_pay']) ?></div>
        </div>
        <div class="worker-kg"><?= number_format((float)$w['total_kg'],1) ?> kg</div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state"><i class="ti ti-users"></i><p>No assignments in this period</p></div>
    <?php endif; ?>
  </div>

  <!-- Recent Expenses -->
  <div>

    <!-- Recent Expenses in range -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="ti ti-receipt"></i> Recent Expenses</div>
        <a href="expenses.php?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="card-action">View all</a>
      </div>
      <?php if ($recentExp): ?>
        <?php foreach ($recentExp as $e):
          $icon = $expIcons[$e['expense_type']] ?? 'ti-dots-circle-horizontal';
          $bg   = $expColors[$e['expense_type']] ?? 'var(--gray-50)';
          $col  = $expText[$e['expense_type']] ?? 'var(--gray-600)';
        ?>
        <div class="expense-row">
          <div class="exp-icon" style="background:<?= $bg ?>">
            <i class="ti <?= $icon ?>" style="color:<?= $col ?>;font-size:16px"></i>
          </div>
          <div class="exp-info">
            <div class="exp-name"><?= sanitize($e['expense_type']) ?></div>
            <div class="exp-sub"><?= sanitize($e['plantation_name'] ?? 'General') ?> · <?= fmtDate($e['expense_date']) ?>
              <?php if ($e['notes']): ?> · <?= sanitize(substr($e['notes'],0,30)) ?><?php endif; ?>
            </div>
          </div>
          <div class="exp-amt" style="color:var(--amber-600)"><?= money($e['amount']) ?></div>
        </div>
        <?php endforeach; ?>
        <!-- Expenses total -->
        <div style="display:flex;justify-content:space-between;padding-top:10px;border-top:1px solid #f0f0eb;margin-top:4px;font-size:13px">
          <span style="color:var(--gray-600)">Total in period</span>
          <strong style="color:var(--amber-600)"><?= money($expRange['total']) ?></strong>
        </div>
      <?php else: ?>
        <div class="empty-state" style="padding:16px"><i class="ti ti-receipt-off"></i><p>No expenses in this period</p></div>
      <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
