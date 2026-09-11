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
        if (!$name) { flash('error', 'Factory name is required.'); redirect('/factory-management.php#factories'); }

        if ($action === 'add_factory') {
            $exists = DB::fetchOne("SELECT id FROM factories WHERE name=? AND estate_id=?", [$name, $estateId]);
            if ($exists) { flash('error', 'A factory with this name already exists.'); redirect('/factory-management.php#factories'); }
            DB::insert("INSERT INTO factories (estate_id,name,location,notes,is_active) VALUES (?,?,?,?,?)",
                [$estateId, $name, $location, $notes, $active]);
            flash('success', 'Factory "' . $name . '" added.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            DB::execute("UPDATE factories SET name=?,location=?,notes=?,is_active=? WHERE id=? AND estate_id=?",
                [$name, $location, $notes, $active, $id, $estateId]);
            flash('success', 'Factory updated.');
        }
        redirect('/factory-management.php#factories');
    }

    // ── FACTORY: toggle active status ─────────────
    if ($action === 'toggle_factory') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = (int)($_POST['current'] ?? 1);
        DB::execute("UPDATE factories SET is_active=? WHERE id=? AND estate_id=?", [$current ? 0 : 1, $id, $estateId]);
        flash('success', 'Factory status updated.');
        redirect('/factory-management.php#factories');
    }

    // ── FACTORY: delete (only if never used in a delivery) ──
    if ($action === 'delete_factory') {
        $id   = (int)($_POST['id'] ?? 0);
        $used = DB::fetchOne("SELECT COUNT(*) as cnt FROM factory_deliveries WHERE factory_id=? AND estate_id=?", [$id, $estateId]);
        if (($used['cnt'] ?? 0) > 0) {
            flash('error', 'Cannot delete — this factory has ' . $used['cnt'] . ' delivery record(s). Deactivate it instead.');
            redirect('/factory-management.php#factories');
        }
        DB::execute("DELETE FROM factory_prices WHERE factory_id=? AND estate_id=?", [$id, $estateId]);
        DB::execute("DELETE FROM factories WHERE id=? AND estate_id=?", [$id, $estateId]);
        flash('success', 'Factory deleted.');
        redirect('/factory-management.php#factories');
    }

    // ── MONTHLY PRICE: save (upsert per factory + month) ──
    if ($action === 'save_price') {
        $factoryId = (int)($_POST['factory_id'] ?? 0);
        $monthIn   = trim($_POST['price_month'] ?? date('Y-m')); // "YYYY-MM"
        $price     = (float)($_POST['price_per_kg'] ?? -1);
        if (!$factoryId || !$monthIn || $price < 0) {
            flash('error', 'Factory, month and a valid price are required.');
            redirect('/factory-management.php#prices');
        }
        $priceMonth = date('Y-m-01', strtotime($monthIn . '-01'));
        DB::execute("INSERT INTO factory_prices (estate_id,factory_id,price_month,price_per_kg,created_by)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE price_per_kg=?, updated_at=NOW()",
            [$estateId, $factoryId, $priceMonth, $price, $uid, $price]);
        flash('success', 'Price saved.');
        redirect('/factory-management.php?pmonth=' . urlencode($monthIn) . '#prices');
    }

    // ── MONTHLY PRICE: delete one entry ────────────
    if ($action === 'delete_price') {
        DB::execute("DELETE FROM factory_prices WHERE id=? AND estate_id=?", [(int)($_POST['id'] ?? 0), $estateId]);
        flash('success', 'Price entry removed.');
        redirect('/factory-management.php#prices');
    }

    // ── DELIVERY: assign factory and/or confirm weight for a whole day ──
    if ($action === 'update_delivery') {
        $deliveryDate = $_POST['delivery_date'] ?? '';
        $factoryId    = (int)($_POST['factory_id'] ?? 0) ?: null;
        $weight       = trim($_POST['factory_weight'] ?? '');
        $weight       = ($weight === '') ? null : (float)$weight;

        // Preserve whatever Factory/Month/Date filter was active so saving
        // a row doesn't bounce the page back to the current month.
        $backTo = '/factory-management.php?' . http_build_query(array_filter([
            'factory' => $_POST['ret_factory'] ?? '',
            'month'   => $_POST['ret_month']   ?? '',
            'fdate'   => $_POST['ret_fdate']   ?? '',
        ], fn($v) => $v !== '')) . '#deliveries';

        if (!$deliveryDate) { flash('error', 'Missing delivery date.'); redirect($backTo); }
        DB::execute("INSERT INTO factory_deliveries (estate_id, delivery_date, factory_id, factory_weight, created_by)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE factory_id=VALUES(factory_id), factory_weight=VALUES(factory_weight), updated_at=NOW()",
            [$estateId, $deliveryDate, $factoryId, $weight, $uid]);
        flash('success', 'Delivery updated.');
        redirect($backTo);
    }
}

