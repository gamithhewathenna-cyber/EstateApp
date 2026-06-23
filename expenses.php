<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Expenses';
$estateId = Auth::estateId();

// Update expense_type ENUM to include new categories (run once)
try {
    DB::execute("ALTER TABLE expenses MODIFY COLUMN expense_type 
        ENUM('Spray Can','Pohora','Dolomite','Food','Transport','Equipment','Miscellaneous') 
        NOT NULL DEFAULT 'Miscellaneous'", []);
} catch (Exception $e) { /* already updated */ }

// Predefined items with default prices (editable per entry)
$presetItems = [
    'Spray Can'     => ['icon'=>'ti-spray',        'color'=>'var(--teal-50)',  'text'=>'var(--teal-600)',  'default'=>0],
    'Pohora'        => ['icon'=>'ti-leaf',          'color'=>'var(--green-50)', 'text'=>'var(--green-600)', 'default'=>0],
    'Dolomite'      => ['icon'=>'ti-mountain',      'color'=>'#EDE9FE',         'text'=>'#6D28D9',          'default'=>0],
    'Food'          => ['icon'=>'ti-salad',         'color'=>'var(--amber-50)', 'text'=>'var(--amber-600)', 'default'=>0],
    'Transport'     => ['icon'=>'ti-truck',         'color'=>'var(--teal-50)',  'text'=>'var(--teal-400)',  'default'=>0],
    'Equipment'     => ['icon'=>'ti-tool',          'color'=>'var(--green-50)', 'text'=>'var(--green-600)', 'default'=>0],
    'Miscellaneous' => ['icon'=>'ti-dots-circle-horizontal','color'=>'var(--gray-50)','text'=>'var(--gray-600)','default'=>0],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $date  = $_POST['expense_date'] ?? today();
        $pid   = !empty($_POST['plantation_id']) ? (int)$_POST['plantation_id'] : null;
        $type  = $_POST['expense_type'] ?? 'Miscellaneous';
        $amt   = (float)($_POST['amount'] ?? 0);
        $qty   = (float)($_POST['quantity'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');
        $uid   = Auth::user()['id'];

        if (!array_key_exists($type, $presetItems)) $type = 'Miscellaneous';
        if ($amt <= 0) { flash('error','Amount must be greater than 0.'); redirect('/expenses.php'); }

        $totalAmt = round($amt * $qty, 2);

        DB::insert("INSERT INTO expenses (estate_id,expense_date,plantation_id,expense_type,amount,notes,created_by) VALUES (?,?,?,?,?,?,?)",
            [$estateId, $date, $pid, $type, $totalAmt, $notes, $uid]);
        flash('success', $type . ' expense of ' . money($totalAmt) . ' added.');
        redirect('/expenses.php');
    }

    if ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $date  = $_POST['expense_date'] ?? today();
        $pid   = !empty($_POST['plantation_id']) ? (int)$_POST['plantation_id'] : null;
        $type  = $_POST['expense_type'] ?? 'Miscellaneous';
        $amt   = (float)($_POST['amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($amt <= 0) { flash('error','Amount must be greater than 0.'); redirect('/expenses.php'); }
        DB::execute("UPDATE expenses SET expense_date=?,plantation_id=?,expense_type=?,amount=?,notes=?,updated_at=NOW() WHERE id=?",
            [$date,$pid,$type,$amt,$notes,$id]);
        flash('success','Expense updated.');
        redirect('/expenses.php?from='.$_POST['from'].'&to='.$_POST['to']);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        DB::execute("DELETE FROM expenses WHERE id=? AND estate_id=?", [$id,$estateId]);
        flash('success','Expense deleted.');
        redirect('/expenses.php?from='.$_POST['from'].'&to='.$_POST['to']);
    }
}

// Date range
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? today();
$editId   = (int)($_GET['edit'] ?? 0);
$editRow  = $editId ? DB::fetchOne("SELECT * FROM expenses WHERE id=? AND estate_id=?", [$editId,$estateId]) : null;

$plantations = DB::fetchAll("SELECT * FROM plantations WHERE estate_id=? AND is_active=1 ORDER BY name", [$estateId]);

// All expenses in range
$expenses = DB::fetchAll("SELECT e.*, p.name as plantation_name 
    FROM expenses e LEFT JOIN plantations p ON e.plantation_id=p.id 
    WHERE e.estate_id=? AND e.expense_date BETWEEN ? AND ? 
    ORDER BY e.expense_date DESC, e.id DESC", [$estateId, $dateFrom, $dateTo]);

$grandTotal = array_sum(array_column($expenses,'amount'));

// Monthly summary (current month always)
$thisMonth   = date('Y-m');
$monthTotal  = DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE estate_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=?", [$estateId, $thisMonth]);

// By category in range
$byCategory = DB::fetchAll("SELECT expense_type, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt 
    FROM expenses WHERE estate_id=? AND expense_date BETWEEN ? AND ? 
    GROUP BY expense_type ORDER BY total DESC", [$estateId,$dateFrom,$dateTo]);

// By plantation in range
$byPlant = DB::fetchAll("SELECT COALESCE(p.name,'All / General') as pname, COALESCE(SUM(e.amount),0) as total
    FROM expenses e LEFT JOIN plantations p ON e.plantation_id=p.id
    WHERE e.estate_id=? AND e.expense_date BETWEEN ? AND ?
    GROUP BY e.plantation_id ORDER BY total DESC", [$estateId,$dateFrom,$dateTo]);

$maxCat   = max(array_column($byCategory,'total') ?: [1]);
$maxPlant = max(array_column($byPlant,'total') ?: [1]);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.preset-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:16px}
.preset-card{border:2px solid #e8ede5;border-radius:var(--radius-md);padding:12px 10px;cursor:pointer;text-align:center;transition:border-color .15s,background .15s}
.preset-card:hover{border-color:var(--green-200);background:var(--green-50)}
.preset-card.selected{border-color:var(--green-400);background:var(--green-50)}
.preset-card .pc-icon{font-size:24px;margin-bottom:6px;display:block}
.preset-card .pc-name{font-size:12px;font-weight:700;color:var(--green-900)}
.exp-table tr:hover td{background:#f8faf5}
</style>

<div style="display:grid;grid-template-columns:380px 1fr;gap:18px;align-items:start">

<!-- ===== LEFT: ADD FORM ===== -->
<div>

  <!-- MONTHLY SUMMARY CARD -->
  <div class="card" style="margin-bottom:16px;border-left:4px solid var(--amber-400)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <div style="font-size:13px;font-weight:700;color:var(--green-900);display:flex;align-items:center;gap:7px">
        <i class="ti ti-calendar-stats" style="color:var(--amber-400)"></i>
        Monthly Summary — <?= date('F Y') ?>
      </div>
    </div>
    <div style="font-size:32px;font-weight:700;color:var(--amber-600);margin-bottom:8px">
      <?= money($monthTotal['total']) ?>
    </div>
    <?php
    $monthCats = DB::fetchAll("SELECT expense_type, COALESCE(SUM(amount),0) as total FROM expenses 
        WHERE estate_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY expense_type ORDER BY total DESC", [$estateId,$thisMonth]);
    foreach ($monthCats as $mc): $pct = $monthTotal['total']>0?round($mc['total']/$monthTotal['total']*100):0; ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
      <span style="font-size:12px;color:var(--gray-600);width:90px;flex-shrink:0"><?= sanitize($mc['expense_type']) ?></span>
      <div style="flex:1;height:7px;background:var(--gray-50);border-radius:4px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:var(--amber-400);border-radius:4px"></div>
      </div>
      <span style="font-size:12px;font-weight:600;width:70px;text-align:right"><?= money($mc['total']) ?></span>
    </div>
    <?php endforeach; ?>
    <?php if (!$monthCats): ?>
    <div style="font-size:13px;color:var(--gray-400)">No expenses this month yet.</div>
    <?php endif; ?>
  </div>

  <!-- ADD / EDIT EXPENSE FORM -->
  <div class="form-panel" style="border:2px solid <?= $editRow?'var(--amber-200)':'var(--green-200)' ?>">
    <div class="form-panel-title" style="color:<?= $editRow?'var(--amber-600)':'var(--green-600)' ?>">
      <i class="ti ti-<?= $editRow?'edit':'receipt-2' ?>"></i>
      <?= $editRow ? 'Edit Expense' : 'Add Expense' ?>
      <?php if ($editRow): ?>
      <a href="expenses.php?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-outline btn-sm" style="margin-left:auto"><i class="ti ti-x"></i> Cancel</a>
      <?php endif; ?>
    </div>

    <form method="POST" id="expense-form">
      <input type="hidden" name="action" value="<?= $editRow?'edit':'add' ?>">
      <?php if ($editRow): ?>
      <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
      <input type="hidden" name="from" value="<?= $dateFrom ?>">
      <input type="hidden" name="to" value="<?= $dateTo ?>">
      <?php endif; ?>
      <input type="hidden" name="expense_type" id="selected-type" value="<?= sanitize($editRow['expense_type'] ?? 'Miscellaneous') ?>">

      <!-- Category Selector -->
      <div style="font-size:12px;font-weight:600;color:var(--gray-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">
        Expense Category
      </div>
      <div class="preset-grid">
        <?php foreach ($presetItems as $typeName => $meta): 
          $isSelected = ($editRow ? $editRow['expense_type'] : 'Miscellaneous') === $typeName;
        ?>
        <div class="preset-card <?= $isSelected?'selected':'' ?>"
             id="preset-<?= str_replace(' ','-',$typeName) ?>"
             onclick="selectType('<?= $typeName ?>')">
          <i class="ti <?= $meta['icon'] ?> pc-icon" style="color:<?= $meta['text'] ?>"></i>
          <div class="pc-name"><?= $typeName ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="grid-form" style="margin-bottom:14px">
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="expense_date" value="<?= $editRow['expense_date'] ?? today() ?>" required>
        </div>
        <div class="form-group">
          <label>Plantation / Section</label>
          <select name="plantation_id">
            <option value="">All / General</option>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($editRow['plantation_id']??'')==$p['id']?'selected':'' ?>>
              <?= sanitize($p['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!$editRow): ?>
        <!-- Quantity & Unit Price (only for new entries) -->
        <div class="form-group">
          <label>Quantity / Units</label>
          <input type="number" name="quantity" id="exp-qty" value="1" min="1" step="0.1"
                 placeholder="e.g. 3 cans" oninput="calcExpTotal()">
        </div>
        <div class="form-group">
          <label>Unit Price (Rs.) *</label>
          <input type="number" name="amount" id="exp-unit-price" placeholder="Price per unit"
                 min="0" step="0.01" required oninput="calcExpTotal()">
        </div>
        <!-- Total preview -->
        <div class="form-group col-full">
          <div style="background:var(--green-50);border:1px solid var(--green-100);border-radius:var(--radius-md);padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:13px;color:var(--green-800)"><i class="ti ti-calculator"></i> Total Amount</span>
            <span style="font-size:18px;font-weight:700;color:var(--green-600)" id="exp-total">Rs. 0</span>
          </div>
        </div>
        <?php else: ?>
        <!-- Edit: just total amount -->
        <div class="form-group col-full">
          <label>Total Amount (Rs.) *</label>
          <input type="number" name="amount" value="<?= $editRow['amount'] ?>" min="0" step="0.01" required>
        </div>
        <?php endif; ?>

        <div class="form-group col-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="e.g. 3 cans for Section A spraying..."><?= sanitize($editRow['notes']??'') ?></textarea>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%">
        <i class="ti ti-<?= $editRow?'check':'plus' ?>"></i>
        <?= $editRow ? 'Update Expense' : 'Add Expense' ?>
      </button>
    </form>
  </div>
</div>

<!-- ===== RIGHT: LIST & ANALYTICS ===== -->
<div>

  <!-- DATE RANGE FILTER -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <i class="ti ti-filter" style="color:var(--green-600);font-size:18px"></i>
      <form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;flex:1">
        <div>
          <div style="font-size:11px;font-weight:600;color:var(--gray-600);margin-bottom:3px">FROM</div>
          <input type="date" name="from" value="<?= $dateFrom ?>" style="font-size:13px;padding:6px 10px;border:1px solid #d8ddd5;border-radius:var(--radius-md)">
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:var(--gray-600);margin-bottom:3px">TO</div>
          <input type="date" name="to" value="<?= $dateTo ?>" style="font-size:13px;padding:6px 10px;border:1px solid #d8ddd5;border-radius:var(--radius-md)">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-search"></i> View</button>
      </form>
    </div>
    <!-- Quick shortcuts -->
    <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
      <?php
      $shortcuts = [
        'Today'       => [today(), today()],
        'This Week'   => [date('Y-m-d',strtotime('monday this week')), today()],
        'This Month'  => [date('Y-m-01'), today()],
        'Last Month'  => [date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month'))],
        'Last 3 Months' => [date('Y-m-01',strtotime('-2 months')), today()],
      ];
      foreach ($shortcuts as $label => [$f,$t]):
        $active = ($dateFrom===$f && $dateTo===$t);
      ?>
      <a href="expenses.php?from=<?= $f ?>&to=<?= $t ?>"
         class="btn btn-outline btn-sm"
         style="<?= $active?'background:var(--green-50);border-color:var(--green-400);color:var(--green-800)':'' ?>">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RANGE SUMMARY STATS -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px">
    <div class="stat-card amber">
      <div class="stat-label"><i class="ti ti-receipt"></i> Total Expenses</div>
      <div class="stat-value"><?= money($grandTotal) ?></div>
      <div class="stat-sub"><?= fmtDate($dateFrom) ?> – <?= fmtDate($dateTo) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-list"></i> Transactions</div>
      <div class="stat-value"><?= count($expenses) ?></div>
      <div class="stat-sub"><?= count($byCategory) ?> categories</div>
    </div>
    <div class="stat-card teal">
      <div class="stat-label"><i class="ti ti-calendar-stats"></i> This Month</div>
      <div class="stat-value"><?= moneyShort($monthTotal['total']) ?></div>
      <div class="stat-sub"><?= date('F Y') ?></div>
    </div>
  </div>

  <!-- ANALYTICS ROW -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
    <!-- By Category -->
    <div class="card">
      <div class="card-title" style="margin-bottom:12px"><i class="ti ti-chart-pie"></i> By Category</div>
      <?php if ($byCategory): ?>
        <?php foreach ($byCategory as $bc): 
          $pct = $maxCat>0?round($bc['total']/$maxCat*100):0;
          $meta = $presetItems[$bc['expense_type']] ?? $presetItems['Miscellaneous'];
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px">
              <i class="ti <?= $meta['icon'] ?>" style="color:<?= $meta['text'] ?>;font-size:14px"></i>
              <?= sanitize($bc['expense_type']) ?>
              <span style="font-weight:400;color:var(--gray-400)">(<?= $bc['cnt'] ?>)</span>
            </span>
            <span style="font-size:12px;font-weight:700"><?= money($bc['total']) ?></span>
          </div>
          <div style="height:7px;background:var(--gray-50);border-radius:4px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:var(--amber-400);border-radius:4px"></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state" style="padding:20px"><i class="ti ti-chart-off"></i><p>No data</p></div>
      <?php endif; ?>
    </div>
    <!-- By Plantation -->
    <div class="card">
      <div class="card-title" style="margin-bottom:12px"><i class="ti ti-trees"></i> By Section</div>
      <?php if ($byPlant): ?>
        <?php foreach ($byPlant as $bp): 
          $pct = $maxPlant>0?round($bp['total']/$maxPlant*100):0;
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600"><?= sanitize($bp['pname']) ?></span>
            <span style="font-size:12px;font-weight:700"><?= money($bp['total']) ?></span>
          </div>
          <div style="height:7px;background:var(--gray-50);border-radius:4px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:var(--green-400);border-radius:4px"></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state" style="padding:20px"><i class="ti ti-trees"></i><p>No data</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- FULL EXPENSE LIST -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-history"></i> All Expenses — <?= fmtDate($dateFrom) ?> to <?= fmtDate($dateTo) ?></div>
      <span style="font-size:12px;color:var(--gray-400)"><?= count($expenses) ?> records · <?= money($grandTotal) ?></span>
    </div>

    <?php if (!$expenses): ?>
      <div class="empty-state"><i class="ti ti-receipt-off"></i><p>No expenses in this period.<br>Add one using the form.</p></div>
    <?php else: ?>

    <!-- Group by date -->
    <?php
    $byDate = [];
    foreach ($expenses as $e) $byDate[$e['expense_date']][] = $e;
    foreach ($byDate as $date => $rows):
      $dayTotal = array_sum(array_column($rows,'amount'));
    ?>
    <div style="margin-bottom:14px">
      <!-- Date header -->
      <div style="display:flex;justify-content:space-between;align-items:center;background:var(--gray-50);border-radius:8px;padding:7px 12px;margin-bottom:4px">
        <span style="font-size:12px;font-weight:700;color:var(--gray-800);display:flex;align-items:center;gap:7px">
          <i class="ti ti-calendar" style="color:var(--green-600)"></i> <?= fmtDate($date) ?>
          <span class="pill pill-gray" style="font-size:10px"><?= count($rows) ?> items</span>
        </span>
        <strong style="color:var(--amber-600);font-size:13px"><?= money($dayTotal) ?></strong>
      </div>
      <!-- Items -->
      <?php foreach ($rows as $e):
        $meta = $presetItems[$e['expense_type']] ?? $presetItems['Miscellaneous'];
        $isEditing = ($editId === (int)$e['id']);
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid #f8f8f6;<?= $isEditing?'background:var(--amber-50);border-radius:8px;':'' ?>"
           onmouseover="this.style.background=this.style.background||'#f8faf5'" onmouseout="this.style.background='<?= $isEditing?'var(--amber-50)':'' ?>'">
        <div style="width:34px;height:34px;border-radius:9px;background:<?= $meta['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="ti <?= $meta['icon'] ?>" style="color:<?= $meta['text'] ?>;font-size:17px"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700"><?= sanitize($e['expense_type']) ?></div>
          <div style="font-size:11px;color:var(--gray-400)">
            <?= sanitize($e['plantation_name'] ?? 'All / General') ?>
            <?php if ($e['notes']): ?> · <?= sanitize($e['notes']) ?><?php endif; ?>
          </div>
        </div>
        <div style="font-size:14px;font-weight:700;color:var(--amber-600);white-space:nowrap"><?= money($e['amount']) ?></div>
        <!-- Edit -->
        <a href="expenses.php?edit=<?= $e['id'] ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
           class="btn btn-outline btn-sm" title="Edit">
          <i class="ti ti-edit"></i>
        </a>
        <!-- Delete -->
        <form method="POST" onsubmit="return confirm('Delete this expense?')" style="display:inline">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $e['id'] ?>">
          <input type="hidden" name="from" value="<?= $dateFrom ?>">
          <input type="hidden" name="to" value="<?= $dateTo ?>">
          <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)"><i class="ti ti-trash"></i></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Grand total footer -->
    <div style="display:flex;justify-content:space-between;padding:12px 10px;border-top:2px solid #e8ede5;margin-top:4px">
      <strong style="font-size:14px">Total for Period</strong>
      <strong style="font-size:16px;color:var(--amber-600)"><?= money($grandTotal) ?></strong>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<script>
var presetDefaults = {
  'Spray Can':     0,
  'Pohora':        0,
  'Dolomite':      0,
  'Food':          0,
  'Transport':     0,
  'Equipment':     0,
  'Miscellaneous': 0
};

function selectType(typeName) {
  // Update hidden input
  document.getElementById('selected-type').value = typeName;
  // Highlight selected card
  document.querySelectorAll('.preset-card').forEach(c => c.classList.remove('selected'));
  var key = typeName.replace(/ /g,'-');
  var card = document.getElementById('preset-' + key);
  if (card) card.classList.add('selected');
}

function calcExpTotal() {
  var qty   = parseFloat(document.getElementById('exp-qty')?.value) || 0;
  var price = parseFloat(document.getElementById('exp-unit-price')?.value) || 0;
  var total = qty * price;
  var el    = document.getElementById('exp-total');
  if (el) el.textContent = 'Rs. ' + Math.round(total).toLocaleString();
}

// Validate category selected before submit
document.getElementById('expense-form').addEventListener('submit', function(e) {
  var type = document.getElementById('selected-type').value;
  if (!type) { e.preventDefault(); alert('Please select an expense category.'); }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
