<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Expenses';
$estateId = Auth::estateId();

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
        $providedBy = ($_POST['food_provided_by'] ?? 'us') === 'owner' ? 'owner' : 'us';

        if (!array_key_exists($type, $presetItems)) $type = 'Miscellaneous';
        if ($amt <= 0) { flash('error','Amount must be greater than 0.'); redirect('/expenses.php'); }

        $totalAmt = round($amt * $qty, 2);

        DB::insert("INSERT INTO expenses (estate_id,expense_date,plantation_id,expense_type,amount,food_provided_by,notes,created_by) VALUES (?,?,?,?,?,?,?,?)",
            [$estateId, $date, $pid, $type, $totalAmt, ($type==='Food'?$providedBy:'us'), $notes, $uid]);
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
        $providedBy = ($_POST['food_provided_by'] ?? 'us') === 'owner' ? 'owner' : 'us';
        if ($amt <= 0) { flash('error','Amount must be greater than 0.'); redirect('/expenses.php'); }
        DB::execute("UPDATE expenses SET expense_date=?,plantation_id=?,expense_type=?,amount=?,food_provided_by=?,notes=?,updated_at=NOW() WHERE id=?",
            [$date,$pid,$type,$amt,($type==='Food'?$providedBy:'us'),$notes,$id]);
        flash('success','Expense updated.');
        redirect('/expenses.php?from='.$_POST['from'].'&to='.$_POST['to']);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        DB::execute("DELETE FROM expenses WHERE id=? AND estate_id=?", [$id,$estateId]);
        flash('success','Expense deleted.');
        redirect('/expenses.php?from='.$_POST['from'].'&to='.$_POST['to']);
    }

    // ── TOGGLE EXPENSE PAYMENT STATUS ─────────────
    if ($action === 'toggle_expense_paid') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = DB::fetchOne("SELECT payment_status FROM expenses WHERE id=? AND estate_id=?", [$id, $estateId]);
        $newStatus = ($current && $current['payment_status'] === 'paid') ? 'pending' : 'paid';
        DB::execute("UPDATE expenses SET payment_status=? WHERE id=? AND estate_id=?", [$newStatus, $id, $estateId]);
        flash('success', 'Expense marked as ' . ucfirst($newStatus) . '.');
        redirect('/expenses.php?from='.$_POST['from'].'&to='.$_POST['to']);
    }

    // ── BULK EXPENSE PAYMENT STATUS ───────────────
    if ($action === 'bulk_expense_paid') {
        $ids    = $_POST['bulk_ids'] ?? [];
        $status = $_POST['bulk_status'] ?? 'paid';
        if (!in_array($status, ['paid','pending'])) $status = 'paid';
        if (!empty($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::execute("UPDATE expenses SET payment_status=? WHERE id IN ($placeholders) AND estate_id=?",
                array_merge([$status], $ids, [$estateId]));
            flash('success', count($ids) . ' expense(s) marked as ' . ucfirst($status) . '.');
        }
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
.fp-option{flex:1;border:2px solid #e8ede5;border-radius:var(--radius-md);padding:10px;cursor:pointer;text-align:center;font-size:12px;font-weight:600;transition:border-color .15s,background .15s}
.fp-option:hover{border-color:var(--green-200);background:var(--green-50)}
.fp-option.selected{border-color:var(--green-400);background:var(--green-50)}
.fp-option .fp-icon{display:block;font-size:18px;margin-bottom:4px}
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

      <?php
      $initialType   = $editRow['expense_type'] ?? 'Miscellaneous';
      $initialFpVal  = $editRow['food_provided_by'] ?? 'us';
      ?>
      <div class="form-group" id="food-provider-group" style="display:<?= $initialType==='Food'?'block':'none' ?>;margin-bottom:14px">
        <label>Food Provided By</label>
        <input type="hidden" name="food_provided_by" id="food-provided-by" value="<?= sanitize($initialFpVal) ?>">
        <div style="display:flex;gap:8px">
          <div class="fp-option <?= $initialFpVal==='us'?'selected':'' ?>" id="fp-us" onclick="selectFoodProvider('us')">
            <i class="ti ti-wallet fp-icon" style="color:var(--amber-600)"></i>
            Worker Paid
          </div>
          <div class="fp-option <?= $initialFpVal==='owner'?'selected':'' ?>" id="fp-owner" onclick="selectFoodProvider('owner')">
            <i class="ti ti-gift fp-icon" style="color:var(--green-600)"></i>
            We Paid
          </div>
        </div>
        <div style="font-size:11px;color:var(--gray-400);margin-top:6px">
          "We Paid" food costs are still logged here but excluded from assignment cost reports.
        </div>
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
        <div class="form-group" id="exp-qty-group">
          <label>Quantity / Units</label>
          <input type="number" name="quantity" id="exp-qty" value="1" min="1" step="0.1"
                 placeholder="e.g. 3 cans" oninput="calcExpTotal()">
        </div>
        <div class="form-group" id="exp-price-group">
          <label>Unit Price (Rs.) *</label>
          <input type="number" name="amount" id="exp-unit-price" placeholder="Price per unit"
                 min="0" step="0.01" required oninput="calcExpTotal()">
        </div>
        <!-- Total preview -->
        <div class="form-group col-full" id="exp-total-group">
          <div style="background:var(--green-50);border:1px solid var(--green-100);border-radius:var(--radius-md);padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:13px;color:var(--green-800)"><i class="ti ti-calculator"></i> Total Amount</span>
            <span style="font-size:18px;font-weight:700;color:var(--green-600)" id="exp-total">Rs. 0</span>
          </div>
        </div>
        <?php else: ?>
        <!-- Edit: just total amount -->
        <div class="form-group col-full" id="edit-amount-group">
          <label>Total Amount (Rs.) *</label>
          <input type="number" name="amount" id="edit-amount-input" value="<?= $editRow['amount'] ?>" min="0" step="0.01" required>
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

    <?php $foodExpenseIds = array_column(array_filter($expenses, fn($e)=>$e['expense_type']==='Food'), 'id'); ?>
    <?php if ($foodExpenseIds): ?>
    <div style="display:flex;align-items:center;gap:6px;margin:6px 0 8px">
      <input type="checkbox" id="exp-select-all" onchange="toggleSelectAllExp(this)"
             style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer">
      <label for="exp-select-all" style="font-size:12px;color:var(--gray-500);cursor:pointer">Select all Food expenses</label>
    </div>
    <!-- Bulk payment bar -->
    <div id="exp-bulk-bar" style="display:none;background:var(--green-50);border:1px solid var(--green-200);border-radius:var(--radius-md);padding:8px 12px;margin-bottom:10px;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-size:12px;font-weight:700;color:var(--green-800)" id="exp-bulk-count">0 selected</span>
      <form method="POST" id="exp-bulk-form" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="action" value="bulk_expense_paid">
        <input type="hidden" name="from" value="<?= $dateFrom ?>">
        <input type="hidden" name="to" value="<?= $dateTo ?>">
        <div id="exp-bulk-ids-container"></div>
        <button type="submit" name="bulk_status" value="paid"
                class="btn btn-sm" style="background:var(--green-600);color:#fff;padding:6px 14px">
          <i class="ti ti-circle-check"></i> Mark Selected as Paid
        </button>
        <button type="submit" name="bulk_status" value="pending"
                class="btn btn-outline btn-sm" style="color:var(--gray-600);padding:6px 12px">
          <i class="ti ti-clock"></i> Mark as Pending
        </button>
        <button type="button" onclick="clearExpBulkSelection()" class="btn btn-outline btn-sm" style="color:var(--gray-400)">
          <i class="ti ti-x"></i> Clear
        </button>
      </form>
    </div>
    <?php endif; ?>

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
        $isOwnerFood = ($e['expense_type'] === 'Food' && ($e['food_provided_by'] ?? 'us') === 'owner');
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid #f8f8f6;<?= $isEditing?'background:var(--amber-50);border-radius:8px;':'' ?>"
           onmouseover="this.style.background=this.style.background||'#f8faf5'" onmouseout="this.style.background='<?= $isEditing?'var(--amber-50)':'' ?>'">
        <?php if ($e['expense_type'] === 'Food'): ?>
        <input type="checkbox" class="bulk-check-exp" value="<?= $e['id'] ?>" onchange="updateExpBulkSelection()"
               style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer;flex-shrink:0">
        <?php endif; ?>
        <div style="width:34px;height:34px;border-radius:9px;background:<?= $meta['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="ti <?= $meta['icon'] ?>" style="color:<?= $meta['text'] ?>;font-size:17px"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700">
            <?= sanitize($e['expense_type']) ?>
            <?php if ($isOwnerFood): ?>
            <span class="pill pill-green" title="Logged for record-keeping, excluded from assignment cost reports" style="margin-left:6px"><i class="ti ti-gift"></i> We Paid</span>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--gray-400)">
            <?= sanitize($e['plantation_name'] ?? 'All / General') ?>
            <?php if ($e['notes']): ?> · <?= sanitize($e['notes']) ?><?php endif; ?>
          </div>
        </div>
        <div style="font-size:14px;font-weight:700;color:var(--amber-600);white-space:nowrap"><?= money($e['amount']) ?></div>
        <!-- Paid toggle (Food expenses only — these are the ones tracked in the Cost Report) -->
        <?php if ($e['expense_type'] === 'Food'): $isPaidExp = ($e['payment_status'] ?? 'pending') === 'paid'; ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="toggle_expense_paid">
          <input type="hidden" name="id" value="<?= $e['id'] ?>">
          <input type="hidden" name="from" value="<?= $dateFrom ?>">
          <input type="hidden" name="to" value="<?= $dateTo ?>">
          <button type="submit" title="Toggle payment" style="border:none;cursor:pointer;border-radius:20px;padding:3px 9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;<?= $isPaidExp?'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7':'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5' ?>">
            <i class="ti ti-<?= $isPaidExp?'circle-check':'clock' ?>" style="font-size:11px"></i>
            <?= $isPaidExp?'Paid':'Pending' ?>
          </button>
        </form>
        <?php endif; ?>
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

  var fpGroup = document.getElementById('food-provider-group');
  if (fpGroup) {
    if (typeName === 'Food') {
      fpGroup.style.display = 'block';
    } else {
      fpGroup.style.display = 'none';
      selectFoodProvider('us');
    }
  }
}

function selectFoodProvider(val) {
  var input = document.getElementById('food-provided-by');
  if (input) input.value = val;
  var fpUs = document.getElementById('fp-us'), fpOwner = document.getElementById('fp-owner');
  if (fpUs) fpUs.classList.toggle('selected', val === 'us');
  if (fpOwner) fpOwner.classList.toggle('selected', val === 'owner');
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

// ── BULK EXPENSE PAYMENT JS ─────────────────────────────
function toggleSelectAllExp(masterCb) {
  document.querySelectorAll('.bulk-check-exp').forEach(function(cb) { cb.checked = masterCb.checked; });
  updateExpBulkSelection();
}

function updateExpBulkSelection() {
  var checked   = document.querySelectorAll('.bulk-check-exp:checked');
  var bar       = document.getElementById('exp-bulk-bar');
  var countEl   = document.getElementById('exp-bulk-count');
  var container = document.getElementById('exp-bulk-ids-container');

  if (!bar) return;

  if (container) {
    container.innerHTML = '';
    checked.forEach(function(cb) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'bulk_ids[]';
      inp.value = cb.value;
      container.appendChild(inp);
    });
  }

  var selectAll = document.getElementById('exp-select-all');
  if (checked.length > 0) {
    bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length + ' selected';
    var all = document.querySelectorAll('.bulk-check-exp');
    if (selectAll) {
      selectAll.indeterminate = (checked.length > 0 && checked.length < all.length);
      selectAll.checked = (checked.length === all.length && all.length > 0);
    }
  } else {
    bar.style.display = 'none';
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
  }
}

function clearExpBulkSelection() {
  document.querySelectorAll('.bulk-check-exp').forEach(function(cb) { cb.checked = false; });
  var selectAll = document.getElementById('exp-select-all');
  if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
  updateExpBulkSelection();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
