<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Clearing Cycles';
Auth::check();
$estateId = Auth::estateId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD ──────────────────────────────────────
    if ($action === 'add') {
        $pid     = (int)($_POST['plantation_id']  ?? 0);
        $date    = $_POST['date_cleared']          ?? today();
        $cycle   = (int)($_POST['next_cycle_days'] ?? 30);
        $units   = trim($_POST['units_to_clear']   ?? '');
        $notes   = trim($_POST['notes']            ?? '');
        $nextDue = date('Y-m-d', strtotime($date . ' + ' . $cycle . ' days'));
        $uid     = Auth::user()['id'];
        if (!$pid || !$date) { flash('error','Section and date cleared are required.'); redirect('/clearing.php'); }
        DB::insert("INSERT INTO clearing_cycles (estate_id,plantation_id,date_cleared,next_cycle_days,next_due_date,units_to_clear,notes,created_by) VALUES (?,?,?,?,?,?,?,?)",
            [$estateId,$pid,$date,$cycle,$nextDue,$units!==''?$units:null,$notes,$uid]);
        flash('success','Clearing record saved.');
        redirect('/clearing.php');
    }

    // ── UPDATE ────────────────────────────────────
    if ($action === 'update') {
        Auth::requireAdmin();
        $id      = (int)($_POST['id']              ?? 0);
        $pid     = (int)($_POST['plantation_id']   ?? 0);
        $date    = $_POST['date_cleared']           ?? today();
        $cycle   = (int)($_POST['next_cycle_days']  ?? 30);
        $units   = trim($_POST['units_to_clear']    ?? '');
        $notes   = trim($_POST['notes']             ?? '');
        $nextDue = date('Y-m-d', strtotime($date . ' + ' . $cycle . ' days'));
        if (!$pid || !$date || !$id) { flash('error','All required fields must be filled.'); redirect('/clearing.php?edit='.$id); }
        DB::execute("UPDATE clearing_cycles SET plantation_id=?,date_cleared=?,next_cycle_days=?,next_due_date=?,units_to_clear=?,notes=? WHERE id=? AND estate_id=?",
            [$pid,$date,$cycle,$nextDue,$units!==''?$units:null,$notes,$id,$estateId]);
        flash('success','Clearing record updated.');
        redirect('/clearing.php');
    }

    // ── DELETE ────────────────────────────────────
    if ($action === 'delete') {
        Auth::requireAdmin();
        DB::execute("DELETE FROM clearing_cycles WHERE id=? AND estate_id=?", [(int)($_POST['id']??0), $estateId]);
        flash('success','Record deleted.');
        redirect('/clearing.php');
    }
}

// Load edit row
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = $editId ? DB::fetchOne("SELECT * FROM clearing_cycles WHERE id=? AND estate_id=?", [$editId,$estateId]) : null;

$plantations = DB::fetchAll("SELECT * FROM plantations WHERE estate_id=? AND is_active=1 ORDER BY name", [$estateId]);

$upcomingAll = DB::fetchAll("SELECT cc.*, p.name as plantation_name
    FROM clearing_cycles cc JOIN plantations p ON cc.plantation_id=p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM clearing_cycles WHERE estate_id=? GROUP BY plantation_id) latest
        ON cc.id=latest.max_id
    WHERE cc.estate_id=? ORDER BY cc.next_due_date ASC", [$estateId,$estateId]);

