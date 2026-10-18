<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
// Übersteigt der Upload post_max_size, ist $_POST leer – dann gibt es auch kein Token
if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    flash('error', 'Der Upload ist zu groß für die Server-Einstellungen (post_max_size).');
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();
$contractId = (int) ($_POST['contract_id'] ?? 0);

$check = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE id = ?");
$check->execute([$contractId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    die('Vertrag nicht gefunden.');
}

$error = store_uploaded_document($pdo, $contractId, $_FILES['document'] ?? [], (string) ($_POST['doc_type'] ?? 'other'));
$error ? flash('error', $error) : flash('success', 'Dokument hochgeladen.');
redirect('contract_view.php?id=' . $contractId);
