<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Fertilizer Cycles';
Auth::check();
$estateId = Auth::estateId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD ──────────────────────────────────────
    if ($action === 'add') {
        $pid     = (int)($_POST['plantation_id']   ?? 0);
        $date    = $_POST['applied_date']           ?? today();
        $ftype   = trim($_POST['fertilizer_type']  ?? '');
        $custom  = trim($_POST['fertilizer_type_custom'] ?? '');
        if ($ftype === '__custom__') $ftype = $custom;
        $amt     = (float)($_POST['amount_kg']      ?? 0);
        $cycle   = (int)($_POST['next_cycle_days']  ?? 30);
        $notes   = trim($_POST['notes']             ?? '');
        $nextDue = date('Y-m-d', strtotime($date . ' + ' . $cycle . ' days'));
        $uid     = Auth::user()['id'];
        if (!$pid || !$ftype) { flash('error','Plantation and fertilizer type are required.'); redirect('/fertilizer.php'); }
        DB::insert("INSERT INTO fertilizer_cycles (estate_id,plantation_id,applied_date,fertilizer_type,amount_kg,next_cycle_days,next_due_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)",
            [$estateId,$pid,$date,$ftype,$amt,$cycle,$nextDue,$notes,$uid]);
        flash('success','Fertilizer record saved.');
        redirect('/fertilizer.php');
    }

    // ── UPDATE ────────────────────────────────────
    if ($action === 'update') {
        Auth::requireAdmin();
        $id      = (int)($_POST['id']              ?? 0);
        $pid     = (int)($_POST['plantation_id']   ?? 0);
        $date    = $_POST['applied_date']           ?? today();
        $ftype   = trim($_POST['fertilizer_type']  ?? '');
        $custom  = trim($_POST['fertilizer_type_custom'] ?? '');
        if ($ftype === '__custom__') $ftype = $custom;
        $amt     = (float)($_POST['amount_kg']      ?? 0);
        $cycle   = (int)($_POST['next_cycle_days']  ?? 30);
        $notes   = trim($_POST['notes']             ?? '');
        $nextDue = date('Y-m-d', strtotime($date . ' + ' . $cycle . ' days'));
        if (!$pid || !$ftype || !$id) { flash('error','All fields are required.'); redirect('/fertilizer.php?edit='.$id); }
        DB::execute("UPDATE fertilizer_cycles SET plantation_id=?,applied_date=?,fertilizer_type=?,amount_kg=?,next_cycle_days=?,next_due_date=?,notes=? WHERE id=? AND estate_id=?",
            [$pid,$date,$ftype,$amt,$cycle,$nextDue,$notes,$id,$estateId]);
        flash('success','Fertilizer record updated.');
        redirect('/fertilizer.php');
    }

    // ── DELETE ────────────────────────────────────
    if ($action === 'delete') {
        Auth::requireAdmin();
        DB::execute("DELETE FROM fertilizer_cycles WHERE id=? AND estate_id=?", [(int)($_POST['id']??0), $estateId]);
        flash('success','Record deleted.');
        redirect('/fertilizer.php');
    }
}

// Load edit row
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = $editId ? DB::fetchOne("SELECT * FROM fertilizer_cycles WHERE id=? AND estate_id=?", [$editId,$estateId]) : null;

$plantations = DB::fetchAll("SELECT * FROM plantations WHERE estate_id=? AND is_active=1 ORDER BY name", [$estateId]);

$upcomingAll = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc JOIN plantations p ON fc.plantation_id=p.id
    JOIN (SELECT plantation_id, MAX(id) as max_id FROM fertilizer_cycles WHERE estate_id=? GROUP BY plantation_id) latest
        ON fc.id=latest.max_id
    WHERE fc.estate_id=? ORDER BY fc.next_due_date ASC", [$estateId,$estateId]);

