<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

$ym = $_GET['ym'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Monat.']);
    exit;
}

$pdo = get_pdo();
$breakdown = get_month_breakdown($pdo, $ym);

echo json_encode([
    'ym'    => $ym,
    'label' => format_month_de($ym . '-01'),
    'total' => $breakdown['total'],
    'items' => $breakdown['items'],
], JSON_UNESCAPED_UNICODE);
