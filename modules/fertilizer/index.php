<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
startSecureSession();
requireLogin();
$pageTitle = 'Fertilizer Cycles';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $db->prepare("DELETE FROM fertilizer_cycles WHERE id=?")->execute([(int)$_POST['delete_id']]);
    flash('success', 'Record deleted.');
    redirect('/modules/fertilizer/index.php');
}

// Upcoming
$upcoming = $db->query("
    SELECT f.*, p.name as plantation_name,
           DATEDIFF(f.next_due_date, CURDATE()) as days_left
    FROM fertilizer_cycles f
    JOIN plantations p ON p.id=f.plantation_id
    ORDER BY f.next_due_date ASC LIMIT 6
")->fetchAll();

// Full history
$history = $db->query("
    SELECT f.*, p.name as plantation_name,
           DATEDIFF(f.next_due_date, CURDATE()) as days_left
    FROM fertilizer_cycles f
    JOIN plantations p ON p.id=f.plantation_id
    ORDER BY f.applied_date DESC LIMIT 50
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-toolbar">
  <div style="flex:1"></div>
  <a href="/modules/fertilizer/create.php" class="btn btn-primary"><i class="ti ti-plus"></i> Log Application</a>
</div>

<div class="grid-2">
  <!-- Upcoming / Reminders -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-bell"></i> Upcoming Schedule</div>
    </div>
    <?php foreach ($upcoming as $f):
      $d = (int)$f['days_left'];
      if ($d < 0)     { $cls='fert-urgent'; $lbl='Overdue '.abs($d).'d'; $dot='var(--red-400)'; }
      elseif($d<=7)   { $cls='fert-soon';   $lbl="In $d days";            $dot='var(--amber-400)'; }
      else            { $cls='fert-ok';     $lbl="In $d days";            $dot='var(--green-400)'; }
    ?>
    <div class="fert-item">
      <div class="fert-dot" style="background:<?= $dot ?>"></div>
      <div class="fert-info">
        <div class="fert-name"><?= sanitize($f['plantation_name']) ?> — <?= sanitize($f['fertilizer_type']) ?></div>
        <div class="fert-date">Applied: <?= formatDate($f['applied_date']) ?> · <?= $f['amount_kg'] ?> kg · Due: <?= formatDate($f['next_due_date']) ?></div>
      </div>
      <div class="fert-badge <?= $cls ?>"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($upcoming)): ?>
    <div class="empty-state"><i class="ti ti-droplet-off"></i><p>No fertilizer records yet.</p></div>
    <?php endif; ?>
  </div>

  <!-- History table -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-history"></i> Application History</div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Plantation</th><th>Type</th><th>Kg</th><th>Next Due</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($history as $f): ?>
          <tr>
            <td><?= formatDate($f['applied_date']) ?></td>
            <td class="td-bold"><?= sanitize($f['plantation_name']) ?></td>
            <td><?= sanitize($f['fertilizer_type']) ?></td>
            <td><?= $f['amount_kg'] ?></td>
            <td class="td-muted"><?= formatDate($f['next_due_date']) ?></td>
            <td>
              <form method="POST" onsubmit="return confirmDelete('Delete this record?')" style="display:inline">
                <input type="hidden" name="delete_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($history)): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="ti ti-droplet-off"></i><p>No history.</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
