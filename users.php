<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'User Management';
Auth::requireAdmin();

$currentUserId = (int)(Auth::user()['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD USER ─────────────────────────────────
    if ($action === 'add') {
        $name  = trim($_POST['name']     ?? '');
        $uname = trim($_POST['username'] ?? '');
        $pass  = $_POST['password']      ?? '';
        $pass2 = $_POST['password2']     ?? '';
        $role  = in_array($_POST['role']??'', ['admin','supervisor'])
                 ? $_POST['role'] : 'supervisor';
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!$name)            { flash('error','Full name is required.');         redirect('/users.php?action=add'); }
        if (!$uname)           { flash('error','Username is required.');          redirect('/users.php?action=add'); }
        if (strlen($pass) < 6) { flash('error','Password must be at least 6 characters.'); redirect('/users.php?action=add'); }
        if ($pass !== $pass2)  { flash('error','Passwords do not match.');        redirect('/users.php?action=add'); }

        // Check duplicate username
        $exists = DB::fetchOne("SELECT id FROM users WHERE username = ?", [$uname]);
        if ($exists) { flash('error','Username "'.$uname.'" is already taken.'); redirect('/users.php?action=add'); }

        // Hash password properly
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);

        // Verify hash works before saving
        if (!password_verify($pass, $hash)) {
            flash('error', 'Password hashing failed. Please try again.');
            redirect('/users.php?action=add');
        }

        DB::insert(
            "INSERT INTO users (name, username, password, role, email, phone, is_active) VALUES (?,?,?,?,?,?,1)",
            [$name, $uname, $hash, $role, $email, $phone]
        );
        flash('success', 'User "' . $uname . '" created successfully. They can now log in.');
        redirect('/users.php');
    }

    // ── EDIT USER ─────────────────────────────────
    if ($action === 'edit') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name']  ?? '');
        $role   = in_array($_POST['role']??'', ['admin','supervisor'])
                  ? $_POST['role'] : 'supervisor';
        $active = (int)($_POST['is_active'] ?? 1);
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $pass   = $_POST['password']  ?? '';
        $pass2  = $_POST['password2'] ?? '';

        if (!$name) { flash('error','Full name is required.'); redirect('/users.php?edit='.$id); }
        if ($id === $currentUserId && $active == 0) {
            flash('error',"You can't deactivate your own account.");
            redirect('/users.php?edit='.$id);
        }

        // Build update query — only update password if provided
        if ($pass !== '') {
            if (strlen($pass) < 6) {
                flash('error','New password must be at least 6 characters.');
                redirect('/users.php?edit='.$id);
            }
            if ($pass !== $pass2) {
                flash('error','Passwords do not match.');
                redirect('/users.php?edit='.$id);
            }
            // Hash and verify before saving
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
            if (!password_verify($pass, $hash)) {
                flash('error', 'Password hashing failed. Please try again.');
                redirect('/users.php?edit='.$id);
            }
            DB::execute(
                "UPDATE users SET name=?, role=?, is_active=?, email=?, phone=?, password=?, updated_at=NOW() WHERE id=?",
                [$name, $role, $active, $email, $phone, $hash, $id]
            );
        } else {
            // No password change — don't touch the password column at all
            DB::execute(
                "UPDATE users SET name=?, role=?, is_active=?, email=?, phone=?, updated_at=NOW() WHERE id=?",
                [$name, $role, $active, $email, $phone, $id]
            );
        }

        // If editing own account, update session name
        if ($id === $currentUserId) {
            $_SESSION['user_name'] = $name;
            $_SESSION['user_role'] = $role;
        }

        flash('success', 'User updated successfully.');
        redirect('/users.php');
    }

    // ── DELETE USER ───────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $currentUserId) {
            flash('error',"You can't delete your own account.");
            redirect('/users.php');
        }
        $user = DB::fetchOne("SELECT username FROM users WHERE id=?", [$id]);
        DB::execute("DELETE FROM users WHERE id=?", [$id]);
        flash('success', 'User "' . sanitize($user['username'] ?? '') . '" deleted.');
        redirect('/users.php');
    }
}

$users    = DB::fetchAll("SELECT * FROM users ORDER BY role ASC, name ASC");
$editUser = !empty($_GET['edit'])
    ? DB::fetchOne("SELECT * FROM users WHERE id=?", [(int)$_GET['edit']])
    : null;