// Months as [value=>'YYYY-MM', label=>'September 2026'], newest first.
// Used instead of <input type="month"> because that input type isn't
// reliably supported across browsers (notably older Firefox falls back
// to a plain text box with no calendar, making past months unpickable).
function fmMonthOptions($monthsBack = 12, $monthsForward = 0) {
    $opts = [];
    $base = strtotime(date('Y-m-01'));
    // Oldest first, newest last — so the dropdown reads Jan, Feb, Mar... order.
    for ($i = $monthsBack; $i >= -$monthsForward; $i--) {
        $ts = strtotime(sprintf('%+d month', -$i), $base);
        $opts[date('Y-m', $ts)] = date('F Y', $ts);
    }
    return $opts;
}

// ── Check both migrations have been applied before querying ────
$factoriesReady = true;
try {
    DB::fetchOne("SELECT 1 FROM factories LIMIT 1", []);
    DB::fetchOne("SELECT 1 FROM factory_deliveries LIMIT 1", []);
} catch (Exception $e) {
    $factoriesReady = false;
}

if ($factoriesReady) {
    $editFactory = null;
    if (!empty($_GET['edit_factory'])) {
        $editFactory = DB::fetchOne("SELECT * FROM factories WHERE id=? AND estate_id=?", [(int)$_GET['edit_factory'], $estateId]);
    }

    $factories = DB::fetchAll("SELECT * FROM factories WHERE estate_id=? ORDER BY is_active DESC, name ASC", [$estateId]);

    $curMonth    = date('Y-m-01');
    $curPrices   = DB::fetchAll("SELECT factory_id, price_per_kg FROM factory_prices WHERE estate_id=? AND price_month=?", [$estateId, $curMonth]);
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
        ORDER BY dk.assignment_date DESC", [$estateId, $curMonth, $curMonth, $estateId]);

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

    // ── DELIVERIES TAB: filters ──
    // Shows every day's total Tea Plucking (KG) for the period — not just
    // days already tied to a factory — so a factory (and weight) can be
    // assigned here too, for days that predate this feature or were missed.
    $filterFactory = $_GET['factory'] ?? 'all'; // 'all' | 'none' (unassigned) | <factory id>
    $filterMonth   = $_GET['month']   ?? date('Y-m');
    $filterDate    = $_GET['fdate']   ?? '';

    $dateWhere  = "da.estate_id=? AND LOWER(wt.unit_label)='kg' AND da.approval_status='approved'";
    $dateParams = [$estateId];
    if ($filterDate) {
        $dateWhere   .= " AND da.assignment_date=?";
        $dateParams[] = $filterDate;
    } elseif ($filterMonth) {
        $dateWhere   .= " AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?";
        $dateParams[] = $filterMonth;
    }

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

    // ── MONTHLY PRICES TAB ──
    $priceMonthSel  = $_GET['pmonth'] ?? date('Y-m');
    $priceMonthDate = $priceMonthSel . '-01';
    $pricesThisMonth = DB::fetchAll("SELECT * FROM factory_prices WHERE estate_id=? AND price_month=?", [$estateId, $priceMonthDate]);
    $priceMap        = array_column($pricesThisMonth, null, 'factory_id');

    $priceHistory = DB::fetchAll("SELECT fp.*, f.name as factory_name FROM factory_prices fp
        JOIN factories f ON fp.factory_id=f.id
        WHERE fp.estate_id=? ORDER BY fp.price_month DESC, f.name ASC LIMIT 60", [$estateId]);
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!$factoriesReady): ?>
<div class="card" style="border:1px solid #fca5a5;background:var(--red-50)">
  <div class="card-title" style="color:var(--red-600)"><i class="ti ti-alert-triangle"></i> Setup Required</div>
  <p style="font-size:13px;color:var(--red-600);margin-top:8px">
    The Factory Management database tables haven't been created yet. Ask your administrator to run
    <code style="background:#fff;padding:2px 6px;border-radius:4px">install/migration_factory_management.sql</code>
    and <code style="background:#fff;padding:2px 6px;border-radius:4px">install/migration_factory_deliveries_daily.sql</code>
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
.fm-panel { }
.fm-status-received { background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap; }
.fm-status-pending  { background:#fff3cd;color:#856404;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap; }
.fm-diff-neg { color: var(--red-600); font-weight:600; }
.fm-diff-pos { color: var(--green-600); font-weight:600; }
.fm-weight-input { width:90px;padding:5px 8px;font-size:12px;border:1px solid #d8ddd5;border-radius:6px;text-align:right; }

@media (max-width: 600px) {
  .fm-tab { padding: 8px 10px; font-size: 11px; }
  .fm-tab i { font-size: 15px; }
}
</style>

<!-- STICKY HEADER: TABS + (on Deliveries) FILTERS -->
<div class="fm-nav-wrap">
  <div class="fm-nav">
    <a href="#overview"   class="fm-tab active" onclick="return fmShowTab('overview',this)">
      <i class="ti ti-layout-dashboard"></i><span> Overview</span>
    </a>
    <a href="#factories"  class="fm-tab" onclick="return fmShowTab('factories',this)">
      <i class="ti ti-building-factory-2"></i><span> Factories</span>
    </a>
    <a href="#deliveries" class="fm-tab" onclick="return fmShowTab('deliveries',this)">
      <i class="ti ti-truck-delivery"></i><span> Deliveries</span>
    </a>
    <a href="#prices"     class="fm-tab" onclick="return fmShowTab('prices',this)">
      <i class="ti ti-tag"></i><span> Monthly Prices</span>
    </a>
  </div>

  <!-- Deliveries filters — live in the header so they're always visible and never covered while scrolling -->
  <div class="fm-filter-bar" id="fm-deliv-filters" hidden>
    <form method="GET" action="factory-management.php#deliveries" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="min-width:180px">
        <label>Factory</label>
        <select name="factory">
          <option value="all" <?= $filterFactory === 'all' ? 'selected' : '' ?>>All Factories</option>
          <option value="none" <?= $filterFactory === 'none' ? 'selected' : '' ?>>Unassigned</option>
          <?php foreach ($factories as $f): ?>
          <option value="<?= $f['id'] ?>" <?= (string)$filterFactory === (string)$f['id'] ? 'selected' : '' ?>><?= sanitize($f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Month</label>
        <?php $monthOpts = fmMonthOptions(12); if (!isset($monthOpts[$filterMonth])) $monthOpts[$filterMonth] = date('F Y', strtotime($filterMonth . '-01')); ?>
        <select name="month">
          <?php foreach ($monthOpts as $mVal => $mLabel): ?>
          <option value="<?= $mVal ?>" <?= (!$filterDate && $filterMonth === $mVal) ? 'selected' : '' ?>><?= $mLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Specific Date (optional)</label>
        <input type="date" name="fdate" value="<?= sanitize($filterDate) ?>">
      </div>
      <button type="submit" class="btn btn-primary"><i class="ti ti-filter"></i> Apply Filters</button>
      <a href="factory-management.php#deliveries" class="btn btn-secondary">Reset</a>
    </form>
  </div>
</div>

<!-- ══════════════════════ OVERVIEW ══════════════════════ -->
<div class="fm-panel" id="overview">

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

  <div class="grid-2" style="margin-bottom:20px">
    <!-- Daily trend -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="ti ti-chart-bar"></i> Factory Deliveries (KG) — <?= date('F Y') ?></div>
      </div>
      <?php if ($dailyFactoryKg): ?>
      <div class="mini-chart" style="height:120px;gap:3px">
        <?php foreach ($dailyFactoryKg as $d): ?>
        <div class="mini-bar" style="height:<?= $maxDailyFactoryKg > 0 ? round((float)$d['kg'] / $maxDailyFactoryKg * 100) : 0 ?>%"
             title="<?= fmtDate($d['assignment_date']) ?>: <?= number_format($d['kg'], 1) ?> kg"></div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state"><i class="ti ti-chart-bar-off"></i><p>No confirmed factory weights yet this month</p></div>
      <?php endif; ?>
    </div>

    <!-- Factory performance breakdown -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="ti ti-chart-pie"></i> Factory Performance (This Month)</div>
        <a href="#deliveries" class="card-action" onclick="return fmShowTab('deliveries')">View All</a>
      </div>
      <?php if ($factoryMonthMap): ?>
        <?php foreach ($factories as $f):
          if (empty($factoryMonthMap[$f['id']])) continue;
          $stat  = $factoryMonthMap[$f['id']];
          $share = $ovTotalWeight > 0 ? round($stat['kg'] / $ovTotalWeight * 100) : 0;
        ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
            <span style="font-size:13px;font-weight:600;color:var(--green-900)"><?= sanitize($f['name']) ?></span>
            <span style="font-size:13px;font-weight:700;color:var(--green-700)"><?= money($stat['value']) ?></span>
          </div>
          <div style="height:7px;background:var(--gray-50);border-radius:4px;overflow:hidden;margin-bottom:3px">
            <div style="width:<?= $share ?>%;height:100%;background:linear-gradient(90deg,var(--green-400),var(--green-600));border-radius:4px"></div>
          </div>
          <div style="font-size:11px;color:var(--gray-400)"><?= number_format($stat['kg'], 0) ?> kg · <?= $share ?>% share</div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <div class="empty-state"><i class="ti ti-building-factory-2"></i><p>No confirmed deliveries yet this month</p></div>
      <?php endif; ?>
    </div>
  </div>

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
            <th style="text-align:right">Total KG (This Month)</th><th style="text-align:right">Total Value (LKR)</th>
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
<div class="fm-panel" id="factories" hidden>

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
        <?php if ($editFactory): ?><a href="factory-management.php#factories" class="btn btn-secondary">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="ti ti-list"></i> All Factories</div></div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th><th>Location</th><th style="text-align:right">Price This Month</th>
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
                <a href="factory-management.php?edit_factory=<?= $f['id'] ?>#factories" class="btn btn-outline btn-sm" title="Edit"><i class="ti ti-edit"></i></a>
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
<div class="fm-panel" id="deliveries" hidden>

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
                <input type="hidden" name="ret_month" value="<?= sanitize($filterMonth) ?>">
                <input type="hidden" name="ret_fdate" value="<?= sanitize($filterDate) ?>">
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

<!-- ══════════════════════ MONTHLY PRICES ══════════════════════ -->
<div class="fm-panel" id="prices" hidden>

  <div class="card" style="margin-bottom:16px">
    <form method="GET" action="factory-management.php#prices" style="display:flex;gap:12px;align-items:flex-end">
      <div class="form-group" style="margin-bottom:0">
        <label>Month</label>
        <?php $priceMonthOpts = fmMonthOptions(12, 2); if (!isset($priceMonthOpts[$priceMonthSel])) $priceMonthOpts[$priceMonthSel] = date('F Y', strtotime($priceMonthSel . '-01')); ?>
        <select name="pmonth">
          <?php foreach ($priceMonthOpts as $mVal => $mLabel): ?>
          <option value="<?= $mVal ?>" <?= $priceMonthSel === $mVal ? 'selected' : '' ?>><?= $mLabel ?></option>
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
function fmShowTab(id, el) {
  document.querySelectorAll('.fm-panel').forEach(function(s) { s.hidden = (s.id !== id); });
  document.querySelectorAll('.fm-tab').forEach(function(i) { i.classList.remove('active'); });
  if (!el) el = document.querySelector('.fm-tab[href="#' + id + '"]');
  if (el) el.classList.add('active');
  var delivFilters = document.getElementById('fm-deliv-filters');
  if (delivFilters) delivFilters.hidden = (id !== 'deliveries');
  if (history.replaceState) history.replaceState(null, '', '#' + id);
  return false;
}
(function() {
  var tabs = ['overview', 'factories', 'deliveries', 'prices'];
  var id = window.location.hash ? window.location.hash.slice(1) : '';
  if (tabs.indexOf(id) === -1) id = 'overview';
  fmShowTab(id);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
