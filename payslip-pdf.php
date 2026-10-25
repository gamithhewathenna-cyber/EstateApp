<?php
// ============================================
// Harvest Pro — Worker Salary Payslip (Print/PDF)
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAdmin();
$estateId = Auth::estateId();

// Parameters
$workerId = (int)($_GET['worker'] ?? 0);
$selMonth = $_GET['month'] ?? date('Y-m');

$worker = $workerId ? DB::fetchOne("SELECT * FROM workers WHERE id=? AND estate_id=?", [$workerId, $estateId]) : null;
if (!$worker) {
    echo '<p style="font-family:sans-serif;padding:40px">Worker not found. <a href="payroll.php">Go back</a></p>';
    exit;
}

// Estate info
try {
    $sr = DB::fetchAll("SELECT `key`,`value` FROM app_settings WHERE estate_id=?", [$estateId]);
    $appSettings = array_column($sr, 'value', 'key');
} catch(Exception $e){ $appSettings=[]; }
$estateRow  = DB::fetchOne("SELECT * FROM estates WHERE id=?", [$estateId]);
$appName    = $appSettings['app_name'] ?? APP_NAME;
$estateName = $estateRow['name'] ?? $appName;
$logoFile   = $estateRow['logo_file'] ?? ($appSettings['logo_file'] ?? '');
$logoUrl    = $logoFile ? BASE_URL.'/assets/img/'.$logoFile : '';

// Language
$useSi = isset($_GET['lang']) && $_GET['lang'] === 'si';

$sinhala = [
    'Tea Plucking'        => 'දලු කිලෝ',
    'Clearing Work'       => 'නෙලීම',
    'Tank Spraying'       => 'ඉසින කාර්ය',
    'Helper'              => 'ආධාර',
    'Basic / Support Work'=> 'සාමාන්‍ය කාර්ය',
    'Basic / Support'     => 'සාමාන්‍ය කාර්ය',
    'KG'                  => 'කිලෝ',
    'Unit'                => 'අත්තම්',
    'Day'                 => 'දිනය',
    'Tank'                => 'ටැංකිය',
    'Tree'                => 'ගස',
    'Salary Payslip'      => 'වැටුප් පත්‍රය',
    'Pay Period'          => 'වැටුප් කාලය',
    'Employee'            => 'සේවකයා',
    'Worker ID'           => 'සේවක අංකය',
    'Phone'                => 'දුරකථන අංකය',
    'NIC'                  => 'ජාතික හැඳුනුම්පත',
    'Work Type'            => 'කාර්ය වර්ගය',
    'Quantity'              => 'ප්‍රමාණය',
    'Days'                   => 'දින ගණන',
    'Amount'                 => 'මුදල',
    'Days Worked'            => 'වැඩ කළ දින',
    'Total Records'          => 'වාර්තා ගණන',
    'Gross Earnings'         => 'මුළු ආදායම',
    'Paid'                   => 'ගෙවන ලද',
    'Pending'                => 'ගෙවීමට ඇති',
    'Net Pay'                => 'ශුද්ධ වැටුප',
    'Employee Signature'     => 'සේවක අත්සන',
    'Employer Signature'     => 'සේවායෝජක අත්සන',
];
function siLabel($en, $si_map, $useSi) {
    $si = $si_map[trim($en ?? '')] ?? null;
    if ($useSi && $si) return $si.' <span style="font-size:9px;color:#6b7c61;font-weight:400">('.$en.')</span>';
    return htmlspecialchars($en ?? '');
}

$monthLabel = date('F Y', strtotime($selMonth.'-01'));

