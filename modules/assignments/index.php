<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Daily Assignments';

$db = db();
$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;
$rates = getWorkRates();

// Handle save assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignment'])) {
    $workDate     = $_POST['work_date'] ?? $today;
    $workerId     = (int)($_POST['worker_id'] ?? 0);
    $plantationId = (int)($_POST['plantation_id'] ?? 0);
    $workType     = $_POST['work_type'] ?? 'plucking';
    $quantity     = (float)($_POST['quantity'] ?? 0);
    $rate         = $rates[$workType]['rate'] ?? 50;
    $payment      = round($quantity * $rate, 2);
    $notes        = trim($_POST['notes'] ?? '');

    if ($workerId && $plantationId && $quantity > 0) {
        $stmt = $db->prepare("INSERT INTO assignments (work_date, worker_id, plantation_id, work_type, quantity, rate, payment, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$workDate, $workerId, $plantationId, $workType, $quantity, $rate, $payment, $notes, $_SESSION['user_id']]);
        flash('success', 'Assignment saved successfully.');
    } else {
        flash('error', 'Please fill all required fields and enter a valid quantity.');
    }
    redirect('/modules/assignments/index.php?date=' . urlencode($workDate));
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $db->prepare("DELETE FROM assignments WHERE id=?")->execute([(int)$_POST['delete_id']]);
    flash('success', 'Assignment removed.');
    redirect('/modules/assignments/index.php?date=' . urlencode($selectedDate));
}

