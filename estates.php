<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Estate Management';
Auth::requireAdmin();

$currentUid = (int)(Auth::user()['id']);
$logoDir    = __DIR__ . '/assets/img/';
if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD ESTATE (completely fresh, zero data) ──
    if ($action === 'add') {
        $name     = trim($_POST['name']     ?? '');
        $location = trim($_POST['location'] ?? '');
        $notes    = trim($_POST['notes']    ?? '');
        if (!$name) { flash('error','Estate name is required.'); redirect('/estates.php'); }

        // Create the estate
        $eid = DB::insert(
            "INSERT INTO estates (name,location,notes,is_active) VALUES (?,?,?,1)",
            [$name, $location, $notes]
        );

        // Seed ONLY standard work types — no workers, no data, no copies
        $wtypes = [
            ['Tea Plucking',        'KG',   50,   'Daily tea leaf plucking'],
            ['Clearing Work',       'Unit', 2000, 'Clearing and weeding'],
            ['Tank Spraying',       'Tank', 200,  'Pesticide spraying'],
            ['Helper',              'Day',  1000, 'General helper'],
            ['Basic / Support Work','Unit', 2000, 'Basic support work'],
        ];
        foreach ($wtypes as $wt) {
            DB::insert(
                "INSERT INTO work_types (estate_id,name,unit_label,rate_per_unit,description,is_active) VALUES (?,?,?,?,?,1)",
                [$eid, $wt[0], $wt[1], $wt[2], $wt[3]]
            );
        }

        // Assign only current admin — no one else
        DB::execute(
            "INSERT IGNORE INTO user_estates (user_id,estate_id,role,is_default) VALUES (?,?,'admin',0)",
            [$currentUid, $eid]
        );

        flash('success', 'Estate "' . sanitize($name) . '" created fresh with zero data. Assign users via User Access tab.');
        redirect('/estates.php');
    }

    // ── EDIT ESTATE ───────────────────────────────
    if ($action === 'edit') {
        $id       = (int)($_POST['id']       ?? 0);
        $name     = trim($_POST['name']      ?? '');
        $location = trim($_POST['location']  ?? '');
        $notes    = trim($_POST['notes']     ?? '');
        $active   = (int)($_POST['is_active']?? 1);
        if (!$name || !$id) { flash('error','Estate name is required.'); redirect('/estates.php?edit='.$id); }

        DB::execute(
            "UPDATE estates SET name=?,location=?,notes=?,is_active=? WHERE id=?",
            [$name, $location, $notes, $active, $id]
        );

        // Logo upload
        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['png','jpg','jpeg','svg','webp']) && $file['size'] <= 2*1024*1024) {
                foreach(['png','jpg','jpeg','svg','webp'] as $e) {
                    $old = $logoDir.'estate_'.$id.'_logo.'.$e;
                    if (file_exists($old)) unlink($old);
                }
                $fname = 'estate_'.$id.'_logo.'.$ext;
                if (move_uploaded_file($file['tmp_name'], $logoDir.$fname)) {
                    DB::execute("UPDATE estates SET logo_file=? WHERE id=?", [$fname, $id]);
                }
            }
        }

        flash('success','Estate updated.');
        redirect('/estates.php');
    }

    // ── DELETE ESTATE ─────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Prevent deleting estate 1 (master estate)
        if ($id === 1) {
            flash('error','The primary estate cannot be deleted.');
            redirect('/estates.php');
        }

        // Prevent deleting currently active estate
        if ($id === Auth::estateId()) {
            flash('error','Cannot delete the estate you are currently using. Switch to another estate first.');
            redirect('/estates.php');
        }

        // Get estate name for message
        $est = DB::fetchOne("SELECT name FROM estates WHERE id=?", [$id]);

        // Delete all data for this estate
        DB::execute("DELETE FROM daily_assignments  WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM workers            WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM plantations        WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM fertilizer_cycles  WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM expenses           WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM work_types         WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM app_settings       WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM user_estates       WHERE estate_id=?", [$id]);
        DB::execute("DELETE FROM estates            WHERE id=?",        [$id]);

        // Remove logo file if exists
        $est2 = DB::fetchOne("SELECT logo_file FROM estates WHERE id=?", [$id]);
        if (!empty($est2['logo_file']) && file_exists($logoDir.$est2['logo_file'])) {
            unlink($logoDir.$est2['logo_file']);
        }

        flash('success', 'Estate "' . sanitize($est['name'] ?? '') . '" and all its data deleted permanently.');
        redirect('/estates.php');
    }

    // ── ASSIGN USER TO ESTATE ─────────────────────
    if ($action === 'assign_user') {
        $uid  = (int)($_POST['user_id']    ?? 0);
        $eid  = (int)($_POST['estate_id']  ?? 0);
        $role = in_array($_POST['estate_role']??'',['admin','supervisor']) ? $_POST['estate_role'] : 'supervisor';
        if (!$uid || !$eid) { flash('error','Select a user and estate.'); redirect('/estates.php?tab=users'); }
        DB::execute(
            "INSERT INTO user_estates (user_id,estate_id,role,is_default) VALUES (?,?,?,0)
             ON DUPLICATE KEY UPDATE role=?",
            [$uid, $eid, $role, $role]
        );
        flash('success','User assigned to estate.');
        redirect('/estates.php?tab=users');
    }

    // ── REMOVE USER FROM ESTATE ───────────────────
    if ($action === 'remove_user') {
        $uid = (int)($_POST['user_id']  ?? 0);
        $eid = (int)($_POST['estate_id']?? 0);
        DB::execute("DELETE FROM user_estates WHERE user_id=? AND estate_id=?", [$uid, $eid]);
        flash('success','User removed from estate.');
        redirect('/estates.php?tab=users');
    }

    // ── SET DEFAULT ESTATE ────────────────────────
    if ($action === 'set_default') {
        $uid = (int)($_POST['user_id']  ?? 0);
        $eid = (int)($_POST['estate_id']?? 0);
        DB::execute("UPDATE user_estates SET is_default=0 WHERE user_id=?", [$uid]);
        DB::execute("UPDATE user_estates SET is_default=1 WHERE user_id=? AND estate_id=?", [$uid, $eid]);
        flash('success','Default estate updated.');
        redirect('/estates.php?tab=users');
    }
}