$records = DB::fetchAll("SELECT da.*, wt.name as work_type_name, wt.unit_label
    FROM daily_assignments da
    JOIN work_types wt ON da.work_type_id=wt.id
    WHERE da.estate_id=? AND da.worker_id=? AND da.approval_status='approved'
    AND DATE_FORMAT(da.assignment_date,'%Y-%m')=?
    ORDER BY da.assignment_date ASC", [$estateId, $workerId, $selMonth]);

// Group by work type for the earnings table
$byWorkType = [];
foreach ($records as $r) {
    $wt = $r['work_type_name'];
    if (!isset($byWorkType[$wt])) {
        $byWorkType[$wt] = ['unit_label' => $r['unit_label'], 'qty' => 0, 'amount' => 0, 'records' => 0];
    }
    $byWorkType[$wt]['qty']     += (float)$r['quantity'];
    $byWorkType[$wt]['amount']  += (float)$r['payment'];
    $byWorkType[$wt]['records'] += 1;
}

$totalDays    = count(array_unique(array_column($records, 'assignment_date')));
$totalAmount  = array_sum(array_column($records, 'payment'));
$paidAmount   = array_sum(array_map(fn($r) => $r['payment_status'] === 'paid' ? $r['payment'] : 0, $records));
$pendingAmount = $totalAmount - $paidAmount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= sanitize($estateName) ?> — Payslip — <?= sanitize($worker['full_name']) ?> — <?= $monthLabel ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Noto+Sans+Sinhala:wght@400;600;700&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Poppins', 'Noto Sans Sinhala', 'Segoe UI', Arial, sans-serif;
  font-size: 12px;
  color: #1a2e0a;
  background: #fff;
}

@page { size: A4 portrait; margin: 15mm 12mm 15mm 12mm; }

