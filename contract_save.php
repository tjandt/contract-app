<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}
csrf_verify();

$pdo = get_pdo();
$id  = !empty($_POST['id']) ? (int) $_POST['id'] : null;

// ---------------------------------------------------------------- Eingaben lesen & prüfen
$errors = [];

$text = fn(string $key): string => trim((string) ($_POST[$key] ?? ''));

/** Gültiges Datum (YYYY-MM-DD) oder null; Fehler wird in $errors vermerkt. */
$date = function (string $key, string $label, bool $required = false) use (&$errors, $text): ?string {
    $value = $text($key);
    if ($value === '') {
        if ($required) $errors[] = "$label ist ein Pflichtfeld.";
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        $errors[] = "$label: ungültiges Datum.";
        return null;
    }
    return $value;
};

/** Ganzzahl im erlaubten Bereich. */
$int = function (string $key, string $label, int $default, int $min, int $max) use (&$errors, $text): int {
    $value = $text($key);
    if ($value === '') return $default;
    if (!preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
        $errors[] = "$label muss eine Zahl zwischen $min und $max sein.";
        return $default;
    }
    return (int) $value;
};

$name = $text('name');
if ($name === '' || mb_strlen($name) > 150) {
    $errors[] = 'Vertragsname ist ein Pflichtfeld (max. 150 Zeichen).';
}

$startDate = $date('start_date', 'Vertragsbeginn', true);
$cancelledAt = $date('cancelled_at', 'Gekündigt zum');
$submittedAt = $date('cancellation_submitted_at', 'Kündigung eingereicht am');
$confirmedAt = $date('cancellation_confirmed_at', 'Kündigung bestätigt am');
$anchorDate  = $date('billing_anchor_date', 'Abbuchungsanker');
$priceValidFrom = $date('price_valid_from', 'Preis gültig ab') ?? date('Y-m-d');

$costRaw = str_replace(',', '.', $text('current_cost'));
if (!is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 99999999) {
    $errors[] = 'Kosten müssen eine Zahl ≥ 0 sein.';
}
$currentCost = round((float) $costRaw, 2);

$providerUrl = $text('provider_url');
if ($providerUrl !== '' && !preg_match('~^https?://~i', $providerUrl)) {
    $errors[] = 'Link zum Anbieter muss mit http:// oder https:// beginnen.';
}

$categoryId = $text('category_id') !== '' ? (int) $text('category_id') : null;
if ($categoryId !== null) {
    $catCheck = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ?");
    $catCheck->execute([$categoryId]);
    if (!$catCheck->fetchColumn()) {
        $errors[] = 'Die gewählte Kategorie existiert nicht.';
        $categoryId = null;
    }
}

$noticeUnit = in_array($_POST['notice_period_unit'] ?? '', NOTICE_UNITS, true) ? $_POST['notice_period_unit'] : 'days';
$alignment  = in_array($_POST['end_alignment'] ?? '', END_ALIGNMENTS, true) ? $_POST['end_alignment'] : 'none';

$data = [
    'name'                => $name,
    'category_id'         => $categoryId,
    'provider_name'       => $text('provider_name') ?: null,
    'provider_url'        => $providerUrl ?: null,
    'contract_number'     => $text('contract_number') ?: null,
    'status'              => ($_POST['status'] ?? '') === 'paused' ? 'paused' : 'active',
    'start_date'          => $startDate,
    'initial_term_months' => $int('initial_term_months', 'Erstlaufzeit', 12, 0, 1200),
    'renewal_term_months' => $int('renewal_term_months', 'Verlängerung', 12, 0, 1200),
    'notice_period_days'  => $int('notice_period_days', 'Kündigungsfrist', 30, 0, 3650),
    'notice_period_unit'  => $noticeUnit,
    'end_alignment'       => $alignment,
    'billing_cycle'       => in_array($_POST['billing_cycle'] ?? '', BILLING_CYCLES, true) ? $_POST['billing_cycle'] : 'monthly',
    'billing_anchor_date' => $anchorDate,
    'current_cost'        => $currentCost,
    'notes'               => $text('notes') ?: null,
    'cancelled_at'        => $cancelledAt,
    'cancellation_submitted_at' => $submittedAt,
    'cancellation_confirmed_at' => $confirmedAt,
];

