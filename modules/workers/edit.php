<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM workers WHERE id=?");
$stmt->execute([$id]);
$worker = $stmt->fetch();
if (!$worker) { flash('error', 'Worker not found.'); redirect('/modules/workers/index.php'); }

$pageTitle = 'Edit Worker';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    if (!$name) { $formError = 'Full name is required.'; }
    else {
        $stmt = db()->prepare("UPDATE workers SET full_name=?, phone=?, nic=?, notes=?, status=? WHERE id=?");
        $stmt->execute([
            $name,
            trim($_POST['phone'] ?? ''),
            trim($_POST['nic'] ?? ''),
            trim($_POST['notes'] ?? ''),
            $_POST['status'] ?? 'active',
            $id
        ]);
        flash('success', 'Worker updated successfully.');
        redirect('/modules/workers/index.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:600px">
  <a href="/modules/workers/index.php" class="btn btn-secondary btn-sm" style="margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back</a>
  <div class="card">
    <div class="modal-title"><i class="ti ti-edit"></i> Edit Worker</div>
    <?php if (!empty($formError)): ?>
    <div class="alert alert-error" style="margin-bottom:16px"><i class="ti ti-alert-circle"></i> <?= sanitize($formError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group col-span-2">
          <label class="form-label">Full Name <span class="req">*</span></label>
          <input type="text" name="full_name" value="<?= sanitize($_POST['full_name'] ?? $worker['full_name']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="tel" name="phone" value="<?= sanitize($_POST['phone'] ?? $worker['phone']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">NIC / ID Number</label>
          <input type="text" name="nic" value="<?= sanitize($_POST['nic'] ?? $worker['nic']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status">
            <option value="active"   <?= ($worker['status']==='active'?'selected':'') ?>>Active</option>
            <option value="inactive" <?= ($worker['status']==='inactive'?'selected':'') ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group col-span-2">
          <label class="form-label">Notes</label>
          <textarea name="notes"><?= sanitize($_POST['notes'] ?? $worker['notes']) ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Update Worker</button>
        <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
