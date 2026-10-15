<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Reports';
$estateId = Auth::estateId();
Auth::requireAdmin();

$selMonth = $_GET['month'] ?? date('Y-m');
$selYear  = $_GET['year']  ?? date('Y');
$type     = $_GET['type']  ?? '';

// If export requested
if ($type === 'payroll_csv') {
    $data = DB::fetchAll("SELECT w.full_name, COUNT(DISTINCT da.assignment_date) as days,
        COALESCE(SUM(CASE WHEN da.work_type_id=1 THEN da.quantity ELSE 0 END),0) as total_kg,
        COALESCE(SUM(da.payment),0) as total_pay
        FROM workers w LEFT JOIN daily_assignments da ON w.id=da.worker_id AND da.estate_id=? AND da.approval_status='approved' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
        WHERE w.estate_id=? AND w.is_active=1 GROUP BY w.id ORDER BY total_pay DESC", [$estateId, $selMonth, $estateId]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payroll_'.$selMonth.'.csv"');
    echo "Worker,Days Worked,Total KG,Total Pay (Rs.)\n";
    foreach ($data as $r) echo '"'.$r['full_name'].'",'.$r['days'].','.$r['total_kg'].','.$r['total_pay']."\n";
    exit;
}

if ($type === 'kg_csv') {
    $data = DB::fetchAll("SELECT da.assignment_date, w.full_name, p.name as plantation, SUM(da.quantity) as kg
        FROM daily_assignments da JOIN workers w ON da.worker_id=w.id JOIN plantations p ON da.plantation_id=p.id
        WHERE da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
        GROUP BY da.assignment_date, da.worker_id ORDER BY da.assignment_date, kg DESC", [$estateId, $selMonth]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kg_report_'.$selMonth.'.csv"');
    echo "Date,Worker,Plantation,KG\n";
    foreach ($data as $r) echo '"'.$r['assignment_date'].'","'.$r['full_name'].'","'.$r['plantation'].'",'.$r['kg']."\n";
    exit;
}

if ($type === 'expenses_csv') {
    $data = DB::fetchAll("SELECT e.expense_date, e.expense_type, COALESCE(p.name,'All') as plantation, e.amount, e.notes
        FROM expenses e LEFT JOIN plantations p ON e.plantation_id=p.id
        WHERE e.estate_id=? AND DATE_FORMAT(e.expense_date,'%Y-%m')=? ORDER BY e.expense_date", [$estateId, $selMonth]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expenses_'.$selMonth.'.csv"');
    echo "Date,Type,Plantation,Amount (Rs.),Notes\n";
    foreach ($data as $r) echo '"'.$r['expense_date'].'","'.$r['expense_type'].'","'.$r['plantation'].'",'.$r['amount'].',"'.$r['notes'].'"'."\n";
    exit;
}

require_once __DIR__ . '/includes/header.php';

// Year monthly summary
$yearlyKg = DB::fetchAll("SELECT DATE_FORMAT(assignment_date,'%Y-%m') as month, SUM(quantity) as kg
    FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND YEAR(assignment_date)=?
    GROUP BY month ORDER BY month", [$estateId, $estateId, $selYear]);
$yearlyPay = DB::fetchAll("SELECT DATE_FORMAT(assignment_date,'%Y-%m') as month, SUM(payment) as pay
    FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND YEAR(assignment_date)=?
    GROUP BY month ORDER BY month", [$estateId, $selYear]);

// ── MONTHLY FINANCIAL REPORT (selected month only) ──────────────────
// Income = factory value (tea sold to factories this month). Expenses =
// payroll + general estate expenses + factory-side expenses. Wrapped in
// try/catch: Factory Management's tables may not exist on every install.
$finIncome = 0;
try {
    $finIncomeRow = DB::fetchOne("SELECT COALESCE(SUM(fd.factory_weight * fp.price_per_kg),0) as total
        FROM factory_deliveries fd
        JOIN factory_prices fp ON fp.factory_id=fd.factory_id AND fp.price_month=DATE_FORMAT(fd.delivery_date,'%Y-%m-01')
        WHERE fd.estate_id=? AND fd.factory_weight IS NOT NULL
          AND DATE_FORMAT(fd.delivery_date,'%Y-%m')=?", [$estateId, $selMonth]);
    $finIncome = (float)($finIncomeRow['total'] ?? 0);
} catch (Exception $e) { $finIncome = 0; }

$finFactoryExpenses = 0;
try {
    $finFactExpRow = DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM factory_expenses
        WHERE estate_id=? AND expense_month=?", [$estateId, $selMonth.'-01']);
    $finFactoryExpenses = (float)($finFactExpRow['total'] ?? 0);
} catch (Exception $e) { $finFactoryExpenses = 0; }

// Payroll + KG per section (for section-wise breakdown and revenue allocation)
$finSectionPayroll = DB::fetchAll("SELECT p.id, p.name,
    COALESCE(SUM(da.payment),0) as payroll,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.quantity ELSE 0 END),0) as kg
    FROM plantations p
    LEFT JOIN daily_assignments da ON p.id=da.plantation_id
        AND da.estate_id=? AND da.approval_status='approved'
        AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    LEFT JOIN work_types wt ON da.work_type_id=wt.id
    WHERE p.estate_id=? AND p.is_active=1
    GROUP BY p.id, p.name ORDER BY p.name", [$estateId, $selMonth, $estateId]);

// General expenses tied to a specific section this month
$finSectionExpRows = DB::fetchAll("SELECT plantation_id, COALESCE(SUM(amount),0) as total
    FROM expenses WHERE estate_id=? AND plantation_id IS NOT NULL
    AND DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY plantation_id", [$estateId, $selMonth]);
$finSectionExpByPid = array_column($finSectionExpRows, 'total', 'plantation_id');

// General expenses not tied to any section (estate-wide — e.g. shared Food)
$finUntiedExpRow = DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM expenses
    WHERE estate_id=? AND (plantation_id IS NULL OR plantation_id=0)
    AND DATE_FORMAT(expense_date,'%Y-%m')=?", [$estateId, $selMonth]);
$finUntiedExpenses = (float)($finUntiedExpRow['total'] ?? 0);

$finTotalKg = array_sum(array_column($finSectionPayroll, 'kg'));
$finTotalPayroll = array_sum(array_column($finSectionPayroll, 'payroll'));
$finTotalSectionExp = array_sum($finSectionExpByPid);

foreach ($finSectionPayroll as &$fsp) {
    $fsp['expenses']   = (float)$fsp['payroll'] + (float)($finSectionExpByPid[$fsp['id']] ?? 0);
    $fsp['est_income'] = $finTotalKg > 0 ? ((float)$fsp['kg'] / $finTotalKg) * $finIncome : 0;
    $fsp['net_profit'] = $fsp['est_income'] - $fsp['expenses'];
}
unset($fsp);

$finTotalExpenses = $finTotalPayroll + $finTotalSectionExp + $finUntiedExpenses + $finFactoryExpenses;
$finNetProfit      = $finIncome - $finTotalExpenses;
?>

<div class="filter-row">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <label style="font-size:13px;color:var(--gray-600)">Month:</label>
    <input type="month" name="month" value="<?= sanitize($selMonth) ?>">
    <label style="font-size:13px;color:var(--gray-600)">Year:</label>
    <input type="number" name="year" value="<?= sanitize($selYear) ?>" min="2020" max="2099" style="width:90px">
    <button type="submit" class="btn btn-secondary btn-sm">Update</button>
  </form>
</div>

<!-- Monthly Export -->
<div class="section-header"><div class="section-title"><i class="ti ti-file-export"></i> Monthly Reports – <?= date('F Y', strtotime($selMonth.'-01')) ?></div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px">
  <div class="report-card">
    <div class="report-icon" style="background:var(--green-50)"><i class="ti ti-weight" style="color:var(--green-600)"></i></div>
    <div class="report-info"><div class="report-title">Monthly KG Report</div><div class="report-sub">Worker & plantation production</div></div>
    <div class="report-actions">
      <a href="reports.php?type=kg_csv&month=<?= $selMonth ?>" class="btn btn-outline btn-sm"><i class="ti ti-table"></i> CSV</a>
    </div>
  </div>
  <div class="report-card">
    <div class="report-icon" style="background:var(--amber-50)"><i class="ti ti-cash" style="color:var(--amber-600)"></i></div>
    <div class="report-info"><div class="report-title">Monthly Payroll Report</div><div class="report-sub">Worker-wise salary breakdown</div></div>
    <div class="report-actions">
      <a href="reports.php?type=payroll_csv&month=<?= $selMonth ?>" class="btn btn-outline btn-sm"><i class="ti ti-table"></i> CSV</a>
    </div>
  </div>
  <div class="report-card">
    <div class="report-icon" style="background:var(--teal-50)"><i class="ti ti-receipt" style="color:var(--teal-600)"></i></div>
    <div class="report-info"><div class="report-title">Monthly Expenses</div><div class="report-sub">All expenses this month</div></div>
    <div class="report-actions">
      <a href="reports.php?type=expenses_csv&month=<?= $selMonth ?>" class="btn btn-outline btn-sm"><i class="ti ti-table"></i> CSV</a>
    </div>
  </div>
  <div class="report-card">
    <div class="report-icon" style="background:#EDE9FE"><i class="ti ti-users" style="color:#6D28D9"></i></div>
    <div class="report-info"><div class="report-title">Worker Summary</div><div class="report-sub">Attendance & earnings history</div></div>
    <div class="report-actions">
      <a href="payroll.php?month=<?= $selMonth ?>" class="btn btn-outline btn-sm"><i class="ti ti-eye"></i> View</a>
    </div>
  </div>
</div>

<!-- Monthly Financial Report -->
<div class="section-header"><div class="section-title"><i class="ti ti-report-money"></i> Monthly Financial Report – <?= date('F Y', strtotime($selMonth.'-01')) ?></div></div>
<div class="stats-grid" style="margin-bottom:16px">
  <div class="stat-card" style="border-left-color:#22c55e">
    <div class="stat-label"><i class="ti ti-trending-up" style="color:#22c55e"></i> Total Income</div>
    <div class="stat-value" style="color:#065f46"><?= money($finIncome) ?></div>
    <div class="stat-sub">Tea sold to factories this month</div>
  </div>
  <div class="stat-card" style="border-left-color:var(--red-400)">
    <div class="stat-label"><i class="ti ti-trending-down" style="color:var(--red-400)"></i> Total Expenses</div>
    <div class="stat-value" style="color:var(--red-600)"><?= money($finTotalExpenses) ?></div>
    <div class="stat-sub">Payroll + expenses + factory costs</div>
  </div>
  <div class="stat-card" style="border-left-color:<?= $finNetProfit>=0?'#22c55e':'var(--red-400)' ?>">
    <div class="stat-label"><i class="ti ti-chart-pie" style="color:<?= $finNetProfit>=0?'#22c55e':'var(--red-400)' ?>"></i> Net Profit</div>
    <div class="stat-value" style="color:<?= $finNetProfit>=0?'#065f46':'var(--red-600)' ?>"><?= money($finNetProfit) ?></div>
    <div class="stat-sub">Income − Expenses</div>
  </div>
</div>

<div class="card" style="margin-bottom:22px">
  <div class="card-header">
    <div class="card-title"><i class="ti ti-map-pin"></i> Section-wise Profit</div>
  </div>
  <div style="font-size:11px;color:var(--gray-400);padding:0 16px 10px">
    <i class="ti ti-info-circle" style="font-size:12px;vertical-align:-1px"></i>
    Factory income is recorded once per day for the whole estate, so each section's income is estimated based on its share of KG plucked this month.
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Section</th><th>KG Plucked</th><th>Income (est.)</th><th>Expenses</th><th>Net Profit</th></tr></thead>
      <tbody>
        <?php foreach ($finSectionPayroll as $fsp): ?>
        <tr>
          <td><strong><?= sanitize($fsp['name']) ?></strong></td>
          <td><?= $fsp['kg']>0?number_format($fsp['kg'],1).' kg':'—' ?></td>
          <td style="color:#065f46"><?= money($fsp['est_income']) ?></td>
          <td style="color:var(--red-600)"><?= money($fsp['expenses']) ?></td>
          <td style="font-weight:700;color:<?= $fsp['net_profit']>=0?'#065f46':'var(--red-600)' ?>"><?= money($fsp['net_profit']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$finSectionPayroll): ?>
        <tr><td colspan="5"><div class="empty-state"><i class="ti ti-map-pin-off"></i><p>No active sections.</p></div></td></tr>
        <?php endif; ?>
        <?php if ($finUntiedExpenses > 0 || $finFactoryExpenses > 0): ?>
        <tr style="background:var(--gray-50)">
          <td colspan="3" style="color:var(--gray-400);font-size:12px">General &amp; factory expenses not tied to a section</td>
          <td style="color:var(--red-600)"><?= money($finUntiedExpenses + $finFactoryExpenses) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
        <tr style="background:var(--green-50)">
          <td><strong>Estate Total</strong></td>
          <td><strong><?= number_format($finTotalKg,1) ?> kg</strong></td>
          <td><strong><?= money($finIncome) ?></strong></td>
          <td><strong><?= money($finTotalExpenses) ?></strong></td>
          <td style="font-weight:800;color:<?= $finNetProfit>=0?'#065f46':'var(--red-600)' ?>"><?= money($finNetProfit) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Yearly Summary Table -->
<div class="section-header"><div class="section-title"><i class="ti ti-chart-bar"></i> Yearly Summary – <?= $selYear ?></div></div>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Month</th><th>Total KG</th><th>Total Payroll</th></tr></thead>
      <tbody>
        <?php
        $kgByMonth  = array_column($yearlyKg,  'kg',  'month');
        $payByMonth = array_column($yearlyPay, 'pay', 'month');
        $months = [];
        for ($m=1; $m<=12; $m++) { $key = $selYear.'-'.str_pad($m,2,'0',STR_PAD_LEFT); $months[$key] = date('F', mktime(0,0,0,$m,1)); }
        $totalKg = 0; $totalPay = 0;
        foreach ($months as $key => $label):
          $kg = $kgByMonth[$key] ?? 0;
          $pay = $payByMonth[$key] ?? 0;
          $totalKg += $kg; $totalPay += $pay;
          if ($kg==0 && $pay==0 && $key > date('Y-m')) continue;
        ?>
        <tr>
          <td><?= $label ?></td>
          <td><?= $kg>0?number_format($kg,1).' kg':'—' ?></td>
          <td><?= $pay>0?money($pay):'—' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--green-50)">
          <td><strong>Year Total</strong></td>
          <td><strong><?= number_format($totalKg,1) ?> kg</strong></td>
          <td><strong><?= money($totalPay) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