if ($startDate !== null) {
    if ($cancelledAt !== null && $cancelledAt < $startDate) {
        $errors[] = '„Gekündigt zum“ darf nicht vor dem Vertragsbeginn liegen.';
    }
    if ($priceValidFrom < $startDate) {
        $errors[] = '„Preis gültig ab“ darf nicht vor dem Vertragsbeginn liegen.';
    }
}
if ($confirmedAt !== null && $submittedAt === null) {
    $errors[] = 'Eine Kündigungsbestätigung setzt ein Einreichungsdatum voraus.';
}
if ($confirmedAt !== null && $submittedAt !== null && $confirmedAt < $submittedAt) {
    $errors[] = 'Die Bestätigung kann nicht vor der Einreichung liegen.';
}

if ($errors) {
    foreach ($errors as $err) {
        flash('error', $err);
    }
    $_SESSION['old_input'] = $_POST;
    redirect('contract_edit.php' . ($id ? '?id=' . $id : ''));
}

// Wurde die Kündigung eingereicht, aber kein Vertragsende erfasst, ergibt sich das Ende aus Laufzeit und Frist
if ($submittedAt !== null && $cancelledAt === null) {
    $end = compute_termination_end($data, new DateTimeImmutable($submittedAt));
    if ($end !== null) {
        $data['cancelled_at'] = $end->format('Y-m-d');
        flash('success', 'Vertragsende automatisch berechnet: ' . format_date_de($data['cancelled_at']) . ' (anpassbar unter „Gekündigt zum“).');
    }
}

// ---------------------------------------------------------------- Speichern
$pdo->beginTransaction();
try {
    $columns = array_keys($data);

    if ($id) {
        $old = $pdo->prepare("SELECT current_cost, start_date FROM contracts WHERE id = ? FOR UPDATE");
        $old->execute([$id]);
        $oldRow = $old->fetch();
        if (!$oldRow) {
            throw new RuntimeException('Vertrag nicht gefunden.');
        }

        $set = implode(', ', array_map(fn($c) => "$c = :$c", $columns));
        $pdo->prepare("UPDATE contracts SET $set WHERE id = :id")->execute($data + ['id' => $id]);

        // Vertragsbeginn verschoben: den "Vertragsbeginn"-Eintrag der Kostenhistorie mitziehen,
        // sonst fehlt der Vertrag in der Auswertung vor dem neuen Datum.
        if ($oldRow['start_date'] !== $startDate) {
            $conflict = $pdo->prepare("SELECT COUNT(*) FROM cost_history WHERE contract_id = ? AND valid_from = ?");
            $conflict->execute([$id, $startDate]);
            if (!$conflict->fetchColumn()) {
                $pdo->prepare("UPDATE cost_history SET valid_from = ?
                                WHERE contract_id = ? AND valid_from = ? AND reason = 'Vertragsbeginn'")
                    ->execute([$startDate, $id, $oldRow['start_date']]);
            }
        }

        // Kostenänderung in der Historie festhalten (gleiches Datum -> Eintrag aktualisieren)
        $oldCost = (float) $oldRow['current_cost'];
        if (abs($oldCost - $currentCost) > 0.001) {
            $reason = $currentCost > $oldCost ? 'Preiserhöhung' : 'Preisanpassung';
            $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE amount = VALUES(amount), reason = VALUES(reason)")
                ->execute([$id, $currentCost, $priceValidFrom, $reason]);
        }
    } else {
        $cols = implode(', ', $columns);
        $params = implode(', ', array_map(fn($c) => ":$c", $columns));
        $pdo->prepare("INSERT INTO contracts ($cols) VALUES ($params)")->execute($data);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO cost_history (contract_id, amount, valid_from, reason) VALUES (?, ?, ?, 'Vertragsbeginn')")
            ->execute([$id, $currentCost, $startDate]);
    }

    // Aktuellen Preis aus der Historie ableiten (berücksichtigt Datum der Gültigkeit)
    sync_current_costs($pdo, $id);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('contract_save: ' . $e->getMessage());
    flash('error', 'Der Vertrag konnte nicht gespeichert werden (Details im Server-Log).');
    $_SESSION['old_input'] = $_POST;
    redirect('contract_edit.php' . ($id ? '?id=' . $id : ''));
}

$effective = $pdo->prepare("SELECT current_cost FROM contracts WHERE id = ?");
$effective->execute([$id]);
if (abs((float) $effective->fetchColumn() - $currentCost) > 0.001) {
    flash('success', 'Hinweis: Der Preis gilt erst ab ' . format_date_de($priceValidFrom)
        . ' bzw. ein späterer Eintrag der Kostenhistorie überlagert ihn – aktuell wird der Preis aus der Historie verwendet.');
}

flash('success', 'Gespeichert.');
redirect('contract_view.php?id=' . $id);
