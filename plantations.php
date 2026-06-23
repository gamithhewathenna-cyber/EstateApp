<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Plantation Sections';
$estateId = Auth::estateId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name     = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $area     = (float)($_POST['area_hectares'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');
        $active   = (int)($_POST['is_active'] ?? 1);

        if (!$name) { flash('error', 'Section name is required.'); redirect('/plantations.php'); }

        if ($action === 'add') {
            // Check duplicate
            $exists = DB::fetchOne("SELECT id FROM plantations WHERE name=? AND estate_id=?", [$name,$estateId]);
            if ($exists) { flash('error', 'A section with this name already exists.'); redirect('/plantations.php?action=add'); }
            DB::insert("INSERT INTO plantations (estate_id, name, location, area_hectares, notes, is_active) VALUES (?,?,?,?,?,?)",
                [$estateId, $name, $location, $area ?: null, $notes, $active]);
            flash('success', 'Section "' . $name . '" added successfully.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            DB::execute("UPDATE plantations SET name=?, location=?, area_hectares=?, notes=?, is_active=? WHERE id=?",
                [$name, $location, $area ?: null, $notes, $active, $id]);
            flash('success', 'Section updated successfully.');
        }
        redirect('/plantations.php');
    }

    if ($action === 'toggle') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = (int)($_POST['current'] ?? 1);
        DB::execute("UPDATE plantations SET is_active=? WHERE id=?", [$current ? 0 : 1, $id]);
        flash('success', 'Section status updated.');
        redirect('/plantations.php');
    }

    if ($action === 'delete') {
        Auth::requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        // Check if used in assignments
        $used = DB::fetchOne("SELECT COUNT(*) as cnt FROM daily_assignments WHERE plantation_id=? AND estate_id=?", [$id,$estateId]);
        if ($used['cnt'] > 0) {
            flash('error', 'Cannot delete — this section has ' . $used['cnt'] . ' assignment records. Deactivate it instead.');
            redirect('/plantations.php');
        }
        DB::execute("DELETE FROM plantations WHERE id=?", [$id]);
        flash('success', 'Section deleted.');
        redirect('/plantations.php');
    }
}