// Load today's assignments
$dayAssignments = $db->prepare("
    SELECT a.*, w.full_name, p.name as plantation_name
    FROM assignments a
    JOIN workers w ON w.id=a.worker_id
    JOIN plantations p ON p.id=a.plantation_id
    WHERE a.work_date=?
    ORDER BY a.id DESC
");
$dayAssignments->execute([$selectedDate]);
$dayAssignments = $dayAssignments->fetchAll();

$dayTotal = array_sum(array_column($dayAssignments, 'payment'));
$dayKg    = array_sum(array_map(fn($a) => $a['work_type']==='plucking'?$a['quantity']:0, $dayAssignments));

$workers     = getActiveWorkers();
$plantations = getPlantations();

include __DIR__ . '/../../includes/header.php';
?>

<div class="grid-2">
<!-- LEFT: FORM -->
<div>
  <div class="card">
    <div class="modal-title"><i class="ti ti-clipboard-list"></i> New Assignment</div>

    <form method="POST">
      <input type="hidden" name="save_assignment" value="1">
      <input type="hidden" name="work_type" id="work_type" value="plucking">
      <input type="hidden" name="rate" id="rate" value="50">
      <input type="hidden" name="payment" id="payment" value="0">

      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label class="form-label">Date <span class="req">*</span></label>
          <input type="date" name="work_date" value="<?= sanitize($selectedDate) ?>" onchange="window.location.href='/modules/assignments/index.php?date='+this.value">
        </div>
        <div class="form-group">
          <label class="form-label">Plantation <span class="req">*</span></label>
          <select name="plantation_id" required>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-span-2">
          <label class="form-label">Worker <span class="req">*</span></label>
          <select name="worker_id" required>
            <option value="">— Select Worker —</option>
            <?php foreach ($workers as $w): ?>
            <option value="<?= $w['id'] ?>"><?= sanitize($w['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:10px">Work Type</div>
      <div class="work-type-grid">
        <div class="wt-card selected" onclick="selectWorkType(this,'plucking',<?= $rates['plucking']['rate'] ?>)">
          <div class="wt-name">🍃 Tea Plucking</div>
          <div class="wt-rate">Rs. <?= number_format($rates['plucking']['rate'],0) ?> / kg</div>
        </div>
        <div class="wt-card" onclick="selectWorkType(this,'clearing',<?= $rates['clearing']['rate'] ?>)">
          <div class="wt-name">🌿 Clearing</div>
          <div class="wt-rate">Rs. <?= number_format($rates['clearing']['rate'],0) ?> / unit</div>
        </div>
        <div class="wt-card" onclick="selectWorkType(this,'spraying',<?= $rates['spraying']['rate'] ?>)">
          <div class="wt-name">💦 Tank Spraying</div>
          <div class="wt-rate">Rs. <?= number_format($rates['spraying']['rate'],0) ?> / tank</div>
        </div>
        <div class="wt-card" onclick="selectWorkType(this,'helper',<?= $rates['helper']['rate'] ?>)">
          <div class="wt-name">🙌 Helper</div>
          <div class="wt-rate">Rs. <?= number_format($rates['helper']['rate'],0) ?> / day</div>
        </div>
        <div class="wt-card" onclick="selectWorkType(this,'basic',<?= $rates['basic']['rate'] ?>)">
          <div class="wt-name">⚙️ Basic Work</div>
          <div class="wt-rate">Rs. <?= number_format($rates['basic']['rate'],0) ?> / unit</div>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:4px">
        <label class="form-label" id="qty-label">Quantity (KG)</label>
        <input type="number" name="quantity" id="quantity" placeholder="Enter KG collected" min="0" step="0.1" oninput="calcPayment()" required>
      </div>

      <div class="calc-box">
        <div class="calc-label"><i class="ti ti-calculator"></i> Calculated Payment</div>
        <div class="calc-amount" id="calc-result">Rs. 0</div>
      </div>

      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Notes <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
        <input type="text" name="notes" placeholder="Any notes...">
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><i class="ti ti-check"></i> Save Assignment</button>
    </form>
  </div>
</div>

<!-- RIGHT: TODAY'S ASSIGNMENTS -->
<div>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-list-check"></i> <?= formatDate($selectedDate) ?></div>
      <div style="display:flex;gap:10px;font-size:13px">
        <span style="color:var(--text-muted)"><?= count($dayAssignments) ?> records</span>
      </div>
    </div>

    <!-- Summary -->
    <div style="display:flex;gap:10px;margin-bottom:16px">
      <div style="flex:1;background:var(--green-50);border-radius:var(--radius);padding:10px;text-align:center;border:1px solid var(--green-100)">
        <div style="font-size:18px;font-weight:700;color:var(--green-800)"><?= number_format($dayKg,1) ?> kg</div>
        <div style="font-size:11px;color:var(--green-600)">Total KG</div>
      </div>
      <div style="flex:1;background:var(--amber-50);border-radius:var(--radius);padding:10px;text-align:center;border:1px solid var(--amber-50)">
        <div style="font-size:18px;font-weight:700;color:var(--amber-600)"><?= formatRs($dayTotal) ?></div>
        <div style="font-size:11px;color:var(--amber-600)">Total Pay</div>
      </div>
    </div>

    <div style="max-height:460px;overflow-y:auto">
      <?php foreach ($dayAssignments as $a): ?>
      <div class="assign-item">
        <div class="avatar"><?= strtoupper(substr($a['full_name'],0,2)) ?></div>
        <div class="assign-meta">
          <div class="assign-name"><?= sanitize($a['full_name']) ?></div>
          <div class="assign-sub">
            <?= sanitize($a['plantation_name']) ?> ·
            <?= workTypeBadge($a['work_type']) ?> ·
            <?= number_format($a['quantity'],1) ?> <?= $rates[$a['work_type']]['unit_label'] ?? '' ?>
          </div>
        </div>
        <div class="assign-pay"><?= formatRs($a['payment']) ?></div>
        <form method="POST" onsubmit="return confirmDelete('Remove this assignment?')">
          <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn btn-icon btn-danger" style="margin-left:4px"><i class="ti ti-x"></i></button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (empty($dayAssignments)): ?>
      <div class="empty-state"><i class="ti ti-clipboard"></i><p>No assignments yet for this date.<br>Add your first assignment on the left.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Print -->
  <?php if (!empty($dayAssignments)): ?>
  <div style="text-align:right">
    <a href="/modules/assignments/print.php?date=<?= urlencode($selectedDate) ?>" target="_blank" class="btn btn-secondary btn-sm">
      <i class="ti ti-printer"></i> Print Day Summary
    </a>
  </div>
  <?php endif; ?>
</div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
