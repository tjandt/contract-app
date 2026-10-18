<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();

$stmt = $pdo->query("SELECT ch.*, c.name AS contract_name, c.billing_cycle, cat.name AS category_name
                      FROM cost_history ch
                      JOIN contracts c ON c.id = ch.contract_id
                      LEFT JOIN categories cat ON cat.id = c.category_id
                      ORDER BY ch.valid_from ASC, c.name ASC");
$history = $stmt->fetchAll();

$header = ['Datum', 'Vertrag', 'Kategorie', 'Betrag', 'Abrechnungszyklus', 'Betrag mtl. umgerechnet', 'Grund'];

$rows = [];
foreach ($history as $h) {
    $rows[] = [
        format_date_de($h['valid_from']),
        $h['contract_name'],
        $h['category_name'] ?? '',
        csv_amount((float) $h['amount']),
        cycle_label($h['billing_cycle']),
        csv_amount(to_monthly_amount((float) $h['amount'], $h['billing_cycle'])),
        $h['reason'] ?? '',
    ];
}

send_csv('kostenhistorie_' . date('Y-m-d') . '.csv', $header, $rows);
