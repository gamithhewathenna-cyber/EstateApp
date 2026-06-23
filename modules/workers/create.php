<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Add Worker';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $nic   = trim($_POST['nic'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (!$name) {
        $formError = 'Full name is required.';
    } else {
        $stmt = db()->prepare("INSERT INTO workers (full_name, phone, nic, notes, status) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $phone, $nic, $notes, $status]);
        flash('success', "Worker '$name' added successfully.");
        redirect('/modules/workers/index.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:600px">
  <a href="/modules/workers/index.php" class="btn btn-secondary btn-sm" style="margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back to Workers</a>

  <div class="card">
    <div class="modal-title"><i class="ti ti-user-plus"></i> Add New Worker</div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px">Workers are not permanently assigned to plantations. Assignments are done daily in the Daily Assignments module.</p>

    <?php if (!empty($formError)): ?>
    <div class="alert alert-error" style="margin-bottom:16px"><i class="ti ti-alert-circle"></i> <?= sanitize($formError) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group col-span-2">
          <label class="form-label">Full Name <span class="req">*</span></label>
          <input type="text" name="full_name" placeholder="e.g. Kamala Perera" value="<?= sanitize($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="tel" name="phone" placeholder="071-XXX-XXXX" value="<?= sanitize($_POST['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">NIC / ID Number</label>
          <input type="text" name="nic" placeholder="Optional" value="<?= sanitize($_POST['nic'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="form-group col-span-2">
          <label class="form-label">Notes</label>
          <textarea name="notes" placeholder="Any additional notes about this worker..."><?= sanitize($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Save Worker</button>
        <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