$history = DB::fetchAll("SELECT cc.*, p.name as plantation_name
    FROM clearing_cycles cc JOIN plantations p ON cc.plantation_id=p.id
    WHERE cc.estate_id=? ORDER BY cc.date_cleared DESC, cc.id DESC LIMIT 50", [$estateId]);

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid-2">
<!-- LEFT: FORM -->
<div>

  <?php if ($editRow): ?>
  <!-- EDIT FORM -->
  <div class="form-panel" style="border:2px solid var(--amber-200)">
    <div class="form-panel-title" style="color:var(--amber-600)">
      <i class="ti ti-edit"></i> Edit Clearing Record
      <a href="clearing.php" class="btn btn-outline btn-sm" style="margin-left:auto">
        <i class="ti ti-x"></i> Cancel
      </a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label>Date Cleared *</label>
          <input type="date" name="date_cleared" value="<?= $editRow['date_cleared'] ?>" required>
        </div>
        <div class="form-group">
          <label>Section *</label>
          <select name="plantation_id" required>
            <option value="">— Select —</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id']==$editRow['plantation_id']?'selected':'' ?>>
              <?= sanitize($p['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Next Cycle (days)</label>
          <input type="number" name="next_cycle_days" value="<?= $editRow['next_cycle_days'] ?: 30 ?>" min="1">
          <div style="font-size:11px;color:var(--gray-400);margin-top:3px">
            Next due will be recalculated automatically
          </div>
        </div>
        <div class="form-group">
          <label>Units to Clear</label>
          <input type="number" name="units_to_clear" value="<?= sanitize($editRow['units_to_clear'] ?? '') ?>" min="0" step="0.01" placeholder="e.g. 5">
          <div style="font-size:11px;color:var(--gray-400);margin-top:3px">
            Entered manually — not pulled from the section's own unit/area data
          </div>
        </div>
        <div class="form-group col-full">
          <label>Notes</label>
          <textarea name="notes"><?= sanitize($editRow['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary" style="background:var(--amber-600)">
          <i class="ti ti-check"></i> Update Record
        </button>
        <a href="clearing.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- ADD FORM -->
  <div class="form-panel">
    <div class="form-panel-title"><i class="ti ti-scissors"></i> Log Clearing</div>
    <form method="POST" id="add-clearing-form">
      <input type="hidden" name="action" value="add">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label>Date Cleared *</label>
          <input type="date" name="date_cleared" value="<?= today() ?>" required>
        </div>
        <div class="form-group">
          <label>Section *</label>
          <select name="plantation_id" required>
            <option value="">— Select —</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Next Cycle (days)</label>
          <input type="number" name="next_cycle_days" placeholder="30" value="30" min="1">
        </div>
        <div class="form-group">
          <label>Units to Clear</label>
          <input type="number" name="units_to_clear" placeholder="e.g. 5" min="0" step="0.01">
        </div>
        <div class="form-group col-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="Any observations..."></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">
        <i class="ti ti-check"></i> Save Record
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- RIGHT -->
<div>
  <!-- Reminders -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><div class="card-title"><i class="ti ti-bell"></i> Upcoming Reminders</div></div>
    <?php if ($upcomingAll): ?>
    <?php foreach ($upcomingAll as $f):
      $days = $f['next_due_date'] ? daysUntil($f['next_due_date']) : 999;
      $dotc = $days<=3?'var(--red-400)':($days<=10?'var(--amber-400)':'var(--green-400)');
      $cls  = $days<=3?'due-urgent':($days<=10?'due-soon':'due-ok');
      $lbl  = $days<=0?'Overdue!':'In '.$days.' day'.($days!=1?'s':'');
    ?>
    <div class="fert-item">
      <div class="fert-dot" style="background:<?= $dotc ?>"></div>
      <div class="fert-info">
        <div class="fert-name"><?= sanitize($f['plantation_name']) ?></div>
        <div class="fert-date">Last cleared: <?= fmtDate($f['date_cleared']) ?>
          <?= ($f['units_to_clear']!==null && $f['units_to_clear']!=='') ? ' · Last Time Units: '.number_format((float)$f['units_to_clear'],2) : '' ?></div>
      </div>
      <div class="fert-due <?= $cls ?>"><?= $f['next_due_date'] ? $lbl : 'No due date' ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><i class="ti ti-check"></i><p>No records yet</p></div>
    <?php endif; ?>
  </div>

  <!-- History -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-history"></i> Clearing History</div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date Cleared</th>
            <th>Section</th>
            <th>Units</th>
            <th>Next Due</th>
            <?php if (Auth::isAdmin()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): $isEditing = ($editId === (int)$h['id']); ?>
          <tr style="<?= $isEditing?'background:var(--amber-50)':'' ?>">
            <td><?= fmtDate($h['date_cleared']) ?></td>
            <td><?= sanitize($h['plantation_name']) ?></td>
            <td><?= ($h['units_to_clear']!==null && $h['units_to_clear']!=='') ? number_format((float)$h['units_to_clear'],2) : '—' ?></td>
            <td>
              <?php if ($h['next_due_date']):
                $d = daysUntil($h['next_due_date']);
                $col = $d<=0?'var(--red-400)':($d<=10?'var(--amber-600)':'var(--gray-600)');
              ?>
              <span style="color:<?= $col ?>;font-size:12px">
                <?= fmtDate($h['next_due_date']) ?>
                <?php if ($d<=10): ?>
                <br><small>(<?= $d<=0?'Overdue':'In '.$d.'d' ?>)</small>
                <?php endif; ?>
              </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <?php if (Auth::isAdmin()): ?>
            <td>
              <div style="display:flex;gap:4px">
                <a href="clearing.php?edit=<?= $h['id'] ?>"
                   class="btn btn-outline btn-sm" title="Edit"
                   style="<?= $isEditing?'background:var(--amber-50);border-color:var(--amber-400)':'' ?>">
                  <i class="ti ti-edit"></i>
                </a>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Delete this record?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $h['id'] ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)">
                    <i class="ti ti-trash"></i>
                  </button>
                </form>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if (!$history): ?>
          <tr><td colspan="5">
            <div class="empty-state"><i class="ti ti-scissors"></i><p>No records yet</p></div>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<script>
// Auto-scroll to edit form if editing
<?php if ($editRow): ?>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelector('.form-panel')?.scrollIntoView({behavior:'smooth', block:'start'});
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
