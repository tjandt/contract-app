<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

$stmt = $pdo->query("SELECT c.*, cat.name AS category_name
                      FROM contracts c
                      LEFT JOIN categories cat ON cat.id = c.category_id
                      ORDER BY c.name ASC");
$contracts = $stmt->fetchAll();

$header = [
    'Vertragsname', 'Kategorie', 'Anbieter', 'Anbieter-Link', 'Vertragsnummer',
    'Status', 'Vertragsbeginn', 'Erstlaufzeit (Monate)', 'Verlängerung (Monate)',
    'Kündigungsfrist', 'Vertragsende', 'Kosten', 'Abrechnungszyklus', 'Abbuchungsanker', 'Kosten mtl. umgerechnet',
    'Kündigung eingereicht am', 'Kündigung bestätigt am', 'Gekündigt zum', 'Notizen',
];

$rows = [];
foreach ($contracts as $c) {
    $rows[] = [
        $c['name'],
        $c['category_name'] ?? '',
        $c['provider_name'] ?? '',
        $c['provider_url'] ?? '',
        $c['contract_number'] ?? '',
        effective_status_label($c),
        format_date_de($c['start_date']),
        $c['initial_term_months'],
        $c['renewal_term_months'],
        notice_label($c),
        end_alignment_label($c['end_alignment']),
        csv_amount((float) $c['current_cost']),
        cycle_label($c['billing_cycle']),
        $c['billing_anchor_date'] ? format_date_de($c['billing_anchor_date']) : '',
        csv_amount(to_monthly_amount((float) $c['current_cost'], $c['billing_cycle'])),
        $c['cancellation_submitted_at'] ? format_date_de($c['cancellation_submitted_at']) : '',
        $c['cancellation_confirmed_at'] ? format_date_de($c['cancellation_confirmed_at']) : '',
        $c['cancelled_at'] ? format_date_de($c['cancelled_at']) : '',
        $c['notes'] ?? '',
    ];
}

send_csv('vertraege_' . date('Y-m-d') . '.csv', $header, $rows);
