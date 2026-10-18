<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();
$years = get_available_years($pdo);

$pageTitle = 'Export';
$activeNav = 'export';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Export</h1>
        <p>Daten als CSV-Datei herunterladen, z. B. für Steuerunterlagen oder Excel</p>
    </div>
</div>

<div class="export-grid">

    <div class="card export-card">
        <div class="export-icon">📋</div>
        <div class="section-title">Vertragsliste</div>
        <p class="text-secondary" style="font-size:13.5px; margin:0 0 16px;">
            Alle Verträge mit Stammdaten: Kategorie, Anbieter, Vertragsnummer, Laufzeit,
            Kündigungsfrist, aktuelle Kosten und Status.
        </p>
        <a href="export_contracts.php" class="btn btn-secondary btn-block">Als CSV herunterladen</a>
    </div>

    <div class="card export-card">
        <div class="export-icon">📈</div>
        <div class="section-title">Kostenhistorie</div>
        <p class="text-secondary" style="font-size:13.5px; margin:0 0 16px;">
            Alle erfassten Preisänderungen chronologisch, inkl. Grund und Vertrag –
            praktisch, um Preiserhöhungen im Zeitverlauf nachzuvollziehen.
        </p>
        <a href="export_history.php" class="btn btn-secondary btn-block">Als CSV herunterladen</a>
    </div>

    <div class="card export-card">
        <div class="export-icon">🧾</div>
        <div class="section-title">Jahresübersicht (Steuerunterlagen)</div>
        <p class="text-secondary" style="font-size:13.5px; margin:0 0 16px;">
            Kosten je Vertrag, summiert pro Kalenderjahr – auf Basis der jeweils gültigen Preise aus der
            Kostenhistorie. Im laufenden Jahr zählen nur die bereits angefangenen Monate.
        </p>
        <form method="get" action="export_yearly.php">
            <div class="form-field" style="margin-bottom:14px;">
                <label for="basis">Berechnungsgrundlage</label>
                <select id="basis" name="basis">
                    <option value="accrual">Periodisiert (Kosten je Monat; Jahres-/Quartalsbeiträge verteilt)</option>
                    <option value="cash">Tatsächliche Abbuchungen (laut Zahlungskalender)</option>
                </select>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label for="year">Jahr</label>
                <select id="year" name="year">
                    <option value="all">Alle Jahre</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-block">Als CSV herunterladen</button>
        </form>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
