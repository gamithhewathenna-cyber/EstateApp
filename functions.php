<?php
function money($amount) {
    return 'Rs. ' . number_format((float)$amount, 2);
}

function moneyShort($amount) {
    if ($amount >= 1000000) return 'Rs. ' . number_format($amount/1000000, 1) . 'M';
    if ($amount >= 1000)    return 'Rs. ' . number_format($amount/1000, 1) . 'K';
    return 'Rs. ' . number_format($amount, 0);
}

function kg($amount) {
    return number_format((float)$amount, 1) . ' kg';
}

function fmtDate($date) {
    return date('d M Y', strtotime($date));
}

function today() {
    return date('Y-m-d');
}

function currentMonth() {
    return date('Y-m');
}

function currentYear() {
    return date('Y');
}

function sanitize($val) {
    return htmlspecialchars(trim((string)($val ?? '')), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect($url) {
    header('Location: ' . BASE_URL . $url);
    exit;
}

function flash($key, $msg = null) {
    if ($msg === null) {
        $val = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $val;
    }
    $_SESSION['flash'][$key] = $msg;
}

function pill($text, $type = 'default') {
    $classes = [
        'active'   => 'pill-green',
        'inactive' => 'pill-gray',
        'admin'    => 'pill-blue',
        'supervisor' => 'pill-teal',
        'default'  => 'pill-gray',
    ];
    $cls = $classes[$type] ?? 'pill-gray';
    return '<span class="pill ' . $cls . '">' . sanitize($text) . '</span>';
}

function initials($name) {
    $name = (string)($name ?? '');
    $parts = explode(' ', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) $ini .= strtoupper($p[0]);
    return $ini;
}

function daysUntil($date) {
    $diff = (strtotime($date) - strtotime(today())) / 86400;
    return (int)round($diff);
}
