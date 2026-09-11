<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Factory Management';
Auth::requireAdmin();
$estateId = Auth::estateId();
$uid      = (int)(Auth::user()['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── FACTORY: add / edit ───────────────────────
    if ($action === 'add_factory' || $action === 'edit_factory') {
        $name     = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $active   = (int)($_POST['is_active'] ?? 1);
        if (!$name) { flash('error', 'Factory name is required.'); redirect('/factory-management.php?tab=factories'); }

        if ($action === 'add_factory') {
            $exists = DB::fetchOne("SELECT id FROM factories WHERE name=? AND estate_id=?", [$name, $estateId]);
            if ($exists) { flash('error', 'A factory with this name already exists.'); redirect('/factory-management.php?tab=factories'); }
            DB::insert("INSERT INTO factories (estate_id,name,location,notes,is_active) VALUES (?,?,?,?,?)",
                [$estateId, $name, $location, $notes, $active]);
            flash('success', 'Factory "' . $name . '" added.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            DB::execute("UPDATE factories SET name=?,location=?,notes=?,is_active=? WHERE id=? AND estate_id=?",
                [$name, $location, $notes, $active, $id, $estateId]);
            flash('success', 'Factory updated.');
        }
        redirect('/factory-management.php?tab=factories');
    }

    // ── FACTORY: toggle active status ─────────────
    if ($action === 'toggle_factory') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = (int)($_POST['current'] ?? 1);
        DB::execute("UPDATE factories SET is_active=? WHERE id=? AND estate_id=?", [$current ? 0 : 1, $id, $estateId]);
        flash('success', 'Factory status updated.');
        redirect('/factory-management.php?tab=factories');
    }

    // ── FACTORY: delete (only if never used in a delivery or expense) ──
    if ($action === 'delete_factory') {
        $id   = (int)($_POST['id'] ?? 0);
        $used = DB::fetchOne("SELECT COUNT(*) as cnt FROM factory_deliveries WHERE factory_id=? AND estate_id=?", [$id, $estateId]);
        $usedExp = DB::fetchOne("SELECT COUNT(*) as cnt FROM factory_expenses WHERE factory_id=? AND estate_id=?", [$id, $estateId]);
        if ((($used['cnt'] ?? 0) + ($usedExp['cnt'] ?? 0)) > 0) {
            flash('error', 'Cannot delete — this factory has delivery or expense record(s). Deactivate it instead.');
            redirect('/factory-management.php?tab=factories');
        }
        DB::execute("DELETE FROM factory_prices WHERE factory_id=? AND estate_id=?", [$id, $estateId]);
        DB::execute("DELETE FROM factories WHERE id=? AND estate_id=?", [$id, $estateId]);
        flash('success', 'Factory deleted.');
        redirect('/factory-management.php?tab=factories');
    }

    // ── FACTORY EXPENSE: add / edit ────────────────
    if ($action === 'add_expense' || $action === 'edit_expense') {
        $factoryId       = (int)($_POST['factory_id'] ?? 0);
        $expMonthYear    = trim($_POST['expense_month_year'] ?? date('Y'));
        $expMonthNum     = trim($_POST['expense_month_num']  ?? date('m'));
        $expenseDate     = $_POST['expense_date'] ?? today();
        $category        = trim($_POST['category'] ?? 'Miscellaneous');
        if ($category === 'Other') {
            $categoryOther = trim($_POST['category_other'] ?? '');
            if ($categoryOther !== '') $category = $categoryOther;
        }
        $description     = trim($_POST['description'] ?? '');
        $amount          = (float)($_POST['amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $retYear         = $_POST['ret_year']     ?? date('Y');
        $retMonthNum     = $_POST['ret_monthnum'] ?? date('m');
        $backTo          = '/factory-management.php?year=' . urlencode($retYear) . '&monthnum=' . urlencode($retMonthNum) . '&tab=expenses';

        if (!$factoryId || !$expMonthYear || !$expMonthNum || $amount <= 0) {
            flash('error', 'Factory, month and a valid amount are required.');
            redirect($backTo);
        }
        $expenseMonthDate = $expMonthYear . '-' . str_pad($expMonthNum, 2, '0', STR_PAD_LEFT) . '-01';

        if ($action === 'add_expense') {
            DB::insert("INSERT INTO factory_expenses (estate_id,factory_id,expense_month,expense_date,category,description,amount,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?)",
                [$estateId, $factoryId, $expenseMonthDate, $expenseDate, $category, $description, $amount, $notes, $uid]);
            flash('success', 'Factory expense added.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            DB::execute("UPDATE factory_expenses SET factory_id=?,expense_month=?,expense_date=?,category=?,description=?,amount=?,notes=?,updated_at=NOW() WHERE id=? AND estate_id=?",
                [$factoryId, $expenseMonthDate, $expenseDate, $category, $description, $amount, $notes, $id, $estateId]);
            flash('success', 'Factory expense updated.');
        }
        redirect($backTo);
    }

    // ── FACTORY EXPENSE: delete ─────────────────────
    if ($action === 'delete_expense') {
        $retYear     = $_POST['ret_year']     ?? date('Y');
        $retMonthNum = $_POST['ret_monthnum'] ?? date('m');
        $backTo      = '/factory-management.php?year=' . urlencode($retYear) . '&monthnum=' . urlencode($retMonthNum) . '&tab=expenses';
        DB::execute("DELETE FROM factory_expenses WHERE id=? AND estate_id=?", [(int)($_POST['id'] ?? 0), $estateId]);
        flash('success', 'Factory expense removed.');
        redirect($backTo);
    }

    // ── MONTHLY PRICE: save (upsert per factory + month) ──
    if ($action === 'save_price') {
        $factoryId = (int)($_POST['factory_id'] ?? 0);
        $monthIn   = trim($_POST['price_month'] ?? date('Y-m')); // "YYYY-MM"
        $price     = (float)($_POST['price_per_kg'] ?? -1);
        if (!$factoryId || !$monthIn || $price < 0) {
            flash('error', 'Factory, month and a valid price are required.');
            redirect('/factory-management.php?tab=prices');
        }
        $priceMonth = date('Y-m-01', strtotime($monthIn . '-01'));
        DB::execute("INSERT INTO factory_prices (estate_id,factory_id,price_month,price_per_kg,created_by)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE price_per_kg=?, updated_at=NOW()",
            [$estateId, $factoryId, $priceMonth, $price, $uid, $price]);
        flash('success', 'Price saved.');
        [$mYear, $mNum] = explode('-', $monthIn);
        redirect('/factory-management.php?pyear=' . urlencode($mYear) . '&pmonthnum=' . urlencode($mNum) . '&tab=prices');
    }

    // ── MONTHLY PRICE: delete one entry ────────────
    if ($action === 'delete_price') {
        DB::execute("DELETE FROM factory_prices WHERE id=? AND estate_id=?", [(int)($_POST['id'] ?? 0), $estateId]);
        flash('success', 'Price entry removed.');
        redirect('/factory-management.php?tab=prices');
    }

    // ── DELIVERY: assign factory and/or confirm weight for a whole day ──
    if ($action === 'update_delivery') {
        $deliveryDate = $_POST['delivery_date'] ?? '';
        $factoryId    = (int)($_POST['factory_id'] ?? 0) ?: null;
        $weight       = trim($_POST['factory_weight'] ?? '');
        $weight       = ($weight === '') ? null : (float)$weight;

        // Preserve whatever Factory/Year/Month filter was active so saving
        // a row doesn't bounce the page back to the current month.
        $backTo = '/factory-management.php?' . http_build_query(array_filter([
            'factory'  => $_POST['ret_factory']  ?? '',
            'year'     => $_POST['ret_year']     ?? '',
            'monthnum' => $_POST['ret_monthnum'] ?? '',
            'tab'      => 'deliveries',
        ], fn($v) => $v !== ''));

        if (!$deliveryDate) { flash('error', 'Missing delivery date.'); redirect($backTo); }
        DB::execute("INSERT INTO factory_deliveries (estate_id, delivery_date, factory_id, factory_weight, created_by)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE factory_id=VALUES(factory_id), factory_weight=VALUES(factory_weight), updated_at=NOW()",
            [$estateId, $deliveryDate, $factoryId, $weight, $uid]);
        flash('success', 'Delivery updated.');
        redirect($backTo);
    }
}

// Year + Month pickers instead of <input type="month"> / type="date">,
// which aren't reliably supported across browsers (notably older Firefox
// falls back to a plain text box with no calendar). Pick a year first,
// then a month select shows only that year's Jan–Dec.
function fmYearOptions($back = 5, $forward = 1) {
    $curYear = (int)date('Y');
    $years = [];
    for ($y = $curYear - $back; $y <= $curYear + $forward; $y++) $years[] = $y;
    return $years; // ascending, oldest first
}

$fmMonthNames = [
    '01' => 'January',  '02' => 'February', '03' => 'March',     '04' => 'April',
    '05' => 'May',      '06' => 'June',     '07' => 'July',      '08' => 'August',
    '09' => 'September','10' => 'October',  '11' => 'November',  '12' => 'December',
];

// ── Check all migrations have been applied before querying ────
$factoriesReady = true;
try {
    DB::fetchOne("SELECT 1 FROM factories LIMIT 1", []);
    DB::fetchOne("SELECT 1 FROM factory_deliveries LIMIT 1", []);
    DB::fetchOne("SELECT 1 FROM factory_expenses LIMIT 1", []);
} catch (Exception $e) {
    $factoriesReady = false;
}

// Preset expense categories (Other allows free text via the description field)
$fmExpenseCategories = ['Fertilizer', 'Chemicals', 'Transport', 'Repairs & Maintenance', 'Equipment', 'Labour', 'Miscellaneous', 'Other'];

if ($factoriesReady) {
    $editFactory = null;
    if (!empty($_GET['edit_factory'])) {
        $editFactory = DB::fetchOne("SELECT * FROM factories WHERE id=? AND estate_id=?", [(int)$_GET['edit_factory'], $estateId]);
    }

    $factories = DB::fetchAll("SELECT * FROM factories WHERE estate_id=? ORDER BY is_active DESC, name ASC", [$estateId]);

    // ── SHARED FILTER: Year + Month drives Overview stats AND the Deliveries
    // list (Factory further narrows Deliveries only). Defaults to the
    // current month. Computed here (not just inside the Deliveries block)
    // so Overview reacts to it too instead of always showing "this month".
    $filterFactory  = $_GET['factory']  ?? 'all'; // 'all' | 'none' (unassigned) | <factory id>
    $filterYear     = $_GET['year']     ?? date('Y');
    $filterMonthNum = $_GET['monthnum'] ?? date('m');
    $filterMonth    = $filterYear . '-' . str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
    $filterMonthStart = $filterMonth . '-01';
    $filterMonthLabel = date('F Y', strtotime($filterMonthStart));

    $curPrices   = DB::fetchAll("SELECT factory_id, price_per_kg FROM factory_prices WHERE estate_id=? AND price_month=?", [$estateId, $filterMonthStart]);
    $curPriceMap = array_column($curPrices, 'price_per_kg', 'factory_id');

    // Daily plucking totals (whole estate, all workers/sections combined —
    // one number per day) with whatever factory has been assigned to that
    // day, if any. This is the base dataset for Overview + Deliveries.
    $thisMonthDaily = DB::fetchAll("SELECT dk.assignment_date, dk.total_kg,
            fd.factory_id, fd.factory_weight, f.name as factory_name, fp.price_per_kg
        FROM (
            SELECT da.assignment_date, SUM(da.quantity) as total_kg
            FROM daily_assignments da
            JOIN work_types wt ON da.work_type_id=wt.id
            WHERE da.estate_id=? AND LOWER(wt.unit_label)='kg' AND da.approval_status='approved'
              AND da.assignment_date >= ? AND da.assignment_date < DATE_ADD(?, INTERVAL 1 MONTH)
            GROUP BY da.assignment_date
        ) dk
        LEFT JOIN factory_deliveries fd ON fd.estate_id=? AND fd.delivery_date=dk.assignment_date
        LEFT JOIN factories f ON fd.factory_id=f.id
        LEFT JOIN factory_prices fp ON fp.factory_id=fd.factory_id AND fp.price_month=DATE_FORMAT(dk.assignment_date,'%Y-%m-01')
        ORDER BY dk.assignment_date DESC", [$estateId, $filterMonthStart, $filterMonthStart, $estateId]);

    // Per-factory this-month totals (based on confirmed factory weight only)
    $factoryMonthMap = [];
    foreach ($thisMonthDaily as $row) {
        if (empty($row['factory_id'])) continue;
        $fid = $row['factory_id'];
        if (!isset($factoryMonthMap[$fid])) $factoryMonthMap[$fid] = ['kg' => 0.0, 'count' => 0, 'value' => 0.0];
        $factoryMonthMap[$fid]['count']++;
        if ($row['factory_weight'] !== null) {
            $factoryMonthMap[$fid]['kg'] += (float)$row['factory_weight'];
            if ($row['price_per_kg'] !== null) {
                $factoryMonthMap[$fid]['value'] += (float)$row['factory_weight'] * (float)$row['price_per_kg'];
            }
        }
    }

    // ── OVERVIEW STATS (this month) ──
    $ovTotalWeight     = array_sum(array_column($factoryMonthMap, 'kg'));
    $ovTotalValue      = array_sum(array_column($factoryMonthMap, 'value'));
    $ovDeliveries      = count(array_filter($thisMonthDaily, fn($r) => !empty($r['factory_id'])));
    $ovActiveFactories = count(array_filter($factories, fn($f) => $f['is_active'] == 1));
    $ovTotalExpenses   = (float)(DB::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM factory_expenses WHERE estate_id=? AND expense_month=?",
        [$estateId, $filterMonthStart])['total'] ?? 0);
    $ovNetProfit       = $ovTotalValue - $ovTotalExpenses;

    // Daily factory-weight trend this month (for the mini chart), oldest first
    $dailyFactoryKg = [];
    foreach (array_reverse($thisMonthDaily) as $row) {
        if ($row['factory_weight'] !== null) {
            $dailyFactoryKg[] = ['assignment_date' => $row['assignment_date'], 'kg' => (float)$row['factory_weight']];
        }
    }
    $maxDailyFactoryKg = max(array_column($dailyFactoryKg, 'kg') ?: [1]);

    // Recent deliveries (last 10 days that have a factory assigned, any month)
    $recentDeliveries = DB::fetchAll("SELECT dk.assignment_date, dk.total_kg,
            fd.factory_weight, f.name as factory_name
        FROM (
            SELECT da.assignment_date, SUM(da.quantity) as total_kg
            FROM daily_assignments da
            JOIN work_types wt ON da.work_type_id=wt.id
            WHERE da.estate_id=? AND LOWER(wt.unit_label)='kg' AND da.approval_status='approved'
            GROUP BY da.assignment_date
        ) dk
        JOIN factory_deliveries fd ON fd.estate_id=? AND fd.delivery_date=dk.assignment_date AND fd.factory_id IS NOT NULL
        JOIN factories f ON fd.factory_id=f.id
        ORDER BY dk.assignment_date DESC LIMIT 10", [$estateId, $estateId]);

    // ── DELIVERIES TAB: uses the shared Year/Month filter above, plus Factory ──
    // Shows every day's total Tea Plucking (KG) for the period — not just
    // days already tied to a factory — so a factory (and weight) can be
    // assigned here too, for days that predate this feature or were missed.
    $dateWhere  = "da.estate_id=? AND LOWER(wt.unit_label)='kg' AND da.approval_status='approved' AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?";
    $dateParams = [$estateId, $filterMonth];

    $factoryWhere  = '';
    $factoryParams = [];
    if ($filterFactory === 'none') {
        $factoryWhere = "WHERE fd.factory_id IS NULL";
    } elseif ($filterFactory !== 'all' && (int)$filterFactory > 0) {
        $factoryWhere    = "WHERE fd.factory_id=?";
        $factoryParams[] = (int)$filterFactory;
    }

    $deliveries = DB::fetchAll("SELECT dk.assignment_date, dk.total_kg,
            fd.factory_id, fd.factory_weight, f.name as factory_name, fp.price_per_kg
        FROM (
            SELECT da.assignment_date, SUM(da.quantity) as total_kg
            FROM daily_assignments da
            JOIN work_types wt ON da.work_type_id=wt.id
            WHERE $dateWhere
            GROUP BY da.assignment_date
        ) dk
        LEFT JOIN factory_deliveries fd ON fd.estate_id=? AND fd.delivery_date=dk.assignment_date
        LEFT JOIN factories f ON fd.factory_id=f.id
        LEFT JOIN factory_prices fp ON fp.factory_id=fd.factory_id AND fp.price_month=DATE_FORMAT(dk.assignment_date,'%Y-%m-01')
        $factoryWhere
        ORDER BY dk.assignment_date DESC",
        array_merge($dateParams, [$estateId], $factoryParams));

    $delivTotalPluck     = array_sum(array_column($deliveries, 'total_kg'));
    $delivTotalFactoryKg = array_sum(array_map(fn($d) => $d['factory_weight'] !== null ? (float)$d['factory_weight'] : 0, $deliveries));
    $delivTotalValue     = array_sum(array_map(function ($d) {
        if ($d['factory_weight'] === null || $d['price_per_kg'] === null) return 0;
        return (float)$d['factory_weight'] * (float)$d['price_per_kg'];
    }, $deliveries));

    // ── FACTORY EXPENSES TAB: uses the shared Year/Month + Factory filter ──
    $editExpense = null;
    if (!empty($_GET['edit_expense'])) {
        $editExpense = DB::fetchOne("SELECT * FROM factory_expenses WHERE id=? AND estate_id=?", [(int)$_GET['edit_expense'], $estateId]);
    }

    $expWhere  = "fe.estate_id=? AND fe.expense_month=?";
    $expParams = [$estateId, $filterMonthStart];
    if ($filterFactory !== 'all' && $filterFactory !== 'none' && (int)$filterFactory > 0) {
        $expWhere   .= " AND fe.factory_id=?";
        $expParams[] = (int)$filterFactory;
    }
    $factoryExpenses = DB::fetchAll("SELECT fe.*, f.name as factory_name
        FROM factory_expenses fe
        JOIN factories f ON fe.factory_id=f.id
        WHERE $expWhere
        ORDER BY fe.expense_date DESC, fe.id DESC", $expParams);
    $expTotal = array_sum(array_column($factoryExpenses, 'amount'));

    // ── MONTHLY PRICES TAB ──
    $priceYear      = $_GET['pyear']  ?? date('Y');
    $priceMonthNum  = $_GET['pmonthnum'] ?? date('m');
    $priceMonthSel  = $priceYear . '-' . str_pad($priceMonthNum, 2, '0', STR_PAD_LEFT);
    $priceMonthDate = $priceMonthSel . '-01';
    $pricesThisMonth = DB::fetchAll("SELECT * FROM factory_prices WHERE estate_id=? AND price_month=?", [$estateId, $priceMonthDate]);
    $priceMap        = array_column($pricesThisMonth, null, 'factory_id');

    $priceHistory = DB::fetchAll("SELECT fp.*, f.name as factory_name FROM factory_prices fp
        JOIN factories f ON fp.factory_id=f.id
        WHERE fp.estate_id=? ORDER BY fp.price_month DESC, f.name ASC LIMIT 60", [$estateId]);
}

// Which tab to render as active on page load. Read from an actual GET
// param (not the URL #fragment — the server never sees that) so the
// correct panel is visible from the very first render. This also lets
// every full-page redirect land back on the right tab WITHOUT putting a
// #fragment in the URL, which is what was causing the browser to jump
// the page down to that anchor on load (native scroll-to-fragment runs
// before our JS un-hides the panel, so it used to fire against stale
// layout).
$fmTabs = ['overview', 'factories', 'deliveries', 'expenses', 'prices'];
$activeTab = $_GET['tab'] ?? '';
if (!in_array($activeTab, $fmTabs, true)) {
    if (!empty($_GET['edit_factory']))      $activeTab = 'factories';
    elseif (!empty($_GET['edit_expense']))  $activeTab = 'expenses';
    else                                    $activeTab = 'overview';
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!$factoriesReady): ?>
<div class="card" style="border:1px solid #fca5a5;background:var(--red-50)">
  <div class="card-title" style="color:var(--red-600)"><i class="ti ti-alert-triangle"></i> Setup Required</div>
  <p style="font-size:13px;color:var(--red-600);margin-top:8px">
    The Factory Management database tables haven't been created yet. Ask your administrator to run
    <code style="background:#fff;padding:2px 6px;border-radius:4px">install/migration_factory_management.sql</code>,
    <code style="background:#fff;padding:2px 6px;border-radius:4px">install/migration_factory_deliveries_daily.sql</code>
    and <code style="background:#fff;padding:2px 6px;border-radius:4px">install/migration_factory_expenses.sql</code>
    against the database (e.g. via phpMyAdmin), then reload this page.
  </p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; exit; ?>
<?php endif; ?>

<style>
.fm-nav-wrap {
  background: #fff;
  border: 1px solid #e8ede5;
  border-radius: var(--radius-lg);
  margin-bottom: 20px;
  position: sticky;
  top: 80px;
  z-index: 5;
  overflow: hidden;
}
.fm-nav {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  align-items: center;
  gap: 2px;
  padding: 6px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.fm-nav::-webkit-scrollbar { display: none; }
.fm-filter-bar {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 12px;
  border-top: 1px solid #f0f0eb;
  background: var(--gray-50);
}
.fm-filter-bar .form-group { margin-bottom: 0; }
.fm-tab {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 13px;
  font-weight: 500;
  color: var(--gray-600);
  cursor: pointer;
  border-bottom: 3px solid transparent;
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all .15s;
  white-space: nowrap;
  flex-shrink: 0;
}
.fm-tab:hover  { background: var(--green-50); color: var(--green-800); }
.fm-tab.active { background: var(--green-50); color: var(--green-800); border-bottom-color: var(--green-600); font-weight: 700; }
.fm-tab i      { font-size: 17px; flex-shrink: 0; }
.fm-panel { animation: fadeInPage .25s ease; }
.fm-status-received { background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap; }
.fm-status-pending  { background:#fff3cd;color:#856404;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap; }
.fm-diff-neg { color: var(--red-600); font-weight:600; }
.fm-diff-pos { color: var(--green-600); font-weight:600; }
.fm-weight-input { width:90px;padding:5px 8px;font-size:12px;border:1px solid #d8ddd5;border-radius:6px;text-align:right; }
.fm-overview-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }

@media (max-width: 900px) {
  .fm-overview-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
  .fm-tab { padding: 8px 10px; font-size: 11px; }
  .fm-tab i { font-size: 15px; }
  .fm-overview-grid { grid-template-columns: 1fr; }
}
</style>

<!-- STICKY HEADER: TABS + YEAR/MONTH/FACTORY FILTER (always visible, never covered while scrolling) -->
<div class="fm-nav-wrap">
  <div class="fm-nav">
    <a href="#overview"   class="fm-tab <?= $activeTab === 'overview' ? 'active' : '' ?>" onclick="return fmShowTab('overview',this)">
      <i class="ti ti-layout-dashboard"></i><span> Overview</span>
    </a>
    <a href="#deliveries" class="fm-tab <?= $activeTab === 'deliveries' ? 'active' : '' ?>" onclick="return fmShowTab('deliveries',this)">
      <i class="ti ti-truck-delivery"></i><span> Deliveries</span>
    </a>
    <a href="#expenses"   class="fm-tab <?= $activeTab === 'expenses' ? 'active' : '' ?>" onclick="return fmShowTab('expenses',this)">
      <i class="ti ti-receipt-2"></i><span> Factory Expenses</span>
    </a>
    <a href="#prices"     class="fm-tab <?= $activeTab === 'prices' ? 'active' : '' ?>" onclick="return fmShowTab('prices',this)">
      <i class="ti ti-tag"></i><span> Monthly Prices</span>
    </a>
    <a href="#factories"  class="fm-tab <?= $activeTab === 'factories' ? 'active' : '' ?>" onclick="return fmShowTab('factories',this)">
      <i class="ti ti-building-factory-2"></i><span> Factories</span>
    </a>
  </div>

  <!-- Year/Month filter — always visible in the header (drives Overview + Deliveries + Expenses), Factory narrows Deliveries/Expenses only -->
  <div class="fm-filter-bar">
    <form method="GET" id="fm-filter-form" action="factory-management.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="tab" id="fm-filter-tab-input" value="<?= sanitize($activeTab) ?>">
      <div class="form-group" style="min-width:180px">
        <label>Factory <span style="font-weight:400;color:var(--gray-400)">(Deliveries &amp; Expenses)</span></label>
        <select name="factory">
          <option value="all" <?= $filterFactory === 'all' ? 'selected' : '' ?>>All Factories</option>
          <option value="none" <?= $filterFactory === 'none' ? 'selected' : '' ?>>Unassigned</option>
          <?php foreach ($factories as $f): ?>
          <option value="<?= $f['id'] ?>" <?= (string)$filterFactory === (string)$f['id'] ? 'selected' : '' ?>><?= sanitize($f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Year</label>
        <?php $yearOpts = fmYearOptions(5, 1); if (!in_array((int)$filterYear, $yearOpts)) $yearOpts[] = (int)$filterYear; ?>
        <select name="year">
          <?php foreach ($yearOpts as $y): ?>
          <option value="<?= $y ?>" <?= (int)$filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Month</label>
        <select name="monthnum">
          <?php foreach ($fmMonthNames as $mNum => $mLabel): ?>
          <option value="<?= $mNum ?>" <?= $filterMonthNum === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="ti ti-filter"></i> Apply Filters</button>
      <a href="factory-management.php" id="fm-filter-reset" class="btn btn-secondary">Reset</a>
    </form>
  </div>
</div>

<!-- ══════════════════════ OVERVIEW ══════════════════════ -->
<div class="fm-panel" id="overview" <?= $activeTab === 'overview' ? '' : 'hidden' ?>>

  <div class="stats-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
    <div class="stat-card teal">
      <div class="stat-label"><i class="ti ti-leaf"></i> Total Factory Weight</div>
      <div class="stat-value"><?= number_format($ovTotalWeight, 0) ?> kg</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-truck-delivery"></i> Total Deliveries</div>
      <div class="stat-value"><?= $ovDeliveries ?></div>
    </div>
    <div class="stat-card amber">
      <div class="stat-label"><i class="ti ti-coin"></i> Total Value</div>
      <div class="stat-value"><?= moneyShort($ovTotalValue) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-building-factory-2"></i> Active Factories</div>
      <div class="stat-value"><?= $ovActiveFactories ?> <span style="font-size:12px;color:var(--gray-400);font-weight:500">/ <?= count($factories) ?></span></div>
    </div>
  </div>

  <div class="fm-overview-grid" style="margin-bottom:20px">

    <!-- Factory Profit Summary: Value − Expenses = Net Profit for the selected month -->
    <div class="card" style="border-left:4px solid var(--green-400)">
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap">
        <i class="ti ti-calculator" style="color:var(--green-600);font-size:16px"></i>
        <span style="font-size:13px;font-weight:700;color:var(--green-900)">Profit Summary</span>
        <a href="#expenses" class="card-action" style="margin-left:auto;font-size:11px" onclick="return fmShowTab('expenses')">Expenses</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;align-items:center;justify-content:space-between;background:var(--green-50);border-radius:var(--radius-md);padding:9px 12px">
          <span style="font-size:11px;font-weight:700;color:var(--green-600);text-transform:uppercase;letter-spacing:.03em"><i class="ti ti-coin"></i> Value</span>
          <span style="font-size:15px;font-weight:700;color:var(--green-800)"><?= money($ovTotalValue) ?></span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;background:var(--amber-50);border-radius:var(--radius-md);padding:9px 12px">
          <span style="font-size:11px;font-weight:700;color:var(--amber-600);text-transform:uppercase;letter-spacing:.03em"><i class="ti ti-receipt-2"></i> Expenses</span>
          <span style="font-size:15px;font-weight:700;color:var(--amber-600)"><?= money($ovTotalExpenses) ?></span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;background:<?= $ovNetProfit >= 0 ? 'var(--green-50)' : 'var(--red-50)' ?>;border-radius:var(--radius-md);padding:9px 12px;<?= $ovNetProfit < 0 ? 'border:1px solid #fca5a5' : '' ?>">
          <span style="font-size:11px;font-weight:700;color:<?= $ovNetProfit >= 0 ? 'var(--green-600)' : 'var(--red-600)' ?>;text-transform:uppercase;letter-spacing:.03em"><i class="ti ti-sum"></i> Net Profit</span>
          <span style="font-size:15px;font-weight:700;color:<?= $ovNetProfit >= 0 ? 'var(--green-800)' : 'var(--red-600)' ?>"><?= money($ovNetProfit) ?></span>
        </div>
      </div>
    </div>

    <!-- Daily trend -->
    <div class="card">
      <div class="card-header">
        <div class="card-title" style="font-size:13px"><i class="ti ti-chart-bar"></i> Deliveries (KG)</div>
      </div>
      <?php if ($dailyFactoryKg): ?>
      <div class="mini-chart" style="height:100px;gap:3px">
        <?php foreach ($dailyFactoryKg as $d): ?>
        <div class="mini-bar" style="height:<?= $maxDailyFactoryKg > 0 ? round((float)$d['kg'] / $maxDailyFactoryKg * 100) : 0 ?>%"
             title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'], 1) ?> kg"></div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state"><i class="ti ti-chart-bar-off"></i><p>No confirmed weights yet</p></div>
      <?php endif; ?>
    </div>

    <!-- Factory performance breakdown -->
    <div class="card">
      <div class="card-header">
        <div class="card-title" style="font-size:13px"><i class="ti ti-chart-pie"></i> Factory Performance</div>
        <a href="#deliveries" class="card-action" onclick="return fmShowTab('deliveries')">View All</a>
      </div>
      <?php if ($factoryMonthMap): ?>
        <?php foreach ($factories as $f):
          if (empty($factoryMonthMap[$f['id']])) continue;
          $stat  = $factoryMonthMap[$f['id']];
          $share = $ovTotalWeight > 0 ? round($stat['kg'] / $ovTotalWeight * 100) : 0;
        ?>
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600;color:var(--green-900)"><?= sanitize($f['name']) ?></span>
            <span style="font-size:12px;font-weight:700;color:var(--green-700)"><?= money($stat['value']) ?></span>
          </div>
          <div style="height:6px;background:var(--gray-50);border-radius:4px;overflow:hidden;margin-bottom:3px">
            <div style="width:<?= $share ?>%;height:100%;background:linear-gradient(90deg,var(--green-400),var(--green-600));border-radius:4px"></div>
          </div>
          <div style="font-size:10px;color:var(--gray-400)"><?= number_format($stat['kg'], 0) ?> kg · <?= $share ?>%</div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <div class="empty-state"><i class="ti ti-building-factory-2"></i><p>No confirmed deliveries yet</p></div>
      <?php endif; ?>
    </div>
  </div>
  <div style="font-size:11px;color:var(--gray-400);margin-top:-14px;margin-bottom:20px"><?= $filterMonthLabel ?></div>

  <!-- Recent deliveries -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-history"></i> Recent Factory Deliveries</div>
      <a href="#deliveries" class="card-action" onclick="return fmShowTab('deliveries')">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Factory</th>
            <th style="text-align:right">Plucking (KG)</th><th style="text-align:right">Factory (KG)</th>
            <th style="text-align:right">Difference</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentDeliveries as $r):
            $diff = $r['factory_weight'] !== null ? ((float)$r['total_kg'] - (float)$r['factory_weight']) : null;
          ?>
          <tr>
            <td><?= fmtDate($r['assignment_date']) ?></td>
            <td><?= sanitize($r['factory_name']) ?></td>
            <td style="text-align:right"><?= number_format($r['total_kg'], 1) ?></td>
            <td style="text-align:right"><?= $r['factory_weight'] !== null ? number_format($r['factory_weight'], 1) : '—' ?></td>
            <td style="text-align:right" class="<?= $diff === null ? '' : ($diff > 0 ? 'fm-diff-neg' : ($diff < 0 ? 'fm-diff-pos' : '')) ?>">
              <?= $diff === null ? '—' : number_format($diff, 1) ?>
            </td>
            <td><span class="<?= $r['factory_weight'] !== null ? 'fm-status-received' : 'fm-status-pending' ?>"><?= $r['factory_weight'] !== null ? 'Received' : 'Pending' ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentDeliveries): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="ti ti-truck-delivery"></i><p>No factory deliveries recorded yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Factories summary -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-building-factory-2"></i> Factories</div>
      <a href="#factories" class="card-action" onclick="return fmShowTab('factories')">Manage Factories</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th><th style="text-align:right">Monthly Price (LKR/KG)</th>
            <th style="text-align:right">Total KG (<?= $filterMonthLabel ?>)</th><th style="text-align:right">Total Value (LKR)</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($factories as $f): $stat = $factoryMonthMap[$f['id']] ?? ['kg' => 0, 'value' => 0]; ?>
          <tr>
            <td><strong><?= sanitize($f['name']) ?></strong><?php if ($f['location']): ?><div style="font-size:11px;color:var(--gray-400)"><?= sanitize($f['location']) ?></div><?php endif; ?></td>
            <td style="text-align:right"><?= isset($curPriceMap[$f['id']]) ? number_format($curPriceMap[$f['id']], 2) : '—' ?></td>
            <td style="text-align:right"><?= number_format($stat['kg'], 0) ?></td>
            <td style="text-align:right"><?= money($stat['value']) ?></td>
            <td><?= pill($f['is_active'] ? 'Active' : 'Inactive', $f['is_active'] ? 'active' : 'inactive') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$factories): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="ti ti-building-factory-2"></i><p>No factories added yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════ FACTORIES ══════════════════════ -->
<div class="fm-panel" id="factories" <?= $activeTab === 'factories' ? '' : 'hidden' ?>>

  <div class="form-panel" style="margin-bottom:20px;<?= $editFactory ? 'border:2px solid var(--amber-200)' : '' ?>">
    <div class="form-panel-title" style="<?= $editFactory ? 'color:var(--amber-600)' : '' ?>">
      <i class="ti ti-<?= $editFactory ? 'edit' : 'building-factory-2' ?>"></i>
      <?= $editFactory ? 'Edit Factory' : 'Add New Factory' ?>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="<?= $editFactory ? 'edit_factory' : 'add_factory' ?>">
      <?php if ($editFactory): ?><input type="hidden" name="id" value="<?= $editFactory['id'] ?>"><?php endif; ?>
      <div class="grid-form" style="margin-bottom:16px">
        <div class="form-group">
          <label>Factory Name *</label>
          <input type="text" name="name" placeholder="e.g. Green Valley Tea Factory" required
                 value="<?= sanitize($editFactory['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Location</label>
          <input type="text" name="location" placeholder="e.g. Nuwara Eliya"
                 value="<?= sanitize($editFactory['location'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="is_active">
            <option value="1" <?= ($editFactory['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= ($editFactory['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group col-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="Any notes about this factory..."><?= sanitize($editFactory['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> <?= $editFactory ? 'Update Factory' : 'Save Factory' ?></button>
        <?php if ($editFactory): ?><a href="factory-management.php?tab=factories" class="btn btn-secondary">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="ti ti-list"></i> All Factories</div></div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th><th>Location</th><th style="text-align:right">Price — <?= $filterMonthLabel ?></th>
            <th style="text-align:right">Deliveries</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($factories as $f): $stat = $factoryMonthMap[$f['id']] ?? ['count' => 0]; ?>
          <tr>
            <td><strong><?= sanitize($f['name']) ?></strong></td>
            <td><?= sanitize($f['location']) ?: '—' ?></td>
            <td style="text-align:right"><?= isset($curPriceMap[$f['id']]) ? 'Rs. ' . number_format($curPriceMap[$f['id']], 2) : '—' ?></td>
            <td style="text-align:right"><?= $stat['count'] ?></td>
            <td><?= pill($f['is_active'] ? 'Active' : 'Inactive', $f['is_active'] ? 'active' : 'inactive') ?></td>
            <td>
              <div style="display:flex;gap:4px;justify-content:flex-end">
                <a href="factory-management.php?edit_factory=<?= $f['id'] ?>&tab=factories" class="btn btn-outline btn-sm" title="Edit"><i class="ti ti-edit"></i></a>
                <form method="POST" style="display:inline" onsubmit="return confirm('<?= $f['is_active'] ? 'Deactivate' : 'Activate' ?> this factory?')">
                  <input type="hidden" name="action" value="toggle_factory">
                  <input type="hidden" name="id" value="<?= $f['id'] ?>">
                  <input type="hidden" name="current" value="<?= $f['is_active'] ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="color:<?= $f['is_active'] ? 'var(--amber-600)' : 'var(--green-600)' ?>" title="<?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>">
                    <i class="ti ti-<?= $f['is_active'] ? 'eye-off' : 'eye' ?>"></i>
                  </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete <?= sanitize($f['name']) ?>? Only possible if it has no delivery records.')">
                  <input type="hidden" name="action" value="delete_factory">
                  <input type="hidden" name="id" value="<?= $f['id'] ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)" title="Delete"><i class="ti ti-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$factories): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="ti ti-building-factory-2"></i><p>No factories added yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════ DELIVERIES ══════════════════════ -->
<div class="fm-panel" id="deliveries" <?= $activeTab === 'deliveries' ? '' : 'hidden' ?>>

  <div class="stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px">
    <div class="stat-card teal">
      <div class="stat-label"><i class="ti ti-leaf"></i> Total Plucking Weight</div>
      <div class="stat-value"><?= number_format($delivTotalPluck, 0) ?> kg</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-building-factory-2"></i> Total Factory Weight</div>
      <div class="stat-value"><?= number_format($delivTotalFactoryKg, 0) ?> kg</div>
    </div>
    <div class="stat-card amber">
      <div class="stat-label"><i class="ti ti-coin"></i> Total Value</div>
      <div class="stat-value"><?= moneyShort($delivTotalValue) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-truck-delivery"></i> Deliveries</div>
      <span style="font-size:12px;color:var(--gray-400)"><?= count($deliveries) ?> record(s)</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th style="text-align:right">Plucking (KG)</th>
            <th colspan="2">Factory / Confirm Weight (KG)</th>
            <th style="text-align:right">Difference</th>
            <th style="text-align:right">Price/KG</th>
            <th style="text-align:right">Value</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deliveries as $d):
            $diff   = $d['factory_weight'] !== null ? ((float)$d['total_kg'] - (float)$d['factory_weight']) : null;
            $value  = ($d['factory_weight'] !== null && $d['price_per_kg'] !== null) ? (float)$d['factory_weight'] * (float)$d['price_per_kg'] : null;
            $status = $d['factory_id'] === null ? 'unassigned' : ($d['factory_weight'] !== null ? 'received' : 'pending');
            $statusLabel = ['unassigned' => 'Unassigned', 'pending' => 'Pending', 'received' => 'Received'][$status];
            $statusClass = ['unassigned' => 'fm-status-pending', 'pending' => 'fm-status-pending', 'received' => 'fm-status-received'][$status];
          ?>
          <tr>
            <td><?= fmtDate($d['assignment_date']) ?></td>
            <td style="text-align:right"><?= number_format($d['total_kg'], 1) ?></td>
            <td colspan="2">
              <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="update_delivery">
                <input type="hidden" name="delivery_date" value="<?= $d['assignment_date'] ?>">
                <input type="hidden" name="ret_factory" value="<?= sanitize($filterFactory) ?>">
                <input type="hidden" name="ret_year" value="<?= sanitize($filterYear) ?>">
                <input type="hidden" name="ret_monthnum" value="<?= sanitize($filterMonthNum) ?>">
                <select name="factory_id" style="font-size:12px;padding:5px 8px;border:1px solid #d8ddd5;border-radius:6px">
                  <option value="">— Unassigned —</option>
                  <?php foreach ($factories as $f): ?>
                  <option value="<?= $f['id'] ?>" <?= (int)$d['factory_id'] === (int)$f['id'] ? 'selected' : '' ?>><?= sanitize($f['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="number" name="factory_weight" class="fm-weight-input" step="0.01" min="0"
                       value="<?= $d['factory_weight'] !== null ? $d['factory_weight'] : '' ?>" placeholder="Factory KG">
                <button type="submit" class="btn btn-outline btn-sm" title="Save"><i class="ti ti-check"></i></button>
              </form>
            </td>
            <td style="text-align:right" class="<?= $diff === null ? '' : ($diff > 0 ? 'fm-diff-neg' : ($diff < 0 ? 'fm-diff-pos' : '')) ?>">
              <?= $diff === null ? '—' : number_format($diff, 1) ?>
            </td>
            <td style="text-align:right"><?= $d['price_per_kg'] !== null ? number_format($d['price_per_kg'], 2) : '—' ?></td>
            <td style="text-align:right;font-weight:700"><?= $value !== null ? money($value) : '—' ?></td>
            <td><span class="<?= $statusClass ?>"><?= $statusLabel ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$deliveries): ?>
          <tr><td colspan="8"><div class="empty-state"><i class="ti ti-truck-delivery"></i><p>No plucking days match these filters</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════ FACTORY EXPENSES ══════════════════════ -->
<div class="fm-panel" id="expenses" <?= $activeTab === 'expenses' ? '' : 'hidden' ?>>

  <div class="form-panel" style="margin-bottom:20px;<?= $editExpense ? 'border:2px solid var(--amber-200)' : '' ?>">
    <div class="form-panel-title" style="<?= $editExpense ? 'color:var(--amber-600)' : '' ?>">
      <i class="ti ti-<?= $editExpense ? 'edit' : 'receipt-2' ?>"></i>
      <?= $editExpense ? 'Edit Factory Expense' : 'Add Factory Expense' ?>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="<?= $editExpense ? 'edit_expense' : 'add_expense' ?>">
      <?php if ($editExpense): ?><input type="hidden" name="id" value="<?= $editExpense['id'] ?>"><?php endif; ?>
      <input type="hidden" name="ret_year" value="<?= sanitize($filterYear) ?>">
      <input type="hidden" name="ret_monthnum" value="<?= sanitize($filterMonthNum) ?>">
      <?php
        $expDefYear  = $editExpense ? date('Y', strtotime($editExpense['expense_month'])) : $filterYear;
        $expDefMNum  = $editExpense ? date('m', strtotime($editExpense['expense_month'])) : $filterMonthNum;
        $expYearOpts = fmYearOptions(5, 1);
        if (!in_array((int)$expDefYear, $expYearOpts)) $expYearOpts[] = (int)$expDefYear;
      ?>
      <div class="grid-form" style="margin-bottom:16px">
        <div class="form-group">
          <label>Factory *</label>
          <select name="factory_id" required>
            <option value="">— Select —</option>
            <?php foreach ($factories as $f): ?>
            <option value="<?= $f['id'] ?>" <?= ($editExpense && (int)$editExpense['factory_id'] === (int)$f['id']) ? 'selected' : '' ?>><?= sanitize($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Month &amp; Year *</label>
          <div style="display:flex;gap:8px">
            <select name="expense_month_year" style="flex:1">
              <?php foreach ($expYearOpts as $y): ?>
              <option value="<?= $y ?>" <?= (int)$expDefYear === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endforeach; ?>
            </select>
            <select name="expense_month_num" style="flex:1.4">
              <?php foreach ($fmMonthNames as $mNum => $mLabel): ?>
              <option value="<?= $mNum ?>" <?= $expDefMNum === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php
          $expKnownCats = array_diff($fmExpenseCategories, ['Other']);
          $expIsCustomCat = $editExpense && !in_array($editExpense['category'], $expKnownCats, true);
        ?>
        <div class="form-group">
          <label>Expense Type / Category *</label>
          <select name="category" id="exp-category" required onchange="fmToggleExpenseOther(this)">
            <?php foreach ($fmExpenseCategories as $cat): ?>
            <option value="<?= sanitize($cat) ?>" <?= (($editExpense && $editExpense['category'] === $cat) || ($cat === 'Other' && $expIsCustomCat)) ? 'selected' : '' ?>><?= sanitize($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="exp-other-wrap" style="<?= $expIsCustomCat ? '' : 'display:none' ?>">
          <label>Specify Category *</label>
          <input type="text" name="category_other" placeholder="e.g. Diesel, Packaging..."
                 value="<?= $expIsCustomCat ? sanitize($editExpense['category']) : '' ?>">
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="expense_date" value="<?= sanitize($editExpense['expense_date'] ?? today()) ?>" required>
        </div>
        <div class="form-group">
          <label>Amount (LKR) *</label>
          <input type="number" name="amount" min="0" step="0.01" required placeholder="e.g. 15000"
                 value="<?= $editExpense ? $editExpense['amount'] : '' ?>">
        </div>
        <div class="form-group">
          <label>Description</label>
          <input type="text" name="description" placeholder="e.g. Fertilizer supplied by factory"
                 value="<?= sanitize($editExpense['description'] ?? '') ?>">
        </div>
        <div class="form-group col-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="Any additional notes..."><?= sanitize($editExpense['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> <?= $editExpense ? 'Update Expense' : 'Save Expense' ?></button>
        <?php if ($editExpense): ?><a href="factory-management.php?year=<?= sanitize($filterYear) ?>&monthnum=<?= sanitize($filterMonthNum) ?>&tab=expenses" class="btn btn-secondary">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-receipt-2"></i> Factory Expenses — <?= $filterMonthLabel ?></div>
      <span style="font-size:12px;color:var(--gray-400)"><?= count($factoryExpenses) ?> record(s)</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Factory</th><th>Category</th><th>Description</th>
            <th style="text-align:right">Amount (LKR)</th><th>Notes</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($factoryExpenses as $ex): $isEditing = $editExpense && (int)$editExpense['id'] === (int)$ex['id']; ?>
          <tr style="<?= $isEditing ? 'background:var(--amber-50)' : '' ?>">
            <td><?= fmtDate($ex['expense_date']) ?></td>
            <td><?= sanitize($ex['factory_name']) ?></td>
            <td><?= sanitize($ex['category']) ?></td>
            <td><?= sanitize($ex['description']) ?: '—' ?></td>
            <td style="text-align:right;font-weight:700"><?= money($ex['amount']) ?></td>
            <td style="font-size:12px;color:var(--gray-500)"><?= sanitize($ex['notes']) ?: '—' ?></td>
            <td>
              <div style="display:flex;gap:4px;justify-content:flex-end">
                <a href="factory-management.php?year=<?= sanitize($filterYear) ?>&monthnum=<?= sanitize($filterMonthNum) ?>&edit_expense=<?= $ex['id'] ?>&tab=expenses"
                   class="btn btn-outline btn-sm" title="Edit"><i class="ti ti-edit"></i></a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this expense record?')">
                  <input type="hidden" name="action" value="delete_expense">
                  <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                  <input type="hidden" name="ret_year" value="<?= sanitize($filterYear) ?>">
                  <input type="hidden" name="ret_monthnum" value="<?= sanitize($filterMonthNum) ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)" title="Delete"><i class="ti ti-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$factoryExpenses): ?>
          <tr><td colspan="7"><div class="empty-state"><i class="ti ti-receipt-2"></i><p>No factory expenses recorded for this month</p></div></td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($factoryExpenses): ?>
        <tfoot>
          <tr>
            <td colspan="4" style="text-align:right;font-weight:700;color:var(--green-900)">Total Factory Expenses</td>
            <td style="text-align:right;font-weight:700;color:var(--amber-600)"><?= money($expTotal) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════ MONTHLY PRICES ══════════════════════ -->
<div class="fm-panel" id="prices" <?= $activeTab === 'prices' ? '' : 'hidden' ?>>

  <div class="card" style="margin-bottom:16px">
    <form method="GET" action="factory-management.php" style="display:flex;gap:12px;align-items:flex-end">
      <input type="hidden" name="tab" value="prices">
      <div class="form-group" style="margin-bottom:0">
        <label>Year</label>
        <?php $priceYearOpts = fmYearOptions(5, 1); if (!in_array((int)$priceYear, $priceYearOpts)) $priceYearOpts[] = (int)$priceYear; ?>
        <select name="pyear">
          <?php foreach ($priceYearOpts as $y): ?>
          <option value="<?= $y ?>" <?= (int)$priceYear === $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label>Month</label>
        <select name="pmonthnum">
          <?php foreach ($fmMonthNames as $mNum => $mLabel): ?>
          <option value="<?= $mNum ?>" <?= $priceMonthNum === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="ti ti-filter"></i> Load Month</button>
    </form>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <div class="card-title"><i class="ti ti-tag"></i> Price per KG — <?= date('F Y', strtotime($priceMonthDate)) ?></div>
    </div>
    <?php if ($factories): ?>
    <?php foreach ($factories as $f): $existing = $priceMap[$f['id']] ?? null; ?>
    <form method="POST" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border:1px solid #e8ede5;border-radius:var(--radius-md);margin-bottom:8px;background:<?= $f['is_active'] ? '#fff' : 'var(--gray-50)' ?>;flex-wrap:wrap">
      <input type="hidden" name="action" value="save_price">
      <input type="hidden" name="factory_id" value="<?= $f['id'] ?>">
      <input type="hidden" name="price_month" value="<?= sanitize($priceMonthSel) ?>">
      <div style="flex:1;min-width:160px;font-size:13px;font-weight:700;color:var(--green-900)">
        <?= sanitize($f['name']) ?>
        <?php if (!$f['is_active']): ?><span style="font-size:11px;color:var(--gray-400);font-weight:400"> (inactive)</span><?php endif; ?>
      </div>
      <span style="font-size:12px;color:var(--gray-400)">Rs.</span>
      <input type="number" name="price_per_kg" min="0" step="0.01" required
             value="<?= $existing ? $existing['price_per_kg'] : '' ?>" placeholder="e.g. 250.00"
             style="width:120px;font-size:14px;font-weight:700;padding:6px 10px;border:1.5px solid var(--amber-200);border-radius:var(--radius-md);background:var(--amber-50);color:var(--amber-800);text-align:right">
      <span style="font-size:11px;color:var(--gray-400)">/ kg</span>
      <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Save</button>
    </form>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><i class="ti ti-building-factory-2"></i><p>Add a factory first to set its price</p></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="ti ti-history"></i> Price History</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Month</th><th>Factory</th><th style="text-align:right">Price / KG</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($priceHistory as $ph): ?>
          <tr>
            <td><?= date('F Y', strtotime($ph['price_month'])) ?></td>
            <td><?= sanitize($ph['factory_name']) ?></td>
            <td style="text-align:right">Rs. <?= number_format($ph['price_per_kg'], 2) ?></td>
            <td>
              <form method="POST" style="display:inline" onsubmit="return confirm('Remove this price entry?')">
                <input type="hidden" name="action" value="delete_price">
                <input type="hidden" name="id" value="<?= $ph['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red-400)"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$priceHistory): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-tag"></i><p>No prices set yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function fmToggleExpenseOther(sel) {
  var wrap = document.getElementById('exp-other-wrap');
  if (wrap) wrap.style.display = (sel.value === 'Other') ? 'block' : 'none';
}

function fmShowTab(id, el) {
  document.querySelectorAll('.fm-panel').forEach(function(s) { s.hidden = (s.id !== id); });
  document.querySelectorAll('.fm-tab').forEach(function(i) { i.classList.remove('active'); });
  if (!el) el = document.querySelector('.fm-tab[href="#' + id + '"]');
  if (el) el.classList.add('active');
  // Keep the filter form's hidden "tab" field (and Reset link) pointed at
  // whichever tab is currently open, so applying/resetting filters doesn't
  // bounce you over to a different tab. Deliberately NOT using a #fragment
  // for these — a real page reload to a URL with #fragment makes the
  // browser jump-scroll to that element before our JS un-hides it, which
  // looked like the page "jumping down". A `tab=` query param instead lets
  // PHP render the correct panel visible from the first paint.
  var tabInput = document.getElementById('fm-filter-tab-input');
  if (tabInput) tabInput.value = id;
  var resetLink = document.getElementById('fm-filter-reset');
  if (resetLink) resetLink.href = 'factory-management.php?tab=' + id;
  if (history.replaceState) history.replaceState(null, '', '#' + id);
  return false;
}
(function() {
  var tabs = ['overview', 'factories', 'deliveries', 'expenses', 'prices'];
  // The server already rendered the correct panel visible based on ?tab=
  // (see $activeTab in PHP) — this just syncs the nav's active class and
  // the filter form/reset link to match. Fall back to the #fragment only
  // for old bookmarks/links that predate the ?tab= param.
  var id = '<?= $activeTab ?>';
  if (tabs.indexOf(id) === -1) {
    id = window.location.hash ? window.location.hash.slice(1) : '';
  }
  if (tabs.indexOf(id) === -1) id = 'overview';
  fmShowTab(id);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
