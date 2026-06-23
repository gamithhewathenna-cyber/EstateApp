<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Workers';

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $stmt = db()->prepare("UPDATE workers SET status='inactive' WHERE id=?");
    $stmt->execute([(int)$_POST['delete_id']]);
    flash('success', 'Worker deactivated successfully.');
    redirect('/modules/workers/index.php');
}

$filter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM workers WHERE 1=1";
$params = [];
if ($filter === 'active')   { $sql .= " AND status='active'"; }
if ($filter === 'inactive') { $sql .= " AND status='inactive'"; }
if ($search) { $sql .= " AND full_name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY full_name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-toolbar">
  <div class="page-toolbar-left">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <div class="search-wrap">
        <i class="ti ti-search"></i>
        <input type="text" name="q" placeholder="Search workers..." value="<?= sanitize($search) ?>" style="width:220px" oninput="this.form.submit()">
      </div>
      <select name="status" onchange="this.form.submit()" style="width:140px">
        <option value="all"      <?= $filter==='all'?'selected':'' ?>>All Workers</option>
        <option value="active"   <?= $filter==='active'?'selected':'' ?>>Active Only</option>
        <option value="inactive" <?= $filter==='inactive'?'selected':'' ?>>Inactive Only</option>
      </select>
    </form>
  </div>
  <div class="page-toolbar-right">
    <a href="/modules/workers/create.php" class="btn btn-primary"><i class="ti ti-user-plus"></i> Add Worker</a>
  </div>
</div>

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
            <div style="display:flex;align-items:center;gap:10px">
              <div class="avatar"><?= strtoupper(substr($w['full_name'],0,2)) ?></div>
              <div>
                <div class="td-bold"><?= sanitize($w['full_name']) ?></div>
                <div class="td-muted">Added <?= formatDate($w['created_at']) ?></div>
              </div>
            </div>
          </td>
          <td><?= sanitize($w['phone'] ?: '—') ?></td>
          <td><?= sanitize($w['nic'] ?: '—') ?></td>
          <td>
            <span class="badge badge-<?= $w['status'] === 'active' ? 'active' : 'inactive' ?>">
              <?= $w['status'] === 'active' ? '● Active' : '○ Inactive' ?>
            </span>
          </td>
          <td class="td-muted"><?= sanitize($w['notes'] ?: '—') ?></td>
          <td>
            <div class="btn-group">
              <a href="/modules/workers/edit.php?id=<?= $w['id'] ?>" class="btn btn-sm btn-outline"><i class="ti ti-edit"></i></a>
              <form method="POST" style="display:inline" onsubmit="return confirmDelete('Deactivate this worker?')">
                <input type="hidden" name="delete_id" value="<?= $w['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="ti ti-user-off"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($workers)): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="ti ti-users"></i><p>No workers found.</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
