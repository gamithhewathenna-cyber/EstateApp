<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Daily Assignments';

// Auth::check() re-reads global role from DB and handles session/estate redirect
Auth::check();
$estateId = Auth::estateId();
$uid      = (int)(Auth::user()['id']);
// Use estate-specific role (active_estate_role) when set, fallback to global role
$isAdmin  = Auth::isAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // SAVE NEW (multi-worker)
    if ($action === 'save') {
        $date    = $_POST['assignment_date'] ?? today();
        $pid     = (int)($_POST['plantation_id'] ?? 0);
        $wtid    = (int)($_POST['work_type_id'] ?? 0);
        $qty     = (float)($_POST['quantity'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');
        $wids    = $_POST['worker_ids'] ?? [];

        // Allow submission with only temp workers (no regular workers required)
        $hasTempWorkers = !empty(array_filter($_POST['temp_name'] ?? [], 'trim'));
        if (!$pid || !$wtid || (empty($wids) && !$hasTempWorkers)) {
            flash('error', 'Please select a plantation, work type, and at least one worker (regular or temporary).');
            redirect('/assignments.php?date=' . $date);
        }
        if (empty($wids)) $wids = []; // temp-only — skip regular worker loop
        $wt      = DB::fetchOne("SELECT rate_per_unit, id, unit_label, name, payment_type FROM work_types WHERE id=?", [$wtid]);
        $rate    = $wt ? (float)$wt['rate_per_unit'] : 0;
        // Detect by unit_label — works across all estates regardless of ID
        $wtUnit       = strtolower(trim($wt['unit_label'] ?? ''));
        $wtName       = strtolower(trim($wt['name'] ?? ''));
        $isPluckingWT = ($wtUnit === 'kg');                          // KG = plucking
        $isSprayingWT = in_array($wtUnit, ['tank','tanks','tree','trees']); // Tank/Tree = manual per-worker qty
        $isAutoUnit   = (!$isPluckingWT && !$isSprayingWT);           // Day/Unit = auto
        // Fixed Amount work types (e.g. Tea Plucking paid a flat Rs. per worker)
        // pay the same amount regardless of quantity — the quantity (KG) is
        // still recorded as-is for production reports.
        $isFixedPay   = (($wt['payment_type'] ?? 'per_unit') === 'fixed');

        $approvalStatus = $isAdmin ? 'approved' : 'pending';
        $approvedBy     = $isAdmin ? $uid : null;
        $approvedAt     = $isAdmin ? date('Y-m-d H:i:s') : null;
        $saved = 0;

        foreach ($wids as $wid) {
            $wid = (int)$wid;
            if (!$wid) continue;

            if ($isAutoUnit) {
                // Clearing, Helper, Basic, etc. = 1 unit per person automatically
                $workerQty = 1;
            } elseif ($isSprayingWT) {
                // Tank Spraying = per-worker tank count, default 1 if not provided
                $workerQty = isset($_POST['worker_qty'][$wid]) && (float)$_POST['worker_qty'][$wid] > 0
                    ? (float)$_POST['worker_qty'][$wid] : 1;
            } else {
                // Tea Plucking = per-worker KG, must be > 0
                $workerQty = isset($_POST['worker_qty'][$wid]) && (float)$_POST['worker_qty'][$wid] > 0
                    ? (float)$_POST['worker_qty'][$wid] : (float)($qty ?: 0);
                if ($workerQty <= 0) continue; // skip workers with no KG entered
            }
            $payment = $isFixedPay ? $rate : round($workerQty * $rate, 2);
            DB::insert("INSERT INTO daily_assignments
                (estate_id,assignment_date,worker_id,plantation_id,work_type_id,quantity,rate,payment,notes,created_by,payment_status,approval_status,approved_by,approved_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$estateId,$date,$wid,$pid,$wtid,$workerQty,$rate,$payment,$notes,$uid,'pending',$approvalStatus,$approvedBy,$approvedAt]);
            $saved++;
        }

        // ── SAVE TEMPORARY / OUTSIDE WORKERS ──
        $tempNames = $_POST['temp_name']  ?? [];
        $tempQtys  = $_POST['temp_qty']   ?? [];

        foreach ($tempNames as $idx => $tempName) {
            $tempName = trim($tempName);
            if (!$tempName) continue;

            // Auto qty based on work type
            if ($isAutoUnit) {
                $tempQty = 1;
            } elseif ($isSprayingWT) {
                $tempQty = isset($tempQtys[$idx]) && (float)$tempQtys[$idx] > 0
                    ? (float)$tempQtys[$idx] : 1;
            } else {
                // Plucking — must have qty
                $tempQty = isset($tempQtys[$idx]) && (float)$tempQtys[$idx] > 0
                    ? (float)$tempQtys[$idx] : 0;
                if ($tempQty <= 0) continue;
            }

            $tempPayment = $isFixedPay ? $rate : round($tempQty * $rate, 2);

            // Save with worker_id = 0 (temp), store name in notes prefixed with TEMP:
            DB::insert("INSERT INTO daily_assignments
                (estate_id,assignment_date,worker_id,plantation_id,work_type_id,quantity,rate,payment,notes,created_by,payment_status,approval_status,approved_by,approved_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$estateId, $date, 0, $pid, $wtid, $tempQty, $rate, $tempPayment,
                 'TEMP:' . $tempName . ($notes ? ' | ' . $notes : ''),
                 $uid, 'pending', $approvalStatus, $approvedBy, $approvedAt]);
            $saved++;
        }

        if ($isAdmin) {
            flash('success', $saved . ' assignment' . ($saved>1?'s':'') . ' saved.');
        } else {
            flash('success', $saved . ' assignment' . ($saved>1?'s':'') . ' submitted for admin approval.');
        }
        redirect('/assignments.php?date=' . $date);
    }

    // UPDATE (admin only)
    if ($action === 'update' && $isAdmin) {
        $id         = (int)($_POST['id'] ?? 0);
        $date       = $_POST['assignment_date'] ?? today();
        $wid        = (int)($_POST['worker_id'] ?? 0);
        $pid        = (int)($_POST['plantation_id'] ?? 0);
        $wtid       = (int)($_POST['work_type_id'] ?? 0);
        $qty        = (float)($_POST['quantity'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $manualRate = (float)($_POST['rate'] ?? 0);
        $editWt     = DB::fetchOne("SELECT rate_per_unit, payment_type FROM work_types WHERE id=?", [$wtid]);
        $rate       = $manualRate > 0 ? $manualRate : (float)($editWt['rate_per_unit'] ?? 0);
        $isFixedPay = (($editWt['payment_type'] ?? 'per_unit') === 'fixed');
        $payment    = $isFixedPay ? $rate : round($qty * $rate, 2);
        DB::execute("UPDATE daily_assignments SET assignment_date=?,worker_id=?,plantation_id=?,work_type_id=?,quantity=?,rate=?,payment=?,notes=?,updated_at=NOW() WHERE id=?",
            [$date,$wid,$pid,$wtid,$qty,$rate,$payment,$notes,$id]);
        flash('success', 'Assignment updated.');
        redirect('/assignments.php?date=' . $date);
    }

    // APPROVE (admin only)
    if ($action === 'approve' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        DB::execute("UPDATE daily_assignments SET approval_status='approved',approved_by=?,approved_at=NOW(),rejection_note=NULL WHERE id=?", [$uid,$id]);
        flash('success', 'Assignment approved.');
        redirect('/assignments.php?tab=pending');
    }

    // REJECT (admin only)
    if ($action === 'reject' && $isAdmin) {
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['rejection_note'] ?? '');
        DB::execute("UPDATE daily_assignments SET approval_status='rejected',approved_by=?,approved_at=NOW(),rejection_note=? WHERE id=?", [$uid,$note,$id]);
        flash('success', 'Assignment rejected.');
        redirect('/assignments.php?tab=pending');
    }

    // DELETE (admin only)
    if ($action === 'delete' && $isAdmin) {
        $redirect = $_POST['redirect'] ?? ('/assignments.php?date=' . today());
        DB::execute("DELETE FROM daily_assignments WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        flash('success', 'Assignment removed.');
        header('Location: ' . BASE_URL . $redirect); exit;
    }

    // TOGGLE STATUS (admin only)
    if ($action === 'toggle_status' && $isAdmin) {
        $id       = (int)($_POST['id'] ?? 0);
        $redirect = $_POST['redirect'] ?? ('/assignments.php?date=' . today());
        $current  = DB::fetchOne("SELECT payment_status FROM daily_assignments WHERE id=?", [$id]);
        $newStatus = ($current && $current['payment_status'] === 'paid') ? 'pending' : 'paid';
        DB::execute("UPDATE daily_assignments SET payment_status=? WHERE id=?", [$newStatus, $id]);
        flash('success', 'Payment marked as ' . ucfirst($newStatus) . '.');
        header('Location: ' . BASE_URL . $redirect); exit;
    }

    // ── BULK PAYMENT STATUS ────────────────────────────────
    if ($action === 'bulk_payment' && $isAdmin) {
        $ids      = $_POST['bulk_ids'] ?? [];
        $status   = $_POST['bulk_status'] ?? 'paid';
        $redirect = $_POST['redirect']   ?? ('/assignments.php?date=' . today());
        if (!in_array($status, ['paid','pending'])) $status = 'paid';
        if (!empty($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::execute("UPDATE daily_assignments SET payment_status=? WHERE id IN ($placeholders) AND estate_id=?",
                array_merge([$status], $ids, [$estateId]));
            flash('success', count($ids) . ' assignment(s) marked as ' . ucfirst($status) . '.');
        }
        header('Location: ' . BASE_URL . $redirect); exit;
    }
}

// View params
$tab          = $_GET['tab']  ?? 'day';   // day | range | pending | costs
$selectedDate = $_GET['date'] ?? today();
$dateFrom     = $_GET['from'] ?? date('Y-m-01');
$dateTo       = $_GET['to']   ?? today();
$editId       = (int)($_GET['edit'] ?? 0);
$editRow      = ($editId && $isAdmin) ? DB::fetchOne("SELECT * FROM daily_assignments WHERE id=?", [$editId]) : null;

$workers     = DB::fetchAll("SELECT * FROM workers WHERE estate_id=? AND is_active=1 ORDER BY full_name", [$estateId]);
$plantations = DB::fetchAll("SELECT * FROM plantations WHERE estate_id=? AND is_active=1 ORDER BY name", [$estateId]);
$workTypes   = DB::fetchAll("SELECT * FROM work_types WHERE estate_id=? AND is_active=1 AND is_deleted=0 ORDER BY name ASC", [$estateId]);

// Pending approvals count
$pendingCount = DB::fetchOne("SELECT COUNT(*) as cnt FROM daily_assignments WHERE estate_id=? AND approval_status='pending'", [$estateId])['cnt'] ?? 0;

// Fetch assignments
// Name expression: temp workers stored with worker_id=0, regular workers with worker_id>0
$nameExpr = "COALESCE(w.full_name, TRIM(REPLACE(SUBSTRING_INDEX(IFNULL(da.notes,''),'|',1),'TEMP:','')), '') as full_name,
        CASE WHEN (da.worker_id IS NULL OR da.worker_id = 0) THEN 1 ELSE 0 END as is_temp";

if ($tab === 'range') {
    $assignments = DB::fetchAll("SELECT da.*, $nameExpr,
        p.name as plantation_name, wt.name as work_type_name, wt.unit_label
        FROM daily_assignments da
        LEFT JOIN workers w ON da.worker_id=w.id
        JOIN plantations p ON da.plantation_id=p.id
        JOIN work_types wt ON da.work_type_id=wt.id
        WHERE da.estate_id=? AND da.assignment_date BETWEEN ? AND ? AND da.approval_status='approved'
        ORDER BY da.assignment_date DESC, da.id DESC", [$estateId, $dateFrom, $dateTo]);
} elseif ($tab === 'pending') {
    $assignments = DB::fetchAll("SELECT da.*, $nameExpr,
        p.name as plantation_name, wt.name as work_type_name, wt.unit_label,
        u.name as created_by_name
        FROM daily_assignments da
        LEFT JOIN workers w ON da.worker_id=w.id
        JOIN plantations p ON da.plantation_id=p.id
        JOIN work_types wt ON da.work_type_id=wt.id
        LEFT JOIN users u ON da.created_by=u.id
        WHERE da.estate_id=? AND da.approval_status='pending'
        ORDER BY da.assignment_date DESC, da.id DESC", [$estateId]);
} else {
    if ($isAdmin) {
        $assignments = DB::fetchAll("SELECT da.*, $nameExpr,
            p.name as plantation_name, wt.name as work_type_name, wt.unit_label
            FROM daily_assignments da
            LEFT JOIN workers w ON da.worker_id=w.id
            JOIN plantations p ON da.plantation_id=p.id
            JOIN work_types wt ON da.work_type_id=wt.id
            WHERE da.estate_id=? AND da.assignment_date=? AND da.approval_status='approved'
            ORDER BY da.id DESC", [$estateId, $selectedDate]);
    } else {
        $assignments = DB::fetchAll("SELECT da.*, $nameExpr,
            p.name as plantation_name, wt.name as work_type_name, wt.unit_label
            FROM daily_assignments da
            LEFT JOIN workers w ON da.worker_id=w.id
            JOIN plantations p ON da.plantation_id=p.id
            JOIN work_types wt ON da.work_type_id=wt.id
            WHERE da.estate_id=? AND da.assignment_date=? AND (da.approval_status='approved' OR (da.approval_status='pending' AND da.created_by=?))
            ORDER BY da.id DESC", [$estateId, $selectedDate, $uid]);
    }
}

$totalPay = array_sum(array_column(array_filter($assignments, fn($a)=>($a['approval_status']??'approved')==='approved'), 'payment'));
$totalKg  = array_sum(array_column(array_filter($assignments, fn($a)=>(strtolower($a['unit_label']??'')==='kg') && ($a['approval_status']??'approved')==='approved'), 'quantity'));

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ═══════════════════════════════════════════
   ASSIGNMENTS PAGE — FULL MOBILE RESPONSIVE
   ═══════════════════════════════════════════ */

/* Main two-column grid */
.assign-main-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  align-items: start;
  min-width: 0;
}
.assign-form-col, .assign-list-col { min-width: 0; }

/* A flex/grid item's default min-width is "auto", which resolves to its
   content's full intrinsic width — so a long worker name (or any long text
   in a flex:1 cell) can silently force its row, and the whole page, wider
   than the viewport, even though everything visually looks like it has
   width:100%. min-width:0 lets these actually shrink/truncate instead. */
#worker-list label { min-width: 0; }
#worker-list label > * { min-width: 0; }
.assign-item, .assign-item > * { min-width: 0; }
.assign-info { min-width: 0; }

/* Status badges */
.approval-pending  { background:#fffbeb; border-left:3px solid #f59e0b; }
.approval-rejected { background:#fff1f2; border-left:3px solid #f43f5e; opacity:.8; }
.badge-pending  { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; gap:3px; }
.badge-rejected { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; gap:3px; }
.badge-approved { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; gap:3px; }

/* Mobile "New Assignment" toggle button — removed */
.mobile-form-toggle { display: none !important; }

/* Tab nav — always horizontal-scroll, never wraps */
.assign-tabs {
  display: flex; gap: 2px;
  background: var(--gray-50);
  border-radius: var(--radius-md);
  padding: 3px; margin-bottom: 14px;
  overflow-x: auto; -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.assign-tabs::-webkit-scrollbar { display: none; }
.assign-tabs .tab-btn {
  flex: 1; flex-shrink: 0; flex-basis: auto;
  white-space: nowrap; text-decoration: none; text-align: center;
  min-width: 0;
}

/* ── TABLET LANDSCAPE (≤1000px) ── */
@media (max-width: 1000px) {
  .assign-main-grid { grid-template-columns: 1fr !important; }
  /* Form on top, list below */
  .assign-form-col { order: 1; }
  .assign-list-col { order: 2; }
  /* Shrink worker list height on tablet to save space */
  #worker-list { max-height: 160px !important; }
}

/* ── LARGE PHONE (≤600px) ── */
@media (max-width: 600px) {
  /* Form panel */
  .form-panel { padding: 14px !important; }

  /* Date pickers render oversized on mobile (esp. iOS, which inflates the
     native date widget's height/text) — shrink padding/font/line-height to
     bring it in line with the other form fields. */
  input[type="date"] {
    font-size: 12px !important;
    padding: 4px 6px !important;
    line-height: 1.2 !important;
    height: 30px !important;
    width: calc(100% - 15px) !important;
    max-width: calc(100% - 15px) !important;
    box-sizing: border-box;
    text-align: center;
  }
  input[type="date"]::-webkit-date-and-time-value {
    text-align: center;
    min-height: auto;
  }
  .edit-form-grid { grid-template-columns: 1fr !important; }

  /* Work type cards */
  .work-type-grid { grid-template-columns: 1fr 1fr !important; gap: 6px; }
  .wt-name { font-size: 12px; }
  .wt-rate { font-size: 10px; }
  .wt-card { padding: 8px 10px; }

  /* Worker list */
  #worker-list label { padding: 8px 10px; }
  #worker-list .avatar { display: none; }

  /* Range date inputs — stack vertically */
  .range-date-inputs { flex-direction: column !important; gap: 8px !important; align-items: stretch !important; }
  .range-date-inputs > div { width: 100% !important; min-width: 100% !important; }
  .range-date-inputs input[type=date],
  .range-date-inputs button { width: 100%; }

  /* Shortcut buttons */
  .shortcut-btns { flex-wrap: wrap; gap: 4px; }
  .shortcut-btns a { font-size: 11px; padding: 4px 8px; }

  /* ── SINGLE DAY: assignment row ── */
  .assign-item {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 4px 8px;
    padding: 10px 0;
    align-items: flex-start;
  }
  .assign-item .avatar { flex-shrink: 0; align-self: center; }
  .assign-item .assign-info { flex: 1; min-width: 0; }
  .assign-item .assign-pay { flex-shrink: 0; font-size: 13px; font-weight: 700; color: var(--green-600); }
  .assign-name { font-size: 12px; }
  .assign-sub  { font-size: 10px; }
  /* Admin actions row pushed to new line */
  .assign-actions {
    flex: 0 0 100%;
    display: flex; gap: 4px; justify-content: flex-end;
    padding-top: 6px; flex-wrap: wrap;
  }

  /* ── DATE RANGE: day header ── */
  .range-day-header {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 6px !important;
  }
  .range-day-right {
    align-self: stretch;
    display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
  }

  /* Date range table */
  .assign-table-wrap { font-size: 11px; }
  .assign-table-wrap th,
  .assign-table-wrap td { padding: 5px 6px !important; }
  .hide-mobile { display: none !important; }

  /* ── APPROVALS: card layout ── */
  .approval-card-body {
    flex-wrap: wrap !important;
    gap: 10px;
  }
  .approval-right {
    flex: 0 0 100% !important;
    text-align: left !important;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    padding-top: 10px;
    border-top: 1px solid #fde68a;
  }
  .approval-right .pay-amt { font-size: 15px; font-weight: 700; }
  .approval-right .approve-btns { margin-left: auto; display: flex; gap: 5px; }

  /* Summary pills */
  .summary-pills { flex-wrap: wrap; gap: 5px; }

  /* Calc box */
  .calc-box { flex-direction: column; gap: 6px; text-align: center; }
  .calc-amount { font-size: 18px !important; }

  /* Bulk bar */
  #bulk-bar { flex-direction: column; align-items: stretch !important; }
  #bulk-bar form { flex-direction: column; gap: 8px !important; }
  #bulk-bar .btn { width: 100%; justify-content: center; }
}

/* ── SMALL PHONE (≤400px) ── */
@media (max-width: 400px) {
  .work-type-grid { grid-template-columns: 1fr 1fr !important; }
  .assign-item { font-size: 11px; }
  .assign-tabs .tab-btn { font-size: 10px; padding: 5px 8px; }

  /* Temporary worker rows (built via JS with inline styles) — the name
     input's inline min-width:140px plus the qty input/badge/remove button
     can be too wide to fit together on very small screens. */
  #temp-workers-list input[name="temp_name[]"] { min-width: 90px !important; }
}
</style>

<!-- SUPERVISOR NOTICE -->
<?php if (!$isAdmin): ?>
<div class="alert" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;margin-bottom:16px">
  <i class="ti ti-info-circle"></i>
  You are logged in as <strong>Supervisor</strong>. Your assignments will be sent to admin for approval before appearing in the main list.
</div>
<?php endif; ?>

<div class="assign-main-grid">

<!-- LEFT: FORM -->
<div class="assign-form-col" id="assign-form-col">
  <?php if ($editRow && $isAdmin): ?>
  <!-- EDIT FORM -->
  <div class="form-panel" style="border:2px solid var(--amber-200)">
    <div class="form-panel-title" style="color:var(--amber-600)">
      <i class="ti ti-edit"></i> Edit Assignment
      <a href="assignments.php?date=<?= $selectedDate ?>" class="btn btn-outline btn-sm" style="margin-left:auto"><i class="ti ti-x"></i> Cancel</a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
      <input type="hidden" name="assignment_date" value="<?= $selectedDate ?>">
      <div class="grid-form edit-form-grid" style="margin-bottom:14px">
        <div class="form-group col-full">
          <label>Worker *</label>
          <select name="worker_id" required>
            <?php foreach ($workers as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $w['id']==$editRow['worker_id']?'selected':'' ?>><?= sanitize($w['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Plantation *</label>
          <select name="plantation_id" required>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id']==$editRow['plantation_id']?'selected':'' ?>><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Work Type *</label>
          <select name="work_type_id" required onchange="updateEditRate(this)">
            <?php foreach ($workTypes as $wt): ?>
            <option value="<?= $wt['id'] ?>" data-rate="<?= $wt['rate_per_unit'] ?>" data-unit="<?= sanitize($wt['unit_label']) ?>"
                    <?= $wt['id']==$editRow['work_type_id']?'selected':'' ?>><?= sanitize($wt['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Quantity (<span id="edit-unit-label"><?= sanitize(DB::fetchOne("SELECT unit_label FROM work_types WHERE id=?",[$editRow['work_type_id']])['unit_label']??'Unit') ?></span>) *</label>
          <input type="number" name="quantity" id="edit-qty" value="<?= $editRow['quantity'] ?>" min="0" step="0.01" required oninput="calcEditPayment()">
        </div>
        <div class="form-group">
          <label>Rate per Unit (Rs.) *</label>
          <input type="number" name="rate" id="edit-rate" value="<?= $editRow['rate'] ?>" min="0" step="0.01" required oninput="calcEditPayment()" style="border-color:var(--amber-400)">
          <div style="font-size:11px;color:var(--amber-600);margin-top:3px">Override rate — won't affect other records</div>
        </div>
        <div class="form-group col-full"><label>Notes</label><input type="text" name="notes" value="<?= sanitize($editRow['notes']) ?>"></div>
      </div>
      <div class="calc-box" style="background:var(--amber-50);border-color:var(--amber-100)">
        <div class="calc-label" style="color:var(--amber-800)"><i class="ti ti-calculator"></i> Updated Payment</div>
        <div class="calc-amount" id="edit-calc-result" style="color:var(--amber-600)"><?= money($editRow['payment']) ?></div>
      </div>
      <div class="btn-group" style="margin-top:14px">
        <button type="submit" class="btn btn-primary" style="background:var(--amber-600)"><i class="ti ti-check"></i> Update Assignment</button>
        <a href="assignments.php?date=<?= $selectedDate ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- NEW ASSIGNMENT FORM -->
  <div class="form-panel">
    <div class="form-panel-title">
      <i class="ti ti-clipboard-plus"></i> New Assignment
      <?php if (!$isAdmin): ?>
      <span style="margin-left:auto;font-size:11px;background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:20px;font-weight:700">
        <i class="ti ti-send" style="font-size:11px"></i> Pending Approval
      </span>
      <?php endif; ?>
    </div>
    <form method="POST" id="assign-form">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="work_type_id" id="a-wt-id" value="<?= $workTypes[0]['id'] ?? 1 ?>">
      <div class="grid-form" style="margin-bottom:16px">
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="assignment_date" value="<?= sanitize($selectedDate) ?>" required>
        </div>
        <div class="form-group">
          <label>Plantation *</label>
          <select name="plantation_id" required>
            <?php foreach ($plantations as $p): ?>
            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="font-size:12px;font-weight:600;color:var(--gray-600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Work Type</div>
      <div class="work-type-grid" style="margin-bottom:16px">
        <?php foreach ($workTypes as $i => $wt): ?>
        <div class="wt-card <?= $i===0?'selected':'' ?>"
             onclick="selectWT(this,<?= $wt['id'] ?>,<?= $wt['rate_per_unit'] ?>,'<?= sanitize($wt['unit_label']) ?>','<?= strtolower(sanitize($wt['unit_label'])) ?>','<?= ($wt['payment_type']??'per_unit')==='fixed'?'fixed':'per_unit' ?>')">
          <div class="wt-name"><?= sanitize($wt['name']) ?></div>
          <div class="wt-rate">
            <?php if (($wt['payment_type']??'per_unit')==='fixed'): ?>
              Rs. <?= number_format($wt['rate_per_unit'],0) ?> fixed / worker
            <?php else: ?>
              Rs. <?= number_format($wt['rate_per_unit'],0) ?> / <?= sanitize($wt['unit_label']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label style="font-size:12px;font-weight:600;color:var(--gray-600);text-transform:uppercase;letter-spacing:.04em">Select Workers *</label>
          <div style="display:flex;gap:6px">
            <button type="button" onclick="selectAllWorkers()" class="btn btn-outline btn-sm"><i class="ti ti-checks"></i> All</button>
            <button type="button" onclick="clearAllWorkers()" class="btn btn-outline btn-sm"><i class="ti ti-x"></i> Clear</button>
          </div>
        </div>
        <div class="search-wrap" style="margin-bottom:8px">
          <i class="ti ti-search"></i>
          <input type="text" placeholder="Search workers..." oninput="filterWorkerList(this.value)" style="width:100%">
        </div>
        <div id="worker-list" style="border:1px solid #d8ddd5;border-radius:var(--radius-md);max-height:200px;overflow-y:auto">
          <?php foreach ($workers as $w): ?>
          <div class="worker-check-row" data-name="<?= strtolower(sanitize($w['full_name'])) ?>">
            <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid #f0f0eb"
                   onmouseover="this.style.background='#f8faf5'" onmouseout="this.style.background=''">
              <input type="checkbox" name="worker_ids[]" value="<?= $w['id'] ?>"
                     style="width:16px;height:16px;accent-color:var(--green-600);cursor:pointer"
                     onchange="toggleWorkerQty(this,<?= $w['id'] ?>)">
              <div class="avatar" style="width:28px;height:28px;font-size:11px;flex-shrink:0"><?= initials($w['full_name']) ?></div>
              <span style="font-size:13px;font-weight:500;flex:1"><?= sanitize($w['full_name']) ?></span>
              <!-- Tank qty input (only shown for Tank Spraying) -->
              <div class="worker-qty-wrap" id="wqty-<?= $w['id'] ?>" style="display:none;align-items:center;gap:5px" onclick="event.stopPropagation()">
                <span class="unit-badge" style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--teal-50);color:var(--teal-700);border:1px solid var(--teal-100);white-space:nowrap">Tanks</span>
                <input type="number" name="worker_qty[<?= $w['id'] ?>]" id="wqty-input-<?= $w['id'] ?>"
                       placeholder="1" value="1" min="1" step="1"
                       style="width:55px;padding:4px 8px;font-size:12px;border:1px solid #d8ddd5;border-radius:6px"
                       oninput="calcTotal()">
              </div>
              <!-- Auto badge (shown for non-spray, non-plucking types) -->
              <div class="worker-auto-badge" id="wbadge-<?= $w['id'] ?>" style="display:none">
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:var(--green-50);color:var(--green-700);border:1px solid var(--green-200)">1 Unit ✓</span>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:12px;color:var(--gray-400);margin-top:6px"><span id="selected-count">0</span> worker(s) selected</div>
      </div>
      <!-- Quantity is handled per-worker or auto (hidden field keeps compatibility) -->
      <input type="hidden" name="quantity" id="a-qty" value="1">
      <div style="font-size:11px;color:var(--gray-400);margin-top:-8px;margin-bottom:4px" id="qty-hint"></div>
      <!-- ── TEMPORARY / OUTSIDE WORKERS ── -->
      <div style="margin-top:16px;border-top:1px dashed #d8ddd5;padding-top:14px;margin-bottom:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="font-size:12px;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:7px">
            <i class="ti ti-user-plus" style="color:var(--amber-600);font-size:16px"></i>
            Temporary / Outside Workers
            <span style="font-size:10px;font-weight:400;color:var(--gray-400);text-transform:none">(Optional)</span>
          </div>
          <button type="button" class="btn btn-outline btn-sm" onclick="addTempWorker()" style="color:var(--amber-600);border-color:var(--amber-300)">
            <i class="ti ti-plus"></i> Add
          </button>
        </div>
        <div id="temp-workers-list" style="display:flex;flex-direction:column;gap:8px"></div>
        <div id="temp-workers-empty" style="font-size:12px;color:var(--gray-400);padding:4px 0;display:none">
          No temporary workers added yet.
        </div>
      </div>

      <div class="calc-box" style="margin-top:0">
        <div class="calc-label"><i class="ti ti-calculator"></i> Estimated Total Payroll</div>
        <div class="calc-amount" id="calc-result">Rs. 0</div>
      </div>
      <div style="font-size:11px;color:var(--gray-400);margin-top:4px;text-align:right" id="per-worker-note"></div>
      <div class="form-group" style="margin:14px 0">
        <label>Notes (Optional)</label>
        <input type="text" name="notes" placeholder="e.g. North section clearing...">
      </div>

      <div class="btn-group" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-<?= $isAdmin ? 'check' : 'send' ?>"></i>
          <?= $isAdmin ? 'Save Assignment' : 'Submit for Approval' ?>
        </button>
        <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>

<!-- RIGHT: ASSIGNMENT LIST -->
<div class="assign-list-col">
  <div class="card">
    <!-- TABS -->
    <div class="assign-tabs">
      <a class="tab-btn <?= $tab==='day'?'active':'' ?>" href="assignments.php?tab=day&date=<?= $selectedDate ?>">
        <i class="ti ti-calendar-event" style="font-size:13px;vertical-align:-2px"></i> Single Day
      </a>
      <a class="tab-btn <?= $tab==='range'?'active':'' ?>" href="assignments.php?tab=range&from=<?= $dateFrom ?>&to=<?= $dateTo ?>">
        <i class="ti ti-calendar-stats" style="font-size:13px;vertical-align:-2px"></i> Date Range
      </a>
      <?php if ($isAdmin): ?>
      <a class="tab-btn <?= $tab==='pending'?'active':'' ?>" href="assignments.php?tab=pending" style="position:relative">
        <i class="ti ti-clock-check" style="font-size:13px;vertical-align:-2px"></i> Approvals
        <?php if ($pendingCount > 0): ?>
        <span style="background:var(--red-400);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:20px;margin-left:4px"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
    </div>

    <!-- SINGLE DAY CONTROLS -->
    <?php if ($tab === 'day'): ?>
    <div style="display:flex;gap:6px;margin-bottom:12px;align-items:center">
      <a href="assignments.php?tab=day&date=<?= date('Y-m-d',strtotime($selectedDate.'-1 day')) ?>" class="btn btn-outline btn-sm"><i class="ti ti-chevron-left"></i></a>
      <input type="date" value="<?= $selectedDate ?>" onchange="location.href='assignments.php?tab=day&date='+this.value"
             style="flex:1;font-size:13px;padding:6px 10px;border:1px solid #d8ddd5;border-radius:var(--radius-md)">
      <a href="assignments.php?tab=day&date=<?= date('Y-m-d',strtotime($selectedDate.'+1 day')) ?>" class="btn btn-outline btn-sm"><i class="ti ti-chevron-right"></i></a>
    </div>

    <!-- DATE RANGE CONTROLS — MOBILE RESPONSIVE -->
    <?php elseif ($tab === 'range'): ?>
    <form method="GET" style="margin-bottom:12px">
      <input type="hidden" name="tab" value="range">
      <div class="range-date-inputs" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:8px">
        <div style="flex:1;min-width:120px">
          <div style="font-size:10px;font-weight:700;color:var(--gray-500);margin-bottom:3px">FROM</div>
          <input type="date" name="from" value="<?= $dateFrom ?>" style="width:100%;font-size:13px;padding:7px 10px;border:1px solid #d8ddd5;border-radius:var(--radius-md)">
        </div>
        <div style="flex:1;min-width:120px">
          <div style="font-size:10px;font-weight:700;color:var(--gray-500);margin-bottom:3px">TO</div>
          <input type="date" name="to" value="<?= $dateTo ?>" style="width:100%;font-size:13px;padding:7px 10px;border:1px solid #d8ddd5;border-radius:var(--radius-md)">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap"><i class="ti ti-search"></i> View</button>
      </div>
      <div class="shortcut-btns" style="display:flex;gap:5px;flex-wrap:wrap">
        <?php foreach(['Today'=>[today(),today()],'This Week'=>[date('Y-m-d',strtotime('monday this week')),today()],'This Month'=>[date('Y-m-01'),today()],'Last Month'=>[date('Y-m-01',strtotime('first day of last month')),date('Y-m-t',strtotime('last day of last month'))]] as $lbl=>[$f,$t]): $active=($dateFrom===$f&&$dateTo===$t); ?>
        <a href="assignments.php?tab=range&from=<?= $f ?>&to=<?= $t ?>" class="btn btn-outline btn-sm" style="<?= $active?'background:var(--green-50);border-color:var(--green-400);color:var(--green-800)':'' ?>;font-size:11px"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </form>

    <!-- PENDING APPROVALS tab -->
    <?php elseif ($tab === 'pending' && $isAdmin): ?>
    <div style="font-size:13px;color:var(--gray-500);margin-bottom:12px">
      <?= $pendingCount ?> assignment<?= $pendingCount!=1?'s':'' ?> waiting for approval
    </div>
    <?php endif; ?>

    <!-- SUMMARY PILLS -->
    <?php if ($tab !== 'pending'): ?>
    <div class="summary-pills" style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap">
      <span class="pill pill-green"><i class="ti ti-users" style="font-size:12px"></i> <?= count(array_unique(array_column($assignments,'worker_id'))) ?> workers</span>
      <span class="pill pill-teal"><i class="ti ti-weight" style="font-size:12px"></i> <?= number_format($totalKg,1) ?> kg</span>
      <span class="pill pill-amber"><i class="ti ti-cash" style="font-size:12px"></i> <?= money($totalPay) ?></span>
      <span class="pill pill-gray"><i class="ti ti-list" style="font-size:12px"></i> <?= count($assignments) ?> records</span>
    </div>
    <!-- Bulk payment bar (admin only, shown when assignments exist) -->
    <?php if ($isAdmin && !empty($assignments)): ?>
    <div id="bulk-bar" style="display:none;background:var(--green-50);border:1px solid var(--green-200);border-radius:var(--radius-md);padding:8px 12px;margin-bottom:10px;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-size:12px;font-weight:700;color:var(--green-800)" id="bulk-count">0 selected</span>
      <form method="POST" id="bulk-form" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="action" value="bulk_payment">
        <input type="hidden" name="redirect" value="/assignments.php?tab=<?= $tab ?>&<?= $tab==='range'?'from='.$dateFrom.'&to='.$dateTo:'date='.$selectedDate ?>">
        <div id="bulk-ids-container"></div>
        <button type="submit" name="bulk_status" value="paid"
                class="btn btn-sm" style="background:var(--green-600);color:#fff;padding:6px 14px">
          <i class="ti ti-circle-check"></i> Mark Selected as Paid
        </button>
        <button type="submit" name="bulk_status" value="pending"
                class="btn btn-outline btn-sm" style="color:var(--gray-600);padding:6px 12px">
          <i class="ti ti-clock"></i> Mark as Pending
        </button>
        <button type="button" onclick="clearBulkSelection()" class="btn btn-outline btn-sm" style="color:var(--gray-400)">
          <i class="ti ti-x"></i> Clear
        </button>
      </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ASSIGNMENT LISTS -->
    <div style="max-height:500px;overflow-y:auto">

    <?php if (!$assignments): ?>
      <div class="empty-state">
        <i class="ti ti-clipboard"></i>
        <p><?= $tab==='pending' ? 'No pending approvals.' : 'No assignments found.' ?></p>
      </div>

    <?php elseif ($tab === 'pending' && $isAdmin): ?>
      <!-- PENDING APPROVALS LIST -->
      <?php foreach ($assignments as $a): ?>
      <div class="assign-item approval-pending approval-card-body" style="border-radius:var(--radius-md);margin-bottom:8px;padding:10px">
        <div class="avatar" style="width:32px;height:32px;font-size:11px;flex-shrink:0"><?= initials($a['full_name']) ?></div>
        <div class="assign-info" style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <span class="assign-name"><?= sanitize($a['full_name']) ?></span>
            <span class="badge-pending"><i class="ti ti-clock" style="font-size:10px"></i> Pending</span>
          </div>
          <div class="assign-sub"><?= fmtDate($a['assignment_date']) ?> · <?= sanitize($a['plantation_name']) ?> · <?= sanitize($a['work_type_name']) ?> · <?= number_format($a['quantity'],2) ?> <?= sanitize($a['unit_label']) ?></div>
          <?php if (!empty($a['notes'])): ?>
          <div style="font-size:11px;color:var(--amber-600);margin-top:2px"><i class="ti ti-notes" style="font-size:11px"></i> <?= sanitize($a['notes']) ?></div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--gray-400)">Submitted by: <strong><?= sanitize($a['created_by_name'] ?? 'Unknown') ?></strong></div>
        </div>
        <div class="approval-right" style="text-align:right;flex-shrink:0">
          <div class="pay-amt" style="font-size:14px;font-weight:700;color:var(--green-600);margin-bottom:6px"><?= money($a['payment']) ?></div>
          <div class="approve-btns" style="display:flex;gap:5px">
            <!-- Approve -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#065f46;color:#fff;padding:5px 10px">
                <i class="ti ti-check"></i> Approve
              </button>
            </form>
            <!-- Reject -->
            <button type="button" class="btn btn-outline btn-sm" style="color:var(--red-400)"
                    onclick="toggleRejectForm('rf-<?= $a['id'] ?>')">
              <i class="ti ti-x"></i> Reject
            </button>
          </div>
          <!-- Inline reject form - expands on click -->
          <div id="rf-<?= $a['id'] ?>" style="display:none;margin-top:10px;padding:10px;background:#fff1f2;border-radius:var(--radius-md);border:1px solid #fda4af">
            <form method="POST">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <div class="form-group" style="margin-bottom:10px">
                <label style="font-size:12px;color:var(--red-600)">Reason for rejection (optional)</label>
                <textarea name="rejection_note" rows="2" placeholder="e.g. Wrong KG amount..." style="width:100%;font-size:13px;padding:7px 10px;border:1px solid #fda4af;border-radius:var(--radius-md);resize:none"></textarea>
              </div>
              <div style="display:flex;gap:6px">
                <button type="submit" class="btn btn-danger btn-sm"><i class="ti ti-x"></i> Confirm Reject</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRejectForm('rf-<?= $a['id'] ?>')">Cancel</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

    <?php elseif ($tab === 'day'): ?>
      <!-- SINGLE DAY LIST -->
      <?php if ($isAdmin && !empty($assignments)): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:5px 2px;border-bottom:1px solid #f0f0eb;margin-bottom:8px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;color:var(--gray-600)">
          <input type="checkbox" id="select-all-day" onchange="toggleSelectAll(this,'day')"
                 style="width:16px;height:16px;accent-color:var(--green-600);cursor:pointer">
          Select All
        </label>
        <span style="font-size:11px;color:var(--gray-400)">— check to bulk mark payment</span>
      </div>
      <?php endif; ?>
      <?php $grouped=[]; foreach($assignments as $a) $grouped[$a['work_type_name']][]=$a;
      foreach($grouped as $wtName=>$group): ?>
      <div style="margin-bottom:12px">
        <div style="font-size:11px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.06em;padding:5px 0;border-bottom:1px solid #f0f0eb;margin-bottom:4px;display:flex;justify-content:space-between">
          <span><?= sanitize($wtName) ?></span>
          <span><?= count($group) ?> worker<?= count($group)>1?'s':'' ?> · <?= money(array_sum(array_column($group,'payment'))) ?></span>
        </div>
        <?php foreach($group as $a): $isPaid=($a['payment_status']==='paid'); $isEditing=($editId===(int)$a['id']); ?>
        <div class="assign-item" style="<?= $isEditing?'background:var(--amber-50);border-radius:8px;padding:4px 8px;':($isPaid?'background:#f0fdf4;border-radius:8px;padding:4px 8px;':'') ?>">
          <?php if ($isAdmin): ?>
          <input type="checkbox" class="bulk-check-day" value="<?= $a['id'] ?>"
                 onchange="updateBulkSelection()"
                 style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer;flex-shrink:0;margin-right:2px">
          <?php endif; ?>
          <div class="avatar" style="width:30px;height:30px;font-size:11px;flex-shrink:0"><?= initials($a['full_name']) ?></div>
          <div class="assign-info" style="min-width:0;flex:1">
            <div class="assign-name" style="display:flex;align-items:center;gap:6px">
              <?= sanitize($a['full_name']) ?>
              <?php if (!empty($a['is_temp'])): ?>
              <span style="font-size:9px;font-weight:700;background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200);padding:1px 6px;border-radius:10px;white-space:nowrap">TEMP</span>
              <?php endif; ?>
            </div>
            <div class="assign-sub"><?= sanitize($a['plantation_name']) ?> · <?= number_format($a['quantity'],2) ?> <?= sanitize($a['unit_label']) ?> · Rs.<?= number_format($a['rate'],0) ?>/<?= sanitize($a['unit_label']) ?></div>
            <?php
            // For temp workers notes are stored as "TEMP:name | actual notes"
            // Extract the actual notes part (after |) or show full notes for regular workers
            $displayNote = '';
            if (!empty($a['notes'])) {
              if (!empty($a['is_temp']) && str_starts_with($a['notes'], 'TEMP:')) {
                // Extract part after the | separator
                $parts = explode('|', $a['notes'], 2);
                $displayNote = isset($parts[1]) ? trim($parts[1]) : '';
              } else {
                $displayNote = $a['notes'];
              }
            }
            ?>
            <?php if (!empty($displayNote)): ?>
            <div style="font-size:11px;color:var(--amber-600);margin-top:2px">
              <i class="ti ti-notes" style="font-size:12px"></i> <?= sanitize($displayNote) ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="assign-pay" style="flex-shrink:0"><?= money($a['payment']) ?></div>
          <?php if($isAdmin): ?>
          <div class="assign-actions">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <input type="hidden" name="redirect" value="/assignments.php?tab=day&date=<?= $selectedDate ?>">
              <button type="submit" title="Toggle payment" style="border:none;cursor:pointer;border-radius:20px;padding:3px 9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;<?= $isPaid?'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7':'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5' ?>">
                <i class="ti ti-<?= $isPaid?'circle-check':'clock' ?>" style="font-size:11px"></i>
                <?= $isPaid?'Paid':'Pending' ?>
              </button>
            </form>
            <a href="assignments.php?tab=day&date=<?= $selectedDate ?>&edit=<?= $a['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="ti ti-edit"></i></a>
            <form method="POST" onsubmit="return confirm('Remove?')" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <input type="hidden" name="redirect" value="/assignments.php?tab=day&date=<?= $selectedDate ?>">
              <button type="submit" class="assign-del"><i class="ti ti-trash"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

    <?php else: ?>
      <!-- DATE RANGE TABLE — MOBILE RESPONSIVE -->
      <?php $byDate=[];foreach($assignments as $a)$byDate[$a['assignment_date']][]=$a;
      foreach($byDate as $date=>$dayRows):
        $dayPay=$dayPaid=$dayPending=0;
        foreach($dayRows as $r){$dayPay+=$r['payment'];if($r['payment_status']==='paid')$dayPaid+=$r['payment'];else $dayPending+=$r['payment'];}
        $dayKg=array_sum(array_column(array_filter($dayRows,fn($r)=>strtolower($r['unit_label']??'')==='kg'),'quantity'));
      ?>
      <div style="margin-bottom:14px">
        <div class="range-day-header" style="display:flex;align-items:center;justify-content:space-between;background:var(--green-50);border-radius:8px;padding:8px 12px;margin-bottom:6px;cursor:pointer;gap:6px"
             onclick="toggleDay('day-<?= str_replace('-','',$date) ?>')">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0">
            <i class="ti ti-calendar" style="color:var(--green-600);font-size:15px;flex-shrink:0"></i>
            <span style="font-size:13px;font-weight:700;color:var(--green-900);white-space:nowrap"><?= fmtDate($date) ?></span>
            <span class="pill pill-green" style="font-size:10px"><?= count($dayRows) ?> records</span>
            <?php if($dayKg>0)echo '<span class="pill pill-teal" style="font-size:10px">'.number_format($dayKg,1).' kg</span>'; ?>
          </div>
          <div class="range-day-right" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;flex-shrink:0">
            <strong style="color:var(--green-600);font-size:13px"><?= money($dayPay) ?></strong>
            <?php if($dayPaid>0)echo '<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;white-space:nowrap">✅ '.money($dayPaid).'</span>'; ?>
            <?php if($dayPending>0)echo '<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;white-space:nowrap">⏳ '.money($dayPending).'</span>'; ?>
            <i class="ti ti-chevron-down" style="color:var(--gray-400);font-size:13px"></i>
          </div>
        </div>
        <div id="day-<?= str_replace('-','',$date) ?>" style="overflow-x:auto">
          <table class="assign-table-wrap" style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
              <tr style="background:#f8faf5">
                <?php if ($isAdmin): ?>
                <th style="padding:6px 10px;border-bottom:1px solid #f0f0eb;width:30px">
                  <input type="checkbox" class="select-all-range" onchange="toggleSelectAll(this,'range')"
                         style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer">
                </th>
                <?php endif; ?>
                <th style="padding:6px 10px;text-align:left;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb">Worker</th>
                <th style="padding:6px 10px;text-align:left;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb" class="hide-mobile">Plantation</th>
                <th style="padding:6px 10px;text-align:left;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb" class="hide-mobile">Work Type</th>
                <th style="padding:6px 10px;text-align:right;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb">Qty</th>
                <th style="padding:6px 10px;text-align:right;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb">Pay</th>
                <th style="padding:6px 10px;text-align:left;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb" class="hide-mobile">Notes</th>
                <th style="padding:6px 10px;text-align:center;color:var(--gray-600);font-weight:600;border-bottom:1px solid #f0f0eb">Status</th>
                <?php if($isAdmin)echo '<th style="padding:6px 10px;border-bottom:1px solid #f0f0eb"></th>'; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach($dayRows as $a): $isPaid=($a['payment_status']==='paid'); ?>
              <tr style="border-bottom:1px solid #f8f8f6;background:<?= $isPaid?'#f0fdf4':'#fff5f5' ?>" onmouseover="this.style.background='<?= $isPaid?'#dcfce7':'#fee2e2' ?>'" onmouseout="this.style.background='<?= $isPaid?'#f0fdf4':'#fff5f5' ?>'">
                <?php if ($isAdmin): ?>
                <td style="padding:7px 10px;width:30px">
                  <input type="checkbox" class="bulk-check-range" value="<?= $a['id'] ?>"
                         onchange="updateBulkSelection()"
                         style="width:15px;height:15px;accent-color:var(--green-600);cursor:pointer">
                </td>
                <?php endif; ?>
                <td style="padding:7px 10px">
                  <div style="display:flex;align-items:center;gap:6px">
                    <div class="avatar" style="width:24px;height:24px;font-size:10px;flex-shrink:0"><?= initials($a['full_name']) ?></div>
                    <span style="font-weight:600;font-size:12px"><?= sanitize($a['full_name']) ?></span>
                  </div>
                </td>
                <td style="padding:7px 10px;color:var(--gray-600)" class="hide-mobile"><?= sanitize($a['plantation_name']) ?></td>
                <td style="padding:7px 10px;color:var(--gray-600)" class="hide-mobile"><?= sanitize($a['work_type_name']) ?></td>
                <td style="padding:7px 10px;text-align:right"><?= number_format($a['quantity'],2) ?> <?= sanitize($a['unit_label']) ?></td>
                <td style="padding:7px 10px;text-align:right;font-weight:700;color:var(--green-600)"><?= money($a['payment']) ?></td>
                <td style="padding:7px 10px;font-size:11px;color:var(--amber-600)" class="hide-mobile">
  <?php
    $rNote = '';
    if (!empty($a['notes'])) {
      if (!empty($a['is_temp']) && str_starts_with($a['notes'],'TEMP:')) {
        $rParts = explode('|',$a['notes'],2);
        $rNote  = isset($rParts[1]) ? trim($rParts[1]) : '';
      } else { $rNote = $a['notes']; }
    }
  ?>
  <?= $rNote ? '<span style="display:flex;align-items:center;gap:3px"><i class="ti ti-notes" style="font-size:11px"></i>'.sanitize($rNote).'</span>' : '—' ?>
</td>
                <td style="padding:7px 10px;text-align:center">
                  <?php if($isAdmin): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="redirect" value="/assignments.php?tab=range&from=<?= $dateFrom ?>&to=<?= $dateTo ?>">
                    <button type="submit" style="border:none;cursor:pointer;border-radius:20px;padding:3px 8px;font-size:10px;font-weight:700;white-space:nowrap;<?= $isPaid?'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7':'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5' ?>">
                      <i class="ti ti-<?= $isPaid?'circle-check':'clock' ?>" style="font-size:11px"></i> <?= $isPaid?'Paid':'Pending' ?>
                    </button>
                  </form>
                  <?php else: ?>
                  <span style="<?= $isPaid?'color:#065f46':'color:#991b1b' ?>;font-size:11px;font-weight:700"><?= $isPaid?'✅ Paid':'⏳ Pending' ?></span>
                  <?php endif; ?>
                </td>
                <?php if($isAdmin): ?>
                <td style="padding:7px 10px">
                  <div style="display:flex;gap:3px;justify-content:flex-end">
                    <a href="assignments.php?tab=day&date=<?= $date ?>&edit=<?= $a['id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-edit"></i></a>
                    <form method="POST" onsubmit="return confirm('Remove?')" style="display:inline">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $a['id'] ?>">
                      <input type="hidden" name="redirect" value="/assignments.php?tab=range&from=<?= $dateFrom ?>&to=<?= $dateTo ?>">
                      <button type="submit" class="assign-del" style="font-size:13px"><i class="ti ti-trash"></i></button>
                    </form>
                  </div>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    </div><!-- scroll wrap -->

    <?php if ($tab !== 'pending'): ?>
    <div class="assign-total" style="margin-top:8px">
      <span class="assign-total-label"><?= $tab==='range'?fmtDate($dateFrom).' → '.fmtDate($dateTo):'Total Payroll Today' ?></span>
      <span class="assign-total-val"><?= money($totalPay) ?></span>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>

<!-- Reject handled inline per assignment row -->

<script>
// Work type modes:
// 'plucking'  = Tea Plucking            → per-worker KG input (show qty input)
// 'spraying'  = Tank Spraying / Per Tree → per-worker qty input (Tank/Tree count, etc.)
// 'auto'      = Clearing/Helper/Basic/TeaCutting → 1 unit auto per person

var currentRate = <?= (float)($workTypes[0]['rate_per_unit']??50) ?>;
var currentUnit = '<?= sanitize($workTypes[0]['unit_label']??'KG') ?>';
var isFixedPay  = <?= (($workTypes[0]['payment_type']??'per_unit')==='fixed') ? 'true' : 'false' ?>;

// Detect mode by unit_label — works for all estates regardless of work type ID
function getModeFromUnit(unit) {
  var u = (unit || '').toLowerCase().trim();
  if (u === 'kg')                  return 'plucking';
  if (u === 'tank' || u === 'tanks' || u === 'tree' || u === 'trees') return 'spraying';
  return 'auto';
}

// Pluralized display label for the current unit (Tank → Tanks, Tree → Trees)
function unitPluralLabel(unit) {
  unit = unit || '';
  return unit + (unit.slice(-1).toLowerCase() === 's' ? '' : 's');
}

var currentMode = getModeFromUnit('<?= strtolower(sanitize($workTypes[0]['unit_label']??'KG')) ?>');
var isSpraying  = (currentMode === 'spraying');
var isPlucking  = (currentMode === 'plucking');

function selectWT(el, id, rate, unit, unitLower, paymentType) {
  document.querySelectorAll('.wt-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('a-wt-id').value = id;
  currentRate = parseFloat(rate);
  currentUnit = unit;
  isFixedPay  = (paymentType === 'fixed');

  // Mode determined by unit label — not hardcoded ID
  currentMode = getModeFromUnit(unitLower || unit);
  isPlucking  = (currentMode === 'plucking');
  isSpraying  = (currentMode === 'spraying');

  // Update hint message
  var fixedNote = ' Payment is a fixed Rs. ' + Math.round(currentRate).toLocaleString() + ' per worker regardless of ' + unit.toLowerCase() + ' — the ' + unit.toLowerCase() + ' is still recorded for production reports.';
  var hints = {
    plucking: 'Enter KG for each selected worker individually.' + (isFixedPay ? fixedNote : ''),
    spraying: 'Enter number of ' + unitPluralLabel(unit).toLowerCase() + ' per worker.' + (isFixedPay ? fixedNote : ''),
    auto:     'Each selected worker = 1 ' + unit + ' automatically.' + (isFixedPay ? fixedNote : '')
  };
  var hintEl = document.getElementById('qty-hint');
  if (hintEl) hintEl.textContent = hints[currentMode];

  // Update worker rows visibility
  updateWorkerRows();
  calcTotal();
}

function updateWorkerRows() {
  document.querySelectorAll('#worker-list input[type=checkbox]').forEach(function(cb) {
    var wid      = cb.value;
    var checked  = cb.checked;
    var qtyWrap  = document.getElementById('wqty-' + wid);
    var autoBadge= document.getElementById('wbadge-' + wid);

    // Hide everything first
    if (qtyWrap)   qtyWrap.style.display   = 'none';
    if (autoBadge) autoBadge.style.display = 'none';

    if (!checked) return;

    if (currentMode === 'plucking') {
      // Show KG input
      if (qtyWrap) {
        qtyWrap.style.display = 'flex';
        var badge = qtyWrap.querySelector('.unit-badge');
        if (badge) { badge.textContent='KG'; badge.style.background='var(--green-50)'; badge.style.color='var(--green-700)'; badge.style.borderColor='var(--green-200)'; }
      }
    } else if (currentMode === 'spraying') {
      // Show manual qty input (Tank / Tree / etc.)
      if (qtyWrap) {
        qtyWrap.style.display = 'flex';
        var badge = qtyWrap.querySelector('.unit-badge');
        if (badge) { badge.textContent=unitPluralLabel(currentUnit); badge.style.background='var(--teal-50)'; badge.style.color='var(--teal-700)'; badge.style.borderColor='var(--teal-100)'; }
      }
    } else {
      // Auto mode — show "1 Unit ✓" badge, hide qty input
      if (autoBadge) {
        autoBadge.style.display = 'block';
        var span = autoBadge.querySelector('span');
        var unitLabels = {'Unit':'1 Unit ✓','Day':'1 Day ✓','KG':'Auto ✓'};
        if (span) span.textContent = unitLabels[currentUnit] || '1 ' + currentUnit + ' ✓';
      }
    }
  });
}

function toggleWorkerQty(cb, wid) {
  updateWorkerRows();
  updateCount();
  calcTotal();
}

function updateCount() {
  var checked   = document.querySelectorAll('#worker-list input[type=checkbox]:checked').length;
  var tempNamed = 0;
  document.querySelectorAll('#temp-workers-list [id^="temp-row-"]').forEach(function(row) {
    var nameInp = row.querySelector('input[type=text]');
    if (nameInp && nameInp.value.trim()) tempNamed++;
  });
  var total = checked + tempNamed;
  var el = document.getElementById('selected-count');
  if (el) {
    el.textContent = total;
    // Show breakdown if temp workers added
    if (tempNamed > 0 && checked > 0) {
      el.textContent = total + ' (' + checked + ' regular + ' + tempNamed + ' temp)';
    } else if (tempNamed > 0) {
      el.textContent = total + ' (temp only)';
    }
  }
}

function calcTotal() {
  var checked     = document.querySelectorAll('#worker-list input[type=checkbox]:checked');
  var total       = 0;
  var totalUnits  = 0;
  var workerCount = 0;

  // ── Regular workers ───────────────────────────
  checked.forEach(function(cb) {
    var wid = cb.value;
    var qty = 1;

    if (currentMode === 'plucking') {
      var inp = document.querySelector('#wqty-' + wid + ' input[type=number]');
      qty = inp ? (parseFloat(inp.value) || 0) : 0;
    } else if (currentMode === 'spraying') {
      var inp = document.querySelector('#wqty-' + wid + ' input[type=number]');
      qty = inp ? (parseFloat(inp.value) || 1) : 1;
    } else {
      qty = 1; // auto: 1 unit per person always
    }

    // Fixed Amount work types pay a flat rate per worker, not qty × rate —
    // but a plucking-mode worker still needs KG > 0 entered to count (same
    // rule the server enforces on save).
    if (isFixedPay) {
      if (currentMode !== 'plucking' || qty > 0) total += currentRate;
    } else {
      total += qty * currentRate;
    }
    totalUnits += qty;
    workerCount++;
  });

  // ── Temp / outside workers ────────────────────
  var tempCount2 = 0;
  document.querySelectorAll('#temp-workers-list [id^="temp-row-"]').forEach(function(row) {
    var nameInp = row.querySelector('input[type=text]');
    if (!nameInp || !nameInp.value.trim()) return; // skip unnamed rows
    var qty = 1;
    if (currentMode === 'plucking' || currentMode === 'spraying') {
      var qtyInp = row.querySelector('input[type=number]');
      qty = qtyInp ? (parseFloat(qtyInp.value) || 1) : 1;
    } else {
      qty = 1; // auto: 1 unit per temp worker too
    }
    if (isFixedPay) {
      if (currentMode !== 'plucking' || qty > 0) total += currentRate;
    } else {
      total += qty * currentRate;
    }
    totalUnits += qty;
    tempCount2++;
  });

  var totalWorkers = workerCount + tempCount2;

  // ── Update display ─────────────────────────────
  var resultEl = document.getElementById('calc-result');
  if (resultEl) resultEl.textContent = 'Rs. ' + Math.round(total).toLocaleString();

  var noteEl = document.getElementById('per-worker-note');
  if (noteEl) {
    if (currentMode === 'auto' && totalWorkers > 0) {
      var breakdown = totalWorkers + ' worker(s) × 1 ' + currentUnit + ' × Rs.' + parseInt(currentRate).toLocaleString();
      if (tempCount2 > 0)
        breakdown += ' (' + workerCount + ' regular + ' + tempCount2 + ' temp)';
      noteEl.textContent = breakdown + ' = Rs.' + Math.round(total).toLocaleString();
    } else if (currentMode === 'spraying' && totalWorkers > 0) {
      noteEl.textContent = totalWorkers + ' worker(s) — ' + Math.round(totalUnits) + ' ' + unitPluralLabel(currentUnit).toLowerCase() + ' total';
    } else if (currentMode === 'plucking' && totalWorkers > 0) {
      noteEl.textContent = totalWorkers + ' worker(s) — ' + totalUnits.toFixed(1) + ' kg total';
    } else {
      noteEl.textContent = '';
    }
  }

  // Update hidden qty field
  var hiddenQty = document.getElementById('a-qty');
  if (hiddenQty && currentMode === 'auto') hiddenQty.value = 1;
}

function selectAllWorkers() {
  document.querySelectorAll('#worker-list input[type=checkbox]').forEach(cb => { cb.checked = true; });
  updateWorkerRows();
  updateCount();
  calcTotal();
}

function clearAllWorkers() {
  document.querySelectorAll('#worker-list input[type=checkbox]').forEach(cb => {
    cb.checked = false;
    var wid = cb.value;
    var inp = document.querySelector('#wqty-' + wid + ' input[type=number]');
    if (inp) inp.value = '';
  });
  updateWorkerRows();
  updateCount();
  calcTotal();
}

function filterWorkerList(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.worker-check-row').forEach(r => {
    r.style.display = r.dataset.name.includes(q) ? '' : 'none';
  });
}

function resetForm() {
  if (document.getElementById('assign-form')) document.getElementById('assign-form').reset();
  clearAllWorkers();
  document.getElementById('calc-result').textContent = 'Rs. 0';
  if (document.getElementById('per-worker-note')) document.getElementById('per-worker-note').textContent = '';
  document.getElementById('selected-count').textContent = '0';
  document.querySelectorAll('.wt-card').forEach((c,i) => c.classList.toggle('selected', i===0));
}

// ── TEMPORARY WORKERS JS ──────────────────────────────
var tempCount = 0;

function addTempWorker() {
  tempCount++;
  var idx  = tempCount;
  var list = document.getElementById('temp-workers-list');
  var empty= document.getElementById('temp-workers-empty');
  if (empty) empty.style.display = 'none';

  var row = document.createElement('div');
  row.id  = 'temp-row-' + idx;
  row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--amber-50);border:1px solid var(--amber-200);border-radius:var(--radius-md);flex-wrap:wrap';

  // Determine if we need qty input
  var needsQty = (currentMode === 'plucking' || currentMode === 'spraying');
  var unitLabel = currentUnit;
  var placeholder = currentMode === 'plucking' ? 'KG' : (currentMode === 'spraying' ? unitPluralLabel(currentUnit) : '');

  row.innerHTML =
    '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0">' +
      '<div style="width:28px;height:28px;border-radius:50%;background:var(--amber-200);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--amber-800)">T</div>' +
    '</div>' +
    '<input type="text" name="temp_name[]" placeholder="Enter worker name *" required ' +
           'style="flex:1;min-width:140px;font-size:13px;padding:7px 10px;border:1px solid var(--amber-300);border-radius:var(--radius-md);background:#fff" ' +
           'oninput="calcTotal()">' +
    (needsQty ?
      '<div style="display:flex;align-items:center;gap:5px;flex-shrink:0">' +
        '<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--amber-100);color:var(--amber-800);border:1px solid var(--amber-300)">' + unitLabel + '</span>' +
        '<input type="number" name="temp_qty[]" placeholder="' + placeholder + '" value="1" min="0.1" step="0.1" ' +
               'style="width:65px;font-size:13px;padding:7px 8px;border:1px solid var(--amber-300);border-radius:var(--radius-md);background:#fff" ' +
               'oninput="calcTotal()">' +
      '</div>'
    :
      '<span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:var(--amber-100);color:var(--amber-800);border:1px solid var(--amber-300);white-space:nowrap">' +
        '1 ' + unitLabel + ' ✓' +
      '</span>' +
      '<input type="hidden" name="temp_qty[]" value="1">'
    ) +
    '<button type="button" onclick="removeTempWorker(' + idx + ')" ' +
            'style="background:none;border:none;cursor:pointer;color:var(--red-400);font-size:18px;padding:2px;flex-shrink:0">' +
      '<i class="ti ti-x"></i>' +
    '</button>';

  list.appendChild(row);
  // Focus the name input
  var newInp = row.querySelector('input[type=text]');
  if (newInp) { newInp.addEventListener('input', function(){ updateCount(); calcTotal(); }); newInp.focus(); }
  calcTotal();
}

function removeTempWorker(idx) {
  var row = document.getElementById('temp-row-' + idx);
  if (row) row.remove();
  // Show empty message if no temp workers left
  var list = document.getElementById('temp-workers-list');
  if (list && list.children.length === 0) {
    var empty = document.getElementById('temp-workers-empty');
    if (empty) empty.style.display = 'block';
  }
  calcTotal();
}

// When work type changes, rebuild temp worker rows to show correct inputs
var _origSelectWT = selectWT;
selectWT = function(el, id, rate, unit, unitLower, paymentType) {
  _origSelectWT(el, id, rate, unit, unitLower, paymentType);
  // Rebuild all temp worker rows with new unit
  var rows = document.querySelectorAll('#temp-workers-list [id^="temp-row-"]');
  var names = [];
  rows.forEach(function(row) {
    var nameInp = row.querySelector('input[type=text]');
    names.push(nameInp ? nameInp.value : '');
    row.remove();
  });
  tempCount = 0;
  names.forEach(function(name) {
    addTempWorker();
    var rows2 = document.querySelectorAll('#temp-workers-list [id^="temp-row-"]');
    var lastRow = rows2[rows2.length-1];
    if (lastRow && name) {
      var inp = lastRow.querySelector('input[type=text]');
      if (inp) inp.value = name;
    }
  });
};

// Also update resetForm to clear temp workers
var _origResetForm = resetForm;
resetForm = function() {
  _origResetForm();
  document.getElementById('temp-workers-list').innerHTML = '';
  tempCount = 0;
  var empty = document.getElementById('temp-workers-empty');
  if (empty) empty.style.display = 'none';
};

// ── BULK PAYMENT JS ─────────────────────────────────────
function toggleSelectAll(masterCb, mode) {
  var cls = mode === 'day' ? '.bulk-check-day' : '.bulk-check-range';
  document.querySelectorAll(cls).forEach(function(cb) {
    cb.checked = masterCb.checked;
  });
  updateBulkSelection();
}

function updateBulkSelection() {
  // Collect all checked IDs from both day and range views
  var checked = document.querySelectorAll('.bulk-check-day:checked, .bulk-check-range:checked');
  var bar     = document.getElementById('bulk-bar');
  var countEl = document.getElementById('bulk-count');
  var container = document.getElementById('bulk-ids-container');

  if (!bar) return;

  // Build hidden inputs
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

  if (checked.length > 0) {
    bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length + ' selected';
    // Sync master checkboxes
    var allDay   = document.querySelectorAll('.bulk-check-day');
    var allRange = document.querySelectorAll('.bulk-check-range');
    var allSADay = document.getElementById('select-all-day');
    var allSARange = document.querySelectorAll('.select-all-range');
    var checkedDay   = document.querySelectorAll('.bulk-check-day:checked').length;
    var checkedRange = document.querySelectorAll('.bulk-check-range:checked').length;
    if (allSADay)   allSADay.indeterminate = (checkedDay > 0 && checkedDay < allDay.length);
    if (allSADay)   allSADay.checked = (checkedDay === allDay.length && allDay.length > 0);
    allSARange.forEach(function(cb) {
      cb.indeterminate = (checkedRange > 0 && checkedRange < allRange.length);
      cb.checked = (checkedRange === allRange.length && allRange.length > 0);
    });
  } else {
    bar.style.display = 'none';
    // Reset master checkboxes
    var allSADay2 = document.getElementById('select-all-day');
    if (allSADay2) { allSADay2.checked = false; allSADay2.indeterminate = false; }
    document.querySelectorAll('.select-all-range').forEach(function(cb) {
      cb.checked = false; cb.indeterminate = false;
    });
  }
}

function clearBulkSelection() {
  document.querySelectorAll('.bulk-check-day, .bulk-check-range').forEach(function(cb) { cb.checked = false; });
  document.querySelectorAll('#select-all-day, .select-all-range').forEach(function(cb) { cb.checked = false; cb.indeterminate = false; });
  updateBulkSelection();
}

// Form submit — server-side handles quantities from worker_qty[] or auto=1
var frm = document.getElementById('assign-form');
if (frm) frm.addEventListener('submit', function(e) {
  var checked   = document.querySelectorAll('#worker-list input[type=checkbox]:checked').length;
  var tempRows  = document.querySelectorAll('#temp-workers-list [id^="temp-row-"]');
  var tempCount = 0;
  tempRows.forEach(function(row) {
    var nameInp = row.querySelector('input[type=text]');
    if (nameInp && nameInp.value.trim()) tempCount++;
  });

  // Block only if BOTH regular and temp workers are empty
  if (checked === 0 && tempCount === 0) {
    e.preventDefault();
    alert('Please select at least one worker, or add a temporary worker.');
    return;
  }

  // For plucking with regular workers: at least one needs a KG value
  if (currentMode === 'plucking' && checked > 0) {
    var anyKg = false;
    document.querySelectorAll('#worker-list input[type=checkbox]:checked').forEach(function(cb) {
      var inp = document.querySelector('#wqty-' + cb.value + ' input[type=number]');
      if (inp && parseFloat(inp.value) > 0) anyKg = true;
    });
    if (!anyKg) { e.preventDefault(); alert('Please enter KG for at least one selected worker.'); return; }
  }
});

// toggleDay — accordion for date-range groups
function toggleDay(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = el.style.display === 'none' ? '' : 'none';
}

// toggleRejectForm — inline reject reason expansion
function toggleRejectForm(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
