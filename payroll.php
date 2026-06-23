<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Payroll';
Auth::check();
$estateId = Auth::estateId();

// Handle payment status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Mark single record paid/pending
    if ($action === 'mark_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        if (!in_array($status, ['paid','pending'])) $status = 'pending';
        DB::execute("UPDATE daily_assignments SET payment_status=? WHERE id=? AND estate_id=?", [$status, $id, $estateId]);
        flash('success', 'Payment marked as ' . ucfirst($status) . '.');
        redirect('/payroll.php?month=' . ($_POST['month'] ?? date('Y-m')) . '&tab=' . ($_POST['tab'] ?? 'worker'));
    }

    // Mark ALL for a worker in a month as paid
    if ($action === 'mark_worker_paid') {
        $wid   = (int)($_POST['worker_id'] ?? 0);
        $month = $_POST['month'] ?? date('Y-m');
        DB::execute("UPDATE daily_assignments SET payment_status='paid' WHERE worker_id=? AND estate_id=? AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$wid, $estateId, $month]);
        flash('success', 'All payments marked as Paid for this worker.');
        redirect('/payroll.php?month=' . $month . '&tab=worker');
    }

    // Mark ALL for a month as paid
    if ($action === 'mark_all_paid') {
        $month = $_POST['month'] ?? date('Y-m');
        DB::execute("UPDATE daily_assignments SET payment_status='paid' WHERE estate_id=? AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, $month]);
        flash('success', 'All payments marked as Paid for ' . date('F Y', strtotime($month . '-01')) . '.');
        redirect('/payroll.php?month=' . $month . '&tab=worker');
    }

    // Mark ALL for a month as pending (reset)
    if ($action === 'mark_all_pending') {
        $month = $_POST['month'] ?? date('Y-m');
        DB::execute("UPDATE daily_assignments SET payment_status='pending' WHERE estate_id=? AND DATE_FORMAT(assignment_date,'%Y-%m')=?", [$estateId, $month]);
        flash('success', 'All payments reset to Pending.');
        redirect('/payroll.php?month=' . $month . '&tab=worker');
    }
}

$selMonth = $_GET['month'] ?? date('Y-m');
$tab      = $_GET['tab']   ?? 'worker';

// Summary totals — use unit_label='kg' to identify plucking across all estates
$totals = DB::fetchOne("SELECT
    COALESCE(SUM(da.payment),0) as total_pay,
    COALESCE(SUM(CASE WHEN da.payment_status='paid'    THEN da.payment ELSE 0 END),0) as paid_pay,
    COALESCE(SUM(CASE WHEN da.payment_status='pending' THEN da.payment ELSE 0 END),0) as pending_pay,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.payment ELSE 0 END),0) as plucking_pay,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)!='kg' THEN da.payment ELSE 0 END),0) as other_pay,
    COUNT(DISTINCT da.worker_id) as workers,
    COUNT(DISTINCT da.assignment_date) as days,
    SUM(CASE WHEN da.payment_status='paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN da.payment_status='pending' THEN 1 ELSE 0 END) as pending_count
    FROM daily_assignments da
    JOIN work_types wt ON da.work_type_id = wt.id
    WHERE da.estate_id=? AND da.approval_status='approved' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?", [$estateId, $selMonth]);

// Worker-wise payroll — use unit_label='kg' for plucking detection
$workerPayroll = DB::fetchAll("SELECT w.full_name, w.id as worker_id,
    COUNT(DISTINCT da.assignment_date) as days,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.quantity ELSE 0 END),0) as total_kg,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.payment ELSE 0 END),0) as plucking_pay,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)!='kg' THEN da.payment ELSE 0 END),0) as other_pay,
    COALESCE(SUM(da.payment),0) as total_pay,
    SUM(CASE WHEN da.payment_status='paid' THEN da.payment ELSE 0 END) as paid_amount,
    SUM(CASE WHEN da.payment_status='pending' THEN da.payment ELSE 0 END) as pending_amount,
    SUM(CASE WHEN da.payment_status='paid' THEN 1 ELSE 0 END) as paid_records,
    SUM(CASE WHEN da.payment_status='pending' THEN 1 ELSE 0 END) as pending_records,
    COUNT(da.id) as total_records,
    MAX(da.payment_status) as has_paid,
    MIN(da.payment_status) as all_same
    FROM workers w
    LEFT JOIN daily_assignments da ON w.id=da.worker_id AND da.estate_id=? AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    LEFT JOIN work_types wt ON da.work_type_id = wt.id
    WHERE w.estate_id=? AND w.is_active=1
    GROUP BY w.id ORDER BY total_pay DESC", [$estateId, $selMonth, $estateId]);

