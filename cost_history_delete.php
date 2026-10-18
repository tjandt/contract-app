<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();

$historyId  = (int) ($_POST['history_id'] ?? 0);
$contractId = (int) ($_POST['contract_id'] ?? 0);

$count = $pdo->prepare("SELECT COUNT(*) FROM cost_history WHERE contract_id = ?");
$count->execute([$contractId]);
if ((int) $count->fetchColumn() <= 1) {
    flash('error', 'Der letzte Eintrag der Kostenhistorie kann nicht gelöscht werden – sonst fehlt der Vertrag in allen Auswertungen.');
    redirect('contract_view.php?id=' . $contractId);
}

$pdo->prepare("DELETE FROM cost_history WHERE id = ? AND contract_id = ?")->execute([$historyId, $contractId]);

// current_cost folgt der Historie
sync_current_costs($pdo, $contractId);

flash('success', 'Eintrag gelöscht.');
redirect('contract_view.php?id=' . $contractId);
