<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();

$contractId = (int) ($_POST['contract_id'] ?? 0);
$amountRaw  = str_replace(',', '.', trim((string) ($_POST['amount'] ?? '')));
$validFrom  = trim((string) ($_POST['valid_from'] ?? '')) ?: date('Y-m-d');
$reason     = trim((string) ($_POST['reason'] ?? '')) ?: 'Preisanpassung';

$stmt = $pdo->prepare("SELECT id, start_date FROM contracts WHERE id = ?");
$stmt->execute([$contractId]);
$contract = $stmt->fetch();
if (!$contract) {
    http_response_code(404);
    die('Vertrag nicht gefunden.');
}

$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
$error = null;
if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
    $error = 'Bitte einen gültigen Betrag (≥ 0) eingeben.';
} elseif (!$parsed || $parsed->format('Y-m-d') !== $validFrom) {
    $error = 'Ungültiges Datum.';
} elseif ($validFrom < $contract['start_date']) {
    $error = 'Eine Preisänderung kann nicht vor dem Vertragsbeginn (' . format_date_de($contract['start_date']) . ') gelten.';
} elseif (mb_strlen($reason) > 255) {
    $error = 'Der Grund ist zu lang (max. 255 Zeichen).';
}
if ($error) {
    flash('error', $error);
    redirect('contract_view.php?id=' . $contractId);
}

$pdo->beginTransaction();
try {
    // Gleiches Datum -> bestehenden Eintrag aktualisieren (je Vertrag und Datum gibt es nur einen Preis)
    $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE amount = VALUES(amount), reason = VALUES(reason)")
        ->execute([$contractId, round((float) $amountRaw, 2), $validFrom, $reason]);

    // Aktueller Preis = heute gültiger Historieneintrag: Zukunftsdaten greifen erst am Stichtag,
    // rückdatierte Einträge überschreiben keinen neueren Preis.
    sync_current_costs($pdo, $contractId);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('cost_update: ' . $e->getMessage());
    flash('error', 'Die Änderung konnte nicht gespeichert werden (Details im Server-Log).');
    redirect('contract_view.php?id=' . $contractId);
}

flash('success', $validFrom > date('Y-m-d')
    ? 'Preisänderung gespeichert – sie wird am ' . format_date_de($validFrom) . ' wirksam.'
    : 'Gespeichert.');
redirect('contract_view.php?id=' . $contractId);