$showAdd  = (!empty($_GET['action']) && $_GET['action'] === 'add');

require_once __DIR__ . '/includes/header.php';
?>

<div class="section-header">
  <div class="section-title"><i class="ti ti-shield-lock"></i> System Users</div>
  <a href="users.php?action=add" class="btn btn-primary"><i class="ti ti-user-plus"></i> Add User</a>
</div>

<!-- ── ADD USER FORM (inline, no modal) ── -->
<?php if ($showAdd): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--green-200)">
  <div class="form-panel-title"><i class="ti ti-user-plus"></i> Create New User</div>
  <form method="POST" id="add-user-form">
    <input type="hidden" name="action" value="add">
    <div class="grid-form" style="margin-bottom:16px">

      <div class="form-group col-full">
        <label>Full Name *</label>
        <input type="text" name="name" required placeholder="e.g. Sunil Perera" autofocus>
      </div>

      <div class="form-group">
        <label>Username *</label>
        <input type="text" name="username" required placeholder="Used to login"
               autocomplete="new-password">
        <div style="font-size:11px;color:var(--gray-400);margin-top:3px">Lowercase letters and numbers only</div>
      </div>

      <div class="form-group">
        <label>Role *</label>
        <select name="role">
          <option value="supervisor">Supervisor</option>
          <option value="admin">Admin</option>
        </select>
      </div>

      <div class="form-group">
        <label>Password *</label>
        <div style="position:relative">
          <input type="password" name="password" id="new-pw" required
                 placeholder="Min. 6 characters" minlength="6"
                 autocomplete="new-password"
                 oninput="checkNewPwMatch()"
                 style="width:100%;padding-right:40px">
          <button type="button" onclick="toggleFieldPw('new-pw',this)"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:17px">
            <i class="ti ti-eye"></i>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label>Confirm Password *</label>
        <div style="position:relative">
          <input type="password" name="password2" id="new-pw2" required
                 placeholder="Repeat password"
                 autocomplete="new-password"
                 oninput="checkNewPwMatch()"
                 style="width:100%;padding-right:40px">
          <button type="button" onclick="toggleFieldPw('new-pw2',this)"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:17px">
            <i class="ti ti-eye"></i>
          </button>
        </div>
        <div id="new-pw-match" style="font-size:11px;margin-top:3px"></div>
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" placeholder="email@example.com">
      </div>

      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="phone" placeholder="+94 7X XXX XXXX">
      </div>

    </div>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary" id="add-user-submit">
        <i class="ti ti-check"></i> Create User
      </button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── EDIT USER FORM (inline) ── -->
