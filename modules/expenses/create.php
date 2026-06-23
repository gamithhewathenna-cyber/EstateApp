<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Add Expense';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date   = $_POST['expense_date'] ?? date('Y-m-d');
    $plantId = $_POST['plantation_id'] ?: null;
    $type   = $_POST['expense_type'] ?? 'miscellaneous';
    $amount = (float)($_POST['amount'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');

    if ($amount <= 0) { $formError = 'Please enter a valid amount.'; }
    else {
        $stmt = db()->prepare("INSERT INTO expenses (expense_date, plantation_id, expense_type, amount, notes, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$date, $plantId, $type, $amount, $notes, $_SESSION['user_id']]);
        flash('success', 'Expense added successfully.');
        redirect('/modules/expenses/index.php');
    }
}

$plantations = getPlantations();
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:560px">
  <a href="/modules/expenses/index.php" class="btn btn-secondary btn-sm" style="margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back</a>
  <div class="card">
    <div class="modal-title"><i class="ti ti-receipt-2"></i> Add New Expense</div>
    <?php if (!empty($formError)): ?>
    <div class="alert alert-error" style="margin-bottom:14px"><i class="ti ti-alert-circle"></i> <?= sanitize($formError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label class="form-label">Date <span class="req">*</span></label>
          <input type="date" name="expense_date" value="<?= sanitize($_POST['expense_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Plantation</label>
          <select name="plantation_id">
            <option value="">All Plantations</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Expense Type <span class="req">*</span></label>
          <select name="expense_type" required>
            <option value="food">Food</option>
            <option value="transport">Transport</option>
            <option value="equipment">Equipment</option>
            <option value="fertilizer">Fertilizer</option>
            <option value="miscellaneous">Miscellaneous</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Amount (Rs.) <span class="req">*</span></label>
          <input type="number" name="amount" placeholder="0.00" min="0" step="0.01" value="<?= sanitize($_POST['amount'] ?? '') ?>" required>
        </div>
        <div class="form-group col-span-2">
          <label class="form-label">Notes</label>
          <textarea name="notes" placeholder="Description..."><?= sanitize($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Save Expense</button>
        <a href="/modules/expenses/index.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
