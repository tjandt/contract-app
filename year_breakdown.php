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

$year = $_GET['year'] ?? '';
if (!preg_match('/^\d{4}$/', $year)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges Jahr.']);
    exit;
}

$pdo = get_pdo();
$breakdown = get_year_breakdown($pdo, $year);

echo json_encode([
    'year'  => $year,
    'total' => $breakdown['total'],
    'items' => $breakdown['items'],
], JSON_UNESCAPED_UNICODE);
