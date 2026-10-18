<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();
$id = (int) ($_POST['id'] ?? 0);

$exists = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE id = ?");
$exists->execute([$id]);
if ($exists->fetchColumn()) {
    delete_contract_document_files($pdo, $id); // Dateien zuerst; Historie und Dokument-Zeilen löscht ON DELETE CASCADE
    $pdo->prepare("DELETE FROM contracts WHERE id = ?")->execute([$id]);
    flash('success', 'Vertrag gelöscht.');
} else {
    flash('error', 'Vertrag nicht gefunden.');
}
redirect('contracts.php');
