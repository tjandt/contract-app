<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();
$stmt = $pdo->prepare("SELECT * FROM contract_documents WHERE id = ?");
$stmt->execute([(int) ($_POST['id'] ?? 0)]);
$doc = $stmt->fetch();

if ($doc) {
    $pdo->prepare("DELETE FROM contract_documents WHERE id = ?")->execute([$doc['id']]);
    @unlink(document_path($doc['stored_name']));
    flash('success', 'Dokument gelöscht.');
    redirect('contract_view.php?id=' . (int) $doc['contract_id']);
}
redirect('contracts.php');
