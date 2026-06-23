<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Worker Management';
Auth::check();
$estateId = Auth::estateId();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name   = trim($_POST['full_name'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $nic    = trim($_POST['nic'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $status = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

        if (!$name) { flash('error', 'Full name is required.'); redirect('/workers.php'); }

        if ($action === 'add') {
            DB::insert("INSERT INTO workers (estate_id,full_name,phone,nic,notes,is_active) VALUES (?,?,?,?,?,?)",
                [$estateId,$name,$phone,$nic,$notes,$status]);
            flash('success', 'Worker added successfully.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            DB::execute("UPDATE workers SET full_name=?,phone=?,nic=?,notes=?,is_active=?,updated_at=NOW() WHERE id=?",
                [$name,$phone,$nic,$notes,$status,$id]);
            flash('success', 'Worker updated successfully.');
        }
        redirect('/workers.php');
    }

    if ($action === 'delete') {
        Auth::requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        DB::execute("UPDATE workers SET is_active = 0 WHERE id = ?", [$id]);
        flash('success', 'Worker deactivated.');
        redirect('/workers.php');
    }
}

// Fetch workers
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';
$where  = ['estate_id = ?'];
$params = [$estateId];
if ($search) {
    $where[]  = "(full_name LIKE ? OR phone LIKE ? OR nic LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status === 'active')   { $where[] = 'is_active = 1'; }
if ($status === 'inactive') { $where[] = 'is_active = 0'; }
$sql     = "SELECT * FROM workers WHERE " . implode(' AND ', $where) . " ORDER BY full_name ASC";
$workers = DB::fetchAll($sql, $params);

// Edit worker
$editWorker = null;
if (!empty($_GET['edit'])) {
    $editWorker = DB::fetchOne("SELECT * FROM workers WHERE id=? AND estate_id=?", [(int)$_GET['edit'],$estateId]);
}

// Show add form if ?action=add
$showAdd = (!empty($_GET['action']) && $_GET['action'] === 'add');

require_once __DIR__ . '/includes/header.php';
?>

<div class="section-header">
  <div class="section-title"><i class="ti ti-users"></i> All Workers</div>
  <a href="workers.php?action=add" class="btn btn-primary">
    <i class="ti ti-user-plus"></i> Add Worker
  </a>
</div>

<!-- Filters -->
<div class="filter-row" style="margin-bottom:16px">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <div class="search-wrap">
      <i class="ti ti-search"></i>
      <input type="text" name="search" placeholder="Search workers..." value="<?= sanitize($search) ?>" style="max-width:220px;width:100%">
    </div>
    <select name="status" onchange="this.form.submit()">
      <option value="all"      <?= $status==='all'     ?'selected':'' ?>>All Status</option>
      <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Active</option>
      <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search || $status !== 'all'): ?>
      <a href="workers.php" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
  </form>
  <span style="margin-left:auto;font-size:13px;color:var(--gray-400)"><?= count($workers) ?> workers found</span>
</div>

<!-- ADD WORKER FORM (shown inline when ?action=add) -->
<?php if ($showAdd || $editWorker): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--green-200)">
  <div class="form-panel-title">
    <i class="ti ti-<?= $editWorker ? 'edit' : 'user-plus' ?>"></i>
    <?= $editWorker ? 'Edit Worker' : 'Add New Worker' ?>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="<?= $editWorker ? 'edit' : 'add' ?>">
    <?php if ($editWorker): ?>
    <input type="hidden" name="id" value="<?= $editWorker['id'] ?>">
    <?php endif; ?>
    <div class="grid-form" style="margin-bottom:16px">
      <div class="form-group col-full">
        <label>Full Name *</label>
        <input type="text" name="full_name" placeholder="Enter full name" required
               value="<?= sanitize($editWorker['full_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="text" name="phone" placeholder="+94 7X XXX XXXX"
               value="<?= sanitize($editWorker['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>NIC / ID Number</label>
        <input type="text" name="nic" placeholder="Optional"
               value="<?= sanitize($editWorker['nic'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="is_active">
          <option value="1" <?= ($editWorker['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= ($editWorker['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group col-full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Any notes about this worker..."><?= sanitize($editWorker['notes'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-check"></i> <?= $editWorker ? 'Update Worker' : 'Save Worker' ?>
      </button>
      <a href="workers.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Workers Table -->
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Worker</th>
          <th>Phone</th>
          <th>NIC / ID</th>
          <th>Status</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($workers as $w): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="avatar"><?= initials($w['full_name']) ?></div>
              <div>
                <div style="font-weight:600"><?= sanitize($w['full_name']) ?></div>
                <div style="font-size:11px;color:var(--gray-400)">Added <?= fmtDate($w['created_at']) ?></div>
              </div>
            </div>
          </td>
          <td><?= sanitize($w['phone']) ?: '—' ?></td>
          <td><?= sanitize($w['nic'])   ?: '—' ?></td>
          <td><?= pill($w['is_active'] ? 'Active' : 'Inactive', $w['is_active'] ? 'active' : 'inactive') ?></td>
          <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= sanitize($w['notes']) ?: '—' ?>
          </td>
          <td>
            <div style="display:flex;gap:4px">
              <a href="workers.php?edit=<?= $w['id'] ?>" class="btn btn-outline btn-sm">
                <i class="ti ti-edit"></i> Edit
              </a>
              <?php if (Auth::isAdmin()): ?>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Deactivate <?= sanitize($w['full_name']) ?>?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $w['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)">
                  <i class="ti ti-user-off"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if (!$workers): ?>
        <tr>
          <td colspan="6">
            <div class="empty-state">
              <i class="ti ti-users"></i>
              <p>No workers found.<br>
                <a href="workers.php?action=add" style="color:var(--green-600)">Add your first worker</a>
              </p>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
