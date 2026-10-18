<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contracts.php');
    exit;
}

$pdo = get_pdo();

$contractId = (int) ($_POST['contract_id'] ?? 0);
$amount     = (float) str_replace(',', '.', $_POST['amount'] ?? '0');
$validFrom  = $_POST['valid_from'] ?? date('Y-m-d');
$reason     = trim($_POST['reason'] ?? '') ?: null;

$stmt = $pdo->prepare("SELECT id FROM contracts WHERE id = ?");
$stmt->execute([$contractId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Vertrag nicht gefunden.');
}

$pdo->beginTransaction();
try {
    $hist = $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, ?)");
    $hist->execute([$contractId, $amount, $validFrom, $reason ?: 'Preisanpassung']);

    // Den aktuellen Vertragspreis nur aktualisieren, wenn die Änderung bereits gilt (heute oder in der Vergangenheit).
    if ($validFrom <= date('Y-m-d')) {
        $upd = $pdo->prepare("UPDATE contracts SET current_cost = ? WHERE id = ?");
        $upd->execute([$amount, $contractId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    die('Fehler beim Speichern: ' . htmlspecialchars($e->getMessage()));
}

header('Location: contract_view.php?id=' . $contractId . '&saved=1');
exit;
