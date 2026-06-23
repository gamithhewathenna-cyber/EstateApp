<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Payroll';

$db = db();
$selYear  = (int)($_GET['year']  ?? date('Y'));
$selMonth = (int)($_GET['month'] ?? date('n'));
$monthStr = sprintf('%04d-%02d', $selYear, $selMonth);

// Monthly totals
$totalPay = $db->prepare("SELECT COALESCE(SUM(payment),0) FROM assignments WHERE DATE_FORMAT(work_date,'%Y-%m')=?");
$totalPay->execute([$monthStr]);
$totalPay = (float)$totalPay->fetchColumn();

$totalKg = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM assignments WHERE DATE_FORMAT(work_date,'%Y-%m')=? AND work_type='plucking'");
$totalKg->execute([$monthStr]);
$totalKg = (float)$totalKg->fetchColumn();

// Worker-wise payroll
$workerPay = $db->prepare("
    SELECT w.id, w.full_name,
           COUNT(DISTINCT a.work_date) as days,
           COALESCE(SUM(CASE WHEN a.work_type='plucking' THEN a.quantity ELSE 0 END),0) as total_kg,
           COALESCE(SUM(CASE WHEN a.work_type='plucking' THEN a.payment ELSE 0 END),0) as plucking_pay,
           COALESCE(SUM(CASE WHEN a.work_type!='plucking' THEN a.payment ELSE 0 END),0) as other_pay,
           COALESCE(SUM(a.payment),0) as total_pay
    FROM workers w
    JOIN assignments a ON a.worker_id=w.id
    WHERE DATE_FORMAT(a.work_date,'%Y-%m')=?
    GROUP BY w.id, w.full_name
    ORDER BY total_pay DESC
");
$workerPay->execute([$monthStr]);
$workerPay = $workerPay->fetchAll();

// Plantation-wise payroll
$plantPay = $db->prepare("
    SELECT p.name,
           COALESCE(SUM(a.payment),0) as total_pay,
           COALESCE(SUM(CASE WHEN a.work_type='plucking' THEN a.quantity ELSE 0 END),0) as total_kg
    FROM plantations p
    JOIN assignments a ON a.plantation_id=p.id
    WHERE DATE_FORMAT(a.work_date,'%Y-%m')=?
    GROUP BY p.id, p.name ORDER BY total_pay DESC
");
$plantPay->execute([$monthStr]);
$plantPay = $plantPay->fetchAll();

// Daily summary
$daily = $db->prepare("
    SELECT work_date,
           COUNT(DISTINCT worker_id) as workers,
           COALESCE(SUM(CASE WHEN work_type='plucking' THEN quantity ELSE 0 END),0) as kg,
           COALESCE(SUM(payment),0) as pay
    FROM assignments
    WHERE DATE_FORMAT(work_date,'%Y-%m')=?
    GROUP BY work_date ORDER BY work_date DESC
");
$daily->execute([$monthStr]);
$daily = $daily->fetchAll();

// Years for dropdown
$years = range(date('Y'), date('Y') - 3);
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

include __DIR__ . '/../../includes/header.php';
?>

<!-- MONTH SELECTOR -->
<div class="page-toolbar">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <select name="month" style="width:110px">
      <?php foreach ($months as $i => $m): ?>
      <option value="<?= $i+1 ?>" <?= $selMonth===$i+1?'selected':'' ?>><?= $m ?></option>
      <?php endforeach; ?>
    </select>
    <select name="year" style="width:90px">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $selYear===$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="ti ti-search"></i> View</button>
  </form>
  <div style="margin-left:auto">
    <a href="/modules/reports/payroll_print.php?month=<?= $monthStr ?>" target="_blank" class="btn btn-outline btn-sm"><i class="ti ti-printer"></i> Print Report</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card amber">
    <div class="stat-label">Total Payroll</div>
    <div class="stat-value" style="font-size:20px"><?= formatRs($totalPay) ?></div>
    <div class="stat-sub"><?= count($workerPay) ?> workers · <?= $selMonth ?>/<?= $selYear ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total KG (Plucking)</div>
    <div class="stat-value"><?= number_format($totalKg,0) ?> kg</div>
    <div class="stat-sub"><?= $selMonth ?>/<?= $selYear ?></div>
  </div>
  <div class="stat-card teal">
    <div class="stat-label">Working Days</div>
    <div class="stat-value"><?= count($daily) ?></div>
    <div class="stat-sub">With recorded assignments</div>
  </div>
</div>

<!-- TABS -->
<div class="tab-bar">
  <button class="tab-btn active" data-tab-btn="pay" data-tab-group="pay">Worker-wise</button>
  <button class="tab-btn" data-tab-btn="plant" data-tab-group="pay">Plantation-wise</button>
  <button class="tab-btn" data-tab-btn="daily" data-tab-group="pay">Daily Summary</button>
</div>

<!-- WORKER-WISE -->
<div class="tab-pane active" data-tab-pane="pay" data-tab="pay">
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Worker</th><th>Days</th><th>Total KG</th><th>Plucking Pay</th><th>Other Work</th><th>Total Salary</th></tr>
        </thead>
        <tbody>
          <?php foreach ($workerPay as $w): ?>
          <tr>
            <td><div style="display:flex;align-items:center;gap:8px"><div class="avatar"><?= strtoupper(substr($w['full_name'],0,2)) ?></div><span class="td-bold"><?= sanitize($w['full_name']) ?></span></div></td>
            <td><?= $w['days'] ?></td>
            <td><?= number_format($w['total_kg'],1) ?> kg</td>
            <td><?= formatRs($w['plucking_pay']) ?></td>
            <td><?= $w['other_pay'] > 0 ? formatRs($w['other_pay']) : '—' ?></td>
            <td class="td-money"><?= formatRs($w['total_pay']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($workerPay)): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="ti ti-cash-off"></i><p>No payroll data for this period.</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- PLANTATION-WISE -->
<div class="tab-pane" data-tab-pane="pay" data-tab="plant">
  <div class="card">
    <?php
    $maxPP = max(array_column($plantPay ?: [['total_pay'=>1]], 'total_pay'));
    foreach ($plantPay as $p):
      $pct = $maxPP > 0 ? round(($p['total_pay']/$maxPP)*100) : 0;
    ?>
    <div class="bar-row" style="margin-bottom:14px">
      <div class="bar-label"><?= sanitize($p['name']) ?></div>
      <div class="bar-bg" style="height:14px"><div class="bar-fill" style="width:<?= $pct ?>%;height:100%"></div></div>
      <div class="bar-val"><?= formatRs($p['total_pay']) ?></div>
    </div>
    <div style="margin-left:110px;margin-top:-10px;margin-bottom:14px;font-size:11px;color:var(--text-muted)"><?= number_format($p['total_kg'],0) ?> kg collected</div>
    <?php endforeach; ?>
    <?php if (empty($plantPay)): ?>
    <div class="empty-state"><i class="ti ti-plant-off"></i><p>No data for this period.</p></div>
    <?php endif; ?>
  </div>
</div>

<!-- DAILY SUMMARY -->
<div class="tab-pane" data-tab-pane="pay" data-tab="daily">
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Workers</th><th>Total KG</th><th>Payroll</th></tr></thead>
        <tbody>
          <?php foreach ($daily as $d): ?>
          <tr>
            <td><?= formatDate($d['work_date']) ?></td>
            <td><?= $d['workers'] ?></td>
            <td><?= number_format($d['kg'],1) ?> kg</td>
            <td class="td-money"><?= formatRs($d['pay']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($daily)): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-calendar-off"></i><p>No records.</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
