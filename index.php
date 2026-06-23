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
    CASE WHEN da.worker_id IS NULL THEN 1 ELSE 0 END as is_temp,
    SUM(da.quantity) as total_kg,
    SUM(da.payment) as total_pay,
    COUNT(DISTINCT da.assignment_date) as days
    FROM daily_assignments da
    LEFT JOIN workers w ON da.worker_id=w.id AND da.worker_id IS NOT NULL
    WHERE da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND da.assignment_date BETWEEN ? AND ?
    GROUP BY da.worker_id, SUBSTRING_INDEX(da.notes,'|',1)
    ORDER BY total_kg DESC LIMIT 5", [$estateId,$dateFrom,$dateTo]);

// Fertilizer reminders (always current)
$fertDue = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc JOIN plantations p ON fc.plantation_id=p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles WHERE estate_id=? GROUP BY plantation_id) latest ON fc.id=latest.max_id
    WHERE fc.estate_id=? AND p.estate_id=? AND p.is_active=1 AND fc.next_due_date IS NOT NULL AND fc.next_due_date != ''
    ORDER BY fc.next_due_date ASC LIMIT 4", [$estateId,$estateId,$estateId]);

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
  <div class="stat-card">
    <div class="stat-label"><i class="ti ti-users"></i> Workers</div>
    <div class="stat-value"><?= $workersRange['cnt'] ?></div>
    <div class="stat-sub">active in period · <?= $totalWorkers ?> total</div>
  </div>
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

<!-- ── ROW 2: PLANTATION KG + EXPENSES BREAKDOWN + FERTILIZER ── -->
<div class="grid-3" style="margin-bottom:20px">

  <!-- Plantation KG -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-plant-2"></i> KG by Section</div>
      <a href="production.php" class="card-action">Details</a>
    </div>
    <?php if ($plantKg && max(array_column($plantKg,'kg'))>0): ?>
      <?php foreach ($plantKg as $p): ?>
      <div class="bar-row">
        <div class="bar-label"><?= sanitize($p['name']) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $maxKg>0?round((float)$p['kg']/$maxKg*100):0 ?>%"></div></div>
        <div class="bar-val"><?= number_format((float)$p['kg'],0) ?> kg</div>
      </div>
      <?php endforeach; ?>
      <!-- Mini trend chart -->
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

  <!-- Expenses Breakdown -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-receipt"></i> Expenses Breakdown</div>
      <a href="expenses.php?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="card-action">View all</a>
    </div>
    <?php if ($expByCategory): ?>
      <!-- Big total -->
      <div style="text-align:center;padding:10px 0 14px;border-bottom:1px solid #f0f0eb;margin-bottom:12px">
        <div style="font-size:22px;font-weight:700;color:var(--amber-600)"><?= money($expRange['total']) ?></div>
        <div style="font-size:11px;color:var(--gray-400)">Total Expenses</div>
      </div>
      <?php foreach ($expByCategory as $ec):
        $pct  = $maxExpCat>0 ? round($ec['total']/$maxExpCat*100) : 0;
        $icon = $expIcons[$ec['expense_type']] ?? 'ti-dots-circle-horizontal';
        $col  = $expText[$ec['expense_type']] ?? 'var(--gray-600)';
      ?>
      <div style="margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;align-items:center">
          <span style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px">
            <i class="ti <?= $icon ?>" style="color:<?= $col ?>;font-size:14px"></i>
            <?= sanitize($ec['expense_type']) ?>
            <span style="font-weight:400;color:var(--gray-400);font-size:11px">(<?= $ec['cnt'] ?>)</span>
          </span>
          <span style="font-size:12px;font-weight:700;color:var(--amber-600)"><?= money($ec['total']) ?></span>
        </div>
        <div style="height:6px;background:var(--gray-50);border-radius:4px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:var(--amber-400);border-radius:4px"></div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state"><i class="ti ti-receipt-off"></i><p>No expenses in this period</p></div>
    <?php endif; ?>
  </div>

  <!-- Fertilizer Reminders (always current) -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-droplet"></i> Fertilizer Due</div>
      <a href="fertilizer.php" class="card-action">View all</a>
    </div>
    <?php if ($fertDue): ?>
      <?php foreach ($fertDue as $f):
        if (empty($f['next_due_date'])) continue;
        $days = daysUntil($f['next_due_date']);
        $cls  = $days<=3?'due-urgent':($days<=10?'due-soon':'due-ok');
        $dotc = $days<=3?'var(--red-400)':($days<=10?'var(--amber-400)':'var(--green-400)');
        $lbl  = $days<=0?'Overdue':'In '.$days.'d';
      ?>
      <div class="fert-item">
        <div class="fert-dot" style="background:<?= $dotc ?>"></div>
        <div class="fert-info">
          <div class="fert-name"><?= sanitize($f['plantation_name']) ?></div>
          <div class="fert-date"><?= sanitize($f['fertilizer_type']) ?> · <?= fmtDate($f['applied_date']) ?></div>
        </div>
        <div class="fert-due <?= $cls ?>"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state"><i class="ti ti-check"></i><p>No upcoming cycles</p></div>
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

  <!-- Top Workers -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-trophy"></i> Top Workers</div>
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

  <!-- Right column: Attendance + Recent Expenses -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Today's Attendance (always today) -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="ti ti-activity"></i> Today's Attendance</div>
        <span style="font-size:11px;color:var(--gray-400)"><?= fmtDate($today) ?></span>
      </div>
      <div style="display:flex;gap:10px">
        <div style="flex:1;text-align:center;padding:10px 6px;background:var(--green-50);border-radius:var(--radius-md)">
          <div style="font-size:20px;font-weight:700;color:var(--green-800)"><?= $presentToday ?></div>
          <div style="font-size:11px;color:var(--green-600)">Present</div>
        </div>
        <div style="flex:1;text-align:center;padding:10px 6px;background:var(--amber-50);border-radius:var(--radius-md)">
          <div style="font-size:20px;font-weight:700;color:var(--amber-600)"><?= $absentToday ?></div>
          <div style="font-size:11px;color:var(--amber-600)">Absent</div>
        </div>
        <div style="flex:1;text-align:center;padding:10px 6px;background:var(--teal-50);border-radius:var(--radius-md)">
          <div style="font-size:20px;font-weight:700;color:var(--teal-600)"><?= number_format((float)$kgToday['total'],0) ?></div>
          <div style="font-size:11px;color:var(--teal-600)">kg today</div>
        </div>
      </div>
    </div>

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
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