<?php if ($editUser): ?>
<div class="form-panel" style="margin-bottom:20px;border:2px solid var(--amber-200)">
  <div class="form-panel-title" style="color:var(--amber-600)">
    <i class="ti ti-edit"></i> Edit User — <?= sanitize($editUser['name']) ?>
    <a href="users.php" class="btn btn-outline btn-sm" style="margin-left:auto"><i class="ti ti-x"></i> Cancel</a>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
    <div class="grid-form" style="margin-bottom:16px">

      <div class="form-group col-full">
        <label>Full Name *</label>
        <input type="text" name="name" value="<?= sanitize($editUser['name']) ?>" required>
      </div>

      <div class="form-group">
        <label>Username</label>
        <input type="text" value="<?= sanitize($editUser['username']) ?>" disabled
               style="background:var(--gray-50);color:var(--gray-400)">
        <div style="font-size:11px;color:var(--gray-400);margin-top:3px">Username cannot be changed</div>
      </div>

      <div class="form-group">
        <label>Role</label>
        <select name="role">
          <option value="supervisor" <?= $editUser['role']==='supervisor'?'selected':'' ?>>Supervisor</option>
          <option value="admin"      <?= $editUser['role']==='admin'     ?'selected':'' ?>>Admin</option>
        </select>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="is_active">
          <option value="1" <?= $editUser['is_active'] ?'selected':'' ?>>Active</option>
          <option value="0" <?= !$editUser['is_active']?'selected':'' ?>>Inactive</option>
        </select>
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?= sanitize($editUser['email'] ?? '') ?>"
               placeholder="email@example.com">
      </div>

      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= sanitize($editUser['phone'] ?? '') ?>"
               placeholder="+94 7X XXX XXXX">
      </div>

      <!-- Password section — clearly labelled, only filled if changing -->
      <div class="form-group col-full" style="background:var(--amber-50);border:1px solid var(--amber-200);border-radius:var(--radius-md);padding:14px">
        <div style="font-size:13px;font-weight:700;color:var(--amber-800);margin-bottom:10px">
          <i class="ti ti-lock"></i> Change Password
          <span style="font-weight:400;color:var(--amber-600);font-size:12px"> — leave both fields empty to keep current password</span>
        </div>
        <div class="grid-form">
          <div class="form-group" style="margin-bottom:0">
            <label>New Password</label>
            <div style="position:relative">
              <input type="password" name="password" id="edit-pw"
                     placeholder="Leave blank to keep current"
                     minlength="6" autocomplete="new-password"
                     oninput="checkEditPwMatch()"
                     style="width:100%;padding-right:40px">
              <button type="button" onclick="toggleFieldPw('edit-pw',this)"
                      style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:17px">
                <i class="ti ti-eye"></i>
              </button>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label>Confirm New Password</label>
            <div style="position:relative">
              <input type="password" name="password2" id="edit-pw2"
                     placeholder="Repeat new password"
                     autocomplete="new-password"
                     oninput="checkEditPwMatch()"
                     style="width:100%;padding-right:40px">
              <button type="button" onclick="toggleFieldPw('edit-pw2',this)"
                      style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:17px">
                <i class="ti ti-eye"></i>
              </button>
            </div>
            <div id="edit-pw-match" style="font-size:11px;margin-top:3px"></div>
          </div>
        </div>
      </div>

    </div>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary" id="edit-user-submit">
        <i class="ti ti-check"></i> Update User
      </button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── USER TABLE ── -->
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>User</th><th>Username</th><th>Role</th>
          <th>Email</th><th>Last Login</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="avatar"><?= initials($u['name']) ?></div>
              <strong><?= sanitize($u['name']) ?></strong>
            </div>
          </td>
          <td>
            <code style="background:var(--gray-50);padding:2px 8px;border-radius:4px;font-size:12px">
              <?= sanitize($u['username']) ?>
            </code>
          </td>
          <td><?= pill(ucfirst($u['role']), $u['role']==='admin'?'blue':'teal') ?></td>
          <td style="font-size:12px;color:var(--gray-600)"><?= sanitize($u['email']) ?: '—' ?></td>
          <td style="font-size:12px;color:var(--gray-400)">
            <?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Never' ?>
          </td>
          <td><?= pill($u['is_active']?'Active':'Inactive', $u['is_active']?'active':'inactive') ?></td>
          <td>
            <div style="display:flex;gap:5px">
              <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-outline btn-sm">
                <i class="ti ti-edit"></i> Edit
              </a>
              <?php if ($u['id'] != $currentUserId): ?>
              <form method="POST" onsubmit="return confirm('Delete user \'<?= sanitize($u['name']) ?>\'? This cannot be undone.')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)">
                  <i class="ti ti-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleFieldPw(id, btn) {
  var inp  = document.getElementById(id);
  var icon = btn.querySelector('i');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-off';
}

function checkNewPwMatch() {
  var p1  = document.getElementById('new-pw');
  var p2  = document.getElementById('new-pw2');
  var lbl = document.getElementById('new-pw-match');
  var btn = document.getElementById('add-user-submit');
  if (!p1 || !p2 || !lbl) return;
  if (!p2.value) { lbl.textContent = ''; return; }
  var ok = p1.value === p2.value;
  lbl.textContent = ok ? '✔ Passwords match' : '✘ Passwords do not match';
  lbl.style.color = ok ? '#22c55e' : '#ef4444';
  if (btn) btn.disabled = !ok;
}

function checkEditPwMatch() {
  var p1  = document.getElementById('edit-pw');
  var p2  = document.getElementById('edit-pw2');
  var lbl = document.getElementById('edit-pw-match');
  var btn = document.getElementById('edit-user-submit');
  if (!p1 || !p2 || !lbl) return;
  // If both empty — keeping current, that's fine
  if (!p1.value && !p2.value) { lbl.textContent = ''; if(btn) btn.disabled = false; return; }
  if (!p2.value) { lbl.textContent = ''; return; }
  var ok = p1.value === p2.value;
  lbl.textContent = ok ? '✔ Passwords match' : '✘ Passwords do not match';
  lbl.style.color = ok ? '#22c55e' : '#ef4444';
  if (btn) btn.disabled = !ok;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
