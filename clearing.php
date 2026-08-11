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
        $pid       = (int)($_POST['plantation_id'] ?? 0);
        $date      = $_POST['date_cleared']         ?? today();
        $cycleOpt  = $_POST['next_cycle_option']    ?? '30';
        $customDue = $_POST['next_due_date_custom'] ?? '';
        $notes     = trim($_POST['notes']           ?? '');
        $uid       = Auth::user()['id'];
        if (!$pid || !$date) { flash('error','Section and date cleared are required.'); redirect('/clearing.php'); }
        $due = ($cycleOpt === '__custom__')
            ? ($customDue ?: null)
            : date('Y-m-d', strtotime($date . ' + ' . (int)$cycleOpt . ' days'));
        DB::insert("INSERT INTO clearing_cycles (estate_id,plantation_id,date_cleared,next_due_date,notes,created_by) VALUES (?,?,?,?,?,?)",
            [$estateId,$pid,$date,$due,$notes,$uid]);
        flash('success','Clearing record saved.');
        redirect('/clearing.php');
    }

    // ── UPDATE ────────────────────────────────────
    if ($action === 'update') {
        Auth::requireAdmin();
        $id        = (int)($_POST['id']             ?? 0);
        $pid       = (int)($_POST['plantation_id']  ?? 0);
        $date      = $_POST['date_cleared']          ?? today();
        $cycleOpt  = $_POST['next_cycle_option']     ?? '30';
        $customDue = $_POST['next_due_date_custom']  ?? '';
        $notes     = trim($_POST['notes']            ?? '');
        if (!$pid || !$date || !$id) { flash('error','All required fields must be filled.'); redirect('/clearing.php?edit='.$id); }
        $due = ($cycleOpt === '__custom__')
            ? ($customDue ?: null)
            : date('Y-m-d', strtotime($date . ' + ' . (int)$cycleOpt . ' days'));
        DB::execute("UPDATE clearing_cycles SET plantation_id=?,date_cleared=?,next_due_date=?,notes=? WHERE id=? AND estate_id=?",
            [$pid,$date,$due,$notes,$id,$estateId]);
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

// Preset clearing cycle lengths (days). If an edited record's gap doesn't
// match one of these, fall back to showing it as a custom exact date.
$cyclePresets = [30, 45, 60, 90];
$editCycleOption = '30';
if ($editRow) {
    $editCycleOption = '__custom__';
    if ($editRow['next_due_date'] && $editRow['date_cleared']) {
        $diffDays = (int)round((strtotime($editRow['next_due_date']) - strtotime($editRow['date_cleared'])) / 86400);
        if (in_array($diffDays, $cyclePresets, true)) $editCycleOption = (string)$diffDays;
    }
}

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
          <label>Next Due Date</label>
          <select name="next_cycle_option" id="edit-cycle-select" onchange="toggleEditCustomDue(this)">
            <?php foreach ($cyclePresets as $d): ?>
            <option value="<?= $d ?>" <?= $editCycleOption==(string)$d?'selected':'' ?>><?= $d ?> Days</option>
            <?php endforeach; ?>
            <option value="__custom__" <?= $editCycleOption==='__custom__'?'selected':'' ?>>Custom Date...</option>
          </select>
          <input type="date" name="next_due_date_custom" id="edit-due-custom-input"
                 value="<?= $editRow['next_due_date'] ?>"
                 style="<?= $editCycleOption==='__custom__'?'display:block':'display:none' ?>;margin-top:8px">
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
          <label>Next Due Date</label>
          <select name="next_cycle_option" id="cycle-select" onchange="toggleCustomDue(this)">
            <?php foreach ($cyclePresets as $d): ?>
            <option value="<?= $d ?>" <?= $d==30?'selected':'' ?>><?= $d ?> Days</option>
            <?php endforeach; ?>
            <option value="__custom__">Custom Date...</option>
          </select>
          <input type="date" name="next_due_date_custom" id="due-custom-input" style="display:none;margin-top:8px">
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
        <div class="fert-date">Last cleared: <?= fmtDate($f['date_cleared']) ?></div>
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
            <th>Next Due</th>
            <?php if (Auth::isAdmin()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): $isEditing = ($editId === (int)$h['id']); ?>
          <tr style="<?= $isEditing?'background:var(--amber-50)':'' ?>">
            <td><?= fmtDate($h['date_cleared']) ?></td>
            <td><?= sanitize($h['plantation_name']) ?></td>
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
          <tr><td colspan="4">
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
function toggleCustomDue(sel) {
  var inp = document.getElementById('due-custom-input');
  if (sel.value === '__custom__') {
    inp.style.display = 'block'; inp.required = true; inp.focus();
  } else {
    inp.style.display = 'none'; inp.required = false; inp.value = '';
  }
}

function toggleEditCustomDue(sel) {
  var inp = document.getElementById('edit-due-custom-input');
  if (sel.value === '__custom__') {
    inp.style.display = 'block'; inp.required = true; inp.focus();
  } else {
    inp.style.display = 'none'; inp.required = false;
  }
}

// Auto-scroll to edit form if editing
<?php if ($editRow): ?>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelector('.form-panel')?.scrollIntoView({behavior:'smooth', block:'start'});
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