$plantations = DB::fetchAll("SELECT p.*, 
    (SELECT COUNT(*) FROM daily_assignments da WHERE da.plantation_id=p.id AND da.estate_id=p.estate_id) as total_assignments,
    (SELECT COALESCE(SUM(da.quantity),0) FROM daily_assignments da WHERE da.plantation_id=p.id AND da.estate_id=? AND da.work_type_id=1) as total_kg,
    (SELECT COALESCE(SUM(da.payment),0) FROM daily_assignments da WHERE da.plantation_id=p.id AND da.estate_id=?) as total_pay
    FROM plantations p WHERE p.estate_id=? ORDER BY p.is_active DESC, p.name ASC", [$estateId, $estateId, $estateId]);

$editPlantation = null;
if (!empty($_GET['edit'])) {
    $editPlantation = DB::fetchOne("SELECT * FROM plantations WHERE id=? AND estate_id=?", [(int)$_GET['edit'], $estateId]);
}
$showAdd = (!empty($_GET['action']) && $_GET['action'] === 'add');

require_once __DIR__ . '/includes/header.php';
?>

<div class="section-header">
  <div class="section-title"><i class="ti ti-map-pin"></i> Plantation Sections</div>
  <a href="plantations.php?action=add" class="btn btn-primary">
    <i class="ti ti-plus"></i> Add New Section
  </a>
</div>

<!-- ADD / EDIT FORM -->
<?php if ($showAdd || $editPlantation): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid <?= $editPlantation ? 'var(--amber-200)' : 'var(--green-200)' ?>">
  <div class="form-panel-title" style="color:<?= $editPlantation ? 'var(--amber-600)' : 'var(--green-600)' ?>">
    <i class="ti ti-<?= $editPlantation ? 'edit' : 'map-pin' ?>"></i>
    <?= $editPlantation ? 'Edit Section' : 'Add New Section' ?>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="<?= $editPlantation ? 'edit' : 'add' ?>">
    <?php if ($editPlantation): ?>
    <input type="hidden" name="id" value="<?= $editPlantation['id'] ?>">
    <?php endif; ?>
    <div class="grid-form" style="margin-bottom:16px">
      <div class="form-group">
        <label>Section Name *</label>
        <input type="text" name="name" placeholder="e.g. D Section, Upper Block, River Side..."
               value="<?= sanitize($editPlantation['name'] ?? '') ?>" required autofocus>
      </div>
      <div class="form-group">
        <label>Location / Block</label>
        <input type="text" name="location" placeholder="e.g. North Block, Hill Top..."
               value="<?= sanitize($editPlantation['location'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Area (Hectares)</label>
        <input type="number" name="area_hectares" placeholder="e.g. 10.5" min="0" step="0.01"
               value="<?= $editPlantation['area_hectares'] ?? '' ?>">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="is_active">
          <option value="1" <?= ($editPlantation['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= ($editPlantation['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group col-full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Any notes about this section..."><?= sanitize($editPlantation['notes'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-check"></i> <?= $editPlantation ? 'Update Section' : 'Save Section' ?>
      </button>
      <a href="plantations.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- SECTIONS GRID -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">
  <?php foreach ($plantations as $p): ?>
  <div class="card" style="border-left:4px solid <?= $p['is_active'] ? 'var(--green-400)' : 'var(--gray-200)' ?>;<?= !$p['is_active'] ? 'opacity:.7' : '' ?>">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:40px;height:40px;border-radius:10px;background:<?= $p['is_active'] ? 'var(--green-50)' : 'var(--gray-50)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="ti ti-trees" style="font-size:20px;color:<?= $p['is_active'] ? 'var(--green-600)' : 'var(--gray-400)' ?>"></i>
        </div>
        <div>
          <div style="font-size:15px;font-weight:700;color:var(--green-900)"><?= sanitize($p['name']) ?></div>
          <?php if ($p['location']): ?>
          <div style="font-size:12px;color:var(--gray-400)"><i class="ti ti-map-pin" style="font-size:11px"></i> <?= sanitize($p['location']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?= pill($p['is_active'] ? 'Active' : 'Inactive', $p['is_active'] ? 'active' : 'inactive') ?>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px">
      <div style="text-align:center;padding:8px 4px;background:var(--gray-50);border-radius:8px">
        <div style="font-size:14px;font-weight:700;color:var(--green-800)"><?= number_format($p['total_kg'],0) ?></div>
        <div style="font-size:10px;color:var(--gray-400)">Total KG</div>
      </div>
      <div style="text-align:center;padding:8px 4px;background:var(--gray-50);border-radius:8px">
        <div style="font-size:14px;font-weight:700;color:var(--teal-600)"><?= $p['total_assignments'] ?></div>
        <div style="font-size:10px;color:var(--gray-400)">Records</div>
      </div>
      <div style="text-align:center;padding:8px 4px;background:var(--gray-50);border-radius:8px">
        <div style="font-size:12px;font-weight:700;color:var(--amber-600)"><?= moneyShort($p['total_pay']) ?></div>
        <div style="font-size:10px;color:var(--gray-400)">Total Pay</div>
      </div>
    </div>

    <?php if ($p['area_hectares']): ?>
    <div style="font-size:12px;color:var(--gray-400);margin-bottom:12px">
      <i class="ti ti-ruler" style="font-size:13px;vertical-align:-2px"></i> <?= $p['area_hectares'] ?> hectares
    </div>
    <?php endif; ?>

    <?php if ($p['notes']): ?>
    <div style="font-size:12px;color:var(--gray-600);background:var(--gray-50);border-radius:6px;padding:7px 10px;margin-bottom:12px">
      <?= sanitize($p['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="plantations.php?edit=<?= $p['id'] ?>" class="btn btn-outline btn-sm">
        <i class="ti ti-edit"></i> Edit
      </a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <input type="hidden" name="current" value="<?= $p['is_active'] ?>">
        <button type="submit" class="btn btn-outline btn-sm" style="color:<?= $p['is_active'] ? 'var(--amber-600)' : 'var(--green-600)' ?>">
          <i class="ti ti-<?= $p['is_active'] ? 'eye-off' : 'eye' ?>"></i>
          <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
        </button>
      </form>
      <?php if (Auth::isAdmin() && $p['total_assignments'] == 0): ?>
      <form method="POST" style="display:inline"
            onsubmit="return confirm('Permanently delete <?= sanitize($p['name']) ?>? This cannot be undone.')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)">
          <i class="ti ti-trash"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Add new card shortcut -->
  <a href="plantations.php?action=add"
     style="border:2px dashed var(--green-200);border-radius:var(--radius-lg);padding:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-decoration:none;color:var(--green-600);min-height:160px;transition:background .15s"
     onmouseover="this.style.background='var(--green-50)'" onmouseout="this.style.background=''">
    <i class="ti ti-plus" style="font-size:28px"></i>
    <span style="font-size:13px;font-weight:600">Add New Section</span>
  </a>
</div>

<!-- Summary Table -->
<?php if (count($plantations) > 0): ?>
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="ti ti-table"></i> All Sections Summary</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Section Name</th>
          <th>Location</th>
          <th>Area (Ha)</th>
          <th>Total KG</th>
          <th>Total Records</th>
          <th>Total Payroll</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plantations as $p): ?>
        <tr>
          <td><strong><?= sanitize($p['name']) ?></strong></td>
          <td><?= sanitize($p['location']) ?: '—' ?></td>
          <td><?= $p['area_hectares'] ?: '—' ?></td>
          <td><?= number_format($p['total_kg'],1) ?> kg</td>
          <td><?= $p['total_assignments'] ?></td>
          <td><?= money($p['total_pay']) ?></td>
          <td><?= pill($p['is_active']?'Active':'Inactive',$p['is_active']?'active':'inactive') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
