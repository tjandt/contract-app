<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contracts.php');
    exit;
}

$pdo = get_pdo();

$historyId  = (int) ($_POST['history_id'] ?? 0);
$contractId = (int) ($_POST['contract_id'] ?? 0);

$stmt = $pdo->prepare("DELETE FROM cost_history WHERE id = ? AND contract_id = ?");
$stmt->execute([$historyId, $contractId]);

header('Location: contract_view.php?id=' . $contractId . '&saved=1');
exit;
