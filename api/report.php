<?php
require_once '../includes/config.php';
requireLogin();
$db = getDB();
$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'html';
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');

function outputCSV(string $fn, array $headers, array $rows): void {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $h = implode(',',array_map(fn($c)=>'"'.str_replace('"','""',$c).'"',$headers))."\n";
    echo $h;
    foreach($rows as $r) echo implode(',',array_map(fn($v)=>'"'.str_replace('"','""',(string)$v).'"',$r))."\n";
    exit;
}

function pageWrap(string $title, string $body): void {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.htmlspecialchars($title).'</title>';
    echo '<style>body{font-family:sans-serif;padding:30px;color:#1a1a1a;max-width:1000px;margin:0 auto}h1{color:#3B6D11;border-bottom:2px solid #3B6D11;padding-bottom:8px}table{width:100%;border-collapse:collapse;margin-top:16px}th{background:#3B6D11;color:#fff;padding:8px 12px;text-align:left;font-size:13px}td{padding:7px 12px;border-bottom:1px solid #e5e5e5;font-size:13px}tr:nth-child(even){background:#f9faf7}.total td{font-weight:bold;color:#3B6D11;background:#f0f7ea}.meta{color:#888;font-size:12px;margin-bottom:16px}@media print{.np{display:none}}</style>';
    echo '</head><body>';
    echo '<div class="np" style="margin-bottom:20px"><button onclick="window.print()" style="padding:8px 16px;background:#3B6D11;color:#fff;border:none;border-radius:6px;cursor:pointer">Print / Save PDF</button></div>';
    echo '<h1>'.htmlspecialchars($title).'</h1>';
    echo '<p class="meta">Generated: '.date('d M Y H:i').' &nbsp;|&nbsp; TeaEstate Pro</p>';
    echo $body;
    echo '</body></html>';
    exit;
}

