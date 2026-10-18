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

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

$name = trim($_POST['name'] ?? '');
$startDate = $_POST['start_date'] ?? '';
$currentCost = (float) str_replace(',', '.', $_POST['current_cost'] ?? '0');

if ($name === '' || $startDate === '') {
    http_response_code(400);
    die('Bitte Pflichtfelder ausfüllen (Name, Vertragsbeginn).');
}

$statusInput = in_array($_POST['status'] ?? '', ['active', 'paused'], true) ? $_POST['status'] : 'active';
$cancelledAtInput = trim($_POST['cancelled_at'] ?? '');
$cancelledAtInput = $cancelledAtInput !== '' ? $cancelledAtInput : null;

// Sobald ein Kündigungsdatum gesetzt ist, gilt der Vertrag intern als "cancelled" –
// unabhängig davon, ob das Datum in der Vergangenheit (bereits beendet) oder
// Zukunft (läuft noch bis dahin) liegt. Ohne Datum gilt die normale Status-Auswahl.
$status = $cancelledAtInput !== null ? 'cancelled' : $statusInput;

$data = [
    'name'                => $name,
    'category_id'         => $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null,
    'provider_name'       => trim($_POST['provider_name'] ?? '') ?: null,
    'provider_url'        => trim($_POST['provider_url'] ?? '') ?: null,
    'contract_number'     => trim($_POST['contract_number'] ?? '') ?: null,
    'status'              => $status,
    'start_date'          => $startDate,
    'initial_term_months' => (int) ($_POST['initial_term_months'] ?? 12),
    'renewal_term_months' => (int) ($_POST['renewal_term_months'] ?? 12),
    'notice_period_days'  => (int) ($_POST['notice_period_days'] ?? 30),
    'billing_cycle'       => in_array($_POST['billing_cycle'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['billing_cycle'] : 'monthly',
    'current_cost'        => $currentCost,
    'notes'               => trim($_POST['notes'] ?? '') ?: null,
    'cancelled_at'        => $cancelledAtInput,
];

$pdo->beginTransaction();
try {
    if ($id) {
        $old = $pdo->prepare("SELECT current_cost, start_date FROM contracts WHERE id = ?");
        $old->execute([$id]);
        $oldRow = $old->fetch();
        if (!$oldRow) {
            throw new RuntimeException('Vertrag nicht gefunden.');
        }

        $stmt = $pdo->prepare("UPDATE contracts SET
                name = :name, category_id = :category_id, provider_name = :provider_name,
                provider_url = :provider_url, contract_number = :contract_number, status = :status,
                start_date = :start_date, initial_term_months = :initial_term_months,
                renewal_term_months = :renewal_term_months, notice_period_days = :notice_period_days,
                billing_cycle = :billing_cycle, current_cost = :current_cost, notes = :notes,
                cancelled_at = :cancelled_at
            WHERE id = :id");
        $stmt->execute($data + ['id' => $id]);

        // Wurde das Vertragsbeginn-Datum geändert, den dazugehörigen ältesten
        // Kostenhistorien-Eintrag ("Vertragsbeginn") mit anpassen – sonst
        // driften Stammdaten und Kostenhistorie auseinander (führt dazu, dass
        // der Vertrag in der Monats-/Jahresauswertung vor dem neuen Datum fehlt).
        if ($oldRow['start_date'] !== $startDate) {
            $firstHist = $pdo->prepare("SELECT id FROM cost_history
                                         WHERE contract_id = ? AND valid_from = ? AND reason = 'Vertragsbeginn'
                                         ORDER BY id ASC LIMIT 1");
            $firstHist->execute([$id, $oldRow['start_date']]);
            $firstHistId = $firstHist->fetchColumn();
            if ($firstHistId) {
                $updHist = $pdo->prepare("UPDATE cost_history SET valid_from = ? WHERE id = ?");
                $updHist->execute([$startDate, $firstHistId]);
            }
        }

        // Kostenänderung automatisch in Historie protokollieren
        $oldCost = (float) $oldRow['current_cost'];
        if (abs($oldCost - $currentCost) > 0.001) {
            $reason = $currentCost > $oldCost ? 'Preiserhöhung' : 'Preisanpassung';
            $hist = $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, ?)");
            $hist->execute([$id, $currentCost, date('Y-m-d'), $reason]);
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO contracts
                (name, category_id, provider_name, provider_url, contract_number, status, start_date,
                 initial_term_months, renewal_term_months, notice_period_days, billing_cycle, current_cost, notes, cancelled_at)
            VALUES
                (:name, :category_id, :provider_name, :provider_url, :contract_number, :status, :start_date,
                 :initial_term_months, :renewal_term_months, :notice_period_days, :billing_cycle, :current_cost, :notes, :cancelled_at)");
        $stmt->execute($data);
        $id = (int) $pdo->lastInsertId();

        // Ersten Kostenhistorien-Eintrag anlegen (ab Vertragsbeginn)
        $hist = $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, ?)");
        $hist->execute([$id, $currentCost, $startDate, 'Vertragsbeginn']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    die('Fehler beim Speichern: ' . htmlspecialchars($e->getMessage()));
}

header('Location: contract_view.php?id=' . $id . '&saved=1');
exit;
