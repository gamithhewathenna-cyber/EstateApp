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