@media screen {
  body { background: #e8ede5; padding: 20px; }
  .page { background: #fff; max-width: 700px; margin: 0 auto 20px; padding: 24px 28px; box-shadow: 0 4px 20px rgba(0,0,0,.12); border-radius: 6px; animation: fadeInPage .5s ease-out; }
  .no-print { display: block; }
}
@keyframes fadeInPage { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .page { animation: none; } }

@media print {
  body { background: #fff; padding: 0; }
  .page { padding: 0; box-shadow: none; max-width: none; }
  .no-print { display: none !important; }
}

.no-print {
  background: #0D2B0A;
  padding: 12px 20px;
  max-width: 700px;
  margin: 0 auto 16px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.btn-print {
  background: #4CAF50; color: #fff; border: none; padding: 8px 20px; border-radius: 20px;
  font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.btn-print:hover { background: #388E3C; }
.btn-back {
  background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.3);
  padding: 8px 16px; border-radius: 20px; font-size: 12px; cursor: pointer; text-decoration: none;
  display: inline-flex; align-items: center; gap: 5px;
}

.report-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 18px; padding-bottom: 14px; border-bottom: 3px solid #0D2B0A;
}
.header-left { display: flex; align-items: center; gap: 12px; }
.logo-box {
  width: 48px; height: 48px; background: #0D2B0A; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;
}
.logo-box img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
.logo-box .leaf { font-size: 24px; color: #4CAF50; }
.estate-name  { font-size: 17px; font-weight: 800; color: #0D2B0A; line-height: 1.1; }
.app-brand    { font-size: 10px; color: #6b7c61; font-weight: 500; margin-top: 2px; }
.header-right { text-align: right; }
.report-title { font-size: 15px; font-weight: 700; color: #0D2B0A; }
.report-sub   { font-size: 11px; color: #6b7c61; margin-top: 3px; }
.report-meta  { font-size: 10px; color: #9aab8a; margin-top: 2px; }

.employee-box {
  display: flex; justify-content: space-between; gap: 14px;
  background: #f0f7ec; border: 1px solid #dce8d4; border-radius: 8px;
  padding: 14px 16px; margin-bottom: 20px; flex-wrap: wrap;
}
.emp-field { min-width: 120px; }
.emp-label { font-size: 9px; font-weight: 700; color: #6b7c61; text-transform: uppercase; letter-spacing: .05em; }
.emp-value { font-size: 13px; font-weight: 700; color: #0D2B0A; margin-top: 2px; }

.earn-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.earn-table th {
  background: #0D2B0A; color: #fff; font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .04em; text-align: left; padding: 8px 10px;
}
.earn-table th:last-child, .earn-table td:last-child { text-align: right; }
.earn-table td { padding: 8px 10px; border-bottom: 1px solid #eef4e8; font-size: 12px; }
.earn-table tr:last-child td { border-bottom: none; }
.earn-table tfoot td { font-weight: 800; border-top: 2px solid #0D2B0A; background: #f0f7ec; }

.summary-bar { display: flex; gap: 0; border: 1.5px solid #0D2B0A; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
.sum-item { flex: 1; padding: 10px 14px; border-right: 1px solid #cfddc8; background: #f0f7ec; }
.sum-item:last-child { border-right: none; }
.sum-label { font-size: 9px; font-weight: 700; color: #6b7c61; text-transform: uppercase; letter-spacing: .06em; }
.sum-value { font-size: 14px; font-weight: 800; color: #0D2B0A; margin-top: 2px; }

.net-pay {
  background: #0D2B0A; color: #fff; padding: 14px 18px; border-radius: 8px;
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px;
}
.net-pay-label { font-size: 13px; font-weight: 700; }
.net-pay-value { font-size: 22px; font-weight: 800; color: #A5D6A7; }

.sign-row { display: flex; justify-content: space-between; margin-top: 30px; }
.sign-box { width: 42%; text-align: center; }
.sign-line { border-top: 1px solid #6b7c61; padding-top: 6px; font-size: 10px; color: #6b7c61; }

.report-footer {
  margin-top: 24px; padding-top: 10px; border-top: 1px solid #dce8d4;
  display: flex; justify-content: space-between; font-size: 9px; color: #9aab8a;
}
</style>
</head>
<body>

<!-- ── SCREEN TOOLBAR ── -->
<div class="no-print">
  <a href="payroll.php?month=<?= $selMonth ?>&tab=worker" class="btn-back">← Back</a>
  <button onclick="window.print()" class="btn-print">🖨 Print / Save as PDF</button>
  <span style="color:rgba(255,255,255,.5);font-size:12px">Tip: In the print dialog, choose "Save as PDF" to download</span>
  <div style="display:flex;gap:6px;margin-left:8px">
    <a href="?worker=<?= $workerId ?>&month=<?= $selMonth ?>&lang=en"
       style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;<?= !$useSi?'background:#4CAF50;color:#fff':'background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2)' ?>">
      🇬🇧 English
    </a>
    <a href="?worker=<?= $workerId ?>&month=<?= $selMonth ?>&lang=si"
       style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;<?= $useSi?'background:#4CAF50;color:#fff':'background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2)' ?>">
      🇱🇰 සිංහල
    </a>
  </div>
</div>

<div class="page">

  <!-- ── HEADER ── -->
  <div class="report-header">
    <div class="header-left">
      <div class="logo-box">
        <?php if ($logoUrl): ?>
          <img src="<?= $logoUrl ?>" alt="Logo">
        <?php else: ?>
          <span class="leaf">🌿</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="estate-name"><?= sanitize($estateName) ?></div>
        <div class="app-brand"><?= sanitize($appName) ?></div>
      </div>
    </div>
    <div class="header-right">
      <div class="report-title"><?= siLabel('Salary Payslip', $sinhala, $useSi) ?></div>
      <div class="report-sub"><?= siLabel('Pay Period', $sinhala, $useSi) ?>: <?= $monthLabel ?></div>
      <div class="report-meta">Generated: <?= date('d M Y, H:i') ?></div>
    </div>
  </div>

  <!-- ── EMPLOYEE INFO ── -->
  <div class="employee-box">
    <div class="emp-field">
      <div class="emp-label"><?= siLabel('Employee', $sinhala, $useSi) ?></div>
      <div class="emp-value"><?= sanitize($worker['full_name']) ?></div>
    </div>
    <div class="emp-field">
      <div class="emp-label"><?= siLabel('Worker ID', $sinhala, $useSi) ?></div>
      <div class="emp-value">#<?= (int)$worker['id'] ?></div>
    </div>
    <?php if (!empty($worker['phone'])): ?>
    <div class="emp-field">
      <div class="emp-label"><?= siLabel('Phone', $sinhala, $useSi) ?></div>
      <div class="emp-value"><?= sanitize($worker['phone']) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($worker['nic'])): ?>
    <div class="emp-field">
      <div class="emp-label"><?= siLabel('NIC', $sinhala, $useSi) ?></div>
      <div class="emp-value"><?= sanitize($worker['nic']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── EARNINGS TABLE ── -->
  <table class="earn-table">
    <thead>
      <tr>
        <th><?= siLabel('Work Type', $sinhala, $useSi) ?></th>
        <th><?= siLabel('Quantity', $sinhala, $useSi) ?></th>
        <th><?= siLabel('Days', $sinhala, $useSi) ?></th>
        <th><?= siLabel('Amount', $sinhala, $useSi) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$byWorkType): ?>
      <tr><td colspan="4" style="text-align:center;padding:20px;color:#9aab8a">No approved records for this month.</td></tr>
      <?php endif; ?>
      <?php foreach ($byWorkType as $wtName => $wt): ?>
      <tr>
        <td><?= siLabel($wtName, $sinhala, $useSi) ?></td>
        <td><?= number_format($wt['qty'], strtolower($wt['unit_label'])==='kg'?1:0) ?> <?= siLabel($wt['unit_label'], $sinhala, $useSi) ?></td>
        <td><?= $wt['records'] ?></td>
        <td><?= money($wt['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($byWorkType): ?>
    <tfoot>
      <tr>
        <td colspan="3"><?= siLabel('Gross Earnings', $sinhala, $useSi) ?></td>
        <td><?= money($totalAmount) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- ── SUMMARY ── -->
  <div class="summary-bar">
    <div class="sum-item">
      <div class="sum-label"><?= siLabel('Days Worked', $sinhala, $useSi) ?></div>
      <div class="sum-value"><?= $totalDays ?></div>
    </div>
    <div class="sum-item">
      <div class="sum-label"><?= siLabel('Paid', $sinhala, $useSi) ?></div>
      <div class="sum-value" style="color:#065F46"><?= money($paidAmount) ?></div>
    </div>
    <div class="sum-item" style="<?= $pendingAmount>0?'background:#FFF1F2':'' ?>">
      <div class="sum-label" style="<?= $pendingAmount>0?'color:#991B1B':'' ?>"><?= siLabel('Pending', $sinhala, $useSi) ?></div>
      <div class="sum-value" style="<?= $pendingAmount>0?'color:#991B1B':'' ?>"><?= money($pendingAmount) ?></div>
    </div>
  </div>

  <!-- ── NET PAY ── -->
  <div class="net-pay">
    <div class="net-pay-label"><?= siLabel('Net Pay', $sinhala, $useSi) ?> — <?= $monthLabel ?></div>
    <div class="net-pay-value"><?= money($totalAmount) ?></div>
  </div>

  <!-- ── SIGNATURES ── -->
  <div class="sign-row">
    <div class="sign-box"><div class="sign-line"><?= siLabel('Employee Signature', $sinhala, $useSi) ?></div></div>
    <div class="sign-box"><div class="sign-line"><?= siLabel('Employer Signature', $sinhala, $useSi) ?></div></div>
  </div>

  <!-- ── FOOTER ── -->
  <div class="report-footer">
    <span><?= sanitize($estateName) ?></span>
    <span>Generated by <?= sanitize($appName) ?> on <?= date('d M Y \a\t H:i') ?></span>
  </div>

</div><!-- .page -->
</body>
</html>