// ── DATA ──────────────────────────────────────────
$tab     = $_GET['tab']  ?? 'estates';
$editId  = (int)($_GET['edit']   ?? 0);
$showAdd = ($_GET['action'] ?? '') === 'add';
$editRow = $editId ? DB::fetchOne("SELECT * FROM estates WHERE id=?", [$editId]) : null;

$estates = DB::fetchAll("SELECT e.*,
    (SELECT COUNT(*) FROM workers           WHERE estate_id=e.id) as worker_count,
    (SELECT COUNT(*) FROM daily_assignments WHERE estate_id=e.id) as assignment_count,
    (SELECT COUNT(*) FROM plantations       WHERE estate_id=e.id) as plantation_count,
    (SELECT COUNT(*) FROM user_estates      WHERE estate_id=e.id) as user_count
    FROM estates e ORDER BY e.id ASC", []);

$users       = DB::fetchAll("SELECT * FROM users ORDER BY role,name", []);
$userEstates = DB::fetchAll("SELECT ue.*, u.name as user_name, u.username, e.name as estate_name
    FROM user_estates ue JOIN users u ON ue.user_id=u.id JOIN estates e ON ue.estate_id=e.id
    ORDER BY u.name, e.name", []);

require_once __DIR__ . '/includes/header.php';
?>

<!-- TABS -->
<div class="tab-row" style="margin-bottom:18px">
  <a class="tab-btn <?= $tab==='estates'?'active':'' ?>" href="estates.php?tab=estates" style="text-decoration:none">
    <i class="ti ti-trees" style="font-size:13px;vertical-align:-2px"></i> Estates
  </a>
  <a class="tab-btn <?= $tab==='users'?'active':'' ?>" href="estates.php?tab=users" style="text-decoration:none">
    <i class="ti ti-users" style="font-size:13px;vertical-align:-2px"></i> User Access
  </a>
</div>

<!-- ══════════════════════════════════════════ -->
<?php if ($tab === 'estates'): ?>
<!-- ══════════════════════════════════════════ -->

<div class="section-header">
  <div class="section-title"><i class="ti ti-trees"></i> All Estates (<?= count($estates) ?>)</div>
  <a href="estates.php?action=add" class="btn btn-primary"><i class="ti ti-plus"></i> Add Estate</a>
</div>

<!-- ADD FORM -->
<?php if ($showAdd): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--green-200)">
  <div class="form-panel-title" style="color:var(--green-700)">
    <i class="ti ti-plus"></i> Add New Estate
    <span style="margin-left:auto;font-size:11px;background:var(--green-50);color:var(--green-700);padding:3px 10px;border-radius:20px;font-weight:600">
      <i class="ti ti-sparkles" style="font-size:11px"></i> Starts completely fresh
    </span>
  </div>
  <div style="background:var(--green-50);border:1px solid var(--green-200);border-radius:var(--radius-md);padding:10px 14px;margin-bottom:16px;font-size:13px;color:var(--green-800)">
    <i class="ti ti-info-circle"></i>
    The new estate will have <strong>zero workers, zero assignments, zero data</strong>. Only standard work types are seeded. You'll add your own workers, sections, and records fresh.
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="grid-form" style="margin-bottom:16px">
      <div class="form-group">
        <label>Estate Name *</label>
        <input type="text" name="name" required placeholder="e.g. Kandy Hills Estate" autofocus>
      </div>
      <div class="form-group">
        <label>Location</label>
        <input type="text" name="location" placeholder="e.g. Kandy, Sri Lanka">
      </div>
      <div class="form-group col-full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Any notes about this estate..." rows="2"></textarea>
      </div>
    </div>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary"><i class="ti ti-plus"></i> Create Fresh Estate</button>
      <a href="estates.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- EDIT FORM -->
<?php if ($editRow): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--amber-200)">
  <div class="form-panel-title" style="color:var(--amber-600)">
    <i class="ti ti-edit"></i> Edit Estate
    <a href="estates.php" class="btn btn-outline btn-sm" style="margin-left:auto"><i class="ti ti-x"></i> Cancel</a>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
    <div class="grid-form" style="margin-bottom:16px">
      <div class="form-group">
        <label>Estate Name *</label>
        <input type="text" name="name" value="<?= sanitize($editRow['name']) ?>" required>
      </div>
      <div class="form-group">
        <label>Location</label>
        <input type="text" name="location" value="<?= sanitize($editRow['location'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Logo <span style="font-weight:400;color:var(--gray-400);font-size:11px">PNG/JPG/SVG · Max 2MB</span></label>
        <input type="file" name="logo" accept="image/*">
        <?php if (!empty($editRow['logo_file'])): ?>
        <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
          <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($editRow['logo_file']) ?>"
               style="width:36px;height:36px;object-fit:contain;border-radius:6px;border:1px solid #e8ede5">
          <span style="font-size:11px;color:var(--gray-400)">Current logo</span>
        </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="is_active">
          <option value="1" <?= $editRow['is_active'] ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= !$editRow['is_active']? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group col-full">
        <label>Notes</label>
        <textarea name="notes" rows="2"><?= sanitize($editRow['notes'] ?? '') ?></textarea>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Update Estate</button>
  </form>
