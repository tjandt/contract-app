<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

$yearParam = $_GET['year'] ?? 'all';
// 'accrual' = periodisierte Kosten (Standard), 'cash' = tatsächliche Abbuchungen laut Zahlungskalender
$basis = ($_GET['basis'] ?? 'accrual') === 'cash' ? 'cash' : 'accrual';

$years = $yearParam === 'all'
    ? array_reverse(get_available_years($pdo)) // chronologisch aufsteigend
    : [preg_match('/^\d{4}$/', $yearParam) ? $yearParam : date('Y')];

$rows = [];
if ($basis === 'cash') {
    $header = ['Jahr', 'Vertrag', 'Kategorie', 'Abbuchungen', 'Summe der Abbuchungen'];
    foreach ($years as $year) {
        $payments = get_year_payments($pdo, (string) $year);
        foreach ($payments['items'] as $item) {
            $rows[] = [$year, $item['name'], $item['category_name'] ?? '', $item['count'], csv_amount($item['sum'])];
        }
        if ($payments['items']) {
            $rows[] = [$year, 'Gesamt', '', '', csv_amount($payments['total'])];
        }
    }
    $prefix = 'abbuchungen';
} else {
    $header = ['Jahr', 'Vertrag', 'Kategorie', 'Kosten im Jahr (periodisiert)', 'Berücksichtigte Monate'];
    foreach ($years as $year) {
        $breakdown = get_year_breakdown($pdo, (string) $year);
        foreach ($breakdown['items'] as $item) {
            $rows[] = [$year, $item['name'], $item['category_name'] ?? '', csv_amount($item['amount']), $item['months']];
        }
        if ($breakdown['items']) {
            $rows[] = [$year, 'Gesamt', '', csv_amount($breakdown['total']), $breakdown['months']];
        }
    }
    $prefix = 'jahresuebersicht';
}

$suffix = $yearParam === 'all' ? 'alle_jahre_' . date('Y-m-d') : (string) $years[0];
send_csv($prefix . '_' . $suffix . '.csv', $header, $rows);