if ($type === 'kg_month') {
    $s=$db->prepare("SELECT wk.full_name,p.name as plantation,COUNT(DISTINCT a.work_date) as days,SUM(a.quantity) as kg FROM assignments a JOIN workers wk ON a.worker_id=wk.id JOIN work_types wt ON a.work_type_id=wt.id JOIN plantations p ON a.plantation_id=p.id WHERE wt.code='plucking' AND DATE_FORMAT(a.work_date,'%Y-%m')=? GROUP BY wk.id,p.id ORDER BY kg DESC");
    $s->execute([$month]); $rows=$s->fetchAll();
    if($format==='csv') outputCSV("kg_$month.csv",['Worker','Plantation','Days','KG'],array_map(fn($r)=>[$r['full_name'],$r['plantation'],$r['days'],number_format($r['kg'],2)],$rows));
    $total=array_sum(array_column($rows,'kg'));
    $b='<table><tr><th>Worker</th><th>Plantation</th><th>Days</th><th>Total KG</th></tr>';
    foreach($rows as $r) $b.='<tr><td>'.$r['full_name'].'</td><td>'.$r['plantation'].'</td><td>'.$r['days'].'</td><td>'.number_format($r['kg'],2).' kg</td></tr>';
    $b.='<tr class="total"><td colspan="3">Grand Total</td><td>'.number_format($total,2).' kg</td></tr></table>';
    pageWrap('Monthly KG Report — '.date('M Y',strtotime($month.'-01')),$b);
}
if ($type === 'payroll_month') {
    $s=$db->prepare("SELECT wk.full_name,COUNT(DISTINCT a.work_date) as days,COALESCE(SUM(CASE WHEN wt.code='plucking' THEN a.quantity ELSE 0 END),0) as kg,COALESCE(SUM(CASE WHEN wt.code='plucking' THEN a.total_payment ELSE 0 END),0) as pluck,COALESCE(SUM(CASE WHEN wt.code!='plucking' THEN a.total_payment ELSE 0 END),0) as other,SUM(a.total_payment) as total FROM assignments a JOIN workers wk ON a.worker_id=wk.id JOIN work_types wt ON a.work_type_id=wt.id WHERE DATE_FORMAT(a.work_date,'%Y-%m')=? GROUP BY wk.id ORDER BY total DESC");
    $s->execute([$month]); $rows=$s->fetchAll();
    if($format==='csv') outputCSV("payroll_$month.csv",['Worker','Days','KG','Plucking','Other','Total'],array_map(fn($r)=>[$r['full_name'],$r['days'],number_format($r['kg'],1),'Rs.'.number_format($r['pluck'],2),'Rs.'.number_format($r['other'],2),'Rs.'.number_format($r['total'],2)],$rows));
    $gt=array_sum(array_column($rows,'total'));
    $b='<table><tr><th>Worker</th><th>Days</th><th>KG</th><th>Plucking Pay</th><th>Other Pay</th><th>Total Pay</th></tr>';
    foreach($rows as $r) $b.='<tr><td>'.$r['full_name'].'</td><td>'.$r['days'].'</td><td>'.number_format($r['kg'],1).'</td><td>Rs. '.number_format($r['pluck'],2).'</td><td>Rs. '.number_format($r['other'],2).'</td><td><strong>Rs. '.number_format($r['total'],2).'</strong></td></tr>';
    $b.='<tr class="total"><td colspan="5">Grand Total</td><td>Rs. '.number_format($gt,2).'</td></tr></table>';
    pageWrap('Payroll Report — '.date('M Y',strtotime($month.'-01')),$b);
}
if ($type === 'expenses') {
    $s=$db->prepare("SELECT e.expense_date,e.expense_type,p.name as plantation,e.amount,e.notes FROM expenses e LEFT JOIN plantations p ON e.plantation_id=p.id WHERE DATE_FORMAT(e.expense_date,'%Y-%m')=? ORDER BY e.expense_date DESC");
    $s->execute([$month]); $rows=$s->fetchAll();
    if($format==='csv') outputCSV("expenses_$month.csv",['Date','Type','Plantation','Amount','Notes'],array_map(fn($r)=>[date('d M Y',strtotime($r['expense_date'])),ucfirst($r['expense_type']),$r['plantation']??'All','Rs.'.number_format($r['amount'],2),$r['notes']],$rows));
    $total=array_sum(array_column($rows,'amount'));
    $b='<table><tr><th>Date</th><th>Type</th><th>Plantation</th><th>Amount</th><th>Notes</th></tr>';
    foreach($rows as $r) $b.='<tr><td>'.date('d M Y',strtotime($r['expense_date'])).'</td><td>'.ucfirst($r['expense_type']).'</td><td>'.($r['plantation']??'All').'</td><td>Rs. '.number_format($r['amount'],2).'</td><td>'.htmlspecialchars($r['notes']).'</td></tr>';
    $b.='<tr class="total"><td colspan="3">Total</td><td>Rs. '.number_format($total,2).'</td><td></td></tr></table>';
    pageWrap('Expense Report — '.date('M Y',strtotime($month.'-01')),$b);
}
if ($type === 'fertilizer') {
    $rows=$db->query("SELECT f.*,p.name as plant FROM fertilizer_records f JOIN plantations p ON f.plantation_id=p.id ORDER BY f.applied_date DESC")->fetchAll();
    if($format==='csv') outputCSV("fertilizer.csv",['Date','Plantation','Type','Amount(kg)','Next Due','Notes'],array_map(fn($r)=>[date('d M Y',strtotime($r['applied_date'])),$r['plant'],$r['fertilizer_type'],$r['amount_kg'],date('d M Y',strtotime($r['next_due_date'])),$r['notes']],$rows));
    $b='<table><tr><th>Date</th><th>Plantation</th><th>Fertilizer</th><th>Amount</th><th>Next Due</th><th>Notes</th></tr>';
    foreach($rows as $r) $b.='<tr><td>'.date('d M Y',strtotime($r['applied_date'])).'</td><td>'.$r['plant'].'</td><td>'.$r['fertilizer_type'].'</td><td>'.($r['amount_kg']?$r['amount_kg'].' kg':'—').'</td><td>'.date('d M Y',strtotime($r['next_due_date'])).'</td><td>'.htmlspecialchars($r['notes']).'</td></tr>';
    $b.='</table>';
    pageWrap('Fertilizer History',$b);
}
echo 'Invalid report type.';
