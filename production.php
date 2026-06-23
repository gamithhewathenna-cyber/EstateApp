<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Tea Production';
$estateId = Auth::estateId();

$selMonth = $_GET['month'] ?? date('Y-m');
$selYear  = $_GET['year']  ?? date('Y');

// Summary
$summary = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as month_kg, COUNT(DISTINCT assignment_date) as days, COUNT(DISTINCT worker_id) as workers
    FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, $estateId, $selMonth]);
$yearKg = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND YEAR(assignment_date)=?", [$estateId, $estateId, $selYear]);
$todayKg = DB::fetchOne("SELECT COALESCE(SUM(quantity),0) as total FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND assignment_date=?", [$estateId, $estateId, today()]);

// Top workers this month
$topWorkers = DB::fetchAll("SELECT w.full_name, SUM(da.quantity) as total_kg, COUNT(DISTINCT da.assignment_date) as days, p.name as main_plantation
    FROM daily_assignments da JOIN workers w ON da.worker_id=w.id JOIN plantations p ON da.plantation_id=p.id
    WHERE da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    GROUP BY da.worker_id ORDER BY total_kg DESC LIMIT 10", [$estateId, $selMonth]);

// Plantation performance
$plantPerf = DB::fetchAll("SELECT p.name, COALESCE(SUM(da.quantity),0) as total_kg, COUNT(DISTINCT da.worker_id) as workers
    FROM plantations p LEFT JOIN daily_assignments da ON p.id=da.plantation_id AND da.estate_id=? AND da.approval_status='approved' AND da.work_type_id IN (SELECT id FROM work_types WHERE estate_id=da.estate_id AND LOWER(unit_label)='kg') AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    WHERE p.is_active=1 AND p.estate_id=? GROUP BY p.id ORDER BY total_kg DESC", [$estateId, $selMonth, $estateId]);
$maxKgPlant = max(array_column($plantPerf,'total_kg') ?: [1]);

// Monthly KG by day (for mini chart)
$dailyKg = DB::fetchAll("SELECT assignment_date, SUM(quantity) as kg FROM daily_assignments WHERE estate_id=? AND approval_status='approved' AND work_type_id IN (SELECT id FROM work_types WHERE estate_id=? AND LOWER(unit_label)='kg') AND DATE_FORMAT(assignment_date,'%Y-%m')=? GROUP BY assignment_date ORDER BY assignment_date", [$estateId, $estateId, $selMonth]);
$maxDailyKg = max(array_column($dailyKg,'kg') ?: [1]);

require_once __DIR__ . '/includes/header.php';
?>

<div class="filter-row">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <label style="font-size:13px;color:var(--gray-600)">Month:</label>
    <input type="month" name="month" value="<?= sanitize($selMonth) ?>" onchange="this.form.submit()">
    <button type="submit" class="btn btn-secondary btn-sm">View</button>
  </form>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label"><i class="ti ti-weight"></i> Today's KG</div>
    <div class="stat-value"><?= number_format($todayKg['total'],1) ?> kg</div>
    <div class="stat-sub"><?= fmtDate(today()) ?></div>
  </div>
  <div class="stat-card teal">
    <div class="stat-label"><i class="ti ti-calendar-stats"></i> Monthly KG</div>
    <div class="stat-value"><?= number_format($summary['month_kg'],0) ?> kg</div>
    <div class="stat-sub"><?= $summary['days'] ?> days · <?= $summary['workers'] ?> workers</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="ti ti-leaf"></i> Yearly KG</div>
    <div class="stat-value"><?= number_format($yearKg['total'],0) ?> kg</div>
    <div class="stat-sub">Year <?= $selYear ?></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label"><i class="ti ti-trending-up"></i> Avg Daily KG</div>
    <div class="stat-value"><?= $summary['days']>0?number_format($summary['month_kg']/$summary['days'],0):0 ?> kg</div>
    <div class="stat-sub">This month</div>
  </div>
</div>

<div class="grid-2">
  <!-- Top Workers -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="ti ti-trophy"></i> Top Workers – <?= date('F',strtotime($selMonth.'-01')) ?></div></div>
    <?php foreach ($topWorkers as $i => $w): ?>
    <div class="worker-row">
      <div class="avatar" style="background:<?= $i===0?'var(--amber-50)':($i===1?'var(--gray-50)':'var(--green-50)') ?>;color:<?= $i===0?'var(--amber-600)':($i===1?'var(--gray-600)':'var(--green-800)') ?>">
        <?= $i<3 ? ($i+1) : initials($w['full_name']) ?>
      </div>
      <div class="worker-info">
        <div class="worker-name"><?= sanitize($w['full_name']) ?></div>
        <div class="worker-sub"><?= sanitize($w['main_plantation']) ?> · <?= $w['days'] ?> days</div>
      </div>
      <div class="worker-kg"><?= number_format($w['total_kg'],1) ?> kg</div>
    </div>
    <?php endforeach; ?>
    <?php if (!$topWorkers): ?><div class="empty-state"><i class="ti ti-users"></i><p>No data this month</p></div><?php endif; ?>
  </div>

  <!-- Plantation Performance + Daily Chart -->
  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><div class="card-title"><i class="ti ti-plant-2"></i> Plantation Performance</div></div>
      <?php foreach ($plantPerf as $p): ?>
      <div class="bar-row" style="margin-bottom:12px">
        <div class="bar-label"><?= sanitize($p['name']) ?></div>
        <div class="bar-track" style="height:12px"><div class="bar-fill" style="width:<?= $maxKgPlant>0?round($p['total_kg']/$maxKgPlant*100):0 ?>%;height:100%"></div></div>
        <div class="bar-val"><?= number_format($p['total_kg'],0) ?> kg</div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="ti ti-chart-bar"></i> Daily KG This Month</div></div>
      <?php if ($dailyKg): ?>
      <div class="mini-chart" style="height:80px;gap:2px">
        <?php foreach ($dailyKg as $d): ?>
        <div class="mini-bar" style="height:<?= $maxDailyKg>0?round($d['kg']/$maxDailyKg*100):0 ?>%" title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'],1) ?> kg"></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--gray-400);margin-top:4px">
        <span>Day 1</span><span>Day <?= count($dailyKg) ?></span>
      </div>
      <?php else: ?><div class="empty-state"><i class="ti ti-chart-off"></i><p>No data yet</p></div><?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