</div>
<?php endif; ?>

<!-- ESTATE CARDS -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:20px">
  <?php foreach ($estates as $e):
    $isCurrent = (Auth::estateId() === (int)$e['id']);
    $isPrimary = ((int)$e['id'] === 1);
  ?>
  <div class="card" style="border-left:4px solid <?= $isCurrent?'var(--green-400)':'#e8ede5' ?>;position:relative">

    <!-- Header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:44px;height:44px;border-radius:10px;background:var(--green-50);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
          <?php if (!empty($e['logo_file'])): ?>
            <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($e['logo_file']) ?>" style="width:100%;height:100%;object-fit:contain;padding:4px">
          <?php else: ?>
            <i class="ti ti-trees" style="font-size:22px;color:var(--green-600)"></i>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:15px;font-weight:700;color:var(--green-900);display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <?= sanitize($e['name']) ?>
            <?php if ($isCurrent): ?>
              <span style="font-size:10px;background:var(--green-600);color:#fff;padding:2px 7px;border-radius:10px">Active</span>
            <?php endif; ?>
            <?php if ($isPrimary): ?>
              <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:10px">Primary</span>
            <?php endif; ?>
          </div>
          <?php if ($e['location']): ?>
            <div style="font-size:12px;color:var(--gray-400)"><i class="ti ti-map-pin" style="font-size:11px"></i> <?= sanitize($e['location']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?= pill($e['is_active']?'Active':'Inactive', $e['is_active']?'active':'inactive') ?>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:14px">
      <?php foreach ([
        [$e['worker_count'],     'Workers',    'var(--green-600)'],
        [$e['plantation_count'], 'Sections',   'var(--teal-600)'],
        [$e['assignment_count'], 'Assignments','var(--amber-600)'],
        [$e['user_count'],       'Users',      '#7c3aed'],
      ] as [$val,$lbl,$clr]): ?>
      <div style="text-align:center;padding:8px 4px;background:var(--gray-50);border-radius:8px">
        <div style="font-size:17px;font-weight:800;color:<?= $clr ?>"><?= $val ?></div>
        <div style="font-size:9px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.04em"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="estates.php?edit=<?= $e['id'] ?>" class="btn btn-outline btn-sm">
        <i class="ti ti-edit"></i> Edit
      </a>
      <?php if (!$isCurrent): ?>
      <a href="estate-picker.php" class="btn btn-outline btn-sm" style="color:var(--green-600)">
        <i class="ti ti-switch-horizontal"></i> Switch Here
      </a>
      <?php endif; ?>
      <?php if (!$isPrimary): ?>
      <form method="POST" style="display:inline"
            onsubmit="return confirm('DELETE estate \'<?= sanitize($e['name']) ?>\'?\n\nThis will permanently delete:\n• All <?= $e['worker_count'] ?> workers\n• All <?= $e['assignment_count'] ?> assignments\n• All plantations, expenses, fertilizer records\n\nThis CANNOT be undone!')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $e['id'] ?>">
        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400);border-color:var(--red-200)">
          <i class="ti ti-trash"></i> Delete
        </button>
      </form>
      <?php else: ?>
      <span style="font-size:11px;color:var(--gray-300);padding:6px 4px;align-self:center">
        <i class="ti ti-lock" style="font-size:11px"></i> Primary — cannot delete
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Add new card -->
  <a href="estates.php?action=add"
     style="border:2px dashed var(--green-200);border-radius:var(--radius-lg);padding:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-decoration:none;color:var(--green-600);min-height:180px;transition:background .15s"
     onmouseover="this.style.background='var(--green-50)'" onmouseout="this.style.background=''">
    <i class="ti ti-plus" style="font-size:32px"></i>
    <span style="font-size:13px;font-weight:600">Add New Estate</span>
    <span style="font-size:11px;color:var(--gray-400)">Starts with zero data</span>
  </a>
