<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Expenses';

$db = db();
$month = date('Y-m');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $db->prepare("DELETE FROM expenses WHERE id=?")->execute([(int)$_POST['delete_id']]);
    flash('success', 'Expense deleted.');
    redirect('/modules/expenses/index.php');
}

$selMonth = $_GET['month'] ?? $month;

$monthTotal = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?");
$monthTotal->execute([$selMonth]);
$monthTotal = (float)$monthTotal->fetchColumn();

$expenses = $db->prepare("
    SELECT e.*, p.name as plantation_name
    FROM expenses e
    LEFT JOIN plantations p ON p.id=e.plantation_id
    WHERE DATE_FORMAT(e.expense_date,'%Y-%m')=?
    ORDER BY e.expense_date DESC, e.id DESC
");
$expenses->execute([$selMonth]);
$expenses = $expenses->fetchAll();

// By type
$byType = $db->prepare("SELECT expense_type, SUM(amount) as total FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY expense_type ORDER BY total DESC");
$byType->execute([$selMonth]);
$byType = $byType->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-toolbar">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="month" name="month" value="<?= sanitize($selMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-secondary btn-sm"><i class="ti ti-search"></i> View</button>
  </form>
  <div style="margin-left:auto">
    <a href="/modules/expenses/create.php" class="btn btn-primary"><i class="ti ti-plus"></i> Add Expense</a>
  </div>
</div>

<div class="grid-2" style="margin-bottom:20px">
  <div class="stat-card amber">
    <div class="stat-label">Monthly Total</div>
    <div class="stat-value" style="font-size:20px"><?= formatRs($monthTotal) ?></div>
    <div class="stat-sub"><?= count($expenses) ?> transactions</div>
  </div>
  <div class="card" style="margin-bottom:0">
    <div class="card-title" style="margin-bottom:12px"><i class="ti ti-chart-donut"></i> By Category</div>
    <?php foreach ($byType as $bt): ?>
    <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid rgba(0,0,0,0.05)">
      <span style="color:var(--text-muted)"><?= ucfirst($bt['expense_type']) ?></span>
      <span style="font-weight:600"><?= formatRs($bt['total']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Date</th><th>Type</th><th>Plantation</th><th>Amount</th><th>Notes</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php
        $expIcons=['food'=>'ti-salad','transport'=>'ti-truck','equipment'=>'ti-tool','fertilizer'=>'ti-droplet','miscellaneous'=>'ti-package'];
        foreach ($expenses as $e): ?>
        <tr>
          <td><?= formatDate($e['expense_date']) ?></td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:5px">
              <i class="ti <?= $expIcons[$e['expense_type']] ?? 'ti-package' ?>" style="font-size:15px;color:var(--green-600)"></i>
              <?= ucfirst($e['expense_type']) ?>
            </span>
          </td>
          <td class="td-muted"><?= sanitize($e['plantation_name'] ?? 'All') ?></td>
          <td class="td-money"><?= formatRs($e['amount']) ?></td>
          <td class="td-muted"><?= sanitize($e['notes'] ?: '—') ?></td>
          <td>
            <form method="POST" onsubmit="return confirmDelete('Delete this expense?')" style="display:inline">
              <input type="hidden" name="delete_id" value="<?= $e['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger"><i class="ti ti-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($expenses)): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="ti ti-receipt-off"></i><p>No expenses for this month.</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