// Daily summary — use unit_label='kg' for plucking detection
$dailySummary = DB::fetchAll("SELECT da.assignment_date,
    COUNT(DISTINCT da.worker_id) as workers,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.quantity ELSE 0 END),0) as kg,
    COALESCE(SUM(da.payment),0) as payroll,
    SUM(CASE WHEN da.payment_status='paid' THEN da.payment ELSE 0 END) as paid_amount,
    SUM(CASE WHEN da.payment_status='pending' THEN da.payment ELSE 0 END) as pending_amount
    FROM daily_assignments da
    JOIN work_types wt ON da.work_type_id = wt.id
    WHERE da.estate_id=? AND da.approval_status='approved' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    GROUP BY da.assignment_date ORDER BY da.assignment_date DESC", [$estateId, $selMonth]);

// Detailed records for worker detail view
$workerDetail = null;
$detailWorker = (int)($_GET['worker'] ?? 0);
if ($detailWorker) {
    $workerDetail = DB::fetchAll("SELECT da.*, wt.name as work_type_name, wt.unit_label, p.name as plantation_name
        FROM daily_assignments da
        JOIN work_types wt ON da.work_type_id=wt.id
        JOIN plantations p ON da.plantation_id=p.id
        WHERE da.estate_id=? AND da.worker_id=? AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
        ORDER BY da.assignment_date DESC", [$estateId, $detailWorker, $selMonth]);
    $detailWorkerName = DB::fetchOne("SELECT full_name FROM workers WHERE id=? AND estate_id=?", [$detailWorker,$estateId]);
}

// Plantation-wise — use unit_label='kg' for plucking detection
$plantPayroll = DB::fetchAll("SELECT p.name,
    COALESCE(SUM(da.payment),0) as total_pay,
    COALESCE(SUM(CASE WHEN LOWER(wt.unit_label)='kg' THEN da.quantity ELSE 0 END),0) as total_kg,
    COUNT(DISTINCT da.worker_id) as workers,
    SUM(CASE WHEN da.payment_status='paid' THEN da.payment ELSE 0 END) as paid_amount,
    SUM(CASE WHEN da.payment_status='pending' THEN da.payment ELSE 0 END) as pending_amount
    FROM plantations p
    LEFT JOIN daily_assignments da ON p.id=da.plantation_id AND da.estate_id=? AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    LEFT JOIN work_types wt ON da.work_type_id = wt.id
    WHERE p.estate_id=? AND p.is_active=1 GROUP BY p.id ORDER BY total_pay DESC", [$estateId, $selMonth, $estateId]);
$maxPlant = max(array_column($plantPayroll,'total_pay') ?: [1]);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.status-paid    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
.status-pending { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
.status-partial { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
.pay-row-paid    td { background:#f0fdf4 !important; }
.pay-row-pending td { background:#fff5f5 !important; }

@media (max-width: 900px) {
  .payroll-filter-actions { flex-wrap: wrap !important; margin-left: 0 !important; gap: 6px !important; }
  .filter-row { flex-direction: column; align-items: stretch; gap: 8px; }
}
@media (max-width: 600px) {
  /* Worker summary table — hide less critical columns */
  .payroll-worker-table th:nth-child(2), .payroll-worker-table td:nth-child(2),
  .payroll-worker-table th:nth-child(3), .payroll-worker-table td:nth-child(3),
  .payroll-worker-table th:nth-child(4), .payroll-worker-table td:nth-child(4),
  .payroll-worker-table th:nth-child(5), .payroll-worker-table td:nth-child(5),
  .payroll-worker-table th:nth-child(7), .payroll-worker-table td:nth-child(7),
  .payroll-worker-table th:nth-child(8), .payroll-worker-table td:nth-child(8) { display: none !important; }
  /* Worker detail table */
  .payroll-detail-table th:nth-child(2), .payroll-detail-table td:nth-child(2),
  .payroll-detail-table th:nth-child(3), .payroll-detail-table td:nth-child(3),
  .payroll-detail-table th:nth-child(5), .payroll-detail-table td:nth-child(5) { display: none !important; }
  /* Daily summary table */
  .payroll-daily-table th:nth-child(3), .payroll-daily-table td:nth-child(3),
  .payroll-daily-table th:nth-child(5), .payroll-daily-table td:nth-child(5) { display: none !important; }
  /* Worker detail header row */
  .payroll-detail-header { flex-wrap: wrap; gap: 8px; }
  .payroll-detail-header form { margin-left: 0 !important; }
}
</style>

<!-- Filter row -->
<div class="filter-row">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
    <label style="font-size:13px;color:var(--gray-600)">Month:</label>
    <input type="month" name="month" value="<?= sanitize($selMonth) ?>" onchange="this.form.submit()">
    <button type="submit" class="btn btn-secondary btn-sm">View</button>
  </form>
  <div class="payroll-filter-actions" style="display:flex;gap:8px;margin-left:auto">
    <!-- Mark ALL paid -->
    <form method="POST" onsubmit="return confirm('Mark ALL payments as Paid for <?= date('F Y',strtotime($selMonth.'-01')) ?>?')">
      <input type="hidden" name="action" value="mark_all_paid">
      <input type="hidden" name="month" value="<?= $selMonth ?>">
      <button type="submit" class="btn btn-sm" style="background:#065f46;color:#fff"><i class="ti ti-circle-check"></i> Mark All Paid</button>
    </form>
    <!-- Reset all to pending -->
    <form method="POST" onsubmit="return confirm('Reset ALL payments to Pending?')">
      <input type="hidden" name="action" value="mark_all_pending">
      <input type="hidden" name="month" value="<?= $selMonth ?>">
      <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)"><i class="ti ti-refresh"></i> Reset to Pending</button>
    </form>
    <a href="reports.php?month=<?= $selMonth ?>" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> Export</a>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card amber">
    <div class="stat-label"><i class="ti ti-cash"></i> Total Payroll</div>
    <div class="stat-value"><?= moneyShort($totals['total_pay']) ?></div>
    <div class="stat-sub"><?= $totals['workers'] ?> workers · <?= $totals['days'] ?> days</div>
  </div>
  <div class="stat-card" style="border-left-color:#22c55e">
    <div class="stat-label"><i class="ti ti-circle-check" style="color:#22c55e"></i> Paid</div>
    <div class="stat-value" style="color:#065f46"><?= moneyShort($totals['paid_pay']) ?></div>
    <div class="stat-sub"><?= $totals['paid_count'] ?> records completed</div>
  </div>
  <div class="stat-card" style="border-left-color:var(--red-400)">
    <div class="stat-label"><i class="ti ti-clock" style="color:var(--red-400)"></i> Pending</div>
    <div class="stat-value" style="color:var(--red-600)"><?= moneyShort($totals['pending_pay']) ?></div>
    <div class="stat-sub"><?= $totals['pending_count'] ?> records outstanding</div>
  </div>
  <div class="stat-card teal">
    <div class="stat-label"><i class="ti ti-chart-pie"></i> Paid %</div>
    <div class="stat-value"><?= $totals['total_pay']>0 ? round($totals['paid_pay']/$totals['total_pay']*100) : 0 ?>%</div>
    <div class="stat-sub">of total payroll paid out</div>
  </div>
</div>

<!-- Progress bar: paid vs pending -->
<?php if ($totals['total_pay'] > 0): ?>
<div class="card" style="margin-bottom:18px;padding:14px 18px">
  <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:7px">
    <span style="color:#065f46;font-weight:600"><i class="ti ti-circle-check"></i> Paid: <?= money($totals['paid_pay']) ?></span>
    <span style="color:var(--red-600);font-weight:600"><i class="ti ti-clock"></i> Pending: <?= money($totals['pending_pay']) ?></span>
  </div>
  <div style="height:12px;background:#fee2e2;border-radius:6px;overflow:hidden">
    <div style="height:100%;width:<?= round($totals['paid_pay']/$totals['total_pay']*100) ?>%;background:#22c55e;border-radius:6px;transition:width .3s"></div>
  </div>
  <div style="font-size:11px;color:var(--gray-400);margin-top:5px;text-align:center">
    <?= round($totals['paid_pay']/$totals['total_pay']*100) ?>% of <?= money($totals['total_pay']) ?> paid
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-row">
  <button class="tab-btn <?= $tab==='worker'?'active':'' ?>"     onclick="switchTabUrl('worker')">Worker-wise</button>
  <button class="tab-btn <?= $tab==='plantation'?'active':'' ?>" onclick="switchTabUrl('plantation')">Plantation-wise</button>
  <button class="tab-btn <?= $tab==='daily'?'active':'' ?>"      onclick="switchTabUrl('daily')">Daily Summary</button>
</div>

<!-- ── WORKER TAB ── -->
<?php if ($tab==='worker'): ?>

<?php if ($detailWorker && $workerDetail !== null): ?>
<!-- Worker Detail View -->
<div class="card" style="margin-bottom:14px;border:2px solid var(--green-200)">
  <div class="payroll-detail-header" style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <a href="payroll.php?month=<?= $selMonth ?>&tab=worker" class="btn btn-outline btn-sm"><i class="ti ti-arrow-left"></i> Back</a>
    <div class="avatar" style="width:36px;height:36px"><?= initials($detailWorkerName['full_name']) ?></div>
    <div>
      <div style="font-size:15px;font-weight:700"><?= sanitize($detailWorkerName['full_name']) ?></div>
      <div style="font-size:12px;color:var(--gray-400)"><?= date('F Y',strtotime($selMonth.'-01')) ?> · <?= count($workerDetail) ?> records</div>
    </div>
    <!-- Mark all this worker paid -->
    <form method="POST" style="margin-left:auto">
      <input type="hidden" name="action" value="mark_worker_paid">
      <input type="hidden" name="worker_id" value="<?= $detailWorker ?>">
      <input type="hidden" name="month" value="<?= $selMonth ?>">
      <button type="submit" class="btn btn-sm" style="background:#065f46;color:#fff"><i class="ti ti-circle-check"></i> Mark All Paid</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="payroll-detail-table">
      <thead>
        <tr><th>Date</th><th>Section</th><th>Work Type</th><th>Qty</th><th>Rate</th><th>Payment</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($workerDetail as $r): ?>
        <tr class="pay-row-<?= $r['payment_status'] ?>">
          <td><?= fmtDate($r['assignment_date']) ?></td>
          <td><?= sanitize($r['plantation_name']) ?></td>
          <td><?= sanitize($r['work_type_name']) ?></td>
          <td><?= number_format($r['quantity'],2) ?> <?= sanitize($r['unit_label']) ?></td>
          <td>Rs. <?= number_format($r['rate'],2) ?></td>
          <td><strong><?= money($r['payment']) ?></strong></td>
          <td>
            <?php if ($r['payment_status']==='paid'): ?>
              <span class="status-paid"><i class="ti ti-circle-check" style="font-size:12px"></i> Paid</span>
            <?php else: ?>
              <span class="status-pending"><i class="ti ti-clock" style="font-size:12px"></i> Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="mark_status">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="month" value="<?= $selMonth ?>">
              <input type="hidden" name="tab" value="worker">
              <?php if ($r['payment_status']==='pending'): ?>
                <input type="hidden" name="status" value="paid">
                <button type="submit" class="btn btn-sm" style="background:#065f46;color:#fff;padding:4px 10px">
                  <i class="ti ti-check"></i> Mark Paid
                </button>
              <?php else: ?>
                <input type="hidden" name="status" value="pending">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400);padding:4px 10px">
                  <i class="ti ti-x"></i> Undo
                </button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$workerDetail): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="ti ti-calendar-off"></i><p>No records this month</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- Worker Summary Table -->
<div class="card">
  <div class="table-wrap">
    <table class="payroll-worker-table">
      <thead>
        <tr>
          <th>Worker</th><th>Days</th><th>Total KG</th><th>Plucking</th><th>Other</th>
          <th>Total Pay</th><th>Paid</th><th>Pending</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($workerPayroll as $w):
          if ($w['total_pay'] == 0) continue;
          // Determine overall status
          if ($w['pending_records'] == 0 && $w['paid_records'] > 0) $overallStatus = 'paid';
          elseif ($w['paid_records'] == 0) $overallStatus = 'pending';
          else $overallStatus = 'partial';
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="avatar" style="width:28px;height:28px;font-size:11px"><?= initials($w['full_name']) ?></div>
              <strong><?= sanitize($w['full_name']) ?></strong>
            </div>
          </td>
          <td><?= $w['days'] ?></td>
          <td><?= $w['total_kg']>0?number_format($w['total_kg'],1).' kg':'—' ?></td>
          <td><?= $w['plucking_pay']>0?money($w['plucking_pay']):'—' ?></td>
          <td><?= $w['other_pay']>0?money($w['other_pay']):'—' ?></td>
          <td><strong style="color:var(--green-600)"><?= money($w['total_pay']) ?></strong></td>
          <td style="color:#065f46;font-weight:600"><?= $w['paid_amount']>0?money($w['paid_amount']):'—' ?></td>
          <td style="color:var(--red-600);font-weight:600"><?= $w['pending_amount']>0?money($w['pending_amount']):'—' ?></td>
          <td>
            <?php if ($overallStatus==='paid'): ?>
              <span class="status-paid"><i class="ti ti-circle-check" style="font-size:12px"></i> Paid</span>
            <?php elseif ($overallStatus==='partial'): ?>
              <span class="status-partial"><i class="ti ti-circle-half" style="font-size:12px"></i> Partial</span>
            <?php else: ?>
              <span class="status-pending"><i class="ti ti-clock" style="font-size:12px"></i> Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <!-- View details -->
              <a href="payroll.php?month=<?= $selMonth ?>&tab=worker&worker=<?= $w['worker_id'] ?>"
                 class="btn btn-outline btn-sm" title="View details"><i class="ti ti-eye"></i></a>
              <!-- Quick mark all paid -->
              <?php if ($overallStatus !== 'paid'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="mark_worker_paid">
                <input type="hidden" name="worker_id" value="<?= $w['worker_id'] ?>">
                <input type="hidden" name="month" value="<?= $selMonth ?>">
                <button type="submit" class="btn btn-sm" style="background:#065f46;color:#fff;padding:5px 10px" title="Mark all paid">
                  <i class="ti ti-circle-check"></i> Pay
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── PLANTATION TAB ── -->
<?php elseif ($tab==='plantation'): ?>
<div class="card">
  <?php foreach ($plantPayroll as $p): if($p['total_pay']==0) continue; ?>
  <div style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;margin-bottom:6px;align-items:center">
      <strong style="font-size:14px"><?= sanitize($p['name']) ?></strong>
      <div style="display:flex;align-items:center;gap:10px">
        <?php if ($p['paid_amount']>0): ?>
        <span class="status-paid"><i class="ti ti-circle-check" style="font-size:12px"></i> <?= money($p['paid_amount']) ?> paid</span>
        <?php endif; ?>
        <?php if ($p['pending_amount']>0): ?>
        <span class="status-pending"><i class="ti ti-clock" style="font-size:12px"></i> <?= money($p['pending_amount']) ?> pending</span>
        <?php endif; ?>
        <strong style="color:var(--green-600)"><?= money($p['total_pay']) ?></strong>
      </div>
    </div>
    <div style="height:10px;background:#fee2e2;border-radius:6px;overflow:hidden;margin-bottom:4px">
      <?php $paidPct = $p['total_pay']>0?round($p['paid_amount']/$p['total_pay']*100):0; ?>
      <div style="height:100%;width:<?= $paidPct ?>%;background:#22c55e;border-radius:6px"></div>
    </div>
    <div style="font-size:11px;color:var(--gray-400)"><?= $p['workers'] ?> workers · <?= number_format($p['total_kg'],1) ?> kg · <?= $paidPct ?>% paid</div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── DAILY TAB ── -->
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="payroll-daily-table">
      <thead>
        <tr><th>Date</th><th>Workers</th><th>KG Plucked</th><th>Payroll</th><th>Paid</th><th>Pending</th></tr>
      </thead>
      <tbody>
        <?php foreach ($dailySummary as $d): ?>
        <tr>
          <td><?= fmtDate($d['assignment_date']) ?></td>
          <td><?= $d['workers'] ?></td>
          <td><?= $d['kg']>0?number_format($d['kg'],1).' kg':'—' ?></td>
          <td><strong><?= money($d['payroll']) ?></strong></td>
          <td style="color:#065f46;font-weight:600"><?= $d['paid_amount']>0?money($d['paid_amount']):'—' ?></td>
          <td>
            <?php if ($d['pending_amount']>0): ?>
            <span class="status-pending"><i class="ti ti-clock" style="font-size:11px"></i> <?= money($d['pending_amount']) ?></span>
            <?php else: ?>
            <span class="status-paid"><i class="ti ti-circle-check" style="font-size:11px"></i> All paid</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$dailySummary): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="ti ti-calendar-off"></i><p>No records for this month</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function switchTabUrl(tab) {
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  url.searchParams.delete('worker');
  window.location = url;
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