</div>

<!-- ══════════════════════════════════════════ -->
<?php else: // USER ACCESS TAB ?>
<!-- ══════════════════════════════════════════ -->

<div class="section-header">
  <div class="section-title"><i class="ti ti-users"></i> User Estate Access</div>
</div>

<!-- Assign form -->
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--green-200)">
  <div class="form-panel-title"><i class="ti ti-user-plus"></i> Assign User to Estate</div>
  <form method="POST">
    <input type="hidden" name="action" value="assign_user">
    <div class="grid-form" style="margin-bottom:14px">
      <div class="form-group">
        <label>User *</label>
        <select name="user_id" required>
          <option value="">— Select User —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>"><?= sanitize($u['name']) ?> (@<?= sanitize($u['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Estate *</label>
        <select name="estate_id" required>
          <option value="">— Select Estate —</option>
          <?php foreach ($estates as $e): ?>
          <option value="<?= $e['id'] ?>"><?= sanitize($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Role in this Estate</label>
        <select name="estate_role">
          <option value="supervisor">Supervisor</option>
          <option value="admin">Admin</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Assign Access</button>
  </form>
</div>

<!-- Access table -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="ti ti-table"></i> Current Access (<?= count($userEstates) ?> links)</div>
  </div>
  <?php if ($userEstates): ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>User</th><th>Estate</th><th>Role</th><th>Default</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($userEstates as $ue): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="avatar" style="width:28px;height:28px;font-size:10px"><?= initials($ue['user_name']) ?></div>
              <div>
                <div style="font-weight:600;font-size:13px"><?= sanitize($ue['user_name']) ?></div>
                <div style="font-size:11px;color:var(--gray-400)">@<?= sanitize($ue['username']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-weight:500"><?= sanitize($ue['estate_name']) ?></td>
          <td><?= pill(ucfirst($ue['role']), $ue['role']==='admin'?'blue':'teal') ?></td>
          <td>
            <?php if ($ue['is_default']): ?>
              <span class="pill pill-green">Default</span>
            <?php else: ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="set_default">
                <input type="hidden" name="user_id" value="<?= $ue['user_id'] ?>">
                <input type="hidden" name="estate_id" value="<?= $ue['estate_id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm">Set Default</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Remove <?= sanitize($ue['user_name']) ?> from <?= sanitize($ue['estate_name']) ?>?')">
              <input type="hidden" name="action" value="remove_user">
              <input type="hidden" name="user_id" value="<?= $ue['user_id'] ?>">
              <input type="hidden" name="estate_id" value="<?= $ue['estate_id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)">
                <i class="ti ti-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="empty-state"><i class="ti ti-users"></i><p>No user-estate links yet.</p></div>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