$history = DB::fetchAll("SELECT fc.*, p.name as plantation_name
    FROM fertilizer_cycles fc JOIN plantations p ON fc.plantation_id=p.id
    WHERE fc.estate_id=? ORDER BY fc.applied_date DESC, fc.id DESC LIMIT 50", [$estateId]);

// Known fertilizer types for dropdown
$fertTypes = ['T709','T750','T200','Urea','MOP (Muriate of Potash)','TSP (Triple Super Phosphate)','Compound Fertilizer','Organic'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid-2">
<!-- LEFT: FORM -->
<div>

  <?php if ($editRow): ?>
  <!-- EDIT FORM -->
  <div class="form-panel" style="border:2px solid var(--amber-200)">
    <div class="form-panel-title" style="color:var(--amber-600)">
      <i class="ti ti-edit"></i> Edit Fertilizer Record
      <a href="fertilizer.php" class="btn btn-outline btn-sm" style="margin-left:auto">
        <i class="ti ti-x"></i> Cancel
      </a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label>Date Applied *</label>
          <input type="date" name="applied_date" value="<?= $editRow['applied_date'] ?>" required>
        </div>
        <div class="form-group">
          <label>Plantation *</label>
          <select name="plantation_id" required>
            <option value="">— Select —</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id']==$editRow['plantation_id']?'selected':'' ?>>
              <?= sanitize($p['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-full">
          <label>Fertilizer Type *</label>
          <?php
            $isCustom = !in_array($editRow['fertilizer_type'], $fertTypes);
          ?>
          <select name="fertilizer_type" id="edit-fert-type-select" required onchange="toggleEditCustomType(this)">
            <optgroup label="Tea Fertilizers">
              <option <?= $editRow['fertilizer_type']==='T709'?'selected':'' ?>>T709</option>
              <option <?= $editRow['fertilizer_type']==='T750'?'selected':'' ?>>T750</option>
              <option <?= $editRow['fertilizer_type']==='T200'?'selected':'' ?>>T200</option>
            </optgroup>
            <optgroup label="General Fertilizers">
              <option <?= $editRow['fertilizer_type']==='Urea'?'selected':'' ?>>Urea</option>
              <option <?= $editRow['fertilizer_type']==='MOP (Muriate of Potash)'?'selected':'' ?>>MOP (Muriate of Potash)</option>
              <option <?= $editRow['fertilizer_type']==='TSP (Triple Super Phosphate)'?'selected':'' ?>>TSP (Triple Super Phosphate)</option>
              <option <?= $editRow['fertilizer_type']==='Compound Fertilizer'?'selected':'' ?>>Compound Fertilizer</option>
              <option <?= $editRow['fertilizer_type']==='Organic'?'selected':'' ?>>Organic</option>
            </optgroup>
            <optgroup label="Other">
              <?php if ($isCustom): ?>
              <option value="<?= sanitize($editRow['fertilizer_type']) ?>" selected><?= sanitize($editRow['fertilizer_type']) ?></option>
              <?php endif; ?>
              <option value="__custom__">Other / Custom...</option>
            </optgroup>
          </select>
          <input type="text" name="fertilizer_type_custom" id="edit-fert-custom-input"
                 placeholder="Enter fertilizer name..."
                 value="<?= $isCustom ? sanitize($editRow['fertilizer_type']) : '' ?>"
                 style="<?= $isCustom?'display:block':'display:none' ?>;margin-top:8px">
        </div>
        <div class="form-group">
          <label>Amount (kg)</label>
          <input type="number" name="amount_kg" value="<?= $editRow['amount_kg'] ?>" min="0" step="0.1">
        </div>
        <div class="form-group">
          <label>Next Cycle (days)</label>
          <input type="number" name="next_cycle_days" value="<?= $editRow['next_cycle_days'] ?: 30 ?>" min="1">
          <div style="font-size:11px;color:var(--gray-400);margin-top:3px">
            Next due will be recalculated automatically
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
        <a href="fertilizer.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- ADD FORM -->
  <div class="form-panel">
    <div class="form-panel-title"><i class="ti ti-droplet-plus"></i> Log Fertilizer Application</div>
    <form method="POST" id="add-fert-form">
      <input type="hidden" name="action" value="add">
      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label>Date Applied *</label>
          <input type="date" name="applied_date" value="<?= today() ?>" required>
        </div>
        <div class="form-group">
          <label>Plantation *</label>
          <select name="plantation_id" required>
            <option value="">— Select —</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-full">
          <label>Fertilizer Type *</label>
          <select name="fertilizer_type" id="fert-type-select" required onchange="toggleCustomType(this)">
            <optgroup label="Tea Fertilizers">
              <option>T709</option><option>T750</option><option>T200</option>
            </optgroup>
            <optgroup label="General Fertilizers">
              <option>Urea</option>
              <option>MOP (Muriate of Potash)</option>
              <option>TSP (Triple Super Phosphate)</option>
              <option>Compound Fertilizer</option>
              <option>Organic</option>
            </optgroup>
            <optgroup label="Other">
              <option value="__custom__">Other / Custom...</option>
            </optgroup>
          </select>
          <input type="text" name="fertilizer_type_custom" id="fert-custom-input"
                 placeholder="Enter fertilizer name..." style="display:none;margin-top:8px">
        </div>
        <div class="form-group">
          <label>Amount (kg)</label>
          <input type="number" name="amount_kg" placeholder="e.g. 50" min="0" step="0.1">
        </div>
        <div class="form-group">
          <label>Next Cycle (days)</label>
          <input type="number" name="next_cycle_days" placeholder="30" value="30" min="1">
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
        <div class="fert-name"><?= sanitize($f['plantation_name']) ?> — <?= sanitize($f['fertilizer_type']) ?></div>
        <div class="fert-date">Last: <?= fmtDate($f['applied_date']) ?>
          <?= $f['amount_kg']?' · '.$f['amount_kg'].' kg':'' ?></div>
      </div>
      <div class="fert-due <?= $cls ?>"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><i class="ti ti-check"></i><p>No records yet</p></div>
    <?php endif; ?>
  </div>

  <!-- History -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-history"></i> Application History</div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Plantation</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Next Due</th>
            <?php if (Auth::isAdmin()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): $isEditing = ($editId === (int)$h['id']); ?>
          <tr style="<?= $isEditing?'background:var(--amber-50)':'' ?>">
            <td><?= fmtDate($h['applied_date']) ?></td>
            <td><?= sanitize($h['plantation_name']) ?></td>
            <td>
              <span style="background:var(--teal-50);color:var(--teal-700);padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600">
                <?= sanitize($h['fertilizer_type']) ?>
              </span>
            </td>
            <td><?= $h['amount_kg']>0 ? $h['amount_kg'].' kg' : '—' ?></td>
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
                <a href="fertilizer.php?edit=<?= $h['id'] ?>"
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
          <tr><td colspan="6">
            <div class="empty-state"><i class="ti ti-droplet-off"></i><p>No records yet</p></div>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<script>
function toggleCustomType(sel) {
  var inp = document.getElementById('fert-custom-input');
  if (sel.value === '__custom__') {
    inp.style.display = 'block'; inp.required = true; inp.focus();
  } else {
    inp.style.display = 'none'; inp.required = false; inp.value = '';
  }
}

function toggleEditCustomType(sel) {
  var inp = document.getElementById('edit-fert-custom-input');
  if (sel.value === '__custom__') {
    inp.style.display = 'block'; inp.required = true; inp.focus();
  } else {
    inp.style.display = 'none'; inp.required = false; inp.value = '';
  }
}

// Add form submit — handle custom type
var addForm = document.getElementById('add-fert-form');
if (addForm) {
  addForm.addEventListener('submit', function(e) {
    var sel = document.getElementById('fert-type-select');
    var inp = document.getElementById('fert-custom-input');
    if (sel && sel.value === '__custom__') {
      if (!inp.value.trim()) { e.preventDefault(); inp.focus(); alert('Please enter the fertilizer name.'); return; }
      var opt = new Option(inp.value.trim(), inp.value.trim(), true, true);
      sel.add(opt); sel.value = inp.value.trim();
    }
  });
}

// Edit form submit — handle custom type
var editFormEl = document.querySelector('form[action] input[name=action][value=update]');
if (editFormEl) {
  editFormEl.closest('form').addEventListener('submit', function(e) {
    var sel = document.getElementById('edit-fert-type-select');
    var inp = document.getElementById('edit-fert-custom-input');
    if (sel && sel.value === '__custom__') {
      if (!inp.value.trim()) { e.preventDefault(); inp.focus(); alert('Please enter the fertilizer name.'); return; }
      var opt = new Option(inp.value.trim(), inp.value.trim(), true, true);
      sel.add(opt); sel.value = inp.value.trim();
    }
  });
}

// Auto-scroll to edit form if editing
<?php if ($editRow): ?>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelector('.form-panel')?.scrollIntoView({behavior:'smooth', block:'start'});
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
