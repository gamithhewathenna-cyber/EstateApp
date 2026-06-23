<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Tea Production';

$db = db();
$selYear  = (int)($_GET['year']  ?? date('Y'));
$selMonth = (int)($_GET['month'] ?? date('n'));
$monthStr = sprintf('%04d-%02d', $selYear, $selMonth);
$today    = date('Y-m-d');

// Stats
$todayKg   = $db->query("SELECT COALESCE(SUM(quantity),0) FROM assignments WHERE work_date='$today' AND work_type='plucking'")->fetchColumn();
$monthKg   = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM assignments WHERE DATE_FORMAT(work_date,'%Y-%m')=? AND work_type='plucking'");
$monthKg->execute([$monthStr]); $monthKg = (float)$monthKg->fetchColumn();
$yearKg    = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM assignments WHERE YEAR(work_date)=? AND work_type='plucking'");
$yearKg->execute([$selYear]); $yearKg = (float)$yearKg->fetchColumn();
$daysCount = $db->prepare("SELECT COUNT(DISTINCT work_date) FROM assignments WHERE DATE_FORMAT(work_date,'%Y-%m')=? AND work_type='plucking'");
$daysCount->execute([$monthStr]); $daysCount = (int)$daysCount->fetchColumn();
$avgDay = $daysCount > 0 ? round($monthKg / $daysCount, 1) : 0;

// Worker-wise KG this month
$workerKg = $db->prepare("
    SELECT w.full_name, COALESCE(SUM(a.quantity),0) as kg, COUNT(DISTINCT a.work_date) as days
    FROM workers w
    JOIN assignments a ON a.worker_id=w.id AND a.work_type='plucking'
    WHERE DATE_FORMAT(a.work_date,'%Y-%m')=?
    GROUP BY w.id, w.full_name ORDER BY kg DESC
");
$workerKg->execute([$monthStr]); $workerKg = $workerKg->fetchAll();

// Plantation-wise KG
$plantKg = $db->prepare("
    SELECT p.name, COALESCE(SUM(a.quantity),0) as kg
    FROM plantations p
    LEFT JOIN assignments a ON a.plantation_id=p.id AND a.work_type='plucking' AND DATE_FORMAT(a.work_date,'%Y-%m')=?
    WHERE p.status='active'
    GROUP BY p.id, p.name ORDER BY kg DESC
");
$plantKg->execute([$monthStr]); $plantKg = $plantKg->fetchAll();
$maxPlantKg = max(array_column($plantKg ?: [['kg'=>1]], 'kg'));

// Monthly trend (last 6 months)
$trend = $db->query("
    SELECT DATE_FORMAT(work_date,'%Y-%m') as mon, COALESCE(SUM(quantity),0) as kg
    FROM assignments
    WHERE work_type='plucking' AND work_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY mon ORDER BY mon
")->fetchAll();
$maxTrendKg = max(array_column($trend ?: [['kg'=>1]], 'kg'));

$years = range(date('Y'), date('Y')-3);
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

include __DIR__ . '/../../includes/header.php';
?>

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
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-label">Today's KG</div><div class="stat-value"><?= number_format($todayKg,1) ?> <span style="font-size:12px;font-weight:400">kg</span></div></div>
  <div class="stat-card teal"><div class="stat-label">Monthly KG</div><div class="stat-value"><?= number_format($monthKg,0) ?> <span style="font-size:12px;font-weight:400">kg</span></div><div class="stat-sub"><?= $months[$selMonth-1] ?> <?= $selYear ?></div></div>
  <div class="stat-card"><div class="stat-label">Yearly KG</div><div class="stat-value"><?= number_format($yearKg,0) ?> <span style="font-size:12px;font-weight:400">kg</span></div><div class="stat-sub"><?= $selYear ?></div></div>
  <div class="stat-card amber"><div class="stat-label">Avg Daily KG</div><div class="stat-value"><?= $avgDay ?> <span style="font-size:12px;font-weight:400">kg</span></div><div class="stat-sub">Over <?= $daysCount ?> working days</div></div>
</div>

<div class="grid-2">
  <!-- Top workers -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-trophy"></i> Worker KG — <?= $months[$selMonth-1] ?> <?= $selYear ?></div>
    </div>
    <?php if ($workerKg): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Worker</th><th>Days</th><th>Total KG</th><th>Avg/Day</th></tr></thead>
        <tbody>
          <?php foreach ($workerKg as $i => $w): ?>
          <tr>
            <td style="font-weight:700;color:<?= $i===0?'var(--amber-400)':($i===1?'var(--gray-400)':'var(--text-muted)') ?>"><?= $i+1 ?></td>
            <td class="td-bold"><?= sanitize($w['full_name']) ?></td>
            <td><?= $w['days'] ?></td>
            <td class="td-money"><?= number_format($w['kg'],1) ?> kg</td>
            <td class="td-muted"><?= $w['days']>0?number_format($w['kg']/$w['days'],1):0 ?> kg</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="ti ti-users"></i><p>No data this month</p></div>
    <?php endif; ?>
  </div>

  <div>
    <!-- Plantation performance -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-title" style="margin-bottom:14px"><i class="ti ti-plant-2"></i> Plantation Performance</div>
      <?php foreach ($plantKg as $p):
        $pct = $maxPlantKg > 0 ? round(($p['kg']/$maxPlantKg)*100) : 0;
      ?>
      <div class="bar-row">
        <div class="bar-label"><?= sanitize($p['name']) ?></div>
        <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
        <div class="bar-val"><?= number_format($p['kg'],0) ?> kg</div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Monthly trend chart -->
    <div class="card">
      <div class="card-title" style="margin-bottom:14px"><i class="ti ti-chart-bar"></i> 6-Month Trend</div>
      <div class="mini-chart" style="height:70px;gap:6px">
        <?php foreach ($trend as $i => $t):
          $h = $maxTrendKg > 0 ? round(($t['kg']/$maxTrendKg)*100) : 0;
          $isCur = $t['mon'] === $monthStr;
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
          <div style="flex:1;display:flex;align-items:flex-end;width:100%">
            <div class="mini-bar <?= $isCur?'cur':'' ?>" style="height:<?= $h ?>%;width:100%"></div>
          </div>
          <div style="font-size:9px;color:var(--text-muted);white-space:nowrap"><?= date('M', strtotime($t['mon'].'-01')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
