<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();

$yearParam = $_GET['year'] ?? 'all';
$header = ['Jahr', 'Vertrag', 'Kategorie', 'Kosten im Jahr'];
$rows = [];

if ($yearParam === 'all') {
    $years = array_reverse(get_available_years($pdo)); // chronologisch aufsteigend für den Export
    foreach ($years as $year) {
        $breakdown = get_year_breakdown($pdo, (string) $year);
        foreach ($breakdown['items'] as $item) {
            $rows[] = [$year, $item['name'], $item['category_name'] ?? '', csv_amount($item['amount'])];
        }
        if (!empty($breakdown['items'])) {
            $rows[] = [$year, 'Gesamt', '', csv_amount($breakdown['total'])];
        }
    }
    $filename = 'jahresuebersicht_alle_jahre_' . date('Y-m-d') . '.csv';
} else {
    $year = preg_match('/^\d{4}$/', $yearParam) ? $yearParam : date('Y');
    $breakdown = get_year_breakdown($pdo, $year);
    foreach ($breakdown['items'] as $item) {
        $rows[] = [$year, $item['name'], $item['category_name'] ?? '', csv_amount($item['amount'])];
    }
    if (!empty($breakdown['items'])) {
        $rows[] = [$year, 'Gesamt', '', csv_amount($breakdown['total'])];
    }
    $filename = 'jahresuebersicht_' . $year . '.csv';
}

send_csv($filename, $header, $rows);
